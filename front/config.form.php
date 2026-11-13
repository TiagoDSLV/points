<?php

declare(strict_types=1);

/**
 * Page de configuration du plugin Points (Configuration > Points).
 * GLPI 12 : pas d'inclusion de inc/includes.php (obsolète) ni de jeton CSRF (contrôle Sec-Fetch-Site / Origin du noyau).
 * Lecture : droit plugin_points_config READ ; enregistrement : UPDATE (Session::checkRight lève AccessDeniedHttpException).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Points\Config;
use GlpiPlugin\Points\ConfigValues;
use GlpiPlugin\Points\Rights;

global $CFG_GLPI;

Session::checkRight(Rights::CONFIG, READ);

if (isset($_POST['update'])) {
    Session::checkRight(Rights::CONFIG, UPDATE);
    $errors = Config::save(Config::inputFromForm($_POST));
    if ($errors === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'points'));
    }
    foreach ($errors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
}

Html::header(Config::getTypeName(), $_SERVER['PHP_SELF'], 'config', Config::class);

TemplateRenderer::getInstance()->display('@points/config.html.twig', [
    'config'             => Config::get(),
    'can_edit'           => Session::haveRight(Rights::CONFIG, UPDATE),
    'form_url'           => Config::getFormURL(),
    'debit_moments'      => [
        ConfigValues::DEBIT_CLOSURE => __('When the ticket is closed', 'points'),
        ConfigValues::DEBIT_TASK    => __('When the task is added', 'points'),
    ],
    'overdraft_choices'  => [
        1 => __('Allowed and reported', 'points'),
        0 => __('Refused', 'points'),
    ],
    'separation_choices' => [
        1 => __('Refused (separation of duties)', 'points'),
        0 => __('Allowed', 'points'),
    ],
    'on_expired_choices' => [
        ConfigValues::ON_EXPIRED_VALID_LOT => __('On the most recent valid envelope (refused if none)', 'points'),
        ConfigValues::ON_EXPIRED_ORIGIN    => __('On the original envelope (the points expire at once)', 'points'),
    ],
    // Le type par défaut doit exister : « ----- » (0) n'est proposé que tant que le réglage vaut 0
    'intervention_types' => array_filter(Config::interventionTypes(), static fn (int $id): bool => $id > 0 || (int) Config::value('default_interventiontypes_id') === 0, ARRAY_FILTER_USE_KEY),
    'max_retention'      => ConfigValues::MAX_RETENTION_MONTHS,
    'grids_url'          => Session::haveRight(Rights::GRID, READ) ? $CFG_GLPI['root_doc'] . '/plugins/points/front/grid.php' : '',
]);

Html::footer();
