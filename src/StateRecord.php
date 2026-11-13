<?php

declare(strict_types=1);

namespace GlpiPlugin\Points;

use Glpi\Exception\Database\StatementException;
use GlpiPlugin\Points\Domain\RuleViolation;
use Log;
use Session;

/**
 * Enregistrement à états du plugin (reviews, compensations) : transition atomique, historique natif, réponse de
 * formulaire. L'état n'est JAMAIS écrit par CommonDBTM::update : `move()` est une mise à jour conditionnelle
 * (`WHERE status = ancien`) qui rend chaque transition atomique (deux décisions concurrentes : une seule gagne). Le
 * journal des points, lui, n'est écrit que par Ledger.
 *
 * La classe qui l'utilise fournit `describe()` (message traduit d'un refus de règle).
 */
trait StateRecord
{
    /** Erreur MariaDB ER_CHECKREAD (1020) : la ligne a changé depuis la lecture de la transaction. */
    private const SNAPSHOT_CONFLICT = 1020;

    abstract public static function describe(RuleViolation $e): string;

    /**
     * @return array<string, mixed>|null
     */
    public static function row(int $id): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => static::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();

        return $row === null ? null : $row;
    }

    /**
     * Transition atomique : mise à jour conditionnelle sur l'ancien état. Vrai si cette requête a fait la transition.
     *
     * @param list<string> $from
     * @param array<string, mixed> $set champs écrits en plus
     */
    private static function move(int $id, array $from, string $to, array $set = []): bool
    {
        global $DB;

        $current = self::row($id);
        try {
            $DB->update(static::getTable(), ['status' => $to, 'date_mod' => self::now()] + $set, ['id' => $id, 'status' => $from]);
        } catch (StatementException $e) {
            // Appelée dans la transaction du journal, sous isolation par instantané (MariaDB 11.x, innodb_snapshot_isolation :
            // erreur 1020 « Record has changed since last read ») : une décision concurrente a validé la ligne depuis la lecture
            // de la transaction. C'est le même cas qu'une ligne déjà décidée (0 ligne modifiée) : la transition est perdue.
            if ($e->getCode() === self::SNAPSHOT_CONFLICT) {
                return false;
            }
            throw $e;
        }
        $done = $DB->getAffectedRows() === 1;
        if ($done) {
            self::history($id, (string) ($current['status'] ?? ''), $to);
        }

        return $done;
    }

    /**
     * Historique des états (glpi_logs : date, utilisateur, ancien et nouvel état).
     */
    private static function history(int $id, string $old, string $new): void
    {
        Log::history($id, static::class, [0, $old, $new], '', Log::HISTORY_PLUGIN);
    }

    private static function now(): string
    {
        return Session::getCurrentTime() ?? date('Y-m-d H:i:s');
    }

    /**
     * Exécute une action de formulaire, signale le résultat et revient à la page précédente. Un refus de règle devient un
     * message d'erreur (rien n'est écrit) ; un refus de droit (exception HTTP) n'est pas intercepté.
     *
     * @param callable():(bool|null) $action
     * @param string $success message de réussite, déjà échappé (__s)
     */
    public static function respond(callable $action, string $success): never
    {
        try {
            if ($action() !== false) {
                Session::addMessageAfterRedirect($success);
            }
        } catch (RuleViolation $e) {
            Session::addMessageAfterRedirect(htmlescape(static::describe($e)), false, ERROR);
        } catch (\InvalidArgumentException) {
            Session::addMessageAfterRedirect(__s('The amount or the date is not valid', 'points'), false, ERROR);
        }
        \Html::back();
    }
}
