<?php

declare(strict_types=1);

use GlpiPlugin\Points\Rights;

const CONCEPTION = __DIR__ . '/../../docs/conception.md';

/**
 * Lignes du tableau « Droits du plugin » de conception.md : nom du droit => noms des bits.
 *
 * @return array<string, list<string>>
 */
function conceptionRights(): array
{
    $rows = [];
    foreach (file(CONCEPTION, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\| `(plugin_points_\w+)`[^|]*\| ([A-Z, ]+?) \|/', $line, $m) === 1) {
            $rows[$m[1]] = array_map('trim', explode(',', $m[2]));
        }
    }

    return $rows;
}

test('Rights : les droits et les bits sont ceux de conception.md', function (): void {
    $bits = ['READ' => Rights::READ, 'UPDATE' => Rights::UPDATE, 'CREATE' => Rights::CREATE, 'PURGE' => Rights::PURGE, 'VALIDATE' => Rights::VALIDATE];
    $doc  = conceptionRights();
    assertSame(8, count($doc), 'huit droits dans conception.md');
    $code = [];
    foreach (Rights::definitions() as $name => $list) {
        $code[$name] = $list;
    }
    assertSame(array_keys($doc), array_keys($code), 'mêmes droits, même ordre');
    foreach ($doc as $name => $names) {
        $expected = array_map(static fn (string $n): int => $bits[$n], $names);
        sort($expected);
        $actual = $code[$name];
        sort($actual);
        assertSame($expected, $actual, $name);
    }
});

test('Rights : VALIDATE vaut 1024 et les bits sont des puissances de deux distinctes', function (): void {
    assertSame(1024, Rights::VALIDATE);
    foreach (Rights::definitions() as $name => $bits) {
        assertSame(count($bits), count(array_unique($bits)), "$name : bits distincts");
        foreach ($bits as $bit) {
            assertTrue($bit > 0 && ($bit & ($bit - 1)) === 0, "$name : $bit est une puissance de deux");
        }
    }
});

test('Rights : masques complets (Super-Admin)', function (): void {
    assertSame(1, Rights::fullMask(Rights::BALANCE));
    assertSame(3, Rights::fullMask(Rights::TICKET));
    assertSame(7, Rights::fullMask(Rights::ENVELOPE));
    assertSame(1031, Rights::fullMask(Rights::FLATRATE));
    assertSame(1029, Rights::fullMask(Rights::COMPENSATION));
    assertSame(23, Rights::fullMask(Rights::GRID));
    assertSame(3, Rights::fullMask(Rights::CONFIG));
    assertSame(8, count(Rights::fullMasks()));
    assertThrows(InvalidArgumentException::class, static fn () => Rights::fullMask('config'), 'droit du cœur refusé');
});

test('Rights : un masque ne contient que des bits proposés', function (): void {
    assertTrue(Rights::isValidMask(Rights::FLATRATE, 1025), 'READ + VALIDATE');
    assertTrue(Rights::isValidMask(Rights::BALANCE, 0), 'zéro');
    assertSame(false, Rights::isValidMask(Rights::BALANCE, 2), 'UPDATE non proposé sur balance');
    assertSame(false, Rights::isValidMask(Rights::CONFIG, 4), 'CREATE non proposé sur config');
    assertSame(false, Rights::isValidMask('plugin_points_x', 1), 'droit inconnu');
});

test('Rights : aucun droit ne porte le nom du cœur `config`', function (): void {
    foreach (Rights::names() as $name) {
        assertTrue(str_starts_with($name, 'plugin_points_'), $name);
    }
});
