<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\FlatrateRules as R;
use GlpiPlugin\Points\Domain\RuleViolation;

test('Forfait : transitions permises (conception, règle 9)', function (): void {
    $ok = [
        [R::DRAFT, R::SUBMITTED], [R::DRAFT, R::CANCELLED], [R::SUBMITTED, R::BU_REFUSED], [R::SUBMITTED, R::PROPOSED],
        [R::SUBMITTED, R::CANCELLED], [R::BU_REFUSED, R::DRAFT], [R::BU_REFUSED, R::CANCELLED], [R::PROPOSED, R::APPROVED],
        [R::PROPOSED, R::CLIENT_REFUSED], [R::PROPOSED, R::CANCELLED], [R::APPROVED, R::DEBITED],
    ];
    foreach ($ok as [$from, $to]) {
        assertTrue(R::canTransition($from, $to), "$from -> $to");
    }
    $total = 0;
    foreach (R::all() as $from) {
        foreach (R::all() as $to) {
            $total += R::canTransition($from, $to) ? 1 : 0;
        }
    }
    assertSame(count($ok), $total, 'aucune autre transition');
});

test('Forfait : refus après approbation (annulation), états terminaux, saut d\'étape', function (): void {
    assertTrue(!R::canTransition(R::APPROVED, R::CANCELLED), 'annulation impossible après approbation');
    assertTrue(!R::canTransition(R::SUBMITTED, R::APPROVED), 'pas d\'approbation sans proposition');
    assertTrue(!R::canTransition(R::DRAFT, R::PROPOSED), 'pas de proposition sans soumission');
    assertTrue(!R::canTransition(R::PROPOSED, R::DEBITED), 'pas de débit sans approbation');
    foreach ([R::DEBITED, R::CANCELLED, R::CLIENT_REFUSED] as $status) {
        assertTrue(R::isTerminal($status), "$status terminal");
    }
    foreach ([R::DRAFT, R::SUBMITTED, R::BU_REFUSED, R::PROPOSED, R::APPROVED] as $status) {
        assertTrue(!R::isTerminal($status), "$status non terminal");
    }
    assertThrows(RuleViolation::class, static fn () => R::assertTransition(R::APPROVED, R::CANCELLED));
    R::assertTransition(R::PROPOSED, R::APPROVED);
});

test('Forfait : un forfait non refusé ni annulé remplace le décompte des tâches', function (): void {
    foreach ([R::DRAFT, R::SUBMITTED, R::PROPOSED, R::APPROVED, R::DEBITED] as $status) {
        assertTrue(in_array($status, R::COUNTING, true), "$status remplace le décompte");
    }
    foreach ([R::BU_REFUSED, R::CLIENT_REFUSED, R::CANCELLED] as $status) {
        assertTrue(!in_array($status, R::COUNTING, true), "$status rend les tâches au décompte");
    }
    assertSame([R::DRAFT, R::SUBMITTED, R::PROPOSED, R::APPROVED], R::OPEN);
    assertSame([R::DRAFT, R::SUBMITTED, R::PROPOSED], R::BLOCKING_CLOSURE);
    assertTrue(R::isEditable(R::DRAFT) && !R::isEditable(R::SUBMITTED) && !R::isEditable(R::APPROVED) && !R::isEditable(R::BU_REFUSED), 'modifiable seulement en brouillon');
});

test('Forfait : montant strictement positif, au centième', function (): void {
    assertSame(600, R::parseAmount('6'));
    assertSame(650, R::parseAmount('6,5'));
    assertSame(601, R::parseAmount('6.005'));
    foreach (['0', '0.00', '-1', 'abc', '', '0.004'] as $bad) {
        assertThrows(RuleViolation::class, static fn () => R::parseAmount($bad), "« $bad » refusé");
    }
});

test('Forfait : séparation des tâches (le BU ne valide pas son propre chiffrage)', function (): void {
    assertThrows(RuleViolation::class, static fn () => R::assertSeparation(7, 7));
    R::assertSeparation(7, 8);
    R::assertSeparation(0, 8);
});
