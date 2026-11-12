<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\DBAL\QueryFunction;
use GlpiPlugin\Points\Domain\ContractCache;
use GlpiPlugin\Points\Domain\Dates;
use GlpiPlugin\Points\Domain\LotRules;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\OpType;
use GlpiPlugin\Points\Domain\RuleViolation;
use Session;

/**
 * Service unique d'écriture du journal des points (docs/conception.md, « Modèle de données » et règles 5, 6, 8).
 *
 * Le journal (operations, lots, allocations) est en AJOUT SEUL : ce service est le seul à y écrire, par
 * `$DB->insert`, jamais par update ni delete. Les classes CommonDBTM du plugin refusent toute écriture.
 *
 * Chaque écriture se fait dans une transaction qui verrouille la ligne du contrat (glpi_plugin_points_contracts) :
 * deux débits du même contrat s'exécutent l'un après l'autre. Le verrou est pris par un UPDATE de `date_mod` sur
 * cette ligne : le critère SQL de GLPI ($DB->request) ne sait pas écrire `FOR UPDATE` (src/DBmysqlIterator.php,
 * aucune clause de verrou), et le plugin n'écrit pas de SQL brut hors DDL. Un UPDATE pose le verrou d'écriture de
 * la ligne jusqu'au commit, même si la valeur ne change pas ; les lectures qui suivent voient donc l'état validé
 * par la transaction précédente. Appelé depuis une transaction déjà ouverte (nesting par point de sauvegarde,
 * src/DBmysql.php:1723), le verrou est pris sur le même principe, mais l'instantané de lecture de la transaction
 * englobante peut précéder le verrou : l'appelant ouvre sa transaction avant toute lecture du journal.
 *
 * Montants : centièmes entiers (Domain\Money), jamais de flottant. Dates métier : AAAA-MM-JJ (ou horodatage).
 * Refus de règle : Domain\RuleViolation (Ledger::describe() donne le message traduit).
 */
final class Ledger
{
    private const CONTRACTS   = 'glpi_plugin_points_contracts';
    private const ENVELOPES   = 'glpi_plugin_points_envelopes';
    private const OPERATIONS  = 'glpi_plugin_points_operations';
    private const LOTS        = 'glpi_plugin_points_lots';
    private const ALLOCATIONS = 'glpi_plugin_points_allocations';

    /** Taille des paquets pour les requêtes `IN (…)` du contrôle d'invariants. */
    private const CHUNK = 500;

    // ---------------------------------------------------------------------------------------------------------
    // Écritures publiques
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Nouvelle enveloppe avec son crédit initial : enveloppe + opération `credit` + lot (origine = début de l'enveloppe).
     *
     * @param int $credit centièmes, > 0
     * @param bool $reportable les lots échus de l'enveloppe se reportent sur l'enveloppe suivante (règle 8)
     * @return array{envelopes_id: int, operations_id: int, lots_id: int}
     * @throws RuleViolation
     */
    public static function createEnvelope(int $contractsId, string $name, int $credit, string $begin, string $end, bool $reportable, string $comment = ''): array
    {
        if ($credit <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'The initial credit must be positive');
        }
        LotRules::checkEnvelope($begin, $end);
        $name = trim($name) !== '' ? trim($name) : $begin . ' / ' . $end;

        return self::transaction($contractsId, static function (array $contract) use ($name, $credit, $begin, $end, $reportable, $comment): array {
            global $DB;

            $now = self::now();
            $DB->insert(self::ENVELOPES, [
                'entities_id'   => $contract['entities_id'],
                'is_recursive'  => 0,
                'contracts_id'  => $contract['contracts_id'],
                'name'          => $name,
                'credit'        => Money::format($credit),
                'begin_date'    => $begin,
                'end_date'      => $end,
                'is_carryover'  => $reportable ? 1 : 0,
                'users_id'      => self::userId(),
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
            $envelopeId = (int) $DB->insertId();
            $operationId = self::addOperation($contract, OpType::CREDIT, $credit, $begin, [
                'itemtype' => Envelope::class,
                'items_id' => $envelopeId,
                'comment'  => $comment,
            ]);
            $lotId = self::addLot($contract, $operationId, $envelopeId, $begin, LotRules::expiryDate($end, $begin, self::retention()), $credit);
            self::addAllocation($contract, $operationId, $lotId, $credit);

            return ['envelopes_id' => $envelopeId, 'operations_id' => $operationId, 'lots_id' => $lotId];
        });
    }

    /**
     * Crédit additionnel sur une enveloppe : une opération et un nouveau lot.
     * Options : `type` (credit par défaut, ou import), `origin_date` (date d'achat : défaut aujourd'hui ramené dans
     * l'enveloppe ; explicite, elle doit être dans l'enveloppe), `comment`, `details`.
     *
     * @param int $cents centièmes, > 0
     * @param array{type?: string, origin_date?: string, comment?: string, details?: array<string, mixed>} $opts
     * @return array{operations_id: int, lots_id: int}
     * @throws RuleViolation
     */
    public static function credit(int $contractsId, int $envelopesId, int $cents, array $opts = []): array
    {
        if ($cents <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A credit must be positive');
        }
        $type = $opts['type'] ?? OpType::CREDIT;
        if (!in_array($type, [OpType::CREDIT, OpType::IMPORT], true)) {
            throw new RuleViolation(RuleViolation::BAD_TYPE, 'A credit has type credit or import');
        }

        return self::transaction($contractsId, static function (array $contract) use ($envelopesId, $cents, $type, $opts): array {
            $envelope = self::envelope($contract['contracts_id'], $envelopesId);
            if ($type !== OpType::IMPORT && $envelope['end_date'] < self::today()) {
                throw new RuleViolation(RuleViolation::ENVELOPE_ENDED, 'The envelope is already over');
            }
            $clamped  = Dates::min(self::today(), $envelope['end_date']);
            $origin   = isset($opts['origin_date']) ? self::day($opts['origin_date']) : ($clamped < $envelope['begin_date'] ? $envelope['begin_date'] : $clamped);
            if ($origin < $envelope['begin_date'] || $origin > $envelope['end_date']) {
                throw new RuleViolation(RuleViolation::CREDIT_OUT_OF_RANGE, 'The purchase date is outside the envelope');
            }
            $operationId = self::addOperation($contract, $type, $cents, $origin, [
                'itemtype' => Envelope::class,
                'items_id' => $envelopesId,
                'comment'  => $opts['comment'] ?? '',
                'details'  => $opts['details'] ?? null,
            ]);
            $lotId = self::addLot($contract, $operationId, $envelopesId, $origin, LotRules::expiryDate($envelope['end_date'], $origin, self::retention()), $cents);
            self::addAllocation($contract, $operationId, $lotId, $cents);

            return ['operations_id' => $operationId, 'lots_id' => $lotId];
        });
    }

    /**
     * Débit générique réparti en FIFO (règle 5). Utilisé par les débits de tâche et de forfait et par les ajustements.
     * Options : `itemtype`, `items_id`, `tickets_id`, `comment`, `details`, `operations_id_origin`,
     * `allow_overdraft` (défaut : paramètre du plugin).
     *
     * @param int $cents montant du débit, positif
     * @param string $date date métier
     * @param array<string, mixed> $opts
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>, overdraft: int}
     * @throws RuleViolation
     */
    public static function debit(int $contractsId, string $type, int $cents, string $date, array $opts = []): array
    {
        if (!in_array($type, OpType::debitTypes(), true)) {
            throw new RuleViolation(RuleViolation::BAD_TYPE, 'Not a debit type: ' . $type);
        }
        $day = self::day($date);
        if ($cents <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A debit amount must be positive');
        }

        return self::transaction($contractsId, static fn (array $contract): array => self::writeDebit($contract, $type, $cents, $date, $day, $opts));
    }

    /**
     * Correction (ADV) liée à une opération d'origine, motif obligatoire.
     * Montant négatif : débit supplémentaire en FIFO, qui exige un solde suffisant (jamais de dépassement).
     * Montant positif : remboursement, l'opération d'origine doit être un débit ; rendu à ses lots dans l'ordre inverse,
     * sans dépasser ce qu'elle a pris.
     *
     * @param int $cents signé, non nul
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>}
     * @throws RuleViolation
     */
    public static function correction(int $contractsId, int $originOperationId, int $cents, string $reason, ?string $date = null): array
    {
        if (trim($reason) === '') {
            throw new RuleViolation(RuleViolation::REASON_REQUIRED, 'A reason is required for a correction');
        }
        if ($cents === 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A correction cannot be zero');
        }
        $date = $date ?? self::today();
        $day  = self::day($date);

        return self::transaction($contractsId, static function (array $contract) use ($originOperationId, $cents, $reason, $date, $day): array {
            $origin = self::operation($contract['contracts_id'], $originOperationId);
            $opts   = ['comment' => trim($reason), 'operations_id_origin' => $originOperationId, 'details' => ['origin_type' => $origin['type']]];
            if ($cents < 0) {
                $done = self::writeDebit($contract, OpType::CORRECTION, -$cents, $date, $day, $opts + ['allow_overdraft' => false]);

                return ['operations_id' => $done['operations_id'], 'allocations' => $done['allocations']];
            }

            if (!LotRules::isDebitOrigin($origin['type'], $origin['amount'])) {
                throw new RuleViolation(RuleViolation::NOT_A_DEBIT, 'A positive correction can only target a debit');
            }
            $originAllocations = self::allocationsOf($originOperationId);
            $allocations       = LotRules::refund($originAllocations, self::refundedByLot($originOperationId), $cents);
            $operationId       = self::addOperation($contract, OpType::CORRECTION, $cents, $date, $opts);
            foreach ($allocations as $allocation) {
                self::addAllocation($contract, $operationId, $allocation['lot'], $allocation['amount']);
            }

            return ['operations_id' => $operationId, 'allocations' => $allocations];
        });
    }

    /**
     * Remboursement lié à un débit d'origine, aux lots de ce débit dans l'ordre inverse (utilisé par les compensations).
     * Type `compensation` ou `correction` ; le motif est obligatoire.
     *
     * @param int $cents positif
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>}
     * @throws RuleViolation
     */
    public static function refund(int $contractsId, int $originOperationId, int $cents, string $type, string $date, string $reason, array $opts = []): array
    {
        if (!in_array($type, [OpType::COMPENSATION, OpType::CORRECTION], true)) {
            throw new RuleViolation(RuleViolation::BAD_TYPE, 'A refund has type compensation or correction');
        }
        if (trim($reason) === '') {
            throw new RuleViolation(RuleViolation::REASON_REQUIRED, 'A reason is required for a refund');
        }
        self::day($date);

        return self::transaction($contractsId, static function (array $contract) use ($originOperationId, $cents, $type, $date, $reason, $opts): array {
            $origin = self::operation($contract['contracts_id'], $originOperationId);
            if (!LotRules::isDebitOrigin($origin['type'], $origin['amount'])) {
                throw new RuleViolation(RuleViolation::NOT_A_DEBIT, 'A refund can only target a debit');
            }
            $allocations = LotRules::refund(self::allocationsOf($originOperationId), self::refundedByLot($originOperationId), $cents);
            $operationId = self::addOperation($contract, $type, $cents, $date, [
                'comment'              => trim($reason),
                'operations_id_origin' => $originOperationId,
                'itemtype'             => $opts['itemtype'] ?? null,
                'items_id'             => $opts['items_id'] ?? 0,
                'tickets_id'           => $opts['tickets_id'] ?? 0,
                'details'              => $opts['details'] ?? null,
            ]);
            foreach ($allocations as $allocation) {
                self::addAllocation($contract, $operationId, $allocation['lot'], $allocation['amount']);
            }

            return ['operations_id' => $operationId, 'allocations' => $allocations];
        });
    }

    /**
     * Report manuel (ADV) des lots échus d'une enveloppe reportable vers une autre, même règle que le report
     * automatique (règle 8) : chaque lot échu au reste > 0 est reporté si son échéance, recalculée sur l'enveloppe cible, prolonge la sienne ;
     * un lot qui ne se reporterait pas (limite de 2 ans) n'est pas touché : il expirera par l'action automatique.
     *
     * @return array{carried: int, lots: int, skipped: int} centièmes reportés, lots reportés, lots non reportables
     * @throws RuleViolation
     */
    public static function carryover(int $contractsId, int $fromEnvelopeId, int $toEnvelopeId, ?string $date = null): array
    {
        $day = self::day($date ?? self::today());
        if ($fromEnvelopeId === $toEnvelopeId) {
            throw new RuleViolation(RuleViolation::BAD_TARGET, 'The target envelope must differ from the source');
        }

        return self::transaction($contractsId, static function (array $contract) use ($fromEnvelopeId, $toEnvelopeId, $day): array {
            $source = self::envelope($contract['contracts_id'], $fromEnvelopeId);
            if (!$source['is_carryover']) {
                throw new RuleViolation(RuleViolation::NOT_REPORTABLE, 'The source envelope is not carried over');
            }
            $target = self::envelope($contract['contracts_id'], $toEnvelopeId);
            if ($target['end_date'] <= $day) {
                throw new RuleViolation(RuleViolation::BAD_TARGET, 'The target envelope is already over');
            }
            $result = ['carried' => 0, 'lots' => 0, 'skipped' => 0];
            foreach (self::loadLots($contract['contracts_id']) as $lot) {
                // Même règle que la tâche (règle 8) : seuls les lots échus se reportent
                if ($lot['envelope'] !== $fromEnvelopeId || $lot['remaining'] <= 0 || $lot['expiry_date'] >= $day) {
                    continue;
                }
                $plan = LotRules::carryoverPlan($lot, $target['end_date'], $day, self::retention());
                if (!$plan['carry']) {
                    $result['skipped']++;
                    continue;
                }
                self::writeCarryover($contract, $lot, $target, $plan['expiry'], $day);
                $result['carried'] += $lot['remaining'];
                $result['lots']++;
            }

            return $result;
        });
    }

    /**
     * Expiration et report des lots échus avant une date (action automatique `pointsexpire`, règle 8) : pour chaque
     * lot échu au reste > 0, report si son enveloppe est reportable et qu'une enveloppe suivante permet de le
     * prolonger, sinon expiration. Un contrat par transaction : un échec n'arrête pas les autres contrats.
     *
     * @return array{expired: int, carried: int, lots: int, errors: list<string>} centièmes expirés et reportés
     */
    public static function expireDue(string $date): array
    {
        global $DB;

        $day    = Dates::check($date);
        $result = ['expired' => 0, 'carried' => 0, 'lots' => 0, 'errors' => []];
        $contracts = [];
        foreach ($DB->request(['SELECT' => ['contracts_id'], 'DISTINCT' => true, 'FROM' => self::LOTS, 'WHERE' => ['expiry_date' => ['<', $day]]]) as $row) {
            $contracts[] = (int) $row['contracts_id'];
        }
        foreach ($contracts as $contractsId) {
            // Filtre avant transaction : un contrat sans lot échu au reste > 0 n'est ni verrouillé ni écrit
            if (LotRules::dueLots(self::loadLots($contractsId), $day) === []) {
                continue;
            }
            try {
                $done = self::transaction($contractsId, static function (array $contract) use ($day): array {
                    $partial   = ['expired' => 0, 'carried' => 0, 'lots' => 0];
                    $due       = LotRules::dueLots(self::loadLots($contract['contracts_id']), $day);
                    if ($due === []) {
                        // Revérifié sous verrou : plus rien à faire (un autre passage est venu avant) ; l'annulation de la
                        // transaction défait aussi le verrou, rien n'est écrit
                        throw new NothingToDo();
                    }
                    $envelopes = self::envelopes($contract['contracts_id']);
                    $byId      = array_column($envelopes, null, 'id');
                    usort($due, static fn (array $a, array $b): int => [$a['expiry_date'], $a['id']] <=> [$b['expiry_date'], $b['id']]);
                    foreach ($due as $lot) {
                        $source = $byId[$lot['envelope']] ?? null;
                        $target = $source !== null && $source['is_carryover'] ? LotRules::nextEnvelope($envelopes, $source, $day) : null;
                        $plan   = $target !== null ? LotRules::carryoverPlan($lot, $target['end_date'], $day, self::retention()) : ['carry' => false, 'expiry' => ''];
                        if ($target !== null && $plan['carry']) {
                            self::writeCarryover($contract, $lot, $target, $plan['expiry'], $day);
                            $partial['carried'] += $lot['remaining'];
                        } else {
                            self::writeExpiry($contract, $lot, $day);
                            $partial['expired'] += $lot['remaining'];
                        }
                        $partial['lots']++;
                    }

                    return $partial;
                });
                $result['expired'] += $done['expired'];
                $result['carried'] += $done['carried'];
                $result['lots']    += $done['lots'];
            } catch (NothingToDo) {
                continue;
            } catch (\Throwable $e) {
                // Classe de l'exception seulement : le message peut contenir des valeurs SQL
                $result['errors'][] = sprintf('contract %d: %s', $contractsId, $e::class);
            }
        }

        return $result;
    }

    /**
     * Recalcule le cache du contrat (balance, credited, consumed, date_cache) depuis le journal.
     *
     * @return array{balance: int, credited: int, consumed: int}
     */
    public static function recomputeCache(int $contractsId): array
    {
        return self::transaction($contractsId, static fn (array $contract): array => self::computeCache($contract['contracts_id']));
    }

    /**
     * Reste de points par enveloppe (somme des restes de ses lots, centièmes), pour l'affichage.
     *
     * @return array<int, int> enveloppe => reste
     */
    public static function remainingByEnvelope(int $contractsId): array
    {
        $out = [];
        foreach (self::loadLots($contractsId) as $lot) {
            $out[$lot['envelope']] = ($out[$lot['envelope']] ?? 0) + $lot['remaining'];
        }

        return $out;
    }

    /**
     * Cache du contrat lu en base (sans recalcul), en centièmes ; contrat sans ligne Points : zéros.
     *
     * @return array{balance: int, credited: int, consumed: int}
     */
    public static function cached(int $contractsId): array
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['balance', 'credited', 'consumed'], 'FROM' => self::CONTRACTS, 'WHERE' => ['contracts_id' => $contractsId], 'LIMIT' => 1])->current();

        return [
            'balance'  => Money::parse((string) ($row['balance'] ?? '0')),
            'credited' => Money::parse((string) ($row['credited'] ?? '0')),
            'consumed' => Money::parse((string) ($row['consumed'] ?? '0')),
        ];
    }

    /**
     * Invariants du journal pour un contrat (docs/conception.md) ; liste vide = tout est cohérent :
     * somme des montants d'une opération = somme de ses affectations ; affectations sur des lots du contrat ;
     * reste d'un lot >= 0 (sauf dépassement autorisé par le paramètre) ; solde du cache = somme du journal = somme
     * des restes des lots.
     *
     * @return list<string>
     */
    public static function check(int $contractsId): array
    {
        global $DB;

        $problems = [];
        $ops      = [];
        foreach ($DB->request(['SELECT' => ['id', 'amount'], 'FROM' => self::OPERATIONS, 'WHERE' => ['contracts_id' => $contractsId]]) as $row) {
            $ops[(int) $row['id']] = Money::parse((string) $row['amount']);
        }
        $lots     = self::loadLots($contractsId);
        $lotIds   = array_column($lots, 'id');
        $byOp     = array_fill_keys(array_keys($ops), 0);
        $foreign  = [];
        foreach (array_chunk(array_keys($ops), self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['operations_id', 'plugin_points_lots_id', 'amount'], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['operations_id' => $chunk]]) as $row) {
                $byOp[(int) $row['operations_id']] += Money::parse((string) $row['amount']);
                if (!in_array((int) $row['plugin_points_lots_id'], $lotIds, true)) {
                    $foreign[(int) $row['operations_id']] = true;
                }
            }
        }
        foreach ($ops as $id => $amount) {
            if ($byOp[$id] !== $amount) {
                $problems[] = sprintf('operation %d: amount %s, allocations %s', $id, Money::format($amount), Money::format($byOp[$id]));
            }
        }
        foreach (array_keys($foreign) as $id) {
            $problems[] = sprintf('operation %d: allocation on a lot of another contract', $id);
        }
        $overdraft = (int) Config::value('allow_overdraft') === 1;
        $sumLots   = 0;
        foreach ($lots as $lot) {
            $sumLots += $lot['remaining'];
            if ($lot['remaining'] < 0 && !$overdraft) {
                $problems[] = sprintf('lot %d: negative remainder %s', $lot['id'], Money::format($lot['remaining']));
            }
        }
        $sumOps = array_sum($ops);
        if ($sumOps !== $sumLots) {
            $problems[] = sprintf('journal %s, lots %s', Money::format($sumOps), Money::format($sumLots));
        }
        $computed = self::computeCache($contractsId);
        $cached   = self::cached($contractsId);
        if ($cached !== $computed) {
            $problems[] = sprintf('cache %s, journal %s', Money::format($cached['balance']), Money::format($computed['balance']));
        }

        return $problems;
    }

    /**
     * Message traduit d'un refus de règle, pour l'interface.
     */
    public static function describe(RuleViolation $e): string
    {
        return match ($e->reason) {
            RuleViolation::INVALID_AMOUNT      => __('The amount is not valid', 'points'),
            RuleViolation::INVALID_DATE        => __('A date is not valid', 'points'),
            RuleViolation::ENVELOPE_DATES      => __('The envelope begin date must not be after its end date', 'points'),
            RuleViolation::ENVELOPE_TOO_LONG   => __('An envelope cannot last more than 24 months', 'points'),
            RuleViolation::NO_VALID_LOT        => __('No envelope is valid at this date', 'points'),
            RuleViolation::INSUFFICIENT        => __('Not enough points available', 'points'),
            RuleViolation::NOTHING_TO_REFUND   => __('The amount exceeds what the original debit can give back', 'points'),
            RuleViolation::REASON_REQUIRED     => __('A reason is required', 'points'),
            RuleViolation::UNKNOWN_CONTRACT    => __('Unknown contract', 'points'),
            RuleViolation::UNKNOWN_ENVELOPE    => __('Unknown envelope for this contract', 'points'),
            RuleViolation::UNKNOWN_OPERATION   => __('Unknown operation for this contract', 'points'),
            RuleViolation::BAD_TARGET          => __('The target envelope is not valid for a carry-over', 'points'),
            RuleViolation::BAD_TYPE            => __('Operation type not allowed here', 'points'),
            RuleViolation::CREDIT_OUT_OF_RANGE => __('The purchase date must be inside the envelope', 'points'),
            RuleViolation::ENVELOPE_ENDED      => __('The envelope is already over: no credit can be added', 'points'),
            RuleViolation::NOT_REPORTABLE      => __('The source envelope is not marked as carried over', 'points'),
            RuleViolation::NOT_A_DEBIT         => __('Only a debit can be given back or corrected upwards', 'points'),
            default                            => $e->getMessage(),
        };
    }

    /**
     * Ligne Points du contrat, créée si elle manque (alerte par défaut du paramétrage, entité du contrat natif).
     * Rejouable et tolérante à la concurrence : une insertion perdue relit la ligne gagnante.
     *
     * @return array{id: int, contracts_id: int, entities_id: int}
     * @throws RuleViolation si le contrat natif n'existe pas
     */
    public static function ensureContract(int $contractsId): array
    {
        global $DB;

        $read = static function () use ($DB, $contractsId): ?array {
            $row = $DB->request(['SELECT' => ['id', 'contracts_id', 'entities_id'], 'FROM' => self::CONTRACTS, 'WHERE' => ['contracts_id' => $contractsId], 'LIMIT' => 1])->current();

            return $row === null ? null : ['id' => (int) $row['id'], 'contracts_id' => (int) $row['contracts_id'], 'entities_id' => (int) $row['entities_id']];
        };
        $existing = $read();
        if ($existing !== null) {
            return $existing;
        }
        $native = $DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_contracts', 'WHERE' => ['id' => $contractsId], 'LIMIT' => 1])->current();
        if ($native === null) {
            throw new RuleViolation(RuleViolation::UNKNOWN_CONTRACT, 'Unknown contract');
        }
        $now = self::now();
        try {
            $DB->insert(self::CONTRACTS, [
                'entities_id'     => (int) $native['entities_id'],
                'is_recursive'    => 0,
                'contracts_id'    => $contractsId,
                'alert_threshold' => (int) Config::value('default_alert_threshold'),
                'is_active'       => 1,
                'date_cache'      => $now,
                'date_creation'   => $now,
                'date_mod'        => $now,
            ]);
        } catch (\Throwable $e) {
            // Insertion concurrente : la ligne unique (contracts_id) existe maintenant
            if ($read() === null) {
                throw $e;
            }
        }

        return $read() ?? throw new \RuntimeException('Points contract row not created');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Transaction et verrou
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @template T
     * @param callable(array{id: int, contracts_id: int, entities_id: int}): T $work
     * @return T
     */
    private static function transaction(int $contractsId, callable $work): mixed
    {
        global $DB;

        $row = self::ensureContract($contractsId);
        $DB->beginTransaction();
        try {
            // Verrou d'écriture de la ligne du contrat jusqu'au commit (voir l'en-tête de la classe)
            $DB->update(self::CONTRACTS, ['date_mod' => self::now()], ['id' => $row['id']]);
            $result = $work($row);
            self::writeCache($row);
            $DB->commit();

            return $result;
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Écriture interne (toujours dans transaction())
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array<string, mixed> $opts
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>, overdraft: int}
     */
    private static function writeDebit(array $contract, string $type, int $cents, string $date, string $day, array $opts): array
    {
        $allowOverdraft = array_key_exists('allow_overdraft', $opts) ? (bool) $opts['allow_overdraft'] : (int) Config::value('allow_overdraft') === 1;
        // Débit tardif (règle 5) : les points déjà expirés d'un lot encore valide à la date métier comptent comme disponibles ;
        // ceux que le débit prend sont d'abord repris (expiry_reversal), le client ne paie jamais un point expiré et débité
        $lots     = self::loadLots($contract['contracts_id']);
        $capacity = self::expiredCapacities($contract['contracts_id']);
        $expired  = [];
        $effective = [];
        foreach ($lots as $lot) {
            $expired[$lot['id']] = array_sum(array_column($capacity[$lot['id']] ?? [], 'capacity'));
            $effective[] = ['remaining' => $lot['remaining'] + $expired[$lot['id']]] + $lot;
        }
        $plan      = LotRules::allocateDebit($effective, $day, $cents, $allowOverdraft);
        $byLot     = [];
        foreach ($lots as $lot) {
            $byLot[$lot['id']] = ['remaining' => $lot['remaining'], 'expired' => $expired[$lot['id']]];
        }
        foreach (LotRules::lateReversals($plan['allocations'], $byLot) as $lotId => $left) {
            foreach ($capacity[$lotId] as $expiry) {
                $take = min($left, $expiry['capacity']);
                if ($take <= 0) {
                    continue;
                }
                $reversal = self::addOperation($contract, OpType::EXPIRY_REVERSAL, $take, $day, [
                    'itemtype'             => Envelope::class,
                    'items_id'             => $expiry['envelope'],
                    'operations_id_origin' => $expiry['operation'],
                    'details'              => ['lot' => $lotId],
                ]);
                self::addAllocation($contract, $reversal, $lotId, $take);
                $left -= $take;
            }
        }
        $details        = $opts['details'] ?? [];
        if ($plan['overdraft'] > 0) {
            $details['overdraft'] = Money::format($plan['overdraft']);
        }
        $operationId = self::addOperation($contract, $type, -$cents, $date, [
            'itemtype'             => $opts['itemtype'] ?? null,
            'items_id'             => $opts['items_id'] ?? 0,
            'tickets_id'           => $opts['tickets_id'] ?? 0,
            'operations_id_origin' => $opts['operations_id_origin'] ?? 0,
            'comment'              => $opts['comment'] ?? '',
            'details'              => $details === [] ? null : $details,
        ]);
        foreach ($plan['allocations'] as $allocation) {
            self::addAllocation($contract, $operationId, $allocation['lot'], $allocation['amount']);
        }

        return ['operations_id' => $operationId, 'allocations' => $plan['allocations'], 'overdraft' => $plan['overdraft']];
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array{id: int, origin_date: string, expiry_date: string, remaining: int, envelope: int} $lot
     */
    private static function writeExpiry(array $contract, array $lot, string $day): void
    {
        $operationId = self::addOperation($contract, OpType::EXPIRY, -$lot['remaining'], $day, [
            'itemtype' => Envelope::class,
            'items_id' => $lot['envelope'],
            'details'  => ['lot' => $lot['id'], 'origin_date' => $lot['origin_date'], 'expiry_date' => $lot['expiry_date']],
        ]);
        self::addAllocation($contract, $operationId, $lot['id'], -$lot['remaining']);
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array{id: int, origin_date: string, expiry_date: string, remaining: int, envelope: int} $lot
     * @param array{id: int, begin_date: string, end_date: string, is_carryover: bool} $target
     */
    private static function writeCarryover(array $contract, array $lot, array $target, string $newExpiry, string $day): void
    {
        $out = self::addOperation($contract, OpType::CARRYOVER_OUT, -$lot['remaining'], $day, [
            'itemtype' => Envelope::class,
            'items_id' => $lot['envelope'],
            'details'  => ['lot' => $lot['id'], 'to_envelope' => $target['id']],
        ]);
        self::addAllocation($contract, $out, $lot['id'], -$lot['remaining']);
        $in = self::addOperation($contract, OpType::CARRYOVER_IN, $lot['remaining'], $day, [
            'itemtype'             => Envelope::class,
            'items_id'             => $target['id'],
            'operations_id_origin' => $out,
            'details'              => ['lot' => $lot['id'], 'from_envelope' => $lot['envelope']],
        ]);
        $newLot = self::addLot($contract, $in, $target['id'], $lot['origin_date'], $newExpiry, $lot['remaining']);
        self::addAllocation($contract, $in, $newLot, $lot['remaining']);
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array<string, mixed> $o
     */
    private static function addOperation(array $contract, string $type, int $cents, string $date, array $o): int
    {
        global $DB;

        $details = $o['details'] ?? null;
        // operations.date est un DATE : l'heure éventuelle de la date métier va dans les détails
        if (strlen(trim($date)) > 10) {
            $details = ($details ?? []) + ['time' => substr(trim($date), 11)];
        }
        $DB->insert(self::OPERATIONS, [
            'entities_id'          => $contract['entities_id'],
            'is_recursive'         => 0,
            'contracts_id'         => $contract['contracts_id'],
            'type'                 => $type,
            'amount'               => Money::format($cents),
            'date'                 => self::day($date),
            'tickets_id'           => (int) ($o['tickets_id'] ?? 0),
            'itemtype'             => $o['itemtype'] ?? null,
            'items_id'             => (int) ($o['items_id'] ?? 0),
            'operations_id_origin' => (int) ($o['operations_id_origin'] ?? 0),
            'comment'              => ($o['comment'] ?? '') === '' ? null : (string) $o['comment'],
            'details'              => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'users_id'             => self::userId(),
            'date_creation'        => self::now(),
        ]);

        return (int) $DB->insertId();
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     */
    private static function addLot(array $contract, int $operationId, int $envelopeId, string $origin, string $expiry, int $initial): int
    {
        global $DB;

        $DB->insert(self::LOTS, [
            'entities_id'                => $contract['entities_id'],
            'is_recursive'               => 0,
            'operations_id'              => $operationId,
            'plugin_points_envelopes_id' => $envelopeId,
            'contracts_id'               => $contract['contracts_id'],
            'origin_date'                => $origin,
            'expiry_date'                => $expiry,
            'initial'                    => Money::format($initial),
            'date_creation'              => self::now(),
        ]);

        return (int) $DB->insertId();
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     */
    private static function addAllocation(array $contract, int $operationId, int $lotId, int $cents): void
    {
        global $DB;

        $DB->insert(self::ALLOCATIONS, [
            'entities_id'           => $contract['entities_id'],
            'is_recursive'          => 0,
            'operations_id'         => $operationId,
            'plugin_points_lots_id' => $lotId,
            'amount'                => Money::format($cents),
            'date_creation'         => self::now(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Lectures
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Lots d'un contrat avec leur reste (somme de leurs affectations), centièmes.
     *
     * @return list<array{id: int, origin_date: string, expiry_date: string, remaining: int, envelope: int}>
     */
    private static function loadLots(int $contractsId): array
    {
        global $DB;

        $lots = [];
        foreach ($DB->request(['SELECT' => ['id', 'origin_date', 'expiry_date', 'plugin_points_envelopes_id'], 'FROM' => self::LOTS, 'WHERE' => ['contracts_id' => $contractsId], 'ORDER' => ['origin_date', 'id']]) as $row) {
            $lots[(int) $row['id']] = [
                'id'          => (int) $row['id'],
                'origin_date' => (string) $row['origin_date'],
                'expiry_date' => (string) $row['expiry_date'],
                'remaining'   => 0,
                'envelope'    => (int) $row['plugin_points_envelopes_id'],
            ];
        }
        foreach (array_chunk(array_keys($lots), self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['plugin_points_lots_id', QueryFunction::sum('amount', false, 'total')], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['plugin_points_lots_id' => $chunk], 'GROUPBY' => 'plugin_points_lots_id']) as $row) {
                $lots[(int) $row['plugin_points_lots_id']]['remaining'] = Money::parse((string) $row['total']);
            }
        }

        return array_values($lots);
    }

    /**
     * @return list<array{id: int, begin_date: string, end_date: string, is_carryover: bool}>
     */
    private static function envelopes(int $contractsId): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'begin_date', 'end_date', 'is_carryover'], 'FROM' => self::ENVELOPES, 'WHERE' => ['contracts_id' => $contractsId], 'ORDER' => ['begin_date', 'id']]) as $row) {
            $out[] = ['id' => (int) $row['id'], 'begin_date' => (string) $row['begin_date'], 'end_date' => (string) $row['end_date'], 'is_carryover' => (int) $row['is_carryover'] === 1];
        }

        return $out;
    }

    /**
     * @return array{id: int, begin_date: string, end_date: string, is_carryover: bool}
     * @throws RuleViolation si l'enveloppe n'est pas à ce contrat
     */
    private static function envelope(int $contractsId, int $envelopeId): array
    {
        foreach (self::envelopes($contractsId) as $envelope) {
            if ($envelope['id'] === $envelopeId) {
                return $envelope;
            }
        }
        throw new RuleViolation(RuleViolation::UNKNOWN_ENVELOPE, 'Unknown envelope for this contract');
    }

    /**
     * @return array{id: int, type: string, amount: int}
     * @throws RuleViolation si l'opération n'est pas à ce contrat
     */
    private static function operation(int $contractsId, int $operationId): array
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['id', 'type', 'amount'], 'FROM' => self::OPERATIONS, 'WHERE' => ['id' => $operationId, 'contracts_id' => $contractsId], 'LIMIT' => 1])->current();
        if ($row === null) {
            throw new RuleViolation(RuleViolation::UNKNOWN_OPERATION, 'Unknown operation for this contract');
        }

        return ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'amount' => Money::parse((string) $row['amount'])];
    }

    /**
     * Affectations d'une opération, dans l'ordre d'écriture.
     *
     * @return list<array{lot: int, amount: int}>
     */
    private static function allocationsOf(int $operationId): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['plugin_points_lots_id', 'amount'], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['operations_id' => $operationId], 'ORDER' => 'id']) as $row) {
            $out[] = ['lot' => (int) $row['plugin_points_lots_id'], 'amount' => Money::parse((string) $row['amount'])];
        }

        return $out;
    }

    /**
     * Ce qui a déjà été rendu aux lots d'un débit : affectations positives des opérations qui lui sont liées.
     *
     * @return array<int, int> lot => centièmes
     */
    private static function refundedByLot(int $originOperationId): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['operations_id_origin' => $originOperationId, 'type' => [OpType::CORRECTION, OpType::COMPENSATION]]]) as $row) {
            $ids[] = (int) $row['id'];
        }
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['plugin_points_lots_id', 'amount'], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['operations_id' => $chunk, 'amount' => ['>', 0]]]) as $row) {
                $out[(int) $row['plugin_points_lots_id']] = ($out[(int) $row['plugin_points_lots_id']] ?? 0) + Money::parse((string) $row['amount']);
            }
        }

        return $out;
    }

    /**
     * Points expirés d'un contrat pas encore repris, par lot : un élément par opération `expiry`, avec ce qui reste à
     * reprendre (expiré moins ce que les `expiry_reversal` liées ont déjà repris).
     *
     * @return array<int, list<array{operation: int, envelope: int, capacity: int}>> lot => opérations d'expiration
     */
    private static function expiredCapacities(int $contractsId): array
    {
        global $DB;

        $expiry = [];
        foreach ($DB->request(['SELECT' => ['id', 'items_id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['contracts_id' => $contractsId, 'type' => OpType::EXPIRY], 'ORDER' => 'id']) as $row) {
            $expiry[(int) $row['id']] = (int) $row['items_id'];
        }
        if ($expiry === []) {
            return [];
        }
        $reversed = [];
        foreach (array_chunk(array_keys($expiry), self::CHUNK) as $chunk) {
            $ids = [];
            foreach ($DB->request(['SELECT' => ['id', 'operations_id_origin'], 'FROM' => self::OPERATIONS, 'WHERE' => ['type' => OpType::EXPIRY_REVERSAL, 'operations_id_origin' => $chunk]]) as $row) {
                $ids[(int) $row['id']] = (int) $row['operations_id_origin'];
            }
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $part) {
                foreach ($DB->request(['SELECT' => ['operations_id', 'amount'], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['operations_id' => $part]]) as $row) {
                    $origin            = $ids[(int) $row['operations_id']];
                    $reversed[$origin] = ($reversed[$origin] ?? 0) + Money::parse((string) $row['amount']);
                }
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($expiry), self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['operations_id', 'plugin_points_lots_id', 'amount'], 'FROM' => self::ALLOCATIONS, 'WHERE' => ['operations_id' => $chunk], 'ORDER' => 'id']) as $row) {
                $operation = (int) $row['operations_id'];
                $capacity  = -Money::parse((string) $row['amount']) - ($reversed[$operation] ?? 0);
                if ($capacity > 0) {
                    $out[(int) $row['plugin_points_lots_id']][] = ['operation' => $operation, 'envelope' => $expiry[$operation], 'capacity' => $capacity];
                }
            }
        }

        return $out;
    }

    /**
     * Cache calculé depuis le journal (Domain\ContractCache : balance, credited, consumed).
     *
     * @return array{balance: int, credited: int, consumed: int}
     */
    private static function computeCache(int $contractsId): array
    {
        global $DB;

        $totals = [];
        foreach ($DB->request(['SELECT' => ['type', QueryFunction::sum('amount', false, 'total')], 'FROM' => self::OPERATIONS, 'WHERE' => ['contracts_id' => $contractsId], 'GROUPBY' => 'type']) as $row) {
            $totals[(string) $row['type']] = Money::parse((string) ($row['total'] ?? '0'));
        }

        return ContractCache::compute($totals);
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     */
    private static function writeCache(array $contract): void
    {
        global $DB;

        $cache = self::computeCache($contract['contracts_id']);
        $now   = self::now();
        $DB->update(self::CONTRACTS, [
            'balance'    => Money::format($cache['balance']),
            'credited'   => Money::format($cache['credited']),
            'consumed'   => Money::format($cache['consumed']),
            'date_cache' => $now,
            'date_mod'   => $now,
        ], ['id' => $contract['id']]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Contexte
    // ---------------------------------------------------------------------------------------------------------

    private static function retention(): int
    {
        return (int) Config::value('retention_months');
    }

    private static function now(): string
    {
        return Session::getCurrentTime() ?? date('Y-m-d H:i:s');
    }

    private static function today(): string
    {
        return substr(self::now(), 0, 10);
    }

    private static function userId(): int
    {
        return (int) Session::getLoginUserID();
    }

    private static function day(string $date): string
    {
        try {
            return Dates::day($date);
        } catch (\InvalidArgumentException) {
            throw new RuleViolation(RuleViolation::INVALID_DATE, 'Invalid date');
        }
    }
}
