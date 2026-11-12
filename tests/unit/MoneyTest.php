<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\ContractCache;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\Money;

test('Money : parse en centièmes entiers, virgule ou point, arrondi au centième', function (): void {
    assertSame(1250, Money::parse('12.5'));
    assertSame(1250, Money::parse('12,50'));
    assertSame(-5, Money::parse('-0.05'));
    assertSame(4000, Money::parse(40));
    assertSame(101, Money::parse('1.005'), 'moitié arrondie vers le haut');
    assertSame(100, Money::parse('1.004'));
    assertSame(-101, Money::parse('-1.005'), 'moitié éloignée de zéro');
    assertSame(1999, Money::parse('19.99'), 'pas de dérive de flottant');
    assertSame(0, Money::parse('0'));
});

test('Money : parse refuse ce qui n\'est pas un nombre', function (): void {
    foreach (['', 'abc', '1.2.3', '1e3', '12 5'] as $bad) {
        assertThrows(InvalidArgumentException::class, static fn () => Money::parse($bad), "« $bad »");
    }
});

test('Money : format à deux décimales', function (): void {
    assertSame('12.50', Money::format(1250));
    assertSame('-0.05', Money::format(-5));
    assertSame('0.00', Money::format(0));
    assertSame('40.00', Money::format(4000));
});

test('Dates : addMonths garde le quantième ou le dernier jour du mois', function (): void {
    assertSame('2027-01-15', Dates::addMonths('2025-01-15', 24));
    assertSame('2025-02-28', Dates::addMonths('2025-01-31', 1));
    assertSame('2024-02-29', Dates::addMonths('2024-01-31', 1));
    assertSame('2026-02-28', Dates::addMonths('2024-02-29', 24), 'bissextile + 24 mois');
    assertSame('2026-01-01', Dates::addMonths('2025-12-01', 1));
});

test('Dates : check refuse une date inexistante, day coupe l\'horodatage', function (): void {
    assertThrows(InvalidArgumentException::class, static fn () => Dates::check('2025-02-30'));
    assertThrows(InvalidArgumentException::class, static fn () => Dates::check('25-01-01'));
    assertSame('2025-03-04', Dates::day('2025-03-04 23:59:59'));
});

test('Money : montant borné à 999 999 999 999 centièmes', function (): void {
    assertSame(999_999_999_999, Money::parse('9999999999.99'));
    assertSame(-999_999_999_999, Money::parse('-9999999999.99'));
    foreach (['10000000000', '10000000000.00', '99999999999999999999', '9999999999.995', '-10000000000'] as $big) {
        assertThrows(InvalidArgumentException::class, static fn () => Money::parse($big), $big);
    }
    assertThrows(InvalidArgumentException::class, static fn () => Money::parse(10_000_000_000));
    assertThrows(InvalidArgumentException::class, static fn () => Money::parse(PHP_INT_MAX));
});

test('ContractCache : credited / consumed selon conception.md, credited - consumed = balance', function (): void {
    // crédit 100, débit 30, remboursement 10, expiration 20, report 15 (sortant et entrant), reprise d'expiration 5
    $cache = ContractCache::compute([
        'credit' => 10000, 'debit_task' => -3000, 'correction' => 1000, 'expiry' => -2000, 'carryover_out' => -1500,
        'carryover_in' => 1500, 'expiry_reversal' => 500,
    ]);
    assertSame(['balance' => 6500, 'credited' => 8500, 'consumed' => 2000], $cache);
    assertSame($cache['balance'], $cache['credited'] - $cache['consumed']);
});

test('ContractCache : un remboursement réduit consumed, une expiration réduit credited', function (): void {
    $base = ContractCache::compute(['credit' => 10000, 'debit_task' => -4000]);
    assertSame(['balance' => 6000, 'credited' => 10000, 'consumed' => 4000], $base);
    $refund = ContractCache::compute(['credit' => 10000, 'debit_task' => -4000, 'compensation' => 1000]);
    assertSame(['balance' => 7000, 'credited' => 10000, 'consumed' => 3000], $refund);
    $positive = ContractCache::compute(['credit' => 10000, 'debit_task' => -4000, 'correction' => 500, 'review_adjustment' => -200]);
    assertSame(['balance' => 6300, 'credited' => 10000, 'consumed' => 3700], $positive);
    $expired = ContractCache::compute(['credit' => 10000, 'debit_task' => -4000, 'expiry' => -6000]);
    assertSame(['balance' => 0, 'credited' => 4000, 'consumed' => 4000], $expired);
    $reversal = ContractCache::compute(['credit' => 10000, 'debit_task' => -4000, 'expiry' => -6000, 'expiry_reversal' => 500, 'debit_flatrate' => -500]);
    assertSame(['balance' => 0, 'credited' => 4500, 'consumed' => 4500], $reversal);
});
