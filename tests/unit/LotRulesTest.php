<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\LotRules;
use GlpiPlugin\Points\Domain\RuleViolation;

/** @return array{id: int, origin_date: string, expiry_date: string, remaining: int} */
function lot(int $id, string $origin, string $expiry, int $remaining): array
{
    return ['id' => $id, 'origin_date' => $origin, 'expiry_date' => $expiry, 'remaining' => $remaining];
}

function violationCode(callable $fn): string
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->reason;
    }

    return 'aucune';
}

test('LotRules : échéance = min(fin d\'enveloppe, origine + durée de vie), jamais plus de 24 mois', function (): void {
    assertSame('2025-12-31', LotRules::expiryDate('2025-12-31', '2025-01-01', 24));
    assertSame('2027-01-01', LotRules::expiryDate('2030-01-01', '2025-01-01', 24), 'plafond de 24 mois');
    assertSame('2026-01-01', LotRules::expiryDate('2030-01-01', '2025-01-01', 12), 'durée de vie réglée à 12');
    assertSame('2027-01-01', LotRules::expiryDate('2030-01-01', '2025-01-01', 99), 'durée de vie bornée à 24');
});

test('LotRules : enveloppe, fin <= début + 24 mois (24 mois pile accepté, un jour de plus refusé)', function (): void {
    LotRules::checkEnvelope('2025-01-01', '2027-01-01');
    LotRules::checkEnvelope('2025-01-01', '2025-01-01');
    assertSame(RuleViolation::ENVELOPE_TOO_LONG, violationCode(fn () => LotRules::checkEnvelope('2025-01-01', '2027-01-02')));
    assertSame(RuleViolation::ENVELOPE_TOO_LONG, violationCode(fn () => LotRules::checkEnvelope('2025-01-01', '2027-02-01')), '25 mois');
    assertSame(RuleViolation::ENVELOPE_DATES, violationCode(fn () => LotRules::checkEnvelope('2025-06-01', '2025-05-31')));
    assertSame(RuleViolation::INVALID_DATE, violationCode(fn () => LotRules::checkEnvelope('2025-13-01', '2025-05-31')));
});

test('LotRules : FIFO sur trois lots, par origine puis id', function (): void {
    $lots = [
        lot(3, '2025-03-01', '2026-03-01', 1000),
        lot(1, '2025-01-01', '2026-01-01', 500),
        lot(2, '2025-01-01', '2026-01-01', 700), // même origine : l'id départage
    ];
    $r = LotRules::allocateDebit($lots, '2025-06-01', 1500, false);
    assertSame([['lot' => 1, 'amount' => -500], ['lot' => 2, 'amount' => -700], ['lot' => 3, 'amount' => -300]], $r['allocations']);
    assertSame(0, $r['overdraft']);
    assertSame(-1500, array_sum(array_column($r['allocations'], 'amount')));
});

test('LotRules : un lot échu ou pas encore commencé est ignoré ; dernier jour inclus', function (): void {
    $lots = [
        lot(1, '2024-01-01', '2025-05-31', 900),  // échu le 2025-06-01
        lot(2, '2025-07-01', '2026-06-30', 900),  // pas commencé
        lot(3, '2025-02-01', '2026-02-01', 400),
    ];
    $r = LotRules::allocateDebit($lots, '2025-06-01', 300, false);
    assertSame([['lot' => 3, 'amount' => -300]], $r['allocations']);
    // dernier jour inclus : le lot 1 est encore valide le 2025-05-31, premier jour inclus aussi pour le lot 2
    $r = LotRules::allocateDebit($lots, '2025-05-31', 300, false);
    assertSame([['lot' => 1, 'amount' => -300]], $r['allocations']);
    assertSame(1300, LotRules::availableOn($lots, '2025-07-01'), 'lot 1 échu, lots 2 et 3 valides');
    $r = LotRules::allocateDebit($lots, '2025-07-01', 2000, true);
    assertSame(700, $r['overdraft'], 'le lot 1 échu ne sert pas : 2000 - 1300 en dépassement');
    assertSame(true, LotRules::isValidOn($lots[0], '2025-05-31') && !LotRules::isValidOn($lots[0], '2025-06-01'));
    assertSame(true, LotRules::isValidOn($lots[1], '2025-07-01') && !LotRules::isValidOn($lots[1], '2025-06-30'));
});

test('LotRules : dépassement sur le lot valide le plus récent', function (): void {
    $lots = [
        lot(1, '2025-01-01', '2026-01-01', 500),
        lot(2, '2025-03-01', '2026-03-01', 200),
        lot(3, '2023-01-01', '2024-01-01', 9999), // échu : jamais le lot de dépassement
    ];
    $r = LotRules::allocateDebit($lots, '2025-06-01', 1000, true);
    assertSame([['lot' => 1, 'amount' => -500], ['lot' => 2, 'amount' => -500]], $r['allocations'], 'le reste (300) va sur le lot 2, déjà entamé : fusionné');
    assertSame(300, $r['overdraft']);
    $r = LotRules::allocateDebit([lot(1, '2025-01-01', '2026-01-01', 0), lot(2, '2025-03-01', '2026-03-01', 0)], '2025-06-01', 100, true);
    assertSame([['lot' => 2, 'amount' => -100]], $r['allocations'], 'lots vides : tout en dépassement sur le plus récent');
    assertSame(100, $r['overdraft']);
});

test('LotRules : refus sans dépassement, et sans aucun lot valide (même avec dépassement)', function (): void {
    $lots = [lot(1, '2025-01-01', '2026-01-01', 500)];
    assertSame(RuleViolation::INSUFFICIENT, violationCode(fn () => LotRules::allocateDebit($lots, '2025-06-01', 501, false)));
    assertSame(0, LotRules::allocateDebit($lots, '2025-06-01', 500, false)['overdraft'], 'témoin : 500 pile passe');
    assertSame(RuleViolation::NO_VALID_LOT, violationCode(fn () => LotRules::allocateDebit($lots, '2027-01-01', 10, true)));
    assertSame(RuleViolation::INVALID_AMOUNT, violationCode(fn () => LotRules::allocateDebit($lots, '2025-06-01', 0, true)));
    assertSame(RuleViolation::INVALID_AMOUNT, violationCode(fn () => LotRules::allocateDebit($lots, '2025-06-01', -5, true)));
});

test('LotRules : remboursement dans l\'ordre inverse du débit d\'origine, plafonné', function (): void {
    $origin = [['lot' => 1, 'amount' => -500], ['lot' => 2, 'amount' => -700], ['lot' => 3, 'amount' => -300]];
    assertSame([['lot' => 3, 'amount' => 300], ['lot' => 2, 'amount' => 200]], LotRules::refund($origin, [], 500));
    // déjà rendu : 300 sur le lot 3 → le suivant prend la suite
    assertSame([['lot' => 2, 'amount' => 700], ['lot' => 1, 'amount' => 100]], LotRules::refund($origin, [3 => 300], 800));
    assertSame(RuleViolation::NOTHING_TO_REFUND, violationCode(fn () => LotRules::refund($origin, [3 => 300], 1300)));
    assertSame(1500, array_sum(array_column(LotRules::refund($origin, [], 1500), 'amount')), 'témoin : tout le débit est remboursable');
    assertSame(RuleViolation::NOTHING_TO_REFUND, violationCode(fn () => LotRules::refund($origin, [], 1501)));
    assertSame(RuleViolation::NOTHING_TO_REFUND, violationCode(fn () => LotRules::refund([['lot' => 1, 'amount' => 500]], [], 10)), 'une allocation positive n\'est pas un débit');
});

test('LotRules : lots échus (échéance strictement avant la date, reste > 0)', function (): void {
    $lots = [lot(1, '2024-01-01', '2025-05-31', 100), lot(2, '2024-01-01', '2025-06-01', 100), lot(3, '2024-01-01', '2025-01-01', 0)];
    assertSame([1], array_column(LotRules::dueLots($lots, '2025-06-01'), 'id'), 'le 2025-06-01 est encore valide pour le lot 2 ; le lot 3 est vide');
    assertSame([1, 2], array_column(LotRules::dueLots($lots, '2025-06-02'), 'id'));
});

test('LotRules : report, nouvelle échéance = min(fin de la cible, origine + 24 mois)', function (): void {
    $lot = lot(1, '2025-03-01', '2026-02-28', 400);
    $plan = LotRules::carryoverPlan($lot, '2027-02-28', '2026-03-01', 24);
    assertSame(['carry' => true, 'expiry' => '2027-02-28'], $plan);
});

test('LotRules : report qui dépasserait 2 ans, plafonné à origine + 24 mois ; reliquat déjà au plafond expiré', function (): void {
    // origine 2024-03-02 : plafond 2026-03-02 ; le lot échoit le 2026-02-28 → report de deux jours seulement (jusqu'au plafond)
    $lot = lot(1, '2024-03-02', '2026-02-28', 400);
    assertSame(['carry' => true, 'expiry' => '2026-03-02'], LotRules::carryoverPlan($lot, '2027-02-28', '2026-03-01', 24));
    // origine 2024-03-01 : plafond 2026-03-01 = jour du traitement, le report ne donnerait rien : le reliquat expire
    assertSame(['carry' => false, 'expiry' => '2026-03-01'], LotRules::carryoverPlan(lot(6, '2024-03-01', '2026-02-28', 400), '2027-02-28', '2026-03-01', 24));
    // lot déjà au plafond de 24 mois : rien ne se reporte, le reliquat expire
    $capped = lot(2, '2024-01-01', '2026-01-01', 400);
    assertSame(['carry' => false, 'expiry' => '2026-01-01'], LotRules::carryoverPlan($capped, '2028-01-01', '2026-01-02', 24));
    // lot vide : rien à reporter
    assertSame(false, LotRules::carryoverPlan(lot(3, '2025-01-01', '2025-12-31', 0), '2026-12-31', '2026-01-01', 24)['carry']);
    // échéance du nouveau lot déjà passée : pas de report
    assertSame(false, LotRules::carryoverPlan(lot(4, '2024-01-01', '2024-12-31', 50), '2025-06-30', '2025-07-01', 24)['carry']);
    // échéance du nouveau lot = date de traitement : pas de report (il expirerait le lendemain), la veille encore oui
    assertSame(false, LotRules::carryoverPlan(lot(5, '2024-01-01', '2024-12-31', 50), '2025-07-01', '2025-07-01', 24)['carry']);
    assertSame(true, LotRules::carryoverPlan(lot(5, '2024-01-01', '2024-12-31', 50), '2025-07-02', '2025-07-01', 24)['carry']);
});

test('LotRules : enveloppe suivante = la plus proche qui finit après la source et après la date', function (): void {
    $e1 = ['id' => 1, 'begin_date' => '2025-01-01', 'end_date' => '2025-12-31'];
    $e2 = ['id' => 2, 'begin_date' => '2026-01-01', 'end_date' => '2026-12-31'];
    $e3 = ['id' => 3, 'begin_date' => '2027-01-01', 'end_date' => '2027-12-31'];
    $old = ['id' => 4, 'begin_date' => '2024-01-01', 'end_date' => '2024-12-31'];
    assertSame(2, LotRules::nextEnvelope([$e3, $old, $e2, $e1], $e1, '2026-01-01')['id']);
    assertSame(3, LotRules::nextEnvelope([$e1, $e2, $e3], $e1, '2027-01-01')['id'], 'e2 est finie à la date de traitement');
    assertSame(3, LotRules::nextEnvelope([$e1, $e2, $e3], $e1, '2026-12-31')['id'], 'e2 finit le jour même : un report n\'y donnerait rien');
    assertSame(2, LotRules::nextEnvelope([$e1, $e2, $e3], $e1, '2026-12-30')['id'], 'la veille de sa fin, e2 convient');
    assertSame(null, LotRules::nextEnvelope([$old, $e1], $e1, '2026-01-01'));
    assertSame(null, LotRules::nextEnvelope([$e1], $e1, '2026-01-01'));
});

test('LotRules : sans dépassement, le contrôle porte sur le solde réel (restes négatifs compris)', function (): void {
    $lots = [lot(1, '2025-01-01', '2026-01-01', 1000), lot(2, '2025-02-01', '2026-01-01', -500)];
    assertSame(RuleViolation::INSUFFICIENT, violationCode(fn () => LotRules::allocateDebit($lots, '2025-06-01', 800, false)), '-8 refusé (solde réel 5)');
    $r = LotRules::allocateDebit($lots, '2025-06-01', 500, false);
    assertSame([['lot' => 1, 'amount' => -500]], $r['allocations'], '-5 accepté');
    assertSame(500, LotRules::availableOn($lots, '2025-06-01'));
    assertSame(0, $r['overdraft']);
    // avec dépassement autorisé, le FIFO prend les lots positifs sans toucher au lot négatif
    assertSame(0, LotRules::allocateDebit($lots, '2025-06-01', 800, true)['overdraft']);
});

test('LotRules : débit tardif, la part prise au-delà du reste réel est reprise sur les points expirés', function (): void {
    // lot de 10 expiré (reste réel 0), débit de 5 daté d'avant l'échéance
    $lots  = [1 => ['remaining' => 0, 'expired' => 1000]];
    $alloc = [['lot' => 1, 'amount' => -500]];
    assertSame([1 => 500], LotRules::lateReversals($alloc, $lots));
    // reste réel 3 + expiré 10 : un débit de 4 reprend 1 seulement
    assertSame([1 => 100], LotRules::lateReversals([['lot' => 1, 'amount' => -400]], [1 => ['remaining' => 300, 'expired' => 1000]]));
    // rien d'expiré : pas de reprise
    assertSame([], LotRules::lateReversals($alloc, [1 => ['remaining' => 900, 'expired' => 0]]));
    // plafonné à ce qui est expiré (le reste est un dépassement)
    assertSame([1 => 200], LotRules::lateReversals([['lot' => 1, 'amount' => -900]], [1 => ['remaining' => 0, 'expired' => 200]]));
    // avec le FIFO : le lot expiré compte comme disponible une fois repris
    $effective = [lot(1, '2025-01-01', '2025-05-31', 1000)];
    $plan = LotRules::allocateDebit($effective, '2025-05-01', 500, false);
    assertSame([['lot' => 1, 'amount' => -500]], $plan['allocations']);
    assertSame(0, $plan['overdraft'], 'aucun dépassement');
});

test('LotRules : seul un débit négatif est une origine de remboursement', function (): void {
    foreach (['debit_task', 'debit_flatrate', 'correction', 'review_adjustment'] as $type) {
        assertTrue(LotRules::isDebitOrigin($type, -100), $type);
        assertSame(false, LotRules::isDebitOrigin($type, 100), "$type positif");
    }
    foreach (['carryover_out', 'expiry', 'credit', 'import', 'compensation', 'carryover_in', 'expiry_reversal'] as $type) {
        assertSame(false, LotRules::isDebitOrigin($type, -100), $type);
    }
});

test('RuleViolation : sans message, le code sert de message', function (): void {
    $e = new RuleViolation(RuleViolation::INSUFFICIENT);
    assertSame('insufficient', $e->getMessage());
    assertSame('insufficient', $e->reason);
});
