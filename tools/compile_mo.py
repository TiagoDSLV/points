#!/usr/bin/env python3
"""Compile locales/*.po en locales/*.mo, sans dépendance (pas de msgfmt sur la machine).

    python3 tools/compile_mo.py            # écrit les .mo
    python3 tools/compile_mo.py --check    # code 1 si un .mo manque ou diffère de ce que donnerait son .po

Gère : msgid / msgstr sur plusieurs lignes, séquences d'échappement usuelles, msgctxt, pluriels (msgid_plural,
msgstr[n]), entrées « fuzzy » ignorées, entrée d'en-tête (msgid vide). Sortie déterministe (entrées triées).
"""
import re
import struct
import sys
from pathlib import Path

LOCALES = Path(__file__).resolve().parent.parent / "locales"
_ESC = {"n": "\n", "t": "\t", "r": "\r", '"': '"', "\\": "\\"}


def _unquote(texte):
    m = re.fullmatch(r'"(.*)"', texte.strip())
    if m is None:
        raise ValueError(f"chaîne mal formée : {texte!r}")
    return re.sub(r"\\(.)", lambda e: _ESC.get(e.group(1), "\\" + e.group(1)), m.group(1))


def parse_po(texte):
    """Renvoie {clé: valeur} (clé = 'ctxt\\x04msgid[\\x00plural]', valeur = 'str0[\\x00str1…]')."""
    entrees, courante, champ, fuzzy = {}, {}, None, False

    def clore():
        nonlocal courante, fuzzy
        if "msgid" in courante and not fuzzy:
            cle = courante["msgid"]
            if "msgctxt" in courante:
                cle = courante["msgctxt"] + "\x04" + cle
            if "msgid_plural" in courante:
                cle += "\x00" + courante["msgid_plural"]
            if "msgstr" in courante:
                valeur = courante["msgstr"]
            else:
                valeur = "\x00".join(courante[k] for k in sorted(c for c in courante if c.startswith("msgstr[")))
            if valeur.replace("\x00", "") != "" or cle == "":
                entrees[cle] = valeur
        courante, fuzzy = {}, False

    for ligne in texte.splitlines():
        ligne = ligne.strip()
        if not ligne:
            clore()
            champ = None
        elif ligne.startswith("#,"):
            fuzzy = fuzzy or "fuzzy" in ligne
        elif ligne.startswith("#"):
            continue
        else:
            m = re.match(r"(msgctxt|msgid_plural|msgid|msgstr\[\d+\]|msgstr)\s+(.*)", ligne)
            if m:
                champ = m.group(1)
                if champ == "msgid" and "msgid" in courante:
                    clore()
                courante[champ] = _unquote(m.group(2))
            elif ligne.startswith('"') and champ:
                courante[champ] += _unquote(ligne)
            else:
                raise ValueError(f"ligne inattendue : {ligne!r}")
    clore()
    return entrees


def build_mo(entrees):
    cles = sorted(entrees)
    ids = b""
    strs = b""
    offsets = []
    for cle in cles:
        i, s = cle.encode("utf-8"), entrees[cle].encode("utf-8")
        offsets.append((len(ids), len(i), len(strs), len(s)))
        ids += i + b"\0"
        strs += s + b"\0"
    n = len(cles)
    debut_ids = 7 * 4 + 16 * n
    debut_strs = debut_ids + len(ids)
    table_ids, table_strs = b"", b""
    for o_id, l_id, o_str, l_str in offsets:
        table_ids += struct.pack("<II", l_id, debut_ids + o_id)
        table_strs += struct.pack("<II", l_str, debut_strs + o_str)
    entete = struct.pack("<Iiiiiii", 0x950412DE, 0, n, 7 * 4, 7 * 4 + 8 * n, 0, 0)
    return entete + table_ids + table_strs + ids + strs


def main(argv):
    verifier = "--check" in argv
    pos = sorted(LOCALES.glob("*.po"))
    if not pos:
        print("aucun .po dans locales/", file=sys.stderr)
        return 1
    ko = 0
    for po in pos:
        mo = po.with_suffix(".mo")
        attendu = build_mo(parse_po(po.read_text(encoding="utf-8")))
        if verifier:
            if not mo.exists() or mo.read_bytes() != attendu:
                print(f"KO  {mo.name} absent ou différent de {po.name} : python3 tools/compile_mo.py", file=sys.stderr)
                ko = 1
            else:
                print(f"OK  {mo.name} à jour")
        else:
            mo.write_bytes(attendu)
            print(f"écrit {mo.name} ({len(parse_po(po.read_text(encoding='utf-8')))} entrées)")
    return ko


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
