<?php

declare(strict_types=1);

/**
 * Configuration > Points > Grilles : familles de grilles de prix et types d'intervention. Lecture : droit
 * plugin_points_grid READ ; les écritures sont dans grid.form.php et interventiontype.form.php.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Points\Config;
use GlpiPlugin\Points\Grid;
use GlpiPlugin\Points\GridAdmin;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::GRID, READ);

Html::header(Grid::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'config', Config::class);
TemplateRenderer::getInstance()->display('@points/grids.html.twig', GridAdmin::listData());
Html::footer();
