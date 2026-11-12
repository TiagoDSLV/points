<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Dates métier au format AAAA-MM-JJ (comparables comme des chaînes). Classe pure, sans dépendance à GLPI.
 */
final class Dates
{
    /**
     * @throws \InvalidArgumentException si la chaîne n'est pas une date AAAA-MM-JJ existante
     */
    public static function check(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Date invalide : ' . $date);
        }

        return $date;
    }

    /**
     * Jour d'une date ou d'un horodatage (« AAAA-MM-JJ hh:mm:ss » → « AAAA-MM-JJ »).
     */
    public static function day(string $dateOrTimestamp): string
    {
        return self::check(substr(trim($dateOrTimestamp), 0, 10));
    }

    /**
     * Ajoute des mois en gardant le quantième ; si le mois d'arrivée est plus court, dernier jour de ce mois
     * (31 janvier + 1 mois = 28 ou 29 février). Aucun débordement sur le mois suivant.
     */
    public static function addMonths(string $date, int $months): string
    {
        self::check($date);
        $index = (int) substr($date, 0, 4) * 12 + ((int) substr($date, 5, 2) - 1) + $months;
        $year  = intdiv($index, 12);
        $month = $index % 12 + 1;
        $day   = min((int) substr($date, 8, 2), (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year)));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function min(string $a, string $b): string
    {
        return $a <= $b ? $a : $b;
    }
}
