<?php

declare(strict_types=1);

/**
 * Réglage Points d'un contrat (grille, seuil d'alerte, actif). POST seulement. Droit plugin_points_envelope UPDATE ;
 * contrat lisible par l'utilisateur ; la ligne est créée au premier enregistrement ; la mise à jour passe par
 * Contract::can(UPDATE) (droit et entité) puis par les contrôles de Contract::prepareInputForUpdate.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Points\Contract;
use GlpiPlugin\Points\Ledger;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::ENVELOPE, UPDATE);
if (!isset($_POST['update'])) {
    throw new BadRequestHttpException();
}
$contractsId = Contract::requireContract((int) ($_POST['contracts_id'] ?? 0));

Contract::respond(
    static function () use ($contractsId): ?bool {
        $row  = Ledger::ensureContract($contractsId);
        $item = new Contract();
        if (!$item->can($row['id'], UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        return $item->update([
            'id'                     => $row['id'],
            'plugin_points_grids_id' => (int) ($_POST['plugin_points_grids_id'] ?? 0),
            'alert_threshold'        => (int) ($_POST['alert_threshold'] ?? 0),
            'is_active'              => (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0,
        ]) ? true : false;
    },
    __s('Settings saved', 'points')
);
