<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * État de décompte d'une tâche de ticket (table glpi_plugin_points_tasks) : opération de débit et verrou. Lecture
 * seule pour tout le monde ; la ligne est écrite par Billing quand la tâche est débitée.
 */
class Task extends ReadOnlyRecord
{
    public static function getTypeName($nb = 0)
    {
        return __('Task counting', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-clock';
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return array_merge($this->commonSearchOptions(), [
            ['id' => '3', 'table' => $table, 'field' => 'tickettasks_id', 'name' => __('Task'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'operations_id', 'name' => __('Operation', 'points'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => '6', 'table' => $table, 'field' => 'is_locked', 'name' => __('Locked', 'points'), 'datatype' => 'bool', 'massiveaction' => false],
        ]);
    }
}
