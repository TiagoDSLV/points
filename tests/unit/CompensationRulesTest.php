<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\CompensationRules as C;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\RuleViolation;

function compensationReason(callable $fn): ?string
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->reason;
    }

    return null;
}

test('Compensation : en attente → approuvée ou rejetée, une seule décision', function (): void {
    assertTrue(C::canTransition(C::PENDING, C::APPROVED) && C::canTransition(C::PENDING, C::REJECTED));
    foreach ([C::APPROVED, C::REJECTED] as $from) {
        foreach (C::all() as $to) {
            assertTrue(!C::canTransition($from, $to), "$from -> $to refusé");
        }
    }
    assertSame(RuleViolation::COMPENSATION_STATE, compensationReason(static fn () => C::assertTransition(C::APPROVED, C::REJECTED)));
});

test('Compensation : montant demandé strictement positif, motif obligatoire', function (): void {
    assertSame(250, C::parseAmount('2,5'));
    foreach (['0', '-1', '', 'abc'] as $bad) {
        assertSame(RuleViolation::INVALID_AMOUNT, compensationReason(static fn () => C::parseAmount($bad)), "« $bad » refusé");
    }
    assertSame(RuleViolation::REASON_REQUIRED, compensationReason(static fn () => C::assertReason('')));
    assertSame(RuleViolation::REASON_REQUIRED, compensationReason(static fn () => C::assertReason("  \n ")));
    assertSame(null, compensationReason(static fn () => C::assertReason('Intervention reprise sans faute du client')), 'témoin : motif accepté');
});

test('Compensation : montant au plus égal à ce que le débit peut encore rendre', function (): void {
    assertSame(null, compensationReason(static fn () => C::assertWithin(150, 150)), 'témoin : égal au reste accepté');
    assertSame(RuleViolation::COMPENSATION_AMOUNT, compensationReason(static fn () => C::assertWithin(151, 150)));
});

test('Compensation : montant approuvé = demandé par défaut, jamais au-dessus du demandé', function (): void {
    assertSame(300, C::approvedAmount('', 300));
    assertSame(300, C::approvedAmount('  ', 300));
    assertSame(100, C::approvedAmount('1', 300));
    assertSame(300, C::approvedAmount('3', 300), 'témoin : égal au demandé accepté');
    assertSame(RuleViolation::COMPENSATION_AMOUNT, compensationReason(static fn () => C::approvedAmount('3,01', 300)));
    assertSame(RuleViolation::INVALID_AMOUNT, compensationReason(static fn () => C::approvedAmount('0', 300)));
    assertSame(RuleViolation::INVALID_AMOUNT, compensationReason(static fn () => C::approvedAmount('-1', 300)));
});

test('Compensation : le décideur ne décide pas sa propre demande (témoin : un autre décide)', function (): void {
    assertSame(RuleViolation::COMPENSATION_SELF, compensationReason(static fn () => C::assertSeparation(7, 7)));
    assertSame(null, compensationReason(static fn () => C::assertSeparation(7, 8)));
    assertSame(null, compensationReason(static fn () => C::assertSeparation(0, 0)), 'demandeur inconnu : pas de blocage');
});

test('Compensation : ne vise qu\'un débit de tâche ou de forfait', function (): void {
    assertSame([OpType::DEBIT_TASK, OpType::DEBIT_FLATRATE], C::TARGET_TYPES);
});
