#!/usr/bin/env bash
# Contrôles du plugin Points : syntaxe PHP, tests unitaires, .mo à jour ; --banc ajoute le banc GLPI 12 (Docker).
#
#   scripts/check.sh [--banc]
#
# Code de sortie 0 seulement si TOUTES les sections demandées sont vertes ; une section non lancée est dite SAUTÉE
# (jamais comptée comme verte).
set -uo pipefail
cd "$(dirname "$0")/.."
banc=0
for a in "$@"; do
  case "$a" in
    --banc) banc=1 ;;
    *) echo "usage : scripts/check.sh [--banc]" >&2; exit 2 ;;
  esac
done
echec=0
section() { echo; echo "=== $1"; }
ko() { echo "ECHEC : $1" >&2; echec=1; }

section "php -l (tous les .php)"
n=0
while IFS= read -r f; do
  n=$((n + 1))
  out=$(php -l "$f" 2>&1) || { echo "$out" >&2; ko "syntaxe $f"; }
done < <(find . -name '*.php' -not -path './.git/*' -not -path './vendor/*' | sort)
[ "$n" -gt 0 ] && echo "$n fichier(s) vérifié(s)" || ko "aucun .php trouvé"

section "tests unitaires (tests/unit/run.php)"
php tests/unit/run.php | tail -40
[ "${PIPESTATUS[0]}" = 0 ] || ko "tests unitaires"

section "traductions : .mo à jour par rapport aux .po"
python3 tools/compile_mo.py --check || ko ".mo différents des .po (python3 tools/compile_mo.py)"

if [ "$banc" = 1 ]; then
  section "banc GLPI 12 (tests/banc/run.sh)"
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    tests/banc/run.sh || ko "banc"
  else
    ko "banc : Docker indisponible"
  fi
else
  section "banc GLPI 12"
  echo "SAUTÉE (ajouter --banc) : une section sautée n'est pas verte"
fi

echo
if [ "$echec" = 0 ]; then
  [ "$banc" = 1 ] && echo "check : tout est vert (banc compris)" || echo "check : vert, SAUF le banc (sautée)"
else
  echo "check : ECHEC" >&2
fi
exit "$echec"
