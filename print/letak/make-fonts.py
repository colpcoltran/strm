#!/usr/bin/env python3
"""Inter (OFL 1.1) pro tiskový leták: OpenType/CFF → TrueType + podmnožina znaků.

Proč: Chromium (Skia) vkládá písma s CFF obrysy do PDF jako Type 3, což
tiskárny a preflight nemají rády (horší rasterizace, varování RIPu).
TrueType obrysy se vkládají jako CID TrueType – stejně jako Barlow z webu.
Zdroj: systémový balík fonts-inter (Debian), výstup se commituje do
print/letak/fonts/, takže sestavení letáku na systému nezávisí.
Použití (jednorázově): python3 -m pip install fonttools && python3 print/letak/make-fonts.py
"""
from pathlib import Path

from fontTools import subset
from fontTools.pens.cu2quPen import Cu2QuPen
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.ttLib import TTFont, newTable

SRC = Path('/usr/share/fonts/opentype/inter')
OUT = Path(__file__).resolve().parent / 'fonts'
WEIGHTS = ['Regular', 'Medium', 'SemiBold', 'Bold']
# Latin, Latin-1, Latin Extended-A (čeština), typografické znaky, šipky, ×, €
UNICODES = '0000-007F,00A0-00FF,0100-017F,0218-021B,02C6-02DD,2000-206F,20AC,2122,2190-2193,2212,00D7'


def otf_to_ttf(font, max_err=1.0):
    """Převod kubických (CFF) obrysů na kvadratické (glyf) – recept z fontTools snippets."""
    glyph_order = font.getGlyphOrder()
    glyph_set = font.getGlyphSet()
    glyf = newTable('glyf')
    glyf.glyphOrder = glyph_order
    glyf.glyphs = {}
    for name in glyph_order:
        pen = TTGlyphPen(glyph_set)
        glyph_set[name].draw(Cu2QuPen(pen, max_err, reverse_direction=True))
        glyf[name] = pen.glyph()
    font['loca'] = newTable('loca')
    font['glyf'] = glyf
    del font['CFF ']
    if 'VORG' in font:
        del font['VORG']
    maxp = font['maxp']
    maxp.tableVersion = 0x00010000
    for attr in ('maxZones', 'maxTwilightPoints', 'maxStorage', 'maxFunctionDefs', 'maxInstructionDefs',
                 'maxStackElements', 'maxSizeOfInstructions', 'maxComponentElements'):
        setattr(maxp, attr, 0)
    maxp.maxZones = 1
    font['head'].glyphDataFormat = 0
    post = font['post']
    post.formatType = 2.0
    post.extraNames = []
    post.mapping = {}
    post.glyphOrder = glyph_order
    font.sfntVersion = '\x00\x01\x00\x00'


OUT.mkdir(exist_ok=True)
for w in WEIGHTS:
    font = TTFont(SRC / f'Inter-{w}.otf')
    otf_to_ttf(font)
    opts = subset.Options()
    opts.layout_features = ['kern', 'liga', 'calt', 'case', 'tnum', 'ccmp', 'locl', 'mark', 'mkmk']
    opts.name_IDs = ['*']
    opts.notdef_outline = True
    opts.glyph_names = False
    sub = subset.Subsetter(opts)
    sub.populate(unicodes=subset.parse_unicodes(UNICODES))
    sub.subset(font)
    dst = OUT / f'Inter-{w}.ttf'
    font.save(dst)
    print(f'{dst.name}: {dst.stat().st_size // 1024} kB, {len(font.getGlyphOrder())} glyfů')
