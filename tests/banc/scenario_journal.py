"""Journal des points (lot b) : enveloppes, crédit, débit FIFO, corrections, journal en ajout seul (API refusée), limite
de 24 mois, expiration et report par l'action automatique, concurrence, invariants SQL, onglet « Points » du contrat.
Part d'un plugin installé et activé (run.sh) ; remet la configuration et les droits comme il les a trouvés."""
import datetime
import json
import re
import subprocess
import sys
import time
import urllib.parse

import banc
from banc import ErreurApi, Verif, console, shell_app, sql, sql_ecrire

v = Verif()
AUJOURDHUI = datetime.date.today()
CLASSE = "GlpiPlugin%5CPoints%5C"
T = "glpi_plugin_points_"


def d(jours):
    """Date relative à aujourd'hui (AAAA-MM-JJ)."""
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def led(appel, *args):
    """Appelle Ledger::<appel> dans le conteneur ; renvoie le JSON {"ok" | "violation" | "error": …}."""
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes[-1]) if lignes else {"error": "pas de sortie : " + p.stderr[-300:]}


def ok(r):
    return r["ok"] if "ok" in r else None


def lignes(requete):
    return sql(requete)


def code_http(exc):
    m = re.search(r": (\d{3}) ", str(exc))
    return int(m.group(1)) if m else None


def api_essai(api, methode, chemin, corps=None):
    """(code, réponse ou message) : 200 si la requête passe, sinon le code HTTP de l'erreur et son texte."""
    try:
        return 200, api._req(methode, chemin, corps)
    except ErreurApi as e:
        return code_http(e), str(e)


def refus_droit(resultat):
    """L'API v1 refuse une écriture sans droit par un HTTP 400 (ERROR_GLPI_ADD/UPDATE/DELETE) « You don't have permission »
    (src/Glpi/Api/API.php:2062, 2200 en 12.0.1) ; la lecture sans droit est un 403/404 (ERROR_RIGHT_MISSING)."""
    code, message = resultat
    return code == 400 and "permission" in message


def poster(web, page, champs):
    return web.post(f"/plugins/points/front/{page}", list(champs.items()), {"Sec-Fetch-Site": "same-origin"})


def droit(nom, valeur):
    sql_ecrire(f"UPDATE glpi_profilerights SET rights = {valeur} WHERE name = '{nom}' "
               "AND profiles_id = (SELECT id FROM glpi_profiles WHERE name = 'Super-Admin');")
    console("cache:clear")


def cache(contrat):
    return lignes(f"SELECT balance, credited, consumed FROM {T}contracts WHERE contracts_id = {contrat}")


def ops(contrat):
    return lignes(f"SELECT type, amount FROM {T}operations WHERE contracts_id = {contrat} ORDER BY id")


def restes(contrat):
    """{lot: reste} d'un contrat."""
    return {int(r[0]): r[1] for r in lignes(
        f"SELECT l.id, COALESCE(SUM(a.amount), 0) FROM {T}lots l LEFT JOIN {T}allocations a ON a.plugin_points_lots_id = l.id "
        f"WHERE l.contracts_id = {contrat} GROUP BY l.id ORDER BY l.id")}


def affectations(op):
    return [(int(r[0]), r[1]) for r in lignes(f"SELECT plugin_points_lots_id, amount FROM {T}allocations WHERE operations_id = {op} ORDER BY id")]


api = banc.Api()
ancien_types = lignes("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'contracttypes'")[0][0]

# --- contrats de test dans une entité, créés par l'API v1
entite = api._req("POST", "/Entity", {"input": {"name": "points-banc", "entities_id": 0}})["id"]
type_contrat = api._req("POST", "/ContractType", {"input": {"name": "points-banc-type"}})["id"]
sql_ecrire(f"UPDATE glpi_configs SET value = '[{type_contrat}]' WHERE context = 'plugin:points' AND name = 'contracttypes';")


api = banc.Api()  # nouvelle session : l'entité créée doit figurer parmi les entités actives


def contrat(nom):
    return api._req("POST", "/Contract", {"input": {"name": nom, "entities_id": entite, "contracttypes_id": type_contrat}})["id"]


A, B, C, D, E, G = (contrat(f"points-banc-{n}") for n in "ABCDEG")
v("(0) contrats créés dans l'entité de test", [r[0] for r in lignes(f"SELECT entities_id FROM glpi_contracts WHERE id IN ({A},{B},{C},{D},{E})")], [str(entite)] * 5)
v("(0) aucune ligne Points avant la première écriture", lignes(f"SELECT COUNT(*) FROM {T}contracts WHERE contracts_id = {A}"), [["0"]])

# --- (1) enveloppe 40 points + crédit
e1 = led("createEnvelope", A, "E1", 4000, d(-100), d(200), False, "initial")
v("(1) createEnvelope accepté", "ok" in e1, True)
e1 = ok(e1)
v("(1) ligne Points créée avec l'entité du contrat", lignes(f"SELECT entities_id, alert_threshold, is_active FROM {T}contracts WHERE contracts_id = {A}"), [[str(entite), "80", "1"]])
v("(1) enveloppe : crédit 40.00, entité copiée, non récursive",
  lignes(f"SELECT credit, begin_date, end_date, is_carryover, entities_id, is_recursive FROM {T}envelopes WHERE id = {e1['envelopes_id']}"),
  [["40.00", d(-100), d(200), "0", str(entite), "0"]])
v("(1) opération credit +40.00 liée à l'enveloppe", lignes(f"SELECT type, amount, itemtype, items_id FROM {T}operations WHERE id = {e1['operations_id']}"),
  [["credit", "40.00", "GlpiPlugin\\\\Points\\\\Envelope", str(e1["envelopes_id"])]])
v("(1) lot : origine = début, échéance = fin de l'enveloppe (< début + 24 mois), initial 40.00",
  lignes(f"SELECT origin_date, expiry_date, initial FROM {T}lots WHERE id = {e1['lots_id']}"), [[d(-100), d(200), "40.00"]])
v("(1) cache : solde 40, crédité 40, consommé 0", cache(A), [["40.00", "40.00", "0.00"]])
e2 = ok(led("createEnvelope", A, "E2", 3000, d(-50), d(300), False, ""))
c1 = ok(led("credit", A, e1["envelopes_id"], 2500, {"comment": "achat complémentaire"}))
v("(1) crédit additionnel : nouveau lot d'origine aujourd'hui, même échéance que l'enveloppe",
  lignes(f"SELECT origin_date, expiry_date, initial FROM {T}lots WHERE id = {c1['lots_id']}"), [[d(0), d(200), "25.00"]])
v("(1) cache après crédit : 95", cache(A), [["95.00", "95.00", "0.00"]])
v("(1) crédit sur l'enveloppe d'un autre contrat refusé", led("credit", B, e1["envelopes_id"], 100), {"violation": "unknown_envelope"})
v("(1) crédit nul refusé", led("credit", A, e1["envelopes_id"], 0), {"violation": "invalid_amount"})
v("(1) crédit hors enveloppe refusé (date d'achat après la fin)", led("credit", A, e1["envelopes_id"], 100, {"origin_date": d(201)}), {"violation": "credit_out_of_range"})

# --- (2) débit générique FIFO sur deux enveloppes
l1, l2, l3 = e1["lots_id"], e2["lots_id"], c1["lots_id"]
deb = led("debit", A, "debit_task", 5000, d(0), {"itemtype": "TicketTask", "items_id": 7, "details": {"minutes": 150}})
v("(2) débit de 50 accepté", "ok" in deb, True)
deb = ok(deb)
v("(2) FIFO : 40 sur le plus ancien lot, 10 sur le suivant", affectations(deb["operations_id"]), [(l1, "-40.00"), (l2, "-10.00")])
v("(2) restes des lots : 0, 20, 25", [restes(A)[l] for l in (l1, l2, l3)], ["0.00", "20.00", "25.00"])
v("(2) opération : montant négatif, type, objet et détails",
  lignes(f"SELECT type, amount, itemtype, items_id, details FROM {T}operations WHERE id = {deb['operations_id']}"),
  [["debit_task", "-50.00", "TicketTask", "7", '{"minutes":150}']])
v("(2) cache : solde 45, consommé 50", cache(A), [["45.00", "95.00", "50.00"]])

# --- (3) corrections liées
n_ops = lignes(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {A}")
v("(3) correction sans motif refusée", led("correction", A, deb["operations_id"], -500, ""), {"violation": "reason_required"})
v("(3) correction avec motif blanc refusée", led("correction", A, deb["operations_id"], -500, "   "), {"violation": "reason_required"})
v("(3) correction d'une opération d'un autre contrat refusée", led("correction", B, deb["operations_id"], -500, "x"), {"violation": "unknown_operation"})
v("(3) débit supplémentaire sans solde suffisant refusé (jamais de dépassement)", led("correction", A, deb["operations_id"], -100000, "énorme"), {"violation": "insufficient"})
v("(3) rien écrit par les refus", lignes(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {A}"), n_ops)
neg = ok(led("correction", A, deb["operations_id"], -500, "Oubli de 5 points"))
v("(3) correction négative avec motif : FIFO sur le lot entamé", affectations(neg["operations_id"]), [(l2, "-5.00")])
v("(3) correction négative : liée à l'origine, motif conservé",
  lignes(f"SELECT type, operations_id_origin, comment FROM {T}operations WHERE id = {neg['operations_id']}"),
  [["correction", str(deb["operations_id"]), "Oubli de 5 points"]])
pos = ok(led("correction", A, deb["operations_id"], 1200, "Geste commercial"))
v("(3) correction positive : remboursée aux lots du débit dans l'ordre inverse", affectations(pos["operations_id"]), [(l2, "10.00"), (l1, "2.00")])
v("(3) remboursement au-delà de ce que le débit a pris refusé", led("correction", A, deb["operations_id"], 4000, "trop"), {"violation": "nothing_to_refund"})
v("(3) correction positive sur un crédit refusée (l'origine doit être un débit)", led("correction", A, e1["operations_id"], 100, "non"), {"violation": "not_a_debit"})
v("(3) refund sur un crédit refusé", led("refund", A, e1["operations_id"], 100, "compensation", d(0), "non"), {"violation": "not_a_debit"})
v("(3) cache : solde 52, crédité 95, consommé 43 (le remboursement réduit consumed)", cache(A), [["52.00", "95.00", "43.00"]])
v("(3) invariants du contrat A", led("check", A), {"ok": []})

# --- (4) dépassement, refus, bornes de dates (contrat B)
eb = ok(led("createEnvelope", B, "EB", 1000, d(-10), d(100), False, ""))
v("(4) sans dépassement : refusé quand le solde ne suffit pas", led("debit", B, "debit_task", 1500, d(0), {"allow_overdraft": False}), {"violation": "insufficient"})
v("(4) rien écrit par le refus", len(ops(B)), 1)
v("(4) témoin : le solde exact passe", "ok" in led("debit", B, "debit_task", 1000, d(0), {"allow_overdraft": False}), True)
sur = ok(led("debit", B, "debit_task", 500, d(0), {"allow_overdraft": True}))
v("(4) dépassement sur le lot valide le plus récent, signalé dans les détails",
  (affectations(sur["operations_id"]), sur["overdraft"],
   lignes(f"SELECT details FROM {T}operations WHERE id = {sur['operations_id']}")), ([(eb["lots_id"], "-5.00")], 500, [['{"overdraft":"5.00"}']]))
v("(4) lot en dépassement : reste -5.00 ; solde -5", (restes(B)[eb["lots_id"]], cache(B)[0][0]), ("-5.00", "-5.00"))
v("(4) débit avant l'origine de tous les lots refusé même avec dépassement", led("debit", B, "debit_task", 100, d(-11), {"allow_overdraft": True}), {"violation": "no_valid_lot"})
v("(4) débit le dernier jour de l'enveloppe accepté (borne incluse)", "ok" in led("debit", B, "debit_task", 100, d(100), {"allow_overdraft": True}), True)
v("(4) débit le lendemain de la fin refusé (borne exclue)", led("debit", B, "debit_task", 100, d(101), {"allow_overdraft": True}), {"violation": "no_valid_lot"})
v("(4) type de débit inconnu refusé", led("debit", B, "expiry", 100, d(0)), {"violation": "bad_type"})
v("(4) invariants du contrat B (dépassement autorisé par le paramètre)", led("check", B), {"ok": []})

# --- (5) journal en ajout seul : l'API refuse, la lecture passe
op = deb["operations_id"]
code, _ = api_essai(api, "GET", f"/{CLASSE}Operation/{op}")
v("(5) témoin : lecture d'une opération par l'API", code, 200)
avant = lignes(f"SELECT amount, comment FROM {T}operations WHERE id = {op}")
res = api_essai(api, "PUT", f"/{CLASSE}Operation/{op}", {"input": {"comment": "modifié", "amount": "1"}})
v("(5) UPDATE d'une opération refusé (droit manquant)", refus_droit(res), True)
res = api_essai(api, "DELETE", f"/{CLASSE}Operation/{op}")
v("(5) DELETE d'une opération refusé (droit manquant)", refus_droit(res), True)
res = api_essai(api, "DELETE", f"/{CLASSE}Operation/{op}?force_purge=true")
v("(5) PURGE d'une opération refusée (droit manquant)", refus_droit(res), True)
res = api_essai(api, "POST", f"/{CLASSE}Operation", {"input": {"contracts_id": A, "type": "credit", "amount": "999"}})
v("(5) création d'une opération refusée (droit manquant)", refus_droit(res), True)
v("(5) opération inchangée, aucune opération créée", (lignes(f"SELECT amount, comment FROM {T}operations WHERE id = {op}"), lignes(f"SELECT COUNT(*) FROM {T}operations WHERE amount = 999")), (avant, [["0"]]))
for table in ("Lot", "Allocation"):
    code, _ = api_essai(api, "GET", f"/{CLASSE}{table}/1")
    v(f"(5) témoin : lecture de {table} par l'API", code, 200)
    res = api_essai(api, "DELETE", f"/{CLASSE}{table}/1?force_purge=true")
    v(f"(5) PURGE de {table} refusée (droit manquant)", refus_droit(res), True)
code, _ = api_essai(api, "POST", f"/{CLASSE}Envelope", {"input": {"contracts_id": A, "name": "pirate", "credit": "500", "entities_id": entite}})
n_env = lignes(f"SELECT COUNT(*) FROM {T}envelopes WHERE name = 'pirate'")
v("(5) création d'une enveloppe hors Ledger refusée (aucune enveloppe sans opération ni lot)", n_env, [["0"]])
res = api_essai(api, "DELETE", f"/{CLASSE}Envelope/{e1['envelopes_id']}?force_purge=true")
v("(5) suppression d'une enveloppe refusée (droit manquant)", refus_droit(res), True)
code, _ = api_essai(api, "PUT", f"/{CLASSE}Envelope/{e1['envelopes_id']}", {"input": {"end_date": d(250)}})
v("(5) dates d'une enveloppe non modifiables", lignes(f"SELECT end_date FROM {T}envelopes WHERE id = {e1['envelopes_id']}"), [[d(200)]])
code, _ = api_essai(api, "PUT", f"/{CLASSE}Envelope/{e1['envelopes_id']}", {"input": {"credit": "99"}})
v("(5) crédit initial d'une enveloppe non modifiable", lignes(f"SELECT credit FROM {T}envelopes WHERE id = {e1['envelopes_id']}"), [["40.00"]])
code, _ = api_essai(api, "PUT", f"/{CLASSE}Envelope/{e1['envelopes_id']}", {"input": {"name": "E1 renommée"}})
v("(5) témoin : le nom d'une enveloppe se modifie", (code, lignes(f"SELECT name FROM {T}envelopes WHERE id = {e1['envelopes_id']}")), (200, [["E1 renommée"]]))
ligne_a = lignes(f"SELECT id FROM {T}contracts WHERE contracts_id = {A}")[0][0]
api_essai(api, "PUT", f"/{CLASSE}Contract/{ligne_a}", {"input": {"balance": "999"}})
v("(5) le solde en cache n'est pas modifiable par l'API", cache(A)[0][0], "52.00")
code, _ = api_essai(api, "PUT", f"/{CLASSE}Contract/{ligne_a}", {"input": {"alert_threshold": 60}})
v("(5) témoin : le seuil d'alerte se modifie par l'API", (code, lignes(f"SELECT alert_threshold FROM {T}contracts WHERE id = {ligne_a}")), (200, [["60"]]))
code, _ = api_essai(api, "DELETE", f"/{CLASSE}Contract/{ligne_a}?force_purge=true")
v("(5) la ligne Points d'un contrat ne se supprime pas", lignes(f"SELECT COUNT(*) FROM {T}contracts WHERE id = {ligne_a}"), [["1"]])

# --- (6) limite de 24 mois par le formulaire web (témoin : 24 mois accepté)
web = banc.Web()
champs = {"contracts_id": str(E), "name": "E25", "credit": "5", "begin_date": "2023-01-01", "end_date": "2025-02-01", "is_carryover": "0", "add": "1"}
code, _ = poster(web, "envelope.form.php", champs)
v("(6) enveloppe de 25 mois : formulaire redirigé", code, 302)
v("(6) enveloppe de 25 mois refusée : rien d'écrit", lignes(f"SELECT COUNT(*) FROM {T}envelopes WHERE contracts_id = {E}"), [["0"]])
champs.update({"name": "E24", "end_date": "2025-01-01"})
code, _ = poster(web, "envelope.form.php", champs)
v("(6) témoin : enveloppe de 24 mois acceptée avec son crédit et son lot",
  (code, lignes(f"SELECT credit, end_date FROM {T}envelopes WHERE contracts_id = {E}"), lignes(f"SELECT initial, expiry_date FROM {T}lots WHERE contracts_id = {E}")),
  (302, [["5.00", "2025-01-01"]], [["5.00", "2025-01-01"]]))
v("(6) crédit avec virgule décimale (formulaire)", poster(web, "credit.form.php", {"contracts_id": str(B), "envelopes_id": str(eb["envelopes_id"]), "amount": "2,5", "add": "1"})[0], 302)
v("(6) crédit à 2,5 écrit 2.50", lignes(f"SELECT amount FROM {T}operations WHERE contracts_id = {B} ORDER BY id DESC LIMIT 1"), [["2.50"]])
env_e = lignes(f"SELECT id FROM {T}envelopes WHERE contracts_id = {E}")[0][0]
n_e = len(ops(E))
v("(6) crédit sur une enveloppe terminée refusé", led("credit", E, int(env_e), 100), {"violation": "envelope_ended"})
v("(6) rien écrit par ce refus", len(ops(E)), n_e)
v("(6) montant au-delà de DECIMAL(12,2) refusé par le formulaire", (poster(web, "credit.form.php", {"contracts_id": str(B), "envelopes_id": str(eb["envelopes_id"]), "amount": "10000000000", "add": "1"})[0], len(ops(B))), (302, len(ops(B))))
v("(6) limite aussi dans le service (début après fin)", led("createEnvelope", E, "x", 100, "2025-02-01", "2025-01-01", False, ""), {"violation": "envelope_dates"})
v("(6) limite aussi dans le service (25 mois)", led("createEnvelope", E, "x", 100, "2023-01-01", "2025-01-02", False, ""), {"violation": "envelope_too_long"})
n_avant = len(ops(A))
code, _ = poster(web, "correction.form.php", {"contracts_id": str(A), "operations_id": str(deb["operations_id"]), "amount": "-1", "reason": "", "add": "1"})
v("(6) correction sans motif par le formulaire : refusée, rien écrit", (code, len(ops(A))), (302, n_avant))

# --- (7) expiration et report par l'action automatique (contrat C)
c_old = ok(led("createEnvelope", C, "C1 2023", 1000, "2023-01-01", "2023-12-31", True, ""))
c_np = ok(led("createEnvelope", C, "C3 2025", 700, "2025-01-01", "2025-12-31", False, ""))
c_rep = ok(led("createEnvelope", C, "C5 reportable", 800, d(-200), d(-10), True, ""))
c_cible = ok(led("createEnvelope", C, "CT cible", 2000, d(-30), d(200), True, ""))
c_borne = ok(led("createEnvelope", C, "C6 finit aujourd'hui", 300, d(-30), d(0), False, ""))
g_env = ok(led("createEnvelope", G, "EG", 1000, d(-200), d(-10), False, ""))
v("(7) témoin : enveloppes passées créées, solde 48", cache(C)[0][0], "48.00")
v("(7) l'action automatique pointsexpire est enregistrée (quotidienne)",
  lignes("SELECT name, frequency, state FROM glpi_crontasks WHERE itemtype LIKE 'GlpiPlugin%Points%Cron'"), [["pointsexpire", "86400", "1"]])
time.sleep(2)
avant_cron = lignes(f"SELECT date_mod, date_cache FROM {T}contracts WHERE contracts_id IN ({A}, {B}, {D})")
p = shell_app("php front/cron.php --force pointsexpire")
v("(7) tâche lancée sans erreur", (p.returncode, "rror" in p.stdout + p.stderr), (0, False))
v("(7) la tâche a laissé une trace dans son journal", lignes("SELECT COUNT(*) > 0 FROM glpi_crontasklogs l JOIN glpi_crontasks t ON t.id = l.crontasks_id WHERE t.name = 'pointsexpire'"), [["1"]])
v("(7) opérations : 5 crédits, 2 expirations, 1 report sortant, 1 report entrant",
  sorted(r[0] + ":" + r[1] for r in ops(C) if r[0] != "credit"), sorted(["expiry:-10.00", "expiry:-7.00", "carryover_out:-8.00", "carryover_in:8.00"]))
v("(7) report au-delà de 2 ans : le lot de 2023 (plafond 2025-01-01) expire entièrement", lignes(
    f"SELECT o.type, a.amount FROM {T}operations o JOIN {T}allocations a ON a.operations_id = o.id WHERE o.type = 'expiry' AND a.plugin_points_lots_id = {c_old['lots_id']}"), [["expiry", "-10.00"]])
nouveau = lignes(f"SELECT id, plugin_points_envelopes_id, origin_date, expiry_date, initial FROM {T}lots WHERE contracts_id = {C} AND operations_id IN (SELECT id FROM {T}operations WHERE type = 'carryover_in')")
v("(7) report : nouveau lot dans l'enveloppe cible, origine conservée, échéance = fin de la cible",
  nouveau and nouveau[0][1:], [str(c_cible["envelopes_id"]), d(-200), d(200), "8.00"])
v("(7) lot échu non reportable expiré, lot du report vidé", (restes(C)[c_np["lots_id"]], restes(C)[c_rep["lots_id"]]), ("0.00", "0.00"))
v("(7) lot qui finit aujourd'hui intact (dernier jour inclus)", restes(C)[c_borne["lots_id"]], "3.00")
v("(7) cache du contrat : 31 = 20 + 8 + 3 ; l'expiration réduit credited (48 - 17)", cache(C), [["31.00", "31.00", "0.00"]])
v("(7) contrats sans lot échu : ni date_mod ni date_cache touchés par la tâche",
  lignes(f"SELECT date_mod, date_cache FROM {T}contracts WHERE contracts_id IN ({A}, {B}, {D})"), avant_cron)
v("(7) contrat avec lot échu : écrit par la tâche", lignes(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {G} AND type = 'expiry'"), [["1"]])
v("(7) invariants du contrat C", led("check", C), {"ok": []})
nb = len(ops(C))
shell_app("php front/cron.php --force pointsexpire")
v("(7) deuxième passage : rien de nouveau (rejouable)", len(ops(C)), nb)
v("(7) expiration d'une enveloppe de 2023 du contrat E (24 mois) par la même tâche : lot expiré", [r[0] for r in ops(E)], ["credit", "expiry"])
# débit tardif : lot de 10 expiré, tâche datée d'avant l'échéance
n_exp = lignes(f"SELECT id FROM {T}operations WHERE contracts_id = {G} AND type = 'expiry'")[0][0]
tard = led("debit", G, "debit_task", 500, d(-20), {"allow_overdraft": False})
v("(7) débit tardif accepté sans dépassement", ("ok" in tard, ok(tard)["overdraft"] if "ok" in tard else None), (True, 0))
v("(7) débit tardif : reprise de 5 liée à l'expiration, puis débit de 5", lignes(
    f"SELECT type, amount, operations_id_origin FROM {T}operations WHERE contracts_id = {G} AND id > {n_exp} ORDER BY id"),
  [["expiry_reversal", "5.00", str(n_exp)], ["debit_task", "-5.00", "0"]])
v("(7) débit tardif : solde 0, 5 points restent expirés, cache cohérent", (cache(G), restes(G)[g_env["lots_id"]], led("check", G)), ([["0.00", "5.00", "5.00"]], "0.00", {"ok": []}))
v("(7) débit tardif : un débit de 6 dépasse les 5 points expirés encore reprenables", led("debit", G, "debit_task", 600, d(-20), {"allow_overdraft": False}), {"violation": "insufficient"})
v("(7) correction positive sur une expiration refusée", led("correction", C, int(lignes(f"SELECT id FROM {T}operations WHERE contracts_id = {C} AND type = 'expiry' ORDER BY id LIMIT 1")[0][0]), 100, "non"), {"violation": "not_a_debit"})
v("(7) correction positive sur un report sortant refusée", led("correction", C, int(lignes(f"SELECT id FROM {T}operations WHERE contracts_id = {C} AND type = 'carryover_out' LIMIT 1")[0][0]), 100, "non"), {"violation": "not_a_debit"})
v("(7) crédit sur une enveloppe terminée : refusé, mais accepté pour un import (témoin)",
  (led("credit", E, int(env_e), 100), "ok" in led("credit", E, int(env_e), 100, {"type": "import"})), ({"violation": "envelope_ended"}, True))
v("(7) report manuel : lot vide sans effet", ok(led("carryover", C, c_old["envelopes_id"], c_cible["envelopes_id"])), {"carried": 0, "lots": 0, "skipped": 0})

# --- (8) report manuel (ADV) : lot au reste > 0, limite de 2 ans
m1 = ok(led("createEnvelope", B, "M1", 600, d(-300), d(-5), True, ""))
m2 = ok(led("createEnvelope", B, "M2", 100, d(-30), d(300), False, ""))
v("(8) report manuel d'une enveloppe non reportable refusé", led("carryover", A, e1["envelopes_id"], e2["envelopes_id"]), {"violation": "not_reportable"})
m3 = ok(led("createEnvelope", B, "M3", 100, d(-30), d(50), True, ""))
v("(8) report manuel : un lot pas encore échu n'est pas reporté (règle 8)", ok(led("carryover", B, m3["envelopes_id"], m2["envelopes_id"])), {"carried": 0, "lots": 0, "skipped": 0})
v("(8) report manuel refusé vers la même enveloppe", led("carryover", B, m1["envelopes_id"], m1["envelopes_id"]), {"violation": "bad_target"})
v("(8) report manuel vers une enveloppe d'un autre contrat refusé", led("carryover", B, m1["envelopes_id"], c_cible["envelopes_id"]), {"violation": "unknown_envelope"})
rep = ok(led("carryover", B, m1["envelopes_id"], m2["envelopes_id"]))
v("(8) report manuel : 6 points reportés", rep, {"carried": 600, "lots": 1, "skipped": 0})
v("(8) report manuel : opérations out et in, nouveau lot d'origine conservée",
  ([r[0] + ":" + r[1] for r in ops(B) if r[0].startswith("carryover")], lignes(f"SELECT origin_date, expiry_date, initial FROM {T}lots WHERE plugin_points_envelopes_id = {m2['envelopes_id']} AND initial = 6.00")),
  (["carryover_out:-6.00", "carryover_in:6.00"], [[d(-300), d(300), "6.00"]]))
v("(8) invariants du contrat B après report", led("check", B), {"ok": []})

# --- (9) concurrence : six débits simultanés de 4 points sur 10, sans dépassement
led("createEnvelope", D, "ED", 1000, d(-5), d(100), False, "")
commande = ["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"]
entree = json.dumps({"call": "debit", "args": [D, "debit_task", 400, d(0), {"allow_overdraft": False}]})
procs = [subprocess.Popen(commande, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(6)]
sorties = []
for pr in procs:
    out, _ = pr.communicate(entree)
    sorties.append(json.loads([l for l in out.strip().splitlines() if l.startswith("{")][-1]))
v("(9) deux débits passent, quatre sont refusés faute de solde", (sum("ok" in s for s in sorties), sum(s.get("violation") == "insufficient" for s in sorties)), (2, 4))
v("(9) solde final 2.00, jamais négatif", cache(D)[0][0], "2.00")
v("(9) invariants du contrat D", led("check", D), {"ok": []})

# Verrou : une session SQL tient la ligne du contrat F, y enregistre un débit de 8 (comme un autre débit concurrent) puis valide
# après 4 secondes. Un débit de 4 lancé entre-temps doit attendre le verrou AVANT de lire les lots : il voit alors le reste
# de 2 et est refusé. Sans verrou en tête de transaction, il lirait 10, patienterait à l'écriture du cache et dépasserait.
F = contrat("points-banc-F")
ef = ok(led("createEnvelope", F, "EF", 1000, d(-5), d(100), False, ""))
tenue = subprocess.Popen(["docker", "exec", "-i", banc.DB, "mariadb", "-uroot", "-pbanc-root", "glpi"], stdin=subprocess.PIPE, text=True)
tenue.stdin.write(
    f"BEGIN; SELECT id FROM {T}contracts WHERE contracts_id = {F} FOR UPDATE; SELECT SLEEP(4);"
    f"INSERT INTO {T}operations (entities_id, is_recursive, contracts_id, type, amount, date, date_creation) VALUES ({entite}, 0, {F}, 'debit_task', -8.00, NOW(), NOW());"
    f"INSERT INTO {T}allocations (entities_id, is_recursive, operations_id, plugin_points_lots_id, amount, date_creation) VALUES ({entite}, 0, LAST_INSERT_ID(), {ef['lots_id']}, -8.00, NOW());"
    "COMMIT;")
tenue.stdin.close()
time.sleep(1.5)
debut = time.monotonic()
attente = led("debit", F, "debit_task", 400, d(0), {"allow_overdraft": False})
duree = time.monotonic() - debut
tenue.wait()
v("(9) verrou : le débit lancé pendant qu'un autre tient la ligne attend, puis lit l'état validé et est refusé", (attente, duree >= 2), ({"violation": "insufficient"}, True))
led("recomputeCache", F)
v("(9) invariants du contrat F après le débit concurrent simulé", (led("check", F), cache(F)[0][0]), ({"ok": []}, "2.00"))

# --- (10) invariants SQL globaux et détection d'une incohérence
v("(10) somme des opérations = somme des affectations",
  lignes(f"SELECT (SELECT COALESCE(SUM(amount), 0) FROM {T}operations) = (SELECT COALESCE(SUM(amount), 0) FROM {T}allocations)"), [["1"]])
v("(10) chaque opération = somme de ses affectations", lignes(
    f"SELECT COUNT(*) FROM {T}operations o LEFT JOIN (SELECT operations_id, SUM(amount) s FROM {T}allocations GROUP BY operations_id) a "
    "ON a.operations_id = o.id WHERE o.amount <> COALESCE(a.s, 0)"), [["0"]])
v("(10) solde du cache = somme du journal pour chaque contrat", lignes(
    f"SELECT COUNT(*) FROM {T}contracts c WHERE c.balance <> (SELECT COALESCE(SUM(amount), 0) FROM {T}operations o WHERE o.contracts_id = c.contracts_id)"), [["0"]])
v("(10) solde du cache = somme des restes des lots pour chaque contrat", lignes(
    f"SELECT COUNT(*) FROM {T}contracts c WHERE c.balance <> (SELECT COALESCE(SUM(a.amount), 0) FROM {T}lots l JOIN {T}allocations a ON a.plugin_points_lots_id = l.id WHERE l.contracts_id = c.contracts_id)"), [["0"]])
v("(10) aucun reste négatif hors contrat B (dépassement)", lignes(
    f"SELECT COUNT(*) FROM (SELECT l.id FROM {T}lots l JOIN {T}allocations a ON a.plugin_points_lots_id = l.id WHERE l.contracts_id <> {B} GROUP BY l.id HAVING SUM(a.amount) < 0) x"), [["0"]])
v("(10) aucune échéance de lot au-delà de 24 mois après l'origine", lignes(
    f"SELECT COUNT(*) FROM {T}lots WHERE expiry_date > DATE_ADD(origin_date, INTERVAL 24 MONTH)"), [["0"]])
sql_ecrire(f"UPDATE {T}contracts SET balance = 999 WHERE contracts_id = {D};")
anomalies = led("check", D)
v("(10) témoin positif : un cache altéré est détecté par check()", ("ok" in anomalies and len(anomalies["ok"]) > 0), True)
led("recomputeCache", D)
v("(10) recomputeCache rétablit le cache et check() redevient vide", (cache(D)[0][0], led("check", D)), ("2.00", {"ok": []}))

# --- (11) onglet « Points » du contrat et droits
web = banc.Web()
onglet = f"/ajax/common.tabs.php?_target=/front/contract.form.php&_itemtype=Contract&id={{id}}&_glpi_tab=GlpiPlugin%5CPoints%5CContract%241"
code, html = web.get(onglet.format(id=A))
v("(11) onglet Points : HTTP 200", code, 200)
v("(11) onglet : solde 52.00 et enveloppes affichés", ("52.00" in html, "E1 renommée" in html, 'id="points-ledger"' in html), (True, True, True))
v("(11) onglet : opération de correction au registre", "Oubli de 5 points" in html, True)
v("(11) onglet : formulaires ADV présents avec le droit complet",
  all(f in html for f in ("envelope.form.php", "credit.form.php", "correction.form.php", "carryover.form.php", "contract.form.php")), True)
v("(11) onglet : aucun jeton CSRF (GLPI 12)", "_glpi_csrf_token" in html, False)
code, _ = poster(web, "contract.form.php", {"contracts_id": str(A), "plugin_points_grids_id": "0", "alert_threshold": "150", "is_active": "1", "update": "1"})
v("(11) réglage : seuil 150 refusé, rien écrit", lignes(f"SELECT alert_threshold FROM {T}contracts WHERE contracts_id = {A}"), [["60"]])
code, _ = poster(web, "contract.form.php", {"contracts_id": str(A), "plugin_points_grids_id": "0", "alert_threshold": "70", "is_active": "0", "update": "1"})
v("(11) témoin : réglage valide enregistré", lignes(f"SELECT alert_threshold, is_active FROM {T}contracts WHERE contracts_id = {A}"), [["70", "0"]])
code, _ = web.get("/plugins/points/front/envelope.form.php")
v("(11) page d'écriture appelée en GET : requête refusée (400)", code, 400)
code, _ = poster(web, "envelope.form.php", {"contracts_id": "999999", "name": "x", "credit": "1", "begin_date": d(0), "end_date": d(10), "add": "1"})
v("(11) contrat inexistant : 404", code, 404)

droit("plugin_points_envelope", 1)
web2 = banc.Web()
code, html = web2.get(onglet.format(id=A))
v("(11) droit envelope = 1 : onglet lisible mais sans formulaire d'écriture",
  (code, "points-ledger" in html, any(f in html for f in ("envelope.form.php", "credit.form.php", "correction.form.php", "carryover.form.php", "contract.form.php"))), (200, True, False))
n = lignes(f"SELECT COUNT(*) FROM {T}envelopes WHERE contracts_id = {A}")
for page, champs in (("envelope.form.php", {"name": "x", "credit": "1", "begin_date": d(0), "end_date": d(10), "add": "1"}),
                     ("credit.form.php", {"envelopes_id": str(e1["envelopes_id"]), "amount": "1", "add": "1"}),
                     ("correction.form.php", {"operations_id": str(op), "amount": "-1", "reason": "x", "add": "1"}),
                     ("carryover.form.php", {"from_envelopes_id": str(e1["envelopes_id"]), "to_envelopes_id": str(e2["envelopes_id"]), "add": "1"}),
                     ("contract.form.php", {"alert_threshold": "10", "update": "1"})):
    code, _ = poster(web2, page, {"contracts_id": str(A), **champs})
    v(f"(11) droit envelope = 1 : {page} refusé (403)", code, 403)
v("(11) droit envelope = 1 : rien d'écrit", (lignes(f"SELECT COUNT(*) FROM {T}envelopes WHERE contracts_id = {A}"), len(ops(A))), (n, len(ops(A))))
droit("plugin_points_balance", 0)
web3 = banc.Web()
code, html = web3.get(onglet.format(id=A))
v("(11) sans le droit balance : le registre n'est pas servi", "points-ledger" in html, False)
droit("plugin_points_balance", 1)
droit("plugin_points_envelope", 7)
web4 = banc.Web()
code, _ = poster(web4, "credit.form.php", {"contracts_id": str(A), "envelopes_id": str(e1["envelopes_id"]), "amount": "1", "add": "1"})
v("(11) témoin : droits rétablis, le crédit passe", (code, cache(A)[0][0]), (302, "53.00"))
# L'API v1 refuse aussi la lecture sans le droit balance
droit("plugin_points_balance", 0)
api2 = banc.Api()
code, _ = api_essai(api2, "GET", f"/{CLASSE}Operation/{op}")
v("(11) sans le droit balance : lecture d'une opération par l'API refusée", code in (401, 403, 404), True)
droit("plugin_points_balance", 1)

# --- operations.date est un DATE ; le rejeu de l'installation convertit un ancien TIMESTAMP
def type_date():
    return lignes("SELECT data_type FROM information_schema.columns WHERE table_schema = 'glpi' AND table_name = 'glpi_plugin_points_operations' AND column_name = 'date'")


v("(12) operations.date est de type date", type_date(), [["date"]])
sql_ecrire(f"ALTER TABLE {T}operations MODIFY `date` timestamp NULL DEFAULT NULL;")
v("(12) témoin : colonne repassée en timestamp", type_date(), [["timestamp"]])
for args in (("plugin:install", "--force", "-u", "glpi", "points"), ("plugin:activate", "points")):
    v(f"(12) {' '.join(args)}", console(*args).returncode, 0)
v("(12) rejeu : colonne reconvertie en date, données conservées", (type_date(), lignes(f"SELECT COUNT(*) FROM {T}operations WHERE `date` IS NOT NULL")[0][0] != "0"), ([["date"]], True))
v("(12) rejeu : tâche automatique toujours unique", lignes("SELECT COUNT(*) FROM glpi_crontasks WHERE name = 'pointsexpire'"), [["1"]])

# --- remise en l'état
sql_ecrire(f"UPDATE glpi_configs SET value = '{ancien_types}' WHERE context = 'plugin:points' AND name = 'contracttypes';")
for nom, valeur in (("plugin_points_balance", 1), ("plugin_points_envelope", 7)):
    droit(nom, valeur)

lecture = lambda nom: shell_app(f"cat files/_log/{nom} 2>/dev/null; true").stdout
fautes = [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]
v("(13) aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : journal " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
