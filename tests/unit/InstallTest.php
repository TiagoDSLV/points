<?php

declare(strict_types=1);

use GlpiPlugin\Points\Install;

test('Install : les tables sont celles du modèle de données de conception.md', function (): void {
    $doc = [];
    $inModel = false;
    foreach (file(__DIR__ . '/../../docs/conception.md', FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, '## ')) {
            $inModel = $line === '## Modèle de données';
        }
        if ($inModel && preg_match('/^\| `(glpi_plugin_points_\w+)` \|/', $line, $m) === 1) {
            $doc[] = $m[1];
        }
    }
    assertSame(14, count($doc));
    assertSame($doc, Install::tableNames());
});

test('Install : pas de date_mod sur operations, lots, allocations ; date_creation partout', function (): void {
    $schema = Install::schema();
    foreach (['operations', 'lots', 'allocations'] as $t) {
        $cols = $schema["glpi_plugin_points_$t"]['columns'];
        assertTrue(!array_key_exists('date_mod', $cols), "$t sans date_mod");
        assertTrue(array_key_exists('date_creation', $cols), "$t avec date_creation");
    }
    assertTrue(array_key_exists('date_mod', $schema['glpi_plugin_points_reviews']['columns']), 'reviews a date_mod');
    foreach ($schema as $table => $spec) {
        assertTrue(array_key_exists('entities_id', $spec['columns']), "$table porte entities_id");
        assertSame('id', array_key_first($spec['columns']), "$table : id en premier");
    }
});

test('Install : uniques demandés', function (): void {
    $schema = Install::schema();
    $uniques = [];
    foreach ($schema as $table => $spec) {
        foreach ($spec['keys'] as [$type, , $cols]) {
            if ($type === 'UNIQUE') {
                $uniques[$table] = $cols;
            }
        }
    }
    assertSame(['contracts_id'], $uniques['glpi_plugin_points_contracts']);
    assertSame(['tickets_id'], $uniques['glpi_plugin_points_tickets']);
    assertSame(['tickettasks_id'], $uniques['glpi_plugin_points_tasks']);
    assertSame(['plugin_points_gridversions_id', 'plugin_points_interventiontypes_id', 'daytype'], $uniques['glpi_plugin_points_gridcosts']);
    assertSame(4, count($uniques));
});

test('Install : clés étrangères signées selon l\'option de GLPI', function (): void {
    assertSame('int unsigned NOT NULL DEFAULT 0', Install::schema('unsigned')['glpi_plugin_points_envelopes']['columns']['contracts_id']);
    assertSame('int NOT NULL DEFAULT 0', Install::schema('')['glpi_plugin_points_envelopes']['columns']['contracts_id']);
});

test('Install : CREATE TABLE InnoDB avec charset et collation fournis', function (): void {
    $schema = Install::schema();
    $sql = Install::createTableSql('glpi_plugin_points_tickets', $schema['glpi_plugin_points_tickets'], 'utf8mb4', 'utf8mb4_unicode_ci');
    assertTrue(str_contains($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    assertTrue(str_contains($sql, 'UNIQUE KEY `tickets_id` (`tickets_id`)'));
    assertTrue(str_contains($sql, 'PRIMARY KEY (`id`)'));
});

test('Install : l\'installation n\'écrit du SQL brut que dans le DDL', function (): void {
    foreach (glob(__DIR__ . '/../../src/*.php') ?: [] as $file) {
        $code = file_get_contents($file);
        assertSame(0, preg_match('/->query\(|->doQuery\(\s*[\'"]\s*(SELECT|INSERT|UPDATE|DELETE)/i', $code), basename($file) . ' : SQL brut hors DDL');
    }
});
