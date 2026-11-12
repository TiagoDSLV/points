<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use GlpiPlugin\Points\Domain\RuleViolation;
use Session;

/**
 * Hooks sur TicketTask (POST_PREPAREADD, ITEM_ADD, POST_PREPAREUPDATE, ITEM_UPDATE, PRE_ITEM_PURGE) et Ticket
 * (PRE_ITEM_UPDATE, ITEM_UPDATE, PRE_ITEM_PURGE, ITEM_PURGE), enregistrés par setup.php. Ils n'ont pas de logique propre :
 * refus (entrée à false et message traduit, src/CommonDBTM.php:1348-1370, 1688-1700 en 12.0.1) ou appel de Billing.
 *
 * Les contrôles d'une tâche se font à POST_PREPAREADD / POST_PREPAREUPDATE (src/CommonDBTM.php:1362, 1698), après
 * prepareInputForAdd / prepareInputForUpdate : durée et dates sont celles que GLPI a calculées (planification, modèle de
 * tâche `_tasktemplates_id` compris), pas la saisie brute.
 *
 * Un ticket non facturable, ou au forfait (Billing::isFlatrateTicket), n'est jamais bloqué.
 */
final class ItilHooks
{
    /** Champs d'une tâche qui ne changent plus une fois débitée (conception, règle 6 ; le ticket s'y ajoute : il désigne le contrat). */
    private const LOCKED_FIELDS = ['actiontime', 'date', 'begin', 'end', 'tickets_id'];

    // ---------------------------------------------------------------------------------------------------------
    // TicketTask
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Ajout d'une tâche sur un ticket facturable : refusé sans point valide à la date de la tâche, sans grille ou prix
     * applicable (et, au débit « à la tâche », si le journal refuserait le débit). Une durée nulle n'est jamais refusée.
     * La copie d'une tâche déjà débitée par une fusion de tickets n'est jamais refusée.
     */
    public static function taskPostPrepareAdd(\TicketTask $task): void
    {
        $input = $task->input;
        if (!is_array($input) || Billing::mergedOriginOperation($input) > 0) {
            return;
        }
        self::check($task, (int) ($input['tickets_id'] ?? 0), $input);
    }

    /**
     * Après l'ajout : copie de fusion d'une tâche débitée → ligne verrouillée liée à l'opération d'origine, sans débit ;
     * copie d'une tâche non débitée → aucun débit ici (on est dans la transaction de la fusion), la clôture la débitera ;
     * sinon, au moment « à la tâche », débit. Un échec tardif du débit (débit concurrent) est signalé et la tâche reste
     * libre : elle sera débitée à la clôture.
     */
    public static function taskItemAdd(\TicketTask $task): void
    {
        $ticketsId = (int) ($task->fields['tickets_id'] ?? 0);
        if ($ticketsId < 1) {
            return;
        }
        if ((int) ($task->fields['sourceitems_id'] ?? 0) > 0) {
            $origin = Billing::mergedOriginOperation($task->fields);
            if ($origin > 0) {
                Billing::lockMergedCopy(Billing::context($ticketsId), (int) $task->getID(), $origin);
            }

            return;
        }
        self::debitIfDue($task, $ticketsId);
    }

    /**
     * Modification d'une tâche. Débitée : durée, dates, ticket verrouillés (un champ renvoyé à l'identique n'est pas une
     * modification). Non débitée : mêmes contrôles qu'à l'ajout quand durée, dates ou ticket changent.
     */
    public static function taskPostPrepareUpdate(\TicketTask $task): void
    {
        $input = $task->input;
        if (!is_array($input)) {
            return;
        }
        $changed = false;
        foreach (self::LOCKED_FIELDS as $field) {
            if (array_key_exists($field, $input) && self::normalize($field, $input[$field]) !== self::normalize($field, $task->fields[$field] ?? null)) {
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }
        if (Billing::isLocked((int) $task->getID())) {
            self::refuse($task, __('This task has been debited: its duration, dates and ticket can no longer be changed', 'points'));

            return;
        }
        self::check($task, (int) ($input['tickets_id'] ?? $task->fields['tickets_id'] ?? 0), $input + $task->fields);
    }

    /**
     * Au moment « à la tâche » : une tâche non débitée dont la durée devient non nulle (ou dont les dates changent) est
     * débitée à la modification.
     */
    public static function taskItemUpdate(\TicketTask $task): void
    {
        if (!array_intersect(['actiontime', 'begin', 'date', 'tickets_id'], $task->updates ?? []) || (int) ($task->fields['sourceitems_id'] ?? 0) > 0) {
            return;
        }
        self::debitIfDue($task, (int) ($task->fields['tickets_id'] ?? 0));
    }

    /**
     * Une tâche débitée ne se supprime pas (une correction passe par ADV).
     */
    public static function taskPrePurge(\TicketTask $task): void
    {
        if (Billing::isLocked((int) $task->getID())) {
            self::refuse($task, __('This task has been debited: it cannot be deleted', 'points'));
        }
    }

    /**
     * Contrôles d'une tâche sur un ticket facturable ; un refus (règle ou date illisible) annule l'ajout ou la modification.
     *
     * @param array<string, mixed> $data champs actiontime, begin, date de la tâche telle que GLPI va l'enregistrer
     */
    private static function check(\TicketTask $task, int $ticketsId, array $data): void
    {
        if ($ticketsId < 1 || (int) ($data['actiontime'] ?? 0) <= 0) {
            return;
        }
        $ctx = Billing::context($ticketsId);
        if (!Billing::counts($ctx)) {
            return;
        }
        if ($ctx['ambiguous']) {
            self::refuse($task, __('Several Points contracts are linked to this ticket: choose the one to debit in the Points tab, or mark the ticket as not billable', 'points'));

            return;
        }
        try {
            Billing::assertTaskAccepted($ctx, ['actiontime' => (int) $data['actiontime'], 'begin' => $data['begin'] ?? '', 'date' => $data['date'] ?? '']);
        } catch (RuleViolation $e) {
            self::refuse($task, Billing::describe($e));
        } catch (\InvalidArgumentException) {
            self::refuse($task, __('A date is not valid', 'points'));
        }
    }

    private static function debitIfDue(\TicketTask $task, int $ticketsId): void
    {
        if (Config::value('debit_moment') !== ConfigValues::DEBIT_TASK || (int) ($task->fields['actiontime'] ?? 0) <= 0) {
            return;
        }
        $ctx = Billing::context($ticketsId);
        if (!Billing::counts($ctx) || $ctx['ambiguous']) {
            return;
        }
        try {
            Billing::debitTask($ctx, (int) $task->getID(), $task->fields);
        } catch (RuleViolation $e) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(__('Task %1$d: %2$s', 'points'), $task->getID(), Billing::describe($e))), false, ERROR);
        } catch (\InvalidArgumentException) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(__('Task %1$d: %2$s', 'points'), $task->getID(), __('A date is not valid', 'points'))), false, ERROR);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Ticket
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Passage à Clos impossible (un débit des tâches serait refusé) : le ticket ne passe pas à Clos mais le reste de la
     * demande est conservé. Le statut demandé est ramené à Résolu si la demande vient d'une solution ou d'une approbation
     * (`_trigger`, src/ITILSolution.php:362), sinon laissé au statut courant. Message ; trace pour la clôture automatique
     * (`_auto_update`, src/Ticket.php:5160), dont personne ne lit le message.
     */
    public static function ticketPreUpdate(\Ticket $ticket): void
    {
        $input = $ticket->input;
        if (!is_array($input) || !isset($input['status']) || (int) $input['status'] !== \CommonITILObject::CLOSED) {
            return;
        }
        $current = (int) ($ticket->fields['status'] ?? 0);
        if ($current === \CommonITILObject::CLOSED) {
            return;
        }
        $problem = Billing::closureProblem((int) $ticket->getID());
        if ($problem === null) {
            return;
        }
        $target = isset($input['_trigger']) ? \CommonITILObject::SOLVED : $current;
        if ($target === $current) {
            unset($input['status']);
        } else {
            $input['status'] = $target;
        }
        $ticket->input = $input;
        Session::addMessageAfterRedirect(htmlescape($problem), false, ERROR);
        if (isset($input['_auto_update'])) {
            \Toolbox::logInFile('points', sprintf("closeticket: ticket %d stays Solved, closing refused: %s\n", $ticket->getID(), $problem));
        }
    }

    /**
     * Ticket passé à Clos : débit de toutes ses tâches non débitées (aussi celles ajoutées après une réouverture).
     */
    public static function ticketItemUpdate(\Ticket $ticket): void
    {
        if (!in_array('status', $ticket->updates ?? [], true) || (int) ($ticket->fields['status'] ?? 0) !== \CommonITILObject::CLOSED) {
            return;
        }
        $result = Billing::debitTicket((int) $ticket->getID());
        foreach ($result['errors'] as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        }
    }

    /**
     * Un ticket dont une tâche a été débitée ne se purge pas (le journal y fait référence).
     */
    public static function ticketPrePurge(\Ticket $ticket): void
    {
        if (Billing::ticketHasDebit((int) $ticket->getID())) {
            self::refuse($ticket, __('A task of this ticket has been debited: the ticket cannot be deleted permanently', 'points'));
        }
    }

    /**
     * Ticket purgé : nettoyage de son réglage Points et des lignes d'état de ses tâches.
     */
    public static function ticketItemPurge(\Ticket $ticket): void
    {
        Billing::cleanTicket((int) $ticket->getID());
    }

    // ---------------------------------------------------------------------------------------------------------

    private static function refuse(\CommonDBTM $item, string $message): void
    {
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        $item->input = false;
    }

    /**
     * Valeur comparable d'un champ de tâche : durée en entier, dates comparées par leur horodatage (vide ou nul égaux).
     */
    private static function normalize(string $field, mixed $value): string
    {
        $text = trim((string) $value);
        if ($field === 'actiontime' || $field === 'tickets_id') {
            return (string) (int) $text;
        }
        if ($text === '' || str_starts_with($text, '0000')) {
            return '';
        }
        $time = strtotime($text);

        return $time === false ? $text : (string) $time;
    }
}
