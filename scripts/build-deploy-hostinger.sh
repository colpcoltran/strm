#!/bin/bash
# Přegeneruje nasazovací větev deploy-hostinger z aktuální vývojové větve.
# Rozložení: obsah public/ v kořeni (= public_html) + app/ a data/ uvnitř
# (nouzový režim dle README), databáze s pevným náhodným názvem souboru.
# Spouštět z kořene repozitáře na vývojové větvi s čistým pracovním stromem.
set -euo pipefail

DB_SUFFIX="d1b24c430ca4"   # NEMĚNIT: název souboru živé databáze
SRC_BRANCH=$(git rev-parse --abbrev-ref HEAD)
SRC_SHA=$(git rev-parse --short HEAD)
STAGE=$(mktemp -d)

cp -r public/. "$STAGE/"
cp -r app "$STAGE/app"
mkdir -p "$STAGE/data"
cp data/.htaccess "$STAGE/data/.htaccess"

sed -i "s|dirname(__DIR__) . '/data/responses.sqlite'|dirname(__DIR__) . '/data/responses-$DB_SUFFIX.sqlite'|" \
  "$STAGE/app/config.php"

python3 - "$STAGE/.htaccess" <<'EOF'
import io, sys
p = sys.argv[1]
s = io.open(p, encoding='utf-8').read()
old = "# Bezpečnostní hlavičky\n<IfModule mod_headers.c>"
new = ("# Bezpečnostní hlavičky\n<IfModule mod_headers.c>\n"
       "# !!! TESTOVACÍ FÁZE: web se nesmí indexovat. PŘED OSTRÝM SPUŠTĚNÍM\n"
       "# !!! TENTO ŘÁDEK SMAZAT (a smazat i tento komentář).\n"
       "Header always set X-Robots-Tag \"noindex, nofollow\"")
assert old in s
io.open(p, 'w', encoding='utf-8').write(s.replace(new, old).replace(old, new))
EOF

cat > "$STAGE/.gitignore" <<'EOF'
data/*.sqlite
data/*.sqlite-shm
data/*.sqlite-wal
data/*.log
EOF

cat > "$STAGE/NASAZENI.md" <<EOF
# Větev deploy-hostinger

Automaticky generovaná nasazovací větev pro Hostinger (obsah = document
root public_html). Zdrojová pravda je větev \`$SRC_BRANCH\` – TUTO větev
needitujte ručně, přegenerovává se skriptem
\`scripts/build-deploy-hostinger.sh\`.

PŘED OSTRÝM SPUŠTĚNÍM: v .htaccess smazat hlavičku X-Robots-Tag
(noindex) označenou vykřičníky.
EOF

git branch -D _deploy_tmp 2>/dev/null || true
git checkout --orphan _deploy_tmp
git rm -rf --cached . -q
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -r "$STAGE/." .
git add -A
git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit -q -m "Nasazovací větev pro Hostinger public_html (generováno z $SRC_BRANCH @ $SRC_SHA)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BPrFrtnSRncm2wvtuP24Hp"
git branch -M _deploy_tmp deploy-hostinger
git push --force-with-lease=deploy-hostinger origin deploy-hostinger || git push -f origin deploy-hostinger
git checkout "$SRC_BRANCH" -q
rm -rf "$STAGE"
echo "deploy-hostinger přegenerována z $SRC_BRANCH @ $SRC_SHA"
