<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Search\DefaultSearchRequestInterface;
use GlpiPlugin\Points\Domain\CompensationRules;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\RuleViolation;
use NotificationEvent;
use Session;

/**
 * Demande de compensation sur un débit de tâche ou de forfait (table glpi_plugin_points_compensations ; docs/conception.md,
 * règle 11). Les décisions pures (états, montants, séparation) sont dans Domain\CompensationRules ; cette classe y ajoute
 * les droits, la lecture du journal, le crédit lié (Ledger::refund, seule écriture du journal) et la notification.
 *
 * Droit plugin_points_compensation : READ voir, CREATE demander, VALIDATE décider (bit 1024). Demander exige aussi
 * l'accès à l'entité du débit et la lecture du ticket (\Ticket::can). Décider exige l'accès à l'entité ; le décideur ne
 * décide pas sa propre demande tant que `compensation_separation` vaut 1.
 *
 * Une demande se crée par add() (can* du modèle, liste blanche de prepareInputForAdd) ; elle ne se modifie ni ne se
 * supprime jamais par update, delete ou l'API : l'état passe par `move()` (transition atomique), et le crédit et le
 * passage à « approuvée » sont écrits dans la même transaction du journal (option `inside` de Ledger::refund).
 */
class Compensation extends CommonDBTM implements DefaultSearchRequestInterface
{
    use StateRecord;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::COMPENSATION;

    public bool $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Compensations', 'points') : __('Compensation', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-heart-handshake';
    }

    // ---------------------------------------------------------------------------------------------------------
    // Droits
    // ---------------------------------------------------------------------------------------------------------

    public static function canView(): bool
    {
        return Session::haveRight(Rights::COMPENSATION, READ);
    }

    /** Demander : bit CREATE. */
    public static function canCreate(): bool
    {
        return Session::haveRight(Rights::COMPENSATION, CREATE);
    }

    /** Une demande ne se modifie jamais. */
    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /** Décider : bit VALIDATE. */
    public static function canDecide(): bool
    {
        return Session::haveRight(Rights::COMPENSATION, Rights::VALIDATE);
    }

    public function canViewItem(): bool
    {
        return static::canView() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
    }

    public function canCreateItem(): bool
    {
        $debit = self::debitOf((int) ($this->input['operations_id'] ?? 0));

        return static::canCreate() && $debit !== null && Session::haveAccessToEntity($debit['entities_id']) && self::canReadTicket($debit['tickets_id']);
    }

    public function canUpdateItem(): bool
    {
        return false;
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

    /** Le ticket est lisible par l'utilisateur (droit du cœur et entité : \Ticket::can). */
    private static function canReadTicket(int $ticketsId): bool
    {
        $ticket = new \Ticket();

        return $ticketsId > 0 && $ticket->getFromDB($ticketsId) && $ticket->can($ticketsId, READ);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Demande : prepareInputForAdd (appelé par add())
    // ---------------------------------------------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        try {
            $debit = self::debitOf((int) ($input['operations_id'] ?? 0));
            if ($debit === null || !in_array($debit['type'], CompensationRules::TARGET_TYPES, true) || $debit['amount'] >= 0) {
                throw new RuleViolation(RuleViolation::COMPENSATION_TARGET, 'A compensation can only target a task or flatrate debit');
            }
            $cents = CompensationRules::parseAmount((string) ($input['amount'] ?? ''));
            CompensationRules::assertReason((string) ($input['comment'] ?? ''));
            CompensationRules::assertWithin($cents, Ledger::refundable($debit['id']));
        } catch (RuleViolation $e) {
            Session::addMessageAfterRedirect(htmlescape(self::describe($e)), false, ERROR);

            return false;
        }

        // Liste blanche : l'état, le décideur et le crédit ne viennent jamais de la saisie
        return [
            'operations_id'        => $debit['id'],
            'tickets_id'           => $debit['tickets_id'],
            'contracts_id'         => $debit['contracts_id'],
            'entities_id'          => $debit['entities_id'],
            'is_recursive'         => 0,
            'amount'               => Money::format($cents),
            'comment'              => trim((string) $input['comment']),
            'status'               => CompensationRules::PENDING,
            'users_id'             => (int) Session::getLoginUserID(),
            'users_id_decision'    => 0,
            'decision_comment'     => '',
            'approved_amount'      => '0.00',
            'operations_id_credit' => 0,
        ];
    }

    /** Une demande ne se modifie jamais par update (api v1, formulaire) : sa décision passe par decide(). */
    public function prepareInputForUpdate($input)
    {
        return false;
    }

    public function post_addItem()
    {
        self::history((int) $this->getID(), '', CompensationRules::PENDING);
    }

    /**
     * Demande de compensation (droit CREATE, entité et lecture du ticket : Compensation::check).
     *
     * @return int identifiant de la demande, 0 si une règle l'a refusée (message déjà posé)
     * @throws AccessDeniedHttpException
     */
    public static function request(int $operationsId, string $amount, string $comment): int
    {
        $item  = new self();
        $input = ['operations_id' => $operationsId, 'amount' => $amount, 'comment' => $comment];
        $item->check(-1, CREATE, $input);
        $id = $item->add($input);

        return $id === false ? 0 : (int) $id;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Décision (droit VALIDATE)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Approuve ou rejette une demande en attente. Rejet : motif de décision obligatoire. Approbation : montant approuvé
     * (par défaut le montant demandé) au plus égal au débit moins les compensations déjà approuvées ; le crédit lié
     * (`compensation`) est rendu aux lots du débit d'origine (Ledger::refund) dans la même transaction que le passage
     * à « approuvée ». Le demandeur est notifié (événement `compensation_decided`).
     *
     * @param string $approved montant approuvé saisi ; vide = montant demandé
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function decide(int $id, bool $approve, string $approved, string $comment): void
    {
        $row = self::row($id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }
        if (!self::canDecide() || !Session::haveAccessToEntity((int) $row['entities_id'])) {
            throw new AccessDeniedHttpException();
        }
        $by = (int) Session::getLoginUserID();
        if ((int) Config::value('compensation_separation') === 1) {
            CompensationRules::assertSeparation((int) $row['users_id'], $by);
        }
        CompensationRules::assertTransition((string) $row['status'], $approve ? CompensationRules::APPROVED : CompensationRules::REJECTED);
        $comment = trim($comment);
        $set     = ['users_id_decision' => $by, 'date_decision' => self::now(), 'decision_comment' => $comment];

        if (!$approve) {
            if ($comment === '') {
                throw new RuleViolation(RuleViolation::REASON_REQUIRED, 'A decision comment is required to reject a compensation');
            }
            if (!self::move($id, [CompensationRules::PENDING], CompensationRules::REJECTED, $set)) {
                throw new RuleViolation(RuleViolation::COMPENSATION_STATE);
            }
            self::notify($id);

            return;
        }

        $cents = CompensationRules::approvedAmount($approved, Money::parse((string) $row['amount']));
        $debit = self::debitOf((int) $row['operations_id']);
        if ($debit === null) {
            throw new RuleViolation(RuleViolation::UNKNOWN_OPERATION, 'Unknown debit');
        }
        CompensationRules::assertWithin($cents, Ledger::refundable($debit['id']));
        try {
            // Le plafond est revérifié par Ledger sous le verrou du contrat (restes des lots du débit, toutes compensations
            // et corrections comprises) ; le passage à « approuvée » est écrit dans la même transaction
            Ledger::refund($debit['contracts_id'], $debit['id'], $cents, OpType::COMPENSATION, self::today(), $comment !== '' ? $comment : (string) $row['comment'], [
                'itemtype'   => self::class,
                'items_id'   => $id,
                'tickets_id' => $debit['tickets_id'],
                'details'    => ['compensations_id' => $id, 'requested' => (string) $row['amount'], 'approved' => Money::format($cents)],
                'inside'     => static function (int $operationId) use ($id, $set, $cents): void {
                    if (!self::move($id, [CompensationRules::PENDING], CompensationRules::APPROVED, $set + ['approved_amount' => Money::format($cents), 'operations_id_credit' => $operationId])) {
                        // Une autre décision a gagné : l'exception annule le crédit (annulation par Ledger)
                        throw new RuleViolation(RuleViolation::COMPENSATION_STATE);
                    }
                },
            ]);
        } catch (RuleViolation $e) {
            if ($e->reason === RuleViolation::NOTHING_TO_REFUND) {
                // Deux approbations simultanées : la seconde bute sur le plafond que la première vient d'épuiser. Relire l'état avant
                // d'annoncer un dépassement : une demande déjà décidée est « déjà décidée »
                $fresh = self::row($id);
                if ($fresh === null || $fresh['status'] !== CompensationRules::PENDING) {
                    throw new RuleViolation(RuleViolation::COMPENSATION_STATE);
                }
                throw new RuleViolation(RuleViolation::COMPENSATION_AMOUNT, $e->getMessage());
            }
            throw $e;
        }
        self::notify($id);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Lecture du journal
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Débit visé (lecture du journal) : toute opération, le type est contrôlé par l'appelant.
     *
     * @return array{id: int, type: string, amount: int, contracts_id: int, tickets_id: int, entities_id: int, date: string}|null
     */
    private static function debitOf(int $operationId): ?array
    {
        global $DB;

        if ($operationId < 1) {
            return null;
        }
        $row = $DB->request(['SELECT' => ['id', 'type', 'amount', 'contracts_id', 'tickets_id', 'entities_id', 'date'], 'FROM' => 'glpi_plugin_points_operations', 'WHERE' => ['id' => $operationId], 'LIMIT' => 1])->current();

        return $row === null ? null : [
            'id' => (int) $row['id'], 'type' => (string) $row['type'], 'amount' => Money::parse((string) $row['amount']),
            'contracts_id' => (int) $row['contracts_id'], 'tickets_id' => (int) $row['tickets_id'], 'entities_id' => (int) $row['entities_id'],
            'date' => (string) $row['date'],
        ];
    }

    /** Jour courant dans le fuseau de référence (date métier d'une décision qui a lieu maintenant). */
    private static function today(): string
    {
        return Dates::day(Billing::toReference(self::now()));
    }

    /**
     * Notification native (événement compensation_decided de NotificationTargetCompensation, cible : le demandeur).
     */
    private static function notify(int $id): void
    {
        $item = new self();
        if ($item->getFromDB($id)) {
            NotificationEvent::raiseEvent('compensation_decided', $item, ['entities_id' => (int) $item->fields['entities_id']]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Libellés
    // ---------------------------------------------------------------------------------------------------------

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            CompensationRules::PENDING  => __('Pending', 'points'),
            CompensationRules::APPROVED => __('Approved', 'points'),
            CompensationRules::REJECTED => __('Rejected', 'points'),
            default                     => $status,
        };
    }

    public static function describe(RuleViolation $e): string
    {
        return match ($e->reason) {
            RuleViolation::COMPENSATION_STATE  => __('This compensation request has already been decided', 'points'),
            RuleViolation::COMPENSATION_SELF   => __('You made this request yourself: another user must decide it', 'points'),
            RuleViolation::COMPENSATION_AMOUNT => __('The amount exceeds what the debit leaves to compensate, or what was requested', 'points'),
            RuleViolation::COMPENSATION_TARGET => __('A compensation can only target a task or flatrate debit', 'points'),
            RuleViolation::REASON_REQUIRED     => __('A reason is required', 'points'),
            default                            => Billing::describe($e),
        };
    }

    // ---------------------------------------------------------------------------------------------------------
    // Recherche native : liste « Compensations en attente »
    // ---------------------------------------------------------------------------------------------------------

    /** Par défaut, les demandes en attente (le filtre se change dans la recherche). */
    public static function getDefaultSearchRequest(): array
    {
        return ['criteria' => [['field' => 3, 'searchtype' => 'equals', 'value' => CompensationRules::PENDING]], 'sort' => 2, 'order' => 'DESC'];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return $field === 'status'
            ? htmlescape(self::statusLabel((string) $values[$field]))
            : parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return \Dropdown::showFromArray($name, [
                CompensationRules::PENDING  => self::statusLabel(CompensationRules::PENDING),
                CompensationRules::APPROVED => self::statusLabel(CompensationRules::APPROVED),
                CompensationRules::REJECTED => self::statusLabel(CompensationRules::REJECTED),
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
            ['id' => '4', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '5', 'table' => 'glpi_contracts', 'field' => 'name', 'name' => __('Contract'), 'massiveaction' => false, 'datatype' => 'dropdown'],
            ['id' => '6', 'table' => $table, 'field' => 'operations_id', 'name' => __('Debit operation', 'points'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '7', 'table' => $table, 'field' => 'amount', 'name' => __('Requested amount (points)', 'points'), 'massiveaction' => false, 'datatype' => 'decimal'],
            ['id' => '8', 'table' => $table, 'field' => 'approved_amount', 'name' => __('Approved amount (points)', 'points'), 'massiveaction' => false, 'datatype' => 'decimal'],
            ['id' => '9', 'table' => 'glpi_users', 'field' => 'name', 'name' => __('Requested by', 'points'), 'massiveaction' => false, 'datatype' => 'dropdown'],
            ['id' => '10', 'table' => $table, 'field' => 'date_creation', 'name' => __('Creation date'), 'massiveaction' => false, 'datatype' => 'datetime'],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Section « Compensation » de l'onglet Points du ticket
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Données de la section (gabarit controls.html.twig) : débits du ticket compensables, formulaire de demande, demandes
     * et leur décision. Les droits affichés sont ceux que les actions contrôleront de nouveau à l'envoi du formulaire.
     *
     * @return array<string, mixed>
     */
    public static function viewData(\Ticket $ticket): array
    {
        global $CFG_GLPI, $DB;

        if (!self::canView() || !Session::haveAccessToEntity((int) $ticket->fields['entities_id'])) {
            return ['visible' => false];
        }
        $ticketsId = (int) $ticket->getID();
        $me        = (int) Session::getLoginUserID();
        $separated = (int) Config::value('compensation_separation') === 1;
        $debits    = [];
        foreach ($DB->request(['SELECT' => ['id', 'type', 'amount', 'date', 'items_id', 'entities_id'], 'FROM' => 'glpi_plugin_points_operations', 'WHERE' => ['tickets_id' => $ticketsId, 'type' => CompensationRules::TARGET_TYPES, 'amount' => ['<', 0]], 'ORDER' => 'id']) as $op) {
            // Comme l'action de demande : entité du DÉBIT (celle du contrat, pas du ticket) et ticket lisible
            if (!Session::haveAccessToEntity((int) $op['entities_id'])) {
                continue;
            }
            $gross = -Money::parse((string) $op['amount']);
            $debits[] = [
                'id'        => (int) $op['id'],
                'label'     => sprintf('#%d %s (%s)', $op['id'], Operation::typeLabel((string) $op['type']), Money::format($gross)),
                'remaining' => Money::format(Ledger::refundable((int) $op['id'])),
            ];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'ORDER' => 'id']) as $r) {
            // Une demande d'une entité hors des droits de l'utilisateur n'apparaît pas ; décider : entité de la LIGNE, comme decide()
            if (!Session::haveAccessToEntity((int) $r['entities_id'])) {
                continue;
            }
            $mine = (int) $r['users_id'] === $me;
            $pending = $r['status'] === CompensationRules::PENDING;
            $rows[] = [
                'id'          => (int) $r['id'],
                'operation'   => (int) $r['operations_id'],
                'status'      => (string) $r['status'],
                'status_label' => self::statusLabel((string) $r['status']),
                'amount'      => Money::format(Money::parse((string) $r['amount'])),
                'approved'    => Money::format(Money::parse((string) $r['approved_amount'])),
                'comment'     => (string) $r['comment'],
                'requester'   => (string) getUserName((int) $r['users_id']),
                'decider'     => (int) $r['users_id_decision'] > 0 ? (string) getUserName((int) $r['users_id_decision']) : '',
                'decision_comment' => (string) $r['decision_comment'],
                'credit'      => (int) $r['operations_id_credit'],
                'can_decide'  => $pending && self::canDecide() && !($separated && $mine),
                'self_blocked' => $pending && self::canDecide() && $separated && $mine,
            ];
        }

        return [
            'visible'     => true,
            'tickets_id'  => $ticketsId,
            'form_url'    => $CFG_GLPI['root_doc'] . '/plugins/points/front/compensation.form.php',
            'debits'      => $debits,
            'can_request' => self::canCreate() && $debits !== [],
            'rows'        => $rows,
        ];
    }
}
