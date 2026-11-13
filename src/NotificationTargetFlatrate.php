<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\Money;
use Notification;
use NotificationTarget;

/**
 * Notifications natives des forfaits (src/NotificationTarget.php, src/NotificationEvent.php:146 en 12.0.1). GLPI cherche
 * cette classe par le nom de l'objet (NotificationTarget::getInstanceClass : espace de noms + « NotificationTarget » +
 * nom court).
 *
 * Événements : `flatrate_submitted` (au Responsable BU), `flatrate_decided` (au technicien : décision du BU, réponse du
 * client). Cibles : celles de GLPI (profils, groupes, administrateur de l'entité, auteur = technicien du forfait) et une
 * cible propre, les Responsables BU, c'est-à-dire les utilisateurs dont un profil porte le bit VALIDATE du droit
 * plugin_points_flatrate dans l'entité du ticket.
 *
 * Le texte du message est composé de balises `##lang.flatrate.*##` traduites dans la langue de chaque destinataire (GLPI
 * charge le domaine du plugin avant de produire le message : NotificationTemplate::getTemplateByLanguage).
 */
class NotificationTargetFlatrate extends NotificationTarget
{
    public function getEvents()
    {
        return [
            'flatrate_submitted' => __('Flatrate submitted to the BU manager', 'points'),
            'flatrate_decided'   => __('Flatrate decision', 'points'),
        ];
    }

    public function addNotificationTargets($entity)
    {
        parent::addNotificationTargets($entity);
        $this->addTarget(Notification::AUTHOR, __('Technician who priced the flatrate', 'points'));
        $this->addTarget(Flatrate::TARGET_BU, __('BU managers of the entity', 'points'));
    }

    /**
     * Cible propre : les Responsables BU (droit VALIDATE) de l'entité, par leurs profils (adresses, langue et entité
     * contrôlées par NotificationTarget::addForProfile, comme pour tout profil).
     */
    public function addSpecificTargets($data, $options)
    {
        global $DB;

        if ((int) $data['type'] !== Notification::USER_TYPE || (int) $data['items_id'] !== Flatrate::TARGET_BU) {
            return;
        }
        foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Rights::FLATRATE, 'rights' => ['&', Rights::VALIDATE]]]) as $row) {
            $this->addForProfile((int) $row['profiles_id']);
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $flatrate = $this->obj;
        $fields   = $flatrate instanceof Flatrate ? $flatrate->fields : [];
        $ticket   = new \Ticket();
        $ticket->getFromDB((int) ($fields['tickets_id'] ?? 0));

        $this->data['##flatrate.id##']          = (string) ($fields['id'] ?? '');
        $this->data['##flatrate.action##']      = $this->getEvents()[$event] ?? $event;
        $this->data['##flatrate.ticket##']      = (string) ($fields['tickets_id'] ?? '');
        $this->data['##flatrate.ticketname##']  = (string) ($ticket->fields['name'] ?? '');
        $this->data['##flatrate.status##']      = Flatrate::statusLabel((string) ($fields['status'] ?? ''));
        $this->data['##flatrate.amount##']      = Money::format(Money::parse((string) ($fields['amount'] ?? '0')));
        $this->data['##flatrate.approved##']    = Money::format(Money::parse((string) ($fields['approved_amount'] ?? '0')));
        $this->data['##flatrate.description##'] = (string) ($fields['description'] ?? '');
        $this->data['##flatrate.comment##']     = (string) ($fields['bu_comment'] ?? '');
        $this->data['##flatrate.technician##']  = (string) getUserName((int) ($fields['users_id'] ?? 0));
        $this->data['##flatrate.entity##']      = (string) \Dropdown::getDropdownName('glpi_entities', (int) ($fields['entities_id'] ?? 0));
        $this->data['##flatrate.url##']         = $this->formatURL($options['additionnaloption']['usertype'] ?? self::ANONYMOUS_USER, 'Ticket_' . (int) ($fields['tickets_id'] ?? 0));

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
            'flatrate.id'          => __('Flatrate', 'points'),
            'flatrate.action'      => __('Event', 'points'),
            'flatrate.ticket'      => __('Ticket'),
            'flatrate.ticketname'  => __('Ticket title', 'points'),
            'flatrate.status'      => __('Status'),
            'flatrate.amount'      => __('Amount (points)', 'points'),
            'flatrate.approved'    => __('Approved amount (points)', 'points'),
            'flatrate.description' => __('Description'),
            'flatrate.comment'     => __('BU manager comment', 'points'),
            'flatrate.technician'  => __('Technician', 'points'),
            'flatrate.entity'      => __('Entity'),
            'flatrate.url'         => __('URL'),
        ];
    }
}
