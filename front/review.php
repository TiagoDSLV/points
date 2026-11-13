<?php

declare(strict_types=1);

/**
 * Outils > Points > Reviews à contrôler : recherche native (filtres entité, contrat, ticket, statut ; tri ; export).
 * Droit plugin_points_review READ ; les lignes sont limitées aux entités actives de l'utilisateur par le moteur de recherche.
 */

use GlpiPlugin\Points\Controls;
use GlpiPlugin\Points\Review;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::REVIEW, READ);

Html::header(Review::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'tools', Controls::class, 'review');
Search::show(Review::class);
Html::footer();
