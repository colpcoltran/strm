<?php
declare(strict_types=1);

/**
 * Konfigurace projektu „Technická bezpečnost – landing page“.
 * Toto je jediný soubor, který je potřeba před nasazením upravit.
 */

// E-mail, na který chodí upozornění na nové zájemce.
// Testovací fáze: schránka projektu na vlastní doméně.
const NOTIFY_EMAIL = 'info@technickabezpecnost.cz';

// Adresa odesílatele notifikací. Měla by být na doméně hostingu,
// jinak hrozí, že e-mail skončí ve spamu nebo ho hosting odmítne.
// Prázdná hodnota = doplní se automaticky jako web@<doména webu>.
const MAIL_FROM = '';

// Přístup k přehledu odpovědí a CSV exportu (/admin/export.php).
// Uživatelské jméno pro přihlášení:
const EXPORT_USER = 'spravce';

// Bcrypt hash hesla. Dokud je prázdný, je export ZAMČENÝ (bezpečné výchozí
// chování). Hash vygenerujete příkazem:
//   php -r "echo password_hash('VaseHeslo', PASSWORD_DEFAULT), PHP_EOL;"
// a výsledek vložíte mezi apostrofy níže.
const EXPORT_PASS_HASH = '';

// Cesta k SQLite databázi (výchozí: složka data/ vedle složky app/).
define('DB_PATH', dirname(__DIR__) . '/data/responses.sqlite');

// Vývojový režim: e-maily se místo odeslání zapisují do data/mail-dev.log
// a PHP vypisuje chyby. Zapíná se jen proměnnou prostředí TB_DEV_MODE=1
// (na běžném hostingu je tedy vždy vypnutý).
define('DEV_MODE', getenv('TB_DEV_MODE') === '1');
