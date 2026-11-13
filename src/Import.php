<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\ImportRules;
use GlpiPlugin\Points\Domain\LotRules;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\RuleViolation;

/**
 * Import des soldes du BPM Kiwi (docs/conception.md, « Import des soldes du BPM Kiwi ») : contrôles qui demandent la base,
 * puis écriture par Ledger::importEnvelope. Le format du fichier est dans Domain\ImportRules.
 *
 * Aucun contrôle de droit : la commande console n'a pas de session utilisateur (accès au shell du serveur = administrateur) ;
 * chaque écriture porte `users_id` = 0 et le commentaire « Import BPM <fichier> ligne <n> ».
 */
final class Import
{
    public const STATUS_NEW   = 'new';
    public const STATUS_SKIP  = 'skip';
    public const STATUS_ERROR = 'error';

    /**
     * Contrôle de TOUTES les lignes (rien n'est écrit) : contrat trouvé par son numéro, unique, non supprimé, d'un type à points ;
     * ligne déjà importée ; doublon après résolution du contrat ; ligne NON importée mais semblable à une enveloppe déjà importée (même
     * contrat, solde, début et fin, libellé différent : le libellé a sans doute été corrigé, et la ligne recréditerait le solde) : erreur
     * de plan, sauf `$acceptSimilar` (option `--accept-similar` de la commande).
     *
     * @param list<array{line: int, contract: string, cents: int, begin: string, end: string, carry: bool, label: string}> $rows
     * @return list<array{row: array<string, mixed>, contracts_id: int, status: string, message: string, warnings: list<string>}>
     */
    public static function plan(array $rows, ?string $today = null, bool $acceptSimilar = false): array
    {
        $today ??= Billing::today();
        $types  = Config::value('contracttypes');
        $cache  = [];
        $seen   = [];
        $plan   = [];
        // Empreintes de toutes les lignes du fichier : l'enveloppe « semblable » d'une AUTRE ligne du même fichier (import interrompu, repris)
        // n'est pas un libellé corrigé
        $fileKeys = [];
        foreach ($rows as $row) {
            $cache[$row['contract']] ??= self::resolve($row['contract'], $types);
            if ($cache[$row['contract']][1] === null) {
                $fileKeys[Ledger::importKey($cache[$row['contract']][0], ImportRules::effectiveLabel($row['label'], $row['begin'], $row['end']), $row['cents'], $row['begin'], $row['end'])] = true;
            }
        }
        foreach ($rows as $row) {
            $result = ['row' => $row, 'contracts_id' => 0, 'status' => self::STATUS_NEW, 'message' => '', 'warnings' => []];
            $cache[$row['contract']] ??= self::resolve($row['contract'], $types);
            [$contractsId, $problem] = $cache[$row['contract']];
            if ($problem !== null) {
                $plan[] = ['status' => self::STATUS_ERROR, 'message' => $problem] + $result;
                continue;
            }
            $result['contracts_id'] = $contractsId;
            // Doublon : même contrat, solde et dates, libellés égaux SELON LA BASE (casse, accents, ß/ss… : collation de la colonne)
            $name   = ImportRules::effectiveLabel($row['label'], $row['begin'], $row['end']);
            $bucket = $contractsId . "\x1F" . $row['cents'] . "\x1F" . $row['begin'] . "\x1F" . $row['end'];
            $twin   = null;
            foreach ($seen[$bucket] ?? [] as [$otherName, $otherLine]) {
                if (self::sameLabel($name, $otherName)) {
                    $twin = $otherLine;
                    break;
                }
            }
            if ($twin !== null) {
                $plan[] = ['status' => self::STATUS_ERROR, 'message' => 'Duplicate of line ' . $twin . ' (same contract, labels equal for the database)'] + $result;
                continue;
            }
            $seen[$bucket][] = [$name, $row['line']];
            $expiry     = LotRules::expiryDate($row['end'], $row['begin'], (int) Config::value('retention_months'));
            if ($expiry < $today) {
                $result['warnings'][] = 'The points expire on ' . $expiry . ', already past: they will expire at the next run of the pointsexpire task';
            }
            if (Ledger::importedEnvelope($contractsId, $name, $row['cents'], $row['begin'], $row['end']) !== null) {
                $result['status']  = self::STATUS_SKIP;
                $result['message'] = 'Already imported';
            } elseif (!$acceptSimilar && ($similar = Ledger::similarImport($contractsId, $row['cents'], $row['begin'], $row['end'])) !== null && !isset($fileKeys[$similar['key']])) {
                $plan[] = ['status' => self::STATUS_ERROR, 'message' => 'Same contract, balance and dates as the imported envelope #' . $similar['envelope'] . ' under another label: correct the label or use --accept-similar'] + $result;
                continue;
            }
            $plan[] = $result;
        }

        return $plan;
    }

    /**
     * Écrit les lignes `new` du plan, une transaction par ligne (verrou du contrat), et s'arrête à la première erreur : l'import est
     * rejouable, les lignes déjà écrites seront sautées. `created` = écrite, `skipped` = trouvée déjà importée sous le verrou
     * (lancement concurrent), `failed` = refusée par le Ledger.
     *
     * @param list<array{row: array<string, mixed>, contracts_id: int, status: string, message: string, warnings: list<string>}> $plan
     * @return list<array{line: int, outcome: string, message: string}>
     */
    public static function apply(array $plan, string $source): array
    {
        $out = [];
        foreach ($plan as $item) {
            $row = $item['row'];
            if ($item['status'] !== self::STATUS_NEW) {
                continue;
            }
            try {
                $done = Ledger::importEnvelope(
                    $item['contracts_id'],
                    $row['label'],
                    $row['cents'],
                    $row['begin'],
                    $row['end'],
                    $row['carry'],
                    self::comment($source, $row['line'])
                );
                $out[] = ['line' => $row['line'], 'outcome' => $done['skipped'] ? 'skipped' : 'created', 'message' => $done['skipped'] ? 'Already imported' : ''];
            } catch (RuleViolation $e) {
                $out[] = ['line' => $row['line'], 'outcome' => 'failed', 'message' => Ledger::describe($e)];
                break;
            } catch (\Throwable $e) {
                // Erreur de base ou autre : le décompte des lignes déjà écrites doit toujours être affiché ; la classe seule, pas de message brut
                $out[] = ['line' => $row['line'], 'outcome' => 'failed', 'message' => 'Unexpected error (' . $e::class . ')'];
                break;
            }
        }

        return $out;
    }

    /**
     * Commentaire de l'opération : « Import BPM <fichier> ligne <n> » (nom du fichier sans dossier, sans caractère de contrôle).
     */
    public static function comment(string $source, int $line): string
    {
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', basename($source)) ?? '';

        return sprintf('Import BPM %s ligne %d', mb_substr($name, 0, 150), $line);
    }

    /**
     * @param list<int> $types types de contrat portant des points (paramétrage)
     * @return array{0: int, 1: ?string} [id du contrat, message d'erreur]
     */
    private static function resolve(string $num, array $types): array
    {
        global $DB;

        $found = [];
        foreach ($DB->request(['SELECT' => ['id', 'is_deleted', 'contracttypes_id'], 'FROM' => 'glpi_contracts', 'WHERE' => ['num' => $num, 'is_template' => 0]]) as $contract) {
            $found[] = $contract;
        }
        if ($found === []) {
            return [0, 'Unknown contract number'];
        }
        $live = array_values(array_filter($found, static fn (array $c): bool => (int) $c['is_deleted'] === 0));
        if (count($live) > 1) {
            return [0, 'Several contracts have this number'];
        }
        if ($live === []) {
            return [0, 'The contract is in the trash'];
        }
        if (!in_array((int) $live[0]['contracttypes_id'], $types, true)) {
            return [0, 'The contract type is not a points type (plugin setting)'];
        }

        return [(int) $live[0]['id'], null];
    }

    /**
     * Deux libellés sont-ils égaux pour la base ? Comparaison par la collation de la colonne `name` des enveloppes (sans casse ni accents
     * en utf8mb4_unicode_ci, ß = ss…), posée par la base : aucune normalisation maison qui divergerait d'elle.
     */
    private static function sameLabel(string $a, string $b): bool
    {
        global $DB;

        if ($a === $b) {
            return true;
        }
        $collation = $DB->request(['SELECT' => ['COLLATION_NAME'], 'FROM' => 'information_schema.columns', 'WHERE' => [
            'table_schema' => $DB->dbdefault, 'table_name' => Envelope::getTable(), 'column_name' => 'name',
        ], 'LIMIT' => 1])->current()['COLLATION_NAME'] ?? null;
        $collation = is_string($collation) && preg_match('/^[a-z0-9_]+$/', $collation) === 1 ? $collation : 'utf8mb4_unicode_ci';
        $row = $DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression(
            '(' . \DBmysql::quoteValue($a) . ' COLLATE ' . $collation . ') = (' . \DBmysql::quoteValue($b) . ' COLLATE ' . $collation . ') AS ' . \DBmysql::quoteName('same')
        )], 'FROM' => 'glpi_configs', 'LIMIT' => 1])->current();

        return (int) ($row['same'] ?? 0) === 1;
    }

    /**
     * Rapport d'une ligne, sans donnée libre non nettoyée (Domain\ImportRules::displayCell).
     */
    public static function describeRow(array $row): string
    {
        return sprintf(
            'contract %s, %s points, %s to %s, carry-over %d, label "%s"',
            ImportRules::displayCell($row['contract']),
            Money::format($row['cents']),
            $row['begin'],
            $row['end'],
            $row['carry'] ? 1 : 0,
            ImportRules::displayCell($row['label'])
        );
    }
}
