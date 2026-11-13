<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Search\DefaultSearchRequestInterface;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\ReviewRules;
use GlpiPlugin\Points\Domain\RuleViolation;
use Session;

/**
 * Contrôle d'un débit de tâche hors créneau ou férié (table glpi_plugin_points_reviews ; docs/conception.md, règle 10).
 *
 * Création : par Billing::debitTask, dans la MÊME transaction que le débit (option `inside` de Ledger::debit), jamais
 * ailleurs ; une review par opération (contrôlé sous le verrou du contrat). Décision : confirmer, ou ajuster par une
 * opération `review_adjustment` liée au débit (+ : remboursement par Ledger::refund, plafonné au débit ; − : débit en plus
 * par Ledger::debit sans dépassement, qui exige un solde réel suffisant). L'état est écrit dans la transaction de
 * l'opération (option `inside`) : une review ne se décide qu'une fois (transition atomique `move()`).
 *
 * Aucun chemin générique d'écriture (add, update, delete, API) : seuls les services ci-dessous écrivent. Droit
 * plugin_points_review : READ voir, UPDATE décider, plus l'accès à l'entité de la review (copie de celle du contrat).
 */
class Review extends CommonDBTM implements DefaultSearchRequestInterface
{
    use StateRecord;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::REVIEW;

    public bool $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Reviews', 'points') : __('Review', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-eye-check';
    }

    // ---------------------------------------------------------------------------------------------------------
    // Droits : aucune écriture générique
    // ---------------------------------------------------------------------------------------------------------

    public static function canView(): bool
    {
        return Session::haveRight(Rights::REVIEW, READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::REVIEW, UPDATE);
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        return static::canView() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
    }

    public function canCreateItem(): bool
    {
        return false;
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    public function pre_deleteItem()
    {
        return false;
    }

    public function prepareInputForAdd($input)
    {
        return false;
    }

    /** Une review ne se modifie jamais par update : sa décision passe par confirm() ou adjust(). */
    public function prepareInputForUpdate($input)
    {
        return false;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Création : dans la transaction du débit
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Ouvre la review d'un débit de tâche si ses drapeaux le demandent (samedi, dimanche, férié, hors créneau). Appelée
     * DANS la transaction du débit (Ledger::debit, option `inside`) : l'opération et la review naissent ensemble ou pas
     * du tout. Une review par opération : un débit déjà suivi n'en reçoit pas une seconde.
     *
     * @param array{tickets_id: int, contracts_id: int, entities_id: int} $ctx
     * @param list<string> $flags drapeaux de la tâche (TaskRules::flags)
     * @return int identifiant de la review, 0 si aucun contrôle n'est à ouvrir
     */
    public static function openFor(array $ctx, int $operationId, array $flags): int
    {
        global $DB;

        $reason = ReviewRules::reasonFromFlags($flags);
        if ($reason === '') {
            return 0;
        }
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['operations_id' => $operationId], 'LIMIT' => 1])->current();
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        // Entité du CONTRAT (celle de l'opération de débit, copie de Ledger), pas celle du ticket : un contrat récursif peut servir un
        // ticket d'une sous-entité, et la review suit le cloisonnement du journal
        $operation = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_plugin_points_operations', 'WHERE' => ['id' => $operationId], 'LIMIT' => 1])->current();
        $now = self::now();
        $DB->insert(self::getTable(), [
            'entities_id'              => (int) ($operation['entities_id'] ?? $ctx['entities_id']),
            'is_recursive'             => 0,
            'operations_id'            => $operationId,
            'tickets_id'               => $ctx['tickets_id'],
            'contracts_id'             => $ctx['contracts_id'],
            'reason'                   => $reason,
            'status'                   => ReviewRules::TO_REVIEW,
            'users_id'                 => 0,
            'operations_id_adjustment' => 0,
            'date_creation'            => $now,
            'date_mod'                 => $now,
        ]);
        $id = (int) $DB->insertId();
        self::history($id, '', ReviewRules::TO_REVIEW);

        return $id;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Décision (droit UPDATE)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Confirme le débit tel quel.
     *
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function confirm(int $id, string $comment = ''): void
    {
        $row = self::loadForDecision($id);
        ReviewRules::assertTransition((string) $row['status'], ReviewRules::CONFIRMED);
        if (!self::move($id, [ReviewRules::TO_REVIEW], ReviewRules::CONFIRMED, ['users_id' => (int) Session::getLoginUserID(), 'comment' => trim($comment)])) {
            throw new RuleViolation(RuleViolation::REVIEW_STATE);
        }
    }

    /**
     * Ajuste le débit : montant signé non nul, motif obligatoire. + rembourse (plafonné au débit), − débite en plus (solde
     * réel suffisant, jamais de dépassement). L'opération `review_adjustment` et le passage à « ajusté » sont écrits dans
     * la même transaction du journal ; si une autre décision a gagné, tout est annulé.
     *
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function adjust(int $id, string $amount, string $comment): void
    {
        $row = self::loadForDecision($id);
        ReviewRules::assertTransition((string) $row['status'], ReviewRules::ADJUSTED);
        $cents   = ReviewRules::parseAdjustment($amount);
        $comment = trim($comment);
        if ($comment === '') {
            throw new RuleViolation(RuleViolation::REASON_REQUIRED, 'A reason is required for an adjustment');
        }
        $debit = self::debitOf((int) $row['operations_id']);
        if ($debit === null) {
            throw new RuleViolation(RuleViolation::UNKNOWN_OPERATION, 'Unknown debit');
        }
        $me     = (int) Session::getLoginUserID();
        $inside = static function (int $operationId) use ($id, $me, $comment): void {
            if (!self::move($id, [ReviewRules::TO_REVIEW], ReviewRules::ADJUSTED, ['users_id' => $me, 'comment' => $comment, 'operations_id_adjustment' => $operationId])) {
                // Une autre décision a gagné : l'exception annule l'opération (annulation par Ledger)
                throw new RuleViolation(RuleViolation::REVIEW_STATE);
            }
        };
        $common = [
            'itemtype'   => self::class,
            'items_id'   => $id,
            'tickets_id' => $debit['tickets_id'],
            'details'    => ['reviews_id' => $id, 'adjustment' => Money::format($cents), 'debit' => $debit['id']],
            'inside'     => $inside,
        ];
        if ($cents > 0) {
            // Remboursement aux lots du débit, dans l'ordre inverse, au plus ce que le débit a pris (Ledger::refund)
            Ledger::refund($debit['contracts_id'], $debit['id'], $cents, OpType::REVIEW_ADJUSTMENT, self::today(), $comment, $common);

            return;
        }
        // Débit en plus : à la date du débit contrôlé (mêmes lots), solde réel exigé, jamais de dépassement
        Ledger::debit($debit['contracts_id'], OpType::REVIEW_ADJUSTMENT, -$cents, $debit['date'], $common + [
            'unique'               => true,
            'allow_overdraft'      => false,
            'operations_id_origin' => $debit['id'],
            'comment'              => $comment,
        ]);
    }

    /**
     * @return array<string, mixed>
     * @throws AccessDeniedHttpException|NotFoundHttpException
     */
    private static function loadForDecision(int $id): array
    {
        $row = self::row($id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }
        if (!self::canUpdate() || !Session::haveAccessToEntity((int) $row['entities_id'])) {
            throw new AccessDeniedHttpException();
        }

        return $row;
    }

    /**
     * Débit contrôlé (lecture du journal).
     *
     * @return array{id: int, contracts_id: int, tickets_id: int, date: string, amount: int}|null
     */
    private static function debitOf(int $operationId): ?array
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['id', 'contracts_id', 'tickets_id', 'date', 'amount'], 'FROM' => 'glpi_plugin_points_operations', 'WHERE' => ['id' => $operationId], 'LIMIT' => 1])->current();

        return $row === null ? null : [
            'id' => (int) $row['id'], 'contracts_id' => (int) $row['contracts_id'], 'tickets_id' => (int) $row['tickets_id'],
            'date' => (string) $row['date'], 'amount' => Money::parse((string) $row['amount']),
        ];
    }

    /** Jour courant dans le fuseau de référence (date métier d'une décision qui a lieu maintenant). */
    private static function today(): string
    {
        return Dates::day(Billing::toReference(self::now()));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Libellés
    // ---------------------------------------------------------------------------------------------------------

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ReviewRules::TO_REVIEW => __('To review', 'points'),
            ReviewRules::CONFIRMED => __('Confirmed', 'points'),
            ReviewRules::ADJUSTED  => __('Adjusted', 'points'),
            default                => $status,
        };
    }

    public static function reasonLabel(string $stored): string
    {
        return implode(', ', array_map(static fn (string $reason): string => match ($reason) {
            'saturday'  => __('Saturday', 'points'),
            'sunday'    => __('Sunday', 'points'),
            'holiday'   => __('Holiday', 'points'),
            'off_hours' => __('Outside working hours', 'points'),
            default     => $reason,
        }, ReviewRules::reasons($stored)));
    }

    public static function describe(RuleViolation $e): string
    {
        return match ($e->reason) {
            RuleViolation::REVIEW_STATE => __('This review has already been decided', 'points'),
            default                     => Billing::describe($e),
        };
    }

    // ---------------------------------------------------------------------------------------------------------
    // Recherche native : liste « Reviews à contrôler »
    // ---------------------------------------------------------------------------------------------------------

    /** Par défaut, les reviews à contrôler (le filtre se change dans la recherche). */
    public static function getDefaultSearchRequest(): array
    {
        return ['criteria' => [['field' => 3, 'searchtype' => 'equals', 'value' => ReviewRules::TO_REVIEW]], 'sort' => 2, 'order' => 'DESC'];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return match ($field) {
            'status' => htmlescape(self::statusLabel((string) $values[$field])),
            'reason' => htmlescape(self::reasonLabel((string) $values[$field])),
            default  => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return \Dropdown::showFromArray($name, [
                ReviewRules::TO_REVIEW => self::statusLabel(ReviewRules::TO_REVIEW),
                ReviewRules::CONFIRMED => self::statusLabel(ReviewRules::CONFIRMED),
                ReviewRules::ADJUSTED  => self::statusLabel(ReviewRules::ADJUSTED),
            ], ['value' => $values[$field], 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'status', 'name' => __('Status'), 'massiveaction' => false, 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => '4', 'table' => $table, 'field' => 'reason', 'name' => __('Checks', 'points'), 'massiveaction' => false, 'datatype' => 'specific', 'nosearch' => true],
            ['id' => '5', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '6', 'table' => 'glpi_contracts', 'field' => 'name', 'name' => __('Contract'), 'massiveaction' => false, 'datatype' => 'dropdown'],
            ['id' => '7', 'table' => $table, 'field' => 'operations_id', 'name' => __('Debit operation', 'points'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '8', 'table' => $table, 'field' => 'date_creation', 'name' => __('Creation date'), 'massiveaction' => false, 'datatype' => 'datetime'],
            ['id' => '9', 'table' => 'glpi_users', 'field' => 'name', 'name' => __('Reviewed by', 'points'), 'massiveaction' => false, 'datatype' => 'dropdown'],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Section « Tâches à contrôler » de l'onglet Points du ticket
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Données de la section (gabarit controls.html.twig). Les droits affichés sont ceux que les actions contrôleront de
     * nouveau à l'envoi du formulaire.
     *
     * @return array<string, mixed>
     */
    public static function viewData(\Ticket $ticket): array
    {
        global $CFG_GLPI, $DB;

        if (!self::canView() || !Session::haveAccessToEntity((int) $ticket->fields['entities_id'])) {
            return ['visible' => false];
        }
        $canDecide = self::canUpdate();
        $rows      = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => (int) $ticket->getID()], 'ORDER' => 'id']) as $review) {
            // Une review d'une entité hors des droits de l'utilisateur (contrat récursif d'une entité mère) n'apparaît pas
            if (!Session::haveAccessToEntity((int) $review['entities_id'])) {
                continue;
            }
            $debit = self::debitOf((int) $review['operations_id']);
            $task  = $DB->request(['SELECT' => ['items_id'], 'FROM' => 'glpi_plugin_points_operations', 'WHERE' => ['id' => $review['operations_id']], 'LIMIT' => 1])->current();
            $rows[] = [
                'id'          => (int) $review['id'],
                'task'        => (int) ($task['items_id'] ?? 0),
                'reason'      => self::reasonLabel((string) $review['reason']),
                'status'      => (string) $review['status'],
                'status_label' => self::statusLabel((string) $review['status']),
                'points'      => Money::format($debit === null ? 0 : -$debit['amount']),
                'reviewer'    => (int) $review['users_id'] > 0 ? (string) getUserName((int) $review['users_id']) : '',
                'comment'     => (string) $review['comment'],
                'adjustment'  => (int) $review['operations_id_adjustment'],
                'can_decide'  => $canDecide && $review['status'] === ReviewRules::TO_REVIEW,  // entité déjà contrôlée ci-dessus : celle de la review
            ];
        }

        return [
            'visible'  => true,
            'tickets_id' => (int) $ticket->getID(),
            'form_url' => $CFG_GLPI['root_doc'] . '/plugins/points/front/review.form.php',
            'rows'     => $rows,
            'to_review' => count(array_filter($rows, static fn (array $r): bool => $r['status'] === ReviewRules::TO_REVIEW)),
        ];
    }
}
