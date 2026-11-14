<?php

declare(strict_types=1);

/**
 * Garde-fous de lecture du code du décompte des tâches (le comportement réel est au banc, scenario_taches.py).
 */
test('Billing : le journal n\'est écrit que par Ledger::debit, jamais depuis une transaction englobante', function (): void {
    $code = file_get_contents(__DIR__ . '/../../src/Billing.php');
    assertSame(0, preg_match('/beginTransaction|commit\(|rollBack/i', $code), 'aucune transaction ouverte par Billing');
    assertSame(0, preg_match('/DELETE\s+FROM|TRUNCATE/i', $code), 'aucun SQL brut de suppression');
    preg_match_all('/->delete\(\s*([^,]+),/', $code, $deletes);
    foreach ($deletes[1] as $table) {
        assertTrue(in_array(trim($table), ['Ticket::getTable()', 'self::TASKS'], true), "suppression sur $table : état de décompte seulement, jamais le journal");
    }
    preg_match_all('/->(insert|update)\(\s*([^,]+),/', $code, $writes, PREG_SET_ORDER);
    assertTrue($writes !== [], 'la ligne d\'état de la tâche est écrite');
    foreach ($writes as $write) {
        assertSame('self::TASKS', trim($write[2]), 'Billing écrit seulement glpi_plugin_points_tasks (état), jamais le journal');
    }
    assertTrue(str_contains($code, 'Ledger::debit('), 'le débit passe par Ledger::debit');
    assertTrue(str_contains($code, 'function isFlatrateTicket'), 'emplacement de l\'exclusion des forfaits (lot d)');
});

test('Hooks : les huit crochets du décompte sont enregistrés par setup.php', function (): void {
    $code = file_get_contents(__DIR__ . '/../../setup.php');
    foreach ([
        'POST_PREPAREADD' => 'taskPostPrepareAdd', 'ITEM_ADD' => 'taskItemAdd', 'POST_PREPAREUPDATE' => 'taskPostPrepareUpdate',
        'PRE_ITEM_PURGE' => 'taskPrePurge', 'PRE_ITEM_UPDATE' => 'ticketPreUpdate', 'ticketItemUpdate' => 'ticketItemUpdate', 'taskItemUpdate' => 'taskItemUpdate',
        'ticketPrePurge' => 'ticketPrePurge', 'PRE_ITEM_DELETE' => 'ticketPreDelete', 'ITEM_PURGE' => 'ticketItemPurge', 'ticketItemAdd' => 'ticketItemAdd',
    ] as $hook => $method) {
        assertTrue(str_contains($code, $hook) && str_contains($code, "'$method'"), "$hook / $method");
        assertTrue(method_exists(\GlpiPlugin\Points\ItilHooks::class, $method), "ItilHooks::$method existe");
    }
});

test('Grilles : can* redéfinis, droit plugin_points_grid, suppression refusée si utilisé', function (): void {
    foreach (['Grid', 'GridVersion', 'GridCost', 'InterventionType'] as $class) {
        $code = file_get_contents(__DIR__ . "/../../src/$class.php");
        assertTrue(str_contains($code, 'Rights::GRID'), "$class : droit plugin_points_grid");
        foreach (['canView', 'canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
            assertTrue(str_contains($code, "function $method(): bool"), "$class::$method redéfini");
        }
        assertTrue(str_contains($code, 'pre_deleteItem'), "$class : pre_deleteItem contrôle l'usage");
    }
    foreach (['GridVersion', 'GridCost'] as $class) {
        assertTrue(str_contains(file_get_contents(__DIR__ . "/../../src/$class.php"), 'isUsed('), "$class : version utilisée = verrouillée");
    }
});

test('Réglage ticket : droit plugin_points_ticket, jamais de suppression', function (): void {
    $code = file_get_contents(__DIR__ . '/../../src/Ticket.php');
    assertTrue(str_contains($code, 'Rights::TICKET'), 'droit plugin_points_ticket');
    assertTrue(preg_match('/function canDelete\(\): bool\s*\{\s*return false;/', $code) === 1, 'canDelete false');
    assertTrue(preg_match('/function canPurge\(\): bool\s*\{\s*return false;/', $code) === 1, 'canPurge false');
});

test('Ledger : l\'unicité d\'un débit est revérifiée dans writeDebit (donc sous le verrou), et Billing la demande', function (): void {
    $ledger = file_get_contents(__DIR__ . '/../../src/Ledger.php');
    assertTrue(preg_match('/function writeDebit\(.*?\{\s*if \(!empty\(\$opts\[\'unique\'\]\) && self::debitOf\(/s', $ledger) === 1, 'debitOf est appelé en tête de writeDebit');
    $billing = file_get_contents(__DIR__ . '/../../src/Billing.php');
    assertTrue(str_contains($billing, "'unique'     => true") && str_contains($billing, "'inside'"), 'Billing demande l\'unicité et écrit la ligne verrouillée dans la transaction');
    assertTrue(str_contains($billing, 'ALREADY_DEBITED'), 'Billing traite le refus ALREADY_DEBITED');
});

test('Ledger : le débit est définitif, debitOf ignore les remboursements (et Billing, Flatrate l\'utilisent)', function (): void {
    $ledger = file_get_contents(__DIR__ . '/../../src/Ledger.php');
    preg_match('/public static function debitOf\(.*?\n    \}\n/s', $ledger, $m);
    assertTrue($m !== [], 'debitOf trouvée');
    foreach (['Billing', 'Flatrate'] as $class) {
        assertTrue(str_contains(file_get_contents(__DIR__ . "/../../src/$class.php"), 'Ledger::debitOf('), "$class lit le débit par Ledger::debitOf");
    }
    assertSame(1, substr_count($ledger, "'type' => \$type, 'itemtype' => \$itemtype, 'items_id' => \$itemsId"), 'une seule lecture de débit par clé dans Ledger');
    assertSame(0, preg_match('/operations_id_origin|refund|amount/i', $m[0]), 'ni remboursement ni montant : la clé seule compte');
});

test('Billing : dates de tâche converties de la session vers le fuseau de référence (date.timezone du serveur)', function (): void {
    $session = date_default_timezone_get();
    $ini     = ini_get('date.timezone');
    try {
        ini_set('date.timezone', 'Europe/Paris');
        date_default_timezone_set('America/New_York');
        // vendredi 18:30 à New York (EST, UTC-5) = samedi 00:30 à Paris (UTC+1)
        assertSame('2026-11-14 00:30:00', \GlpiPlugin\Points\Billing::toReference('2026-11-13 18:30:00'));
        assertSame('Europe/Paris', \GlpiPlugin\Points\Billing::referenceTimezone()->getName());
        date_default_timezone_set('Europe/Paris');
        assertSame('2026-11-14 00:30:00', \GlpiPlugin\Points\Billing::toReference('2026-11-14 00:30:00'), 'même fuseau : sans effet');
        assertThrows(InvalidArgumentException::class, static fn () => \GlpiPlugin\Points\Billing::toReference('pas une date'));
    } finally {
        date_default_timezone_set($session);
        ini_set('date.timezone', $ini !== '' ? (string) $ini : 'UTC');
    }
});
