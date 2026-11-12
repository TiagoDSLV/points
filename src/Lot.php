<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * Lot de points (table glpi_plugin_points_lots) : lecture seule, écrit par Ledger.
 */
class Lot extends ReadOnlyRecord
{
    public static function getTypeName($nb = 0)
    {
        return __('Lot', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-stack-2';
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return array_merge($this->commonSearchOptions(), [
            ['id' => '3', 'table' => $table, 'field' => 'origin_date', 'name' => __('Purchase date', 'points'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'expiry_date', 'name' => __('Expiry date', 'points'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'initial', 'name' => __('Initial points', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
        ]);
    }
}
