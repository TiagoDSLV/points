<?php

declare(strict_types=1);

/**
 * Aide du banc (jamais déployée avec un usage hors banc) : appelle une fonction publique de Ledger depuis la console du
 * conteneur GLPI. Entrée standard : {"call": "debit", "args": [...]} ; dernière ligne de sortie : JSON
 * {"ok": résultat} | {"violation": code} | {"error": classe}.
 *
 *   docker exec -i -u www-data points-banc-app php plugins/points/tests/banc/ledger_cli.php
 */

use GlpiPlugin\Points\Domain\RuleViolation;
use GlpiPlugin\Points\Ledger;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel();
$kernel->boot();

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$allowed = ['createEnvelope', 'credit', 'debit', 'correction', 'refund', 'carryover', 'expireDue', 'check', 'recomputeCache', 'cached', 'remainingByEnvelope'];
try {
    if (!in_array($request['call'], $allowed, true)) {
        throw new InvalidArgumentException('call not allowed');
    }
    echo "\n" . json_encode(['ok' => Ledger::{$request['call']}(...$request['args'])]) . "\n";
} catch (RuleViolation $e) {
    echo "\n" . json_encode(['violation' => $e->reason]) . "\n";
} catch (Throwable $e) {
    echo "\n" . json_encode(['error' => $e::class . ': ' . $e->getMessage()]) . "\n";
}
