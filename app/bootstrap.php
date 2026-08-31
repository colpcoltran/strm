<?php
declare(strict_types=1);

/**
 * Společný základ obou PHP endpointů: databáze, pomocné funkce,
 * odpovědi (JSON i HTML fallback bez JavaScriptu) a notifikační e-mail.
 */

require __DIR__ . '/config.php';

mb_internal_encoding('UTF-8');
ini_set('display_errors', DEV_MODE ? '1' : '0');
ini_set('log_errors', '1');

function getPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS responses (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            answer     TEXT NOT NULL CHECK (answer IN ('ANO', 'NE')),
            jmeno      TEXT,
            prijmeni   TEXT,
            profese    TEXT,
            email      TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )"
    );
    // Jeden zájemce (e-mail) = jeden řádek; NE-odpovědi jsou anonymní a bez limitu.
    $pdo->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_responses_email
         ON responses (email) WHERE answer = 'ANO'"
    );
    return $pdo;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Ořeže hodnotu z formuláře a odstraní řídicí znaky – jedna ochrana
 * společná pro DB, e-mailové hlavičky i CSV export.
 */
function cleanText(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function clientWantsJson(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function respondJson(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Minimální samostatná HTML stránka pro průchod bez JavaScriptu
 * a pro administrátorský přehled. Cesty jsou relativní k /api/ i /admin/.
 */
function respondHtml(int $status, string $title, string $bodyHtml, string $bodyClass = 'fallback-page'): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html>'
        . '<html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="icon" href="../favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="../assets/style.css">'
        . '</head><body class="' . e($bodyClass) . '"><main class="fallback-card">'
        . $bodyHtml
        . '</main></body></html>';
    exit;
}

/** Čas (UTC ze SQLite, nebo teď) převedený do Europe/Prague. */
function pragueTime(?string $utc = null, string $format = 'j. n. Y H:i'): string
{
    $dt = new DateTimeImmutable($utc ?? 'now', new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone('Europe/Prague'))->format($format);
}

function mailFromAddress(): string
{
    if (MAIL_FROM !== '') {
        return MAIL_FROM;
    }
    $host = strtolower($_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $host = preg_replace('/[^a-z0-9.-]/', '', $host) ?: 'localhost';
    return 'web@' . $host;
}

/**
 * Upozorní klienta na nového zájemce. Selhání odeslání request neshazuje –
 * záznam v DB je důležitější než notifikace; selhání se zapíše do logu
 * (bez osobních údajů).
 */
function sendInterestNotification(int $rowId, string $jmeno, string $prijmeni, string $profese, string $email): bool
{
    $body = "Nový zájemce o odborný web Technická bezpečnost:\n\n"
        . 'Jméno:    ' . $jmeno . "\n"
        . 'Příjmení: ' . $prijmeni . "\n"
        . 'Profese:  ' . $profese . "\n"
        . 'E-mail:   ' . $email . "\n"
        . 'Odesláno: ' . pragueTime() . " (Europe/Prague)\n\n"
        . "Tato zpráva byla vygenerována automaticky dotazníkem na landing page.\n";

    $subject = mb_encode_mimeheader('Nový zájemce – Technická bezpečnost', 'UTF-8', 'B');
    // E-mail zájemce prošel FILTER_VALIDATE_EMAIL, do hlavičky Reply-To je bezpečný.
    $headers = 'From: ' . mailFromAddress() . "\r\n"
        . 'Reply-To: ' . $email . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . 'Content-Transfer-Encoding: 8bit';

    if (DEV_MODE) {
        $logLine = '=== ' . pragueTime() . ' ===' . "\nTo: " . NOTIFY_EMAIL . "\nSubject: " . $subject
            . "\n" . $headers . "\n\n" . $body . "\n";
        file_put_contents(dirname(DB_PATH) . '/mail-dev.log', $logLine, FILE_APPEND | LOCK_EX);
        return true;
    }

    $ok = @mail(NOTIFY_EMAIL, $subject, $body, $headers);
    if (!$ok) {
        file_put_contents(
            dirname(DB_PATH) . '/mail-fail.log',
            gmdate('c') . ' mail() selhal pro zaznam id=' . $rowId . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    return $ok;
}
