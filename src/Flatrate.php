<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\FlatrateRules;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\RuleViolation;
use NotificationEvent;
use Session;

/**
 * Forfait d'un ticket (table glpi_plugin_points_flatrates ; docs/conception.md, règle 9). Les décisions pures (états,
 * montant, séparation des tâches) sont dans Domain\FlatrateRules ; cette classe y ajoute les droits, la lecture de GLPI,
 * la validation native du client (TicketValidation), les notifications et le débit (Ledger::debit, seule écriture du
 * journal).
 *
 * Les changements d'état ne passent pas par CommonDBTM::update : ils sont écrits par `move()`, une mise à jour
 * conditionnelle (`WHERE status = ancien`) qui rend chaque transition atomique (deux décisions concurrentes : une seule
 * gagne). Un update générique (formulaire, API) ne peut changer que le montant et la description, en brouillon.
 *
 * Droits : plugin_points_flatrate (READ voir, CREATE chiffrer, UPDATE modifier/soumettre/rouvrir, VALIDATE décider),
 * plus l'accès à l'entité du ticket et `Ticket::can(READ)` pour toute action.
 */
class Flatrate extends CommonDBTM
{
    use ProtectsFields;
    use StateRecord;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::FLATRATE;

    public bool $dohistory = false;

    /** Champs que ni un formulaire ni l'API ne modifient jamais (l'état passe par move()). */
    private const FROZEN = [
        'tickets_id', 'contracts_id', 'status', 'users_id', 'users_id_bu', 'date_bu', 'bu_comment', 'users_id_validate',
        'ticketvalidations_id', 'approved_amount', 'operations_id', 'entities_id', 'is_recursive', 'date_creation',
    ];

    /** Cible de notification propre au plugin : les Responsables BU (droit VALIDATE) de l'entité du ticket. */
    public const TARGET_BU = 9001;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Flatrates', 'points') : __('Flatrate', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-package';
    }

    // ---------------------------------------------------------------------------------------------------------
    // Droits
    // ---------------------------------------------------------------------------------------------------------

    public static function canView(): bool
    {
        return Session::haveRight(Rights::FLATRATE, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(Rights::FLATRATE, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::FLATRATE, UPDATE);
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /** Valider ou refuser (Responsable BU) : bit VALIDATE du droit. */
    public static function canDecide(): bool
    {
        return Session::haveRight(Rights::FLATRATE, Rights::VALIDATE);
    }

    public function canViewItem(): bool
    {
        return static::canView() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1))
            && self::canReadTicket((int) ($this->fields['tickets_id'] ?? 0));
    }

    public function canCreateItem(): bool
    {
        $ticketsId = (int) ($this->input['tickets_id'] ?? 0);

        return static::canCreate() && Session::haveAccessToEntity(self::ticketEntity($ticketsId)) && self::canReadTicket($ticketsId);
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1))
            && self::canReadTicket((int) ($this->fields['tickets_id'] ?? 0));
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

    private static function ticketEntity(int $ticketsId): int
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketsId], 'LIMIT' => 1])->current();

        return $row === null ? -1 : (int) $row['entities_id'];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Création et modification (brouillon) : prepareInputFor*, appelés par add() / update()
    // ---------------------------------------------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        $ticketsId = (int) ($input['tickets_id'] ?? 0);
        $entity    = self::ticketEntity($ticketsId);
        if ($entity < 0) {
            Session::addMessageAfterRedirect(__s('Unknown ticket', 'points'), false, ERROR);

            return false;
        }

        try {
            $cents = FlatrateRules::parseAmount((string) ($input['amount'] ?? ''));
            $ctx   = Billing::context($ticketsId);
            if ($ctx['contracts_id'] < 1 || !$ctx['is_billable'] || $ctx['ambiguous']) {
                throw new RuleViolation(RuleViolation::FLATRATE_NO_CONTRACT, 'No Points contract');
            }
            if (self::openFor($ticketsId) !== [] || self::countOf($ticketsId, [FlatrateRules::DEBITED]) > 0) {
                throw new RuleViolation(RuleViolation::FLATRATE_OPEN, 'The ticket already has a flatrate');
            }
            self::assertNoTaskDebit($ticketsId);
            self::assertTicketNotSolved($ticketsId);
        } catch (RuleViolation $e) {
            Session::addMessageAfterRedirect(htmlescape(self::describe($e)), false, ERROR);

            return false;
        }

        // Liste blanche : l'état et les champs d'approbation ne viennent jamais de la saisie
        return [
            'tickets_id'           => $ticketsId,
            'entities_id'          => $entity,
            'is_recursive'         => 0,
            'contracts_id'         => $ctx['contracts_id'],
            'amount'               => Money::format($cents),
            'description'          => trim((string) ($input['description'] ?? '')),
            'status'               => FlatrateRules::DRAFT,
            'users_id'             => (int) Session::getLoginUserID(),
            'users_id_bu'          => 0,
            'bu_comment'           => '',
            'users_id_validate'    => 0,
            'ticketvalidations_id' => 0,
            'approved_amount'      => '0.00',
            'operations_id'        => 0,
        ];
    }

    public function post_addItem()
    {
        $id = (int) $this->getID();
        self::history($id, '', FlatrateRules::DRAFT);
        // Deux créations concurrentes : la plus ancienne reste (le contrôle de prepareInputForAdd n'est pas atomique)
        if (self::openFor((int) $this->fields['tickets_id'], $id, true) !== []) {
            global $DB;
            $DB->delete(self::getTable(), ['id' => $id]);
            $DB->delete('glpi_logs', ['itemtype' => self::class, 'items_id' => $id]);
            // Un échec propre : l'ajout n'est pas annoncé comme réussi (formulaire : message ; API : erreur au lieu de 201)
            throw new RuleViolation(RuleViolation::FLATRATE_OPEN, 'The ticket already has a flatrate');
        }
    }

    /**
     * Des tâches du ticket sont déjà débitées : aucun forfait ne peut naître, revenir en brouillon, être soumis ni validé
     * (double facturation : le temps passé et le forfait).
     *
     * @throws RuleViolation FLATRATE_TASKS_DEBITED
     */
    private static function assertNoTaskDebit(int $ticketsId): void
    {
        if (Billing::ticketHasDebit($ticketsId)) {
            throw new RuleViolation(RuleViolation::FLATRATE_TASKS_DEBITED, 'Tasks of this ticket are already debited');
        }
    }

    /**
     * Un ticket résolu ou clos ne reçoit plus de forfait (création, soumission).
     *
     * @throws RuleViolation FLATRATE_TICKET_CLOSED
     */
    private static function assertTicketNotSolved(int $ticketsId): void
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['status'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketsId], 'LIMIT' => 1])->current();
        if ($row !== null && in_array((int) $row['status'], array_merge(\Ticket::getSolvedStatusArray(), \Ticket::getClosedStatusArray()), true)) {
            throw new RuleViolation(RuleViolation::FLATRATE_TICKET_CLOSED, 'The ticket is solved or closed');
        }
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(htmlescape(self::describe(new RuleViolation(RuleViolation::FLATRATE_FROZEN))), false, ERROR);

            return false;
        }
        $changesAmount = array_key_exists('amount', $input) && !self::sameValue((string) ($this->fields['amount'] ?? ''), (string) $input['amount']);
        $changesText   = array_key_exists('description', $input) && (string) $input['description'] !== (string) ($this->fields['description'] ?? '');
        if (($changesAmount || $changesText) && !FlatrateRules::isEditable((string) $this->fields['status'])) {
            Session::addMessageAfterRedirect(htmlescape(self::describe(new RuleViolation(RuleViolation::FLATRATE_FROZEN))), false, ERROR);

            return false;
        }
        if (array_key_exists('amount', $input)) {
            try {
                $input['amount'] = Money::format(FlatrateRules::parseAmount((string) $input['amount']));
            } catch (RuleViolation $e) {
                Session::addMessageAfterRedirect(htmlescape(self::describe($e)), false, ERROR);

                return false;
            }
        }
        if (isset($input['description'])) {
            $input['description'] = trim((string) $input['description']);
        }

        return $input;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Forfaits non terminaux (brouillon, soumis, proposé, approuvé) d'un ticket.
     *
     * @param bool $olderOnly ne garder que ceux dont l'id est inférieur à $exceptId (contrôle après création)
     * @return list<array<string, mixed>>
     */
    public static function openFor(int $ticketsId, int $exceptId = 0, bool $olderOnly = false): array
    {
        global $DB;

        $where = ['tickets_id' => $ticketsId, 'status' => FlatrateRules::OPEN];
        if ($exceptId > 0) {
            $where['id'] = [$olderOnly ? '<' : '!=', $exceptId];
        }

        return array_values(iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id'])));
    }

    /**
     * @param list<string> $statuses
     */
    private static function countOf(int $ticketsId, array $statuses): int
    {
        global $DB;

        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId, 'status' => $statuses]]));
    }

    /**
     * Un forfait non refusé ni annulé existe sur le ticket : ses tâches ne sont pas décomptées (Billing::isFlatrateTicket).
     */
    public static function countsForTicket(int $ticketsId): bool
    {
        return self::countOf($ticketsId, FlatrateRules::COUNTING) > 0;
    }

    /**
     * Forfait lié à une validation native (TicketValidation), null s'il n'y en a pas.
     *
     * @return array<string, mixed>|null
     */
    public static function byValidation(int $validationsId): ?array
    {
        global $DB;

        if ($validationsId < 1) {
            return null;
        }
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['ticketvalidations_id' => $validationsId], 'LIMIT' => 1])->current();

        return $row === null ? null : $row;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            FlatrateRules::DRAFT          => __('Draft', 'points'),
            FlatrateRules::SUBMITTED      => __('Submitted to the BU manager', 'points'),
            FlatrateRules::BU_REFUSED     => __('Refused by the BU manager', 'points'),
            FlatrateRules::PROPOSED       => __('Proposed to the customer', 'points'),
            FlatrateRules::APPROVED       => __('Approved by the customer', 'points'),
            FlatrateRules::CLIENT_REFUSED => __('Refused by the customer', 'points'),
            FlatrateRules::DEBITED        => __('Debited', 'points'),
            FlatrateRules::CANCELLED      => __('Cancelled', 'points'),
            default                       => $status,
        };
    }

    /**
     * Message traduit d'un refus de règle des forfaits ; les autres (grille, lot, solde) sont ceux du décompte.
     */
    public static function describe(RuleViolation $e): string
    {
        return match ($e->reason) {
            RuleViolation::INVALID_AMOUNT         => __('The flatrate amount must be a positive number', 'points'),
            RuleViolation::FLATRATE_STATE         => __('This action is not possible in the current state of the flatrate', 'points'),
            RuleViolation::FLATRATE_OPEN          => __('This ticket already has a flatrate in progress or debited', 'points'),
            RuleViolation::FLATRATE_SELF          => __('The BU manager who priced a flatrate cannot validate it', 'points'),
            RuleViolation::FLATRATE_FROZEN        => __('A flatrate can only be modified while it is a draft, and its state and approved amount never change by hand', 'points'),
            RuleViolation::FLATRATE_NO_CONTRACT   => __('The ticket needs one billable Points contract before a flatrate can be priced', 'points'),
            RuleViolation::FLATRATE_TASKS_DEBITED => __('Tasks of this ticket are already debited: no flatrate is possible', 'points'),
            RuleViolation::FLATRATE_TICKET_CLOSED => __('A solved or closed ticket cannot receive a flatrate', 'points'),
            RuleViolation::FLATRATE_VALIDATOR     => __('The customer approver must be a user of the ticket or of its entity who is allowed to answer approvals', 'points'),
            RuleViolation::FLATRATE_REASON        => __('A comment is required to refuse a flatrate', 'points'),
            RuleViolation::FLATRATE_NO_ESTIMATE_TYPE => sprintf(__('The estimate reads the price of the intervention type "%s", which does not exist: restore it in the intervention types', 'points'), Install::INTERVENTION_TYPES[1]),
            default                               => Billing::describe($e),
        };
    }

    // ---------------------------------------------------------------------------------------------------------
    // Transitions (technicien et Responsable BU)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Chiffre un forfait (brouillon) : droits CREATE, entité et lecture du ticket (Flatrate::check).
     *
     * @return int identifiant du forfait, 0 si un contrôle l'a refusé (message déjà posé)
     * @throws AccessDeniedHttpException
     */
    public static function createDraft(int $ticketsId, string $amount, string $description): int
    {
        $item  = new self();
        $input = ['tickets_id' => $ticketsId, 'amount' => $amount, 'description' => $description];
        $item->check(-1, CREATE, $input);
        $id = $item->add($input);

        return $id === false ? 0 : (int) $id;
    }

    /**
     * Modifie montant et description d'un brouillon (droit UPDATE par can()).
     *
     * @throws AccessDeniedHttpException|NotFoundHttpException
     */
    public static function updateDraft(int $id, string $amount, string $description): bool
    {
        $item = self::loadFor($id, UPDATE);

        return $item->update(['id' => $id, 'amount' => $amount, 'description' => $description]);
    }

    /**
     * Brouillon → soumis au Responsable BU (technicien : UPDATE). Notifie les Responsables BU.
     *
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function submit(int $id): void
    {
        $item = self::loadFor($id, UPDATE);
        FlatrateRules::assertTransition((string) $item->fields['status'], FlatrateRules::SUBMITTED);
        FlatrateRules::parseAmount((string) $item->fields['amount']);
        self::assertNoTaskDebit((int) $item->fields['tickets_id']);
        self::assertTicketNotSolved((int) $item->fields['tickets_id']);
        if (!self::move($id, [FlatrateRules::DRAFT], FlatrateRules::SUBMITTED)) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE);
        }
        self::notify('flatrate_submitted', $id);
    }

    /**
     * Refusé par le BU → brouillon, pour correction (technicien : UPDATE). Un seul forfait en cours par ticket.
     *
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function reopen(int $id): void
    {
        $item = self::loadFor($id, UPDATE);
        FlatrateRules::assertTransition((string) $item->fields['status'], FlatrateRules::DRAFT);
        $ticketsId = (int) $item->fields['tickets_id'];
        self::assertNoTaskDebit($ticketsId);
        if (self::openFor($ticketsId, $id) !== []) {
            throw new RuleViolation(RuleViolation::FLATRATE_OPEN);
        }
        if (!self::move($id, [FlatrateRules::BU_REFUSED], FlatrateRules::DRAFT)) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE);
        }
        if (self::openFor($ticketsId, $id, true) !== []) {
            // Concurrence : un autre forfait a pris la place entre-temps, celui-ci reste refusé
            self::move($id, [FlatrateRules::DRAFT], FlatrateRules::BU_REFUSED);
            throw new RuleViolation(RuleViolation::FLATRATE_OPEN);
        }
    }

    /**
     * Annule un forfait avant son approbation (technicien : UPDATE ; Responsable BU : VALIDATE).
     *
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function cancel(int $id): void
    {
        $item = new self();
        if (!$item->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        $allowed = (self::canUpdate() || self::canDecide())
            && Session::haveAccessToEntity((int) $item->fields['entities_id'])
            && self::canReadTicket((int) $item->fields['tickets_id']);
        if (!$allowed) {
            throw new AccessDeniedHttpException();
        }
        $from = (string) $item->fields['status'];
        FlatrateRules::assertTransition($from, FlatrateRules::CANCELLED);
        if (!self::move($id, [$from], FlatrateRules::CANCELLED)) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE);
        }
        if ($from === FlatrateRules::PROPOSED) {
            self::purgeProposedValidation($item->fields);
        }
    }

    /**
     * Supprime la validation native en attente d'un forfait proposé : GLPI la purge et le statut global de validation du ticket est
     * recalculé. À appeler une fois le forfait sorti de l'état « proposé » (l'état « annulé » ne protège plus la validation :
     * keepsValidation), par l'annulation du forfait et par la désinstallation du plugin.
     *
     * @param array<string, mixed> $row ligne du forfait
     */
    public static function purgeProposedValidation(array $row): void
    {
        if ((int) $row['ticketvalidations_id'] < 1) {
            return;
        }
        (new \TicketValidation())->delete(['id' => (int) $row['ticketvalidations_id']], true);
        // GLPI 12 recalcule le statut AVANT de supprimer l'étape de validation devenue vide (CommonITILValidation::post_purgeItem,
        // src/CommonITILValidation.php:533-538) : il resterait « refusé ». Recalcul après la purge.
        $ticket = new \Ticket();
        if ($ticket->getFromDB((int) $row['tickets_id'])) {
            $ticket->update(['id' => $ticket->getID(), 'global_validation' => \TicketValidation::computeValidationStatus($ticket), '_from_itilvalidation' => true]);
        }
    }

    /**
     * Décision du Responsable BU (VALIDATE) sur un forfait soumis : refus (commentaire obligatoire, retour possible en
     * brouillon) ou proposition au client, par une validation de ticket native dont l'approbateur est choisi parmi les
     * utilisateurs du ticket ou de l'entité (par défaut le demandeur). Le BU ne valide pas son propre chiffrage.
     *
     * @param int $validatorId approbateur client ; 0 = demandeur du ticket
     * @throws RuleViolation|AccessDeniedHttpException|NotFoundHttpException
     */
    public static function decide(int $id, bool $accept, string $comment, int $validatorId = 0): void
    {
        $item = self::loadFor($id, null);
        $row  = $item->fields;
        $by   = (int) Session::getLoginUserID();
        if ((int) Config::value('flatrate_separation') === 1) {
            FlatrateRules::assertSeparation((int) $row['users_id'], $by);
        }
        FlatrateRules::assertTransition((string) $row['status'], $accept ? FlatrateRules::PROPOSED : FlatrateRules::BU_REFUSED);
        $comment = trim($comment);
        $set     = ['users_id_bu' => $by, 'date_bu' => self::now(), 'bu_comment' => $comment];

        if (!$accept) {
            if ($comment === '') {
                throw new RuleViolation(RuleViolation::FLATRATE_REASON);
            }
            if (!self::move($id, [FlatrateRules::SUBMITTED], FlatrateRules::BU_REFUSED, $set)) {
                throw new RuleViolation(RuleViolation::FLATRATE_STATE);
            }
            self::notify('flatrate_decided', $id);

            return;
        }

        $ticketsId  = (int) $row['tickets_id'];
        self::assertNoTaskDebit($ticketsId);
        $validator  = $validatorId > 0 ? $validatorId : self::defaultValidator($ticketsId);
        if ($validator < 1 || !isset(self::validatorCandidates($ticketsId, (int) $row['entities_id'], [(int) $row['users_id'], $by])[$validator])) {
            throw new RuleViolation(RuleViolation::FLATRATE_VALIDATOR);
        }
        // Le débit doit être possible à la date d'approbation (comme un débit de tâche : dépassement selon allow_overdraft)
        $cents = Money::parse((string) $row['amount']);
        Ledger::assertDebitable((int) $row['contracts_id'], [[$cents, Billing::today()]]);

        // Prise de l'état d'abord (atomique), validation native ensuite : deux Responsables BU ne créent jamais deux validations
        if (!self::move($id, [FlatrateRules::SUBMITTED], FlatrateRules::PROPOSED, $set + ['users_id_validate' => $validator])) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE);
        }
        $validation = new \TicketValidation();
        $validationId = $validation->add([
            'tickets_id'         => $ticketsId,
            'entities_id'        => (int) $row['entities_id'],
            'itemtype_target'    => \User::class,
            'items_id_target'    => $validator,
            'comment_submission' => '<p>' . htmlescape(sprintf(
                __('Flatrate proposal: %1$s points. %2$s', 'points'),
                Money::format($cents),
                (string) $row['description']
            )) . '</p>',
        ]);
        if ($validationId === false) {
            // Retour à « soumis » : le BU peut recommencer (état de forfait, jamais le journal)
            self::move($id, [FlatrateRules::PROPOSED], FlatrateRules::SUBMITTED, ['users_id_bu' => 0, 'date_bu' => null, 'bu_comment' => '', 'users_id_validate' => 0]);
            Session::addMessageAfterRedirect(__s('The approval request could not be created', 'points'), false, ERROR);

            return;
        }
        global $DB;
        $DB->update(self::getTable(), ['ticketvalidations_id' => (int) $validationId], ['id' => $id]);
        self::notify('flatrate_decided', $id);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Réponse du client (validation native)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Refus éventuel d'une réponse du client sur la validation d'un forfait (POST_PREPAREUPDATE) : le forfait doit être
     * encore proposé, et, pour une acceptation, le débit doit être possible (solde, lot valide à la date du jour).
     *
     * @param array<string, mixed> $validation champs de la validation avant modification
     * @param array<string, mixed> $input
     * @return string|null message de refus, null si la réponse peut être enregistrée
     */
    public static function answerProblem(array $validation, array $input): ?string
    {
        $flatrate = self::byValidation((int) ($validation['id'] ?? 0));
        if ($flatrate === null) {
            return null;
        }
        // La demande (texte, cible) d'une validation de forfait est celle que le Responsable BU a créée : jamais modifiée
        foreach (['comment_submission', 'itemtype_target', 'items_id_target'] as $field) {
            if (array_key_exists($field, $input) && (string) $input[$field] !== (string) ($validation[$field] ?? '')) {
                return __('The request of an approval that belongs to a flatrate cannot be changed', 'points');
            }
        }
        if (!isset($input['status']) || (int) $input['status'] === (int) $validation['status']) {
            return null;
        }
        if ($flatrate['status'] !== FlatrateRules::PROPOSED) {
            return __('This approval belongs to a flatrate that is no longer waiting for the customer answer', 'points');
        }
        if ((int) $input['status'] === \CommonITILValidation::ACCEPTED) {
            try {
                Ledger::assertDebitable((int) $flatrate['contracts_id'], [[Money::parse((string) $flatrate['amount']), Billing::today()]]);
            } catch (RuleViolation $e) {
                return sprintf(__('The flatrate cannot be debited: %s', 'points'), self::describe($e));
            }
        }

        return null;
    }

    /**
     * Réponse enregistrée (ITEM_UPDATE de la validation) : acceptée → approuvé, montant figé, débit tout de suite si le
     * moment du débit est « à la tâche » ; refusée → refusé par le client (retour au décompte des tâches).
     *
     * @param array<string, mixed> $validation champs de la validation après modification
     */
    public static function onAnswer(array $validation): void
    {
        $flatrate = self::byValidation((int) ($validation['id'] ?? 0));
        if ($flatrate === null) {
            return;
        }
        $id     = (int) $flatrate['id'];
        $status = (int) ($validation['status'] ?? 0);
        if ($status === \CommonITILValidation::ACCEPTED) {
            // Montant figé = montant chiffré (le brouillon n'est plus modifiable depuis la soumission)
            if (!self::move($id, [FlatrateRules::PROPOSED], FlatrateRules::APPROVED, ['approved_amount' => $flatrate['amount']])) {
                return;
            }
            self::notify('flatrate_decided', $id);
            if (Config::value('debit_moment') === ConfigValues::DEBIT_TASK) {
                try {
                    self::debit($id);
                } catch (RuleViolation $e) {
                    Session::addMessageAfterRedirect(htmlescape(sprintf(__('The flatrate cannot be debited: %s', 'points'), self::describe($e))), false, ERROR);
                }
            }
        } elseif ($status === \CommonITILValidation::REFUSED) {
            if (self::move($id, [FlatrateRules::PROPOSED], FlatrateRules::CLIENT_REFUSED)) {
                self::notify('flatrate_decided', $id);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Débit
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Jour de l'approbation par le client (date de la réponse de la validation, lue dans le fuseau de référence).
     */
    public static function approvalDay(array $row): string
    {
        global $DB;

        $validation = (int) ($row['ticketvalidations_id'] ?? 0) > 0
            ? $DB->request(['SELECT' => ['validation_date'], 'FROM' => 'glpi_ticketvalidations', 'WHERE' => ['id' => $row['ticketvalidations_id']], 'LIMIT' => 1])->current()
            : null;
        $date = trim((string) ($validation['validation_date'] ?? ''));

        return Dates::day(Billing::toReference($date !== '' ? $date : self::now()));
    }

    /**
     * Débite le montant approuvé, tel quel (opération `debit_flatrate` liée au forfait, unique : le débit est définitif),
     * et passe le forfait à « débité » dans la même transaction du journal. Sans effet s'il est déjà débité.
     * Appelé hors de toute transaction : Ledger::debit ouvre la sienne et verrouille le contrat.
     *
     * @return int identifiant de l'opération
     * @throws RuleViolation
     */
    public static function debit(int $id): int
    {
        $row = self::row($id);
        if ($row === null) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE, 'Unknown flatrate');
        }
        if ($row['status'] === FlatrateRules::DEBITED) {
            return (int) $row['operations_id'];
        }
        FlatrateRules::assertTransition((string) $row['status'], FlatrateRules::DEBITED);
        self::assertCandidateContract($row);
        $cents = Money::parse((string) $row['approved_amount']);
        $day   = self::approvalDay($row);
        try {
            $debit = Ledger::debit((int) $row['contracts_id'], OpType::DEBIT_FLATRATE, $cents, $day, [
                // Unicité revérifiée par Ledger sous le verrou du contrat ; l'état « débité » est écrit dans la même transaction
                'unique'     => true,
                'inside'     => static function (int $operationId) use ($id): void {
                    if (!self::move($id, [FlatrateRules::APPROVED], FlatrateRules::DEBITED, ['operations_id' => $operationId])) {
                        // Transition perdue (autre débit, annulation) : le journal annule tout ; traité comme « déjà débité »
                        throw new RuleViolation(RuleViolation::ALREADY_DEBITED, 'The flatrate is no longer approved');
                    }
                },
                'itemtype'   => self::class,
                'items_id'   => $id,
                'tickets_id' => (int) $row['tickets_id'],
                'details'    => [
                    'flatrates_id'         => $id,
                    'amount'               => Money::format($cents),
                    'ticketvalidations_id' => (int) $row['ticketvalidations_id'],
                    'approval_date'        => $day,
                    'debit_moment'         => (string) Config::value('debit_moment'),
                ],
            ]);
        } catch (RuleViolation $e) {
            if ($e->reason !== RuleViolation::ALREADY_DEBITED) {
                throw $e;
            }
            $existing = self::debitedOperation($id);
            if ($existing < 1) {
                // Transition perdue sans débit validé (le forfait a changé d'état entre-temps) : ne jamais passer à « débité » sans opération
                throw new RuleViolation(RuleViolation::FLATRATE_STATE, 'The flatrate changed state before it could be debited');
            }
            self::move($id, [FlatrateRules::APPROVED], FlatrateRules::DEBITED, ['operations_id' => $existing]);

            return $existing;
        }

        return $debit['operations_id'];
    }

    /**
     * Le contrat du forfait doit encore être candidat au débit du ticket (contrat lié, de l'entité du ticket ou récursif d'une entité mère) :
     * un ticket passé chez un autre client ne débite pas le contrat de l'ancien (cloisonnement, même règle que les tâches : Billing::context).
     *
     * @param array<string, mixed> $row
     * @throws RuleViolation
     */
    private static function assertCandidateContract(array $row): void
    {
        if (!isset(Billing::context((int) $row['tickets_id'])['candidates'][(int) $row['contracts_id']])) {
            throw new RuleViolation(RuleViolation::FLATRATE_NO_CONTRACT, 'The contract is no longer a Points contract of the ticket');
        }
    }

    private static function debitedOperation(int $id): int
    {
        return Ledger::debitOf(OpType::DEBIT_FLATRATE, self::class, $id);
    }

    /**
     * Clôture du ticket : débite les forfaits approuvés (le moment « à la clôture », ou un débit « à la tâche » qui a
     * échoué à l'approbation). Les messages des forfaits non débités sont renvoyés : la clôture est déjà faite.
     *
     * @return array{debited: int, errors: list<string>}
     */
    public static function debitOnClosure(int $ticketsId): array
    {
        global $DB;

        $out = ['debited' => 0, 'errors' => []];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId, 'status' => FlatrateRules::APPROVED], 'ORDER' => 'id']) as $row) {
            try {
                $out['debited'] += self::debit((int) $row['id']) > 0 ? 1 : 0;
            } catch (RuleViolation $e) {
                $out['errors'][] = sprintf(__('Flatrate %1$d: %2$s', 'points'), $row['id'], self::describe($e));
            }
        }

        return $out;
    }

    /**
     * Refus de clôture : un forfait non approuvé (brouillon, soumis, proposé), ou approuvé mais non débitable. Null si la
     * clôture est possible. Lecture seule.
     */
    public static function closureProblem(int $ticketsId): ?string
    {
        global $DB;

        if (self::countOf($ticketsId, FlatrateRules::BLOCKING_CLOSURE) > 0) {
            return __('The flatrate of this ticket is not approved by the customer yet: the ticket cannot be closed', 'points');
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId, 'status' => FlatrateRules::APPROVED]]) as $row) {
            try {
                self::assertCandidateContract($row);
                Ledger::assertDebitable((int) $row['contracts_id'], [[Money::parse((string) $row['approved_amount']), self::approvalDay($row)]]);
            } catch (RuleViolation $e) {
                return sprintf(__('The flatrate cannot be debited: %s', 'points'), self::describe($e));
            }
        }

        return null;
    }

    /**
     * Un forfait lié à la validation ne se supprime pas tant qu'il est proposé, approuvé ou débité.
     */
    public static function keepsValidation(int $validationsId): bool
    {
        $row = self::byValidation($validationsId);

        return $row !== null && in_array($row['status'], FlatrateRules::KEEPS_VALIDATION, true);
    }

    /**
     * Un forfait proposé, approuvé ou débité empêche la suppression définitive du ticket (le journal y fait référence).
     */
    public static function blocksTicketPurge(int $ticketsId): bool
    {
        return self::countOf($ticketsId, FlatrateRules::KEEPS_VALIDATION) > 0;
    }

    /**
     * Ticket purgé : nettoyage de ses forfaits (le journal n'est jamais touché ; un forfait débité empêche déjà la purge).
     */
    public static function cleanTicket(int $ticketsId): void
    {
        global $DB;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId]]) as $row) {
            $DB->delete('glpi_logs', ['itemtype' => self::class, 'items_id' => $row['id']]);
        }
        $DB->delete(self::getTable(), ['tickets_id' => $ticketsId]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Approbateurs du client
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Demandeur du ticket (le premier), 0 s'il n'y en a pas.
     */
    public static function defaultValidator(int $ticketsId): int
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ticketsId, 'type' => \CommonITILActor::REQUESTER, 'users_id' => ['>', 0]], 'ORDER' => 'id', 'LIMIT' => 1])->current();

        return $row === null ? 0 : (int) $row['users_id'];
    }

    /**
     * Approbateurs possibles : demandeurs et observateurs du ticket, et utilisateurs ayant un profil dans l'entité du ticket
     * elle-même (pas les parentes). Actifs, non supprimés, triés par nom, sans limite. Jamais l'auteur du forfait, celui
     * qui décide, ni un utilisateur dont un profil porte CREATE ou VALIDATE sur plugin_points_flatrate (il chiffre ou valide
     * des forfaits : il ne peut pas non plus les approuver pour le client). Seuls restent ceux qui peuvent répondre à une validation de
     * ticket dans l'entité du ticket : droit natif `ticketvalidation` VALIDATEREQUEST ou VALIDATEINCIDENT, lu par la requête de la liste
     * native des approbateurs (User::getSqlSearchResult, droits `validate_request` et `validate_incident` : src/CommonITILValidation.php:952,
     * src/User.php:4285-4304 en 12.0.1) ; sans lui, l'approbateur ne pourrait pas répondre (CommonITILValidation::canUpdate).
     *
     * @param list<int> $excluded utilisateurs écartés (auteur du forfait, décideur)
     * @return array<int, string> id => nom
     */
    public static function validatorCandidates(int $ticketsId, int $entitiesId, array $excluded = []): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => [
            'tickets_id' => $ticketsId, 'users_id' => ['>', 0], 'type' => [\CommonITILActor::REQUESTER, \CommonITILActor::OBSERVER],
        ]]) as $row) {
            $ids[(int) $row['users_id']] = true;
        }
        foreach ($DB->request([
            'SELECT'   => ['users_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_profiles_users',
            'WHERE'    => ['entities_id' => $entitiesId],
        ]) as $row) {
            $ids[(int) $row['users_id']] = true;
        }
        $barredProfiles = [];
        foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => [
            'name' => Rights::FLATRATE, 'OR' => [['rights' => ['&', Rights::CREATE]], ['rights' => ['&', Rights::VALIDATE]]],
        ]]) as $row) {
            $barredProfiles[] = (int) $row['profiles_id'];
        }
        if ($barredProfiles !== []) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'DISTINCT' => true, 'FROM' => 'glpi_profiles_users', 'WHERE' => ['profiles_id' => $barredProfiles]]) as $row) {
                unset($ids[(int) $row['users_id']]);
            }
        }
        foreach ($excluded as $userId) {
            unset($ids[$userId]);
        }
        if ($ids !== []) {
            $canAnswer = [];
            foreach (\User::getSqlSearchResult(false, ['validate_request', 'validate_incident'], $entitiesId) as $row) {
                $canAnswer[(int) $row['id']] = true;
            }
            $ids = array_intersect_key($ids, $canAnswer);
        }
        $names = [];
        if ($ids !== []) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => array_keys($ids), 'is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'name']) as $row) {
                $names[(int) $row['id']] = (string) getUserName((int) $row['id']);
            }
        }

        return $names;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Section « Forfait » de l'onglet Points du ticket
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Données de la section Forfait (gabarit flatrate.html.twig). Les droits affichés sont ceux que les actions
     * contrôleront de nouveau à l'envoi du formulaire.
     *
     * @return array<string, mixed>
     */
    public static function viewData(\Ticket $ticket): array
    {
        global $CFG_GLPI, $DB;

        if (!self::canView()) {
            return ['visible' => false];
        }
        $ticketsId = (int) $ticket->getID();
        $entity    = (int) $ticket->fields['entities_id'];
        $inEntity  = Session::haveAccessToEntity($entity);
        $me        = (int) Session::getLoginUserID();
        $rows      = array_values(iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'ORDER' => 'id DESC'])));
        $current   = null;
        foreach ($rows as $row) {
            if (in_array($row['status'], FlatrateRules::COUNTING, true)) {
                $current = $row;
                break;
            }
        }
        $current ??= $rows[0] ?? null;

        $ctx       = Billing::context($ticketsId);
        $canCreate = $inEntity && self::canCreate() && $ctx['contracts_id'] > 0 && $ctx['is_billable'] && !$ctx['ambiguous']
            && !in_array($current['status'] ?? '', FlatrateRules::COUNTING, true) && !Billing::ticketHasDebit($ticketsId);
        $view = ['visible' => true, 'tickets_id' => $ticketsId, 'form_url' => $CFG_GLPI['root_doc'] . '/plugins/points/front/flatrate.form.php', 'can_create' => $canCreate];

        $view['others'] = [];
        foreach ($rows as $row) {
            if ($current !== null && (int) $row['id'] === (int) $current['id']) {
                continue;
            }
            $view['others'][] = ['id' => (int) $row['id'], 'status' => self::statusLabel((string) $row['status']), 'amount' => Money::format(Money::parse((string) $row['amount']))];
        }

        $view['current'] = null;
        $view['history'] = [];
        if ($current !== null) {
            $status   = (string) $current['status'];
            $validation = (int) $current['ticketvalidations_id'] > 0
                ? $DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['id' => $current['ticketvalidations_id']], 'LIMIT' => 1])->current()
                : null;
            $view['current'] = [
                'id'           => (int) $current['id'],
                'status'       => $status,
                'status_label' => self::statusLabel($status),
                'amount'       => Money::format(Money::parse((string) $current['amount'])),
                'approved'     => Money::format(Money::parse((string) $current['approved_amount'])),
                'description'  => (string) $current['description'],
                'technician'   => (string) getUserName((int) $current['users_id']),
                'bu'           => (int) $current['users_id_bu'] > 0 ? (string) getUserName((int) $current['users_id_bu']) : '',
                'bu_comment'   => (string) $current['bu_comment'],
                'validator'    => (int) $current['users_id_validate'] > 0 ? (string) getUserName((int) $current['users_id_validate']) : '',
                'validation'   => $validation === null ? '' : \CommonITILValidation::getStatus((int) $validation['status']),
                'operation'    => (int) $current['operations_id'],
                'moment'       => Config::value('debit_moment') === ConfigValues::DEBIT_TASK ? __('When the customer approves', 'points') : __('When the ticket is closed', 'points'),
            ];
            $mine = (int) $current['users_id'] === $me;
            $view['can_edit']   = $inEntity && $status === FlatrateRules::DRAFT && self::canUpdate();
            $view['can_submit'] = $view['can_edit'];
            $view['can_reopen'] = $inEntity && $status === FlatrateRules::BU_REFUSED && self::canUpdate();
            $view['can_cancel'] = $inEntity && in_array($status, [FlatrateRules::DRAFT, FlatrateRules::SUBMITTED, FlatrateRules::BU_REFUSED, FlatrateRules::PROPOSED], true)
                && (self::canUpdate() || self::canDecide());
            $separated = (int) Config::value('flatrate_separation') === 1 && $mine;
            $view['can_decide'] = $inEntity && $status === FlatrateRules::SUBMITTED && self::canDecide() && !$separated;
            $view['self_blocked'] = $inEntity && $status === FlatrateRules::SUBMITTED && self::canDecide() && $separated;
            if ($view['can_decide']) {
                $view['validators']        = self::validatorCandidates($ticketsId, $entity, [(int) $current['users_id'], $me]);
                $view['default_validator'] = self::defaultValidator($ticketsId);
            }
            foreach ($DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['itemtype' => self::class, 'items_id' => $current['id']], 'ORDER' => 'id']) as $log) {
                $view['history'][] = [
                    'date' => \Html::convDateTime((string) $log['date_mod']),
                    'user' => (string) $log['user_name'],
                    'from' => $log['old_value'] === '' ? '' : self::statusLabel((string) $log['old_value']),
                    'to'   => self::statusLabel((string) $log['new_value']),
                ];
            }
        }
        $view['estimate'] = $view['can_create'] || ($view['can_edit'] ?? false);

        return $view;
    }

    /**
     * Estimation d'aide au chiffrage : heures × coût de la grille du contrat (type « Ordre de travail ») à la date du jour,
     * avec le calcul d'une tâche (durée arrondie au quart d'heure, type de jour d'aujourd'hui).
     *
     * @throws RuleViolation
     */
    public static function estimate(int $ticketsId, string $hours): string
    {
        global $DB;

        try {
            $hoursCents = Money::parse($hours);
        } catch (\InvalidArgumentException) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT);
        }
        $ctx = Billing::context($ticketsId);
        $type = $DB->request(['SELECT' => ['id'], 'FROM' => InterventionType::getTable(), 'WHERE' => ['name' => Install::INTERVENTION_TYPES[1]], 'LIMIT' => 1])->current();
        if ($hoursCents <= 0 || $ctx['contracts_id'] < 1) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT);
        }
        if ($type === null) {
            // Le type est celui du vocabulaire métier semé à l'installation ; renommé ou supprimé, l'estimation n'a plus de prix à lire
            throw new RuleViolation(RuleViolation::FLATRATE_NO_ESTIMATE_TYPE, 'The intervention type used for the estimate does not exist');
        }
        // Estimation d'un autre type : lecture seule, le réglage du ticket n'est pas touché
        $ctx['interventiontypes_id'] = (int) $type['id'];
        $r = Billing::evaluate($ctx, ['actiontime' => $hoursCents * 36, 'date' => self::now()]);

        return Money::format($r['points']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Interne
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Charge le forfait et contrôle le droit demandé (UPDATE, ou null : seulement lecture ; la décision BU contrôle
     * VALIDATE elle-même ici), l'entité et la lecture du ticket.
     *
     * @throws AccessDeniedHttpException|NotFoundHttpException
     */
    private static function loadFor(int $id, ?int $right): self
    {
        $item = new self();
        if (!$item->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        $allowed = $right === null
            ? self::canDecide() && Session::haveAccessToEntity((int) $item->fields['entities_id']) && self::canReadTicket((int) $item->fields['tickets_id'])
            : $item->can($id, $right);
        if (!$allowed) {
            throw new AccessDeniedHttpException();
        }

        return $item;
    }

    /**
     * Notification native (événements flatrate_submitted et flatrate_decided de NotificationTargetFlatrate).
     */
    private static function notify(string $event, int $id): void
    {
        $item = new self();
        if ($item->getFromDB($id)) {
            NotificationEvent::raiseEvent($event, $item, ['entities_id' => (int) $item->fields['entities_id']]);
        }
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'amount', 'name' => __('Amount', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'status', 'name' => __('Status'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => '6', 'table' => $table, 'field' => 'approved_amount', 'name' => __('Approved amount', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }
}
