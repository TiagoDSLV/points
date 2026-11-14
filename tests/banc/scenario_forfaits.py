"""Forfaits (lot d) : chiffrage, soumission, validation du Responsable BU, proposition au client par la validation de ticket
native, approbation et débit définitif, refus, annulation, droits, montant figé (formulaire et API v1), concurrence,
moment du débit « à la tâche », clôture refusée, notifications natives. Part d'un plugin installé et activé (run.sh) ;
crée son entité, ses profils et ses utilisateurs ; remet la configuration comme il l'a trouvée."""
import atexit
import datetime
import json
import re
import subprocess
import sys
import threading
import urllib.parse
from zoneinfo import ZoneInfo

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
T = "glpi_plugin_points_"
CLASSE = "GlpiPlugin%5CPoints%5C"
FLAT = r"'GlpiPlugin\\Points\\Flatrate'"
MDP = "Forfait-points-1"
SUF = format(int(datetime.datetime.now().timestamp()) % 100000, "x")  # noms uniques : le scénario se rejoue sur un banc gardé
FLAT_BATCH = r"GlpiPlugin\\Points\\Flatrate"  # sortie du client mariadb en mode batch : barres obliques doublées
PARIS = ZoneInfo("Europe/Paris")
AUJOURDHUI = datetime.datetime.now(PARIS).date()


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def dernier(jour_iso, avant=1):
    jour = AUJOURDHUI - datetime.timedelta(days=avant)
    while jour.isoweekday() != jour_iso:
        jour -= datetime.timedelta(days=1)
    return jour.isoformat()


def une(requete):
    return sql(requete)[0][0]


def creer(api, itemtype, entree):
    return api._req("POST", f"/{itemtype}", {"input": entree})["id"]


def essai(api, methode, chemin, corps=None):
    """(vrai si la requête passe, texte de la réponse ou de l'erreur)."""
    try:
        return True, json.dumps(api._req(methode, chemin, corps))
    except ErreurApi as e:
        return False, str(e)


def led(appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def cli(appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/glpi_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def ok(r):
    return r["ok"] if "ok" in r else None


def poster(web, champs):
    """POST du formulaire des forfaits (en-tête de navigateur) ; renvoie le code HTTP."""
    return web.post("/plugins/points/front/flatrate.form.php", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})[0]


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def onglet(web, tk):
    return web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                   + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={tk}")


def solde(contrat):
    return une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {contrat}")


def forfaits(tk):
    """[(id, statut, montant, approuvé)] des forfaits d'un ticket."""
    return [(int(r[0]), r[1], r[2], r[3]) for r in sql(f"SELECT id, status, amount, approved_amount FROM {T}flatrates WHERE tickets_id = {tk} ORDER BY id")]


def statut(tk, n=-1):
    return forfaits(tk)[n][1]


def debits_forfait(tk):
    return sql(f"SELECT amount, itemtype, items_id, details FROM {T}operations WHERE tickets_id = {tk} AND type = 'debit_flatrate' ORDER BY id")


def debits_tache(tk):
    return int(une(f"SELECT COUNT(*) FROM {T}operations WHERE tickets_id = {tk} AND type = 'debit_task'"))


# --- état d'origine, remis à la fin
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'")
          for c in ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id", "flatrate_separation")}
ancien_notif = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'core' AND name = '{c}'") for c in ("use_notifications", "notifications_mailing")}


def remettre_config():
    for cle, valeur in ancien.items():
        config(cle, valeur)
    for cle, valeur in ancien_notif.items():
        console("config:set", cle, valeur)


atexit.register(remettre_config)  # même si le scénario s'arrête sur une exception


def lecture(nom):
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                          capture_output=True, text=True).stdout


def erreurs_plugin():
    return [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]


api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST, OT = types["Assistance"], types["Ordre de travail"]

# --- entités, type de contrat, grille (Assistance 1 / 1,5 / 2 ; Ordre de travail 2 / 3 / 4)
type_points = creer(api, "ContractType", {"name": f"points-forfaits-type-{SUF}"})
config("contracttypes", f"[{type_points}]")
entite = creer(api, "Entity", {"name": f"points-forfaits-{SUF}", "entities_id": 0})
autre_entite = creer(api, "Entity", {"name": f"points-forfaits-autre-{SUF}", "entities_id": 0})
api = banc.Api()
sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Forfaits-std-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Forfaits-std-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for type_, prix in ((ASSIST, (1, 1.5, 2)), (OT, (2, 3, 4))):
    for jour, cout in enumerate(prix, start=1):
        sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
                   f"VALUES (0, 1, {version}, {type_}, {jour}, {cout});")

# --- profils : technicien (forfaits 7), Responsable BU (1025), les deux (1031), client avec validation native
profil_tech_modele = une("SELECT id FROM glpi_profiles WHERE name = 'Technician'")
profil_ss = une("SELECT id FROM glpi_profiles WHERE name = 'Self-Service'")


def profil(nom, modele, interface, droits):
    pid = creer(api, "Profile", {"name": nom, "interface": interface})
    sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {pid}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele} "
               "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    for droit, valeur in droits.items():
        sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({pid}, '{droit}', {valeur}) ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    return pid


p_tech = profil(f"points-forfait-tech-{SUF}", profil_tech_modele, "central", {"plugin_points_flatrate": 7, "plugin_points_ticket": 3, "plugin_points_balance": 1})
p_bu = profil(f"points-forfait-bu-{SUF}", profil_tech_modele, "central", {"plugin_points_flatrate": 1025, "plugin_points_ticket": 1, "plugin_points_balance": 1})
p_dual = profil(f"points-forfait-dual-{SUF}", profil_tech_modele, "central", {"plugin_points_flatrate": 1031, "plugin_points_ticket": 1, "plugin_points_balance": 1})
p_client = profil(f"points-forfait-client-{SUF}", profil_ss, "helpdesk", {"ticketvalidation": 4096 + 8192})
console("cache:clear")


def nettoyer_profils():
    """Les profils de test et l'historique des forfaits ne restent pas : le socle compte les droits et l'historique du plugin."""
    ids = ",".join(str(p) for p in (p_tech, p_bu, p_dual, p_client))
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id IN ({ids});DELETE FROM glpi_profilerights WHERE profiles_id IN ({ids});"
               f"DELETE FROM glpi_profiles WHERE id IN ({ids});DELETE FROM glpi_logs WHERE itemtype = {FLAT};")
    console("cache:clear")


atexit.register(nettoyer_profils)


def utilisateur(nom, profil_id, entite_id):
    uid = creer(api, "User", {"name": nom, "password": MDP, "password2": MDP, "_profiles_id": int(profil_id), "_entities_id": entite_id,
                              "_is_recursive": 1, "is_active": 1})
    sql_ecrire(f"INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email) VALUES ({uid}, 1, 0, '{nom}@example.invalid');")
    return uid


tech_id = utilisateur(f"forfait-tech{SUF}", p_tech, entite)
bu_id = utilisateur(f"forfait-bu{SUF}", p_bu, entite)
dual_id = utilisateur(f"forfait-dual{SUF}", p_dual, entite)
client_id = utilisateur(f"forfait-client{SUF}", p_client, entite)
bu_autre_id = utilisateur(f"forfait-bu-autre{SUF}", p_bu, autre_entite)
tech, bu, dual, client = (banc.Api(f"{n}{SUF}", MDP) for n in ("forfait-tech", "forfait-bu", "forfait-dual", "forfait-client"))
w_tech, w_bu, w_dual, w_autre = (banc.Web(f"{n}{SUF}", MDP) for n in ("forfait-tech", "forfait-bu", "forfait-dual", "forfait-bu-autre"))
w_client = banc.Web(f"forfait-client{SUF}", MDP)

# notifications natives : activées par courriel (la file glpi_queuednotifications suffit : aucun courriel ne part du banc)
console("config:set", "use_notifications", "1")
console("config:set", "notifications_mailing", "1")

# --- contrats : A (enveloppe 40), D (mode « à la tâche »), H (petite enveloppe, dépassement interdit), tous à la grille Forfaits-std
creer_contrat = lambda nom: creer(api, "Contract", {"name": nom, "entities_id": entite, "contracttypes_id": type_points})
A, D, H, E = (creer_contrat(f"points-forfaits-{n}") for n in "ADHE")
for contrat, credit in ((A, 4000), (D, 4000), (H, 300), (E, 4000)):
    led("createEnvelope", contrat, "E", credit, d(-100), d(200), False, "")
    sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {contrat};")


def ticket(nom, contrat):
    return creer(api, "Ticket", {"name": nom, "content": nom, "entities_id": entite, "_contracts_id": contrat, "_users_id_requester": client_id})


def valider_ticket(tk):
    """Validation de ticket native liée au forfait courant : (id, statut, cible, commentaire de demande)."""
    r = sql(f"SELECT v.id, v.status, v.items_id_target, v.comment_submission FROM glpi_ticketvalidations v JOIN {T}flatrates f ON f.ticketvalidations_id = v.id "
            f"WHERE f.tickets_id = {tk} ORDER BY f.id DESC LIMIT 1")
    return r[0] if r else None


def chiffrer_soumettre(web, tk, montant="6", description="Migration du serveur"):
    poster(web, {"action": "create", "tickets_id": tk, "amount": montant, "description": description})
    return forfaits(tk)[-1][0] if forfaits(tk) else 0


v("(0) aucune erreur du plugin dans les journaux PHP et SQL avant le scénario", erreurs_plugin(), [])

# =====================================================================================================================
# (1) droits de la matrice : technicien sans VALIDATE refusé, témoin BU accepté (le forfait de l'exemple chiffré : 6 points)
# =====================================================================================================================
t1 = ticket("forfait : cycle complet", A)
v("(1) le contrat par défaut est lié au ticket", [r[0] for r in sql(f"SELECT contracts_id FROM glpi_tickets_contracts WHERE tickets_id = {t1}")], [str(A)])
code, page = onglet(w_tech, t1)
v("(1) onglet Points : section Forfait, formulaire de chiffrage et estimation proposés au technicien",
  (code, 'id="points-flatrate"' in page, 'id="points-flatrate-create"' in page, 'id="points-flatrate-estimate"' in page), (200, True, True, True))

# estimation d'aide : heures × coût « Ordre de travail » de la grille, à la date du jour
cout_ot = {1: 2, 2: 2, 3: 2, 4: 2, 5: 2, 6: 3, 7: 4}[AUJOURDHUI.isoweekday()]
poster(w_tech, {"action": "estimate", "tickets_id": t1, "hours": "3"})
code, page = w_tech.get(f"/front/ticket.form.php?id={t1}")
v("(1) estimation d'aide : 3 h × prix « Ordre de travail » du jour", f"3 hours = {3 * cout_ot}.00 points" in page, True)
v("(1) l'estimation n'écrit rien", forfaits(t1), [])

# montant refusé : zéro, négatif, texte
for mauvais in ("0", "-5", "abc", ""):
    poster(w_tech, {"action": "create", "tickets_id": t1, "amount": mauvais, "description": "x"})
    v(f"(1) montant « {mauvais} » refusé, rien créé", forfaits(t1), [])
# technicien sans droit de création : refusé (témoin : le technicien avec CREATE chiffre)
sql_ecrire(f"UPDATE glpi_profilerights SET rights = 1 WHERE name = 'plugin_points_flatrate' AND profiles_id = {p_tech};")
console("cache:clear")
w_tech = banc.Web(f"forfait-tech{SUF}", MDP)
code = poster(w_tech, {"action": "create", "tickets_id": t1, "amount": "6", "description": "sans droit"})
v("(1) technicien en lecture seule : chiffrage refusé", (code, forfaits(t1)), (403, []))
sql_ecrire(f"UPDATE glpi_profilerights SET rights = 7 WHERE name = 'plugin_points_flatrate' AND profiles_id = {p_tech};")
console("cache:clear")
w_tech = banc.Web(f"forfait-tech{SUF}", MDP)
f1 = chiffrer_soumettre(w_tech, t1, "5", "Migration du serveur")
v("(1) témoin : le technicien chiffre un brouillon de 5 (statut draft, contrat du ticket, technicien)",
  (forfaits(t1), sql(f"SELECT contracts_id, users_id, entities_id FROM {T}flatrates WHERE id = {f1}")), ([(f1, "draft", "5.00", "0.00")], [[str(A), tech_id.__str__(), str(entite)]]))
chiffrer_soumettre(w_tech, t1, "7", "second")
v("(1) un seul forfait non terminal par ticket : le second est refusé", len(forfaits(t1)), 1)

# brouillon modifiable (formulaire) ; montant figé ensuite
poster(w_tech, {"action": "update", "tickets_id": t1, "id": f1, "amount": "6", "description": "Migration du serveur"})
v("(1) témoin : brouillon modifié par le formulaire (5 → 6)", forfaits(t1), [(f1, "draft", "6.00", "0.00")])
ok_api, _ = essai(tech, "PUT", f"/{CLASSE}Flatrate/{f1}", {"input": {"status": "approved", "approved_amount": "99"}})
v("(1) API v1 : statut et montant approuvé jamais modifiables à la main", forfaits(t1), [(f1, "draft", "6.00", "0.00")])
ok_api, _ = essai(tech, "POST", f"/{CLASSE}Flatrate", {"input": {"tickets_id": t1, "amount": "1", "status": "approved"}})
v("(1) API v1 : second forfait refusé même par l'API", len(forfaits(t1)), 1)
ok_api, _ = essai(tech, "DELETE", f"/{CLASSE}Flatrate/{f1}?force_purge=true")
v("(1) API v1 : suppression d'un forfait refusée", forfaits(t1), [(f1, "draft", "6.00", "0.00")])

# tâches du ticket : pas décomptées tant qu'un forfait non refusé existe
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t1, "content": "tâche", "actiontime": 3600, "date": dernier(2) + " 09:00:00"}})
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t1, "content": "tâche sans lot valide", "actiontime": 900, "date": d(-500) + " 09:00:00"}})
v("(1) ticket au forfait : deux tâches acceptées (même sans point valide à la date de la deuxième : seules les règles du forfait comptent)",
  int(une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {t1}")), 2)

# notifications : soumission → le Responsable BU
code = poster(w_tech, {"action": "submit", "tickets_id": t1, "id": f1})
v("(1) soumis au Responsable BU", statut(t1), "submitted")
v("(1) notification flatrate_submitted en file pour les Responsables BU (VALIDATE) de l'entité, pas pour le BU d'une autre entité ni le technicien",
  sorted(r[0] for r in sql(f"SELECT recipient FROM glpi_queuednotifications WHERE itemtype = {FLAT} AND items_id = {f1}")),
  [f"forfait-bu{SUF}@example.invalid", f"forfait-dual{SUF}@example.invalid"])
v("(1) brouillon soumis : montant et description ne se modifient plus (formulaire)",
  (poster(w_tech, {"action": "update", "tickets_id": t1, "id": f1, "amount": "9", "description": "x"}), forfaits(t1)), (302, [(f1, "submitted", "6.00", "0.00")]))
ok_api, _ = essai(tech, "PUT", f"/{CLASSE}Flatrate/{f1}", {"input": {"amount": "9"}})
v("(1) soumis : montant non modifiable par l'API v1 non plus", forfaits(t1), [(f1, "submitted", "6.00", "0.00")])

# technicien sans VALIDATE : décision refusée ; BU d'une autre entité refusé ; auteur lui-même refusé (séparation)
v("(1) technicien (sans VALIDATE) : validation refusée", (poster(w_tech, {"action": "accept", "tickets_id": t1, "id": f1, "validator": client_id}), statut(t1)), (403, "submitted"))
v("(1) Responsable BU d'une autre entité : validation refusée", (poster(w_autre, {"action": "accept", "tickets_id": t1, "id": f1, "validator": client_id}), statut(t1)), (403, "submitted"))
v("(1) le Responsable BU ne peut pas chiffrer (pas de bit CREATE)", (poster(w_bu, {"action": "create", "tickets_id": t1, "amount": "3", "description": "x"}), len(forfaits(t1))), (403, 1))

# clôture refusée avec un forfait soumis (puis proposé)
ok_api, _ = essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(1) clôture refusée avec un forfait soumis : le ticket n'est pas clos", une(f"SELECT status FROM glpi_tickets WHERE id = {t1}") != "6", True)

# décision BU : approbateur hors du ticket et de l'entité refusé ; sans commentaire un refus est refusé
v("(1) approbateur sans lien avec le ticket ni l'entité : refusé",
  (poster(w_bu, {"action": "accept", "tickets_id": t1, "id": f1, "validator": bu_autre_id}), statut(t1)), (302, "submitted"))
code, page = onglet(w_bu, t1)
v("(1) approbateurs proposés : le client, ni le Responsable BU ni le technicien ni l'utilisateur à droits de forfait",
  (f'<option value="{client_id}"' in page, f'<option value="{bu_id}"' in page, f'<option value="{tech_id}"' in page, f'<option value="{dual_id}"' in page), (True, False, False, False))
for intrus, nom in ((bu_id, "le Responsable BU lui-même"), (tech_id, "l'auteur du forfait"), (dual_id, "un utilisateur ayant le droit de valider")):
    v(f"(1) approbateur client = {nom} : refusé", (poster(w_bu, {"action": "accept", "tickets_id": t1, "id": f1, "validator": intrus}), statut(t1), valider_ticket(t1)), (302, "submitted", None))
poster(w_bu, {"action": "refuse", "tickets_id": t1, "id": f1, "comment": ""})
v("(1) refus BU sans commentaire refusé", statut(t1), "submitted")
poster(w_bu, {"action": "accept", "tickets_id": t1, "id": f1, "validator": client_id, "comment": "OK pour la BU"})
v("(1) témoin : Responsable BU valide, forfait proposé au client", statut(t1), "proposed")
val = valider_ticket(t1)
v("(1) validation de ticket native créée pour le demandeur : en attente, montant et description dans la demande",
  (val[1], val[2], "6.00" in val[3], "Migration du serveur" in val[3]), ("2", str(client_id), True, True))
v("(1) forfait : validateur et validation mémorisés, décision BU", sql(f"SELECT users_id_validate, ticketvalidations_id, users_id_bu, bu_comment FROM {T}flatrates WHERE id = {f1}"),
  [[str(client_id), val[0], str(bu_id), "OK pour la BU"]])
ok_api, _ = essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(1) clôture refusée avec un forfait proposé : le ticket n'est pas clos", une(f"SELECT status FROM glpi_tickets WHERE id = {t1}") != "6", True)
ok_api, _ = essai(api, "DELETE", f"/TicketValidation/{val[0]}?force_purge=true")
v("(1) la validation d'un forfait proposé ne se supprime pas", une(f"SELECT COUNT(*) FROM glpi_ticketvalidations WHERE id = {val[0]}"), "1")
v("(1) le débit ne se fait pas avant l'approbation", (solde(A), debits_forfait(t1)), ("40.00", []))
poster(w_tech, {"action": "cancel", "tickets_id": t1, "id": f1 + 100000})
v("(1) forfait d'un autre ticket ou inconnu : refusé", statut(t1), "proposed")

# approbation par le client (API v1, droit natif de validation) ; l'approbation fige le montant
ok_api, texte = essai(client, "PUT", f"/TicketValidation/{val[0]}", {"input": {"status": 3}})
v("(1) le client approuve la validation native", ok_api, True)
v("(1) forfait approuvé, montant approuvé figé = montant chiffré", forfaits(t1), [(f1, "approved", "6.00", "6.00")])
v("(1) moment « à la clôture » : rien n'est débité à l'approbation", (solde(A), debits_forfait(t1)), ("40.00", []))
poster(w_tech, {"action": "update", "tickets_id": t1, "id": f1, "amount": "9", "description": "x"})
essai(tech, "PUT", f"/{CLASSE}Flatrate/{f1}", {"input": {"amount": "9"}})
essai(tech, "PUT", f"/{CLASSE}Flatrate/{f1}", {"input": {"approved_amount": "9"}})
essai(api, "PUT", f"/{CLASSE}Flatrate/{f1}", {"input": {"amount": "9", "approved_amount": "9", "status": "draft"}})
v("(1) montant figé après approbation : modification refusée par formulaire ET par l'API v1 (technicien et administrateur)",
  forfaits(t1), [(f1, "approved", "6.00", "6.00")])
v("(1) approuvé : annulation refusée (avant approbation seulement)",
  (poster(w_bu, {"action": "cancel", "tickets_id": t1, "id": f1}), statut(t1)), (302, "approved"))
ok_api, _ = essai(client, "PUT", f"/TicketValidation/{val[0]}", {"input": {"status": 4, "comment_validation": "finalement non"}})
v("(1) la réponse du client ne se change plus après l'approbation (le forfait n'attend plus sa réponse)", (statut(t1), une(f"SELECT status FROM glpi_ticketvalidations WHERE id = {val[0]}")), ("approved", "3"))
v("(1) approuvé mais pas encore débité : tâches non débitées", (debits_tache(t1), solde(A)), (0, "40.00"))

# clôture : débit du forfait, pas des tâches
essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(1) ticket clos", une(f"SELECT status FROM glpi_tickets WHERE id = {t1}"), "6")
v("(1) cycle complet : débit du forfait de 6 points (solde 34), lié au forfait, aucune tâche débitée",
  (solde(A), [(r[0], r[1], r[2]) for r in debits_forfait(t1)], debits_tache(t1)), ("34.00", [("-6.00", FLAT_BATCH, str(f1))], 0))
op = une(f"SELECT id FROM {T}operations WHERE tickets_id = {t1} AND type = 'debit_flatrate'")
v("(1) forfait débité, lié à son opération ; date métier = date d'approbation (fuseau de référence)",
  (forfaits(t1), sql(f"SELECT operations_id FROM {T}flatrates WHERE id = {f1}"), une(f"SELECT date FROM {T}operations WHERE id = {op}") == AUJOURDHUI.isoformat()),
  ([(f1, "debited", "6.00", "6.00")], [[op]], True))
v("(1) cache du contrat : solde, crédité, consommé", sql(f"SELECT balance, credited, consumed FROM {T}contracts WHERE contracts_id = {A}"), [["34.00", "40.00", "6.00"]])
v("(1) invariants du journal", ok(led("check", A)), [])
v("(1) le débit est définitif : un second débit direct du forfait est refusé (clé d'unicité)",
  led("debit", A, "debit_flatrate", 600, d(0), {"unique": True, "itemtype": r"GlpiPlugin\Points\Flatrate", "items_id": f1}).get("violation"), "already_debited")
v("(1) rouvrir et reclore le ticket ne redébite rien", (essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 2}})[0], essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})[0], solde(A), len(debits_forfait(t1))), (True, True, "34.00", 1))
code, page = onglet(w_tech, t1)
v("(1) onglet : forfait débité et historique des états", (code, 'data-status="debited"' in page, 'id="points-flatrate-history"' in page, "Submitted to the BU manager" in page), (200, True, True, True))
v("(1) historique : cinq changements d'état (création, soumission, proposition, approbation, débit)",
  int(une(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = {FLAT} AND items_id = {f1}")), 5)
v("(1) onglet : les tâches du ticket au forfait ne sont pas comptées", "Not counted" in page, True)
v("(1) ticket au forfait débité : un nouveau forfait est refusé", (poster(w_tech, {"action": "create", "tickets_id": t1, "amount": "3", "description": "x"}), len(forfaits(t1))), (302, 1))

# notifications : décision et réponse du client vers le technicien
v("(1) notifications flatrate_decided en file pour le technicien (proposition, approbation)",
  int(une(f"SELECT COUNT(*) FROM glpi_queuednotifications WHERE itemtype = {FLAT} AND items_id = {f1} AND recipient = 'forfait-tech{SUF}@example.invalid'")) >= 2, True)

# =====================================================================================================================
# (2) refus BU avec commentaire, retour en brouillon, correction ; refus client → retour au décompte des tâches
# =====================================================================================================================
t2 = ticket("forfait : refus et correction", A)
f2 = chiffrer_soumettre(w_tech, t2, "8", "Forfait trop haut")
poster(w_tech, {"action": "submit", "tickets_id": t2, "id": f2})
poster(w_bu, {"action": "refuse", "tickets_id": t2, "id": f2, "comment": "Trop cher"})
v("(2) refus du Responsable BU avec commentaire", (statut(t2), une(f"SELECT bu_comment FROM {T}flatrates WHERE id = {f2}"), valider_ticket(t2)), ("bu_refused", "Trop cher", None))
v("(2) notification de la décision au technicien", int(une(f"SELECT COUNT(*) FROM glpi_queuednotifications WHERE itemtype = {FLAT} AND items_id = {f2} AND recipient = 'forfait-tech{SUF}@example.invalid'")) >= 1, True)
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t2, "content": "tâche", "actiontime": 3600, "date": dernier(2) + " 09:00:00"}})
v("(2) forfait refusé par le BU : les tâches sont de nouveau décomptées (onglet : estimées)", "Estimated, not yet debited" in onglet(w_tech, t2)[1], True)
poster(w_tech, {"action": "update", "tickets_id": t2, "id": f2, "amount": "5", "description": "x"})
v("(2) refusé : montant non modifiable tant que le forfait n'est pas rouvert", forfaits(t2), [(f2, "bu_refused", "8.00", "0.00")])
poster(w_tech, {"action": "reopen", "tickets_id": t2, "id": f2})
v("(2) retour en brouillon pour correction", statut(t2), "draft")
poster(w_tech, {"action": "update", "tickets_id": t2, "id": f2, "amount": "5", "description": "Forfait corrigé"})
poster(w_tech, {"action": "submit", "tickets_id": t2, "id": f2})
poster(w_bu, {"action": "accept", "tickets_id": t2, "id": f2, "validator": client_id})
v("(2) corrigé, soumis à nouveau puis validé : proposé", forfaits(t2), [(f2, "proposed", "5.00", "0.00")])
val2 = valider_ticket(t2)
demande = une(f"SELECT comment_submission FROM glpi_ticketvalidations WHERE id = {val2[0]}")
essai(api, "PUT", f"/TicketValidation/{val2[0]}", {"input": {"comment_submission": "<p>Montant modifié en douce</p>"}})
v("(2) la demande d'une validation de forfait ne se modifie pas (API v1, administrateur)", une(f"SELECT comment_submission FROM glpi_ticketvalidations WHERE id = {val2[0]}"), demande)
ok_api, _ = essai(client, "PUT", f"/TicketValidation/{val2[0]}", {"input": {"status": 4}})
v("(2) refus du client sans motif : refusé par la validation native (motif obligatoire)", statut(t2), "proposed")
ok_api, _ = essai(client, "PUT", f"/TicketValidation/{val2[0]}", {"input": {"status": 4, "comment_validation": "Trop cher"}})
v("(2) le client refuse (motif donné) : forfait refusé par le client", (ok_api, statut(t2)), (True, "client_refused"))
v("(2) refus client : rien n'est débité, le ticket revient au décompte des tâches", (solde(A), debits_forfait(t2), debits_tache(t2)), ("34.00", [], 0))
essai(api, "PUT", f"/Ticket/{t2}", {"input": {"status": 6}})
v("(2) clôture après refus client : la tâche est débitée (retour au temps passé : 1 heure Assistance = 1 point)",
  (une(f"SELECT status FROM glpi_tickets WHERE id = {t2}"), debits_tache(t2), solde(A)), ("6", 1, "33.00"))
poster(w_tech, {"action": "create", "tickets_id": t2, "amount": "4", "description": "nouveau forfait"})
v("(2) après refus client avec une tâche débitée : un nouveau forfait est refusé (le temps passé est déjà facturé)", len(forfaits(t2)), 1)

# =====================================================================================================================
# (3) séparation des tâches, annulation, validation orpheline
# =====================================================================================================================
t3 = ticket("forfait : séparation et annulation", A)
f3 = chiffrer_soumettre(w_dual, t3, "4", "Chiffré par le Responsable BU lui-même")
poster(w_dual, {"action": "submit", "tickets_id": t3, "id": f3})
v("(3) le Responsable BU qui a chiffré le forfait ne peut pas le valider (séparation des tâches)",
  (poster(w_dual, {"action": "accept", "tickets_id": t3, "id": f3, "validator": client_id}), statut(t3)), (302, "submitted"))
code, page = onglet(w_dual, t3)
v("(3) onglet : message de séparation, pas de bouton de validation", ('id="points-flatrate-self"' in page, 'id="points-flatrate-decide"' in page), (True, False))
poster(w_bu, {"action": "accept", "tickets_id": t3, "id": f3, "validator": client_id})
v("(3) témoin : un autre Responsable BU valide le même forfait", statut(t3), "proposed")
val3 = valider_ticket(t3)
v("(3) global_validation du ticket : en attente tant que le forfait est proposé", une(f"SELECT global_validation FROM glpi_tickets WHERE id = {t3}"), "2")
poster(w_bu, {"action": "cancel", "tickets_id": t3, "id": f3})
v("(3) annulation avant approbation (Responsable BU)", statut(t3), "cancelled")
v("(3) annulation d'un forfait proposé : la validation en attente est purgée, le statut de validation du ticket redevient cohérent (aucune validation)",
  (une(f"SELECT COUNT(*) FROM glpi_ticketvalidations WHERE id = {val3[0]}"), une(f"SELECT global_validation FROM glpi_tickets WHERE id = {t3}")), ("0", "1"))
f3b = chiffrer_soumettre(w_tech, t3, "3", "Nouveau forfait après annulation")
v("(3) témoin : après annulation un nouveau forfait est possible", [x[1] for x in forfaits(t3)], ["cancelled", "draft"])
poster(w_tech, {"action": "cancel", "tickets_id": t3, "id": f3b})
v("(3) annulation d'un brouillon par le technicien", statut(t3), "cancelled")

# (3b) double facturation : un forfait refusé ne revient pas en brouillon si des tâches du ticket sont débitées (mode « à la clôture »)
t7 = ticket("forfait : refus, clôture, réouverture", E)
f7 = chiffrer_soumettre(w_tech, t7, "6", "Forfait refusé")
poster(w_tech, {"action": "submit", "tickets_id": t7, "id": f7})
poster(w_bu, {"action": "refuse", "tickets_id": t7, "id": f7, "comment": "Non"})
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t7, "content": "tâche", "actiontime": 3600, "date": dernier(2) + " 09:00:00"}})
essai(api, "PUT", f"/Ticket/{t7}", {"input": {"status": 6}})
v("(3b) forfait refusé : la clôture débite la tâche (retour au décompte)", (debits_tache(t7), statut(t7)), (1, "bu_refused"))
essai(api, "PUT", f"/Ticket/{t7}", {"input": {"status": 2}})
poster(w_tech, {"action": "reopen", "tickets_id": t7, "id": f7})
v("(3b) tâche débitée : le forfait refusé ne revient pas en brouillon (pas de double facturation)", (statut(t7), debits_tache(t7), solde(E)), ("bu_refused", 1, "39.00"))

# (3c) ticket résolu : ni création ni soumission
t9 = ticket("forfait : ticket résolu", A)
f9 = chiffrer_soumettre(w_tech, t9, "2", "Brouillon avant résolution")
essai(api, "PUT", f"/Ticket/{t9}", {"input": {"status": 5}})
v("(3c) témoin : ticket résolu", une(f"SELECT status FROM glpi_tickets WHERE id = {t9}"), "5")
poster(w_tech, {"action": "submit", "tickets_id": t9, "id": f9})
v("(3c) ticket résolu : soumission refusée", statut(t9), "draft")
poster(w_tech, {"action": "cancel", "tickets_id": t9, "id": f9})
poster(w_tech, {"action": "create", "tickets_id": t9, "amount": "3", "description": "x"})
v("(3c) ticket résolu : chiffrage refusé", [x[1] for x in forfaits(t9)], ["cancelled"])
essai(api, "PUT", f"/Ticket/{t9}", {"input": {"status": 2}})
poster(w_tech, {"action": "create", "tickets_id": t9, "amount": "3", "description": "x"})
v("(3c) témoin : ticket rouvert, chiffrage accepté", [x[1] for x in forfaits(t9)], ["cancelled", "draft"])

# (3d) séparation des tâches : réglage flatrate_separation (1 par défaut : refus ; 0 : permis)
config("flatrate_separation", "0")
t10 = ticket("forfait : séparation désactivée", A)
f10 = chiffrer_soumettre(w_dual, t10, "2", "Chiffré et validé par la même personne")
poster(w_dual, {"action": "submit", "tickets_id": t10, "id": f10})
code, page = onglet(w_dual, t10)
v("(3d) flatrate_separation = 0 : l'onglet propose la validation à l'auteur", ('id="points-flatrate-decide"' in page, 'id="points-flatrate-self"' in page), (True, False))
poster(w_dual, {"action": "accept", "tickets_id": t10, "id": f10, "validator": client_id})
v("(3d) flatrate_separation = 0 : l'auteur peut valider son forfait", statut(t10), "proposed")
config("flatrate_separation", "1")
t11 = ticket("forfait : séparation activée", A)
f11 = chiffrer_soumettre(w_dual, t11, "2", "Séparation active")
poster(w_dual, {"action": "submit", "tickets_id": t11, "id": f11})
poster(w_dual, {"action": "accept", "tickets_id": t11, "id": f11, "validator": client_id})
v("(3d) flatrate_separation = 1 : l'auteur ne peut pas valider son forfait", statut(t11), "submitted")

# =====================================================================================================================
# (4) double approbation / concurrence : un seul débit
# =====================================================================================================================
config("debit_moment", "task")
t4 = ticket("forfait : concurrence, débit à l'approbation", D)
f4 = chiffrer_soumettre(w_tech, t4, "6", "Forfait en mode tâche")
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t4, "content": "tâche", "actiontime": 3600, "date": dernier(2) + " 09:00:00"}})
v("(4) mode « à la tâche » : la tâche d'un ticket au forfait n'est pas débitée à l'ajout", (debits_tache(t4), solde(D)), (0, "40.00"))
poster(w_tech, {"action": "submit", "tickets_id": t4, "id": f4})
poster(w_bu, {"action": "accept", "tickets_id": t4, "id": f4, "validator": client_id})
val4 = valider_ticket(t4)
clients = [banc.Api(f"forfait-client{SUF}", MDP) for _ in range(3)]
sorties = []
fils = [threading.Thread(target=lambda c=c: sorties.append(essai(c, "PUT", f"/TicketValidation/{val4[0]}", {"input": {"status": 3}}))) for c in clients]
for f in fils:
    f.start()
for f in fils:
    f.join()
v("(4) trois approbations simultanées : un seul débit de forfait", (len(debits_forfait(t4)), solde(D), statut(t4)), (1, "34.00", "debited"))
v("(4) mode « à la tâche » : débit au moment de l'approbation (ticket encore ouvert)", une(f"SELECT status FROM glpi_tickets WHERE id = {t4}") != "6", True)
essai(api, "PUT", f"/Ticket/{t4}", {"input": {"status": 6}})
v("(4) clôture : ni nouveau débit de forfait ni débit de tâche", (len(debits_forfait(t4)), debits_tache(t4), solde(D)), (1, 0, "34.00"))
v("(4) invariants du journal du contrat D", ok(led("check", D)), [])

# débit direct simultané d'un même forfait approuvé (Flatrate::debit en parallèle)
t8 = ticket("forfait : refus puis tâche en mode tâche", E)
f8 = chiffrer_soumettre(w_tech, t8, "6", "Refusé")
poster(w_tech, {"action": "submit", "tickets_id": t8, "id": f8})
poster(w_bu, {"action": "refuse", "tickets_id": t8, "id": f8, "comment": "Non"})
essai(tech, "POST", "/TicketTask", {"input": {"tickets_id": t8, "content": "tâche", "actiontime": 3600, "date": dernier(2) + " 09:00:00"}})
v("(4) mode « à la tâche » : forfait refusé, la tâche est débitée à l'ajout", (debits_tache(t8), statut(t8)), (1, "bu_refused"))
poster(w_tech, {"action": "reopen", "tickets_id": t8, "id": f8})
v("(4) tâche débitée : le forfait refusé ne revient pas en brouillon", statut(t8), "bu_refused")

config("debit_moment", "closure")
t5 = ticket("forfait : débits directs simultanés", D)
f5 = chiffrer_soumettre(w_tech, t5, "2", "Débits simultanés")
poster(w_tech, {"action": "submit", "tickets_id": t5, "id": f5})
poster(w_bu, {"action": "accept", "tickets_id": t5, "id": f5, "validator": client_id})
essai(client, "PUT", f"/TicketValidation/{valider_ticket(t5)[0]}", {"input": {"status": 3}})
v("(4) témoin : forfait approuvé, pas encore débité (moment « à la clôture »)", (statut(t5), solde(D)), ("approved", "34.00"))
procs = [subprocess.Popen(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/glpi_cli.php"],
                          stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(4)]
for p in procs:
    p.stdin.write(json.dumps({"call": "flatrate_debit", "args": [f5]}))
    p.stdin.close()
rep = []
for p in procs:
    lignes_ = [l for l in p.stdout.read().splitlines() if l.startswith("{")]
    rep.append(json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie"})
    p.wait()
v("(4) quatre débits simultanés du même forfait : un seul débit au journal, les autres reconnaissent le débit", (len(debits_forfait(t5)), solde(D), statut(t5), {r.get('ok') for r in rep}), (1, "32.00", "debited", {int(une(f"SELECT operations_id FROM {T}flatrates WHERE id = {f5}"))}))
v("(4) invariants du journal du contrat D après concurrence", ok(led("check", D)), [])

# =====================================================================================================================
# (5) solde : dépassement interdit refusé à la validation BU (témoin : dépassement autorisé accepté) ; un seul contrat requis
# =====================================================================================================================
config("allow_overdraft", "0")
t6 = ticket("forfait : solde insuffisant", H)
f6 = chiffrer_soumettre(w_tech, t6, "6", "Plus que l'enveloppe de 3")
poster(w_tech, {"action": "submit", "tickets_id": t6, "id": f6})
poster(w_bu, {"action": "accept", "tickets_id": t6, "id": f6, "validator": client_id})
v("(5) dépassement interdit : validation BU refusée (solde de 3 points pour un forfait de 6), rien n'est créé", (statut(t6), valider_ticket(t6)), ("submitted", None))
config("allow_overdraft", "1")
poster(w_bu, {"action": "accept", "tickets_id": t6, "id": f6, "validator": client_id})
v("(5) témoin : dépassement autorisé, validation acceptée", statut(t6), "proposed")
config("allow_overdraft", "0")
ok_api, texte = essai(client, "PUT", f"/TicketValidation/{valider_ticket(t6)[0]}", {"input": {"status": 3}})
v("(5) l'acceptation du client est refusée si le débit est devenu impossible (dépassement interdit)", (statut(t6), valider_ticket(t6)[1]), ("proposed", "2"))
config("allow_overdraft", "1")
ok_api, _ = essai(client, "PUT", f"/TicketValidation/{valider_ticket(t6)[0]}", {"input": {"status": 3}})
essai(api, "PUT", f"/Ticket/{t6}", {"input": {"status": 6}})
v("(5) témoin : dépassement autorisé, approuvé puis débité à la clôture (solde négatif signalé)", (statut(t6), solde(H)), ("debited", "-3.00"))
t7 = creer(api, "Ticket", {"name": "forfait : ticket sans contrat points", "content": "x", "entities_id": entite})
poster(w_tech, {"action": "create", "tickets_id": t7, "amount": "2", "description": "sans contrat"})
v("(5) ticket sans contrat Points : chiffrage refusé", forfaits(t7), [])

# --- notifications : modèle utilisable (texte rendu dans la file, en français ou en anglais selon le destinataire)
corps = sql(f"SELECT body_text FROM glpi_queuednotifications WHERE itemtype = {FLAT} AND items_id = {f1} AND recipient = 'forfait-bu{SUF}@example.invalid' LIMIT 1")
v("(5) message de la notification : balises remplacées (montant, description)", (len(corps) == 1, "##" in (corps[0][0] if corps else "##"), "6.00" in (corps[0][0] if corps else "")), (True, False, True))

# =====================================================================================================================
# (5b) approbateur client sans le droit natif de répondre à une validation (ticketvalidation : VALIDATEREQUEST 4096 / VALIDATEINCIDENT 8192,
#      contrôlé par la liste native des approbateurs, src/CommonITILValidation.php:952 en 12.0.1) : il ne pourrait pas répondre ; refusé
#      comme approbateur, par défaut (demandeur) comme choisi, et absent de la liste. Témoin : le client avec le droit (sections précédentes).
# =====================================================================================================================
p_sans = profil(f"points-forfait-client-sans-{SUF}", profil_ss, "helpdesk", {"ticketvalidation": 0})
atexit.register(lambda: sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id = {p_sans};DELETE FROM glpi_profilerights WHERE profiles_id = {p_sans};"
                                   f"DELETE FROM glpi_profiles WHERE id = {p_sans};"))
console("cache:clear")
sans_id = utilisateur(f"forfait-client-sans{SUF}", p_sans, entite)
t8 = creer(api, "Ticket", {"name": "forfait : demandeur sans droit de validation", "content": "x", "entities_id": entite, "_contracts_id": A, "_users_id_requester": sans_id})
f8 = chiffrer_soumettre(w_tech, t8, "2", "Petit forfait")
poster(w_tech, {"action": "submit", "tickets_id": t8, "id": f8})
code, page = onglet(w_bu, t8)
v("(5b) liste des approbateurs : le demandeur sans droit de validation n'y est pas, le client avec le droit oui",
  (f'<option value="{sans_id}"' in page, f'<option value="{client_id}"' in page), (False, True))
poster(w_bu, {"action": "accept", "tickets_id": t8, "id": f8, "comment": "ok"})
v("(5b) approbateur par défaut (demandeur) sans droit de validation : refusé, aucune validation créée", (statut(t8), valider_ticket(t8)), ("submitted", None))
poster(w_bu, {"action": "accept", "tickets_id": t8, "id": f8, "validator": sans_id, "comment": "ok"})
v("(5b) même approbateur choisi explicitement : refusé", (statut(t8), valider_ticket(t8)), ("submitted", None))
poster(w_bu, {"action": "accept", "tickets_id": t8, "id": f8, "validator": client_id, "comment": "ok"})
v("(5b) témoin : approbateur avec le droit, forfait proposé", statut(t8), "proposed")

# --- invariants et journaux (la remise en l'état est faite par atexit : configuration, profils de test, historique)
for contrat in (A, D, H, E):
    v(f"(6) invariants du contrat {contrat}", ok(led("check", contrat)), [])
fautes = erreurs_plugin()
v("(6) aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : forfaits " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
