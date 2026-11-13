"""Socle du plugin Points (lot a) : tables, droits, rejeu de l'installation, désinstallation, réinstallation, journal PHP,
configuration, onglet de profil et page de configuration par le web. Part d'un plugin installé et activé (run.sh)."""
import re
import sys

import banc
from banc import Verif, console, sql, sql_ecrire, shell_app

v = Verif()

TABLES = ["allocations", "compensations", "contracts", "envelopes", "flatrates", "gridcosts", "grids", "gridversions",
          "interventiontypes", "lots", "operations", "reviews", "tasks", "tickets"]
TABLES = sorted(f"glpi_plugin_points_{t}" for t in TABLES)
# Droit => masque complet (Rights::definitions : somme des bits proposés)
DROITS = {"plugin_points_balance": 1, "plugin_points_ticket": 3, "plugin_points_envelope": 7,
          "plugin_points_flatrate": 1031, "plugin_points_review": 3, "plugin_points_compensation": 1029,
          "plugin_points_grid": 23, "plugin_points_config": 3}
CONFIG_CLES = ["allow_overdraft", "calendars_id", "contracttypes", "debit_moment", "default_alert_threshold",
               "default_interventiontypes_id", "flatrate_separation", "retention_months"]


def tables():
    return sorted(r[0] for r in sql("SELECT table_name FROM information_schema.tables WHERE table_schema = 'glpi' "
                                    "AND table_name LIKE 'glpi\\_plugin\\_points\\_%'"))


def droits():
    """{(profil, droit): rights} pour les droits du plugin."""
    return {(r[0], r[1]): int(r[2]) for r in sql(
        "SELECT p.name, pr.name, pr.rights FROM glpi_profilerights pr JOIN glpi_profiles p ON p.id = pr.profiles_id "
        "WHERE pr.name LIKE 'plugin\\_points\\_%'")}


def config():
    return {r[0]: r[1] for r in sql("SELECT name, value FROM glpi_configs WHERE context = 'plugin:points'")}


def profils():
    return [r[0] for r in sql("SELECT name FROM glpi_profiles ORDER BY id")]


def reinstaller(force=True):
    """plugin:install --force puis plugin:activate (à relancer après --force)."""
    sortie = []
    for args in (("plugin:install", "--force", "-u", "glpi", "points") if force else ("plugin:install", "-u", "glpi", "points"),
                 ("plugin:activate", "points")):
        p = console(*args)
        sortie.append(p)
        v(f"{' '.join(args)} : code de sortie", p.returncode, 0)
    return sortie


def lire_log(nom):
    """Lignes du journal GLPI contenant « points » (sans casse) ; journal absent = aucune."""
    p = shell_app(f"cat /var/glpi/logs/{nom} 2>/dev/null; true")
    return [l for l in p.stdout.splitlines() if re.search("points", l, re.I)]


# --- (i) tables et données initiales
v("(i) tables glpi_plugin_points_* présentes", tables(), TABLES)
moteurs = {r[0] for r in sql("SELECT engine FROM information_schema.tables WHERE table_schema = 'glpi' "
                             "AND table_name LIKE 'glpi\\_plugin\\_points\\_%'")}
v("(i) toutes en InnoDB", moteurs, {"InnoDB"})
types = sorted(r[0] for r in sql("SELECT name FROM glpi_plugin_points_interventiontypes"))
v("(i) types d'intervention", types, sorted(["Assistance", "Ordre de travail", "Intervention urgente"]))
cfg = config()
v("(i) clés de configuration plugin:points", sorted(cfg), CONFIG_CLES)
v("(i) défauts", (cfg["debit_moment"], cfg["default_alert_threshold"], cfg["allow_overdraft"], cfg["calendars_id"],
                  cfg["contracttypes"], cfg["retention_months"]), ("closure", "80", "1", "0", "[]", "24"))
assistance = sql("SELECT id FROM glpi_plugin_points_interventiontypes WHERE name = 'Assistance'")[0][0]
v("(i) type d'intervention par défaut = Assistance", cfg["default_interventiontypes_id"], assistance)
clients = sql("SELECT COUNT(*) FROM glpi_plugins WHERE directory = 'points' AND state = 1")[0][0]
v("(i) plugin activé dans glpi_plugins", clients, "1")

FLAT = r"'GlpiPlugin\\Points\\Flatrate'"


def notifs_forfait():
    """Notifications des forfaits : (événement, actif, mode, cible) et nombre de modèles et de traductions par défaut."""
    lignes = sql(f"SELECT n.event, n.is_active, l.mode, t.type, t.items_id FROM glpi_notifications n "
                 f"JOIN glpi_notifications_notificationtemplates l ON l.notifications_id = n.id "
                 f"JOIN glpi_notificationtargets t ON t.notifications_id = n.id WHERE n.itemtype = {FLAT} ORDER BY n.event")
    modeles = sql(f"SELECT COUNT(*), SUM(tr.language = '' AND tr.content_text LIKE '%##flatrate.amount##%' AND tr.content_html LIKE '%##lang.flatrate.amount##%') "
                  f"FROM glpi_notificationtemplates m JOIN glpi_notificationtemplatetranslations tr ON tr.notificationtemplates_id = m.id WHERE m.itemtype = {FLAT}")[0]
    return lignes, modeles


# Notifications natives des forfaits (lot d) : événement et cible de chacune, modèle texte et HTML
v("(i) notifications des forfaits : deux événements actifs par courriel, cibles BU (9001) et auteur (3)",
  notifs_forfait(), ([["flatrate_decided", "1", "mailing", "1", "3"], ["flatrate_submitted", "1", "mailing", "1", "9001"]], ["1", "1"]))

# --- (ii) droits : Super-Admin tout, les autres 0
d0 = droits()
tous = profils()
v("(ii) une ligne par profil et par droit", len(d0), len(tous) * len(DROITS))
v("(ii) Super-Admin : tous les bits de chaque droit",
  {n: m for (p, n), m in d0.items() if p == "Super-Admin"}, DROITS)
autres = {(p, n): m for (p, n), m in d0.items() if p != "Super-Admin" and m != 0}
v("(ii) autres profils : 0 partout", autres, {})
v("(ii) droit VALIDATE (1024) porté par le Super-Admin sur forfaits et compensations",
  (d0[("Super-Admin", "plugin_points_flatrate")] & 1024, d0[("Super-Admin", "plugin_points_compensation")] & 1024), (1024, 1024))

# --- (iii) rejeu de l'installation : rien d'existant ne change
sql_ecrire("UPDATE glpi_profilerights SET rights = 1 WHERE name = 'plugin_points_balance' "
           "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Technician');"
           "UPDATE glpi_profilerights SET rights = 1 WHERE name = 'plugin_points_grid' "
           "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');"
           "UPDATE glpi_configs SET value = '12' WHERE context = 'plugin:points' AND name = 'retention_months';"
           "UPDATE glpi_plugin_points_interventiontypes SET name = 'Renommé par l''admin' WHERE name = 'Ordre de travail';")
avant_droits, avant_cfg = droits(), config()
v("(iii) témoin : modification d'un droit posée avant rejeu",
  (avant_droits[("Technician", "plugin_points_balance")], avant_droits[("Super-Admin", "plugin_points_grid")]), (1, 1))
sql_ecrire(f"UPDATE glpi_notifications SET is_active = 0, name = 'renommée par l''admin' WHERE itemtype = {FLAT} AND event = 'flatrate_decided';")
reinstaller()
apres_droits, apres_cfg = droits(), config()
v("(iii) notifications des forfaits : rejeu sans doublon, réglage de l'administrateur conservé",
  (sql(f"SELECT COUNT(*) FROM glpi_notifications WHERE itemtype = {FLAT}")[0][0], sql(f"SELECT COUNT(*) FROM glpi_notificationtemplates WHERE itemtype = {FLAT}")[0][0],
   sql(f"SELECT name, is_active FROM glpi_notifications WHERE itemtype = {FLAT} AND event = 'flatrate_decided'")),
  ("2", "1", [["renommée par l'admin", "0"]]))
sql_ecrire(f"UPDATE glpi_notifications SET is_active = 1, name = 'Points: flatrate decision' WHERE itemtype = {FLAT} AND event = 'flatrate_decided';")
v("(iii) droits inchangés après rejeu (modifications conservées)", apres_droits, avant_droits)
v("(iii) Technician garde son droit modifié", apres_droits[("Technician", "plugin_points_balance")], 1)
v("(iii) Super-Admin garde son droit réduit (non réécrit)", apres_droits[("Super-Admin", "plugin_points_grid")], 1)
v("(iii) configuration inchangée (retention_months = 12 conservé)", apres_cfg, avant_cfg)
v("(iii) tables toujours présentes", tables(), TABLES)
types = sorted(r[0] for r in sql("SELECT name FROM glpi_plugin_points_interventiontypes"))
v("(iii) type renommé conservé au rejeu, aucun type recréé ni doublon", types, sorted(["Assistance", "Renommé par l'admin", "Intervention urgente"]))

# --- chemin de mise à jour : colonne et index supprimés, le rejeu les remet
def colonne_et_index():
    col = sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'glpi' "
              "AND table_name = 'glpi_plugin_points_tickets' AND column_name = 'is_billable'")[0][0]
    idx = sql("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = 'glpi' "
              "AND table_name = 'glpi_plugin_points_tickets' AND index_name = 'contracts_id'")[0][0]
    return col, idx


v("(maj) témoin : colonne et index présents avant", colonne_et_index(), ("1", "1"))
sql_ecrire("ALTER TABLE glpi_plugin_points_tickets DROP COLUMN is_billable, DROP INDEX contracts_id;")
v("(maj) colonne et index supprimés (état d'une version antérieure)", colonne_et_index(), ("0", "0"))
reinstaller()
v("(maj) rejeu : colonne et index revenus", colonne_et_index(), ("1", "1"))
v("(maj) rejeu : la colonne ajoutée a son défaut (is_billable = 1)",
  sql("SELECT column_default FROM information_schema.columns WHERE table_schema = 'glpi' "
      "AND table_name = 'glpi_plugin_points_tickets' AND column_name = 'is_billable'")[0][0], "1")

# --- (vi) journal PHP : témoin négatif (rien) et positif (le détecteur voit une ligne injectée)
console("list")
console("plugin:list")
for nom in ("php-errors.log", "sql-errors.log"):
    v(f"(vi) {nom} : aucune erreur du plugin après les commandes console", lire_log(nom), [])
shell_app("echo '[2026-01-01 00:00:00] glpiphplog.WARNING: témoin plugins/points/setup.php' >> /var/glpi/logs/php-errors.log")
v("(vi) témoin positif : une ligne « points » injectée est détectée", len(lire_log("php-errors.log")), 1)
shell_app("grep -v 'témoin plugins/points' /var/glpi/logs/php-errors.log > /var/glpi/logs/php-errors.tmp; "
          "mv /var/glpi/logs/php-errors.tmp /var/glpi/logs/php-errors.log")
v("(vi) témoin retiré", lire_log("php-errors.log"), [])

# --- web : onglet de profil et page de configuration
web = banc.Web()
# Profile exige une réauthentification en 12 (Profile::itemTypeRequiresReauthentication) : /ReAuth/Verify avec le mot de passe
code, _ = web.get("/front/profile.form.php?id=4")
v("(web) fiche Profile : réauthentification demandée (redirection)", code, 302)
code, _ = web.post("/ReAuth/Verify", [("user_input", "glpi")])
v("(web) réauthentification acceptée", code, 200)
tech = sql("SELECT id FROM glpi_profiles WHERE name = 'Technician'")[0][0]
onglet = f"/ajax/common.tabs.php?_target=/front/profile.form.php&_itemtype=Profile&id={tech}&_glpi_tab=GlpiPlugin%5CPoints%5CProfile%241"
code, html = web.get(onglet)
v("(web) onglet « Points » du profil : HTTP", code, 200)
v("(web) onglet : une ligne par droit du plugin", sorted(set(re.findall(r'name="_(plugin_points_\w+)\[', html))), sorted(DROITS))
v("(web) onglet : colonne Valider (1024) sur forfaits et compensations",
  sorted(set(re.findall(r'name="_(plugin_points_\w+)\[1024_', html))), ["plugin_points_compensation", "plugin_points_flatrate"])
v("(web) onglet : libellé français « Valider »", "Valider" in html or "Validate" in html, True)
f = next(f for f in banc.formulaires(html) if "profile.form.php" in f["action"])
champs = dict(f["champs"])
champs.update({"_plugin_points_review[1_0]": "1", "_plugin_points_review[2_0]": "1", "_plugin_points_flatrate[1024_0]": "1", "update": "1"})
code, _ = web.post(f["action"], list(champs.items()), {"Sec-Fetch-Site": "cross-site"})
v("(web) enregistrement du profil depuis une autre origine refusé", code, 403)
v("(web) rien écrit", droits()[("Technician", "plugin_points_review")], 0)
code, _ = web.post(f["action"], list(champs.items()), {"Sec-Fetch-Site": "same-origin"})
v("(web) enregistrement de la matrice du plugin (même origine) : redirection", code, 302)
d = droits()
v("(web) droits du Technician écrits par la matrice (review 3, forfaits VALIDATE 1024)",
  (d[("Technician", "plugin_points_review")], d[("Technician", "plugin_points_flatrate")]), (3, 1024))
v("(web) autres droits du Technician inchangés (seul balance = 1, posé plus haut)",
  {n: m for (p, n), m in d.items() if p == "Technician" and m and n not in ("plugin_points_review", "plugin_points_flatrate")},
  {"plugin_points_balance": 1})
LIEN = "/plugins/points/front/config.form.php"
code, central = web.get("/front/central.php")
v("(web) menu : entrée Configuration > Points présente avec le droit", LIEN in central, True)
# Interface simplifiée (Responsable client) : l'onglet n'a que la ligne plugin_points_balance, et elle s'enregistre
ss = sql("SELECT id FROM glpi_profiles WHERE name = 'Self-Service'")[0][0]
code, html = web.get(f"/ajax/common.tabs.php?_target=/front/profile.form.php&_itemtype=Profile&id={ss}&_glpi_tab=GlpiPlugin%5CPoints%5CProfile%241")
v("(web) profil simplifié : onglet « Points » servi", code, 200)
v("(web) profil simplifié : seule la ligne plugin_points_balance", sorted(set(re.findall(r'name="_(plugin_points_\w+)\[', html))), ["plugin_points_balance"])
f = next(f for f in banc.formulaires(html) if "profile.form.php" in f["action"])
champs = dict(f["champs"])
champs.update({"_plugin_points_balance[1_0]": "1", "update": "1"})
code, _ = web.post(f["action"], list(champs.items()), {"Sec-Fetch-Site": "same-origin"})
v("(web) profil simplifié : enregistrement (redirection)", code, 302)
v("(web) profil simplifié : droit plugin_points_balance écrit", droits()[("Self-Service", "plugin_points_balance")], 1)
v("(web) profil simplifié : autres droits du plugin à 0",
  sum(m for (p, n), m in droits().items() if p == "Self-Service" and n != "plugin_points_balance"), 0)
code, page = web.get("/plugins/points/front/config.form.php")
v("(web) page de configuration : HTTP", code, 200)
v("(web) page : champ retention_months à 12", bool(re.search(r'name="retention_months"[^>]*value="12"', page)
                                                      or re.search(r'value="12"[^>]*name="retention_months"', page)), True)
formulaire = next((f for f in banc.formulaires(page) if "config.form.php" in f["action"]), None)
v("(web) page : formulaire d'enregistrement présent, sans jeton CSRF",
  (formulaire is not None, "_glpi_csrf_token" in dict(formulaire["champs"]) if formulaire else None), (True, False))
champs = dict(formulaire["champs"]) if formulaire else {}
champs.update({"retention_months": "99", "default_alert_threshold": "70", "debit_moment": "task", "update": "1"})
code, _ = web.post("/plugins/points/front/config.form.php", list(champs.items()), {"Sec-Fetch-Site": "cross-site"})
v("(web) POST d'une autre origine refusé", code, 403)
v("(web) rien écrit par le POST refusé", config()["retention_months"], "12")
code, _ = web.post("/plugins/points/front/config.form.php", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})
v("(web) POST de même origine accepté (redirection)", code, 302)
c = config()
v("(web) retention_months = 99 refusé : rien d'écrit, pas de bornage silencieux", (c["retention_months"], c["default_alert_threshold"], c["debit_moment"]), ("12", "80", "closure"))
champs["retention_months"] = "24"
code, _ = web.post("/plugins/points/front/config.form.php", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})
c = config()
v("(web) saisie valide : retention 24, seuil et moment enregistrés", (code, c["retention_months"], c["default_alert_threshold"], c["debit_moment"]), (302, "24", "70", "task"))
# Droit : un profil sans plugin_points_config n'accède pas à la page
sql_ecrire("UPDATE glpi_profilerights SET rights = 0 WHERE name = 'plugin_points_config' "
           "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
console("cache:clear")
web2 = banc.Web()
code, _ = web2.get("/plugins/points/front/config.form.php")
v("(web) sans le droit plugin_points_config : page refusée", code, 403)
code, central = web2.get("/front/central.php")
v("(web) sans le droit : entrée de menu absente (témoin négatif du menu)", LIEN in central, False)
# Lecture seule (plugin_points_config = 1) : la page s'affiche, mais un POST de même origine est refusé et rien n'est écrit
sql_ecrire("UPDATE glpi_profilerights SET rights = 1 WHERE name = 'plugin_points_config' "
           "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
console("cache:clear")
web3 = banc.Web()
code, _ = web3.get("/plugins/points/front/config.form.php")
v("(web) droit config = 1 : page lisible (témoin positif)", code, 200)
avant = config()
code, _ = web3.post("/plugins/points/front/config.form.php", [("retention_months", "6"), ("update", "1")], {"Sec-Fetch-Site": "same-origin"})
v("(web) droit config = 1 : POST de même origine refusé", code, 403)
v("(web) droit config = 1 : rien d'écrit", config(), avant)
sql_ecrire("UPDATE glpi_profilerights SET rights = 3 WHERE name = 'plugin_points_config' "
           "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
console("cache:clear")
# Saisie invalide : refusée en entier, rien n'est écrit (pas de remplacement silencieux par le défaut)
web4 = banc.Web()
avant = config()
for champ, valeur in (("retention_months", "36"), ("default_alert_threshold", "150"), ("debit_moment", "never")):
    code, _ = web4.post("/plugins/points/front/config.form.php",
                        [("retention_months", "6"), ("default_alert_threshold", "50"), (champ, valeur), ("update", "1")],
                        {"Sec-Fetch-Site": "same-origin"})
    v(f"(web) saisie invalide {champ}={valeur} : redirigée", code, 302)
    v(f"(web) saisie invalide {champ}={valeur} : aucune valeur écrite (même les valides de la saisie)", config(), avant)
code, _ = web4.post("/plugins/points/front/config.form.php", [("retention_months", "6"), ("update", "1")], {"Sec-Fetch-Site": "same-origin"})
v("(web) saisie valide : écrite (témoin positif)", config()["retention_months"], "6")

# --- (iv) désinstallation : objets du plugin créés avant, qui doivent disparaître
LIKE = r"'GlpiPlugin\\\\Points%'"
IT = r"'GlpiPlugin\\Points\\X'"
sql_ecrire(f"INSERT INTO glpi_notificationtemplates (name, itemtype) VALUES ('points-test', {IT});"
           f"INSERT INTO glpi_notifications (name, itemtype, event) VALUES ('points-test', {IT}, 'threshold');"
           f"INSERT INTO glpi_alerts (itemtype, items_id, type) VALUES ({IT}, 1, 1);"
           f"INSERT INTO glpi_displaypreferences (itemtype, num, rank, users_id) VALUES ({IT}, 1, 1, 0);"
           f"INSERT INTO glpi_savedsearches (name, itemtype, type) VALUES ('points-test', {IT}, 1);"
           f"INSERT INTO glpi_logs (itemtype, items_id, date_mod) VALUES ({IT}, 1, NOW());")
OBJETS = ("glpi_notificationtemplates", "glpi_notifications", "glpi_alerts", "glpi_displaypreferences", "glpi_savedsearches", "glpi_logs")


def objets_plugin():
    return {t: sql(f"SELECT COUNT(*) FROM {t} WHERE itemtype LIKE {LIKE}")[0][0] for t in OBJETS}


# Les notifications des forfaits (1 modèle, 2 notifications) s'ajoutent à l'objet de test
v("(iv) témoin : un objet du plugin dans chaque table avant désinstallation (et les notifications des forfaits)", objets_plugin(),
  {t: ("2" if t == "glpi_notificationtemplates" else "3" if t == "glpi_notifications" else "1") for t in OBJETS})
ids_notif = [r[0] for r in sql(f"SELECT id FROM glpi_notifications WHERE itemtype = {FLAT}")]
ids_modele = [r[0] for r in sql(f"SELECT id FROM glpi_notificationtemplates WHERE itemtype = {FLAT}")]
liens_notif = lambda: sql(f"SELECT (SELECT COUNT(*) FROM glpi_notificationtemplatetranslations WHERE notificationtemplates_id IN ({','.join(ids_modele)})), "
                          f"(SELECT COUNT(*) FROM glpi_notificationtargets WHERE notifications_id IN ({','.join(ids_notif)})), "
                          f"(SELECT COUNT(*) FROM glpi_notifications_notificationtemplates WHERE notifications_id IN ({','.join(ids_notif)}))")[0]
v("(iv) témoin : traduction, cibles et liens des notifications des forfaits présents", [int(x) > 0 for x in liens_notif()], [True, True, True])
p = console("plugin:uninstall", "points")
v("(iv) plugin:uninstall : code de sortie", p.returncode, 0)
v("(iv) plus aucune table glpi_plugin_points_%", tables(), [])
v("(iv) plus aucune ligne glpi_profilerights plugin_points_%", sql("SELECT COUNT(*) FROM glpi_profilerights WHERE name LIKE 'plugin\\_points\\_%'")[0][0], "0")
v("(iv) plus aucune configuration plugin:points", sql("SELECT COUNT(*) FROM glpi_configs WHERE context = 'plugin:points'")[0][0], "0")
v("(iv) notification, modèle, alerte, préférence, recherche enregistrée et historique supprimés", objets_plugin(), {t: "0" for t in OBJETS})
v("(iv) traductions, cibles et liens des notifications des forfaits supprimés avec elles", liens_notif(), ["0", "0", "0"])
v("(iv) plus d'action automatique du plugin",
  sql(f"SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE {LIKE}")[0][0], "0")
v("(iv) aucune erreur du plugin dans les journaux", lire_log("php-errors.log") + lire_log("sql-errors.log"), [])

# --- (v) réinstallation propre
reinstaller(force=False)
v("(v) tables rétablies", tables(), TABLES)
v("(v) droits rétablis : Super-Admin tout, autres 0",
  ({n: m for (p, n), m in droits().items() if p == "Super-Admin"}, {k: m for k, m in droits().items() if k[0] != "Super-Admin" and m}), (DROITS, {}))
v("(v) configuration aux défauts", (config()["retention_months"], config()["debit_moment"]), ("24", "closure"))
v("(v) aucune erreur du plugin dans les journaux", lire_log("php-errors.log") + lire_log("sql-errors.log"), [])

print("banc : socle " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
