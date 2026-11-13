<?php

declare(strict_types=1);

/**
 * Garde-fous de lecture du code de l'alerte et du reporting (lot g) ; le comportement réel est au banc (scenario_alerte.py).
 */
$src = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../../' . $file);

test('Alerte : action automatique pointsalert enregistrée et routée vers ThresholdAlert', function () use ($src): void {
    assertTrue(str_contains($src('src/Install.php'), 'CronTask::register(Cron::class, Cron::ALERT, DAY_TIMESTAMP'), 'enregistrée, quotidienne');
    assertTrue(preg_match('/function cronPointsalert.*?ThresholdAlert::run\(date\(/s', $src('src/Cron.php')) === 1, 'cronPointsalert appelle ThresholdAlert::run');
    assertTrue(str_contains($src('src/Cron.php'), "self::ALERT  => ['description'"), 'cronInfo');
});

test('Alerte : contrats actifs seulement, une levée par passage (alerte native), calcul sur les lots valides', function () use ($src): void {
    $code = $src('src/ThresholdAlert.php');
    assertTrue(str_contains($code, "'glpi_plugin_points_contracts.is_active' => 1") && str_contains($code, "'glpi_contracts.is_deleted' => 0") && str_contains($code, "'glpi_contracts.is_template' => 0"), 'actifs, non supprimés, hors modèles');
    assertTrue(preg_match('/\$reached && !\$exists/', $code) === 1, 'levée seulement sans alerte mémorisée');
    assertTrue(str_contains($code, 'Ledger::validTotals('), 'calcul sur les lots valides, pas sur le cache');
    assertSame(0, preg_match('/Ledger::cached\(/', $code), 'le cache du contrat n\'est jamais lu pour le seuil');
    assertTrue(preg_match('/raiseEvent\(self::EVENT.*?\$DB->insert\(self::ALERTS/s', $code) === 1, 'passage mémorisé après la levée seulement');
    assertTrue(str_contains($code, '\\Alert::THRESHOLD'), 'alerte native de type seuil');
});

test('Alerte : levée sous le verrou du contrat, totaux relus juste avant', function () use ($src): void {
    assertTrue(preg_match('/function raise.*?Ledger::locked\(.*?Ledger::validTotals\(.*?AlertRules::reached\(.*?raiseEvent\(/s', $src('src/ThresholdAlert.php')) === 1, 'relecture sous verrou avant raiseEvent');
});

test('Install : reprise des colonnes de recherche seulement à leur ajout', function () use ($src): void {
    $code = $src('src/Install.php');
    assertTrue(preg_match('/addField\(\$table, \$field, \$definition\);\s*\$backfill = \$backfill \|\| /', $code) === 1 && str_contains($code, 'if ($backfill) {'), 'drapeau posé à l\'ajout effectif');
});

test('Alerte : Ledger remet l\'alerte à zéro dans la transaction qui écrit le cache', function () use ($src): void {
    assertTrue(preg_match('/function writeCache.*?ContractCache::nextExpiry\(\$lots\).*?ContractCache::percent\(.*?ThresholdAlert::resetIfBelow\(.*?self::today\(\)/s', $src('src/Ledger.php')) === 1, 'cache, colonnes de recherche et remise à zéro ensemble');
});

test('Alerte : notification threshold, cible par défaut = administrateur de l\'entité, classe enregistrée', function () use ($src): void {
    assertTrue(str_contains($src('src/Install.php'), "ThresholdAlert::EVENT => ['Points: threshold reached', Notification::ENTITY_ADMINISTRATOR]"), 'cible par défaut');
    assertTrue(str_contains($src('setup.php'), "'notificationtemplates_types' => true"), 'classe déclarée au cœur');
    $target = $src('src/NotificationTargetContract.php');
    foreach (['contract', 'client', 'balance', 'percent', 'threshold', 'expiry'] as $tag) {
        assertTrue(str_contains($target, "'points.$tag'") && str_contains($src('src/Install.php'), "##points.$tag##"), "balise $tag");
    }
});

test('Recherche : options ajoutées au contrat et à l\'entité, sous plugin_points_balance, entité propre, aucun champ interne', function () use ($src): void {
    $code = $src('src/SearchOptions.php');
    assertTrue(preg_match('/if \(!Session::haveRight\(Rights::BALANCE, READ\)\) \{\s*return \[\];/', $code) === 1, 'aucune option sans le droit de lecture des soldes');
    assertTrue(str_contains($code, "'condition' => [new ActiveEntitiesCondition()]"), 'jointure limitée aux entités actives (entité propre du contrat), relues à chaque requête');
    assertSame(0, preg_match('/glpiactiveentities/', $code), 'aucune entité de session figée dans la définition mise en cache');
    $lazy = $src('src/ActiveEntitiesCondition.php');
    assertTrue(str_contains($lazy, 'function getValue') && str_contains($lazy, "\$_SESSION['glpiactiveentities']") && str_contains($lazy, 'Rights::BALANCE, READ'), 'condition évaluée au moment de la requête, avec le droit');
    assertTrue(str_contains($code, "'NEWTABLE.is_deleted' => 0, 'NEWTABLE.is_template' => 0"), 'entité : contrats à la corbeille et modèles exclus');
    assertTrue(str_contains($src('setup.php'), "Hooks::CHANGE_PROFILE]['points']"), 'cache des options vidé au changement de profil');
    assertSame(0, preg_match('/glpi_plugin_points_(operations|envelopes|lots|allocations)|[\'"]comment[\'"]|[\'"]details[\'"]|[\'"]users_id[\'"]/', $code), 'aucun champ interne ni table du journal');
    assertTrue(str_contains($src('hook.php'), 'function plugin_points_getAddSearchOptionsNew'), 'crochet du cœur');
});

test('Liste et onglet entité : droit de lecture des soldes, accès à l\'entité propre', function () use ($src): void {
    assertTrue(str_contains($src('front/contract.php'), 'Session::checkRight(Rights::BALANCE, READ)'), 'page de la liste');
    $code = $src('src/Contract.php');
    assertTrue(preg_match('/instanceof \\\\Entity\) \{.*?\$item->getID\(\) >= 0 && self::canView\(\) \? self::countInEntity/s', $code) === 1, 'onglet : droit de lecture des soldes');
    assertTrue(preg_match('/function displayTabContentForItem.*?!self::canView\(\) \|\| \$item->getID\(\) < 0 \|\| !\$item->can\(\$item->getID\(\), READ\)\) \{/s', $code) === 1, 'contenu : droit des soldes et lecture de l\'entité (= accès à l\'entité)');
    assertTrue(preg_match('/function countInEntity.*?glpi_contracts.is_deleted. => 0, .glpi_contracts.is_template. => 0/s', $code) === 1, 'compte d\'onglets : corbeille et modèles exclus');
    assertTrue(preg_match('/function getSystemSQLCriteria.*?is_deleted. => 1, .is_template. => 1/s', $code) === 1, 'liste : corbeille et modèles exclus');
    assertTrue(preg_match('/function post_updateItem.*?alert_threshold.*?ThresholdAlert::clear/s', $code) === 1, 'seuil modifié : alerte effacée');
    assertTrue(str_contains($code, '"$table.entities_id" => $entityId'), 'contrats de l\'entité elle-même, pas de ses sous-entités');
    assertTrue(str_contains($code, '$native->can((int) $row[\'contract\'], READ)'), 'lien vers le contrat seulement si lisible');
});

test('Colonnes de recherche : consumed_pct et next_expiry sont des colonnes réelles indexées, figées contre la modification par l\'API', function () use ($src): void {
    $schema = GlpiPlugin\Points\Install::schema()['glpi_plugin_points_contracts'];
    assertTrue(isset($schema['columns']['next_expiry'], $schema['columns']['consumed_pct']), 'colonnes dans le modèle de données');
    $indexed = array_map(static fn (array $k): string => implode(',', $k[2]), $schema['keys']);
    assertTrue(in_array('next_expiry', $indexed, true) && in_array('consumed_pct', $indexed, true) && in_array('balance', $indexed, true), 'index de filtre et de tri');
    assertTrue(str_contains($src('src/Contract.php'), "'next_expiry', 'consumed_pct', 'date_cache'"), 'figées contre update');
});
