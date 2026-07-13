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

class PluginCreditTicket extends CommonDBTM
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0)
    {
        return _sn('Credit voucher', 'Credit vouchers', $nb, 'credit');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $nb = $item instanceof CommonDBTM ? self::countForItem($item) : 0;
        if ($item instanceof Ticket) {
            if ($_SESSION['glpishow_count_on_tabs']) {
                return self::createTabEntry(self::getTypeName($nb), $nb);
            } else {
                return self::getTypeName($nb);
            }
        } else {
            return self::getTypeName($nb);
        }
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Ticket) {
            self::showForTicket($item);
        }
        return true;
    }

    /**
     * @param $item    CommonDBTM object
     */
    public static function countForItem(CommonDBTM $item)
    {
        return countElementsInTable(self::getTable(), ['tickets_id' => $item->getID()]);
    }

    /**
     * Get all credit vouchers for a ticket.
     *
     * @param $ID           integer     tickets ID
     * @return array of vouchers
     */
    public static function getAllForTicket($ID): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $request = [
            'SELECT' => '*',
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'tickets_id' => $ID,
            ],
            'ORDER'  => ['id DESC'],
        ];

        $vouchers = [];
        foreach ($DB->request($request) as $data) {
            $vouchers[$data['id']] = $data;
        }

        return $vouchers;
    }


    /**
     * Get all tickets for a credit voucher.
     *
     * @param $ID           integer     plugin_credit_contracts_id ID
     * @return array of vouchers
     */
    public static function getAllForCreditContract($ID): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $request = [
            'SELECT' => '*',
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_credit_contracts_id' => $ID,
            ],
            'ORDER'  => ['id DESC'],
        ];

        $tickets = [];
        foreach ($DB->request($request) as $data) {
            $tickets[$data['id']] = $data;
        }

        return $tickets;
    }

    /**
     * Get consumed tickets for credit contract entry
     *
     * @param $ID integer PluginCreditContract id
     */
    public static function getConsumedForCreditContract($ID)
    {
        /** @var DBmysql $DB */
        global $DB;

        $tot   = 0;

        $request = [
            'SELECT' => ['SUM' => 'consumed as sum'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_credit_contracts_id' => $ID,
            ],
        ];

        $result = $DB->request($request);
        if ($row = $result->current()) {
            $tot = $row['sum'];
        }

        return $tot;
    }

    /**
     * Show credit vouchers consumed for a ticket
     *
     * @param $ticket Ticket object
     */
    public static function showForTicket(Ticket $ticket)
    {
        $ID = $ticket->getField('id');
        if (!$ticket->can($ID, READ)) {
            return false;
        }

        $rand           = mt_rand();
        $entries        = [];
        $total_consumed = 0;

        $ticket = new Ticket();
        $ticket->getFromDB($ID);

        foreach (self::getAllForTicket($ID) as $data) {
            $credit_contract = new PluginCreditContract();
            $credit_contract->getFromDB($data['plugin_credit_contracts_id']);

            $total_consumed += (int) $data['consumed'];

            $task_link = '';
            if ((int) ($data['tickettasks_id'] ?? 0) > 0) {
                $task_link = sprintf(
                    '<a href="%s#TicketTask%d">%s #%d</a>',
                    htmlspecialchars($ticket->getLinkURL()),
                    (int) $data['tickettasks_id'],
                    __('Task', 'credit'),
                    (int) $data['tickettasks_id'],
                );
            }

            $entries[] = array_merge($data, [
                'id'            => $data['id'],
                'name'          => $credit_contract->getName(),
                'date_creation' => $data['date_creation'],
                'users_id'      => Session::haveRight('user', READ)
                    ? getUserLink($data['users_id'])
                    : getUserName($data['users_id']),
                'consumed'      => $data['consumed'],
                'task_link'     => $task_link,
                'itemtype'      => PluginCreditTicket::class,
            ]);
        }

        $pool             = PluginCreditContract::getPoolForTicket($ID);
        $remaining_points = null;
        $pool_unlimited   = false;
        $pool_name        = null;
        if ($pool !== null) {
            $remaining_points = $pool['remaining'];
            $pool_unlimited   = $pool['unlimited'];
            $pool_name        = $pool['contract_name'];
        }

        TemplateRenderer::getInstance()->display('@credit/tickets/form.html.twig', [
            'rand'             => $rand,
            'type_name'        => self::getTypeName(2),
            'entries'          => $entries,
            'total_consumed'   => $total_consumed,
            'remaining_points' => $remaining_points,
            'pool_unlimited'   => $pool_unlimited,
            'pool_name'        => $pool_name,
        ]);
    }

    /**
     * Display voucher consumption fields at the end of a ticket processing form.
     *
     * @param array $params Array with "item" and "options" keys
     *
     * @return void
     */
    public static function displayVoucherInTicketProcessingForm($params)
    {
        $item = $params['item'];

        // Only inject on new TicketTask forms
        if (!($item instanceof TicketTask) || !$item->isNewItem()) {
            return;
        }

        // Find parent Ticket
        $ticket = $params['options']['parent'] ?? null;
        if (!($ticket instanceof Ticket)) {
            return;
        }

        // Ticket must be open and editable
        if (
            in_array($ticket->fields['status'], Ticket::getSolvedStatusArray())
            || in_array($ticket->fields['status'], Ticket::getClosedStatusArray())
            || !$ticket->canEdit($ticket->getID())
        ) {
            return;
        }

        $pool    = PluginCreditContract::getPoolForTicket($ticket->getID());
        $baremes = PluginCreditBareme::getAllBaremes();

        TemplateRenderer::getInstance()->display('@credit/tickets/consume.html.twig', [
            'consume'   => false,
            'type_name' => self::getTypeName(2),
            'pool'      => $pool,
            'baremes'   => $baremes,
        ]);
    }

    /**
     * Test if consumed voucher is selected and add them.
     *
     * @param CommonDBTM $item Created item
     *
     * @return void
     */
    public static function consumeVoucher(CommonDBTM $item)
    {
        if (!count($item->input)) {
            return;
        }

        $ticketId = null;
        if (array_key_exists('tickets_id', $item->fields)) {
            // Ticket ID can be found in `tickets_id` field for TicketTask.
            $ticketId = $item->fields['tickets_id'];
        } elseif (
            array_key_exists('itemtype', $item->fields)
             && array_key_exists('items_id', $item->fields)
             && 'Ticket' == $item->fields['itemtype']
        ) {
            // Ticket ID can be found in `items_id` field for ITILFollowup and ITILSolution.
            $ticketId = $item->fields['items_id'];
        }

        $ticket = new Ticket();
        if (null === $ticketId || !$ticket->getFromDB($ticketId)) {
            return;
        }

        if (
            !is_numeric(Session::getLoginUserID(false))
            || !Session::haveRightsOr('ticket', [Ticket::STEAL, Ticket::OWN])
        ) {
            return;
        }

        if (
            !isset($item->input['plugin_credit_consumed_voucher'])
            || $item->input['plugin_credit_consumed_voucher'] != 1
        ) {
            return;
        }

        $pool = PluginCreditContract::getPoolForTicket((int) $ticketId);
        if ($pool === null) {
            return; // no points contract linked to this ticket
        }
        $pool_id = $pool['pool_id'];

        $credit_ticket = new self();

        $credit_entity = new PluginCreditContract();
        $credit_entity->getFromDB($pool_id);

        $quantity_sold      = (int) $credit_entity->fields['quantity'];
        $quantity_consumed  = $credit_ticket->getConsumedForCreditContract($pool_id);
        $quantity_remaining = max(0, $quantity_sold - $quantity_consumed);

        if (0 !== $quantity_sold && $quantity_remaining < ($item->input['plugin_credit_quantity'] ?? 1)) {
            if ($credit_entity->getField('overconsumption_allowed')) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __s('Quantity consumed exceeds remaining credits: %d', 'credit'),
                        $quantity_remaining,
                    ),
                    true,
                    WARNING,
                );
            } else {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __s('Quantity consumed exceeds remaining credits: %d', 'credit'),
                        $quantity_remaining,
                    ),
                    true,
                    ERROR,
                );
                return;
            }
        }

        // Auto-calculate quantity from task duration + bareme when both are provided.
        $quantity = (int) ($item->input['plugin_credit_quantity'] ?? 1);
        if (
            $item instanceof TicketTask
            && !empty($item->input['plugin_credit_bareme_id'])
            && (int) $item->input['plugin_credit_bareme_id'] > 0
            && isset($item->fields['actiontime'])
            && (int) $item->fields['actiontime'] > 0
        ) {
            $calculated = PluginCreditBareme::calculatePoints(
                (int) $item->fields['actiontime'],
                (int) $item->input['plugin_credit_bareme_id'],
            );
            if ($calculated > 0) {
                $quantity = $calculated;
            }
        }

        $input = [
            'tickets_id'                 => $ticket->getID(),
            'plugin_credit_contracts_id' => $pool_id,
            'tickettasks_id'             => ($item instanceof TicketTask) ? $item->getID() : 0,
            'plugin_credit_bareme_id'    => (int) ($item->input['plugin_credit_bareme_id'] ?? 0),
            'consumed'                   => $quantity,
            'users_id'                   => Session::getLoginUserID(),
        ];
        if ($credit_ticket->add($input)) {
            Session::addMessageAfterRedirect(
                __s('Credit voucher successfully added.', 'credit'),
                true,
                INFO,
            );
        }
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 881,
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Date consumed', 'credit'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 882,
            'table'    => self::getTable(),
            'field'    => 'consumed',
            'name'     => __('Quantity consumed', 'credit'),
            'datatype' => 'number',
            'min'      => 1,
            'max'      => 1000000,
            'step'     => 1,
            'toadd'    => [0 => __('Unlimited')],
        ];

        $tab[] = [
            'id'       => 883,
            'table'    => PluginCreditContract::getTable(),
            'field'    => 'name',
            'name'     => PluginCreditContract::getTypeName(Session::getPluralNumber()),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * Install all necessary table for the plugin
     *
     * @return boolean True if success
     */
    public static function install(Migration $migration)
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        $create_sql = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id` int {$default_key_sign} NOT NULL auto_increment,
                `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `plugin_credit_contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `tickettasks_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `plugin_credit_bareme_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `consumed` int NOT NULL DEFAULT '0',
                `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `plugin_credit_contracts_id` (`plugin_credit_contracts_id`),
                KEY `tickettasks_id` (`tickettasks_id`),
                KEY `plugin_credit_bareme_id` (`plugin_credit_bareme_id`),
                KEY `date_creation` (`date_creation`),
                KEY `consumed` (`consumed`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
SQL;

        if (!$DB->tableExists($table)) {
            $DB->doQuery($create_sql);
        } elseif (!$DB->fieldExists($table, 'plugin_credit_contracts_id')) {
            // Old schema used `plugin_credit_entities_id` — incompatible, recreate clean.
            $DB->doQuery("DROP TABLE `{$table}`");
            $DB->doQuery($create_sql);
        } else {
            // Fix #1 in 1.0.1 : change tinyint to int for tickets_id
            $migration->changeField($table, 'tickets_id', 'tickets_id', "int {$default_key_sign} NOT NULL DEFAULT 0");
            $migration->changeField($table, 'users_id', 'users_id', "int {$default_key_sign} NOT NULL DEFAULT 0");
            $migration->addField($table, 'tickettasks_id', "int {$default_key_sign} NOT NULL DEFAULT 0", ['after' => 'plugin_credit_contracts_id']);
            $migration->addKey($table, 'tickettasks_id');
            $migration->addField($table, 'plugin_credit_bareme_id', "int {$default_key_sign} NOT NULL DEFAULT 0", ['after' => 'tickettasks_id']);
            $migration->addKey($table, 'plugin_credit_bareme_id');
            $migration->executeMigration();
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
