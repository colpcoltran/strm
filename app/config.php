<?php
declare(strict_types=1);

/**
 * Konfigurace projektu „Technická bezpečnost – landing page“.
 * Toto je jediný soubor, který je potřeba před nasazením upravit.
 */

// E-mail, na který chodí upozornění na nové zájemce (schránka projektu).
// Kontaktní adresa uvedená na webu (info@special-inspections.com) upozornění
// záměrně nedostává – slouží jen pro dotazy a žádosti návštěvníků.
const NOTIFY_EMAIL = 'info@technickabezpecnost.cz';

// Adresa odesílatele notifikací = skutečná schránka na doméně webu (při
// odesílání přes SMTP musí být shodná se SMTP_USER). Neexistující adresa
// nebo cizí doména = spam. Prázdná hodnota = web@<doména webu> (nouzově).
const MAIL_FROM = 'info@technickabezpecnost.cz';

// Odesílání přes SMTP ze skutečné schránky (jinak notifikace padají do spamu –
// PHP mail() z webového serveru neprojde DMARC, viz README). Přihlašovací
// údaje patří do app/config.local.php, který NENÍ v gitu – vzor je
// v app/config.local.php.example. Bez něj se použije PHP mail().
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 465);
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'ssl');
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', '');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', '');
}


// Bcrypt hash hesla. Dokud je prázdný, je export ZAMČENÝ (bezpečné výchozí
// chování). Hash vygenerujete příkazem:
//   php -r "echo password_hash('VaseHeslo', PASSWORD_DEFAULT), PHP_EOL;"
// a výsledek vložíte mezi apostrofy níže.
const EXPORT_PASS_HASH = '$2y$12$QDHGmym4augtk0M6r1sijuUZnajE3WcJ2uWy12OROsZnQsvwQLSuS';

// Cesta k SQLite databázi (výchozí: složka data/ vedle složky app/).
define('DB_PATH', dirname(__DIR__) . '/data/responses.sqlite');

// Vývojový režim: e-maily se místo odeslání zapisují do data/mail-dev.log
// a PHP vypisuje chyby. Zapíná se jen proměnnou prostředí TB_DEV_MODE=1
// (na běžném hostingu je tedy vždy vypnutý).
define('DEV_MODE', getenv('TB_DEV_MODE') === '1');
