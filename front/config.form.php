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
    'intervention_types' => Config::interventionTypes(),
    'max_retention'      => ConfigValues::MAX_RETENTION_MONTHS,
]);

Html::footer();
