<?php
declare(strict_types=1);

/**
 * Chráněný přehled odpovědí a export do CSV (otevře se přímo v českém Excelu).
 * Přístup hlídá HTTP Basic Auth řešená v PHP – funguje na Apache, nginx
 * i vestavěném PHP serveru a bez nastaveného hesla je export zamčený.
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

[$authUser, $authPass] = basicAuthCredentials();
// Heslo se ověřuje vždy proti skutečnému hashi (ten v této chvíli vždy
// existuje – jinak by výše padla 503) a s výsledkem kontroly jména se
// skládá bez zkratu. Doba odezvy je tak u špatného jména i špatného
// hesla z konstrukce stejná a neprozradí, zda zadané jméno existuje.
$userOk = $authUser !== null && hash_equals(EXPORT_USER, $authUser);
$passOk = password_verify((string) $authPass, $passHash);
if (!($userOk & $passOk)) {
    usleep(300000); // zdražení online hádání hesla, bez ukládání IP
    header('WWW-Authenticate: Basic realm="Technicka bezpecnost - export dat"');
    respondHtml(401, 'Vyžadováno přihlášení', '<h1>Vyžadováno přihlášení</h1>'
        . '<p>Zadejte prosím přístupové údaje k exportu (viz README).</p>');
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
    $pdo = getPdo();

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

    respondHtml(200, 'Export odpovědí – Technická bezpečnost', '<h1>Odpovědi dotazníku</h1>'
        . '<div class="stat-row">'
        . '<div class="stat"><strong>' . $countAno . '</strong><span>má zájem (ANO)</span></div>'
        . '<div class="stat"><strong>' . $countNe . '</strong><span>nemá zájem (NE)</span></div>'
        . '<div class="stat"><strong>' . ($countAno + $countNe) . '</strong><span>odpovědí celkem</span></div>'
        . '</div>'
        . '<p>Poslední odpověď: ' . e(is_string($lastAt) ? pragueTime($lastAt) : 'zatím žádná') . '</p>'
        . '<p><a class="btn btn-primary" href="export.php?download=1">Stáhnout CSV pro Excel</a></p>'
        . '<h2>Zájemci</h2>'
        . '<div class="table-wrap"><table>'
        . '<thead><tr><th>Č.</th><th>Datum</th><th>Jméno</th><th>Příjmení</th><th>Profese</th><th>E-mail</th></tr></thead>'
        . '<tbody>' . $tableRows . '</tbody>'
        . '</table></div>'
        . '<p class="note">Odpovědi NE se ukládají anonymně, proto jsou jen v souhrnném počtu.'
        . ' Nezapomeňte: všechna data je potřeba smazat nejpozději 31.&nbsp;3.&nbsp;2027 (viz Zásady).</p>',
        'fallback-page admin-page');
} catch (Throwable $exception) {
    error_log('export.php: ' . $exception->getMessage());
    respondHtml(500, 'Chyba serveru', '<h1>Přehled se nepodařilo načíst</h1><p>Zkuste to prosím znovu.</p>');
}
