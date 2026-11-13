<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Machine d'états d'un forfait (docs/conception.md, règle 9 et « Valeurs codées ») : transitions permises, montant,
 * séparation des tâches, estimation d'aide. Classe pure, sans dépendance à GLPI.
 *
 *   draft → submitted → bu_refused → draft (correction)
 *                     → proposed   → approved → debited
 *                                  → client_refused
 *   cancelled : possible avant approved (draft, submitted, bu_refused, proposed)
 */
final class FlatrateRules
{
    public const DRAFT          = 'draft';
    public const SUBMITTED      = 'submitted';
    public const BU_REFUSED     = 'bu_refused';
    public const PROPOSED       = 'proposed';
    public const APPROVED       = 'approved';
    public const CLIENT_REFUSED = 'client_refused';
    public const DEBITED        = 'debited';
    public const CANCELLED      = 'cancelled';

    /** Statuts « non terminaux » : un seul par ticket (docs/conception.md, règle 9 ; lot d). */
    public const OPEN = [self::DRAFT, self::SUBMITTED, self::PROPOSED, self::APPROVED];

    /** Statuts qui remplacent le décompte des tâches du ticket : tout forfait non refusé ni annulé. */
    public const COUNTING = [self::DRAFT, self::SUBMITTED, self::PROPOSED, self::APPROVED, self::DEBITED];

    /** Statuts qui empêchent la clôture du ticket (le forfait n'est pas encore approuvé). */
    public const BLOCKING_CLOSURE = [self::DRAFT, self::SUBMITTED, self::PROPOSED];

    /** Statuts dont la validation native liée ne se supprime pas. */
    public const KEEPS_VALIDATION = [self::PROPOSED, self::APPROVED, self::DEBITED];

    private const TRANSITIONS = [
        self::DRAFT          => [self::SUBMITTED, self::CANCELLED],
        self::SUBMITTED      => [self::BU_REFUSED, self::PROPOSED, self::CANCELLED],
        self::BU_REFUSED     => [self::DRAFT, self::CANCELLED],
        self::PROPOSED       => [self::APPROVED, self::CLIENT_REFUSED, self::CANCELLED],
        self::APPROVED       => [self::DEBITED],
        self::CLIENT_REFUSED => [],
        self::DEBITED        => [],
        self::CANCELLED      => [],
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
     * @throws RuleViolation FLATRATE_STATE
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new RuleViolation(RuleViolation::FLATRATE_STATE, "A flatrate cannot go from $from to $to");
        }
    }

    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? []) === [];
    }

    /** Montant et description ne se modifient qu'en brouillon. */
    public static function isEditable(string $status): bool
    {
        return $status === self::DRAFT;
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
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'Invalid flatrate amount');
        }
        if ($cents <= 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'A flatrate amount must be positive');
        }

        return $cents;
    }

    /**
     * Séparation des tâches : le Responsable BU ne valide pas un forfait qu'il a lui-même chiffré.
     *
     * @throws RuleViolation FLATRATE_SELF
     */
    public static function assertSeparation(int $authorUsersId, int $deciderUsersId): void
    {
        if ($authorUsersId > 0 && $authorUsersId === $deciderUsersId) {
            throw new RuleViolation(RuleViolation::FLATRATE_SELF, 'The author of a flatrate cannot validate it');
        }
    }
}
