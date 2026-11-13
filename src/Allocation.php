<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * Affectation d'une opération à un lot (table glpi_plugin_points_allocations) : lecture seule, écrite par Ledger.
 */
class Allocation extends ReadOnlyRecord
{
    public static function getTypeName($nb = 0)
    {
        return __('Allocation', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-split';
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return array_merge($this->commonSearchOptions(), [
            ['id' => '3', 'table' => $table, 'field' => 'amount', 'name' => __('Points', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
        ]);
    }
}
