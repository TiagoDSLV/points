<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

use GlpiPlugin\Points\ConfigValues;

/**
 * Règles de calcul du journal (docs/conception.md, règles 5, 6 et 8) : échéance d'un lot, répartition FIFO d'un
 * débit, remboursement, expiration et report, contrôle d'une enveloppe. Classe pure : aucune dépendance à GLPI,
 * aucune lecture de base, montants en centièmes entiers, dates AAAA-MM-JJ.
 *
 * Un lot est un tableau ['id' => int, 'origin_date' => 'AAAA-MM-JJ', 'expiry_date' => 'AAAA-MM-JJ', 'remaining' => int].
 * Un lot est valide à une date d si origin_date <= d <= expiry_date : le dernier jour est inclus.
 */
final class LotRules
{
    /**
     * Contrôle d'une enveloppe : dates valides, début <= fin, fin <= début + 24 mois (règle de la direction).
     *
     * @throws RuleViolation
     */
    public static function checkEnvelope(string $begin, string $end): void
    {
        try {
            Dates::check($begin);
            Dates::check($end);
        } catch (\InvalidArgumentException) {
            throw new RuleViolation(RuleViolation::INVALID_DATE, 'Invalid envelope date');
        }
        if ($begin > $end) {
            throw new RuleViolation(RuleViolation::ENVELOPE_DATES, 'Envelope begin date is after its end date');
        }
        if ($end > Dates::addMonths($begin, ConfigValues::MAX_RETENTION_MONTHS)) {
            throw new RuleViolation(RuleViolation::ENVELOPE_TOO_LONG, 'Envelope longer than 24 months');
        }
    }

    /**
     * Échéance d'un lot : min(fin de l'enveloppe, origine + durée de vie), la durée de vie étant bornée à 1..24 mois.
     */
    public static function expiryDate(string $envelopeEnd, string $originDate, int $retentionMonths): string
    {
        $cap = Dates::addMonths($originDate, ConfigValues::clampRetention($retentionMonths));

        return Dates::min(Dates::check($envelopeEnd), $cap);
    }

    /**
     * @param array{id: int, origin_date: string, expiry_date: string, remaining: int} $lot
     */
    public static function isValidOn(array $lot, string $date): bool
    {
        return $lot['origin_date'] <= $date && $date <= $lot['expiry_date'];
    }

    /**
     * Solde réel des lots valides à une date : somme de leurs restes, restes négatifs (dépassements) compris.
     *
     * @param list<array{id: int, origin_date: string, expiry_date: string, remaining: int}> $lots
     */
    public static function availableOn(array $lots, string $date): int
    {
        $sum = 0;
        foreach ($lots as $lot) {
            if (self::isValidOn($lot, $date)) {
                $sum += $lot['remaining'];
            }
        }

        return $sum;
    }

    /**
     * Répartition FIFO d'un débit : lots valides à la date métier, reste > 0, triés par origine puis id. Ce qui ne
     * trouve pas de lot va en dépassement sur le lot valide le plus récent si $allowOverdraft, sinon le débit est refusé.
     * Un lot échu, ou pas encore commencé, est ignoré.
     *
     * @param list<array{id: int, origin_date: string, expiry_date: string, remaining: int}> $lots
     * @param int $amount montant du débit, positif, en centièmes
     * @return array{allocations: list<array{lot: int, amount: int}>, overdraft: int, overdraft_lot: int|null} affectations (montants
     *         négatifs, une par lot), part en dépassement (positive, comprise dans les affectations) et lot qui la porte (null sans dépassement)
     * @throws RuleViolation
     */
    public static function allocateDebit(array $lots, string $date, int $amount, bool $allowOverdraft): array
    {
        if ($amount <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A debit amount must be positive');
        }
        $valid = array_values(array_filter($lots, static fn (array $lot): bool => self::isValidOn($lot, $date)));
        if ($valid === []) {
            throw new RuleViolation(RuleViolation::NO_VALID_LOT, 'No valid lot at this date');
        }
        usort($valid, static fn (array $a, array $b): int => [$a['origin_date'], $a['id']] <=> [$b['origin_date'], $b['id']]);
        // Sans dépassement : le contrôle porte sur le solde réel des lots valides, restes négatifs compris
        if (!$allowOverdraft && self::availableOn($valid, $date) < $amount) {
            throw new RuleViolation(RuleViolation::INSUFFICIENT, 'Insufficient points and overdraft not allowed');
        }

        $left = $amount;
        $take = [];
        foreach ($valid as $lot) {
            if ($left === 0) {
                break;
            }
            if ($lot['remaining'] <= 0) {
                continue;
            }
            $part = min($lot['remaining'], $left);
            $take[$lot['id']] = -$part;
            $left -= $part;
        }
        $overdraft    = 0;
        $overdraftLot = null;
        if ($left > 0) {
            if (!$allowOverdraft) {
                throw new RuleViolation(RuleViolation::INSUFFICIENT, 'Insufficient points and overdraft not allowed');
            }
            $latest            = $valid[array_key_last($valid)]['id'];
            $take[$latest]     = ($take[$latest] ?? 0) - $left;
            $overdraft         = $left;
            $overdraftLot      = $latest;
        }
        $allocations = [];
        foreach ($take as $lotId => $part) {
            $allocations[] = ['lot' => $lotId, 'amount' => $part];
        }

        return ['allocations' => $allocations, 'overdraft' => $overdraft, 'overdraft_lot' => $overdraftLot];
    }

    /**
     * Débit tardif (règle 5) : pour chaque lot touché par un débit, part prise au-delà de son reste réel, qui vient donc
     * de points déjà expirés ; elle est reprise (`expiry_reversal`) dans la limite de ce qui a été expiré et pas encore repris.
     *
     * @param list<array{lot: int, amount: int}> $allocations affectations du débit (négatives)
     * @param array<int, array{remaining: int, expired: int}> $lots lot => reste réel et points expirés non repris
     * @return array<int, int> lot => montant à reprendre (positif)
     */
    public static function lateReversals(array $allocations, array $lots): array
    {
        $out = [];
        foreach ($allocations as $allocation) {
            $lot = $lots[$allocation['lot']] ?? null;
            if ($lot === null || $lot['expired'] <= 0) {
                continue;
            }
            $need = -$allocation['amount'] - max(0, $lot['remaining']);
            $part = min($need, $lot['expired']);
            if ($part > 0) {
                $out[$allocation['lot']] = $part;
            }
        }

        return $out;
    }

    /**
     * Une correction positive, un remboursement ou une compensation ne visent qu'un débit : débit de tâche, de forfait,
     * correction ou ajustement, de montant négatif.
     */
    public static function isDebitOrigin(string $type, int $amount): bool
    {
        return $amount < 0 && in_array($type, OpType::debitTypes(), true);
    }

    /**
     * Remboursement sur les lots d'un débit d'origine, dans l'ordre inverse de la répartition d'origine. Chaque lot
     * ne rend pas plus que ce que le débit y a pris, moins ce qui lui a déjà été rendu.
     *
     * @param list<array{lot: int, amount: int}> $originAllocations affectations du débit d'origine, dans l'ordre d'écriture
     * @param array<int, int> $alreadyRefunded lot => centièmes déjà rendus (positifs)
     * @param int $amount montant à rendre, positif
     * @return list<array{lot: int, amount: int}> affectations positives
     * @throws RuleViolation si le montant dépasse ce qui peut être rendu
     */
    public static function refund(array $originAllocations, array $alreadyRefunded, int $amount): array
    {
        if ($amount <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A refund amount must be positive');
        }
        $capacity = [];
        foreach ($originAllocations as $allocation) {
            if ($allocation['amount'] < 0) {
                $capacity[$allocation['lot']] = ($capacity[$allocation['lot']] ?? 0) - $allocation['amount'];
            }
        }
        foreach ($capacity as $lotId => $value) {
            $capacity[$lotId] = max(0, $value - ($alreadyRefunded[$lotId] ?? 0));
        }
        $left = $amount;
        $out  = [];
        foreach (array_reverse(array_keys($capacity)) as $lotId) {
            $part = min($capacity[$lotId], $left);
            if ($part > 0) {
                $out[]  = ['lot' => $lotId, 'amount' => $part];
                $left  -= $part;
            }
            if ($left === 0) {
                break;
            }
        }
        if ($left > 0) {
            throw new RuleViolation(RuleViolation::NOTHING_TO_REFUND, 'Amount exceeds what the origin debit can give back');
        }

        return $out;
    }

    /**
     * Lot valide le plus récent à un jour (origine la plus récente, puis id le plus grand) : le lot qui reçoit un remboursement dont
     * le lot d'origine est échu (règle 11, `compensation_on_expired` = `valid_lot`). Null s'il n'y en a aucun.
     *
     * @param list<array{id: int, origin_date: string, expiry_date: string, envelope?: int}> $lots
     * @return array{id: int, origin_date: string, expiry_date: string, envelope?: int}|null
     */
    public static function latestValidLot(array $lots, string $day): ?array
    {
        $valid = array_values(array_filter($lots, static fn (array $lot): bool => self::isValidOn($lot, $day)));
        usort($valid, static fn (array $a, array $b): int => [$a['origin_date'], $a['id']] <=> [$b['origin_date'], $b['id']]);

        return $valid === [] ? null : $valid[array_key_last($valid)];
    }

    /**
     * Remboursement dont une part retombe sur un lot échu au jour de l'opération : cette part n'est pas rendue à ce lot (elle
     * expirerait aussitôt), elle est comptée à part pour être créditée sur un lot valide.
     *
     * @param list<array{lot: int, amount: int}> $allocations affectations positives du remboursement (LotRules::refund)
     * Un lot échu en dépassement (reste négatif, dépassement autorisé) garde d'abord la part qui comble son reste : min(part, -reste). Seul
     * le surplus est redirigé ; sans cela le remboursement créerait des points (le dépassement n'aurait jamais été payé).
     *
     * @param list<array{id: int, expiry_date: string, remaining?: int}> $lots lots du contrat
     * @return array{kept: list<array{lot: int, amount: int}>, redirected: int, from: array<int, int>} parts gardées, total
     *         redirigé et origine de ce total (lot échu => centièmes)
     */
    public static function redirectExpired(array $allocations, array $lots, string $day): array
    {
        $expiry    = array_column($lots, 'expiry_date', 'id');
        $remaining = array_column($lots, 'remaining', 'id');
        $out       = ['kept' => [], 'redirected' => 0, 'from' => []];
        foreach ($allocations as $allocation) {
            if (isset($expiry[$allocation['lot']]) && $expiry[$allocation['lot']] < $day) {
                $fill    = min($allocation['amount'], max(0, -($remaining[$allocation['lot']] ?? 0)));
                $surplus = $allocation['amount'] - $fill;
                if ($fill > 0) {
                    $out['kept'][] = ['lot' => $allocation['lot'], 'amount' => $fill];
                }
                if ($surplus > 0) {
                    $out['redirected']               += $surplus;
                    $out['from'][$allocation['lot']]  = ($out['from'][$allocation['lot']] ?? 0) + $surplus;
                }
                continue;
            }
            $out['kept'][] = $allocation;
        }

        return $out;
    }

    /**
     * Ce qu'une opération de remboursement a redirigé, par lot d'origine, lu dans ses détails (`redirected` : lot => centièmes) ; compte
     * dans ce que le débit a déjà rendu, sans quoi la part redirigée pourrait être rendue de nouveau.
     *
     * @param array<string, mixed>|null $details détails décodés d'une opération
     * @return array<int, int>
     */
    public static function redirectedFrom(?array $details): array
    {
        $out = [];
        foreach ((array) ($details['redirected'] ?? []) as $lot => $cents) {
            if (is_int($cents) && $cents > 0) {
                $out[(int) $lot] = $cents;
            }
        }

        return $out;
    }

    /**
     * Lots échus à une date (échéance strictement avant) dont il reste des points.
     *
     * @param list<array{id: int, origin_date: string, expiry_date: string, remaining: int}> $lots
     * @return list<array{id: int, origin_date: string, expiry_date: string, remaining: int}>
     */
    public static function dueLots(array $lots, string $date): array
    {
        return array_values(array_filter($lots, static fn (array $lot): bool => $lot['expiry_date'] < $date && $lot['remaining'] > 0));
    }

    /**
     * Enveloppe suivante d'une enveloppe source : une autre enveloppe du contrat qui finit après elle et après la date
     * de traitement (un report doit donner au moins un jour de plus) ; la plus proche (début, puis fin, puis id).
     *
     * @param list<array{id: int, begin_date: string, end_date: string}> $envelopes enveloppes du contrat
     * @param array{id: int, begin_date: string, end_date: string} $source
     */
    public static function nextEnvelope(array $envelopes, array $source, string $date): ?array
    {
        $candidates = array_values(array_filter(
            $envelopes,
            static fn (array $e): bool => $e['id'] !== $source['id'] && $e['end_date'] > $source['end_date'] && $e['end_date'] > $date
        ));
        usort($candidates, static fn (array $a, array $b): int => [$a['begin_date'], $a['end_date'], $a['id']] <=> [$b['begin_date'], $b['end_date'], $b['id']]);

        return $candidates[0] ?? null;
    }

    /**
     * Report d'un lot vers une enveloppe : le nouveau lot garde l'origine, son échéance est min(fin de la cible,
     * origine + durée de vie). Le report est tout ou rien : il n'a lieu que si cette échéance prolonge celle du lot
     * et va au-delà de la date de traitement ; sinon le reste du lot est au-delà de la limite (2 ans) et doit expirer.
     *
     * @param array{id: int, origin_date: string, expiry_date: string, remaining: int} $lot
     * @return array{carry: bool, expiry: string} échéance du nouveau lot ; carry faux = rien à reporter, expirer
     */
    public static function carryoverPlan(array $lot, string $targetEnd, string $date, int $retentionMonths): array
    {
        $expiry = self::expiryDate($targetEnd, $lot['origin_date'], $retentionMonths);

        return [
            'carry'  => $lot['remaining'] > 0 && $expiry > $lot['expiry_date'] && $expiry > $date,
            'expiry' => $expiry,
        ];
    }
}
