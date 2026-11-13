<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\ImportRules;

const IMPORT_HEADER = "contrat;solde;debut;fin;report;libelle\n";

/** @return list<string> messages d'erreur, préfixés du numéro de ligne */
function importErrors(string $content): array
{
    return array_map(static fn (array $e): string => $e['line'] . ': ' . $e['message'], ImportRules::parse($content)['errors']);
}

test('ImportRules : fichier valide (3 lignes fictives), montants et dates lus', function (): void {
    $r = ImportRules::parse(IMPORT_HEADER . "CTR-0001;120;2026-01-01;2026-12-31;1;Solde initial\nCTR-0002;12,5;2026-03-01;2027-02-28;0;\nCTR-0003;7.25;2026-06-01;2026-06-30;0;Reliquat\n");
    assertSame([], $r['errors']);
    assertSame(3, count($r['rows']));
    assertSame(['line' => 2, 'contract' => 'CTR-0001', 'cents' => 12000, 'begin' => '2026-01-01', 'end' => '2026-12-31', 'carry' => true, 'label' => 'Solde initial'], $r['rows'][0]);
    assertSame(1250, $r['rows'][1]['cents'], 'virgule décimale');
    assertSame(725, $r['rows'][2]['cents'], 'point décimal');
    assertSame(false, $r['rows'][1]['carry']);
});

test('ImportRules : BOM UTF-8, fins de ligne Windows et Mac, lignes vides et espaces tolérés', function (): void {
    $content = "\xEF\xBB\xBF" . "contrat;solde;debut;fin;report;libelle\r\n\r\n CTR-1 ; 5 ;2026-01-01;2026-02-01;0; x \r\nCTR-2;6;2026-01-01;2026-02-01;0;y\r\n\r\n";
    $r = ImportRules::parse($content);
    assertSame([], $r['errors']);
    assertSame(['CTR-1', 'CTR-2'], array_column($r['rows'], 'contract'));
    assertSame([3, 4], array_column($r['rows'], 'line'), 'la ligne vide compte dans la numérotation');
    assertSame('x', $r['rows'][0]['label']);
    assertSame([], ImportRules::parse("contrat;solde;debut;fin;report;libelle\rCTR-1;1;2026-01-01;2026-02-01;0;a\r")['errors']);
    assertSame([], ImportRules::parse("CONTRAT;Solde;DEBUT;fin;report;Libelle\nCTR-1;1;2026-01-01;2026-02-01;0;a")['errors'], 'en-tête sans casse');
});

test('ImportRules : en-tête inattendu, colonnes en trop ou en moins, fichier vide ou sans donnée', function (): void {
    assertSame(['1: Unexpected header, expected: contrat;solde;debut;fin;report;libelle'], importErrors("contrat,solde,debut,fin,report,libelle\nCTR-1;1;2026-01-01;2026-02-01;0;a\n"));
    assertSame(['2: Expected 6 columns separated by ";", found 5'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0\n"));
    assertSame(['2: Expected 6 columns separated by ";", found 7'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;a;b\n"));
    assertSame(['0: The file is empty'], importErrors(''));
    assertSame(['0: The file is empty'], importErrors("\n\n"));
    assertSame(['0: The file has no data line'], importErrors(IMPORT_HEADER));
});

test('ImportRules : texte non UTF-8 ou octet nul refusé', function (): void {
    assertSame(['0: The file is not valid UTF-8 text'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;caf\xE9\n"));
    assertSame(['0: The file is not valid UTF-8 text'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;a\0b\n"));
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;café\n"), 'UTF-8 accepté');
});

test('ImportRules : solde (zéro, négatif, texte, séparateur de milliers) refusé', function (): void {
    foreach (['0', '0,00', '-5', 'abc', '', '1 000', '1,234.5', '12e3', '1.500', '12,345', '0,005', '5.', '.5', "1\u{A0}000", "5\u{A0}", '--5', '+-5', '1.2.3', '٣'] as $balance) {
        $errors = importErrors(IMPORT_HEADER . "CTR-1;$balance;2026-01-01;2026-02-01;0;a\n");
        assertSame(1, count($errors), "solde « $balance » refusé");
        assertTrue(str_starts_with($errors[0], '2: '), "solde « $balance » : ligne 2");
    }
    assertSame(['2: The balance must be greater than 0'], importErrors(IMPORT_HEADER . "CTR-1;0;2026-01-01;2026-02-01;0;a\n"));
    assertSame(['2: Invalid balance (decimal number with at most 2 decimals, "," or "." as separator)'], importErrors(IMPORT_HEADER . "CTR-1;abc;2026-01-01;2026-02-01;0;a\n"));
});

test('ImportRules : montant strict, aucun arrondi silencieux (1.500, 12,345, 0,005 refusés ; 1,5 / 1.50 / +5 / 0,01 acceptés)', function (): void {
    foreach (['1.500', '12,345', '0,005', '0.001', '1,999'] as $balance) {
        $errors = importErrors(IMPORT_HEADER . "CTR-1;$balance;2026-01-01;2026-02-01;0;a\n");
        assertSame(['2: Invalid balance (decimal number with at most 2 decimals, "," or "." as separator)'], $errors, "« $balance » refusé");
    }
    foreach (['1,5' => 150, '1.50' => 150, '+5' => 500, '0,01' => 1, '0.1' => 10, '007' => 700, '12' => 1200] as $balance => $cents) {
        $r = ImportRules::parse(IMPORT_HEADER . "CTR-1;$balance;2026-01-01;2026-02-01;0;a\n");
        assertSame([], $r['errors'], "« $balance » accepté");
        assertSame($cents, $r['rows'][0]['cents'], "« $balance » en centièmes");
    }
});

test('ImportRules : libellé ou contrat avec caractère invisible (C1, bidi, largeur nulle) refusé, comme dans le rapport', function (): void {
    foreach (["\u{85}", "\u{202E}", "\u{200B}", "\u{FEFF}", "\u{2066}", "\x7f"] as $c) {
        assertSame(1, count(importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;a{$c}b\n")), 'libellé ' . bin2hex($c));
        assertSame(1, count(importErrors(IMPORT_HEADER . "CTR{$c}1;1;2026-01-01;2026-02-01;0;a\n")), 'contrat ' . bin2hex($c));
        assertTrue(!str_contains(ImportRules::displayCell("a{$c}b"), $c), 'rapport ' . bin2hex($c));
    }
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;Reliquat été — 日本\n"), 'lettres, accents et tirets longs acceptés');
});

test('ImportRules : dates invalides, fin avant début, plus de 24 mois (24 mois pile accepté)', function (): void {
    assertSame(['2: Invalid begin date (YYYY-MM-DD expected)'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-13-01;2026-12-31;0;a\n"));
    assertSame(['2: Invalid end date (YYYY-MM-DD expected)'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;31/12/2026;0;a\n"));
    assertSame(['2: Invalid begin date (YYYY-MM-DD expected)', '2: Invalid end date (YYYY-MM-DD expected)'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-02-30;2026-02-31;0;a\n"));
    assertSame(['2: The end date is before the begin date'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-06-01;2026-05-31;0;a\n"));
    assertSame(['2: The envelope lasts more than 24 months'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2028-01-02;0;a\n"));
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2028-01-01;0;a\n"), '24 mois pile');
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;1;2026-06-01;2026-06-01;0;a\n"), 'début = fin');
});

test('ImportRules : report 0 ou 1 seulement, contrat vide, libellé trop long ou à caractère de contrôle', function (): void {
    assertSame(['2: The carry-over flag must be 0 or 1'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;2;a\n"));
    assertSame(['2: The carry-over flag must be 0 or 1'], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;oui;a\n"));
    assertSame(['2: Invalid contract number (empty, too long or with control or invisible characters)'], importErrors(IMPORT_HEADER . ";1;2026-01-01;2026-02-01;0;a\n"));
    assertSame(1, count(importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;" . str_repeat('a', 256) . "\n")));
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;" . str_repeat('a', 255) . "\n"));
    assertSame(1, count(importErrors(IMPORT_HEADER . "CTR-1;1;2026-01-01;2026-02-01;0;a\x1b[31mb\n")), 'séquence d\'échappement refusée');
});

test('ImportRules : doublon dans le fichier (casse et libellé vide compris), guillemet non fermé', function (): void {
    $dup = importErrors(IMPORT_HEADER . "CTR-1;5;2026-01-01;2026-02-01;0;Solde\nCTR-2;5;2026-01-01;2026-02-01;0;Solde\nctr-1;5,00;2026-01-01;2026-02-01;1;SOLDE\n");
    assertSame(['4: Duplicate of line 2'], $dup);
    assertSame(['3: Duplicate of line 2'], importErrors(IMPORT_HEADER . "CTR-1;5;2026-01-01;2026-02-01;0;\nCTR-1;5;2026-01-01;2026-02-01;0;2026-01-01 / 2026-02-01\n"), 'libellé vide = libellé par défaut');
    assertSame([], importErrors(IMPORT_HEADER . "CTR-1;5;2026-01-01;2026-02-01;0;a\nCTR-1;6;2026-01-01;2026-02-01;0;a\nCTR-1;5;2026-01-01;2026-02-02;0;a\nCTR-1;5;2026-01-01;2026-02-01;0;b\n"), 'une différence suffit');
    assertSame(['2: Unbalanced quote (a cell cannot span several lines)', '3: Unbalanced quote (a cell cannot span several lines)'], importErrors(IMPORT_HEADER . "CTR-1;5;2026-01-01;2026-02-01;0;\"a\nb\"\n"));
    $quoted = ImportRules::parse(IMPORT_HEADER . "CTR-1;5;2026-01-01;2026-02-01;0;\"a;b\"\n");
    assertSame('a;b', $quoted['rows'][0]['label'], 'cellule entre guillemets avec séparateur');
});

test('ImportRules : toutes les erreurs sont rendues d\'un coup, ligne bonne comprise', function (): void {
    $r = ImportRules::parse(IMPORT_HEADER . "CTR-1;0;2026-01-01;2026-02-01;0;a\nCTR-2;5;2026-01-01;2026-02-01;0;b\nCTR-3;x;2026-01-01;2026-02-01;0;c\n");
    assertSame([2, 4], array_column($r['errors'], 'line'));
    assertSame(1, count($r['rows']));
});

test('ImportRules : cellule du rapport sans injection de formule ni séquence de contrôle', function (): void {
    assertSame("'=1+1", ImportRules::displayCell('=1+1'));
    assertSame("'+cmd", ImportRules::displayCell('+cmd'));
    assertSame("'-2", ImportRules::displayCell('-2'));
    assertSame("'@SUM(A1)", ImportRules::displayCell('@SUM(A1)'));
    assertSame('a [31m b', ImportRules::displayCell("a\x1b[31m\tb"));
    assertTrue(!str_contains(ImportRules::displayCell("a\x1b[31mb\tc\nd"), "\x1b"), 'pas de séquence d\'échappement');
    assertTrue(!preg_match('/[\x00-\x1F]/', ImportRules::displayCell("a\tb\nc")), 'aucun caractère de contrôle');
    assertSame('abc', ImportRules::displayCell('abc'));
    assertSame(11, mb_strlen(ImportRules::displayCell(str_repeat('é', 50), 10)), 'bornée avec une ellipse');
});

test('Import : le code n\'écrit que par Ledger et la commande garde le préfixe exigé par GLPI', function (): void {
    $import = file_get_contents(__DIR__ . '/../../src/Import.php');
    assertSame(0, preg_match('/->(insert|update|delete)\(|\$DB->query/', $import), 'Import n\'écrit pas lui-même');
    assertTrue(str_contains($import, 'Ledger::importEnvelope('), 'écriture par le service du journal');
    $command = file_get_contents(__DIR__ . '/../../src/Console/ImportCommand.php');
    assertTrue(str_contains($command, "setName('plugins:points:import')"), 'préfixe plugins:points: (CommandLoader.php:261-280)');
    assertTrue(str_contains($command, "'apply'"), 'option --apply');
    $ledger = file_get_contents(__DIR__ . '/../../src/Ledger.php');
    preg_match('/function importEnvelope\(.*?\n    }\n/s', $ledger, $m);
    assertTrue(isset($m[0]) && str_contains($m[0], 'importedEnvelope(') && str_contains($m[0], 'self::transaction('), 'idempotence vérifiée sous le verrou du contrat');
    assertTrue(str_contains($m[0], "['skipped' => true]"), 'ligne déjà importée sautée');
    // Le comportement réel (rejeu, renommage, concurrence) est au banc : scenario_import.py
    assertTrue(str_contains($m[0], "'import_key'"), 'empreinte stockée dans les détails de l\'opération');
    assertTrue(str_contains($command, "Plugin::isPluginActive('points')"), 'la commande refuse de tourner sans plugin actif');
    assertTrue(str_contains($import, 'catch (\\Throwable'), 'Import::apply intercepte toute erreur pour garder le décompte');
});
