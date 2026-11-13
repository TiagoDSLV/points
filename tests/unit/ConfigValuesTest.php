<?php

declare(strict_types=1);

use GlpiPlugin\Points\ConfigValues;

test('ConfigValues : défauts de conception.md', function (): void {
    $d = ConfigValues::defaults();
    assertSame([], $d['contracttypes']);
    assertSame('closure', $d['debit_moment']);
    assertSame(80, $d['default_alert_threshold']);
    assertSame(1, $d['allow_overdraft']);
    assertSame(0, $d['calendars_id']);
    assertSame(24, $d['retention_months']);
    assertSame(1, $d['flatrate_separation']);
    assertSame(8, count($d), 'huit clés comme dans conception.md');
});

test('ConfigValues : les clés sont celles du tableau « Paramétrage » de conception.md', function (): void {
    $keys = [];
    foreach (file(__DIR__ . '/../../docs/conception.md', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\| `(contracttypes|debit_moment|default_alert_threshold|allow_overdraft|calendars_id|default_interventiontypes_id|retention_months|flatrate_separation)` \|/', $line, $m) === 1) {
            $keys[] = $m[1];
        }
    }
    assertSame(array_keys(ConfigValues::defaults()), $keys);
});

test('ConfigValues : retention_months jamais supérieur à 24', function (): void {
    assertSame(24, ConfigValues::normalize(['retention_months' => '36'])['retention_months']);
    assertSame(24, ConfigValues::normalize(['retention_months' => 25])['retention_months']);
    assertSame(24, ConfigValues::normalize(['retention_months' => PHP_INT_MAX])['retention_months']);
    assertSame(24, ConfigValues::normalize(['retention_months' => '24'])['retention_months']);
    assertSame(12, ConfigValues::normalize(['retention_months' => '12'])['retention_months'], 'témoin positif');
    assertSame(1, ConfigValues::normalize(['retention_months' => '0'])['retention_months']);
    assertSame(1, ConfigValues::normalize(['retention_months' => -5])['retention_months']);
    assertSame(24, ConfigValues::normalize(['retention_months' => 'abc'])['retention_months'], 'invalide : défaut');
    assertSame(24, ConfigValues::clampRetention(1000));
});

test('ConfigValues : valeurs invalides reprennent le défaut, valides conservées', function (): void {
    $n = ConfigValues::normalize([
        'debit_moment'            => 'task',
        'default_alert_threshold' => '90',
        'allow_overdraft'         => '0',
        'calendars_id'            => '3',
        'default_interventiontypes_id' => '2',
    ]);
    assertSame('task', $n['debit_moment']);
    assertSame(90, $n['default_alert_threshold']);
    assertSame(0, $n['allow_overdraft']);
    assertSame(3, $n['calendars_id']);
    assertSame(2, $n['default_interventiontypes_id']);

    $bad = ConfigValues::normalize(['debit_moment' => 'never', 'default_alert_threshold' => '150', 'calendars_id' => '-2', 'allow_overdraft' => 'x']);
    assertSame('closure', $bad['debit_moment']);
    assertSame(80, $bad['default_alert_threshold']);
    assertSame(0, $bad['calendars_id']);
    assertSame(1, $bad['allow_overdraft']);
});

test('ConfigValues : liste de types de contrat (JSON ou tableau)', function (): void {
    assertSame([1, 5], ConfigValues::normalize(['contracttypes' => '[1,"5",0,"x",1]'])['contracttypes']);
    assertSame([2, 3], ConfigValues::normalize(['contracttypes' => ['2', 3]])['contracttypes']);
    assertSame([], ConfigValues::normalize(['contracttypes' => 'pas du json'])['contracttypes']);
    assertSame([], ConfigValues::normalize([])['contracttypes']);
});

test('ConfigValues : stockage en chaînes, aller-retour stable', function (): void {
    $stored = ConfigValues::toStorage(['contracttypes' => [4, 9], 'retention_months' => 99]);
    assertSame('[4,9]', $stored['contracttypes']);
    assertSame('24', $stored['retention_months']);
    assertSame('closure', $stored['debit_moment']);
    assertSame(ConfigValues::normalize($stored), ConfigValues::normalize(ConfigValues::normalize($stored)));
    assertSame([4, 9], ConfigValues::normalize($stored)['contracttypes']);
});

test('ConfigValues::validate : une saisie invalide est signalée (jamais corrigée en silence)', function (): void {
    assertSame([], ConfigValues::validate([]), 'rien saisi : rien à refuser');
    assertSame([], ConfigValues::validate([
        'contracttypes' => ['1', 2], 'debit_moment' => 'task', 'default_alert_threshold' => '100', 'allow_overdraft' => '0',
        'calendars_id' => '0', 'default_interventiontypes_id' => '3', 'retention_months' => '24',
    ]), 'témoin positif : saisie entièrement valide');
    assertSame(['retention_months'], ConfigValues::validate(['retention_months' => '36']), 'retention > 24 refusée');
    assertSame(['retention_months'], ConfigValues::validate(['retention_months' => 0]));
    assertSame(['retention_months'], ConfigValues::validate(['retention_months' => 'abc']));
    assertSame(['default_alert_threshold'], ConfigValues::validate(['default_alert_threshold' => '150']));
    assertSame(['default_alert_threshold'], ConfigValues::validate(['default_alert_threshold' => '-1']));
    assertSame(['debit_moment'], ConfigValues::validate(['debit_moment' => 'never']));
    assertSame(['allow_overdraft'], ConfigValues::validate(['allow_overdraft' => '2']));
    assertSame(['calendars_id'], ConfigValues::validate(['calendars_id' => '-3']));
    assertSame([], ConfigValues::validate(['contracttypes' => '']), 'liste vide postée par le formulaire');
    assertSame(['contracttypes'], ConfigValues::validate(['contracttypes' => ['x']]));
    assertSame(['contracttypes'], ConfigValues::validate(['contracttypes' => 'pas du json']));
    assertSame(['debit_moment', 'retention_months'], ConfigValues::validate(['retention_months' => '99', 'debit_moment' => 'x', 'inconnue' => 'y']), 'toutes les clés fautives, clés inconnues ignorées');
});

test('ConfigValues : flatrate_separation vaut 0 ou 1, 1 par défaut, toute autre saisie refusée', function (): void {
    assertSame(1, ConfigValues::normalize([])['flatrate_separation']);
    assertSame(0, ConfigValues::normalize(['flatrate_separation' => '0'])['flatrate_separation']);
    assertSame(1, ConfigValues::normalize(['flatrate_separation' => 'x'])['flatrate_separation']);
    assertSame([], ConfigValues::validate(['flatrate_separation' => '0']));
    assertSame(['flatrate_separation'], ConfigValues::validate(['flatrate_separation' => '2']));
});
