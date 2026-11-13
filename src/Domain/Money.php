<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Montants en points : centièmes entiers en interne (jamais de flottant pour de l'argent), texte à deux décimales
 * pour la base (DECIMAL(12,2)) et l'affichage. Classe pure, sans dépendance à GLPI.
 */
final class Money
{
    /** Plus grand montant absolu, en centièmes : DECIMAL(12,2) de la base. */
    public const MAX_CENTS = 999_999_999_999;

    /**
     * Texte décimal (point ou virgule, signe facultatif) → centièmes. Plus de deux décimales : arrondi au centième,
     * moitié éloignée de zéro (calcul sur les chiffres, sans flottant). Un entier PHP vaut des points entiers.
     *
     * @throws \InvalidArgumentException si ce n'est pas un nombre décimal
     */
    public static function parse(string|int $value): int
    {
        if (is_int($value)) {
            if (abs($value) > intdiv(self::MAX_CENTS, 100)) {
                throw new \InvalidArgumentException('Montant trop grand : ' . $value);
            }

            return $value * 100;
        }
        $text = str_replace(',', '.', trim($value));
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('Montant invalide : ' . $value);
        }
        if (strlen(ltrim($m[2], '0')) > 10) {
            throw new \InvalidArgumentException('Montant trop grand : ' . $value);
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents    = (int) $m[2] * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return self::bounded($m[1] === '-' ? -$cents : $cents, $value);
    }

    /**
     * @throws \InvalidArgumentException au-delà de ±999 999 999 999 centièmes (DECIMAL(12,2) de la base)
     */
    private static function bounded(int $cents, string|int $value): int
    {
        if (abs($cents) > self::MAX_CENTS) {
            throw new \InvalidArgumentException('Montant trop grand : ' . $value);
        }

        return $cents;
    }

    /**
     * Centièmes → texte à deux décimales, point décimal (« 12.50 », « -0.05 »).
     */
    public static function format(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
