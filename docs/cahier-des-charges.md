# Points : cahier des charges

Version de travail du 2026-11-12, pour présentation à Céline Lefebvre (validation administrative) et Simon Delus
(validation commerciale). Nom affiché « Points » à revoir. Rien n'est installé : ce document décrit ce que le plugin
fera dans Assist (GLPI d'Athéo). La conception technique est dans `conception.md`.

## En une phrase

Le client achète des points ; chaque intervention d'Athéo en consomme selon une grille de prix ; le solde est
toujours lisible, exact et justifiable ligne par ligne.

## Ce qui est décidé

1. **Un seul système pour toutes les BU** (Serenity, Elevate, Télécoms…). Seule la grille de prix change d'une BU
   ou d'un client à l'autre. Elevate quitte son outil « SU » et passe sur Points (décision du 07/11/26).
2. **Les points appartiennent à un contrat**, pas au client en général. Les contrats viennent d'Odoo (le plugin
   n'en crée jamais). Le support à la demande est porté par un contrat de type « Serenity à points ».
3. **Les points arrivent par enveloppes datées** : une quantité, une date de début, une date de fin. On consomme
   toujours l'enveloppe la plus ancienne d'abord. Un point disparaît à la fin de son enveloppe et **n'est jamais
   gardé plus de 2 ans** (règle de la direction). Une enveloppe peut être marquée « reportable » : ce qui reste à sa
   fin passe dans l'enveloppe suivante du même contrat, sans jamais dépasser les 2 ans comptés depuis l'achat.
4. **Seul le temps des tâches compte** (pas les messages au client ni les solutions). **Tout quart d'heure commencé
   est dû** : 10 minutes comptent 15, 20 minutes comptent 30, 50 minutes comptent 60.
5. **Rien ne s'efface.** Chaque mouvement de points est une ligne d'un registre : on n'en modifie ni n'en supprime
   aucune. Une erreur se corrige par une nouvelle ligne, qui renvoie à la ligne corrigée et dit qui, quand et pourquoi.
   Le solde est la somme du registre.
6. **Forfaits** (ordres de travail) : le technicien chiffre, le Responsable BU valide **avant** que la proposition
   parte au client, puis le client l'approuve dans Assist (validation habituelle d'un ticket). Le montant approuvé est
   bloqué et débité tel quel, quel que soit le temps réellement passé.
7. **Chacun voit ce qui le concerne** : le responsable chez le client voit le solde de sa société, jamais celui d'un
   autre client ; le Responsable BU valide les forfaits de sa BU ; Facturation / ADV crédite et corrige ; les
   techniciens saisissent leurs tâches et lisent le solde ; l'administrateur règle les grilles.

## Comment ça marche

1. **ADV crée une enveloppe** sur le contrat du client : par exemple 40 points du 01/01 au 31/12.
2. **Un ticket arrive.** Il est rattaché au contrat du client (Assist le fait déjà automatiquement par le contrat par
   défaut du client). Le technicien indique le type d'intervention : assistance (au temps passé), ordre de travail
   (au forfait) ou intervention urgente.
3. **Le technicien saisit des tâches** avec leur durée. Une tâche est refusée s'il n'existe aucune enveloppe valide à
   sa date : on ne travaille pas « à crédit » sans le savoir.
4. **Le débit** : chaque tâche coûte « durée arrondie au quart d'heure × prix de la grille », le prix dépendant du
   type d'intervention et du jour (semaine, samedi, dimanche ou jour férié, selon le calendrier d'Assist). Par
   défaut, le débit se fait à la **clôture du ticket** (comme SU) : tant que le ticket est ouvert, le technicien peut
   corriger ses durées ; une fois débitée, une durée est bloquée.
5. **Le contrôle** : une tâche faite un week-end, un jour férié ou en dehors des horaires du calendrier est signalée
   à ADV, qui la **confirme** ou l'**ajuste** (une ligne de correction est ajoutée).
6. **La compensation** : si un client conteste, on enregistre une demande de compensation avec un motif
   obligatoire ; ADV l'accepte (jamais plus que ce qui a été débité) ou la refuse ; le demandeur est prévenu par mail.
7. **L'alerte** : quand 80 % des points valides d'un contrat sont consommés (seuil réglable par contrat), une
   notification part.
8. **Le suivi** : les listes habituelles d'Assist donnent les soldes par contrat, par client, par BU et pour tout le
   portefeuille, avec filtres et export.

## Exemple chiffré

Grille « Standard » (exemple d'Elevate, prix en points par heure, réglage par défaut) :

| Type d'intervention | Semaine | Samedi | Dimanche et jours fériés |
|---|---|---|---|
| Assistance | 1 | 1,5 | 2 |
| Ordre de travail | 2 | 3 | 4 |
| Intervention urgente | 3 | 4,5 | 6 |

Le client a une enveloppe de **40 points**. Ticket d'assistance au temps passé :

| Tâche | Jour | Durée saisie | Durée comptée | Prix | Points |
|---|---|---|---|---|---|
| 1 | mardi | 10 min | 15 min | 1 par heure | 0,25 |
| 2 | mardi | 20 min | 30 min | 1 par heure | 0,50 |
| 3 | mardi | 50 min | 60 min | 1 par heure | 1,00 |
| 4 | dimanche | 30 min | 30 min | 2 par heure | 1,00 (signalée à ADV : dimanche) |

Puis un ordre de travail au forfait de **6 points**, validé par le Responsable BU puis approuvé par le client : 6 points.

Total consommé : 0,25 + 0,50 + 1,00 + 1,00 + 6 = **8,75 points** ; **solde : 31,25 points** (21,9 % consommés, sous
le seuil d'alerte de 80 %).

Si la grille était exprimée en points **par quart d'heure** (question 3 ci-dessous), les mêmes tâches coûteraient
1 + 2 + 4 + (2 × 2) = 11 points, soit 17 points avec le forfait et un solde de 23 points.

## Qui fait quoi (profils d'Assist)

| Profil | Ce qu'il peut faire dans Points |
|---|---|
| Responsable client | voir le solde, les enveloppes et le registre de **sa** société, rien d'autre |
| Technicien N1, N2, N3 | choisir le type d'intervention d'un ticket, saisir ses tâches, chiffrer un forfait, voir le solde |
| Responsable BU | valider ou refuser les forfaits des clients de sa BU, voir les soldes |
| Facturation / ADV | créer les enveloppes, créditer, corriger, reporter, contrôler les tâches signalées, décider les compensations, importer les soldes |
| Admin | régler les grilles de prix et le paramétrage |

Les droits exacts (un droit par fonction, propres au plugin) sont listés dans `conception.md` pour être écrits dans
les profils d'Assist.

## Questions à trancher (réponse attendue : un choix)

Chaque question a une valeur par défaut déjà réglable dans le plugin ; rien n'est figé.

1. **Quand les points sont-ils débités ?** (a) à la clôture du ticket, comme SU — *réglage par défaut* ; (b) à chaque
   tâche saisie. Avec (a), le technicien peut corriger ses durées jusqu'à la clôture ; avec (b), le solde bouge tout
   de suite mais chaque erreur de durée demande une correction par ADV.
2. **Qui peut demander une compensation ?** (a) Facturation / ADV seulement — *défaut* ; (b) aussi le ROC ; (c) aussi
   le client lui-même. Réglé par profil, sans développement.
3. **Les prix de la grille sont-ils par heure ou par quart d'heure ?** (a) par heure — *défaut* ; (b) par quart
   d'heure. Et quelles sont les valeurs réelles des grilles Serenity et Elevate ? (l'exemple ci-dessus est celui de SU).
4. **Qui reçoit l'alerte de seuil ?** (a) l'administrateur de l'entité du client — *défaut* ; (b) le ROC ; (c) ADV ;
   (d) le responsable chez le client. Plusieurs choix possibles, réglés dans les notifications d'Assist.
5. **Reprise des soldes du BPM Kiwi** : accepte-t-on un fichier par contrat avec numéro de contrat, solde restant,
   date de début et de fin de validité et indicateur de report (format proposé dans `conception.md`) ?
6. **Nom définitif du plugin** : « Points » ou autre ?
7. **Dépassement** (question nouvelle) : si l'enveloppe est vide quand un ticket est débité, (a) le solde passe en
   négatif et le dépassement est signalé — *défaut* ; (b) le débit est bloqué jusqu'à un nouveau crédit.
