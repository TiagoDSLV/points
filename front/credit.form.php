<?php

declare(strict_types=1);

/**
 * Crédit additionnel sur une enveloppe (nouveau lot). POST seulement. Droit plugin_points_envelope CREATE ; contrat
 * lisible par l'utilisateur ; l'enveloppe doit appartenir à ce contrat (contrôlé par Ledger).
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
        $opts = ['comment' => (string) ($_POST['comment'] ?? '')];
        if (trim((string) ($_POST['origin_date'] ?? '')) !== '') {
            $opts['origin_date'] = trim((string) $_POST['origin_date']);
        }
        Ledger::credit($contractsId, (int) ($_POST['envelopes_id'] ?? 0), Money::parse((string) ($_POST['amount'] ?? '')), $opts);
    },
    __s('Credit added', 'points')
);
