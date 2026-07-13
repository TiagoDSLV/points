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

use function Safe\strtotime;

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

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $consumed = $this->isNewItem() ? 0 : self::getConsumedForCredit($this->getID());
        TemplateRenderer::getInstance()->display('@credit/creditcontract_form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'credittypeclass'   => PluginCreditType::class,
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

        if (!isset($input['name']) || $input['name'] == '') {
            Session::addMessageAfterRedirect(__s('Credit voucher name is mandatory.', 'credit'));
            return false;
        }

        if (isset($input['end_date']) && $input['end_date'] != '') {
            $input['end_date'] .= ' 23:59:59';
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['name']) && (string) $input['name'] === '') {
            Session::addMessageAfterRedirect(__s('Credit voucher name is mandatory.', 'credit'));
            return false;
        }

        if (isset($input['end_date']) && $input['end_date'] != '') {
            $input['end_date'] .= ' 23:59:59';
        }

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
        $ID = $contract->getField('id');
        if (!$contract->can($ID, READ)) {
            return;
        }

        $canedit = $contract->canEdit($ID);

        $columns = [
            'name'                    => __('Name'),
            'plugin_credit_types_id'  => __('Type'),
            'is_active'               => __('Active'),
            'begin_date'              => __('Start date'),
            'end_date'                => __('End date'),
            'quantity'                => __('Quantity sold', 'credit'),
            'quantity_consumed'       => __('Quantity consumed', 'credit'),
            'quantity_remaining'      => __('Quantity remaining', 'credit'),
            'overconsumption_allowed' => __('Allow overconsumption', 'credit'),
            'low_credit_alert'        => __('Low credits alert', 'credit'),
        ];

        $entries = [];
        foreach (self::getAllForContract($ID) as $data) {
            $quantity_sold = (int) $data['quantity'];
            if (0 === $quantity_sold) {
                $quantity_sold = __('Unlimited');
            }

            $item = new self();
            $item = $item->getById($data['id']);

            if (!empty($data['plugin_credit_types_id'])) {
                $type = new PluginCreditType();
                $type = $type->getById($data['plugin_credit_types_id']);
                if ($type) {
                    $data['plugin_credit_types_id'] = $type->getLink();
                }
            } else {
                $data['plugin_credit_types_id'] = '';
            }

            $modal = Ajax::createIframeModalWindow(
                'displaycreditconsumed_' . $data["id"],
                plugin_credit_geturl() . "front/ticket.php?plugcreditcontract=" . $data["id"],
                ['title'         => __('Consumed details', 'credit'),
                    'reloadonclose' => false,
                    'display'       => false,
                    'dialog_class'  => 'modal-xl-credit',
                ],
            );

            $link = "<a href='#' data-bs-toggle='modal' data-bs-target='#displaycreditconsumed_{$data["id"]}' title='" . __('Consumed details', 'credit') . "' alt='" . __('Consumed details', 'credit') . "'>" . PluginCreditContract::getConsumedForCredit($data['id']) . "</a>";

            $entries[] = array_merge($data, [
                'name'                    => $item->getLink(),
                'quantity'                => $quantity_sold,
                'itemtype'                => PluginCreditContract::class,
                'low_credit_alert'        => $data['low_credit_alert'] == -1 ? __('Disabled') : $data['low_credit_alert'] . '%',
                'quantity_consumed'       => $modal . $link,
                'quantity_remaining'      => $data['quantity'] > 0 ? $data['quantity'] - PluginCreditContract::getConsumedForCredit($data['id']) : 'Unlimited',
            ]);
        }

        $rand  = mt_rand();
        $nb = count($entries);
        $massiveactionparams = [
            'num_displayed'    => min($nb, $_SESSION['glpilist_limit']),
            'container'        => 'mass' . self::class . $rand,
            'itemtype'         => PluginCreditContract::class,
        ];

        TemplateRenderer::getInstance()->display('@credit/creditcontract.html.twig', [
            'form_url'            => self::getFormUrl(),
            'credittypeclass'     => PluginCreditType::class,
            'columns'             => $columns,
            'contract_id'         => $ID,
            'entries'             => $entries,
            'canedit'             => $canedit,
            'massiveactionparams' => $massiveactionparams,
        ]);
    }

    /**
     * Get active contracts for a given GLPI entity, for use in the task form dropdown.
     *
     * @param int $entity_id GLPI entity ID
     * @return array [pool_id => label, ...]
     */
    public static function getActiveContractsForEntity(int $entity_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $filter = self::getActiveFilter();
        $iterator = $DB->request([
            'SELECT' => [
                self::getTable() . '.id AS pool_id',
                self::getTable() . '.quantity',
                'glpi_contracts.name AS contract_name',
            ],
            'FROM'      => self::getTable(),
            'LEFT JOIN' => [
                'glpi_contracts' => [
                    'ON' => ['glpi_contracts' => 'id', self::getTable() => 'contracts_id'],
                ],
            ],
            'WHERE' => array_merge($filter, [
                'glpi_contracts.is_deleted'  => 0,
                'glpi_contracts.entities_id' => $entity_id,
            ]),
            'ORDER' => ['glpi_contracts.name ASC'],
        ]);
        $result = [];
        foreach ($iterator as $row) {
            $consumed  = self::getConsumedForCredit((int) $row['pool_id']);
            $remaining = $row['quantity'] > 0 ? max(0, (int) $row['quantity'] - $consumed) : null;
            $label     = $row['contract_name'];
            if ($remaining !== null) {
                $label .= ' (' . $remaining . ' pts restants)';
            }
            $result[(int) $row['pool_id']] = $label;
        }
        return $result;
    }

    public static function getActiveFilter()
    {
        /** @var DBmysql $DB */
        global $DB;
        return [
            self::getTable() . '.is_active' => 1,
            'OR' => [
                self::getTable() . '.end_date' => null,
                new QueryExpression(
                    sprintf(
                        'NOW() < %s',
                        $DB->quoteName(self::getTable() . '.end_date'),
                    ),
                ),
            ],
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

        $tab[] = [
            'id'       => 991,
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 992,
            'table'    => self::getTable(),
            'field'    => 'begin_date',
            'name'     => __('Start date'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 993,
            'table'    => self::getTable(),
            'field'    => 'end_date',
            'name'     => __('End date'),
            'datatype' => 'date',
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
            'id'       => 995,
            'table'    => PluginCreditType::getTable(),
            'field'    => 'name',
            'name'     => PluginCreditType::getTypeName(),
            'datatype' => 'dropdown',
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
            case 'creditexpired':
                return [
                    'description' => __('Expiration date', 'credit'),
                    'parameter'   => __('Notice (in days)', 'credit'),
                ];
            case 'lowcredits':
                return [
                    'description' => __('Low credits', 'credit'),
                ];
        }
        return [];
    }

    public static function cronCreditExpired($task)
    {
        /**
         * @var array $CFG_GLPI
         * @var DBmysql $DB
         */
        global $CFG_GLPI, $DB;

        if (!$CFG_GLPI['use_notifications']) {
            return 0;
        }

        $notice_time = (int) $task->fields['param'];

        $alert = new Alert();
        $credits_iterator = $DB->request(
            [
                'SELECT'    => [
                    self::getTable() . '.*',
                ],
                'FROM'      => self::getTable(),
                'LEFT JOIN' => [
                    'glpi_alerts' => [
                        'ON' => [
                            'glpi_alerts'      => 'items_id',
                            self::getTable()   => 'id',
                            [
                                'AND' => [
                                    'glpi_alerts.itemtype' => self::getType(),
                                    'glpi_alerts.type'     => Alert::END,
                                ],
                            ],
                        ],
                    ],
                ],
                'WHERE'     => [
                    'glpi_alerts.date'                  => null,
                    self::getTable() . '.is_active'     => 1,
                    ['NOT' => [self::getTable() . '.end_date' => null]],
                    new QueryExpression(
                        sprintf(
                            'ADDDATE(NOW(), INTERVAL %s DAY) >= %s',
                            $notice_time,
                            $DB->quoteName(self::getTable() . '.end_date'),
                        ),
                    ),
                ],
            ],
        );

        foreach ($credits_iterator as $credit_data) {
            $task->addVolume(1);
            $task->log(
                sprintf(
                    'Credit %s expires on %s',
                    $credit_data['name'],
                    date('Y-m-d', strtotime($credit_data['end_date'])),
                ),
            );

            $credit = new PluginCreditContract();
            $credit->getFromDB($credit_data['id']);

            NotificationEvent::raiseEvent('expired', $credit);

            $input = [
                'type'     => Alert::END,
                'itemtype' => self::getType(),
                'items_id' => $credit_data['id'],
            ];
            $alert->add($input);
            unset($alert->fields['id']);
        }

        return 1;
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
                    self::getTable() . '.name',
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
                'WHERE' => [
                    self::getTable() . '.is_active' => 1,
                ],
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
                    'Low credit for %s',
                    $credit_data['name'],
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

        if (!$DB->tableExists($table)) {
            $query = <<<SQL
                CREATE TABLE IF NOT EXISTS `$table` (
                    `id` int {$default_key_sign} NOT NULL auto_increment,
                    `name` varchar(255) DEFAULT NULL,
                    `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    `is_active` tinyint NOT NULL DEFAULT '0',
                    `plugin_credit_types_id` tinyint {$default_key_sign} NOT NULL DEFAULT '0',
                    `begin_date` timestamp NULL DEFAULT NULL,
                    `end_date` timestamp NULL DEFAULT NULL,
                    `quantity` int NOT NULL DEFAULT '0',
                    `overconsumption_allowed` tinyint NOT NULL DEFAULT '0',
                    `low_credit_alert` int DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `contracts_id` (`contracts_id`),
                    KEY `name` (`name`),
                    KEY `is_active` (`is_active`),
                    KEY `plugin_credit_types_id` (`plugin_credit_types_id`),
                    KEY `begin_date` (`begin_date`),
                    KEY `end_date` (`end_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        }

        return true;
    }

    /**
     * Uninstall previously installed table of the plugin
     *
     * @return boolean True if success
     */
    public static function uninstall(Migration $migration)
    {
        $table = self::getTable();
        $migration->dropTable($table);

        return true;
    }
}
