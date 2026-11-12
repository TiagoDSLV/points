<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Session;

/**
 * Famille de grilles de prix (table glpi_plugin_points_grids) : Standard, Client VIP, Hospitalier, Astreinte HNO.
 * Globale (entité 0, récursive) : le cloisonnement est le droit plugin_points_grid, sans contrôle d'entité. L'unité de
 * prix (60 minutes, ou 15) ne change plus dès qu'une version de la famille a servi à un débit ; une famille utilisée
 * par un contrat ou un débit ne se supprime pas.
 */
class Grid extends CommonDBTM
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::GRID;

    public bool $dohistory = false;

    /** Unités de prix proposées (minutes) : par heure ou par quart d'heure. */
    public const UNITS = [60, 15];

    private const FROZEN = ['entities_id', 'is_recursive', 'date_creation'];

    public static function getTypeName($nb = 0)
    {
        return __('Price grid', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-table';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Rights::GRID, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(Rights::GRID, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::GRID, UPDATE);
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(Rights::GRID, PURGE);
    }

    public function canViewItem(): bool
    {
        return static::canView();
    }

    public function canCreateItem(): bool
    {
        return static::canCreate();
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate();
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return static::canPurge();
    }

    /**
     * Versions de la famille (id => begin_date), de la plus récente à la plus ancienne.
     *
     * @return array<int, string>
     */
    public static function versions(int $gridId): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'begin_date'], 'FROM' => GridVersion::getTable(), 'WHERE' => ['plugin_points_grids_id' => $gridId], 'ORDER' => ['begin_date DESC', 'id DESC']]) as $row) {
            $out[(int) $row['id']] = (string) $row['begin_date'];
        }

        return $out;
    }

    /**
     * Une version de la famille a-t-elle servi à un débit ?
     */
    public static function hasUsedVersion(int $gridId): bool
    {
        foreach (array_keys(self::versions($gridId)) as $versionId) {
            if (GridVersion::isUsed($versionId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un contrat Points l'utilise-t-il ?
     */
    public static function isUsedByContract(int $gridId): bool
    {
        global $DB;

        return count($DB->request(['SELECT' => ['id'], 'FROM' => Contract::getTable(), 'WHERE' => ['plugin_points_grids_id' => $gridId], 'LIMIT' => 1])) > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checked(array $input): array|false
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__s('A name is required', 'points'), false, ERROR);

            return false;
        }
        if (array_key_exists('unit_minutes', $input) && !in_array((int) $input['unit_minutes'], self::UNITS, true)) {
            Session::addMessageAfterRedirect(__s('The price unit must be 60 minutes (per hour) or 15 minutes (per quarter of an hour)', 'points'), false, ERROR);

            return false;
        }
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->checked($input);
        if ($input === false) {
            return false;
        }
        if (trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__s('A name is required', 'points'), false, ERROR);

            return false;
        }
        $input['unit_minutes'] = (int) ($input['unit_minutes'] ?? 60);
        $input['entities_id']  = 0;
        $input['is_recursive'] = 1;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('The scope of a grid cannot be changed', 'points'), false, ERROR);

            return false;
        }
        if (
            array_key_exists('unit_minutes', $input)
            && (int) $input['unit_minutes'] !== (int) $this->fields['unit_minutes']
            && self::hasUsedVersion((int) $this->getID())
        ) {
            Session::addMessageAfterRedirect(__s('The price unit cannot change: a version of this grid is already used by an operation', 'points'), false, ERROR);

            return false;
        }

        return $this->checked($input);
    }

    public function pre_deleteItem()
    {
        $id = (int) $this->getID();
        if (self::isUsedByContract($id) || self::hasUsedVersion($id)) {
            Session::addMessageAfterRedirect(__s('This grid is used by a contract or an operation: it cannot be deleted', 'points'), false, ERROR);

            return false;
        }

        return true;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // Famille inutilisée (pre_deleteItem) : versions et coûts n'ont jamais servi
        foreach (array_keys(self::versions((int) $this->getID())) as $versionId) {
            $DB->delete(GridCost::getTable(), ['plugin_points_gridversions_id' => $versionId]);
        }
        $DB->delete(GridVersion::getTable(), ['plugin_points_grids_id' => $this->getID()]);
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '1', 'table' => $table, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'unit_minutes', 'name' => __('Price unit (minutes)', 'points'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }
}
