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
use Glpi\Event;
use Glpi\Exception\Http\BadRequestHttpException;

$PluginCreditContract = new PluginCreditContract();

if (isset($_POST["add"])) {
    $PluginCreditContract->check(-1, CREATE, $_POST);
    if ($PluginCreditContract->add($_POST)) {
        Event::log(
            $PluginCreditContract->getID(),
            "contract",
            4,
            "setup",
            sprintf(__('%s adds a vouchers to a contract'), $_SESSION["glpiname"]),
        );
    }
    Html::back();
} elseif (isset($_POST["update"])) {
    $PluginCreditContract->check($_POST['id'], UPDATE);
    $PluginCreditContract->update($_POST);
    Html::back();
} elseif (isset($_POST["delete"])) {
    $PluginCreditContract->check($_POST['id'], DELETE);
    $PluginCreditContract->delete($_POST);
    $PluginCreditContract->redirectToList();
} elseif (isset($_POST["restore"])) {
    $PluginCreditContract->check($_POST['id'], DELETE);
    $PluginCreditContract->restore($_POST);
    $PluginCreditContract->redirectToList();
} elseif (isset($_POST["purge"])) {
    $PluginCreditContract->check($_POST['id'], PURGE);
    $PluginCreditContract->delete($_POST, true);
    $PluginCreditContract->redirectToList();
} elseif (isset($_GET['id'])) {
    $ID = isset($_GET['id']) ? intval($_GET['id']) : 0;

    Session::checkRight(PluginCreditContract::$rightname, READ);

    if (isset($_GET['forcetab'])) {
        Session::setActiveTab(PluginCreditContract::class, $_GET['forcetab']);
        unset($_GET['forcetab']);
    }

    Html::header(PluginCreditContract::getTypeName(), $_SERVER['PHP_SELF'], "admin", PluginCreditContract::class, "credit");
    $PluginCreditContract->display(['id' => $ID]);
    Html::footer();
} else {
    throw new BadRequestHttpException();
}
