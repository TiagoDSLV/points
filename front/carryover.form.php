<?php

declare(strict_types=1);

/**
 * Report manuel des lots d'une enveloppe vers une autre. POST seulement. Droit plugin_points_envelope UPDATE ; contrat
 * lisible par l'utilisateur ; les deux enveloppes doivent appartenir à ce contrat (contrôlé par Ledger).
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
    static function () use ($contractsId): bool {
        $done = Ledger::carryover($contractsId, (int) ($_POST['from_envelopes_id'] ?? 0), (int) ($_POST['to_envelopes_id'] ?? 0));
        Session::addMessageAfterRedirect(sprintf(
            __s('%1$s points carried over (%2$d lot(s)); %3$d lot(s) cannot be carried over', 'points'),
            Money::format($done['carried']),
            $done['lots'],
            $done['skipped']
        ));

        return false; // message détaillé déjà posé : pas de message générique
    },
    __s('Carry-over done', 'points')
);
