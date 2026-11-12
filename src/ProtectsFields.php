<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\Money;

/**
 * Refus de modifier des champs figés par un update (API ou formulaire) : un champ envoyé avec la valeur déjà
 * enregistrée n'est pas une modification (un formulaire renvoie tous ses champs).
 */
trait ProtectsFields
{
    /**
     * @param array<string, mixed> $input
     * @param list<string> $names champs figés
     * @return list<string> champs figés dont la valeur envoyée diffère de celle de la base
     */
    private function changedProtectedFields(array $input, array $names): array
    {
        $changed = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            if (!self::sameValue((string) ($this->fields[$name] ?? ''), (string) $input[$name])) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    private static function sameValue(string $stored, string $sent): bool
    {
        $decimal = '/^-?\d+(?:[.,]\d+)?$/';
        if (preg_match($decimal, $stored) === 1 && preg_match($decimal, $sent) === 1) {
            return Money::parse($stored) === Money::parse($sent);
        }

        return $stored === $sent;
    }
}
