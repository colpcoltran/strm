# QR kód na www.technickabezpecnost.cz

Samostatný QR kód s adresou `https://www.technickabezpecnost.cz/`. Je totožný
s kódem na letáku (`print/letak/`): verze 4, korekce chyb Q (kód se přečte
i při poškození až čtvrtiny plochy), tichá zóna 4 moduly je součástí souborů.

| Soubor | Použití |
| --- | --- |
| `qr-technickabezpecnost.svg` | Vektor pro grafika a web, libovolná velikost. |
| `qr-technickabezpecnost.pdf` | Vektor pro tiskárnu, 40 × 40 mm. |
| `qr-technickabezpecnost-tisk.png` | 1 230 × 1 230 px, 600 dpi (≈ 52 mm) pro Word, PowerPoint a programy bez vektorů. |
| `qr-technickabezpecnost-web.png` | 410 × 410 px pro e-mail, web a sociální sítě. |

Každý soubor je ve dvou barvách: švestková barva loga (bez přípony) a černá
(přípona `-cerny`, nejvyšší kontrast – je i na letáku). Podklad je vždy bílý.

## Zásady použití

- Netisknout menší než 2 × 2 cm. Na plakátu čteném z dálky počítat
  s velikostí aspoň desetiny čtecí vzdálenosti (2 m → 20 cm).
- Neořezávat bílý okraj kolem kódu (tichou zónu) a nic do něj nevkládat.
- Neobracet barvy (světlé moduly na tmavém podkladu přečte jen část
  čteček). Na tmavý nebo barevný podklad dát kód i s jeho bílým okrajem.
- Kód nedeformovat (vždy stejná šířka i výška).

## Přegenerování

```bash
python3 -m pip install segno opencv-python-headless pillow
python3 print/qr/make-qr.py
```

Skript vytvoří všechny soubory a každý vykreslí a dekóduje (PNG přímo,
PDF přes `pdftoppm`, SVG přes Chromium z Playwright). Pokud by některý
kód nevedl přesně na adresu webu, skončí chybou.
