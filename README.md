# Points : plugin GLPI 12

Gestion de points prépayés sur les contrats natifs de GLPI : enveloppes datées, grilles de prix, registre en ajout
seul, forfaits, contrôles et compensations. Cible : GLPI 12.0.x (12.0.0 à 12.0.99), PHP 8.3. Dépôt privé d'Athéo.

État : lots a à h réalisés (le plan est dans `tasks/`), lots g et h encore en relecture ; reste i (intégration Assist).

- a : socle (installation et désinstallation rejouables, 14 tables `glpi_plugin_points_*`, droits `plugin_points_*`, paramétrage, traductions).
- b : enveloppes datées, journal en ajout seul, lots FIFO, expiration et report (action `pointsexpire`).
- c : décompte des tâches (grilles versionnées, types de jour, moment du débit).
- d : forfaits (validation Responsable BU puis client).
- e : reviews et compensations.
- f : cloisonnement par entité et par profil, page « Points » de l'interface simplifiée.
- g : alerte de seuil (action `pointsalert`, notification `threshold`), liste « Contrats à points » (Outils > Points), options de recherche sur
  Contract et Entity, onglet « Points » de l'entité.
- h : import des soldes du BPM Kiwi par la console (voir « Importer les soldes »).
- Fusion de tickets sans double décompte ni point perdu (`docs/conception.md`, règle 6) ; contrôle des soldes par la console
  (voir « Contrôler le journal et les soldes »).

## Documentation

- `docs/cahier-des-charges.md` : le besoin, en mots simples.
- `docs/conception.md` : la conception technique (fait foi : identité, tables, droits, paramétrage).

## Tester

```bash
scripts/check.sh            # php -l, tests unitaires, .mo à jour ; le banc est SAUTÉ (pas vert)
scripts/check.sh --banc     # idem + banc GLPI 12 jetable sous Docker (images figées par digest)
php tests/unit/run.php      # tests unitaires seuls, sans GLPI
tests/banc/run.sh [scenario_alerte …]   # banc seul (scénarios : alerte, cloisonnement, controles, final, forfaits, fusion, import, journal, socle, soldes, taches, zdesinstallation) ; BANC_GARDER=1 garde les conteneurs, BANC_PORT=8097 par défaut
```

Le banc crée les conteneurs `points-banc-app` et `points-banc-db` (réseau `points-banc`), installe le plugin par la
console puis joue les scénarios `tests/banc/scenario_*.py`.

## Importer les soldes du BPM Kiwi

```bash
php bin/console plugins:points:import fichier.csv           # simulation : rapport ligne à ligne, rien n'est écrit
php bin/console plugins:points:import fichier.csv --apply   # écrit une enveloppe et une opération « import » par ligne
```

Une ligne de même contrat, solde et dates qu'une enveloppe déjà importée, sous un autre libellé, est une erreur de plan (le libellé a sans doute
été corrigé) : `--accept-similar` la laisse passer.

## Contrôler le journal et les soldes

Sur le serveur GLPI, depuis le dossier de GLPI (celui qui contient `bin/console`), avec l'utilisateur du serveur web :

```bash
php bin/console plugins:points:check      # lecture seule : invariants du journal de tous les contrats, code de sortie non nul au moindre écart
php bin/console plugins:points:balances   # lecture seule : solde enregistré de chaque contrat contre le recalcul depuis les mouvements
php bin/console plugins:points:balances --all             # liste aussi les contrats justes (OK) avec solde, crédité, consommé
php bin/console plugins:points:balances --contract=123    # un seul contrat (identifiant du contrat natif)
```

`plugins:points:balances` compare le solde, le crédité, le consommé, le % consommé et la prochaine échéance enregistrés sur la ligne Points
de chaque contrat à ce que donnent ses mouvements (journal des opérations, restes des lots). Une ligne `GAP contract N (nom)` par contrat
faux, suivie d'une ligne par écart (`balance: recorded …, recomputed …` ; `lots: journal …, sum of the lot remainders …`), puis le bilan
`N contract(s) checked, M with a gap` ; code de sortie 1 s'il y a un écart, 0 sinon. Rien n'est écrit. Usage : recette (avant et après
l'import des soldes du BPM Kiwi), puis après la bascule (à côté de `plugins:points:check`). Un écart ne se corrige pas par cette commande :
le signaler (le cache se recalcule à la prochaine écriture du contrat ; le journal, lui, ne se modifie jamais).

CSV UTF-8, séparateur `;`, en-tête `contrat;solde;debut;fin;report;libelle` (exemple fictif : `tests/banc/import-exemple.csv`). Tout le
fichier est contrôlé avant la première écriture ; une ligne invalide donne un code de sortie non nul et rien n'est écrit. Rejouable : une
ligne déjà importée est sautée. Format exact, contrôles et traçabilité : `docs/conception.md`, section « Import des soldes du BPM Kiwi ».

## Traductions

Chaînes sources en anglais, domaine `points`. Après toute modification d'un `.po` :
`python3 tools/compile_mo.py` (les `.mo` sont versionnés ; `scripts/check.sh` vérifie qu'ils sont à jour).

## Installer

Copier le dossier sous `plugins/points` de GLPI, puis `php bin/console plugin:install -u <admin> points` et
`php bin/console plugin:activate points`. L'installation est rejouable et sert de mise à jour. Les droits sont à 0
pour tous les profils, sauf le Super-Admin ; ils se règlent dans l'onglet « Points » de la fiche Profile.
