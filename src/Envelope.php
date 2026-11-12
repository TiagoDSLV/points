<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonDBTM;
use Session;

/**
 * Enveloppe de points (table glpi_plugin_points_envelopes).
 *
 * Création : par Ledger::createEnvelope seulement (enveloppe + opération + lot en une transaction) ; l'ajout par
 * l'API ou un formulaire générique est refusé. Modification : nom et case « reportable » ; dates, crédit initial et
 * contrat sont figés. Aucune suppression. Lecture : droit plugin_points_balance ; écriture : plugin_points_envelope.
 */
class Envelope extends CommonDBTM
{
    use ProtectsFields;

    // src/CommonGLPI.php:109 en 12.0.1 : propriété typée ; la recherche s'appuie sur ce droit de lecture
    public static string $rightname = Rights::BALANCE;

    public bool $dohistory = false;

    /** Champs que l'update ne peut pas changer. */
    private const FROZEN = ['contracts_id', 'credit', 'begin_date', 'end_date', 'entities_id', 'is_recursive', 'users_id', 'date_creation'];

    public static function getTypeName($nb = 0)
    {
        return __('Envelope', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-wallet';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Rights::BALANCE, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(Rights::ENVELOPE, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Rights::ENVELOPE, UPDATE);
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
        return static::canCreate() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? $this->input['entities_id'] ?? -1));
    }

    public function canUpdateItem(): bool
    {
        return static::canUpdate() && Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
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
     * Une enveloppe naît avec son opération et son lot : seul Ledger::createEnvelope la crée.
     */
    public function prepareInputForAdd($input)
    {
        Session::addMessageAfterRedirect(__s('An envelope is created with its initial credit from the contract page', 'points'), false, ERROR);

        return false;
    }

    public function prepareInputForUpdate($input)
    {
        if ($this->changedProtectedFields($input, self::FROZEN) !== []) {
            Session::addMessageAfterRedirect(__s('The dates, the initial credit and the contract of an envelope cannot be changed', 'points'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        return false;
    }

    public function rawSearchOptions()
    {
        $table = static::getTable();

        return [
            ['id' => 'common', 'name' => __('Characteristics')],
            ['id' => '1', 'table' => $table, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => '2', 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'massiveaction' => false, 'datatype' => 'number'],
            ['id' => '3', 'table' => $table, 'field' => 'credit', 'name' => __('Initial credit', 'points'), 'datatype' => 'decimal', 'massiveaction' => false],
            ['id' => '4', 'table' => $table, 'field' => 'begin_date', 'name' => __('Begin date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '5', 'table' => $table, 'field' => 'end_date', 'name' => __('End date'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => '6', 'table' => $table, 'field' => 'is_carryover', 'name' => __('Carried over when it ends', 'points'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'massiveaction' => false, 'datatype' => 'dropdown'],
        ];
    }
}
