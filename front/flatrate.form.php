<?php

declare(strict_types=1);

/**
 * Actions sur le forfait d'un ticket : chiffrer (create), modifier un brouillon (update), soumettre (submit), rouvrir
 * après un refus BU (reopen), valider et proposer au client (accept) ou refuser (refuse) (Responsable BU), annuler (cancel),
 * estimation d'aide (estimate, n'écrit rien). POST seulement.
 *
 * Garde de la page : droit plugin_points_flatrate (lecture) et ticket lisible par l'utilisateur (droit du cœur et
 * entité). Chaque action reprend ensuite son propre contrôle de droit (CREATE, UPDATE ou VALIDATE) dans Flatrate, par les
 * can* du modèle. Le refus de droit est une exception HTTP, sans arrêt brutal du script ; un refus de règle est un message.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Flatrate;
use GlpiPlugin\Points\Rights;

Session::checkRight(Rights::FLATRATE, READ);
$action = (string) ($_POST['action'] ?? '');
if ($action === '') {
    throw new BadRequestHttpException();
}
$ticketsId = (int) ($_POST['tickets_id'] ?? 0);
$ticket    = new \Ticket();
if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId)) {
    throw new NotFoundHttpException();
}
if (!$ticket->can($ticketsId, READ) || !Session::haveAccessToEntity((int) $ticket->fields['entities_id'])) {
    throw new AccessDeniedHttpException();
}
$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    // Le forfait désigné doit appartenir à ce ticket (sinon le garde ci-dessus ne couvrirait pas le bon ticket)
    $row = Flatrate::row($id);
    if ($row === null || (int) $row['tickets_id'] !== $ticketsId) {
        throw new NotFoundHttpException();
    }
}

switch ($action) {
    case 'create':
        Flatrate::respond(
            static fn (): bool => Flatrate::createDraft($ticketsId, (string) ($_POST['amount'] ?? ''), (string) ($_POST['description'] ?? '')) > 0,
            __s('Flatrate saved as a draft', 'points')
        );
        // no break : respond() ne revient pas
    case 'update':
        Flatrate::respond(
            static fn (): bool => Flatrate::updateDraft($id, (string) ($_POST['amount'] ?? ''), (string) ($_POST['description'] ?? '')),
            __s('Flatrate saved', 'points')
        );
    case 'submit':
        Flatrate::respond(static function () use ($id): bool {
            Flatrate::submit($id);

            return true;
        }, __s('Flatrate submitted to the BU manager', 'points'));
    case 'reopen':
        Flatrate::respond(static function () use ($id): bool {
            Flatrate::reopen($id);

            return true;
        }, __s('Flatrate reopened as a draft', 'points'));
    case 'accept':
        Flatrate::respond(static function () use ($id): bool {
            Flatrate::decide($id, true, (string) ($_POST['comment'] ?? ''), (int) ($_POST['validator'] ?? 0));

            return true;
        }, __s('Flatrate validated and proposed to the customer', 'points'));
    case 'refuse':
        Flatrate::respond(static function () use ($id): bool {
            Flatrate::decide($id, false, (string) ($_POST['comment'] ?? ''));

            return true;
        }, __s('Flatrate refused', 'points'));
    case 'cancel':
        Flatrate::respond(static function () use ($id): bool {
            Flatrate::cancel($id);

            return true;
        }, __s('Flatrate cancelled', 'points'));
    case 'estimate':
        if (!Flatrate::canCreate() && !Flatrate::canUpdate()) {
            throw new AccessDeniedHttpException();
        }
        Flatrate::respond(static function () use ($ticketsId): bool {
            $points = Flatrate::estimate($ticketsId, (string) ($_POST['hours'] ?? ''));
            Session::addMessageAfterRedirect(htmlescape(sprintf(__('Estimate: %1$s hours = %2$s points (work order price of the grid, today)', 'points'), (string) ($_POST['hours'] ?? ''), $points)));

            return false;
        }, '');
    default:
        throw new BadRequestHttpException();
}
