#!/usr/bin/env python3
"""Samostatný QR kód na web www.technickabezpecnost.cz – SVG, PDF a PNG.

Parametry shodné s QR kódem na letáku (print/letak/make-qr.py): kanonická
adresa, korekce chyb Q (25 %), tichá zóna 4 moduly. Dvě barvy: navy webu
a černá. Po vygenerování každý soubor vykreslí a dekóduje (OpenCV), takže
skript skončí chybou, pokud by kterýkoli kód nešel přečíst.

Použití:  python3 -m pip install segno opencv-python-headless
          python3 print/qr/make-qr.py
Potřeba i poppler-utils (pdftoppm, pdfinfo) a Node + Playwright (SVG test).
"""
import subprocess
import sys
import tempfile
from pathlib import Path

import cv2
import segno
from PIL import Image

URL = 'https://www.technickabezpecnost.cz/'
DIR = Path(__file__).resolve().parent
BORDER = 4
PDF_MM = 40                        # rozměr PDF vč. tiché zóny
COLORS = {'': '#0c2743', '-cerny': '#000000'}
MM_PT = 72 / 25.4

qr = segno.make(URL, error='q', micro=False, boost_error=False)
n = qr.symbol_size(border=BORDER)[0]           # moduly vč. tiché zóny
print(f'QR verze {qr.version}, korekce {qr.error}, {n} × {n} modulů vč. tiché zóny, maska {qr.mask}')

files = []
for suffix, dark in COLORS.items():
    base = DIR / f'qr-technickabezpecnost{suffix}'
    common = dict(dark=dark, light='#ffffff', border=BORDER)
    svg = base.with_suffix('.svg')
    qr.save(svg, kind='svg', scale=PDF_MM / n, unit='mm', xmldecl=False, svgns=True, nl=False,
            title='QR kód: ' + URL, **common)
    pdf = base.with_suffix('.pdf')
    qr.save(pdf, kind='pdf', scale=PDF_MM * MM_PT / n, **common)
    tisk = Path(f'{base}-tisk.png')
    qr.save(tisk, kind='png', scale=30, dpi=600, **common)
    web = Path(f'{base}-web.png')
    qr.save(web, kind='png', scale=10, **common)
    files += [svg, pdf, tisk, web]


def decode(png):
    img = cv2.imread(str(png))
    text, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
    if not text:
        text, _, _ = cv2.QRCodeDetectorAruco().detectAndDecode(img)
    return text


def svg_to_png(svg, png):
    """SVG vykreslí Chromium (stejný engine jako prohlížeče) na 400 × 400 px.
    Obal je soubor vedle PNG – stránka z setContent (about:blank) by obrázek
    ze souboru nenačetla."""
    wrapper = png.with_suffix('.html')
    wrapper.write_text(f'<body style="margin:20px;background:#fff"><img id="q" src="file://{svg}" '
                       'style="width:400px;height:400px;display:block"></body>', encoding='utf-8')
    js = f"""
    const {{ chromium }} = require('/opt/node22/lib/node_modules/playwright');
    (async () => {{
      const b = await chromium.launch(); const p = await b.newPage({{ viewport: {{ width: 440, height: 440 }} }});
      await p.goto('file://{wrapper}');
      const ok = await p.evaluate(() => {{ const i = document.getElementById('q'); return i.complete && i.naturalWidth > 0; }});
      if (!ok) throw new Error('SVG se nenačetlo');
      await p.screenshot({{ path: '{png}' }}); await b.close();
    }})().catch((e) => {{ console.error(e.message); process.exit(1); }});
    """
    subprocess.run(['node', '-e', js], check=True)


failed = False
with tempfile.TemporaryDirectory() as tmp:
    for f in files:
        if f.suffix == '.png':
            im = Image.open(f)
            dpi = im.info.get('dpi')
            size = f'{im.width} × {im.height} px' + (f', {round(dpi[0])} dpi ≈ {im.width / dpi[0] * 25.4:.0f} mm' if dpi else '')
            got = decode(f)
        elif f.suffix == '.pdf':
            info = subprocess.run(['pdfinfo', str(f)], capture_output=True, text=True).stdout
            w, h = (float(x) for x in next(l for l in info.splitlines() if l.startswith('Page size')).split()[2:5:2])
            size = f'{w / MM_PT:.1f} × {h / MM_PT:.1f} mm (vektor)'
            if abs(w / MM_PT - PDF_MM) > 0.1 or abs(h / MM_PT - PDF_MM) > 0.1:
                print(f'CHYBA: {f.name} má rozměr {size}')
                failed = True
            out = Path(tmp) / f.stem
            subprocess.run(['pdftoppm', '-q', '-r', '150', '-png', '-singlefile', str(f), str(out)], check=True)
            got = decode(out.with_suffix('.png'))
        else:
            size = f'{PDF_MM} × {PDF_MM} mm výchozí, škálovatelné (vektor)'
            out = Path(tmp) / (f.stem + '.png')
            svg_to_png(f, out)
            got = decode(out)
        ok = got == URL
        failed |= not ok
        print(f'{"OK  " if ok else "CHYBA"} {f.name:42} {size:38} → {got or "nepřečteno"}')

sys.exit(1 if failed else 0)
