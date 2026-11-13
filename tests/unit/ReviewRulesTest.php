<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\ReviewRules as R;
use GlpiPlugin\Points\Domain\RuleViolation;

test('Review : une seule décision (to_review → confirmed ou adjusted), états terminaux', function (): void {
    assertTrue(R::canTransition(R::TO_REVIEW, R::CONFIRMED), 'confirmer');
    assertTrue(R::canTransition(R::TO_REVIEW, R::ADJUSTED), 'ajuster');
    foreach ([[R::CONFIRMED, R::ADJUSTED], [R::ADJUSTED, R::CONFIRMED], [R::CONFIRMED, R::TO_REVIEW], [R::ADJUSTED, R::TO_REVIEW], [R::CONFIRMED, R::CONFIRMED], [R::ADJUSTED, R::ADJUSTED]] as [$from, $to]) {
        assertTrue(!R::canTransition($from, $to), "$from -> $to refusé : une review ne se décide qu'une fois");
    }
    assertThrows(RuleViolation::class, static fn () => R::assertTransition(R::CONFIRMED, R::ADJUSTED));
    R::assertTransition(R::TO_REVIEW, R::ADJUSTED);
    assertSame([R::TO_REVIEW, R::CONFIRMED, R::ADJUSTED], R::all());
});

test('Review : raison = drapeaux reconnus, dans l\'ordre, séparés par une virgule ; aucun drapeau = pas de review', function (): void {
    assertSame('', R::reasonFromFlags([]));
    assertSame('sunday', R::reasonFromFlags(['sunday']));
    assertSame('saturday,off_hours', R::reasonFromFlags(['off_hours', 'saturday']), 'ordre de TaskRules::flags');
    assertSame('sunday,holiday,off_hours', R::reasonFromFlags(['off_hours', 'holiday', 'sunday', 'inconnu']), 'drapeau inconnu écarté');
    assertSame(['saturday', 'holiday'], R::reasons('saturday,holiday'));
    assertSame([], R::reasons(''));
});

test('Review : ajustement signé non nul (+ remboursement, − débit en plus), virgule acceptée, texte refusé', function (): void {
    assertSame(50, R::parseAdjustment('0,5'));
    assertSame(-50, R::parseAdjustment('-0.5'));
    assertSame(-125, R::parseAdjustment('-1,25'));
    foreach (['0', '0.00', '-0', '', 'abc', '1e3'] as $bad) {
        $reason = null;
        try {
            R::parseAdjustment($bad);
        } catch (RuleViolation $e) {
            $reason = $e->reason;
        }
        assertSame(RuleViolation::INVALID_AMOUNT, $reason, "« $bad » refusé");
    }
});
