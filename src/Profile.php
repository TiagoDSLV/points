<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Onglet « Points » de la fiche Profile : matrice des droits du plugin (Rights).
 * Les droits sont enregistrés par le formulaire du cœur (front/profile.form.php, champs `_plugin_points_*`).
 */
class Profile extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Points', 'points');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$withtemplate
            && $item instanceof \Profile
            && \Profile::canView()
        ) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-coins');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile || !\Profile::canView()) {
            return false;
        }

        TemplateRenderer::getInstance()->display('@points/profile.html.twig', [
            'item'     => $item,
            'rights'   => self::matrix(($item->fields['interface'] ?? 'central') === 'helpdesk'),
            'title'    => self::getTypeName(),
            'form_url' => \Profile::getFormURL(),
            'can_edit' => Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]),
        ]);

        return true;
    }

    /**
     * Lignes de la matrice pour Profile::displayRightsChoiceMatrix() (src/Profile.php:3676 en 12.0.1) : une ligne par droit,
     * une colonne par bit ; VALIDATE (1024) a son libellé propre.
     *
     * @param bool $helpdesk profil de l'interface simplifiée : ligne plugin_points_balance seule
     * @return list<array{rights: array<int, string>, label: string, field: string}>
     */
    public static function matrix(bool $helpdesk = false): array
    {
        $rows = [];
        foreach (Rights::definitions() as $name => $bits) {
            // Interface simplifiée (Responsable client) : seule la lecture des soldes a un sens
            if ($helpdesk && $name !== Rights::BALANCE) {
                continue;
            }
            $columns = [];
            foreach ($bits as $bit) {
                $columns[$bit] = self::bitLabel($bit);
            }
            $rows[] = ['rights' => $columns, 'label' => self::rightLabel($name), 'field' => $name];
        }

        return $rows;
    }

    private static function bitLabel(int $bit): string
    {
        return match ($bit) {
            Rights::READ     => __('Read', 'points'),
            Rights::UPDATE   => __('Update', 'points'),
            Rights::CREATE   => __('Create', 'points'),
            Rights::PURGE    => __('Purge', 'points'),
            Rights::VALIDATE => __('Validate', 'points'),
            default          => (string) $bit,
        };
    }

    private static function rightLabel(string $name): string
    {
        return match ($name) {
            Rights::BALANCE      => __('Balances, envelopes and ledger', 'points'),
            Rights::TICKET       => __('Points settings of a ticket', 'points'),
            Rights::ENVELOPE     => __('Envelopes, credits, corrections, carry-overs and import', 'points'),
            Rights::FLATRATE     => __('Flat rates (Validate = business unit approval)', 'points'),
            Rights::REVIEW       => __('Reviews', 'points'),
            Rights::COMPENSATION => __('Compensations (Create = request, Validate = decide)', 'points'),
            Rights::GRID         => __('Grids and intervention types', 'points'),
            Rights::CONFIG       => __('Points settings', 'points'),
            default              => $name,
        };
    }
}
