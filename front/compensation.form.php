<?php

declare(strict_types=1);

/**
 * Compensation : demander (request : débit visé, montant, motif obligatoire), approuver (approve : montant approuvé
 * facultatif) ou rejeter (reject : motif de décision obligatoire). POST seulement.
 *
 * Garde de la page : droit plugin_points_compensation (lecture). Chaque action reprend son propre contrôle de droit
 * (CREATE pour demander, avec entité du débit et lecture du ticket ; VALIDATE pour décider, avec entité) dans
 * Compensation, et l'écriture du journal passe par Ledger. Le refus de droit est une exception HTTP, sans arrêt brutal du
 * script ; un refus de règle est un message.
 */

use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Compensation;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::COMPENSATION, READ);
$action = (string) ($_POST['action'] ?? '');
if ($action === '') {
    throw new BadRequestHttpException();
}
$id = (int) ($_POST['id'] ?? 0);
if ($action !== 'request' && ($id <= 0 || Compensation::row($id) === null)) {
    throw new NotFoundHttpException();
}

switch ($action) {
    case 'request':
        Compensation::respond(
            static fn (): bool => Compensation::request((int) ($_POST['operations_id'] ?? 0), (string) ($_POST['amount'] ?? ''), (string) ($_POST['comment'] ?? '')) > 0,
            __s('Compensation requested', 'points')
        );
    case 'approve':
        Compensation::respond(static function () use ($id): bool {
            Compensation::decide($id, true, (string) ($_POST['approved'] ?? ''), (string) ($_POST['comment'] ?? ''));

            return true;
        }, __s('Compensation approved', 'points'));
    case 'reject':
        Compensation::respond(static function () use ($id): bool {
            Compensation::decide($id, false, '', (string) ($_POST['comment'] ?? ''));

            return true;
        }, __s('Compensation rejected', 'points'));
    default:
        throw new BadRequestHttpException();
}
