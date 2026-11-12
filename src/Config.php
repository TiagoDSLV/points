<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonGLPI;
use Config as GlpiConfig;
use Session;

/**
 * Paramétrage du plugin (contexte `plugin:points`) : lecture typée avec défauts, écriture validée, entrée de menu
 * « Configuration > Points ». Les défauts et la normalisation sont dans ConfigValues (pure, testée sans GLPI).
 */
class Config extends CommonGLPI
{
    public const CONTEXT = 'plugin:points';

    // Droit du plugin (jamais `config` : docs/conception.md). `string` typé : src/CommonGLPI.php:109 en 12.0.1.
    public static string $rightname = Rights::CONFIG;

    public static function getTypeName($nb = 0)
    {
        return __('Points settings', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    /**
     * Entrée du menu Configuration ; absente sans le droit de lecture (src/Html.php:959-985 : MENU_TOADD).
     */
    public static function getMenuContent()
    {
        // Visible avec le droit du paramétrage OU celui des grilles ; chaque page garde son propre contrôle de droit
        $config = static::canView();
        if (!$config && !Session::haveRight(Rights::GRID, READ)) {
            return false;
        }

        return [
            'title' => static::getTypeName(),
            'page'  => $config ? static::getFormURL(false) : '/plugins/points/front/grid.php',
            'icon'  => static::getIcon(),
        ];
    }

    /**
     * Configuration typée : valeurs lues en base, normalisées, défauts pour tout ce qui manque.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return ConfigValues::normalize(GlpiConfig::getConfigurationValues(self::CONTEXT));
    }

    public static function value(string $key): mixed
    {
        $values = self::get();
        if (!array_key_exists($key, $values)) {
            throw new \InvalidArgumentException('Clé de configuration inconnue : ' . $key);
        }

        return $values[$key];
    }

    /**
     * Enregistre les clés fournies (les autres gardent leur valeur). Une saisie invalide est REFUSÉE en entier : rien
     * n'est écrit et la liste des messages d'erreur est renvoyée (vide = enregistré). Droit UPDATE sur
     * `plugin_points_config` exigé ici aussi, pas seulement dans la page.
     *
     * @param array<string, mixed> $input
     * @return list<string> messages d'erreur
     */
    public static function save(array $input): array
    {
        Session::checkRight(Rights::CONFIG, UPDATE);

        $input   = array_intersect_key($input, ConfigValues::defaults());
        $invalid = ConfigValues::validate($input);
        if ($invalid !== []) {
            return array_map(
                static fn (string $key): string => sprintf(__('Invalid value for the setting "%s": nothing was saved', 'points'), $key),
                $invalid
            );
        }
        $stored = ConfigValues::toStorage(array_merge(self::get(), $input));
        GlpiConfig::setConfigurationValues(self::CONTEXT, array_intersect_key($stored, $input));

        return [];
    }

    /**
     * Valeurs du formulaire → entrée de save() : la liste multiple vide n'envoie rien, la clé `_contracttypes_defined` le dit.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function inputFromForm(array $post): array
    {
        $input = [];
        foreach (array_keys(ConfigValues::defaults()) as $key) {
            if (array_key_exists($key, $post)) {
                $input[$key] = $post[$key];
            }
        }
        if (!isset($input['contracttypes']) && isset($post['_contracttypes_defined'])) {
            $input['contracttypes'] = [];
        }

        return $input;
    }

    /**
     * Types d'intervention (id => nom) pour la liste du formulaire.
     *
     * @return array<int, string>
     */
    public static function interventionTypes(): array
    {
        global $DB;

        $types = [0 => '-----'];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_points_interventiontypes', 'ORDER' => 'id']) as $row) {
            $types[(int) $row['id']] = (string) $row['name'];
        }

        return $types;
    }
}
