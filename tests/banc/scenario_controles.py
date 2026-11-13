"""Reviews et compensations (lot e) : review ouverte avec le débit d'une tâche hors créneau ou férié (même transaction),
confirmation, ajustement (+ remboursement plafonné, − débit en plus avec solde réel), une seule décision (séquentielle et
concurrente), demande de compensation (motif, montant, entité, ticket), décision (VALIDATE, séparation, plafond, crédit lié aux
lots du débit, notification au demandeur), droits par formulaire, API v1 et recherche native cloisonnée, débit définitif.
Part d'un plugin installé et activé (run.sh) ; crée ses entités, profils et utilisateurs ; remet la configuration comme il
l'a trouvée. Aucune opération synthétique : les débits viennent de vraies tâches de tickets clos."""
import atexit
import datetime
import json
import os
import re
import subprocess
import sys
import threading
import time
import urllib.parse
from decimal import Decimal
from zoneinfo import ZoneInfo

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
if os.environ.get("BANC_ARRET_AU_PREMIER_KO"):
    # Outil de mutation : s'arrêter au premier KO (les remises en l'état d'atexit s'exécutent)
    def _arret(verif):
        appel, vrai = verif.__call__, verif.vrai

        def a(nom, obtenu, attendu):
            appel(nom, obtenu, attendu)
            if not verif.ok:
                sys.exit(1)

        def b(nom, condition, detail=""):
            vrai(nom, condition, detail)
            if not verif.ok:
                sys.exit(1)
        return a, b
    _a, _b = _arret(v)
    v = type("VerifArret", (Verif,), {"__call__": lambda self, *x: _a(*x), "vrai": lambda self, *x: _b(*x)})()
T = "glpi_plugin_points_"
CLASSE = "GlpiPlugin%5CPoints%5C"
REV = r"'GlpiPlugin\\Points\\Review'"
COMP = r"'GlpiPlugin\\Points\\Compensation'"
COMP_BATCH = r"GlpiPlugin\\Points\\Compensation"  # sortie du client mariadb en mode batch : barres obliques doublées
MDP = "Controle-points-1"
SUF = format(int(datetime.datetime.now().timestamp()) % 100000, "x")  # noms uniques : le scénario se rejoue sur un banc gardé
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


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def poster(web, formulaire, champs):
    """POST d'un formulaire du plugin (en-tête de navigateur) ; renvoie le code HTTP."""
    return web.post(f"/plugins/points/front/{formulaire}.form.php", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})[0]


def onglet(web, tk):
    return web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                   + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={tk}")


def solde(contrat):
    return Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {contrat}"))


def n_ops(type_, **crit):
    where = " AND ".join([f"type = '{type_}'"] + [f"{k} = {c}" for k, c in crit.items()])
    return int(une(f"SELECT COUNT(*) FROM {T}operations WHERE {where}"))


def revue(rid):
    """(statut, raison, opération de débit, opération d'ajustement, relecteur, commentaire) d'une review."""
    r = sql(f"SELECT status, reason, operations_id, operations_id_adjustment, users_id, COALESCE(comment, '') FROM {T}reviews WHERE id = {rid}")[0]
    r += [""] * (6 - len(r))  # `sql` retire la tabulation finale d'un commentaire vide
    return r[0], r[1], int(r[2]), int(r[3]), int(r[4]), r[5]


def compensation(cid):
    """(statut, demandé, approuvé, opération de crédit, demandeur, décideur) d'une compensation."""
    r = sql(f"SELECT status, amount, approved_amount, operations_id_credit, users_id, users_id_decision FROM {T}compensations WHERE id = {cid}")[0]
    return r[0], Decimal(r[1]), Decimal(r[2]), int(r[3]), int(r[4]), int(r[5])


def logs(classe, ident):
    return int(une(f"SELECT COUNT(*) FROM glpi_logs WHERE itemtype = {classe} AND items_id = {ident}"))


# --- état d'origine, remis à la fin
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'")
          for c in ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id", "compensation_separation")}
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

# --- configuration du scénario : type de contrat, débit à la clôture, dépassement autorisé, pas de calendrier global
type_points = creer(api, "ContractType", {"name": f"points-controles-type-{SUF}"})
config("contracttypes", f"[{type_points}]")
config("debit_moment", "closure")
config("allow_overdraft", "1")
config("calendars_id", "0")
config("compensation_separation", "1")
entite = creer(api, "Entity", {"name": f"points-controles-{SUF}", "entities_id": 0})
autre_entite = creer(api, "Entity", {"name": f"points-controles-autre-{SUF}", "entities_id": 0})
entite_cal = creer(api, "Entity", {"name": f"points-controles-cal-{SUF}", "entities_id": 0})
api = banc.Api()
entite_fille = creer(api, "Entity", {"name": f"points-controles-fille-{SUF}", "entities_id": entite})
api = banc.Api()

# calendrier natif : lundi à vendredi 08:00-18:00, un jour férié (le dernier mercredi), pour l'entité « cal »
mardi, samedi, dimanche, mercredi = dernier(2), dernier(6), dernier(7), dernier(3)
cal = creer(api, "Calendar", {"name": f"points-controles-cal-{SUF}", "entities_id": 0, "is_recursive": 1})
for jour in range(1, 6):
    creer(api, "CalendarSegment", {"calendars_id": cal, "day": jour, "begin": "08:00:00", "end": "18:00:00", "entities_id": 0, "is_recursive": 1})
ferie = creer(api, "Holiday", {"name": f"points-controles-ferie-{SUF}", "begin_date": mercredi, "end_date": mercredi, "is_perpetual": 0, "entities_id": 0, "is_recursive": 1})
creer(api, "Calendar_Holiday", {"calendars_id": cal, "holidays_id": ferie, "entities_id": 0, "is_recursive": 1})
api._req("PUT", f"/Entity/{entite_cal}", {"input": {"calendars_strategy": 0, "calendars_id": cal}})

# --- grille (Assistance 1 / 1,5 / 2 par heure : semaine, samedi, dimanche et férié)
sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Controles-std-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Controles-std-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for jour, cout in enumerate((1, 1.5, 2), start=1):
    sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
               f"VALUES (0, 1, {version}, {ASSIST}, {jour}, {cout});")

# --- profils : ADV qui demande et contrôle (review 3, compensation 5), qui décide (1025, review en lecture), les deux (1029, review 3),
# technicien sans aucun des deux droits, client de l'interface simplifiée
profil_tech_modele = une("SELECT id FROM glpi_profiles WHERE name = 'Technician'")
profil_ss = une("SELECT id FROM glpi_profiles WHERE name = 'Self-Service'")


def profil(nom, modele, interface, droits):
    pid = creer(api, "Profile", {"name": nom, "interface": interface})
    sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {pid}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele} "
               "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    for droit, valeur in droits.items():
        sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({pid}, '{droit}', {valeur}) ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    return pid


p_req = profil(f"points-ctl-req-{SUF}", profil_tech_modele, "central", {"plugin_points_review": 3, "plugin_points_compensation": 5, "plugin_points_balance": 1, "plugin_points_ticket": 1})
p_dec = profil(f"points-ctl-dec-{SUF}", profil_tech_modele, "central", {"plugin_points_review": 1, "plugin_points_compensation": 1025, "plugin_points_balance": 1, "plugin_points_ticket": 1})
p_dual = profil(f"points-ctl-dual-{SUF}", profil_tech_modele, "central", {"plugin_points_review": 3, "plugin_points_compensation": 1029, "plugin_points_balance": 1, "plugin_points_ticket": 1})
p_none = profil(f"points-ctl-none-{SUF}", profil_tech_modele, "central", {"plugin_points_review": 0, "plugin_points_compensation": 0, "plugin_points_balance": 1, "plugin_points_ticket": 1})
p_client = profil(f"points-ctl-client-{SUF}", profil_ss, "helpdesk", {})
console("cache:clear")


def nettoyer():
    """Profils de test, historique et file de notifications des contrôles ne restent pas : le socle compte l'historique du plugin."""
    ids = ",".join(str(p) for p in (p_req, p_dec, p_dual, p_none, p_client))
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id IN ({ids});DELETE FROM glpi_profilerights WHERE profiles_id IN ({ids});"
               f"DELETE FROM glpi_profiles WHERE id IN ({ids});DELETE FROM glpi_logs WHERE itemtype IN ({REV}, {COMP});"
               f"DELETE FROM glpi_queuednotifications WHERE itemtype = {COMP};")
    console("cache:clear")


atexit.register(nettoyer)


def utilisateur(nom, profil_id, entite_id):
    uid = creer(api, "User", {"name": nom, "password": MDP, "password2": MDP, "_profiles_id": int(profil_id), "_entities_id": entite_id,
                              "_is_recursive": 1, "is_active": 1})
    sql_ecrire(f"INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email) VALUES ({uid}, 1, 0, '{nom}@example.invalid');")
    return uid


NOMS = {"req": (p_req, entite), "dec": (p_dec, entite), "dual": (p_dual, entite), "none": (p_none, entite), "client": (p_client, entite), "autre": (p_dual, autre_entite), "fille": (p_req, entite_fille)}
uid = {k: utilisateur(f"ctl-{k}{SUF}", p, e) for k, (p, e) in NOMS.items()}
web = {k: banc.Web(f"ctl-{k}{SUF}", MDP) for k in NOMS}
web["dual_b"] = banc.Web(f"ctl-dual{SUF}", MDP)  # seconde session du même compte, pour les requêtes simultanées
cli_api = {k: banc.Api(f"ctl-{k}{SUF}", MDP) for k in ("req", "dec", "dual", "autre", "fille")}
console("config:set", "use_notifications", "1")
console("config:set", "notifications_mailing", "1")

# --- contrats : A (100 points), H (2 points exactement), C (entité à calendrier)
type_contrat = lambda nom, ent: creer(api, "Contract", {"name": nom, "entities_id": ent, "contracttypes_id": type_points})
R = creer(api, "Contract", {"name": f"points-controles-R-{SUF}", "entities_id": entite, "is_recursive": 1, "contracttypes_id": type_points})
A, H, C = type_contrat(f"points-controles-A-{SUF}", entite), type_contrat(f"points-controles-H-{SUF}", entite), type_contrat(f"points-controles-C-{SUF}", entite_cal)
env = {}
for contrat, credit in ((A, 10000), (H, 200), (C, 10000), (R, 10000)):
    env[contrat] = ok(led("createEnvelope", contrat, "E", credit, d(-100), d(200), False, ""))["envelopes_id"]
    sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {contrat};")


def ticket(nom, contrat, ent=None):
    return creer(api, "Ticket", {"name": nom, "content": nom, "entities_id": ent or entite, "_contracts_id": contrat})


def tache_close(contrat, jour, heures=1, ent=None, nom="tâche", **plus):
    """Ticket avec une tâche de `heures` h à la date `jour`, clos : (ticket, opération de débit, identifiant de review ou 0)."""
    tk = ticket(nom, contrat, ent)
    essai(api, "POST", "/TicketTask", {"input": {"tickets_id": tk, "content": nom, "actiontime": 3600 * heures, "date": jour + " 09:00:00", **plus}})
    essai(api, "PUT", f"/Ticket/{tk}", {"input": {"status": 6}})
    op = sql(f"SELECT id FROM {T}operations WHERE tickets_id = {tk} AND type = 'debit_task' ORDER BY id")
    rv = sql(f"SELECT id FROM {T}reviews WHERE tickets_id = {tk} ORDER BY id")
    return tk, int(op[0][0]) if op else 0, int(rv[0][0]) if rv else 0


v("(0) aucune erreur du plugin dans les journaux PHP et SQL avant le scénario", erreurs_plugin(), [])

# =====================================================================================================================
# (1) review ouverte avec le débit d'une tâche hors créneau ou férié, unique, sans effet sur un débit ordinaire
# =====================================================================================================================
t1 = ticket("contrôle : dimanche et semaine", A)
for jour, nom in ((dimanche, "dimanche"), (mardi, "semaine")):
    essai(api, "POST", "/TicketTask", {"input": {"tickets_id": t1, "content": nom, "actiontime": 3600, "date": jour + " 09:00:00"}})
v("(1) avant la clôture : aucun débit, aucune review", (n_ops("debit_task", tickets_id=t1), int(une(f"SELECT COUNT(*) FROM {T}reviews WHERE tickets_id = {t1}"))), (0, 0))
avant = solde(A)
essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
ops1 = {r[1]: r[0] for r in sql(f"SELECT id, amount FROM {T}operations WHERE tickets_id = {t1} AND type = 'debit_task' ORDER BY id")}
v("(1) clôture : débit de 2 (dimanche) et de 1 (semaine)", (sorted(ops1), solde(A)), (["-1.00", "-2.00"], avant - 3))
r1 = sql(f"SELECT id, status, reason, operations_id, tickets_id, contracts_id, entities_id, users_id, operations_id_adjustment FROM {T}reviews WHERE tickets_id = {t1}")
v("(1) une seule review, pour le débit du dimanche : à contrôler, raison sunday, ticket, contrat et entité du débit",
  r1, [[r1[0][0], "to_review", "sunday", ops1["-2.00"], str(t1), str(A), str(entite), "0", "0"]] if r1 else [])
rv1 = int(r1[0][0])
v("(1) le débit de semaine n'a pas de review (témoin)", int(une(f"SELECT COUNT(*) FROM {T}reviews WHERE operations_id = {ops1['-1.00']}")), 0)
v("(1) historique natif : création de la review", logs(REV, rv1), 1)
essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 2}})
essai(api, "PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
v("(1) rouvrir et reclore ne débite ni ne contrôle rien de plus", (n_ops("debit_task", tickets_id=t1), int(une(f"SELECT COUNT(*) FROM {T}reviews WHERE tickets_id = {t1}")), solde(A)), (2, 1, avant - 3))

t_sam, op_sam, rv_sam = tache_close(A, samedi, nom="samedi")
v("(1) tâche du samedi : débit 1,5 et review raison saturday", (une(f"SELECT amount FROM {T}operations WHERE id = {op_sam}"), revue(rv_sam)[:2]), ("-1.50", ("to_review", "saturday")))

# calendrier natif : férié (type de jour 3), hors créneau (begin planifié) ; témoin dans les créneaux
t_f, op_f, rv_f = tache_close(C, mercredi, ent=entite_cal, nom="férié")
v("(1) jour férié du calendrier : débit 2, review raison holiday", (une(f"SELECT amount FROM {T}operations WHERE id = {op_f}"), revue(rv_f)[:2] if rv_f else None), ("-2.00", ("to_review", "holiday")))
t_h, op_h, rv_h = tache_close(C, mardi, ent=entite_cal, nom="hors créneau", begin=mardi + " 22:00:00", end=mardi + " 22:30:00")
v("(1) tâche planifiée hors des créneaux : review raison off_hours", revue(rv_h)[:2] if rv_h else None, ("to_review", "off_hours"))
t_ok, op_ok, rv_ok = tache_close(C, mardi, ent=entite_cal, nom="dans les créneaux", begin=mardi + " 10:00:00", end=mardi + " 10:30:00")
v("(1) témoin : tâche dans les créneaux, jour ouvré : débitée sans review", (op_ok > 0, rv_ok), (True, 0))

# --- contrat récursif de l'entité mère, ticket d'une sous-entité : la review suit l'entité du CONTRAT, pas celle du ticket
tf_, opf_, rvf = tache_close(R, dimanche, ent=entite_fille, nom="contrat de l'entité mère")
v("(1) contrat récursif de l'entité mère, ticket de la sous-entité : la review et le débit portent l'entité du contrat",
  (sql(f"SELECT entities_id FROM {T}reviews WHERE id = {rvf}")[0][0], sql(f"SELECT entities_id FROM {T}operations WHERE id = {opf_}")[0][0], sql(f"SELECT entities_id FROM glpi_tickets WHERE id = {tf_}")[0][0]),
  (str(entite), str(entite), str(entite_fille)))
v("(1) le technicien limité à la sous-entité ne confirme ni n'ajuste cette review (entité du contrat hors de ses droits)",
  (poster(web["fille"], "review", {"action": "confirm", "id": rvf}), poster(web["fille"], "review", {"action": "adjust", "id": rvf, "amount": "0.5", "comment": "x"}), revue(rvf)[0]), (403, 403, "to_review"))
code, page = onglet(web["fille"], tf_)
v("(1) onglet du technicien de la sous-entité : ni review ni formulaire de demande de compensation (débit d'une entité hors de ses droits)",
  (f'data-review="{rvf}"' in page, 'id="points-compensation-request"' in page), (False, False))
v("(1) le technicien de la sous-entité demande une compensation sur ce débit : refusé",
  (poster(web["fille"], "compensation", {"action": "request", "operations_id": opf_, "amount": "0.5", "comment": "Motif"}), int(une(f"SELECT COUNT(*) FROM {T}compensations WHERE operations_id = {opf_}"))), (403, 0))
code, page = onglet(web["dual"], tf_)
v("(1) témoin : l'ADV de l'entité du contrat voit la review et sa décision", (f'data-review="{rvf}"' in page, f'id="points-review-form-{rvf}"' in page), (True, True))

# =====================================================================================================================
# (2) confirmation ; droits ; une review ne se décide qu'une fois
# =====================================================================================================================
t2, op2, rv2 = tache_close(A, dimanche, nom="à confirmer")
for qui, attendu in (("none", 403), ("dec", 403), ("client", 403), ("autre", 403)):
    v(f"(2) {qui} : confirmation refusée ({'sans droit UPDATE' if qui != 'autre' else 'entité étrangère'})",
      (poster(web[qui], "review", {"action": "confirm", "id": rv2}), revue(rv2)[0]), (attendu, "to_review"))
v("(2) review inconnue : introuvable", poster(web["dual"], "review", {"action": "confirm", "id": 99999999}), 404)
v("(2) action inconnue : requête refusée", poster(web["dual"], "review", {"action": "x", "id": rv2}), 400)
v("(2) témoin : review 3 (ADV) confirme", (poster(web["dual"], "review", {"action": "confirm", "id": rv2, "comment": "conforme"}), revue(rv2)),
  (302, ("confirmed", "sunday", op2, 0, uid["dual"], "conforme")))
solde_confirme = solde(A)
v("(2) confirmer n'écrit rien au journal", n_ops("review_adjustment", items_id=rv2), 0)
v("(2) historique natif : création puis confirmation", logs(REV, rv2), 2)
poster(web["req"], "review", {"action": "confirm", "id": rv2})
poster(web["req"], "review", {"action": "adjust", "id": rv2, "amount": "0.5", "comment": "tard"})
v("(2) double décision : confirmer puis confirmer ou ajuster est refusé, rien n'est écrit",
  (revue(rv2)[0], n_ops("review_adjustment", items_id=rv2), solde(A), logs(REV, rv2)), ("confirmed", 0, solde_confirme, 2))

# =====================================================================================================================
# (3) ajustement : − débit en plus (solde réel), + remboursement plafonné au débit
# =====================================================================================================================
t3, op3, rv3 = tache_close(A, dimanche, nom="ajustement moins")
avant = solde(A)
for amount, comment, nom in (("-0.5", "", "sans motif"), ("-0.5", "   ", "motif blanc"), ("0", "x", "montant nul"), ("abc", "x", "montant illisible"), ("", "x", "montant vide")):
    poster(web["dual"], "review", {"action": "adjust", "id": rv3, "amount": amount, "comment": comment})
    v(f"(3) ajustement {nom} : refusé, rien n'est écrit", (revue(rv3)[0], n_ops("review_adjustment", items_id=rv3), solde(A)), ("to_review", 0, avant))
for qui in ("dec", "none", "autre"):
    v(f"(3) {qui} : ajustement refusé", (poster(web[qui], "review", {"action": "adjust", "id": rv3, "amount": "-0.5", "comment": "x"}), revue(rv3)[0], solde(A)), (403, "to_review", avant))
poster(web["dual"], "review", {"action": "adjust", "id": rv3, "amount": "-0.5", "comment": "Intervention plus longue que saisie"})
adj = sql(f"SELECT id, amount, operations_id_origin, itemtype, items_id, tickets_id, comment, date FROM {T}operations WHERE type = 'review_adjustment' AND items_id = {rv3}")
v("(3) ajustement − 0,5 avec motif : une opération review_adjustment liée au débit, à la date du débit, débit supplémentaire",
  [r[1:] for r in adj], [["-0.50", str(op3), r"GlpiPlugin\\Points\\Review", str(rv3), str(t3), "Intervention plus longue que saisie", dimanche]])
v("(3) review ajustée : opération d'ajustement, relecteur, motif ; solde diminué de 0,5",
  (revue(rv3), solde(A)), (("adjusted", "sunday", op3, int(adj[0][0]), uid["dual"], "Intervention plus longue que saisie"), avant - Decimal("0.5")))
v("(3) l'ajustement répartit son montant sur les lots du contrat (somme des affectations = montant)",
  une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}allocations WHERE operations_id = {adj[0][0]}"), "-0.50")
v("(3) invariants du journal après ajustement −", ok(led("check", A)), [])

t4, op4, rv4 = tache_close(A, dimanche, nom="ajustement plus")
avant = solde(A)
poster(web["dual"], "review", {"action": "adjust", "id": rv4, "amount": "3", "comment": "plus que le débit"})
v("(3) ajustement + supérieur au débit (3 > 2) : refusé, rien n'est écrit", (revue(rv4)[0], n_ops("review_adjustment", items_id=rv4), solde(A)), ("to_review", 0, avant))
poster(web["dual"], "review", {"action": "adjust", "id": rv4, "amount": "0,5", "comment": "Durée facturée trop haute"})
adj4 = sql(f"SELECT id, amount, operations_id_origin FROM {T}operations WHERE type = 'review_adjustment' AND items_id = {rv4}")
v("(3) ajustement + 0,5 : remboursement lié au débit, solde remonté de 0,5", ([r[1:] for r in adj4], solde(A), revue(rv4)[0]), ([["0.50", str(op4)]], avant + Decimal("0.5"), "adjusted"))
lots_debit = {r[0] for r in sql(f"SELECT plugin_points_lots_id FROM {T}allocations WHERE operations_id = {op4}")}
lots_adj = {r[0] for r in sql(f"SELECT plugin_points_lots_id FROM {T}allocations WHERE operations_id = {adj4[0][0]}")}
v("(3) le remboursement est rendu aux lots du débit d'origine", (lots_adj <= lots_debit, len(lots_adj) > 0), (True, True))
v("(3) invariants du journal après ajustement +", ok(led("check", A)), [])

# ajustement − sans solde réel : le débit du contrat H a vidé son enveloppe ; le dépassement est autorisé (défaut) mais jamais pour un ajustement
t5, op5, rv5 = tache_close(H, dimanche, nom="contrat vide")
v("(3) contrat H : solde nul après le débit de 2", solde(H), Decimal("0"))
poster(web["dual"], "review", {"action": "adjust", "id": rv5, "amount": "-0.5", "comment": "Durée sous-estimée"})
v("(3) ajustement − sans solde suffisant : refusé malgré allow_overdraft = 1, rien n'est écrit", (revue(rv5)[0], n_ops("review_adjustment", items_id=rv5), solde(H)), ("to_review", 0, Decimal("0")))
ok(led("credit", H, env[H], 500, {"origin_date": d(-90)}))
poster(web["dual"], "review", {"action": "adjust", "id": rv5, "amount": "-0.5", "comment": "Durée sous-estimée"})
v("(3) témoin : après un crédit de 5, le même ajustement − passe", (revue(rv5)[0], solde(H)), ("adjusted", Decimal("4.5")))
v("(3) invariants du journal du contrat H", ok(led("check", H)), [])

# =====================================================================================================================
# (4) une seule décision, aussi en concurrence
# =====================================================================================================================
def en_parallele(actions):
    """actions : liste de (web, formulaire, champs) lancées ensemble ; renvoie les codes HTTP."""
    codes = [None] * len(actions)

    def lancer(i, w, form, champs):
        codes[i] = poster(w, form, champs)
    fils = [threading.Thread(target=lancer, args=(i, *a)) for i, a in enumerate(actions)]
    for f in fils:
        f.start()
    for f in fils:
        f.join()
    return codes


t6, op6, rv6 = tache_close(A, dimanche, nom="concurrence ajustements")
avant = solde(A)
en_parallele([(web["dual"], "review", {"action": "adjust", "id": rv6, "amount": "0.5", "comment": "a"}),
              (web["req"], "review", {"action": "adjust", "id": rv6, "amount": "0.5", "comment": "b"}),
              (web["dual_b"], "review", {"action": "adjust", "id": rv6, "amount": "-0.25", "comment": "c"})])
v("(4) trois ajustements simultanés de la même review : une seule opération, une seule décision",
  (n_ops("review_adjustment", items_id=rv6), revue(rv6)[0], logs(REV, rv6)), (1, "adjusted", 2))
v("(4) le solde ne bouge que d'un ajustement", solde(A) in (avant + Decimal("0.5"), avant - Decimal("0.25")), True)
t7, op7, rv7 = tache_close(A, dimanche, nom="concurrence confirmation et ajustement")
en_parallele([(web["dual"], "review", {"action": "confirm", "id": rv7}), (web["req"], "review", {"action": "adjust", "id": rv7, "amount": "0.5", "comment": "d"})])
st7, n7 = revue(rv7)[0], n_ops("review_adjustment", items_id=rv7)
v("(4) confirmation et ajustement simultanés : une seule décision cohérente (ajusté avec son opération, ou confirmé sans opération)", (st7, n7) in (("adjusted", 1), ("confirmed", 0)), True)
v("(4) invariants du journal après concurrence", ok(led("check", A)), [])


def conflit_instantane(table, ident, decision, attente=6):
    """Décision concurrente validée ENTRE la lecture et l'écriture de l'état par la transaction du journal : une session SQL tient
    la ligne (`status` changé, non validé) pendant `attente` s ; la requête de décision lit, puis bute sur la ligne ; à la validation de
    l'autre session, MariaDB 11.x (isolation par instantané) répond « Record has changed since last read » (1020) au lieu de « 0 ligne »."""
    verrou = threading.Thread(target=lambda: sql_ecrire(f"BEGIN; UPDATE {T}{table} SET status = '{decision}' WHERE id = {ident}; DO SLEEP({attente}); COMMIT;"))
    verrou.start()
    time.sleep(2)
    return verrou


t7b, op7b, rv7b = tache_close(A, dimanche, nom="conflit d'instantané")
avant = solde(A)
verrou = conflit_instantane("reviews", rv7b, "confirmed")
code = poster(web["dual"], "review", {"action": "adjust", "id": rv7b, "amount": "0.5", "comment": "décision perdue"})
verrou.join()
v("(4) décision perdue au conflit d'instantané (1020) : réponse normale (pas d'erreur 500), l'autre décision reste, rien n'est écrit au journal",
  (code, revue(rv7b)[0], n_ops("review_adjustment", items_id=rv7b), solde(A)), (302, "confirmed", 0, avant))

# =====================================================================================================================
# (5) compensation : demande
# =====================================================================================================================
tc, opc, _ = tache_close(A, mardi, heures=2, nom="compensation")  # débit de 2 points
v("(5) débit de la tâche de 2 h de semaine : 2 points, sans review", (une(f"SELECT amount FROM {T}operations WHERE id = {opc}"), _), ("-2.00", 0))
credit_op = int(une(f"SELECT id FROM {T}operations WHERE contracts_id = {A} AND type = 'credit' LIMIT 1"))


def demande(qui, op, montant, motif):
    return poster(web[qui], "compensation", {"action": "request", "operations_id": op, "amount": montant, "comment": motif})


def nb_comp(**crit):
    return int(une(f"SELECT COUNT(*) FROM {T}compensations WHERE " + " AND ".join(f"{k} = {c}" for k, c in crit.items())))


n0 = nb_comp(operations_id=opc)
for montant, motif, nom in (("1", "", "sans motif"), ("1", "  \n ", "motif blanc"), ("0", "x", "montant nul"), ("-1", "x", "montant négatif"), ("abc", "x", "montant illisible"),
                           ("2.01", "plus que le débit", "supérieure au débit (2,01 > 2)")):
    demande("req", opc, montant, motif)
    v(f"(5) demande {nom} : refusée, rien n'est créé", nb_comp(operations_id=opc), n0)
v("(5) demande sur un crédit (sans ticket lisible) : refusée", (demande("req", credit_op, "1", "x"), nb_comp(operations_id=credit_op)), (403, 0))
v("(5) demande sur une opération qui n'est pas un débit de tâche ou de forfait (ajustement de review) : refusée", (demande("req", int(adj[0][0]), "0.1", "x"), nb_comp(operations_id=int(adj[0][0]))), (302, 0))
v("(5) demande sur une opération inconnue : refusée", demande("req", 99999999, "1", "x"), 403)
for qui in ("dec", "none", "client", "autre"):
    v(f"(5) {qui} : demande refusée ({'entité étrangère' if qui == 'autre' else 'sans droit CREATE ou sans droit du tout'})", (demande(qui, opc, "1", "Motif"), nb_comp(operations_id=opc)), (403, n0))
v("(5) témoin : ADV (CREATE, entité, ticket lisible) demande 1 point avec motif", (demande("req", opc, "1", "Intervention reprise, panne non résolue"), nb_comp(operations_id=opc)), (302, n0 + 1))
c1 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opc}"))
v("(5) demande : en attente, ticket, contrat, entité du débit, demandeur, motif, rien d'approuvé", compensation(c1) + tuple(sql(f"SELECT tickets_id, contracts_id, entities_id, comment FROM {T}compensations WHERE id = {c1}")[0]),
  ("pending", Decimal("1"), Decimal("0"), 0, uid["req"], 0, str(tc), str(A), str(entite), "Intervention reprise, panne non résolue"))
v("(5) historique natif : création de la demande", logs(COMP, c1), 1)
v("(5) témoin : une demande égale au débit (2) est acceptée", (demande("req", opc, "2", "Reprise complète"), nb_comp(operations_id=opc)), (302, n0 + 2))
c_full = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opc}"))

# =====================================================================================================================
# (6) compensation : décision
# =====================================================================================================================
avant = solde(A)
for qui, attendu in (("req", 403), ("none", 403), ("client", 403), ("autre", 403)):
    v(f"(6) {qui} : approbation refusée ({'sans VALIDATE' if qui == 'req' else 'sans droit ou entité étrangère'})",
      (poster(web[qui], "compensation", {"action": "approve", "id": c1}), compensation(c1)[0], solde(A)), (attendu, "pending", avant))
v("(6) rejet refusé aussi sans VALIDATE", (poster(web["req"], "compensation", {"action": "reject", "id": c1, "comment": "non"}), compensation(c1)[0]), (403, "pending"))
poster(web["dec"], "compensation", {"action": "approve", "id": c1, "approved": "1.5", "comment": "trop"})
v("(6) montant approuvé supérieur au montant demandé (1,5 > 1) : refusé", (compensation(c1)[0], solde(A)), ("pending", avant))
poster(web["dec"], "compensation", {"action": "approve", "id": c1, "approved": "0", "comment": "nul"})
poster(web["dec"], "compensation", {"action": "approve", "id": c1, "approved": "abc", "comment": "illisible"})
v("(6) montant approuvé nul ou illisible : refusé", (compensation(c1)[0], solde(A)), ("pending", avant))
v("(6) décision sur une demande inconnue : introuvable", poster(web["dec"], "compensation", {"action": "approve", "id": 99999999}), 404)
code = poster(web["dec"], "compensation", {"action": "approve", "id": c1, "approved": "0,75", "comment": "Geste commercial accordé"})
cr = sql(f"SELECT id, type, amount, operations_id_origin, itemtype, items_id, tickets_id, comment, date FROM {T}operations WHERE type = 'compensation' AND items_id = {c1}")
v("(6) témoin : VALIDATE approuve 0,75 : crédit `compensation` lié au débit et à la demande, à la date du jour",
  (code, [r[1:] for r in cr]), (302, [["compensation", "0.75", str(opc), COMP_BATCH, str(c1), str(tc), "Geste commercial accordé", AUJOURDHUI.isoformat()]]))
v("(6) demande approuvée : montant figé, décideur, crédit lié, commentaire de décision",
  compensation(c1) + tuple(sql(f"SELECT decision_comment, date_decision IS NOT NULL FROM {T}compensations WHERE id = {c1}")[0]),
  ("approved", Decimal("1"), Decimal("0.75"), int(cr[0][0]), uid["req"], uid["dec"], "Geste commercial accordé", "1"))
v("(6) le solde remonte de 0,75 ; la consommation du contrat baisse d'autant", (solde(A), sql(f"SELECT balance FROM {T}contracts WHERE contracts_id = {A}")[0][0]), (avant + Decimal("0.75"), str(avant + Decimal("0.75"))))
lots_debit = {r[0] for r in sql(f"SELECT plugin_points_lots_id FROM {T}allocations WHERE operations_id = {opc}")}
lots_cr = sql(f"SELECT plugin_points_lots_id, amount FROM {T}allocations WHERE operations_id = {cr[0][0]}")
v("(6) le crédit est réaffecté aux lots du débit d'origine, pour 0,75 en tout", ({r[0] for r in lots_cr} <= lots_debit, sum(Decimal(r[1]) for r in lots_cr)), (True, Decimal("0.75")))
v("(6) historique natif : création puis approbation", logs(COMP, c1), 2)
n_cr = n_ops("compensation", items_id=c1)
poster(web["dec"], "compensation", {"action": "approve", "id": c1, "approved": "0.25"})
poster(web["dec"], "compensation", {"action": "reject", "id": c1, "comment": "finalement non"})
v("(6) double décision : approuver ou rejeter une demande déjà approuvée est refusé, un seul crédit",
  (compensation(c1)[0], n_ops("compensation", items_id=c1), n_cr, solde(A)), ("approved", 1, 1, avant + Decimal("0.75")))

# notification au demandeur (file native) : approuvée
corps = sql(f"SELECT recipient, body_text, name FROM glpi_queuednotifications WHERE itemtype = {COMP} AND items_id = {c1}")
v("(6) notification compensation_decided en file pour le demandeur seulement", [r[0] for r in corps], [f"ctl-req{SUF}@example.invalid"])
v("(6) message : balises remplacées, montant approuvé et motif présents", ("##" in corps[0][1], "0.75" in corps[0][1], "Intervention reprise, panne non résolue" in corps[0][1]), (False, True, True))

# plafond : demandé au plus égal au débit moins les compensations approuvées (2 − 0,75 = 1,25) ; approuvé idem
n0 = nb_comp(operations_id=opc)
demande("req", opc, "1.5", "plus que ce qui reste")
v("(6) demande de 1,5 alors que 1,25 reste à compenser : refusée", nb_comp(operations_id=opc), n0)
v("(6) témoin : demande de 1,25 (le reste) acceptée", (demande("req", opc, "1.25", "le reste du débit"), nb_comp(operations_id=opc)), (302, n0 + 1))
c2 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opc}"))
poster(web["dec"], "compensation", {"action": "approve", "id": c_full, "comment": "toute la demande"})
v("(6) la demande de 2 points (déjà plus que le reste) ne peut plus être approuvée entière : refusée, reste en attente", (compensation(c_full)[0], solde(A)), ("pending", avant + Decimal("0.75")))
poster(web["dec"], "compensation", {"action": "approve", "id": c2})
v("(6) montant approuvé par défaut = montant demandé (1,25) ; le débit est entièrement compensé", (compensation(c2)[:3], solde(A)), (("approved", Decimal("1.25"), Decimal("1.25")), avant + 2))
demande("req", opc, "0.01", "plus rien à compenser")
v("(6) plus rien à compenser : une demande de 0,01 est refusée", nb_comp(operations_id=opc), n0 + 1)
poster(web["dec"], "compensation", {"action": "reject", "id": c_full, "comment": "Plus rien à compenser sur ce débit"})
v("(6) rejet avec motif de décision : rejetée, aucun crédit", (compensation(c_full)[0], n_ops("compensation", items_id=c_full)), ("rejected", 0))
v("(6) invariants du journal après compensations", ok(led("check", A)), [])

# rejet : motif de décision obligatoire
tcr, opr, _ = tache_close(A, mardi, heures=1, nom="compensation rejetée")
demande("req", opr, "0.5", "Demande à rejeter")
c3 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opr}"))
for comment in ("", "   "):
    poster(web["dec"], "compensation", {"action": "reject", "id": c3, "comment": comment})
v("(6) rejet sans motif de décision : refusé", (compensation(c3)[0], logs(COMP, c3)), ("pending", 1))
poster(web["dec"], "compensation", {"action": "reject", "id": c3, "comment": "Hors périmètre du contrat"})
v("(6) rejet avec motif : rejetée, décideur et motif enregistrés, aucun crédit, solde inchangé",
  (compensation(c3), n_ops("compensation", items_id=c3), sql(f"SELECT decision_comment FROM {T}compensations WHERE id = {c3}")[0][0]),
  (("rejected", Decimal("0.5"), Decimal("0"), 0, uid["req"], uid["dec"]), 0, "Hors périmètre du contrat"))
poster(web["dec"], "compensation", {"action": "approve", "id": c3})
v("(6) une demande rejetée ne s'approuve plus", (compensation(c3)[0], n_ops("compensation", items_id=c3)), ("rejected", 0))
corps = sql(f"SELECT recipient, body_text FROM glpi_queuednotifications WHERE itemtype = {COMP} AND items_id = {c3}")
v("(6) notification du rejet en file pour le demandeur, avec le motif de décision", ([r[0] for r in corps], "Hors périmètre du contrat" in corps[0][1] if corps else None), ([f"ctl-req{SUF}@example.invalid"], True))

# séparation des tâches : le décideur ne décide pas sa propre demande (réglage compensation_separation)
tcs, ops_, _ = tache_close(A, mardi, heures=1, nom="séparation")
demande("dual", ops_, "0.5", "Demande de l'ADV complet")
c4 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {ops_}"))
poster(web["dual"], "compensation", {"action": "approve", "id": c4})
poster(web["dual"], "compensation", {"action": "reject", "id": c4, "comment": "moi-même"})
v("(6) séparation (réglage 1) : le demandeur ne décide pas sa propre demande, ni approbation ni rejet", (compensation(c4)[0], n_ops("compensation", items_id=c4)), ("pending", 0))
code_ctl, page = onglet(web["dual"], tcs)
v("(6) onglet : le demandeur voit pourquoi il ne peut pas décider, sans formulaire de décision", ("points-compensation-form-%d" % c4 in page, "another user must decide it" in page), (False, True))
config("compensation_separation", "0")
poster(web["dual"], "compensation", {"action": "approve", "id": c4})
v("(6) témoin : séparation désactivée (0), le même utilisateur décide sa demande", (compensation(c4)[0], n_ops("compensation", items_id=c4)), ("approved", 1))
config("compensation_separation", "1")

tcz, opz, _ = tache_close(A, mardi, heures=1, nom="conflit d'instantané compensation")
demande("req", opz, "0.5", "Demande tenue par une autre décision")
c8 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opz}"))
avant = solde(A)
verrou = conflit_instantane("compensations", c8, "rejected")
code = poster(web["dec"], "compensation", {"action": "approve", "id": c8})
verrou.join()
v("(6) approbation perdue au conflit d'instantané (1020) : réponse normale, la décision de l'autre reste, aucun crédit",
  (code, compensation(c8)[0], n_ops("compensation", items_id=c8), solde(A)), (302, "rejected", 0, avant))

# reste compensable = débit moins TOUTES les affectations positives liées (ici un ajustement + de review de 0,5) : demande, pré-vérification, affichage
n0 = nb_comp(operations_id=op4)
code, page = onglet(web["req"], t4)
v("(6) affichage : le débit de 2 remboursé de 0,5 par une review affiche 1,5 compensable", re.search(rf"#{op4} [^<]*compensable 1\.50", page) is not None, True)
demande("req", op4, "1.8", "au-delà de ce qu'il reste")
v("(6) débit 2 déjà remboursé de 0,5 par un ajustement : une demande de 1,8 est refusée dès la demande", nb_comp(operations_id=op4), n0)
v("(6) témoin : une demande de 1,5 (ce qui reste) est acceptée", (demande("req", op4, "1.5", "ce qu'il reste"), nb_comp(operations_id=op4)), (302, n0 + 1))
c5 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {op4}"))
poster(web["dec"], "compensation", {"action": "approve", "id": c5})
v("(6) 1,5 est approuvé (crédit lié)", (compensation(c5)[:3], n_ops("compensation", items_id=c5)), (("approved", Decimal("1.5"), Decimal("1.5")), 1))
code, page = onglet(web["req"], t4)
v("(6) affichage : plus rien de compensable sur ce débit", re.search(rf"#{op4} [^<]*compensable 0\.00", page) is not None, True)

# deux approbations simultanées de la même demande (le contrat est tenu 6 s par une session SQL) : la seconde est « déjà décidée », pas un dépassement
tj, opj, _ = tache_close(A, mardi, heures=1, nom="double approbation")
demande("req", opj, "1", "Demande approuvée deux fois")
c9 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {opj}"))
avant = solde(A)
tenue = threading.Thread(target=lambda: sql_ecrire(f"BEGIN; SELECT id FROM {T}contracts WHERE contracts_id = {A} FOR UPDATE; DO SLEEP(6); COMMIT;"))
tenue.start()
for k in ("dec", "dual"):
    web[k].get("/front/central.php")  # vide les messages en attente des actions précédentes
time.sleep(2)
en_parallele([(web["dec"], "compensation", {"action": "approve", "id": c9}), (web["dual"], "compensation", {"action": "approve", "id": c9})])
tenue.join()
messages = [web[k].get("/front/central.php")[1] for k in ("dec", "dual")]
v("(6) double approbation simultanée : une seule approbation, un seul crédit", (compensation(c9)[0], n_ops("compensation", items_id=c9), solde(A)), ("approved", 1, avant + 1))
v("(6) le perdant lit « déjà décidée », pas un dépassement de montant", (sum("already been decided" in m for m in messages), sum("leaves to compensate" in m for m in messages)), (1, 0))
v("(6) invariants du journal après plafond", ok(led("check", A)), [])

# =====================================================================================================================
# (7) un débit remboursé ou compensé n'est jamais redébité
# =====================================================================================================================
t8, op8, rv8 = tache_close(A, dimanche, nom="rembourse puis rouvert")
poster(web["dual"], "review", {"action": "adjust", "id": rv8, "amount": "2", "comment": "Débit entier annulé"})
avant, nb = solde(A), n_ops("debit_task", tickets_id=t8)
v("(7) ajustement + égal au débit : tout est remboursé (le débit net est nul)", (revue(rv8)[0], nb), ("adjusted", 1))
essai(api, "PUT", f"/Ticket/{t8}", {"input": {"status": 2}})
essai(api, "PUT", f"/Ticket/{t8}", {"input": {"status": 6}})
v("(7) tâche remboursée par une review : rouvrir et reclore ne la redébite pas", (n_ops("debit_task", tickets_id=t8), solde(A)), (1, avant))
t9, op9, _ = tache_close(A, mardi, heures=1, nom="compensé puis rouvert")
demande("req", op9, "1", "Compensation complète")
c6 = int(une(f"SELECT MAX(id) FROM {T}compensations WHERE operations_id = {op9}"))
poster(web["dec"], "compensation", {"action": "approve", "id": c6})
avant = solde(A)
essai(api, "PUT", f"/Ticket/{t9}", {"input": {"status": 2}})
essai(api, "PUT", f"/Ticket/{t9}", {"input": {"status": 6}})
v("(7) tâche compensée en entier : rouvrir et reclore ne la redébite pas", (compensation(c6)[0], n_ops("debit_task", tickets_id=t9), solde(A)), ("approved", 1, avant))

# =====================================================================================================================
# (8) API v1 : aucune écriture générique, whitelist, cloisonnement ; recherche native
# =====================================================================================================================
t10, op10, rv10 = tache_close(A, dimanche, nom="api")
ok_api, _ = essai(cli_api["dual"], "PUT", f"/{CLASSE}Review/{rv10}", {"input": {"status": "confirmed", "comment": "par l'API"}})
v("(8) API v1 : une review ne se modifie pas par PUT (statut inchangé)", revue(rv10)[0], "to_review")
essai(cli_api["dual"], "DELETE", f"/{CLASSE}Review/{rv10}?force_purge=true")
n_rv = int(une(f"SELECT COUNT(*) FROM {T}reviews"))
essai(cli_api["dual"], "POST", f"/{CLASSE}Review", {"input": {"operations_id": op10, "tickets_id": t10, "status": "confirmed"}})
v("(8) API v1 : une review ne se supprime ni ne se crée par l'API", (int(une(f"SELECT COUNT(*) FROM {T}reviews WHERE id = {rv10}")), int(une(f"SELECT COUNT(*) FROM {T}reviews")) - n_rv), (1, 0))
ok_get, _ = essai(cli_api["dual"], "GET", f"/{CLASSE}Review/{rv10}")
ok_get_autre, _ = essai(cli_api["autre"], "GET", f"/{CLASSE}Review/{rv10}")
v("(8) API v1 : lecture permise à l'ADV de l'entité, refusée à l'entité étrangère (témoin positif)", (ok_get, ok_get_autre), (True, False))

n_c = int(une(f"SELECT COUNT(*) FROM {T}compensations"))
ok_post, _ = essai(cli_api["req"], "POST", f"/{CLASSE}Compensation", {"input": {"operations_id": op10, "amount": "0.5", "comment": "par l'API", "status": "approved", "approved_amount": "9", "users_id": 1}})
c7 = int(une(f"SELECT MAX(id) FROM {T}compensations"))
v("(8) API v1 : l'ADV demande (témoin) ; l'état, le montant approuvé et le demandeur ne viennent pas de la saisie",
  (ok_post, int(une(f"SELECT COUNT(*) FROM {T}compensations")) - n_c, compensation(c7)), (True, 1, ("pending", Decimal("0.5"), Decimal("0"), 0, uid["req"], 0)))
n_c = int(une(f"SELECT COUNT(*) FROM {T}compensations"))
for qui in ("dec", "autre"):
    essai(cli_api[qui], "POST", f"/{CLASSE}Compensation", {"input": {"operations_id": op10, "amount": "0.5", "comment": "refusé"}})
    v(f"(8) API v1 : {qui} ne peut pas demander", int(une(f"SELECT COUNT(*) FROM {T}compensations")), n_c)
essai(cli_api["dec"], "PUT", f"/{CLASSE}Compensation/{c7}", {"input": {"status": "approved", "approved_amount": "0.5"}})
essai(cli_api["dual"], "PUT", f"/{CLASSE}Compensation/{c7}", {"input": {"status": "approved", "approved_amount": "0.5"}})
essai(cli_api["dec"], "DELETE", f"/{CLASSE}Compensation/{c7}?force_purge=true")
v("(8) API v1 : une demande ne se modifie ni ne se supprime par l'API, même avec VALIDATE", (compensation(c7)[0], int(une(f"SELECT COUNT(*) FROM {T}compensations WHERE id = {c7}"))), ("pending", 1))
ok_get, _ = essai(cli_api["dec"], "GET", f"/{CLASSE}Compensation/{c7}")
ok_get_autre, _ = essai(cli_api["autre"], "GET", f"/{CLASSE}Compensation/{c7}")
v("(8) API v1 : lecture d'une demande permise dans l'entité, refusée à l'entité étrangère", (ok_get, ok_get_autre), (True, False))


def recherche(client, itemtype, critere=""):
    r = client._req("GET", f"/search/{CLASSE}{itemtype}?forcedisplay[0]=2{critere}")
    return int(r["totalcount"]), sorted(int(x["2"]) for x in r.get("data", []))


entites_a = f"({entite}, {entite_cal})"
tous_a = sorted(int(r[0]) for r in sql(f"SELECT id FROM {T}reviews WHERE entities_id = {entite}"))
a_controler = sorted(int(r[0]) for r in sql(f"SELECT id FROM {T}reviews WHERE entities_id = {entite} AND status = 'to_review'"))
crit_statut = "&criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=to_review"
n, ids = recherche(cli_api["dual"], "Review")
v("(8) recherche native : l'ADV voit toutes les reviews de son entité (et pas celles de l'entité à calendrier)", (n, ids), (len(tous_a), tous_a))
n, ids = recherche(cli_api["dual"], "Review", crit_statut)
v("(8) recherche native : filtre sur le statut « à contrôler »", ids, a_controler)
v("(8) recherche native : la review du contrat de l'entité mère est vue par l'ADV de l'entité du contrat (Outils > Points), pas par le technicien de la sous-entité",
  (rvf in recherche(cli_api["dual"], "Review")[1], rvf in recherche(cli_api["fille"], "Review")[1]), (True, False))
v("(8) recherche native : l'entité étrangère ne voit aucune review", recherche(cli_api["autre"], "Review"), (0, []))
crit_ticket = f"&criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]={t10}"
v("(8) recherche native : filtre sur le ticket", recherche(cli_api["dual"], "Review", crit_ticket)[1], [rv10])
crit_contrat = f"&criteria[0][field]=6&criteria[0][searchtype]=equals&criteria[0][value]={H}"
v("(8) recherche native : filtre sur le contrat", recherche(cli_api["dual"], "Review", crit_contrat)[1], [rv5])
en_attente = sorted(int(r[0]) for r in sql(f"SELECT id FROM {T}compensations WHERE entities_id = {entite} AND status = 'pending'"))
n, ids = recherche(cli_api["dec"], "Compensation", "&criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=pending")
v("(8) recherche native : compensations en attente de l'entité", ids, en_attente)
v("(8) recherche native : l'entité étrangère ne voit aucune compensation", recherche(cli_api["autre"], "Compensation"), (0, []))
code, page = web["dual"].get("/plugins/points/front/review.php")
v("(8) page « Reviews à contrôler » : l'ADV l'ouvre (liste native)", (code, "search-page" in page), (200, True))
code, page = web["dual"].get("/plugins/points/front/compensation.php")
v("(8) page « Compensations en attente » : l'ADV l'ouvre", (code, "search-page" in page), (200, True))
for qui in ("none", "client"):
    v(f"(8) {qui} : pages des listes refusées", (web[qui].get("/plugins/points/front/review.php")[0], web[qui].get("/plugins/points/front/compensation.php")[0]), (403, 403))
v("(8) témoin : la lecture seule (review 1, compensation 1025) suffit pour ouvrir les deux listes",
  (web["dec"].get("/plugins/points/front/review.php")[0], web["dec"].get("/plugins/points/front/compensation.php")[0]), (200, 200))

# =====================================================================================================================
# (9) onglet Points du ticket : signalement, demande et historique, selon les droits
# =====================================================================================================================
code, page = onglet(web["dual"], t10)
v("(9) onglet : tâche à contrôler signalée, formulaire de décision, historique des demandes", (code, 'id="points-reviews"' in page, 'id="points-reviews-notice"' in page, f'id="points-review-form-{rv10}"' in page, 'id="points-compensations-table"' in page), (200, True, True, True, True))
code, page = onglet(web["req"], t10)
v("(9) ADV (CREATE) : formulaire de demande de compensation ; pas de formulaire de décision (sans VALIDATE)", ('id="points-compensation-request"' in page, f'id="points-compensation-form-{c7}"' in page), (True, False))
code, page = onglet(web["dec"], t10)
v("(9) décideur (VALIDATE sans CREATE) : pas de formulaire de demande, review en lecture seule", ('id="points-compensation-request"' in page, f'id="points-review-form-{rv10}"' in page, 'id="points-reviews"' in page), (False, False, True))
for qui in ("none", "client"):
    code, page = onglet(web[qui], t10)
    v(f"(9) {qui} : aucune section de contrôle ni de compensation", ('id="points-reviews"' in page, 'id="points-compensations"' in page), (False, False))
v("(9) onglet : le décideur voit le formulaire de décision de la demande en attente ; le demandeur sans VALIDATE ne le voit pas",
  (f'id="points-compensation-form-{c7}"' in onglet(web["dec"], t10)[1], f'id="points-compensation-form-{c7}"' in onglet(web["req"], t10)[1]), (True, False))
code, page = onglet(web["dual"], t2)
v("(9) review confirmée : visible avec son statut, plus de formulaire de décision", (f'id="points-review-form-{rv2}"' in page, "Confirmed" in page), (False, True))
poster(web["dec"], "compensation", {"action": "reject", "id": c7, "comment": "Test API terminé"})

# =====================================================================================================================
# (9b) conflit d'instantané (1020) sur les autres états écrits dans la transaction du journal : forfait et ligne d'état d'une tâche
# =====================================================================================================================
TASKS = T + "tasks"
tf = ticket("conflit d'instantané : forfait", A)
sql_ecrire(f"INSERT INTO {T}flatrates (entities_id, is_recursive, tickets_id, contracts_id, amount, description, status, users_id, approved_amount) "
           f"VALUES ({entite}, 0, {tf}, {A}, 1.00, 'conflit', 'approved', 1, 1.00);")
fid = int(une(f"SELECT MAX(id) FROM {T}flatrates WHERE tickets_id = {tf}"))
avant = solde(A)
verrou = conflit_instantane("flatrates", fid, "cancelled")
rep = cli("flatrate_debit", fid)
verrou.join()
v("(9b) forfait : transition perdue au conflit d'instantané (1020) : refus « état du forfait » (pas d'erreur 500, jamais « débité » sans opération) ; aucun débit, solde inchangé",
  ("error" in rep, rep.get("violation"), n_ops("debit_flatrate", tickets_id=tf), solde(A), une(f"SELECT status FROM {T}flatrates WHERE id = {fid}")), (False, "flatrate_state", 0, avant, "cancelled"))
sql_ecrire(f"DELETE FROM {T}flatrates WHERE id = {fid}; DELETE FROM glpi_logs WHERE itemtype = 'GlpiPlugin\\\\Points\\\\Flatrate' AND items_id = {fid};")

tt = ticket("conflit d'instantané : tâche", A)
essai(api, "POST", "/TicketTask", {"input": {"tickets_id": tt, "content": "tâche", "actiontime": 3600, "date": mardi + " 09:00:00"}})
tache = int(une(f"SELECT id FROM glpi_tickettasks WHERE tickets_id = {tt}"))
sql_ecrire(f"INSERT INTO {TASKS} (entities_id, is_recursive, tickettasks_id, tickets_id, operations_id, is_locked) VALUES ({entite}, 0, {tache}, {tt}, 0, 0);")
avant = solde(A)
verrou = threading.Thread(target=lambda: sql_ecrire(f"BEGIN; UPDATE {TASKS} SET date_mod = NOW() WHERE tickettasks_id = {tache}; DO SLEEP(6); COMMIT;"))
verrou.start()
time.sleep(2)
ok_close, texte = essai(api, "PUT", f"/Ticket/{tt}", {"input": {"status": 6}})
verrou.join()
statut_avant = une(f"SELECT status FROM glpi_tickets WHERE id = {tt}")
v("(9b) ligne d'état d'une tâche : écriture perdue au conflit d'instantané (1020) : pas d'erreur 500, aucun débit validé, et la clôture est refusée (le ticket revient à son statut d'avant)",
  (ok_close, n_ops("debit_task", tickets_id=tt), solde(A), statut_avant), (True, 0, avant, "1"))
v("(9b) la tâche perdue n'est pas verrouillée sans débit", une(f"SELECT is_locked FROM {TASKS} WHERE tickettasks_id = {tache}"), "0")
essai(api, "PUT", f"/Ticket/{tt}", {"input": {"status": 6}})
v("(9b) témoin : la clôture suivante débite la tâche normalement (1 point) et clôt le ticket", (n_ops("debit_task", tickets_id=tt), solde(A), une(f"SELECT status FROM glpi_tickets WHERE id = {tt}")), (1, avant - 1, "6"))

# =====================================================================================================================
# (10) invariants, journaux
# =====================================================================================================================
for contrat in (A, H, C):
    v(f"(10) invariants du contrat {contrat}", ok(led("check", contrat)), [])
fautes = erreurs_plugin()
v("(10) aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : controles " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
