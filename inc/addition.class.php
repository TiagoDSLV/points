<?php

/**
 * -------------------------------------------------------------------------
 * Credit plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Credit.
 *
 * Credit is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Credit is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Credit. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @author    François Legastelois
 * @copyright Copyright (C) 2017-2023 by Credit plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/credit
 * -------------------------------------------------------------------------
 */

class PluginCreditAddition extends CommonDBTM
{
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return _sn('Points addition', 'Points additions', $nb, 'credit');
    }

    public static function getIcon()
    {
        return 'ti ti-plus';
    }

    /**
     * Sum of all added points for a pool.
     */
    public static function getTotalForPool(int $pool_id): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = $DB->request([
            'SELECT' => ['SUM' => 'quantity AS total'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_credit_contracts_id' => $pool_id],
        ])->current();

        return (int) ($result['total'] ?? 0);
    }

    /**
     * All addition rows for a pool, ordered most-recent first.
     */
    public static function getForPool(int $pool_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT'    => [self::getTable() . '.*', 'glpi_users.name AS tech_name'],
            'FROM'      => self::getTable(),
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_users' => 'id', self::getTable() => 'users_id'],
                ],
            ],
            'WHERE' => ['plugin_credit_contracts_id' => $pool_id],
            'ORDER' => [self::getTable() . '.id DESC'],
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function install(Migration $migration): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $DB->doQuery(<<<SQL
                CREATE TABLE IF NOT EXISTS `$table` (
                    `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `plugin_credit_contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    `quantity` int NOT NULL DEFAULT '0',
                    `order_number` varchar(255) NOT NULL DEFAULT '',
                    `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `plugin_credit_contracts_id` (`plugin_credit_contracts_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
            SQL);
        }

        return true;
    }

    public static function uninstall(Migration $migration): bool
    {
        $migration->dropTable(self::getTable());
        return true;
    }
}
