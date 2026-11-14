<?php

declare(strict_types=1);

/**
 * Aide du banc (jamais déployée) : actions du cœur sans interface API v1, depuis la console du conteneur GLPI.
 * Entrée standard : {"call": "merge", "args": [cible, [sources]]} ; dernière ligne de sortie :
 * JSON {"ok": …} | {"error": …}.
 *
 *   merge : fusion de tickets (Ticket::merge, src/Ticket.php:5765), utilisateur « glpi » connecté ; par défaut avec copie des tâches et lien
 *           « fils de » ; troisième argument facultatif : paramètres de Ticket::merge (`linktypes`, `link_type`) ; renvoie aussi les messages.
 *   transfer : transfert natif d'un contrat vers une entité (Transfer::moveItems, src/Transfer.php:175 en 12.0.1), pour le refus d'un changement d'entité.
 *   flatrate_debit : GlpiPlugin\Points\Flatrate::debit(id), pour les débits concurrents d'un même forfait (lot d).
 *   estimate : GlpiPlugin\Points\Flatrate::estimate(ticket, heures), utilisateur « glpi » connecté (lot final : type « Ordre de travail » introuvable).
 *   search : plusieurs utilisateurs successifs DANS LE MÊME PROCESSUS (Session::init de chacun, comme l'action automatique des recherches
 *            enregistrées, src/SavedSearch_Alert.php:358-372), recherche native d'un type avec des options forcées ; args [[logins], type, [options]].
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
            $params = array_replace(['linktypes' => ['TicketTask']], (array) ($request['args'][2] ?? []));
            $done   = Ticket::merge((int) $request['args'][0], array_map('intval', $request['args'][1]), $status, $params);
            $messages = [];
            foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $list) {
                foreach ((array) $list as $message) {
                    $messages[] = html_entity_decode(strip_tags((string) $message), ENT_QUOTES);
                }
            }
            echo "\n" . json_encode(['ok' => ['done' => $done, 'status' => $status, 'messages' => $messages]]) . "\n";
            break;
        case 'transfer':
            $user = new User();
            $user->getFromDBbyName('glpi');
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user          = $user;
            Session::init($auth);
            Session::changeActiveEntities('all', true);
            $transfer = new Transfer();
            $transfer->moveItems(['Contract' => [(int) $request['args'][0]]], (int) $request['args'][1], []);
            echo "\n" . json_encode(['ok' => true]) . "\n";
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
        case 'estimate':
            $user = new User();
            $user->getFromDBbyName('glpi');
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user          = $user;
            Session::init($auth);
            Session::changeActiveEntities('all', true);
            echo "\n" . json_encode(['ok' => GlpiPlugin\Points\Flatrate::estimate((int) $request['args'][0], (string) $request['args'][1])]) . "\n";
            break;
        case 'search':
            [$logins, $itemtype, $ids] = $request['args'];
            $out = [];
            foreach ($logins as $login) {
                $user = new User();
                $user->getFromDBbyName($login);
                $auth = new Auth();
                $auth->auth_succeded = true;
                $auth->user          = $user;
                Session::init($auth);
                if (empty($_SESSION['glpiactiveprofile']) && !empty($_SESSION['glpiprofiles'])) {
                    // compte sans profil par défaut : premier profil de ses affectations (ce que fait la page de choix du profil)
                    Session::changeProfile((int) array_key_first($_SESSION['glpiprofiles']));
                }
                $options = Glpi\Search\SearchOption::getOptionsForItemtype($itemtype);
                $params  = Search::manageParams($itemtype, ['start' => 0, 'list_limit' => 999, 'criteria' => []], false, false);
                $data    = Search::getDatas($itemtype, $params, array_map('intval', $ids));
                $rows    = [];
                foreach ($data['data']['rows'] ?? [] as $row) {
                    $line = [];
                    foreach ($ids as $id) {
                        $line[(string) $id] = $row['raw']['ITEM_' . $itemtype . '_' . $id] ?? null;
                    }
                    $rows[] = $line;
                }
                $out[] = ['login' => $login, 'options' => array_values(array_map('strval', array_filter(array_keys($options), 'is_numeric'))), 'rows' => $rows];
            }
            echo "\n" . json_encode(['ok' => $out]) . "\n";
            break;
        default:
            throw new InvalidArgumentException('call not allowed');
    }
} catch (GlpiPlugin\Points\Domain\RuleViolation $e) {
    echo "\n" . json_encode(['violation' => $e->reason]) . "\n";
} catch (Throwable $e) {
    echo "\n" . json_encode(['error' => $e::class . ': ' . $e->getMessage()]) . "\n";
}
