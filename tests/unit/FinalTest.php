<?php

declare(strict_types=1);

/**
 * Lot final (corrections d'ensemble) : logique pure en unitaire, garde-fous de lecture du code ; le comportement réel est au banc
 * (scenario_final.py, scenario_zdesinstallation.py).
 */

use GlpiPlugin\Points\ConfigValues;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\LotRules;
use GlpiPlugin\Points\Domain\RuleViolation;
use GlpiPlugin\Points\SearchOptions;

$finalSrc = static fn (string $path): string => (string) file_get_contents(__DIR__ . '/../../' . $path);

test('Dates::todayIn : le jour est celui du fuseau de référence, pas celui de l\'instant lu tel quel', function (): void {
    $paris = new \DateTimeZone('Europe/Paris');
    // 23 h 30 UTC le 13/11 = 0 h 30 à Paris le 14/11
    assertSame('2026-11-14', Dates::todayIn(new \DateTimeImmutable('2026-11-13 23:30:00', new \DateTimeZone('UTC')), $paris));
    assertSame('2026-11-13', Dates::todayIn(new \DateTimeImmutable('2026-11-13 22:30:00', new \DateTimeZone('UTC')), $paris), 'témoin : 23 h 30 à Paris, même jour');
    // 19 h 30 à New York (UTC-5) le 13/11 = 1 h 30 à Paris le 14/11
    assertSame('2026-11-14', Dates::todayIn(new \DateTimeImmutable('2026-11-13 19:30:00', new \DateTimeZone('America/New_York')), $paris));
});

test('Date du jour unique : plus de date(\'Y-m-d\') ni de today() local dans le plugin', function () use ($finalSrc): void {
    foreach (array_merge(glob(__DIR__ . '/../../src/*.php') ?: [], glob(__DIR__ . '/../../src/*/*.php') ?: [], glob(__DIR__ . '/../../front/*.php') ?: []) as $file) {
        $code = (string) file_get_contents($file);
        assertSame(0, preg_match('/\bdate\(\s*[\'"]Y-m-d[\'"]\s*\)/', $code), basename($file) . ' : date(\'Y-m-d\')');
        if (basename($file) !== 'Billing.php') {
            assertSame(0, preg_match('/function today\(/', $code), basename($file) . ' : today() local');
        }
    }
    assertTrue(str_contains($finalSrc('src/Billing.php'), 'public static function today()'), 'Billing::today');
    assertTrue(str_contains($finalSrc('src/Ledger.php'), 'Billing::today()'), 'Ledger utilise Billing::today');
});

test('LotRules : un remboursement dont le lot est échu est redirigé (part notée), les autres lots sont gardés', function (): void {
    $lots = [
        ['id' => 1, 'origin_date' => '2025-01-01', 'expiry_date' => '2025-12-31'],
        ['id' => 2, 'origin_date' => '2026-01-01', 'expiry_date' => '2026-12-31'],
    ];
    $r = LotRules::redirectExpired([['lot' => 1, 'amount' => 300], ['lot' => 2, 'amount' => 200]], $lots, '2026-06-01');
    assertSame([['lot' => 2, 'amount' => 200]], $r['kept']);
    assertSame(300, $r['redirected']);
    assertSame([1 => 300], $r['from']);
    $none = LotRules::redirectExpired([['lot' => 2, 'amount' => 200]], $lots, '2026-06-01');
    assertSame(0, $none['redirected'], 'témoin : aucun lot échu, rien n\'est redirigé');
    assertSame([['lot' => 2, 'amount' => 200]], $none['kept']);
    $last = LotRules::redirectExpired([['lot' => 1, 'amount' => 50]], $lots, '2025-12-31');
    assertSame(0, $last['redirected'], 'le dernier jour est encore valide');
});

test('LotRules : le lot valide le plus récent reçoit la part redirigée ; aucun lot valide = null', function (): void {
    $lots = [
        ['id' => 1, 'origin_date' => '2025-01-01', 'expiry_date' => '2025-12-31'],
        ['id' => 2, 'origin_date' => '2026-01-01', 'expiry_date' => '2026-12-31'],
        ['id' => 3, 'origin_date' => '2026-03-01', 'expiry_date' => '2027-02-28'],
        ['id' => 4, 'origin_date' => '2026-03-01', 'expiry_date' => '2027-02-28'],
        ['id' => 5, 'origin_date' => '2026-09-01', 'expiry_date' => '2027-08-31'], // pas encore commencé
    ];
    assertSame(4, LotRules::latestValidLot($lots, '2026-06-01')['id'], 'origine la plus récente puis id le plus grand, hors lot futur');
    assertSame(null, LotRules::latestValidLot($lots, '2030-01-01'), 'tous échus : aucun lot');
    assertSame(null, LotRules::latestValidLot([], '2026-06-01'));
});

test('LotRules : ce qui a été redirigé compte comme rendu sur le lot d\'origine (lu dans les détails)', function (): void {
    assertSame([7 => 300, 9 => 50], LotRules::redirectedFrom(['redirected' => [7 => 300, 9 => 50]]));
    assertSame([], LotRules::redirectedFrom(null));
    assertSame([], LotRules::redirectedFrom(['redirected' => ['x' => 'y', 3 => -5]]));
    $lot = static fn (int $id): array => ['id' => $id, 'origin_date' => '2025-01-01', 'expiry_date' => '2026-12-31', 'remaining' => 0];
    // Le plafond de LotRules::refund tient compte de la part redirigée : le débit de 5 ne peut plus rendre que 2
    $refund = LotRules::refund([['lot' => 7, 'amount' => -500]], [7 => 300], 200);
    assertSame([['lot' => 7, 'amount' => 200]], $refund);
    $code = violationFinal(fn () => LotRules::refund([['lot' => 7, 'amount' => -500]], [7 => 300], 201));
    assertSame(RuleViolation::NOTHING_TO_REFUND, $code, 'une part redirigée ne peut pas être rendue deux fois');
    assertTrue($lot(1)['id'] === 1);
});

function violationFinal(callable $fn): ?string
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->reason;
    }

    return null;
}

test('LotRules : le dépassement nomme le lot qui le porte', function (): void {
    $lots = [
        ['id' => 1, 'origin_date' => '2025-01-01', 'expiry_date' => '2026-01-01', 'remaining' => 500],
        ['id' => 2, 'origin_date' => '2025-03-01', 'expiry_date' => '2026-03-01', 'remaining' => 200],
    ];
    $r = LotRules::allocateDebit($lots, '2025-06-01', 1000, true);
    assertSame(2, $r['overdraft_lot']);
    assertSame(300, $r['overdraft']);
    assertSame(null, LotRules::allocateDebit($lots, '2025-06-01', 100, true)['overdraft_lot'], 'témoin : sans dépassement, aucun lot marqué');
});

test('ConfigValues : le type d\'intervention par défaut et le calendrier doivent exister (calendrier 0 permis)', function (): void {
    $exists = static fn (string $table, int $id): bool => in_array([$table, $id], [['glpi_plugin_points_interventiontypes', 3], ['glpi_calendars', 5]], true);
    assertSame([], ConfigValues::validate(['default_interventiontypes_id' => '3', 'calendars_id' => '5'], $exists), 'témoin : les deux existent');
    assertSame([], ConfigValues::validate(['calendars_id' => '0'], $exists), 'calendrier 0 = celui de l\'entité');
    assertSame(['default_interventiontypes_id'], ConfigValues::validate(['default_interventiontypes_id' => '99'], $exists));
    assertSame(['default_interventiontypes_id'], ConfigValues::validate(['default_interventiontypes_id' => '0'], $exists), 'le type par défaut ne peut pas être 0');
    assertSame(['calendars_id'], ConfigValues::validate(['calendars_id' => '99'], $exists));
    assertSame([], ConfigValues::validate(['default_interventiontypes_id' => '99']), 'sans contrôle d\'existence : forme seulement');
});

test('ConfigValues : compensation_on_expired vaut valid_lot ou origin, valid_lot par défaut', function (): void {
    assertSame('valid_lot', ConfigValues::normalize([])['compensation_on_expired']);
    assertSame('origin', ConfigValues::normalize(['compensation_on_expired' => 'origin'])['compensation_on_expired']);
    assertSame('valid_lot', ConfigValues::normalize(['compensation_on_expired' => 'autre'])['compensation_on_expired']);
    assertSame([], ConfigValues::validate(['compensation_on_expired' => 'origin']));
    assertSame(['compensation_on_expired'], ConfigValues::validate(['compensation_on_expired' => 'autre']));
    assertSame('origin', ConfigValues::toStorage(['compensation_on_expired' => 'origin'])['compensation_on_expired']);
});

test('SearchOptions : une recherche enregistrée qui utilise une option du plugin est reconnue', function (): void {
    $numbers = SearchOptions::numbers();
    assertSame([9701, 9702, 9703, 9704, 9705, 9706, 9707], $numbers['Contract']);
    assertSame([9721, 9722, 9723, 9724, 9725], $numbers['Entity']);
    $contract = $numbers['Contract'];
    assertTrue(SearchOptions::queryUses('criteria%5B0%5D%5Bfield%5D=9703&criteria%5B0%5D%5Bsearchtype%5D=contains&criteria%5B0%5D%5Bvalue%5D=5', $contract), 'critère');
    assertTrue(SearchOptions::queryUses('sort%5B0%5D=9701&order%5B0%5D=DESC', $contract), 'tri');
    assertTrue(SearchOptions::queryUses('metacriteria[0][field]=9707&metacriteria[0][value]=1', $contract), 'méta-critère');
    assertSame(false, SearchOptions::queryUses('criteria[0][field]=1&criteria[0][value]=9703&sort=2', $contract), 'témoin : 9703 comme VALEUR, pas comme champ');
    assertSame(false, SearchOptions::queryUses('criteria[0][field]=9721', $contract), 'une option de l\'entité n\'est pas une option du contrat');
    assertSame(false, SearchOptions::queryUses('', $contract));
});

test('Contrat : purge refusée par le crochet, type vérifié par requireContract', function () use ($finalSrc): void {
    $setup = $finalSrc('setup.php');
    assertTrue(preg_match("/PRE_ITEM_PURGE.*?'Contract'\s*=>\s*\[\\\\GlpiPlugin\\\\Points\\\\Contract::class, 'contractPrePurge'\]/s", $setup) === 1, 'crochet PRE_ITEM_PURGE du contrat');
    $contract = $finalSrc('src/Contract.php');
    assertTrue(preg_match('/function contractPrePurge\(\\\\Contract \$contract\).*?\$contract->input = false;/s', $contract) === 1, 'contractPrePurge annule la purge');
    assertTrue(preg_match('/function requireContract.*?carriesPoints\(\$contract\).*?AccessDeniedHttpException/s', $contract) === 1, 'requireContract refuse un contrat qui ne porte pas de points');
});

test('Ledger::check ne dépend plus de allow_overdraft ; commande plugins:points:check en lecture seule', function () use ($finalSrc): void {
    $ledger = $finalSrc('src/Ledger.php');
    preg_match('/public static function check\(.*?\n    \}\n/s', $ledger, $m);
    assertTrue($m !== [], 'check trouvée');
    assertSame(0, preg_match('/allow_overdraft/', $m[0]), 'check ne lit pas le paramètre du moment');
    assertTrue(str_contains($m[0], 'overdraft_lot'), 'check lit le lot de dépassement des opérations de débit');
    $command = $finalSrc('src/Console/CheckCommand.php');
    assertTrue(str_contains($command, "setName('plugins:points:check')") && str_contains($command, 'Ledger::checkAll()'), 'commande');
    assertSame(0, preg_match('/->(insert|update|delete)\(|recomputeCache|Ledger::(credit|debit|refund|correction|carryover|expireDue)/', $command), 'lecture seule');
    assertTrue(str_contains($command, 'Command::FAILURE'), 'code non nul sur un écart');
});

test('Ledger : un remboursement passe par writeRefund (correction +, compensation, ajustement +), sous le verrou', function () use ($finalSrc): void {
    $ledger = $finalSrc('src/Ledger.php');
    assertSame(2, substr_count($ledger, 'return self::writeRefund('), 'correction + et refund() passent par writeRefund');
    assertSame(1, substr_count($ledger, 'LotRules::refund(self::allocationsOf'), 'la répartition d\'un remboursement est écrite une seule fois, dans writeRefund');
    assertTrue(str_contains($ledger, 'compensation_on_expired') && str_contains($ledger, 'LotRules::redirectExpired') && str_contains($ledger, 'LotRules::latestValidLot'), 'paramètre lu, part redirigée');
    assertTrue(str_contains($ledger, "'redirected'") && str_contains($ledger, 'LotRules::redirectedFrom'), 'part redirigée notée et relue (refundedByLot)');
});

test('Ponytail : une seule lecture d\'opération, un seul libellé de drapeau, configuration en cache, version de grille indexée', function () use ($finalSrc): void {
    foreach (['Review', 'Compensation'] as $class) {
        assertSame(0, preg_match('/function debitOf\(/', $finalSrc("src/$class.php")), "$class : plus de lecture d'opération locale");
        assertTrue(str_contains($finalSrc("src/$class.php"), 'Operation::read('), "$class lit par Operation::read");
    }
    assertSame(0, preg_match('/Outside working hours/', $finalSrc('src/Review.php') . $finalSrc('src/Ticket.php')), 'libellés des drapeaux dans Billing::flagLabel seulement');
    assertTrue(str_contains($finalSrc('src/Billing.php'), 'public static function flagLabel('), 'Billing::flagLabel');
    $config = $finalSrc('src/Config.php');
    assertTrue(str_contains($config, 'self::$cache ??=') && preg_match('/function save\(.*?self::reset\(\);/s', $config) === 1, 'Config::get en cache, vidé par save');
    $version = $finalSrc('src/GridVersion.php');
    assertSame(0, preg_match('/LIKE/', $version), 'isUsed ne cherche plus dans les détails du journal');
    assertTrue(str_contains($version, "'plugin_points_gridversions_id' => \$versionId"), 'isUsed lit la colonne indexée');
    assertTrue(str_contains($finalSrc('src/Install.php'), "\$idx('plugin_points_gridversions_id')"), 'colonne indexée dans le schéma');
    assertTrue(str_contains($finalSrc('src/Ledger.php'), "'HAVING'"), 'expireDue ne parcourt que les contrats au lot échu non vide');
    assertTrue(str_contains($finalSrc('src/Ticket.php'), 'Ledger::netDebits('), 'l\'onglet du ticket lit le débit net du journal');
});

test('Install : la désinstallation retire options, recherches enregistrées et validations en attente', function () use ($finalSrc): void {
    $install = $finalSrc('src/Install.php');
    assertTrue(str_contains($install, 'self::purgeProposedValidations();') && str_contains($install, 'SearchOptions::numbers()') && str_contains($install, 'SavedSearch())->delete('), 'trois nettoyages');
    assertSame(0, preg_match("/DELETE|->delete\('glpi_savedsearches'/", $install), 'les recherches enregistrées passent par SavedSearch::delete (tables filles)');
    assertTrue(preg_match('/function uninstall\(.*?purgeProposedValidations\(\);.*?dropTable/s', $install) === 1, 'validations purgées AVANT la suppression des tables');
});

test('Import : option --accept-similar et erreur de plan sur une ligne semblable', function () use ($finalSrc): void {
    assertTrue(str_contains($finalSrc('src/Console/ImportCommand.php'), "'accept-similar'"), 'option');
    $import = $finalSrc('src/Import.php');
    assertTrue(str_contains($import, 'Ledger::similarImport(') && str_contains($import, '!$acceptSimilar'), 'contrôle de la ligne semblable, passé outre par l\'option');
});

test('RuleViolation : plus de code inutilisé', function () use ($finalSrc): void {
    $violation = $finalSrc('src/Domain/RuleViolation.php');
    preg_match_all('/public const (\w+)\s*=/', $violation, $constants);
    $all = '';
    foreach (array_merge(glob(__DIR__ . '/../../src/*.php') ?: [], glob(__DIR__ . '/../../src/Domain/*.php') ?: []) as $file) {
        if (basename($file) !== 'RuleViolation.php') {
            $all .= (string) file_get_contents($file);
        }
    }
    foreach ($constants[1] as $name) {
        assertTrue(str_contains($all, "RuleViolation::$name"), "RuleViolation::$name n'est jamais utilisé");
    }
});

test('Fusion de tickets : une tâche doublée sur un autre ticket n\'est pas débitée ici', function () use ($finalSrc): void {
    $billing = $finalSrc('src/Billing.php');
    assertTrue(preg_match('/function pendingTasks\(.*?mergedElsewhere\(/s', $billing) === 1, 'pendingTasks exclut les tâches doublées par une fusion');
    assertSame(1, substr_count($billing, 'function sameTask('), 'une seule comparaison de tâches');
});

test('Forfait : le refus de clôture d\'un forfait utilise les messages traduits du forfait', function () use ($finalSrc): void {
    $flatrate = $finalSrc('src/Flatrate.php');
    assertTrue(preg_match('/function closureProblem\(.*?The flatrate cannot be debited: %s.*?self::describe\(\$e\)/s', $flatrate) === 1, 'closureProblem passe par Flatrate::describe (FLATRATE_NO_CONTRACT traduit)');
    assertTrue(str_contains($flatrate, 'RuleViolation::FLATRATE_NO_CONTRACT   =>'), 'FLATRATE_NO_CONTRACT a son message');
});

test('Libellés : expiry_reversal a son libellé, chaque type d\'opération aussi', function () use ($finalSrc): void {
    $operation = $finalSrc('src/Operation.php');
    foreach (\GlpiPlugin\Points\Domain\OpType::all() as $type) {
        $const = strtoupper($type);
        assertTrue(str_contains($operation, "OpType::$const "), "Operation::typeLabel : $type");
    }
});

test('LotRules : un lot échu en dépassement garde d\'abord la part qui comble son reste négatif, seul le surplus est redirigé', function (): void {
    $lots = [['id' => 1, 'expiry_date' => '2025-12-31', 'remaining' => -2000], ['id' => 2, 'expiry_date' => '2026-12-31', 'remaining' => 500]];
    $r = LotRules::redirectExpired([['lot' => 1, 'amount' => 3000]], $lots, '2026-06-01');
    assertSame([['lot' => 1, 'amount' => 2000]], $r['kept'], 'le reste -20 est comblé sur le lot échu');
    assertSame(1000, $r['redirected'], 'seuls 10 sont redirigés');
    assertSame([1 => 1000], $r['from']);
    $small = LotRules::redirectExpired([['lot' => 1, 'amount' => 1500]], $lots, '2026-06-01');
    assertSame([['lot' => 1, 'amount' => 1500]], $small['kept'], 'part inférieure au dépassement : tout reste sur le lot échu');
    assertSame(0, $small['redirected'], 'rien n\'est créé');
    $positive = LotRules::redirectExpired([['lot' => 1, 'amount' => 300]], [['id' => 1, 'expiry_date' => '2025-12-31', 'remaining' => 600]], '2026-06-01');
    assertSame(300, $positive['redirected'], 'témoin : un lot échu au reste positif redirige tout');
});

test('AlertRules : un lot créé par un remboursement redirigé n\'est pas un achat (hors crédité, en déduction du consommé)', function (): void {
    $lots = [
        ['origin_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'initial' => 10000, 'remaining' => 4000],
        ['origin_date' => '2026-11-01', 'expiry_date' => '2027-10-31', 'initial' => 1000, 'remaining' => 1000, 'refund' => true],
    ];
    assertSame(['credited' => 10000, 'consumed' => 5000], \GlpiPlugin\Points\Domain\AlertRules::validTotals($lots, '2026-11-13'), 'consommé 6000 - 1000');
    $lots[1]['remaining'] = 0;
    assertSame(['credited' => 10000, 'consumed' => 6000], \GlpiPlugin\Points\Domain\AlertRules::validTotals($lots, '2026-11-13'), 'consommé ensuite : neutre');
    $lots[1]['refund'] = false;
    assertSame(['credited' => 11000, 'consumed' => 7000], \GlpiPlugin\Points\Domain\AlertRules::validTotals($lots, '2026-11-13'), 'témoin : un vrai crédit compte');
});

test('Structure : ligne Points du ticket (entité suivie, lecture du ticket), fusion par lien natif, figeage en écriture, reprise à chaque installation', function () use ($finalSrc): void {
    $ticket = $finalSrc('src/Ticket.php');
    assertTrue(preg_match('/function canViewItem\(\).*?self::canReadTicket\(/s', $ticket) === 1, 'canViewItem exige la lecture du ticket');
    assertTrue(str_contains($ticket, 'function followTicketEntity(') && str_contains($finalSrc('src/ItilHooks.php'), 'Ticket::followTicketEntity('), 'entité suivie');
    $billing = $finalSrc('src/Billing.php');
    assertTrue(preg_match('/function mergedElsewhere\(.*?tickets_id_1.*?SON_OF.*?\n    \}\n/s', $billing) === 1, 'copie liée par le lien de fusion natif');
    preg_match('/public static function evaluate\(.*?\n    \}\n/s', $billing, $m);
    assertSame(0, preg_match('/freezeType/', $m[0] ?? ''), 'evaluate reste en lecture seule');
    assertTrue(substr_count($billing, 'self::freezeType($ctx);') === 2, 'figeage dans assertTaskAccepted et debitTask');
    assertTrue(str_contains($ticket, 'Billing::mergedElsewhere('), 'l\'onglet applique la même exclusion que pendingTasks');
    assertSame(0, preg_match('/backfillVersions/', $finalSrc('src/Install.php')), 'reprise à chaque installation, sans condition');
    assertTrue(str_contains($finalSrc('src/Import.php'), '$fileKeys'), 'import : empreintes du fichier');
});
