"""Import des soldes du BPM Kiwi (lot h) : commande `plugins:points:import` de la console GLPI. Simulation sans écriture,
application, rejeu sans doublon, double lancement concurrent, lignes invalides (rien d'écrit), contrat inconnu / sans points / à la
corbeille / ambigu, dates de plus de 24 mois, BOM, virgule décimale, fin passée signalée, rapport sans injection, soldes visibles
dans l'onglet et la recherche. Rejouable sur un banc gardé (suffixe unique par passage). Fichiers fictifs, déposés dans /tmp du conteneur."""
import datetime
import json
import re
import subprocess
import sys
import time

import banc
from banc import Verif, console, shell_app, sql, sql_ecrire

v = Verif()
AUJOURDHUI = datetime.date.today()
T = "glpi_plugin_points_"
SUF = str(int(time.time()))
TABLES = ["envelopes", "operations", "lots", "allocations", "contracts"]
ENTETE = "contrat;solde;debut;fin;report;libelle"


def d(jours):
    return (AUJOURDHUI + datetime.timedelta(days=jours)).isoformat()


def comptes():
    return [sql(f"SELECT COUNT(*) FROM {T}{t}")[0][0] for t in TABLES]


def deposer(nom, contenu):
    """Dépose un fichier (texte ou octets) dans /tmp du conteneur ; renvoie son chemin."""
    octets = contenu if isinstance(contenu, bytes) else contenu.encode()
    chemin = f"/tmp/{SUF}-{nom}"
    subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh", "-c", f"cat > {chemin}"], input=octets, check=True)
    return chemin


def importer(chemin, *options):
    return console("plugins:points:import", chemin, *options)


def sortie(p):
    return p.stdout + p.stderr


def csv(*lignes):
    return ENTETE + "\n" + "\n".join(lignes) + "\n"


api = banc.Api()
ancien_types = sql("SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = 'contracttypes'")[0][0]
entite = api._req("POST", "/Entity", {"input": {"name": f"points-import-{SUF}", "entities_id": 0}})["id"]
type_points = api._req("POST", "/ContractType", {"input": {"name": f"import-points-{SUF}"}})["id"]
type_autre = api._req("POST", "/ContractType", {"input": {"name": f"import-autre-{SUF}"}})["id"]
sql_ecrire(f"UPDATE glpi_configs SET value = '[{type_points}]' WHERE context = 'plugin:points' AND name = 'contracttypes';")
console("cache:clear")
api = banc.Api()  # l'entité créée doit figurer parmi les entités actives


def contrat(num, type_=None):
    return api._req("POST", "/Contract", {"input": {"name": f"imp-{num}-{SUF}", "num": f"{num}-{SUF}", "entities_id": entite,
                                                       "contracttypes_id": type_ or type_points}})["id"]


def num(n):
    return f"{n}-{SUF}"


C1, C2, C3, C4, CCONC = (contrat(n) for n in ("IMP-001", "IMP-002", "IMP-003", "IMP-004", "IMP-CONC"))
C_AUTRE = contrat("IMP-AUTRE", type_autre)
C_CORBEILLE = contrat("IMP-CORBEILLE")
sql_ecrire(f"UPDATE glpi_contracts SET is_deleted = 1 WHERE id = {C_CORBEILLE};")
contrat("IMP-AMBIGU")
contrat("IMP-AMBIGU")
contrat("IMP-ÉCOLE")  # la base compare les numéros sans accent : « IMP-ECOLE » désigne le même contrat

# --- (0) la commande est chargée par GLPI sous le préfixe exigé
p = console("list", "plugins:points")
v("(0) la commande plugins:points:import est enregistrée", "plugins:points:import" in sortie(p), True)
p = console("plugins:points:import")
v("(0) sans fichier : refus (code non nul)", p.returncode != 0, True)
p = importer("/tmp/n-existe-pas.csv")
v("(0) fichier absent : code non nul", p.returncode != 0, True)

# --- (1) simulation : rapport, aucune écriture
ok = csv(f"{num('IMP-001')};120,5;{d(-60)};{d(300)};1;Solde initial Kiwi",
         f"{num('IMP-002')};7.25;{d(-30)};{d(150)};0;",
         f"{num('IMP-001')};30;{d(-60)};{d(300)};0;Complément",
         f"{num('IMP-003')};10;{d(-400)};{d(-300)};0;Ancien")
f_ok = deposer("ok.csv", "﻿" + ok.replace("\n", "\r\n"))  # BOM et fins de ligne Windows
avant = comptes()
p = importer(f_ok)
v("(1) simulation : code 0", p.returncode, 0)
v("(1) simulation : aucune écriture (enveloppes, opérations, lots, affectations, lignes Points)", comptes(), avant)
v("(1) simulation : rapport ligne à ligne (4 lignes NEW)", len(re.findall(r"line \d: NEW", sortie(p))), 4)
v("(1) simulation : libellé de l'enveloppe, solde à virgule", ("120.50 points" in sortie(p), "7.25 points" in sortie(p)), (True, True))
v("(1) simulation : la fin passée est signalée", len(re.findall(r"warning: The points expire on .* already past", sortie(p))), 1)
v("(1) simulation : dit comment écrire", "use --apply" in sortie(p), True)

# --- (2) application
p = importer(f_ok, "--apply")
v("(2) application : code 0", p.returncode, 0)
v("(2) application : 4 enveloppes, 4 opérations, 4 lots, 4 affectations, 4 lignes Points (4 contrats)",
  [int(a) - int(b) for a, b in zip(comptes(), avant)], [4, 4, 4, 4, 3])
v("(2) application : message de fin", "4 envelope(s) created, 0 skipped" in sortie(p), True)
ops = sql(f"SELECT type, amount, date, comment, users_id, itemtype FROM {T}operations WHERE contracts_id = {C1} ORDER BY id")
v("(2) opérations du contrat 1 : type import, solde, date = début, commentaire Import BPM fichier ligne, auteur 0",
  ops, [["import", "120.50", d(-60), f"Import BPM {SUF}-ok.csv ligne 2", "0", "GlpiPlugin\\\\Points\\\\Envelope"],
        ["import", "30.00", d(-60), f"Import BPM {SUF}-ok.csv ligne 4", "0", "GlpiPlugin\\\\Points\\\\Envelope"]])
v("(2) enveloppe : libellé, dates, report, entité du contrat, auteur 0",
  sql(f"SELECT name, credit, begin_date, end_date, is_carryover, entities_id, users_id FROM {T}envelopes WHERE contracts_id = {C1} ORDER BY id"),
  [["Solde initial Kiwi", "120.50", d(-60), d(300), "1", str(entite), "0"], ["Complément", "30.00", d(-60), d(300), "0", str(entite), "0"]])
v("(2) libellé vide : nom par défaut début / fin", sql(f"SELECT name FROM {T}envelopes WHERE contracts_id = {C2}"), [[f"{d(-30)} / {d(150)}"]])
v("(2) lot : origine = début, échéance = fin de l'enveloppe, reste = solde",
  sql(f"SELECT l.origin_date, l.expiry_date, l.initial, SUM(a.amount) FROM {T}lots l JOIN {T}allocations a ON a.plugin_points_lots_id = l.id WHERE l.contracts_id = {C2} GROUP BY l.id"),
  [[d(-30), d(150), "7.25", "7.25"]])
v("(2) cache du contrat 1 : 150.50 crédités, aucun consommé", sql(f"SELECT balance, credited, consumed FROM {T}contracts WHERE contracts_id = {C1}"), [["150.50", "150.50", "0.00"]])
v("(2) cache du contrat 2 (virgule et point décimaux)", sql(f"SELECT balance FROM {T}contracts WHERE contracts_id = {C2}"), [["7.25"]])
v("(2) l'entité de la ligne Points est celle du contrat", sql(f"SELECT entities_id FROM {T}contracts WHERE contracts_id = {C1}"), [[str(entite)]])
def led(appel, *args):
    """Ledger::<appel> dans le conteneur (ledger_cli.php) ; renvoie le JSON {"ok" | "violation" | "error": …}."""
    p = subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "php", "plugins/points/tests/banc/ledger_cli.php"],
                       input=json.dumps({"call": appel, "args": list(args)}), capture_output=True, text=True)
    lignes = [l for l in p.stdout.strip().splitlines() if l.startswith("{")]
    return json.loads(lignes[-1]) if lignes else {"error": "pas de sortie : " + p.stderr[-300:]}


v("(2) invariants du journal du contrat 1", led("check", C1), {"ok": []})

# --- (3) soldes visibles dans l'onglet du contrat et la recherche native
web = banc.Web()
code, html = web.get(f"/ajax/common.tabs.php?_target=/front/contract.form.php&_itemtype=Contract&id={C1}&_glpi_tab=GlpiPlugin%5CPoints%5CContract%241")
v("(3) onglet Points du contrat 1 : solde 150.50, enveloppes importées, type import", (code, "150.50" in html, "Solde initial Kiwi" in html, "Complément" in html), (200, True, True, True))
rows = api._req("GET", "/search/Contract?range=0-999&forcedisplay[0]=1&forcedisplay[1]=9701&forcedisplay[2]=9702&criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=" + SUF)["data"]
par_nom = {r["1"]: (r["9701"], r["9702"]) for r in rows}
v("(3) recherche native : solde et crédité du contrat 1", par_nom.get(f"imp-IMP-001-{SUF}"), ("150.50", "150.50"))
v("(3) recherche native : solde du contrat 2", par_nom.get(f"imp-IMP-002-{SUF}", (None,))[0], "7.25")

# --- (4) rejeu : aucune écriture, lignes sautées
avant = comptes()
p = importer(f_ok)
v("(4) rejeu en simulation : 4 lignes SKIP, code 0", (p.returncode, len(re.findall(r"line \d: SKIP", sortie(p)))), (0, 4))
p = importer(f_ok, "--apply")
v("(4) rejeu en application : code 0, rien d'écrit, 4 sautées", (p.returncode, comptes() == avant, "0 envelope(s) created, 4 skipped" in sortie(p)), (0, True, True))
# empreinte dans l'opération : renommer une enveloppe (son nom reste modifiable) ne fait pas recréditer au rejeu
sql_ecrire(f"UPDATE {T}envelopes SET name = 'Renommée {SUF}' WHERE contracts_id IN ({C1}, {C2});")
avant = comptes()
cache_avant = sql(f"SELECT contracts_id, balance, credited FROM {T}contracts WHERE contracts_id IN ({C1}, {C2}) ORDER BY contracts_id")
p = importer(f_ok)
v("(4) enveloppes renommées : le rejeu en simulation les voit déjà importées", (p.returncode, len(re.findall(r"line \d: SKIP", sortie(p)))), (0, 4))
p = importer(f_ok, "--apply")
v("(4) enveloppes renommées : rejeu sans rien recréditer", (p.returncode, comptes() == avant, sql(f"SELECT contracts_id, balance, credited FROM {T}contracts WHERE contracts_id IN ({C1}, {C2}) ORDER BY contracts_id") == cache_avant),
  (0, True, True))
v("(4) l'empreinte est dans les détails de l'opération import", sql(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {C1} AND type = 'import' AND details LIKE '%import_key%'"), [["2"]])
# une seule ligne nouvelle dans un fichier déjà importé : seule elle est écrite
f_plus = deposer("plus.csv", ok + f"{num('IMP-004')};5;{d(-10)};{d(100)};0;Nouveau\n")
p = importer(f_plus, "--apply")
v("(4) fichier complété : seule la ligne nouvelle est écrite", (p.returncode, [int(a) - int(b) for a, b in zip(comptes(), avant)]), (0, [1, 1, 1, 1, 1]))
# la même clé avec un autre solde ou un autre libellé est une autre enveloppe
f_autre = deposer("autre.csv", csv(f"{num('IMP-004')};5.01;{d(-10)};{d(100)};0;Nouveau", f"{num('IMP-004')};5;{d(-10)};{d(100)};0;Nouveau bis"))
avant = comptes()
p = importer(f_autre, "--apply")
v("(4) solde ou libellé différent : deux nouvelles enveloppes", (p.returncode, [int(a) - int(b) for a, b in zip(comptes(), avant)]), (0, [2, 2, 2, 2, 0]))

# --- (5) lignes invalides : rien d'écrit, même avec --apply et même si d'autres lignes sont bonnes
bonne = f"{num('IMP-004')};9;{d(-5)};{d(50)};0;Bonne ligne"
cas = {
    "contrat inconnu": (bonne + f"\nINCONNU-{SUF};1;{d(-5)};{d(50)};0;x", "line 3: Unknown contract number"),
    "contrat d'un type sans points": (bonne + f"\n{num('IMP-AUTRE')};1;{d(-5)};{d(50)};0;x", "line 3: The contract type is not a points type"),
    "contrat à la corbeille": (bonne + f"\n{num('IMP-CORBEILLE')};1;{d(-5)};{d(50)};0;x", "line 3: The contract is in the trash"),
    "numéro porté par deux contrats": (bonne + f"\n{num('IMP-AMBIGU')};1;{d(-5)};{d(50)};0;x", "line 3: Several contracts have this number"),
    "plus de 24 mois": (bonne + f"\n{num('IMP-001')};1;2026-01-01;2028-01-02;0;x", "line 3: The envelope lasts more than 24 months"),
    "fin avant début": (bonne + f"\n{num('IMP-001')};1;{d(10)};{d(5)};0;x", "line 3: The end date is before the begin date"),
    "date invalide": (bonne + f"\n{num('IMP-001')};1;2026-02-30;{d(5)};0;x", "line 3: Invalid begin date"),
    "solde nul": (bonne + f"\n{num('IMP-001')};0;{d(-5)};{d(50)};0;x", "line 3: The balance must be greater than 0"),
    "solde négatif": (bonne + f"\n{num('IMP-001')};-4;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "solde à trois décimales (aucun arrondi)": (bonne + f"\n{num('IMP-001')};1.500;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "solde 12,345": (bonne + f"\n{num('IMP-001')};12,345;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "solde 0,005 (arrondi à 0,01)": (bonne + f"\n{num('IMP-001')};0,005;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "solde avec séparateur de milliers": (bonne + f"\n{num('IMP-001')};1 000;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "solde avec espace insécable": (bonne + f"\n{num('IMP-001')};5\u00a0;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "libellé avec caractère bidi": (bonne + f"\n{num('IMP-001')};1;{d(-5)};{d(50)};0;x\u202ey", "line 3: Invalid label"),
    "doublon selon la collation (casse et accent)": (f"{num('IMP-004')};3;{d(-5)};{d(50)};0;Reliquat été\n{num('IMP-004')};3;{d(-5)};{d(50)};0;Reliquat ete", "line 3: Duplicate of line 2"),
    "doublon selon la collation (ß = ss)": (f"{num('IMP-004')};3;{d(-5)};{d(50)};0;Strasse\n{num('IMP-004')};3;{d(-5)};{d(50)};0;Straße", "line 3: Duplicate of line 2"),
    "solde illisible": (bonne + f"\n{num('IMP-001')};beaucoup;{d(-5)};{d(50)};0;x", "line 3: Invalid balance"),
    "report à 2": (bonne + f"\n{num('IMP-001')};1;{d(-5)};{d(50)};2;x", "line 3: The carry-over flag must be 0 or 1"),
    "colonne manquante": (bonne + f"\n{num('IMP-001')};1;{d(-5)};{d(50)};0", "line 3: Expected 6 columns"),
    "doublon dans le fichier": (bonne + "\n" + bonne, "line 3: Duplicate of line 2"),
    "doublon après résolution du contrat (accent, la base compare sans accent)": (f"{num('IMP-ÉCOLE')};2;{d(-5)};{d(50)};0;x\n{num('IMP-ECOLE')};2;{d(-5)};{d(50)};0;x", "line 3: Duplicate of line 2 (same contract"),
    "doublon après résolution du contrat (casse)": (bonne + "\n" + bonne.replace("IMP-004", "imp-004"), "line 3: Duplicate of line 2"),
}
for nom, (lignes_, attendu) in cas.items():
    f = deposer("invalide.csv", csv(*lignes_.split("\n")))
    avant = comptes()
    for options in ((), ("--apply",)):
        p = importer(f, *options)
        v(f"(5) {nom}{' avec --apply' if options else ''} : code non nul, rien d'écrit, message de la ligne",
          (p.returncode != 0, comptes() == avant, attendu in sortie(p)), (True, True, True))
f = deposer("temoin.csv", csv(bonne))
avant = comptes()
p = importer(f, "--apply")
v("(5) témoin : la ligne bonne, seule, est écrite", (p.returncode, [int(a) - int(b) for a, b in zip(comptes(), avant)]), (0, [1, 1, 1, 1, 0]))
for nom, contenu, attendu in (("en-tête inattendu", "contrat,solde,debut,fin,report,libelle\n", "Unexpected header"),
                              ("fichier vide", "", "The file is empty"),
                              ("sans ligne de données", ENTETE + "\n", "no data line"),
                              ("texte non UTF-8", ENTETE.encode() + f"\n{num('IMP-004')};1;{d(-5)};{d(50)};0;caf\xe9\n".encode("latin-1"), "not valid UTF-8")):
    f = deposer("format.csv", contenu)
    avant = comptes()
    p = importer(f, "--apply")
    v(f"(5) {nom} : code non nul, rien d'écrit", (p.returncode != 0, comptes() == avant, attendu in sortie(p)), (True, True, True))

# --- (6) rapport : aucune injection (formule de tableur, balise de console, séquence d'échappement)
f = deposer("rapport.csv", csv(f"{num('IMP-004')};1;{d(-5)};{d(50)};0;=HYPERLINK(\"http://x\")".replace('"', ""),
                               f"{num('IMP-004')};2;{d(-5)};{d(50)};0;<info>gras</info>",
                               f"{num('IMP-004')};3;{d(-5)};{d(50)};0;+cmd|calc"))
avant = comptes()
p = importer(f)
v("(6) rapport : la formule est neutralisée (apostrophe), le libellé balisé est affiché tel quel", (p.returncode, "'=HYPERLINK" in sortie(p), 'label "=' in sortie(p), "<info>gras</info>" in sortie(p), "label \"'+cmd" in sortie(p)), (0, True, False, True, True))
v("(6) rapport : simulation sans écriture", comptes(), avant)
f = deposer("controle.csv", csv(f"{num('IMP-004')};1;{d(-5)};{d(50)};0;a\x1b[31mrouge"))
p = importer(f, "--apply")
v("(6) séquence d'échappement dans un libellé : refus", (p.returncode != 0, "\x1b[31m" in sortie(p)), (True, False))

# --- (7) dates à la limite : 24 mois pile accepté, fin passée acceptée et signalée, expirée ensuite par la tâche
debut = datetime.date(AUJOURDHUI.year - 3, 1, 1)
fin24 = datetime.date(AUJOURDHUI.year - 1, 1, 1)
f = deposer("limite.csv", csv(f"{num('IMP-003')};4;{debut};{fin24};0;Vingt-quatre mois"))
avant = comptes()
p = importer(f, "--apply")
v("(7) 24 mois pile et fin passée : accepté, signalé", (p.returncode, "already past" in sortie(p), [int(a) - int(b) for a, b in zip(comptes(), avant)]), (0, True, [1, 1, 1, 1, 0]))
p = shell_app("php front/cron.php --force pointsexpire")
v("(7) la tâche pointsexpire expire les lots échus de l'import",
  sorted(sql(f"SELECT type, amount FROM {T}operations WHERE contracts_id = {C3}")),
  sorted([["import", "10.00"], ["import", "4.00"], ["expiry", "-10.00"], ["expiry", "-4.00"]]))
v("(7) solde du contrat 3 après expiration", sql(f"SELECT balance FROM {T}contracts WHERE contracts_id = {C3}"), [["0.00"]])

# --- (8) double lancement concurrent : une seule écriture par ligne
f = deposer("conc.csv", csv(*(f"{num('IMP-CONC')};{n + 1};{d(-20)};{d(200)};0;Ligne {n}" for n in range(8))))
avant = comptes()
procs = [subprocess.Popen(["docker", "exec", "-u", "www-data", banc.APP, "php", "bin/console", "plugins:points:import", f, "--apply"],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(4)]
sorties = [(q.wait(), *q.communicate()) for q in procs]
v("(8) quatre lancements simultanés : tous code 0", [s[0] for s in sorties], [0, 0, 0, 0])
creees = sum(int(m.group(1)) for s in sorties for m in re.finditer(r"(\d+) envelope\(s\) created", s[1]))
v("(8) 8 enveloppes au total (aucun doublon), 8 opérations import, 8 lots", (sql(f"SELECT COUNT(*) FROM {T}envelopes WHERE contracts_id = {CCONC}")[0][0],
                                                                       sql(f"SELECT COUNT(*) FROM {T}operations WHERE contracts_id = {CCONC} AND type = 'import'")[0][0],
                                                                       sql(f"SELECT COUNT(*) FROM {T}lots WHERE contracts_id = {CCONC}")[0][0]), ("8", "8", "8"))
v("(8) invariants du journal du contrat", led("check", CCONC), {"ok": []})
v("(8) la somme des lignes créées par les quatre processus est 8", creees, 8)
v("(8) cache du contrat : 36 = 1 + … + 8", sql(f"SELECT balance, credited FROM {T}contracts WHERE contracts_id = {CCONC}"), [["36.00", "36.00"]])

# --- (9) plugin inactif : la commande ne tourne pas (le chargeur ne la charge pas ; la classe refuse aussi)
avant = comptes()
f = deposer("inactif.csv", csv(f"{num('IMP-004')};77;{d(-5)};{d(50)};0;Plugin inactif"))
v("(9) plugin désactivé", console("plugin:deactivate", "points").returncode, 0)
p = importer(f, "--apply")
v("(9) plugin inactif : import impossible (code non nul, rien d'écrit)", (p.returncode != 0, comptes() == avant), (True, True))
v("(9) plugin réactivé", console("plugin:activate", "points").returncode, 0)
p = importer(f)
v("(9) témoin : plugin actif, la simulation passe", (p.returncode, comptes() == avant), (0, True))

# --- remise en l'état et journaux
sql_ecrire(f"UPDATE glpi_configs SET value = '{ancien_types}' WHERE context = 'plugin:points' AND name = 'contracttypes';")
console("cache:clear")
lecture = lambda nom: shell_app(f"cat /var/glpi/logs/{nom} 2>/dev/null; true").stdout
fautes = [l for nom in ("php-errors.log", "sql-errors.log") for l in lecture(nom).splitlines() if re.search("points", l, re.I)]
v("(10) aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : import " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
