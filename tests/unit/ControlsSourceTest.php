<?php

declare(strict_types=1);

/**
 * Garde-fous de lecture du code des reviews et des compensations (le comportement réel est au banc, scenario_controles.py).
 */
$src = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../../' . $file);

test('Review et Compensation : le journal n\'est écrit que par Ledger, jamais depuis une transaction englobante', function () use ($src): void {
    foreach (['src/Review.php' => 'Review', 'src/Compensation.php' => 'Compensation', 'src/StateRecord.php' => 'StateRecord'] as $file => $class) {
        $code = $src($file);
        assertSame(0, preg_match('/beginTransaction|commit\(|rollBack/i', $code), "$class : aucune transaction ouverte");
        assertSame(0, preg_match('/->(insert|update|delete)\(\s*[\'"]glpi_plugin_points_(operations|lots|allocations|envelopes|contracts)/', $code), "$class : jamais d'écriture directe du journal");
        preg_match_all('/\$DB->(insert|update|delete)\(\s*([^,]+),/', $code, $writes, PREG_SET_ORDER);
        foreach ($writes as $write) {
            assertTrue(in_array(trim($write[2]), ['self::getTable()', 'static::getTable()', "'glpi_logs'"], true), "$class écrit " . $write[2] . ' : son état seulement');
        }
    }
    assertTrue(preg_match('/function move\(.*?\'status\' => \$from\]/s', $src('src/StateRecord.php')) === 1, 'transition atomique : mise à jour conditionnelle sur l\'ancien état');
});

test('Review : créée dans la transaction du débit, ajustement par Ledger avec la décision dans la même transaction', function () use ($src): void {
    $billing = $src('src/Billing.php');
    assertTrue(preg_match('/\'inside\'\s*=>\s*static function.*?Review::openFor\(/s', $billing) === 1, 'Review::openFor appelée par l\'option inside de Ledger::debit');
    $review = $src('src/Review.php');
    assertTrue(str_contains($review, 'Ledger::refund(') && str_contains($review, 'Ledger::debit(') && str_contains($review, 'OpType::REVIEW_ADJUSTMENT'), 'ajustement par Ledger');
    assertTrue(preg_match("/'allow_overdraft'\s*=>\s*false/", $review) === 1, 'ajustement négatif : jamais de dépassement');
    assertTrue(preg_match('/function prepareInputForAdd.*?return false;/s', $review) === 1 && preg_match('/function prepareInputForUpdate.*?return false;/s', $review) === 1, 'aucune écriture générique');
    $ledger = $src('src/Ledger.php');
    assertTrue(preg_match('/function refund\(.*?\$opts\[\'inside\'\]\(\$operationId\)/s', $ledger) === 1, 'Ledger::refund exécute inside dans sa transaction');
    assertTrue(str_contains($ledger, 'OpType::REVIEW_ADJUSTMENT]'), 'les ajustements positifs comptent dans les remboursements déjà faits');
});

test('Compensation : crédit par Ledger::refund, droits CREATE (demander) et VALIDATE (décider), séparation', function () use ($src): void {
    $code = $src('src/Compensation.php');
    assertTrue(str_contains($code, 'Ledger::refund(') && str_contains($code, 'OpType::COMPENSATION') && str_contains($code, "'inside'"), 'crédit lié dans la transaction du journal');
    assertTrue(str_contains($code, 'Rights::COMPENSATION, CREATE') && str_contains($code, 'Rights::COMPENSATION, Rights::VALIDATE'), 'droits demander et décider');
    assertTrue(str_contains($code, 'canReadTicket') && str_contains($code, 'haveAccessToEntity'), 'ticket lisible et entité');
    assertTrue(str_contains($code, 'CompensationRules::assertSeparation') && str_contains($code, 'compensation_separation'), 'séparation des tâches réglable');
    foreach (['canView', 'canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
        assertTrue(str_contains($code, "function $method(): bool"), "$method redéfini");
    }
    foreach (['canUpdate', 'canDelete', 'canPurge'] as $method) {
        assertTrue(preg_match('/function ' . $method . '\(\): bool\s*\{\s*return false;/', $code) === 1, "$method false");
    }
    assertTrue(str_contains($code, 'function pre_deleteItem') && preg_match('/function prepareInputForUpdate.*?return false;/s', $code) === 1, 'ni modification ni suppression');
});

test('Review : can* redéfinis, droit plugin_points_review, jamais de création ni de suppression génériques', function () use ($src): void {
    $code = $src('src/Review.php');
    assertTrue(str_contains($code, 'Rights::REVIEW'), 'droit plugin_points_review');
    foreach (['canCreate', 'canDelete', 'canPurge'] as $method) {
        assertTrue(preg_match('/function ' . $method . '\(\): bool\s*\{\s*return false;/', $code) === 1, "$method false");
    }
    assertTrue(str_contains($code, 'function pre_deleteItem') && str_contains($code, 'haveAccessToEntity'), 'pre_deleteItem refuse, entité contrôlée');
});

test('Écrans des contrôles : hooks, notification, formulaires POST gardés, ni exit ni die', function () use ($src): void {
    $setup = $src('setup.php');
    assertTrue(str_contains($setup, 'Compensation::class, [\'notificationtemplates_types\' => true]') && str_contains($setup, 'Controls::class'), 'notification et menu Outils enregistrés');
    assertTrue(str_contains($src('src/NotificationTargetCompensation.php'), 'compensation_decided') && str_contains($src('src/Install.php'), 'compensation_decided'), 'événement semé et déclaré');
    assertTrue(str_contains($src('src/Install.php'), 'seedNotificationSet(Compensation::class'), 'modèle des compensations semé (rejouable)');
    foreach (['front/review.form.php' => 'Rights::REVIEW, UPDATE', 'front/compensation.form.php' => 'Rights::COMPENSATION, READ', 'front/review.php' => 'Rights::REVIEW, READ', 'front/compensation.php' => 'Rights::COMPENSATION, READ'] as $file => $guard) {
        $code = $src($file);
        assertTrue(str_contains($code, "Session::checkRight($guard)"), "$file : garde $guard");
        assertTrue(preg_match('/\b(exit|die)\s*[;(]/', $code) === 0, "$file : ni exit ni die");
    }
    assertTrue(str_contains($src('src/Review.php'), 'DefaultSearchRequestInterface') && str_contains($src('src/Compensation.php'), 'DefaultSearchRequestInterface'), 'listes à contrôler / en attente par défaut');
    assertTrue(str_contains($src('templates/controls.html.twig'), 'method="post"') && !str_contains($src('templates/controls.html.twig'), '|raw'), 'formulaires POST, Twig échappé');
});

test('Reprise des débits perdus : forfait et tâche ne passent jamais à « débité » ou « verrouillé » sans opération', function () use ($src): void {
    $flatrate = $src('src/Flatrate.php');
    assertTrue(preg_match('/\$existing < 1\).*?FLATRATE_STATE/s', $flatrate) === 1, 'Flatrate::debit : aucun débit après ALREADY_DEBITED = refus d\'état');
    $billing = $src('src/Billing.php');
    assertTrue(preg_match('/\$existing < 1\).*?TASK_TO_RESUME/s', $billing) === 1, 'Billing::debitTask : aucun débit après ALREADY_DEBITED = tâche à reprendre');
    assertTrue(str_contains($src('src/ItilHooks.php'), "\$result['retry']"), 'la clôture est refusée quand une tâche est à reprendre');
});

test('Compensation : le reste compensable vient de Ledger::refundable, la review porte l\'entité du contrat', function () use ($src): void {
    $code = $src('src/Compensation.php');
    assertSame(3, substr_count($code, 'Ledger::refundable('), 'demande, décision et affichage');
    assertTrue(str_contains($src('src/Ledger.php'), 'function refundable('), 'Ledger::refundable existe');
    assertTrue(preg_match('/openFor.*?FROM\' => \'glpi_plugin_points_operations\'.*?\'entities_id\'\s*=>\s*\(int\) \(\$operation/s', $src('src/Review.php')) === 1, 'entité de la review = celle de l\'opération de débit');
    assertTrue(preg_match('/NOTHING_TO_REFUND\) \{\s*\/\/.*?self::row\(\$id\)/s', $code) === 1, 'état relu avant de traduire un dépassement');
});
