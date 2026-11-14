<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Config as GlpiConfig;
use CronTask;
use DBConnection;
use GlpiPlugin\Points\Domain\FlatrateRules;
use Migration;
use Notification;
use NotificationTemplate;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Installation, mise à jour et désinstallation du plugin (appelées par hook.php).
 *
 * L'installation est rejouable et sert de mise à jour : tables créées si absentes, champs et index manquants
 * ajoutés (Migration), données initiales et droits posés seulement s'ils manquent. Un rejeu ne change jamais
 * un droit ni une valeur de configuration déjà posés.
 *
 * Le DDL est du SQL brut (seul endroit du plugin) ; tout le reste passe par les tableaux de critères de GLPI
 * ($DB->request, insert, delete : src/DBmysql.php).
 */
final class Install
{
    /** Types d'intervention créés à l'installation (vocabulaire métier : stockés tels quels, pas traduits). */
    public const INTERVENTION_TYPES = ['Assistance', 'Ordre de travail', 'Intervention urgente'];

    /** Nom du modèle de notification des forfaits (un seul, pour les deux événements). */
    private const TEMPLATE_NAME = 'Points: flatrate';

    /** Nom du modèle de notification des compensations. */
    private const COMPENSATION_TEMPLATE_NAME = 'Points: compensation';

    /** Nom du modèle de notification du seuil d'alerte. */
    private const THRESHOLD_TEMPLATE_NAME = 'Points: threshold';

    /** Motif LIKE des classes du plugin (le séparateur de l'espace de noms est doublé : échappement LIKE). */
    private const LIKE_CLASSES = 'GlpiPlugin\\\\Points\\\\%';

    /**
     * Modèle de données de docs/conception.md (« Modèle de données »).
     *
     * @param string $sign option de signe des clés (DBConnection::getDefaultPrimaryKeySignOption : 'unsigned' ou '')
     * @return array<string, array{columns: array<string, string>, keys: list<array{0: string, 1: string, 2: list<string>}>}>
     *         nom de table => colonnes (nom => définition SQL) et index (type, nom, colonnes)
     */
    public static function schema(string $sign = 'unsigned'): array
    {
        $id   = preg_replace('/\s+/', ' ', "int $sign NOT NULL AUTO_INCREMENT");
        $fk   = preg_replace('/\s+/', ' ', "int $sign NOT NULL DEFAULT 0");
        $ts   = 'timestamp NULL DEFAULT NULL';
        $date = 'date DEFAULT NULL';
        $amt  = 'decimal(12,2) NOT NULL DEFAULT 0.00';
        $txt  = 'text DEFAULT NULL';
        $str  = 'varchar(255) DEFAULT NULL';
        $flag = fn (int $default): string => "tinyint NOT NULL DEFAULT $default";
        $state = fn (string $default): string => "varchar(30) NOT NULL DEFAULT '$default'";

        // Colonnes communes : clé, cloisonnement par entité (copie de l'entité du contrat, is_recursive 0 :
        // seules les grilles et les types d'intervention, globaux, sont récursifs)
        $head = fn (int $recursive = 0): array => [
            'id'           => $id,
            'entities_id'  => $fk,
            'is_recursive' => $flag($recursive),
        ];
        $created = ['date_creation' => $ts];
        $stamps  = ['date_creation' => $ts, 'date_mod' => $ts];
        $idx     = static fn (string ...$cols): array => ['INDEX', implode('_', $cols), $cols];
        $unique  = static fn (string $name, string ...$cols): array => ['UNIQUE', $name, $cols];

        return [
            'glpi_plugin_points_grids' => [
                'columns' => $head(1) + [
                    'name'         => $str,
                    'comment'      => $txt,
                    'is_active'    => $flag(1),
                    'unit_minutes' => 'int unsigned NOT NULL DEFAULT 60',
                ] + $stamps,
                'keys' => [$idx('name'), $idx('entities_id'), $idx('is_active'), $idx('date_creation'), $idx('date_mod')],
            ],
            'glpi_plugin_points_gridversions' => [
                'columns' => $head(1) + [
                    'plugin_points_grids_id' => $fk,
                    'begin_date'             => $date,
                    'comment'                => $txt,
                ] + $stamps,
                'keys' => [$idx('plugin_points_grids_id', 'begin_date'), $idx('entities_id'), $idx('date_creation'), $idx('date_mod')],
            ],
            'glpi_plugin_points_gridcosts' => [
                'columns' => $head(1) + [
                    'plugin_points_gridversions_id'     => $fk,
                    'plugin_points_interventiontypes_id' => $fk,
                    'daytype'                           => 'tinyint NOT NULL DEFAULT 1', // 1 semaine, 2 samedi, 3 dimanche et férié
                    'cost'                              => $amt,
                ] + $stamps,
                'keys' => [
                    $unique('unicity', 'plugin_points_gridversions_id', 'plugin_points_interventiontypes_id', 'daytype'),
                    $idx('plugin_points_interventiontypes_id'), $idx('entities_id'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_interventiontypes' => [
                'columns' => $head(1) + [
                    'name'    => $str,
                    'comment' => $txt,
                ] + $stamps,
                'keys' => [$idx('name'), $idx('entities_id'), $idx('date_creation'), $idx('date_mod')],
            ],
            'glpi_plugin_points_contracts' => [
                'columns' => $head() + [
                    'contracts_id'           => $fk,
                    'plugin_points_grids_id' => $fk,
                    'alert_threshold'        => 'int NOT NULL DEFAULT 80', // % ; défaut du paramétrage
                    'is_active'              => $flag(1),
                    'balance'                => $amt, // cache, recalculable depuis le journal
                    'credited'               => $amt,
                    'consumed'               => $amt,
                    'date_cache'             => $ts,
                    // Colonnes de recherche (filtre et tri natifs), recalculées avec le cache par Ledger (même transaction) et recomputeCache
                    'next_expiry'            => $date, // plus petite échéance d'un lot au reste > 0
                    'consumed_pct'           => 'decimal(8,2) NOT NULL DEFAULT 0.00', // consumed / credited du cache, en %
                ] + $stamps,
                'keys' => [
                    $unique('contracts_id', 'contracts_id'),
                    $idx('plugin_points_grids_id'), $idx('entities_id'), $idx('is_active'), $idx('balance'), $idx('consumed_pct'),
                    $idx('next_expiry'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_envelopes' => [
                'columns' => $head() + [
                    'contracts_id' => $fk,
                    'name'         => $str,
                    'credit'       => $amt,
                    'begin_date'   => $date,
                    'end_date'     => $date,
                    'is_carryover' => $flag(0),
                    'users_id'     => $fk,
                ] + $stamps,
                'keys' => [
                    $idx('contracts_id'), $idx('entities_id'), $idx('begin_date'), $idx('end_date'), $idx('users_id'),
                    $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            // Journal en ajout seul : pas de date_mod
            'glpi_plugin_points_operations' => [
                'columns' => $head() + [
                    'contracts_id'         => $fk,
                    'type'                 => $state(''),
                    'amount'               => $amt, // signé : + crédit, - débit
                    'date'                 => $date, // date métier (DATE) ; l'heure éventuelle va dans `details`
                    'tickets_id'           => $fk,
                    'itemtype'             => 'varchar(255) DEFAULT NULL',
                    'items_id'             => $fk,
                    'operations_id_origin' => $fk,
                    'comment'              => $txt,
                    'details'              => 'longtext DEFAULT NULL', // JSON
                    'users_id'             => $fk,
                ] + $created,
                'keys' => [
                    $idx('contracts_id'), $idx('entities_id'), $idx('type'), $idx('date'), $idx('tickets_id'),
                    ['INDEX', 'item', ['itemtype', 'items_id']], $idx('operations_id_origin'), $idx('users_id'),
                    $idx('date_creation'),
                ],
            ],
            'glpi_plugin_points_lots' => [
                'columns' => $head() + [
                    'operations_id'                => $fk,
                    'plugin_points_envelopes_id'   => $fk,
                    'contracts_id'                 => $fk,
                    'origin_date'                  => $date,
                    'expiry_date'                  => $date,
                    'initial'                      => $amt,
                ] + $created,
                'keys' => [
                    $idx('operations_id'), $idx('plugin_points_envelopes_id'), $idx('entities_id'),
                    $idx('contracts_id', 'expiry_date'), $idx('origin_date'), $idx('date_creation'),
                ],
            ],
            'glpi_plugin_points_allocations' => [
                'columns' => $head() + [
                    'operations_id'          => $fk,
                    'plugin_points_lots_id'  => $fk,
                    'amount'                 => $amt, // signé ; reste d'un lot = somme de ses affectations
                ] + $created,
                'keys' => [$idx('operations_id'), $idx('plugin_points_lots_id'), $idx('entities_id'), $idx('date_creation')],
            ],
            'glpi_plugin_points_tickets' => [
                'columns' => $head() + [
                    'tickets_id'                         => $fk,
                    'contracts_id'                       => $fk,
                    'plugin_points_interventiontypes_id' => $fk,
                    'is_billable'                        => $flag(1),
                ] + $stamps,
                'keys' => [
                    $unique('tickets_id', 'tickets_id'),
                    $idx('contracts_id'), $idx('plugin_points_interventiontypes_id'), $idx('entities_id'),
                    $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_tasks' => [
                'columns' => $head() + [
                    'tickettasks_id' => $fk,
                    'tickets_id'     => $fk,
                    'operations_id'  => $fk, // 0 tant que la tâche n'est pas débitée
                    'is_locked'      => $flag(0),
                    // Version de grille du débit (écrite avec lui) : GridVersion::isUsed la lit par cet index au lieu de fouiller les détails
                    'plugin_points_gridversions_id' => $fk,
                ] + $stamps,
                'keys' => [
                    $unique('tickettasks_id', 'tickettasks_id'),
                    $idx('plugin_points_gridversions_id'),
                    $idx('tickets_id'), $idx('operations_id'), $idx('entities_id'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_flatrates' => [
                'columns' => $head() + [
                    'tickets_id'          => $fk,
                    'contracts_id'        => $fk,
                    'amount'              => $amt,
                    'description'         => $txt,
                    'status'              => $state('draft'),
                    'users_id'            => $fk, // technicien
                    'users_id_bu'         => $fk,
                    'date_bu'             => $ts,
                    'bu_comment'          => $txt,
                    'users_id_validate'   => $fk, // validateur client
                    'ticketvalidations_id' => $fk,
                    'approved_amount'     => $amt, // figé à l'approbation
                    'operations_id'       => $fk,
                ] + $stamps,
                'keys' => [
                    $idx('tickets_id'), $idx('contracts_id'), $idx('status'), $idx('users_id'), $idx('users_id_bu'),
                    $idx('users_id_validate'), $idx('ticketvalidations_id'), $idx('operations_id'), $idx('entities_id'),
                    $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_reviews' => [
                'columns' => $head() + [
                    'operations_id'            => $fk,
                    'tickets_id'               => $fk, // copie du ticket et du contrat du débit : filtres de la recherche native
                    'contracts_id'             => $fk,
                    'reason'                   => $state(''),
                    'status'                   => $state('to_review'),
                    'users_id'                 => $fk,
                    'comment'                  => $txt,
                    'operations_id_adjustment' => $fk,
                ] + $stamps,
                'keys' => [
                    $idx('operations_id'), $idx('tickets_id'), $idx('contracts_id'), $idx('status'), $idx('users_id'), $idx('operations_id_adjustment'),
                    $idx('entities_id'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_compensations' => [
                'columns' => $head() + [
                    'operations_id'        => $fk,
                    'tickets_id'           => $fk,
                    'contracts_id'         => $fk, // copie du contrat du débit : filtre de la recherche native
                    'amount'               => $amt, // demandé
                    'comment'              => $txt, // motif obligatoire
                    'status'               => $state('pending'),
                    'users_id'             => $fk, // demandeur
                    'users_id_decision'    => $fk,
                    'date_decision'        => $ts,
                    'decision_comment'     => $txt,
                    'approved_amount'      => $amt,
                    'operations_id_credit' => $fk,
                ] + $stamps,
                'keys' => [
                    $idx('operations_id'), $idx('tickets_id'), $idx('contracts_id'), $idx('status'), $idx('users_id'), $idx('users_id_decision'),
                    $idx('operations_id_credit'), $idx('entities_id'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function tableNames(): array
    {
        return array_keys(self::schema());
    }

    /**
     * CREATE TABLE d'une table du schéma (InnoDB, charset et collation de GLPI).
     *
     * @param array{columns: array<string, string>, keys: list<array{0: string, 1: string, 2: list<string>}>} $spec
     */
    public static function createTableSql(string $table, array $spec, string $charset, string $collation): string
    {
        $lines = [];
        foreach ($spec['columns'] as $name => $definition) {
            $lines[] = "`$name` $definition";
        }
        $lines[] = 'PRIMARY KEY (`id`)';
        foreach ($spec['keys'] as [$type, $name, $columns]) {
            $lines[] = ($type === 'UNIQUE' ? 'UNIQUE KEY' : 'KEY') . " `$name` (`" . implode('`, `', $columns) . '`)';
        }

        return "CREATE TABLE `$table` (\n  " . implode(",\n  ", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";
    }

    public static function install(Migration $migration): bool
    {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();

        $backfill = false;
        foreach (self::schema(DBConnection::getDefaultPrimaryKeySignOption()) as $table => $spec) {
            if (!$DB->tableExists($table, false)) {
                $migration->displayMessage("Création de $table");
                $DB->doQuery(self::createTableSql($table, $spec, $charset, $collation));
                continue;
            }
            // Mise à jour : champs et index manquants (jamais de suppression ni de modification)
            foreach ($spec['columns'] as $field => $definition) {
                if (!$DB->fieldExists($table, $field, false)) {
                    $migration->addField($table, $field, $definition);
                    $backfill = $backfill || ($table === 'glpi_plugin_points_contracts' && in_array($field, ['next_expiry', 'consumed_pct'], true));
                }
            }
            foreach ($spec['keys'] as [$type, $name, $columns]) {
                $migration->addKey($table, $columns, $name, $type);
            }
        }
        $migration->executeMigration();

        // operations.date a été un TIMESTAMP : converti en DATE au rejeu (l'heure est perdue, elle n'a jamais été écrite)
        $dateType = $DB->listFields('glpi_plugin_points_operations', false)['date']['Type'] ?? 'date';
        if (stripos((string) $dateType, 'timestamp') !== false || stripos((string) $dateType, 'datetime') !== false) {
            $DB->doQuery('ALTER TABLE `glpi_plugin_points_operations` MODIFY `date` date DEFAULT NULL');
        }

        // Colonnes de recherche du cache (lot g, `next_expiry`, `consumed_pct`) : remplies depuis le journal SEULEMENT quand ce passage vient de les
        // ajouter à une table qui existait déjà (un rejeu ne réécrit rien : un contrat aux lots épuisés a légitimement une échéance NULL). Une
        // migration interrompue entre l'ajout et la reprise se rattrape par `Ledger::recomputeCache` (et la prochaine écriture du journal du contrat recalcule de toute façon ces colonnes).
        if ($backfill) {
            foreach ($DB->request(['SELECT' => ['contracts_id'], 'FROM' => 'glpi_plugin_points_contracts']) as $row) {
                Ledger::recomputeCache((int) $row['contracts_id']);
            }
        }

        // Version de grille des tâches déjà débitées : reprise depuis les détails de leur opération de débit, à CHAQUE installation (une
        // migration interrompue entre l'ajout de la colonne et la reprise se rattrape au rejeu). Seules les lignes à 0 sont lues et
        // complétées : un rejeu complet ne réécrit rien.
        foreach ($DB->request(['SELECT' => ['id', 'operations_id'], 'FROM' => 'glpi_plugin_points_tasks', 'WHERE' => ['is_locked' => 1, 'plugin_points_gridversions_id' => 0, 'operations_id' => ['>', 0]]]) as $row) {
            $version = Operation::gridVersionOf((int) $row['operations_id']);
            if ($version > 0) {
                $DB->update('glpi_plugin_points_tasks', ['plugin_points_gridversions_id' => $version], ['id' => $row['id']]);
            }
        }

        $assistance = self::seedInterventionTypes();
        self::seedConfig($assistance);
        self::seedRights();
        self::registerCronTasks();
        self::seedNotifications();
        self::seedDisplay();

        return true;
    }

    /**
     * Notifications natives : modèles (texte et HTML, balises `##lang.*##` traduites dans la langue de chaque destinataire,
     * d'où des messages en français et en anglais) et une notification par événement avec sa cible. Forfaits : un modèle
     * et deux événements (Responsables BU pour `flatrate_submitted`, auteur du forfait = technicien pour `flatrate_decided`).
     * Compensations : un modèle et l'événement `compensation_decided` (auteur de la demande). Rejouable : ce qui existe
     * déjà (créé ou modifié par l'administrateur) n'est jamais réécrit.
     */
    private static function seedNotifications(): void
    {
        self::seedNotificationSet(Flatrate::class, self::TEMPLATE_NAME, '##flatrate.action## - ##lang.flatrate.ticket## ##flatrate.ticket##', [
            '##flatrate.action##',
            '##lang.flatrate.ticket## : ##flatrate.ticket## ##flatrate.ticketname##',
            '##lang.flatrate.entity## : ##flatrate.entity##',
            '##lang.flatrate.status## : ##flatrate.status##',
            '##lang.flatrate.amount## : ##flatrate.amount##',
            '##lang.flatrate.approved## : ##flatrate.approved##',
            '##lang.flatrate.description## : ##flatrate.description##',
            '##lang.flatrate.comment## : ##flatrate.comment##',
            '##lang.flatrate.technician## : ##flatrate.technician##',
            '##lang.flatrate.url## : ##flatrate.url##',
        ], [
            // Événement => [nom, cible]
            'flatrate_submitted' => ['Points: flatrate submitted to the BU manager', Flatrate::TARGET_BU],
            'flatrate_decided'   => ['Points: flatrate decision', Notification::AUTHOR],
        ]);
        self::seedNotificationSet(Compensation::class, self::COMPENSATION_TEMPLATE_NAME, '##compensation.action## - ##lang.compensation.ticket## ##compensation.ticket##', [
            '##compensation.action##',
            '##lang.compensation.ticket## : ##compensation.ticket## ##compensation.ticketname##',
            '##lang.compensation.entity## : ##compensation.entity##',
            '##lang.compensation.status## : ##compensation.status##',
            '##lang.compensation.amount## : ##compensation.amount##',
            '##lang.compensation.approved## : ##compensation.approved##',
            '##lang.compensation.reason## : ##compensation.reason##',
            '##lang.compensation.decision## : ##compensation.decision##',
            '##lang.compensation.requester## : ##compensation.requester##',
            '##lang.compensation.decider## : ##compensation.decider##',
            '##lang.compensation.url## : ##compensation.url##',
        ], [
            'compensation_decided' => ['Points: compensation decision', Notification::AUTHOR],
        ]);
        // Seuil d'alerte : destinataire par défaut = administrateur de l'entité du contrat (question 4 du cahier des charges)
        self::seedNotificationSet(Contract::class, self::THRESHOLD_TEMPLATE_NAME, '##points.action## - ##points.contract## (##points.client##)', [
            '##points.action##',
            '##lang.points.contract## : ##points.contract##',
            '##lang.points.client## : ##points.client##',
            '##lang.points.balance## : ##points.balance##',
            '##lang.points.credited## : ##points.credited##',
            '##lang.points.consumed## : ##points.consumed##',
            '##lang.points.percent## : ##points.percent## %',
            '##lang.points.threshold## : ##points.threshold## %',
            '##lang.points.expiry## : ##points.expiry##',
            '##lang.points.url## : ##points.url##',
        ], [
            ThresholdAlert::EVENT => ['Points: threshold reached', Notification::ENTITY_ADMINISTRATOR],
        ]);
    }

    /**
     * Un modèle (traduction par défaut : première ligne en titre, les autres en lignes) et ses notifications.
     *
     * @param class-string $itemtype
     * @param list<string> $lines lignes du message, balises comprises
     * @param array<string, array{0: string, 1: int}> $events événement => [nom de la notification, cible]
     */
    private static function seedNotificationSet(string $itemtype, string $templateName, string $subject, array $lines, array $events): void
    {
        global $DB;

        $now        = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $templateId = 0;
        $found      = $DB->request(['SELECT' => ['id'], 'FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'name' => $templateName], 'LIMIT' => 1])->current();
        if ($found !== null) {
            $templateId = (int) $found['id'];
        } else {
            $DB->insert(NotificationTemplate::getTable(), ['name' => $templateName, 'itemtype' => $itemtype, 'date_mod' => $now, 'date_creation' => $now]);
            $templateId = (int) $DB->insertId();
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => $subject,
                'content_text'             => implode("\n", $lines),
                'content_html'             => '<p><strong>' . $lines[0] . '</strong></p><p>' . implode('<br />', array_slice($lines, 1)) . '</p>',
            ]);
        }

        foreach ($events as $event => [$name, $target]) {
            if (count($DB->request(['SELECT' => ['id'], 'FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'event' => $event], 'LIMIT' => 1])) > 0) {
                continue;
            }
            $DB->insert(Notification::getTable(), [
                'name' => $name, 'entities_id' => 0, 'itemtype' => $itemtype, 'event' => $event, 'is_recursive' => 1, 'is_active' => 1,
                'allow_response' => 0, 'date_mod' => $now, 'date_creation' => $now,
            ]);
            $notificationId = (int) $DB->insertId();
            $DB->insert('glpi_notifications_notificationtemplates', ['notifications_id' => $notificationId, 'mode' => 'mailing', 'notificationtemplates_id' => $templateId]);
            $DB->insert('glpi_notificationtargets', ['notifications_id' => $notificationId, 'type' => Notification::USER_TYPE, 'items_id' => $target]);
        }
    }

    /**
     * Colonnes par défaut de la liste « Contrats à points » (préférence globale d'affichage, users_id 0) : contrat, client, BU, solde, crédité,
     * consommé, % consommé, prochaine échéance, grille. Semées seulement si la liste n'a aucune préférence globale (jamais réécrites : un
     * administrateur peut les changer).
     */
    private static function seedDisplay(): void
    {
        global $DB;

        $where = ['itemtype' => Contract::class, 'users_id' => 0, 'interface' => 'central'];
        if (count($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_displaypreferences', 'WHERE' => $where, 'LIMIT' => 1])) > 0) {
            return;
        }
        foreach ([10, 80, 11, 3, 4, 5, 8, 9, 12] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', $where + ['num' => $num, 'rank' => $rank + 1]);
        }
    }

    /**
     * Actions automatiques : `pointsexpire` et `pointsalert` (quotidiennes). Rejouable : CronTask::register ne recrée pas une tâche
     * existante (src/CronTask.php:1084 en 12.0.1 : doublon évité, renvoie false), et ne touche pas à ses réglages.
     */
    private static function registerCronTasks(): void
    {
        CronTask::register(Cron::class, Cron::EXPIRE, DAY_TIMESTAMP, [
            'comment' => 'Expire or carry over the points of ended lots (docs/conception.md, rule 8)',
            'state'   => CronTask::STATE_WAITING,
        ]);
        CronTask::register(Cron::class, Cron::ALERT, DAY_TIMESTAMP, [
            'comment' => 'Notify the contracts that reached their points alert threshold (docs/conception.md, rule 12)',
            'state'   => CronTask::STATE_WAITING,
        ]);
    }

    /**
     * Types d'intervention : semés seulement si la table est vide (création), jamais au rejeu sur une table
     * peuplée (un type renommé ou supprimé par l'administrateur n'est pas recréé). Renvoie l'id de « Assistance »,
     * à défaut du premier type, à défaut 0 : sert seulement de valeur initiale de configuration.
     */
    private static function seedInterventionTypes(): int
    {
        global $DB;

        $table = 'glpi_plugin_points_interventiontypes';
        if (count($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'LIMIT' => 1])) === 0) {
            $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
            foreach (self::INTERVENTION_TYPES as $name) {
                $DB->insert($table, [
                    'entities_id'   => 0,
                    'is_recursive'  => 1,
                    'name'          => $name,
                    'date_creation' => $now,
                    'date_mod'      => $now,
                ]);
            }
        }
        foreach ([['name' => self::INTERVENTION_TYPES[0]], ['id' => ['>', 0]]] as $where) {
            $found = $DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'ORDER' => 'id', 'LIMIT' => 1]);
            if (count($found) > 0) {
                return (int) $found->current()['id'];
            }
        }

        return 0;
    }

    /**
     * Configuration aux défauts de conception.md, sans écraser une valeur existante.
     */
    private static function seedConfig(int $defaultInterventionTypeId): void
    {
        $defaults = ConfigValues::defaults();
        $defaults['default_interventiontypes_id'] = $defaultInterventionTypeId;
        $missing = array_diff_key(
            ConfigValues::toStorage($defaults),
            GlpiConfig::getConfigurationValues(Config::CONTEXT)
        );
        if ($missing !== []) {
            GlpiConfig::setConfigurationValues(Config::CONTEXT, $missing);
        }
        Config::reset();
    }

    /**
     * Droits : une ligne par profil et par droit (0 par défaut) ; seuls les droits créés par ce passage sont donnés
     * en entier aux Super-Admin. Un droit déjà présent n'est jamais modifié.
     */
    private static function seedRights(): void
    {
        global $DB;

        $created = [];
        foreach (Rights::names() as $name) {
            if (count($DB->request(['SELECT' => ['id'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $name], 'LIMIT' => 1])) === 0) {
                $created[] = $name;
            }
        }
        if ($created !== []) {
            // src/ProfileRight.php:155 : une ligne à 0 pour chaque profil existant
            if (!ProfileRight::addProfileRights($created)) {
                throw new \RuntimeException('ProfileRight::addProfileRights a échoué');
            }
        }

        // Profil sans ligne pour un droit (profil créé entre-temps) : ligne à 0
        $profiles = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => GlpiProfile::getTable()])), 'id');
        foreach (Rights::names() as $name) {
            $have = array_column(iterator_to_array($DB->request(['SELECT' => ['profiles_id'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $name]])), 'profiles_id');
            foreach (array_diff($profiles, $have) as $profileId) {
                $DB->insert(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name, 'rights' => 0]);
            }
        }

        // Profils « administrateurs » = ceux qui ont le droit du cœur `config` en écriture (config & UPDATE) ; sur une
        // installation neuve, seul le Super-Admin (install/empty_data.php:6780). On ne se fie pas au nom (renommable)
        // ni à Profile::getSuperAdminProfilesId() (src/Profile.php:3921), qui lit le droit `profile`, pas `config`.
        $grant = array_intersect_key(Rights::fullMasks(), array_flip($created));
        if ($grant !== []) {
            $admins = $DB->request(['SELECT' => ['profiles_id'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => 'config', 'rights' => ['&', UPDATE]]]);
            foreach ($admins as $row) {
                ProfileRight::updateProfileRights((int) $row['profiles_id'], $grant);
            }
        }
    }

    public static function uninstall(): bool
    {
        global $DB;

        // Avant de supprimer les tables : les validations de ticket en attente créées pour des forfaits proposés ne doivent pas rester
        // sans réponse possible (le ticket garderait un statut de validation « en attente » sans fin)
        self::purgeProposedValidations();
        foreach (self::tableNames() as $table) {
            $DB->dropTable($table, true);
        }
        ProfileRight::deleteProfileRights(Rights::names());
        $DB->delete(GlpiConfig::getTable(), ['context' => Config::CONTEXT]);
        Config::reset();
        CronTask::unregister('points');
        self::removeNotificationsAndAlerts();
        self::removeSearchTraces();

        return true;
    }

    /**
     * Forfaits proposés au client : le forfait est annulé (il ne protège plus sa validation) puis la validation native en attente est
     * purgée par GLPI, qui recalcule le statut global de validation du ticket (Flatrate::purgeProposedValidation). Sans objet si la table
     * des forfaits n'existe pas.
     */
    private static function purgeProposedValidations(): void
    {
        global $DB;

        if (!$DB->tableExists(Flatrate::getTable(), false)) {
            return;
        }
        foreach ($DB->request(['FROM' => Flatrate::getTable(), 'WHERE' => ['status' => FlatrateRules::PROPOSED, 'ticketvalidations_id' => ['>', 0]]]) as $row) {
            $DB->update(Flatrate::getTable(), ['status' => FlatrateRules::CANCELLED], ['id' => $row['id']]);
            Flatrate::purgeProposedValidation($row);
        }
    }

    /**
     * Ce que le plugin a ajouté au contrat et à l'entité natifs : colonnes de la liste (préférences d'affichage) portant ses options de
     * recherche, et recherches enregistrées qui les utilisent, de tout type (méta-critère d'un ticket sur le contrat compris :
     * SearchOptions::queryUses), supprimées par SavedSearch::delete avec leurs tables filles (utilisateurs par défaut, alertes). Les
     * recherches enregistrées sur les classes du plugin sont supprimées de la même façon.
     */
    private static function removeSearchTraces(): void
    {
        global $DB;

        $numbers = SearchOptions::numbers();
        foreach ($numbers as $itemtype => $list) {
            $DB->delete('glpi_displaypreferences', ['itemtype' => $itemtype, 'num' => $list]);
        }
        foreach ($DB->request(['SELECT' => ['id', 'itemtype', 'query'], 'FROM' => 'glpi_savedsearches']) as $row) {
            $ours = str_starts_with((string) $row['itemtype'], 'GlpiPlugin\\Points\\')
                || SearchOptions::queryUses((string) $row['query'], (string) $row['itemtype'], $numbers);
            if ($ours) {
                (new \SavedSearch())->delete(['id' => (int) $row['id']], true);
            }
        }
    }

    /**
     * Notifications, modèles, alertes, courriels en file d'attente, préférences d'affichage et historiques dont l'itemtype est une classe du plugin (les recherches
     * enregistrées : removeSearchTraces).
     * Les notifications des forfaits et des compensations sont créées par seedNotifications ; les alertes de seuil (glpi_alerts) sont celles du contrat à points.
     */
    public static function removeNotificationsAndAlerts(): void
    {
        global $DB;

        $like = ['LIKE', self::LIKE_CLASSES];
        foreach ([Notification::class, NotificationTemplate::class] as $class) {
            $item = new $class();
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $class::getTable(), 'WHERE' => ['itemtype' => $like]]) as $row) {
                $item->delete(['id' => $row['id']], true);
            }
        }
        $DB->delete('glpi_alerts', ['itemtype' => $like]);
        // Courriels en file d'attente d'un objet du plugin : ils ne pourraient plus être rendus sans leur classe
        $DB->delete('glpi_queuednotifications', ['itemtype' => $like]);
        $DB->delete('glpi_displaypreferences', ['itemtype' => $like]);
        $DB->delete('glpi_logs', ['itemtype' => $like]);
    }
}
