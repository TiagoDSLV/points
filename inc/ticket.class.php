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
        global $DB;

        $ID = $ticket->getField('id');
        if (!$ticket->can($ID, READ)) {
            return false;
        }

        $rand    = mt_rand();
        $entries = [];

        foreach (self::getAllForTicket($ID) as $data) {
            $credit_contract = new PluginCreditContract();
            $credit_contract->getFromDB($data['plugin_credit_contracts_id']);

            if (!empty($data['plugin_credit_types_id'])) {
                $type = (new PluginCreditType())->getById($data['plugin_credit_types_id']);
                $data['plugin_credit_types_id'] = $type ? $type->getLink() : '';
            } else {
                $data['plugin_credit_types_id'] = '';
            }

            $entries[] = array_merge($data, [
                'id'                     => $data['id'],
                'name'                   => $credit_contract->getName(),
                'plugin_credit_types_id' => $data['plugin_credit_types_id'],
                'date_creation'          => $data['date_creation'],
                'users_id'               => Session::haveRight('user', READ)
                    ? getUserLink($data['users_id'])
                    : getUserName($data['users_id']),
                'consumed'               => $data['consumed'],
                'itemtype'               => PluginCreditTicket::class,
            ]);
        }

        TemplateRenderer::getInstance()->display('@credit/tickets/form.html.twig', [
            'rand'      => $rand,
            'type_name' => self::getTypeName(2),
            'entries'   => $entries,
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

        $contracts = PluginCreditContract::getActiveContractsForEntity($ticket->getEntityID());
        $baremes   = PluginCreditBareme::getAllBaremes();

        TemplateRenderer::getInstance()->display('@credit/tickets/consume.html.twig', [
            'rand'                 => mt_rand(),
            'consume'              => false,
            'default_credit'       => 0,
            'default_credit_max'   => 0,
            'type_name'            => self::getTypeName(2),
            'contracts'            => $contracts,
            'baremes'              => $baremes,
            'plugin_credit_geturl' => plugin_credit_geturl(),
        ]);
    }

    /**
     * Display the detailled list of tickets on which consumption is declared.
     *
     * @param int $ID plugin_credit_contracts_id
     */
    public static function displayConsumed($ID)
    {
        $consumed_credits = self::getConsumedForCreditContract($ID);
        $tickets_data = [];

        if ($consumed_credits > 0) {
            foreach (self::getAllForCreditContract($ID) as $data) {
                $Ticket = new Ticket();
                $Ticket->getFromDB($data['tickets_id']);

                $itilcat = new ITILCategory();
                $category = __('None');
                if ($itilcat->getFromDB($Ticket->fields['itilcategories_id'])) {
                    $category = $itilcat->getName(['comments' => true]);
                }

                $showuserlink = Session::haveRight('user', READ) ? 1 : 0;

                $ticket_url = $Ticket->getLinkURL();
                $ticket_name = $Ticket->getNameID();
                $username = $showuserlink !== 0 ? getUserLink($data['users_id']) : getUserName($data['users_id']);

                $tickets_data[] = [
                    'ticket_link' => sprintf(
                        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                        htmlescape($ticket_url),
                        htmlescape($ticket_name),
                    ),
                    'status' => Ticket::getStatus($Ticket->fields['status']),
                    'type' => Ticket::getTicketTypeName($Ticket->fields['type']),
                    'category' => $category,
                    'date_creation' => $data["date_creation"],
                    'username' => $username,
                    'consumed' => $data['consumed'],
                ];
            }
        }

        TemplateRenderer::getInstance()->display('@credit/tickets/consumed_details.html.twig', [
            'consumed_credits' => $consumed_credits,
            'tickets_data' => $tickets_data,
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

        if (
            !isset($item->input['plugin_credit_contracts_id'])
            || $item->input['plugin_credit_contracts_id'] == 0
        ) {
            Session::addMessageAfterRedirect(
                __s('You must provide a credit voucher', 'credit'),
                true,
                ERROR,
            );
            return;
        }

        $credit_ticket = new self();

        $credit_entity = new PluginCreditContract();
        $credit_entity->getFromDB($item->input['plugin_credit_contracts_id']);

        $quantity_sold      = (int) $credit_entity->fields['quantity'];
        $quantity_consumed  = $credit_ticket->getConsumedForCreditContract($item->input['plugin_credit_contracts_id']);
        $quantity_remaining = max(0, $quantity_sold - $quantity_consumed);

        if (0 !== $quantity_sold && $quantity_remaining < $item->input['plugin_credit_quantity']) {
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
            'plugin_credit_contracts_id' => $item->input['plugin_credit_contracts_id'],
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

        if (!$DB->tableExists($table)) {
            $query = <<<SQL
                CREATE TABLE IF NOT EXISTS `$table` (
                    `id` int {$default_key_sign} NOT NULL auto_increment,
                    `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    `plugin_credit_contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `consumed` int NOT NULL DEFAULT '0',
                    `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                    PRIMARY KEY (`id`),
                    KEY `tickets_id` (`tickets_id`),
                    KEY `plugin_credit_contracts_id` (`plugin_credit_contracts_id`),
                    KEY `date_creation` (`date_creation`),
                    KEY `consumed` (`consumed`),
                    KEY `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        } else {
            // Fix #1 in 1.0.1 : change tinyint to int for tickets_id
            $migration->changeField($table, 'tickets_id', 'tickets_id', "int {$default_key_sign} NOT NULL DEFAULT 0");

            $migration->changeField($table, 'users_id', 'users_id', "int {$default_key_sign} NOT NULL DEFAULT 0");

            //execute the whole migration
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
