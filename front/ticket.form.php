<?php

declare(strict_types=1);

/**
 * Réglage Points d'un ticket (contrat, type d'intervention, facturable). POST seulement. Droit plugin_points_ticket
 * UPDATE ; ticket lisible par l'utilisateur (droit du cœur et entité) ; l'écriture passe par Ticket::saveSetting, donc
 * par les droits et les contrôles du modèle. Le refus est une exception HTTP, jamais un exit.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Points\Contract;
use GlpiPlugin\Points\Rights;
use GlpiPlugin\Points\Ticket;

Session::checkRight(Rights::TICKET, UPDATE);
if (!isset($_POST['update'])) {
    throw new BadRequestHttpException();
}
$ticketsId = (int) ($_POST['tickets_id'] ?? 0);
$ticket    = new \Ticket();
if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId)) {
    throw new NotFoundHttpException();
}
if (!$ticket->can($ticketsId, READ)) {
    throw new AccessDeniedHttpException();
}

Contract::respond(
    static fn (): bool => Ticket::saveSetting($ticketsId, [
        'contracts_id'                       => (int) ($_POST['contracts_id'] ?? 0),
        'plugin_points_interventiontypes_id' => (int) ($_POST['plugin_points_interventiontypes_id'] ?? 0),
        'is_billable'                        => (int) ($_POST['is_billable'] ?? 1) === 1 ? 1 : 0,
    ]),
    __s('Settings saved', 'points')
);
