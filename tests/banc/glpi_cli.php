<?php

declare(strict_types=1);

/**
 * Aide du banc (jamais déployée) : actions du cœur sans interface API v1, depuis la console du conteneur GLPI.
 * Entrée standard : {"call": "merge", "args": [cible, [sources]]} ; dernière ligne de sortie :
 * JSON {"ok": …} | {"error": …}.
 *
 *   merge : fusion de tickets avec copie des tâches (Ticket::merge, src/Ticket.php:5765), utilisateur « glpi » connecté.
 *   flatrate_debit : GlpiPlugin\Points\Flatrate::debit(id), pour les débits concurrents d'un même forfait (lot d).
 */

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel();
$kernel->boot();

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    switch ($request['call']) {
        case 'merge':
            $user = new User();
            $user->getFromDBbyName('glpi');
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user          = $user;
            Session::init($auth);
            Session::changeActiveEntities('all', true);
            $status = [];
            $done   = Ticket::merge((int) $request['args'][0], array_map('intval', $request['args'][1]), $status, ['linktypes' => ['TicketTask']]);
            echo "\n" . json_encode(['ok' => ['done' => $done, 'status' => $status]]) . "\n";
            break;
        case 'flatrate_debit':
            $user = new User();
            $user->getFromDBbyName('glpi');
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user          = $user;
            Session::init($auth);
            Session::changeActiveEntities('all', true);
            echo "\n" . json_encode(['ok' => GlpiPlugin\Points\Flatrate::debit((int) $request['args'][0])]) . "\n";
            break;
        default:
            throw new InvalidArgumentException('call not allowed');
    }
} catch (GlpiPlugin\Points\Domain\RuleViolation $e) {
    echo "\n" . json_encode(['violation' => $e->reason]) . "\n";
} catch (Throwable $e) {
    echo "\n" . json_encode(['error' => $e::class . ': ' . $e->getMessage()]) . "\n";
}
