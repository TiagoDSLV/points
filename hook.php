<?php

declare(strict_types=1);

use GlpiPlugin\Points\Install;

/**
 * Installation (rejouable : sert aussi de mise à jour). Les classes du plugin sont chargées par l'autoload PSR-4
 * enregistré par GLPI avant l'appel (src/Plugin.php:401-405 en 12.0.1).
 */
function plugin_points_install(): bool
{
    return Install::install(new Migration(PLUGIN_POINTS_VERSION));
}

function plugin_points_uninstall(): bool
{
    return Install::uninstall();
}

/**
 * Options de recherche ajoutées au contrat natif et à l'entité (portefeuille de points) ; voir GlpiPlugin\Points\SearchOptions.
 *
 * @return array<int, array<string, mixed>>
 */
function plugin_points_getAddSearchOptionsNew(string $itemtype): array
{
    return \GlpiPlugin\Points\SearchOptions::forItemtype($itemtype);
}
