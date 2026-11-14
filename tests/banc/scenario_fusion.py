"""Fusion de tickets (Ticket::merge, src/Ticket.php:5765-6060 en 12.0.1) et points décomptés : forfait du ticket source (débité ou
approuvé), fusion sans les tâches, fusion avec un lien autre que « fils de » puis restauration du ticket source. Aucun double décompte,
aucun point perdu. La documentation officielle ne décrit pas la fusion (llms.txt de help.glpi-project.org, 2026-11-14) : le code tranche.
Part d'un plugin installé et activé (run.sh) ; remet la configuration comme il l'a trouvée."""
import atexit
import datetime
import json
import re
import subprocess
import sys
import time
from zoneinfo import ZoneInfo

import banc
from banc import ErreurApi, Verif, console, sql, sql_ecrire

v = Verif()
T = "glpi_plugin_points_"
SUF = str(int(time.time()))
AUJOURDHUI = datetime.datetime.now(ZoneInfo("Europe/Paris")).date()
LIE, AUCUN = 1, 0  # CommonITILObject_CommonITILObject::LINK_TO ; 0 = pas de lien (src/Ticket.php:2204-2210)


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


def une(requete):
    lignes_ = sql(requete)
    return lignes_[0][0] if lignes_ else None


def creer(api_, itemtype, entree):
    return api_._req("POST", f"/{itemtype}", {"input": entree})["id"]


def config(cle, valeur):
    sql_ecrire(f"UPDATE glpi_configs SET value = '{valeur}' WHERE context = 'plugin:points' AND name = '{cle}';")


CLES = ("contracttypes", "debit_moment", "allow_overdraft", "calendars_id", "default_interventiontypes_id")
ancien = {c: une(f"SELECT value FROM glpi_configs WHERE context = 'plugin:points' AND name = '{c}'") for c in CLES}


FORFAITS = []  # forfaits du scénario : leur historique natif (glpi_logs) est retiré à la sortie (scenario_socle compte ceux du plugin)


def remettre():
    for cle, valeur in ancien.items():
        config(cle, valeur)
    if FORFAITS:
        sql_ecrire(f"DELETE FROM glpi_logs WHERE itemtype = 'GlpiPlugin\\\\Points\\\\Flatrate' AND items_id IN ({','.join(map(str, FORFAITS))});")


atexit.register(remettre)


def lecture(nom):
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", banc.APP, "sh"], input=f"cat /var/glpi/logs/{nom} 2>/dev/null; true",
                          capture_output=True, text=True).stdout


DEPART = {nom: len(lecture(nom).splitlines()) for nom in ("php-errors.log", "sql-errors.log")}
api = banc.Api()
types = {r[1]: int(r[0]) for r in sql(f"SELECT id, name FROM {T}interventiontypes")}
ASSIST = types["Assistance"]
type_points = creer(api, "ContractType", {"name": f"fusion-points-{SUF}"})
config("contracttypes", f"[{type_points}]")
config("debit_moment", "closure")
config("allow_overdraft", "1")
config("calendars_id", "0")
config("default_interventiontypes_id", str(ASSIST))
console("cache:clear")
entite = creer(api, "Entity", {"name": f"points-fusion-{SUF}", "entities_id": 0})
api = banc.Api()

# grille : Assistance 1 point par heure, tous les jours
sql_ecrire(f"INSERT INTO {T}grids (entities_id, is_recursive, name, unit_minutes, is_active) VALUES (0, 1, 'Fusion-{SUF}', 60, 1);")
grille = int(une(f"SELECT id FROM {T}grids WHERE name = 'Fusion-{SUF}'"))
sql_ecrire(f"INSERT INTO {T}gridversions (entities_id, is_recursive, plugin_points_grids_id, begin_date) VALUES (0, 1, {grille}, '{d(-400)}');")
version = int(une(f"SELECT id FROM {T}gridversions WHERE plugin_points_grids_id = {grille}"))
for jour in (1, 2, 3):
    sql_ecrire(f"INSERT INTO {T}gridcosts (entities_id, is_recursive, plugin_points_gridversions_id, plugin_points_interventiontypes_id, daytype, cost) "
               f"VALUES (0, 1, {version}, {ASSIST}, {jour}, 1);")
mardi = dernier(2)


def avec_points(nom, credit=4000):
    c = creer(api, "Contract", {"name": f"fusion-{nom}-{SUF}", "entities_id": entite, "contracttypes_id": type_points})
    led("createEnvelope", c, "E", credit, d(-100), d(200), False, "")
    sql_ecrire(f"UPDATE {T}contracts SET plugin_points_grids_id = {grille} WHERE contracts_id = {c};")
    return c


def ticket(nom, c):
    return creer(api, "Ticket", {"name": nom, "content": nom, "entities_id": entite, "_contracts_id": c})


def tache(tk, secondes, contenu):
    try:
        return creer(api, "TicketTask", {"tickets_id": tk, "content": contenu, "actiontime": secondes, "date": mardi + " 09:00:00"})
    except ErreurApi as e:
        return str(e)


def clore(tk):
    try:
        api._req("PUT", f"/Ticket/{tk}", {"input": {"status": 6}})
    except ErreurApi:
        pass


def forfait(tk, c, montant, statut="approved"):
    """Forfait posé directement à l'état voulu (le cycle complet est testé par scenario_forfaits.py)."""
    sql_ecrire(f"INSERT INTO {T}flatrates (entities_id, is_recursive, tickets_id, contracts_id, amount, description, status, approved_amount, date_creation, date_mod) "
               f"VALUES ({entite}, 0, {tk}, {c}, {montant}, 'fusion', '{statut}', {montant if statut == 'approved' else 0}, NOW(), NOW());")
    FORFAITS.append(int(une(f"SELECT MAX(id) FROM {T}flatrates WHERE tickets_id = {tk}")))
    return FORFAITS[-1]


def corbeille(tk):
    return une(f"SELECT is_deleted FROM glpi_tickets WHERE id = {tk}")


def nb_taches(tk):
    return int(une(f"SELECT COUNT(*) FROM glpi_tickettasks WHERE tickets_id = {tk}"))


def debits_taches(c):
    """Débits de tâches du contrat : [(ticket, montant)]."""
    return [(int(r[0]), r[1]) for r in sql(f"SELECT tickets_id, amount FROM {T}operations WHERE contracts_id = {c} AND type = 'debit_task' ORDER BY id")]


def solde(c):
    return une(f"SELECT COALESCE(SUM(amount), 0) FROM {T}operations WHERE contracts_id = {c}")


def fusion(cible, source, **params):
    """(code de fusion du source : 0 fusionné, 1 erreur, 2 droits ; messages)."""
    r = ok(glpi("merge", cible, [source], params or None))
    if not r:
        return None, []
    statut = r["status"] if isinstance(r["status"], dict) else {}  # source déjà à la corbeille : ignoré par Ticket::merge, aucun statut
    return statut.get(str(source)), r["messages"]


# =====================================================================================================================
# (1) source au forfait DÉBITÉ, fusion avec les tâches : les tâches couvertes par le forfait seraient débitées une seconde fois sur le
#     ticket principal (aucun forfait n'y protège les copies). Refus de la fusion : rien n'est fusionné, rien n'est débité en plus.
# =====================================================================================================================
C1 = avec_points("forfait-debite")
S1, M1 = ticket("fusion source au forfait débité", C1), ticket("fusion principal 1", C1)
forfait(S1, C1, 5)
v("(1) témoin : la tâche du ticket au forfait est acceptée", isinstance(tache(S1, 3600, "travail au forfait"), int), True)
f1 = int(une(f"SELECT id FROM {T}flatrates WHERE tickets_id = {S1}"))
v("(1) témoin : forfait débité (5 points)", (ok(glpi("flatrate_debit", f1)) is not None, une(f"SELECT status FROM {T}flatrates WHERE id = {f1}"), solde(C1)), (True, "debited", "35.00"))
code, messages = fusion(M1, S1)
v("(1) fusion refusée : le source reste hors corbeille, aucune tâche copiée", (code, corbeille(S1), nb_taches(M1)), (1, "0", 0))
v("(1) le refus est expliqué (forfait du ticket source)", any("has a flatrate that is neither refused nor cancelled" in m for m in messages), True)
clore(M1)
v("(1) aucun double décompte : le contrat ne porte que le forfait (aucun débit de tâche), solde 35", (debits_taches(C1), solde(C1)), ([], "35.00"))

# =====================================================================================================================
# (2) source au forfait APPROUVÉ (débit à la clôture) : à la corbeille, il ne serait jamais clos, le forfait jamais débité (points perdus)
#     et ses tâches débitées à la place sur le principal. Refus ; témoin : le source clos débite son forfait une fois.
# =====================================================================================================================
C2 = avec_points("forfait-approuve")
S2, M2 = ticket("fusion source au forfait approuvé", C2), ticket("fusion principal 2", C2)
f2 = forfait(S2, C2, 7)
tache(S2, 7200, "travail au forfait approuvé")
code, messages = fusion(M2, S2)
v("(2) fusion refusée : source hors corbeille, aucune tâche copiée", (code, corbeille(S2), nb_taches(M2)), (1, "0", 0))
clore(S2)
clore(M2)
v("(2) témoin : le source clos débite son forfait une fois, aucune tâche débitée ; solde 33",
  (une(f"SELECT status FROM {T}flatrates WHERE id = {f2}"), debits_taches(C2), solde(C2)), ("debited", [], "33.00"))

# =====================================================================================================================
# (3) fusion SANS les tâches (case « Fusionner les tâches » décochée) d'un source facturable dont une tâche n'est pas débitée : la tâche
#     resterait sur le source en corbeille, jamais débitée (points perdus). Refus ; témoins : avec les tâches, ou sans tâche à débiter.
# =====================================================================================================================
C3 = avec_points("sans-taches")
S3, M3 = ticket("fusion source sans copie des tâches", C3), ticket("fusion principal 3", C3)
tache(S3, 3600, "à débiter")
code, messages = fusion(M3, S3, linktypes=[])
v("(3) fusion sans les tâches refusée : le source garde sa tâche à débiter", (code, corbeille(S3)), (1, "0"))
v("(3) le refus est expliqué (tâches à débiter)", any("has tasks to debit that are not copied to the main ticket" in m for m in messages), True)
code, _ = fusion(M3, S3)
v("(3) témoin : la même fusion AVEC les tâches passe", (code, corbeille(S3), nb_taches(M3)), (0, "1", 1))
clore(M3)
v("(3) témoin : la copie est débitée une fois sur le principal", debits_taches(C3), [(M3, "-1.00")])
S3b, M3b = ticket("fusion source sans tâche", C3), ticket("fusion principal 3b", C3)
code, _ = fusion(M3b, S3b, linktypes=[])
v("(3) témoin : sans tâche à débiter, la fusion sans les tâches passe", (code, corbeille(S3b)), (0, "1"))

# =====================================================================================================================
# (4) fusion avec les tâches et un lien « lié à » ou sans lien, puis restauration et clôture du source : les originaux, déjà copiés sur le
#     principal, ne se débitent pas une seconde fois (le suivi de fusion, toujours posé par Ticket::merge, désigne le principal).
# =====================================================================================================================
for n, lien in ((4, LIE), (5, AUCUN)):
    C4 = avec_points(f"lien-{lien}")
    S4, M4 = ticket(f"fusion source lien {lien}", C4), ticket(f"fusion principal lien {lien}", C4)
    tache(S4, 3600, "copiée")
    code, _ = fusion(M4, S4, link_type=lien)
    v(f"({n}) fusion avec lien {lien} : passe, tâche copiée", (code, corbeille(S4), nb_taches(M4)), (0, "1", 1))
    sql_ecrire(f"UPDATE glpi_tickets SET is_deleted = 0 WHERE id = {S4};")  # restauration du ticket source
    clore(S4)
    clore(M4)
    v(f"({n}) source restauré puis clos : rien n'est débité sur lui ; la copie l'est une fois sur le principal", debits_taches(C4), [(M4, "-1.00")])
    v(f"({n}) invariants du journal", ok(led("check", C4)), [])

# =====================================================================================================================
# (6) témoin : un ticket au forfait débité qui n'est pas fusionné va à la corbeille comme avant (le refus ne vise que la fusion)
# =====================================================================================================================
C6 = avec_points("corbeille")
S6 = ticket("ticket au forfait mis à la corbeille", C6)
f6 = forfait(S6, C6, 2)
glpi("flatrate_debit", f6)
try:
    api._req("DELETE", f"/Ticket/{S6}")
except ErreurApi:
    pass
v("(6) témoin : mise à la corbeille hors fusion inchangée", corbeille(S6), "1")

# =====================================================================================================================
# (7) le principal ne débiterait pas les copies sur le même contrat : non facturable, autre contrat, clos, au forfait. Refus (points perdus
#     ou débités chez un autre client sinon) ; témoin : le source clos d'abord débite ses tâches, puis fusionne librement.
# =====================================================================================================================
C7, C7b = avec_points("principal-ko"), avec_points("autre-contrat")
cibles = {
    "non facturable": creer(api, "Ticket", {"name": "principal sans contrat", "content": "x", "entities_id": entite}),
    "autre contrat": ticket("principal d'un autre contrat", C7b),
    "clos": ticket("principal clos", C7),
    "au forfait": ticket("principal au forfait", C7),
    "contrat à choisir": ticket("principal à deux contrats", C7),
}
sql_ecrire(f"INSERT INTO glpi_tickets_contracts (tickets_id, contracts_id) VALUES ({cibles['contrat à choisir']}, {C7b});")
clore(cibles["clos"])
forfait(cibles["au forfait"], C7, 3)
for nom, cible in cibles.items():
    S7 = ticket(f"source vers principal {nom}", C7)
    tache(S7, 3600, "à débiter sur C7")
    code, messages = fusion(cible, S7)
    v(f"(7) principal {nom} : fusion refusée, source hors corbeille, rien copié", (code, corbeille(S7), nb_taches(cible)), (1, "0", 0))
    if nom == "clos":
        v("(7) le principal clos l'est resté (le refus porte bien sur un principal clos)", une(f"SELECT status FROM glpi_tickets WHERE id = {cible}"), "6")
    # principal à contrat à choisir : la copie de la tâche y est déjà refusée (ItilHooks::check), ce qui annule aussi la fusion
    attendu = "Several Points contracts are linked to this ticket" if nom == "contrat à choisir" else "would not debit them on the same contract"
    v(f"(7) principal {nom} : refus expliqué", any(attendu in m for m in messages), True)
clore(S7)
code, _ = fusion(cibles["non facturable"], S7)
v("(7) témoin : source clos d'abord (tâche débitée sur C7), la fusion passe", (code, debits_taches(C7), debits_taches(C7b)), (0, [(S7, "-1.00")], []))

# =====================================================================================================================
# (8) fusion en chaîne A → B → C d'une tâche débitée : la copie de la copie reste verrouillée, aucun second débit
# =====================================================================================================================
C8 = avec_points("chaine")
A8, B8, D8 = ticket("chaîne A", C8), ticket("chaîne B", C8), ticket("chaîne C", C8)
tache(A8, 3600, "débitée sur A")
clore(A8)
v("(8) témoin : tâche débitée sur A", debits_taches(C8), [(A8, "-1.00")])
v("(8) A → B : passe", fusion(B8, A8)[0], 0)
v("(8) B → C : passe", fusion(D8, B8)[0], 0)
clore(D8)
v("(8) C clos : la copie de la copie n'est pas débitée une seconde fois", (debits_taches(C8), solde(C8)), ([(A8, "-1.00")], "39.00"))
v("(8) invariants", ok(led("check", C8)), [])

# =====================================================================================================================
# (9) source fusionné puis restauré, mis à la corbeille hors fusion : comme tout ticket (aucun refus)
# =====================================================================================================================
C9 = avec_points("restaure")
A9, B9 = ticket("restauré source", C9), ticket("restauré principal", C9)
v("(9) fusion sans tâche : passe", fusion(B9, A9)[0], 0)
sql_ecrire(f"UPDATE glpi_tickets SET is_deleted = 0 WHERE id = {A9};")
tache(A9, 3600, "ajoutée après restauration")
try:
    api._req("DELETE", f"/Ticket/{A9}")
except ErreurApi:
    pass
v("(9) mise à la corbeille ordinaire du ticket restauré : acceptée", corbeille(A9), "1")

# =====================================================================================================================
# (10) source NON facturable (aucun contrat Points) fusionné avec ses tâches dans un principal facturable : les copies seraient débitées
#      sur le contrat du principal (un autre client, peut-être). Refus ; témoin : sans les tâches, la fusion passe.
# =====================================================================================================================
C10 = avec_points("principal-facturable")
S10 = creer(api, "Ticket", {"name": "source non facturable", "content": "x", "entities_id": entite})
M10 = ticket("principal facturable", C10)
tache(S10, 3600, "travail non facturable")
code, messages = fusion(M10, S10)
v("(10) fusion avec les tâches refusée : source hors corbeille, rien copié", (code, corbeille(S10), nb_taches(M10)), (1, "0", 0))
v("(10) refus expliqué", any("is not billable on Points" in m for m in messages), True)
v("(10) témoin : sans les tâches, la fusion passe", fusion(M10, S10, linktypes=[])[0], 0)
clore(M10)
v("(10) rien n'est débité sur le contrat du principal", debits_taches(C10), [])

# =====================================================================================================================
# (11) seconde fusion d'un source restauré : ses tâches, déjà recopiées sur un premier principal, le seraient sur un second (double débit).
#      Refus ; témoin : la seconde fusion sans les tâches passe, et la tâche est débitée une fois (premier principal).
# =====================================================================================================================
C11 = avec_points("seconde-fusion")
S11, T1, T2 = ticket("source fusionné deux fois", C11), ticket("premier principal", C11), ticket("second principal", C11)
tache(S11, 3600, "copiée deux fois ?")
v("(11) première fusion : passe", fusion(T1, S11)[0], 0)
sql_ecrire(f"UPDATE glpi_tickets SET is_deleted = 0 WHERE id = {S11};")
code, messages = fusion(T2, S11)
v("(11) seconde fusion avec les tâches refusée : rien copié sur le second principal", (code, corbeille(S11), nb_taches(T2)), (1, "0", 0))
v("(11) refus expliqué", any("already copied to another ticket" in m for m in messages), True)
v("(11) témoin : seconde fusion sans les tâches, passe", fusion(T2, S11, linktypes=[])[0], 0)
clore(T1)
clore(T2)
v("(11) la tâche est débitée une fois, sur le premier principal", debits_taches(C11), [(T1, "-1.00")])

# =====================================================================================================================
# (12) fusion, restauration, puis refusion dans le MÊME principal : la tâche serait copiée deux fois sur lui (double débit). Refus ;
#      témoin : la refusion sans les tâches passe, la tâche est débitée une fois.
# =====================================================================================================================
C12 = avec_points("refusion")
S12, P12 = ticket("source refusionné", C12), ticket("même principal", C12)
tache(S12, 3600, "copiée une fois seulement")
v("(12) première fusion : passe", fusion(P12, S12)[0], 0)
sql_ecrire(f"UPDATE glpi_tickets SET is_deleted = 0 WHERE id = {S12};")
code, messages = fusion(P12, S12)
v("(12) refusion avec les tâches dans le même principal : refusée, une seule copie", (code, corbeille(S12), nb_taches(P12)), (1, "0", 1))
v("(12) refus expliqué", any("already copied to another ticket" in m for m in messages), True)
v("(12) témoin : refusion sans les tâches, passe", fusion(P12, S12, linktypes=[])[0], 0)
clore(P12)
v("(12) la tâche est débitée une fois", debits_taches(C12), [(P12, "-1.00")])

for c in (C1, C2, C3, C6, C7, C7b, C8, C9, C10, C11, C12):
    v(f"invariants du contrat {c}", ok(led("check", c)), [])
fautes = [l for nom in DEPART for l in lecture(nom).splitlines()[DEPART[nom]:] if re.search("points", l, re.I)]
v("aucune erreur du plugin dans les journaux PHP et SQL", fautes, [])

print("banc : fusion " + ("vert" if v.ok else "ROUGE"))
sys.exit(0 if v.ok else 1)
