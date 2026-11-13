<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Demande de compensation (docs/conception.md, règle 11 et « Valeurs codées ») : états, montants, motifs, séparation
 * des tâches. Classe pure, sans dépendance à GLPI.
 *
 *   pending → approved (crédit lié, montant approuvé ≤ ce que le débit peut encore rendre)
 *           → rejected (motif de décision obligatoire)
 */
final class CompensationRules
{
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Types d'opération qu'une compensation peut viser (règle 11 : un débit de tâche ou de forfait). */
    public const TARGET_TYPES = [OpType::DEBIT_TASK, OpType::DEBIT_FLATRATE];

    private const TRANSITIONS = [
        self::PENDING  => [self::APPROVED, self::REJECTED],
        self::APPROVED => [],
        self::REJECTED => [],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws RuleViolation COMPENSATION_STATE
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new RuleViolation(RuleViolation::COMPENSATION_STATE, "A compensation cannot go from $from to $to");
        }
    }

    /**
     * Montant saisi → centièmes, strictement positif.
     *
     * @throws RuleViolation INVALID_AMOUNT
     */
    public static function parseAmount(string $text): int
    {
        try {
            $cents = Money::parse($text);
        } catch (\InvalidArgumentException) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'Invalid compensation amount');
        }
        if ($cents <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A compensation amount must be positive');
        }

        return $cents;
    }

    /**
     * Le motif de la demande est obligatoire.
     *
     * @throws RuleViolation REASON_REQUIRED
     */
    public static function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuleViolation(RuleViolation::REASON_REQUIRED, 'A reason is required');
        }
    }

    /**
     * Montant demandé ou approuvé au plus égal à ce qui reste (Ledger::refundable).
     *
     * @throws RuleViolation COMPENSATION_AMOUNT
     */
    public static function assertWithin(int $cents, int $remaining): void
    {
        if ($cents > $remaining) {
            throw new RuleViolation(RuleViolation::COMPENSATION_AMOUNT, 'The amount exceeds what the debit leaves to compensate');
        }
    }

    /**
     * Montant approuvé : celui de la demande s'il n'est pas précisé, jamais au-dessus de ce qui a été demandé.
     *
     * @throws RuleViolation INVALID_AMOUNT|COMPENSATION_AMOUNT
     */
    public static function approvedAmount(string $text, int $requested): int
    {
        if (trim($text) === '') {
            return $requested;
        }
        $cents = self::parseAmount($text);
        if ($cents > $requested) {
            throw new RuleViolation(RuleViolation::COMPENSATION_AMOUNT, 'The approved amount exceeds the requested amount');
        }

        return $cents;
    }

    /**
     * Séparation des tâches : le décideur ne décide pas sa propre demande.
     *
     * @throws RuleViolation COMPENSATION_SELF
     */
    public static function assertSeparation(int $requesterUsersId, int $deciderUsersId): void
    {
        if ($requesterUsersId > 0 && $requesterUsersId === $deciderUsersId) {
            throw new RuleViolation(RuleViolation::COMPENSATION_SELF, 'The requester of a compensation cannot decide it');
        }
    }
}
