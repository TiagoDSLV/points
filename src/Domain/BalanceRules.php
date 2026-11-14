<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Contrôle des soldes (commande `plugins:points:balances`) : ce que la ligne Points d'un contrat a enregistré (cache : solde, crédité,
 * consommé, % consommé, prochaine échéance) contre ce que les mouvements donnent (journal des opérations, restes des lots). Fonctions
 * pures, montants en centièmes ; le calcul est celui de l'écriture du cache (ContractCache), jamais une seconde définition.
 */
final class BalanceRules
{
    /** Champs enregistrés comparés au recalcul, dans l'ordre du rapport. */
    public const FIELDS = ['balance', 'credited', 'consumed', 'consumed_pct', 'next_expiry'];

    /** Écart : aucune ligne Points pour un contrat qui a des mouvements. */
    public const ROW = 'row';

    /** Écart : la somme des restes des lots diffère du solde recalculé depuis le journal. */
    public const LOTS = 'lots';

    /**
     * Valeurs recalculées depuis les mouvements.
     *
     * @param array<string, int> $totals type d'opération => somme des montants signés (centièmes)
     * @param list<array{expiry_date: string, remaining: int}> $lots lots du contrat et leur reste (somme des affectations)
     * @return array{balance: int, credited: int, consumed: int, consumed_pct: int, next_expiry: ?string, lots: int}
     */
    public static function recompute(array $totals, array $lots): array
    {
        $cache = ContractCache::compute($totals);

        return $cache + [
            'consumed_pct' => ContractCache::percent($cache['credited'], $cache['consumed']),
            'next_expiry'  => ContractCache::nextExpiry($lots),
            'lots'         => array_sum(array_column($lots, 'remaining')),
        ];
    }

    /**
     * Écarts entre l'enregistré et le recalcul ; liste vide = solde juste. Pour `lots`, `recorded` est le solde recalculé du journal et
     * `computed` la somme des restes des lots. Sans ligne Points (`$recorded` null), l'écart `row` est signalé et l'enregistré vaut zéro.
     *
     * @param array{balance: int, credited: int, consumed: int, consumed_pct: int, next_expiry: ?string}|null $recorded
     * @param array{balance: int, credited: int, consumed: int, consumed_pct: int, next_expiry: ?string, lots: int} $computed
     * @return list<array{field: string, recorded: int|string|null, computed: int|string|null}>
     */
    public static function gaps(?array $recorded, array $computed): array
    {
        $gaps = [];
        if ($recorded === null) {
            $gaps[]   = ['field' => self::ROW, 'recorded' => null, 'computed' => null];
            $recorded = ['balance' => 0, 'credited' => 0, 'consumed' => 0, 'consumed_pct' => 0, 'next_expiry' => null];
        }
        foreach (self::FIELDS as $field) {
            if ($recorded[$field] !== $computed[$field]) {
                $gaps[] = ['field' => $field, 'recorded' => $recorded[$field], 'computed' => $computed[$field]];
            }
        }
        if ($computed['lots'] !== $computed['balance']) {
            $gaps[] = ['field' => self::LOTS, 'recorded' => $computed['balance'], 'computed' => $computed['lots']];
        }

        return $gaps;
    }

    /**
     * Valeur lisible d'un champ du rapport : montants et pourcentage en décimal (Money), échéance en date, absent : « - ».
     */
    public static function format(string $field, int|string|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return is_int($value) && $field !== 'next_expiry' ? Money::format($value) : (string) $value;
    }

    /**
     * Ligne de rapport d'un écart (anglais, comme les autres sorties de la console du plugin).
     *
     * @param array{field: string, recorded: int|string|null, computed: int|string|null} $gap
     */
    public static function describe(array $gap): string
    {
        return match ($gap['field']) {
            self::ROW  => 'no Points row for a contract that has movements',
            self::LOTS => sprintf('lots: journal %s, sum of the lot remainders %s', self::format('balance', $gap['recorded']), self::format('balance', $gap['computed'])),
            default    => sprintf('%s: recorded %s, recomputed %s', $gap['field'], self::format($gap['field'], $gap['recorded']), self::format($gap['field'], $gap['computed'])),
        };
    }
}
