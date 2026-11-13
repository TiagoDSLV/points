<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Page « Points » de l'interface simplifiée (profil helpdesk, Responsable client) : pour chaque contrat à points de ses entités actives, solde,
 * enveloppes (échéances, reste) et registre, en LECTURE seule (aucun formulaire). Droit plugin_points_balance READ.
 *
 * La fiche contrat native est inaccessible en interface simplifiée (contrôleur moderne, stratégie `central_access` : src/Glpi/Http/Firewall.php:152
 * en 12.0.1) ; cette page est le seul accès. Les contrats listés sont ceux dont l'entité (propre, sans récursivité : les lignes du plugin portent
 * l'entité du contrat) est une entité active de l'utilisateur. Le registre n'expose ni l'auteur de l'opération ni son commentaire (notes internes).
 */
final class Simplified
{
    /**
     * Contrats à points visibles de l'utilisateur courant, dans ses entités actives.
     *
     * @return list<array{id: int, name: string, num: string, entity: string}>
     */
    public static function contracts(): array
    {
        global $DB;

        if (!Session::haveRight(Rights::BALANCE, READ)) {
            return [];
        }
        $table = Contract::getTable();
        $out   = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_contracts.id AS id', 'glpi_contracts.name AS name', 'glpi_contracts.num AS num', 'glpi_entities.completename AS entity'],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_contracts' => ['ON' => [$table => 'contracts_id', 'glpi_contracts' => 'id']],
                'glpi_entities'  => ['ON' => [$table => 'entities_id', 'glpi_entities' => 'id']],
            ],
            'WHERE'      => [
                'glpi_contracts.is_deleted'  => 0,
                'glpi_contracts.is_template' => 0,
                getEntitiesRestrictCriteria($table, 'entities_id', '', false),
            ],
            'ORDER'      => ['glpi_entities.completename', 'glpi_contracts.name', 'glpi_contracts.id'],
        ]) as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'num' => (string) $row['num'], 'entity' => (string) $row['entity']];
        }

        return $out;
    }

    public static function show(): void
    {
        $contracts = [];
        foreach (self::contracts() as $contract) {
            $contracts[] = $contract + ['ledger' => Contract::ledgerView($contract['id'], false)];
        }
        TemplateRenderer::getInstance()->display('@points/simplified.html.twig', ['contracts' => $contracts]);
    }
}
