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
}
