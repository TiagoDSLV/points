# Points : conception technique

Cible : **GLPI 12.0.1** (image du banc `glpi/glpi:12.0.1@sha256:02b4e8146b9b71d833443718d093352609dafec1b32a1d218b5961d6492e7b06`),
PHP 8.3. Faits qualifiés : « lu 12.0.1 » = lu dans le code de l'image du banc (`src/…:ligne`), « banc » = vérifié
au banc, « doc » = documentation officielle citée. Le besoin en mots simples : `cahier-des-charges.md`.

## Identité

| Élément | Valeur |
|---|---|
| Clé et dossier | `points` (`plugins/points`) |
| Espace de noms | `GlpiPlugin\Points` (PSR-4, `src/`) |
| Tables | `glpi_plugin_points_*` |
| Droits | `plugin_points_*` (jamais `config`) |
| Contexte de configuration | `plugin:points` |
| Domaine de traduction | `points` ; chaînes sources en anglais, `locales/fr_FR.po` (interface en français), `en_GB.po` |
| Bornes | `glpi.min` 12.0.0, `glpi.max` 12.0.99, `php.min` 8.3 |

Aucune reprise du code, des tables ni des droits du plugin `credit` ; aucune migration depuis lui.

## Mécanismes natifs utilisés

| Besoin | Mécanisme natif de GLPI 12 | Source |
|---|---|---|
| Contrat d'un ticket | lien `Ticket_Contract` (`glpi_tickets_contracts`) ; contrat par défaut de l'entité posé à la création du ticket (`_contracts_id`, `Entity::getDefaultContract`) | lu 12.0.1 `src/Ticket_Contract.php:38`, `src/Ticket.php:1533-1540` ; le lien se crée sans contrôle d'entité : candidat au débit seulement s'il est de l'entité du ticket ou récursif d'une entité mère (section « Cloisonnement ») |
| BU | type de contrat (`contracttypes_id`), décision d'Assist du 07/11/26 (fiche 08) | atheo-assist |
| Jours fériés, créneaux | calendrier natif : `Calendar::isHoliday`, `isAWorkingHour` ; calendrier = celui du paramétrage, sinon celui de l'entité | lu 12.0.1 `src/Calendar.php:249,409` |
| Validation client d'un forfait | validation de ticket native (`TicketValidation`, statuts WAITING 2, ACCEPTED 3, REFUSED 4) | lu 12.0.1 `src/CommonITILValidation.php:64-67` |
| Alerte, notification au demandeur | notifications natives (`NotificationTarget`, `NotificationEvent::raiseEvent`) ; destinataires réglés dans l'interface | lu 12.0.1 `src/NotificationEvent.php:146` |
| Expiration, report, seuil | actions automatiques (`CronTask::register`) | lu 12.0.1 `src/CronTask.php:1075` |
| Reporting | moteur de recherche natif (`rawSearchOptions`, filtres, export) | doc développeur, « Search options » |
| Droits | `ProfileRight`, matrice de droits sur la fiche profil | lu 12.0.1 `src/ProfileRight.php:155,191` |
| Import | commande console du plugin (`src/Console/*Command.php`, chargée par `CommandLoader`, nom `plugins:points:…`) | lu 12.0.1 `src/Glpi/Console/CommandLoader.php:212-247` |

## Modèle de données

Toutes les tables portent `entities_id` (copie de l'entité du contrat, `is_recursive` = 0 ; grilles, versions, coûts et types d'intervention : globaux,
`is_recursive` = 1) pour le cloisonnement natif. Montants : `DECIMAL(12,2)`. Dates métier : `DATE` ou `TIMESTAMP` selon le champ.

| Table | Rôle | Champs principaux |
|---|---|---|
| `glpi_plugin_points_grids` | famille de grilles (Standard, Client VIP, Hospitalier, Astreinte HNO) | `name`, `comment`, `is_active`, `unit_minutes` (60 par défaut, 15 possible) |
| `glpi_plugin_points_gridversions` | version datée d'une famille | `plugin_points_grids_id`, `begin_date` (applicable à partir de), `comment` ; version applicable = plus grande `begin_date` ≤ date de l'opération |
| `glpi_plugin_points_gridcosts` | coût d'une version | `plugin_points_gridversions_id`, `plugin_points_interventiontypes_id`, `daytype` (1 semaine, 2 samedi, 3 dimanche et jour férié), `cost` ; unique (version, type, jour) |
| `glpi_plugin_points_interventiontypes` | liste (Assistance, Ordre de travail, Intervention urgente) | intitulé ; liste déroulante native |
| `glpi_plugin_points_contracts` | réglage Points d'un contrat natif (un pour un) | `contracts_id` unique, `plugin_points_grids_id`, `alert_threshold` (%, défaut du paramétrage : 80), `is_active`, cache : `balance`, `credited`, `consumed`, `date_cache` ; colonnes de recherche du cache : `next_expiry` (DATE), `consumed_pct` (DECIMAL(8,2)) |
| `glpi_plugin_points_envelopes` | enveloppe datée | `contracts_id`, `name`, `credit` (initial), `begin_date`, `end_date` (≤ début + 24 mois), `is_carryover`, `users_id`, `date_creation` ; dates non modifiables |
| `glpi_plugin_points_operations` | **journal en ajout seul** | `contracts_id`, `type`, `amount` (signé : + crédit, − débit), `date` (`DATE`, date métier : date de la tâche, de l'approbation… ; l'heure d'une tâche reste dans `details`), `tickets_id`, `itemtype` / `items_id` (TicketTask, forfait, compensation, review, enveloppe), `operations_id_origin` (opération corrigée ou liée), `comment` (motif), `details` (JSON : minutes saisies et comptées, type de jour, version de grille, coût unitaire, type d'intervention), `users_id`, `date_creation` |
| `glpi_plugin_points_lots` | lot de points créé par un crédit (FIFO, expiration) | `operations_id` (crédit, report entrant, import), `plugin_points_envelopes_id`, `contracts_id`, `origin_date` (date d'achat d'origine, conservée au report), `expiry_date` = min(fin de l'enveloppe, `origin_date` + 24 mois), `initial` |
| `glpi_plugin_points_allocations` | répartition d'une opération sur les lots | `operations_id`, `plugin_points_lots_id`, `amount` (signé) ; reste d'un lot = somme de ses affectations |
| `glpi_plugin_points_tickets` | réglage Points d'un ticket | `tickets_id` unique, `contracts_id` (contrat Points retenu), `plugin_points_interventiontypes_id`, `is_billable` ; la ligne est écrite pour tout ticket À LA CRÉATION (crochet ITEM_ADD du ticket) avec le type d'intervention du paramétrage de ce moment : le changer ensuite n'affecte plus les tickets existants ; un ticket sans ligne (antérieur au plugin) la reçoit au premier chemin d'ÉCRITURE (ajout ou modification d'une tâche, débit : `Billing::freezeType`), jamais en lecture (onglet, contrôle de clôture) ; la ligne suit l'entité de son ticket (`entities_id`, crochet ITEM_UPDATE du ticket) et se lit avec le droit du plugin, l'accès à son entité ET la lecture du ticket ; un type d'intervention qui a été le défaut d'un ticket n'est plus supprimable (la ligne le référence) ; l'onglet n'apparaît que si la ligne porte un contrat ou un ticket non facturable |
| `glpi_plugin_points_tasks` | état de décompte d'une tâche | `tickettasks_id` unique, `tickets_id`, `operations_id` (débit, 0 tant que non débitée), `is_locked`, `plugin_points_gridversions_id` (version de grille du débit, indexée, écrite dans la transaction du débit : une version utilisée n'est plus modifiable, `GridVersion::isUsed` lit cet index ; migration : reprise depuis les détails des opérations à chaque installation, pour les lignes encore à 0) |
| `glpi_plugin_points_flatrates` | forfait | `tickets_id`, `contracts_id`, `amount` (chiffré), `description`, `status`, `users_id` (technicien), `users_id_bu`, `date_bu`, `bu_comment`, `users_id_validate` (validateur client), `ticketvalidations_id`, `approved_amount` (figé), `operations_id` |
| `glpi_plugin_points_reviews` | contrôle d'un débit hors créneau ou férié | `operations_id` (débit), `tickets_id` et `contracts_id` (copies du débit, pour les filtres de la recherche), `reason` (samedi, dimanche, férié, hors créneau), `status` (à contrôler, confirmé, ajusté), `users_id`, `date_mod`, `comment`, `operations_id_adjustment` |
| `glpi_plugin_points_compensations` | demande de compensation | `operations_id` (débit visé), `tickets_id`, `contracts_id` (copie du débit), `amount` (demandé), `comment` (motif obligatoire), `status` (en attente, approuvée, rejetée), `users_id` (demandeur), `users_id_decision`, `date_decision`, `decision_comment`, `approved_amount`, `operations_id_credit` |

Types d'opération : `credit`, `import`, `debit_task`, `debit_flatrate`, `expiry`, `expiry_reversal`,
`carryover_out`, `carryover_in`, `correction`, `review_adjustment`, `compensation`. Une correction positive, un
remboursement ou une compensation ne peuvent viser qu'un débit (`debit_task`, `debit_flatrate`, correction ou
ajustement négatifs) ; une expiration ne se corrige que par le débit tardif ci-dessus, un report par un report.

Cache du contrat : `credited` = crédits, imports et reports reçus moins reports émis et expirations (points achetés
encore utilisables ou consommés) ; `consumed` = débits moins remboursements, corrections positives et compensations ;
`balance` = somme du journal. `consumed_pct` = `consumed` / `credited` du cache en % (0 si rien de crédité, arrondi au centième) et
`next_expiry` = plus petite échéance d'un lot au reste > 0 (NULL s'il n'y en a pas ; un lot échu au reste > 0 attend encore l'action
`pointsexpire`) : colonnes réelles et indexées, donc filtrables et triables par la recherche native sans `computation` SQL ; recalculées par
`Ledger` dans la même transaction que le cache (`writeCache`) et par `recomputeCache` ; la migration (réinstallation, rejouable) les remplit
pour les contrats déjà crédités, seulement quand ce passage vient d'ajouter les colonnes (un rejeu ne réécrit rien : un contrat aux lots épuisés a légitimement une échéance NULL).

**Crédité et consommé des lots valides** (base du seuil d'alerte, règle 12 ; ne lit pas le cache). Un lot est valide à la date du jour d si
`origin_date` <= d <= `expiry_date` (dernier jour inclus). Pour les lots valides : *crédité* = somme de leurs points initiaux (`initial` : un lot
reporté repart avec ce qui a été reporté, il garde son origine) ; *consommé* = somme, lot par lot, de `initial` - reste (reste = somme des
affectations du lot : débits, moins remboursements, corrections +, compensations et ajustements +). Un dépassement autorisé (reste négatif)
compte comme consommé au-delà du crédité. Un lot échu ou pas encore commencé ne compte ni au crédité ni au consommé, donc un lot échu
consommé en entier ne pèse pas : c'est la différence avec `credited` / `consumed` du cache (qui gardent la consommation des lots échus ;
le débit tardif d'un lot échu ne change donc pas le seuil tant que ce lot n'est pas valide). Seuil atteint si crédité > 0 et consommé x 100 >=
seuil x crédité, en centièmes entiers ; sans crédit valide, jamais d'alerte (aucune division).

Valeurs codées (`varchar(30)`) : forfait `draft`, `submitted`, `bu_refused`, `proposed`, `approved`,
`client_refused`, `debited`, `cancelled` ; review `to_review`, `confirmed`, `adjusted` ; raison de review `saturday`,
`sunday`, `holiday`, `off_hours` (plusieurs séparées par une virgule) ; compensation `pending`, `approved`, `rejected`.

Invariants (contrôlés par les tests et par `points:check` en console) : somme des `amount` d'une opération = somme de
ses affectations ; reste d'un lot ≥ 0, sauf sur un lot qui a reçu une affectation de dépassement (l'opération de débit porte `details.overdraft_lot` : le paramètre `allow_overdraft` du moment n'entre pas dans le contrôle, il a pu changer depuis ; tout autre reste négatif est un écart) ; solde du contrat =
somme du journal = somme des restes des lots ; aucune ligne de `operations`, `lots`, `allocations` n'est modifiée
ni supprimée (pas de `date_mod`). La commande `php bin/console plugins:points:check` contrôle ces invariants pour tous les contrats (`Ledger::checkAll`), en lecture seule, et sort avec un code non nul au moindre écart (supervision).

**Contrôle des soldes** (commande `php bin/console plugins:points:balances`, `Ledger::balances`, `Domain\BalanceRules`) : pour chaque contrat
qui a une ligne Points ou une opération, les valeurs ENREGISTRÉES de la ligne (`balance`, `credited`, `consumed`, `consumed_pct`,
`next_expiry`) sont comparées au recalcul depuis les mouvements (totaux du journal par type, par `ContractCache` : le même calcul que
l'écriture du cache, et restes des lots) ; la somme des restes des lots est comparée au solde recalculé. Écarts listés (`row` : contrat à
mouvements sans ligne Points ; `lots` : journal contre restes), code de sortie 1 s'il y en a un. Lecture seule (aucune écriture, pas même le
cache). Différence avec `plugins:points:check` : `check` contrôle les invariants du journal (opération = affectations, restes négatifs) et le
solde, le crédité et le consommé du cache ; `balances` rend le rapport chiffré par contrat (enregistré contre recalculé), colonnes de recherche
comprises (% consommé, prochaine échéance), pour la recette et après la bascule. Options : `--all` liste aussi les contrats justes,
`--contract=ID` un seul contrat. Tests : `tests/unit/BalanceRulesTest.php`, banc `scenario_soldes.py`.

## Date du jour

La date métier de tout ce qui a lieu « maintenant » (crédit sans date, décision d'une compensation ou d'une review, correction, approbation et débit
d'un forfait, passage de l'action automatique d'expiration et d'alerte, notification du seuil, plan d'import) est le jour du **fuseau de référence**
(règle 2) : `Billing::today()`, calculé par `Dates::todayIn`. Jamais le jour du fuseau de la session ou du processus.

## Règles de calcul

1. **Durée comptée** : `ceil(minutes saisies / 15) × 15` ; 0 minute = 0 point.
2. **Date d'une tâche** : début planifié (`begin`) s'il existe, sinon `date`, lue dans le **fuseau de référence**
   (`date.timezone` du PHP du serveur, UTC à défaut : GLPI 12 n'a pas de fuseau global, lu 12.0.1
   `src/DBmysql.php:326,1832-1840` ; **à régler sur `Europe/Paris` dans l'image d'Assist**, sinon une tâche du samedi 0 h 30
   est comptée vendredi ; jamais le fuseau de l'utilisateur ni du processus de la tâche automatique) avant tout calcul de
   type de jour, de créneau, de version de grille et de lot. Durée et dates prises après le calcul de GLPI (modèle de
   tâche compris).
3. **Type de jour** : jour férié du calendrier ou dimanche → 3 ; samedi → 2 ; sinon 1. Calendrier : paramètre
   `calendars_id` du plugin, sinon calendrier de l'entité du ticket (stratégie native d'héritage).
4. **Coût** : version de la grille du contrat applicable à la date de la tâche ; coût (type d'intervention du ticket,
   type de jour) ; points = arrondi au centième de `coût × minutes comptées / unit_minutes`.
5. **FIFO** : un débit prend les lots du contrat valides à la date métier (`origin_date` ≤ date ≤ `expiry_date`),
   reste > 0, triés par `origin_date` puis id. Ce qui ne trouve pas de lot va en **dépassement** sur le lot valide le
   plus récent (paramètre `allow_overdraft`, défaut 1) ou est refusé (0). **Débit tardif** (débit à la clôture
   d'une tâche datée d'avant l'échéance d'un lot déjà expiré) : le débit reprend d'abord, sur ce lot, les points
   expirés (opération `expiry_reversal` liée à l'opération `expiry`), puis les consomme ; le client ne paie jamais un
   point à la fois expiré et débité. Points reportés : le lot reçu garde son origine, il reste valide à la date de la
   tâche et le FIFO le prend normalement. Sans dépassement autorisé, le contrôle porte sur le solde réel des lots
   valides (restes négatifs compris).
6. **Garde-fous** : tâche refusée (ticket facturable), à l'ajout comme à la modification d'une tâche non débitée, s'il
   n'existe aucun point valide à sa date (lot dont l'origine ≤ date ≤ échéance : un crédit daté du 01/03 ne couvre pas
   une tâche du 15/02) ; durée et date
   d'une tâche débitée verrouillées (modification et suppression refusées) ; un ajustement à la baisse (débit en plus)
   exige un solde suffisant.
   Une tâche débitée l'est **définitivement** : un remboursement ou une compensation ne la rend jamais débitable une
   seconde fois (même règle pour un forfait). Une tâche recopiée par la fusion de tickets (`sourceitems_id`) depuis
   une tâche débitée est enregistrée verrouillée, sans nouveau débit ; une tâche NON débitée copiée par la fusion (`sourceitems_id` = le ticket source, même durée, dates, contenu et technicien) n'est débitée que sur le ticket principal, pourvu que le ticket de la copie soit le principal d'une fusion du ticket source, reconnu par le suivi que `Ticket::merge` y pose TOUJOURS (`glpi_itilfollowups`, `itemtype` Ticket, `items_id` = principal, `sourceitems_id` = source : lu 12.0.1 `src/Ticket.php:5835-5850`), quel que soit le lien choisi à la fusion (aucun, lié, doublon, fils de, parent de : `src/Ticket.php:2204-2210` ; le lien « fils de » seul ne suffisait pas : fusion « lié à » ou sans lien, source restauré puis clos = double débit, banc `scenario_fusion.py`) : une copie posée à la main sans fusion n'exclut rien (`Billing::mergeTargets`). Le ticket source, restauré puis clos, ne débite donc pas ses originaux une seconde fois (`Billing::pendingTasks`, `mergedElsewhere`) ; son onglet les montre « débitées sur le ticket principal », hors total ; une tâche ajoutée au ticket restauré, sans copie ailleurs, se débite normalement. **Fusion refusée** (crochet `PRE_ITEM_DELETE` du ticket : `Ticket::merge` met le source à la corbeille en dernier, `src/Ticket.php:6020`, et un refus annule toute la fusion par sa transaction, `src/Ticket.php:6045-6055` ; `Billing::mergeProblem`) quand des points resteraient derrière le source : (a) il porte un forfait ni refusé ni annulé (règle 9) ; (b) il garde une tâche facturable non débitée qui n'est pas recopiée sur le principal (fusion sans « Fusionner les tâches ») : à la corbeille, elle ne serait jamais débitée. Hors fusion (aucun suivi de fusion), la mise à la corbeille ne change pas. La documentation officielle ne décrit pas la fusion (help.glpi-project.org, `llms.txt` du 2026-11-14) : le code 12.0.1 tranche. Une clôture impossible laisse le ticket Résolu
   (avec un message, et une trace pour la clôture automatique), jamais dans un état intermédiaire.
7. **Moment du débit** (paramètre `debit_moment`) : `closure` (défaut, ticket passé à Clos) ou `task` (à l'ajout de
   la tâche). Une tâche ajoutée après une réouverture est débitée à la clôture suivante.
8. **Expiration** (action automatique quotidienne `pointsexpire`) : pour chaque lot échu au reste > 0 : si son
   enveloppe est reportable et qu'une enveloppe suivante du même contrat existe, report (`carryover_out` +
   `carryover_in`, nouveau lot qui garde `origin_date`, échéance = min(fin de la cible, origine + 24 mois)) ;
   sinon `expiry`. Un report manuel (ADV) suit la même règle.
9. **Forfait** : statuts brouillon → soumis au Responsable BU → refusé BU (retour brouillon) | proposé au client
   (validation native créée) → approuvé (montant figé) | refusé client → débité. Tant qu'un forfait non refusé ni
   annulé existe sur le ticket, ses tâches ne sont pas débitées. Débit du montant approuvé au moment du débit choisi.
   Précisions (lot d) : un seul forfait en cours par ticket, et aucun forfait sur un ticket dont des tâches sont déjà
   débitées ; montant modifiable en brouillon seulement ; proposition = validation native de ticket adressée au
   valideur choisi (défaut : demandeur), qui doit avoir le droit natif de répondre à une validation de ticket dans l'entité du ticket (`ticketvalidation` VALIDATEREQUEST ou VALIDATEINCIDENT, lu par la requête de la liste native des approbateurs, `User::getSqlSearchResult` : lu 12.0.1 `src/CommonITILValidation.php:952`, `src/User.php:4285-4304` ; sans lui il ne pourrait pas répondre) ; la validation liée ne peut pas
   être supprimée tant que le forfait est proposé, approuvé ou débité ; date métier du débit = date de la réponse du
   client ; historique des états dans l'historique natif (`glpi_logs`) ; le Responsable BU ne valide pas un forfait
   qu'il a lui-même chiffré (question 8 du cahier des charges, réglage par défaut) ; un ticket au forfait
   (ni refusé ni annulé) ne peut pas être le source d'une fusion (règle 6 ; qu'il suive le principal reste une question ouverte, `suivi.md`).
10. **Review** : créée pour tout débit de tâche hors créneau (calendrier) ou de type de jour 2 ou 3 ; confirmée, ou
    ajustée par une opération `review_adjustment` liée.
    Précisions (lot e) : la review naît dans la même transaction que le débit de la tâche (option `inside` de
    `Ledger::debit`), une seule par débit, et porte l'entité du contrat (celle de l'opération), pas celle du ticket ; la confirmation n'écrit rien au journal ; un ajustement exige un motif et se
    décide une seule fois (transition atomique) : + rembourse aux lots du débit dans l'ordre inverse, au plus ce que le
    débit a pris (`Ledger::refund`, qui compte aussi les corrections et compensations déjà rendues) ; − est un débit en plus,
    daté du jour du débit contrôlé, qui exige un solde réel suffisant et ne dépasse jamais (même si `allow_overdraft` vaut
    1). La décision et l'opération sont écrites dans la même transaction du journal. Droit `plugin_points_review` : READ
    voir, UPDATE décider, plus l'entité de la review.
11. **Compensation** (part d'un remboursement qui retomberait sur un lot échu : créditée sur le lot valide le plus
   récent du contrat, nouveau lot daté du jour ; refus si aucun lot valide — question 10 du cahier des charges,
   paramètre `compensation_on_expired` = `valid_lot` par défaut, `origin` sinon) : motif obligatoire ; montant approuvé ≤ débit visé − compensations déjà approuvées ; crédit lié
    (`compensation`, réaffecté aux lots du débit d'origine) ; demandeur notifié.
    Un lot échu EN DÉPASSEMENT (reste négatif) garde d'abord la part qui comble ce reste (min(part, −reste)) : seul le surplus est redirigé,
    sinon le remboursement créerait des points (ex. lot de 10, débit de 30 → −20 ; une compensation de 30 rend 20 au lot échu et 10 au nouveau lot).
    Mise en œuvre (lot final, `Ledger::writeRefund`, commune à la compensation, à la correction + et à l'ajustement + de review) : « échu » =
    échéance du lot avant le jour de l'opération ; la part qui retombe sur un lot échu est créditée sur un NOUVEAU lot (origine = jour de
    l'opération, échéance = min(fin de l'enveloppe du lot valide le plus récent, jour + durée de vie), `initial` = la part) ; refus
    `refund_no_valid_lot` (rien d'écrit) s'il n'y a aucun lot valide ce jour-là. La part redirigée est notée dans les détails de
    l'opération (`redirected` : lot d'origine → centièmes) : elle compte comme déjà rendue sur le lot d'origine (`refundedByLot`), donc le
    plafond (`Ledger::refundable`) ne la rend pas une seconde fois. Avec `origin`, comportement d'avant : la part est rendue au lot échu.
    Précisions (lot e) : on ne compense qu'un débit de tâche ou de forfait ; demander exige le bit CREATE, l'accès à
    l'entité du débit et la lecture du ticket (`Ticket::can`) ; décider exige le bit VALIDATE et l'accès à l'entité (pas
    la lecture du ticket : la décision est financière) ; la demande est positive et au plus égale à ce que le débit peut encore rendre
    (`Ledger::refundable` : ce qu'il a pris moins toutes les affectations positives liées, corrections +, compensations
    approuvées, ajustements + de review) ; le montant approuvé vaut par défaut le montant demandé, jamais plus ; un rejet exige un motif de
    décision ; une seule décision par demande (transition atomique, le crédit et le passage à « approuvée » sont écrits
    dans la même transaction) ; le décideur ne décide pas sa propre demande tant que `compensation_separation` vaut 1 ;
    le plafond est revérifié sous le verrou du contrat par `Ledger::refund`. Notification native `compensation_decided`
    (modèle FR/EN semé, cible : auteur de la demande), déclenchée à l'approbation comme au rejet.
12. **Alerte** (action automatique `pointsalert`) : consommé / crédité des lots valides ≥ seuil du contrat →
    notification `threshold`, une fois par passage du seuil (alerte native `glpi_alerts`, remise à zéro par un crédit).
    Un lot créé par la redirection d'un remboursement (règle 11) rend des points, il n'en achète pas : il est exclu du crédité et son reste vient en déduction du consommé (jamais sous 0). Précisions (lot g) : « crédité » et « consommé » des lots valides sont définis dans « Cache du contrat ». L'action quotidienne
    (`Cron::cronPointsalert`, `ThresholdAlert::run`) parcourt les lignes Points actives (`is_active` = 1) dont le contrat natif n'est ni supprimé ni
    un modèle ; elle lève l'événement natif `threshold` (objet : la ligne Points, classe `NotificationTargetContract`) puis mémorise le passage
    dans `glpi_alerts` (type `Alert::THRESHOLD`, `items_id` = ligne Points) ; rien n'est mémorisé si la levée échoue (retentée le lendemain) ; un
    contrat déjà alerté n'est pas notifié de nouveau. Les totaux sont relus sous le verrou du contrat (`Ledger::locked`) juste avant la levée : s'ils n'atteignent plus le seuil, rien n'est levé. Un seuil modifié (`Contract::post_updateItem`) efface l'alerte : le contrat est réévalué au passage suivant avec son nouveau seuil. La remise à zéro (effacement de l'alerte) a lieu : (a) dans la transaction du journal qui
    fait repasser le contrat sous le seuil (crédit, remboursement : `Ledger::writeCache` → `ThresholdAlert::resetIfBelow`), donc un nouveau
    franchissement est notifié même entre deux passages ; (b) au passage suivant si le seuil du contrat a été relevé ou si un lot échu fait
    repasser sous le seuil. Destinataire par défaut : l'administrateur de l'entité du contrat (cible native `Notification::ENTITY_ADMINISTRATOR`,
    question 4 du cahier des charges ; adresse = celle de l'entité, `glpi_entities.admin_email`, héritée) ; d'autres cibles natives se règlent dans
    l'interface. Modèle FR/EN semé (rejouable, supprimé à la désinstallation) ; balises : `##points.contract##` (contrat), `##points.client##`
    (entité), `##points.balance##` (solde du cache), `##points.credited##` et `##points.consumed##` (lots valides), `##points.percent##` (% consommé
    des lots valides), `##points.threshold##` (seuil), `##points.expiry##` (prochaine échéance), `##points.url##`, `##points.action##`.

## Désinstallation (lot final)

`Install::uninstall` supprime, dans l'ordre : (1) les validations de ticket natives EN ATTENTE des forfaits proposés (le forfait passe à
« annulé », GLPI purge la validation et le statut global de validation du ticket est recalculé : `Flatrate::purgeProposedValidation`, le
même chemin que l'annulation d'un forfait) ; (2) les tables du plugin, les droits, la configuration, les actions automatiques ; (3) les
notifications, modèles, alertes, courriels en file d'attente (`glpi_queuednotifications`), historiques et préférences d'affichage dont l'itemtype est une classe du plugin ; (4) les colonnes
d'affichage du contrat et de l'entité natifs portant les options 9701-9707 et 9721-9725 ; (5) les recherches enregistrées, de tout type, qui utilisent
l'une de ces options (critère, tri, ou méta-critère d'une recherche d'un autre type, d'un ticket par exemple, groupes compris : `SearchOptions::queryUses`, qui ne compte un numéro que pour le type qu'il désigne) ou dont l'itemtype est une classe du plugin, par
`SavedSearch::delete(…, true)` qui supprime aussi leurs tables filles (utilisateurs par défaut, alertes). Les recherches et colonnes du cœur
restent. Vérifié au banc APRÈS usage réel (`scenario_zdesinstallation.py`) : aucune ligne du cœur ne porte plus un itemtype du plugin,
puis réinstallation propre.

## Droits du plugin

Tous à 0 à l'installation, sauf Super-Admin (tout). Bits natifs : READ 1, UPDATE 2, CREATE 4, PURGE 16 ; VALIDATE
1024 (bit propre, comme `ticketvalidation`).

| Droit | Bits | Responsable client | Technicien N1/N2/N3 | Responsable BU | Facturation / ADV | Admin |
|---|---|---|---|---|---|---|
| `plugin_points_balance` : soldes, enveloppes, journal | READ | 1 | 1 | 1 | 1 | 1 |
| `plugin_points_ticket` : réglage Points d'un ticket | READ, UPDATE | 0 | 3 | 1 | 3 | 3 |
| `plugin_points_envelope` : enveloppes, crédits, corrections, reports, import | READ, CREATE, UPDATE | 0 | 0 | 1 | 7 | 1 |
| `plugin_points_flatrate` : forfaits (chiffrer, soumettre ; VALIDATE = valider BU) | READ, CREATE, UPDATE, VALIDATE | 0 | 7 | 1025 | 1 | 1 |
| `plugin_points_review` : contrôles | READ, UPDATE | 0 | 0 | 1 | 3 | 1 |
| `plugin_points_compensation` : demander (CREATE), décider (VALIDATE) | READ, CREATE, VALIDATE | 0 | 0 | 1 | 1029 | 1 |
| `plugin_points_grid` : grilles, types d'intervention | READ, CREATE, UPDATE, PURGE | 0 | 1 | 1 | 1 | 23 |
| `plugin_points_config` : paramétrage | READ, UPDATE | 0 | 0 | 0 | 0 | 3 |

À l'installation, tous les bits aux profils ayant le droit config en écriture, 0 ailleurs ; Assist écrit ensuite les droits en dur.

Valeurs proposées pour `deploy/config/profils-cibles-valeurs.sql` d'atheo-assist (non appliquées). Le Demandeur et
les comptes de service : 0 partout.

## Écrans

| Où | Contenu | Droit |
|---|---|---|
| Contrat, onglet « Points » | réglage (grille, seuil), solde, enveloppes, registre ; actions ADV : créer une enveloppe, créditer, corriger, reporter (un contrat dont le type n'est pas dans `contracttypes` n'a ni onglet ni écriture, sauf s'il a déjà une ligne Points) | balance READ ; envelope pour écrire |
| Ticket, onglet « Points » | contrat Points, type d'intervention, tâches et débits (total = débit net signé du journal, `Ledger::netDebits` : débit moins remboursements, corrections +, compensations, ajustements + plus débits en plus), forfait (chiffrer, soumettre, valider BU, proposer), compensation | ticket, flatrate, compensation |
| Entité, onglet « Points » | soldes des contrats à points de l'entité elle-même (entité propre, pas ses sous-entités ni un contrat d'une entité mère) : BU, solde, crédité, consommé, % consommé, seuil, prochaine échéance, lien vers chaque contrat (si le contrat est lisible) ; onglet absent s'il n'y a aucun contrat | balance READ + lecture native de l'entité (`Entity::can`) + accès à l'entité |
| Interface simplifiée (Responsable client) | entrée « Points » du menu (`Hooks::HELPDESK_MENU_ENTRY` + icône, `front/points.php`, classe `Simplified`) : pour chaque contrat à points de ses entités actives, solde, enveloppes (échéances, reste) et registre (date, type, points, ticket ; ni auteur ni commentaire) ; lecture seule, aucun formulaire ; pas d'onglet sur la fiche contrat, **refusée en interface simplifiée** (fiche servie par un contrôleur moderne, stratégie par défaut `central_access` qui refuse un profil `helpdesk` : lu 12.0.1 `src/Glpi/Http/Firewall.php:152,170-190`, `src/Session.php:1219-1224` ; **vérifié au banc 12.0.1** : 403 sur `/front/contract.form.php?id=…` pour le profil simplifié, y compris pour son propre contrat, 200 pour le même contrat en interface standard) | balance READ |
| Outils > Points | « Contrats à points » (`front/contract.php`, recherche native, filtres, tri, export CSV : contrat, client = entité, BU = type de contrat, grille, solde, crédité, consommé, % consommé, prochaine échéance, seuil, actif), reviews à contrôler, compensations en attente ; options de recherche ajoutées au Contract et à l'Entity natifs pour le portefeuille (crochet `plugin_points_getAddSearchOptionsNew`, numéros 9701-9707 et 9721-9725) | balance READ (contrats) ; review, compensation |
| Configuration > Points | paramétrage, grilles, types d'intervention | config, grid |

## Cloisonnement par entité et par profil (lot f)

Chaque ligne du plugin porte l'entité du contrat, sans récursivité (`is_recursive` = 0) ; toute lecture et toute écriture exige l'accès à cette
entité (`Session::haveAccessToEntity`, entités actives du profil) EN PLUS du droit de la table « Droits du plugin ». Vérifié au banc 12.0.1
(`tests/banc/scenario_cloisonnement.py`, profils nommés comme ceux d'Assist, droits relus dans la table ci-dessus ; chaque ensemble de comptes
qui réussit est comparé à l'ensemble attendu, donc chaque refus a son témoin positif) : getItem, recherche native, getMultipleItems, add / update /
delete par l'API v1, onglets, formulaires, pages, export CSV natif (`front/report.dynamic.php`).

- **Un client ne voit jamais les points de l'autre** : ni par l'API, ni par la recherche, ni par l'export. Le Responsable client joué deux
  fois (interface standard, décision de principe du responsable, suivi Assist point 50 ; interface simplifiée) voit ses contrats seulement.
  Le Responsable client de l'interface standard voit l'onglet « Points » de SES contrats seulement (droit natif de lecture du contrat requis).
- **Interface simplifiée : `Profile::$helpdesk_rights`** (lu 12.0.1 `src/Profile.php:75,674-685`, `src/Session.php:636-641`) : à l'ouverture de
  session, un profil `helpdesk` perd tout droit qui n'est pas dans cette liste, même enregistré en base. `setup.php` y ajoute
  `plugin_points_balance` ; sans cela le droit restait à 0 pour le Responsable client en interface simplifiée (défaut trouvé au banc, lot f).
  Le script `front/points.php` est déclaré en `STRATEGY_HELPDESK_ACCESS` (par défaut un script de plugin est en `STRATEGY_AUTHENTICATED` :
  `Firewall.php:91,226,261`) : les profils standard n'y accèdent pas (ils ont l'onglet du contrat).
- **Lien ticket - contrat sans contrôle d'entité** (défaut trouvé au banc) : le cœur crée le lien `Ticket_Contract` sans vérifier l'entité
  (`_contracts_id` à la création d'un ticket par un Demandeur, API) ; un ticket du client A lié au contrat du client B faisait débiter les
  points de B. Seul un contrat de l'entité du ticket, ou récursif d'une entité mère, est maintenant candidat au débit
  (`Billing::context`, `getEntitiesRestrictCriteria` récursif).
- **Contrat récursif d'une entité mère** (comportement mesuré avant correction, puis règle) : le contrat natif récursif est lisible depuis
  l'entité fille (droit du cœur). Avant : l'onglet « Points » montrait solde, enveloppes et registre à un compte de l'entité fille, alors que
  l'API et la recherche le refusaient (lignes du plugin à l'entité de la mère, non récursives) ; et un compte ADV de l'entité fille pouvait
  créditer, corriger, créer une enveloppe ou reporter sur le journal du contrat de la mère (`Contract::requireContract` ne contrôlait que
  `Contract::can(READ)`, récursif). Règle retenue : **ni lecture ni écriture des points depuis une entité fille** (onglet masqué, API et
  recherche refusées, page de l'interface simplifiée sans ce contrat, formulaires d'écriture 403) ; seuls les comptes dont les entités actives
  contiennent l'entité propre du contrat l'utilisent. Un ticket de l'entité fille lié à ce contrat est débité normalement sur le contrat de la
  mère (l'opération porte l'entité de la mère). Pour qu'un compte d'une entité fille voie les points, lui donner l'accès à l'entité du contrat,
  ou créer le contrat dans son entité.
- **Champs internes du journal** (`InternalFields`) : commentaire ou motif, détails de calcul, auteur et opération corrigée d'une opération, auteur d'une
  enveloppe ne sont lus qu'avec `plugin_points_envelope` READ ou `plugin_points_review` READ (ADV, Responsable BU, Admin ; ni le Responsable client ni le
  technicien) ; critère le plus simple, choisi pour ne pas créer de droit. Montant, type, date, contrat, enveloppes et soldes restent lisibles avec
  `plugin_points_balance`. Mécanismes (lu 12.0.1) : `Operation::unsetUndisclosedFields` et `Envelope::unsetUndisclosedFields` retirent les champs ;
  l'API v1 l'appelle pour getItem (`src/Glpi/Api/API.php:676`), getItems et ses sous-éléments (`:1386`), les colonnes d'une recherche (`:1823`) et le
  filtre `searchText` (`:1295` : le champ retiré n'entre pas dans le filtre, qui est ignoré sans erreur, donc aucune sonde possible : mesuré au banc) ;
  `post_getFromDB` vide les champs pour getMultipleItems et les pages natives (`src/CommonDBTM.php:352,475`) ; les options de recherche 6 (commentaire) et
  8 (auteur) de l'opération n'existent pas sans le droit (recherche native et export ne connaissent que `rawSearchOptions`,
  `src/Glpi/Search/SearchOption.php:155-170` ; un critère de la recherche native sur une option absente est refusé, 400) ; l'onglet du contrat n'affiche ni
  commentaire ni auteur. Historique natif : les objets du plugin n'ont pas d'onglet Historique (`dohistory` faux) ; seuls les changements d'état des
  reviews et compensations sont écrits dans `glpi_logs` (lisibles avec leur droit, sans page pour le Responsable client) ; l'historique du contrat natif
  ne contient rien du plugin (vérifié au banc, section 7). Vérifié au banc, mutations à l'appui.
- **Options de recherche du portefeuille** (lot g, `SearchOptions`) : la liste « Contrats à points » est la recherche native de la table du plugin
  (lignes non récursives, entité propre du contrat : le moteur ne montre que les entités actives de l'utilisateur) ; les options ajoutées au Contract
  et à l'Entity natifs n'existent qu'avec `plugin_points_balance` READ (sans lui, ni option ni critère accepté), et leur jointure ne retient que les
  lignes Points dont l'entité propre est une entité active de l'utilisateur COURANT (`ActiveEntitiesCondition`, une `QueryExpression` relue à chaque
  construction de requête : les options sont en cache statique pour tout le processus, src/Glpi/Search/SearchOption.php:84,161-168,435 en 12.0.1, alors que
  des actions automatiques enchaînent plusieurs utilisateurs, src/SavedSearch_Alert.php:358-372 ; le cache est en plus vidé au changement de profil,
  crochet CHANGE_PROFILE ; vérifié au banc avec plusieurs utilisateurs successifs dans un même processus). Les contrats natifs à la corbeille et les
  modèles sont exclus (jointure de l'entité, liste par `getSystemSQLCriteria`, onglet et son compte) : un contrat récursif d'une entité mère, lisible par
  l'entité fille par le droit du cœur, y garde des cellules vides et ne répond à aucun filtre (aucune sonde). Aucune option ne lit un champ interne
  (`InternalFields`) ni une table du journal : seuls les champs du cache, la grille, le contrat, l'entité et le type de contrat. Pour l'entité, une
  cellule regroupe une valeur par contrat à points de l'entité. La recherche de l'Entity exige en plus le droit natif de lecture des entités (un
  Responsable client sans ce droit passe par l'onglet du contrat ou la page de l'interface simplifiée). Vérifié au banc (`scenario_alerte.py`, deux
  clients, entité fille, contrat récursif, compte sans droit, mutations à l'appui).
- **Purge d'un contrat à points** : `Contract::contractPrePurge` (hook PRE_ITEM_PURGE sur le contrat natif) refuse la purge d'un contrat qui a une
  ligne Points (message) : le journal, en ajout seul, porte l'id du contrat. La mise à la corbeille reste possible.
- **Type du contrat** : `Contract::requireContract` (gardes de `contract.form.php`, `credit.form.php`, `envelope.form.php`, `correction.form.php`,
  `carryover.form.php`) refuse (403) un contrat dont le type n'est pas dans `contracttypes`, sauf s'il a déjà une ligne Points (un type retiré du
  paramétrage ne rend pas son journal inaccessible).
- **Entité d'un contrat à points** : `Contract::contractPreUpdate` (hook PRE_ITEM_UPDATE sur le contrat natif) refuse tout changement d'`entities_id` d'un
  contrat qui a une ligne Points (message) : les lignes du journal portent l'entité du contrat et ne se modifient pas. Le transfert natif passe par
  `$item->update()` (`Transfer::transferItem`, `src/Transfer.php:1240` en 12.0.1) : il est donc refusé aussi (vérifié au banc par `Transfer::moveItems`).
  Pour changer de client, créer un nouveau contrat et reprendre les soldes par l'import.
- **Forfait** : avant `Ledger::debit` (et dans le contrôle de clôture), le contrat du forfait doit rester candidat au débit du ticket
  (`Billing::context`) ; un ticket passé chez un autre client ne débite pas le contrat de l'ancien (refus `flatrate_no_contract`).
- **Limites connues** : (1) les commentaires des compensations et des reviews restent lisibles avec leur propre droit de lecture (qui s'accompagne du droit
  interne dans les profils de la table) ; le détail des tâches de l'onglet du ticket reste lu avec `plugin_points_ticket` READ ; (2) l'onglet
  « Ticket » montre au technicien d'une entité fille le total des points débités par les tâches de son propre ticket sur le contrat de la mère (sans le nom du contrat).

## Paramétrage (`plugin:points`)

| Clé | Défaut | Sens |
|---|---|---|
| `contracttypes` | `[]` | types de contrat portant des points (ex. « Serenity à points ») |
| `debit_moment` | `closure` | `closure` ou `task` (question 1) |
| `default_alert_threshold` | 80 | seuil par défaut d'un nouveau contrat |
| `allow_overdraft` | 1 | dépassement autorisé et signalé (question 7) |
| `calendars_id` | 0 | calendrier des types de jour ; 0 = celui de l'entité |
| `default_interventiontypes_id` | Assistance | type d'intervention d'un nouveau ticket |
| `retention_months` | 24 | durée maximale de vie d'un point ; ne peut pas dépasser 24 |
| `flatrate_separation` | 1 | 1 : le Responsable BU ne valide pas un forfait qu'il a chiffré (question 8) ; 0 : permis |
| `compensation_separation` | 1 | 1 : celui qui a demandé une compensation ne la décide pas lui-même ; 0 : permis (règle 11, lot e) |
| `compensation_on_expired` | `valid_lot` | part d'un remboursement (compensation, correction +, ajustement + de review) qui retomberait sur un lot échu : `valid_lot` la crédite sur le lot valide le plus récent, refus s'il n'y en a aucun ; `origin` la rend au lot échu (elle expire aussitôt) (règle 11, question 10 du cahier des charges) |

Une saisie est refusée en entier si une valeur est invalide ; `default_interventiontypes_id` et `calendars_id` doivent désigner une ligne qui existe
(le calendrier 0 = celui de l'entité est permis ; le type par défaut ne peut pas être 0 une fois des types créés). La configuration est lue une fois par
processus (`Config::get`) et le cache est vidé par `Config::save` et par l'installation. L'estimation d'un forfait lit le prix du type « Ordre de travail » :
renommé ou supprimé, elle le dit (`flatrate_no_estimate_type`) au lieu d'annoncer un montant invalide.

## Import des soldes du BPM Kiwi (lot h)

Commande console du plugin : `php bin/console plugins:points:import fichier.csv [--apply]` (classe `Console\ImportCommand`). Le nom
porte le préfixe `plugins:points:` : le chargeur des commandes de plugin ignore, avec un avertissement, toute commande qui ne le porte pas
(lu 12.0.1 `src/Glpi/Console/CommandLoader.php:261-280`). Sans `--apply` : simulation, rapport ligne à ligne, aucune écriture (pas même la
ligne Points d'un contrat). Options : `--apply`, `--accept-similar` (voir « Comportement »).

**Format.** Texte UTF-8 (BOM toléré), fins de ligne Unix, Windows ou Mac, séparateur `;`, première ligne = en-tête exact (casse libre)
`contrat;solde;debut;fin;report;libelle`, une enveloppe par ligne, lignes vides ignorées, espaces autour des cellules retirés. Une cellule peut
être entre guillemets (pour contenir un `;`) mais pas sur plusieurs lignes.

| Colonne | Contenu | Contrôle |
|---|---|---|
| `contrat` | numéro du contrat, tel que dans le champ « Numéro » (`num`) du contrat GLPI | un seul contrat natif de ce numéro, ni à la corbeille ni modèle, dont le type est un type à points du paramétrage (`contracttypes`) ; la ligne Points est créée à l'écriture si elle manque (entité du contrat) |
| `solde` | solde restant, en points | strictement `^\+?\d+([.,]\d{1,2})?$` (virgule OU point décimal, **au plus deux décimales**, après retrait des espaces ordinaires), **> 0** : aucun arrondi silencieux (`1.500`, `12,345`, `0,005` refusés), ni signe moins, ni séparateur de milliers, ni espace insécable |
| `debut`, `fin` | `AAAA-MM-JJ` | dates du calendrier, fin >= début, fin <= début + 24 mois (24 mois pile accepté) |
| `report` | `0` ou `1` | enveloppe reportable (règle 8) |
| `libelle` | libellé libre, devient le nom de l'enveloppe | 255 caractères au plus, sans caractère de contrôle ni de format (`\p{Cc}`, `\p{Cf}` : C1, bidi, largeur nulle) ; vide : « début / fin » |

**Comportement.**

- Tout le fichier est contrôlé AVANT la première écriture (format, contrats, dates, doublons). Une seule erreur : code de sortie non nul, message
  « ligne N : … » pour chacune, rien d'écrit, même avec `--apply`. Doublon : deux lignes de même contrat, solde et dates dont les libellés sont égaux
  SELON LA BASE (comparaison par la collation de la colonne `name`, utf8mb4_unicode_ci : casse, accents, ß/ss… ; « Reliquat été » = « Reliquat ete »), y compris
  deux numéros qui désignent le même contrat ; erreur de plan, rien d'écrit.
- Écriture (`Ledger::importEnvelope`) : une transaction par ligne, sous le verrou de la ligne du contrat : enveloppe + opération de type `import`
  (date = début, montant = solde) + lot (origine = début, échéance = min(fin, début + 24 mois)) + affectation. Le cache du contrat (`credited`, `balance`) en
  tient compte comme d'un crédit. Une ligne en échec arrête l'application (code non nul) ; les lignes déjà écrites restent (journal en ajout seul) et
  un nouveau lancement les saute.
- Ligne semblable : une ligne NON importée mais de même contrat, même solde, même début et même fin qu'une enveloppe déjà importée, sous un autre libellé (libellé corrigé : l'empreinte change, la ligne recréditerait le solde) est une **erreur de plan** (`Ledger::similarImport`), sauf si l'enveloppe trouvée porte l'empreinte d'une AUTRE ligne du même fichier (import interrompu puis repris) ; `--accept-similar` la laisse passer (deux soldes distincts de mêmes montant et dates).
- Rejouable : une ligne déjà importée est sautée (`SKIP`). L'empreinte de la ligne (SHA-256 de : id du contrat, solde en centièmes, dates, libellé
  effectif sans casse) est stockée dans `details.import_key` de l'opération `import` (jamais modifiée) et cherchée par `Ledger::importedEnvelope` : le
  nom de l'enveloppe, modifiable, ne compte pas (renommer une enveloppe ne fait pas recréditer au rejeu ; vérifié au banc). Le contrôle est refait SOUS
  le verrou du contrat : deux lancements concurrents n'écrivent qu'une fois chaque ligne (vérifié au banc, quatre processus). Le report n'entre pas
  dans l'empreinte.
- La commande refuse de tourner si le plugin n'est pas actif (`Plugin::isPluginActive`) ; toute erreur inattendue d'une écriture est interceptée
  (`\Throwable`) pour afficher le décompte des lignes déjà écrites, avec un code non nul.
- Fin passée : acceptée (le type `import` n'est pas refusé sur une enveloppe terminée), signalée par un avertissement ; le lot expirera à la
  prochaine exécution de l'action automatique `pointsexpire`.
- Droits : aucune session utilisateur en console, donc aucun contrôle de droit ni d'entité (l'accès au shell du serveur vaut administrateur).
  Chaque écriture est tracée : `users_id` = 0 (enveloppe et opération) et commentaire de l'opération « Import BPM <nom du fichier> ligne <n> »
  (lisible avec les droits des champs internes).
- Rapport : les cellules libres (numéro, libellé) sont nettoyées avant affichage : caractères de contrôle retirés, longueur bornée, apostrophe devant
  `=`, `+`, `-`, `@` (aucune formule si le rapport est collé dans un tableur), balises de la console neutralisées. Un libellé qui contient un caractère de
  contrôle est de toute façon refusé. Aucun rapport CSV n'est produit.
- Fichier de 5 Mo au plus. Aucune donnée réelle dans le dépôt : exemple fictif ci-dessous et `tests/banc/import-exemple.csv`.

Exemple fictif (3 lignes, numéros inventés) :

```
contrat;solde;debut;fin;report;libelle
CTR-EXEMPLE-001;120,5;2026-01-01;2026-12-31;1;Solde initial fictif
CTR-EXEMPLE-002;7.25;2026-03-01;2027-02-28;0;Reliquat fictif
CTR-EXEMPLE-001;30;2026-06-01;2027-05-31;0;
```

Tests : `tests/unit/ImportRulesTest.php` (lecture et validations), banc `scenario_import.py` (simulation sans écriture, application, rejeu,
double lancement, lignes invalides, contrats inconnus, sans points, à la corbeille, ambigus, 24 mois, BOM, virgule, fin passée, rapport sans
injection, soldes dans l'onglet et la recherche).

## Compatibilité GLPI 11.0.x (coût estimé, non réalisé)

**Abandonnée** : décision du responsable du 2026-11-13, Points est exigé le jour de la bascule d'Assist, en GLPI 12 ; aucune branche 11.
L'estimation ci-dessous reste pour mémoire.

Assist est en 11.0.11. Un code unique 11 et 12 n'est pas recommandé (`references/migration-11-12.md` du skill
glpi-php) : signatures typées du cœur 12 (une surcharge typée casse en 11), CSRF (jeton `_glpi_csrf_token` exigé en
11, retiré en 12). Coût d'une branche 11 : jeton CSRF dans les formulaires (3 à 4 gabarits), types retirés des
surcharges de `CommonDBTM` (environ 40 signatures), requêtes déjà par tableaux de critères (aucun changement),
banc 11.0.11 à dupliquer : **environ 2 jours**, plus la recette. Conception 12 inchangée.
