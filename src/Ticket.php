<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\RuleViolation;
use GlpiPlugin\Points\Domain\TaskRules;
use Session;

/**
 * Réglage Points d'un ticket (table glpi_plugin_points_tickets, une ligne par ticket) et onglet « Points » de la fiche
 * Ticket. Le nom de la classe donne la table (src/DbUtils.php:244-249 en 12.0.1) ; le ticket du cœur s'écrit \Ticket.
 *
 * La ligne est facultative : sans elle, le contrat est celui que Billing::context déduit des liens natifs du ticket et
 * le type d'intervention celui du paramétrage. Droit plugin_points_ticket (READ pour voir, UPDATE pour régler) et accès
 * à l'entité du ticket. Pas de suppression ; le lien au ticket est figé.
 */
class Ticket extends CommonDBTM
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::TICKET;

    public bool $dohistory = false;

    private const FROZEN = ['tickets_id', 'entities_id', 'is_recursive', 'date_creation'];

    public static function getTypeName($nb = 0)
    {
        return __('Ticket Points settings', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Rights::TICKET, READ);
    }

    /** Le droit n'a pas de bit CREATE : régler un ticket pour la première fois exige UPDATE. */
    public static function canCreate(): bool
    {
        return Session::haveRight(Rights::TICKET, UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::TICKET, UPDATE);
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
        return static::canView() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1)) && self::canReadTicket((int) ($this->fields['tickets_id'] ?? 0));
    }

    public function canCreateItem(): bool
    {
        $ticketsId = (int) ($this->input['tickets_id'] ?? 0);

        return static::canCreate() && Session::haveAccessToEntity(self::ticketEntity($ticketsId)) && self::canReadTicket($ticketsId);
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1)) && self::canReadTicket((int) ($this->fields['tickets_id'] ?? 0));
    }

    /**
     * Le ticket est lisible par l'utilisateur (droit du cœur et entité : \Ticket::can).
     */
    private static function canReadTicket(int $ticketsId): bool
    {
        $ticket = new \Ticket();

        return $ticketsId > 0 && $ticket->getFromDB($ticketsId) && $ticket->can($ticketsId, READ);
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    /**
     * Entité du ticket, -1 s'il n'existe pas (aucun accès).
     */
    private static function ticketEntity(int $ticketsId): int
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketsId], 'LIMIT' => 1])->current();

        return $row === null ? -1 : (int) $row['entities_id'];
    }

    /**
     * Contrôles communs : contrat parmi ceux que Billing::context propose, type d'intervention connu.
     *
     * @param array<string, mixed> $input
     */
    private function validSetting(array $input, int $ticketsId): bool
    {
        global $DB;

        $contractId = (int) ($input['contracts_id'] ?? 0);
        if ($contractId > 0 && !isset(Billing::context($ticketsId)['candidates'][$contractId])) {
            Session::addMessageAfterRedirect(__s('This contract is not a Points contract linked to the ticket', 'points'), false, ERROR);

            return false;
        }
        $typeId = (int) ($input['plugin_points_interventiontypes_id'] ?? 0);
        if ($typeId > 0 && count($DB->request(['SELECT' => ['id'], 'FROM' => InterventionType::getTable(), 'WHERE' => ['id' => $typeId], 'LIMIT' => 1])) === 0) {
            Session::addMessageAfterRedirect(__s('Unknown intervention type', 'points'), false, ERROR);

            return false;
        }

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $ticketsId = (int) ($input['tickets_id'] ?? 0);
        $entity    = self::ticketEntity($ticketsId);
        if ($entity < 0) {
            Session::addMessageAfterRedirect(__s('Unknown ticket', 'points'), false, ERROR);

            return false;
        }
        if (!$this->validSetting($input, $ticketsId)) {
            return false;
        }
        $input['entities_id']  = $entity;
        $input['is_recursive'] = 0;
        if (isset($input['is_billable'])) {
            $input['is_billable'] = (int) $input['is_billable'] === 1 ? 1 : 0;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('The ticket of a Points setting cannot be changed', 'points'), false, ERROR);

            return false;
        }
        if (!$this->validSetting($input, (int) $this->fields['tickets_id'])) {
            return false;
        }
        if (isset($input['is_billable'])) {
            $input['is_billable'] = (int) $input['is_billable'] === 1 ? 1 : 0;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        return false;
    }

    /**
     * Enregistre le réglage d'un ticket (créé au premier enregistrement) en passant par les droits du modèle.
     *
     * @param array{contracts_id: int, plugin_points_interventiontypes_id: int, is_billable: int} $values
     * @throws AccessDeniedHttpException
     */
    public static function saveSetting(int $ticketsId, array $values): bool
    {
        global $DB;

        $item = new self();
        $row  = $DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'LIMIT' => 1])->current();
        if ($row !== null) {
            if (!$item->can((int) $row['id'], UPDATE)) {
                throw new AccessDeniedHttpException();
            }

            return $item->update(['id' => (int) $row['id']] + $values);
        }
        $input = ['tickets_id' => $ticketsId] + $values;
        $item->check(-1, CREATE, $input);

        return $item->add($input) !== false;
    }

    /**
     * La ligne Points suit l'entité de son ticket (transfert, changement d'entité) : le cloisonnement la lit par `entities_id`. Ce n'est
     * pas le journal : l'UPDATE est permis. Appelée par le crochet ITEM_UPDATE du ticket (ItilHooks::ticketItemUpdate).
     */
    public static function followTicketEntity(int $ticketsId, int $entitiesId): void
    {
        global $DB;

        $DB->update(self::getTable(), ['entities_id' => $entitiesId], ['tickets_id' => $ticketsId, 'NOT' => ['entities_id' => $entitiesId]]);
    }

    /**
     * Fige le type d'intervention du paramétrage dans la ligne Points du ticket (créée si elle manque, complétée si son type est vide) :
     * changer le défaut du paramétrage n'affecte plus ce ticket. Écriture d'état de décompte (comme les lignes de tâche), sans
     * contrôle de droit : appelée par la création du ticket (ItilHooks::ticketItemAdd) et par la première évaluation (Billing::evaluate).
     * Une fois par ticket et par processus. Rejouable et tolérante à la concurrence (clé unique sur le ticket).
     */
    public static function freezeType(int $ticketsId, int $entitiesId, int $typeId): void
    {
        global $DB;

        static $done = [];
        if ($typeId < 1 || isset($done[$ticketsId][$typeId])) {
            return;
        }
        $row = $DB->request(['SELECT' => ['id', 'plugin_points_interventiontypes_id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'LIMIT' => 1])->current();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        if ($row === null) {
            try {
                $DB->insert(self::getTable(), [
                    'entities_id' => $entitiesId, 'is_recursive' => 0, 'tickets_id' => $ticketsId, 'contracts_id' => 0,
                    'plugin_points_interventiontypes_id' => $typeId, 'is_billable' => 1, 'date_creation' => $now, 'date_mod' => $now,
                ]);
            } catch (\Throwable $e) {
                // Insertion concurrente (clé unique sur le ticket) : la ligne existe maintenant, rien à refaire
                if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'LIMIT' => 1])) === 0) {
                    throw $e;
                }
            }
        } elseif ((int) $row['plugin_points_interventiontypes_id'] === 0) {
            $DB->update(self::getTable(), ['plugin_points_interventiontypes_id' => $typeId, 'date_mod' => $now], ['id' => $row['id'], 'plugin_points_interventiontypes_id' => 0]);
        }
        $done[$ticketsId][$typeId] = true;
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'is_billable', 'name' => __('Billable', 'points'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Onglet « Points » de la fiche Ticket
    // ---------------------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item instanceof \Ticket && $item->getID() > 0 && self::canView() && self::isRelevant($item)) {
            return self::createTabEntry(__('Points', 'points'), 0, $item::class, 'ti ti-coins');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Ticket || !self::canView() || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForTicket($item);

        return true;
    }

    /**
     * L'onglet n'apparaît que pour un ticket qui a un contrat Points, un réglage ou une tâche débitée.
     */
    private static function isRelevant(\Ticket $ticket): bool
    {
        global $DB;

        $ctx = Billing::context((int) $ticket->getID());
        if ($ctx['candidates'] !== []) {
            return true;
        }

        // La ligne Points est écrite pour tout ticket (type d'intervention figé à la création) : elle ne compte que si elle porte un contrat
        // ou un ticket marqué non facturable
        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $ticket->getID(), 'OR' => ['contracts_id' => ['>', 0], 'is_billable' => 0]], 'LIMIT' => 1])) > 0
            || count($DB->request(['SELECT' => ['id'], 'FROM' => Task::getTable(), 'WHERE' => ['tickets_id' => $ticket->getID()], 'LIMIT' => 1])) > 0
            || count($DB->request(['SELECT' => ['id'], 'FROM' => Flatrate::getTable(), 'WHERE' => ['tickets_id' => $ticket->getID()], 'LIMIT' => 1])) > 0;
    }

    private static function showForTicket(\Ticket $ticket): void
    {
        global $CFG_GLPI, $DB;

        $id      = (int) $ticket->getID();
        $ctx     = Billing::context($id);
        $daytypes = GridAdmin::dayTypes();
        $locked = [];
        foreach ($DB->request(['SELECT' => ['tickettasks_id', 'operations_id', 'is_locked'], 'FROM' => Task::getTable(), 'WHERE' => ['tickets_id' => $id]]) as $row) {
            $locked[(int) $row['tickettasks_id']] = $row;
        }
        $nets  = Ledger::netDebits(array_map(static fn (array $row): int => (int) $row['operations_id'], $locked));
        $rows  = [];
        $total = 0;
        $counted = $ctx['contracts_id'] > 0 && $ctx['is_billable'] && !$ctx['flatrate'];
        $seePrivate = Session::haveRight(\TicketTask::$rightname, \CommonITILTask::SEEPRIVATE);
        $me         = (int) Session::getLoginUserID();
        $all = iterator_to_array($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $id], 'ORDER' => ['date', 'id']]), false);
        // Mêmes tâches que pendingTasks laisse au ticket principal (fusion de tickets) : montrées, jamais comptées ici
        $merged = array_flip(Billing::mergedElsewhere($id, array_values(array_filter($all, static fn (array $t): bool => (int) $t['actiontime'] > 0 && !isset($locked[(int) $t['id']])))));
        foreach ($all as $task) {
            // Tâche privée : visible avec le droit SEEPRIVATE de TicketTask, ou si elle est à moi (CommonITILTask::canViewItem)
            if ((int) $task['is_private'] === 1 && !$seePrivate && (int) $task['users_id'] !== $me && (int) $task['users_id_tech'] !== $me) {
                continue;
            }
            $line = [
                'id' => (int) $task['id'], 'date' => '', 'entered' => (int) ceil((int) $task['actiontime'] / 60),
                'counted' => TaskRules::countedMinutesFromSeconds((int) $task['actiontime']), 'daytype' => '', 'points' => '', 'state' => '', 'flags' => '',
            ];
            $line['date'] = self::safeDate($task);
            $debit = $locked[(int) $task['id']] ?? null;
            if ($debit !== null && (int) $debit['operations_id'] > 0) {
                $op      = Operation::read((int) $debit['operations_id']);
                $details = $op['details'] ?? [];
                // Débit net du journal (Ledger::netDebits : remboursements, corrections et compensations liés compris)
                $net             = $nets[(int) $debit['operations_id']] ?? 0;
                $line['points']  = Money::format($net);
                $line['daytype'] = $daytypes[(int) ($details['daytype'] ?? 0)] ?? '';
                $line['counted'] = (int) ($details['counted_minutes'] ?? $line['counted']);
                $line['flags']   = implode(', ', array_map(Billing::flagLabel(...), (array) ($details['flags'] ?? [])));
                if ((int) ($op['items_id'] ?? 0) !== (int) $task['id']) {
                    // Copie par fusion d'une tâche débitée sur un autre ticket : montré, jamais compté deux fois
                    $line['state'] = __('Debited on the merged ticket (locked)', 'points');
                } else {
                    $line['state'] = __('Debited (locked)', 'points');
                    $total += $net;
                }
            } elseif (isset($merged[(int) $task['id']]) && $counted) {
                $line['state'] = __('Debited on the main ticket', 'points');
            } elseif (!$counted) {
                $line['state'] = (int) $task['actiontime'] > 0 ? __('Not counted', 'points') : '';
            } elseif ((int) $task['actiontime'] <= 0) {
                $line['state'] = __('Nothing to debit', 'points');
            } else {
                try {
                    $r = Billing::evaluate($ctx, $task);
                    $line['points']  = Money::format($r['points']);
                    $line['daytype'] = $daytypes[$r['daytype']] ?? '';
                    $line['flags']   = implode(', ', array_map(Billing::flagLabel(...), $r['flags']));
                    $line['state']   = __('Estimated, not yet debited', 'points');
                    $total += $r['points'];
                } catch (RuleViolation $e) {
                    $line['state'] = Billing::describe($e);
                } catch (\InvalidArgumentException) {
                    $line['state'] = __('A date is not valid', 'points');
                }
            }
            $rows[] = $line;
        }
        $choices = [0 => '-----'];
        foreach ($ctx['candidates'] as $candidate) {
            $choices[$candidate['id']] = $candidate['name'];
        }
        $setting = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $id], 'LIMIT' => 1])->current();

        TemplateRenderer::getInstance()->display('@points/ticket.html.twig', [
            'tickets_id'     => $id,
            'form_url'       => $CFG_GLPI['root_doc'] . '/plugins/points/front/ticket.form.php',
            'can_edit'       => Session::haveRight(Rights::TICKET, UPDATE),
            'candidates'     => $choices,
            'has_candidates' => $ctx['candidates'] !== [],
            'contract'       => (int) ($setting['contracts_id'] ?? 0) > 0 && isset($ctx['candidates'][(int) $setting['contracts_id']]) ? (int) $setting['contracts_id'] : 0,
            'resolved'       => $ctx['contracts_id'] > 0 ? $ctx['candidates'][$ctx['contracts_id']]['name'] : '',
            'ambiguous'      => $ctx['ambiguous'],
            'is_billable'    => $ctx['is_billable'] ? 1 : 0,
            'type'           => (int) ($setting['plugin_points_interventiontypes_id'] ?? 0),
            'default_type'   => Config::interventionTypes()[(int) Config::value('default_interventiontypes_id')] ?? '',
            'types'          => Config::interventionTypes(),
            'flatrate'       => Flatrate::viewData($ticket),
            'reviews'        => Review::viewData($ticket),
            'compensations'  => Compensation::viewData($ticket),
            'tasks'          => $rows,
            'total'          => Money::format($total),
            'debit_moment'   => Config::value('debit_moment') === ConfigValues::DEBIT_TASK ? __('When the task is added', 'points') : __('When the ticket is closed', 'points'),
        ]);
    }

    /**
     * Date affichée d'une tâche, dans le fuseau de référence ; la date brute si elle est illisible.
     *
     * @param array<string, mixed> $task
     */
    private static function safeDate(array $task): string
    {
        try {
            return Billing::taskDate($task);
        } catch (\InvalidArgumentException) {
            return (string) ($task['date'] ?? '');
        }
    }
}
