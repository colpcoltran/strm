#!/bin/bash
# Leták A4 (tisk): QR kód → PDF ve třech variantách → tiskové rámečky a kontroly.
# Spouštět z kořene repozitáře:  bash print/letak/build.sh
# Potřeba: python3 + segno, pypdf, opencv-python-headless; poppler-utils;
# Node + Playwright (Chromium); systémový font Inter (jinak Liberation Sans/Arial).
set -euo pipefail
cd "$(dirname "$0")"
python3 make-qr.py
node build-letak.cjs
python3 finish.py
