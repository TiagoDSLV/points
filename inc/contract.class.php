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

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;

class PluginCreditContract extends CommonDBTM
{
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return _sn('Points', 'Points', $nb, 'credit');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    public function getName($options = [])
    {
        return Dropdown::getDropdownName('glpi_contracts', $this->fields['contracts_id'] ?? 0);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $consumed = $this->isNewItem() ? 0 : self::getConsumedForCredit($this->getID());
        TemplateRenderer::getInstance()->display('@credit/creditcontract_form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'quantity_consumed' => $consumed,
            'contract_id'       => $options['contract_id'] ?? 0,
        ]);
        return true;
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (self::canView()) {
            $menu['title']           = self::getTypeName(Session::getPluralNumber());
            $menu['page']            = self::getSearchURL(false);
            $menu['icon']            = self::getIcon();
            $menu['links']['search'] = self::getSearchURL(false);
        }
        return $menu;
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public function canCreateItem(): bool
    {
        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Contract) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs']) {
                $nb = self::countForItem($item);
            }
            return self::createTabEntry(self::getTypeName($nb), $nb);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Contract) {
            self::showForContract($item);
        }
        return true;
    }

    /**
     * @param $item    CommonDBTM object
     */
    public static function countForItem(CommonDBTM $item)
    {
        return countElementsInTable(
            self::getTable(),
            ['contracts_id' => $item->getID()],
        );
    }

    public function prepareInputForAdd($input)
    {
        if (!empty($input['contracts_id']) && countElementsInTable(self::getTable(), ['contracts_id' => $input['contracts_id']]) > 0) {
            Session::addMessageAfterRedirect(__s('A points pool already exists for this contract.', 'credit'), true, ERROR);
            return false;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $input;
    }

    public function post_purgeItem()
    {
        $pc_ticket = new PluginCreditTicket();
        $pc_ticket->deleteByCriteria([
            'plugin_credit_contracts_id' => $this->getID(),
        ]);
    }

    /**
     * Get all credit vouchers for contract.
     *
     * @param int   $contracts_id  contracts ID
     * @param array $sqlfilter     to add a SQL filter (default [])
     * @return array of vouchers
     */
    public static function getAllForContract(int $contracts_id, array $sqlfilter = []): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $request = [
            'SELECT' => '*',
            'FROM'   => self::getTable(),
            'WHERE'  => array_merge(['contracts_id' => $contracts_id], $sqlfilter),
            'ORDER'  => ['id DESC'],
        ];

        $vouchers = [];
        foreach ($DB->request($request) as $data) {
            $vouchers[$data['id']] = $data;
        }

        return $vouchers;
    }

    /**
     * Show credit vouchers of a contract
     *
     * @param Contract $contract
     */
    public static function showForContract(Contract $contract)
    {
        /** @var DBmysql $DB */
        global $DB;

        $ID = $contract->getField('id');
        if (!$contract->can($ID, READ)) {
            return;
        }

        $canedit = $contract->canEdit($ID);

        // Find the pool for this contract.
        $pool = null;
        $pool_iterator = $DB->request([
            'SELECT' => '*',
            'FROM'   => self::getTable(),
            'WHERE'  => ['contracts_id' => $ID],
            'LIMIT'  => 1,
        ]);
        if (count($pool_iterator) > 0) {
            $pool = $pool_iterator->current();
        }

        // Load consumption entries if pool exists.
        $consumed_entries = [];
        if ($pool !== null) {
            $tickets_table = PluginCreditTicket::getTable();
            $iterator = $DB->request([
                'SELECT' => [
                    $tickets_table . '.*',
                    'glpi_users.name AS tech_name',
                    'glpi_tickettasks.date AS task_date',
                ],
                'FROM'      => $tickets_table,
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => ['glpi_users' => 'id', $tickets_table => 'users_id'],
                    ],
                    'glpi_tickettasks' => [
                        'ON' => ['glpi_tickettasks' => 'id', $tickets_table => 'tickettasks_id'],
                    ],
                ],
                'WHERE' => ['plugin_credit_contracts_id' => (int) $pool['id']],
                'ORDER' => [$tickets_table . '.id DESC'],
            ]);

            foreach ($iterator as $data) {
                $ticket      = new Ticket();
                $ticket_link = '';
                $task_link = '';
                if ($ticket->getFromDB($data['tickets_id'])) {
                    $ticket_link = sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($ticket->getLinkURL()),
                        htmlspecialchars($ticket->getNameID()),
                    );

                    if ((int) ($data['tickettasks_id'] ?? 0) > 0) {
                        $task_link = sprintf(
                            '<a href="%s#TicketTask%d">%s #%d</a>',
                            htmlspecialchars($ticket->getLinkURL()),
                            (int) $data['tickettasks_id'],
                            __('Task', 'credit'),
                            (int) $data['tickettasks_id'],
                        );
                    }
                }

                $bareme_name = '';
                if ((int) ($data['plugin_credit_bareme_id'] ?? 0) > 0) {
                    $bareme = new PluginCreditBareme();
                    if ($bareme->getFromDB($data['plugin_credit_bareme_id'])) {
                        $bareme_name = $bareme->getName();
                    }
                }

                $consumed_entries[] = [
                    'id'          => (int) $data['id'],
                    'ticket_link' => $ticket_link,
                    'task_link'   => $task_link,
                    'date'        => $data['task_date'] ?? $data['date_mod'] ?? '',
                    'tech_name'   => $data['tech_name'] ?? '',
                    'bareme_name' => $bareme_name,
                    'consumed'    => (int) $data['consumed'],
                ];
            }
        }

        // Compute stats.
        $total_consumed = array_sum(array_column($consumed_entries, 'consumed'));
        $quantity       = (int) ($pool['quantity'] ?? 0);
        $remaining      = ($pool !== null && $quantity > 0) ? max(0, $quantity - $total_consumed) : null;

        TemplateRenderer::getInstance()->display('@credit/creditcontract.html.twig', [
            'item'             => $contract,
            'pool'             => $pool,
            'consumed_entries' => $consumed_entries,
            'total_consumed'   => $total_consumed,
            'remaining'        => $remaining,
            'canedit'          => $canedit,
            'form_url'         => self::getFormUrl(),
            'ticket_form_url'  => plugin_credit_geturl() . 'front/ticket.form.php',
        ]);
    }

    /**
     * Get the points pool linked to a ticket (via glpi_contracts_items).
     *
     * @param int $ticket_id Ticket ID
     * @return array{pool_id: int, contract_name: string, remaining: int|null, unlimited: bool}|null
     */
    public static function getPoolForTicket(int $ticket_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        // In GLPI 11, contracts are not explicitly linked to tickets via glpi_contracts_items.
        // The "Contrats" tab on a ticket shows contracts matching the ticket's entity.
        // We match the same way: find a pool whose contract belongs to the ticket's entity.
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id)) {
            return null;
        }
        $entity_id = (int) $ticket->fields['entities_id'];

        $pool_table = self::getTable();
        $iterator = $DB->request([
            'SELECT' => [
                $pool_table . '.id AS pool_id',
                $pool_table . '.quantity',
                $pool_table . '.overconsumption_allowed',
                'glpi_contracts.name AS contract_name',
            ],
            'FROM'       => $pool_table,
            'INNER JOIN' => [
                'glpi_contracts' => [
                    'ON' => ['glpi_contracts' => 'id', $pool_table => 'contracts_id'],
                ],
            ],
            'WHERE' => [
                'glpi_contracts.is_deleted'  => 0,
                'glpi_contracts.entities_id' => $entity_id,
            ],
            'LIMIT' => 1,
        ]);

        $row = $iterator->current();
        if (!$row) {
            return null;
        }

        $pool_id  = (int) $row['pool_id'];
        $quantity = (int) $row['quantity'];

        $consumed  = self::getConsumedForCredit($pool_id);
        $remaining = $quantity > 0 ? max(0, $quantity - $consumed) : null;

        return [
            'pool_id'       => $pool_id,
            'contract_name' => $row['contract_name'],
            'remaining'     => $remaining,
            'unlimited'     => $quantity === 0,
        ];
    }

    public static function getMaximumConsumptionForCredit(int $credit_id)
    {
        /** @var DBmysql $DB */
        global $DB;

        $query = [
            'SELECT' => ['overconsumption_allowed', 'quantity'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'id' => $credit_id,
            ],
        ];
        $result = $DB->request($query)->current();
        $overconsumption_allowed = $result['overconsumption_allowed'];
        $quantity_sold           = (int) $result['quantity'];

        if (0 !== $quantity_sold && !$overconsumption_allowed) {
            $consumed = self::getConsumedForCredit($credit_id);
            $max      = max(0, $quantity_sold - $consumed);

            return $max;
        } else {
            return 100000;
        }
    }

    /**
     * Get the total consumption for a credit voucher.
     *
     * @param int $credit_id ID of the credit voucher
     *
     * @return int Total consumption
     */
    public static function getConsumedForCredit(int $credit_id)
    {
        /** @var DBmysql $DB */
        global $DB;

        $ticket_query = [
            'SELECT' => [
                'SUM' => 'consumed AS consumed_total',
            ],
            'FROM'   => PluginCreditTicket::getTable(),
            'WHERE'  => [
                'plugin_credit_contracts_id' => $credit_id,
            ],
        ];

        $ticket_result = $DB->request($ticket_query)->current();

        return (int) $ticket_result['consumed_total'];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        // Replace the generic name (ID=1 from parent, points to our table's "name" column
        // which doesn't exist) with a join on glpi_contracts.name.
        foreach ($tab as $key => $option) {
            if (($option['id'] ?? 0) == 1) {
                $tab[$key] = [
                    'id'         => 1,
                    'table'      => 'glpi_contracts',
                    'field'      => 'name',
                    'name'       => _n('Contract', 'Contracts', 1),
                    'datatype'   => 'itemlink',
                    'itemtype'   => 'Contract',
                    'joinparams' => [
                        'jointype'  => '',
                        'linkfield' => 'contracts_id',
                    ],
                ];
                break;
            }
        }

        $tab[] = [
            'id'         => 993,
            'table'      => 'glpi_entities',
            'field'      => 'completename',
            'name'       => Entity::getTypeName(1),
            'datatype'   => 'dropdown',
            'joinparams' => [
                'jointype'   => '',
                'linkfield'  => 'entities_id',
                'beforejoin' => [
                    'table'      => 'glpi_contracts',
                    'joinparams' => [
                        'jointype'  => '',
                        'linkfield' => 'contracts_id',
                    ],
                ],
            ],
        ];

        $tab[] = [
            'id'       => 994,
            'table'    => self::getTable(),
            'field'    => 'quantity',
            'name'     => __('Quantity sold', 'credit'),
            'datatype' => 'number',
            'min'      => 1,
            'max'      => 1000000,
            'step'     => 1,
            'toadd'    => [0 => __('Unlimited')],
        ];

        $tab[] = [
            'id'       => 996,
            'table'    => self::getTable(),
            'field'    => 'overconsumption_allowed',
            'name'     => __('Allow overconsumption', 'credit'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'      => 997,
            'table'   => self::getTable(),
            'field'   => 'low_credit_alert',
            'name'    => __('Low credit alert', 'credit'),
            'datatype' => 'number',
            'min'     => 0,
            'max'     => 50,
            'step'    => 10,
            'toadd'   => [-1 => __('Disabled')],
            'unit'    => '%',
        ];

        return $tab;
    }

    public static function cronInfo($name)
    {
        switch ($name) {
            case 'lowcredits':
                return [
                    'description' => __('Low credits', 'credit'),
                ];
        }
        return [];
    }

    public static function cronLowCredits($task)
    {
        /**
         * @var array $CFG_GLPI
         * @var DBmysql $DB
         */
        global $CFG_GLPI, $DB;

        if (!$CFG_GLPI['use_notifications']) {
            return 0;
        }

        $alert = new Alert();
        $credits_iterator = $DB->request(
            [
                'SELECT' => [
                    self::getTable() . '.id',
                    self::getTable() . '.contracts_id',
                    self::getTable() . '.quantity',
                    self::getTable() . '.low_credit_alert',
                    new QueryExpression('SUM(' . PluginCreditTicket::getTable() . '.consumed) AS quantity_consumed'),
                ],
                'FROM' => self::getTable(),
                'LEFT JOIN' => [
                    PluginCreditTicket::getTable() => [
                        'ON' => [
                            PluginCreditTicket::getTable() => 'plugin_credit_contracts_id',
                            self::getTable()               => 'id',
                        ],
                    ],
                ],
                'WHERE' => [],
                'GROUPBY' => self::getTable() . '.id',
                'HAVING' => [new QueryExpression(
                    $DB->quoteName(self::getTable() . '.quantity')
                    . ' - quantity_consumed <= ('
                    . $DB->quoteName(self::getTable() . '.quantity')
                    . ' * '
                    . $DB->quoteName(self::getTable() . '.low_credit_alert')
                    . ') / 100'
                )],
            ],
        );

        foreach ($credits_iterator as $credit_data) {
            $task->addVolume(1);
            $task->log(
                sprintf(
                    'Low credit for contract #%d',
                    $credit_data['contracts_id'],
                ),
            );

            $credit = new PluginCreditContract();
            $credit->getFromDB($credit_data['id']);

            NotificationEvent::raiseEvent('lowcredits', $credit);

            $input = [
                'type'     => Alert::END,
                'itemtype' => self::getType(),
                'items_id' => $credit_data['id'],
            ];

            if (countElementsInTable(Alert::getTable(), $input) === 0) {
                $alert->add($input);
                unset($alert->fields['id']);
            }
        }

        return 1;
    }

    /**
     * Install all necessary tables for the plugin
     *
     * @return boolean True if success
     */
    public static function install(Migration $migration)
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        $create_sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id` int {$default_key_sign} NOT NULL auto_increment,
                `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `quantity` int NOT NULL DEFAULT '0',
                `overconsumption_allowed` tinyint NOT NULL DEFAULT '0',
                `low_credit_alert` int DEFAULT '-1',
                PRIMARY KEY (`id`),
                UNIQUE KEY `contracts_id` (`contracts_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
SQL;

        if (!$DB->tableExists($table)) {
            $DB->doQuery($create_sql);
        } elseif ($DB->fieldExists($table, 'name')) {
            // Old schema with name/is_active/dates — recreate clean (dev env, no real data).
            $DB->doQuery("DROP TABLE `{$table}`");
            $DB->doQuery($create_sql);
        }
        // else: schema already correct, nothing to do.

        return true;
    }

    /**
     * Uninstall previously installed table of the plugin
     *
     * @return boolean True if success
     */
    public static function uninstall(Migration $migration)
    {
        $migration->dropTable(self::getTable());
        $migration->dropTable('glpi_plugin_credit_types');

        return true;
    }
}
