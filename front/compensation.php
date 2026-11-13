<?php

declare(strict_types=1);

/**
 * Outils > Points > Compensations en attente : recherche native (filtres entité, contrat, ticket, statut ; tri ; export).
 * Droit plugin_points_compensation READ ; les lignes sont limitées aux entités actives de l'utilisateur par le moteur de recherche.
 */

use GlpiPlugin\Points\Compensation;
use GlpiPlugin\Points\Controls;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::COMPENSATION, READ);

Html::header(Compensation::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'tools', Controls::class, 'compensation');
Search::show(Compensation::class);
Html::footer();
