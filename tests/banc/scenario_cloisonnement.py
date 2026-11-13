"""Cloisonnement et droits par profil (lot f) : deux clients (A et B sous « Clients »), un contrat à points chacun, les profils d'Assist
(Responsable client en interface standard ET simplifiée, Technicien N2, Responsable BU, Facturation / ADV, Admin, Demandeur) avec EXACTEMENT les
droits de la table « Droits du plugin » de docs/conception.md (lue par le scénario, pas recopiée). Pour chaque objet du plugin : lecture
(getItem, recherche, getMultipleItems) et écriture (add, update, delete) par l'API v1, onglets et pages par l'interface, avec les entités du
profil : l'ensemble des comptes qui réussissent doit être EXACTEMENT l'ensemble attendu (donc chaque refus a son témoin positif). Un client ne voit
jamais les points de l'autre, ni par la recherche native, ni par l'export. Page « Points » de l'interface simplifiée. Contrat récursif d'une
entité mère. Part d'un plugin installé et activé (run.sh) ; remet la configuration comme il l'a trouvée."""
import atexit
import datetime
import json
import os
import re
import subprocess
import sys
import urllib.parse
from decimal import Decimal
from zoneinfo import ZoneInfo

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
T = "glpi_plugin_points_"
CLASSE = "GlpiPlugin%5CPoints%5C"
MDP = "Cloisonne-points-1"
SUF = format(int(datetime.datetime.now().timestamp()) % 100000, "x")  # noms uniques : le scénario se rejoue sur un banc gardé
PARIS = ZoneInfo("Europe/Paris")
AUJOURDHUI = datetime.datetime.now(PARIS).date()
CONCEPTION = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "docs", "conception.md")


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


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def poster(web, formulaire, champs):
    """POST d'un formulaire du plugin (en-tête de navigateur) ; renvoie le code HTTP."""
    return web.post(f"/plugins/points/front/{formulaire}.form.php", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})[0]


def lecture(nom):
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                          capture_output=True, text=True).stdout


def erreurs_plugin():
    return [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]


# --- droits : table « Droits du plugin » de conception.md (nom du droit -> valeur par colonne)
COLONNES = ["Responsable client", "Technicien N1/N2/N3", "Responsable BU", "Facturation / ADV", "Admin"]


def table_conception():
    texte = open(CONCEPTION, encoding="utf-8").read()
    bloc = texte.split("## Droits du plugin")[1].split("## Écrans")[0]
    droits = {}
    for ligne in bloc.splitlines():
        m = re.match(r"\|\s*`(plugin_points_\w+)`[^|]*\|[^|]*\|" + r"\s*(\d+)\s*\|" * 5 + r"\s*$", ligne)
        if m:
            droits[m.group(1)] = dict(zip(COLONNES, (int(x) for x in m.groups()[1:])))
    return droits


DROITS = table_conception()
v("(0) la table de conception.md donne les huit droits du plugin", sorted(DROITS), sorted(["plugin_points_balance", "plugin_points_ticket", "plugin_points_envelope", "plugin_points_flatrate", "plugin_points_review", "plugin_points_compensation", "plugin_points_grid", "plugin_points_config"]))
v("(0) valeurs de la table (relevé de contrôle) : ADV, BU, Admin, technicien",
  (DROITS["plugin_points_envelope"]["Facturation / ADV"], DROITS["plugin_points_flatrate"]["Responsable BU"], DROITS["plugin_points_grid"]["Admin"], DROITS["plugin_points_flatrate"]["Technicien N1/N2/N3"]), (7, 1025, 23, 7))

# --- état d'origine, remis à la fin
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'")
          for c in ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id", "compensation_separation")}


def remettre_config():
    for cle, valeur in ancien.items():
        config(cle, valeur)


atexit.register(remettre_config)

api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST, OT = types["Assistance"], types["Ordre de travail"]
type_points = creer(api, "ContractType", {"name": f"points-clois-type-{SUF}"})
config("contracttypes", f"[{type_points}]")
config("debit_moment", "closure")
config("allow_overdraft", "1")
config("calendars_id", "0")
config("compensation_separation", "1")

# --- entités : Clients > A (> A_fille), Clients > B
clients = creer(api, "Entity", {"name": f"points-clois-clients-{SUF}", "entities_id": 0})
api = banc.Api()
ent = {"clients": clients}
ent["A"] = creer(api, "Entity", {"name": f"points-clois-A-{SUF}", "entities_id": clients})
ent["B"] = creer(api, "Entity", {"name": f"points-clois-B-{SUF}", "entities_id": clients})
api = banc.Api()
ent["F"] = creer(api, "Entity", {"name": f"points-clois-Afille-{SUF}", "entities_id": ent["A"]})
api = banc.Api()

# --- grille (Assistance 1 par heure en semaine, 1,5 le samedi, 2 le dimanche)
sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Clois-std-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Clois-std-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for jour, cout in enumerate((1, 1.5, 2), start=1):
    sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
               f"VALUES (0, 1, {version}, {ASSIST}, {jour}, {cout});")

# --- profils d'Assist, droits de la table de conception.md. Modèles du cœur : droits natifs plausibles (contrat lisible pour le
# Responsable client en interface standard, le BU, l'ADV et l'Admin ; pas pour le technicien, comme le modèle Technician)
modele = {nom: une(f"SELECT id FROM glpi_profiles WHERE name = '{nom}'") for nom in ("Observer", "Technician", "Supervisor", "Admin", "Self-Service")}


def profil(nom, mod, interface, colonne):
    pid = creer(api, "Profile", {"name": f"{nom} [banc {SUF}]", "interface": interface})
    sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {pid}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele[mod]} "
               "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    for droit, par_colonne in DROITS.items():
        sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({pid}, '{droit}', {par_colonne[colonne] if colonne else 0}) "
                   "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    return pid


PROFILS = {
    "rc":   profil("Responsable client", "Observer", "central", COLONNES[0]),        # interface standard (décision de principe, suivi point 50)
    "rcs":  profil("Responsable client simplifié", "Self-Service", "helpdesk", COLONNES[0]),
    "tech": profil("Technicien N2", "Technician", "central", COLONNES[1]),
    "bu":   profil("Responsable BU", "Supervisor", "central", COLONNES[2]),
    "adv":  profil("Facturation / ADV", "Admin", "central", COLONNES[3]),
    "adm":  profil("Admin", "Admin", "central", COLONNES[4]),
    "dem":  profil("Demandeur", "Self-Service", "helpdesk", None),
}
console("cache:clear")


def nettoyer():
    ids = ",".join(str(p) for p in PROFILS.values())
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id IN ({ids});DELETE FROM glpi_profilerights WHERE profiles_id IN ({ids});"
               f"DELETE FROM glpi_profiles WHERE id IN ({ids});DELETE FROM glpi_logs WHERE itemtype LIKE 'GlpiPlugin%Points%' AND date_mod >= '{INSTANT}';"
               "DELETE FROM glpi_queuednotifications WHERE itemtype LIKE 'GlpiPlugin%Points%';")
    console("cache:clear")


INSTANT = (datetime.datetime.now() - datetime.timedelta(seconds=5)).strftime("%Y-%m-%d %H:%M:%S")
if not os.environ.get("CLOIS_GARDER"):  # enquête : profils et comptes gardés (le banc l'est avec BANC_GARDER=1)
    atexit.register(nettoyer)


def utilisateur(nom, profil_id, entite_id):
    uid = creer(api, "User", {"name": nom, "password": MDP, "password2": MDP, "_profiles_id": int(profil_id), "_entities_id": entite_id,
                              "_is_recursive": 1, "is_active": 1})
    sql_ecrire(f"INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email) VALUES ({uid}, 1, 0, '{nom}@example.invalid');")
    return uid


# rôle -> (groupe de profil, entité de rattachement ; les sous-entités sont comprises)
ROLES = {
    "rcA": ("rc", "A"), "rcsA": ("rcs", "A"), "rcB": ("rc", "B"), "rcsB": ("rcs", "B"),
    "tech": ("tech", "clients"), "buA": ("bu", "A"), "adv": ("adv", "clients"), "adm": ("adm", "clients"), "dem": ("dem", "A"),
    "rcF": ("rc", "F"), "rcsF": ("rcs", "F"), "advF": ("adv", "F"),
}
uid = {r: utilisateur(f"clois-{r}{SUF}", PROFILS[g], ent[e]) for r, (g, e) in ROLES.items()}
GROUPE = {r: g for r, (g, _) in ROLES.items()}
# entités de « données » (A, B ; l'entité fille F ne porte aucune donnée) que chaque rôle atteint : A comprend sa fille F
PORTEE = {"rcA": {"A"}, "rcsA": {"A"}, "rcB": {"B"}, "rcsB": {"B"}, "tech": {"A", "B"}, "buA": {"A"}, "adv": {"A", "B"}, "adm": {"A", "B"}, "dem": {"A"},
          "rcF": set(), "rcsF": set(), "advF": set()}
cli_api = {r: banc.Api(f"clois-{r}{SUF}", MDP) for r in ROLES}
_web = {}


def web(r):
    if r not in _web:
        _web[r] = banc.Web(f"clois-{r}{SUF}", MDP)
    return _web[r]


v("(0) aucune erreur du plugin dans les journaux PHP et SQL avant le scénario", erreurs_plugin(), [])

# =====================================================================================================================
# Données : par client, un contrat à points (enveloppe, débits de tâches dont un hors créneau, forfait, compensation) ; un contrat
# récursif R dans l'entité A, débité depuis un ticket de la sous-entité F
# =====================================================================================================================
dimanche, mardi = dernier(7), dernier(2)


def ticket(nom, contrat, e, **plus):
    return creer(api, "Ticket", {"name": nom, "content": nom, "entities_id": ent[e], "_contracts_id": contrat, **plus})


def tache_close(tk, jour, heures=1, nom="tâche"):
    essai(api, "POST", "/TicketTask", {"input": {"tickets_id": tk, "content": nom, "actiontime": 3600 * heures, "date": jour + " 09:00:00"}})


def clore(tk):
    essai(api, "PUT", f"/Ticket/{tk}", {"input": {"status": 6}})


CT, ENV_NOM, TK, TF, TN = {}, {}, {}, {}, {}
for X in ("A", "B"):
    CT[X] = creer(api, "Contract", {"name": f"points-clois-contrat-{X}-{SUF}", "entities_id": ent[X], "contracttypes_id": type_points})
    ENV_NOM[X] = f"ENVELOPPE-SECRETE-{X}-{SUF}"
    led("createEnvelope", CT[X], ENV_NOM[X], 10000, d(-100), d(200), False, f"commentaire-{X}-{SUF}")
    sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {CT[X]};")
CT["R"] = creer(api, "Contract", {"name": f"points-clois-contrat-R-{SUF}", "entities_id": ent["A"], "is_recursive": 1, "contracttypes_id": type_points})
led("createEnvelope", CT["R"], f"ENVELOPPE-RECURSIVE-{SUF}", 10000, d(-100), d(200), False, "")
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {CT['R']};")

for X in ("A", "B"):
    TK[X] = ticket(f"clois-ticket-{X}", CT[X], X, **({"_users_id_requester": uid["dem"]} if X == "A" else {}))
    poster(web("adv"), "ticket", {"update": 1, "tickets_id": TK[X], "contracts_id": CT[X], "plugin_points_interventiontypes_id": ASSIST, "is_billable": 1})
    tache_close(TK[X], mardi, 1, "semaine")
    tache_close(TK[X], dimanche, 1, "dimanche")
    clore(TK[X])
    TF[X] = ticket(f"clois-forfait-{X}", CT[X], X)
    sql_ecrire(f"INSERT INTO {T}flatrates (entities_id, is_recursive, tickets_id, contracts_id, amount, description, status, users_id, approved_amount) "
               f"VALUES ({ent[X]}, 0, {TF[X]}, {CT[X]}, 1.00, 'forfait-{X}', 'draft', 1, 0);")
    TN[X] = ticket(f"clois-neuf-{X}", CT[X], X)
    # ticket « neuf » : sans réglage Points, pour tester sa création par l'API (la ligne naît avec le ticket depuis le lot final : type figé)
    sql_ecrire(f"DELETE FROM {T}tickets WHERE tickets_id = {TN[X]};")
TR = ticket("clois-ticket-R", CT["R"], "F")
tache_close(TR, dimanche, 1, "dimanche")
clore(TR)


def debit(contrat):
    return int(une(f"SELECT id FROM {T}operations WHERE contracts_id = {contrat} AND type = 'debit_task' AND amount = -2.00 ORDER BY id LIMIT 1"))


OP = {X: debit(CT[X]) for X in ("A", "B", "R")}
for X in ("A", "B"):
    poster(web("adv"), "compensation", {"action": "request", "operations_id": OP[X], "amount": "0.5", "comment": f"motif-{X}-{SUF}"})

# objet de chaque type, par client : {type: {A: id, B: id}} ; R : le contrat récursif de l'entité mère
O = {k: {} for k in ("Contract", "Envelope", "Operation", "Lot", "Allocation", "Task", "Ticket", "Flatrate", "Review", "Compensation")}
for X in ("A", "B", "R"):
    c = CT[X]
    O["Contract"][X] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {c}"))
    O["Envelope"][X] = int(une(f"SELECT MIN(id) FROM {T}envelopes WHERE contracts_id = {c}"))
    O["Operation"][X] = OP[X]
    O["Lot"][X] = int(une(f"SELECT MIN(id) FROM {T}lots WHERE contracts_id = {c}"))
    O["Allocation"][X] = int(une(f"SELECT MIN(id) FROM {T}allocations WHERE operations_id = {OP[X]}"))
    O["Review"][X] = int(une(f"SELECT MIN(id) FROM {T}reviews WHERE contracts_id = {c}"))
for X in ("A", "B"):
    O["Task"][X] = int(une(f"SELECT MIN(id) FROM {T}tasks WHERE tickets_id = {TK[X]}"))
    O["Ticket"][X] = int(une(f"SELECT id FROM {T}tickets WHERE tickets_id = {TK[X]}"))
    O["Flatrate"][X] = int(une(f"SELECT id FROM {T}flatrates WHERE tickets_id = {TF[X]}"))
    O["Compensation"][X] = int(une(f"SELECT MIN(id) FROM {T}compensations WHERE contracts_id = {CT[X]}"))
if os.environ.get("CLOIS_GARDER"):
    json.dump({"SUF": SUF, "ent": ent, "CT": CT, "TK": TK, "TF": TF, "TN": TN, "TR": TR, "O": O, "OP": OP}, open(os.environ["CLOIS_GARDER"], "w"))
v("(0) objets du plugin créés pour A, B (et R pour les journaux)", all(all(i > 0 for i in m.values()) for m in O.values()), True)
v("(0) le débit du contrat récursif R (ticket de la sous-entité F) porte l'entité du contrat (A)",
  une(f"SELECT entities_id FROM {T}operations WHERE id = {OP['R']}"), str(ent["A"]))

# --- définitions communes aux sections (1) à (4) ; les sections (1) et (2) (matrice complète par l'API, longues) se sautent avec CLOIS_RAPIDE=1 :
# usage des mutations de la section (3) et suivantes seulement, jamais pour la suite complète
RAPIDE = bool(os.environ.get("CLOIS_RAPIDE"))


def multi(items):
    return "&".join(f"items[{n}][itemtype]={CLASSE}{k}&items[{n}][items_id]={i}" for n, (k, i) in enumerate(items))
BAL = {"rc", "rcs", "tech", "bu", "adv", "adm"}
LECT = {k: BAL for k in ("Contract", "Envelope", "Operation", "Lot", "Allocation", "Task")}
LECT.update({"Ticket": {"tech", "bu", "adv", "adm"}, "Flatrate": {"tech", "bu", "adv", "adm"}, "Review": {"bu", "adv", "adm"}, "Compensation": {"bu", "adv", "adm"}})
GRILLES = {"Grid": None, "GridVersion": None, "GridCost": None, "InterventionType": None}  # droit grid READ, sans entité (globales)
LECT_GRILLE = {"tech", "bu", "adv", "adm"}


def attendus(kind, X):
    """Comptes qui lisent l'objet `kind` du client X (R : contrat récursif de A, lu comme A)."""
    ent_x = "A" if X == "R" else X
    return {r for r in ROLES if GROUPE[r] in LECT[kind] and ent_x in PORTEE[r]}


def lit(r, kind, ident):
    return essai(cli_api[r], "GET", f"/{CLASSE}{kind}/{ident}")[0]


TABLE = {"Contract": "contracts", "Envelope": "envelopes", "Operation": "operations", "Lot": "lots", "Allocation": "allocations", "Task": "tasks",
         "Ticket": "tickets", "Flatrate": "flatrates", "Review": "reviews", "Compensation": "compensations", "Grid": "grids", "GridVersion": "gridversions",
         "GridCost": "gridcosts", "InterventionType": "interventiontypes"}
# (type, champ, valeur essayée, valeur d'origine pour remettre, groupes qui ont le droit d'écrire)
PUTS = [
    ("Contract", "alert_threshold", "61", "80", {"adv"}),                         # plugin_points_envelope UPDATE (7 : ADV seul)
    ("Envelope", "name", "RENOMMEE", None, {"adv"}),
    ("Ticket", "plugin_points_interventiontypes_id", str(OT), str(ASSIST), {"tech", "adv", "adm"}),  # plugin_points_ticket UPDATE (3)
    ("Flatrate", "amount", "2.35", "1.00", {"tech"}),                              # plugin_points_flatrate UPDATE (7 : technicien seul)
    ("Review", "comment", "api", "", set()),
    ("Compensation", "comment", "api", None, set()),
    ("Operation", "comment", "api", None, set()),
    ("Lot", "initial", "5.00", None, set()),
    ("Allocation", "amount", "5.00", None, set()),
    ("Task", "is_locked", "0", None, set()),
]


def valeur(kind, ident, champ):
    lignes = sql(f"SELECT COALESCE({champ}, '') FROM {T}{TABLE[kind]} WHERE id = {ident}")  # une valeur vide donne une sortie vide
    return lignes[0][0] if lignes and lignes[0] else ""


def meme(a, b):
    try:
        return Decimal(a) == Decimal(b)
    except Exception:
        return a == b



if not RAPIDE:
    # =====================================================================================================================
    # (1) Lecture par l'API v1 : getItem, recherche native, getMultipleItems. Ensemble des comptes qui lisent = ensemble attendu (droit de la table
    # ET entités du compte) ; les comptes de l'entité fille F ne lisent rien des points du contrat récursif de l'entité mère A (règle de conception)
    # =====================================================================================================================
    for kind, par_client in O.items():
        for X, ident in par_client.items():
            lisent = {r for r in ROLES if lit(r, kind, ident)}
            v(f"(1) API getItem {kind} ({'contrat récursif R' if X == 'R' else 'client ' + X}) : comptes qui lisent", sorted(lisent), sorted(attendus(kind, X)))
    for kind in GRILLES:
        ident = int(une({"Grid": f"SELECT MIN(id) FROM {T}grids", "GridVersion": f"SELECT MIN(id) FROM {T}gridversions",
                         "GridCost": f"SELECT MIN(id) FROM {T}gridcosts", "InterventionType": f"SELECT MIN(id) FROM {T}interventiontypes"}[kind]))
        lisent = {r for r in ROLES if lit(r, kind, ident)}
        v(f"(1) API getItem {kind} (liste globale) : comptes qui lisent = ceux du droit plugin_points_grid READ", sorted(lisent), sorted(r for r in ROLES if GROUPE[r] in LECT_GRILLE))


    def recherche(client, kind, critere=""):
        try:
            r = client._req("GET", f"/search/{CLASSE}{kind}?forcedisplay[0]=2&range=0-999{critere}")
        except ErreurApi as e:
            if "ERROR_RIGHT_MISSING" in str(e):  # droit de lecture absent : la recherche est refusée, rien n'est listé
                return []
            raise
        return sorted(int(x["2"]) for x in r.get("data", []))


    for kind, par_client in O.items():
        nos = {i for i in par_client.values()}
        ecarts = {}
        for r in ROLES:
            vus = set(recherche(cli_api[r], kind)) & nos
            voulus = {i for X, i in par_client.items() if r in attendus(kind, X)}
            if vus != voulus:
                ecarts[r] = (sorted(vus), sorted(voulus))
        v(f"(1) recherche native {kind} : chaque compte ne trouve que ses clients (écarts)", ecarts, {})
        v(f"(1) recherche native {kind} : témoin, l'ADV voit tous les objets des deux clients", sorted(set(recherche(cli_api["adv"], kind)) & nos), sorted(nos))
    for kind in GRILLES:
        quoi = {r: len(recherche(cli_api[r], kind)) > 0 for r in ROLES}
        v(f"(1) recherche native {kind} : liste globale lue par les comptes du droit grid READ seulement", sorted(r for r, b in quoi.items() if b), sorted(r for r in ROLES if GROUPE[r] in LECT_GRILLE))

    # getMultipleItems : une requête avec les seuls objets que le compte lit (réussit, contient les données : témoin positif) et une requête avec tous
    # les objets des deux clients (refusée en bloc, ou sans aucune donnée d'un client non lu)


    tous = [(k, i) for k, par in O.items() for i in par.values()]
    ecarts = {}
    for r in ROLES:
        lisibles = [(k, i) for k, par in O.items() for X, i in par.items() if r in attendus(k, X)]
        if lisibles:
            reussi, texte = essai(cli_api[r], "GET", f"/getMultipleItems?{multi(lisibles)}")
            voulu = {X: r in attendus("Envelope", X) for X in ("A", "B")}
            vu = {X: ENV_NOM[X] in texte for X in ("A", "B")}
            if not reussi or vu != voulu:
                ecarts[(r, "objets lus")] = (reussi, vu, voulu)
        reussi, texte = essai(cli_api[r], "GET", f"/getMultipleItems?{multi(tous)}")
        for X in ("A", "B"):
            if r not in attendus("Envelope", X) and ENV_NOM[X] in texte:
                ecarts[(r, "fuite", X)] = texte[:100]
    v("(1) getMultipleItems : chaque compte reçoit les objets qu'il lit (témoin) et jamais la donnée d'un client qu'il ne lit pas (écarts)", ecarts, {})

    # =====================================================================================================================
    # (2) Écriture par l'API v1 : update, add, delete. Un compte réussit seulement s'il a le droit (table de conception) ET l'entité du client ; le
    # journal (opérations, lots, affectations, tâches, reviews, compensations par PUT, envelopes et contrats par POST) n'est écrit par personne.
    # Succès mesuré par la base (une réponse API 200 ne prouve rien : le cœur répond 207 aux refus de règle).
    # =====================================================================================================================
    for kind, champ, essayee, retour, groupes in PUTS:
        for X in ("A", "B"):
            ident = O[kind][X]
            origine = valeur(kind, ident, champ) if retour is None else retour
            reussis = set()
            for r in ROLES:
                cible = essayee if kind != "Envelope" else f"RENOMMEE-{r}"
                essai(cli_api[r], "PUT", f"/{CLASSE}{kind}/{ident}", {"input": {champ: cible}})
                if meme(valeur(kind, ident, champ), cible) and not meme(valeur(kind, ident, champ), origine):
                    reussis.add(r)
                    sql_ecrire(f"UPDATE {T}{TABLE[kind]} SET {champ} = '{origine}' WHERE id = {ident};")
            voulus = {r for r in ROLES if GROUPE[r] in groupes and X in PORTEE[r]}
            v(f"(2) API PUT {kind}.{champ} (client {X}) : comptes qui écrivent", sorted(reussis), sorted(voulus))
    # POST : lignes ajoutées, par compte (supprimées ensuite par SQL pour essayer le compte suivant)
    n_avant = {k: int(une(f"SELECT COUNT(*) FROM {T}{t}")) for k, t in TABLE.items()}
    POSTS = {
        "Contract": lambda X: {"contracts_id": CT[X], "plugin_points_grids_id": grille},
        "Envelope": lambda X: {"contracts_id": CT[X], "name": "pirate", "credit": "500", "begin_date": d(-10), "end_date": d(100)},
        "Operation": lambda X: {"contracts_id": CT[X], "type": "credit", "amount": "999"},
        "Lot": lambda X: {"contracts_id": CT[X], "initial": "999"},
        "Allocation": lambda X: {"operations_id": OP[X], "amount": "999"},
        "Task": lambda X: {"tickets_id": TK[X], "tickettasks_id": 1},
        "Review": lambda X: {"operations_id": OP[X], "tickets_id": TK[X], "status": "confirmed"},
    }
    for kind, f in POSTS.items():
        for X in ("A", "B"):
            crees = {}
            for r in ROLES:
                essai(cli_api[r], "POST", f"/{CLASSE}{kind}", {"input": f(X)})
                crees[r] = int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]}")) - n_avant[kind]
            v(f"(2) API POST {kind} (client {X}) : aucun compte n'ajoute (le journal et les enveloppes ne naissent que par Ledger)", sum(crees.values()), 0)
    # Réglage de ticket, forfait, compensation : créés par l'API avec leur droit ; la ligne est retirée par SQL entre deux comptes
    CREATIONS = [
        ("Ticket", lambda X: {"tickets_id": TN[X], "is_billable": 1}, {"tech", "adv", "adm"}, f"tickets_id = %d"),
        ("Flatrate", lambda X: {"tickets_id": TN[X], "amount": "1.00", "description": "api"}, {"tech"}, "tickets_id = %d"),
        ("Compensation", lambda X: {"operations_id": OP[X], "amount": "0.1", "comment": "api"}, {"adv"}, "operations_id = %d"),
    ]
    for kind, f, groupes, cle in CREATIONS:
        for X in ("A", "B"):
            cible = TN[X] if kind != "Compensation" else OP[X]
            avant = int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]} WHERE {cle % cible}"))
            reussis = set()
            for r in ROLES:
                essai(cli_api[r], "POST", f"/{CLASSE}{kind}", {"input": f(X)})
                maintenant = int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]} WHERE {cle % cible}"))
                if maintenant > avant:
                    reussis.add(r)
                    sql_ecrire(f"DELETE FROM {T}{TABLE[kind]} WHERE {cle % cible} AND id NOT IN ({','.join(str(i) for i in O[kind].values())});"
                               f"DELETE FROM glpi_logs WHERE itemtype = 'GlpiPlugin\\\\Points\\\\{kind}' AND items_id NOT IN ({','.join(str(i) for i in O[kind].values())}) AND date_mod >= '{INSTANT}';")
            voulus = {r for r in ROLES if GROUPE[r] in groupes and X in PORTEE[r]}
            v(f"(2) API POST {kind} (client {X}) : comptes qui créent", sorted(reussis), sorted(voulus))
    # DELETE, avec et sans purge : aucune ligne ne disparaît
    lignes_avant = {k: sorted(sql(f"SELECT id FROM {T}{t}")) for k, t in TABLE.items() if k in O}
    for kind, par in O.items():
        for ident in par.values():
            for r in ROLES:
                essai(cli_api[r], "DELETE", f"/{CLASSE}{kind}/{ident}")
                essai(cli_api[r], "DELETE", f"/{CLASSE}{kind}/{ident}?force_purge=true")
    lignes_apres = {k: sorted(sql(f"SELECT id FROM {T}{t}")) for k, t in TABLE.items() if k in O}
    v("(2) API DELETE (avec et sans purge) : aucune ligne du plugin ne disparaît, quel que soit le compte", lignes_apres, lignes_avant)

    # listes globales (grilles, versions, prix, types d'intervention) : seul l'Admin (23) écrit ; objets jetables créés par l'Admin (témoin)
    adm = cli_api["adm"]
    g1 = creer(adm, f"{CLASSE}Grid", {"name": f"jetable-{SUF}", "unit_minutes": 60})
    vj = creer(adm, f"{CLASSE}GridVersion", {"plugin_points_grids_id": g1, "begin_date": d(-30)})
    cj = creer(adm, f"{CLASSE}GridCost", {"plugin_points_gridversions_id": vj, "plugin_points_interventiontypes_id": ASSIST, "daytype": 1, "cost": "1"})
    ij = creer(adm, f"{CLASSE}InterventionType", {"name": f"type-jetable-{SUF}"})
    v("(2) témoin : l'Admin crée une grille, une version, un prix et un type d'intervention par l'API", all(i > 0 for i in (g1, vj, cj, ij)), True)
    for kind, champ, ident, essayee in (("Grid", "name", g1, "renomme"), ("GridVersion", "comment", vj, "api"), ("GridCost", "cost", cj, "7.00"), ("InterventionType", "name", ij, "renomme")):
        origine = valeur(kind, ident, champ)
        reussis = set()
        for r in ROLES:
            cible = essayee  # remis à l'origine après chaque réussite : le même essai pour tous les comptes
            essai(cli_api[r], "PUT", f"/{CLASSE}{kind}/{ident}", {"input": {champ: cible}})
            if meme(valeur(kind, ident, champ), cible):
                reussis.add(r)
                sql_ecrire(f"UPDATE {T}{TABLE[kind]} SET {champ} = '{origine}' WHERE id = {ident};")
        v(f"(2) API PUT {kind}.{champ} : seul l'Admin (droit grid 23) écrit", sorted(reussis), ["adm"])
    ajoutes = {}
    for kind, entree in (("Grid", {"name": f"pirate-{SUF}", "unit_minutes": 60}), ("InterventionType", {"name": f"pirate-type-{SUF}"})):
        for r in ROLES:
            essai(cli_api[r], "POST", f"/{CLASSE}{kind}", {"input": {**entree, "name": entree["name"] + "-" + r}})
            n = int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]} WHERE name = '{entree['name']}-{r}'"))
            if n:
                ajoutes.setdefault(kind, set()).add(r)
        sql_ecrire(f"DELETE FROM {T}{TABLE[kind]} WHERE name LIKE 'pirate%-{SUF}-%';")
    v("(2) API POST Grid et InterventionType : seul l'Admin crée", {k: sorted(s) for k, s in ajoutes.items()}, {"Grid": ["adm"], "InterventionType": ["adm"]})
    for kind, ident in (("GridCost", cj), ("GridVersion", vj), ("InterventionType", ij), ("Grid", g1)):
        for r in ROLES:
            if r != "adm":
                essai(cli_api[r], "DELETE", f"/{CLASSE}{kind}/{ident}?force_purge=true")
        v(f"(2) API DELETE {kind} : les autres comptes ne suppriment rien", int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]} WHERE id = {ident}")), 1)
        essai(adm, "DELETE", f"/{CLASSE}{kind}/{ident}?force_purge=true")
        v(f"(2) témoin : l'Admin supprime son {kind} jetable", int(une(f"SELECT COUNT(*) FROM {T}{TABLE[kind]} WHERE id = {ident}")), 0)



# =====================================================================================================================
# (3) Interface : onglet « Points » du contrat et du ticket, formulaires d'écriture, pages, export natif
# =====================================================================================================================
CONTRAT_LU = {"rc", "bu", "adv", "adm"}  # droit natif de lecture des contrats (modèles : Observer, Supervisor, Admin ; ni Technician ni Self-Service)
RECURSIF_LU = "A"  # le contrat récursif R est dans l'entité A : lu comme A


def onglet_contrat(w, cid):
    return w.get("/ajax/common.tabs.php?_target=/front/contract.form.php&_itemtype=Contract&_glpi_tab="
                 + urllib.parse.quote("GlpiPlugin\\Points\\Contract$1", safe="") + f"&id={cid}")


def onglet_ticket(w, tk):
    return w.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                 + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={tk}")


for X in ("A", "B", "R"):
    voient, lient = set(), set()
    for r in ROLES:
        voit = 'id="points-balance"' in onglet_contrat(web(r), CT[X])[1]
        code, fiche = web(r).get(f"/front/contract.form.php?id={CT[X]}")
        if voit:
            voient.add(r)
        if code == 200 and "GlpiPlugin%5CPoints%5CContract%241" in fiche:
            lient.add(r)
    voulus = {r for r in ROLES if GROUPE[r] in CONTRAT_LU and ("A" if X == "R" else X) in PORTEE[r]}
    v(f"(3) fiche contrat {X} : comptes qui voient le contenu de l'onglet « Points »", sorted(voient), sorted(voulus))
    v(f"(3) fiche contrat {X} : comptes qui voient l'onglet dans la liste des onglets (même ensemble)", sorted(lient), sorted(voulus))
# Responsable client : en interface standard, l'onglet de SES contrats seulement ; en interface simplifiée, la fiche contrat elle-même est refusée
v("(3) Responsable client (interface standard) : voit l'onglet Points de son contrat A, pas celui de B",
  ('id="points-balance"' in onglet_contrat(web("rcA"), CT["A"])[1], 'id="points-balance"' in onglet_contrat(web("rcA"), CT["B"])[1]), (True, False))
v("(3) Responsable client (interface simplifiée) : la fiche contrat native est refusée (403), même pour son propre contrat ; témoin : l'interface standard l'ouvre",
  (web("rcsA").get(f"/front/contract.form.php?id={CT['A']}")[0], web("rcsA").get(f"/front/contract.form.php?id={CT['B']}")[0], web("rcA").get(f"/front/contract.form.php?id={CT['A']}")[0]), (403, 403, 200))
v("(3) Responsable client (interface simplifiée) : l'onglet Points n'est pas servi non plus par l'URL d'onglet",
  onglet_contrat(web("rcsA"), CT["A"])[1], "")

# onglet Points du ticket (droit plugin_points_ticket READ + lecture du ticket)
LIT_TICKET = {"tech", "bu", "adv", "adm"}
for X in ("A", "B"):
    voient = {r for r in ROLES if 'id="points-tasks"' in onglet_ticket(web(r), TK[X])[1]}
    v(f"(3) ticket du client {X} : comptes qui voient l'onglet « Points » (le Demandeur, demandeur du ticket A, ne le voit pas)", sorted(voient),
      sorted(r for r in ROLES if GROUPE[r] in LIT_TICKET and X in PORTEE[r]))

# formulaires d'écriture du contrat : « Facturation / ADV » seulement (plugin_points_envelope CREATE / UPDATE), pour les contrats de ses entités
n = [0]


def tour(nom, X, champs_de, mesure):
    reussis, codes = set(), {}
    for r in ROLES:
        n[0] += 1
        champs = champs_de(r, n[0])
        code = poster(web(r), nom, champs)
        codes[r] = code
        if mesure(r, n[0], champs):
            reussis.add(r)
    return reussis, codes


for X in ("A", "B"):
    voulus = sorted(r for r in ROLES if GROUPE[r] == "adv" and X in PORTEE[r])
    ok_, codes = tour("envelope", X, lambda r, i: {"add": 1, "contracts_id": CT[X], "name": f"E-{r}-{i}-{SUF}", "credit": "1", "begin_date": d(-5), "end_date": d(100), "is_carryover": 0},
                      lambda r, i, c: int(une(f"SELECT COUNT(*) FROM {T}envelopes WHERE name = '{c['name']}'")) == 1)
    v(f"(3) formulaire « nouvelle enveloppe » du contrat {X} : comptes qui créent", sorted(ok_), voulus)
    v(f"(3) formulaire « nouvelle enveloppe » du contrat {X} : refus = 403 pour tous les autres", sorted(r for r, c in codes.items() if c == 403), sorted(set(ROLES) - set(voulus)))
    ok_, codes = tour("credit", X, lambda r, i: {"add": 1, "contracts_id": CT[X], "envelopes_id": O["Envelope"][X], "amount": "1", "comment": f"credit-{r}-{i}-{SUF}"},
                      lambda r, i, c: int(une(f"SELECT COUNT(*) FROM {T}operations WHERE comment = '{c['comment']}'")) == 1)
    v(f"(3) formulaire « crédit » du contrat {X} : comptes qui créditent", sorted(ok_), voulus)
    ok_, codes = tour("correction", X, lambda r, i: {"add": 1, "contracts_id": CT[X], "operations_id": OP[X], "amount": "-0.1", "reason": f"correction-{r}-{i}-{SUF}"},
                      lambda r, i, c: int(une(f"SELECT COUNT(*) FROM {T}operations WHERE comment = '{c['reason']}'")) == 1)
    v(f"(3) formulaire « correction » du contrat {X} : comptes qui corrigent", sorted(ok_), voulus)
    ok_, codes = tour("carryover", X, lambda r, i: {"add": 1, "contracts_id": CT[X], "from_envelopes_id": O["Envelope"][X], "to_envelopes_id": O["Envelope"][X]},
                      lambda r, i, c: False)
    v(f"(3) formulaire « report » du contrat {X} : refusé (403) à tous sauf l'ADV des entités du contrat (qui passe : 302, report éventuellement impossible)",
      sorted(r for r, c in codes.items() if c != 403), voulus)
    ok_, codes = tour("contract", X, lambda r, i: {"update": 1, "contracts_id": CT[X], "plugin_points_grids_id": grille, "alert_threshold": 40 + i % 50, "is_active": 1},
                      lambda r, i, c: une(f"SELECT alert_threshold FROM {T}contracts WHERE contracts_id = {CT[X]}") == str(c["alert_threshold"]))
    v(f"(3) formulaire « réglage du contrat » {X} : comptes qui modifient", sorted(ok_), voulus)

# réglage Points d'un ticket (plugin_points_ticket UPDATE : technicien, ADV, Admin)
for X in ("A", "B"):
    ok_, codes = set(), {}
    for r in ROLES:
        codes[r] = poster(web(r), "ticket", {"update": 1, "tickets_id": TK[X], "contracts_id": CT[X], "plugin_points_interventiontypes_id": OT, "is_billable": 1})
        if une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE id = {O['Ticket'][X]}") == str(OT):
            ok_.add(r)
            sql_ecrire(f"UPDATE {T}tickets SET plugin_points_interventiontypes_id = {ASSIST} WHERE id = {O['Ticket'][X]};")
    v(f"(3) formulaire « réglage du ticket » {X} : comptes qui modifient", sorted(ok_), sorted(r for r in ROLES if GROUPE[r] in {"tech", "adv", "adm"} and X in PORTEE[r]))

# pages : droit de lecture par page
PAGES = {"review.php": {"bu", "adv", "adm"}, "compensation.php": {"bu", "adv", "adm"}, "grid.php": {"tech", "bu", "adv", "adm"}, "config.form.php": {"adm"}}
for page, groupes in PAGES.items():
    ouvrent = {r for r in ROLES if web(r).get(f"/plugins/points/front/{page}")[0] == 200}
    v(f"(3) page {page} : comptes qui l'ouvrent (droit de la table de conception)", sorted(ouvrent), sorted(r for r in ROLES if GROUPE[r] in groupes))
refus_config = {r: poster(web(r), "config", {"update": 1, "retention_months": 12}) for r in ROLES if GROUPE[r] != "adm"}
v("(3) paramétrage : l'enregistrement est refusé (403) à tous les comptes sans plugin_points_config UPDATE ; la valeur est inchangée",
  (sorted(set(refus_config.values())), une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'retention_months'")), ([403], "24"))
ajout = {}
for r in ROLES:
    poster(web(r), "interventiontype", {"add": 1, "name": f"type-form-{r}-{SUF}"})
    if int(une(f"SELECT COUNT(*) FROM {T}interventiontypes WHERE name = 'type-form-{r}-{SUF}'")):
        ajout[r] = True
sql_ecrire(f"DELETE FROM {T}interventiontypes WHERE name LIKE 'type-form-%-{SUF}';")
v("(3) formulaire des types d'intervention : seul l'Admin (grid CREATE) ajoute", sorted(ajout), ["adm"])

# export natif (front/report.dynamic.php, CSV) : même cloisonnement que la recherche
def export(r, kind, colonnes=(2, 80)):
    q = urllib.parse.urlencode({"item_type": "GlpiPlugin\\" + "Points\\" + kind, "display_type": -3, "start": 0, **{f"forcetoview[{i}]": c for i, c in enumerate(colonnes)}})
    return web(r).get("/front/report.dynamic.php?" + q)


ecarts = {}
for r in ROLES:
    code, csv = export(r, "Envelope", (1, 80))
    for X in ("A", "B"):
        if (ENV_NOM[X] in csv) != (r in attendus("Envelope", X)):
            ecarts[(r, X)] = (code, ENV_NOM[X] in csv)
    if (f"ENVELOPPE-RECURSIVE-{SUF}" in csv) != (r in attendus("Envelope", "R")):
        ecarts[(r, "R")] = code
v("(3) export CSV des enveloppes : chaque compte n'exporte que celles de ses clients (le contrat récursif R compris) (écarts)", ecarts, {})
ecarts = {}
for r in ROLES:
    code, csv = export(r, "Review")
    vus = sorted(int(x) for x in re.findall(r'^"(\d+)";', csv, re.M)) if code == 200 else []
    voulus = sorted(i for X, i in O["Review"].items() if r in attendus("Review", X))
    if vus != voulus:
        ecarts[r] = (code, vus, voulus)
v("(3) export CSV des reviews : chaque compte n'exporte que les reviews de ses clients ; refus (403) sans droit review READ (écarts)", ecarts, {})

# =====================================================================================================================
# (4) Contrat récursif R d'une entité mère A, vu et écrit depuis la sous-entité F (règle de conception : lecture et écriture des points
# seulement avec l'accès à l'entité propre du contrat ; la fiche native du contrat reste lisible de F, comme le veut la récursivité)
# =====================================================================================================================
code_f, page_f = onglet_contrat(web("rcF"), CT["R"])
v("(4) rcF (sous-entité F, interface standard) : l'onglet Points du contrat récursif R n'affiche ni solde ni enveloppe", ('id="points-balance"' in page_f, "RECURSIVE" in page_f), (False, False))
v("(4) témoin : rcF ouvre la fiche native de R (récursif), rcA (entité A) voit les points de R",
  (web("rcF").get(f"/front/contract.form.php?id={CT['R']}")[0], 'id="points-balance"' in onglet_contrat(web("rcA"), CT["R"])[1]), (200, True))
v("(4) l'ADV de la sous-entité F (central, entité F) ne voit pas non plus l'onglet de R", 'id="points-balance"' in onglet_contrat(web("advF"), CT["R"])[1], False)
n_ops = lambda: int(une(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {CT['R']}"))
avant = n_ops()
codes = {nom: poster(web("advF"), nom, champs) for nom, champs in (
    ("credit", {"add": 1, "contracts_id": CT["R"], "envelopes_id": O["Envelope"]["R"], "amount": "1", "comment": f"advF-{SUF}"}),
    ("correction", {"add": 1, "contracts_id": CT["R"], "operations_id": OP["R"], "amount": "-0.1", "reason": f"advF-{SUF}"}),
    ("envelope", {"add": 1, "contracts_id": CT["R"], "name": f"advF-{SUF}", "credit": "1", "begin_date": d(-5), "end_date": d(100), "is_carryover": 0}),
    ("carryover", {"add": 1, "contracts_id": CT["R"], "from_envelopes_id": O["Envelope"]["R"], "to_envelopes_id": O["Envelope"]["R"]}),
    ("contract", {"update": 1, "contracts_id": CT["R"], "plugin_points_grids_id": grille, "alert_threshold": 33, "is_active": 1}))}
v("(4) l'ADV de la sous-entité F n'écrit pas dans le journal du contrat récursif R de l'entité mère (403, rien d'écrit)",
  (codes, n_ops() - avant, int(une(f"SELECT COUNT(*) FROM {T}envelopes WHERE name = 'advF-{SUF}'")), une(f"SELECT alert_threshold FROM {T}contracts WHERE contracts_id = {CT['R']}")),
  ({k: 403 for k in codes}, 0, 0, "80"))
avant = n_ops()
codes_a = poster(web("adv"), "credit", {"add": 1, "contracts_id": CT["R"], "envelopes_id": O["Envelope"]["R"], "amount": "1", "comment": f"adv-R-{SUF}"})
v("(4) témoin : l'ADV des entités mères écrit sur R", (codes_a, n_ops() - avant), (302, 1))
v("(4) lecture de R par l'API depuis F : refusée (ligne du contrat, enveloppe, opération), acceptée depuis A (témoin)",
  ({k: lit("rcF", k, O[k]["R"]) for k in ("Contract", "Envelope", "Operation")}, {k: lit("rcA", k, O[k]["R"]) for k in ("Contract", "Envelope", "Operation")}),
  ({k: False for k in ("Contract", "Envelope", "Operation")}, {k: True for k in ("Contract", "Envelope", "Operation")}))

# =====================================================================================================================
# (5) Cloisonnement du débit : le lien ticket-contrat se crée sans contrôle d'entité (_contracts_id à la création du ticket, par un Demandeur
# par exemple) ; le débit ne peut pas être pris sur le contrat d'un autre client
# =====================================================================================================================
dem = cli_api["dem"]
tx = creer(dem, "Ticket", {"name": "croisé : ticket du client A lié au contrat de B", "content": "x", "entities_id": ent["A"], "_contracts_id": CT["B"]})
v("(5) le lien natif ticket A - contrat B se crée (comportement du cœur, non contrôlé par le plugin)",
  une(f"SELECT COUNT(*) FROM glpi_tickets_contracts WHERE tickets_id = {tx} AND contracts_id = {CT['B']}"), "1")
solde_b = Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT['B']}"))
tache_close(tx, mardi, 1, "croisée")
clore(tx)
v("(5) la tâche du ticket A n'est pas débitée sur le contrat de B (solde de B inchangé, aucune opération)",
  (Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT['B']}")), int(une(f"SELECT COUNT(*) FROM {T}operations WHERE tickets_id = {tx}"))), (solde_b, 0))
ty = creer(api, "Ticket", {"name": "témoin : ticket de F, contrat récursif R de A", "content": "x", "entities_id": ent["F"], "_contracts_id": CT["R"]})
tache_close(ty, mardi, 1, "récursif")
clore(ty)
v("(5) témoin : une tâche d'un ticket de la sous-entité F est débitée sur le contrat récursif R de A",
  une(f"SELECT COUNT(*) FROM {T}operations WHERE tickets_id = {ty} AND contracts_id = {CT['R']} AND type = 'debit_task'"), "1")
v("(5) le réglage Points d'un ticket refuse un contrat d'un autre client (non candidat)",
  (poster(web("adv"), "ticket", {"update": 1, "tickets_id": tx, "contracts_id": CT["B"], "plugin_points_interventiontypes_id": ASSIST, "is_billable": 1}), une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {tx} AND contracts_id = {CT['B']}")), (302, "0"))

# =====================================================================================================================
# (6) Page « Points » de l'interface simplifiée (Hooks::HELPDESK_MENU_ENTRY) : lecture seule des contrats des entités actives
# =====================================================================================================================
PAGE = "/plugins/points/front/points.php"
CNOM = {X: f"points-clois-contrat-{X}-{SUF}" for X in ("A", "B", "R")}
codes = {r: web(r).get(PAGE)[0] for r in ROLES}
v("(6) page Points : ouverte par les comptes de l'interface simplifiée qui ont plugin_points_balance READ (Responsable client), refusée (403) aux autres : "
  "profils standard (pare-feu STRATEGY_HELPDESK_ACCESS) et Demandeur sans droit",
  sorted(r for r, c in codes.items() if c == 200), ["rcsA", "rcsB", "rcsF"])
v("(6) page Points : refus = 403 (et non une redirection ou une page vide)", sorted({c for r, c in codes.items() if r not in ("rcsA", "rcsB", "rcsF")}), [403])


v("(6) sous-chemin de la page (front/points.php/x) : refusé aux profils standard (contrôle de non-régression ; la regex du pare-feu est vérifiée par le test unitaire) ; témoin : l'interface simplifiée ouvre la page elle-même",
  (sorted({web(r).get(PAGE + "/x")[0] for r in ("adv", "rcA", "tech")}), web("rcsA").get(PAGE)[0]), ([403], 200))
def contenu(r):
    return web(r).get(PAGE)[1]


pa, pb, pf = contenu("rcsA"), contenu("rcsB"), contenu("rcsF")
v("(6) Responsable client A : voit ses contrats (A et le contrat récursif R de son entité), pas celui de B ni ses enveloppes",
  (CNOM["A"] in pa, CNOM["R"] in pa, CNOM["B"] in pa, ENV_NOM["A"] in pa, ENV_NOM["B"] in pa), (True, True, False, True, False))
v("(6) Responsable client B : voit B seulement", (CNOM["B"] in pb, ENV_NOM["B"] in pb, CNOM["A"] in pb, CNOM["R"] in pb, ENV_NOM["A"] in pb), (True, True, False, False, False))
v("(6) compte de la sous-entité F : aucun contrat (celui de l'entité mère A, récursif ou non, n'est pas dans ses entités) : message « aucun contrat »",
  (CNOM["A"] in pf, CNOM["R"] in pf, CNOM["B"] in pf, 'id="points-none"' in pf), (False, False, False, True))
soldes = {X: Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT[X]}")) for X in ("A", "B")}
affiche = {X: re.search(r'data-contract="%d".*?class="points-balance">(-?[\d.]+)<' % CT[X], pa if X == "A" else pb, re.S) for X in ("A", "B")}
v("(6) le solde affiché est la somme du journal du contrat", {X: Decimal(m.group(1)) if m else None for X, m in affiche.items()}, soldes)
v("(6) la page montre les échéances des enveloppes (date de fin) et le registre (débit de tâche, ticket)", (d(200) in pa, "Task debit" in pa, f"#{TK['A']}" in pa), (True, True, True))
v("(6) le registre n'expose ni le commentaire interne d'une opération ni son auteur ; témoin : l'onglet du contrat de l'ADV les montre",
  (f"commentaire-A-{SUF}" in pa, "glpi" in re.search(r"points-ledger.*?</table>", pa, re.S).group(0),
   f"commentaire-A-{SUF}" in onglet_contrat(web("adv"), CT["A"])[1]), (False, False, True))
v("(6) la page est en lecture seule : aucun formulaire ni lien d'écriture du plugin", re.findall(r"plugins/points/front/\w+\.form\.php", pa), [])
_, accueil_rcs = web("rcsA").get("/Helpdesk")
_, accueil_dem = web("dem").get("/Helpdesk")
v("(6) entrée « Points » du menu de l'interface simplifiée : présente avec le droit de lecture des soldes, absente sans (Demandeur)",
  (PAGE in accueil_rcs, PAGE in accueil_dem), (True, False))

# =====================================================================================================================
# (7) Champs internes du journal (commentaire ou motif, détails, auteur, opération corrigée) : lus seulement avec plugin_points_envelope READ ou
# plugin_points_review READ (ADV, Responsable BU, Admin) ; jamais par le Responsable client ni le technicien, par aucune voie
# =====================================================================================================================
SECRET = f"secret-correction-{SUF}"
poster(web("adv"), "correction", {"add": 1, "contracts_id": CT["A"], "operations_id": OP["A"], "amount": "-0.1", "reason": SECRET})
corr = int(une(f"SELECT id FROM {T}operations WHERE comment = '{SECRET}'"))
env_adv = int(une(f"SELECT MIN(id) FROM {T}envelopes WHERE contracts_id = {CT['A']} AND users_id = {uid['adv']}"))
INTERNES = {"rcA", "rcsA", "tech"}   # sans droit interne ; buA, adv, adm l'ont
LISENT = {"rcA", "rcsA", "tech", "buA", "adv", "adm"}
ecarts = {}
for r in sorted(LISENT):
    interne = r not in INTERNES
    op = cli_api[r].get(f"{CLASSE}Operation", corr)
    vu = (op.get("comment"), op.get("details"), op.get("users_id"), op.get("operations_id_origin"))
    voulu = (SECRET, op.get("details"), uid["adv"], OP["A"]) if interne else (None, None, None, None)  # champs retirés, pas seulement vidés
    if vu != voulu or (interne and not op.get("details")) or (not interne and (op.get("amount") != "-0.10" or op.get("type") != "correction")):
        ecarts[(r, "getItem Operation")] = (vu, op.get("amount"), op.get("type"))
    if cli_api[r].get(f"{CLASSE}Envelope", env_adv).get("users_id") != (None if not interne else uid["adv"]):
        ecarts[(r, "getItem Envelope users_id")] = True
    ok_m, texte = essai(cli_api[r], "GET", f"/getMultipleItems?{multi([('Operation', corr), ('Envelope', env_adv)])}")
    if not ok_m or (SECRET in texte) == (not interne) or (f'"users_id": {uid["adv"]}' in texte) == (not interne):
        ecarts[(r, "getMultipleItems")] = (ok_m, SECRET in texte)
    recherche_brute = cli_api[r]._req("GET", f"/search/{CLASSE}Operation?forcedisplay[0]=2&forcedisplay[1]=6&forcedisplay[2]=8&forcedisplay[3]=4&range=0-999")
    texte = json.dumps(recherche_brute)
    ligne = next((x for x in recherche_brute.get("data", []) if int(x["2"]) == corr), None)
    if ligne is None or ligne.get("4") != "-0.10" or (SECRET in texte) == (not interne) or (("6" in ligne) != interne):
        ecarts[(r, "search Operation")] = (ligne, SECRET in texte)
    ok_f, texte_f = essai(cli_api[r], "GET", f"/search/{CLASSE}Operation?forcedisplay[0]=2&criteria[0][field]=6&criteria[0][searchtype]=contains&criteria[0][value]={SECRET}&range=0-999")
    trouves = sorted(int(x["2"]) for x in json.loads(texte_f).get("data", [])) if ok_f else None
    if (interne and trouves != [corr]) or (not interne and ok_f):
        ecarts[(r, "filtre sur le commentaire")] = (ok_f, trouves)
    # liste getItems, sous-éléments (parent natif et parent du plugin), filtre searchText sur le champ interne
    CLES = ("comment", "details", "users_id", "operations_id_origin")
    voies = {"getItems": f"/{CLASSE}Operation?range=0-999",
             "sous-élément du contrat natif": f"/Contract/{CT['A']}/{CLASSE}Operation?range=0-999",
             "sous-élément du contrat du plugin": f"/{CLASSE}Contract/{O['Contract']['A']}/{CLASSE}Operation?range=0-999",
             "searchText": f"/{CLASSE}Operation?searchText[comment]={SECRET}&range=0-999"}
    for nom, chemin in voies.items():
        ok_v, texte_v = essai(cli_api[r], "GET", chemin)
        lignes_v = json.loads(texte_v) if ok_v else []
        corr_v = next((x for x in lignes_v if x.get("id") == corr), None)
        retire = all(not any(k in x for k in CLES) for x in lignes_v)
        if nom == "searchText":
            # interne : seul le motif cherché est trouvé ; sinon le filtre est ignoré (aucune sonde) : la ligne n'est pas isolée
            mauvais = (interne and [x["id"] for x in lignes_v] != [corr]) or (not interne and (len(lignes_v) < 2 or SECRET in texte_v))
        else:
            mauvais = (interne and nom != "sous-élément du contrat du plugin" and (corr_v is None or corr_v.get("comment") != SECRET)) \
                or (not interne and ((not ok_v and not nom.startswith("sous")) or not retire or SECRET in texte_v))
        if mauvais:
            ecarts[(r, nom)] = (ok_v, [x.get("id") for x in lignes_v][:6], SECRET in texte_v)
    # historique natif du contrat (Historique) : rien du plugin ni de interne
    code_h, hist = web(r).get("/ajax/common.tabs.php?_target=/front/contract.form.php&_itemtype=Contract&_glpi_tab=Log%241&id=" + str(CT["A"]))
    if SECRET in hist or "GlpiPlugin" in hist:
        ecarts[(r, "historique natif")] = code_h
    code, csv = export(r, "Operation", (2, 6, 8, 4))
    if code != 200 or (SECRET in csv) == (not interne):
        ecarts[(r, "export")] = (code, SECRET in csv)
    code, page = onglet_contrat(web(r), CT["A"])
    if r in ("tech", "rcsA"):
        continue  # pas de droit natif de lecture du contrat : pas d'onglet (déjà vérifié en (3))
    if (SECRET in page) == (not interne) or ("points-balance" not in page):
        ecarts[(r, "onglet du contrat")] = (code, SECRET in page)
v("(7) champs internes (commentaire, détails, auteur, opération corrigée) : lus par l'ADV, le Responsable BU et l'Admin (témoins), vidés pour le Responsable client "
  "(standard et simplifié) et le technicien par getItem, getMultipleItems, search, filtre, export et onglet ; montant, type, date restent lus (écarts)", ecarts, {})
total = len(cli_api["rcA"]._req("GET", f"/search/{CLASSE}Operation?forcedisplay[0]=2&range=0-999").get("data", []))
ok_f, texte_f = essai(cli_api["rcA"], "GET", f"/search/{CLASSE}Operation?forcedisplay[0]=2&criteria[0][field]=6&criteria[0][searchtype]=contains&criteria[0][value]={SECRET}&range=0-999")
filtrees = len(json.loads(texte_f).get("data", [])) if ok_f else None
v("(7) filtre sur le commentaire sans droit interne : refusé ou ignoré (toutes les lignes), jamais appliqué : le Responsable client ne peut pas sonder les motifs",
  filtrees in (None, total), True)

# =====================================================================================================================
# (8) Changement d'entité d'un contrat à points (PUT, transfert natif) refusé ; forfait : le contrat doit rester candidat au débit du ticket
# =====================================================================================================================
sans_points = creer(api, "Contract", {"name": f"points-clois-sans-points-{SUF}", "entities_id": ent["A"]})
entite_de = lambda c: une(f"SELECT entities_id FROM glpi_contracts WHERE id = {c}")
essai(api, "PUT", f"/Contract/{CT['A']}", {"input": {"entities_id": ent["B"]}})
essai(api, "PUT", f"/Contract/{sans_points}", {"input": {"entities_id": ent["B"]}})
v("(8) PUT Contract.entities_id : refusé pour un contrat à points (entité et lignes inchangées), accepté pour un contrat sans points (témoin)",
  (entite_de(CT["A"]), entite_de(sans_points), une(f"SELECT entities_id FROM {T}operations WHERE id = {OP['A']}")), (str(ent["A"]), str(ent["B"]), str(ent["A"])))
essai(api, "PUT", f"/Contract/{CT['A']}", {"input": {"name": f"points-clois-contrat-A-{SUF}", "entities_id": ent["A"]}})
v("(8) témoin : une mise à jour du contrat à points qui garde son entité passe", une(f"SELECT name FROM glpi_contracts WHERE id = {CT['A']}"), f"points-clois-contrat-A-{SUF}")
cli("transfer", CT["A"], ent["B"])
sans_points2 = creer(api, "Contract", {"name": f"points-clois-sans-points2-{SUF}", "entities_id": ent["A"]})
cli("transfer", sans_points2, ent["B"])
v("(8) transfert natif (Transfer::moveItems, passe par update()) : refusé pour un contrat à points, effectué pour un contrat sans points (témoin)",
  (entite_de(CT["A"]), entite_de(sans_points2)), (str(ent["A"]), str(ent["B"])))

# forfait approuvé sur un ticket passé chez un autre client : débit refusé ; témoin : ticket resté chez son client
tz_ok, tz_ko = ticket("forfait resté", CT["A"], "A"), ticket("forfait déplacé", CT["A"], "A")
ids_f = {}
for nom, tk in (("ok", tz_ok), ("ko", tz_ko)):
    sql_ecrire(f"INSERT INTO {T}flatrates (entities_id, is_recursive, tickets_id, contracts_id, amount, description, status, users_id, approved_amount) "
               f"VALUES ({ent['A']}, 0, {tk}, {CT['A']}, 1.00, 'forfait-{nom}', 'approved', 1, 1.00);")
    ids_f[nom] = int(une(f"SELECT MAX(id) FROM {T}flatrates WHERE tickets_id = {tk}"))
sql_ecrire(f"UPDATE glpi_tickets SET entities_id = {ent['B']} WHERE id = {tz_ko};")
solde_a = Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT['A']}"))
r_ko = cli("flatrate_debit", ids_f["ko"])
v("(8) forfait approuvé d'un ticket passé chez un autre client : débit refusé, solde du contrat de l'ancien client inchangé, forfait toujours approuvé",
  (r_ko.get("violation"), Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT['A']}")), une(f"SELECT status FROM {T}flatrates WHERE id = {ids_f['ko']}")),
  ("flatrate_no_contract", solde_a, "approved"))
r_ok = cli("flatrate_debit", ids_f["ok"])
v("(8) témoin : le forfait du ticket resté chez son client est débité", ("ok" in r_ok and r_ok["ok"] > 0, Decimal(une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {CT['A']}"))), (True, solde_a - 1))

v("(6) aucune erreur du plugin dans les journaux PHP et SQL à la fin du scénario", erreurs_plugin(), [])
if RAPIDE:
    print("SAUTÉ (1)(2) : CLOIS_RAPIDE=1, matrice complète de l'API non jouée : une section sautée n'est pas verte (usage des mutations seulement)")
    sys.exit(2)
print("cloisonnement : vert" if v.ok else "cloisonnement : ECHEC")
sys.exit(0 if v.ok else 1)
