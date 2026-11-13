<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\TaskRules;
use Session;

/**
 * Écrans d'administration des grilles (Configuration > Points) : données des pages et enregistrement de la matrice
 * des prix. Toutes les écritures passent par les modèles Grid, GridVersion, GridCost, InterventionType (droit
 * plugin_points_grid, refus de modifier une version utilisée) : ce service n'écrit rien lui-même.
 */
final class GridAdmin
{
    /**
     * Types de jour dans l'ordre de la matrice.
     *
     * @return array<int, string>
     */
    public static function dayTypes(): array
    {
        return [
            TaskRules::DAY_WEEK     => __('Week', 'points'),
            TaskRules::DAY_SATURDAY => __('Saturday', 'points'),
            TaskRules::DAY_SUNDAY   => __('Sunday and holiday', 'points'),
        ];
    }

    /**
     * Données de la page « liste » : familles et types d'intervention.
     *
     * @return array<string, mixed>
     */
    public static function listData(): array
    {
        global $CFG_GLPI, $DB;

        $grids = [];
        foreach ($DB->request(['FROM' => Grid::getTable(), 'ORDER' => ['name', 'id']]) as $row) {
            $grids[] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'unit'     => (int) $row['unit_minutes'],
                'active'   => (int) $row['is_active'] === 1,
                'versions' => count(Grid::versions((int) $row['id'])),
            ];
        }
        $types = [];
        foreach ($DB->request(['FROM' => InterventionType::getTable(), 'ORDER' => ['id']]) as $row) {
            $types[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'comment' => (string) ($row['comment'] ?? '')];
        }

        return [
            'grids'      => $grids,
            'types'      => $types,
            'base_url'   => $CFG_GLPI['root_doc'] . '/plugins/points/front/',
            'config_url' => Config::getFormURL(),
            'can_create' => Session::haveRight(Rights::GRID, CREATE),
            'can_update' => Session::haveRight(Rights::GRID, UPDATE),
            'can_purge'  => Session::haveRight(Rights::GRID, PURGE),
            'units'      => [60 => __('Per hour (60 minutes)', 'points'), 15 => __('Per quarter of an hour (15 minutes)', 'points')],
        ];
    }

    /**
     * Données de la page d'une famille : réglage, versions datées et matrice type d'intervention × type de jour.
     *
     * @return array<string, mixed>
     * @throws NotFoundHttpException
     */
    public static function detailData(int $gridId): array
    {
        global $CFG_GLPI, $DB;

        $grid = new Grid();
        if (!$grid->getFromDB($gridId)) {
            throw new NotFoundHttpException();
        }
        $types = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => InterventionType::getTable(), 'ORDER' => ['id']]) as $row) {
            $types[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        $canUpdate = Session::haveRight(Rights::GRID, UPDATE);
        $versions  = [];
        $anyUsed   = false;
        $choices   = [0 => '-----'];
        foreach (Grid::versions($gridId) as $versionId => $begin) {
            $version = $DB->request(['FROM' => GridVersion::getTable(), 'WHERE' => ['id' => $versionId], 'LIMIT' => 1])->current();
            $costs   = [];
            foreach ($DB->request(['SELECT' => ['plugin_points_interventiontypes_id', 'daytype', 'cost'], 'FROM' => GridCost::getTable(), 'WHERE' => ['plugin_points_gridversions_id' => $versionId]]) as $row) {
                $costs[(int) $row['plugin_points_interventiontypes_id']][(int) $row['daytype']] = Money::format(Money::parse((string) $row['cost']));
            }
            $used    = GridVersion::isUsed($versionId);
            $anyUsed = $anyUsed || $used;
            $versions[] = [
                'id'       => $versionId,
                'begin'    => $begin,
                'comment'  => (string) ($version['comment'] ?? ''),
                'used'     => $used,
                'editable' => $canUpdate && !$used,
                'costs'    => $costs,
            ];
            $choices[$versionId] = $begin;
        }

        return [
            'grid'       => ['id' => $gridId, 'name' => (string) $grid->fields['name'], 'comment' => (string) ($grid->fields['comment'] ?? ''), 'unit' => (int) $grid->fields['unit_minutes'], 'active' => (int) $grid->fields['is_active']],
            'unit_locked' => $anyUsed,
            'in_use'     => $anyUsed || Grid::isUsedByContract($gridId),
            'versions'   => $versions,
            'types'      => $types,
            'day_types'  => self::dayTypes(),
            'copy_from'  => $choices,
            'units'      => [60 => __('Per hour (60 minutes)', 'points'), 15 => __('Per quarter of an hour (15 minutes)', 'points')],
            'base_url'   => $CFG_GLPI['root_doc'] . '/plugins/points/front/',
            'list_url'   => $CFG_GLPI['root_doc'] . '/plugins/points/front/grid.php',
            'can_update' => $canUpdate,
            'can_create' => Session::haveRight(Rights::GRID, CREATE),
            'can_purge'  => Session::haveRight(Rights::GRID, PURGE),
        ];
    }

    /**
     * Enregistre la matrice d'une version : une case vide supprime le prix, une case remplie le crée ou le modifie.
     * Chaque écriture passe par GridCost (droit, version non utilisée, prix valide) ; un refus est signalé par le modèle.
     *
     * @param array<int|string, mixed> $cells type d'intervention => type de jour => saisie
     * @return bool false si au moins une case a été refusée
     */
    public static function saveCosts(int $versionId, array $cells): bool
    {
        global $DB;

        $version = new GridVersion();
        $version->check($versionId, UPDATE);
        $ok = true;
        $typeIds = array_map(static fn (array $row): int => (int) $row['id'], iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => InterventionType::getTable()]), false));
        foreach ($typeIds as $typeId) {
            foreach (array_keys(self::dayTypes()) as $daytype) {
                $raw      = $cells[$typeId][$daytype] ?? null;
                $raw      = is_scalar($raw) ? trim((string) $raw) : '';
                $existing = $DB->request([
                    'SELECT' => ['id', 'cost'],
                    'FROM'   => GridCost::getTable(),
                    'WHERE'  => ['plugin_points_gridversions_id' => $versionId, 'plugin_points_interventiontypes_id' => $typeId, 'daytype' => $daytype],
                    'LIMIT'  => 1,
                ])->current();
                $cost = new GridCost();
                if ($raw === '') {
                    if ($existing !== null) {
                        $cost->check((int) $existing['id'], PURGE);
                        $ok = $cost->delete(['id' => (int) $existing['id']], true) && $ok;
                    }
                } elseif ($existing !== null) {
                    $cost->check((int) $existing['id'], UPDATE);
                    $ok = $cost->update(['id' => (int) $existing['id'], 'cost' => $raw]) && $ok;
                } else {
                    $input = ['plugin_points_gridversions_id' => $versionId, 'plugin_points_interventiontypes_id' => $typeId, 'daytype' => $daytype, 'cost' => $raw];
                    $cost->check(-1, CREATE, $input);
                    $ok = $cost->add($input) !== false && $ok;
                }
            }
        }

        return $ok;
    }

    /**
     * Copie les prix d'une version dans une autre, vide (nouvelle version qui part des prix d'une ancienne).
     */
    public static function copyCosts(int $fromVersionId, int $toVersionId): bool
    {
        global $DB;

        $ok = true;
        foreach ($DB->request(['FROM' => GridCost::getTable(), 'WHERE' => ['plugin_points_gridversions_id' => $fromVersionId]]) as $row) {
            $input = [
                'plugin_points_gridversions_id'      => $toVersionId,
                'plugin_points_interventiontypes_id' => (int) $row['plugin_points_interventiontypes_id'],
                'daytype'                            => (int) $row['daytype'],
                'cost'                               => (string) $row['cost'],
            ];
            $cost = new GridCost();
            $cost->check(-1, CREATE, $input);
            $ok = $cost->add($input) !== false && $ok;
        }

        return $ok;
    }
}
