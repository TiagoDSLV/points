<?php

declare(strict_types=1);

/**
 * Page « Points » de l'interface simplifiée (entrée de menu Hooks::HELPDESK_MENU_ENTRY) : soldes, enveloppes et registre des contrats à points
 * des entités actives, en lecture seule. Droit plugin_points_balance READ.
 *
 * Pare-feu : un script `front/` de plugin est en STRATEGY_AUTHENTICATED par défaut (src/Glpi/Http/Firewall.php:91,226,261 en 12.0.1) ; setup.php
 * déclare ici STRATEGY_HELPDESK_ACCESS (profil de l'interface simplifiée seulement). Le droit est contrôlé par la page elle-même.
 */

use GlpiPlugin\Points\Rights;
use GlpiPlugin\Points\Simplified;

Session::checkRight(Rights::BALANCE, READ);

Html::helpHeader(__('Points', 'points'), 'plugins', 'points');
Simplified::show();
Html::helpFooter();
