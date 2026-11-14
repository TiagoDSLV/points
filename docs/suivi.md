# Suivi technique du plugin Points

Fichier unique de suivi du dépôt (standard claude-config). Contexte : `cahier-des-charges.md`, `conception.md`.
Projet Assist : `docs/suivi.md` d'atheo-assist, points 1 et 50 ; fiche `docs/architecture/03-facturation-points.md`.

## En cours

- Plugin réécrit de zéro pour GLPI 12 (branche `claude/busy-gates-snaod5`, PR en brouillon, jamais fusionnée sans
  décision du responsable) : lots a à h livrés, chacun relu indépendamment, puis lot final (corrections de la relecture et de la revue ponytail globales), vert au banc
  GLPI 12.0.1 (`scripts/check.sh --banc`). Fusionnée dans main (PR #1).
- Branche `claude/fusion-soldes` (PR en brouillon, 2026-11-14) : fusion de tickets (écarts prouvés au banc puis corrigés par crochet),
  deux constats corrigés (approbateur client sans droit natif de validation, méta-critère à la désinstallation), commande
  `plugins:points:balances` (contrôle des soldes, lecture seule).

## À faire

1. Présentation du cahier des charges à Céline Lefebvre (validation administrative) et Simon Delus (validation
   commerciale) ; réponses aux questions ci-dessous ; nom définitif.
2. Valeurs réelles des grilles Serenity et Elevate (familles, versions datées, coûts), à saisir par l'Admin.
3. Fusion de tickets : traitée (branche `claude/fusion-soldes`) : une fusion qui laisserait des points derrière le ticket source (forfait
   ni refusé ni annulé, tâche facturable non débitée non copiée, ou copiée sur un principal qui ne la débiterait pas sur le même contrat,
   copie déjà faite par une fusion précédente, tâches d'un source non facturable copiées sur un principal facturable) est refusée ; le
   principal est reconnu par le suivi de fusion natif ou le lien « fils de » ; fusion en chaîne verrouillée (`conception.md`, règle 6). Reste la décision du responsable (Questions ouvertes, 1 à 4).
4. Installation sur Assist en GLPI 12, le jour de la bascule (décision du responsable du 2026-11-13 : Points exigé à la bascule
   d'Assist, production en GLPI 12, aucune branche GLPI 11). Droits à écrire dans
   `deploy/config/profils-cibles-valeurs.sql` d'atheo-assist (valeurs proposées dans sa fiche 03, non appliquées).
5. Relecture d'ensemble et `/ponytail-review` global : faits le 2026-11-13 ; le lot final de corrections a été
   relu par deux relecteurs (fond et refactor), dont les constats sont corrigés et vérifiés au banc par des tests qui
   échouent sans la correction (cette dernière série n'a pas été relue une nouvelle fois). Reste au responsable : passer la PR en prête à relire et décider de
   la fusion.
6. Brancher `plugins:points:check` et `plugins:points:balances` (lecture seule, code de sortie non nul au moindre écart) sur la supervision
   d'Assist, à côté de l'action automatique d'expiration ; les lancer à la recette (avant et après l'import du BPM Kiwi) : sur le serveur GLPI,
   dans le dossier de GLPI, avec l'utilisateur du serveur web (`README.md`, « Contrôler le journal et les soldes »).
7. Banc : un passage complet après chaque changement (`scripts/check.sh --banc`) ; les scénarios d'une passe partielle dépendent de l'ordre
   (`scenario_taches.py` compte les grilles et attend la réinstallation de `scenario_socle.py`).

## Constats ouverts

| Constat | Nature | Depuis | Zone |
|---|---|---|---|
| Un lot en dépassement garde un reste négatif : un crédit ultérieur ne le rembourse pas (il crée un nouveau lot) ; le solde du contrat reste juste | conception à confirmer | 2026-11-13 | journal |
| Le commentaire d'une review ou d'une compensation reste lisible avec le seul droit de lecture de ces objets (dans la matrice proposée, ce droit va avec les droits internes) | limite | 2026-11-13 | cloisonnement |
| Gardes redondantes non détectables au banc (onglet Entité : accès à l'entité ; commande d'import : plugin actif ; `Import::apply` sur erreur inattendue) : couvertes par des tests de lecture du code seulement | test | 2026-11-13 | tests |
| Notifications et onglets testés au banc dans la langue par défaut (anglais) ; rendu français vérifié seulement par les fichiers de traduction | test | 2026-11-13 | traductions |
| L'onglet Points du ticket exige `plugin_points_ticket` READ : un profil avec seulement `plugin_points_flatrate` ne voit pas la section Forfait (conforme à la matrice proposée, fragile) | limite | 2026-11-13 | écrans |
| Fusion de tickets : une tâche non débitée est jugée « copiée » quand le principal d'une fusion de ce ticket (suivi de fusion natif ou lien « fils de ») porte une tâche de `sourceitems_id` ce ticket et de mêmes durée, dates, contenu et technicien ; une tâche strictement identique ajoutée à la main au ticket source restauré serait exclue du débit | limite | 2026-11-14 | tâches |
| Fusion refusée plus largement que le strict risque : un forfait déjà débité bloque aussi une fusion SANS les tâches ; une tâche de coût nul (ou sans grille) compte comme « à débiter » | limite | 2026-11-14 | fusion |
| Fusion de plusieurs tickets à la fois (action de masse) : un refus annule toute la fusion, mais le cœur annonce réussis les tickets traités avant le refus ; non testé au banc | limite | 2026-11-14 | fusion |
| `plugins:points:balances` : environ cinq lectures par contrat, rapport gardé en mémoire ; à surveiller si le nombre de contrats à points d'Assist dépasse quelques milliers | charge | 2026-11-14 | console |
| Mise à la corbeille HORS fusion d'un ticket qui a des tâches non débitées ou un forfait approuvé non débité : rien n'est débité tant qu'il n'est pas restauré et clos (comportement inchangé ; Questions ouvertes, 3) | limite | 2026-11-14 | fusion |
| Tâche ajoutée à un ticket source en corbeille : GLPI la recopie sur le principal SEULEMENT avec le lien « fils de » (`CommonITILTask::addToMergedTickets`, `Ticket::getMergedTickets`) ; avec un autre lien ou sans lien, ou si le principal refuse la copie (aucun point valide à sa date), elle reste sur le source, non débitée tant qu'il n'est pas restauré et clos | limite | 2026-11-14 | fusion |
| La date du jour (`Billing::today()`, fuseau de référence) est vérifiée en unitaire (`Dates::todayIn`) et par lecture du code, pas au banc : le changement de jour entre deux fuseaux ne se rejoue pas | test | 2026-11-13 | tests |
| Un dépassement écrit avant le lot final (détails `overdraft` sans `overdraft_lot`) serait signalé par `plugins:points:check` ; aucun contrat réel n'est concerné (le plugin n'est pas installé sur Assist), pas de reprise écrite | limite | 2026-11-13 | journal |
| Un type d'intervention qui a été le défaut d'un ticket (ligne Points figée à la création) n'est plus supprimable | limite | 2026-11-13 | tickets |
| `tests/unit/FinalTest.php` : en partie des tests de structure (lecture du code), le comportement est au banc (`scenario_final.py`, `scenario_zdesinstallation.py`) | test | 2026-11-13 | tests |
| Une ligne Points est écrite pour tout ticket à sa création (type d'intervention figé) : un enregistrement par ticket, à surveiller si le volume de tickets d'Assist le justifie | charge | 2026-11-13 | tickets |
| Un remboursement dont le lot est échu crée un lot de plus par opération (`compensation_on_expired` = `valid_lot`) ; ces lots se reportent ou expirent comme les autres | limite | 2026-11-13 | journal |

## Questions ouvertes

Posées le 2026-11-14 (branche `claude/fusion-soldes`), chacune avec le comportement par défaut déjà en place :

1. Fusion d'un ticket au forfait (ni refusé ni annulé) dans un autre : le forfait doit-il suivre le ticket principal ? Défaut : la fusion
   est refusée (annuler le forfait s'il n'est pas approuvé). Faire suivre le forfait demande de
   déplacer aussi sa validation native et de trancher le cas d'un principal qui a déjà un forfait ou des tâches débitées.
2. Fusion d'un ticket à tâches non débitées dans un principal qui ne les débiterait pas sur le même contrat (au forfait, non facturable,
   autre contrat, clos) : faut-il les laisser couvrir par le principal (forfait, cas d'un même incident) ? Défaut : la fusion est refusée
   (clore le source d'abord, ce qui débite ses tâches, puis fusionner).
3. Ticket mis à la corbeille hors fusion avec des points à décompter (tâches non débitées, forfait approuvé non débité) : faut-il refuser la
   mise à la corbeille, ou débiter ? Défaut : comportement inchangé (rien n'est débité tant qu'il n'est pas restauré et clos).
4. Fusion, avec ses tâches, d'un ticket non facturable (ou sans contrat Points) dans un principal facturable : ses tâches doivent-elles être
   débitées sur le contrat du principal (le travail devient celui du principal) ? Défaut : la fusion est refusée (fusionner sans les
   tâches) ; débiter serait possible pour un même client, jamais pour un autre.

Questions fermées 1 à 9 de `cahier-des-charges.md` (valeur par défaut déjà réglable pour chacune) : moment du débit,
qui demande une compensation, grille par heure ou par quart d'heure et valeurs réelles, destinataires de l'alerte,
format d'import du BPM Kiwi, nom, dépassement, contrôle croisé des forfaits, solde d'un contrat de groupe vu d'une
filiale.

## Bloquants

Aucun. (Assist en GLPI 11.0.11 aujourd'hui, le plugin borne `12.0.0`–`12.0.99` : résolu par la décision du 2026-11-13, installation en
GLPI 12 le jour de la bascule, À faire, point 4.)
