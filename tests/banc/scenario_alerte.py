"""Alerte de seuil et reporting (lot g) : action automatique pointsalert (seuil calculé sur les lots valides, une notification par passage,
remise à zéro par un crédit ou par l'action, contrat inactif ou supprimé ignoré, destinataire = administrateur de l'entité du contrat), colonnes
de recherche du cache (prochaine échéance, % consommé : recalcul, migration rejouable), liste « Contrats à points » et options ajoutées au contrat
natif et à l'entité (filtres, tri, export), onglet « Points » de l'entité, avec deux clients (A, B), une entité fille (F) et un contrat récursif :
chaque compte ne voit que ses clients, aucun champ interne n'est exposé, un compte sans droit plugin_points_balance ne voit aucune option.
Part d'un plugin installé et activé (run.sh) ; remet la configuration comme il l'a trouvée."""
import atexit
import csv
import datetime
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
from decimal import Decimal
from zoneinfo import ZoneInfo

import banc
from banc import ErreurApi, Verif, console, shell_app, sql, sql_ecrire

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
CONTRAT_PTS = r"'GlpiPlugin\\Points\\Contract'"
MDP = "Alerte-points-1"
SUF = format(int(datetime.datetime.now().timestamp()) % 100000, "x")  # noms uniques : le scénario se rejoue sur un banc gardé
PARIS = ZoneInfo("Europe/Paris")
AUJOURDHUI = datetime.datetime.now(PARIS).date()
CONCEPTION = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "docs", "conception.md")
SECRET = f"SECRET-ALERTE-{SUF}"


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def une(requete):
    return sql(requete)[0][0]


def creer(api, itemtype, entree):
    return api._req("POST", f"/{itemtype}", {"input": entree})["id"]


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
    assert "ok" in r, r
    return r["ok"]


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def lecture(nom):
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                          capture_output=True, text=True).stdout


def erreurs_plugin():
    return [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]


def cron():
    p = shell_app("php front/cron.php --force pointsalert")
    v("(cron) pointsalert lancée sans erreur", (p.returncode, "rror" in p.stdout + p.stderr), (0, False))


# --- droits : table « Droits du plugin » de conception.md
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
v("(0) la table de conception.md donne les huit droits du plugin", len(DROITS), 8)

ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'") for c in ("contracttypes", "debit_moment", "allow_overdraft")}
ancien_notif = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'core' AND name = '{c}'") for c in ("use_notifications", "notifications_mailing")}


def remettre_config():
    for cle, valeur in ancien.items():
        config(cle, valeur)
    for cle, valeur in ancien_notif.items():
        console("config:set", cle, valeur)


atexit.register(remettre_config)

api = banc.Api()
bu = {k: creer(api, "ContractType", {"name": f"alerte-bu{k}-{SUF}"}) for k in (1, 2)}
config("contracttypes", f"[{bu[1]},{bu[2]}]")
config("allow_overdraft", "1")
console("config:set", "use_notifications", "1")
console("config:set", "notifications_mailing", "1")

# --- entités : Clients > A (> F), Clients > B ; administrateur (adresse) de A et de B
clients = creer(api, "Entity", {"name": f"alerte-clients-{SUF}", "entities_id": 0})
api = banc.Api()
ent = {"clients": clients}
ent["A"] = creer(api, "Entity", {"name": f"alerte-A-{SUF}", "entities_id": clients})
ent["B"] = creer(api, "Entity", {"name": f"alerte-B-{SUF}", "entities_id": clients})
api = banc.Api()
ent["F"] = creer(api, "Entity", {"name": f"alerte-Afille-{SUF}", "entities_id": ent["A"]})
api = banc.Api()
MAIL = {X: f"alerte-admin-{X.lower()}{SUF}@example.invalid" for X in ("A", "B")}
for X in ("A", "B"):
    sql_ecrire(f"UPDATE glpi_entities SET admin_email = '{MAIL[X]}', admin_email_name = 'Admin {X}' WHERE id = {ent[X]};")

# --- contrats : nom, entité, BU
SPEC = {"A1": ("A", 1), "A2": ("A", 2), "A3": ("A", 1), "A4": ("A", 2), "A5": ("A", 1), "A6": ("A", 1), "R": ("A", 1), "B1": ("B", 1)}
NOM = {k: f"alerte-contrat-{k}-{SUF}" for k in SPEC}
CT, ROW = {}, {}
for k, (e, b) in SPEC.items():
    CT[k] = creer(api, "Contract", {"name": NOM[k], "entities_id": ent[e], "contracttypes_id": bu[b], **({"is_recursive": 1} if k == "R" else {})})


def enveloppe(k, nom, cents, debut, fin, reportable=False, commentaire=""):
    return ok(led("createEnvelope", CT[k], nom, cents, debut, fin, reportable, commentaire))["envelopes_id"]


def debiter(k, cents, jour=None):
    ok(led("debit", CT[k], "debit_task", cents, jour or d(0), {"itemtype": "TicketTask", "items_id": 9000000 + int(datetime.datetime.now().timestamp()) % 100000 + cents}))


def ligne(k):
    return sql(f"SELECT id, balance, credited, consumed, consumed_pct, next_expiry, alert_threshold FROM {T}contracts WHERE contracts_id = {CT[k]}")[0]


def alertes(k):
    return int(une(f"SELECT COUNT(*) FROM glpi_alerts WHERE itemtype = {CONTRAT_PTS} AND items_id = {ROW[k]} AND type = 1"))


def notifs(k):
    return sql(f"SELECT recipient, name, body_text FROM glpi_queuednotifications WHERE itemtype = {CONTRAT_PTS} AND items_id = {ROW[k]} ORDER BY id")


# =====================================================================================================================
# (1) seuil sur les lots valides, une notification par passage, remise à zéro
# =====================================================================================================================
ENV_A1 = enveloppe("A1", "ENV-A1", 10000, d(-100), d(200), False, SECRET)
ROW["A1"] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT['A1']}"))
v("(1) témoin : seuil du contrat à points = 80 par défaut", ligne("A1")[6], "80")
debiter("A1", 7900)
cron()
v("(1) 79 % : aucune notification, aucune alerte", (len(notifs("A1")), alertes("A1")), (0, 0))
debiter("A1", 100)
cron()
n = notifs("A1")
v("(1) 80 % atteint : UNE notification en file, adressée à l'administrateur de l'entité du contrat, une alerte native (type seuil)",
  ([x[0] for x in n], alertes("A1")), ([MAIL["A"]], 1))
corps = n[0][2] if n else ""
v("(1) le message porte contrat, client, % consommé, seuil, solde, prochaine échéance (balises du modèle)",
  all(t in corps for t in (NOM["A1"], f"alerte-A-{SUF}", "80.00 %", "80 %", "20.00", d(200))), True)
cron()
cron()
v("(1) rejeu du passage : aucune notification de plus, toujours une alerte", (len(notifs("A1")), alertes("A1")), (1, 1))
debiter("A1", 500)
cron()
v("(1) 85 % : toujours la même alerte, pas de nouvelle notification", (len(notifs("A1")), alertes("A1")), (1, 1))
ok(led("credit", CT["A1"], ENV_A1, 10000, {"comment": SECRET}))
v("(1) un crédit qui fait repasser sous le seuil efface l'alerte tout de suite (même transaction, sans action automatique) ; 42,50 % en colonne",
  (alertes("A1"), ligne("A1")[4]), (0, "42.50"))
cron()
v("(1) toujours sous le seuil : rien de nouveau", len(notifs("A1")), 1)
debiter("A1", 7500)
cron()
v("(1) nouveau franchissement (80 %) : une deuxième notification", (len(notifs("A1")), alertes("A1")), (2, 1))
sql_ecrire(f"UPDATE {T}contracts SET alert_threshold = 95 WHERE id = {ROW['A1']};")
cron()
v("(1) seuil relevé à 95 % : l'action automatique efface l'alerte, sans notification", (len(notifs("A1")), alertes("A1")), (2, 0))
sql_ecrire(f"UPDATE {T}contracts SET alert_threshold = 80 WHERE id = {ROW['A1']};")
cron()
v("(1) seuil ramené à 80 % : l'alerte est de nouveau levée (troisième notification)", (len(notifs("A1")), alertes("A1")), (3, 1))

# (2) le seuil se calcule sur les lots valides, pas sur le cache : un lot échu consommé en entier pèse sur le cache, pas sur l'alerte
enveloppe("A2", "ENV-PASSEE", 10000, d(-400), d(-100))
debiter("A2", 10000, d(-300))
enveloppe("A2", "ENV-VALIDE", 10000, d(-50), d(300))
ROW["A2"] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT['A2']}"))
debiter("A2", 6000)
cron()
l2 = ligne("A2")
v("(2) cache : crédité 200, consommé 160, 80,00 % (le lot échu consommé compte)", (l2[2], l2[3], l2[4]), ("200.00", "160.00", "80.00"))
v("(2) lots valides : 60 % consommé, donc aucune notification (le cache seul aurait alerté)", (len(notifs("A2")), alertes("A2")), (0, 0))
debiter("A2", 2000)
cron()
v("(2) lots valides à 80 % : notification", (len(notifs("A2")), alertes("A2")), (1, 1))

# (3) contrat inactif, contrat natif supprimé : ignorés ; deuxième client : son administrateur seul
for k in ("A3", "A4", "B1"):
    enveloppe(k, f"ENV-{k}", 10000, d(-100), d(60) if k == "A4" else d(200))
    ROW[k] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT[k]}"))
    debiter(k, 9000)
sql_ecrire(f"UPDATE {T}contracts SET is_active = 0 WHERE id = {ROW['A3']};UPDATE glpi_contracts SET is_deleted = 1 WHERE id = {CT['A4']};")
cron()
v("(3) contrat à points inactif ou contrat natif supprimé à 90 % : aucune notification", (len(notifs("A3")), len(notifs("A4"))), (0, 0))
v("(3) témoin : le contrat actif du client B à 90 % est notifié à l'administrateur de B seulement", [x[0] for x in notifs("B1")], [MAIL["B"]])
sql_ecrire(f"UPDATE {T}contracts SET is_active = 1 WHERE id = {ROW['A3']};UPDATE glpi_contracts SET is_deleted = 0 WHERE id = {CT['A4']};")
cron()
v("(3) réactivés : une notification chacun, à l'administrateur de A", ([x[0] for x in notifs("A3")], [x[0] for x in notifs("A4")]), ([MAIL["A"]], [MAIL["A"]]))
v("(3) toutes les notifications du banc vont à l'administrateur de l'entité du contrat",
  {r[0] for r in sql(f"SELECT recipient FROM glpi_queuednotifications WHERE itemtype = {CONTRAT_PTS} AND items_id IN ({','.join(str(ROW[k]) for k in ('A1', 'A2', 'A3', 'A4'))})")}, {MAIL["A"]})

# =====================================================================================================================
# (4) colonnes de recherche du cache : prochaine échéance, % consommé
# =====================================================================================================================
ea = enveloppe("A5", "EA", 5000, d(-10), d(30))
enveloppe("A5", "EB", 5000, d(-5), d(200))
ROW["A5"] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT['A5']}"))
v("(4) prochaine échéance = plus petite échéance d'un lot au reste > 0", (ligne("A5")[5], ligne("A5")[4]), (d(30), "0.00"))
debiter("A5", 5000)
v("(4) premier lot épuisé : la prochaine échéance passe au lot suivant, 50,00 % consommé", (ligne("A5")[5], ligne("A5")[4]), (d(200), "50.00"))
sql_ecrire(f"UPDATE {T}contracts SET next_expiry = NULL, consumed_pct = 0 WHERE id = {ROW['A5']};")
led("recomputeCache", CT["A5"])
v("(4) recomputeCache remet les deux colonnes", (ligne("A5")[5], ligne("A5")[4]), (d(200), "50.00"))
# contrat aux lots épuisés : crédité, tout consommé, donc aucune échéance (NULL légitime) ; un rejeu de l'installation ne doit pas le réécrire
enveloppe("A6", "E6", 5000, d(-10), d(100))
debiter("A6", 5000)
ROW["A6"] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT['A6']}"))
v("(4) contrat aux lots épuisés : 100,00 % consommé, aucune prochaine échéance", (ligne("A6")[4], ligne("A6")[5]), ("100.00", "NULL"))
# migration rejouable : colonnes retirées, réinstallation, valeurs reprises depuis le journal ; un second rejeu ne change rien
avant = {k: ligne(k)[1:6] for k in ("A1", "A2", "A5", "A6", "B1")}
sql_ecrire(f"ALTER TABLE {T}contracts DROP COLUMN next_expiry, DROP COLUMN consumed_pct;")
for args in (("plugin:install", "--force", "-u", "glpi", "points"), ("plugin:activate", "points")):
    v(f"(4) migration : {' '.join(args)}", console(*args).returncode, 0)
v("(4) migration : colonnes recréées, valeurs reprises depuis le journal", {k: ligne(k)[1:6] for k in avant}, avant)
v("(4) migration : index de recherche recréés",
  sorted(r[0] for r in sql(f"SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = 'glpi' AND table_name = '{T}contracts' AND index_name IN ('consumed_pct', 'next_expiry', 'balance')")),
  ["balance", "consumed_pct", "next_expiry"])
cache_avant = [une(f"SELECT date_cache FROM {T}contracts WHERE id = {ROW[k]}") for k in ("A5", "A6")]
time.sleep(1.2)
for args in (("plugin:install", "--force", "-u", "glpi", "points"), ("plugin:activate", "points")):
    console(*args)
v("(4) second rejeu : ni un contrat déjà rempli ni un contrat aux lots épuisés (échéance NULL) n'est réécrit",
  [une(f"SELECT date_cache FROM {T}contracts WHERE id = {ROW[k]}") for k in ("A5", "A6")], cache_avant)
v("(4) rejeu : une seule action pointsalert", une("SELECT COUNT(*) FROM glpi_crontasks WHERE name = 'pointsalert'"), "1")
enveloppe("R", "ENV-R", 10000, d(-100), d(300))
ROW["R"] = int(une(f"SELECT id FROM {T}contracts WHERE contracts_id = {CT['R']}"))
debiter("R", 3000)
console("cache:clear")

# =====================================================================================================================
# (5) profils, comptes, recherche cloisonnée
# =====================================================================================================================
modele = {nom: une(f"SELECT id FROM glpi_profiles WHERE name = '{nom}'") for nom in ("Observer", "Technician", "Admin")}


def profil(nom, mod, colonne):
    pid = creer(api, "Profile", {"name": f"{nom} [alerte {SUF}]", "interface": "central"})
    sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {pid}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele[mod]} "
               "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    for droit, par_colonne in DROITS.items():
        sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({pid}, '{droit}', {par_colonne[colonne] if colonne else 0}) "
                   "ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    return pid


PROFILS = {"rc": profil("Responsable client", "Observer", COLONNES[0]), "tech": profil("Technicien N2", "Technician", COLONNES[1]),
           "adv": profil("Facturation / ADV", "Admin", COLONNES[3]), "sans": profil("Observer sans droit Points", "Observer", None)}
# le compte « sans droit Points » lit pourtant les contrats et les entités (droits natifs) : seul plugin_points_balance le distingue
sql_ecrire(f"UPDATE glpi_profilerights SET rights = rights | 1 WHERE name IN ('entity', 'contract') AND profiles_id = {PROFILS['sans']};")
console("cache:clear")
INSTANT = (datetime.datetime.now() - datetime.timedelta(seconds=5)).strftime("%Y-%m-%d %H:%M:%S")


def nettoyer():
    ids = ",".join(str(p) for p in PROFILS.values())
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id IN ({ids});DELETE FROM glpi_profilerights WHERE profiles_id IN ({ids});"
               f"DELETE FROM glpi_profiles WHERE id IN ({ids});DELETE FROM glpi_logs WHERE itemtype LIKE 'GlpiPlugin%Points%' AND date_mod >= '{INSTANT}';"
               "DELETE FROM glpi_queuednotifications WHERE itemtype LIKE 'GlpiPlugin%Points%';"
               f"DELETE FROM glpi_alerts WHERE itemtype = {CONTRAT_PTS};")
    console("cache:clear")


atexit.register(nettoyer)


def utilisateur(nom, profil_id, entite_id):
    return creer(api, "User", {"name": nom, "password": MDP, "password2": MDP, "_profiles_id": int(profil_id), "_entities_id": entite_id,
                               "_is_recursive": 1, "is_active": 1})


def droit_natif(r, nom):
    return int(une(f"SELECT rights FROM glpi_profilerights WHERE name = '{nom}' AND profiles_id = {PROFILS[ROLES[r][0]]}"))


ROLES = {"rcA": ("rc", "A"), "rcB": ("rc", "B"), "rcF": ("rc", "F"), "advF": ("adv", "F"), "tech": ("tech", "clients"), "adv": ("adv", "clients"), "sans": ("sans", "A")}
for r, (g, e) in ROLES.items():
    utilisateur(f"alerte-{r}{SUF}", PROFILS[g], ent[e])
cli_api = {r: banc.Api(f"alerte-{r}{SUF}", MDP) for r in ROLES}
_web = {}


def web(r):
    if r not in _web:
        _web[r] = banc.Web(f"alerte-{r}{SUF}", MDP)
    return _web[r]


# contrats (plugin) de chaque entité de données ; portée de chaque compte (l'entité fille F n'a aucun contrat ; le contrat récursif R est dans A)
PAR_ENTITE = {"A": {"A1", "A2", "A3", "A4", "A5", "A6", "R"}, "B": {"B1"}}
PORTEE = {"rcA": {"A"}, "rcB": {"B"}, "rcF": set(), "advF": set(), "tech": {"A", "B"}, "adv": {"A", "B"}, "sans": set()}
AVEC_DROIT = {"rcA", "rcB", "rcF", "advF", "tech", "adv"}  # plugin_points_balance READ


def voulus(r, filtre=lambda k: True):
    return sorted(k for e in PORTEE[r] for k in PAR_ENTITE[e] if filtre(k) and r in AVEC_DROIT)


def rech(r, itemtype, afficher=(2,), crit=(), tri=None):
    """Lignes (dicts option -> valeur) de la recherche native par l'API ; None si refusée."""
    q = "".join(f"&forcedisplay[{i}]={o}" for i, o in enumerate(afficher))
    for i, (champ, type_, valeur) in enumerate(crit):
        q += f"&criteria[{i}][field]={champ}&criteria[{i}][searchtype]={type_}&criteria[{i}][value]={urllib.parse.quote(str(valeur))}"
    if tri:
        q += f"&sort={tri[0]}&order={tri[1]}"
    try:
        return cli_api[r]._req("GET", f"/search/{itemtype}?range=0-999{q}").get("data", [])
    except ErreurApi:
        return None


def noms_vus(r, crit=(), tri=None):
    lignes = rech(r, CLASSE + "Contract", (2, 10), crit, tri)
    if lignes is None:
        return None
    mine = {NOM[k]: k for k in NOM}
    return [mine[l["10"]] for l in lignes if l.get("10") in mine]


# liste des contrats à points : par compte
for r in ROLES:
    vus = noms_vus(r)
    v(f"(5) liste des contrats à points, compte {r} : exactement ses clients" + (" (refusée sans droit)" if r == "sans" else ""),
      None if vus is None else sorted(vus), voulus(r) if r != "sans" else None)
# recherche par BU et par client
for r in ("rcA", "rcB", "adv", "tech"):
    for b in (1, 2):
        v(f"(5) recherche par BU {b}, compte {r}", sorted(noms_vus(r, [(11, "contains", f"alerte-bu{b}-{SUF}")])), voulus(r, lambda k, b=b: SPEC[k][1] == b))
    for X in ("A", "B"):
        v(f"(5) recherche par client {X}, compte {r} (un client ne peut pas chercher l'autre)",
          sorted(noms_vus(r, [(80, "contains", f"alerte-{X}-{SUF}")])), voulus(r, lambda k, X=X: SPEC[k][0] == X))
# filtres et tri sur les colonnes du cache
cache_sql = {k: {"balance": Decimal(ligne(k)[1]), "pct": Decimal(ligne(k)[4]), "exp": None if ligne(k)[5] == "NULL" else ligne(k)[5]} for k in SPEC}
v("(5) témoin : les contrats ont des % et des échéances différents", (len({c["pct"] for c in cache_sql.values()}) > 2, len({c["exp"] for c in cache_sql.values()}) > 1), (True, True))
hauts = sorted(k for k, c in cache_sql.items() if c["pct"] >= 80)
v("(5) filtre % consommé >= 80 (administrateur) : ni tous ni aucun contrat", (0 < len(hauts) < len(SPEC), sorted(noms_vus("adv", [(8, "contains", ">=80")]))), (True, hauts))
v("(5) même filtre, Responsable client A : les siens seulement", sorted(noms_vus("rcA", [(8, "contains", ">=80")])), sorted(k for k in hauts if SPEC[k][0] == "A"))
v("(5) filtre solde > 100 (administrateur)", sorted(noms_vus("adv", [(3, "contains", ">100")])), sorted(k for k, c in cache_sql.items() if c["balance"] > 100))
avant_d = sorted(k for k, c in cache_sql.items() if c["exp"] is not None and c["exp"] < d(100))
v("(5) filtre prochaine échéance avant J+100", (0 < len(avant_d) < len(SPEC), sorted(noms_vus("adv", [(9, "lessthan", d(100))]))), (True, avant_d))
ordre = noms_vus("adv", tri=(8, "DESC"))
v("(5) tri par % consommé décroissant : suite des % non croissante", [cache_sql[k]["pct"] for k in ordre], sorted((cache_sql[k]["pct"] for k in ordre), reverse=True))
ordre = noms_vus("adv", tri=(8, "ASC"))
v("(5) tri par % consommé croissant", [cache_sql[k]["pct"] for k in ordre], sorted(cache_sql[k]["pct"] for k in ordre))
ordre = [k for k in noms_vus("adv", tri=(9, "ASC")) if cache_sql[k]["exp"]]
v("(5) tri par prochaine échéance croissante", [cache_sql[k]["exp"] for k in ordre], sorted(cache_sql[k]["exp"] for k in ordre))
lignes = rech("adv", CLASSE + "Contract", (2, 10, 3, 4, 5, 8, 9, 11, 12, 80))
par_nom = {l["10"]: l for l in lignes if l.get("10") in NOM.values()}
a1 = par_nom[NOM["A1"]]
v("(5) colonnes de la liste (contrat A1) : solde, crédité, consommé, %, échéance, BU, client",
  (Decimal(a1["3"]), Decimal(a1["4"]), Decimal(a1["5"]), Decimal(a1["8"]), a1["9"][:10], a1["11"], a1["80"].endswith(f"alerte-A-{SUF}")),
  (Decimal(ligne("A1")[1]), Decimal(ligne("A1")[2]), Decimal(ligne("A1")[3]), Decimal(ligne("A1")[4]), ligne("A1")[5], f"alerte-bu1-{SUF}", True))


# --- options ajoutées au contrat natif et à l'entité
def options(r, itemtype):
    try:
        return cli_api[r]._req("GET", f"/listSearchOptions/{itemtype}")
    except ErreurApi:
        return None


OPT_CONTRAT = {"balance": "9701", "credited": "9702", "consumed": "9703", "pct": "9704", "expiry": "9705"}
OPT_ENTITE = {"balance": "9721", "credited": "9722", "consumed": "9723", "pct": "9724", "expiry": "9725"}


def ajoutees(r, itemtype):
    o = options(r, itemtype) or {}
    return sorted(k for k in o if k.isdigit() and int(k) >= 9700)


v("(5) options ajoutées au contrat natif : tous les comptes avec plugin_points_balance READ les ont, le compte sans ce droit aucune",
  {r: ajoutees(r, "Contract") != [] for r in ROLES}, {r: r in AVEC_DROIT for r in ROLES})
v("(5) options ajoutées à l'entité : de même", {r: ajoutees(r, "Entity") != [] for r in ROLES}, {r: r in AVEC_DROIT for r in ROLES})
# aucun champ interne ni table du journal parmi les options, quel que soit le compte
AUTORISES = {"glpi_plugin_points_contracts": {"id", "balance", "credited", "consumed", "consumed_pct", "next_expiry", "alert_threshold", "is_active"},
             "glpi_plugin_points_grids": {"name"}}
intrus = {}
for r in ROLES:
    for itemtype in ("Contract", "Entity", CLASSE + "Contract"):
        for k, o in (options(r, itemtype) or {}).items():
            if isinstance(o, dict) and str(o.get("table", "")).startswith("glpi_plugin_points_") and o.get("field") not in AUTORISES.get(o["table"], set()):
                intrus[(r, itemtype, k)] = (o["table"], o.get("field"))
v("(5) aucune option du contrat, de l'entité ni de la liste ne lit un champ interne ou une table du journal (commentaire, auteur, détails)", intrus, {})

NATIF = (2, 1) + tuple(int(x) for x in OPT_CONTRAT.values())


def plat(valeur):
    """Valeurs d'une cellule de l'API (texte, ou liste / dictionnaire quand la cellule regroupe plusieurs lignes), vides écartés."""
    if isinstance(valeur, dict):
        valeur = list(valeur.values())
    if not isinstance(valeur, list):
        valeur = [valeur]
    return [str(x) for x in valeur if x not in (None, "")]


for r in ("rcA", "rcB", "tech", "adv"):
    lignes = rech(r, "Contract", NATIF) or []
    lisibles = bool(droit_natif(r, "contract") & 1)
    visibles = {k: Decimal(plat(l[OPT_CONTRAT["balance"]])[0]) for k in NOM for l in lignes if l.get("1") == NOM[k] and plat(l.get(OPT_CONTRAT["balance"]))}
    vus = sorted(k for k in NOM if any(l.get("1") == NOM[k] for l in lignes))
    v(f"(5) recherche du contrat natif, compte {r} : contrats lus (droit natif) = ses clients seulement, le contrat de l'autre client jamais",
      vus, voulus(r) if lisibles else [])
    v(f"(5) recherche du contrat natif, compte {r} : colonnes de points = cache, pour ses contrats seulement",
      visibles, {k: Decimal(ligne(k)[1]) for k in voulus(r)} if lisibles else {})
# contrat récursif R : visible (droit natif) du compte de l'entité fille, mais SES POINTS ne le sont pas (ni valeur, ni filtre, ni tri)
ligne_r = next((l for l in (rech("advF", "Contract", NATIF) or []) if l.get("1") == NOM["R"]), None)
v("(5) contrat récursif R : lisible par le compte de l'entité fille (témoin positif du droit natif), colonnes de points vides",
  (ligne_r is not None, [plat(ligne_r.get(o)) for o in OPT_CONTRAT.values()] if ligne_r else None), (True, [[]] * 5))
sonde = rech("advF", "Contract", (1,), [(int(OPT_CONTRAT["balance"]), "contains", ">-1000000")])
v("(5) contrat récursif R : un filtre sur le solde par le compte de l'entité fille ne trouve rien (aucune sonde)", sonde, [])
sonde = rech("adv", "Contract", (1,), [(int(OPT_CONTRAT["balance"]), "contains", ">-1000000")])
v("(5) témoin : le même filtre par l'administrateur trouve R et les autres", sorted(k for k in NOM if any(l.get("1") == NOM[k] for l in sonde)), sorted(SPEC))
ordre = [l for l in rech("adv", "Contract", NATIF, tri=(int(OPT_CONTRAT["pct"]), "DESC")) if l.get("1") in NOM.values() and l.get(OPT_CONTRAT["pct"])]
v("(5) tri du contrat natif par % consommé décroissant", [Decimal(l[OPT_CONTRAT["pct"]]) for l in ordre], sorted((Decimal(l[OPT_CONTRAT["pct"]]) for l in ordre), reverse=True))
sans = rech("sans", "Contract", NATIF)
v("(5) compte sans droit plugin_points_balance : la recherche du contrat reste ouverte (droit natif), sans aucune colonne de points",
  (sans is not None and any(l.get("1") == NOM["A1"] for l in sans), [o for l in (sans or []) for o in OPT_CONTRAT.values() if plat(l.get(o))]), (True, []))
sonde = rech("sans", "Contract", (1,), [(int(OPT_CONTRAT["balance"]), "contains", ">-1000000")])
v("(5) compte sans droit : un critère sur une option de points est refusé (aucune sonde)", sonde, None)

# entité : la recherche de l'entité exige le droit natif de lecture des entités (Observer : non ; Admin : oui) ; colonnes de points selon la portée
ENT_NOM = {X: f"alerte-{X}-{SUF}" for X in ("A", "B")}
for r in ROLES:
    lisibles = bool(droit_natif(r, "entity") & 1)
    lignes = rech(r, "Entity", (2, 1) + tuple(int(x) for x in OPT_ENTITE.values())) or []
    avec = sorted(X for X in ENT_NOM for l in lignes if str(l.get("1", "")).endswith(ENT_NOM[X]) and plat(l.get(OPT_ENTITE["balance"])))
    v(f"(5) recherche de l'entité, compte {r} : les colonnes de points ne sont remplies que pour ses entités", avec, sorted(PORTEE[r]) if lisibles and r in AVEC_DROIT else [])
    vus_b = any(str(l.get("1", "")).endswith(ENT_NOM["B"]) for l in lignes)
    v(f"(5) recherche de l'entité, compte {r} : l'entité de l'autre client n'apparaît pas", vus_b, lisibles and ("B" in PORTEE[r] or r in ("tech", "adv")))
lignes = rech("adv", "Entity", (2, 1, int(OPT_ENTITE["balance"]), int(OPT_ENTITE["pct"])))
b = next(l for l in lignes if str(l.get("1", "")).endswith(ENT_NOM["B"]))
v("(5) entité B (administrateur) : solde et % du seul contrat de B", (Decimal(plat(b[OPT_ENTITE["balance"]])[0]), Decimal(plat(b[OPT_ENTITE["pct"]])[0])), (Decimal(ligne("B1")[1]), Decimal(ligne("B1")[4])))
a_ = next(l for l in lignes if str(l.get("1", "")).endswith(ENT_NOM["A"]))
v("(5) entité A (administrateur) : un solde par contrat à points de A, tous égaux au cache", sorted(Decimal(x) for x in plat(a_[OPT_ENTITE["balance"]])), sorted(Decimal(ligne(k)[1]) for k in PAR_ENTITE["A"]))
v("(5) entité (administrateur) : filtre sur le % consommé >= 80 ne garde que les entités qui ont un contrat à 80 % ou plus",
  sorted(X for X in ENT_NOM if any(str(l.get("1", "")).endswith(ENT_NOM[X]) for l in rech("adv", "Entity", (1,), [(int(OPT_ENTITE["pct"]), "contains", ">=80")]))),
  sorted({SPEC[k][0] for k in hauts}))
sonde = rech("advF", "Entity", (1,), [(int(OPT_ENTITE["balance"]), "contains", ">-1000000")])
v("(5) entité : un filtre sur le solde par le compte de l'entité fille ne trouve rien (il lit pourtant les entités : témoin ci-dessous)", sonde, [])
sonde = rech("adv", "Entity", (1,), [(int(OPT_ENTITE["balance"]), "contains", ">-1000000")])
v("(5) témoin : le même filtre par l'administrateur trouve les entités A et B", sorted(X for X in ENT_NOM if any(str(l.get("1", "")).endswith(ENT_NOM[X]) for l in sonde)), ["A", "B"])
sans = rech("sans", "Entity", (1,), [(int(OPT_ENTITE["balance"]), "contains", ">-1000000")])
v("(5) compte sans droit plugin_points_balance : critère sur une option de points de l'entité refusé", sans, None)

# aucun texte interne ni donnée de l'autre client dans AUCUNE des colonnes proposées (toutes les options de chaque type, tous les comptes)
fuites = {}
reussies, attendues = {}, {}
for r in ROLES:
    for itemtype in ("Contract", "Entity", CLASSE + "Contract"):
        # recherche possible = droit natif (contrat, entité) ou plugin_points_balance READ (liste) ; le nombre de recherches réussies est exigé
        attendues[(r, itemtype)] = bool(droit_natif(r, "contract") & 1) if itemtype == "Contract" else bool(droit_natif(r, "entity") & 1) if itemtype == "Entity" else r in AVEC_DROIT
        o = options(r, itemtype) or {}
        ids = [int(k) for k, x in o.items() if k.isdigit() and isinstance(x, dict) and x.get("field") != "id" and not x.get("nodisplay")]  # l'API refuse les options « ID » et non affichables avec forcedisplay
        reussies[(r, itemtype)] = False
        if not ids:
            continue
        try:
            texte = json.dumps(cli_api[r]._req("GET", f"/search/{itemtype}?range=0-999" + "".join(f"&forcedisplay[{i}]={n}" for i, n in enumerate(ids))), ensure_ascii=False)
        except ErreurApi:
            continue
        reussies[(r, itemtype)] = True
        for marque in (SECRET, "points-clois"):
            if marque in texte:
                fuites[(r, itemtype, marque)] = True
        if r == "rcA" and (NOM["B1"] in texte or ENT_NOM["B"] in texte):
            fuites[(r, itemtype, "autre client")] = True
        if r == "rcB" and (NOM["A1"] in texte or ENT_NOM["A"] in texte):
            fuites[(r, itemtype, "autre client")] = True
v("(5) toutes options affichées : les recherches aboutissent pour les comptes qui y ont droit, et pour eux seuls (aucun contrôle vide par erreur)", reussies, attendues)
v("(5) toutes options affichées, tous comptes : ni commentaire interne (crédit, enveloppe) ni donnée de l'autre client", fuites, {})
v("(5) témoin : le commentaire secret est bien dans le journal", int(une(f"SELECT COUNT(*) FROM {T}operations WHERE comment = '{SECRET}'")) >= 1, True)

# export natif (CSV) de la liste : même cloisonnement
def export(r, colonnes=(10, 80, 3, 8, 9)):
    q = urllib.parse.urlencode({"item_type": "GlpiPlugin\\Points\\Contract", "display_type": -3, "start": 0, **{f"forcetoview[{i}]": c for i, c in enumerate(colonnes)}})
    return web(r).get("/front/report.dynamic.php?" + q)


ecarts = {}
for r in ROLES:
    code, texte = export(r)
    vus = sorted(k for k in NOM if NOM[k] in texte) if code == 200 else []
    if vus != voulus(r):
        ecarts[r] = (code, vus)
v("(5) export CSV de la liste : chaque compte n'exporte que ses clients, rien sans droit (écarts)", ecarts, {})
code, csv_texte = export("adv")
cellules = {l[2]: l for l in csv.reader(csv_texte.lstrip("\ufeff").splitlines(), delimiter=";")}[NOM["A1"]]
v("(5) export CSV (administrateur) : solde, % et échéance de A1 dans le fichier", (Decimal(cellules[3]), Decimal(cellules[4]), cellules[5]),
  (Decimal(ligne("A1")[1]), Decimal(ligne("A1")[4]), ligne("A1")[5]))

# =====================================================================================================================
# (6) pages : liste (Outils > Points) et onglet « Points » de l'entité
# =====================================================================================================================
codes = {r: web(r).get("/plugins/points/front/contract.php")[0] for r in ROLES}
v("(6) page « Contrats à points » : ouverte avec plugin_points_balance READ, refusée sans (403)", codes, {r: 200 if r in AVEC_DROIT else 403 for r in ROLES})


menu = {r: "plugins/points/front/contract.php" in web(r).get("/front/central.php")[1] for r in ROLES}
v("(6) menu Outils > Points : l'entrée « Contrats à points » n'apparaît qu'avec plugin_points_balance READ", menu, {r: r in AVEC_DROIT for r in ROLES})


def onglet_entite(r, e):
    return web(r).get("/ajax/common.tabs.php?_target=/front/entity.form.php&_itemtype=Entity&_glpi_tab="
                      + urllib.parse.quote("GlpiPlugin\\Points\\Contract$1", safe="") + f"&id={ent[e]}")[1]


for X in ("A", "B"):
    voient = {r for r in ROLES if 'id="points-entity"' in onglet_entite(r, X)}
    v(f"(6) onglet « Points » de l'entité {X} : droit natif de lecture de l'entité, plugin_points_balance READ et accès à CETTE entité",
      sorted(voient), sorted(r for r in AVEC_DROIT if X in PORTEE[r] and droit_natif(r, "entity") & 1))
for X in ("A", "B"):
    lient = {r for r in ROLES if "GlpiPlugin%5CPoints%5CContract%241" in web(r).get(f"/front/entity.form.php?id={ent[X]}")[1]}
    v(f"(6) fiche de l'entité {X} : l'onglet « Points » est dans la liste des onglets pour les mêmes comptes seulement",
      sorted(lient), sorted(r for r in AVEC_DROIT if X in PORTEE[r] and droit_natif(r, "entity") & 1))
adv = onglet_entite("adv", "A")
v("(6) onglet de l'entité A (administrateur) : les contrats de A, pas celui de B, avec un lien vers chaque fiche",
  ({k for k in NOM if NOM[k] in adv}, NOM["B1"] in adv, f"contract.form.php?id={CT['A1']}" in adv), ({k for k in PAR_ENTITE["A"]}, False, True))
v("(6) onglet de l'entité A : solde et % de A1", (ligne("A1")[1] in adv, ligne("A1")[4] in adv), (True, True))
v("(6) l'onglet de la fiche entité fille F (aucun contrat) n'est pas proposé à l'administrateur",
  "GlpiPlugin%5CPoints%5CContract%241" in web("adv").get(f"/front/entity.form.php?id={ent['F']}")[1], False)
v("(6) témoin : l'onglet est proposé sur la fiche de l'entité A", "GlpiPlugin%5CPoints%5CContract%241" in web("adv").get(f"/front/entity.form.php?id={ent['A']}")[1], True)

# contrats natifs à la corbeille ou modèles : absents de la liste, de la recherche de l'entité, de l'onglet et du compte d'onglets
sql_ecrire(f"UPDATE glpi_contracts SET is_deleted = 1 WHERE id = {CT['B1']};UPDATE glpi_contracts SET is_template = 1, template_name = 'alerte-modele-{SUF}' WHERE id = {CT['A3']};")
vus = noms_vus("adv")
v("(6) liste « Contrats à points » : le contrat à la corbeille et le modèle n'y sont pas", (("B1" in vus), ("A3" in vus), ("A1" in vus)), (False, False, True))
ligne_b = next(l for l in rech("adv", "Entity", (1, int(OPT_ENTITE["balance"]))) if str(l.get("1", "")).endswith(ENT_NOM["B"]))
ligne_a = next(l for l in rech("adv", "Entity", (1, int(OPT_ENTITE["balance"]))) if str(l.get("1", "")).endswith(ENT_NOM["A"]))
v("(6) recherche de l'entité : la cellule de B (contrat à la corbeille) est vide, celle de A n'a plus le modèle",
  (plat(ligne_b.get(OPT_ENTITE["balance"])), len(plat(ligne_a.get(OPT_ENTITE["balance"])))), ([], len(PAR_ENTITE["A"]) - 1))
v("(6) onglet « Points » de l'entité B : absent de la fiche (plus aucun contrat), et l'onglet de A n'a plus le modèle",
  ("GlpiPlugin%5CPoints%5CContract%241" in web("adv").get(f"/front/entity.form.php?id={ent['B']}")[1], NOM["A3"] in onglet_entite("adv", "A"), NOM["A1"] in onglet_entite("adv", "A")), (False, False, True))
sql_ecrire(f"UPDATE glpi_contracts SET is_deleted = 0 WHERE id = {CT['B1']};UPDATE glpi_contracts SET is_template = 0, template_name = NULL WHERE id = {CT['A3']};")
v("(6) témoin : restaurés, ils réapparaissent", (("B1" in noms_vus("adv")), ("A3" in noms_vus("adv"))), (True, True))

# plusieurs utilisateurs successifs dans UN MÊME PROCESSUS PHP (comme l'action automatique des recherches enregistrées) : les options de points
# suivent l'utilisateur courant (entités actives, droit), pas le premier servi
logins = [f"alerte-{r}{SUF}" for r in ("adv", "advF", "sans", "rcA", "advF")]
rep_ = cli("search", logins, "Contract", [1, int(OPT_CONTRAT["balance"])])
if "ok" not in rep_:
    v("(6) recherche multi-utilisateurs dans un processus : exécutée", rep_, "ok")
else:
    def cellule(u, nom):
        l = next((l for l in u["rows"] if l["1"] == NOM[nom]), None)
        return None if l is None else plat(l[OPT_CONTRAT["balance"]])
    adv_, advf_, sans_, rca_, advf2 = rep_["ok"]
    solde_r = [f"{Decimal(ligne('R')[1]):.2f}"]
    v("(6) même processus : l'administrateur lit le solde de R, puis l'ADV de l'entité fille voit R (droit natif) SANS ses points",
      ([Decimal(x) for x in cellule(adv_, "R") or []], cellule(advf_, "R")), ([Decimal(x) for x in solde_r], []))
    v("(6) même processus : le compte sans droit n'a pas l'option et ne lit rien, le Responsable client A relit le solde de R, l'ADV fille toujours rien",
      (OPT_CONTRAT["balance"] in sans_["options"], cellule(sans_, "R"), [Decimal(x) for x in cellule(rca_, "R") or []], cellule(advf2, "R"), OPT_CONTRAT["balance"] in adv_["options"]),
      (False, [], [Decimal(x) for x in solde_r], [], True))

# un seuil modifié par l'API efface l'alerte, réévaluée au prochain passage (80 -> 95 % : rien ; consommation à 96 % : notifié)
n0 = len(notifs("A1"))
v("(6) témoin : le contrat A1 est alerté (80 %)", alertes("A1"), 1)
cli_api["adv"]._req("PUT", f"/{CLASSE}Contract/{ROW['A1']}", {"input": {"alert_threshold": 95}})
v("(6) seuil relevé à 95 % par l'API : l'alerte mémorisée est effacée tout de suite", alertes("A1"), 0)
cron()
v("(6) passage suivant : 80 % < 95 %, rien de notifié", (len(notifs("A1")), alertes("A1")), (n0, 0))
debiter("A1", 3200)
cron()
v("(6) consommation à 96 % : notifié au passage suivant", (len(notifs("A1")), alertes("A1")), (n0 + 1, 1))

# colonnes de recherche : jamais modifiables par l'API (cache), alors que le seuil l'est (témoin positif)
for champ, valeur in (("next_expiry", "2030-01-01"), ("consumed_pct", "1.00"), ("balance", "999.00")):
    avant = ligne("A1")
    try:
        cli_api["adv"]._req("PUT", f"/{CLASSE}Contract/{ROW['A1']}", {"input": {champ: valeur}})
    except ErreurApi:
        pass
    v(f"(7) mise à jour de {champ} par l'API : refusée, rien n'est écrit", ligne("A1"), avant)
cli_api["adv"]._req("PUT", f"/{CLASSE}Contract/{ROW['A1']}", {"input": {"alert_threshold": 81}})
v("(7) témoin : le seuil se modifie par l'API (droit plugin_points_envelope UPDATE)", ligne("A1")[6], "81")
cli_api["adv"]._req("PUT", f"/{CLASSE}Contract/{ROW['A1']}", {"input": {"alert_threshold": 80}})

v("(7) aucune erreur du plugin dans les journaux PHP et SQL", erreurs_plugin(), [])
print("banc : alerte " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
