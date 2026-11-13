<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Entity;
use Glpi\Exception\Database\StatementException;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\RuleViolation;
use GlpiPlugin\Points\Domain\TaskRules;
use Session;

/**
 * Décompte des tâches de ticket (docs/conception.md, règles 1 à 7) : contexte de facturation d'un ticket, évaluation
 * d'une tâche (grille, calendrier), débit par Ledger::debit (jamais d'écriture du journal autrement, et jamais depuis
 * une transaction englobante : Ledger ouvre la sienne), état verrouillé d'une tâche débitée (glpi_plugin_points_tasks).
 *
 * Les décisions pures sont dans Domain\TaskRules ; ce service y ajoute la lecture de GLPI (liens de contrat, calendrier
 * natif, grilles). Les hooks (ItilHooks) n'ont pas d'autre logique que d'appeler ce service.
 */
final class Billing
{
    private const OPERATIONS = 'glpi_plugin_points_operations';
    private const TASKS      = 'glpi_plugin_points_tasks';

    /**
     * Un ticket au forfait ne décompte pas ses tâches (règle 9) : vrai dès qu'un forfait non refusé ni annulé existe
     * (brouillon, soumis, proposé, approuvé ou débité). Seules les règles du forfait comptent alors : pas d'exigence de
     * point valide à la date d'une tâche. Un forfait refusé (BU ou client) ou annulé rend le ticket au décompte des tâches.
     */
    public static function isFlatrateTicket(int $ticketsId): bool
    {
        return Flatrate::countsForTicket($ticketsId);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Contexte d'un ticket
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Contrat Points d'un ticket : parmi les contrats liés nativement (Ticket_Contract) dont le type est dans
     * `contracttypes` et qui ont une ligne Points active ; un seul → automatique ; plusieurs → celui du réglage du
     * ticket, sinon à choisir (`ambiguous`) ; aucun → ticket non facturable.
     *
     * @return array{tickets_id: int, entities_id: int, candidates: array<int, array{id: int, name: string, grids_id: int}>,
     *         contracts_id: int, ambiguous: bool, is_billable: bool, billable: bool, interventiontypes_id: int, type_frozen: bool,
     *         grids_id: int, flatrate: bool}
     */
    public static function context(int $ticketsId): array
    {
        global $DB;

        $ticket = $DB->request(['SELECT' => ['id', 'entities_id', 'is_deleted'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketsId], 'LIMIT' => 1])->current();
        $ctx    = [
            'tickets_id' => $ticketsId, 'entities_id' => (int) ($ticket['entities_id'] ?? 0), 'candidates' => [], 'contracts_id' => 0,
            'ambiguous' => false, 'is_billable' => true, 'billable' => false, 'interventiontypes_id' => (int) Config::value('default_interventiontypes_id'), 'type_frozen' => false,
            'grids_id' => 0, 'flatrate' => false,
        ];
        // Un ticket dans la corbeille (source d'une fusion, qui reçoit la copie des tâches du ticket principal par
        // CommonITILTask::addToMergedTickets) n'est jamais décompté : le débit se fait sur le ticket principal
        if ($ticket === null || (int) $ticket['is_deleted'] === 1) {
            return $ctx;
        }
        $types = Config::value('contracttypes');
        if ($types !== []) {
            $rows = $DB->request([
                'SELECT'     => ['glpi_contracts.id AS contracts_id', 'glpi_contracts.name AS name', 'glpi_plugin_points_contracts.plugin_points_grids_id AS grids_id'],
                'FROM'       => 'glpi_tickets_contracts',
                'INNER JOIN' => [
                    'glpi_contracts'              => ['ON' => ['glpi_tickets_contracts' => 'contracts_id', 'glpi_contracts' => 'id']],
                    'glpi_plugin_points_contracts' => ['ON' => ['glpi_plugin_points_contracts' => 'contracts_id', 'glpi_contracts' => 'id']],
                ],
                'WHERE'      => [
                    'glpi_tickets_contracts.tickets_id'          => $ticketsId,
                    'glpi_contracts.is_deleted'                  => 0,
                    'glpi_contracts.contracttypes_id'            => $types,
                    'glpi_plugin_points_contracts.is_active'     => 1,
                    // Cloisonnement : le lien ticket-contrat se crée sans contrôle d'entité (`_contracts_id` à la création d'un ticket,
                    // API Ticket_Contract) ; seul un contrat de l'entité du ticket, ou récursif d'une entité mère, peut porter un débit.
                    // Sinon le débit d'un ticket du client A serait pris sur les points du client B (banc, lot f).
                    getEntitiesRestrictCriteria('glpi_contracts', 'entities_id', $ctx['entities_id'], true),
                ],
                'ORDER'      => 'glpi_contracts.id',
            ]);
            foreach ($rows as $row) {
                $ctx['candidates'][(int) $row['contracts_id']] = ['id' => (int) $row['contracts_id'], 'name' => (string) $row['name'], 'grids_id' => (int) $row['grids_id']];
            }
        }
        $setting = $DB->request(['FROM' => Ticket::getTable(), 'WHERE' => ['tickets_id' => $ticketsId], 'LIMIT' => 1])->current();
        if ($setting !== null) {
            $ctx['is_billable'] = (int) $setting['is_billable'] === 1;
            if ((int) $setting['plugin_points_interventiontypes_id'] > 0) {
                $ctx['interventiontypes_id'] = (int) $setting['plugin_points_interventiontypes_id'];
                $ctx['type_frozen']          = true;
            }
        }
        $chosen = (int) ($setting['contracts_id'] ?? 0);
        if (isset($ctx['candidates'][$chosen])) {
            $ctx['contracts_id'] = $chosen;
        } elseif (count($ctx['candidates']) === 1) {
            $ctx['contracts_id'] = array_key_first($ctx['candidates']);
        } elseif (count($ctx['candidates']) > 1) {
            $ctx['ambiguous'] = true;
        }
        $ctx['grids_id'] = $ctx['candidates'][$ctx['contracts_id']]['grids_id'] ?? 0;
        $ctx['billable'] = $ctx['contracts_id'] > 0 && $ctx['is_billable'];
        $ctx['ambiguous'] = $ctx['ambiguous'] && $ctx['is_billable'];
        $ctx['flatrate'] = self::isFlatrateTicket($ticketsId);

        return $ctx;
    }

    /**
     * Les tâches de ce ticket sont-elles décomptées (ou à contrôler : contrat à choisir) ? Un ticket non facturable
     * ou au forfait n'est jamais bloqué.
     *
     * @param array{billable: bool, ambiguous: bool, flatrate: bool} $ctx
     */
    public static function counts(array $ctx): bool
    {
        return ($ctx['billable'] || $ctx['ambiguous']) && !$ctx['flatrate'];
    }

    /**
     * Calendrier des types de jour : paramètre calendars_id, sinon celui de l'entité du ticket (stratégie native
     * d'héritage, Entity::getUsedConfig : src/CommonITILTask.php:641 le lit de la même façon en 12.0.1).
     */
    public static function calendar(int $entitiesId): ?\Calendar
    {
        $id = (int) Config::value('calendars_id');
        if ($id === 0) {
            $id = (int) Entity::getUsedConfig('calendars_strategy', $entitiesId, 'calendars_id', 0);
        }
        $calendar = new \Calendar();

        return $id > 0 && $calendar->getFromDB($id) ? $calendar : null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Date et durée d'une tâche
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Fuseau de référence des dates de tâche (règle 2) : le fuseau global de GLPI, celui du serveur. GLPI 12 n'a pas de
     * réglage global de fuseau : « 0 = utiliser la configuration du serveur » signifie date_default_timezone_get() au
     * démarrage (src/Glpi/Kernel/Listener/PostBootListener/SetDbSessionVars.php:79-83, src/DBmysql.php:326), puis le
     * fuseau de l'utilisateur est posé par DBmysql::setTimezone (src/DBmysql.php:1832-1840, appelé pour l'utilisateur
     * connecté par src/Auth.php:1228). La valeur d'origine est donc le réglage `date.timezone` de PHP (UTC si absent),
     * que date_default_timezone_set ne modifie pas : ni le fuseau de l'utilisateur ni celui de la tâche automatique.
     */
    public static function referenceTimezone(): \DateTimeZone
    {
        $name = (string) ini_get('date.timezone');

        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Date lue dans la session courante (fuseau de l'utilisateur, DBmysql::setTimezone) → fuseau de référence.
     *
     * @throws \InvalidArgumentException si la date n'est pas lisible
     */
    public static function toReference(string $dateTime): string
    {
        try {
            $date = new \DateTimeImmutable($dateTime, new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            throw new \InvalidArgumentException('Date invalide : ' . $dateTime);
        }

        return $date->setTimezone(self::referenceTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Jour courant dans le fuseau de référence : la date métier de tout ce qui a lieu « maintenant » (crédit, décision, remboursement,
     * forfait, action automatique, alerte). Un seul calcul pour tout le plugin ; jamais la date PHP brute (fuseau de la session ou du
     * processus), qui change de jour à un autre moment que les dates de tâche.
     */
    public static function today(): string
    {
        $now = new \DateTimeImmutable(Session::getCurrentTime() ?? 'now', new \DateTimeZone(date_default_timezone_get()));

        return Dates::todayIn($now, self::referenceTimezone());
    }

    /**
     * Exécute un calcul de calendrier (Calendar::isHoliday, isAWorkingHour lisent le fuseau par défaut de PHP) dans le
     * fuseau de référence ; le fuseau de la session est rétabli ensuite (PHP seulement : la session SQL n'est pas touchée).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function inReferenceTimezone(callable $work): mixed
    {
        $session = date_default_timezone_get();
        date_default_timezone_set(self::referenceTimezone()->getName());
        try {
            return $work();
        } finally {
            date_default_timezone_set($session);
        }
    }

    /**
     * Règle 2 : début planifié s'il existe, sinon date de la tâche, converti dans le fuseau de référence.
     *
     * @param array<string, mixed> $task champs begin, date (fuseau de la session)
     * @throws \InvalidArgumentException
     */
    public static function taskDate(array $task): string
    {
        foreach (['begin', 'date'] as $field) {
            $value = trim((string) ($task[$field] ?? ''));
            if ($value !== '' && !str_starts_with($value, '0000')) {
                return self::toReference($value);
            }
        }

        return self::toReference(Session::getCurrentTime() ?? date('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Évaluation
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Décompte d'une tâche : minutes comptées, type de jour, version de grille, coût, points, drapeaux.
     *
     * Lecture seule (le type d'un ticket sans réglage est figé par freezeType, dans les chemins d'écriture seulement).
     *
     * @param array{contracts_id: int, entities_id: int, grids_id: int, interventiontypes_id: int} $ctx
     * @param array<string, mixed> $task champs actiontime, begin, date
     * @return array{entered_seconds: int, counted_minutes: int, daytype: int, flags: list<string>, gridversions_id: int,
     *         cost: int, points: int, unit_minutes: int, date: string}
     * @throws RuleViolation
     */
    public static function evaluate(array $ctx, array $task): array
    {
        global $DB;

        $seconds = (int) ($task['actiontime'] ?? 0);
        $date    = self::taskDate($task);
        $day     = Dates::day($date);
        $calendar = self::calendar($ctx['entities_id']);
        $begin    = trim((string) ($task['begin'] ?? ''));
        $beginRef = $begin !== '' && !str_starts_with($begin, '0000') ? self::toReference($begin) : '';
        [$holiday, $offHours] = $calendar === null ? [false, false] : self::inReferenceTimezone(
            static fn (): array => [$calendar->isHoliday($day), $beginRef !== '' && !$calendar->isAWorkingHour((int) strtotime($beginRef))]
        );

        $grid = $ctx['grids_id'] > 0 ? $DB->request(['FROM' => Grid::getTable(), 'WHERE' => ['id' => $ctx['grids_id']], 'LIMIT' => 1])->current() : null;
        if ($grid === null && TaskRules::countedMinutesFromSeconds(max(0, $seconds)) > 0) {
            throw new RuleViolation(RuleViolation::NO_GRID, 'The contract has no price grid');
        }
        $versions = [];
        foreach ($DB->request(['SELECT' => ['id', 'begin_date'], 'FROM' => GridVersion::getTable(), 'WHERE' => ['plugin_points_grids_id' => $ctx['grids_id']]]) as $row) {
            $versions[] = ['id' => (int) $row['id'], 'begin_date' => (string) $row['begin_date']];
        }
        $costOf = static function (int $versionId, int $daytype) use ($DB, $ctx): ?int {
            $row = $DB->request([
                'SELECT' => ['cost'],
                'FROM'   => GridCost::getTable(),
                'WHERE'  => ['plugin_points_gridversions_id' => $versionId, 'plugin_points_interventiontypes_id' => $ctx['interventiontypes_id'], 'daytype' => $daytype],
                'LIMIT'  => 1,
            ])->current();

            return $row === null ? null : Money::parse((string) $row['cost']);
        };

        return TaskRules::evaluate(max(0, $seconds), $date, $holiday, $offHours, (int) ($grid['unit_minutes'] ?? 60), $versions, $costOf) + ['date' => $date];
    }

    /**
     * Le type d'intervention d'un ticket sans réglage est celui du paramétrage à cet instant : il est FIGÉ dans la ligne du ticket
     * (Ticket::freezeType) pour que changer le défaut plus tard n'affecte pas ce ticket. À la création, ItilHooks::ticketItemAdd l'a déjà
     * fait ; ceci rattrape les tickets antérieurs au plugin, aux seuls chemins d'ÉCRITURE (ajout ou modification d'une tâche, débit).
     *
     * @param array{tickets_id: int, entities_id: int, interventiontypes_id: int, type_frozen: bool} $ctx
     */
    public static function freezeType(array $ctx): void
    {
        if (!$ctx['type_frozen'] && $ctx['tickets_id'] > 0) {
            Ticket::freezeType($ctx['tickets_id'], $ctx['entities_id'], $ctx['interventiontypes_id']);
        }
    }

    /**
     * Il existe au moins un lot valide du contrat à cette date (règle 6 : une tâche est refusée sinon).
     */
    public static function hasValidLot(int $contractsId, string $date): bool
    {
        global $DB;

        $day = Dates::day($date);

        return count($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_points_lots',
            'WHERE'  => ['contracts_id' => $contractsId, 'origin_date' => ['<=', $day], 'expiry_date' => ['>=', $day]],
            'LIMIT'  => 1,
        ])) > 0;
    }

    /**
     * Refus d'ajout d'une tâche (ticket facturable) : aucun lot valide à sa date, pas de grille ou de prix applicable,
     * et, au moment du débit « à la tâche », un débit que le journal refuserait.
     *
     * @param array{contracts_id: int, entities_id: int, grids_id: int, interventiontypes_id: int} $ctx
     * @param array<string, mixed> $task champs actiontime, begin, date
     * @throws RuleViolation
     */
    public static function assertTaskAccepted(array $ctx, array $task): void
    {
        self::freezeType($ctx);
        $date = self::taskDate($task);
        if (!self::hasValidLot($ctx['contracts_id'], $date)) {
            throw new RuleViolation(RuleViolation::NO_VALID_LOT, 'No valid lot at this date');
        }
        $result = self::evaluate($ctx, $task);
        if ($result['points'] > 0 && Config::value('debit_moment') === ConfigValues::DEBIT_TASK) {
            Ledger::assertDebitable($ctx['contracts_id'], [[$result['points'], $date]]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Débit
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Opération de débit d'une tâche (le journal fait foi), 0 si elle n'est pas débitée.
     */
    public static function debitedOperation(int $taskId): int
    {
        return Ledger::debitOf(OpType::DEBIT_TASK, \TicketTask::class, $taskId);
    }

    /**
     * Fusion de tickets (Ticket::merge recopie les tâches par add avec `sourceitems_id`, src/Ticket.php:5880) : opération
     * de débit de la tâche d'origine dont la copie est `$task`, 0 si l'origine n'est pas débitée ou introuvable. L'origine
     * est la tâche du ticket source de même durée, dates, contenu et technicien.
     *
     * @param array<string, mixed> $task champs de la copie : sourceitems_id, actiontime, begin, end, date, content, users_id_tech
     */
    public static function mergedOriginOperation(array $task): int
    {
        global $DB;

        $source = (int) ($task['sourceitems_id'] ?? 0);
        if ($source < 1) {
            return 0;
        }
        foreach ($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $source]]) as $row) {
            if (!self::sameTask($row, $task)) {
                continue;
            }
            $operation = self::debitedOperation((int) $row['id']);
            if ($operation > 0) {
                return $operation;
            }
        }

        return 0;
    }

    /**
     * Deux tâches ont-elles la même durée, les mêmes dates, le même contenu et le même technicien ? Une tâche recopiée par la fusion de
     * tickets (Ticket::merge) est identique à son original sur ces champs.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameTask(array $a, array $b): bool
    {
        foreach (['actiontime', 'begin', 'end', 'date', 'content', 'users_id_tech'] as $field) {
            $x = trim((string) ($a[$field] ?? ''));
            $y = trim((string) ($b[$field] ?? ''));
            if (in_array($field, ['begin', 'end', 'date'], true)) {
                $equal = ($x === '' || str_starts_with($x, '0000') ? 0 : (int) strtotime($x)) === ($y === '' || str_starts_with($y, '0000') ? 0 : (int) strtotime($y));
            } else {
                $equal = $x === $y;
            }
            if (!$equal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tâches d'un ticket qui ne se débitent PAS ici parce que la fusion de tickets les a recopiées sur le ticket principal : Ticket::merge
     * (et l'ajout d'une tâche à un ticket source en corbeille, CommonITILTask::addToMergedTickets) recopie la tâche avec `sourceitems_id` =
     * ce ticket, et pose le lien « fils de » (tickets_id_1 = source, tickets_id_2 = principal, link SON_OF ; src/Ticket.php:5918-5922 en
     * 12.0.1). N'est exclue qu'une tâche dont une copie de même contenu (durée, dates, contenu, technicien) est dans un ticket lié à celui-ci
     * par ce lien : une copie posée à la main (API, `sourceitems_id` sans fusion) n'exclut rien. Un ticket source restauré puis clos ne
     * débite donc pas ses originaux une seconde fois ; une tâche ajoutée au ticket restauré, sans copie, reste débitable.
     *
     * @param list<array<string, mixed>> $tasks tâches du ticket (champs id, actiontime, begin, end, date, content, users_id_tech)
     * @return list<int> identifiants des tâches à ne pas débiter
     */
    public static function mergedElsewhere(int $ticketsId, array $tasks): array
    {
        global $DB;

        if ($tasks === []) {
            return [];
        }
        $targets = [];
        foreach ($DB->request(['SELECT' => ['tickets_id_2'], 'FROM' => \Ticket_Ticket::getTable(), 'WHERE' => ['tickets_id_1' => $ticketsId, 'link' => \CommonITILObject_CommonITILObject::SON_OF]]) as $row) {
            $targets[] = (int) $row['tickets_id_2'];
        }
        if ($targets === []) {
            return [];
        }
        $skip = [];
        foreach ($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['sourceitems_id' => $ticketsId, 'tickets_id' => $targets]]) as $copy) {
            foreach ($tasks as $task) {
                if (self::sameTask($task, $copy)) {
                    $skip[(int) $task['id']] = true;
                }
            }
        }

        return array_keys($skip);
    }

    /**
     * Enregistre une copie de fusion d'une tâche débitée : ligne verrouillée qui renvoie à l'opération d'origine, sans
     * nouveau débit.
     *
     * @param array{tickets_id: int, entities_id: int} $ctx
     */
    public static function lockMergedCopy(array $ctx, int $taskId, int $originOperationId): void
    {
        self::lock($ctx, $taskId, $originOperationId);
    }

    /**
     * Une tâche de ce ticket a-t-elle été débitée (ligne verrouillée ou opération du journal) ?
     */
    public static function ticketHasDebit(int $ticketsId): bool
    {
        global $DB;

        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::TASKS, 'WHERE' => ['tickets_id' => $ticketsId, 'is_locked' => 1], 'LIMIT' => 1])) > 0
            || count($DB->request(['SELECT' => ['id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['type' => OpType::DEBIT_TASK, 'tickets_id' => $ticketsId], 'LIMIT' => 1])) > 0;
    }

    /**
     * Nettoie les lignes d'état d'un ticket purgé (réglage et lignes de tâches) ; le journal n'est jamais touché.
     */
    public static function cleanTicket(int $ticketsId): void
    {
        global $DB;

        $DB->delete(Ticket::getTable(), ['tickets_id' => $ticketsId]);
        $DB->delete(self::TASKS, ['tickets_id' => $ticketsId]);
    }

    /**
     * La tâche est-elle verrouillée (débitée) ? Durée, date, début, fin et ticket ne se modifient plus.
     */
    public static function isLocked(int $taskId): bool
    {
        global $DB;

        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::TASKS, 'WHERE' => ['tickettasks_id' => $taskId, 'is_locked' => 1], 'LIMIT' => 1])) > 0;
    }

    /**
     * Débite une tâche (opération `debit_task` liée à la TicketTask) et la verrouille. Sans effet si elle est déjà
     * débitée ; rien n'est débité pour une durée nulle ou un coût nul (la tâche reste libre).
     *
     * Appelé hors de toute transaction : Ledger::debit ouvre la sienne et verrouille le contrat.
     *
     * Un débit hors créneau ou férié (drapeaux saturday, sunday, holiday, off_hours) ouvre sa review dans la même
     * transaction (règle 10 : Review::openFor).
     *
     * @param array{tickets_id: int, contracts_id: int, entities_id: int, grids_id: int, interventiontypes_id: int} $ctx
     * @param array<string, mixed> $task champs actiontime, begin, date
     * @return int identifiant de l'opération, 0 si rien n'a été débité
     * @throws RuleViolation
     */
    public static function debitTask(array $ctx, int $taskId, array $task): int
    {
        $existing = self::debitedOperation($taskId);
        if ($existing > 0) {
            self::lock($ctx, $taskId, $existing);

            return $existing;
        }
        if ((int) ($task['actiontime'] ?? 0) <= 0) {
            return 0;
        }
        self::freezeType($ctx);
        $r = self::evaluate($ctx, $task);
        if ($r['points'] <= 0) {
            return 0;
        }
        try {
            $debit = Ledger::debit($ctx['contracts_id'], OpType::DEBIT_TASK, $r['points'], $r['date'], [
            // Unicité revérifiée par Ledger sous le verrou du contrat ; la ligne verrouillée est écrite dans la même transaction
            'unique'     => true,
            // Règle 10 : un débit hors créneau ou férié ouvre sa review dans la même transaction (Review::openFor)
            'inside'     => static function (int $operationId) use ($ctx, $taskId, $r): void {
                self::lock($ctx, $taskId, $operationId, $r['gridversions_id']);
                Review::openFor($ctx, $operationId, $r['flags']);
            },
            'itemtype'   => \TicketTask::class,
            'items_id'   => $taskId,
            'tickets_id' => $ctx['tickets_id'],
            'details'    => [
                'tasks_id'             => $taskId,
                'entered_seconds'      => $r['entered_seconds'],
                'entered_minutes'      => (int) ceil($r['entered_seconds'] / 60),
                'counted_minutes'      => $r['counted_minutes'],
                'unit_minutes'         => $r['unit_minutes'],
                'daytype'              => $r['daytype'],
                'interventiontypes_id' => $ctx['interventiontypes_id'],
                'grids_id'             => $ctx['grids_id'],
                'gridversions_id'      => $r['gridversions_id'],
                'cost'                 => Money::format($r['cost']),
                'flags'                => $r['flags'],
            ],
            ]);
        } catch (RuleViolation $e) {
            // Débité entre-temps par une autre requête : rien à refaire, la tâche est débitée
            if ($e->reason !== RuleViolation::ALREADY_DEBITED) {
                throw $e;
            }
            $existing = self::debitedOperation($taskId);
            if ($existing < 1) {
                // Perdu sur la ligne d'état sans débit validé : rien n'est verrouillé ni débité, la tâche est à reprendre
                throw new RuleViolation(RuleViolation::TASK_TO_RESUME, 'The task could not be debited, try again');
            }
            self::lock($ctx, $taskId, $existing);

            return $existing;
        }

        return $debit['operations_id'];
    }

    /**
     * Ligne d'état de la tâche : opération de débit et verrou. État de décompte, pas du journal : écrite directement.
     *
     * @param array{tickets_id: int, entities_id: int} $ctx
     */
    private static function lock(array $ctx, int $taskId, int $operationId, int $gridVersionId = 0): void
    {
        global $DB;

        // Débit déjà écrit (reprise après conflit) ou copie de fusion : la version est celle des détails de l'opération
        $gridVersionId = $gridVersionId > 0 ? $gridVersionId : Operation::gridVersionOf($operationId);
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::TASKS, 'WHERE' => ['tickettasks_id' => $taskId], 'LIMIT' => 1])->current();
        if ($row !== null) {
            try {
                $DB->update(self::TASKS, ['operations_id' => $operationId, 'is_locked' => 1, 'plugin_points_gridversions_id' => $gridVersionId, 'date_mod' => $now], ['id' => $row['id']]);
            } catch (StatementException $e) {
                // Dans la transaction du journal, sous isolation par instantané (MariaDB 11.x) : erreur 1020 « Record has changed since
                // last read », la ligne a été écrite par un autre débit entre-temps : « déjà débitée », le journal annule tout.
                if ($e->getCode() === 1020) {
                    throw new RuleViolation(RuleViolation::ALREADY_DEBITED, 'The task row was changed by another debit');
                }
                throw $e;
            }

            return;
        }
        $DB->insert(self::TASKS, [
            'entities_id'    => $ctx['entities_id'],
            'is_recursive'   => 0,
            'tickettasks_id' => $taskId,
            'tickets_id'     => $ctx['tickets_id'],
            'operations_id'  => $operationId,
            'is_locked'      => 1,
            // Version de grille du débit : colonne indexée que lit GridVersion::isUsed (la version utilisée n'est plus modifiable)
            'plugin_points_gridversions_id' => $gridVersionId,
            'date_creation'  => $now,
            'date_mod'       => $now,
        ]);
    }

    /**
     * Tâches d'un ticket à débiter (durée non nulle, pas encore débitées), dans l'ordre de leur date puis de leur id.
     *
     * @return list<array{id: int, actiontime: int, begin: ?string, date: ?string}> (et les champs de la tâche utiles à la fusion)
     */
    public static function pendingTasks(int $ticketsId): array
    {
        global $DB;

        $tasks = [];
        foreach ($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $ticketsId, 'actiontime' => ['>', 0]]]) as $row) {
            $tasks[(int) $row['id']] = [
                'id' => (int) $row['id'], 'actiontime' => (int) $row['actiontime'], 'begin' => $row['begin'], 'date' => $row['date'], 'end' => $row['end'],
                'content' => $row['content'], 'users_id_tech' => $row['users_id_tech'],
            ];
        }
        // Tâches doublées par une fusion de tickets : débitées sur l'autre ticket seulement
        foreach (self::mergedElsewhere($ticketsId, array_values($tasks)) as $id) {
            unset($tasks[$id]);
        }
        if ($tasks !== []) {
            foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['type' => OpType::DEBIT_TASK, 'itemtype' => \TicketTask::class, 'items_id' => array_keys($tasks)]]) as $row) {
                unset($tasks[(int) $row['items_id']]);
            }
        }
        if ($tasks !== []) {
            // Copies de fusion d'une tâche déjà débitée : verrouillées sans opération propre
            foreach ($DB->request(['SELECT' => ['tickettasks_id'], 'FROM' => self::TASKS, 'WHERE' => ['tickettasks_id' => array_keys($tasks), 'is_locked' => 1]]) as $row) {
                unset($tasks[(int) $row['tickettasks_id']]);
            }
        }
        $tasks = array_values($tasks);
        usort($tasks, static fn (array $a, array $b): int => [self::taskDate($a), $a['id']] <=> [self::taskDate($b), $b['id']]);

        return $tasks;
    }

    /**
     * Message de refus de clôture : un débit des tâches du ticket serait impossible (pas de grille ou de prix, pas de
     * lot valide, dépassement interdit, contrat à choisir). Null si la clôture est possible. Lecture seule.
     */
    public static function closureProblem(int $ticketsId): ?string
    {
        $ctx = self::context($ticketsId);
        if ($ctx['flatrate']) {
            return Flatrate::closureProblem($ticketsId);
        }
        if (!self::counts($ctx)) {
            return null;
        }
        $tasks = self::pendingTasks($ticketsId);
        if ($tasks === []) {
            return null;
        }
        if ($ctx['ambiguous']) {
            return __('Several Points contracts are linked to this ticket: choose the one to debit in the Points tab, or mark the ticket as not billable', 'points');
        }
        $debits = [];
        foreach ($tasks as $task) {
            try {
                $r = self::evaluate($ctx, $task);
                if ($r['points'] > 0) {
                    $debits[] = [$r['points'], $r['date']];
                    if (!self::hasValidLot($ctx['contracts_id'], $r['date'])) {
                        throw new RuleViolation(RuleViolation::NO_VALID_LOT, 'No valid lot at this date');
                    }
                }
            } catch (RuleViolation $e) {
                return sprintf(__('Task %1$d: %2$s', 'points'), $task['id'], self::describe($e));
            } catch (\InvalidArgumentException) {
                return sprintf(__('Task %1$d: %2$s', 'points'), $task['id'], __('A date is not valid', 'points'));
            }
        }
        try {
            Ledger::assertDebitable($ctx['contracts_id'], $debits);
        } catch (RuleViolation $e) {
            return sprintf(__('The tasks cannot be debited: %s', 'points'), self::describe($e));
        }

        return null;
    }

    /**
     * Débite toutes les tâches non débitées d'un ticket (clôture). Un échec n'arrête pas les suivantes ; les messages
     * des tâches non débitées sont renvoyés (la clôture est déjà faite : elles seront reprises à la clôture suivante).
     *
     * `retry` : au moins une tâche est restée à reprendre (débit perdu sur un conflit concurrent) : la clôture est à refuser.
     *
     * @return array{debited: int, errors: list<string>, retry?: bool}
     */
    public static function debitTicket(int $ticketsId): array
    {
        $ctx = self::context($ticketsId);
        $out = ['debited' => 0, 'errors' => [], 'retry' => false];
        if ($ctx['flatrate']) {
            // Ticket au forfait : les tâches ne sont pas débitées, le forfait approuvé l'est (si ce n'est pas déjà fait)
            return Flatrate::debitOnClosure($ticketsId);
        }
        if (!self::counts($ctx) || $ctx['ambiguous']) {
            return $out;
        }
        foreach (self::pendingTasks($ticketsId) as $task) {
            try {
                $out['debited'] += self::debitTask($ctx, $task['id'], $task) > 0 ? 1 : 0;
            } catch (RuleViolation $e) {
                $out['errors'][] = sprintf(__('Task %1$d: %2$s', 'points'), $task['id'], self::describe($e));
                $out['retry'] = $out['retry'] || $e->reason === RuleViolation::TASK_TO_RESUME;
            } catch (\InvalidArgumentException) {
                $out['errors'][] = sprintf(__('Task %1$d: %2$s', 'points'), $task['id'], __('A date is not valid', 'points'));
            }
        }

        return $out;
    }

    /**
     * Libellé d'un drapeau de tâche ou d'une raison de review (samedi, dimanche, férié, hors créneau) : l'unique source des quatre.
     */
    public static function flagLabel(string $flag): string
    {
        return match ($flag) {
            TaskRules::FLAG_SATURDAY  => __('Saturday', 'points'),
            TaskRules::FLAG_SUNDAY    => __('Sunday', 'points'),
            TaskRules::FLAG_HOLIDAY   => __('Holiday', 'points'),
            TaskRules::FLAG_OFF_HOURS => __('Outside working hours', 'points'),
            default                   => $flag,
        };
    }

    /**
     * Message traduit d'un refus (grille, prix, lot, solde).
     */
    public static function describe(RuleViolation $e): string
    {
        return match ($e->reason) {
            RuleViolation::NO_VALID_LOT    => __('No valid points at the date of the task', 'points'),
            RuleViolation::TASK_TO_RESUME  => __('The task could not be debited because of a concurrent change: it will be taken again, try closing the ticket again', 'points'),
            RuleViolation::NO_GRID         => __('The contract has no price grid', 'points'),
            RuleViolation::NO_GRID_VERSION => __('No grid version applies at the date of the task', 'points'),
            RuleViolation::NO_COST         => __('The grid has no price for this intervention type and day type', 'points'),
            default                        => Ledger::describe($e),
        };
    }
}
