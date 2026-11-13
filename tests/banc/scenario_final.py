"""Lot final (corrections d'ensemble) : purge d'un contrat à points refusée, commande plugins:points:check et dépassement marqué dans le
journal, type d'intervention figé à la création du ticket, contrat d'un autre type refusé par les formulaires, import d'une ligne
semblable, remboursement sur un lot échu (paramètre compensation_on_expired), fusion de tickets puis restauration du ticket source,
débit net de l'onglet du ticket, validation du paramétrage, version de grille indexée (et migration). Part d'un plugin installé et
activé (run.sh). La désinstallation après usage réel est dans scenario_zdesinstallation.py."""
import atexit
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
SUF = str(int(time.time()))
CLASSE = "GlpiPlugin%5CPoints%5C"


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def dernier(jour_iso, avant=1):
    jour = AUJOURDHUI - datetime.timedelta(days=avant)
    while jour.isoweekday() != jour_iso:
        jour -= datetime.timedelta(days=1)
    return jour.isoformat()


def appeler(script, appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", f"plugins/points/tests/banc/{script}"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def led(appel, *args):
    return appeler("ledger_cli.php", appel, *args)


def glpi(appel, *args):
    return appeler("glpi_cli.php", appel, *args)


def ok(r):
    return r["ok"] if "ok" in r else None


def creer(api_, itemtype, entree):
    return api_._req("POST", f"/{itemtype}", {"input": entree})["id"]


def essai(client, methode, chemin, corps=None):
    try:
        return True, json.dumps(client._req(methode, chemin, corps))
    except ErreurApi as e:
        return False, str(e)


def poster(web_, page, champs):
    return web_.post(f"/plugins/points/front/{page}", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})


def une(requete):
    lignes_ = sql(requete)
    return lignes_[0][0] if lignes_ else None  # aucune ligne : None (un KO lisible, pas une exception)


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


# --- état d'origine, remis à la sortie (même si le scénario échoue)
CLES = ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id", "compensation_on_expired")
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'") for c in CLES}
noms_types = {r[0]: r[1] for r in sql(f"SELECT id, name FROM {T}interventiontypes")}


def remettre():
    for cle, valeur in ancien.items():
        config(cle, valeur)
    for id_, nom in noms_types.items():
        sql_ecrire(f"UPDATE {T}interventiontypes SET name = '{nom}' WHERE id = {id_};")
    # les altérations volontaires du journal (témoins de corruption) sont retirées par le scénario lui-même, jamais ici


atexit.register(remettre)

DEPART_JOURNAL = {nom: len(subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true", capture_output=True, text=True).stdout.splitlines())
                  for nom in ("php-errors.log", "sql-errors.log")}  # seules les lignes écrites par ce passage comptent
api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST, OT = types["Assistance"], types["Ordre de travail"]
type_points = creer(api, "ContractType", {"name": f"final-points-{SUF}"})
type_autre = creer(api, "ContractType", {"name": f"final-autre-{SUF}"})
config("contracttypes", f"[{type_points}]")
config("debit_moment", "closure")
config("allow_overdraft", "1")
config("calendars_id", "0")
config("compensation_on_expired", "valid_lot")
config("default_interventiontypes_id", str(ASSIST))
console("cache:clear")
entite = creer(api, "Entity", {"name": f"points-final-{SUF}", "entities_id": 0})
api = banc.Api()  # l'entité créée doit figurer parmi les entités actives

# grille : Assistance 1 par heure en semaine, Ordre de travail 2
sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Final-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Final-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for type_, cout in ((ASSIST, 1), (OT, 2)):
    for jour in (1, 2, 3):
        sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
                   f"VALUES (0, 1, {version}, {type_}, {jour}, {cout});")

mardi, mercredi = dernier(2), dernier(3)
web = banc.Web()


def contrat(nom, type_=None, num=None):
    entree = {"name": f"fin-{nom}-{SUF}", "entities_id": entite, "contracttypes_id": type_ or type_points}
    if num:
        entree["num"] = num
    return creer(api, "Contract", entree)


def avec_points(nom, credit=4000, debut=None, fin=None, grille_=True):
    """Contrat à points avec une enveloppe valide (la ligne Points naît de la première écriture du journal)."""
    c = contrat(nom)
    led("createEnvelope", c, "E", credit, debut or d(-100), fin or d(200), False, "")
    if grille_:
        sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {c};")
    return c


def ticket(nom, c=None):
    entree = {"name": nom, "content": nom, "entities_id": entite}
    if c is not None:
        entree["_contracts_id"] = c
    return creer(api, "Ticket", entree)


def tache(tk, secondes, date, contenu="tâche", **plus):
    return essai(api, "POST", "/TicketTask", {"input": {"tickets_id": tk, "content": contenu, "actiontime": secondes, "date": date + " 09:00:00", **plus}})


def clore(tk):
    api._req("PUT", f"/Ticket/{tk}", {"input": {"status": 6}})


def debits(tk):
    return [(r[0], json.loads(r[1])) for r in sql(f"SELECT amount, details FROM {T}operations WHERE tickets_id = {tk} AND type = 'debit_task' ORDER BY id")]


def nb_ops(c):
    return int(une(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {c}"))


def nb_lots(c):
    return int(une(f"SELECT COUNT(*) FROM {T}lots WHERE contracts_id = {c}"))


def lots(c):
    return {int(r[0]): r for r in sql(f"SELECT id, initial, origin_date, expiry_date, plugin_points_envelopes_id FROM {T}lots WHERE contracts_id = {c} ORDER BY id")}


def restes(c):
    return {int(r[0]): r[1] for r in sql(
        f"SELECT l.id, COALESCE(SUM(a.amount), 0) FROM {T}lots l LEFT JOIN {T}allocations a ON a.plugin_points_lots_id = l.id WHERE l.contracts_id = {c} GROUP BY l.id")}


def onglet(tk):
    return web.get("/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab="
                   + urllib.parse.quote("GlpiPlugin\\Points\\Ticket$1", safe="") + f"&id={tk}")


# --- (1) purge d'un contrat natif qui a une ligne Points : refusée ; témoin : contrat sans points
P1 = avec_points("purge-points")
P2 = contrat("purge-sans-points")
v("(1) témoin : P1 a une ligne Points, P2 aucune", (une(f"SELECT COUNT(*) FROM {T}contracts WHERE contracts_id = {P1}"), une(f"SELECT COUNT(*) FROM {T}contracts WHERE contracts_id = {P2}")), ("1", "0"))
essai(api, "DELETE", f"/Contract/{P1}?force_purge=true")
v("(1) purge d'un contrat à points refusée : contrat, ligne Points et journal restent",
  (une(f"SELECT COUNT(*) FROM glpi_contracts WHERE id = {P1}"), une(f"SELECT COUNT(*) FROM {T}contracts WHERE contracts_id = {P1}"), nb_ops(P1)), ("1", "1", 1))
essai(api, "DELETE", f"/Contract/{P2}?force_purge=true")
v("(1) témoin : un contrat sans ligne Points se purge", une(f"SELECT COUNT(*) FROM glpi_contracts WHERE id = {P2}"), "0")
essai(api, "DELETE", f"/Contract/{P1}")
v("(1) témoin : la mise à la corbeille d'un contrat à points reste possible", une(f"SELECT is_deleted FROM glpi_contracts WHERE id = {P1}"), "1")

# --- (2) dépassement marqué dans le journal, plus de dépendance au paramètre du moment ; commande plugins:points:check
O = avec_points("depassement", 1000)
r = led("debit", O, "debit_task", 1500, d(-1), {})
v("(2) débit de 15 sur 10 points : dépassement accepté (5 en dépassement)", ok(r)["overdraft"] if ok(r) else r, 500)
marque = json.loads(une(f"SELECT details FROM {T}operations WHERE id = {ok(r)['operations_id']}"))
lot_o = int(une(f"SELECT id FROM {T}lots WHERE contracts_id = {O}"))
v("(2) l'opération de débit nomme le lot de dépassement", (marque.get("overdraft"), marque.get("overdraft_lot")), ("5.00", lot_o))
v("(2) lot en dépassement : reste -5.00, contrôle vert", (restes(O)[lot_o], ok(led("check", O))), ("-5.00", []))
config("allow_overdraft", "0")
v("(2) le paramètre allow_overdraft change ensuite : le dépassement déjà écrit reste légitime", ok(led("check", O)), [])
config("allow_overdraft", "1")


def check_console():
    p = console("plugins:points:check")
    return p.returncode, p.stdout + p.stderr


code, sortie = check_console()
v("(2) plugins:points:check : le contrat en dépassement marqué n'est pas signalé", f"contract {O}:" in sortie, False)
v("(2) plugins:points:check : code de sortie nul si et seulement si aucun écart n'est affiché", (code == 0, "inconsistency" not in sortie), (True, True))
Q = avec_points("corrompu", 1000)
op_q, lot_q = (int(x) for x in sql(f"SELECT o.id, l.id FROM {T}operations o JOIN {T}lots l ON l.operations_id = o.id WHERE o.contracts_id = {Q}")[0])
sql_ecrire(f"INSERT INTO {T}allocations (entities_id, is_recursive, operations_id, plugin_points_lots_id, amount, date_creation) "
           f"VALUES ({entite}, 0, {op_q}, {lot_q}, -12.00, NOW());")
problemes = ok(led("check", Q))
v("(2) témoin : un reste négatif SANS marque de dépassement est un écart", any("negative remainder" in p for p in problemes), True)
code, sortie = check_console()
v("(2) plugins:points:check : écart signalé par contrat, code de sortie non nul", (code != 0, f"contract {Q}: lot {lot_q}: negative remainder" in sortie), (True, True))
v("(2) plugins:points:check en lecture seule : rien n'a été écrit pour le contrat corrompu", (nb_ops(Q), une(f"SELECT COUNT(*) FROM {T}allocations WHERE operations_id = {op_q}")), (1, "2"))
config("allow_overdraft", "1")
v("(2) témoin : même avec allow_overdraft = 1, ce reste négatif non marqué reste un écart (l'ancienne règle le tolérait)",
  any("negative remainder" in p for p in ok(led("check", Q))), True)
sql_ecrire(f"DELETE FROM {T}allocations WHERE operations_id = {op_q} AND amount = -12.00;")
v("(2) après retrait de l'altération, le contrat est de nouveau cohérent", ok(led("check", Q)), [])
code, sortie = check_console()
v("(2) plugins:points:check : plus aucun écart pour les contrats du scénario", [c for c in (O, Q) if f"contract {c}:" in sortie], [])

# --- (3) type d'intervention figé à la création du ticket
C3 = avec_points("type-fige")
config("default_interventiontypes_id", str(ASSIST))
t1 = ticket("type figé 1", C3)
v("(3) à la création, la ligne Points du ticket porte le type par défaut (Assistance)", une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE tickets_id = {t1}"), str(ASSIST))
config("default_interventiontypes_id", str(OT))
tache(t1, 3600, mardi)
clore(t1)
v("(3) le défaut a changé : le ticket existant reste débité au prix Assistance (1.00)", [(m, j["interventiontypes_id"]) for m, j in debits(t1)], [("-1.00", ASSIST)])
t2 = ticket("type figé 2", C3)
tache(t2, 3600, mardi)
clore(t2)
v("(3) témoin : un ticket créé après le changement est débité au prix Ordre de travail (2.00)", [(m, j["interventiontypes_id"]) for m, j in debits(t2)], [("-2.00", OT)])
t3 = ticket("type figé 3 (sans ligne : ticket antérieur)", C3)
sql_ecrire(f"DELETE FROM {T}tickets WHERE tickets_id = {t3};")
tache(t3, 3600, mardi)
v("(3) un ticket sans ligne la reçoit à sa première évaluation, avec le défaut du moment (Ordre de travail)",
  une(f"SELECT plugin_points_interventiontypes_id FROM {T}tickets WHERE tickets_id = {t3}"), str(OT))
config("default_interventiontypes_id", str(ASSIST))
clore(t3)
v("(3) puis le défaut change de nouveau : ce ticket reste à 2.00", [m for m, _ in debits(t3)], ["-2.00"])


def onglet_present(tk):
    """L'onglet « Points » figure dans la liste des onglets de la fiche du ticket."""
    _, page = web.get(f"/front/ticket.form.php?id={tk}")
    return "GlpiPlugin_Points_Ticket_1" in page


v("(3) la ligne Points écrite pour tout ticket ne fait pas apparaître l'onglet sur un ticket sans contrat ; témoin : il apparaît avec un contrat",
  (onglet_present(ticket("sans contrat")), onglet_present(t1)), (False, True))

# --- (4) contrat d'un autre type : formulaires refusés ; contrat qui a déjà une ligne Points : permis même si son type est retiré
X_AUTRE = contrat("autre-type", type_autre)
X_PTS = contrat("bon-type")
champs = {"update": "1", "contracts_id": str(X_AUTRE), "plugin_points_grids_id": str(grille), "alert_threshold": "70", "is_active": "1"}
code, _ = poster(web, "contract.form.php", champs)
v("(4) contrat.form.php : contrat d'un autre type refusé (403), aucune ligne Points créée", (code, une(f"SELECT COUNT(*) FROM {T}contracts WHERE contracts_id = {X_AUTRE}")), (403, "0"))
code, _ = poster(web, "contract.form.php", {**champs, "contracts_id": str(X_PTS)})
v("(4) témoin : contrat d'un type à points accepté, ligne Points créée avec le réglage", (code, sql(f"SELECT alert_threshold FROM {T}contracts WHERE contracts_id = {X_PTS}")), (302, [["70"]]))
code, _ = poster(web, "credit.form.php", {"add": "1", "contracts_id": str(X_AUTRE), "envelopes_id": "1", "amount": "5"})
v("(4) credit.form.php : même refus pour un contrat d'un autre type", (code, nb_ops(X_AUTRE)), (403, 0))
config("contracttypes", "[]")
code, _ = poster(web, "contract.form.php", {**champs, "contracts_id": str(X_PTS), "alert_threshold": "60"})
v("(4) type retiré du paramétrage : le contrat qui a déjà une ligne Points reste modifiable",
  (code, une(f"SELECT alert_threshold FROM {T}contracts WHERE contracts_id = {X_PTS}")), (302, "60"))
code, _ = poster(web, "contract.form.php", {**champs, "contracts_id": str(contrat('sans-ligne-type-retire'))})
v("(4) témoin : sans ligne Points et sans type à points, refusé", code, 403)
config("contracttypes", f"[{type_points}]")

# --- (5) import : une ligne dont seul le libellé a été corrigé est une erreur de plan, sauf --accept-similar
NUM = f"FIN-IMP-{SUF}"
C_IMP = contrat("import", num=NUM)


def deposer(nom, contenu):
    chemin = f"/tmp/{SUF}-{nom}"
    subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh", "-c", f"cat > {chemin}"], input=contenu.encode(), check=True)
    return chemin


def csv(libelle, solde="12,5"):
    return deposer(f"imp-{abs(hash(libelle + solde))}.csv", "contrat;solde;debut;fin;report;libelle\n" + f"{NUM};{solde};{d(-30)};{d(300)};0;{libelle}\n")


def importer(chemin, *options):
    p = console("plugins:points:import", chemin, *options)
    return p.returncode, p.stdout + p.stderr


def nb_env(c):
    return int(une(f"SELECT COUNT(*) FROM {T}envelopes WHERE contracts_id = {c}"))


code, sortie = importer(csv("Solde initial"), "--apply")
v("(5) première importation : enveloppe créée", (code, nb_env(C_IMP)), (0, 1))
code, sortie = importer(csv("Solde initial"), "--apply")
v("(5) témoin : même ligne rejouée : sautée, rien d'écrit, code 0", (code, "SKIP" in sortie, nb_env(C_IMP)), (0, True, 1))
code, sortie = importer(csv("Solde initial corrigé"))
v("(5) libellé corrigé, même solde et mêmes dates : erreur de plan (simulation), code non nul", (code != 0, "--accept-similar" in sortie), (True, True))
code, sortie = importer(csv("Solde initial corrigé"), "--apply")
v("(5) la même ligne avec --apply : rien d'écrit", (code != 0, nb_env(C_IMP)), (True, 1))
code, sortie = importer(csv("Autre solde", "13"), "--apply")
v("(5) témoin : un autre solde n'est pas « semblable » : importé", (code, nb_env(C_IMP)), (0, 2))
code, sortie = importer(csv("Solde initial corrigé"), "--apply", "--accept-similar")
v("(5) --accept-similar : la ligne passe, enveloppe créée", (code, nb_env(C_IMP)), (0, 3))

# --- (6) remboursement qui retomberait sur un lot échu (règle 11, question 10)


def contrat_lot_echu(nom, avec_valide=True):
    """Lot 1 échu (E1, crédit 10, débit de 4 à d(-100)) ; E2 valide (crédit 20) si demandé."""
    c = contrat(nom)
    led("createEnvelope", c, "E1", 1000, d(-300), d(-10), False, "")
    if avec_valide:
        led("createEnvelope", c, "E2", 2000, d(-5), d(300), False, "")
    deb = ok(led("debit", c, "debit_task", 400, d(-100), {}))
    return c, deb["operations_id"], deb["allocations"][0]["lot"]


R, op_r, lot1 = contrat_lot_echu("echu-valid-lot")
l_avant = set(lots(R))
r = led("correction", R, op_r, 300, "motif")
op_corr = ok(r)["operations_id"] if ok(r) else None
nouveaux = set(lots(R)) - l_avant
v("(6) valid_lot : la part d'un lot échu va sur un NOUVEAU lot (un seul lot créé)", len(nouveaux), 1)
nouveau = next(iter(nouveaux))
env2 = int(une(f"SELECT id FROM {T}envelopes WHERE contracts_id = {R} AND name = 'E2'"))
info = lots(R)[nouveau]
v("(6) le nouveau lot : 3.00 points, daté du jour, dans l'enveloppe du lot valide le plus récent", (info[1], info[2], info[4]), ("3.00", d(0), str(env2)))
v("(6) l'affectation du remboursement est sur le nouveau lot, le lot échu garde son reste", (sql(f"SELECT plugin_points_lots_id, amount FROM {T}allocations WHERE operations_id = {op_corr}"), restes(R)[lot1]),
  ([[str(nouveau), "3.00"]], "6.00"))
v("(6) la part redirigée est notée dans les détails de l'opération", json.loads(une(f"SELECT details FROM {T}operations WHERE id = {op_corr}")).get("redirected"), {str(lot1): 300})
v("(6) la part redirigée compte comme rendue : il ne reste que 1.00 à rendre sur le débit", ok(led("refundable", op_r)), 100)
r = led("correction", R, op_r, 200, "trop")
v("(6) la rendre une seconde fois est refusé", r, {"violation": "nothing_to_refund"})
v("(6) témoin : le reliquat de 1.00 est rendu", "ok" in led("correction", R, op_r, 100, "reliquat"), True)
v("(6) invariants du contrat (solde = journal = lots)", ok(led("check", R)), [])
deb2 = ok(led("debit", R, "debit_task", 200, d(-1), {}))
avant = nb_lots(R)
led("correction", R, deb2["operations_id"], 100, "lot valide")
v("(6) témoin : un remboursement dont le lot est encore valide va au lot d'origine, sans nouveau lot",
  (nb_lots(R), sql(f"SELECT plugin_points_lots_id FROM {T}allocations WHERE operations_id = (SELECT MAX(id) FROM {T}operations WHERE contracts_id = {R})")),
  (avant, [[str(deb2["allocations"][0]["lot"])]]))
r = led("refund", R, op_r, 1, "compensation", d(0), "x")
v("(6) la compensation suit la même règle (plafond épuisé : refusée)", r, {"violation": "nothing_to_refund"})

config("compensation_on_expired", "origin")
S, op_s, lot_s = contrat_lot_echu("echu-origin")
avant = nb_lots(S)
r = led("correction", S, op_s, 300, "motif")
v("(6) origin : la part est rendue au lot échu (comportement d'avant), aucun nouveau lot",
  (nb_lots(S), sql(f"SELECT plugin_points_lots_id, amount FROM {T}allocations WHERE operations_id = {ok(r)['operations_id']}"), restes(S)[lot_s]),
  (avant, [[str(lot_s), "3.00"]], "9.00"))
config("compensation_on_expired", "valid_lot")
U, op_u, lot_u = contrat_lot_echu("echu-sans-valide", avec_valide=False)
avant = nb_ops(U)
r = led("correction", U, op_u, 300, "motif")
v("(6) aucun lot valide : refusé, rien d'écrit", (r, nb_ops(U), nb_lots(U)), ({"violation": "refund_no_valid_lot"}, avant, 1))
config("compensation_on_expired", "origin")
v("(6) témoin : avec origin le même remboursement passe", "ok" in led("correction", U, op_u, 300, "motif"), True)
config("compensation_on_expired", "valid_lot")
v("(6) invariants (origin, sans lot valide)", (ok(led("check", S)), ok(led("check", U))), ([], []))

# --- (7) fusion de tickets puis restauration du ticket source : aucun double débit
# Ticket::merge copie les tâches du ticket source (tickets_id_1 du lien « fils de ») vers le ticket principal avec sourceitems_id = source ;
# une tâche ajoutée ensuite au source en corbeille y est copiée de même (CommonITILTask::addToMergedTickets, src/CommonITILTask.php:973+)
M = avec_points("fusion", 8000)
A_, B_ = ticket("fusion source", M), ticket("fusion cible", M)
tache(A_, 3600, mardi, "t1")
glpi("merge", B_, [A_])
v("(7) fusion : la tâche est copiée sur la cible avec sourceitems_id = source, source en corbeille",
  (sql(f"SELECT sourceitems_id FROM glpi_tickettasks WHERE tickets_id = {B_}"), une(f"SELECT is_deleted FROM glpi_tickets WHERE id = {A_}")), ([[str(A_)]], "1"))
tache(A_, 1200, mardi, "t3")  # tâche ajoutée au source en corbeille
if une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {B_} AND content = 't3'") == "0":
    tache(B_, 1200, mardi, "t3", sourceitems_id=A_)  # la copie que GLPI n'a pas faite par l'API (pas de `_job`)
v("(7) la tâche ajoutée au source existe aussi, copiée, sur la cible", une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {B_} AND content = 't3' AND sourceitems_id = {A_}"), "1")
sql_ecrire(f"UPDATE glpi_tickets SET is_deleted = 0 WHERE id = {A_};")  # restauration du ticket source
tache(A_, 1800, mercredi, "t4")  # tâche propre au ticket restauré, sans copie ailleurs
_, page = onglet(A_)
v("(7) onglet du ticket restauré : les deux tâches copiées sur la cible sont « débitées sur le ticket principal », hors total (seule t4 : 0.50)",
  (page.count("Debited on the main ticket"), re.search(r'id="points-tasks-total">([^<]*)<', page).group(1)), (2, "0.50"))
clore(A_)
v("(7) ticket source restauré puis clos : seule sa tâche propre (t4, 0.50) est débitée, pas les originaux déjà copiés sur la cible",
  [m for m, _ in debits(A_)], ["-0.50"])
clore(B_)
v("(7) ticket cible clos : les copies de t1 (1.00) et de t3 (20 min comptées 30 : 0.50) sont débitées une fois chacune", sorted(m for m, _ in debits(B_)), sorted(["-1.00", "-0.50"]))
v("(7) au total trois débits (aucun double débit) et un journal cohérent", (nb_ops(M) - 1, ok(led("check", M))), (3, []))

# --- (8) total de l'onglet du ticket = net signé du journal
N = avec_points("net", 4000)
tn = ticket("net", N)
tache(tn, 10800, mardi)
clore(tn)
op_n = int(une(f"SELECT id FROM {T}operations WHERE tickets_id = {tn} AND type = 'debit_task'"))
led("correction", N, op_n, 100, "remboursement")
led("correction", N, op_n, -50, "débit en plus")
code, page = onglet(tn)
total = re.search(r'id="points-tasks-total">([^<]*)<', page)
v("(8) débit 3.00 - remboursement 1.00 + débit en plus 0.50 : total de l'onglet 2.50", (code, total.group(1) if total else None), (200, "2.50"))
v("(8) Ledger::netDebits donne le même net", ok(led("netDebits", [op_n])), {str(op_n): 250})

# --- (9) paramétrage : le type d'intervention par défaut et le calendrier doivent exister
avant_cfg = (une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'default_interventiontypes_id'"), une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'calendars_id'"))
poster(web, "config.form.php", {"update": "1", "default_interventiontypes_id": "999999"})
poster(web, "config.form.php", {"update": "1", "calendars_id": "999999"})
v("(9) type d'intervention ou calendrier inexistant : refusé, rien d'enregistré",
  (une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'default_interventiontypes_id'"), une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'calendars_id'")), avant_cfg)
poster(web, "config.form.php", {"update": "1", "default_interventiontypes_id": str(OT), "calendars_id": "0"})
v("(9) témoin : un type existant et le calendrier 0 sont enregistrés", (une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'default_interventiontypes_id'"), une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'calendars_id'")), (str(OT), "0"))
poster(web, "config.form.php", {"update": "1", "compensation_on_expired": "autre"})
v("(9) compensation_on_expired inconnu : refusé", une("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'compensation_on_expired'"), "valid_lot")
config("default_interventiontypes_id", str(ASSIST))
v("(9) estimation d'un forfait : témoin (type « Ordre de travail » présent)", "ok" in glpi("estimate", tn, "2"), True)
sql_ecrire(f"UPDATE {T}interventiontypes SET name = 'renommé-{SUF}' WHERE id = {OT};")
v("(9) estimation d'un forfait sans type « Ordre de travail » : message clair, pas « montant invalide »", glpi("estimate", tn, "2"), {"violation": "flatrate_no_estimate_type"})
sql_ecrire(f"UPDATE {T}interventiontypes SET name = 'Ordre de travail' WHERE id = {OT};")

# --- (10) version de grille : colonne indexée écrite avec le débit, reprise à la migration
mes_tickets = ",".join(str(t) for t in (t1, t2, t3, A_, B_, tn))
versions_tache = sql(f"SELECT DISTINCT plugin_points_gridversions_id FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND tickets_id IN ({mes_tickets})")
v("(10) les tâches débitées portent la version de grille de leur débit", versions_tache, [[str(version)]])
n_verrouillees = une(f"SELECT COUNT(*) FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND plugin_points_gridversions_id = {version}")
v("(10) il y a des tâches débitées sur cette version", int(n_verrouillees) >= 6, True)
couts = [int(r[0]) for r in sql(f"SELECT id FROM {T}gridcosts WHERE plugin_points_gridversions_id = {version} AND plugin_points_interventiontypes_id = {ASSIST} AND daytype = 1")]
essai(api, "PUT", f"/{CLASSE}GridCost/{couts[0]}", {"input": {"cost": "9"}})
v("(10) version utilisée : modification d'un prix refusée", une(f"SELECT cost FROM {T}gridcosts WHERE id = {couts[0]}"), "1.00")
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-10)}');")
libre = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille} AND begin_date = '{d(-10)}'"))
sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) VALUES (0, 1, {libre}, {ASSIST}, 1, 5);")
cout_libre = int(une(f"SELECT id FROM {T}gridcosts WHERE plugin_points_gridversions_id = {libre}"))
essai(api, "PUT", f"/{CLASSE}GridCost/{cout_libre}", {"input": {"cost": "6"}})
v("(10) témoin : version inutilisée modifiable", une(f"SELECT cost FROM {T}gridcosts WHERE id = {cout_libre}"), "6.00")
code, page = web.get(f"/plugins/points/front/grid.form.php?id={grille}")
v("(10) page de la grille : version utilisée verrouillée, version libre éditable", (code, f'points-version-{version}' in page), (200, True))
# migration : ancien schéma (colonne absente), réinstallation rejouable, reprise depuis les détails des opérations
sql_ecrire(f"ALTER TABLE {T}tasks DROP COLUMN plugin_points_gridversions_id;")
for args in (("plugin:install", "--force", "-u", "glpi", "points"), ("plugin:activate", "points")):
    console(*args)
v("(10) migration : colonne recréée et reprise pour les tâches déjà débitées",
  une(f"SELECT COUNT(*) FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND plugin_points_gridversions_id = {version}"), n_verrouillees)
console("plugin:install", "--force", "-u", "glpi", "points")
console("plugin:activate", "points")
v("(10) migration rejouable : rien ne change au second passage", une(f"SELECT COUNT(*) FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND plugin_points_gridversions_id = {version}"), n_verrouillees)
v("(10) index de la colonne présent", int(une(f"SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{T}tasks' AND column_name = 'plugin_points_gridversions_id'")) >= 1, True)

# --- (11) expiration : seuls les contrats qui ont un lot échu au reste > 0 sont écrits
E1_ = contrat("expire-reste")
led("createEnvelope", E1_, "E", 500, d(-300), d(-10), False, "")
E2_ = contrat("expire-vide")
led("createEnvelope", E2_, "E", 500, d(-300), d(-10), False, "")
led("debit", E2_, "debit_task", 500, d(-100), {})
modif_vide = une(f"SELECT date_mod FROM {T}contracts WHERE contracts_id = {E2_}")
time.sleep(1.1)
led("expireDue", d(0))
v("(11) lot échu au reste > 0 : expiré", sql(f"SELECT type, amount FROM {T}operations WHERE contracts_id = {E1_} AND type = 'expiry'"), [["expiry", "-5.00"]])
v("(11) lot échu épuisé : ni opération, ni verrou (date_mod de la ligne du contrat inchangée)",
  (une(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {E2_} AND type = 'expiry'"), une(f"SELECT date_mod FROM {T}contracts WHERE contracts_id = {E2_}")), ("0", modif_vide))


# --- (12) lot échu en dépassement : la part qui comble son reste n'est pas redirigée (sinon des points naissent)
D1 = contrat("depassement-echu")
led("createEnvelope", D1, "E1", 1000, d(-300), d(-10), False, "")
deb = ok(led("debit", D1, "debit_task", 3000, d(-100), {}))
lot_d = deb["allocations"][0]["lot"]
v("(12) débit de 30 sur un lot de 10 : reste -20, dépassement marqué sur ce lot", (restes(D1)[lot_d], json.loads(une(f"SELECT details FROM {T}operations WHERE id = {deb['operations_id']}")).get("overdraft_lot")), ("-20.00", lot_d))
led("createEnvelope", D1, "E2", 10000, d(-5), d(300), False, "")
r = led("refund", D1, deb["operations_id"], 3000, "compensation", d(0), "geste")
v("(12) compensation de 30 acceptée", "ok" in r, True)
valides = une(f"SELECT COALESCE(SUM(a.amount), 0) FROM {T}lots l JOIN {T}allocations a ON a.plugin_points_lots_id = l.id WHERE l.contracts_id = {D1} AND l.origin_date <= '{d(0)}' AND l.expiry_date >= '{d(0)}'")
v("(12) le lot échu est comblé (reste 0) et seul le surplus de 10 est redirigé : disponible 110, pas 130", (restes(D1)[lot_d], valides), ("0.00", "110.00"))
v("(12) invariants (dépassement comblé, lot redirigé)", ok(led("check", D1)), [])
v("(12) seuil d'alerte : le lot issu d'un remboursement n'est pas un achat (crédité 100, consommé 0)", ok(led("validTotals", D1, d(0))), {"credited": 10000, "consumed": 0})

# --- (13) ligne Points du ticket : entité suivie, lecture du ticket exigée
MDP2 = "Final-points-1"
modele_tech = une("SELECT id FROM glpi_profiles WHERE name = 'Technician'")


def profil(nom, droits):
    pid = creer(api, "Profile", {"name": f"{nom}-{SUF}", "interface": "central"})
    sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT {pid}, name, rights FROM glpi_profilerights WHERE profiles_id = {modele_tech} ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    for droit_, valeur in droits.items():
        sql_ecrire(f"INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ({pid}, '{droit_}', {valeur}) ON DUPLICATE KEY UPDATE rights = VALUES(rights);")
    return pid


p_lit, p_sans = profil("final-lit", {"plugin_points_ticket": 3, "plugin_points_balance": 1}), profil("final-sans-ticket", {"plugin_points_ticket": 3, "plugin_points_balance": 1, "ticket": 0})
entite2 = creer(api, "Entity", {"name": f"points-final2-{SUF}", "entities_id": 0})
api = banc.Api()


def utilisateur(nom, profil_id, ent):
    uid = creer(api, "User", {"name": f"{nom}{SUF}", "password": MDP2, "password2": MDP2, "_profiles_id": int(profil_id), "_entities_id": ent, "_is_recursive": 1, "is_active": 1})
    return uid


u_a, u_b, u_sans = utilisateur("fin-a", p_lit, entite), utilisateur("fin-b", p_lit, entite2), utilisateur("fin-sans", p_sans, entite)
console("cache:clear")


def nettoyer():
    ids = ",".join(str(i) for i in (p_lit, p_sans))
    sql_ecrire(f"DELETE FROM glpi_profiles_users WHERE users_id IN ({u_a},{u_b},{u_sans}); DELETE FROM glpi_users WHERE id IN ({u_a},{u_b},{u_sans});"
               f"DELETE FROM glpi_profilerights WHERE profiles_id IN ({ids}); DELETE FROM glpi_profiles WHERE id IN ({ids});")


atexit.register(nettoyer)
cl_a, cl_b, cl_sans = (banc.Api(f"{n}{SUF}", MDP2) for n in ("fin-a", "fin-b", "fin-sans"))
TT = ticket("transfert", avec_points("transfert"))
ligne = int(une(f"SELECT id FROM {T}tickets WHERE tickets_id = {TT}"))


def lit(client):
    return essai(client, "GET", f"/{CLASSE}Ticket/{ligne}")[0]


v("(13) ticket de l'entité A : le compte de A lit la ligne Points, celui de B non", (lit(cl_a), lit(cl_b)), (True, False))
v("(13) compte qui a l'entité mais pas le droit du cœur de lire le ticket : ligne refusée", lit(cl_sans), False)
api._req("PUT", f"/Ticket/{TT}", {"input": {"entities_id": entite2}})
v("(13) ticket transféré : la ligne Points suit son entité", une(f"SELECT entities_id FROM {T}tickets WHERE id = {ligne}"), str(entite2))
v("(13) après transfert : l'ancien compte ne lit plus, le nouveau lit", (lit(cl_a), lit(cl_b)), (False, True))

# --- (14) ticket sans ligne : l'onglet et la lecture n'écrivent rien (le figeage se fait en écriture)
T14 = ticket("lecture seule", avec_points("lecture-seule"))
tache(T14, 3600, mardi)
sql_ecrire(f"DELETE FROM {T}tickets WHERE tickets_id = {T14};")
onglet(T14)
v("(14) l'onglet d'un ticket sans ligne n'en crée pas", une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {T14}"), "0")
clore(T14)
v("(14) témoin : la clôture (débit, écriture) fige le type", une(f"SELECT COUNT(*) FROM {T}tickets WHERE tickets_id = {T14}"), "1")

# --- (15) copie posée à la main (sourceitems_id sans fusion ni lien) : le ticket débite normalement ses tâches
C15 = avec_points("copie-main")
A15, X15 = ticket("copie source", C15), ticket("copie autre", C15)
tache(A15, 3600, mardi, "t15")
tache(X15, 3600, mardi, "t15", sourceitems_id=A15)
clore(A15)
v("(15) sans lien de fusion natif, une copie ailleurs n'exclut rien : la tâche est débitée une fois", len(debits(A15)), 1)

# --- (16) import interrompu puis repris : l'enveloppe « semblable » d'une autre ligne du même fichier n'est pas une erreur
NUM2 = f"FIN-IMP2-{SUF}"
C16 = contrat("import-repris", num=NUM2)
ligne_a, ligne_b = f"{NUM2};20;{d(-30)};{d(300)};0;Premier", f"{NUM2};20;{d(-30)};{d(300)};0;Second"
f_a = deposer("repris-a.csv", "contrat;solde;debut;fin;report;libelle\n" + ligne_a + "\n")
f_ab = deposer("repris-ab.csv", "contrat;solde;debut;fin;report;libelle\n" + ligne_a + "\n" + ligne_b + "\n")
importer(f_a, "--apply")
code, sortie = importer(f_ab)
v("(16) fichier complet après un import interrompu : simulation sans erreur, seule la ligne manquante est nouvelle", (code, "ERROR" in sortie, "NEW" in sortie, "SKIP" in sortie), (0, False, True, True))
code, sortie = importer(f_ab, "--apply")
v("(16) la reprise écrit la seconde ligne sans --accept-similar", (code, nb_env(C16)), (0, 2))

# --- (17) migration : colonne présente mais à 0 : reprise au rejeu de l'installation
sql_ecrire(f"UPDATE {T}tasks SET plugin_points_gridversions_id = 0;")
console("plugin:install", "--force", "-u", "glpi", "points")
console("plugin:activate", "points")
v("(17) colonne à 0 (migration interrompue) : remplie au rejeu", une(f"SELECT COUNT(*) FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND plugin_points_gridversions_id = {version}"), n_verrouillees)
v("(17) toutes les tâches débitées retrouvent une version", une(f"SELECT COUNT(*) FROM {T}tasks WHERE is_locked = 1 AND operations_id > 0 AND plugin_points_gridversions_id = 0"), "0")

# --- invariants de tous les contrats du scénario, journaux
for c in (O, Q, C3, R, S, U, M, N, E1_, E2_, D1, C15):
    v(f"invariants du contrat {c}", ok(led("check", c)), [])
lecture = lambda nom: subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                                     capture_output=True, text=True).stdout
fautes = [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines()[DEPART_JOURNAL[nom]:] if re.search("points", l, re.I)]
v("aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : final " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
