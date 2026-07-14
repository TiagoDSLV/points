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

if (!isset($_POST['add_points'])) {
    Html::back();
    exit;
}

$pool_id      = (int) ($_POST['plugin_credit_contracts_id'] ?? 0);
$quantity     = (int) ($_POST['quantity'] ?? 0);
$order_number = trim($_POST['order_number'] ?? '');

if ($pool_id <= 0 || $quantity <= 0 || $order_number === '') {
    Session::addMessageAfterRedirect(
        __s('Invalid input: quantity and order number are required.', 'credit'),
        true,
        ERROR,
    );
    Html::back();
    exit;
}

$pool = new PluginCreditContract();
$pool->check($pool_id, UPDATE);

$addition = new PluginCreditAddition();
$input = [
    'plugin_credit_contracts_id' => $pool_id,
    'quantity'                   => $quantity,
    'order_number'               => $order_number,
    'users_id'                   => Session::getLoginUserID(),
];

if ($addition->add($input)) {
    $log_msg = sprintf(
        __('%1$d pts added (Order: %2$s)', 'credit'),
        $quantity,
        $order_number,
    );
    Log::history(
        (int) $pool->fields['contracts_id'],
        Contract::class,
        [0, '', $log_msg],
        PluginCreditAddition::class,
        Log::HISTORY_ADD_SUBITEM,
    );
    Session::addMessageAfterRedirect(
        __s('Points added successfully.', 'credit'),
        true,
        INFO,
    );
}

Html::back();
