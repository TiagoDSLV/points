<?php

declare(strict_types=1);

/**
 * Garde-fous de lecture du code des forfaits (le comportement réel est au banc, scenario_forfaits.py).
 */
test('Flatrate : le journal n\'est écrit que par Ledger::debit, jamais depuis une transaction englobante', function (): void {
    $code = file_get_contents(__DIR__ . '/../../src/Flatrate.php');
    assertSame(0, preg_match('/beginTransaction|commit\(|rollBack/i', $code), 'aucune transaction ouverte par Flatrate');
    assertSame(0, preg_match('/glpi_plugin_points_(operations|lots|allocations)[\'"]\s*[,)]\s*\[?[^;]*->(insert|update|delete)|->(insert|update|delete)\(\s*[\'"]glpi_plugin_points_(operations|lots|allocations)/', $code), 'jamais d\'écriture directe du journal');
    preg_match_all('/\$DB->(insert|update|delete)\(\s*([^,]+),/', $code, $writes, PREG_SET_ORDER);
    foreach ($writes as $write) {
        assertTrue(in_array(trim($write[2]), ['self::getTable()', "'glpi_logs'"], true), 'Flatrate écrit ' . $write[2] . ' : état du forfait et historique seulement');
    }
    assertTrue(str_contains($code, 'Ledger::debit(') && str_contains($code, "'unique'     => true") && str_contains($code, 'OpType::DEBIT_FLATRATE'), 'débit définitif : Ledger::debit avec clé d\'unicité');
    assertTrue(preg_match('/function move\(.*?\'status\' => \$from\]/s', $code) === 1, 'transition atomique : mise à jour conditionnelle sur l\'ancien état');
});

test('Flatrate : can* redéfinis, droit plugin_points_flatrate, jamais de suppression', function (): void {
    $code = file_get_contents(__DIR__ . '/../../src/Flatrate.php');
    assertTrue(str_contains($code, 'Rights::FLATRATE') && str_contains($code, 'Rights::VALIDATE'), 'droit plugin_points_flatrate et bit VALIDATE');
    foreach (['canView', 'canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
        assertTrue(str_contains($code, "function $method(): bool"), "$method redéfini");
    }
    assertTrue(preg_match('/function canDelete\(\): bool\s*\{\s*return false;/', $code) === 1, 'canDelete false');
    assertTrue(preg_match('/function canPurge\(\): bool\s*\{\s*return false;/', $code) === 1, 'canPurge false');
    assertTrue(str_contains($code, 'function pre_deleteItem'), 'pre_deleteItem refuse');
    assertTrue(str_contains($code, 'FlatrateRules::assertSeparation'), 'séparation des tâches');
    assertTrue(str_contains($code, "can(READ)") || str_contains($code, 'canReadTicket'), 'lecture du ticket exigée');
});

test('Forfaits : hooks de validation, de clôture et de purge, notifications et formulaire', function (): void {
    $setup = file_get_contents(__DIR__ . '/../../setup.php');
    foreach (['validationPostPrepareUpdate', 'validationItemUpdate', 'validationPrePurge'] as $method) {
        assertTrue(str_contains($setup, "'$method'"), "$method enregistré");
        assertTrue(method_exists(\GlpiPlugin\Points\ItilHooks::class, $method), "ItilHooks::$method existe");
    }
    assertTrue(str_contains($setup, "'TicketValidation'"), 'hooks sur TicketValidation');
    $billing = file_get_contents(__DIR__ . '/../../src/Billing.php');
    assertTrue(str_contains($billing, 'Flatrate::countsForTicket') && str_contains($billing, 'Flatrate::closureProblem') && str_contains($billing, 'Flatrate::debitOnClosure'), 'Billing délègue au forfait');
    $target = file_get_contents(__DIR__ . '/../../src/NotificationTargetFlatrate.php');
    assertTrue(str_contains($target, 'flatrate_submitted') && str_contains($target, 'flatrate_decided'), 'événements des notifications');
    $install = file_get_contents(__DIR__ . '/../../src/Install.php');
    assertTrue(str_contains($install, 'seedNotifications();') && str_contains($install, 'flatrate_submitted') && str_contains($install, 'flatrate_decided'), 'notifications créées à l\'installation');
    $front = file_get_contents(__DIR__ . '/../../front/flatrate.form.php');
    assertTrue(str_contains($front, 'Session::checkRight(Rights::FLATRATE, READ)') && str_contains($front, '$ticket->can($ticketsId, READ)') && str_contains($front, 'haveAccessToEntity'), 'formulaire : droit, ticket, entité');
    assertTrue(preg_match('/\b(exit|die)\s*[;(]/', $front) === 0, 'ni exit ni die');
});
