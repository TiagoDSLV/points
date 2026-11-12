<?php

declare(strict_types=1);

/**
 * Le journal est en ajout seul : garde-fous de lecture du code (le comportement réel est au banc, scenario_journal.py).
 */
test('Ledger : aucune suppression, update seulement sur la ligne du contrat, insert seulement dans le journal', function (): void {
    $code = file_get_contents(__DIR__ . '/../../src/Ledger.php');
    assertSame(0, preg_match('/->delete\(|->deleteByCriteria\(|DELETE\s+FROM|TRUNCATE/i', $code), 'aucune suppression');
    preg_match_all('/->update\(\s*([^,]+),/', $code, $updates);
    assertTrue($updates[1] !== [], 'le verrou et le cache passent par update');
    foreach ($updates[1] as $table) {
        assertSame('self::CONTRACTS', trim($table), 'update réservé à la ligne du contrat (verrou et cache)');
    }
    // Colonnes écrites par Ledger sur la ligne du contrat : verrou et cache seulement, jamais le réglage ni le lien
    preg_match_all('/->update\(\s*self::CONTRACTS,\s*\[(.*?)\]\s*,/s', $code, $sets);
    assertTrue($sets[1] !== [], 'mises à jour de la ligne du contrat trouvées');
    foreach ($sets[1] as $set) {
        preg_match_all("/'(\w+)'\s*=>/", $set, $columns);
        foreach ($columns[1] as $column) {
            assertTrue(in_array($column, ['date_mod', 'balance', 'credited', 'consumed', 'date_cache'], true), "Ledger écrit la colonne $column du contrat");
        }
    }
    preg_match_all('/->insert\(\s*([^,]+),/', $code, $inserts);
    foreach ($inserts[1] as $table) {
        assertTrue(in_array(trim($table), ['self::ENVELOPES', 'self::OPERATIONS', 'self::LOTS', 'self::ALLOCATIONS', 'self::CONTRACTS'], true), "insert sur $table");
    }
});

test('Classes du journal : lecture seule, aucun chemin de création par le modèle générique', function (): void {
    foreach (['ReadOnlyRecord', 'Envelope', 'Contract'] as $class) {
        $code = file_get_contents(__DIR__ . "/../../src/$class.php");
        assertTrue(str_contains($code, 'pre_deleteItem'), "$class : pre_deleteItem refuse");
        assertTrue(str_contains($code, 'prepareInputForAdd'), "$class : prepareInputForAdd refuse");
        assertTrue(str_contains($code, 'canPurge(): bool'), "$class : canPurge redéfini");
    }
    $readOnly = file_get_contents(__DIR__ . '/../../src/ReadOnlyRecord.php');
    foreach (['canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
        assertTrue(preg_match('/function ' . $method . '\(\): bool\s*\{\s*return false;/', $readOnly) === 1, "ReadOnlyRecord::$method renvoie false");
    }
});
