<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Cache du contrat calculé depuis les totaux du journal par type (docs/conception.md, « Cache du contrat ») :
 * credited = crédits, imports, reports reçus moins reports émis et expirations (plus reprise d'une expiration) ;
 * consumed = débits moins remboursements, corrections positives et compensations ; balance = somme du journal,
 * donc credited - consumed = balance.
 */
final class ContractCache
{
    /**
     * @param array<string, int> $totals type d'opération => somme des montants signés (centièmes)
     * @return array{balance: int, credited: int, consumed: int}
     */
    public static function compute(array $totals): array
    {
        $cache = ['balance' => 0, 'credited' => 0, 'consumed' => 0];
        foreach ($totals as $type => $sum) {
            $cache['balance'] += $sum;
            if (OpType::isCredited((string) $type)) {
                $cache['credited'] += $sum;
            } else {
                $cache['consumed'] -= $sum;
            }
        }

        return $cache;
    }

    /** Plus grand pourcentage stocké (colonne decimal(8,2)), en centièmes de pour cent. */
    public const MAX_PERCENT = 99999999;

    /**
     * Pourcentage consommé du cache (consumed / credited), en centièmes de pour cent, arrondi au plus proche ; 0 si rien n'est
     * crédité ; plafonné à la capacité de la colonne. Valeur de recherche et d'affichage : le seuil d'alerte, lui, se calcule sur les
     * lots valides (AlertRules).
     */
    public static function percent(int $credited, int $consumed): int
    {
        if ($credited <= 0 || $consumed <= 0) {
            return 0;
        }

        return min(self::MAX_PERCENT, intdiv($consumed * 20000 + $credited, 2 * $credited));
    }

    /**
     * Prochaine échéance : plus petite échéance parmi les lots dont le reste est > 0 (un lot échu au reste > 0 attend encore son
     * expiration ou son report par l'action automatique) ; null s'il n'y en a aucun.
     *
     * @param list<array{expiry_date: string, remaining: int}> $lots
     */
    public static function nextExpiry(array $lots): ?string
    {
        $next = null;
        foreach ($lots as $lot) {
            if ($lot['remaining'] > 0 && ($next === null || $lot['expiry_date'] < $next)) {
                $next = $lot['expiry_date'];
            }
        }

        return $next;
    }
}
