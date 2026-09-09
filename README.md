# Technická bezpečnost – validační landing page

Jednostránkový web, který ověřuje zájem o připravovaný odborný portál
**Technická bezpečnost – odborné odpovědi pro praxi**. Zájemce o placené
členství (750 Kč měsíčně) vyplní registrační formulář (jméno, příjmení,
profese či oblast zájmu, e-mail). Formulář je na stránce dvakrát: sbalený
v hero (rozbalí a sbalí ho CTA „Mám zájem") a vždy viditelný dole v sekci
`#registrace`. Registrace se ukládají do SQLite databáze a o každém novém
zájemci odejde e-mailové upozornění klientovi. Volba „nemám zájem" byla ve
2. kole připomínek na přání klienta z webu odstraněna (viz „Vědomá
interpretační rozhodnutí").

Vizuál: jednobarevný podklad „starý papír" (`--c-paper` ve `style.css`,
odstín převzatý z klientem dodané textury), portrét autora s mottem
„Ptejte se a já budu odpovídat." v hero, tmavě modré karty přínosů.

## Technologie

- **Frontend:** čisté HTML + CSS + JavaScript, žádný framework, žádný build
  krok, žádné externí zdroje (CDN, analytika). Web funguje i s vypnutým
  JavaScriptem (rozbalování řeší CSS, formuláře klasický POST). Titulkové
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
│   ├── assets/              ← style.css, app.js, portret-autor(@2x).jpg, og-image.jpg, fonts/
│   ├── api/submit.php       ← příjem odpovědí (ukládání + notifikace)
│   ├── admin/export.php     ← chráněný přehled + export CSV
│   ├── favicon.svg, robots.txt, .htaccess
├── app/                     ← MIMO document root
│   ├── config.php           ← ⚙️ jediný soubor, který upravujete
│   └── bootstrap.php        ← společný kód endpointů
├── data/                    ← MIMO document root; SQLite vznikne automaticky
├── scripts/                 ← vývojové nástroje (nasazovací větev, OG obrázek, náhled)
└── README.md
```

## Konfigurace (`app/config.php`)

| Konstanta | Význam |
| --- | --- |
| `NOTIFY_EMAIL` | Kam chodí upozornění na zájemce (testovací fáze: `info@technickabezpecnost.cz`). |
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

- `https://vase-domena.cz/admin/export.php` – po přihlášení počet zájemců,
  tabulka zájemců a tlačítko **Stáhnout CSV pro Excel** (sloupec `profese`
  obsahuje „profesi či oblast zájmu" z formuláře).
- CSV má UTF-8 BOM, středníky a CRLF – český Excel jej otevře na dvojklik.
- Hodnoty začínající znaky `=`, `+`, `-` nebo `@` mají v CSV předřazený
  apostrof – to je záměrná ochrana, aby Excel nespouštěl podvržené vzorce
  z vyplněných formulářů.
- Export nemá veřejnou adresu, neposílejte jej e-mailem a nešiřte dál
  (obsahuje osobní údaje).

## Testovací checklist po nasazení

1. CTA **Mám zájem** v hero rozbalí formulář (druhý klik ho sbalí); vyplněný
   formulář → success zpráva v hero i dole, řádek v DB, e-mail dorazil na
   `NOTIFY_EMAIL`.
2. Stejný e-mail podruhé → žádný druhý řádek ani druhý e-mail (tichý úspěch).
3. Dolní formulář v sekci **#registrace** je vidět bez klikání a funguje stejně.
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

## Obrázky a barva papíru

**Portrét autora** (`assets/portret-autor.jpg` 600×800 px a `portret-autor@2x.jpg`
1200×1600 px, ořez 3:4 z fotografie dodané klientem) je jediná fotografie na
webu. Na desktopu je vpravo v hero s mottem a popiskem, na mobilu jako kulatý
avatar v „podpisovém řádku". Výměna: připravte ořez 3:4 (např. squoosh.app,
JPEG ~80 %), přepište oba soubory a případně upravte `alt` (dnes „Autor
projektu" – bez hranatých závorek, čtečky by je předčítaly) a popisek
`.hero-author` v `index.html` (placeholder `[Jméno Příjmení]`). Originál
fotografie zůstává u klienta / v účtu Magnific, do repozitáře se neukládá.

**Barva papíru** – hero a sekce karet mají jednobarevný podklad „starý papír"
(přání klienta). Odstín `#d9d7c4` je světlejší tón z dodané textury (medián
textury `#cfcdb8` slouží jako `--c-paper-deep` pro rámečky). Ladění barvy =
změna proměnné `--c-paper` v `style.css` (a případně `--c-paper-deep`,
`--c-paper-light`). Texty na papíru jsou navrženy na kontrast WCAG AA:
zesvětlení papíru nic nerozbije; při ztmavení pod `#cfcdb8` znovu ověřte
bronzový eyebrow/cenu (`--c-bronze`) a tlumený text (`--c-muted-warm`),
případně je ztmavte.

**OG obrázek** (`assets/og-image.jpg`, 1200×630) se generuje ze šablony
`scripts/og-template.html` příkazem `node scripts/make-og-image.cjs`
(vyžaduje Playwright s Chromiem). Spusťte znovu po změně barvy papíru,
motta nebo portrétu. Absolutní URL do `og:image` doplňte po nasazení.

**Motto** „Ptejte se a já budu odpovídat." je v `index.html` (`.hero-motto`)
a v OG šabloně; klient nabídl i varianty „Mé zkušenosti jsou zde pro vás",
„Pojďme se spolu posunout dále", „Výuka a školení jsou užitečné, ale
zkušenosti se nedají nahradit" – výměna je jeden řetězec na dvou místech.

**Náhled k prokliku bez serveru:** `python3 scripts/build-nahled.py
cesta/nahled.html` složí jediný HTML soubor (fonty i obrázky vložené,
odeslání formuláře simulované) – vhodné k zaslání klientovi.

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
- **Odpověď NE byla na pokyn klienta odstraněna z celého webu** (hero i dolní
  sekce); web tak měří jen absolutní počet zájemců, nikoli poměr
  zájem/nezájem – jako jmenovatel poslouží statistika návštěv z administrace
  hostingu. Endpoint `api/submit.php` přijímá jen `answer=ANO` (NE vrací 422),
  aby se do statistiky nedostaly řádky, které z webu nikdo nemohl odeslat;
  export ukazuje počet NE jen tehdy, je-li nenulový (starší testovací řádky).
  Návrat ankety = obnovit větev NE z historie gitu (commit před 2. kolem).
- **Pole „Vaše profese či oblast zájmu"** je přejmenované původní pole
  Profese (klient chtěl kolonku přidat, formulář ji už měl). Interně zůstává
  `profese` (databáze, CSV, e-mail) – žádná migrace dat.
- **Audio karta sloučena do archivu:** klient zdůraznil, že nejde o podcast;
  karta „Poslech kdekoli" se sluchátky zmizela, audio je zmíněno jen jako
  doplněk v kartě Členský archiv a v calloutu „Není to podcast".
- **Zvýraznění cílových skupin** v úvodní větě (`<strong>`) je typografické,
  text klienta je doslovný; lze jedním tahem odebrat.
