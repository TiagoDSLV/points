<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * Valeurs de configuration du contexte `plugin:points` : défauts et normalisation typée (docs/conception.md,
 * « Paramétrage »). Classe pure, sans dépendance à GLPI : la lecture et l'écriture en base sont dans Config.
 */
final class ConfigValues
{
    public const DEBIT_CLOSURE = 'closure';
    public const DEBIT_TASK    = 'task';

    /** Durée de vie maximale d'un point, en mois (règle de la direction : jamais plus de 2 ans). */
    public const MAX_RETENTION_MONTHS = 24;

    /**
     * @return array{contracttypes: list<int>, debit_moment: string, default_alert_threshold: int,
     *               allow_overdraft: int, calendars_id: int, default_interventiontypes_id: int,
     *               retention_months: int, flatrate_separation: int}
     */
    public static function defaults(): array
    {
        return [
            'contracttypes'                => [],
            'debit_moment'                 => self::DEBIT_CLOSURE,
            'default_alert_threshold'      => 80,
            'allow_overdraft'              => 1,
            'calendars_id'                 => 0,
            'default_interventiontypes_id' => 0, // posé à l'installation sur le type « Assistance »
            'retention_months'             => self::MAX_RETENTION_MONTHS,
            'flatrate_separation'          => 1,
        ];
    }

    /**
     * Ramène des valeurs brutes (chaînes lues en base, ou saisie d'un formulaire) à des valeurs typées valides.
     * Une clé absente ou invalide reprend son défaut ; `retention_months` est borné à 1..24.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        if (array_key_exists('contracttypes', $raw)) {
            $out['contracttypes'] = self::toIdList($raw['contracttypes']);
        }
        if (isset($raw['debit_moment']) && in_array($raw['debit_moment'], [self::DEBIT_CLOSURE, self::DEBIT_TASK], true)) {
            $out['debit_moment'] = $raw['debit_moment'];
        }
        $threshold = self::toInt($raw['default_alert_threshold'] ?? null);
        if ($threshold !== null && $threshold >= 1 && $threshold <= 100) {
            $out['default_alert_threshold'] = $threshold;
        }
        $overdraft = self::toInt($raw['allow_overdraft'] ?? null);
        if ($overdraft !== null) {
            $out['allow_overdraft'] = $overdraft === 0 ? 0 : 1;
        }
        foreach (['calendars_id', 'default_interventiontypes_id'] as $key) {
            $id = self::toInt($raw[$key] ?? null);
            if ($id !== null && $id >= 0) {
                $out[$key] = $id;
            }
        }
        $separation = self::toInt($raw['flatrate_separation'] ?? null);
        if ($separation !== null) {
            $out['flatrate_separation'] = $separation === 0 ? 0 : 1;
        }
        $retention = self::toInt($raw['retention_months'] ?? null);
        if ($retention !== null) {
            $out['retention_months'] = self::clampRetention($retention);
        }

        return $out;
    }

    /**
     * Contrôle strict d'une saisie : renvoie les clés dont la valeur est invalide (liste vide = saisie valide).
     * Contrairement à normalize() (lecture tolérante), rien n'est corrigé : l'appelant refuse la saisie entière.
     * Les clés inconnues sont ignorées.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function validate(array $input): array
    {
        $invalid = [];
        foreach (array_keys(self::defaults()) as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            $int   = self::toInt($value);
            $ok    = match ($key) {
                'contracttypes'           => self::isValidIdList($value),
                'debit_moment'            => in_array($value, [self::DEBIT_CLOSURE, self::DEBIT_TASK], true),
                'default_alert_threshold' => $int !== null && $int >= 1 && $int <= 100,
                'allow_overdraft', 'flatrate_separation' => $int === 0 || $int === 1,
                'calendars_id', 'default_interventiontypes_id' => $int !== null && $int >= 0,
                'retention_months'        => $int !== null && $int >= 1 && $int <= self::MAX_RETENTION_MONTHS,
                default                   => false,
            };
            if (!$ok) {
                $invalid[] = $key;
            }
        }

        return $invalid;
    }

    private static function isValidIdList(mixed $value): bool
    {
        // Liste multiple vide : le formulaire de GLPI poste un champ caché `contracttypes` vide
        if ($value === '') {
            return true;
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            $id = self::toInt($item);
            if ($id === null || $id < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Borne la durée de vie d'un point : au moins 1 mois, jamais plus de 24.
     */
    public static function clampRetention(int $months): int
    {
        return max(1, min(self::MAX_RETENTION_MONTHS, $months));
    }

    /**
     * Valeurs typées → chaînes stockées dans glpi_configs (la liste de types de contrat est en JSON).
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function toStorage(array $values): array
    {
        $stored = [];
        foreach (self::normalize($values) as $key => $value) {
            $stored[$key] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;
        }

        return $stored;
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function toIdList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::toInt($item);
            if ($id !== null && $id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
