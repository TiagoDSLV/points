<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Session;

/**
 * Champs internes du journal (motif ou commentaire, détails de calcul, auteur, opération corrigée) : lus seulement avec un droit interne,
 * c'est-à-dire plugin_points_envelope READ ou plugin_points_review READ (ADV, Responsable BU, Admin ; pas le Responsable client ni le technicien).
 * Soldes, type, montant, date, contrat et enveloppes restent lisibles avec plugin_points_balance.
 *
 * Voies : `unsetUndisclosedFields` (API v1 getItem, getItems et sous-éléments, colonnes de la recherche, et filtre `searchText` : API.php:1295 retire le
 * champ du filtre, qui n'est donc jamais appliqué, sans erreur : aucune sonde possible) ; `post_getFromDB` (getMultipleItems, pages natives) ;
 * options de recherche absentes (recherche native et export ne connaissent que `rawSearchOptions`, src/Glpi/Search/SearchOption.php:155-170 ; un
 * critère de la recherche native sur une option absente est refusé, 400) ; onglet du contrat (`Contract::ledgerView`). Les requêtes internes de
 * Ledger passent par `$DB->request`, jamais par ces classes.
 */
final class InternalFields
{
    public static function allowed(): bool
    {
        return Session::haveRight(Rights::ENVELOPE, READ) || Session::haveRight(Rights::REVIEW, READ);
    }

    /**
     * Retire les champs internes (appelé par `unsetUndisclosedFields`, que l'API v1 invoque pour getItem, getItems et ses sous-éléments, le filtre
     * `searchText` et les colonnes de la recherche : src/Glpi/Api/API.php:676,1295,1386,1823 en 12.0.1).
     *
     * @param array<string, mixed> $fields
     * @param list<string> $names
     */
    public static function unset(array &$fields, array $names): void
    {
        if (self::allowed()) {
            return;
        }
        foreach ($names as $name) {
            unset($fields[$name]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $blank champ => valeur neutre
     * @return array<string, mixed>
     */
    public static function blank(array $fields, array $blank): array
    {
        if (self::allowed()) {
            return $fields;
        }
        foreach ($blank as $name => $neutral) {
            if (array_key_exists($name, $fields)) {
                $fields[$name] = $neutral;
            }
        }

        return $fields;
    }
}
