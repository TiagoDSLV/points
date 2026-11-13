"""Désinstallation APRÈS usage réel (lot final) : le plugin sert d'abord (enveloppes, débits de tâche, review ajustée, compensation approuvée,
correction, forfait proposé au client avec sa validation native en attente, recherches enregistrées et colonnes d'affichage du contrat
et de l'entité portant des options de recherche du plugin), puis il est désinstallé : aucune trace ne doit rester, ni dans les tables du
plugin, ni dans celles du cœur (itemtype du plugin, options 9701-9707 et 9721-9725, tables filles des recherches enregistrées, validation
en attente et statut global du ticket). Réinstallation propre ensuite. Nommé pour passer en dernier : il remet le plugin à son état
d'installation. Part d'un plugin installé et activé (run.sh)."""
import atexit
import datetime
import json
import re
import subprocess
import sys
import time

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
AUJOURDHUI = datetime.date.today()
T = "glpi_plugin_points_"
SUF = str(int(time.time()))
MDP = "Zdes-points-1"


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def dernier(jour_iso, avant=1):
    jour = AUJOURDHUI - datetime.timedelta(days=avant)
    while jour.isoweekday() != jour_iso:
        jour -= datetime.timedelta(days=1)
    return jour.isoformat()


def led(appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def ok(r):
    return r["ok"] if "ok" in r else None


def creer(api_, itemtype, entree):
    return api_._req("POST", f"/{itemtype}", {"input": entree})["id"]


def une(requete):
    return sql(requete)[0][0]


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


def poster(web_, page, champs):
    return web_.post(f"/plugins/points/front/{page}", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})[0]


def reinstaller(force=True):
    for args in (("plugin:install", "--force", "-u", "glpi", "points") if force else ("plugin:install", "-u", "glpi", "points"), ("plugin:activate", "points")):
        p = console(*args)
        if p.returncode != 0:
            print(p.stdout + p.stderr)
    console("cache:clear")


# La désinstallation efface la configuration du plugin : sans réinstallation en cas d'échec, les autres exécutions repartiraient d'un banc cassé
atexit.register(lambda: None if une("SELECT COUNT(*) FROM glpi_plugins WHERE directory = 'points' AND state = 1") == "1" else reinstaller(False))

api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST = types["Assistance"]
moi = int(une("SELECT id FROM glpi_users WHERE name = 'glpi'"))
type_points = creer(api, "ContractType", {"name": f"zdes-points-{SUF}"})
config("contracttypes", f"[{type_points}]")
config("debit_moment", "closure")
config("flatrate_separation", "0")
config("compensation_separation", "0")
config("default_interventiontypes_id", str(ASSIST))
entite = creer(api, "Entity", {"name": f"points-zdes-{SUF}", "entities_id": 0})
api = banc.Api()
web = banc.Web()

sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Zdes-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Zdes-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for jour, cout in enumerate((1, 1.5, 2), start=1):
    sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
               f"VALUES (0, 1, {version}, {ASSIST}, {jour}, {cout});")

# client avec le droit natif de validation (un compte qui peut chiffrer ou valider un forfait ne peut pas en être l'approbateur)
modele_ss = une("SELECT id FROM glpi_profiles WHERE name = 'Self-Service'")
p_client = creer(api, "Profile", {"name": f"zdes-client-{SUF}", "interface": "helpdesk"})
sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {p_client}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele_ss} "
           "ON DUPLICATE KEY UPDATE rights = VALUES(rights);"
           f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({p_client}, 'ticketvalidation', {4096 + 8192}) ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
client_id = creer(api, "User", {"name": f"zdes-client{SUF}", "password": MDP, "password2": MDP, "_profiles_id": int(p_client), "_entities_id": entite,
                                "_is_recursive": 1, "is_active": 1})
sql_ecrire(f"INSERT INTO glpi_useremails (users_id, is_default, is_dynamic, email) VALUES ({client_id}, 1, 0, 'zdes{SUF}@example.invalid');")


def nettoyer_client():
    """Le compte et le profil de test ne restent pas (les autres scénarios comptent les droits du plugin par profil)."""
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE profiles_id = {p_client}; DELETE FROM glpi_profilerights WHERE profiles_id = {p_client};"
               f"DELETE FROM glpi_profiles WHERE id = {p_client}; DELETE FROM glpi_useremails WHERE users_id = {client_id}; DELETE FROM glpi_users WHERE id = {client_id};")


atexit.register(nettoyer_client)

K = creer(api, "Contract", {"name": f"zdes-K-{SUF}", "entities_id": entite, "contracttypes_id": type_points})
led("createEnvelope", K, "E1", 4000, d(-100), d(200), True, "initial")
led("createEnvelope", K, "E2", 1000, d(190), d(500), False, "suivante")
sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {K};")


def ticket(nom):
    return creer(api, "Ticket", {"name": nom, "content": nom, "entities_id": entite, "_contracts_id": K, "_users_id_requester": client_id})


# --- usage réel : tâche du samedi débitée à la clôture (review), review ajustée, compensation approuvée, correction
t1 = ticket("zdes débit")
api._req("POST", "/TicketTask", {"input": {"tickets_id": t1, "content": "samedi", "actiontime": 3600, "date": dernier(6) + " 09:00:00"}})
api._req("PUT", f"/Ticket/{t1}", {"input": {"status": 6}})
debit = int(une(f"SELECT id FROM {T}operations WHERE tickets_id = {t1} AND type = 'debit_task'"))
review = int(une(f"SELECT id FROM {T}reviews WHERE operations_id = {debit}"))
v("(0) usage : débit de la tâche du samedi et sa review à contrôler", (une(f"SELECT amount FROM {T}operations WHERE id = {debit}"), une(f"SELECT status FROM {T}reviews WHERE id = {review}")), ("-1.50", "to_review"))
poster(web, "review.form.php", {"action": "adjust", "id": review, "amount": "0.5", "comment": "ajustement du banc"})
v("(0) usage : review ajustée (remboursement de 0.5)", une(f"SELECT status FROM {T}reviews WHERE id = {review}"), "adjusted")
poster(web, "compensation.form.php", {"action": "request", "operations_id": debit, "amount": "0.25", "comment": "geste commercial"})
comp = int(une(f"SELECT id FROM {T}compensations WHERE operations_id = {debit}"))
poster(web, "compensation.form.php", {"action": "approve", "id": comp, "approved": "0.25", "comment": "d'accord"})
v("(0) usage : compensation approuvée", une(f"SELECT status FROM {T}compensations WHERE id = {comp}"), "approved")
led("correction", K, debit, 10, "correction du banc")

# --- usage réel : forfait proposé au client (validation native en attente)
t2 = ticket("zdes forfait")
poster(web, "flatrate.form.php", {"action": "create", "tickets_id": t2, "amount": "6", "description": "forfait du banc"})
fid = int(une(f"SELECT id FROM {T}flatrates WHERE tickets_id = {t2}"))
poster(web, "flatrate.form.php", {"action": "submit", "tickets_id": t2, "id": fid})
poster(web, "flatrate.form.php", {"action": "accept", "tickets_id": t2, "id": fid, "validator": client_id, "comment": "ok BU"})
validation = une(f"SELECT ticketvalidations_id FROM {T}flatrates WHERE id = {fid}")
v("(0) usage : forfait proposé, validation native en attente, statut global « en attente »",
  (une(f"SELECT status FROM {T}flatrates WHERE id = {fid}"), une(f"SELECT status FROM glpi_ticketvalidations WHERE id = {validation}"), une(f"SELECT global_validation FROM glpi_tickets WHERE id = {t2}")),
  ("proposed", "2", "2"))
v("(0) usage : journal cohérent avant la désinstallation", ok(led("check", K)), [])

# courriels en file d'attente : un d'un objet du plugin (doit partir), un d'un ticket (doit rester)
sql_ecrire("DELETE FROM glpi_queuednotifications WHERE name LIKE 'zdes-%';"
           f"INSERT INTO glpi_queuednotifications (itemtype, items_id, mode, name, create_time) VALUES ('GlpiPlugin\\\\Points\\\\Flatrate', {fid}, 'mailing', 'zdes-plugin-{SUF}', NOW()), ('Ticket', {t2}, 'mailing', 'zdes-ticket-{SUF}', NOW());")
TEMOINS_AFFICHAGE = "SELECT COUNT(*) FROM glpi_displaypreferences WHERE (itemtype = 'Contract' AND num IN (1, 9720)) OR (itemtype = 'Entity' AND num IN (1, 9700))"
# --- usage réel : colonnes d'affichage et recherches enregistrées portant des options du plugin (et des témoins qui ne doivent pas partir)
# rejouable sur un banc gardé : restes d'un passage interrompu retirés d'abord
sql_ecrire("DELETE FROM glpi_displaypreferences WHERE (itemtype = 'Contract' AND num IN (9701, 9703, 9707, 9720)) OR (itemtype = 'Entity' AND num IN (9721, 9725, 9700));"
           "DELETE FROM glpi_savedsearches_users WHERE savedsearches_id IN (SELECT id FROM glpi_savedsearches WHERE name LIKE 'zdes-%');"
           "DELETE FROM glpi_savedsearches_alerts WHERE savedsearches_id IN (SELECT id FROM glpi_savedsearches WHERE name LIKE 'zdes-%');"
           "DELETE FROM glpi_savedsearches WHERE name LIKE 'zdes-%';")
sql_ecrire(
    "INSERT IGNORE INTO glpi_displaypreferences (itemtype, num, rank, users_id, interface) VALUES "
    f"('Contract', 9701, 90, {moi}, 'central'), ('Contract', 9703, 91, 0, 'central'), ('Contract', 9707, 92, {moi}, 'central'), "
    f"('Entity', 9721, 90, {moi}, 'central'), ('Entity', 9725, 91, 0, 'central'), "
    f"('Contract', 1, 93, {moi}, 'central'), ('Entity', 1, 92, {moi}, 'central'), ('Contract', 9720, 94, {moi}, 'central'), ('Entity', 9700, 93, {moi}, 'central');")
RECH = {
    "contrat-critere": ("Contract", "criteria[0][field]=9703&criteria[0][searchtype]=morethan&criteria[0][value]=0&itemtype=Contract&start=0"),
    "contrat-tri": ("Contract", "sort[0]=9701&order[0]=DESC&itemtype=Contract&start=0"),
    "entite-critere": ("Entity", "criteria[0][field]=9722&criteria[0][searchtype]=contains&criteria[0][value]=1&itemtype=Entity&start=0"),
    "temoin-contrat": ("Contract", "criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=9703&itemtype=Contract&start=0"),
    "temoin-entite": ("Entity", "criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=x&itemtype=Entity&start=0"),
    "plugin-liste": ("GlpiPlugin\\\\Points\\\\Contract", "criteria[0][field]=8&criteria[0][searchtype]=morethan&criteria[0][value]=50&itemtype=GlpiPlugin\\\\Points\\\\Contract&start=0"),
}
ids = {}
# glpi_savedsearches_users : un seul défaut par (utilisateur, type) ; utilisateurs synthétiques (identifiants >= 9 000 000), retirés par ce scénario
SYNTH = 9_000_000 + int(SUF) % 1_000_000
for rang, (nom, (itemtype, requete)) in enumerate(RECH.items()):
    sql_ecrire(f"INSERT INTO glpi_savedsearches (name, type, itemtype, users_id, is_private, query, date_creation) VALUES ('zdes-{nom}-{SUF}', 1, '{itemtype}', {moi}, 1, '{requete}', NOW());")
    ids[nom] = int(une(f"SELECT id FROM glpi_savedsearches WHERE name = 'zdes-{nom}-{SUF}'"))
    sql_ecrire(f"INSERT INTO glpi_savedsearches_users (users_id, itemtype, savedsearches_id) VALUES ({SYNTH + rang}, '{itemtype}', {ids[nom]});")
    sql_ecrire(f"INSERT INTO glpi_savedsearches_alerts (savedsearches_id, name, is_active, operator, value, frequency, date_creation) VALUES ({ids[nom]}, 'alerte-{nom}', 0, 0, 1, 0, NOW());")
v("(0) usage : six recherches enregistrées avec leurs tables filles", une(f"SELECT COUNT(*) FROM glpi_savedsearches WHERE name LIKE 'zdes-%-{SUF}'"), "6")

temoins_avant = une(TEMOINS_AFFICHAGE)
v("(1) avant : les colonnes témoins existent (au moins les deux numéros voisins)", int(temoins_avant) >= 2, True)

# --- état avant : le plugin est bien là
tables_avant = int(une("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi_plugin_points_%'"))
v("(1) avant : les tables du plugin existent", tables_avant >= 13, True)

# --- désinstallation
p = console("plugin:uninstall", "points")
v("(2) plugin:uninstall : code de sortie", p.returncode, 0)
if p.returncode != 0:
    print(p.stdout + p.stderr)

v("(2) plus aucune table du plugin", une("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi_plugin_points_%'"), "0")
v("(2) aucune ligne du cœur dont l'itemtype est une classe du plugin (toutes les tables qui ont un champ itemtype)",
  [t for (t,) in sql("SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'itemtype'")
   if int(une(f"SELECT COUNT(*) FROM `{t}` WHERE itemtype LIKE 'GlpiPlugin\\\\\\\\Points\\\\\\\\%'")) > 0], [])
v("(2) plus de configuration, de droits, d'action automatique du plugin",
  (une("SELECT COUNT(*) FROM glpi_configs WHERE context = 'plugin:points'"), une("SELECT COUNT(*) FROM glpi_profilerights WHERE name LIKE 'plugin_points_%'"),
   une("SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE 'GlpiPlugin%Points%' OR name IN ('pointsexpire', 'pointsalert')")), ("0", "0", "0"))
v("(2) colonnes d'affichage des options 9701-9707 et 9721-9725 retirées (contrat et entité)",
  une("SELECT COUNT(*) FROM glpi_displaypreferences WHERE (itemtype = 'Contract' AND num BETWEEN 9701 AND 9707) OR (itemtype = 'Entity' AND num BETWEEN 9721 AND 9725)"), "0")
v("(2) témoins : les colonnes du cœur et les numéros voisins (9720, 9700) restent", une(TEMOINS_AFFICHAGE), temoins_avant)
v("(2) recherches enregistrées qui utilisent une option du plugin ou une classe du plugin : supprimées",
  une(f"SELECT COUNT(*) FROM glpi_savedsearches WHERE name IN ('zdes-contrat-critere-{SUF}', 'zdes-contrat-tri-{SUF}', 'zdes-entite-critere-{SUF}', 'zdes-plugin-liste-{SUF}')"), "0")
v("(2) témoins : les recherches enregistrées sans option du plugin restent (9703 en simple valeur de critère)",
  une(f"SELECT COUNT(*) FROM glpi_savedsearches WHERE name IN ('zdes-temoin-contrat-{SUF}', 'zdes-temoin-entite-{SUF}')"), "2")
supprimees = ",".join(str(ids[n]) for n in ("contrat-critere", "contrat-tri", "entite-critere", "plugin-liste"))
v("(2) tables filles des recherches supprimées : aucun orphelin (utilisateurs par défaut, alertes)",
  (une(f"SELECT COUNT(*) FROM glpi_savedsearches_users WHERE savedsearches_id IN ({supprimees})"), une(f"SELECT COUNT(*) FROM glpi_savedsearches_alerts WHERE savedsearches_id IN ({supprimees})")), ("0", "0"))
v("(2) témoins : les tables filles des recherches conservées restent",
  (une(f"SELECT COUNT(*) FROM glpi_savedsearches_users WHERE savedsearches_id IN ({ids['temoin-contrat']}, {ids['temoin-entite']})"),
   une(f"SELECT COUNT(*) FROM glpi_savedsearches_alerts WHERE savedsearches_id IN ({ids['temoin-contrat']}, {ids['temoin-entite']})")), ("2", "2"))
v("(2) validation native en attente d'un forfait proposé : purgée, statut global du ticket recalculé (aucune validation : 1)",
  (une(f"SELECT COUNT(*) FROM glpi_ticketvalidations WHERE id = {validation}"), une(f"SELECT global_validation FROM glpi_tickets WHERE id = {t2}")), ("0", "1"))
v("(2) courriel en file d'attente d'un objet du plugin supprimé, celui d'un ticket reste", (une(f"SELECT COUNT(*) FROM glpi_queuednotifications WHERE name = 'zdes-plugin-{SUF}'"), une(f"SELECT COUNT(*) FROM glpi_queuednotifications WHERE name = 'zdes-ticket-{SUF}'")), ("0", "1"))
sql_ecrire(f"DELETE FROM glpi_queuednotifications WHERE name LIKE 'zdes-%';")
v("(2) notifications, modèles et alertes du plugin supprimés",
  (une("SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE 'GlpiPlugin%Points%'"), une("SELECT COUNT(*) FROM glpi_notificationtemplates WHERE itemtype LIKE 'GlpiPlugin%Points%'"),
   une("SELECT COUNT(*) FROM glpi_alerts WHERE itemtype LIKE 'GlpiPlugin%Points%'"), une("SELECT COUNT(*) FROM glpi_logs WHERE itemtype LIKE 'GlpiPlugin%Points%'")), ("0", "0", "0", "0"))
v("(2) le contrat natif, ses tickets et leurs tâches ne sont pas touchés", (une(f"SELECT COUNT(*) FROM glpi_contracts WHERE id = {K}"), une(f"SELECT COUNT(*) FROM glpi_tickets WHERE id IN ({t1}, {t2})"),
   une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {t1}")), ("1", "2", "1"))

# --- réinstallation propre
reinstaller(False)
v("(3) réinstallation : tables, droits, configuration par défaut, actions automatiques, colonnes de la liste",
  (une("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi_plugin_points_%'") == str(tables_avant),
   une("SELECT COUNT(*) FROM glpi_profilerights WHERE name LIKE 'plugin_points_%'") != "0",
   une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'compensation_on_expired'"),
   une("SELECT COUNT(*) FROM glpi_crontasks WHERE name IN ('pointsexpire', 'pointsalert')"),
   une("SELECT COUNT(*) FROM glpi_displaypreferences WHERE itemtype LIKE 'GlpiPlugin%Points%Contract' AND users_id = 0")),
  (True, True, "valid_lot", "2", "9"))
v("(3) la réinstallation n'a rien repris des anciens objets (journal vide)", une(f"SELECT COUNT(*) FROM {T}operations"), "0")
config("contracttypes", f"[{type_points}]")
K2 = creer(api, "Contract", {"name": f"zdes-K2-{SUF}", "entities_id": entite, "contracttypes_id": type_points})
v("(3) le plugin sert de nouveau : enveloppe, débit, contrôle", (("ok" in led("createEnvelope", K2, "E", 1000, d(-10), d(100), False, "")), "ok" in led("debit", K2, "debit_task", 300, d(-1), {}), ok(led("check", K2))),
  (True, True, []))
p = console("plugins:points:check")
v("(3) plugins:points:check : cohérent après réinstallation", (p.returncode, "consistent" in p.stdout), (0, True))

lecture = lambda nom: subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                                     capture_output=True, text=True).stdout
fautes = [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]
v("aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : zdesinstallation " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
