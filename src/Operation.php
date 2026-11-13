<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\Money;
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
     * Lecture d'une opération par son identifiant (lecture du journal, sans contrôle de droit : l'appelant contrôle l'accès).
     * Seule lecture à l'unité du plugin : débit visé d'une review ou d'une compensation, opération d'origine d'une correction.
     *
     * @return array{id: int, type: string, amount: int, date: string, contracts_id: int, tickets_id: int, entities_id: int, itemtype: string, items_id: int, details: array<string, mixed>}|null
     */
    public static function read(int $id): ?array
    {
        global $DB;

        if ($id < 1) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['id', 'type', 'amount', 'date', 'contracts_id', 'tickets_id', 'entities_id', 'itemtype', 'items_id', 'details'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();

        return $row === null ? null : [
            'id' => (int) $row['id'], 'type' => (string) $row['type'], 'amount' => Money::parse((string) $row['amount']), 'date' => (string) $row['date'],
            'contracts_id' => (int) $row['contracts_id'], 'tickets_id' => (int) $row['tickets_id'], 'entities_id' => (int) $row['entities_id'],
            'itemtype' => (string) ($row['itemtype'] ?? ''), 'items_id' => (int) $row['items_id'],
            'details' => json_decode((string) ($row['details'] ?? ''), true) ?: [],
        ];
    }

    /**
     * Version de grille d'un débit de tâche (détails `gridversions_id`), 0 si l'opération n'en porte pas. Lecture du journal.
     */
    public static function gridVersionOf(int $operationId): int
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['details'], 'FROM' => self::getTable(), 'WHERE' => ['id' => $operationId], 'LIMIT' => 1])->current();

        return (int) (json_decode((string) ($row['details'] ?? ''), true)['gridversions_id'] ?? 0);
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
            OpType::EXPIRY_REVERSAL   => __('Expiry reversal', 'points'),
            OpType::CARRYOVER_OUT     => __('Carry-over out', 'points'),
            OpType::CARRYOVER_IN      => __('Carry-over in', 'points'),
            OpType::CORRECTION        => __('Correction', 'points'),
            OpType::REVIEW_ADJUSTMENT => __('Review adjustment', 'points'),
            OpType::COMPENSATION      => __('Compensation', 'points'),
            default                   => $type,
        };
    }
}
