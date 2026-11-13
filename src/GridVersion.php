<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use GlpiPlugin\Points\Domain\Dates;
use Session;

/**
 * Version datée d'une grille (table glpi_plugin_points_gridversions) : applicable à partir de `begin_date`
 * (docs/conception.md, règle 4). Une version déjà utilisée par un débit de tâche (colonne indexée de l'état des tâches)
 * n'est plus modifiable ni supprimable : on en crée une nouvelle. Les grilles sont globales (entité 0, récursives) :
 * le cloisonnement est le droit plugin_points_grid, sans contrôle d'entité.
 */
class GridVersion extends CommonDBTM
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::GRID;

    public bool $dohistory = false;

    private const FROZEN = ['plugin_points_grids_id', 'entities_id', 'is_recursive', 'date_creation'];

    public static function getTypeName($nb = 0)
    {
        return __('Grid version', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-event';
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
     * Un débit de tâche s'appuie-t-il sur cette version ? Colonne indexée `plugin_points_gridversions_id` de l'état des tâches, écrite dans
     * la transaction du débit (Billing::lock) : plus de recherche dans le texte des détails du journal.
     */
    public static function isUsed(int $versionId): bool
    {
        global $DB;

        return $versionId > 0 && count($DB->request(['SELECT' => ['id'], 'FROM' => Task::getTable(), 'WHERE' => ['plugin_points_gridversions_id' => $versionId], 'LIMIT' => 1])) > 0;
    }

    public function prepareInputForAdd($input)
    {
        global $DB;

        $gridId = (int) ($input['plugin_points_grids_id'] ?? 0);
        if ($gridId < 1 || count($DB->request(['SELECT' => ['id'], 'FROM' => Grid::getTable(), 'WHERE' => ['id' => $gridId], 'LIMIT' => 1])) === 0) {
            Session::addMessageAfterRedirect(__s('Unknown grid', 'points'), false, ERROR);

            return false;
        }
        if (!$this->validDate($input, $gridId, 0)) {
            return false;
        }
        $input['entities_id']  = 0;
        $input['is_recursive'] = 1;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('The grid of a version cannot be changed', 'points'), false, ERROR);

            return false;
        }
        if (self::isUsed((int) $this->getID())) {
            Session::addMessageAfterRedirect(__s('This version is already used by an operation: create a new version instead', 'points'), false, ERROR);

            return false;
        }
        if (isset($input['begin_date']) && !$this->validDate($input, (int) $this->fields['plugin_points_grids_id'], (int) $this->getID())) {
            return false;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        if (self::isUsed((int) $this->getID())) {
            Session::addMessageAfterRedirect(__s('This version is already used by an operation: it cannot be deleted', 'points'), false, ERROR);

            return false;
        }

        return true;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        // Version inutilisée (pre_deleteItem) : ses coûts n'ont jamais servi à un débit
        $DB->delete(GridCost::getTable(), ['plugin_points_gridversions_id' => $this->getID()]);
    }

    /**
     * Date de début valide et unique dans la famille.
     *
     * @param array<string, mixed> $input
     */
    private function validDate(array $input, int $gridId, int $exceptId): bool
    {
        global $DB;

        try {
            $date = Dates::check((string) ($input['begin_date'] ?? ''));
        } catch (\InvalidArgumentException) {
            Session::addMessageAfterRedirect(__s('A date is not valid', 'points'), false, ERROR);

            return false;
        }
        $where = ['plugin_points_grids_id' => $gridId, 'begin_date' => $date];
        if ($exceptId > 0) {
            $where['NOT'] = ['id' => $exceptId];
        }
        if (count($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1])) > 0) {
            Session::addMessageAfterRedirect(__s('A version of this grid already starts on this date', 'points'), false, ERROR);

            return false;
        }

        return true;
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'begin_date', 'name' => __('Begin date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text', 'massiveaction' => false],
        ];
    }
}
