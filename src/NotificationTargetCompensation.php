<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\Money;
use Notification;
use NotificationTarget;

/**
 * Notification native de la décision d'une compensation (src/NotificationTarget.php, src/NotificationEvent.php:146 en
 * 12.0.1). GLPI cherche cette classe par le nom de l'objet (NotificationTarget::getInstanceClass : espace de noms +
 * « NotificationTarget » + nom court).
 *
 * Événement : `compensation_decided` (approuvée ou rejetée), à l'auteur de la demande (cible Notification::AUTHOR : le
 * champ `users_id` de la demande, src/NotificationTarget.php:843). Les autres cibles natives (profils, groupes,
 * administrateur de l'entité) restent réglables dans l'interface.
 *
 * Le texte du message est composé de balises `##lang.compensation.*##` traduites dans la langue de chaque destinataire.
 */
class NotificationTargetCompensation extends NotificationTarget
{
    public function getEvents()
    {
        return [
            'compensation_decided' => __('Compensation decision', 'points'),
        ];
    }

    public function addNotificationTargets($entity)
    {
        parent::addNotificationTargets($entity);
        $this->addTarget(Notification::AUTHOR, __('User who requested the compensation', 'points'));
    }

    public function addDataForTemplate($event, $options = [])
    {
        $compensation = $this->obj;
        $fields       = $compensation instanceof Compensation ? $compensation->fields : [];
        $ticket       = new \Ticket();
        $ticket->getFromDB((int) ($fields['tickets_id'] ?? 0));

        $this->data['##compensation.id##']          = (string) ($fields['id'] ?? '');
        $this->data['##compensation.action##']      = $this->getEvents()[$event] ?? $event;
        $this->data['##compensation.ticket##']      = (string) ($fields['tickets_id'] ?? '');
        $this->data['##compensation.ticketname##']  = (string) ($ticket->fields['name'] ?? '');
        $this->data['##compensation.status##']      = Compensation::statusLabel((string) ($fields['status'] ?? ''));
        $this->data['##compensation.amount##']      = Money::format(Money::parse((string) ($fields['amount'] ?? '0')));
        $this->data['##compensation.approved##']    = Money::format(Money::parse((string) ($fields['approved_amount'] ?? '0')));
        $this->data['##compensation.reason##']      = (string) ($fields['comment'] ?? '');
        $this->data['##compensation.decision##']    = (string) ($fields['decision_comment'] ?? '');
        $this->data['##compensation.requester##']   = (string) getUserName((int) ($fields['users_id'] ?? 0));
        $this->data['##compensation.decider##']     = (int) ($fields['users_id_decision'] ?? 0) > 0 ? (string) getUserName((int) $fields['users_id_decision']) : '';
        $this->data['##compensation.entity##']      = (string) \Dropdown::getDropdownName('glpi_entities', (int) ($fields['entities_id'] ?? 0));
        $this->data['##compensation.url##']         = $this->formatURL($options['additionnaloption']['usertype'] ?? self::ANONYMOUS_USER, 'Ticket_' . (int) ($fields['tickets_id'] ?? 0));

        foreach (self::labels() as $tag => $label) {
            $this->data['##lang.' . $tag . '##'] = $label;
        }
    }

    public function getTags()
    {
        foreach (self::labels() as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
    }

    /**
     * Libellés des balises (traduits dans la langue courante : celle du destinataire au moment du rendu).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'compensation.id'         => __('Compensation', 'points'),
            'compensation.action'     => __('Event', 'points'),
            'compensation.ticket'     => __('Ticket'),
            'compensation.ticketname' => __('Ticket title', 'points'),
            'compensation.status'     => __('Status'),
            'compensation.amount'     => __('Requested amount (points)', 'points'),
            'compensation.approved'   => __('Approved amount (points)', 'points'),
            'compensation.reason'     => __('Reason of the request', 'points'),
            'compensation.decision'   => __('Decision comment', 'points'),
            'compensation.requester'  => __('Requested by', 'points'),
            'compensation.decider'    => __('Decided by', 'points'),
            'compensation.entity'     => __('Entity'),
            'compensation.url'        => __('URL'),
        ];
    }
}
