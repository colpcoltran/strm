#!/usr/bin/env node
/* Vygeneruje public/assets/og-image.jpg (1200×630) ze šablony
   scripts/og-template.html a public/assets/apple-touch-icon.png (180×180,
   favicon.svg na barvě hlavičky). Spouštět po změně loga, barev, motta,
   portrétu nebo faviconu:  node scripts/make-og-image.cjs
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
  console.log('OG obrázek zapsán: ' + out);

  // Ikona pro iOS: neprůhledná (iOS sám zaobluje rohy), favicon na barvě hlavičky
  const fs = require('fs');
  const svg = fs.readFileSync(path.join(__dirname, '..', 'public', 'favicon.svg')).toString('base64');
  await page.setViewportSize({ width: 180, height: 180 });
  await page.setContent('<body style="margin:0;width:180px;height:180px;background:#fbf9f3;display:grid;place-items:center">'
    + '<img src="data:image/svg+xml;base64,' + svg + '" style="width:138px;height:138px"></body>');
  await page.waitForTimeout(100);
  const icon = path.join(__dirname, '..', 'public', 'assets', 'apple-touch-icon.png');
  await page.screenshot({ path: icon });
  console.log('Ikona pro iOS zapsána: ' + icon);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
