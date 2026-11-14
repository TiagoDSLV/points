"""Contrôle des soldes : commande `plugins:points:balances` (lecture seule). Le solde, le crédité, le consommé, le % consommé et la prochaine
échéance enregistrés sur la ligne Points d'un contrat sont recalculés depuis les mouvements (journal, restes des lots) ; tout écart est
listé et donne un code de sortie non nul ; rien n'est écrit. Part d'un plugin installé et activé (run.sh) ; les altérations volontaires
sont retirées par le scénario lui-même."""
import datetime
import json
import re
import subprocess
import sys
import time

import banc
from banc import Verif, console, sql, sql_ecrire

v = Verif()
T = "glpi_plugin_points_"
SUF = str(int(time.time()))
AUJOURDHUI = datetime.date.today()


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def led(appel, *args):
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes_ = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes_[-1]) if lignes_ else {"error": "pas de sortie : " + p.stderr[-300:]}


def ok(r):
    return r["ok"] if "ok" in r else None


def une(requete):
    lignes_ = sql(requete)
    return lignes_[0][0] if lignes_ else None


def soldes(*options):
    p = console("plugins:points:balances", *options)
    return p.returncode, p.stdout + p.stderr


def ligne(c):
    return sql(f"SELECT balance, credited, consumed, consumed_pct, next_expiry, date_cache, date_mod FROM {T}contracts WHERE contracts_id = {c}")[0]


def lecture(nom):
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                          capture_output=True, text=True).stdout


DEPART = {nom: len(lecture(nom).splitlines()) for nom in ("php-errors.log", "sql-errors.log")}
api = banc.Api()
entite = api._req("POST", "/Entity", {"input": {"name": f"points-soldes-{SUF}", "entities_id": 0}})["id"]
api = banc.Api()  # l'entité créée doit figurer parmi les entités actives
C = api._req("POST", "/Contract", {"input": {"name": f"soldes-{SUF}", "entities_id": entite}})["id"]
N = api._req("POST", "/Contract", {"input": {"name": f"soldes-sans-points-{SUF}", "entities_id": entite}})["id"]
led("createEnvelope", C, "E", 4000, d(-100), d(200), False, "")
led("debit", C, "debit_task", 500, d(-1), {})

# --- (1) état cohérent : tout le banc, puis le contrat seul avec --all
code, sortie = soldes()
v("(1) tous les contrats du banc : aucun écart, code 0", (code, "0 with a gap" in sortie), (0, True))
code, sortie = soldes("--all", f"--contract={C}")
v("(1) --all --contract : le contrat est listé juste (solde 35, crédité 40, consommé 5)",
  (code, f"OK   contract {C} (soldes-{SUF}): balance 35.00, credited 40.00, consumed 5.00" in sortie, "1 contract(s) checked, 0 with a gap" in sortie), (0, True, True))

# --- (2) solde enregistré faux : écart listé, code 1, rien n'est écrit (pas même le cache)
sql_ecrire(f"UPDATE {T}contracts SET balance = 99 WHERE contracts_id = {C};")
avant = ligne(C)
code, sortie = soldes()
v("(2) solde enregistré 99 pour 35 au journal : code 1, contrat et champ nommés",
  (code, f"GAP  contract {C} (soldes-{SUF})" in sortie, "balance: recorded 99.00, recomputed 35.00" in sortie, "with a gap" in sortie), (1, True, True, True))
v("(2) lecture seule : la ligne Points n'a pas changé (solde faux, date du cache, date de modification)", ligne(C), avant)

# --- (3) colonnes de recherche fausses (% consommé, prochaine échéance) : plugins:points:check ne les voit pas, balances oui
led("recomputeCache", C)
v("(3) témoin : cache recalculé, aucun écart", soldes()[0], 0)
sql_ecrire(f"UPDATE {T}contracts SET consumed_pct = 0, next_expiry = NULL WHERE contracts_id = {C};")
code, sortie = soldes(f"--contract={C}")
v("(3) % consommé et échéance faux : code 1, deux écarts nommés",
  (code, "consumed_pct: recorded 0.00, recomputed 12.50" in sortie, f"next_expiry: recorded -, recomputed {d(200)}" in sortie), (1, True, True))
led("recomputeCache", C)

# --- (4) restes des lots différents du journal (affectation isolée, sans opération) : écart « lots »
lot = int(une(f"SELECT id FROM {T}lots WHERE contracts_id = {C}"))
sql_ecrire(f"INSERT INTO {T}allocations (entities_id, is_recursive, operations_id, plugin_points_lots_id, amount, date_creation) VALUES ({entite}, 0, 0, {lot}, 1, NOW());")
code, sortie = soldes(f"--contract={C}")
v("(4) affectation isolée : code 1, journal 35 contre restes 36", (code, "lots: journal 35.00, sum of the lot remainders 36.00" in sortie), (1, True))
sql_ecrire(f"DELETE FROM {T}allocations WHERE plugin_points_lots_id = {lot} AND operations_id = 0;")
v("(4) témoin : affectation retirée, aucun écart", soldes(f"--contract={C}")[0], 0)

# --- (5) options : contrat sans points, identifiant invalide
code, sortie = soldes(f"--contract={N}")
v("(5) contrat sans points : code 1, message clair", (code, f"Contract {N} has no points" in sortie), (1, True))
code, sortie = soldes("--contract=abc")
v("(5) identifiant invalide : code 1", (code, "--contract expects a contract ID" in sortie), (1, True))

# --- (6) état final : tout le banc cohérent, journaux propres
v("(6) tous les contrats du banc : aucun écart à la sortie", soldes()[0], 0)
v("(6) plugins:points:check d'accord", console("plugins:points:check").returncode, 0)
fautes = [l for nom in DEPART for l in lecture(nom).splitlines()[DEPART[nom]:] if re.search("points", l, re.I)]
v("aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : soldes " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
