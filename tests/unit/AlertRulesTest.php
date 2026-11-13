<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\AlertRules as A;
use GlpiPlugin\Points\Domain\ContractCache as C;

/** @return array{origin_date: string, expiry_date: string, remaining: int, initial: int} */
function alertLot(string $origin, string $expiry, int $initial, int $remaining): array
{
    return ['origin_date' => $origin, 'expiry_date' => $expiry, 'initial' => $initial, 'remaining' => $remaining];
}

test('Alerte : crédité et consommé des seuls lots valides à la date du jour (bornes incluses)', function (): void {
    $lots = [
        alertLot('2026-01-01', '2026-12-31', 10000, 4000),   // valide : consommé 6000
        alertLot('2025-01-01', '2026-06-30', 5000, 0),       // échu : ignoré
        alertLot('2026-12-01', '2027-12-01', 3000, 3000),    // pas encore commencé : ignoré
        alertLot('2026-11-13', '2026-11-13', 1000, 250),     // valide ce seul jour (bornes incluses) : consommé 750
    ];
    assertSame(['credited' => 11000, 'consumed' => 6750], A::validTotals($lots, '2026-11-13'));
    assertSame(['credited' => 10000, 'consumed' => 6000], A::validTotals($lots, '2026-11-14'), 'le lot d\'un jour sort le lendemain');
    assertSame(['credited' => 0, 'consumed' => 0], A::validTotals([], '2026-11-13'));
});

test('Alerte : le dépassement autorisé (reste négatif) compte comme consommé au-delà du crédité', function (): void {
    assertSame(['credited' => 1000, 'consumed' => 1250], A::validTotals([alertLot('2026-01-01', '2026-12-31', 1000, -250)], '2026-11-13'));
});

test('Alerte : seuil atteint à égalité, pas en dessous, jamais sans crédit', function (): void {
    assertTrue(A::reached(10000, 8000, 80), 'témoin : 80 % atteint');
    assertTrue(A::reached(10000, 9000, 80));
    assertTrue(!A::reached(10000, 7999, 80), '79,99 % non atteint');
    assertTrue(!A::reached(0, 0, 80), 'rien de crédité : jamais d\'alerte');
    assertTrue(!A::reached(0, 500, 80), 'consommé sans crédit valide : pas de division, pas d\'alerte');
    assertTrue(A::reached(3, 1, 33), 'centièmes entiers : 33,33 % >= 33 %');
    assertTrue(!A::reached(3, 1, 34), '33,33 % < 34 %');
});

test('Alerte : seuil 0 (ou négatif) jamais atteint, crédité 0 ou négatif jamais atteint', function (): void {
    assertTrue(!A::reached(10000, 10000, 0), 'seuil 0 : désactivé, pas une alerte permanente');
    assertTrue(!A::reached(10000, 10000, -5), 'seuil négatif');
    assertTrue(!A::reached(-100, 500, 80), 'crédité négatif');
    assertTrue(!A::reached(0, 0, 0), 'tout à zéro');
    assertTrue(A::reached(1, 1, 1), 'témoin : 1 centième consommé sur 1, seuil 1 %');
});

test('Cache : pourcentage consommé en centièmes de pour cent, arrondi, borné', function (): void {
    assertSame(8000, C::percent(10000, 8000));
    assertSame(3333, C::percent(3, 1));
    assertSame(6667, C::percent(3, 2));
    assertSame(0, C::percent(0, 500));
    assertSame(0, C::percent(1000, 0));
    assertSame(0, C::percent(1000, -100));
    assertSame(12500, C::percent(1000, 1250), 'dépassement : au-delà de 100 %');
    assertSame(C::MAX_PERCENT, C::percent(1, 2000000000), 'plafonné à la capacité de decimal(8,2)');
});

test('Cache : prochaine échéance = plus petite échéance d\'un lot au reste > 0', function (): void {
    $lots = [
        ['expiry_date' => '2026-03-01', 'remaining' => 0],
        ['expiry_date' => '2026-09-01', 'remaining' => 100],
        ['expiry_date' => '2026-06-01', 'remaining' => 50],
        ['expiry_date' => '2026-04-01', 'remaining' => -20],
    ];
    assertSame('2026-06-01', C::nextExpiry($lots));
    assertSame(null, C::nextExpiry([['expiry_date' => '2026-03-01', 'remaining' => 0]]));
    assertSame(null, C::nextExpiry([]));
});
