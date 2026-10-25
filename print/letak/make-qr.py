#!/usr/bin/env python3
"""QR kód pro leták: vektorové SVG (ostré v jakékoli velikosti tisku).

Obsah: https://www.technickabezpecnost.cz/ (kanonická adresa webu),
korekce chyb Q (25 %), tichá zóna 4 moduly (minimum dle ISO/IEC 18004),
černé moduly na bílém podkladu (nejvyšší kontrast, totožné se samostatným
kódem print/qr/qr-technickabezpecnost-cerny.*).
Použití:  python3 -m pip install segno && python3 print/letak/make-qr.py
"""
from pathlib import Path

import segno

URL = 'https://www.technickabezpecnost.cz/'
OUT = Path(__file__).with_name('qr-web.svg')

qr = segno.make(URL, error='q', micro=False, boost_error=False)
qr.save(OUT, kind='svg', dark='#000000', light='#ffffff', border=4,
        xmldecl=False, svgns=True, omitsize=True, nl=False)
print(f'{OUT.name}: verze {qr.version}, korekce {qr.error}, {qr.symbol_size(border=4)[0]}×{qr.symbol_size(border=4)[1]} modulů vč. tiché zóny')
