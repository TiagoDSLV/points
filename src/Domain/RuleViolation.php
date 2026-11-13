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
    // Décompte des tâches (lot c)
    public const NO_GRID             = 'no_grid';
    public const NO_GRID_VERSION     = 'no_grid_version';
    public const NO_COST             = 'no_cost';
    public const INVALID_TASK        = 'invalid_task';
    public const ALREADY_DEBITED     = 'already_debited';
    // Forfaits (lot d)
    public const FLATRATE_STATE      = 'flatrate_state';
    public const FLATRATE_OPEN       = 'flatrate_open';
    public const FLATRATE_SELF       = 'flatrate_self';
    public const FLATRATE_FROZEN     = 'flatrate_frozen';
    public const FLATRATE_NO_CONTRACT = 'flatrate_no_contract';
    public const FLATRATE_TASKS_DEBITED = 'flatrate_tasks_debited';
    public const FLATRATE_VALIDATOR  = 'flatrate_validator';
    public const FLATRATE_TICKET_CLOSED = 'flatrate_ticket_closed';
    public const FLATRATE_REASON     = 'flatrate_reason';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
