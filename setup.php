<?php

declare(strict_types=1);

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Points\Config;
use GlpiPlugin\Points\Controls;
use GlpiPlugin\Points\ItilHooks;
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
    // Onglet « Points » sur la fiche Contract (le contrat du cœur est \Contract ; celui du plugin porte le même nom court) et sur la fiche Entity
    // et notification du seuil d'alerte (NotificationTargetContract)
    Plugin::registerClass(\GlpiPlugin\Points\Contract::class, ['addtabon' => [\Contract::class, \Entity::class], 'notificationtemplates_types' => true]);

    // Notifications natives des forfaits (NotificationTargetFlatrate) : la classe du forfait est déclarée au cœur
    Plugin::registerClass(\GlpiPlugin\Points\Flatrate::class, ['notificationtemplates_types' => true]);
    // Notification de la décision d'une compensation (NotificationTargetCompensation)
    Plugin::registerClass(\GlpiPlugin\Points\Compensation::class, ['notificationtemplates_types' => true]);

    // Onglet « Points » sur la fiche Ticket (réglage du ticket et tâches décomptées)
    Plugin::registerClass(\GlpiPlugin\Points\Ticket::class, ['addtabon' => \Ticket::class]);

    if (!Plugin::isPluginActive('points')) {
        return;
    }

    // Décompte des tâches (lot c) : refus et débit autour de TicketTask et du passage du ticket à Clos.
    // Un seul rappel par itemtype et par crochet (src/Plugin.php:1802 en 12.0.1 : $tab[$itemtype]).
    $PLUGIN_HOOKS[Hooks::POST_PREPAREADD]['points']    = ['TicketTask' => [ItilHooks::class, 'taskPostPrepareAdd']];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['points']           = ['TicketTask' => [ItilHooks::class, 'taskItemAdd']];
    $PLUGIN_HOOKS[Hooks::POST_PREPAREUPDATE]['points'] = [
        'TicketTask'       => [ItilHooks::class, 'taskPostPrepareUpdate'],
        'TicketValidation' => [ItilHooks::class, 'validationPostPrepareUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['points']    = [
        'Ticket'   => [ItilHooks::class, 'ticketPreUpdate'],
        // Contrat natif : l'entité d'un contrat à points ne change pas (le transfert natif passe par update(), donc par ce hook)
        'Contract' => [\GlpiPlugin\Points\Contract::class, 'contractPreUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['points']        = [
        'TicketTask' => [ItilHooks::class, 'taskItemUpdate'],
        'Ticket'     => [ItilHooks::class, 'ticketItemUpdate'],
        'TicketValidation' => [ItilHooks::class, 'validationItemUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['points']     = [
        'TicketTask' => [ItilHooks::class, 'taskPrePurge'],
        'Ticket'     => [ItilHooks::class, 'ticketPrePurge'],
        'TicketValidation' => [ItilHooks::class, 'validationPrePurge'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['points']         = ['Ticket' => [ItilHooks::class, 'ticketItemPurge']];

    // À l'ouverture de session, un profil de l'interface simplifiée perd tout droit absent de Profile::$helpdesk_rights (src/Profile.php:674-685,
    // cleanProfile, appelée par Session::changeProfile, src/Session.php:636-641 en 12.0.1) : sans cette ligne, plugin_points_balance reste à 0 pour le
    // Responsable client en interface simplifiée, même enregistré à 1 en base (constaté au banc, lot f).
    if (!in_array(Rights::BALANCE, \Profile::$helpdesk_rights, true)) {
        \Profile::$helpdesk_rights[] = Rights::BALANCE;
    }

    // Interface simplifiée (Responsable client) : entrée « Points » du menu (src/Html.php:1129-1163 en 12.0.1), avec le droit de lecture des soldes.
    // Le script front d'un plugin est en STRATEGY_AUTHENTICATED par défaut (src/Glpi/Http/Firewall.php:91,226,261) : la page est réservée au profil
    // de l'interface simplifiée (STRATEGY_HELPDESK_ACCESS) ; les profils standard ont l'onglet du contrat. Le droit est contrôlé par la page.
    Firewall::addPluginStrategyForLegacyScripts('points', '#^/front/points\.php(/.*)?$#', Firewall::STRATEGY_HELPDESK_ACCESS);
    if (Session::haveRight(Rights::BALANCE, READ)) {
        $PLUGIN_HOOKS[Hooks::HELPDESK_MENU_ENTRY]['points']      = '/front/points.php';
        $PLUGIN_HOOKS[Hooks::HELPDESK_MENU_ENTRY_ICON]['points'] = 'ti ti-coins';
    }

    // Options de recherche ajoutées au Contract et à l'Entity : en cache statique pour le processus, vidées quand le profil change (SearchOptions)
    $PLUGIN_HOOKS[Hooks::CHANGE_PROFILE]['points'] = [\GlpiPlugin\Points\SearchOptions::class, 'resetCache'];

    // Lien de configuration dans la liste des plugins
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['points'] = 'front/config.form.php';

    // Configuration > Points, avec le droit de lecture du paramétrage ou des grilles ; Outils > Points (reviews à
    // contrôler, compensations en attente) et portefeuille de contrats, avec l'un des trois droits de lecture. Chaque page contrôle son droit.
    if (Session::haveRight(Rights::CONFIG, READ) || Session::haveRight(Rights::GRID, READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['points']['config'] = Config::class;
    }
    if (Session::haveRight(Rights::BALANCE, READ) || Session::haveRight(Rights::REVIEW, READ) || Session::haveRight(Rights::COMPENSATION, READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['points']['tools'] = Controls::class;
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
