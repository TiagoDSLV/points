# Suivi technique du plugin Points

Fichier unique de suivi du dépôt (standard claude-config). Contexte : `cahier-des-charges.md`, `conception.md`.
Projet Assist : `docs/suivi.md` d'atheo-assist, points 1 et 50 ; fiche `docs/architecture/03-facturation-points.md`.

## En cours

- Plugin réécrit de zéro pour GLPI 12 (branche `claude/busy-gates-snaod5`, PR en brouillon, jamais fusionnée sans
  décision du responsable) : lots a à h livrés, chacun relu indépendamment, puis lot final (corrections de la relecture et de la revue ponytail globales), vert au banc
  GLPI 12.0.1 (`scripts/check.sh --banc`).

## À faire

1. Présentation du cahier des charges à Céline Lefebvre (validation administrative) et Simon Delus (validation
   commerciale) ; réponses aux questions ci-dessous ; nom définitif.
2. Valeurs réelles des grilles Serenity et Elevate (familles, versions datées, coûts), à saisir par l'Admin.
3. Fusion de tickets : les forfaits du ticket source ne suivent pas le ticket principal (les tâches, elles, sont
   traitées : copie d'une tâche débitée verrouillée sans nouveau débit). À concevoir.
4. Installation sur Assist seulement après sa montée en GLPI 12 (atheo-assist, suivi point 43) ; sinon branche
   11.0.x (environ 2 jours, `conception.md`, « Compatibilité GLPI 11.0.x »). Droits à écrire dans
   `deploy/config/profils-cibles-valeurs.sql` d'atheo-assist (valeurs proposées dans sa fiche 03, non appliquées).
5. Relecture d'ensemble et `/ponytail-review` global : faits le 2026-11-13 ; le lot final de corrections a été
   relu par deux relecteurs (fond et refactor), dont les constats sont corrigés et vérifiés au banc par des tests qui
   échouent sans la correction (cette dernière série n'a pas été relue une nouvelle fois). Reste au responsable : passer la PR en prête à relire et décider de
   la fusion.
6. Brancher `plugins:points:check` (lecture seule, code de sortie non nul au moindre écart) sur la supervision d'Assist, à côté de l'action
   automatique d'expiration.

## Constats ouverts

| Constat | Nature | Depuis | Zone |
|---|---|---|---|
| Un lot en dépassement garde un reste négatif : un crédit ultérieur ne le rembourse pas (il crée un nouveau lot) ; le solde du contrat reste juste | conception à confirmer | 2026-11-13 | journal |
| Le commentaire d'une review ou d'une compensation reste lisible avec le seul droit de lecture de ces objets (dans la matrice proposée, ce droit va avec les droits internes) | limite | 2026-11-13 | cloisonnement |
| Gardes redondantes non détectables au banc (onglet Entité : accès à l'entité ; commande d'import : plugin actif ; `Import::apply` sur erreur inattendue) : couvertes par des tests de lecture du code seulement | test | 2026-11-13 | tests |
| Notifications et onglets testés au banc dans la langue par défaut (anglais) ; rendu français vérifié seulement par les fichiers de traduction | test | 2026-11-13 | traductions |
| L'onglet Points du ticket exige `plugin_points_ticket` READ : un profil avec seulement `plugin_points_flatrate` ne voit pas la section Forfait (conforme à la matrice proposée, fragile) | limite | 2026-11-13 | écrans |
| À la proposition d'un forfait, le droit natif de validation du valideur client n'est pas vérifié : sans ce droit, il ne peut pas répondre | limite | 2026-11-13 | forfaits |
| Fusion de tickets : une tâche non débitée est jugée « copiée » quand une tâche d'un autre ticket a pour `sourceitems_id` ce ticket et mêmes durée, dates, contenu et technicien ; une tâche strictement identique ajoutée à la main au ticket source restauré serait exclue du débit | limite | 2026-11-13 | tâches |
| La date du jour (`Billing::today()`, fuseau de référence) est vérifiée en unitaire (`Dates::todayIn`) et par lecture du code, pas au banc : le changement de jour entre deux fuseaux ne se rejoue pas | test | 2026-11-13 | tests |
| Un dépassement écrit avant le lot final (détails `overdraft` sans `overdraft_lot`) serait signalé par `plugins:points:check` ; aucun contrat réel n'est concerné (le plugin n'est pas installé sur Assist), pas de reprise écrite | limite | 2026-11-13 | journal |
| Désinstallation : une recherche enregistrée dont seul un méta-critère (critère sur un type lié) porte une option du plugin n'est pas reconnue et reste ; les recherches et colonnes du cœur ne sont pas touchées | limite | 2026-11-13 | désinstallation |
| Un type d'intervention qui a été le défaut d'un ticket (ligne Points figée à la création) n'est plus supprimable | limite | 2026-11-13 | tickets |
| `tests/unit/FinalTest.php` : en partie des tests de structure (lecture du code), le comportement est au banc (`scenario_final.py`, `scenario_zdesinstallation.py`) | test | 2026-11-13 | tests |
| Une ligne Points est écrite pour tout ticket à sa création (type d'intervention figé) : un enregistrement par ticket, à surveiller si le volume de tickets d'Assist le justifie | charge | 2026-11-13 | tickets |
| Un remboursement dont le lot est échu crée un lot de plus par opération (`compensation_on_expired` = `valid_lot`) ; ces lots se reportent ou expirent comme les autres | limite | 2026-11-13 | journal |

## Questions ouvertes

Questions fermées 1 à 9 de `cahier-des-charges.md` (valeur par défaut déjà réglable pour chacune) : moment du débit,
qui demande une compensation, grille par heure ou par quart d'heure et valeurs réelles, destinataires de l'alerte,
format d'import du BPM Kiwi, nom, dépassement, contrôle croisé des forfaits, solde d'un contrat de groupe vu d'une
filiale.

## Bloquants

- Assist en GLPI 11.0.11 : le plugin borne `12.0.0`–`12.0.99` (À faire, point 4).
