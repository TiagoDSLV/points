<?php

declare(strict_types=1);

/**
 * Outils > Points > Contrats à points : portefeuille par la recherche native (client, BU, solde, crédité, consommé, % consommé, prochaine
 * échéance, grille ; filtres, tri, export). Droit plugin_points_balance READ ; les lignes sont limitées aux entités actives de
 * l'utilisateur par le moteur de recherche (lignes Points non récursives : entité propre du contrat).
 */

use GlpiPlugin\Points\Contract;
use GlpiPlugin\Points\Controls;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::BALANCE, READ);

Html::header(Contract::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'tools', Controls::class, 'contract');
Search::show(Contract::class);
Html::footer();
