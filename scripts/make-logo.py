#!/usr/bin/env python3
"""Webové a tiskové varianty loga z podkladu klienta (mats/Logo Technická bezpečnost.png).

Zdroj je PNG s neprůhledným bílým podkladem a šedými stíny. Průhlednost se
počítá z „fialovosti“ pixelu (R − G): logo je jednobarevné (#620d58), takže
míchání s bílou dává R − G úměrné krytí, kdežto šedé stíny mají R ≈ G
a zmizí. Tmavé pixely (max kanál < 150) jsou plně kryté vždy – tmavší
písmeno B by jinak prosvítalo. Hrany zůstanou vyhlazené, barva se dopočítá
zpětně z bílé.

Bloky loga (nápis s bleskem, slogan, adresa) se najdou automaticky podle
prázdných řádků mezi nimi: nápis = první (nejvyšší) blok, celé logo = vše.

Výstup:
  public/assets/logo.png (210 px) a logo@2x.png (420 px) – nápis do hlavičky;
      v CSS je 52 px vysoký (≈ 199 px široký), mobil 44 / 36 px
  print/logo/logo-technicka-bezpecnost.png – celé logo se sloganem, průhledné
  print/logo/logo-technicka-bezpecnost-bile.png – nápis bíle pro tmavý podklad
Použití: python3 -m pip install pillow numpy && python3 scripts/make-logo.py
Při novém logu stačí vyměnit soubor v mats/ a skript spustit znovu; pokud
se změní barva loga, upravte LOGO_RGB a prahy níže (a zkontrolujte výpis bloků).
"""
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'mats' / 'Logo Technická bezpečnost.png'
WEB = ROOT / 'public' / 'assets'
PRINT = ROOT / 'print' / 'logo'
LOGO_RGB = np.array([98, 13, 88], dtype=float)   # medián barvy loga
RG_SOLID = 55.0                                   # R − G od této hodnoty = plné krytí
DARK_SOLID, DARK_ZERO = 150.0, 210.0              # tmavé pixely (max kanál) jsou vždy kryté
MIN_GAP = 8                                       # prázdných řádků mezi bloky

src = np.asarray(Image.open(SRC).convert('RGB')).astype(float)
r, g = src[..., 0], src[..., 1]
alpha_rg = np.clip((r - g) / RG_SOLID, 0, 1)
# tmavší, méně syté části (písmeno B, kořen blesku) by z R − G vyšly poloprůhledné;
# stíny mají max kanál > 220, takže je tohle kritérium nevrátí
alpha_dark = np.clip((DARK_ZERO - src.max(axis=2)) / (DARK_ZERO - DARK_SOLID), 0, 1)
alpha = np.maximum(alpha_rg, alpha_dark)
alpha[alpha < 0.06] = 0                           # zbytky stínů a šumu
# zpětný výpočet barvy z míchání s bílou: C = a·L + (1 − a)·255
a3 = np.maximum(alpha, 1e-3)[..., None]
color = np.clip((src - (1 - a3) * 255) / a3, 0, 255)
color[alpha < 0.35] = LOGO_RGB                    # u slabého krytí je dopočet nestabilní
rgba = np.dstack([color, alpha * 255]).round().astype(np.uint8)
logo = Image.fromarray(rgba, 'RGBA')

# svislé bloky s inkoustem
ink_rows = np.flatnonzero((rgba[..., 3] > 0).sum(axis=1) > 3)
blocks, start, prev = [], ink_rows[0], ink_rows[0]
for y in ink_rows[1:]:
    if y - prev > MIN_GAP:
        blocks.append((start, prev))
        start = y
    prev = y
blocks.append((start, prev))
print('bloky loga (řádky zdroje):', ', '.join(f'{a}–{b}' for a, b in blocks))
if (blocks[0][1] - blocks[0][0]) < 0.4 * (blocks[-1][1] - blocks[0][0]):
    raise SystemExit('První blok je nečekaně nízký – zkontrolujte podklad a MIN_GAP.')


def crop(img, top, bottom, pad=6):
    a = np.asarray(img)[..., 3]
    ys, xs = np.nonzero(a[top:bottom + 1] > 0)
    return img.crop((max(xs.min() - pad, 0), top + ys.min() - pad, xs.max() + pad + 1, top + ys.max() + pad + 1))


def save(img, folder, name, width):
    folder.mkdir(parents=True, exist_ok=True)
    h = round(img.height * width / img.width)
    out = img.resize((width, h), Image.LANCZOS)
    if folder == WEB:
        # jednobarevné logo: 256 barev bez ditheringu je vizuálně shodné, ~5× menší
        out = out.quantize(colors=256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    out.save(folder / name, optimize=True)
    print(f'{folder.relative_to(ROOT)}/{name}: {width} × {h} px, {(folder / name).stat().st_size // 1024} kB')


mark = crop(logo, *blocks[0])
full = crop(logo, blocks[0][0], blocks[-1][1])
white = np.asarray(mark).copy()
white[..., :3] = 255

save(mark, WEB, 'logo.png', 210)
save(mark, WEB, 'logo@2x.png', 420)
save(full, PRINT, 'logo-technicka-bezpecnost.png', 1800)
save(Image.fromarray(white, 'RGBA'), PRINT, 'logo-technicka-bezpecnost-bile.png', 1800)
print(f'poměr stran nápisu {mark.width / mark.height:.3f} (width/height v index.html a bootstrap.php)')
