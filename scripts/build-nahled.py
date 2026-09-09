#!/usr/bin/env python3
"""Složí jediný HTML soubor s náhledem webu bez serveru:
CSS, fonty i obrázky jsou vložené jako data URI a odeslání formuláře je
simulované (nic se nikam neposílá). Použití:

    python3 scripts/build-nahled.py vystup/nahled.html
"""
import base64, mimetypes, pathlib, re, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'public'
out = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'nahled.html')

def data_uri(path: pathlib.Path) -> str:
    mime = mimetypes.guess_type(path.name)[0] or 'application/octet-stream'
    if path.suffix == '.woff2':
        mime = 'font/woff2'
    return f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode()

html = (ROOT / 'index.html').read_text(encoding='utf-8')
css = (ROOT / 'assets' / 'style.css').read_text(encoding='utf-8')
js = (ROOT / 'assets' / 'app.js').read_text(encoding='utf-8')

# CSS: url('fonts/…') a případné obrázky
css = re.sub(r"url\('([^']+)'\)", lambda m: f"url('{data_uri(ROOT / 'assets' / m.group(1))}')", css)

# HTML: obrázky (src + srcset)
def inline_img(m):
    return f'src="{data_uri(ROOT / m.group(1))}"'
html = re.sub(r'src="(assets/[^"]+\.(?:jpg|png|svg))"', inline_img, html)
html = re.sub(r'\s*srcset="[^"]*"', '', html)
html = html.replace('href="favicon.svg"', f'href="{data_uri(ROOT / "favicon.svg")}"')
html = re.sub(r'<link rel="apple-touch-icon"[^>]*>\n?', '', html)

# JS: fetch → simulace (700 ms, vždy úspěch)
stub = """  /* NÁHLED: backend se simuluje – nic se nikam neodesílá. */
  function fakeSubmit(form) {
    return new Promise(function (resolve) {
      window.setTimeout(function () {
        resolve({ status: 200, data: { ok: true, answer: form.querySelector('[name="answer"]').value } });
      }, 700);
    });
  }

  function wireForm(form, originStatus) {"""
assert js.count('  function wireForm(form, originStatus) {') == 1
js = js.replace('  function wireForm(form, originStatus) {', stub)
start = js.index("fetch(form.getAttribute('action'), {")
end = js.index("}).then(function (result) {", start) + len("}).then(function (result) {")
js = js[:start] + "fakeSubmit(form).then(function (result) {" + js[end:]

html = html.replace('<link rel="stylesheet" href="assets/style.css">', '<style>\n' + css + '\n</style>')
# Inline skript nezná `defer` – musí být až za obsahem stránky.
html = html.replace('<script src="assets/app.js" defer></script>', '')
html = html.replace('</body>', '<script>\n' + js + '\n</script>\n</body>')
banner = ('<div style="background:#f0a63c;color:#17222c;font:600 14px/1.4 system-ui;text-align:center;padding:8px 12px">'
          'NÁHLED – odeslání formuláře je simulované, nic se neukládá</div>')
html = html.replace('<body>', '<body>' + banner, 1)
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(html, encoding='utf-8')
print(f'Náhled zapsán: {out} ({out.stat().st_size // 1024} kB)')
