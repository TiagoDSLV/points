<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Lecture et validation du fichier d'import des soldes du BPM Kiwi (docs/conception.md, « Import des soldes du BPM Kiwi »).
 * Classe pure : aucune dépendance à GLPI, aucune lecture de base ; les contrôles qui demandent la base (contrat, type,
 * déjà importé) sont dans Import.
 *
 * Format : texte UTF-8 (BOM toléré), séparateur `;`, première ligne = en-tête `contrat;solde;debut;fin;report;libelle`,
 * une enveloppe par ligne. Le fichier est lu en entier : toute erreur est rendue avec son numéro de ligne, et l'appelant
 * n'écrit rien tant qu'il en reste une.
 */
final class ImportRules
{
    public const HEADER = ['contrat', 'solde', 'debut', 'fin', 'report', 'libelle'];

    /** Longueur maximale d'une cellule texte (num du contrat, libellé : VARCHAR(255)). */
    public const MAX_TEXT = 255;

    /**
     * @return array{rows: list<array{line: int, contract: string, cents: int, begin: string, end: string, carry: bool, label: string}>,
     *               errors: list<array{line: int, message: string}>}
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return ['rows' => [], 'errors' => [['line' => 0, 'message' => 'The file is not valid UTF-8 text']]];
        }

        $rows   = [];
        $errors = [];
        $seen   = [];
        $header = false;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $text) {
            $line = $index + 1;
            if (trim($text) === '') {
                continue;
            }
            // Une cellule entre guillemets ne s'étend pas sur plusieurs lignes : le numéro de ligne du rapport resterait faux
            if (substr_count($text, '"') % 2 === 1) {
                $errors[] = ['line' => $line, 'message' => 'Unbalanced quote (a cell cannot span several lines)'];
                $header   = true;
                continue;
            }
            $cells = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($text, ';', '"', ''));
            if (!$header) {
                $header = true;
                if (array_map('mb_strtolower', $cells) !== self::HEADER) {
                    $errors[] = ['line' => $line, 'message' => 'Unexpected header, expected: ' . implode(';', self::HEADER)];
                }
                continue;
            }
            if (count($cells) !== count(self::HEADER)) {
                $errors[] = ['line' => $line, 'message' => sprintf('Expected %d columns separated by ";", found %d', count(self::HEADER), count($cells))];
                continue;
            }
            $problems = [];
            $row      = self::row($line, $cells, $problems);
            foreach ($problems as $problem) {
                $errors[] = ['line' => $line, 'message' => $problem];
            }
            if ($problems !== [] || $row === null) {
                continue;
            }
            $key = self::key($row['contract'], $row['cents'], $row['begin'], $row['end'], $row['label']);
            if (isset($seen[$key])) {
                $errors[] = ['line' => $line, 'message' => 'Duplicate of line ' . $seen[$key]];
                continue;
            }
            $seen[$key] = $line;
            $rows[]     = $row;
        }
        if (!$header) {
            $errors[] = ['line' => 0, 'message' => 'The file is empty'];
        } elseif ($rows === [] && $errors === []) {
            $errors[] = ['line' => 0, 'message' => 'The file has no data line'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Clé d'une enveloppe importée : contrat, solde, dates et libellé effectif (celui que l'enveloppe portera). Sert au
     * contrôle des doublons du fichier ; la base est comparée par la même clé (Ledger::importEnvelope). La casse ne compte pas
     * (la base compare les noms sans casse).
     */
    public static function key(string $contract, int $cents, string $begin, string $end, string $label): string
    {
        return mb_strtolower(implode("\x1F", [$contract, (string) $cents, $begin, $end, self::effectiveLabel($label, $begin, $end)]));
    }

    /**
     * Nom de l'enveloppe : le libellé, sinon « début / fin » (comme une enveloppe créée sans nom, Ledger::createEnvelope).
     */
    public static function effectiveLabel(string $label, string $begin, string $end): string
    {
        return trim($label) !== '' ? trim($label) : $begin . ' / ' . $end;
    }

    /**
     * Cellule destinée à l'affichage d'un rapport : caractères de contrôle retirés (séquences d'échappement du terminal),
     * longueur bornée, et une cellule qui commence par `=`, `+`, `-`, `@` précédée d'une apostrophe (aucune formule si le
     * rapport est collé dans un tableur). Les valeurs écrites en base ne passent jamais par ici.
     */
    public static function displayCell(string $cell, int $max = 60): string
    {
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $cell) ?? '';
        if (mb_strlen($clean) > $max) {
            $clean = mb_substr($clean, 0, $max) . '…';
        }
        if ($clean !== '' && strpbrk($clean[0], "=+-@") !== false) {
            $clean = "'" . $clean;
        }

        return $clean;
    }

    /**
     * @param list<string> $cells
     * @param list<string> $problems rempli par référence
     * @return array{line: int, contract: string, cents: int, begin: string, end: string, carry: bool, label: string}|null
     */
    private static function row(int $line, array $cells, array &$problems): ?array
    {
        [$contract, $balance, $begin, $end, $carry, $label] = $cells;

        if ($contract === '' || mb_strlen($contract) > self::MAX_TEXT || preg_match('/[\p{Cc}\p{Cf}]/u', $contract) === 1) {
            $problems[] = 'Invalid contract number (empty, too long or with control or invisible characters)';
        }
        $cents = 0;
        try {
            // Strict : chiffres, une virgule ou un point suivi d'AU PLUS deux décimales (aucun arrondi silencieux d'un solde importé),
            // ni signe moins, ni séparateur de milliers, ni espace insécable
            if (preg_match('/^\+?\d+([.,]\d{1,2})?$/', $balance) !== 1) {
                throw new \InvalidArgumentException('format');
            }
            $cents = Money::parse($balance);
            if ($cents <= 0) {
                $problems[] = 'The balance must be greater than 0';
            }
        } catch (\InvalidArgumentException) {
            $problems[] = 'Invalid balance (decimal number with at most 2 decimals, "," or "." as separator)';
        }
        $datesOk = true;
        foreach (['begin' => $begin, 'end' => $end] as $name => $value) {
            try {
                Dates::check($value);
            } catch (\InvalidArgumentException) {
                $datesOk    = false;
                $problems[] = sprintf('Invalid %s date (YYYY-MM-DD expected)', $name === 'begin' ? 'begin' : 'end');
            }
        }
        if ($datesOk) {
            try {
                LotRules::checkEnvelope($begin, $end);
            } catch (RuleViolation $e) {
                $problems[] = match ($e->reason) {
                    RuleViolation::ENVELOPE_DATES    => 'The end date is before the begin date',
                    RuleViolation::ENVELOPE_TOO_LONG => 'The envelope lasts more than 24 months',
                    default                          => $e->getMessage(),
                };
            }
        }
        if (!in_array($carry, ['0', '1'], true)) {
            $problems[] = 'The carry-over flag must be 0 or 1';
        }
        if (mb_strlen($label) > self::MAX_TEXT || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1) {
            $problems[] = 'Invalid label (too long or with control or invisible characters)';
        }

        return $problems !== [] ? null : [
            'line' => $line, 'contract' => $contract, 'cents' => $cents, 'begin' => $begin, 'end' => $end, 'carry' => $carry === '1', 'label' => $label,
        ];
    }
}
