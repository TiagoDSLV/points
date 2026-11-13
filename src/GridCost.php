<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use GlpiPlugin\Points\Domain\Money;
use Session;

/**
 * Coût d'une version de grille (table glpi_plugin_points_gridcosts) : type d'intervention × type de jour
 * (1 semaine, 2 samedi, 3 dimanche et jour férié). Unique par (version, type, jour). Plus modifiable ni supprimable
 * dès que la version est utilisée par une opération (GridVersion::isUsed). Globale : droit plugin_points_grid.
 */
class GridCost extends CommonDBTM
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::GRID;

    public bool $dohistory = false;

    private const FROZEN = ['plugin_points_gridversions_id', 'plugin_points_interventiontypes_id', 'daytype', 'entities_id', 'is_recursive', 'date_creation'];

    public static function getTypeName($nb = 0)
    {
        return __('Grid price', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-coin';
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

    public function prepareInputForAdd($input)
    {
        global $DB;

        $versionId = (int) ($input['plugin_points_gridversions_id'] ?? 0);
        $typeId    = (int) ($input['plugin_points_interventiontypes_id'] ?? 0);
        $daytype   = (int) ($input['daytype'] ?? 0);
        if ($versionId < 1 || count($DB->request(['SELECT' => ['id'], 'FROM' => GridVersion::getTable(), 'WHERE' => ['id' => $versionId], 'LIMIT' => 1])) === 0) {
            Session::addMessageAfterRedirect(__s('Unknown grid version', 'points'), false, ERROR);

            return false;
        }
        if ($typeId < 1 || count($DB->request(['SELECT' => ['id'], 'FROM' => InterventionType::getTable(), 'WHERE' => ['id' => $typeId], 'LIMIT' => 1])) === 0) {
            Session::addMessageAfterRedirect(__s('Unknown intervention type', 'points'), false, ERROR);

            return false;
        }
        if ($daytype < 1 || $daytype > 3) {
            Session::addMessageAfterRedirect(__s('The day type must be 1 (week), 2 (Saturday) or 3 (Sunday and holiday)', 'points'), false, ERROR);

            return false;
        }
        if (GridVersion::isUsed($versionId)) {
            Session::addMessageAfterRedirect(__s('This version is already used by an operation: create a new version instead', 'points'), false, ERROR);

            return false;
        }
        $cost = $this->validCost($input);
        if ($cost === null) {
            return false;
        }
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_points_gridversions_id' => $versionId, 'plugin_points_interventiontypes_id' => $typeId, 'daytype' => $daytype],
            'LIMIT'  => 1,
        ]);
        if (count($exists) > 0) {
            Session::addMessageAfterRedirect(__s('This price already exists for this version', 'points'), false, ERROR);

            return false;
        }
        $input['cost']         = $cost;
        $input['entities_id']  = 0;
        $input['is_recursive'] = 1;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('Only the price of a grid cell can be changed', 'points'), false, ERROR);

            return false;
        }
        if (GridVersion::isUsed((int) $this->fields['plugin_points_gridversions_id'])) {
            Session::addMessageAfterRedirect(__s('This version is already used by an operation: create a new version instead', 'points'), false, ERROR);

            return false;
        }
        if (array_key_exists('cost', $input)) {
            $cost = $this->validCost($input);
            if ($cost === null) {
                return false;
            }
            $input['cost'] = $cost;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        if (GridVersion::isUsed((int) $this->fields['plugin_points_gridversions_id'])) {
            Session::addMessageAfterRedirect(__s('This version is already used by an operation: it cannot be deleted', 'points'), false, ERROR);

            return false;
        }

        return true;
    }

    /**
     * Coût normalisé à deux décimales, ou null (message déjà signalé) s'il n'est pas un nombre positif ou nul.
     *
     * @param array<string, mixed> $input
     */
    private function validCost(array $input): ?string
    {
        try {
            $cents = Money::parse((string) ($input['cost'] ?? ''));
        } catch (\InvalidArgumentException) {
            $cents = -1;
        }
        if ($cents < 0) {
            Session::addMessageAfterRedirect(__s('A price must be a number, zero or more', 'points'), false, ERROR);

            return null;
        }

        return Money::format($cents);
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'daytype', 'name' => __('Day type', 'points'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'cost', 'name' => __('Price', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
        ];
    }
}
