<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Types d'opération du journal (docs/conception.md, « Modèle de données ») et leur rôle dans le cache du contrat.
 */
final class OpType
{
    public const CREDIT           = 'credit';
    public const IMPORT           = 'import';
    public const DEBIT_TASK       = 'debit_task';
    public const DEBIT_FLATRATE   = 'debit_flatrate';
    public const EXPIRY           = 'expiry';
    public const EXPIRY_REVERSAL  = 'expiry_reversal';
    public const CARRYOVER_OUT    = 'carryover_out';
    public const CARRYOVER_IN     = 'carryover_in';
    public const CORRECTION       = 'correction';
    public const REVIEW_ADJUSTMENT = 'review_adjustment';
    public const COMPENSATION     = 'compensation';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREDIT, self::IMPORT, self::DEBIT_TASK, self::DEBIT_FLATRATE, self::EXPIRY, self::EXPIRY_REVERSAL, self::CARRYOVER_OUT,
            self::CARRYOVER_IN, self::CORRECTION, self::REVIEW_ADJUSTMENT, self::COMPENSATION,
        ];
    }

    /**
     * Types qu'un débit FIFO peut porter (Ledger::debit).
     *
     * @return list<string>
     */
    public static function debitTypes(): array
    {
        return [self::DEBIT_TASK, self::DEBIT_FLATRATE, self::REVIEW_ADJUSTMENT, self::CORRECTION];
    }

    /**
     * Types qui comptent dans `credited` (docs/conception.md, « Cache du contrat ») : points achetés et leurs
     * mouvements (reports, expirations, reprise d'une expiration). Les autres types sont de la consommation.
     */
    public static function isCredited(string $type): bool
    {
        return in_array($type, [self::CREDIT, self::IMPORT, self::CARRYOVER_IN, self::CARRYOVER_OUT, self::EXPIRY, self::EXPIRY_REVERSAL], true);
    }
}
