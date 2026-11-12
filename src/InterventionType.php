<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDropdown;
use Session;

/**
 * Type d'intervention (Assistance, Ordre de travail, Intervention urgente) : liste déroulante native
 * (table glpi_plugin_points_interventiontypes). Global (entité 0, récursif) : droit plugin_points_grid, sans contrôle
 * d'entité. Un type utilisé par un prix de grille, un réglage de ticket ou le paramétrage ne se supprime pas.
 */
class InterventionType extends CommonDropdown
{
    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::GRID;

    public bool $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return __('Intervention type', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
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
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checked(array $input): array|false
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__s('A name is required', 'points'), false, ERROR);

            return false;
        }
        // Global : jamais rattaché à une entité précise
        unset($input['entities_id'], $input['is_recursive']);

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->checked($input);
        if ($input === false || trim((string) ($input['name'] ?? '')) === '') {
            if ($input !== false) {
                Session::addMessageAfterRedirect(__s('A name is required', 'points'), false, ERROR);
            }

            return false;
        }
        $input['entities_id']  = 0;
        $input['is_recursive'] = 1;

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->checked($input);

        return $input === false ? false : parent::prepareInputForUpdate($input);
    }

    /**
     * Utilisé par un prix de grille, un réglage de ticket ou le paramétrage (type par défaut).
     */
    public static function isUsedById(int $id): bool
    {
        global $DB;

        foreach ([GridCost::getTable(), Ticket::getTable()] as $table) {
            if (count($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['plugin_points_interventiontypes_id' => $id], 'LIMIT' => 1])) > 0) {
                return true;
            }
        }

        return (int) Config::value('default_interventiontypes_id') === $id;
    }

    public function pre_deleteItem()
    {
        if (self::isUsedById((int) $this->getID())) {
            Session::addMessageAfterRedirect(__s('This intervention type is used by a grid, a ticket or the settings: it cannot be deleted', 'points'), false, ERROR);

            return false;
        }

        return parent::pre_deleteItem();
    }
}
