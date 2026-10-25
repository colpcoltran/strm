# Technická bezpečnost – validační landing page

Jednostránkový web, který ověřuje zájem o připravovaný odborný portál
**Technická bezpečnost – odborné odpovědi pro praxi**. Zájemce o placené
členství (750 Kč měsíčně) vyplní registrační formulář (jméno, příjmení,
profese či oblast zájmu, e-mail). Formulář je na stránce dvakrát: sbalený
v hero (rozbalí a sbalí ho CTA „Budu mít zájem") a vždy viditelný dole v sekci
`#registrace`. Registrace se ukládají do SQLite databáze a o každém novém
zájemci odejde e-mailové upozornění klientovi. Volba „nemám zájem" byla ve
2. kole připomínek na přání klienta z webu odstraněna (viz „Vědomá
interpretační rozhodnutí").

Vizuál: jednobarevný podklad „starý papír" (`--c-paper` ve `style.css`,
odstín převzatý z klientem dodané textury), portrét autora s mottem
„Ptejte se. Odpovídám z praxe, ne z paragrafů." v hero, medailonek autora
v sekci Praktický výklad. Od 25. 10. 2026 nese web logo klienta (hlavička)
a paletu odvozenou z jeho švestkové barvy – viz „Logo a barvy".

## Technologie

- **Frontend:** čisté HTML + CSS + JavaScript, žádný framework, žádný build
  krok, žádné externí zdroje (CDN, analytika). Web funguje i s vypnutým
  JavaScriptem (CTA vede na vždy viditelný formulář dole, formuláře posílá
  klasický POST a PHP vrátí samostatnou stránku). Titulkové
  písmo Barlow Condensed je self-hostované v `assets/fonts/` (licence
  OFL-1.1, soubor `LICENSE-OFL.txt` tamtéž) – nic se nenačítá z Google
  Fonts, tělový text používá systémová písma.
- **Backend:** PHP 8.0 nebo novější (dva malé endpointy), SQLite přes PDO, `mail()`.
- **Soukromí:** nulové cookies, žádný localStorage, žádné třetí strany,
  IP adresy se neukládají.

## Mapa souborů

```
├── public/                  ← document root webu
│   ├── index.html           ← celá stránka
│   ├── assets/              ← style.css, app.js, logo(-white)(@2x).png, portret-autor(-300|@2x).jpg, og-image.jpg, fonts/
│   ├── api/submit.php       ← příjem odpovědí (ukládání + notifikace)
│   ├── admin/index.php      ← chráněný přehled registrací (/admin/)
│   ├── admin/export.php     ← export CSV (/admin/export.csv)
│   ├── favicon.svg, robots.txt, sitemap.xml, .htaccess
├── app/                     ← MIMO document root
│   ├── config.php           ← ⚙️ jediný soubor, který upravujete
│   ├── bootstrap.php        ← společný kód endpointů
│   ├── mailer.php           ← SMTP klient pro notifikace (bez závislostí)
│   ├── config.local.php.example ← vzor přihlášení k SMTP (config.local.php není v gitu)
│   └── admin.php            ← přihlášení a ochrany administrace
├── data/                    ← MIMO document root; SQLite vznikne automaticky
├── scripts/                 ← vývojové nástroje (nasazovací větev, logo, OG obrázek, náhled, dev router)
├── mats/                    ← podklady od klienta (originál loga); na web se nenasazují
├── print/logo/              ← celé logo se sloganem, průhledné (pro tiskoviny)
├── print/letak/             ← tištěný leták A4 s QR kódem (zdroj, fonty, hotová PDF v out/)
├── print/qr/                ← samostatný QR kód na web (SVG, PDF, PNG; navy a černý – zatím v původní barvě)
└── README.md
```

## Konfigurace (`app/config.php`)

| Konstanta | Význam |
| --- | --- |
| `NOTIFY_EMAIL` | Kam chodí upozornění na zájemce: `info@technickabezpecnost.cz` (schránka projektu). Kontaktní adresa uvedená na webu (info@special-inspections.com) žádné automatické e-maily nedostává. |
| `MAIL_FROM` | Odesílatel notifikací – skutečná schránka na doméně webu (`info@technickabezpecnost.cz`); při odesílání přes SMTP shodná se `SMTP_USER`. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | V souboru `app/config.local.php` (není v gitu, vzor `app/config.local.php.example`): odesílání přes SMTP Hostingeru ze schránky info@, aby zprávy nepadaly do spamu. Bez souboru se použije PHP `mail()`. |
| `EXPORT_PASS_HASH` | Bcrypt hash hesla k administraci (`/admin/` a `/admin/export.csv`). **Dokud je prázdný, je administrace zamčená.** Přihlašuje se vlastním formulářem jen heslem (bez uživatelského jména – správce je jediný; heslo je na přání klienta při psaní viditelné), přihlášení drží podepsaná cookie 30 dní (jen pro `/admin/`, HttpOnly, SameSite=Strict; změna hesla všechna přihlášení zneplatní). Skripty (curl) se přihlásí stejně: `curl -c c.txt --data-urlencode 'heslo=…' https://…/admin/index.php` a dál `-b c.txt`. Po 10 neúspěšných pokusech za 15 minut se přihlášení na 15 minut pozastaví (bez ukládání IP adres). Jak hash vytvořit: viz „Nastavení hesla k exportu" níže. |
| `DB_PATH` | Cesta k SQLite souboru (výchozí `data/responses.sqlite`). |

## Lokální vývoj

```bash
TB_DEV_MODE=1 php -S localhost:8000 -t public scripts/dev-router.php
```

- `TB_DEV_MODE=1` zapne vývojový režim: e-maily se místo odeslání zapisují do
  `data/mail-dev.log` a PHP vypisuje chyby.
- Vestavěný server nečte `.htaccess` – bezpečnostní hlavičky a HTTPS redirect
  se projeví až na hostingu; ochrana exportu heslem funguje i lokálně (řeší ji
  PHP). V dev režimu lze heslo exportu dodat i proměnnou `TB_EXPORT_HASH`.

## Nasazení na sdílený hosting (FTP)

1. Nahrajte projekt tak, aby **document root ukazoval na složku `public/`**;
   složky `app/` a `data/` zůstávají o úroveň výš, mimo web.
2. V administraci hostingu zvolte **PHP 8.0 nebo novější** (doporučeno 8.2+).
3. V `app/config.php` vyplňte `EXPORT_PASS_HASH` (postup níže), zkontrolujte
   `NOTIFY_EMAIL` a `MAIL_FROM`. Na server nahrajte `app/config.local.php`
   s heslem schránky pro SMTP (vzor `app/config.local.php.example`, postup
   v „Když e-maily padají do spamu nebo nechodí“).
4. V administraci hostingu zapněte HTTPS (Let's Encrypt). Po ověření, že
   HTTPS funguje, můžete v `public/.htaccess` odkomentovat hlavičku HSTS.
5. Složka `data/` musí být pro PHP zapisovatelná (obvykle stačí výchozí
   práva; jinak `chmod 770`). Databáze vznikne automaticky při první odpovědi.
6. Jméno autora, medailonek („Kdo za projektem stojí", `#autor`), provozovatel
   (Martin Šturma) i kontaktní e-mail (info@special-inspections.com)
   jsou vyplněné podle podkladů klienta. Při změně upravte hero (`.hero-author`,
   `.hero-author-role`), `#autor`, patičku (`.footer-owner`, `.footer-meta`), Zásady
   a OG šablonu (`scripts/og-template.html` + `node scripts/make-og-image.cjs`);
   `scripts/build-deploy-hostinger.sh`
   upozorní na případné placeholdery v hranatých závorkách `[…]`. `og:url` a `og:image`
   míří na www.technickabezpecnost.cz – při jiné doméně upravte.

**Nouzový režim** – hosting neumožňuje umístit soubory nad document root:
nahrajte složky `app/` i `data/` společně dovnitř webové složky vedle
`index.html`. Obě obsahují `.htaccess` s `Require all denied`, takže je Apache
nevydá. **Pozor: soubory `.htaccess` jsou skryté a některé FTP klienty je
při přenosu vynechají – po nahrání zkontrolujte, že na serveru opravdu
jsou.** (Aplikace si chybějící `data/.htaccess` při prvním requestu sama
doplní, na to ale nespoléhejte.) Doporučujeme pak v `app/config.php` změnit
`DB_PATH` na název s náhodným přídavkem, např. `responses-9f3k2x8q.sqlite`.

### Nastavení hesla k exportu

Do `EXPORT_PASS_HASH` v `app/config.php` patří bcrypt hash hesla (nikdy ne
heslo samotné). Jak ho získat:

- **S PHP na počítači:** `php -r "echo password_hash('VaseHeslo', PASSWORD_DEFAULT), PHP_EOL;"`
- **Bez PHP na počítači:** vytvořte soubor `hash.php` s obsahem
  `<?php echo password_hash($_GET['h'] ?? '', PASSWORD_DEFAULT);`, nahrajte
  ho do webové složky, otevřete `https://vase-domena.cz/hash.php?h=VaseHeslo`,
  zobrazený hash zkopírujte do configu a **soubor `hash.php` ihned smažte**.

Změna hesla = vygenerovat nový hash a nahradit ho v configu.

### Když e-maily padají do spamu nebo nechodí

**Příčina spamu (ověřeno z hlaviček 15. 9. 2026):** PHP `mail()` odchází
z webového serveru Hostingeru s obálkovým odesílatelem
`noreply@srvXXXX.main-hosting.eu` a bez DKIM podpisu → pro doménu webu
`dmarc=fail` („SPF not aligned, No valid DKIM“) a filtr Hostingeru zprávu
označí `X-Spam: Yes`. DNS domény (SPF, DKIM, DMARC) je u Hostingeru
v pořádku, jen zpráva z webu jimi není kryta.

**Řešení = odesílat přes SMTP ze skutečné schránky** (poštovní server zprávu
podepíše DKIM, SPF i DMARC projdou):

1. Zkopírujte `app/config.local.php.example` jako `config.local.php` a doplňte
   heslo schránky `info@technickabezpecnost.cz` (stejné, jakým se hlásíte do
   webmailu).
2. Nahrajte ho na server: hPanel → Soubory → Správce souborů →
   `public_html/app/config.local.php`. Složka `app/` je pro web zakázaná
   (`Require all denied`), soubor není v gitu a nasazení z gitu ho nemaže.
3. Odešlete zkušební registraci – zpráva má dorazit do Doručené pošty
   s hlavičkami `dkim=pass`, `spf=pass`, `dmarc=pass` a
   `Return-Path: <info@technickabezpecnost.cz>`. Zkušební řádek pak smažte
   z databáze.
4. Když SMTP selže (špatné heslo, port, výpadek), web se vrátí k `mail()`
   a důvod zapíše do `data/mail-fail.log` (bez hesla i osobních údajů).

Bez SMTP: `MAIL_FROM` musí být existující schránka na doméně webu; zprávy
u Hostingeru přesto skončí ve spamu (viz výše). `data/mail-fail.log` ukáže
případné selhání `mail()`. Záznam v databázi vznikne i při selhání e-mailu –
o zájemce nepřijdete, vidíte ho v přehledu.

## Administrace a export dat

- `https://vase-domena.cz/admin/` – přehled registrací (po přihlášení heslem
  ve formuláři, bez jména): dlaždice celkem / dnes / 7 dní / 30 dní, registrace po
  týdnech, vyhledávání a tabulka zájemců. Tlačítko **Stáhnout CSV pro
  Excel** vede na `https://vase-domena.cz/admin/export.csv` (sloupec
  `profese` obsahuje „profesi či oblast zájmu" z formuláře). Přehled je
  záměrně jen ke čtení – je určený klientovi, bez mazání a provozních
  poznámek (výmaz a lhůty řeší tento README).
- Hezké adresy zajišťuje `DirectoryIndex` a přepis v `public/.htaccess`;
  lokálně je napodobuje `scripts/dev-router.php` (viz Lokální vývoj).
- CSV má UTF-8 BOM, středníky a CRLF – český Excel jej otevře na dvojklik.
- Hodnoty začínající znaky `=`, `+`, `-` nebo `@` mají v CSV předřazený
  apostrof – to je záměrná ochrana, aby Excel nespouštěl podvržené vzorce
  z vyplněných formulářů.
- Export nemá veřejnou adresu, neposílejte jej e-mailem a nešiřte dál
  (obsahuje osobní údaje).

## Testovací checklist po nasazení

1. CTA **Budu mít zájem** v hero rozbalí formulář (druhý klik ho sbalí); vyplněný
   formulář → success zpráva v hero i dole, řádek v DB, e-mail dorazil na
   `NOTIFY_EMAIL` do Doručené pošty (ne spamu; hlavičky `dkim=pass`,
   `dmarc=pass`, `Return-Path` = schránka domény).
2. Stejný e-mail podruhé → u pole e-mail hláška „Tento e-mail už je
   registrovaný…“, žádný druhý řádek ani druhý e-mail.
3. Dolní formulář v sekci **#registrace** je vidět bez klikání a funguje stejně.
4. Prázdná pole / špatný e-mail → české chybové hlášky u polí.
5. Vypnutý JavaScript → celý průchod funguje přes klasické stránky.
6. Mobil (úzké okno) → vše čitelné a použitelné.
7. DevTools → Application: žádné cookies, žádný storage; Network: žádné
   požadavky na cizí domény.
8. `https://…/data/responses.sqlite` a `https://…/app/config.php` vrací
   403/404 (v nouzovém režimu).
9. `/admin/` ani `/admin/export.csv` bez přihlášení nepustí dál (formulář
   jen s heslem); přehled ukazuje statistiky, registrace po týdnech a tabulku
   s vyhledáváním; tlačítko Odhlásit funguje; CSV se správně otevře v Excelu.
10. `/sitemap.xml` vrací 200 (XML s jedinou URL), `robots.txt` má řádek
    `Sitemap:`, HTML má `<link rel="canonical">`, adresa bez `www` přesměruje
    301 na `https://www.technickabezpecnost.cz/`.

## Ostrý provoz

Web je od 14. 9. 2026 v ostrém provozu: nasazovací větev už nevkládá hlavičku
`X-Robots-Tag: noindex` (indexace povolena, `robots.txt` nic nezakazuje),
v `public/.htaccess` je zapnuté HSTS (`max-age` 1 rok, jen pro tento host)
a testovací záznamy byly z živé databáze smazány.
Administrace (`/admin/`) sama zůstává `noindex` a `robots.txt` ji zakazuje.

**Indexace (od 15. 10. 2026):** web má `sitemap.xml` (jediná URL, `lastmod`
doplňuje nasazovací skript z data posledního commitu `index.html`), řádek
`Sitemap:` v `robots.txt`, `<link rel="canonical">` a přesměrování
`technickabezpecnost.cz` → `www` (301, jedním skokem i z http). Samo o sobě
to Google k webu nepřivede – nová doména bez odkazů se neobjeví sama. Nutné
kroky majitele: 1) Google Search Console → přidat vlastnost typu Doména
(`technickabezpecnost.cz`) a ověřit TXT záznamem v DNS zóně hPanelu
(alternativa: vlastnost URL-prefix `https://www.technickabezpecnost.cz/`
s HTML souborem nebo meta tagem, který se nasadí z repa); 2) Indexování →
Soubory Sitemap → odeslat `https://www.technickabezpecnost.cz/sitemap.xml`;
3) Kontrola URL → `https://www.technickabezpecnost.cz/` → Požádat o indexaci
(stačí jednou). Obvykle do 1–2 týdnů je stránka v indexu; stav a návštěvy
Googlebota ukáže jen Search Console. Doplňkově Bing Webmaster Tools
(import ze Search Console) a 1–3 skutečné odkazy (web special-inspections.com,
LinkedIn, e-mailový podpis).

## Tištěný leták A4 (`print/letak/`)

Leták ve vizuálu webu s QR kódem na `https://www.technickabezpecnost.cz/`.
Leták i QR kód vznikly před novým logem a jsou zatím v původní modré paletě.
Samostatný QR kód ve stejném provedení (SVG, PDF, PNG) je v `print/qr/`.
Na web se nenasazuje (nasazovací skript kopíruje jen `public/` a `app/`).

| Soubor v `print/letak/out/` | Pro koho |
| --- | --- |
| `letak-A4-spad-3mm.pdf` | **Tiskárně** (výchozí): 216 × 303 mm = A4 + spadávka 3 mm na každé straně, TrimBox/BleedBox nastavené. |
| `letak-A4-spad-3mm-znacky.pdf` | Tiskárně, která chce ořezové značky: 226 × 313 mm, značky 0,25 pt v okraji 5 mm. |
| `letak-A4-bez-spadu.pdf` | Kancelářská tiskárna a e-mail: čistá A4 210 × 297 mm (tiskárna sama nechá bílý okraj, text drží ≥ 6 mm od kraje). |
| `nahled.png` | Rychlý náhled. |

- Barvy jsou RGB (tokeny webu), fonty vložené jako TrueType (Barlow Condensed
  z webu, Inter převedený do TrueType v `fonts/` – licence OFL 1.1), portrét
  ~700 dpi, QR vektorově 33 mm s korekcí Q. Vyžaduje-li tiskárna CMYK nebo
  PDF/X, převod udělá její RIP z PDF se spadávkou.
- Přegenerování (po změně textů v `letak.html`): `bash print/letak/build.sh`
  (Python: `segno`, `pypdf`, `opencv-python-headless`; Node + Playwright
  s Chromiem; `poppler-utils`). Sestavení selže, pokud text vyjede z bezpečné
  zóny nebo z listu, font se vloží jako Type 3, QR nejde přečíst (150 i
  100 dpi), pozadí nesahá do rohů spadávky nebo ořezové značky nesedí na
  TrimBox (tolerance 0,05 mm).
- Texty jsou převzaté z webu; na letáku chybí datum „ozvu se do konce
  listopadu 2026“, aby tiskovina nezastarala. Při změně ceny, termínu
  spuštění nebo kontaktu upravte web i `letak.html`.

## GDPR – provozní povinnosti

- Správcem údajů i provozovatelem webu je na přání klienta Martin Šturma jako
  fyzická osoba (kontakt info@special-inspections.com) – vyplněno v patičce
  i Zásadách; Zásady jsou psané v 1. osobě, „já" = správce.
- Identifikace jménem a e-mailem stačí (čl. 13 GDPR): web nic neprodává, jen
  ověřuje zájem. Povinnost uvádět název, IČO a sídlo (§ 435 odst. 1 OZ) váže
  podnikatele – pokud web později poběží komerčně pod firmou (placené
  členství), doplňte do patičky (`.footer-owner`, `.footer-meta`) i do odstavce
  Správce v Zásadách: Special Inspections s. r. o., IČO 06447058, Otěvěky
  č. ev. 16, 270 33 Žďár (zápis názvu podle podkladu klienta – ověřte proti
  obchodnímu rejstříku).
- Kontaktní schránku uvedenou na webu (info@special-inspections.com) reálně číst –
  mohou přijít žádosti o výmaz údajů. Administrace mazání záměrně nenabízí
  (je pro klienta jen ke čtení); výmaz řádku provede správce webu přímo
  v SQLite (`DELETE FROM responses WHERE id = …` – soubor stáhnout přes
  správce souborů hostingu a nahrát zpět, nebo dočasným skriptem). Upozornění na zájemce tam nechodí, ta
  jdou na `NOTIFY_EMAIL` (info@technickabezpecnost.cz).
- Nejpozději **31. 3. 2027** smazat databázi (`data/responses.sqlite`),
  logy a notifikační e-maily ve schránce.
- Formulář nemá (záměrně) souhlasový checkbox – právním základem je čl. 6
  odst. 1 písm. b) GDPR; veřejný web nemá cookies, proto není cookie lišta.
  Jedinou cookie (`tb_admin`, technicky nezbytná) dostane až administrátor
  po přihlášení do `/admin/` – návštěvníků se netýká.
- Tato obhajoba stojí na formulaci u formuláře, že odesláním **žádáte
  o zaslání informace o spuštění** – při případných úpravách textů se tato
  věta nesmí ztratit ani oslabit. Budoucí e-mail zájemcům smí být jen
  o tomto projektu (jiná sdělení by už vyžadovala souhlas dle
  zák. č. 480/2004 Sb.).

## Logo a barvy

**Logo** dodal klient jako PNG s bílým podkladem (`mats/Logo Technická
bezpečnost.png`). Varianty dělá `python3 scripts/make-logo.py`:
průhlednost se počítá z „fialovosti“ pixelu, takže šedé stíny originálu
zmizí a hrany zůstanou hladké; bloky loga (nápis, slogan, adresa) skript
najde sám podle prázdných řádků a vypíše je. Výstupy: `assets/logo.png`
(210 px) + `@2x` (420 px) – nápis s bleskem do hlavičky (52 px, mobil
44 px, pod 360 px 36 px) a do stránek bez JavaScriptu/administrace;
`print/logo/logo-technicka-bezpecnost.png` (celé logo se sloganem)
a `…-bile.png` (nápis bíle pro tmavý podklad) pro tiskoviny. Při novém
logu stačí vyměnit soubor v `mats/` a skript spustit znovu; pokud se změní
barva loga, upravte i `LOGO_RGB` a prahy ve skriptu a zkontrolujte výpis
bloků. Změní-li se poměr stran, upravte `width`/`height` loga
v `index.html` a `app/bootstrap.php`.

**Paleta** (`:root` ve `style.css`) vychází ze švestky loga `#620d58`
(odstín 307°); celá tmavá řada drží stejný odstín, mění se jen světlost:

| Role | Barva |
| --- | --- |
| Tlačítka, odkazy, motto (`--c-primary`) | `#620d58` – přímo barva loga |
| Nadpisy, hover (`--c-primary-dark`) | `#3f0a39` – téměř černá švestka, aby logo zůstalo nejsytější |
| Karty přínosů | přechod `#5a1450` → `#3f0c39`, ikony světlý jantar `#f2bd6b` |
| Hlavička | lomená bílá `#fbf9f3` (logo v původních barvách, kontrast 11,6 : 1) + jantarová linka 3 px |
| Akcent (`--c-accent`) | jantar `#dc9a35` – doplňkový teplý akcent (komplementární ke švestce, výstražné značení); jen dekorace. Logo samo je jednobarevné. |
| Patička | `#2c0727`, odkazy jantarové `#f2bd6b`, focus bílý |
| Text | `#41353f` (tělo), `#291927` (zvýraznění) – neutrály s nádechem švestky |

Všech 25 klíčových dvojic barev splňuje WCAG AA (text ≥ 4,5 : 1, velký
text, ovládací prvky a logo ≥ 3 : 1). Paleta vybrána ze čtyř návrhů
(světlá hlavička s jantarem, tmavá hlavička s bílým logem, monochromatická
švestka, papír s mosazí) – vyhrála světlá hlavička, protože logo zůstává
v barvách, ve kterých ho klient dodal. Favicon (`favicon.svg`) je odvozený
znak, ne výřez z loga: švestkový štít s jantarovým bleskem (motiv blesku
z loga, čitelný i v 16 px). Ikona pro iOS (`assets/apple-touch-icon.png`)
a OG obrázek se vyrábějí příkazem `node scripts/make-og-image.cjs`;
`theme-color` je barva hlavičky `#fbf9f3`.

**Cache:** Hostinger posílá souborům v `assets/` hlavičku cache na 7 dní.
Nasazovací skript proto do odkazů na CSS, JS, obrázky a favicon v
`index.html` a `app/bootstrap.php` doplní otisk obsahu (`?v=…`) – změněný
soubor se stáhne hned, nezměněný zůstane v cache. HTML se ověřuje při každé
návštěvě (`Cache-Control: no-cache` v `.htaccess`). V repozitáři odkazy
parametry nemají. Sociální sítě si náhled ukládají podle adresy; po změně
OG obrázku stačí nasadit (adresa dostane nový otisk), případně náhled
obnovit ve Facebook Sharing Debuggeru / LinkedIn Post Inspectoru.

## Obrázky a barva papíru

**Portrét autora** (`assets/portret-autor.jpg` 600×800 px, `portret-autor-300.jpg`
300×400 px pro mobilní avatar a `portret-autor@2x.jpg` 1200×1600 px, ořez 3:4
z fotografie dodané klientem) je jediná fotografie na
webu. Na desktopu je vpravo v hero s mottem a popiskem, na mobilu jako kulatý
avatar v „podpisovém řádku". Výměna: připravte ořez 3:4 (např. squoosh.app,
JPEG ~80 %), přepište všechny tři soubory (300, 600 a @2x) a případně upravte `alt` („Portrét: Martin Šturma") a řádek
`.hero-author-role` v `index.html` (charakteristika odvozená z medailonku).
Medailonek v sekci Praktický výklad je na přání klienta bez fotografie.
Originál fotografie zůstává u klienta / v účtu Magnific, do repozitáře se neukládá.

**Barva papíru** – hero a sekce karet mají jednobarevný podklad „starý papír"
(přání klienta). Původní odstín `#e0ddcb` (světlý tón z dodané textury) byl
s novým logem posunut o chlup od olivové k teplé béžové `#e3dccb`
(`--c-paper-deep` `#d3cab4`, `--c-paper-light` `#f1ede2`, `--c-line`
`#dad1bf`), aby se netloukl se švestkou; vrátit jde změnou těchto čtyř
proměnných (kontrasty projdou i s původními hodnotami). Ladění barvy =
změna proměnné `--c-paper` v `style.css` (a případně `--c-paper-deep`,
`--c-paper-light`). Texty na papíru jsou navrženy na kontrast WCAG AA:
zesvětlení papíru nic nerozbije; při ztmavení pod `#d3cab4` znovu ověřte
bronzový eyebrow/cenu (`--c-bronze`) a tlumený text (`--c-muted-warm`),
případně je ztmavte.

**OG obrázek** (`assets/og-image.jpg`, 1200×630) se generuje ze šablony
`scripts/og-template.html` příkazem `node scripts/make-og-image.cjs`
(vyžaduje Playwright s Chromiem: `npm i -D playwright && npx playwright
install chromium`; `node_modules/` je v `.gitignore`). Spusťte znovu po
změně loga, barev, motta nebo portrétu (šablona má barvy zapsané přímo).

**Motto** „Ptejte se. Odpovídám z praxe, ne z paragrafů." je v `index.html`
(`.hero-motto`) a v OG šabloně – výměna je jeden řetězec na dvou místech
(+ `node scripts/make-og-image.cjs`). Zvoleno z návrhů: „Tisíc stran norem.
Jedna jasná odpověď.", „Vy ručíte za provoz. Já za odpovědi.", „Norma vás
neochrání. Rozumět jí ano.", „Norma říká. Praxe ukáže. Já vysvětlím.",
„Odpovědi bez alibismu."; původní návrhy klienta: „Ptejte se a já budu
odpovídat", „Mé zkušenosti jsou zde pro vás", „Pojďme se spolu posunout dále",
„Výuka a školení jsou užitečné, ale zkušenosti se nedají nahradit".

**Náhled k prokliku bez serveru:** `python3 scripts/build-nahled.py
cesta/nahled.html` složí jediný HTML soubor (fonty i obrázky vložené,
odeslání formuláře simulované) – vhodné k zaslání klientovi.

## Co tu záměrně není

Žádná analytika, žádná CAPTCHA (spam řeší honeypot + limit počtu odeslání za
minutu), žádné cookies, žádné externí fonty či skripty. Je to záměr – web je
rychlý, auditovatelný a bez právních komplikací.

## Vědomá interpretační rozhodnutí (ke schválení klientem)

- **Duplicitní registrace:** stejný e-mail se uloží jen jednou; druhé
  odeslání na přání klienta ukáže u pole e-mail hlášku „Tento e-mail už je
  registrovaný – ozvu se vám…“ (JS i bez JS), nic nepřepíše a druhou
  notifikaci nepošle. Původní „tichý úspěch“ (poděkování i podruhé) byl
  nahrazen vědomě – hláška prozradí, že daný e-mail je registrovaný, což je
  u pracovních kontaktů zanedbatelné riziko.
- **Export = CSV kompatibilní s Excelem**, samostatný soubor .xlsx se
  negeneruje (CSV s BOM a středníky otevře český Excel na dvojklik).
- **Odpověď NE byla na pokyn klienta odstraněna z celého webu** (hero i dolní
  sekce); web tak měří jen absolutní počet zájemců, nikoli poměr
  zájem/nezájem – jako jmenovatel poslouží statistika návštěv z administrace
  hostingu. Endpoint `api/submit.php` přijímá jen `answer=ANO` (NE vrací 422),
  aby se do statistiky nedostaly řádky, které z webu nikdo nemohl odeslat;
  přehled ukazuje počet NE jen tehdy, je-li nenulový (starší testovací řádky).
  Návrat ankety = obnovit větev NE z historie gitu (commit před 2. kolem).
- **Pole „Vaše profese či oblast zájmu"** je přejmenované původní pole
  Profese (klient chtěl kolonku přidat, formulář ji už měl). Interně zůstává
  `profese` (databáze, CSV, e-mail) – žádná migrace dat.
- **Audio karta sloučena do archivu:** klient zdůraznil, že nejde o podcast;
  karta „Poslech kdekoli" se sluchátky zmizela, audio je zmíněno jen jako
  doplněk v kartě Členský archiv a v calloutu „Není to podcast".
- **Zvýraznění cílových skupin** v úvodní větě (`<strong>`) je typografické,
  text klienta je doslovný; lze jedním tahem odebrat.
- **Medailonek autora** je doslovný text klienta; oproti podkladu opraven pád
  „revizních techniků" → „revizním technikům" a doplněna koncová tečka za
  poslední větu (podklad ji neměl). Umístěn jako citace na konci sekce
  Praktický výklad. Řádek pod portrétem v hero („Konzultant technické
  bezpečnosti, třetí dekádu v praxi") je odvozený z medailonku – ke schválení.
  Na přání klienta (3. kolo připomínek) je medailonek bez fotografie a podpis
  zní jen „Martin Šturma" bez firmy; provozovatelem webu i správcem údajů je
  Martin Šturma jako fyzická osoba, nikoli Special Inspections s. r. o.
