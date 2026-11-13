<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * Liste centrale des droits du plugin et de leurs bits (docs/conception.md, « Droits du plugin »).
 *
 * Classe pure, sans dépendance à GLPI : utilisée par l'installation, la désinstallation, l'onglet de profil
 * et les tests unitaires. Les valeurs des bits reprennent les constantes du cœur (src/autoload/constants.php:67-71
 * en GLPI 12.0.1 : READ 1, UPDATE 2, CREATE 4, PURGE 16) ; VALIDATE 1024 est un bit propre au plugin.
 */
final class Rights
{
    public const READ     = 1;
    public const UPDATE   = 2;
    public const CREATE   = 4;
    public const PURGE    = 16;
    public const VALIDATE = 1024;

    public const BALANCE      = 'plugin_points_balance';
    public const TICKET       = 'plugin_points_ticket';
    public const ENVELOPE     = 'plugin_points_envelope';
    public const FLATRATE     = 'plugin_points_flatrate';
    public const REVIEW       = 'plugin_points_review';
    public const COMPENSATION = 'plugin_points_compensation';
    public const GRID         = 'plugin_points_grid';
    public const CONFIG       = 'plugin_points_config';

    /**
     * Bits proposés par droit, dans l'ordre d'affichage de la matrice.
     *
     * @return array<string, list<int>>
     */
    public static function definitions(): array
    {
        return [
            self::BALANCE      => [self::READ],
            self::TICKET       => [self::READ, self::UPDATE],
            self::ENVELOPE     => [self::READ, self::CREATE, self::UPDATE],
            self::FLATRATE     => [self::READ, self::CREATE, self::UPDATE, self::VALIDATE],
            self::REVIEW       => [self::READ, self::UPDATE],
            self::COMPENSATION => [self::READ, self::CREATE, self::VALIDATE],
            self::GRID         => [self::READ, self::CREATE, self::UPDATE, self::PURGE],
            self::CONFIG       => [self::READ, self::UPDATE],
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Tous les bits d'un droit réunis (valeur donnée au Super-Admin).
     */
    public static function fullMask(string $name): int
    {
        $bits = self::definitions()[$name] ?? null;
        if ($bits === null) {
            throw new \InvalidArgumentException('Droit inconnu : ' . $name);
        }

        return array_sum($bits);
    }

    /**
     * @return array<string, int> nom du droit => masque complet
     */
    public static function fullMasks(): array
    {
        $masks = [];
        foreach (self::names() as $name) {
            $masks[$name] = self::fullMask($name);
        }

        return $masks;
    }

    /**
     * Un masque ne peut contenir que des bits proposés pour ce droit.
     */
    public static function isValidMask(string $name, int $mask): bool
    {
        $bits = self::definitions()[$name] ?? null;

        return $bits !== null && $mask >= 0 && ($mask & ~array_sum($bits)) === 0;
    }
}
