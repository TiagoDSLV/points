<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\Money;
use NotificationTarget;

/**
 * Notification native du seuil d'alerte d'un contrat à points (src/NotificationTarget.php, src/NotificationEvent.php:146 en 12.0.1).
 * GLPI cherche cette classe par le nom de l'objet levé, la ligne Points du contrat (NotificationTarget::getInstanceClass : espace de
 * noms + « NotificationTarget » + nom court, src/NotificationTarget.php:446-452).
 *
 * Événement : `threshold`. Destinataire par défaut : l'administrateur de l'entité du contrat (cible native
 * Notification::ENTITY_ADMINISTRATOR, déjà proposée par NotificationTarget::addNotificationTargets : question 4 du cahier des charges) ;
 * les autres cibles natives (profils, groupes) restent réglables dans l'interface.
 *
 * Balises `##points.*##` (valeurs lues au moment du rendu) et `##lang.points.*##` (libellés traduits dans la langue du destinataire).
 */
class NotificationTargetContract extends NotificationTarget
{
    public function getEvents()
    {
        return [
            ThresholdAlert::EVENT => __('Points threshold reached', 'points'),
        ];
    }

    public function addDataForTemplate($event, $options = [])
    {
        $fields = $this->obj instanceof Contract ? $this->obj->fields : [];
        $native = new \Contract();
        $native->getFromDB((int) ($fields['contracts_id'] ?? 0));

        $today  = date('Y-m-d');
        $totals = Ledger::validTotals((int) ($fields['contracts_id'] ?? 0), $today);
        $cached = Ledger::cached((int) ($fields['contracts_id'] ?? 0));

        $this->data['##points.action##']     = $this->getEvents()[$event] ?? $event;
        $this->data['##points.contract##']   = (string) ($native->fields['name'] ?? '');
        $this->data['##points.client##']     = (string) \Dropdown::getDropdownName('glpi_entities', (int) ($fields['entities_id'] ?? 0));
        $this->data['##points.balance##']    = Money::format($cached['balance']);
        $this->data['##points.credited##']   = Money::format($totals['credited']);
        $this->data['##points.consumed##']   = Money::format($totals['consumed']);
        $this->data['##points.percent##']    = Money::format($totals['credited'] > 0 ? (int) round($totals['consumed'] * 10000 / $totals['credited']) : 0);
        $this->data['##points.threshold##']  = (string) (int) ($fields['alert_threshold'] ?? 0);
        $this->data['##points.expiry##']     = (string) ($fields['next_expiry'] ?? '');
        $this->data['##points.url##']        = $this->formatURL($options['additionnaloption']['usertype'] ?? self::ANONYMOUS_USER, 'Contract_' . (int) ($fields['contracts_id'] ?? 0));

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
            'points.action'    => __('Event', 'points'),
            'points.contract'  => __('Contract'),
            'points.client'    => __('Client (entity)', 'points'),
            'points.balance'   => __('Balance', 'points'),
            'points.credited'  => __('Credited (valid lots)', 'points'),
            'points.consumed'  => __('Consumed (valid lots)', 'points'),
            'points.percent'   => __('Consumed (%)', 'points'),
            'points.threshold' => __('Alert threshold (%)', 'points'),
            'points.expiry'    => __('Next expiry', 'points'),
            'points.url'       => __('URL'),
        ];
    }
}
