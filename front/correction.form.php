<?php

declare(strict_types=1);

/**
 * Correction liée à une opération d'origine, motif obligatoire. POST seulement. Droit plugin_points_envelope UPDATE ;
 * contrat lisible par l'utilisateur ; l'opération d'origine doit appartenir à ce contrat (contrôlé par Ledger).
 */

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Points\Contract;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Ledger;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::ENVELOPE, UPDATE);
if (!isset($_POST['add'])) {
    throw new BadRequestHttpException();
}
$contractsId = Contract::requireContract((int) ($_POST['contracts_id'] ?? 0));

Contract::respond(
    static function () use ($contractsId): void {
        Ledger::correction(
            $contractsId,
            (int) ($_POST['operations_id'] ?? 0),
            Money::parse((string) ($_POST['amount'] ?? '')),
            (string) ($_POST['reason'] ?? '')
        );
    },
    __s('Correction recorded', 'points')
);
