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
| Contrat d'un ticket | lien `Ticket_Contract` (`glpi_tickets_contracts`) ; contrat par défaut de l'entité posé à la création du ticket (`_contracts_id`, `Entity::getDefaultContract`) | lu 12.0.1 `src/Ticket_Contract.php:38`, `src/Ticket.php:1533-1540` |
| BU | type de contrat (`contracttypes_id`), décision d'Assist du 07/11/26 (fiche 08) | atheo-assist |
| Jours fériés, créneaux | calendrier natif : `Calendar::isHoliday`, `isAWorkingHour` ; calendrier = celui du paramétrage, sinon celui de l'entité | lu 12.0.1 `src/Calendar.php:249,409` |
| Validation client d'un forfait | validation de ticket native (`TicketValidation`, statuts WAITING 2, ACCEPTED 3, REFUSED 4) | lu 12.0.1 `src/CommonITILValidation.php:64-67` |
| Alerte, notification au demandeur | notifications natives (`NotificationTarget`, `NotificationEvent::raiseEvent`) ; destinataires réglés dans l'interface | lu 12.0.1 `src/NotificationEvent.php:146` |
| Expiration, report, seuil | actions automatiques (`CronTask::register`) | lu 12.0.1 `src/CronTask.php:1075` |
| Reporting | moteur de recherche natif (`rawSearchOptions`, filtres, export) | doc développeur, « Search options » |
| Droits | `ProfileRight`, matrice de droits sur la fiche profil | lu 12.0.1 `src/ProfileRight.php:155,191` |
| Import | commande console du plugin (`src/Console/*Command.php`, chargée par `CommandLoader`) | lu 12.0.1 `src/Glpi/Console/CommandLoader.php:212-247` |

## Modèle de données

Toutes les tables portent `entities_id` (copie de l'entité du contrat, `is_recursive` = 0 ; grilles, versions, coûts et types d'intervention : globaux,
`is_recursive` = 1) pour le cloisonnement natif. Montants : `DECIMAL(12,2)`. Dates métier : `DATE` ou `TIMESTAMP` selon le champ.

| Table | Rôle | Champs principaux |
|---|---|---|
| `glpi_plugin_points_grids` | famille de grilles (Standard, Client VIP, Hospitalier, Astreinte HNO) | `name`, `comment`, `is_active`, `unit_minutes` (60 par défaut, 15 possible) |
| `glpi_plugin_points_gridversions` | version datée d'une famille | `plugin_points_grids_id`, `begin_date` (applicable à partir de), `comment` ; version applicable = plus grande `begin_date` ≤ date de l'opération |
| `glpi_plugin_points_gridcosts` | coût d'une version | `plugin_points_gridversions_id`, `plugin_points_interventiontypes_id`, `daytype` (1 semaine, 2 samedi, 3 dimanche et jour férié), `cost` ; unique (version, type, jour) |
| `glpi_plugin_points_interventiontypes` | liste (Assistance, Ordre de travail, Intervention urgente) | intitulé ; liste déroulante native |
| `glpi_plugin_points_contracts` | réglage Points d'un contrat natif (un pour un) | `contracts_id` unique, `plugin_points_grids_id`, `alert_threshold` (%, défaut du paramétrage : 80), `is_active`, cache : `balance`, `credited`, `consumed`, `date_cache` |
| `glpi_plugin_points_envelopes` | enveloppe datée | `contracts_id`, `name`, `credit` (initial), `begin_date`, `end_date` (≤ début + 24 mois), `is_carryover`, `users_id`, `date_creation` ; dates non modifiables |
| `glpi_plugin_points_operations` | **journal en ajout seul** | `contracts_id`, `type`, `amount` (signé : + crédit, − débit), `date` (`DATE`, date métier : date de la tâche, de l'approbation… ; l'heure d'une tâche reste dans `details`), `tickets_id`, `itemtype` / `items_id` (TicketTask, forfait, compensation, review, enveloppe), `operations_id_origin` (opération corrigée ou liée), `comment` (motif), `details` (JSON : minutes saisies et comptées, type de jour, version de grille, coût unitaire, type d'intervention), `users_id`, `date_creation` |
| `glpi_plugin_points_lots` | lot de points créé par un crédit (FIFO, expiration) | `operations_id` (crédit, report entrant, import), `plugin_points_envelopes_id`, `contracts_id`, `origin_date` (date d'achat d'origine, conservée au report), `expiry_date` = min(fin de l'enveloppe, `origin_date` + 24 mois), `initial` |
| `glpi_plugin_points_allocations` | répartition d'une opération sur les lots | `operations_id`, `plugin_points_lots_id`, `amount` (signé) ; reste d'un lot = somme de ses affectations |
| `glpi_plugin_points_tickets` | réglage Points d'un ticket | `tickets_id` unique, `contracts_id` (contrat Points retenu), `plugin_points_interventiontypes_id`, `is_billable` |
| `glpi_plugin_points_tasks` | état de décompte d'une tâche | `tickettasks_id` unique, `tickets_id`, `operations_id` (débit, 0 tant que non débitée), `is_locked` |
| `glpi_plugin_points_flatrates` | forfait | `tickets_id`, `contracts_id`, `amount` (chiffré), `description`, `status`, `users_id` (technicien), `users_id_bu`, `date_bu`, `bu_comment`, `users_id_validate` (validateur client), `ticketvalidations_id`, `approved_amount` (figé), `operations_id` |
| `glpi_plugin_points_reviews` | contrôle d'un débit hors créneau ou férié | `operations_id` (débit), `reason` (samedi, dimanche, férié, hors créneau), `status` (à contrôler, confirmé, ajusté), `users_id`, `date_mod`, `comment`, `operations_id_adjustment` |
| `glpi_plugin_points_compensations` | demande de compensation | `operations_id` (débit visé), `tickets_id`, `amount` (demandé), `comment` (motif obligatoire), `status` (en attente, approuvée, rejetée), `users_id` (demandeur), `users_id_decision`, `date_decision`, `decision_comment`, `approved_amount`, `operations_id_credit` |

Types d'opération : `credit`, `import`, `debit_task`, `debit_flatrate`, `expiry`, `expiry_reversal`,
`carryover_out`, `carryover_in`, `correction`, `review_adjustment`, `compensation`. Une correction positive, un
remboursement ou une compensation ne peuvent viser qu'un débit (`debit_task`, `debit_flatrate`, correction ou
ajustement négatifs) ; une expiration ne se corrige que par le débit tardif ci-dessus, un report par un report.

Cache du contrat : `credited` = crédits, imports et reports reçus moins reports émis et expirations (points achetés
encore utilisables ou consommés) ; `consumed` = débits moins remboursements, corrections positives et compensations ;
`balance` = somme du journal. L'alerte de seuil (règle 12) ne lit pas ce cache : elle calcule sur les lots valides.

Valeurs codées (`varchar(30)`) : forfait `draft`, `submitted`, `bu_refused`, `proposed`, `approved`,
`client_refused`, `debited`, `cancelled` ; review `to_review`, `confirmed`, `adjusted` ; raison de review `saturday`,
`sunday`, `holiday`, `off_hours` (plusieurs séparées par une virgule) ; compensation `pending`, `approved`, `rejected`.

Invariants (contrôlés par les tests et par `points:check` en console) : somme des `amount` d'une opération = somme de
ses affectations ; reste d'un lot ≥ 0, sauf dépassement autorisé sur le lot de dépassement ; solde du contrat =
somme du journal = somme des restes des lots ; aucune ligne de `operations`, `lots`, `allocations` n'est modifiée
ni supprimée (pas de `date_mod`).

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
   une tâche débitée est enregistrée verrouillée, sans nouveau débit. Une clôture impossible laisse le ticket Résolu
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
   valideur choisi (défaut : demandeur), qui doit avoir le droit natif de validation ; la validation liée ne peut pas
   être supprimée tant que le forfait est proposé, approuvé ou débité ; date métier du débit = date de la réponse du
   client ; historique des états dans l'historique natif (`glpi_logs`) ; le Responsable BU ne valide pas un forfait
   qu'il a lui-même chiffré (question 8 du cahier des charges, réglage par défaut) ; à la fusion de tickets, les
   forfaits du ticket source ne suivent pas (à traiter, plan).
10. **Review** : créée pour tout débit de tâche hors créneau (calendrier) ou de type de jour 2 ou 3 ; confirmée, ou
    ajustée par une opération `review_adjustment` liée.
11. **Compensation** : motif obligatoire ; montant approuvé ≤ débit visé − compensations déjà approuvées ; crédit lié
    (`compensation`, réaffecté aux lots du débit d'origine) ; demandeur notifié.
12. **Alerte** (action automatique `pointsalert`) : consommé / crédité des lots valides ≥ seuil du contrat →
    notification `threshold`, une fois par passage du seuil (alerte native `glpi_alerts`, remise à zéro par un crédit).

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
| Contrat, onglet « Points » | réglage (grille, seuil), solde, enveloppes, registre ; actions ADV : créer une enveloppe, créditer, corriger, reporter | balance READ ; envelope pour écrire |
| Ticket, onglet « Points » | contrat Points, type d'intervention, tâches et débits, forfait (chiffrer, soumettre, valider BU, proposer), compensation | ticket, flatrate, compensation |
| Entité, onglet « Points » | soldes des contrats de l'entité | balance READ |
| Interface simplifiée (Responsable client) | entrée « Points » du menu (`Hooks::HELPDESK_MENU_ENTRY`) : pour chaque contrat à points de ses entités, solde, enveloppes (échéances) et registre ; pas d'onglet sur la fiche contrat, inaccessible en interface simplifiée (fiche contrat servie par un contrôleur moderne, stratégie par défaut `central_access` qui refuse un profil `helpdesk` : lu 12.0.1 `src/Glpi/Http/Firewall.php:152,170-190`, `src/Session.php:1219-1224`, à confirmer au banc, lot f) | balance READ |
| Outils > Points | liste des contrats à points (recherche native : client, BU, solde, % consommé, prochaine échéance), registre, reviews à contrôler, compensations en attente | selon droit |
| Configuration > Points | paramétrage, grilles, types d'intervention | config, grid |

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

## Import des soldes du BPM Kiwi (format proposé)

CSV UTF-8, séparateur `;`, une ligne par enveloppe :
`contrat;solde;debut;fin;report;libelle` (numéro de contrat Odoo tel que dans le champ « Numéro » du contrat GLPI,
solde restant en points avec virgule ou point décimal, dates `AAAA-MM-JJ`, report 0/1, libellé libre).
`php bin/console points:import fichier.csv` simule par défaut (rapport ligne à ligne, aucune écriture) ;
`--apply` écrit (enveloppe + opération `import`), rejouable : une ligne déjà importée (même contrat, dates, solde,
libellé) est sautée. Toute ligne invalide arrête l'application avant écriture.

## Compatibilité GLPI 11.0.x (coût estimé, non réalisé)

Assist est en 11.0.11. Un code unique 11 et 12 n'est pas recommandé (`references/migration-11-12.md` du skill
glpi-php) : signatures typées du cœur 12 (une surcharge typée casse en 11), CSRF (jeton `_glpi_csrf_token` exigé en
11, retiré en 12). Coût d'une branche 11 : jeton CSRF dans les formulaires (3 à 4 gabarits), types retirés des
surcharges de `CommonDBTM` (environ 40 signatures), requêtes déjà par tableaux de critères (aucun changement),
banc 11.0.11 à dupliquer : **environ 2 jours**, plus la recette. Conception 12 inchangée.
