/* Vyrobí tiskové PDF letáku ve třech variantách (Playwright + Chromium)
   a před exportem zkontroluje rozvržení: nic nepřetéká a veškerý text
   i QR kód drží bezpečnou zónu od ořezové čáry.
   Použití: node print/letak/build-letak.cjs   (spouští build.sh) */
const path = require('path');
const fs = require('fs');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  ({ chromium } = require('/opt/node22/lib/node_modules/playwright'));
}

const DIR = __dirname;
const OUT = path.join(DIR, 'out');
const SAFE_MM = 6; // minimální vzdálenost textu/QR od ořezové čáry
const VARIANTS = [
  { v: 'spad', file: 'letak-A4-spad-3mm.pdf' },
  { v: 'znacky', file: 'letak-A4-spad-3mm-znacky.pdf' },
  { v: 'bez', file: 'letak-A4-bez-spadu.pdf' },
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  let failed = false;

  for (const { v, file } of VARIANTS) {
    const page = await browser.newPage();
    await page.goto('file://' + path.join(DIR, 'letak.html') + '?v=' + v);
    const info = await page.evaluate(() => window.__letak);
    // Viewport = velikost stránky, aby rozvržení na obrazovce odpovídalo tisku
    const px = (mm) => Math.round((mm * 96) / 25.4);
    await page.setViewportSize({ width: px(info.W + info.over), height: px(info.H + info.over) });
    await page.evaluate(async () => {
      await document.fonts.ready;
      await Promise.all(Array.from(document.images).map((i) => (i.complete ? null
        : new Promise((r) => { i.onload = r; i.onerror = r; }))));
    });

    const report = await page.evaluate((safeMm) => {
      const mm = 96 / 25.4;
      const L = window.__letak;
      const o = (L.slug + L.bleed) * mm;
      const trim = { left: o, top: o, right: o + 210 * mm, bottom: o + 297 * mm };
      const sheet = document.querySelector('.sheet').getBoundingClientRect();
      const problems = [];

      // 1) bezpečná zóna od ořezu
      document.querySelectorAll('.safe, .brand, .band-url').forEach((el) => {
        const r = el.getBoundingClientRect();
        const d = Math.min(r.left - trim.left, r.top - trim.top, trim.right - r.right, trim.bottom - r.bottom) / mm;
        if (d < safeMm - 0.05) {
          problems.push(`mimo bezpečnou zónu (${d.toFixed(1)} mm): ${el.className} „${el.textContent.trim().slice(0, 40)}“`);
        }
      });

      // 2) přetečení listu a bloků
      const foot = document.querySelector('.band-bottom').getBoundingClientRect();
      if (foot.bottom > sheet.bottom + 0.5) {
        problems.push(`obsah přetéká list o ${((foot.bottom - sheet.bottom) / mm).toFixed(1)} mm`);
      }
      document.querySelectorAll('.cta, .card, .callout, .portrait, .qr').forEach((el) => {
        if (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1) {
          problems.push('přetéká: ' + el.className);
        }
      });

      const cta = document.querySelector('.cta');
      const ctaCopy = document.querySelector('.cta-copy').getBoundingClientRect();
      const qrBox = document.querySelector('.qr').getBoundingClientRect();
      const ctaPad = parseFloat(getComputedStyle(cta).paddingTop) + parseFloat(getComputedStyle(cta).paddingBottom);
      const qr = document.querySelector('.qr-img').getBoundingClientRect();
      const img = document.querySelector('.portrait img');
      return {
        problems,
        ctaSlackMm: ((cta.clientHeight - ctaPad - Math.max(ctaCopy.height, qrBox.height)) / mm).toFixed(1),
        qrMm: (qr.width / mm).toFixed(1),
        portraitDpi: Math.round(img.naturalWidth / (img.getBoundingClientRect().width / mm / 25.4)),
        fontsOk: ['700 20px "Barlow Condensed"', '600 20px "Barlow Condensed"', '400 12px "Inter Letak"',
        '500 12px "Inter Letak"', '600 12px "Inter Letak"', '700 12px "Inter Letak"'].every((f) => document.fonts.check(f)),
        sections: Array.from(document.querySelectorAll('.sheet > *'))
          .map((s) => `${s.className}: ${(s.getBoundingClientRect().height / mm).toFixed(1)} mm`),
      };
    }, SAFE_MM);

    console.log(`\n[${v}] stránka ${info.W} × ${info.H} mm, QR ${report.qrMm} mm, portrét ${report.portraitDpi} dpi, `
      + `rezerva ve výzvě ${report.ctaSlackMm} mm, fonty ${report.fontsOk ? 'OK' : 'CHYBÍ'}`);
    console.log('  ' + report.sections.join(' | '));
    if (!report.fontsOk) report.problems.push('některý font (Barlow Condensed / Inter Letak) se nenačetl');
    if (report.problems.length) {
      failed = true;
      report.problems.forEach((p) => console.log('  CHYBA: ' + p));
    }

    await page.pdf({
      path: path.join(OUT, file),
      // o 1 mm větší – přesný rozměr a tiskové rámečky nastaví finish.py
      width: (info.W + info.over) + 'mm',
      height: (info.H + info.over) + 'mm',
      printBackground: true,
      preferCSSPageSize: true,
      margin: { top: 0, right: 0, bottom: 0, left: 0 },
    });
    console.log('  → out/' + file);
    await page.close();
  }

  await browser.close();
  if (failed) {
    console.error('\nRozvržení má chyby – viz výše.');
    process.exit(1);
  }
})().catch((e) => { console.error(e); process.exit(2); });
