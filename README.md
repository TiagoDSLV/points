# Points — fork du plugin GLPI Credit

> Fork de [pluginsGLPI/credit](https://github.com/pluginsGLPI/credit).  
> Affiché sous le nom **"Points"** dans GLPI — identité technique interne reste `credit`.

---

## Ce que ce fork ajoute

Une classe dropdown **Barème** (`PluginCreditBareme`) qui calcule automatiquement
les points consommés quand un technicien ajoute une Tâche à un ticket.

Règle : `ceil(durée_min / 15) × points_par_tranche` — toute tranche de 15 min
entamée compte pleine.

Les barèmes (noms et valeurs) se créent manuellement après installation via
**Configuration > Intitulés > Barèmes**.

## Fichiers ajoutés vs upstream

| Fichier | Rôle |
|---|---|
| `inc/bareme.class.php` | Dropdown + calcul `calculatePoints()` |
| `ajax/calculatePoints.php` | Endpoint AJAX pour aperçu live |
| `inc/ticket.class.php` | `consumeVoucher()` surchargée |
| `templates/tickets/consume.html.twig` | Dropdown barème + preview temps réel |

Aucun renommage technique : dossier, classes, tables SQL et constantes restent `credit`.

## Synchronisation avec upstream

```bash
git fetch upstream
git merge upstream/main
```

Les ajouts sont dans des fichiers nouveaux ou des méthodes isolées — pas de conflit
attendu avec les fichiers upstream non modifiés.

## Compatibilité

GLPI 11.0.0 – 11.0.99

## Statut

POC — non déployé en production.

## Licence

GPL-3.0 — voir [LICENSE.md](LICENSE.md).  
Code original : [pluginsGLPI/credit](https://github.com/pluginsGLPI/credit) © TECLIB'.  
Ce fork redistribue et modifie ce code sous les mêmes termes GPL-3.0.
