<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Session;

/**
 * Ligne du journal (operations, lots, allocations) : lecture seule pour tout le monde. Le journal est en AJOUT SEUL et
 * n'est écrit que par Ledger (`$DB->insert`, sans passer par ces classes) : ni création, ni modification, ni
 * suppression par l'API, la recherche ou un formulaire, même pour un Super-Admin.
 *
 * Lecture : droit plugin_points_balance (READ) et accès à l'entité de la ligne (copie de celle du contrat) ; le droit
 * du cœur sur les contrats n'est pas exigé pour voir un solde.
 */
abstract class ReadOnlyRecord extends CommonDBTM
{
    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée
    public static string $rightname = Rights::BALANCE;

    public bool $dohistory = false;

    public static function canView(): bool
    {
        return Session::haveRight(Rights::BALANCE, READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        return static::canView() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
    }

    public function canCreateItem(): bool
    {
        return false;
    }

    public function canUpdateItem(): bool
    {
        return false;
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    /**
     * Dernier verrou, au cas où un chemin contournerait les can* (src/CommonDBTM.php:2198 : delete() et purge
     * passent par pre_deleteItem).
     */
    public function pre_deleteItem()
    {
        return false;
    }

    public function prepareInputForAdd($input)
    {
        return false;
    }

    public function prepareInputForUpdate($input)
    {
        return false;
    }

    /**
     * Options de recherche communes : identifiant et entité. Les classes filles ajoutent leurs champs.
     *
     * @return list<array<string, mixed>>
     */
    protected function commonSearchOptions(): array
    {
        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '2', 'table' => static::getTable(), 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }
}
