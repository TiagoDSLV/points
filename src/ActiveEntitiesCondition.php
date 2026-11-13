<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\DBAL\QueryExpression;
use Session;

/**
 * Condition de jointure évaluée AU MOMENT DE LA REQUÊTE : la ligne Points jointe doit être de l'entité propre d'une entité active de
 * l'utilisateur COURANT, et l'utilisateur doit avoir plugin_points_balance READ. GLPI met les options de recherche en cache statique pour tout le
 * processus (src/Glpi/Search/SearchOption.php:84,161-168,435 en 12.0.1) alors que des actions automatiques enchaînent plusieurs utilisateurs dans un
 * même processus (src/SavedSearch_Alert.php:358-372) : un tableau de critères figé à la définition de l'option servirait les entités du premier. Les
 * conditions de jointure passent par `QueryExpression::getValue()` au moment où la requête est construite (src/Glpi/Search/Provider/SQLProvider.php:3086-3110
 * pour la substitution des repères, `computeComplexJoinID` : 4080-4090 pour l'identifiant de jointure), d'où cette sous-classe qui relit la session
 * à chaque appel. Les entités sont des entiers : aucune valeur saisie n'entre dans le SQL.
 */
final class ActiveEntitiesCondition extends QueryExpression
{
    public function __construct()
    {
        parent::__construct('NEWTABLE.entities_id IN (-1)');
    }

    public function getValue(): string
    {
        $active = [];
        if (Session::haveRight(Rights::BALANCE, READ)) {
            $active = array_map('intval', array_values($_SESSION['glpiactiveentities'] ?? []));
        }

        return 'NEWTABLE.entities_id IN (' . implode(', ', $active === [] ? [-1] : $active) . ')';
    }
}
