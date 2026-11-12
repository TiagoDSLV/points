<?php

declare(strict_types=1);

/**
 * Nouvelle enveloppe avec son crédit initial (formulaire de l'onglet « Points » d'un contrat). POST seulement.
 * Droit plugin_points_envelope CREATE ; contrat lisible par l'utilisateur (droit du cœur et entité) ; le refus est une
 * exception HTTP (Session::checkRight, Contract::requireContract), jamais un exit.
 */

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Points\Contract;
use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Ledger;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::ENVELOPE, CREATE);
if (!isset($_POST['add'])) {
    throw new BadRequestHttpException();
}
$contractsId = Contract::requireContract((int) ($_POST['contracts_id'] ?? 0));

Contract::respond(
    static function () use ($contractsId): void {
        Ledger::createEnvelope(
            $contractsId,
            (string) ($_POST['name'] ?? ''),
            Money::parse((string) ($_POST['credit'] ?? '')),
            (string) ($_POST['begin_date'] ?? ''),
            (string) ($_POST['end_date'] ?? ''),
            (int) ($_POST['is_carryover'] ?? 0) === 1,
            (string) ($_POST['comment'] ?? '')
        );
    },
    __s('Envelope created', 'points')
);
