<?php

declare(strict_types=1);

/**
 * Décision sur une review : confirmer (confirm) ou ajuster (adjust : montant signé et motif obligatoire). POST seulement.
 *
 * Garde de la page : droit plugin_points_review UPDATE ; chaque action reprend son contrôle (droit, entité de la review)
 * dans Review, et l'écriture passe par Ledger. Le refus de droit est une exception HTTP, sans arrêt brutal du script ; un
 * refus de règle est un message.
 */

use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Review;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::REVIEW, UPDATE);
$action = (string) ($_POST['action'] ?? '');
if ($action === '') {
    throw new BadRequestHttpException();
}
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0 || Review::row($id) === null) {
    throw new NotFoundHttpException();
}

switch ($action) {
    case 'confirm':
        Review::respond(static function () use ($id): bool {
            Review::confirm($id, (string) ($_POST['comment'] ?? ''));

            return true;
        }, __s('Review confirmed', 'points'));
    case 'adjust':
        Review::respond(static function () use ($id): bool {
            Review::adjust($id, (string) ($_POST['amount'] ?? ''), (string) ($_POST['comment'] ?? ''));

            return true;
        }, __s('Review adjusted', 'points'));
    default:
        throw new BadRequestHttpException();
}
