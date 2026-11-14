# Plan : fusion de tickets, constats ouverts, contrôle des soldes (branche `claude/fusion-soldes`)

Validé par le prompt du responsable (2026-11-14), dans son périmètre. Supprimé dans le dernier commit.

## 1. Fusion de tickets

Sources : documentation officielle (help.glpi-project.org, `llms.txt` du 2026-11-14 : pages Tickets, Gestion des tickets,
Aller plus loin, Actions, FAQ Ticket lues) : **aucune page ne décrit la fusion**. Le code 12.0.1 tranche (`src/Ticket.php:5765-6060`,
`Ticket::merge`) :

- un suivi est TOUJOURS ajouté au ticket principal avec `sourceitems_id` = source (5835-5850), même sans copie ni lien ;
- tâches copiées seulement si `TicketTask` est demandé (case « Fusionner les tâches », cochée par défaut, 2193-2198) ;
- lien au choix (2204-2210 : aucun, lié, doublon, fils de (défaut), parent de) ; posé APRÈS la copie des tâches (5915-5926) ;
- le source va à la corbeille (`delete`, pas de purge) à la fin (6020) ; `PRE_ITEM_DELETE` peut l'annuler
  (`src/CommonDBTM.php:2189-2197`) : l'exception remonte et toute la fusion est annulée (transaction, 6045-6055).

Écarts à prouver au banc (`tests/banc/scenario_fusion.py`), rouges avant correction :

- F1 double décompte : source au forfait débité, fusion avec tâches → copies débitées sur le principal à sa clôture.
- F2 points perdus : source au forfait approuvé (débit à la clôture), fusion → forfait jamais débité (source en corbeille).
- F3 points perdus : fusion SANS les tâches d'un source facturable à tâches non débitées → jamais débitées.
- F4 double décompte : fusion avec tâches et lien ≠ « fils de », source restauré puis clos → originaux débités une 2e fois.

Correction (crochets natifs, pas de SQL hors classe du plugin) :

- `PRE_ITEM_DELETE` sur Ticket : fusion en cours = suivi d'un autre ticket avec `sourceitems_id` = ce ticket ; refus si le source porte
  un forfait non refusé ni annulé (F1, F2) ou garde des tâches facturables non débitées sans copie sur le principal (F3).
- `Billing::mergedElsewhere` : ticket principal reconnu par le lien « fils de » OU par le suivi de fusion (F4).
- Question ouverte (défaut : refus) : le forfait doit-il suivre le ticket principal ?

## 2. Constats ouverts corrigeables dans le code

- Valideur client sans droit natif de validation : candidats filtrés par `User::getSqlSearchResult` (droits `validate_request`,
  `validate_incident`, comme la liste native, `src/CommonITILValidation.php:952`). Test banc rouge avant.
- Désinstallation : recherche enregistrée d'un autre type avec méta-critère sur une option du plugin. Test unitaire rouge avant
  (fonction pure), banc de désinstallation.
- Les autres restent inscrits (conception, limite, test), mis à jour.

## 3. Commande `plugins:points:balances`

Lecture seule ; par contrat : solde, crédité, consommé enregistrés (cache) contre recalcul depuis le journal (opérations) et somme des
restes des lots ; écarts listés, code 1 s'il y en a ; `--all` liste aussi les contrats justes. Domaine pur
`Domain\BalanceRules` (tests unitaires), banc `scenario_soldes.py` (cache faussé → code 1 ; remis → code 0). Fiche : README.

## Fin

`scripts/check.sh --banc` vert, sortie sur la PR ; relecteur Opus puis `/ponytail-review` (si disponible) ; `docs/suivi.md` à jour ;
plan supprimé ; PR brouillon vers main.
