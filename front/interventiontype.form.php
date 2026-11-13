<?php

declare(strict_types=1);

/**
 * Types d'intervention (liste déroulante native). POST seulement ; chaque écriture passe par le modèle
 * InterventionType, qui exige le droit plugin_points_grid (CREATE, UPDATE, PURGE) et refuse de supprimer un type utilisé.
 */

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Points\InterventionType;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::GRID, READ);
if ($_POST === []) {
    throw new BadRequestHttpException();
}
$type = new InterventionType();
if (isset($_POST['add'])) {
    $input = ['name' => (string) ($_POST['name'] ?? ''), 'comment' => (string) ($_POST['comment'] ?? '')];
    $type->check(-1, CREATE, $input);
    if ($type->add($input) !== false) {
        Session::addMessageAfterRedirect(__s('Saved', 'points'));
    }
} elseif (isset($_POST['update'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $type->check($id, UPDATE);
    if ($type->update(['id' => $id, 'name' => (string) ($_POST['name'] ?? ''), 'comment' => (string) ($_POST['comment'] ?? '')])) {
        Session::addMessageAfterRedirect(__s('Saved', 'points'));
    }
} elseif (isset($_POST['purge'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $type->check($id, PURGE);
    if ($type->delete(['id' => $id], true)) {
        Session::addMessageAfterRedirect(__s('Deleted', 'points'));
    }
} else {
    throw new BadRequestHttpException();
}
Html::back();
