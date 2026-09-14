<?php
declare(strict_types=1);

/**
 * Administrace – přehled registrací (adresa /admin/). Statistiky, registrace
 * po týdnech, vyhledávání a tabulka zájemců; CSV export je na
 * /admin/export.csv. Přihlášení a brzdu řeší app/admin.php. Stránka je jen
 * ke čtení a nepoužívá JavaScript (CSP webu zakazuje inline skripty).
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

/** Počet registrací od zadaného času (UTC, formát SQLite). */
function countSince(PDO $pdo, string $sinceUtc): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM responses WHERE answer = 'ANO' AND created_at >= :since");
    $stmt->execute([':since' => $sinceUtc]);
    return (int) $stmt->fetchColumn();
}

/**
 * Posledních 8 týdnů (pondělí–neděle podle Europe/Prague) s počty registrací.
 * @return array<string, array{label: string, count: int}>
 */
function weeklySeries(PDO $pdo): array
{
    $prague = new DateTimeZone('Europe/Prague');
    $utc = new DateTimeZone('UTC');
    $thisMonday = new DateTimeImmutable('monday this week', $prague);
    $weeks = [];
    for ($i = 7; $i >= 0; $i--) {
        $start = $thisMonday->modify('-' . $i . ' weeks');
        $weeks[$start->format('Y-m-d')] = [
            'label' => $start->format('j. n.') . '–' . $start->modify('+6 days')->format('j. n.'),
            'count' => 0,
        ];
    }
    $stmt = $pdo->prepare("SELECT created_at FROM responses WHERE answer = 'ANO' AND created_at >= :since");
    $stmt->execute([':since' => $thisMonday->modify('-7 weeks')->setTimezone($utc)->format('Y-m-d H:i:s')]);
    foreach ($stmt as $row) {
        $local = (new DateTimeImmutable((string) $row['created_at'], $utc))->setTimezone($prague);
        $key = $local->modify('monday this week')->format('Y-m-d');
        if (isset($weeks[$key])) {
            $weeks[$key]['count']++;
        }
    }
    return $weeks;
}

function renderDashboard(PDO $pdo): void
{
    $prague = new DateTimeZone('Europe/Prague');
    $utc = new DateTimeZone('UTC');

    $countAno = 0;
    $countNe = 0;
    foreach ($pdo->query('SELECT answer, COUNT(*) AS pocet FROM responses GROUP BY answer') as $row) {
        if ($row['answer'] === 'ANO') {
            $countAno = (int) $row['pocet'];
        } else {
            $countNe = (int) $row['pocet'];
        }
    }
    $todayUtc = (new DateTimeImmutable('today', $prague))->setTimezone($utc)->format('Y-m-d H:i:s');
    $countToday = countSince($pdo, $todayUtc);
    $count7 = countSince($pdo, gmdate('Y-m-d H:i:s', time() - 7 * 86400));
    $count30 = countSince($pdo, gmdate('Y-m-d H:i:s', time() - 30 * 86400));
    $lastAt = $pdo->query("SELECT MAX(created_at) FROM responses WHERE answer = 'ANO'")->fetchColumn();

    // Registrace po týdnech – sloupce jako SVG (bez inline stylů, CSP)
    $weeks = weeklySeries($pdo);
    $max = max(1, max(array_column($weeks, 'count')));
    $weekRows = '';
    foreach ($weeks as $week) {
        $width = $week['count'] > 0 ? max(4, (int) round(240 * $week['count'] / $max)) : 0;
        $weekRows .= '<tr><th scope="row">' . e($week['label']) . '</th>'
            . '<td class="bar"><svg width="240" height="14" viewBox="0 0 240 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<rect x="0" y="0" width="' . $width . '" height="14" rx="3"></rect></svg></td>'
            . '<td class="num">' . $week['count'] . '</td></tr>';
    }

    // Vyhledávání (GET, bez JavaScriptu)
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    $where = "answer = 'ANO'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (jmeno LIKE :q1 ESCAPE '\\' OR prijmeni LIKE :q2 ESCAPE '\\'"
            . " OR profese LIKE :q3 ESCAPE '\\' OR email LIKE :q4 ESCAPE '\\')";
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
    }
    $stmt = $pdo->prepare(
        "SELECT id, created_at, jmeno, prijmeni, profese, email FROM responses WHERE $where ORDER BY id DESC"
    );
    $stmt->execute($params);
    $tableRows = '';
    $shown = 0;
    foreach ($stmt as $row) {
        $shown++;
        $email = (string) $row['email'];
        $tableRows .= '<tr>'
            . '<td>' . (int) $row['id'] . '</td>'
            . '<td>' . e(pragueTime($row['created_at'])) . '</td>'
            . '<td>' . e((string) $row['jmeno']) . '</td>'
            . '<td>' . e((string) $row['prijmeni']) . '</td>'
            . '<td>' . e((string) $row['profese']) . '</td>'
            . '<td class="email"><a href="mailto:' . e($email) . '">' . e($email) . '</a></td>'
            . '</tr>';
    }
    if ($tableRows === '') {
        $tableRows = '<tr><td colspan="6">' . ($q !== '' ? 'Hledání nic nenašlo.' : 'Zatím žádní zájemci.') . '</td></tr>';
    }

    $html = '<header class="admin-head"><div>'
        . '<p class="admin-eyebrow">Technická bezpečnost · administrace</p>'
        . '<h1>Přehled registrací</h1></div>'
        . '<div class="admin-tools">'
        . '<a class="btn btn-primary" href="export.csv">Stáhnout CSV pro Excel</a>'
        . '<a class="btn btn-ghost" href="../">Otevřít web</a>'
        . '<form method="post" action="index.php" class="logout-form"><button type="submit" name="odhlasit" value="1" class="btn btn-ghost">Odhlásit</button></form>'
        . '</div></header>'
        . '<div class="stat-row">'
        . '<div class="stat"><strong>' . $countAno . '</strong><span>zájemců celkem</span></div>'
        . '<div class="stat"><strong>' . $countToday . '</strong><span>dnes</span></div>'
        . '<div class="stat"><strong>' . $count7 . '</strong><span>posledních 7 dní</span></div>'
        . '<div class="stat"><strong>' . $count30 . '</strong><span>posledních 30 dní</span></div>'
        . ($countNe > 0 ? '<div class="stat"><strong>' . $countNe . '</strong><span>nemá zájem (NE)</span></div>' : '')
        . '</div>'
        . '<p class="admin-meta">Poslední registrace: ' . e(is_string($lastAt) ? pragueTime($lastAt) : 'zatím žádná') . '</p>'
        . '<section class="admin-section"><h2>Registrace po týdnech</h2>'
        . '<table class="weeks"><tbody>' . $weekRows . '</tbody></table></section>'
        . '<section class="admin-section"><div class="admin-tablehead">'
        . '<h2>Zájemci <span class="count">(' . $shown . ($q !== '' ? ' z ' . $countAno : '') . ')</span></h2>'
        . '<form method="get" action="./" class="admin-search" role="search">'
        . '<input type="search" name="q" value="' . e($q) . '" placeholder="Jméno, e-mail nebo profese" aria-label="Hledat v registracích" maxlength="100">'
        . '<button type="submit" class="btn btn-ghost">Hledat</button>'
        . ($q !== '' ? '<a class="btn btn-ghost" href="./">Zrušit filtr</a>' : '')
        . '</form></div>'
        . '<div class="table-wrap"><table>'
        . '<thead><tr><th scope="col">Č.</th><th scope="col">Datum</th><th scope="col">Jméno</th><th scope="col">Příjmení</th>'
        . '<th scope="col">Profese / oblast zájmu</th><th scope="col">E-mail</th></tr></thead>'
        . '<tbody>' . $tableRows . '</tbody>'
        . '</table></div></section>';

    respondHtml(200, 'Přehled registrací – Technická bezpečnost', $html, 'fallback-page admin-page');
}

try {
    renderDashboard(requireAdmin());
} catch (Throwable $exception) {
    error_log('admin/index.php: ' . $exception->getMessage());
    respondHtml(500, 'Chyba serveru', '<h1>Přehled se nepodařilo načíst</h1><p>Zkuste to prosím znovu.</p>',
        'fallback-page admin-page');
}
