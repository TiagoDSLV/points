<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CommonGLPI;
use Session;

/**
 * Entrée « Points » du menu Outils (src/Html.php:959-985 : MENU_TOADD) : listes natives « Reviews à contrôler » et
 * « Compensations en attente ». Chaque page contrôle son droit ; l'entrée est absente sans aucun des deux droits de lecture.
 */
class Controls extends CommonGLPI
{
    public static string $rightname = Rights::REVIEW;

    public static function getTypeName($nb = 0)
    {
        return __('Points', 'points');
    }

    public static function getIcon()
    {
        return 'ti ti-coins';
    }

    public static function getMenuContent()
    {
        $reviews       = Session::haveRight(Rights::REVIEW, READ);
        $compensations = Session::haveRight(Rights::COMPENSATION, READ);
        if (!$reviews && !$compensations) {
            return false;
        }
        $options = [];
        if ($reviews) {
            $options['review'] = [
                'title' => __('Reviews to check', 'points'),
                'page'  => '/plugins/points/front/review.php',
                'icon'  => Review::getIcon(),
            ];
        }
        if ($compensations) {
            $options['compensation'] = [
                'title' => __('Pending compensations', 'points'),
                'page'  => '/plugins/points/front/compensation.php',
                'icon'  => Compensation::getIcon(),
            ];
        }

        return [
            'title'   => static::getTypeName(),
            'page'    => $reviews ? '/plugins/points/front/review.php' : '/plugins/points/front/compensation.php',
            'icon'    => static::getIcon(),
            'options' => $options,
        ];
    }
}
