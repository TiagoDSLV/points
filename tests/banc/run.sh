#!/usr/bin/env bash
# Banc jetable : GLPI 12.0.1 et MariaDB aux images figées par digest, le plugin monté en lecture seule ; scénarios
# tests/banc/scenario_*.py ; tout est supprimé à la fin (BANC_GARDER=1 : banc gardé pour enquête).
# Section `--banc` de scripts/check.sh.
#
#   tests/banc/run.sh [scenario_socle …]
set -euo pipefail
cd "$(dirname "$0")"
PLUGIN_DIR=$(cd ../.. && pwd)
IMG_GLPI=glpi/glpi:12.0.1@sha256:02b4e8146b9b71d833443718d093352609dafec1b32a1d218b5961d6492e7b06
IMG_DB=mariadb:11.8.8@sha256:24e76fcec8c003a0362d0dd53f4806e7e79458d7fdeaf47437760e19496f5a9c
export BANC_URL=http://127.0.0.1:${BANC_PORT:-8097}
scenarios=("$@")
[ ${#scenarios[@]} -eq 0 ] && scenarios=($(ls scenario_*.py | sed 's/\.py$//'))

# Seuls les conteneurs du banc sont touchés (un conteneur de dev peut tourner à côté sur un autre port)
menage() { docker rm -fv points-banc-app points-banc-db >/dev/null 2>&1 || true
           docker network rm points-banc >/dev/null 2>&1 || true; }
[ "${BANC_GARDER:-0}" = 1 ] || trap menage EXIT
menage
docker network create points-banc >/dev/null
docker run -d --name points-banc-db --network points-banc -e MARIADB_DATABASE=glpi -e MARIADB_USER=glpi \
  -e MARIADB_PASSWORD=banc -e MARIADB_ROOT_PASSWORD=banc-root -e TZ=Europe/Paris "$IMG_DB" >/dev/null
base=0
for i in $(seq 60); do docker exec points-banc-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && { base=1; break; }; sleep 2; done
[ "$base" = 1 ] || { echo "banc : MariaDB ne démarre pas" >&2; docker logs points-banc-db 2>&1 | tail -20 >&2; exit 1; }
docker run -d --name points-banc-app --network points-banc -p "127.0.0.1:${BANC_PORT:-8097}:80" -v "$PLUGIN_DIR:/var/www/glpi/plugins/points:ro" \
  -e GLPI_DB_HOST=points-banc-db -e GLPI_DB_PORT=3306 -e GLPI_DB_NAME=glpi -e GLPI_DB_USER=glpi -e GLPI_DB_PASSWORD=banc \
  -e TZ=Europe/Paris "$IMG_GLPI" >/dev/null
echo "banc : installation de GLPI (une à deux minutes)"
# Apache ne démarre qu'après l'installation (entrypoint : database:install, puis supervisord) : attendre l'HTTP d'abord.
# Un `docker exec bin/console` lancé pendant l'installation est un second processus console qui crée les mêmes dossiers
# (`files/_themes`) : le perdant de la course lève `mkdir(): File exists`, et si c'est l'installation, le conteneur meurt.
pret=0
for i in $(seq 120); do
  [ "$(docker inspect -f '{{.State.Running}}' points-banc-app 2>/dev/null)" = true ] || break
  curl -fsS -o /dev/null -H 'X-Forwarded-Proto: https' "$BANC_URL/" >/dev/null 2>&1 && { pret=1; break; }
  sleep 3
done
[ "$pret" = 1 ] || { echo "banc : GLPI ne démarre pas" >&2; docker logs points-banc-app 2>&1 | tail -20 >&2; exit 1; }
# API activée, une seule fois, installation finie : un échec est fatal
for reglage in enable_api enable_api_login_credentials; do
  docker exec -u www-data points-banc-app php bin/console config:set "$reglage" 1 >/dev/null \
    || { echo "banc : config:set $reglage a échoué" >&2; exit 1; }
done
# Client d'API « full access from localhost » ouvert à toute adresse : la requête vient de l'hôte, par le pont Docker
printf '%s\n' "UPDATE glpi_apiclients SET ipv4_range_start = NULL, ipv4_range_end = NULL, ipv6 = NULL;" \
  | docker exec -i points-banc-db mariadb -uroot -pbanc-root glpi \
  || { echo "banc : ouverture du client d'API a échoué" >&2; exit 1; }
# Fuseaux horaires activés (database:enable_timezones) : un utilisateur peut avoir un fuseau différent de celui du serveur.
# GLPI exige la lecture de mysql.time_zone_name pour son compte de base de données ; le banc l'accorde par root.
printf '%s\n' "GRANT SELECT ON mysql.time_zone_name TO 'glpi'@'%'; FLUSH PRIVILEGES;" \
  | docker exec -i points-banc-db mariadb -uroot -pbanc-root \
  || { echo "banc : GRANT sur mysql.time_zone_name a échoué" >&2; exit 1; }
docker exec -u www-data points-banc-app php bin/console database:enable_timezones \
  || { echo "banc : database:enable_timezones a échoué" >&2; exit 1; }
# Plugin : installation par la console, puis activation (à relancer après --force). Les scénarios partent d'un plugin actif.
docker exec -u www-data points-banc-app php bin/console plugin:install -u glpi points \
  || { echo "banc : plugin:install a échoué" >&2; exit 1; }
docker exec -u www-data points-banc-app php bin/console plugin:activate points \
  || { echo "banc : plugin:activate a échoué" >&2; exit 1; }
ok=0
for s in "${scenarios[@]}"; do
  echo "--- $s"
  python3 "$s.py" || ok=1
done
[ "$ok" = 0 ] && echo "banc : tous les scénarios sont verts" || echo "banc : échec" >&2
exit $ok
