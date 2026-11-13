<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Session;

/**
 * Options de recherche ajoutées au contrat natif et à l'entité (hook `plugin_<clé>_getAddSearchOptionsNew`, src/Plugin.php:2171 en
 * 12.0.1) : le portefeuille de points se filtre et se trie depuis la recherche du contrat ou de l'entité. Elles lisent les colonnes réelles
 * du cache (glpi_plugin_points_contracts : balance, credited, consumed, consumed_pct, next_expiry), jamais le journal.
 *
 * Cloisonnement (docs/conception.md, « Cloisonnement ») :
 * - aucune option sans le droit plugin_points_balance READ ;
 * - la jointure ne retient que les lignes Points dont l'entité PROPRE du contrat est dans les entités actives de l'utilisateur COURANT
 *   (ActiveEntitiesCondition, relue à chaque requête : les options sont en cache statique pour tout le processus) ;
 *   le cache est aussi vidé à chaque changement de profil (`resetCache`, crochet CHANGE_PROFILE) pour que la présence des options suive le droit : un contrat récursif d'une entité mère, lisible par l'entité fille, n'expose pas ses points à
 *   cette dernière (même règle que l'onglet du contrat) ;
 * - aucun champ interne (InternalFields : commentaires, détails, auteurs) : seuls les champs du cache et de la grille sont proposés.
 */
final class SearchOptions
{
    /** Numéros des options ajoutées (au-dessus de ceux du cœur) : contrat 9701-9707, entité 9721-9725. */
    private const CONTRACT_BASE = 9701;
    private const ENTITY_BASE   = 9721;

    /**
     * Crochet CHANGE_PROFILE (src/Session.php:667 en 12.0.1) : les options sont reconstruites avec le droit du nouveau profil.
     */
    public static function resetCache(): void
    {
        \Glpi\Search\SearchOption::clearSearchOptionCache(\Contract::class);
        \Glpi\Search\SearchOption::clearSearchOptionCache(\Entity::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forItemtype(string $itemtype): array
    {
        if ($itemtype !== \Contract::class && $itemtype !== \Entity::class) {
            return [];
        }
        if (!Session::haveRight(Rights::BALANCE, READ)) {
            return [];
        }
        $table = Contract::getTable();
        // Le contrat natif est lié à sa ligne Points par contrats_id ; l'entité l'est par ses contrats (une entité a plusieurs contrats :
        // valeurs regroupées dans la cellule)
        // Condition relue à chaque construction de requête (ActiveEntitiesCondition), jamais figée dans la définition mise en cache
        $join = ['jointype' => 'child', 'condition' => [new ActiveEntitiesCondition()]];
        $many = false;
        $base = self::CONTRACT_BASE;
        if ($itemtype === \Entity::class) {
            // contrats natifs ni à la corbeille ni modèles
            $join = $join + ['beforejoin' => ['table' => 'glpi_contracts', 'joinparams' => ['jointype' => 'child', 'condition' => ['NEWTABLE.is_deleted' => 0, 'NEWTABLE.is_template' => 0]]]];
            $many = true;
            $base = self::ENTITY_BASE;
        }
        $option = static function (int $offset, string $field, string $name, string $datatype, array $extra = []) use ($table, $join, $many, $base): array {
            return array_replace([
                'id'            => $base + $offset,
                'table'         => $table,
                'field'         => $field,
                'name'          => $name,
                'datatype'      => $datatype,
                'massiveaction' => false,
                'joinparams'    => $join,
                'forcegroupby'  => $many,
            ], $extra);
        };
        $options = [
            $option(0, 'balance', __('Points balance', 'points'), 'decimal'),
            $option(1, 'credited', __('Points credited', 'points'), 'decimal'),
            $option(2, 'consumed', __('Points consumed', 'points'), 'decimal'),
            $option(3, 'consumed_pct', __('Points consumed (%)', 'points'), 'decimal'),
            $option(4, 'next_expiry', __('Points next expiry', 'points'), 'date'),
        ];
        if ($itemtype === \Contract::class) {
            $options[] = $option(5, 'alert_threshold', __('Points alert threshold (%)', 'points'), 'number');
            $options[] = $option(6, 'plugin_points_grids_id', __('Points grid', 'points'), 'dropdown', [
                'table' => 'glpi_plugin_points_grids', 'field' => 'name',
                'joinparams' => ['beforejoin' => ['table' => $table, 'joinparams' => $join]],
            ]);
        }

        return $options;
    }
}
