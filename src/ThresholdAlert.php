<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\AlertRules;
use NotificationEvent;
use Session;

/**
 * Alerte de seuil (docs/conception.md, règle 12). Par contrat à points actif, quand consommé / crédité des lots valides atteint le
 * seuil du contrat, l'événement natif `threshold` est levé UNE fois par passage du seuil : l'alerte native (`glpi_alerts`,
 * type Alert::THRESHOLD, `items_id` = ligne Points du contrat) mémorise le passage. Elle est effacée dès que le contrat repasse sous le
 * seuil : par Ledger dans la transaction de l'écriture qui l'a fait repasser (un crédit, un remboursement), ou par l'action automatique
 * si le seuil a changé ou si un lot a expiré ; un nouveau franchissement lève alors un nouvel événement.
 *
 * L'action automatique (`pointsalert`) ne lit pas le cache du contrat : le calcul porte sur les lots (Ledger::validTotals).
 */
final class ThresholdAlert
{
    public const EVENT = 'threshold';

    private const ALERTS = 'glpi_alerts';

    /**
     * Passage quotidien : lève l'événement des contrats qui viennent d'atteindre leur seuil, efface l'alerte de ceux qui sont repassés
     * dessous. Un échec sur un contrat n'arrête pas les autres (classe de l'exception seulement : le message peut contenir du SQL).
     *
     * @return array{checked: int, raised: int, reset: int, errors: list<string>}
     */
    public static function run(string $today): array
    {
        global $DB;

        $result = ['checked' => 0, 'raised' => 0, 'reset' => 0, 'errors' => []];
        $rows   = $DB->request([
            'SELECT'     => ['glpi_plugin_points_contracts.id AS id', 'glpi_plugin_points_contracts.contracts_id AS contracts_id', 'glpi_plugin_points_contracts.alert_threshold AS threshold'],
            'FROM'       => 'glpi_plugin_points_contracts',
            'INNER JOIN' => ['glpi_contracts' => ['ON' => ['glpi_plugin_points_contracts' => 'contracts_id', 'glpi_contracts' => 'id']]],
            'WHERE'      => ['glpi_plugin_points_contracts.is_active' => 1, 'glpi_contracts.is_deleted' => 0, 'glpi_contracts.is_template' => 0],
            'ORDER'      => 'glpi_plugin_points_contracts.id',
        ]);
        foreach ($rows as $row) {
            $result['checked']++;
            try {
                $totals  = Ledger::validTotals((int) $row['contracts_id'], $today);
                $reached = AlertRules::reached($totals['credited'], $totals['consumed'], (int) $row['threshold']);
                $exists  = self::exists((int) $row['id']);
                if ($reached && !$exists) {
                    if (self::raise((int) $row['id'], (int) $row['contracts_id'], $today)) {
                        $result['raised']++;
                    }
                } elseif (!$reached && $exists) {
                    self::clear((int) $row['id']);
                    $result['reset']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('contract %d: %s', (int) $row['contracts_id'], $e::class);
            }
        }

        return $result;
    }

    /**
     * Appelée par Ledger dans la transaction de l'écriture du journal : le contrat n'atteint plus son seuil, son alerte est effacée.
     *
     * @param list<array{origin_date: string, expiry_date: string, remaining: int, initial: int}> $lots
     */
    public static function resetIfBelow(int $rowId, array $lots, string $today): void
    {
        global $DB;

        if (!self::exists($rowId)) {
            return;
        }
        $threshold = (int) ($DB->request(['SELECT' => ['alert_threshold'], 'FROM' => 'glpi_plugin_points_contracts', 'WHERE' => ['id' => $rowId], 'LIMIT' => 1])->current()['alert_threshold'] ?? 0);
        $totals    = AlertRules::validTotals($lots, $today);
        if (!AlertRules::reached($totals['credited'], $totals['consumed'], $threshold)) {
            self::clear($rowId);
        }
    }

    public static function exists(int $rowId): bool
    {
        global $DB;

        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::ALERTS, 'WHERE' => self::key($rowId), 'LIMIT' => 1])) > 0;
    }

    /**
     * Lève l'événement puis mémorise le passage, SOUS LE VERROU du contrat : les totaux sont relus juste avant (un remboursement ou un crédit
     * survenu depuis le parcours ne doit pas produire une alerte périmée) ; plus atteint, ou déjà alerté, rien n'est levé. L'événement et
     * l'alerte sont écrits dans la même transaction : rien n'est mémorisé si la levée échoue (retentée au passage suivant).
     */
    private static function raise(int $rowId, int $contractsId, string $today): bool
    {
        global $DB;

        return Ledger::locked($contractsId, static function () use ($DB, $rowId, $contractsId, $today): bool {
            $setting = $DB->request(['SELECT' => ['alert_threshold', 'is_active'], 'FROM' => 'glpi_plugin_points_contracts', 'WHERE' => ['id' => $rowId], 'LIMIT' => 1])->current();
            $totals  = Ledger::validTotals($contractsId, $today);
            if ($setting === null || (int) $setting['is_active'] !== 1 || self::exists($rowId)
                || !AlertRules::reached($totals['credited'], $totals['consumed'], (int) $setting['alert_threshold'])) {
                return false;
            }
            $item = new Contract();
            if (!$item->getFromDB($rowId)) {
                return false;
            }
            if (!NotificationEvent::raiseEvent(self::EVENT, $item, ['entities_id' => (int) $item->fields['entities_id']])) {
                return false;
            }
            $DB->insert(self::ALERTS, self::key($rowId) + ['date' => Session::getCurrentTime() ?? date('Y-m-d H:i:s')]);

            return true;
        });
    }

    public static function clear(int $rowId): void
    {
        global $DB;

        $DB->delete(self::ALERTS, self::key($rowId));
    }

    /**
     * @return array{itemtype: class-string, items_id: int, type: int}
     */
    private static function key(int $rowId): array
    {
        return ['itemtype' => Contract::class, 'items_id' => $rowId, 'type' => \Alert::THRESHOLD];
    }
}
