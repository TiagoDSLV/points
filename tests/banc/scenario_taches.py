"""Décompte des tâches de ticket (lot c) : grilles et leurs écrans, réglage et onglet Points du ticket, jours fériés du
calendrier natif, exemple chiffré du cahier des charges, refus (sans enveloppe, sans grille, à la clôture), verrouillage
après débit, débit « à la tâche », débit tardif après expiration (lot b). Tâches créées par l'API v1, avec un technicien.
Part d'un plugin installé et activé (run.sh) ; remet la configuration et les droits comme il les a trouvés."""
import datetime
import json
import re
import subprocess
import sys
import time
import urllib.parse

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
AUJOURDHUI = datetime.date.today()
T = "glpi_plugin_points_"
CLASSE = "GlpiPlugin%5CPoints%5C"
MDP = "Tech-points-1"


def d(jours):
    """Date relative à aujourd'hui (AAAA-MM-JJ)."""
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def prochain(jour_iso, apres):
    """Premier jour de semaine donné (1 lundi … 7 dimanche) au moins `apres` jours après aujourd'hui."""
    jour = AUJOURDHUI + datetime.timedelta(days=apres)
    while jour.isoweekday() != jour_iso:
        jour += datetime.timedelta(days=1)
    return jour.isoformat()


def dernier(jour_iso, avant=1):
    """Dernier jour de semaine donné (1 lundi … 7 dimanche) au moins `avant` jours avant aujourd'hui."""
    jour = AUJOURDHUI - datetime.timedelta(days=avant)
    while jour.isoweekday() != jour_iso:
        jour -= datetime.timedelta(days=1)
    return jour.isoformat()


def led(appel, *args):
    """Appelle Ledger::<appel> dans le conteneur ; renvoie le JSON {"ok" | "violation" | "error": …}."""
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def ok(r):
    return r["ok"] if "ok" in r else None


def creer(api, itemtype, entree):
    return api._req("POST", f"/{itemtype}", {"input": entree})["id"]


def essai(api, methode, chemin, corps=None):
    """(vrai si la requête passe, texte de la réponse ou de l'erreur)."""
    try:
        return True, json.dumps(api._req(methode, chemin, corps))
    except ErreurApi as e:
        return False, str(e)


def poster(web, page, champs):
    return web.post(f"/plugins/points/front/{page}", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})


def droit(nom, valeur):
    sql_ecrire(f"UPDATE glpi_profilerights SET rights = {valeur} WHERE name = '{nom}' "
               "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
    console("cache:clear")


def droits_super_admin(**valeurs):
    """Plusieurs droits du Super-Admin d'un coup, un seul vidage du cache (plusieurs vidages de suite rendent la page de connexion instable)."""
    for nom, valeur in valeurs.items():
        sql_ecrire(f"UPDATE glpi_profilerights SET rights = {valeur} WHERE name = '{nom}' "
                   "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
    console("cache:clear")


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def une(requete):
    return sql(requete)[0][0]


def ops(contrat):
    return sql(f"SELECT type, amount FROM {T}operations WHERE contracts_id = {contrat} ORDER BY id")


def solde(contrat):
    return une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {contrat}")


def nb_taches(ticket):
    return int(une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {ticket}"))


def statut(ticket):
    return une(f"SELECT status FROM glpi_tickets WHERE id = {ticket}")


def debits(ticket):
    """[(montant, détails JSON)] des débits de tâche d'un ticket."""
    lignes_ = [(r[0], json.loads(r[1])) for r in sql(
        f"SELECT amount, details FROM {T}operations WHERE tickets_id = {ticket} AND type = 'debit_task' ORDER BY id")]
    return sorted(lignes_, key=lambda b: b[1]["tasks_id"])  # ordre de saisie (le débit suit l'ordre des dates)


# --- état d'origine, remis à la fin
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'")
          for c in ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id")}
droits_origine = {n: une(f"SELECT rights FROM glpi_profilerights WHERE name = '{n}' AND profiles_id = "
                         "(SELECT id FROM glpi_profiles WHERE name = 'Super-Admin')") for n in ("plugin_points_grid", "plugin_points_ticket", "plugin_points_config", "plugin_points_review", "plugin_points_compensation", "plugin_points_balance")}

# Hypothèse explicite : un autre scénario ne laisse pas d'opération de tâche synthétique dont l'id pourrait coïncider avec
# celui d'une vraie tâche (le débit d'une tâche déjà référencée par le journal est, à raison, refusé comme déjà débité)
v("(0) aucune opération de tâche synthétique à petit identifiant laissée par un autre scénario",
  une(f"SELECT COUNT(*) FROM {T}operations WHERE itemtype = 'TicketTask' AND items_id < 1000000 AND items_id NOT IN (SELECT id FROM glpi_tickettasks)"), "0")

api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST, OT, URGENT = types["Assistance"], types["Ordre de travail"], types["Intervention urgente"]

# --- entité, types de contrat, calendriers (jours fériés natifs), technicien
type_points = creer(api, "ContractType", {"name": "points-taches-type"})
type_autre = creer(api, "ContractType", {"name": "points-taches-autre"})
config("contracttypes", f"[{type_points}]")
entite = creer(api, "Entity", {"name": "points-taches", "entities_id": 0})
api = banc.Api()  # nouvelle session : l'entité créée doit figurer parmi les entités actives

mardi, samedi, dimanche, mercredi = dernier(2), dernier(6), dernier(7), dernier(3)
ferie = mercredi
cal = creer(api, "Calendar", {"name": "points-cal-feries", "entities_id": 0, "is_recursive": 1})
cal_nu = creer(api, "Calendar", {"name": "points-cal-sans-ferie", "entities_id": 0, "is_recursive": 1})
for calendrier in (cal, cal_nu):
    for jour in range(1, 6):  # lundi à vendredi, 08:00-18:00 (day : 0 dimanche … 6 samedi, date('w'))
        creer(api, "CalendarSegment", {"calendars_id": calendrier, "day": jour, "begin": "08:00:00", "end": "18:00:00",
                                       "entities_id": 0, "is_recursive": 1})
jour_ferie = creer(api, "Holiday", {"name": "points-ferie", "begin_date": ferie, "end_date": ferie, "is_perpetual": 0,
                                    "entities_id": 0, "is_recursive": 1})
creer(api, "Calendar_Holiday", {"calendars_id": cal, "holidays_id": jour_ferie, "entities_id": 0, "is_recursive": 1})
api._req("PUT", f"/Entity/{entite}", {"input": {"calendars_strategy": 0, "calendars_id": cal}})
v("(0) calendrier de l'entité posé", une(f"SELECT calendars_id FROM glpi_entities WHERE id = {entite}"), str(cal))

profil_tech = une("SELECT id FROM glpi_profiles WHERE name = 'Technician'")
tech_id = creer(api, "User", {"name": "tech-points", "password": MDP, "password2": MDP, "_profiles_id": int(profil_tech),
                              "_entities_id": entite, "_is_recursive": 1, "is_active": 1})
tech = banc.Api("tech-points", MDP)
web = banc.Web()

# --- (1) grille Standard par les écrans d'administration (formulaires)
code, _ = poster(web, "grid.form.php", {"add": "1", "name": "Standard", "unit_minutes": "30"})
v("(1) unité 30 refusée (60 ou 15 seulement)", (code, une(f"SELECT COUNT(*) FROM {T}grids")), (302, "0"))
code, _ = poster(web, "grid.form.php", {"add": "1", "name": "", "unit_minutes": "60"})
v("(1) nom vide refusé", une(f"SELECT COUNT(*) FROM {T}grids"), "0")
code, _ = poster(web, "grid.form.php", {"add": "1", "name": "Standard", "unit_minutes": "60"})
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Standard'"))
v("(1) grille Standard créée (globale, unité 60, active)",
  sql(f"SELECT unit_minutes, is_active, entities_id, is_recursive FROM {T}grids WHERE id = {grille}"), [["60", "1", "0", "1"]])
code, _ = poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(grille), "begin_date": "pas-une-date"})
v("(1) date de version invalide refusée", une(f"SELECT COUNT(*) FROM {T}gridversions"), "0")
code, _ = poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(grille), "begin_date": d(-400), "comment": "v1"})
v1 = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(grille), "begin_date": d(-400)})
v("(1) deux versions à la même date refusées", une(f"SELECT COUNT(*) FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"), "1")

# matrice type d'intervention × type de jour du cahier des charges (virgule décimale acceptée)
standard = {ASSIST: ("1", "1,5", "2"), OT: ("2", "3", "4"), URGENT: ("3", "4,5", "6")}


def matrice(version, valeurs):
    champs = {"save_costs": "1", "version_id": str(version)}
    for type_, prix in valeurs.items():
        for jour, prix_jour in enumerate(prix, start=1):
            champs[f"cost[{type_}][{jour}]"] = prix_jour
    return poster(web, "grid.form.php", champs)


matrice(v1, standard)
v("(1) matrice enregistrée : 9 prix, 1,5 devient 1.50",
  (une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1}"),
   une(f"SELECT cost FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 2")),
  ("9", "1.50"))
matrice(v1, {ASSIST: ("1", "-3", "2")})
v("(1) prix négatif refusé, l'ancien prix reste",
  une(f"SELECT cost FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 2"), "1.50")
matrice(v1, {t: p for t, p in standard.items() if t != URGENT} | {URGENT: ("3", "", "6")})
v("(1) case vidée : le prix est supprimé", une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1}"), "8")
matrice(v1, standard)
v("(1) case remplie à nouveau", une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1}"), "9")
code, page = web.get(f"/plugins/points/front/grid.form.php?id={grille}")
v("(1) page de la grille : matrice et version affichées", (code, "points-version-%d" % v1 in page, "Sunday and holiday" in page), (200, True, True))
code, page = web.get("/plugins/points/front/grid.php")
v("(1) liste des grilles : Standard et types d'intervention", (code, "Standard" in page, "Intervention urgente" in page), (200, True, True))
code, page = web.get("/plugins/points/front/config.form.php")
v("(1) lien vers les grilles depuis Configuration > Points", (code, "points-grids-link" in page), (200, True))

# type d'intervention : création, doublon de nom non exigé, suppression d'un type inutilisé
poster(web, "interventiontype.form.php", {"add": "1", "name": "points-taches-test"})
test_type = int(une(f"SELECT id FROM {T}interventiontypes WHERE name = 'points-taches-test'"))
v("(1) type d'intervention créé (global, récursif)", sql(f"SELECT entities_id, is_recursive FROM {T}interventiontypes WHERE id = {test_type}"), [["0", "1"]])
poster(web, "interventiontype.form.php", {"purge": "1", "id": str(ASSIST)})
v("(1) type utilisé par un prix : suppression refusée", une(f"SELECT COUNT(*) FROM {T}interventiontypes WHERE id = {ASSIST}"), "1")
poster(web, "interventiontype.form.php", {"purge": "1", "id": str(test_type)})
v("(1) témoin : type inutilisé supprimé", une(f"SELECT COUNT(*) FROM {T}interventiontypes WHERE id = {test_type}"), "0")

# --- contrats : type points (par défaut de l'entité), autre type, sans enveloppe
creer_contrat = lambda nom, type_=type_points: creer(api, "Contract", {"name": nom, "entities_id": entite, "contracttypes_id": type_})
A = creer_contrat("points-taches-A")  # contrat par défaut de l'entité : exemple chiffré
B = creer_contrat("points-taches-B")  # grille versionnée
C = creer_contrat("points-taches-C")  # enveloppe pas encore commencée
D = creer_contrat("points-taches-D")  # mode « à la tâche »
E = creer_contrat("points-taches-E")  # lot expiré : débit tardif
F = creer_contrat("points-taches-F")  # second contrat Points (ambiguïté)
G = creer_contrat("points-taches-G", type_autre)  # contrat d'un autre type : jamais de points
api._req("PUT", f"/Entity/{entite}", {"input": {"contracts_strategy_default": 0, "contracts_id_default": A}})
v("(2) contrat par défaut de l'entité posé", une(f"SELECT contracts_id_default FROM glpi_entities WHERE id = {entite}"), str(A))

for contrat, credit, debut, fin in ((A, 4000, d(-100), d(200)), (B, 4000, d(-100), d(200)), (C, 1000, d(100), d(300)), (D, 4000, d(-100), d(200)),
                                    (F, 1000, d(-100), d(200))):
    led("createEnvelope", contrat, "E", credit, debut, fin, False, "")
for contrat in (A, B, C, D, F):
    sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {contrat};")
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = 0 WHERE contracts_id = {F};")  # F : sans grille


def ticket(nom, contrat=None, type_="Ticket"):
    entree = {"name": nom, "content": nom, "entities_id": entite}
    if contrat is not None:
        entree["_contracts_id"] = contrat
    return creer(api, "Ticket", entree)


def tache(client, tk, secondes, date, **plus):
    """(créée ?, texte) : ajout d'une tâche par l'API v1."""
    return essai(client, "POST", "/TicketTask", {"input": {"tickets_id": tk, "content": "tâche", "actiontime": secondes, "date": date + " 09:00:00", **plus}})


def liens(tk):
    return [int(r[0]) for r in sql(f"SELECT contracts_id FROM glpi_tickets_contracts WHERE tickets_id = {tk} ORDER BY contracts_id")]


# --- (3) exemple chiffré : 10, 20, 50 minutes un mardi et 30 minutes un dimanche, grille Standard, enveloppe de 40
t1 = ticket("exemple chiffré")
v("(3) le contrat par défaut de l'entité est lié nativement au ticket", liens(t1), [A])
for secondes, jour in ((600, mardi), (1200, mardi), (3000, mardi)):
    cree, _ = tache(tech, t1, secondes, jour)
    v(f"(3) technicien : tâche de {secondes // 60} min acceptée", cree, True)
cree, _ = tache(tech, t1, 1800, dimanche)
v("(3) technicien : tâche du dimanche acceptée", cree, True)
v("(3) à la clôture seulement : rien n'est débité tant que le ticket est ouvert", (ops(A), solde(A)), ([["credit", "40.00"]], "40.00"))
code, page = web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                     + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={t1}")
v("(3) onglet Points du ticket : tâches estimées et total 2.75", (code, "points-tasks" in page, "2.75" in page, "Estimated, not yet debited" in page), (200, True, True, True))
api._req("PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(3) ticket clos", statut(t1), "6")
v("(3) quatre débits 0,25 + 0,50 + 1,00 + 1,00 (journal)", [(m, j["counted_minutes"], j["daytype"]) for m, j in debits(t1)],
  [("-0.25", 15, 1), ("-0.50", 30, 1), ("-1.00", 60, 1), ("-1.00", 30, 3)])
v("(3) solde 37,25", solde(A), "37.25")
v("(3) cache du contrat : solde, crédité, consommé", sql(f"SELECT balance, credited, consumed FROM {T}contracts WHERE contracts_id = {A}"), [["37.25", "40.00", "2.75"]])
v("(3) le dimanche est signalé (drapeaux) avec le coût unitaire et la version", (debits(t1)[3][1]["flags"], debits(t1)[3][1]["cost"], debits(t1)[3][1]["gridversions_id"]), (["sunday"], "2.00", v1))
v("(3) détails : type d'intervention, grille, minutes saisies", (debits(t1)[0][1]["interventiontypes_id"], debits(t1)[0][1]["grids_id"], debits(t1)[0][1]["entered_minutes"]), (ASSIST, grille, 10))
v("(3) débits liés à la tâche, faits par l'utilisateur qui clôt le ticket (glpi)",
  sql(f"SELECT o.users_id, o.itemtype, o.items_id = t.id FROM {T}operations o JOIN glpi_tickettasks t ON t.id = o.items_id WHERE o.tickets_id = {t1} AND o.type = 'debit_task' LIMIT 1"),
  [[une("SELECT id FROM glpi_users WHERE name = 'glpi'"), "TicketTask", "1"]])
v("(3) quatre tâches verrouillées, liées à leur débit",
  sql(f"SELECT COUNT(*), SUM(is_locked), SUM(operations_id > 0) FROM {T}tasks WHERE tickets_id = {t1}"), [["4", "4", "4"]])
v("(3) invariants du journal", ok(led("check", A)), [])
code, page = web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                     + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={t1}")
v("(3) onglet : tâches débitées et verrouillées, total 2.75", (code, "Debited (locked)" in page, "2.75" in page, "Sunday" in page), (200, True, True, True))

# --- (4) réouverture : le cœur refuse de modifier les tâches d'un ticket clos, les refus du plugin se testent donc ticket ouvert
essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 2}})
v("(4) ticket rouvert", statut(t1), "2")
# verrouillage après débit (et témoins sur une tâche non débitée)
tache_debitee = int(une(f"SELECT items_id FROM {T}operations WHERE tickets_id = {t1} AND type = 'debit_task' ORDER BY id LIMIT 1"))
avant = sql(f"SELECT actiontime, date, begin, end FROM glpi_tickettasks WHERE id = {tache_debitee}")
for champ, valeur in (("actiontime", 7200), ("date", mardi + " 15:00:00"), ("begin", mardi + " 14:00:00"), ("end", mardi + " 16:00:00")):
    essai(tech, "PUT", f"/TicketTask/{tache_debitee}", {"input": {champ: valeur}})
    v(f"(4) tâche débitée : modification de {champ} refusée", sql(f"SELECT actiontime, date, begin, end FROM glpi_tickettasks WHERE id = {tache_debitee}"), avant)
essai(tech, "PUT", f"/TicketTask/{tache_debitee}", {"input": {"plan": {"begin": mardi + " 14:00:00", "end": mardi + " 15:00:00"}}})
v("(4) tâche débitée : planification (plan) refusée", sql(f"SELECT actiontime, date, begin, end FROM glpi_tickettasks WHERE id = {tache_debitee}"), avant)
essai(tech, "PUT", f"/TicketTask/{tache_debitee}", {"input": {"content": "contenu corrigé", "actiontime": avant[0][0]}})
v("(4) témoin : le contenu d'une tâche débitée reste modifiable (durée renvoyée à l'identique)",
  une(f"SELECT content FROM glpi_tickettasks WHERE id = {tache_debitee}"), "contenu corrigé")
essai(tech, "DELETE", f"/TicketTask/{tache_debitee}?force_purge=1")
v("(4) tâche débitée : suppression refusée", une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE id = {tache_debitee}"), "1")
v("(4) journal intact", (sorted(ops(A)[1:]), solde(A)), (sorted([["debit_task", "-0.25"], ["debit_task", "-0.50"], ["debit_task", "-1.00"], ["debit_task", "-1.00"]]), "37.25"))

t2 = ticket("tâche libre")
cree, _ = tache(tech, t2, 900, mardi)
libre = int(une(f"SELECT MAX(id) FROM glpi_tickettasks WHERE tickets_id = {t2}"))
essai(tech, "PUT", f"/TicketTask/{libre}", {"input": {"actiontime": 1800}})
v("(4) témoin : durée d'une tâche non débitée modifiable", une(f"SELECT actiontime FROM glpi_tickettasks WHERE id = {libre}"), "1800")
essai(tech, "DELETE", f"/TicketTask/{libre}?force_purge=1")
v("(4) témoin : tâche non débitée supprimable", une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE id = {libre}"), "0")

# --- (5) la tâche ajoutée après réouverture est débitée à la clôture suivante, les anciennes ne le sont pas deux fois
cree, _ = tache(tech, t1, 3600, mardi)
v("(5) tâche ajoutée après réouverture : pas débitée avant la clôture", (cree, solde(A), len(debits(t1))), (True, "37.25", 4))
api._req("PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(5) clôture suivante : seule la nouvelle tâche est débitée", (len(debits(t1)), solde(A), debits(t1)[4][0]), (5, "36.25", "-1.00"))

# --- (6) refus d'une tâche sans enveloppe valide à sa date, sans grille, sans version, sans prix
t3 = ticket("enveloppe pas commencée", C)
cree, texte = tache(tech, t3, 900, mardi)
v("(6) contrat C : aucune enveloppe valide à la date → tâche refusée", (cree, nb_taches(t3)), (False, 0))
v("(6) le refus dit pourquoi (message traduit)", "No valid points at the date of the task" in texte, True)
dans_enveloppe = prochain(2, 150)
cree, _ = tache(tech, t3, 900, dans_enveloppe)
v("(6) témoin : une tâche datée dans l'enveloppe est acceptée", (cree, nb_taches(t3)), (True, 1))
cree, _ = tache(tech, t3, 0, mardi)
v("(6) durée nulle : jamais refusée, rien n'est débité", (cree, nb_taches(t3)), (True, 2))
api._req("PUT", f"/Ticket/{t3}", {"input": {"status": 6}})
v("(6) clôture : seule la tâche datée dans l'enveloppe est débitée (à sa date)", (statut(t3), [m for m, _ in debits(t3)], solde(C)), ("6", ["-0.25"], "9.75"))

t4 = ticket("sans grille", F)
v("(6) contrat F sans grille : lié au ticket (seul contrat Points)", liens(t4), [F])
cree, texte = tache(tech, t4, 900, mardi)
v("(6) contrat sans grille : tâche refusée", (cree, nb_taches(t4), "no price grid" in texte), (False, 0, True))
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {F};")
cree, _ = tache(tech, t4, 900, mardi)
v("(6) témoin : la grille posée, la tâche passe", (cree, nb_taches(t4)), (True, 1))
# clôture refusée : la grille disparaît après l'ajout de la tâche
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = 0 WHERE contracts_id = {F};")
ok_cloture, _ = essai(api, "PUT", f"/Ticket/{t4}", {"input": {"status": 6}})
v("(6) clôture refusée sans grille : le ticket reste ouvert, rien n'est débité", (statut(t4) != "6", len(debits(t4)), solde(F)), (True, 0, "10.00"))
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {F};")
essai(api, "PUT", f"/Ticket/{t4}", {"input": {"status": 6}})
v("(6) témoin : la grille revenue, la clôture passe et débite", (statut(t4), len(debits(t4)), solde(F)), ("6", 1, "9.75"))

# version sans prix pour le type : refus ; version absente à la date : refus
code, _ = poster(web, "grid.form.php", {"add": "1", "name": "Sans prix", "unit_minutes": "60"})
vide = int(une(f"SELECT id FROM {T}grids WHERE name = 'Sans prix'"))
poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(vide), "begin_date": d(-400)})
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {vide} WHERE contracts_id = {F};")
t5 = ticket("grille sans prix", F)
cree, texte = tache(tech, t5, 900, mardi)
v("(6) grille sans prix pour ce type et ce jour : tâche refusée", (cree, "no price" in texte, nb_taches(t5)), (False, True, 0))
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {F};")
cree, texte = tache(tech, t5, 900, d(-500))
v("(6) tâche antérieure à la première version de la grille : refusée (aucune version, et enveloppe)", (cree, nb_taches(t5)), (False, 0))
poster(web, "grid.form.php", {"purge": "1", "id": str(vide)})
v("(6) grille vide inutilisée supprimée avec ses versions", (une(f"SELECT COUNT(*) FROM {T}grids WHERE id = {vide}"), une(f"SELECT COUNT(*) FROM {T}gridversions WHERE plugin_points_grids_id = {vide}")), ("0", "0"))

# --- (7) dépassement interdit : refus de clôture ; autorisé : débit avec dépassement
config("allow_overdraft", "0")
t6 = ticket("dépassement", F)
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {F};")
for _ in range(2):
    tache(tech, t6, 36000, mardi)  # 2 × 10 h à 1 point/h : 20 points pour un solde de 9,75
essai(api, "PUT", f"/Ticket/{t6}", {"input": {"status": 6}})
v("(7) dépassement interdit : clôture refusée, rien n'est débité", (statut(t6) != "6", len(debits(t6)), solde(F)), (True, 0, "9.75"))
config("allow_overdraft", "1")
essai(api, "PUT", f"/Ticket/{t6}", {"input": {"status": 6}})
v("(7) témoin : dépassement autorisé, clôture et débit avec dépassement signalé", (statut(t6), solde(F), "overdraft" in debits(t6)[-1][1]), ("6", "-10.25", True))

# --- (8) version de grille applicable à la date de la tâche (2e version datée qui change les coûts)
code, _ = poster(web, "grid.form.php", {"add": "1", "name": "Versionnée", "unit_minutes": "60"})
gv = int(une(f"SELECT id FROM {T}grids WHERE name = 'Versionnée'"))
poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(gv), "begin_date": d(-60)})
gv1 = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {gv}"))
matrice(gv1, standard)
poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(gv), "begin_date": d(-10), "copy_from": str(gv1)})
gv2 = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {gv} AND begin_date = '{d(-10)}'"))
v("(8) nouvelle version : prix copiés de la précédente", une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {gv2}"), "9")
matrice(gv2, {ASSIST: ("2", "3", "4"), OT: ("2", "3", "4"), URGENT: ("3", "4,5", "6")})
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {gv} WHERE contracts_id = {B};")
v("(8) contrat B sur la grille versionnée", une(f"SELECT plugin_points_grids_id FROM {T}contracts WHERE contracts_id = {B}"), str(gv))
ancienne, recente = dernier(2, 20), dernier(2, 1)
t7 = ticket("versions", B)
v("(8) témoin : les deux mardis sont de part et d'autre de la 2e version", (ancienne < d(-10) <= recente), True)
tache(tech, t7, 3600, ancienne)
tache(tech, t7, 3600, recente)
api._req("PUT", f"/Ticket/{t7}", {"input": {"status": 6}})
v("(8) avant la date : version 1 (1 point) ; après : version 2 (2 points)",
  [(m, j["gridversions_id"]) for m, j in debits(t7)], [("-1.00", gv1), ("-2.00", gv2)])
code, _ = poster(web, "grid.form.php", {"save_costs": "1", "version_id": str(gv1), f"cost[{ASSIST}][1]": "9"})
v("(8) version utilisée : modification d'un prix refusée (formulaire)", une(f"SELECT cost FROM {T}gridcosts WHERE plugin_points_gridversions_id = {gv1} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 1"), "1.00")
essai(api, "PUT", f"/{CLASSE}GridCost/{une(f'SELECT id FROM {T}gridcosts WHERE plugin_points_gridversions_id = {gv1} LIMIT 1')}", {"input": {"cost": "9"}})
v("(8) version utilisée : modification d'un prix refusée (API)", une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {gv1} AND cost = 9"), "0")
poster(web, "grid.form.php", {"update_version": "1", "version_id": str(gv1), "begin_date": d(-70)})
v("(8) version utilisée : date de début non modifiable", une(f"SELECT begin_date FROM {T}gridversions WHERE id = {gv1}"), d(-60))
poster(web, "grid.form.php", {"purge_version": "1", "version_id": str(gv1)})
v("(8) version utilisée : suppression refusée", une(f"SELECT COUNT(*) FROM {T}gridversions WHERE id = {gv1}"), "1")
poster(web, "grid.form.php", {"update": "1", "id": str(gv), "name": "Versionnée", "unit_minutes": "15", "is_active": "1"})
v("(8) grille ayant une version utilisée : unité non modifiable", une(f"SELECT unit_minutes FROM {T}grids WHERE id = {gv}"), "60")
poster(web, "grid.form.php", {"purge": "1", "id": str(gv)})
v("(8) grille utilisée par un contrat : suppression refusée", une(f"SELECT COUNT(*) FROM {T}grids WHERE id = {gv}"), "1")
poster(web, "grid.form.php", {"add_version": "1", "plugin_points_grids_id": str(gv), "begin_date": d(5), "copy_from": str(gv2)})
v("(8) témoin : une nouvelle version reste possible", une(f"SELECT COUNT(*) FROM {T}gridversions WHERE plugin_points_grids_id = {gv}"), "3")
nouvelle = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {gv} AND begin_date = '{d(5)}'"))
matrice(nouvelle, {ASSIST: ("5", "5", "5")})
v("(8) témoin : la version inutilisée reste modifiable (prix 5)", une(f"SELECT cost FROM {T}gridcosts WHERE plugin_points_gridversions_id = {nouvelle} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 1"), "5.00")
poster(web, "grid.form.php", {"purge_version": "1", "version_id": str(nouvelle)})
v("(8) témoin : la version inutilisée est supprimable avec ses prix", (une(f"SELECT COUNT(*) FROM {T}gridversions WHERE id = {nouvelle}"), une(f"SELECT COUNT(*) FROM {T}gridcosts WHERE plugin_points_gridversions_id = {nouvelle}")), ("0", "0"))

# --- (9) samedi, jour férié du calendrier natif, hors horaires ; calendrier du paramètre
t8 = ticket("samedi et férié", D)
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {D};")
tache(tech, t8, 3600, samedi)
tache(tech, t8, 3600, ferie)
tache(tech, t8, 1800, mardi, begin=mardi + " 22:00:00", end=mardi + " 22:30:00")
tache(tech, t8, 1800, mardi, begin=mardi + " 10:00:00", end=mardi + " 10:30:00")
api._req("PUT", f"/Ticket/{t8}", {"input": {"status": 6}})
bilan = debits(t8)
v("(9) samedi : coût 1,5 (type de jour 2), signalé saturday", (bilan[0][0], bilan[0][1]["daytype"], bilan[0][1]["flags"]), ("-1.50", 2, ["saturday"]))
v("(9) jour férié du calendrier de l'entité : coût 2 (type de jour 3), signalé holiday", (bilan[1][0], bilan[1][1]["daytype"], bilan[1][1]["flags"]), ("-2.00", 3, ["holiday"]))
v("(9) tâche planifiée hors des créneaux du calendrier : signalée off_hours", (bilan[2][0], bilan[2][1]["flags"]), ("-0.50", ["off_hours"]))
v("(9) témoin : tâche planifiée dans les créneaux : aucun drapeau", (bilan[3][0], bilan[3][1]["flags"]), ("-0.50", []))
config("calendars_id", str(cal_nu))
t9 = ticket("calendrier du paramètre", D)
tache(tech, t9, 3600, ferie)
api._req("PUT", f"/Ticket/{t9}", {"input": {"status": 6}})
v("(9) calendrier du paramètre (sans férié) : la même date compte comme un jour de semaine", (debits(t9)[0][0], debits(t9)[0][1]["daytype"], debits(t9)[0][1]["flags"]), ("-1.00", 1, []))
config("calendars_id", "0")

# --- (10) réglage du ticket : contrat à choisir, type d'intervention, facturable
t10 = ticket("deux contrats", A)
creer(api, "Ticket_Contract", {"tickets_id": t10, "contracts_id": F})
v("(10) deux contrats Points liés au ticket", liens(t10), sorted([A, F]))
cree, texte = tache(tech, t10, 900, mardi)
v("(10) plusieurs contrats Points sans choix : tâche refusée, message invitant à choisir", (cree, nb_taches(t10), "choose the one" in texte), (False, 0, True))
code, _ = poster(web, "ticket.form.php", {"update": "1", "tickets_id": str(t10), "contracts_id": str(G), "plugin_points_interventiontypes_id": "0", "is_billable": "1"})
v("(10) contrat hors des contrats Points du ticket : réglage refusé", une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {t10}"), "0")
code, _ = poster(web, "ticket.form.php", {"update": "1", "tickets_id": str(t10), "contracts_id": str(F), "plugin_points_interventiontypes_id": str(URGENT), "is_billable": "1"})
v("(10) choix du contrat et du type d'intervention enregistré", (code, sql(f"SELECT contracts_id, plugin_points_interventiontypes_id, is_billable, entities_id FROM {T}tickets WHERE tickets_id = {t10}")),
  (302, [[str(F), str(URGENT), "1", str(entite)]]))
cree, _ = tache(tech, t10, 3600, mardi)
v("(10) témoin : contrat choisi, la tâche passe", (cree, nb_taches(t10)), (True, 1))
api._req("PUT", f"/Ticket/{t10}", {"input": {"status": 6}})
v("(10) débit sur le contrat choisi, au prix du type urgent (3 points par heure)", ([m for m, _ in debits(t10)], debits(t10)[0][1]["interventiontypes_id"]), (["-3.00"], URGENT))
v("(10) le contrat débité est F", une(f"SELECT contracts_id FROM {T}operations WHERE tickets_id = {t10} AND type = 'debit_task'"), str(F))
v("(10) type d'intervention inconnu refusé", poster(web, "ticket.form.php", {"update": "1", "tickets_id": str(t10), "contracts_id": str(F), "plugin_points_interventiontypes_id": "99999", "is_billable": "1"})[0] == 302
  and une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE tickets_id = {t10}"), str(URGENT))

# ticket non facturable : jamais bloqué, jamais débité
t11 = ticket("autre type de contrat", G)
cree, _ = tache(tech, t11, 3600, mardi)
cree2, _ = tache(tech, t11, 3600, d(-900))
v("(10) contrat d'un autre type : tâches acceptées même sans enveloppe valide", (cree, cree2, nb_taches(t11)), (True, True, 2))
api._req("PUT", f"/Ticket/{t11}", {"input": {"status": 6}})
v("(10) clôture sans débit", (statut(t11), len(debits(t11))), ("6", 0))
t12 = ticket("non facturable", C)
poster(web, "ticket.form.php", {"update": "1", "tickets_id": str(t12), "contracts_id": "0", "plugin_points_interventiontypes_id": "0", "is_billable": "0"})
cree, _ = tache(tech, t12, 900, mardi)
v("(10) ticket marqué non facturable : tâche acceptée alors que le contrat C n'a pas d'enveloppe à cette date", (cree, nb_taches(t12)), (True, 1))
api._req("PUT", f"/Ticket/{t12}", {"input": {"status": 6}})
v("(10) ticket non facturable : clôture sans débit", (statut(t12), len(debits(t12))), ("6", 0))
t13 = ticket("sans contrat", None)
sql_ecrire(f"DELETE FROM glpi_tickets_contracts WHERE tickets_id = {t13};")
cree, _ = tache(tech, t13, 900, mardi)
v("(10) ticket sans contrat : tâche acceptée, non facturable", (cree, nb_taches(t13)), (True, 1))

# --- (11) droits : réglage du ticket (plugin_points_ticket) et grilles (plugin_points_grid)
droit("plugin_points_ticket", 1)
web2 = banc.Web()
code, _ = poster(web2, "ticket.form.php", {"update": "1", "tickets_id": str(t10), "contracts_id": str(F), "plugin_points_interventiontypes_id": str(OT), "is_billable": "1"})
v("(11) réglage du ticket sans le droit UPDATE : refusé", (code, une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE tickets_id = {t10}")), (403, str(URGENT)))
droit("plugin_points_ticket", 3)
web2 = banc.Web()
code, _ = poster(web2, "ticket.form.php", {"update": "1", "tickets_id": str(t10), "contracts_id": str(F), "plugin_points_interventiontypes_id": str(OT), "is_billable": "1"})
v("(11) témoin : droit UPDATE rétabli, le réglage passe", (code, une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE tickets_id = {t10}")), (302, str(OT)))
droit("plugin_points_grid", 1)
web3 = banc.Web()
code, _ = poster(web3, "grid.form.php", {"add": "1", "name": "Interdite", "unit_minutes": "60"})
v("(11) grilles : sans le droit CREATE, création refusée", (code, une(f"SELECT COUNT(*) FROM {T}grids WHERE name = 'Interdite'")), (403, "0"))
code, _ = poster(web3, "grid.form.php", {"save_costs": "1", "version_id": str(v1), f"cost[{ASSIST}][1]": "7"})
v("(11) grilles : sans le droit UPDATE, matrice refusée", (code, une(f"SELECT cost FROM {T}gridcosts WHERE plugin_points_gridversions_id = {v1} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 1")), (403, "1.00"))
code, _ = poster(web3, "interventiontype.form.php", {"add": "1", "name": "Interdit"})
v("(11) types d'intervention : sans le droit CREATE, création refusée", (code, une(f"SELECT COUNT(*) FROM {T}interventiontypes WHERE name = 'Interdit'")), (403, "0"))
code, page = web3.get("/plugins/points/front/grid.php")
v("(11) témoin : la lecture des grilles reste possible", (code, "Standard" in page, "New grid" in page), (200, True, False))
droit("plugin_points_grid", 0)
web3 = banc.Web()
code, _ = web3.get("/plugins/points/front/grid.php")
v("(11) sans le droit READ : liste des grilles refusée", code, 403)
droit("plugin_points_grid", droits_origine["plugin_points_grid"])
droit("plugin_points_ticket", droits_origine["plugin_points_ticket"])
n_avant = une(f"SELECT COUNT(*) FROM {T}tickets")
essai(tech, "POST", f"/{CLASSE}Ticket", {"input": {"tickets_id": t13, "is_billable": 0}})
v("(11) technicien sans le droit plugin_points_ticket : création d'un réglage par l'API refusée", une(f"SELECT COUNT(*) FROM {T}tickets"), n_avant)
essai(tech, "POST", f"/{CLASSE}Operation", {"input": {"contracts_id": A, "amount": "5"}})
v("(11) le journal reste en ajout seul", une(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {A} AND amount = 5"), "0")

# --- (12) débit « à la tâche »
config("debit_moment", "task")
t14 = ticket("à la tâche", D)
avant_solde = solde(D)
cree, _ = tache(tech, t14, 3600, mardi)
v("(12) à la tâche : débit dès l'ajout, ticket ouvert", (cree, len(debits(t14)), debits(t14)[0][0], statut(t14) != "6"), (True, 1, "-1.00", True))
v("(12) à la tâche : débit fait par le technicien qui saisit la tâche", une(f"SELECT users_id FROM {T}operations WHERE tickets_id = {t14} AND type = 'debit_task'"), str(tech_id))
v("(12) à la tâche : tâche verrouillée et solde diminué", (une(f"SELECT is_locked FROM {T}tasks WHERE tickets_id = {t14}"), float(avant_solde) - float(solde(D))), ("1", 1.0))
tid = int(une(f"SELECT MAX(id) FROM glpi_tickettasks WHERE tickets_id = {t14}"))
essai(tech, "PUT", f"/TicketTask/{tid}", {"input": {"actiontime": 7200}})
v("(12) à la tâche : durée immédiatement verrouillée", une(f"SELECT actiontime FROM glpi_tickettasks WHERE id = {tid}"), "3600")
cree, _ = tache(tech, t14, 0, mardi)
v("(12) à la tâche : durée nulle, rien n'est débité", (cree, len(debits(t14))), (True, 1))
cree, texte = tache(tech, ticket("à la tâche sans enveloppe", C), 900, mardi)
v("(12) à la tâche : tâche refusée sans enveloppe valide", cree, False)
api._req("PUT", f"/Ticket/{t14}", {"input": {"status": 6}})
v("(12) à la tâche : la clôture ne débite pas une seconde fois", (len(debits(t14)), float(avant_solde) - float(solde(D))), (1, 1.0))
config("allow_overdraft", "0")
t15 = ticket("à la tâche, dépassement interdit", F)
cree, _ = tache(tech, t15, 360000, mardi)  # 100 h
v("(12) à la tâche, dépassement interdit : tâche refusée si le débit serait refusé", (cree, nb_taches(t15)), (False, 0))
config("allow_overdraft", "1")
config("debit_moment", "closure")

# --- (13) débit tardif après expiration (lot b) par une tâche antidatée
led("createEnvelope", E, "ancienne", 1000, d(-200), d(-30), False, "")
led("createEnvelope", E, "courante", 500, d(-20), d(300), False, "")
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {E};")
led("expireDue", d(0))
v("(13) le lot échu a expiré (10 points)", [o for o in ops(E)], [["credit", "10.00"], ["credit", "5.00"], ["expiry", "-10.00"]])
antidatee = dernier(2, 60)
t16 = ticket("antidatée", E)
cree, _ = tache(tech, t16, 3600, antidatee)
v("(13) tâche antidatée dans un lot expiré : acceptée (lot valide à sa date)", (cree, nb_taches(t16)), (True, 1))
api._req("PUT", f"/Ticket/{t16}", {"input": {"status": 6}})
v("(13) débit tardif : reprise de l'expiration puis débit de 1 point sur le lot expiré",
  [o[0] for o in ops(E)][3:], ["expiry_reversal", "debit_task"])
v("(13) solde : 5 + 10 - 10 (expiré) + 1 - 1 = 5 ; le client ne paie pas 2 fois", solde(E), "5.00")
v("(13) invariants du journal après débit tardif", ok(led("check", E)), [])

# --- (13b) deux débits de la même tâche en parallèle : un seul passe (unicité revérifiée sous le verrou du contrat)
def led_parallele(n, *args, verrou=0):
    """n appels de Ledger::debit en parallèle. verrou : secondes pendant lesquelles une session SQL tient le verrou de la
    ligne du contrat (UPDATE dans une transaction ouverte) : les n débits démarrent, se bloquent tous sur ce verrou, puis
    s'exécutent l'un après l'autre à sa libération."""
    tenue = None
    if verrou:
        tenue = subprocess.Popen(["docker", "exec", "-i", banc.DB, "mariadb", "-uroot", "-pbanc-root", "glpi"], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        tenue.stdin.write(f"BEGIN; UPDATE {T}contracts SET date_mod = NOW() WHERE contracts_id = {args[0]}; SELECT SLEEP({verrou}); ROLLBACK;")
        tenue.stdin.close()
        time.sleep(2)  # le verrou est pris
    procs = [subprocess.Popen(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(n)]
    for p in procs:
        p.stdin.write(json.dumps({"call": "debit", "args": list(args)}))
        p.stdin.close()
    sorties = []
    for p in procs:
        lignes_ = [l for l in p.stdout.read().strip().splitlines() if l.startswith("{")]
        sorties.append(json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie"})
        p.wait()
    if tenue is not None:
        tenue.wait()
    return sorties


for essai_n in range(2):
    fausse = 9000001 + essai_n
    sorties = led_parallele(4, E, "debit_task", 100, d(-10), {"unique": True, "itemtype": "TicketTask", "items_id": fausse, "tickets_id": 0}, verrou=10)
    v(f"(13b) 4 débits parallèles de la même tâche, verrou du contrat tenu : un seul passe (essai {essai_n + 1})",
      (sum("ok" in r for r in sorties), sum(r.get("violation") == "already_debited" for r in sorties),
       une(f"SELECT COUNT(*) FROM {T}operations WHERE type = 'debit_task' AND itemtype = 'TicketTask' AND items_id = {fausse}")), (1, 3, "1"))
sorties = led_parallele(1, E, "debit_task", 100, d(-10), {"unique": True, "itemtype": "TicketTask", "items_id": 9000010})
v("(13b) témoin : une autre tâche se débite", ["ok" in r for r in sorties], [True])
v("(13b) invariants du journal après les débits parallèles", ok(led("check", E)), [])

# --- (13c) purge d'un ticket : refusée s'il a une tâche débitée, sinon nettoyage des lignes du plugin
essai(api, "DELETE", f"/Ticket/{t1}")
essai(api, "DELETE", f"/Ticket/{t1}?force_purge=1")
v("(13c) ticket à tâche débitée : purge refusée, tâches et journal conservés",
  (une(f"SELECT COUNT(*) FROM glpi_tickets WHERE id = {t1}"), une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {t1}") != "0",
   une(f"SELECT COUNT(*) FROM {T}tasks WHERE tickets_id = {t1}") != "0"), ("1", True, True))
v("(13c) le réglage du ticket non débité existe avant la purge", une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {t12}"), "1")
essai(api, "DELETE", f"/Ticket/{t12}")
essai(api, "DELETE", f"/Ticket/{t12}?force_purge=1")
v("(13c) témoin : ticket sans débit purgé, son réglage Points nettoyé",
  (une(f"SELECT COUNT(*) FROM glpi_tickets WHERE id = {t12}"), une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {t12}"),
   une(f"SELECT COUNT(*) FROM {T}tasks WHERE tickets_id = {t12}")), ("0", "0", "0"))

# --- (13d) menu Configuration > Points : droit config OU grille
def menu_points(config_droit, grille_droit, review_droit=0, compensation_droit=0):
    # Outils > Points (contrats à points, lot g ; reviews, compensations, lot e) a ses propres droits : à 0 pour ne juger ici que l'entrée Configuration
    droits_super_admin(plugin_points_config=config_droit, plugin_points_grid=grille_droit, plugin_points_balance=0,
                       plugin_points_review=review_droit, plugin_points_compensation=compensation_droit)
    w = banc.Web()
    code, page = w.get("/front/central.php")
    return code, "plugins/points/front/" in page, w.get("/plugins/points/front/config.form.php")[0], w.get("/plugins/points/front/grid.php")[0]


v("(13d) droit grille seul : entrée de menu visible, page des grilles ouverte, page de configuration refusée", menu_points(0, 1), (200, True, 403, 200))
v("(13d) droit config seul : entrée de menu visible, configuration ouverte, grilles refusées", menu_points(3, 0), (200, True, 200, 403))
v("(13d) témoin : aucun des deux droits, pas d'entrée de menu", menu_points(0, 0)[:2], (200, False))


def menu_outils(review_droit, compensation_droit, balance_droit=0):
    """(code, lien vers les reviews, lien vers les compensations) de la page d'accueil, avec ces seuls droits de lecture."""
    droits_super_admin(plugin_points_review=review_droit, plugin_points_compensation=compensation_droit, plugin_points_balance=balance_droit)
    code, page = banc.Web().get("/front/central.php")
    return code, "plugins/points/front/review.php" in page, "plugins/points/front/compensation.php" in page, "plugins/points/front/contract.php" in page


v("(13d) droit de lecture des reviews seul : entrée Outils > Points avec les reviews seulement", menu_outils(1, 0), (200, True, False, False))
v("(13d) droit de lecture des compensations seul : entrée Outils > Points avec les compensations seulement", menu_outils(0, 1), (200, False, True, False))
v("(13d) témoin : aucun des deux droits de contrôle, pas d'entrée Outils > Points", menu_outils(0, 0), (200, False, False, False))
v("(13d) droit de lecture des soldes seul : entrée Outils > Points avec la liste des contrats seulement (lot g)", menu_outils(0, 0, 1), (200, False, False, True))
for nom, valeur in droits_origine.items():
    droit(nom, valeur)

# --- (16) fusion de tickets (Ticket::merge recopie les tâches avec sourceitems_id) : aucun double débit, aucun débit dans la fusion
def glpi(appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/glpi_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def nb_ops(contrat):
    return int(une(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {contrat}"))


def corbeille(tk):
    return une(f"SELECT is_deleted FROM glpi_tickets WHERE id = {tk}")


config("debit_moment", "closure")


def cron_closeticket():
    """Action automatique closeticket lancée comme en production (front/cron.php, sans utilisateur connecté)."""
    subprocess.run(["docker", "exec", "-u", "www-data", banc.APP, "php", "front/cron.php", "--force", "closeticket"], capture_output=True, text=True)


# a) source ouverte, tâche non débitée : copie normale, débitée à la clôture de la cible
s1, c1 = ticket("fusion source ouverte", D), ticket("fusion cible 1", D)
tache(tech, s1, 3600, mardi)
avant = nb_ops(D)
glpi("merge", c1, [s1])
v("(16a) fusion : tâche non débitée copiée sur la cible, source en corbeille", (nb_taches(c1), corbeille(s1)), (1, "1"))
v("(16a) aucun débit pendant la fusion, aucune ligne verrouillée", (nb_ops(D), une(f"SELECT COUNT(*) FROM {T}tasks WHERE tickets_id = {c1}")), (avant, "0"))
api._req("PUT", f"/Ticket/{c1}", {"input": {"status": 6}})
v("(16a) clôture de la cible : la copie est débitée une fois (1 point)", ([m for m, _ in debits(c1)], nb_ops(D)), (["-1.00"], avant + 1))
# b) source close dont la tâche est débitée (mode closure) : la copie est verrouillée, sans débit
s2, c2 = ticket("fusion source close", D), ticket("fusion cible 2", D)
tache(tech, s2, 3600, mardi)
api._req("PUT", f"/Ticket/{s2}", {"input": {"status": 6}})
origine = une(f"SELECT id FROM {T}operations WHERE tickets_id = {s2} AND type = 'debit_task'")
avant = nb_ops(D)
glpi("merge", c2, [s2])
v("(16b) fusion d'un ticket clos : tâche copiée, verrouillée, liée à l'opération d'origine, sans débit",
  (nb_taches(c2), sql(f"SELECT is_locked, operations_id FROM {T}tasks WHERE tickets_id = {c2}"), nb_ops(D)), (1, [["1", origine]], avant))
copie = int(une(f"SELECT id FROM glpi_tickettasks WHERE tickets_id = {c2}"))
essai(tech, "PUT", f"/TicketTask/{copie}", {"input": {"actiontime": 7200}})
v("(16b) la copie d'une tâche débitée est verrouillée", une(f"SELECT actiontime FROM glpi_tickettasks WHERE id = {copie}"), "3600")
api._req("PUT", f"/Ticket/{c2}", {"input": {"status": 6}})
v("(16b) clôture de la cible : aucun double débit", (nb_ops(D), len(debits(c2))), (avant, 0))
# c) mode « à la tâche » : la tâche d'origine est débitée à l'ajout, la copie est verrouillée sans nouveau débit
config("debit_moment", "task")
s3, c3 = ticket("fusion source à la tâche", D), ticket("fusion cible 3", D)
tache(tech, s3, 3600, mardi)
avant = nb_ops(D)
glpi("merge", c3, [s3])
v("(16c) mode tâche : copie verrouillée, aucun nouveau débit", (nb_taches(c3), sql(f"SELECT is_locked FROM {T}tasks WHERE tickets_id = {c3}"), nb_ops(D)), (1, [["1"]], avant))
# d) mode « à la tâche », tâche d'origine non débitée (saisie en mode closure) : pas de débit dans la fusion, débit à la clôture
config("debit_moment", "closure")
s4, c4 = ticket("fusion source libre", D), ticket("fusion cible 4", D)
tache(tech, s4, 3600, mardi)
config("debit_moment", "task")
avant = nb_ops(D)
glpi("merge", c4, [s4])
v("(16d) mode tâche, origine non débitée : copie libre, aucun débit pendant la fusion", (nb_taches(c4), nb_ops(D), une(f"SELECT COUNT(*) FROM {T}tasks WHERE tickets_id = {c4}")), (1, avant, "0"))
api._req("PUT", f"/Ticket/{c4}", {"input": {"status": 6}})
v("(16d) clôture de la cible : débitée une fois", ([m for m, _ in debits(c4)], nb_ops(D)), (["-1.00"], avant + 1))
config("debit_moment", "closure")
v("(16) invariants du journal après les fusions", ok(led("check", D)), [])

# --- (17) tâche créée par un modèle de tâche (durée du modèle, calculée par GLPI) : mêmes contrôles
modele = creer(api, "TaskTemplate", {"name": "points-taches-modele", "content": "modèle", "actiontime": 3600, "entities_id": 0, "is_recursive": 1})
t_m = ticket("modèle sans lot", C)
cree, texte = essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t_m, "_tasktemplates_id": modele, "date": mardi + " 09:00:00"}})
v("(17) tâche par modèle (durée 1 h du modèle) sans point valide à sa date : refusée", (cree, nb_taches(t_m)), (False, 0))
t_m2 = ticket("modèle avec lot", D)
cree, texte = essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t_m2, "_tasktemplates_id": modele, "date": mardi + " 09:00:00"}})
v("(17) témoin : tâche par modèle avec un lot valide acceptée, durée du modèle", (cree, une(f"SELECT actiontime FROM glpi_tickettasks WHERE tickets_id = {t_m2}")), (True, "3600"))

# --- (18) fuseau de référence : un utilisateur dans un autre fuseau que le serveur clôt une tâche du samedi 00:30 (serveur)
profil_sa = une("SELECT id FROM glpi_profiles WHERE name = 'Super-Admin'")
tz_id = creer(api, "User", {"name": "tz-points", "password": MDP, "password2": MDP, "_profiles_id": int(profil_sa), "_entities_id": entite,
                            "_is_recursive": 1, "is_active": 1, "timezone": "America/New_York"})
v("(18) utilisateur en fuseau America/New_York", une(f"SELECT timezone FROM glpi_users WHERE id = {tz_id}"), "America/New_York")
fuseau_serveur = subprocess.run(["docker", "exec", "-u", "www-data", banc.APP, "php", "-r", "echo date_default_timezone_get();"], capture_output=True, text=True).stdout
web_tz = banc.Web("tz-points", MDP)  # l'API v1 n'applique pas le fuseau de l'utilisateur : la clôture passe par l'interface web
t_z = ticket("fuseau", D)
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t_z, "content": "samedi 00:30", "actiontime": 3600, "date": samedi + " 00:30:00"}})
vendredi = (datetime.date.fromisoformat(samedi) - datetime.timedelta(days=1)).isoformat()
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t_z, "content": "vendredi 20:30", "actiontime": 3600, "date": vendredi + " 20:30:00"}})
code, _ = web_tz.post("/front/ticket.form.php", [("id", str(t_z)), ("status", "6"), ("update", "1")], {"Sec-Fetch-Site": "same-origin"})
v("(18) clôture par l'interface web (utilisateur de New York)", (code, statut(t_z)), (302, "6"))
bilan = debits(t_z)
v(f"(18) clôture par l'utilisateur de New York (serveur {fuseau_serveur}) : samedi 00:30 du serveur = tarif samedi (1,5), pas vendredi",
  [(m, j["daytype"], j["flags"]) for m, j in bilan], [("-1.50", 2, ["saturday"]), ("-1.00", 1, [])])
v("(18) date métier du journal dans le fuseau de référence", sorted(r[0] for r in sql(f"SELECT date FROM {T}operations WHERE tickets_id = {t_z} AND type = 'debit_task'")), sorted([samedi, vendredi]))
t_z2 = ticket("fuseau, témoin sans décalage", D)
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t_z2, "content": "samedi 00:30", "actiontime": 3600, "date": samedi + " 00:30:00"}})
api._req("PUT", f"/Ticket/{t_z2}", {"input": {"status": 6}})
v("(18) témoin : clôturée par l'utilisateur du fuseau du serveur, même tarif", [(m, j["daytype"]) for m, j in debits(t_z2)], [("-1.50", 2)])

# --- (19) clôture impossible : le ticket reste Résolu (solution conservée), trace pour la clôture automatique
lecture_log = lambda nom: subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                                         capture_output=True, text=True).stdout
api._req("PUT", f"/Entity/{entite}", {"input": {"autoclose_delay": 0}})
t_ac = ticket("clôture automatique impossible", D)
tache(tech, t_ac, 3600, mardi)
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = 0 WHERE contracts_id = {D};")
creer(api, "ITILSolution", {"itemtype": "Ticket", "items_id": t_ac, "content": "solution proposée"})
v("(19) solution avec clôture immédiate (autoclose_delay 0) sans grille : ticket Résolu, solution présente, rien débité",
  (statut(t_ac), une(f"SELECT COUNT(*) FROM glpi_itilsolutions WHERE items_id = {t_ac} AND itemtype = 'Ticket'"), len(debits(t_ac))), ("5", "1", 0))
cron_closeticket()
v("(19) action automatique closeticket sans grille : le ticket reste Résolu, trace dans points.log",
  (statut(t_ac), f"ticket {t_ac} stays Solved" in lecture_log("points.log")), ("5", True))
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {D};")
cron_closeticket()
v("(19) témoin : la grille revenue, l'action automatique clôt le ticket et débite", (statut(t_ac), len(debits(t_ac))), ("6", 1))
t_ac2 = ticket("solution avec grille", D)
tache(tech, t_ac2, 3600, mardi)
creer(api, "ITILSolution", {"itemtype": "Ticket", "items_id": t_ac2, "content": "solution"})
v("(19) témoin : solution avec clôture immédiate et grille : ticket Clos et débité", (statut(t_ac2), len(debits(t_ac2))), ("6", 1))
api._req("PUT", f"/Entity/{entite}", {"input": {"autoclose_delay": -2}})

# --- (20) mise à jour d'une tâche non débitée : mêmes contrôles qu'à l'ajout ; débit à la modification en mode « à la tâche »
t_u = ticket("modification", C)
tache(tech, t_u, 900, dans_enveloppe)
tid_u = int(une(f"SELECT MAX(id) FROM glpi_tickettasks WHERE tickets_id = {t_u}"))
essai(tech, "PUT", f"/TicketTask/{tid_u}", {"input": {"date": mardi + " 09:00:00"}})
v("(20) tâche non débitée : date déplacée hors de tout lot refusée", une(f"SELECT date FROM glpi_tickettasks WHERE id = {tid_u}")[:10], dans_enveloppe)
autre_jour = prochain(3, 150)
essai(tech, "PUT", f"/TicketTask/{tid_u}", {"input": {"date": autre_jour + " 10:00:00"}})
v("(20) témoin : date déplacée dans le lot acceptée", une(f"SELECT date FROM glpi_tickettasks WHERE id = {tid_u}")[:10], autre_jour)
essai(tech, "PUT", f"/TicketTask/{tid_u}", {"input": {"content": "contenu seul"}})
v("(20) témoin : modification du contenu seul toujours possible", une(f"SELECT content FROM glpi_tickettasks WHERE id = {tid_u}"), "contenu seul")
config("debit_moment", "task")
t_u2 = ticket("durée nulle puis non nulle", D)
tache(tech, t_u2, 0, mardi)
tid_u2 = int(une(f"SELECT MAX(id) FROM glpi_tickettasks WHERE tickets_id = {t_u2}"))
v("(20) mode tâche : durée nulle, aucun débit", len(debits(t_u2)), 0)
essai(tech, "PUT", f"/TicketTask/{tid_u2}", {"input": {"actiontime": 3600}})
v("(20) mode tâche : la durée devient non nulle, la tâche est débitée et verrouillée",
  ([m for m, _ in debits(t_u2)], une(f"SELECT is_locked FROM {T}tasks WHERE tickettasks_id = {tid_u2}")), (["-1.00"], "1"))
config("debit_moment", "closure")

# --- (21) date illisible : refus avec message, pas d'erreur 500
t_bad = ticket("date illisible", D)
cree, texte = essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t_bad, "content": "x", "actiontime": 900, "date": "pas-une-date"}})
v("(21) date illisible par l'API v1 : refusée (HTTP 400), aucune tâche créée", (cree, " 400 " in texte, nb_taches(t_bad)), (False, True, 0))
v("(21) le refus porte le message traduit", "date is not valid" in texte, True)

# --- (22) débit définitif : un remboursement ne rend pas la tâche débitable une seconde fois
op14 = une(f"SELECT id FROM {T}operations WHERE tickets_id = {t14} AND type = 'debit_task'")
tid14 = une(f"SELECT items_id FROM {T}operations WHERE id = {op14}")
v("(22) remboursement du débit de la tâche (correction positive)", "ok" in led("correction", D, int(op14), 100, "remboursement"), True)
sorties = led_parallele(1, D, "debit_task", 100, d(-3), {"unique": True, "itemtype": "TicketTask", "items_id": int(tid14)})
v("(22) débit refusé : déjà débité (le remboursement n'y change rien)", [r.get("violation") for r in sorties], ["already_debited"])
api._req("PUT", f"/Ticket/{t14}", {"input": {"status": 2}})
api._req("PUT", f"/Ticket/{t14}", {"input": {"status": 6}})
v("(22) réouverture puis clôture : la tâche remboursée n'est pas redébitée", len(debits(t14)), 1)
code, page = web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                     + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={t14}")
v("(22) onglet : total net du remboursement", (code, 'id="points-tasks-total">0.00' in page), (200, True))

# --- (23) onglet du ticket : tâches privées listées seulement avec le droit SEEPRIVATE de TicketTask
tech_profil = une("SELECT id FROM glpi_profiles WHERE name = 'Technician'")
droit_task = une(f"SELECT rights FROM glpi_profilerights WHERE name = 'task' AND profiles_id = {tech_profil}")
droit_pt = une(f"SELECT rights FROM glpi_profilerights WHERE name = 'plugin_points_ticket' AND profiles_id = {tech_profil}")


def droit_tech(task, points):
    sql_ecrire(f"UPDATE glpi_profilerights SET rights = {task} WHERE name = 'task' AND profiles_id = {tech_profil};"
               f"UPDATE glpi_profilerights SET rights = {points} WHERE name = 'plugin_points_ticket' AND profiles_id = {tech_profil};")
    console("cache:clear")


def onglet(client, tk):
    return client.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                      + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={tk}")


t_p = ticket("tâches privées", D)
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t_p, "content": "privée", "actiontime": 900, "date": mardi + " 09:00:00", "is_private": 1}})
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t_p, "content": "publique", "actiontime": 900, "date": mardi + " 10:00:00", "is_private": 0}})
privee = une(f"SELECT id FROM glpi_tickettasks WHERE tickets_id = {t_p} AND is_private = 1")
publique = une(f"SELECT id FROM glpi_tickettasks WHERE tickets_id = {t_p} AND is_private = 0")
droit_tech(int(droit_task) & ~8192, 1)
web_tech = banc.Web("tech-points", MDP)
code, page = onglet(web_tech, t_p)
v("(23) sans SEEPRIVATE : la tâche privée d'un autre n'est pas listée, la publique l'est", (code, f"<td>{privee}</td>" in page, f"<td>{publique}</td>" in page), (200, False, True))
droit_tech(int(droit_task) | 8192, 1)
web_tech = banc.Web("tech-points", MDP)
code, page = onglet(web_tech, t_p)
v("(23) témoin : avec SEEPRIVATE, les deux sont listées", (code, f"<td>{privee}</td>" in page, f"<td>{publique}</td>" in page), (200, True, True))
droit_tech(int(droit_task), int(droit_pt))

# --- (14) les anciens débits ne bougent pas ; invariants de tous les contrats
for contrat in (A, B, C, D, E, F):
    v(f"(14) invariants du contrat {contrat}", ok(led("check", contrat)), [])
v("(14) aucune opération modifiée ni supprimée par le lot (journal en ajout seul : nombre d'opérations de A inchangé)", len(ops(A)), 1 + 5)

# --- remise en l'état
for cle, valeur in ancien.items():
    config(cle, valeur)
for nom, valeur in droits_origine.items():
    droit(nom, valeur)

lecture = lambda nom: subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                                     capture_output=True, text=True).stdout
fautes = [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]
v("(15) aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : taches " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
