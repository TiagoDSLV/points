<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Seuil d'alerte (docs/conception.md, règle 12) : calculé sur les LOTS VALIDES à la date du jour (origine <= date <= échéance),
 * jamais sur le cache du contrat.
 *
 * - crédité = somme des points initiaux des lots valides (un lot reporté repart avec ce qui a été reporté) ;
 * - consommé = somme, sur ces lots, de initial - reste (le reste est la somme des affectations du lot : débits, remboursements,
 *   corrections, compensations, ajustements ; un dépassement autorisé donne un reste négatif donc un consommé > initial) ;
 * - seuil atteint si crédité > 0 et consommé * 100 >= seuil * crédité (centièmes entiers, aucun flottant).
 *
 * - un lot créé par la redirection d'un remboursement (`refund` : points rendus, pas achetés) ne compte pas au crédité ; son reste vient en
 *   déduction du consommé (consommé du lot = initial - reste, moins son initial) ; le consommé total ne descend pas sous 0.
 *
 * Classe pure : aucune dépendance à GLPI.
 */
final class AlertRules
{
    /**
     * @param list<array{origin_date: string, expiry_date: string, remaining: int, initial: int, refund?: bool}> $lots
     * @return array{credited: int, consumed: int}
     */
    public static function validTotals(array $lots, string $today): array
    {
        $credited = 0;
        $consumed = 0;
        foreach ($lots as $lot) {
            if ($lot['origin_date'] <= $today && $today <= $lot['expiry_date']) {
                if (!empty($lot['refund'])) {
                    $consumed -= $lot['remaining'];
                    continue;
                }
                $credited += $lot['initial'];
                $consumed += $lot['initial'] - $lot['remaining'];
            }
        }

        return ['credited' => $credited, 'consumed' => max(0, $consumed)];
    }

    public static function reached(int $credited, int $consumed, int $threshold): bool
    {
        return $credited > 0 && $threshold > 0 && $consumed * 100 >= $threshold * $credited;
    }
}
