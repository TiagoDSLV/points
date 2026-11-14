<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\DBAL\QueryFunction;
use GlpiPlugin\Points\Domain\AlertRules;
use GlpiPlugin\Points\Domain\BalanceRules;
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

        return self::transaction($contractsId, static fn (array $contract): array => self::writeEnvelope($contract, OpType::CREDIT, $name, $credit, $begin, $end, $reportable, $comment));
    }

    /**
     * Import d'un solde du BPM Kiwi (commande `plugins:points:import`) : une enveloppe et une opération de type `import` (au lieu de
     * `credit`) avec son lot. Rejouable : l'opération porte l'empreinte de la ligne (`details.import_key`, voir importKey) ; si une
     * opération `import` du contrat a déjà cette empreinte, rien n'est écrit (`skipped`). L'empreinte vit dans l'opération, que rien ne
     * modifie : renommer l'enveloppe (son nom reste modifiable) ne fait pas recréditer au rejeu. Le contrôle se fait SOUS le verrou du
     * contrat, dans la même transaction que l'écriture : deux lancements concurrents n'écrivent qu'une enveloppe. Sans utilisateur
     * connecté (console), l'auteur est 0.
     *
     * @param int $credit centièmes, > 0
     * @return array{skipped: true}|array{skipped: false, envelopes_id: int, operations_id: int, lots_id: int}
     * @throws RuleViolation
     */
    public static function importEnvelope(int $contractsId, string $name, int $credit, string $begin, string $end, bool $reportable, string $comment): array
    {
        if ($credit <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'The imported balance must be positive');
        }
        LotRules::checkEnvelope($begin, $end);
        $name = trim($name) !== '' ? trim($name) : $begin . ' / ' . $end;

        return self::transaction($contractsId, static function (array $contract) use ($name, $credit, $begin, $end, $reportable, $comment): array {
            if (self::importedEnvelope($contract['contracts_id'], $name, $credit, $begin, $end) !== null) {
                return ['skipped' => true];
            }
            $details = ['import_key' => self::importKey($contract['contracts_id'], $name, $credit, $begin, $end)];

            return ['skipped' => false] + self::writeEnvelope($contract, OpType::IMPORT, $name, $credit, $begin, $end, $reportable, $comment, $details);
        });
    }

    /**
     * Empreinte d'une ligne d'import : contrat (id), solde en centièmes, dates et libellé (sans casse), hachés.
     */
    public static function importKey(int $contractsId, string $name, int $credit, string $begin, string $end): string
    {
        return hash('sha256', implode("\x1F", [$contractsId, $credit, $begin, $end, mb_strtolower(trim($name))]));
    }

    /**
     * Opération `import` du contrat qui porte l'empreinte de cette ligne : l'id de son enveloppe, ou null. Lecture seule. Le nom
     * de l'enveloppe n'entre pas en jeu (il peut être renommé).
     */
    public static function importedEnvelope(int $contractsId, string $name, int $credit, string $begin, string $end): ?int
    {
        global $DB;

        $key = self::importKey($contractsId, $name, $credit, $begin, $end);
        $row = $DB->request(['SELECT' => ['items_id'], 'FROM' => self::OPERATIONS, 'WHERE' => [
            'contracts_id' => $contractsId, 'type' => OpType::IMPORT, 'details' => ['LIKE', '%"import_key":"' . $key . '"%'],
        ], 'LIMIT' => 1])->current();

        return $row === null ? null : (int) $row['items_id'];
    }

    /**
     * Opération `import` du contrat de même solde, même début et même fin d'enveloppe : l'id de l'enveloppe, ou null. Sert à repérer
     * une ligne dont seul le libellé a été corrigé (l'empreinte, elle, change avec le libellé). Renvoie l'enveloppe et l'empreinte de
     * l'opération trouvée. Lecture seule.
     *
     * @return array{envelope: int, key: string}|null
     */
    public static function similarImport(int $contractsId, int $credit, string $begin, string $end): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT'     => [self::OPERATIONS . '.items_id', self::OPERATIONS . '.details'],
            'FROM'       => self::OPERATIONS,
            'INNER JOIN' => [self::ENVELOPES => ['ON' => [self::OPERATIONS => 'items_id', self::ENVELOPES => 'id']]],
            'WHERE'      => [
                self::OPERATIONS . '.contracts_id' => $contractsId, self::OPERATIONS . '.type' => OpType::IMPORT,
                self::OPERATIONS . '.amount' => Money::format($credit), self::OPERATIONS . '.date' => $begin, self::ENVELOPES . '.end_date' => $end,
            ],
            'LIMIT'      => 1,
        ])->current();

        return $row === null ? null : ['envelope' => (int) $row['items_id'], 'key' => (string) (json_decode((string) $row['details'], true)['import_key'] ?? '')];
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
            if ($type !== OpType::IMPORT && $envelope['end_date'] < Billing::today()) {
                throw new RuleViolation(RuleViolation::ENVELOPE_ENDED, 'The envelope is already over');
            }
            $clamped  = Dates::min(Billing::today(), $envelope['end_date']);
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
     * `allow_overdraft` (défaut : paramètre du plugin), `unique` (true : refus ALREADY_DEBITED si un débit du même type,
     * itemtype et items_id existe déjà, même remboursé (le débit est définitif) ; revérifié SOUS le verrou du contrat, dans la
     * même transaction que l'écriture), `inside` (callable(int $operationsId) exécuté dans la même transaction, après
     * l'écriture : état annexe écrit atomiquement avec le débit).
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
     * Simulation en LECTURE SEULE d'une suite de débits (rien n'est écrit, aucun verrou) : lève le refus que
     * Ledger::debit lèverait au premier débit impossible (aucun lot valide, ou solde insuffisant si le dépassement est
     * interdit). Sert aux garde-fous qui refusent une action avant qu'elle n'ait lieu ; un débit concurrent peut encore
     * faire échouer le vrai débit, qui reste la seule écriture. Même calcul que writeDebit : les points expirés
     * récupérables par débit tardif comptent comme disponibles, chaque débit consomme les lots avant le suivant.
     *
     * @param list<array{0: int, 1: string}> $debits (montant en centièmes > 0, date métier), dans l'ordre d'exécution
     * @throws RuleViolation
     */
    public static function assertDebitable(int $contractsId, array $debits): void
    {
        $allowOverdraft = (int) Config::value('allow_overdraft') === 1;
        $capacity       = self::expiredCapacities($contractsId);
        $effective      = [];
        foreach (self::loadLots($contractsId) as $lot) {
            $effective[$lot['id']] = ['remaining' => $lot['remaining'] + array_sum(array_column($capacity[$lot['id']] ?? [], 'capacity'))] + $lot;
        }
        foreach ($debits as [$cents, $date]) {
            if ($cents <= 0) {
                throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A debit amount must be positive');
            }
            $plan = LotRules::allocateDebit(array_values($effective), self::day($date), $cents, $allowOverdraft);
            foreach ($plan['allocations'] as $allocation) {
                $effective[$allocation['lot']]['remaining'] += $allocation['amount'];
            }
        }
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
        $date = $date ?? Billing::today();
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
            return self::writeRefund($contract, $originOperationId, $cents, OpType::CORRECTION, $date, $opts);
        });
    }

    /**
     * Remboursement lié à un débit d'origine, aux lots de ce débit dans l'ordre inverse (utilisé par les compensations
     * et par les ajustements positifs de review). Type `compensation`, `correction` ou `review_adjustment` ; le motif est
     * obligatoire. Options : `itemtype`, `items_id`, `tickets_id`, `details`, `inside` (callable(int $operationsId)
     * exécuté dans la même transaction, après l'écriture : l'état annexe est écrit atomiquement avec l'opération ; une
     * exception de ce rappel annule tout).
     *
     * @param int $cents positif
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>}
     * @throws RuleViolation
     */
    public static function refund(int $contractsId, int $originOperationId, int $cents, string $type, string $date, string $reason, array $opts = []): array
    {
        if (!in_array($type, [OpType::COMPENSATION, OpType::CORRECTION, OpType::REVIEW_ADJUSTMENT], true)) {
            throw new RuleViolation(RuleViolation::BAD_TYPE, 'A refund has type compensation, correction or review_adjustment');
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

            return self::writeRefund($contract, $originOperationId, $cents, $type, $date, [
                'comment'    => trim($reason),
                'itemtype'   => $opts['itemtype'] ?? null,
                'items_id'   => $opts['items_id'] ?? 0,
                'tickets_id' => $opts['tickets_id'] ?? 0,
                'details'    => $opts['details'] ?? null,
                'inside'     => $opts['inside'] ?? null,
            ]);
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
        $day = self::day($date ?? Billing::today());
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
        // Seuls les contrats qui ont un lot échu au reste > 0 (somme de ses affectations) sont parcourus : les autres ne sont ni verrouillés
        // ni écrits. Le reste est relu sous le verrou, dans la transaction de chaque contrat.
        $contracts = [];
        foreach ($DB->request([
            'SELECT'     => [self::LOTS . '.contracts_id', QueryFunction::sum(self::ALLOCATIONS . '.amount', false, 'total')],
            'FROM'       => self::LOTS,
            'INNER JOIN' => [self::ALLOCATIONS => ['ON' => [self::LOTS => 'id', self::ALLOCATIONS => 'plugin_points_lots_id']]],
            'WHERE'      => [self::LOTS . '.expiry_date' => ['<', $day]],
            'GROUPBY'    => [self::LOTS . '.id', self::LOTS . '.contracts_id'],
            'HAVING'     => ['total' => ['>', 0]],
        ]) as $row) {
            $contracts[(int) $row['contracts_id']] = (int) $row['contracts_id'];
        }
        foreach ($contracts as $contractsId) {
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
     * Exécute une lecture-décision sous le verrou du contrat (même transaction et même verrou que les écritures du journal) : rien du journal
     * ne change pendant `$work`. Sert à l'alerte de seuil : relire les lots juste avant de lever l'événement. Le cache est réécrit à la fin.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function locked(int $contractsId, callable $work): mixed
    {
        return self::transaction($contractsId, static fn (array $contract): mixed => $work());
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
     * Ce qu'un débit peut encore rendre, en centièmes : ce qu'il a pris sur chaque lot moins TOUT ce qui lui a déjà été rendu
     * (corrections +, compensations, ajustements + de review). Même calcul que le plafond de `refund()` (LotRules::refund) ;
     * lecture seule, sans verrou : sert à la demande, à la pré-vérification et à l'affichage, le plafond réel reste celui de
     * `refund()` sous le verrou du contrat.
     */
    public static function refundable(int $originOperationId): int
    {
        $taken = [];
        foreach (self::allocationsOf($originOperationId) as $allocation) {
            if ($allocation['amount'] < 0) {
                $taken[$allocation['lot']] = ($taken[$allocation['lot']] ?? 0) - $allocation['amount'];
            }
        }
        $refunded = self::refundedByLot($originOperationId);
        $total    = 0;
        foreach ($taken as $lotId => $cents) {
            $total += max(0, $cents - ($refunded[$lotId] ?? 0));
        }

        return $total;
    }

    /**
     * Débit net de chaque opération de débit, en centièmes (positif = points consommés) : le montant de l'opération plus tout ce qui
     * lui est lié (corrections, compensations, ajustements de review, signés : un remboursement le réduit, un débit en plus
     * l'augmente). Seule définition du « débit net d'une tâche » : l'onglet du ticket l'affiche, aucune règle du journal ailleurs.
     *
     * @param list<int> $operationIds
     * @return array<int, int> opération => débit net
     */
    public static function netDebits(array $operationIds): array
    {
        global $DB;

        $net = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $operationIds))), self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['id', 'amount'], 'FROM' => self::OPERATIONS, 'WHERE' => ['id' => $chunk]]) as $row) {
                $net[(int) $row['id']] = -Money::parse((string) $row['amount']);
            }
            foreach ($DB->request(['SELECT' => ['operations_id_origin', 'amount'], 'FROM' => self::OPERATIONS, 'WHERE' => [
                'operations_id_origin' => $chunk, 'type' => [OpType::CORRECTION, OpType::COMPENSATION, OpType::REVIEW_ADJUSTMENT],
            ]]) as $row) {
                $net[(int) $row['operations_id_origin']] -= Money::parse((string) $row['amount']);
            }
        }

        return $net;
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
     * reste d'un lot >= 0, sauf sur un lot qui a reçu une affectation de dépassement (détails `overdraft_lot` d'une opération de
     * débit : le paramètre `allow_overdraft` du moment n'y entre pas, il a pu changer depuis) ; solde du cache = somme du journal =
     * somme des restes des lots.
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
        $overdraftLots = [];
        foreach ($DB->request(['SELECT' => ['details'], 'FROM' => self::OPERATIONS, 'WHERE' => [
            'contracts_id' => $contractsId, 'type' => OpType::debitTypes(), 'details' => ['LIKE', '%"overdraft_lot"%'],
        ]]) as $row) {
            $lot = (int) (json_decode((string) $row['details'], true)['overdraft_lot'] ?? 0);
            if ($lot > 0) {
                $overdraftLots[$lot] = true;
            }
        }
        $sumLots = 0;
        foreach ($lots as $lot) {
            $sumLots += $lot['remaining'];
            if ($lot['remaining'] < 0 && !isset($overdraftLots[$lot['id']])) {
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
     * Contrôle des invariants de tous les contrats qui ont une ligne Points ou une opération (commande `plugins:points:check`).
     * Lecture seule.
     *
     * @return array<int, list<string>> contrat => écarts (les contrats cohérents n'y figurent pas)
     */
    public static function checkAll(): array
    {
        $out = [];
        foreach (self::contractIds() as $contractsId) {
            $problems = self::check($contractsId);
            if ($problems !== []) {
                $out[$contractsId] = $problems;
            }
        }

        return $out;
    }

    /**
     * Contrôle des soldes (commande `plugins:points:balances`) : pour chaque contrat qui a une ligne Points ou une opération, ce que la ligne
     * a enregistré (solde, crédité, consommé, % consommé, prochaine échéance) contre le recalcul depuis les mouvements (journal des
     * opérations, restes des lots : Domain\BalanceRules). LECTURE SEULE : aucune écriture, pas même du cache.
     *
     * @param int $only un seul contrat (0 : tous) ; un contrat sans ligne Points ni opération donne un rapport vide
     * @return list<array{contracts_id: int, name: string, recorded: ?array<string, int|string|null>, computed: array<string, int|string|null>,
     *         gaps: list<array{field: string, recorded: int|string|null, computed: int|string|null}>}>
     */
    public static function balances(int $only = 0): array
    {
        global $DB;

        $ids = self::contractIds();
        if ($only > 0) {
            $ids = in_array($only, $ids, true) ? [$only] : [];
        }
        $names = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_contracts', 'WHERE' => ['id' => $chunk]]) as $row) {
                $names[(int) $row['id']] = (string) $row['name'];
            }
        }
        $out = [];
        foreach ($ids as $contractsId) {
            // Instantané cohérent par contrat (transaction sans écriture : sous REPEATABLE READ, isolement par défaut d'InnoDB, toutes les
            // lectures voient le même état) : un débit validé entre la lecture de la ligne et celle du journal ne donne pas un faux écart
            $DB->beginTransaction();
            try {
                $out[] = self::balanceOf($contractsId, $names[$contractsId] ?? '');
            } finally {
                $DB->commit();
            }
        }

        return $out;
    }

    /**
     * Une ligne du rapport de balances() (lectures seulement).
     *
     * @return array{contracts_id: int, name: string, recorded: ?array<string, int|string|null>, computed: array<string, int|string|null>,
     *         gaps: list<array{field: string, recorded: int|string|null, computed: int|string|null}>}
     */
    private static function balanceOf(int $contractsId, string $name): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['balance', 'credited', 'consumed', 'consumed_pct', 'next_expiry'], 'FROM' => self::CONTRACTS, 'WHERE' => ['contracts_id' => $contractsId], 'LIMIT' => 1,
        ])->current();
        $recorded = $row === null ? null : [
            'balance'      => Money::parse((string) $row['balance']),
            'credited'     => Money::parse((string) $row['credited']),
            'consumed'     => Money::parse((string) $row['consumed']),
            'consumed_pct' => Money::parse((string) $row['consumed_pct']),
            'next_expiry'  => $row['next_expiry'] === null || (string) $row['next_expiry'] === '' ? null : (string) $row['next_expiry'],
        ];
        $computed = BalanceRules::recompute(self::totalsByType($contractsId), self::loadLots($contractsId));

        return [
            'contracts_id' => $contractsId,
            'name'         => $name,
            'recorded'     => $recorded,
            'computed'     => $computed,
            'gaps'         => BalanceRules::gaps($recorded, $computed),
        ];
    }

    /**
     * Contrats qui ont une ligne Points ou une opération, par identifiant croissant (contrôles de la console).
     *
     * @return list<int>
     */
    private static function contractIds(): array
    {
        global $DB;

        $contracts = [];
        foreach ([self::CONTRACTS, self::OPERATIONS] as $table) {
            foreach ($DB->request(['SELECT' => ['contracts_id'], 'DISTINCT' => true, 'FROM' => $table]) as $row) {
                $contracts[(int) $row['contracts_id']] = (int) $row['contracts_id'];
            }
        }
        ksort($contracts);

        return array_values($contracts);
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
            RuleViolation::ALREADY_DEBITED     => __('This item has already been debited', 'points'),
            RuleViolation::NOT_A_DEBIT         => __('Only a debit can be given back or corrected upwards', 'points'),
            RuleViolation::REFUND_NO_VALID_LOT => __('The original envelope has ended and no envelope is valid today to receive the points given back', 'points'),
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
     * Enveloppe + opération (`credit` ou `import`) + lot + affectation.
     *
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @return array{envelopes_id: int, operations_id: int, lots_id: int}
     */
    private static function writeEnvelope(array $contract, string $type, string $name, int $credit, string $begin, string $end, bool $reportable, string $comment, ?array $details = null): array
    {
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
        $operationId = self::addOperation($contract, $type, $credit, $begin, [
            'itemtype' => Envelope::class,
            'items_id' => $envelopeId,
            'comment'  => $comment,
            'details'  => $details,
        ]);
        $lotId = self::addLot($contract, $operationId, $envelopeId, $begin, LotRules::expiryDate($end, $begin, self::retention()), $credit);
        self::addAllocation($contract, $operationId, $lotId, $credit);

        return ['envelopes_id' => $envelopeId, 'operations_id' => $operationId, 'lots_id' => $lotId];
    }

    /**
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array<string, mixed> $opts
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>, overdraft: int}
     */
    private static function writeDebit(array $contract, string $type, int $cents, string $date, string $day, array $opts): array
    {
        if (!empty($opts['unique']) && self::debitOf($type, (string) ($opts['itemtype'] ?? ''), (int) ($opts['items_id'] ?? 0)) > 0) {
            throw new RuleViolation(RuleViolation::ALREADY_DEBITED, 'This item has already been debited');
        }
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
            // Le lot qui porte le dépassement est la seule raison légitime d'un reste négatif (Ledger::check)
            $details['overdraft']     = Money::format($plan['overdraft']);
            $details['overdraft_lot'] = $plan['overdraft_lot'];
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

        if (isset($opts['inside']) && is_callable($opts['inside'])) {
            $opts['inside']($operationId);
        }

        return ['operations_id' => $operationId, 'allocations' => $plan['allocations'], 'overdraft' => $plan['overdraft']];
    }

    /**
     * Remboursement (correction +, compensation, ajustement + de review) : opération liée au débit d'origine et affectations aux lots du
     * débit, dans l'ordre inverse (LotRules::refund). La part qui retomberait sur un lot ÉCHU au jour de l'opération suit le paramètre
     * `compensation_on_expired` (règle 11) : `origin` la rend à ce lot (elle expirera à la prochaine exécution de l'action automatique) ;
     * `valid_lot` (défaut) la crédite sur un nouveau lot daté du jour, dans l'enveloppe du lot valide le plus récent, ou refuse
     * (REFUND_NO_VALID_LOT) s'il n'y en a aucun. La part redirigée est notée dans les détails (`redirected` : lot d'origine => centièmes) : elle
     * compte comme déjà rendue sur ce lot (refundedByLot), sans quoi elle pourrait être rendue une seconde fois. Sous le verrou du contrat.
     *
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     * @param array<string, mixed> $o comment, itemtype, items_id, tickets_id, details, inside
     * @return array{operations_id: int, allocations: list<array{lot: int, amount: int}>}
     */
    private static function writeRefund(array $contract, int $originOperationId, int $cents, string $type, string $date, array $o): array
    {
        $day         = self::day($date);
        $allocations = LotRules::refund(self::allocationsOf($originOperationId), self::refundedByLot($originOperationId), $cents);
        $details     = $o['details'] ?? [];
        $redirected  = 0;
        $target      = null;
        if ((string) Config::value('compensation_on_expired') === ConfigValues::ON_EXPIRED_VALID_LOT) {
            $lots  = self::loadLots($contract['contracts_id']);
            $split = LotRules::redirectExpired($allocations, $lots, $day);
            if ($split['redirected'] > 0) {
                $target = LotRules::latestValidLot($lots, $day);
                if ($target === null) {
                    throw new RuleViolation(RuleViolation::REFUND_NO_VALID_LOT, 'No valid lot to credit the refund: its original lot has ended');
                }
                $allocations = $split['kept'];
                $redirected  = $split['redirected'];
                $details['redirected'] = $split['from'];
            }
        }
        $operationId = self::addOperation($contract, $type, $cents, $date, [
            'comment'              => $o['comment'] ?? '',
            'operations_id_origin' => $originOperationId,
            'itemtype'             => $o['itemtype'] ?? null,
            'items_id'             => $o['items_id'] ?? 0,
            'tickets_id'           => $o['tickets_id'] ?? 0,
            'details'              => $details === [] ? null : $details,
        ]);
        foreach ($allocations as $allocation) {
            self::addAllocation($contract, $operationId, $allocation['lot'], $allocation['amount']);
        }
        if ($target !== null) {
            $envelope = self::envelope($contract['contracts_id'], $target['envelope']);
            $lotId    = self::addLot($contract, $operationId, $envelope['id'], $day, LotRules::expiryDate($envelope['end_date'], $day, self::retention()), $redirected);
            self::addAllocation($contract, $operationId, $lotId, $redirected);
            $allocations[] = ['lot' => $lotId, 'amount' => $redirected];
        }
        if (isset($o['inside']) && is_callable($o['inside'])) {
            $o['inside']($operationId);
        }

        return ['operations_id' => $operationId, 'allocations' => $allocations];
    }

    /**
     * Opération de débit de cette clé (type, itemtype, items_id), 0 s'il n'y en a pas. Le débit est DÉFINITIF : un remboursement, une
     * correction ou une compensation ne le rendent jamais débitable une seconde fois. Seule lecture « cet élément est-il débité ? » du
     * plugin (tâches, forfaits, garde-fou d'unicité). Appelée sous le verrou du contrat par writeDebit : une lecture faite avant le
     * verrou ne suffit pas (deux débits concurrents).
     */
    public static function debitOf(string $type, string $itemtype, int $itemsId): int
    {
        global $DB;

        if ($itemtype === '' || $itemsId < 1) {
            return 0;
        }
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['type' => $type, 'itemtype' => $itemtype, 'items_id' => $itemsId], 'LIMIT' => 1])->current();

        return $row === null ? 0 : (int) $row['id'];
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
     * @return list<array{id: int, origin_date: string, expiry_date: string, remaining: int, envelope: int, initial: int, refund: bool}>
     */
    private static function loadLots(int $contractsId): array
    {
        global $DB;

        $lots = [];
        $byOperation = [];
        foreach ($DB->request(['SELECT' => ['id', 'operations_id', 'origin_date', 'expiry_date', 'plugin_points_envelopes_id', 'initial'], 'FROM' => self::LOTS, 'WHERE' => ['contracts_id' => $contractsId], 'ORDER' => ['origin_date', 'id']]) as $row) {
            $lots[(int) $row['id']] = [
                'id'          => (int) $row['id'],
                'origin_date' => (string) $row['origin_date'],
                'expiry_date' => (string) $row['expiry_date'],
                'remaining'   => 0,
                'envelope'    => (int) $row['plugin_points_envelopes_id'],
                'initial'     => Money::parse((string) $row['initial']),
                'refund'      => false,
            ];
            $byOperation[(int) $row['operations_id']][] = (int) $row['id'];
        }
        // Lot créé par un remboursement (part redirigée, writeRefund) : des points rendus, pas un achat (seuil d'alerte)
        foreach (array_chunk(array_keys($byOperation), self::CHUNK) as $chunk) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::OPERATIONS, 'WHERE' => ['id' => $chunk, 'type' => [OpType::CORRECTION, OpType::COMPENSATION, OpType::REVIEW_ADJUSTMENT]]]) as $row) {
                foreach ($byOperation[(int) $row['id']] as $lotId) {
                    $lots[$lotId]['refund'] = true;
                }
            }
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
        $row = Operation::read($operationId);
        if ($row === null || $row['contracts_id'] !== $contractsId) {
            throw new RuleViolation(RuleViolation::UNKNOWN_OPERATION, 'Unknown operation for this contract');
        }

        return ['id' => $row['id'], 'type' => $row['type'], 'amount' => $row['amount']];
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
     * Ce qui a déjà été rendu aux lots d'un débit : affectations positives des opérations qui lui sont liées, plus ce qu'elles ont
     * redirigé hors d'un lot échu (détails `redirected`, voir writeRefund).
     *
     * @return array<int, int> lot => centièmes
     */
    private static function refundedByLot(int $originOperationId): array
    {
        global $DB;

        $ids = [];
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'details'], 'FROM' => self::OPERATIONS, 'WHERE' => ['operations_id_origin' => $originOperationId, 'type' => [OpType::CORRECTION, OpType::COMPENSATION, OpType::REVIEW_ADJUSTMENT]]]) as $row) {
            $ids[] = (int) $row['id'];
            foreach (LotRules::redirectedFrom(json_decode((string) ($row['details'] ?? ''), true) ?: null) as $lot => $cents) {
                $out[$lot] = ($out[$lot] ?? 0) + $cents;
            }
        }
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
        return ContractCache::compute(self::totalsByType($contractsId));
    }

    /**
     * Somme des montants du journal d'un contrat par type d'opération, en centièmes.
     *
     * @return array<string, int>
     */
    private static function totalsByType(int $contractsId): array
    {
        global $DB;

        $totals = [];
        foreach ($DB->request(['SELECT' => ['type', QueryFunction::sum('amount', false, 'total')], 'FROM' => self::OPERATIONS, 'WHERE' => ['contracts_id' => $contractsId], 'GROUPBY' => 'type']) as $row) {
            $totals[(string) $row['type']] = Money::parse((string) ($row['total'] ?? '0'));
        }

        return $totals;
    }

    /**
     * Écrit le cache du contrat et ses colonnes de recherche (prochaine échéance, % consommé), dans la transaction de l'écriture
     * du journal ; lève l'alerte de seuil si le contrat repasse sous le seuil (règle 12 : « remise à zéro »).
     *
     * @param array{id: int, contracts_id: int, entities_id: int} $contract
     */
    private static function writeCache(array $contract): void
    {
        global $DB;

        $cache = self::computeCache($contract['contracts_id']);
        $lots  = self::loadLots($contract['contracts_id']);
        $now   = self::now();
        $DB->update(self::CONTRACTS, [
            'balance'      => Money::format($cache['balance']),
            'credited'     => Money::format($cache['credited']),
            'consumed'     => Money::format($cache['consumed']),
            'next_expiry'  => ContractCache::nextExpiry($lots),
            'consumed_pct' => Money::format(ContractCache::percent($cache['credited'], $cache['consumed'])),
            'date_cache'   => $now,
            'date_mod'     => $now,
        ], ['id' => $contract['id']]);
        ThresholdAlert::resetIfBelow($contract['id'], $lots, Billing::today());
    }

    /**
     * Crédité et consommé des lots valides à une date (base du seuil d'alerte, règle 12), centièmes ; lecture seule.
     *
     * @return array{credited: int, consumed: int}
     */
    public static function validTotals(int $contractsId, string $today): array
    {
        return AlertRules::validTotals(self::loadLots($contractsId), Dates::check($today));
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
