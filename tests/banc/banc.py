"""Module commun des scénarios du banc jetable (tests/banc/run.sh) : API historique v1, navigation web minimale,
SQL en lecture et console GLPI. Aucune dépendance hors bibliothèque standard.

Le SQL passe toujours par l'entrée standard de `docker exec -i points-banc-db mariadb` (jamais `-e` après `sh -c`)."""
import base64
import http.cookiejar
import json
import os
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

URL = os.environ.get("BANC_URL", "http://127.0.0.1:8097")
APP = "points-banc-app"
DB = "points-banc-db"
PLUGIN = "points"


class ErreurApi(RuntimeError):
    pass


class Api:
    """Client minimal de l'API historique (v1), session ouverte avec glpi/glpi."""

    def __init__(self, login="glpi", mdp="glpi"):
        auth = base64.b64encode(f"{login}:{mdp}".encode()).decode()
        self.tok = None
        self.tok = self._req("GET", "/initSession", head={"Authorization": f"Basic {auth}"})["session_token"]

    def _req(self, meth, path, body=None, head=None):
        h = {"Content-Type": "application/json", "X-Forwarded-Proto": "https"}
        if self.tok:
            h["Session-Token"] = self.tok
        h.update(head or {})
        data = json.dumps(body).encode() if body is not None else None
        r = urllib.request.Request(URL + "/apirest.php" + path, data=data, method=meth, headers=h)
        try:
            with urllib.request.urlopen(r, timeout=60) as resp:
                txt = resp.read().decode()
        except urllib.error.HTTPError as e:
            raise ErreurApi(f"{meth} {path} : {e.code} {e.read().decode()[:300]}") from None
        return json.loads(txt) if txt else None

    def liste(self, itemtype, plage="0-999"):
        return self._req("GET", f"/{itemtype}?range={plage}") or []

    def get(self, itemtype, id_):
        return self._req("GET", f"/{itemtype}/{id_}")

    def tue(self):
        self._req("GET", "/killSession")
        self.tok = None


def sql(requete):
    """Requête en lecture sur la base du banc : lignes de champs (tabulation), sans en-têtes."""
    r = subprocess.run(["docker", "exec", "-i", DB, "mariadb", "-uroot", "-pbanc-root", "glpi", "-N"],
                       input=requete.encode(), capture_output=True, check=True)
    return [l.split("\t") for l in r.stdout.decode().strip().splitlines()]


def sql_ecrire(requete):
    """Écriture sur la base du banc (simule une modification faite par un administrateur)."""
    subprocess.run(["docker", "exec", "-i", DB, "mariadb", "-uroot", "-pbanc-root", "glpi"],
                   input=requete.encode(), capture_output=True, check=True)


def console(*args, user="www-data"):
    """`php bin/console …` dans le conteneur GLPI ; renvoie le processus terminé (code, sortie)."""
    return subprocess.run(["docker", "exec", "-u", user, APP, "php", "bin/console", *args],
                          capture_output=True, text=True)


def shell_app(script):
    """Script sh passé par l'entrée standard dans le conteneur GLPI (www-data)."""
    return subprocess.run(["docker", "exec", "-i", "-u", "www-data", APP, "sh"], input=script, capture_output=True, text=True)


class Verif:
    def __init__(self):
        self.ok = True

    def __call__(self, nom, obtenu, attendu):
        bon = obtenu == attendu
        self.ok &= bon
        print(f"OK  {nom}" if bon else f"KO  {nom} : obtenu {obtenu!r}, attendu {attendu!r}")

    def vrai(self, nom, condition, detail=""):
        self.ok &= bool(condition)
        print(f"{'OK' if condition else 'KO'}  {nom}{' : ' + str(detail) if detail and not condition else ''}")


class _Formulaires(HTMLParser):
    """Relève les formulaires et ce qu'un navigateur posterait (champs cachés, texte, cases cochées, sélections)."""

    def __init__(self):
        super().__init__()
        self.formulaires, self._f, self._select = [], None, None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            self._f = {"action": a.get("action", ""), "champs": []}
            self.formulaires.append(self._f)
        elif self._f is None:
            return
        elif tag == "input" and a.get("name"):
            t = a.get("type", "text")
            if t in ("checkbox", "radio"):
                if "checked" in a:
                    self._f["champs"].append((a["name"], a.get("value", "on")))
            elif t not in ("submit", "button", "file"):
                self._f["champs"].append((a["name"], a.get("value", "")))
        elif tag == "select" and a.get("name"):
            self._select = a["name"]
        elif tag == "option" and self._select and "selected" in a:
            self._f["champs"].append((self._select, a.get("value", "")))

    def handle_endtag(self, tag):
        if tag == "select":
            self._select = None
        elif tag == "form":
            self._f = None


def formulaires(html):
    p = _Formulaires()
    p.feed(html)
    return p.formulaires


class _SansRedirection(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Web:
    """Navigation web sans JavaScript : connexion, GET, POST (avec en-têtes de navigateur au choix)."""

    def __init__(self, login="glpi", mdp="glpi"):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), _SansRedirection())
        self.op.addheaders = [("X-Forwarded-Proto", "https")]
        f = next(f for f in formulaires(self.get("/index.php?noAUTO=1")[1]) if "login" in f["action"])
        champs = dict(f["champs"])
        champs["login_name"], champs["login_password"] = login, mdp
        self.post(f["action"], list(champs.items()))

    def get(self, chemin):
        """(code HTTP, corps)."""
        try:
            with self.op.open(urllib.request.Request(URL + chemin), timeout=60) as resp:
                return resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def post(self, chemin, champs, entetes=None):
        """(code HTTP, corps) ; une redirection 302 n'est pas suivie."""
        req = urllib.request.Request(URL + chemin if not chemin.startswith("http") else chemin,
                                     data=urllib.parse.urlencode(champs).encode(), headers=entetes or {})
        try:
            with self.op.open(req, timeout=60) as resp:
                return resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()
