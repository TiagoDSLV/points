# Plan : plugin « Points » réécrit de zéro pour GLPI 12

Branche `claude/busy-gates-snaod5` (dépôt TiagoDSLV/Points) ; PR en brouillon, jamais fusionnée.
Plan validé par le prompt du responsable du 2026-11-12, tant qu'il reste dans ce périmètre.

## Décisions de conception (prises dans le périmètre du prompt)

- Identité : clé `points`, espace de noms `GlpiPlugin\Points`, tables `glpi_plugin_points_*`, droits `plugin_points_*`.
  Rien n'est repris du code de `credit` ; le code du fork est retiré de la branche (reste dans l'historique et sur `main`).
- Cible : GLPI 12.0.1 (banc `glpi/glpi:12.0.1@sha256:02b4e814…`), PHP 8.3, bornes `12.0.0` / `12.0.99`.
- Journal en ajout seul : opérations (`operations`), lots de points (`lots`), affectations FIFO (`allocations`) ;
  aucune modification ni suppression (droits et hooks refusent), solde = somme du journal ; un cache de solde par
  contrat est recalculable à tout moment depuis le journal.
- Questions ouvertes : valeur par défaut paramétrable, rien de tranché (cahier des charges, section « Questions »).
- Tests : unitaires sans GLPI (`tests/unit/run.php`, sans dépendance) et banc jetable GLPI 12 sous Docker
  (`tests/banc/run.sh`, scénarios Python par l'API historique et SQL en lecture).

## Lots

| Lot | Contenu | État |
|---|---|---|
| 0 | Plan, cahier des charges (`docs/cahier-des-charges.md`), conception (`docs/conception.md`) | fait |
| a | Socle : setup, install / uninstall rejouables, droits, config, banc GLPI 12 | fait (relu, banc vert) |
| b | Enveloppes, journal, expiration (tâche auto), report, limite 2 ans | fait (relu, banc vert) |
| c | Décompte des tâches : quart d'heure, grilles versionnées, types de jour (calendrier natif), moment du débit | fait (relu, banc vert) |
| d | Forfaits : chiffrage, validation Responsable BU, validation native du client, montant figé | fait (relu, banc vert) |
| e | Reviews (hors créneau, férié) et compensations | fait (relu, banc vert) |
| f | Cloisonnement et droits par profil (deux clients au banc) | fait (relu, banc vert) |
| g | Alerte de seuil (notification native) et reporting par la recherche native | fait (relu, banc vert) |
| h | Import des soldes initiaux (format CSV proposé, simulation puis application) | à faire |
| i | PR atheo-assist (fiche 03, suivi point 1, droits proposés, plugins.json) | à faire |

## Reste / reprise

(mis à jour en fin de session)

- Lot g (relu en cours) : alerte `pointsalert` (`ThresholdAlert`, seuil sur les lots valides, modèle `threshold`), colonnes de cache `next_expiry` et
  `consumed_pct`, liste « Contrats à points », options sur Contract et Entity, onglet « Points » de l'entité ; banc `scenario_alerte.py`.
