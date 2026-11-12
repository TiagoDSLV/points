<?php

declare(strict_types=1);

namespace GlpiPlugin\Points\Domain;

/**
 * Refus d'une règle métier du journal. Le code est stable (testé, traduit par Ledger) ; le message est en anglais.
 */
final class RuleViolation extends \DomainException
{
    public const INVALID_AMOUNT      = 'invalid_amount';
    public const INVALID_DATE        = 'invalid_date';
    public const ENVELOPE_DATES      = 'envelope_dates';
    public const ENVELOPE_TOO_LONG   = 'envelope_too_long';
    public const NO_VALID_LOT        = 'no_valid_lot';
    public const INSUFFICIENT        = 'insufficient';
    public const NOTHING_TO_REFUND   = 'nothing_to_refund';
    public const REASON_REQUIRED     = 'reason_required';
    public const UNKNOWN_CONTRACT    = 'unknown_contract';
    public const UNKNOWN_ENVELOPE    = 'unknown_envelope';
    public const UNKNOWN_OPERATION   = 'unknown_operation';
    public const BAD_TARGET          = 'bad_target';
    public const BAD_TYPE            = 'bad_type';
    public const CREDIT_OUT_OF_RANGE = 'credit_out_of_range';
    public const ENVELOPE_ENDED      = 'envelope_ended';
    public const NOT_REPORTABLE      = 'not_reportable';
    public const NOT_A_DEBIT         = 'not_a_debit';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
