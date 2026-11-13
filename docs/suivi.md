# Suivi technique du plugin Points

Fichier unique de suivi du dépôt (standard claude-config). Contexte : `cahier-des-charges.md`, `conception.md`.
Projet Assist : `docs/suivi.md` d'atheo-assist, points 1 et 50 ; fiche `docs/architecture/03-facturation-points.md`.

## En cours

- Plugin réécrit de zéro pour GLPI 12 (branche `claude/busy-gates-snaod5`, PR en brouillon, jamais fusionnée sans
  décision du responsable) : lots a à h livrés, chacun relu indépendamment et vert au banc GLPI 12.0.1
  (`scripts/check.sh --banc`).

## À faire

1. Présentation du cahier des charges à Céline Lefebvre (validation administrative) et Simon Delus (validation
   commerciale) ; réponses aux questions ci-dessous ; nom définitif.
2. Valeurs réelles des grilles Serenity et Elevate (familles, versions datées, coûts), à saisir par l'Admin.
3. Fusion de tickets : les forfaits du ticket source ne suivent pas le ticket principal (les tâches, elles, sont
   traitées : copie d'une tâche débitée verrouillée sans nouveau débit). À concevoir.
4. Installation sur Assist seulement après sa montée en GLPI 12 (atheo-assist, suivi point 43) ; sinon branche
   11.0.x (environ 2 jours, `conception.md`, « Compatibilité GLPI 11.0.x »). Droits à écrire dans
   `deploy/config/profils-cibles-valeurs.sql` d'atheo-assist (valeurs proposées dans sa fiche 03, non appliquées).
5. Relecture d'ensemble de la branche et `/ponytail-review` global avant de passer la PR en prête à relire (les
   relectures ont été faites lot par lot).

## Constats ouverts

| Constat | Nature | Depuis | Zone |
|---|---|---|---|
| Un lot en dépassement garde un reste négatif : un crédit ultérieur ne le rembourse pas (il crée un nouveau lot) ; le solde du contrat reste juste | conception à confirmer | 2026-11-13 | journal |
| Le commentaire d'une review ou d'une compensation reste lisible avec le seul droit de lecture de ces objets (dans la matrice proposée, ce droit va avec les droits internes) | limite | 2026-11-13 | cloisonnement |
| Gardes redondantes non détectables au banc (onglet Entité : accès à l'entité ; commande d'import : plugin actif ; `Import::apply` sur erreur inattendue) : couvertes par des tests de lecture du code seulement | test | 2026-11-13 | tests |
| Notifications et onglets testés au banc dans la langue par défaut (anglais) ; rendu français vérifié seulement par les fichiers de traduction | test | 2026-11-13 | traductions |
| L'onglet Points du ticket exige `plugin_points_ticket` READ : un profil avec seulement `plugin_points_flatrate` ne voit pas la section Forfait (conforme à la matrice proposée, fragile) | limite | 2026-11-13 | écrans |
| À la proposition d'un forfait, le droit natif de validation du valideur client n'est pas vérifié : sans ce droit, il ne peut pas répondre | limite | 2026-11-13 | forfaits |

## Questions ouvertes

Questions fermées 1 à 9 de `cahier-des-charges.md` (valeur par défaut déjà réglable pour chacune) : moment du débit,
qui demande une compensation, grille par heure ou par quart d'heure et valeurs réelles, destinataires de l'alerte,
format d'import du BPM Kiwi, nom, dépassement, contrôle croisé des forfaits, solde d'un contrat de groupe vu d'une
filiale.

## Bloquants

- Assist en GLPI 11.0.11 : le plugin borne `12.0.0`–`12.0.99` (À faire, point 4).
