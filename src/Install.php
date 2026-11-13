<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Config as GlpiConfig;
use CronTask;
use DBConnection;
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
                ] + $stamps,
                'keys' => [
                    $unique('contracts_id', 'contracts_id'),
                    $idx('plugin_points_grids_id'), $idx('entities_id'), $idx('is_active'), $idx('date_creation'), $idx('date_mod'),
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
                ] + $stamps,
                'keys' => [
                    $unique('tickettasks_id', 'tickettasks_id'),
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
                    'reason'                   => $state(''),
                    'status'                   => $state('to_review'),
                    'users_id'                 => $fk,
                    'comment'                  => $txt,
                    'operations_id_adjustment' => $fk,
                ] + $stamps,
                'keys' => [
                    $idx('operations_id'), $idx('status'), $idx('users_id'), $idx('operations_id_adjustment'),
                    $idx('entities_id'), $idx('date_creation'), $idx('date_mod'),
                ],
            ],
            'glpi_plugin_points_compensations' => [
                'columns' => $head() + [
                    'operations_id'        => $fk,
                    'tickets_id'           => $fk,
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
                    $idx('operations_id'), $idx('tickets_id'), $idx('status'), $idx('users_id'), $idx('users_id_decision'),
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

        $assistance = self::seedInterventionTypes();
        self::seedConfig($assistance);
        self::seedRights();
        self::registerCronTasks();
        self::seedNotifications();

        return true;
    }

    /**
     * Notifications natives des forfaits : un modèle (texte et HTML, balises `##lang.*##` traduites dans la langue de chaque
     * destinataire, d'où des messages en français et en anglais), et une notification par événement avec ses cibles
     * (Responsables BU pour `flatrate_submitted`, auteur du forfait = technicien pour `flatrate_decided`). Rejouable : ce
     * qui existe déjà (créé ou modifié par l'administrateur) n'est jamais réécrit.
     */
    private static function seedNotifications(): void
    {
        global $DB;

        $now        = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $itemtype   = Flatrate::class;
        $templateId = 0;
        $found      = $DB->request(['SELECT' => ['id'], 'FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'name' => self::TEMPLATE_NAME], 'LIMIT' => 1])->current();
        if ($found !== null) {
            $templateId = (int) $found['id'];
        } else {
            $DB->insert(NotificationTemplate::getTable(), ['name' => self::TEMPLATE_NAME, 'itemtype' => $itemtype, 'date_mod' => $now, 'date_creation' => $now]);
            $templateId = (int) $DB->insertId();
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => '##flatrate.action## - ##lang.flatrate.ticket## ##flatrate.ticket##',
                'content_text'             => implode("\n", [
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
                ]),
                'content_html'             => '<p><strong>##flatrate.action##</strong></p>'
                    . '<p>##lang.flatrate.ticket## : ##flatrate.ticket## ##flatrate.ticketname##<br />'
                    . '##lang.flatrate.entity## : ##flatrate.entity##<br />'
                    . '##lang.flatrate.status## : ##flatrate.status##<br />'
                    . '##lang.flatrate.amount## : ##flatrate.amount##<br />'
                    . '##lang.flatrate.approved## : ##flatrate.approved##<br />'
                    . '##lang.flatrate.description## : ##flatrate.description##<br />'
                    . '##lang.flatrate.comment## : ##flatrate.comment##<br />'
                    . '##lang.flatrate.technician## : ##flatrate.technician##<br />'
                    . '##lang.flatrate.url## : ##flatrate.url##</p>',
            ]);
        }

        // Événement => [nom, cible]
        foreach ([
            'flatrate_submitted' => ['Points: flatrate submitted to the BU manager', Flatrate::TARGET_BU],
            'flatrate_decided'   => ['Points: flatrate decision', Notification::AUTHOR],
        ] as $event => [$name, $target]) {
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
     * Actions automatiques : `pointsexpire` (quotidienne). Rejouable : CronTask::register ne recrée pas une tâche
     * existante (src/CronTask.php:1084 en 12.0.1 : doublon évité, renvoie false), et ne touche pas à ses réglages.
     */
    private static function registerCronTasks(): void
    {
        CronTask::register(Cron::class, Cron::EXPIRE, DAY_TIMESTAMP, [
            'comment' => 'Expire or carry over the points of ended lots (docs/conception.md, rule 8)',
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

        foreach (self::tableNames() as $table) {
            $DB->dropTable($table, true);
        }
        ProfileRight::deleteProfileRights(Rights::names());
        $DB->delete(GlpiConfig::getTable(), ['context' => Config::CONTEXT]);
        CronTask::unregister('points');
        self::removeNotificationsAndAlerts();

        return true;
    }

    /**
     * Notifications, modèles, alertes, préférences d'affichage, recherches enregistrées et historiques dont l'itemtype est une classe du plugin.
     * Les notifications des forfaits sont créées par seedNotifications ; les alertes arrivent avec un lot suivant.
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
        $DB->delete('glpi_displaypreferences', ['itemtype' => $like]);
        $DB->delete('glpi_savedsearches', ['itemtype' => $like]);
        $DB->delete('glpi_logs', ['itemtype' => $like]);
    }
}
