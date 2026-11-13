<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\OpType;

/**
 * Opération du journal (table glpi_plugin_points_operations) : lecture seule, écrite par Ledger.
 */
class Operation extends ReadOnlyRecord
{
    public static function getTypeName($nb = 0)
    {
        return __('Operation', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-list-details';
    }

    /**
     * Champs internes d'une opération : vidés sans droit interne (InternalFields).
     */
    public function post_getFromDB()
    {
        $this->fields = InternalFields::blank($this->fields, ['comment' => '', 'details' => null, 'users_id' => 0, 'operations_id_origin' => 0]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function unsetUndisclosedFields(&$fields)
    {
        parent::unsetUndisclosedFields($fields);
        InternalFields::unset($fields, ['comment', 'details', 'users_id', 'operations_id_origin']);
    }

    public function rawSearchOptions()
    {
        $table   = static::getTable();
        $options = array_merge($this->commonSearchOptions(), [
            ['id' => '3', 'table' => $table, 'field' => 'type', 'name' => __('Type'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'amount', 'name' => __('Points', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'date', 'name' => __('Date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '7', 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
        ]);
        if (InternalFields::allowed()) {
            $options[] = ['id' => '6', 'table' => $table, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text', 'massiveaction' => false];
            $options[] = ['id' => '8', 'table' => 'glpi_users', 'field' => 'name', 'name' => __('User'), 'datatype' => 'dropdown', 'massiveaction' => false];
        }

        return $options;
    }

    /**
     * Libellé d'un type d'opération.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            OpType::CREDIT            => __('Credit', 'points'),
            OpType::IMPORT            => __('Import', 'points'),
            OpType::DEBIT_TASK        => __('Task debit', 'points'),
            OpType::DEBIT_FLATRATE    => __('Flat rate debit', 'points'),
            OpType::EXPIRY            => __('Expiry', 'points'),
            OpType::CARRYOVER_OUT     => __('Carry-over out', 'points'),
            OpType::CARRYOVER_IN      => __('Carry-over in', 'points'),
            OpType::CORRECTION        => __('Correction', 'points'),
            OpType::REVIEW_ADJUSTMENT => __('Review adjustment', 'points'),
            OpType::COMPENSATION      => __('Compensation', 'points'),
            default                   => $type,
        };
    }
}
