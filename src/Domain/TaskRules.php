<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Décompte d'une tâche de ticket (docs/conception.md, règles 1 à 4) : minutes comptées, type de jour, version de grille
 * applicable, points, drapeaux de contrôle. Classe pure, sans dépendance à GLPI (le calendrier, la grille et les coûts
 * sont fournis par l'appelant). Montants en centièmes entiers (Money), jamais de flottant.
 */
final class TaskRules
{
    public const DAY_WEEK          = 1;
    public const DAY_SATURDAY      = 2;
    public const DAY_SUNDAY        = 3; // dimanche et jour férié

    public const FLAG_SATURDAY  = 'saturday';
    public const FLAG_SUNDAY    = 'sunday';
    public const FLAG_HOLIDAY   = 'holiday';
    public const FLAG_OFF_HOURS = 'off_hours';

    /** Quart d'heure, en minutes. */
    private const QUARTER = 15;

    /**
     * Règle 1 : tout quart d'heure commencé est dû ; 0 minute = 0 point.
     *
     * @throws \InvalidArgumentException si la durée est négative
     */
    public static function countedMinutes(int $minutes): int
    {
        if ($minutes < 0) {
            throw new \InvalidArgumentException('Durée négative : ' . $minutes);
        }

        return intdiv($minutes + self::QUARTER - 1, self::QUARTER) * self::QUARTER;
    }

    /**
     * Même règle depuis une durée en secondes (actiontime d'une tâche GLPI) : 1 seconde compte un quart d'heure.
     *
     * @throws \InvalidArgumentException si la durée est négative
     */
    public static function countedMinutesFromSeconds(int $seconds): int
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Durée négative : ' . $seconds);
        }
        $quarterSeconds = self::QUARTER * 60;

        return intdiv($seconds + $quarterSeconds - 1, $quarterSeconds) * self::QUARTER;
    }

    /**
     * Règle 3 : dimanche ou jour férié → 3 ; samedi → 2 ; sinon 1. Le caractère férié vient du calendrier (booléen).
     *
     * @param string $date AAAA-MM-JJ ou horodatage
     */
    public static function dayType(string $date, bool $holiday): int
    {
        $weekday = self::weekday($date);
        if ($holiday || $weekday === 7) {
            return self::DAY_SUNDAY;
        }

        return $weekday === 6 ? self::DAY_SATURDAY : self::DAY_WEEK;
    }

    /**
     * Drapeaux de contrôle d'une tâche (lot e : reviews), dans l'ordre : saturday, sunday, holiday, off_hours.
     *
     * @return list<string>
     */
    public static function flags(string $date, bool $holiday, bool $offHours): array
    {
        $weekday = self::weekday($date);
        $flags   = [];
        if ($weekday === 6) {
            $flags[] = self::FLAG_SATURDAY;
        }
        if ($weekday === 7) {
            $flags[] = self::FLAG_SUNDAY;
        }
        if ($holiday) {
            $flags[] = self::FLAG_HOLIDAY;
        }
        if ($offHours) {
            $flags[] = self::FLAG_OFF_HOURS;
        }

        return $flags;
    }

    /**
     * Règle 4 : version applicable = celle de la plus grande begin_date ≤ date de la tâche ; aucune → refus.
     *
     * @param list<array{id: int, begin_date: string}> $versions
     * @param string $date AAAA-MM-JJ ou horodatage
     * @return int identifiant de la version
     * @throws RuleViolation NO_GRID_VERSION
     */
    public static function applicableVersion(array $versions, string $date): int
    {
        $day  = Dates::day($date);
        $best = null;
        foreach ($versions as $version) {
            $begin = (string) $version['begin_date'];
            if ($begin === '' || $begin > $day) {
                continue;
            }
            // Plus grande begin_date ; à date égale, la version créée en dernier (plus grand id)
            if ($best === null || [$begin, $version['id']] > [$best['begin_date'], $best['id']]) {
                $best = $version;
            }
        }
        if ($best === null) {
            throw new RuleViolation(RuleViolation::NO_GRID_VERSION, 'No grid version applies at this date');
        }

        return (int) $best['id'];
    }

    /**
     * Règle 4 : points = arrondi au centième de coût × minutes comptées / unit_minutes (moitié vers le haut),
     * en centièmes entiers.
     *
     * @param int $costCents coût de l'unité, ≥ 0
     * @throws \InvalidArgumentException
     */
    public static function points(int $costCents, int $countedMinutes, int $unitMinutes): int
    {
        if ($costCents < 0 || $countedMinutes < 0 || $unitMinutes < 1) {
            throw new \InvalidArgumentException('Coût, durée ou unité invalide');
        }

        return intdiv(2 * $costCents * $countedMinutes + $unitMinutes, 2 * $unitMinutes);
    }

    /**
     * Décompte complet d'une tâche.
     *
     * @param int $seconds durée saisie (actiontime)
     * @param string $date date métier de la tâche (begin sinon date) : AAAA-MM-JJ ou horodatage
     * @param bool $holiday jour férié du calendrier
     * @param bool $offHours hors créneau du calendrier
     * @param int $unitMinutes unité de la grille (60 ou 15)
     * @param list<array{id: int, begin_date: string}> $versions versions de la grille du contrat
     * @param callable(int, int): ?int $costOf (version, type de jour) → coût en centièmes, null si absent
     * @return array{entered_seconds: int, counted_minutes: int, daytype: int, flags: list<string>, gridversions_id: int,
     *         cost: int, points: int, unit_minutes: int}
     * @throws RuleViolation NO_GRID_VERSION, NO_COST
     */
    public static function evaluate(int $seconds, string $date, bool $holiday, bool $offHours, int $unitMinutes, array $versions, callable $costOf): array
    {
        $counted = self::countedMinutesFromSeconds($seconds);
        $result  = [
            'entered_seconds' => $seconds,
            'counted_minutes' => $counted,
            'daytype'         => self::dayType($date, $holiday),
            'flags'           => self::flags($date, $holiday, $offHours),
            'gridversions_id' => 0,
            'cost'            => 0,
            'points'          => 0,
            'unit_minutes'    => $unitMinutes,
        ];
        if ($counted === 0) {
            return $result; // règle 1 : 0 minute = 0 point, aucune grille requise
        }
        $result['gridversions_id'] = self::applicableVersion($versions, $date);
        $cost = $costOf($result['gridversions_id'], $result['daytype']);
        if ($cost === null) {
            throw new RuleViolation(RuleViolation::NO_COST, 'No price for this intervention type and day type');
        }
        $result['cost']   = $cost;
        $result['points'] = self::points($cost, $counted, $unitMinutes);

        return $result;
    }

    /**
     * Jour de la semaine ISO (1 lundi … 7 dimanche) d'une date AAAA-MM-JJ ou d'un horodatage.
     */
    private static function weekday(string $date): int
    {
        return (int) (new \DateTimeImmutable(Dates::day($date)))->format('N');
    }
}
