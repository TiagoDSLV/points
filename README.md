# Points : plugin GLPI 12

Gestion de points prépayés sur les contrats natifs de GLPI : enveloppes datées, grilles de prix, registre en ajout
seul, forfaits, contrôles et compensations. Cible : GLPI 12.0.x (12.0.0 à 12.0.99), PHP 8.3. Dépôt privé d'Athéo.

État : lot (a) « socle » : installation et désinstallation rejouables, 14 tables `glpi_plugin_points_*`, droits
`plugin_points_*` (onglet « Points » sur la fiche Profile), paramétrage (Configuration > Points), traductions
(`fr_FR`, `en_GB`), tests. Les lots suivants (enveloppes, décompte, forfaits…) sont décrits dans le plan de `tasks/`.

## Documentation

- `docs/cahier-des-charges.md` : le besoin, en mots simples.
- `docs/conception.md` : la conception technique (fait foi : identité, tables, droits, paramétrage).

## Tester

```bash
scripts/check.sh            # php -l, tests unitaires, .mo à jour ; le banc est SAUTÉ (pas vert)
scripts/check.sh --banc     # idem + banc GLPI 12 jetable sous Docker (images figées par digest)
php tests/unit/run.php      # tests unitaires seuls, sans GLPI
tests/banc/run.sh [scenario_socle]   # banc seul ; BANC_GARDER=1 garde les conteneurs, BANC_PORT=8097 par défaut
```

Le banc crée les conteneurs `points-banc-app` et `points-banc-db` (réseau `points-banc`), installe le plugin par la
console puis joue les scénarios `tests/banc/scenario_*.py`.

## Traductions

Chaînes sources en anglais, domaine `points`. Après toute modification d'un `.po` :
`python3 tools/compile_mo.py` (les `.mo` sont versionnés ; `scripts/check.sh` vérifie qu'ils sont à jour).

## Installer

Copier le dossier sous `plugins/points` de GLPI, puis `php bin/console plugin:install -u <admin> points` et
`php bin/console plugin:activate points`. L'installation est rejouable et sert de mise à jour. Les droits sont à 0
pour tous les profils, sauf le Super-Admin ; ils se règlent dans l'onglet « Points » de la fiche Profile.
