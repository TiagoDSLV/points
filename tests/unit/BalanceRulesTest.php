<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\BalanceRules as B;

/** @return array{expiry_date: string, remaining: int} */
function balanceLot(string $expiry, int $remaining): array
{
    return ['expiry_date' => $expiry, 'remaining' => $remaining];
}

test('Soldes : recalcul depuis les mouvements (solde, crédité, consommé, %, échéance, restes des lots)', function (): void {
    // Crédit 40, débit 5, remboursement 1 : solde 36, crédité 40, consommé 4 (10 %)
    $computed = B::recompute(['credit' => 4000, 'debit_task' => -500, 'correction' => 100], [balanceLot('2027-05-31', 3600), balanceLot('2026-12-31', 0)]);
    assertSame(['balance' => 3600, 'credited' => 4000, 'consumed' => 400, 'consumed_pct' => 1000, 'next_expiry' => '2027-05-31', 'lots' => 3600], $computed);
    assertSame(['balance' => 0, 'credited' => 0, 'consumed' => 0, 'consumed_pct' => 0, 'next_expiry' => null, 'lots' => 0], B::recompute([], []), 'aucun mouvement');
});

test('Soldes : enregistré = recalcul, aucun écart ; chaque champ faux est signalé', function (): void {
    $computed = B::recompute(['credit' => 4000, 'debit_task' => -500], [balanceLot('2027-05-31', 3500)]);
    $recorded = ['balance' => 3500, 'credited' => 4000, 'consumed' => 500, 'consumed_pct' => 1250, 'next_expiry' => '2027-05-31'];
    assertSame([], B::gaps($recorded, $computed));
    assertSame([['field' => 'balance', 'recorded' => 9900, 'computed' => 3500]], B::gaps(['balance' => 9900] + $recorded, $computed), 'solde faux');
    assertSame(
        [['field' => 'consumed', 'recorded' => 0, 'computed' => 500], ['field' => 'next_expiry', 'recorded' => null, 'computed' => '2027-05-31']],
        B::gaps(['consumed' => 0, 'next_expiry' => null] + $recorded, $computed),
        'deux champs faux, dans l\'ordre du rapport'
    );
});

test('Soldes : restes des lots différents du journal, ligne Points absente', function (): void {
    $computed = B::recompute(['credit' => 4000, 'debit_task' => -500], [balanceLot('2027-05-31', 3000)]);
    $recorded = ['balance' => 3500, 'credited' => 4000, 'consumed' => 500, 'consumed_pct' => 1250, 'next_expiry' => '2027-05-31'];
    assertSame([['field' => 'lots', 'recorded' => 3500, 'computed' => 3000]], B::gaps($recorded, $computed), 'lots : journal 35, restes 30');
    $gaps = B::gaps(null, $computed);
    assertSame('row', $gaps[0]['field'], 'sans ligne Points : écart row d\'abord');
    assertSame(['row', 'balance', 'credited', 'consumed', 'consumed_pct', 'next_expiry', 'lots'], array_column($gaps, 'field'));
    assertSame([['field' => 'row', 'recorded' => null, 'computed' => null]], B::gaps(null, B::recompute([], [])), 'sans ligne ni mouvement : seul row');
});

test('Soldes : lignes de rapport lisibles (montants, pourcentage, date, absent)', function (): void {
    assertSame('balance: recorded 99.00, recomputed 35.00', B::describe(['field' => 'balance', 'recorded' => 9900, 'computed' => 3500]));
    assertSame('consumed_pct: recorded 0.00, recomputed 12.50', B::describe(['field' => 'consumed_pct', 'recorded' => 0, 'computed' => 1250]));
    assertSame('next_expiry: recorded -, recomputed 2027-05-31', B::describe(['field' => 'next_expiry', 'recorded' => null, 'computed' => '2027-05-31']));
    assertSame('lots: journal 35.00, sum of the lot remainders 30.00', B::describe(['field' => 'lots', 'recorded' => 3500, 'computed' => 3000]));
    assertSame('no Points row for a contract that has movements', B::describe(['field' => 'row', 'recorded' => null, 'computed' => null]));
});

test('Soldes : commande plugins:points:balances en lecture seule, code non nul sur un écart, calcul unique', function (): void {
    $root    = dirname(__DIR__, 2);
    $command = (string) file_get_contents($root . '/src/Console/BalancesCommand.php');
    assertTrue(str_contains($command, "setName('plugins:points:balances')") && str_contains($command, 'Ledger::balances('), 'commande et source des données');
    assertSame(0, preg_match('/->(insert|update|delete)\(|recomputeCache|Ledger::(credit|debit|refund|correction|carryover|expireDue|locked)/', $command), 'lecture seule');
    assertTrue(str_contains($command, 'Command::FAILURE'), 'code non nul sur un écart');
    $ledger = (string) file_get_contents($root . '/src/Ledger.php');
    assertTrue(preg_match('/public static function balances\(.*?BalanceRules::recompute\(.*?BalanceRules::gaps\(.*?\n    \}\n/s', $ledger) === 1, 'Ledger::balances passe par BalanceRules');
    preg_match('/public static function balances\(.*?\n    \}\n/s', $ledger, $m);
    assertSame(0, preg_match('/->(insert|update|delete)\(|self::transaction\(|writeCache/', $m[0] ?? 'x'), 'Ledger::balances en lecture seule');
});
