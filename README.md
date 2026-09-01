# Technická bezpečnost – validační landing page

Jednostránkový web, který ověřuje zájem o připravovaný odborný portál
**Technická bezpečnost – odborné odpovědi pro praxi**. Návštěvník odpoví
ANO/NE na otázku o placeném členství; při ANO se plynule rozbalí registrační
formulář (jméno, příjmení, profese, e-mail). Odpovědi se ukládají do SQLite
databáze a o každém novém zájemci odejde e-mailové upozornění klientovi.

## Technologie

- **Frontend:** čisté HTML + CSS + JavaScript, žádný framework, žádný build
  krok, žádné externí zdroje (fonty, CDN, analytika). Web funguje i s vypnutým
  JavaScriptem (rozbalování řeší CSS, formuláře klasický POST).
- **Backend:** PHP 8.0 nebo novější (dva malé endpointy), SQLite přes PDO, `mail()`.
- **Soukromí:** nulové cookies, žádný localStorage, žádné třetí strany,
  NE-odpovědi zcela anonymní, IP adresy se neukládají.

## Mapa souborů

```
├── public/                  ← document root webu
│   ├── index.html           ← celá stránka
│   ├── assets/              ← style.css, app.js, og-image.png, apple-touch-icon.png
│   ├── api/submit.php       ← příjem odpovědí (ukládání + notifikace)
│   ├── admin/export.php     ← chráněný přehled + export CSV
│   ├── favicon.svg, robots.txt, .htaccess
├── app/                     ← MIMO document root
│   ├── config.php           ← ⚙️ jediný soubor, který upravujete
│   └── bootstrap.php        ← společný kód endpointů
├── data/                    ← MIMO document root; SQLite vznikne automaticky
└── README.md
```

## Konfigurace (`app/config.php`)

| Konstanta | Význam |
| --- | --- |
| `NOTIFY_EMAIL` | Kam chodí upozornění na zájemce. **Testovací `zbysek@digicary.cz` – před ostrým provozem změnit.** |
| `MAIL_FROM` | Odesílatel notifikací. Nechte prázdné (doplní se `web@<doména>`), nebo nastavte adresu na doméně hostingu. |
| `EXPORT_USER` | Přihlašovací jméno k exportu (výchozí `spravce`). |
| `EXPORT_PASS_HASH` | Bcrypt hash hesla k exportu. **Dokud je prázdný, je export zamčený.** Jak hash vytvořit: viz „Nastavení hesla k exportu" níže. |
| `DB_PATH` | Cesta k SQLite souboru (výchozí `data/responses.sqlite`). |

## Lokální vývoj

```bash
TB_DEV_MODE=1 php -S localhost:8000 -t public
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
   `NOTIFY_EMAIL` a případně `MAIL_FROM`.
4. V administraci hostingu zapněte HTTPS (Let's Encrypt). Po ověření, že
   HTTPS funguje, můžete v `public/.htaccess` odkomentovat hlavičku HSTS.
5. Složka `data/` musí být pro PHP zapisovatelná (obvykle stačí výchozí
   práva; jinak `chmod 770`). Databáze vznikne automaticky při první odpovědi.
6. V `public/index.html` doplňte v patičce **jméno a kontaktní e-mail
   provozovatele** (placeholdery `[Jméno Příjmení]`, `[doplňte e-mail]`)
   a v hlavičce absolutní URL `og:image`.

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

### Když nechodí e-maily

1. Zkontrolujte složku spam ve schránce `NOTIFY_EMAIL`.
2. Nastavte `MAIL_FROM` na adresu **na doméně webu** (např.
   `web@vase-domena.cz`) a ověřte, že doména má u hostingu SPF záznam
   (hostingy ho většinou nastavují automaticky).
3. Podívejte se do `data/mail-fail.log` – pokud existuje, `mail()` na
   serveru selhává a je potřeba kontaktovat podporu hostingu (některé
   hostingy odesílání přes `mail()` omezují nebo vyžadují povolení).
4. Záznam v databázi vznikne i při selhání e-mailu – o zájemce nepřijdete,
   vidíte ho v exportu.

## Export dat

- `https://vase-domena.cz/admin/export.php` – po přihlášení přehled počtů
  ANO/NE, tabulka zájemců a tlačítko **Stáhnout CSV pro Excel**.
- CSV má UTF-8 BOM, středníky a CRLF – český Excel jej otevře na dvojklik.
- Hodnoty začínající znaky `=`, `+`, `-` nebo `@` mají v CSV předřazený
  apostrof – to je záměrná ochrana, aby Excel nespouštěl podvržené vzorce
  z vyplněných formulářů.
- Export nemá veřejnou adresu, neposílejte jej e-mailem a nešiřte dál
  (obsahuje osobní údaje).

## Testovací checklist po nasazení

1. Odpověď **ANO** + vyplněný formulář → success zpráva, řádek v DB,
   e-mail dorazil na `NOTIFY_EMAIL`.
2. Stejný e-mail podruhé → žádný druhý řádek ani druhý e-mail (tichý úspěch).
3. Odpověď **NE** → anonymní řádek (jen datum a NE).
4. Prázdná pole / špatný e-mail → české chybové hlášky u polí.
5. Vypnutý JavaScript → celý průchod funguje přes klasické stránky.
6. Mobil (úzké okno) → vše čitelné a použitelné.
7. DevTools → Application: žádné cookies, žádný storage; Network: žádné
   požadavky na cizí domény.
8. `https://…/data/responses.sqlite` a `https://…/app/config.php` vrací
   403/404 (v nouzovém režimu).
9. `/admin/export.php` bez hesla nepustí dál; CSV se správně otevře v Excelu.

## GDPR – provozní povinnosti

- Doplnit skutečné jméno a e-mail správce do patičky a Zásad (index.html).
- Schránku správce reálně číst – mohou přijít žádosti o výmaz údajů.
- Nejpozději **31. 3. 2027** smazat databázi (`data/responses.sqlite`),
  logy a notifikační e-maily ve schránce.
- Formulář nemá (záměrně) souhlasový checkbox – právním základem je čl. 6
  odst. 1 písm. b) GDPR; web nemá cookies, proto není cookie lišta.
- Tato obhajoba stojí na formulaci u formuláře, že odesláním **žádáte
  o zaslání informace o spuštění** – při případných úpravách textů se tato
  věta nesmí ztratit ani oslabit. Budoucí e-mail zájemcům smí být jen
  o tomto projektu (jiná sdělení by už vyžadovala souhlas dle
  zák. č. 480/2004 Sb.).

## Fotografie (volitelné vylepšení)

Web nyní používá vlastní vektorovou grafiku (jemná „blueprint" textura v hero,
ilustrace `assets/ilustrace-vyklad.svg` v sekci detailů) – je bez licenčních
starostí a ostrá na všech displejích. Chcete-li místo ilustrace skutečnou
fotografii z fotobanky:

1. **Odkud brát:** Unsplash (unsplash.com), Pexels (pexels.com) nebo Pixabay
   (pixabay.com) – jejich licence dovolují komerční použití zdarma a bez
   uvádění autora. Vyhýbejte se snímkům označeným „editorial use only"
   a u fotek s rozpoznatelnými lidmi ověřte, že fotobanka uvádí souhlas
   modelu (model release).
2. **Co hledat:** „electrician switchboard", „electrical panel inspection",
   „pressure gauge industrial", „technician safety check", „rozvaděč".
   Vhodné jsou snímky s tmavšími/modrými tóny, které ladí s barvami webu.
3. **Jak nasadit:** obrázek zmenšete na ~1000 px šířky a zkomprimujte
   (např. squoosh.app, cílově do ~150 kB), uložte jako
   `assets/foto-vyklad.jpg` a v `index.html` přepište `src` obrázku v sekci
   „Praktický výklad" (komentář `Slot pro fotografii` místo označuje);
   upravte i `alt` text podle obsahu fotky.
4. Fotografii lze stejným způsobem dát i do hero: soubor
   `assets/hero-bg.svg` nahraďte fotografií a v `style.css` u `.hero`
   ponechte gradient jako druhou vrstvu, ať zůstane čitelný text
   (`background-image: url('hero-foto.jpg'), linear-gradient(…)` +
   případně ztmavení `linear-gradient(rgba(14,44,76,0.75), …)`).

## Co tu záměrně není

Žádná analytika, žádná CAPTCHA (spam řeší honeypot + limit počtu odeslání za
minutu), žádné cookies, žádné externí fonty či skripty. Je to záměr – web je
rychlý, auditovatelný a bez právních komplikací.

## Vědomá interpretační rozhodnutí (ke schválení klientem)

- **Duplicitní registrace:** stejný e-mail se uloží jen jednou a druhá
  notifikace se neposílá (uživatel přesto vidí úspěch). Statistika i schránka
  zůstávají čisté.
- **Export = CSV kompatibilní s Excelem**, samostatný soubor .xlsx se
  negeneruje (CSV s BOM a středníky otevře český Excel na dvojklik).
- **Odpověď NE se odesílá tlačítkem** „Odeslat odpověď" (ne automaticky při
  kliknutí na volbu) – brání omylům a nezkresluje statistiku.
- **Texty voleb** jsou „Ano, mám zájem" / „Ne, nemám zájem" (zadání uvádělo
  [ ANO ] / [ NE ]); ukládané hodnoty jsou ANO/NE dle zadání.
