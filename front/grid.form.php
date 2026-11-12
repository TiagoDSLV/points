<?php

declare(strict_types=1);

/**
 * Famille de grilles de prix : réglage, versions datées, matrice type d'intervention × type de jour. Lecture : droit
 * plugin_points_grid READ ; chaque écriture (POST) passe par le modèle (Grid, GridVersion, GridCost), qui exige le droit
 * (CREATE, UPDATE, PURGE) et refuse de modifier une version déjà utilisée. Refus de droit : exception HTTP, jamais un exit.
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Points\Config;
use GlpiPlugin\Points\Grid;
use GlpiPlugin\Points\GridAdmin;
use GlpiPlugin\Points\GridVersion;
use GlpiPlugin\Points\Rights;

global $CFG_GLPI;

Session::checkRight(Rights::GRID, READ);

if ($_POST !== []) {
    $grid    = new Grid();
    $version = new GridVersion();
    $saved   = __s('Saved', 'points');
    if (isset($_POST['add'])) {
        $input = ['name' => (string) ($_POST['name'] ?? ''), 'comment' => (string) ($_POST['comment'] ?? ''), 'unit_minutes' => (int) ($_POST['unit_minutes'] ?? 60), 'is_active' => 1];
        $grid->check(-1, CREATE, $input);
        $id = $grid->add($input);
        if ($id !== false) {
            Session::addMessageAfterRedirect($saved);
            Html::redirect(Grid::getFormURLWithID((int) $id));
        }
    } elseif (isset($_POST['update'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $grid->check($id, UPDATE);
        if ($grid->update([
            'id'           => $id,
            'name'         => (string) ($_POST['name'] ?? ''),
            'comment'      => (string) ($_POST['comment'] ?? ''),
            'unit_minutes' => (int) ($_POST['unit_minutes'] ?? 60),
            'is_active'    => (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0,
        ])) {
            Session::addMessageAfterRedirect($saved);
        }
    } elseif (isset($_POST['purge'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $grid->check($id, PURGE);
        if ($grid->delete(['id' => $id], true)) {
            Session::addMessageAfterRedirect(__s('Deleted', 'points'));
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/points/front/grid.php');
        }
    } elseif (isset($_POST['add_version'])) {
        $input = [
            'plugin_points_grids_id' => (int) ($_POST['plugin_points_grids_id'] ?? 0),
            'begin_date'             => (string) ($_POST['begin_date'] ?? ''),
            'comment'                => (string) ($_POST['comment'] ?? ''),
        ];
        $version->check(-1, CREATE, $input);
        $versionId = $version->add($input);
        if ($versionId !== false) {
            $copyFrom = (int) ($_POST['copy_from'] ?? 0);
            if ($copyFrom > 0) {
                GridAdmin::copyCosts($copyFrom, (int) $versionId);
            }
            Session::addMessageAfterRedirect($saved);
        }
    } elseif (isset($_POST['update_version'])) {
        $id = (int) ($_POST['version_id'] ?? 0);
        $version->check($id, UPDATE);
        if ($version->update(['id' => $id, 'begin_date' => (string) ($_POST['begin_date'] ?? ''), 'comment' => (string) ($_POST['comment'] ?? '')])) {
            Session::addMessageAfterRedirect($saved);
        }
    } elseif (isset($_POST['purge_version'])) {
        $id = (int) ($_POST['version_id'] ?? 0);
        $version->check($id, PURGE);
        if ($version->delete(['id' => $id], true)) {
            Session::addMessageAfterRedirect(__s('Deleted', 'points'));
        }
    } elseif (isset($_POST['save_costs'])) {
        $cells = is_array($_POST['cost'] ?? null) ? $_POST['cost'] : [];
        if (GridAdmin::saveCosts((int) ($_POST['version_id'] ?? 0), $cells)) {
            Session::addMessageAfterRedirect($saved);
        }
    } else {
        throw new BadRequestHttpException();
    }
    Html::back();
}

Html::header(Grid::getTypeName(1), $_SERVER['PHP_SELF'], 'config', Config::class);
TemplateRenderer::getInstance()->display('@points/grid.html.twig', GridAdmin::detailData((int) ($_GET['id'] ?? 0)));
Html::footer();
