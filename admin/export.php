<?php
declare(strict_types=1);

/**
 * CSV export registrací (adresa /admin/export.csv – přepis v public/.htaccess;
 * přímo funguje i /admin/export.php). Soubor s BOM a středníky se otevře
 * v českém Excelu na dvojklik. Přihlášení řeší app/admin.php, přehled
 * je na /admin/ (index.php).
 */

foreach ([dirname(__DIR__, 2) . '/app/admin.php', dirname(__DIR__) . '/app/admin.php'] as $adminPath) {
    if (is_file($adminPath)) {
        require $adminPath;
        break;
    }
}
if (!function_exists('requireAdmin')) {
    http_response_code(500);
    // Záměrně bez diakritiky: hlavička s kódováním v tuto chvíli není nastavena.
    exit('Chybi app/admin.php – zkontrolujte rozlozeni souboru dle README.');
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
    downloadCsv(requireAdmin());
} catch (Throwable $exception) {
    error_log('admin/export.php: ' . $exception->getMessage());
    respondHtml(500, 'Chyba serveru', '<h1>Export se nepodařilo sestavit</h1><p>Zkuste to prosím znovu.</p>',
        'fallback-page admin-page');
}
