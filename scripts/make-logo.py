#!/usr/bin/env python3
"""Webové varianty loga z podkladu klienta (mats/Logo Technická bezpečnost.png).

Zdroj je PNG s neprůhledným bílým podkladem a šedými stíny. Průhlednost se
počítá z „fialovosti“ pixelu (R − G): logo má jednu barvu (#620d58), takže
míchání s bílou dává R − G úměrné krytí, kdežto šedé stíny mají R ≈ G
a zmizí. Tmavé pixely (max kanál < 150) jsou plně kryté vždy – tmavší
písmeno B by jinak prosvítalo. Hrany zůstanou vyhlazené, barva se dopočítá
zpětně z bílé.

Výstup (public/assets/):
  logo.png / logo@2x.png        – nápis s bleskem (hlavička webu)
  logo-white.png / @2x          – totéž bíle (tmavá patička)
a do print/logo/:
  logo-technicka-bezpecnost.png – celé logo se sloganem a adresou, průhledné (tiskoviny)
Použití: python3 -m pip install pillow numpy && python3 scripts/make-logo.py
"""
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'mats' / 'Logo Technická bezpečnost.png'
OUT = ROOT / 'public' / 'assets'
PRINT = ROOT / 'print' / 'logo'
LOGO_RGB = np.array([98, 13, 88], dtype=float)   # medián barvy loga
RG_SOLID = 55.0                                   # R − G od této hodnoty = plné krytí
DARK_SOLID, DARK_ZERO = 150.0, 210.0              # tmavé pixely (max kanál) jsou vždy kryté

src = np.asarray(Image.open(SRC).convert('RGB')).astype(float)
r, g, b = src[..., 0], src[..., 1], src[..., 2]
alpha_rg = np.clip((r - g) / RG_SOLID, 0, 1)
# tmavší, méně syté části (písmeno B, kořen blesku) by z R − G vyšly poloprůhledné;
# stíny mají max kanál > 220, takže je tohle kritérium nevrátí
alpha_dark = np.clip((DARK_ZERO - src.max(axis=2)) / (DARK_ZERO - DARK_SOLID), 0, 1)
alpha = np.maximum(alpha_rg, alpha_dark)
alpha[alpha < 0.06] = 0                           # zbytky stínů a šumu
# zpětný výpočet barvy z míchání s bílou: C = a·L + (1 − a)·255
a3 = np.maximum(alpha, 1e-3)[..., None]
color = np.clip((src - (1 - a3) * 255) / a3, 0, 255)
weak = alpha < 0.35                                # u slabého krytí je dopočet nestabilní
color[weak] = LOGO_RGB
rgba = np.dstack([color, alpha * 255]).round().astype(np.uint8)
logo = Image.fromarray(rgba, 'RGBA')


def crop(img, top, bottom, pad=6):
    a = np.asarray(img)[..., 3]
    ys, xs = np.nonzero(a[top:bottom] > 0)
    return img.crop((max(xs.min() - pad, 0), top + ys.min() - pad, xs.max() + pad + 1, top + ys.max() + pad + 1))


def save(img, name, width, folder=OUT):
    folder.mkdir(parents=True, exist_ok=True)
    h = round(img.height * width / img.width)
    out = img.resize((width, h), Image.LANCZOS)
    out.save(folder / name, optimize=True)
    print(f'{folder.name}/{name}: {width} × {h} px, {(folder / name).stat().st_size // 1024} kB')
    return out


# svislé bloky zdroje: nápis 131–592, slogan 612–664, adresa 718–761 (viz analýza v commitu)
mark = crop(logo, 100, 600)
full = crop(logo, 100, 790)
white = mark.copy()
wa = np.asarray(white).copy()
wa[..., :3] = 255
white = Image.fromarray(wa, 'RGBA')

save(mark, 'logo.png', 440)
save(mark, 'logo@2x.png', 880)
save(white, 'logo-white.png', 440)
save(white, 'logo-white@2x.png', 880)
save(full, 'logo-technicka-bezpecnost.png', 1800, PRINT)
print(f'poměr stran nápisu {mark.width / mark.height:.3f}')
