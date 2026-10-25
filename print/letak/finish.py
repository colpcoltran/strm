#!/usr/bin/env python3
"""Dokončení tiskových PDF a jejich kontrola.

1. Přesný rozměr a tiskové rámečky: Chromium zaokrouhluje velikost stránky,
   proto se leták vykresluje o 1 mm větší (pozadí přetažené vpravo a dole)
   a tady se MediaBox nastaví přesně, ukotvený v levém horním rohu, kde je
   rozvržení v milimetrech přesné. TrimBox = čistý formát 210 × 297 mm,
   BleedBox = A4 + spadávka 3 mm – tiskárna/RIP přesně ví, kde řezat.
2. Kontroly: rozměry, vložené fonty bez Type 3 (pdffonts), čitelnost QR
   kódu (pdftoppm → OpenCV) při 150 i 100 dpi, pozadí až do rohů (žádný
   bílý proužek ve spadávce), ořezové značky přesně na TrimBoxu.
3. Náhled PNG.
Vyžaduje: pypdf, opencv-python-headless, numpy, poppler-utils.
"""
import subprocess
import sys
import tempfile
from pathlib import Path

import cv2
import numpy as np
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject

DIR = Path(__file__).resolve().parent
OUT = DIR / 'out'
URL = 'https://www.technickabezpecnost.cz/'
MM = 72 / 25.4
TOP_BG, BOTTOM_BG = '#fbf9f3', '#2c0727'   # horní pás a patička (letak.html)
# soubor: (spadávka mm, okraj pro značky mm)
VARIANTS = {
    'letak-A4-spad-3mm.pdf': (3, 0),
    'letak-A4-spad-3mm-znacky.pdf': (3, 5),
    'letak-A4-bez-spadu.pdf': (0, 0),
}
failed = False


def fail(msg):
    global failed
    failed = True
    print('  CHYBA: ' + msg)


def render(pdf, dpi, base):
    subprocess.run(['pdftoppm', '-q', '-r', str(dpi), '-png', '-singlefile', str(pdf), str(base)], check=True)
    return Path(str(base) + '.png')


def decode_qr(png):
    img = cv2.imread(str(png))
    text, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
    if not text:
        try:
            text, _, _ = cv2.QRCodeDetectorAruco().detectAndDecode(img)
        except Exception:
            text = ''
    return text


for name, (bleed, slug) in VARIANTS.items():
    path = OUT / name
    print(f'\n{name}')
    reader = PdfReader(str(path))
    if len(reader.pages) != 1:
        fail(f'počet stran {len(reader.pages)} (má být 1)')
    writer = PdfWriter(clone_from=reader)
    page = writer.pages[0]
    w_act, h_act = float(page.mediabox.width), float(page.mediabox.height)
    W = (210 + 2 * (bleed + slug)) * MM
    H = (297 + 2 * (bleed + slug)) * MM
    if w_act < W - 0.01 or h_act < H - 0.01:
        fail(f'vykreslená stránka {w_act / MM:.2f} × {h_act / MM:.2f} mm je menší než cíl – chybí přetažení')
    # horní hrana v souřadnicích PDF – z MediaBoxu, ne z výšky, aby byl skript
    # idempotentní (po prvním běhu už MediaBox nezačíná v nule)
    top = float(page.mediabox.top)
    o = (slug + bleed) * MM          # ořezová čára od levého/horního okraje
    s = slug * MM
    left = float(page.mediabox.left)
    if abs(left) > 0.01:
        fail(f'MediaBox nezačíná vlevo v nule ({left:.2f} pt)')
    media = [0, top - H, W, top]
    page.mediabox = RectangleObject(media)
    page.cropbox = RectangleObject(media)
    page.bleedbox = RectangleObject([s, top - s - (297 + 2 * bleed) * MM, s + (210 + 2 * bleed) * MM, top - s])
    page.trimbox = RectangleObject([o, top - o - 297 * MM, o + 210 * MM, top - o])
    page.artbox = RectangleObject([o, top - o - 297 * MM, o + 210 * MM, top - o])
    writer.add_metadata({'/Title': 'Technická bezpečnost – leták A4', '/Author': 'Martin Šturma',
                         '/Subject': f'Čistý formát 210 × 297 mm, spadávka {bleed} mm'})
    with open(path, 'wb') as f:
        writer.write(f)

    info = subprocess.run(['pdfinfo', '-box', str(path)], capture_output=True, text=True).stdout
    def box(label):
        line = next(l for l in info.splitlines() if l.startswith(label))
        x0, y0, x1, y1 = map(float, line.split()[1:])
        return (x1 - x0) / MM, (y1 - y0) / MM
    mw, mh = box('MediaBox:')
    tw, th = box('TrimBox:')
    bw, bh = box('BleedBox:')
    print(f'  stránka {mw:.2f} × {mh:.2f} mm · BleedBox {bw:.2f} × {bh:.2f} mm · TrimBox {tw:.2f} × {th:.2f} mm')
    if abs(tw - 210) > 0.02 or abs(th - 297) > 0.02 or abs(mw - W / MM) > 0.02 or abs(mh - H / MM) > 0.02:
        fail('rozměry rámečků nesedí')

    # fonty: vložené, žádné Type 3
    fonts = subprocess.run(['pdffonts', str(path)], capture_output=True, text=True).stdout.splitlines()[2:]
    rows = [l.split() for l in fonts]
    bad = [r[0] for r in rows if r[-5] != 'yes']
    type3 = sorted({r[0].split('+')[-1] for r in rows if 'Type' in r and r[r.index('Type') + 1] == '3'})
    names = sorted({r[0].split('+')[-1] for r in rows})
    if not rows or bad:
        fail('nevložené fonty: ' + ', '.join(bad))
    if type3:
        fail('fonty Type 3: ' + ', '.join(type3))
    print(f'  fonty vložené, bez Type 3: {", ".join(names)}')

    with tempfile.TemporaryDirectory() as tmp:
        # QR kód
        for dpi in (150, 100):
            got = decode_qr(render(path, dpi, Path(tmp) / f'q{dpi}'))
            if got != URL:
                fail(f'QR při {dpi} dpi nepřečten správně: {got!r}')
        print(f'  QR přečten při 150 i 100 dpi → {URL}')

        dpi = 300 if slug == 0 else 600   # značky měřit jemněji (1 px = 0,042 mm)
        img = cv2.imread(str(render(path, dpi, Path(tmp) / 'p')))
        hpx, wpx = img.shape[:2]
        px = dpi / 25.4
        if slug == 0:
            # pozadí do všech rohů: nahoře lomená bílá horního pásu, dole patička
            # (čistě bílý roh = papír, chybí pozadí ve spadávce; bílá se od
            # #fbf9f3 liší o 12 v modrém kanálu, tolerance je 5)
            corners = {'vlevo nahoře': (img[1, 1], TOP_BG), 'vpravo nahoře': (img[1, wpx - 2], TOP_BG),
                       'vlevo dole': (img[hpx - 2, 1], BOTTOM_BG), 'vpravo dole': (img[hpx - 2, wpx - 2], BOTTOM_BG)}
            wrong = []
            for k, (bgr, want) in corners.items():
                rgb = [int(x) for x in bgr[::-1]]
                exp = [int(want[i:i + 2], 16) for i in (1, 3, 5)]
                if max(abs(g - e) for g, e in zip(rgb, exp)) > 5:
                    wrong.append(f'{k} #{"".join(f"{x:02x}" for x in rgb)} místo {want}')
            if wrong:
                fail('roh bez pozadí ve spadávce: ' + ', '.join(wrong))
            else:
                print('  pozadí sahá do všech čtyř rohů')
        else:
            # ořezové značky: tmavé čáry v okraji musí ležet přesně na TrimBoxu
            gray = img.mean(axis=2)
            def line_pos(strip, axis):
                dark = (strip < 128).sum(axis=axis)
                idx = np.flatnonzero(dark > dark.max() * 0.5)
                return idx.mean() if idx.size else None
            sl = int(slug * px * 0.8)          # pás okraje se značkami (bez spadávky)
            hh, hw = hpx // 2, wpx // 2

            def shifted(pos, by):
                return None if pos is None else pos + by

            # každá značka se hledá jen ve své polovině stránky
            checks = {
                'vlevo nahoře – vodorovná': (line_pos(gray[:hh, :sl], 1), o / MM * px),
                'vlevo nahoře – svislá': (line_pos(gray[:sl, :hw], 0), o / MM * px),
                'vpravo dole – vodorovná': (shifted(line_pos(gray[hh:, wpx - sl:], 1), hh), (H - o) / MM * px),
                'vpravo dole – svislá': (shifted(line_pos(gray[hpx - sl:, hw:], 0), hw), (W - o) / MM * px),
            }
            worst = 0.0
            for label, (got, exp) in checks.items():
                if got is None:
                    fail(f'značka {label} nenalezena')
                    continue
                dev = (got + 0.5 - exp) / px     # střed pixelu
                worst = max(worst, abs(dev))
                if abs(dev) > 0.05:
                    fail(f'značka {label} mimo TrimBox o {dev:+.3f} mm')
            print(f'  ořezové značky na TrimBoxu, největší odchylka {worst:.3f} mm')

render(OUT / 'letak-A4-bez-spadu.pdf', 110, OUT / 'nahled')
print('\nnáhled → out/nahled.png')
sys.exit(1 if failed else 0)
