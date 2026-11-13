<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Search\DefaultSearchRequestInterface;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\RuleViolation;
use Session;

/**
 * Réglage Points d'un contrat natif (table glpi_plugin_points_contracts, une ligne par contrat) et onglet « Points »
 * de la fiche Contract. Le nom de la classe donne la table : GlpiPlugin\Points\Contract => glpi_plugin_points_contracts
 * (src/DbUtils.php:244-249 en 12.0.1) ; le contrat du cœur s'écrit \Contract.
 *
 * La ligne est créée par Ledger (ensureContract) et son cache (balance, credited, consumed, date_cache) n'est écrit
 * que par lui. Par l'API ou un formulaire générique : pas de création, pas de suppression, et seuls la grille, le seuil
 * d'alerte et l'état actif se modifient (droit plugin_points_envelope UPDATE). Lecture : droit plugin_points_balance
 * et accès à l'entité, sans exiger le droit du cœur sur les contrats.
 */
class Contract extends CommonDBTM implements DefaultSearchRequestInterface
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::BALANCE;

    public bool $dohistory = false;

    private const FROZEN = ['contracts_id', 'entities_id', 'is_recursive', 'balance', 'credited', 'consumed', 'next_expiry', 'consumed_pct', 'date_cache', 'date_creation'];

    /** Opérations proposées à la correction et lignes du registre affichées. */
    private const LIST_LIMIT = 200;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Points contracts', 'points') : __('Points contract', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Rights::BALANCE, READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::ENVELOPE, UPDATE);
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

    public function prepareInputForAdd($input)
    {
        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('The balance and the link to the contract cannot be changed', 'points'), false, ERROR);

            return false;
        }
        if (isset($input['alert_threshold']) && ((int) $input['alert_threshold'] < 1 || (int) $input['alert_threshold'] > 100)) {
            Session::addMessageAfterRedirect(__s('The alert threshold must be between 1 and 100', 'points'), false, ERROR);

            return false;
        }
        if (isset($input['plugin_points_grids_id']) && (int) $input['plugin_points_grids_id'] > 0 && !array_key_exists((int) $input['plugin_points_grids_id'], self::grids())) {
            Session::addMessageAfterRedirect(__s('Unknown grid', 'points'), false, ERROR);

            return false;
        }

        return $input;
    }

    /**
     * Un seuil modifié efface l'alerte mémorisée : le contrat est réévalué au prochain passage de `pointsalert` avec son nouveau seuil (relevé :
     * pas d'alerte tant qu'il n'est pas atteint ; abaissé sous la consommation : alerte au prochain passage).
     */
    public function post_updateItem($history = true)
    {
        if (in_array('alert_threshold', $this->updates ?? [], true)) {
            ThresholdAlert::clear((int) $this->getID());
        }
    }

    public function pre_deleteItem()
    {
        return false;
    }

    /**
     * Critères ajoutés par le moteur de recherche à la table des lignes Points (src/Glpi/Search/Provider/SQLProvider.php:1166 en 12.0.1) :
     * la liste « Contrats à points » ne montre ni les contrats natifs à la corbeille ni les modèles.
     *
     * @return array<int|string, mixed>
     */
    public static function getSystemSQLCriteria(?string $tablename = null): array
    {
        $table = $tablename ?? static::getTable();

        return ['NOT' => ["$table.contracts_id" => new QuerySubQuery([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_contracts',
            'WHERE'  => ['OR' => ['is_deleted' => 1, 'is_template' => 1]],
        ])]];
    }

    /**
     * Liste « Outils > Points > Contrats à points » (portefeuille) : triée par pourcentage consommé décroissant.
     *
     * @return array<string, mixed>
     */
    public static function getDefaultSearchRequest(): array
    {
        return ['sort' => 8, 'order' => 'DESC'];
    }

    /**
     * Options de recherche du portefeuille : client (entité du contrat), BU (type du contrat), grille, solde, crédité, consommé,
     * % consommé et prochaine échéance sont des colonnes réelles du cache (filtre et tri natifs, recalculées par Ledger). Aucun champ
     * interne (commentaires, auteurs) : le cloisonnement par entité est celui du moteur de recherche (entité propre du contrat, lignes
     * non récursives).
     */
    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'balance', 'name' => __('Balance', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'credited', 'name' => __('Credited', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'consumed', 'name' => __('Consumed', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '6', 'table' => $table, 'field' => 'alert_threshold', 'name' => __('Alert threshold (%)', 'points'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '7', 'table' => $table, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => '8', 'table' => $table, 'field' => 'consumed_pct', 'name' => __('Consumed (%)', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '9', 'table' => $table, 'field' => 'next_expiry', 'name' => __('Next expiry', 'points'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '10', 'table' => 'glpi_contracts', 'field' => 'name', 'name' => __('Contract'), 'datatype' => 'itemlink', 'itemlink_type' => \Contract::class, 'massiveaction' => false],
            // BU = type du contrat natif (décision d'Assist du 07/11/26) : jointure par le contrat
            ['id' => '11', 'table' => 'glpi_contracttypes', 'field' => 'name', 'name' => __('BU (contract type)', 'points'), 'datatype' => 'dropdown', 'massiveaction' => false,
                'joinparams' => ['beforejoin' => ['table' => 'glpi_contracts']]],
            ['id' => '12', 'table' => 'glpi_plugin_points_grids', 'field' => 'name', 'name' => __('Grid', 'points'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Client (entity)', 'points'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Garde des pages front/*.form.php
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Contrat natif demandé par une page d'écriture : il doit exister, être lisible par l'utilisateur (\Contract::can(READ) : droit du
     * cœur et entité) et porter des points (carriesPoints : ligne Points existante, ou type de contrat du paramétrage). Le droit du
     * plugin est vérifié par la page elle-même.
     *
     * @throws NotFoundHttpException|AccessDeniedHttpException
     */
    public static function requireContract(int $contractsId): int
    {
        $contract = new \Contract();
        if ($contractsId <= 0 || !$contract->getFromDB($contractsId)) {
            throw new NotFoundHttpException();
        }
        // Contrat récursif d'une entité mère : \Contract::can(READ) l'ouvre aux entités filles, mais une écriture du journal
        // exige l'accès à l'entité DU contrat (non récursif), comme la lecture des lignes du plugin (docs/conception.md, « Contrat récursif »)
        if (!$contract->can($contractsId, READ) || !self::haveEntityAccess($contract)) {
            throw new AccessDeniedHttpException();
        }
        // Un contrat d'un autre type ne reçoit ni réglage ni écriture du journal (lecture et écriture restent possibles s'il a déjà une
        // ligne Points : un type retiré du paramétrage ne rend pas son journal inaccessible)
        if (!self::carriesPoints($contract)) {
            throw new AccessDeniedHttpException();
        }

        return $contractsId;
    }

    /**
     * Hook PRE_ITEM_UPDATE du contrat natif : l'entité d'un contrat qui a une ligne Points ne change pas. Les lignes du journal portent l'entité
     * du contrat (copie, non récursive) et ne se modifient pas : un changement d'entité (PUT, formulaire, transfert natif : Transfer::transferItem
     * appelle `$item->update()`, src/Transfer.php:1240 en 12.0.1) les laisserait dans l'ancienne entité, visibles de l'ancien client seul.
     */
    public static function contractPreUpdate(\Contract $contract): void
    {
        global $DB;

        $input = $contract->input;
        if (!is_array($input) || !array_key_exists('entities_id', $input) || (int) $input['entities_id'] === (int) ($contract->fields['entities_id'] ?? -1)) {
            return;
        }
        if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contract->getID()], 'LIMIT' => 1])) === 0) {
            return;
        }
        $contract->input = false;
        Session::addMessageAfterRedirect(__s('The entity of a contract with a Points journal cannot be changed', 'points'), false, ERROR);
    }

    /**
     * Hook PRE_ITEM_PURGE du contrat natif : un contrat qui a une ligne Points ne se purge pas. Le journal (opérations, lots, affectations)
     * est en ajout seul et porte l'id du contrat ; le purger laisserait un journal orphelin, et sa ligne Points avec. Le contrat à la
     * corbeille (suppression sans purge) reste possible : le journal reste lisible par l'entité.
     */
    public static function contractPrePurge(\Contract $contract): void
    {
        global $DB;

        if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contract->getID()], 'LIMIT' => 1])) === 0) {
            return;
        }
        $contract->input = false;
        Session::addMessageAfterRedirect(__s('A contract with a Points journal cannot be deleted permanently', 'points'), false, ERROR);
    }

    /**
     * Accès à l'entité propre du contrat (les lignes du plugin portent l'entité du contrat, is_recursive = 0) : un contrat
     * récursif d'une entité mère ne donne pas accès à ses points depuis une entité fille.
     */
    public static function haveEntityAccess(\Contract $contract): bool
    {
        return Session::haveAccessToEntity((int) ($contract->fields['entities_id'] ?? -1));
    }

    /**
     * Exécute une écriture du journal demandée par un formulaire, signale le résultat et revient à la page précédente.
     * Un refus de règle ou une saisie invalide devient un message d'erreur (rien n'est écrit) ; un refus de droit
     * (exception HTTP) n'est pas intercepté. L'action renvoie false si elle a déjà signalé son échec.
     *
     * @param callable():(bool|null) $action
     * @param string $success message de réussite, déjà échappé (__s)
     */
    public static function respond(callable $action, string $success): never
    {
        try {
            if ($action() !== false) {
                Session::addMessageAfterRedirect($success);
            }
        } catch (RuleViolation $e) {
            Session::addMessageAfterRedirect(htmlescape(Ledger::describe($e)), false, ERROR);
        } catch (\InvalidArgumentException) {
            Session::addMessageAfterRedirect(__s('The amount or the date is not valid', 'points'), false, ERROR);
        }
        \Html::back();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Onglet « Points » de la fiche Contract
    // ---------------------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item instanceof \Entity) {
            // Fiche Entity : les contrats à points de l'entité elle-même (entité propre, pas ses sous-entités ni un contrat d'une entité mère).
            // L'accès à l'entité est celui de la fiche (Entity::canViewItem = Session::haveAccessToEntity, src/Entity.php:336-339 en 12.0.1) :
            // un contrôle de plus ici serait redondant (mutation non détectable au banc)
            $count = $item->getID() >= 0 && self::canView() ? self::countInEntity($item->getID()) : 0;

            return $count > 0 ? self::createTabEntry(__('Points', 'points'), $count, $item::class, 'ti ti-coins') : '';
        }
        if (!$withtemplate && $item instanceof \Contract && $item->getID() > 0 && self::canView() && self::haveEntityAccess($item) && self::carriesPoints($item)) {
            return self::createTabEntry(__('Points', 'points'), 0, $item::class, 'ti ti-coins');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Entity) {
            // can(READ) de l'entité = accès à l'entité (Entity::canViewItem, src/Entity.php:336-339 en 12.0.1) ; les lignes lues sont celles de CETTE entité
            if (!self::canView() || $item->getID() < 0 || !$item->can($item->getID(), READ)) {
                return false;
            }
            self::showForEntity($item);

            return true;
        }
        if (!$item instanceof \Contract || !self::canView() || !$item->can($item->getID(), READ) || !self::haveEntityAccess($item)) {
            return false;
        }
        self::showForContract($item);

        return true;
    }

    private static function countInEntity(int $entityId): int
    {
        global $DB;

        $table = self::getTable();

        // Contrats natifs à la corbeille ou modèles exclus (comme la liste de l'onglet et la liste « Contrats à points »)
        return count($DB->request([
            'SELECT'     => ["$table.id"],
            'FROM'       => $table,
            'INNER JOIN' => ['glpi_contracts' => ['ON' => [$table => 'contracts_id', 'glpi_contracts' => 'id']]],
            'WHERE'      => ["$table.entities_id" => $entityId, 'glpi_contracts.is_deleted' => 0, 'glpi_contracts.is_template' => 0],
        ]));
    }

    /**
     * Contrats à points d'une entité (entité propre du contrat), avec les colonnes du cache, du plus consommé au moins consommé ; le lien vers
     * la fiche du contrat n'est donné qu'à qui peut la lire. L'appelant a contrôlé l'accès à l'entité et le droit de lecture des soldes.
     *
     * @return list<array<string, mixed>>
     */
    public static function entityRows(int $entityId): array
    {
        global $DB;

        $table = self::getTable();
        $rows  = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_contracts.id AS contract', 'glpi_contracts.name AS name', 'glpi_contracttypes.name AS bu', "$table.balance AS balance", "$table.credited AS credited",
                "$table.consumed AS consumed", "$table.consumed_pct AS pct", "$table.alert_threshold AS threshold", "$table.next_expiry AS expiry", "$table.is_active AS active"],
            'FROM'       => $table,
            'INNER JOIN' => ['glpi_contracts' => ['ON' => [$table => 'contracts_id', 'glpi_contracts' => 'id']]],
            'LEFT JOIN'  => ['glpi_contracttypes' => ['ON' => ['glpi_contracts' => 'contracttypes_id', 'glpi_contracttypes' => 'id']]],
            'WHERE'      => ["$table.entities_id" => $entityId, 'glpi_contracts.is_deleted' => 0, 'glpi_contracts.is_template' => 0],
            'ORDER'      => ["$table.consumed_pct DESC", 'glpi_contracts.name', 'glpi_contracts.id'],
        ]) as $row) {
            $native = new \Contract();
            $link   = $native->can((int) $row['contract'], READ) ? $native->getFormURLWithID((int) $row['contract']) : '';
            $rows[] = [
                'name'      => (string) $row['name'],
                'bu'        => (string) ($row['bu'] ?? ''),
                'balance'   => Money::format(Money::parse((string) $row['balance'])),
                'credited'  => Money::format(Money::parse((string) $row['credited'])),
                'consumed'  => Money::format(Money::parse((string) $row['consumed'])),
                'pct'       => (string) $row['pct'],
                'threshold' => (int) $row['threshold'],
                'expiry'    => (string) ($row['expiry'] ?? ''),
                'active'    => (int) $row['active'] === 1,
                'url'       => $link,
            ];
        }

        return $rows;
    }

    private static function showForEntity(\Entity $entity): void
    {
        TemplateRenderer::getInstance()->display('@points/entity.html.twig', ['contracts' => self::entityRows($entity->getID())]);
    }

    /**
     * Un contrat porte des points s'il a une ligne Points ou si son type fait partie des types du paramétrage.
     */
    public static function carriesPoints(\Contract $contract): bool
    {
        global $DB;

        if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $contract->getID()], 'LIMIT' => 1])) > 0) {
            return true;
        }

        return in_array((int) ($contract->fields['contracttypes_id'] ?? 0), Config::value('contracttypes'), true);
    }

    /**
     * Soldes, enveloppes et registre d'un contrat, lus en base (l'appelant a contrôlé l'accès : entité du contrat et droit de lecture).
     * `$internal` : onglet du contrat (auteur et commentaire de chaque opération, choix pour la correction) ; faux pour l'interface
     * simplifiée, dont le registre n'expose ni l'auteur ni les notes internes.
     *
     * @return array{balance: string, credited: string, consumed: string, envelopes: list<array<string, mixed>>, operations: list<array<string, mixed>>,
     *               operation_choices: array<int, string>, envelope_choices: array<int, string>}
     */
    public static function ledgerView(int $id, bool $internal = true): array
    {
        global $DB;

        $remaining = Ledger::remainingByEnvelope($id);

        $envelopes = [];
        foreach ($DB->request(['FROM' => Envelope::getTable(), 'WHERE' => ['contracts_id' => $id], 'ORDER' => ['begin_date', 'id']]) as $row) {
            $envelopes[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'begin'     => (string) $row['begin_date'],
                'end'       => (string) $row['end_date'],
                'credit'    => Money::format(Money::parse((string) $row['credit'])),
                'remaining' => Money::format($remaining[(int) $row['id']] ?? 0),
                'carryover' => (int) $row['is_carryover'] === 1,
            ];
        }

        $operations = [];
        $choices    = [];
        $users      = [];
        foreach ($DB->request(['FROM' => Operation::getTable(), 'WHERE' => ['contracts_id' => $id], 'ORDER' => ['id DESC'], 'LIMIT' => self::LIST_LIMIT]) as $row) {
            $amount  = Money::format(Money::parse((string) $row['amount']));
            $label   = Operation::typeLabel((string) $row['type']);
            $userId  = (int) $row['users_id'];
            $line    = [
                'id'     => (int) $row['id'],
                'date'   => substr((string) $row['date'], 0, 10),
                'type'   => $label,
                'amount' => $amount,
                'ticket' => (int) $row['tickets_id'],
            ];
            if ($internal) {
                $users[$userId] ??= $userId > 0 ? getUserName($userId) : '';
                $line['comment'] = (string) ($row['comment'] ?? '');
                $line['user']    = $users[$userId];
            }
            $operations[] = $line;
            $choices[(int) $row['id']] = sprintf('#%d  %s  %s  %s', $row['id'], substr((string) $row['date'], 0, 10), $label, $amount);
        }

        $envelopeChoices = [];
        foreach ($envelopes as $envelope) {
            $envelopeChoices[$envelope['id']] = sprintf('%s (%s → %s)', $envelope['name'], $envelope['begin'], $envelope['end']);
        }

        $cache = Ledger::cached($id);

        return [
            'balance'           => Money::format($cache['balance']),
            'credited'          => Money::format($cache['credited']),
            'consumed'          => Money::format($cache['consumed']),
            'envelopes'         => $envelopes,
            'operations'        => $operations,
            'operation_choices' => $choices,
            'envelope_choices'  => $envelopeChoices,
        ];
    }

    private static function showForContract(\Contract $contract): void
    {
        global $CFG_GLPI, $DB;

        $id      = $contract->getID();
        $setting = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['contracts_id' => $id], 'LIMIT' => 1])->current();
        $view    = self::ledgerView($id, InternalFields::allowed());

        TemplateRenderer::getInstance()->display('@points/contract.html.twig', [
            'contracts_id'      => $id,
            'base_url'          => $CFG_GLPI['root_doc'] . '/plugins/points/front/',
            'can_settings'      => Session::haveRight(Rights::ENVELOPE, UPDATE),
            'can_create'        => Session::haveRight(Rights::ENVELOPE, CREATE),
            'can_update'        => Session::haveRight(Rights::ENVELOPE, UPDATE),
            'setting'           => [
                'plugin_points_grids_id' => (int) ($setting['plugin_points_grids_id'] ?? 0),
                'alert_threshold'        => (int) ($setting['alert_threshold'] ?? Config::value('default_alert_threshold')),
                'is_active'              => (int) ($setting['is_active'] ?? 1),
            ],
            'grids'             => self::grids(),
            'balance'           => $view['balance'],
            'credited'          => $view['credited'],
            'consumed'          => $view['consumed'],
            'envelopes'         => $view['envelopes'],
            'envelope_choices'  => $view['envelope_choices'],
            'operations'        => $view['operations'],
            'operation_choices' => $view['operation_choices'],
        ]);
    }

    /**
     * Grilles actives (id => nom), 0 = aucune.
     *
     * @return array<int, string>
     */
    private static function grids(): array
    {
        global $DB;

        $grids = [0 => '-----'];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_points_grids', 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $row) {
            $grids[(int) $row['id']] = (string) $row['name'];
        }

        return $grids;
    }
}
