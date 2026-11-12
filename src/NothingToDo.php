<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

/**
 * Signal interne de Ledger::expireDue : rien à faire sous verrou, la transaction est annulée sans erreur.
 */
final class NothingToDo extends \RuntimeException
{
}
