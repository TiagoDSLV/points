<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Points\Config;
use GlpiPlugin\Points\Profile;
use GlpiPlugin\Points\Rights;

define('PLUGIN_POINTS_VERSION', '0.1.0');
// Bornes : `max` est exclusive (src/Plugin.php:2357 en 12.0.1 : version_compare(..., '<'))
define('PLUGIN_POINTS_MIN_GLPI', '12.0.0');
define('PLUGIN_POINTS_MAX_GLPI', '12.0.99');
define('PLUGIN_POINTS_MIN_PHP', '8.3');

/**
 * Initialisation : classes, onglet des droits sur la fiche Profile, entrée de menu de configuration.
 * Pas de csrf_compliant : supprimé en GLPI 12 (protection par Sec-Fetch-Site et Origin).
 */
function plugin_init_points(): void
{
    global $PLUGIN_HOOKS;

    // Onglet « Points » sur la fiche Profile (src/Plugin.php:1679 registerClass, 'addtabon')
    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);
    // Onglet « Points » sur la fiche Contract (le contrat du cœur est \Contract ; celui du plugin porte le même nom court)
    Plugin::registerClass(\GlpiPlugin\Points\Contract::class, ['addtabon' => \Contract::class]);

    if (!Plugin::isPluginActive('points')) {
        return;
    }

    // Lien de configuration dans la liste des plugins
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['points'] = 'front/config.form.php';

    // Configuration > Points, seulement avec le droit de lecture du paramétrage
    if (Session::haveRight(Rights::CONFIG, READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['points'] = ['config' => Config::class];
    }
}

/**
 * @return array<string, mixed>
 */
function plugin_version_points(): array
{
    return [
        'name'         => 'Points',
        'version'      => PLUGIN_POINTS_VERSION,
        'author'       => 'Athéo',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/TiagoDSLV/Points',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_POINTS_MIN_GLPI,
                'max' => PLUGIN_POINTS_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_POINTS_MIN_PHP,
            ],
        ],
    ];
}
