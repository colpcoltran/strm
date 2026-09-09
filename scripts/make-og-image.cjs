#!/usr/bin/env node
/* Vygeneruje public/assets/og-image.jpg (1200×630) ze šablony
   scripts/og-template.html. Spouštět po změně barvy papíru, motta
   nebo portrétu:  node scripts/make-og-image.cjs
   Vyžaduje Playwright s Chromiem (npm i -D playwright && npx playwright install chromium). */
const path = require('path');
const { pathToFileURL } = require('url');
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  try {
    ({ chromium } = require('/opt/node22/lib/node_modules/playwright'));
  } catch (e2) {
    console.error('Chybí Playwright. Nainstalujte: npm i -D playwright && npx playwright install chromium');
    process.exit(1);
  }
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.goto(pathToFileURL(path.join(__dirname, 'og-template.html')).href);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(200);
  const out = path.join(__dirname, '..', 'public', 'assets', 'og-image.jpg');
  await page.screenshot({ path: out, type: 'jpeg', quality: 82 });
  await browser.close();
  console.log('OG obrázek zapsán: ' + out);
})().catch((e) => { console.error(e); process.exit(1); });
