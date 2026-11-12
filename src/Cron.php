<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use CronTask;

/**
 * Actions automatiques du plugin. `pointsexpire` : expiration et report quotidiens des lots échus (règle 8 de
 * docs/conception.md). GLPI appelle `<itemtype>::cron<nom>` (src/CronTask.php:975 en 12.0.1) et `cronInfo`.
 */
final class Cron
{
    public const EXPIRE = 'pointsexpire';

    /**
     * @return array{description?: string}
     */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            self::EXPIRE => ['description' => __('Expire or carry over the points of ended lots', 'points')],
            default      => [],
        };
    }

    /**
     * Expire ou reporte les lots échus à la date du jour. Un échec sur un contrat n'arrête pas les autres, mais fait
     * échouer la tâche (visible dans ses journaux) ; le message ne contient que la classe de l'exception.
     *
     * @return int 1 si des lots ont été traités, 0 sinon
     */
    public static function cronPointsexpire(CronTask $task): int
    {
        $result = Ledger::expireDue(date('Y-m-d'));
        $task->setVolume($result['lots']);
        $task->log(sprintf('lots: %d, expired: %s, carried over: %s', $result['lots'], Domain\Money::format($result['expired']), Domain\Money::format($result['carried'])));
        foreach ($result['errors'] as $error) {
            $task->log($error);
        }
        if ($result['errors'] !== []) {
            throw new \RuntimeException(sprintf('Points expiry failed for %d contract(s)', count($result['errors'])));
        }

        return $result['lots'] > 0 ? 1 : 0;
    }
}
