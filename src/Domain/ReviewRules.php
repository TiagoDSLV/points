<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Contrôle d'un débit de tâche hors créneau ou férié (docs/conception.md, règle 10 et « Valeurs codées »). Classe pure,
 * sans dépendance à GLPI.
 *
 *   to_review → confirmed
 *             → adjusted (opération `review_adjustment` liée, + remboursement ou − débit supplémentaire)
 *
 * Une review ne se décide qu'une fois : confirmed et adjusted sont terminaux.
 */
final class ReviewRules
{
    public const TO_REVIEW = 'to_review';
    public const CONFIRMED = 'confirmed';
    public const ADJUSTED  = 'adjusted';

    /** Raisons reconnues, dans l'ordre où elles sont écrites (celui de TaskRules::flags). */
    private const REASONS = [TaskRules::FLAG_SATURDAY, TaskRules::FLAG_SUNDAY, TaskRules::FLAG_HOLIDAY, TaskRules::FLAG_OFF_HOURS];

    private const TRANSITIONS = [
        self::TO_REVIEW => [self::CONFIRMED, self::ADJUSTED],
        self::CONFIRMED => [],
        self::ADJUSTED  => [],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * Raison stockée d'une review : les drapeaux de la tâche reconnus, séparés par une virgule ; chaîne vide = aucun
     * contrôle à ouvrir.
     *
     * @param list<string> $flags
     */
    public static function reasonFromFlags(array $flags): string
    {
        return implode(',', array_values(array_filter(self::REASONS, static fn (string $reason): bool => in_array($reason, $flags, true))));
    }

    /**
     * @return list<string>
     */
    public static function reasons(string $stored): array
    {
        return array_values(array_filter(explode(',', $stored), static fn (string $reason): bool => in_array($reason, self::REASONS, true)));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws RuleViolation REVIEW_STATE
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new RuleViolation(RuleViolation::REVIEW_STATE, "A review cannot go from $from to $to");
        }
    }

    /**
     * Ajustement saisi → centièmes signés, jamais nul : + rembourse, − débite en plus.
     *
     * @throws RuleViolation INVALID_AMOUNT
     */
    public static function parseAdjustment(string $text): int
    {
        try {
            $cents = Money::parse($text);
        } catch (\InvalidArgumentException) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'Invalid adjustment');
        }
        if ($cents === 0) {
            throw new RuleViolation(RuleViolation::INVALID_AMOUNT, 'An adjustment cannot be zero');
        }

        return $cents;
    }
}
