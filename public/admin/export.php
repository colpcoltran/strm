<?php
declare(strict_types=1);

/**
 * Chráněná administrace: přehled zájemců a export do CSV (otevře se přímo
 * v českém Excelu). Přístup hlídá HTTP Basic Auth řešená v PHP – funguje na
 * Apache, nginx i vestavěném PHP serveru a bez nastaveného hesla je zamčená.
 * Správce je jediný, proto se ověřuje pouze heslo (jméno v dialogu prohlížeče
 * může zůstat prázdné). Proti hádání hesla je globální brzda: po
 * AUTH_MAX_FAILS neúspěšných pokusech za AUTH_WINDOW_MIN minut se přihlášení
 * na tu dobu odmítá – bez ukládání IP adres.
 */

foreach ([dirname(__DIR__, 2) . '/app/bootstrap.php', dirname(__DIR__) . '/app/bootstrap.php'] as $bootstrapPath) {
    if (is_file($bootstrapPath)) {
        require $bootstrapPath;
        break;
    }
}
if (!function_exists('respondJson')) {
    http_response_code(500);
    // Záměrně bez diakritiky: hlavička s kódováním v tuto chvíli není nastavena.
    exit('Chybi app/bootstrap.php – zkontrolujte rozlozeni souboru dle README.');
}

header('X-Robots-Tag: noindex, nofollow');

/** @return array{0: ?string, 1: ?string} */
function basicAuthCredentials(): array
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    if ($user !== null) {
        return [$user, (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    // CGI/FastCGI hostingy PHP_AUTH_* nenaplní – hlavičku předává
    // pravidlo SetEnvIf v public/.htaccess. Schéma „Basic" je dle
    // RFC 7617 case-insensitive.
    $headerValue = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (is_string($headerValue) && preg_match('/^Basic\s+(.+)$/i', $headerValue, $m)) {
        $decoded = base64_decode($m[1], true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            [$user, $pass] = explode(':', $decoded, 2);
            return [$user, $pass];
        }
    }
    return [null, null];
}

$passHash = EXPORT_PASS_HASH;
if ($passHash === '' && DEV_MODE) {
    $passHash = getenv('TB_EXPORT_HASH') ?: '';
}
if ($passHash === '') {
    respondHtml(503, 'Export není nastaven', '<h1>Export není nastaven</h1>'
        . '<p>V souboru <code>app/config.php</code> vyplňte konstantu <code>EXPORT_PASS_HASH</code>'
        . ' podle návodu v README. Do té doby je export z bezpečnostních důvodů nedostupný.</p>');
}

const AUTH_MAX_FAILS = 10;
const AUTH_WINDOW_MIN = 15;

/** Počet neúspěšných přihlášení v posledním okně; při chybě DB brzda nebrzdí. */
function recentAuthFails(?PDO $pdo): int
{
    if ($pdo === null) {
        return 0;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS auth_fail (at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
        $pdo->exec("DELETE FROM auth_fail WHERE at < datetime('now', '-1 day')");
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM auth_fail WHERE at > datetime('now', '-" . AUTH_WINDOW_MIN . " minutes')"
        )->fetchColumn();
    } catch (Throwable $exception) {
        error_log('export.php auth_fail: ' . $exception->getMessage());
        return 0;
    }
}

try {
    $authPdo = getPdo();
} catch (Throwable $exception) {
    $authPdo = null;
}

if (recentAuthFails($authPdo) >= AUTH_MAX_FAILS) {
    header('Retry-After: ' . (AUTH_WINDOW_MIN * 60));
    respondHtml(429, 'Příliš mnoho pokusů', '<h1>Příliš mnoho neúspěšných pokusů</h1>'
        . '<p>Přihlášení do administrace je na ' . AUTH_WINDOW_MIN . ' minut pozastaveno. Zkuste to prosím později.</p>');
}

[$authUser, $authPass] = basicAuthCredentials();
// Jediný správce – ověřuje se pouze heslo, jméno v dialogu je libovolné.
// password_verify má z konstrukce stejnou dobu odezvy pro každý vstup.
if (!password_verify((string) $authPass, $passHash)) {
    if ($authPass !== null && $authPass !== '' && $authPdo !== null) {
        // Skutečný (ne prázdný) pokus o heslo se počítá do brzdy; bez IP adresy.
        try {
            $authPdo->exec('INSERT INTO auth_fail DEFAULT VALUES');
        } catch (Throwable $exception) {
            error_log('export.php auth_fail insert: ' . $exception->getMessage());
        }
    }
    usleep(300000); // zdražení online hádání hesla
    header('WWW-Authenticate: Basic realm="Technicka bezpecnost - administrace (staci heslo)"');
    respondHtml(401, 'Vyžadováno přihlášení', '<h1>Vyžadováno přihlášení</h1>'
        . '<p>Zadejte prosím heslo k administraci (jméno může zůstat prázdné). Viz README.</p>');
}

function csvCell(?string $value): string
{
    $value = (string) $value;
    // Excel by text začínající =, +, -, @ vyhodnotil jako vzorec (CSV injection).
    if ($value !== '' && str_contains("=+-@\t", $value[0])) {
        $value = "'" . $value;
    }
    if (strpbrk($value, ";\"\r\n") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/** Vždy končí exit; deklarováno jako void kvůli kompatibilitě s PHP 8.0. */
function downloadCsv(PDO $pdo): void
{
    // Nejdřív celé CSV sestavit, teprve pak poslat hlavičky – kdyby
    // dotaz selhal, nesmí se chybová stránka stáhnout jako soubor.csv.
    // BOM + středníky + CRLF => český Excel otevře soubor správně na dvojklik.
    $lines = ['id;datum;odpoved;jmeno;prijmeni;profese;email'];
    $rows = $pdo->query('SELECT id, created_at, answer, jmeno, prijmeni, profese, email FROM responses ORDER BY id');
    foreach ($rows as $row) {
        $lines[] = implode(';', [
            (string) $row['id'],
            pragueTime($row['created_at'], 'd.m.Y H:i'),
            csvCell($row['answer']),
            csvCell($row['jmeno']),
            csvCell($row['prijmeni']),
            csvCell($row['profese']),
            csvCell($row['email']),
        ]);
    }
    $csv = "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";

    $filename = 'technicka-bezpecnost-' . pragueTime(null, 'Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

try {
    $pdo = $authPdo ?? getPdo();

    if (($_GET['download'] ?? '') === '1') {
        downloadCsv($pdo);
    }

    $countAno = 0;
    $countNe = 0;
    foreach ($pdo->query('SELECT answer, COUNT(*) AS pocet FROM responses GROUP BY answer') as $row) {
        if ($row['answer'] === 'ANO') {
            $countAno = (int) $row['pocet'];
        } else {
            $countNe = (int) $row['pocet'];
        }
    }
    $lastAt = $pdo->query('SELECT MAX(created_at) FROM responses')->fetchColumn();

    $tableRows = '';
    $interested = $pdo->query(
        "SELECT id, created_at, jmeno, prijmeni, profese, email
         FROM responses WHERE answer = 'ANO' ORDER BY id DESC"
    );
    foreach ($interested as $row) {
        $tableRows .= '<tr>'
            . '<td>' . (int) $row['id'] . '</td>'
            . '<td>' . e(pragueTime($row['created_at'])) . '</td>'
            . '<td>' . e((string) $row['jmeno']) . '</td>'
            . '<td>' . e((string) $row['prijmeni']) . '</td>'
            . '<td>' . e((string) $row['profese']) . '</td>'
            . '<td>' . e((string) $row['email']) . '</td>'
            . '</tr>';
    }
    if ($tableRows === '') {
        $tableRows = '<tr><td colspan="6">Zatím žádní zájemci.</td></tr>';
    }

    respondHtml(200, 'Registrace zájemců – Technická bezpečnost', '<h1>Registrace zájemců</h1>'
        . '<div class="stat-row">'
        . '<div class="stat"><strong>' . $countAno . '</strong><span>má zájem (ANO)</span></div>'
        . ($countNe > 0 ? '<div class="stat"><strong>' . $countNe . '</strong><span>nemá zájem (NE)</span></div>' : '')
        . '<div class="stat"><strong>' . ($countAno + $countNe) . '</strong><span>záznamů celkem</span></div>'
        . '</div>'
        . '<p>Poslední odpověď: ' . e(is_string($lastAt) ? pragueTime($lastAt) : 'zatím žádná') . '</p>'
        . '<p><a class="btn btn-primary" href="export.php?download=1">Stáhnout CSV pro Excel</a></p>'
        . '<h2>Zájemci</h2>'
        . '<div class="table-wrap"><table>'
        . '<thead><tr><th scope="col">Č.</th><th scope="col">Datum</th><th scope="col">Jméno</th><th scope="col">Příjmení</th><th scope="col">Profese / oblast zájmu</th><th scope="col">E-mail</th></tr></thead>'
        . '<tbody>' . $tableRows . '</tbody>'
        . '</table></div>'
        . '<p class="note">Web sbírá jen registrace zájemců; volba „nemám zájem“ byla na přání klienta odstraněna a endpoint ji už nepřijímá (případné starší anonymní řádky NE zůstávají jen v souhrnném počtu).'
        . ' Nezapomeňte: všechna data je potřeba smazat nejpozději 31.&nbsp;3.&nbsp;2027 (viz Zásady).</p>',
        'fallback-page admin-page');
} catch (Throwable $exception) {
    error_log('export.php: ' . $exception->getMessage());
    respondHtml(500, 'Chyba serveru', '<h1>Přehled se nepodařilo načíst</h1><p>Zkuste to prosím znovu.</p>');
}
