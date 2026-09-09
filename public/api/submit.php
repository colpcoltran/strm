<?php
declare(strict_types=1);

/**
 * Endpoint registrace: uloží zájemce (answer=ANO) a odešle e-mailové
 * upozornění klientovi. Volba „nemám zájem“ (NE) byla na přání klienta
 * z webu odstraněna – endpoint ji odmítá (422), aby se do statistiky
 * nedostaly řádky, které z webu nikdo nemohl odeslat. Odpovídá JSON (fetch z app.js)
 * nebo samostatnou HTML stránkou (průchod bez JavaScriptu).
 */

// Podporovaná rozložení: app/ vedle public/ (doporučeno),
// nebo app/ uvnitř document rootu (nouzový režim, viz README).
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

$backLink = '<p><a href="../#registrace">&larr; Zpět na formulář</a></p>';
$homeLink = '<p><a href="../">&larr; Zpět na stránku</a></p>';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    if (clientWantsJson()) {
        respondJson(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    respondHtml(405, 'Nepovolený požadavek', '<h1>Nepovolený požadavek</h1>'
        . '<p>Formulář je potřeba odeslat ze stránky projektu.</p>' . $backLink);
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    if (clientWantsJson()) {
        respondJson(413, ['ok' => false, 'error' => 'payload_too_large']);
    }
    respondHtml(413, 'Příliš velký požadavek', '<h1>Příliš velký požadavek</h1>' . $backLink);
}

$answer = is_string($_POST['answer'] ?? null) ? $_POST['answer'] : '';

// Honeypot: skryté pole musí v požadavku být (prohlížeč prázdné textové
// pole odesílá vždy) a musí být prázdné. Bot, který pole vynechá nebo
// vyplní, dostane „úspěch“ a nic se neuloží.
$honeypot = $_POST['kontrola'] ?? null;
if (!is_string($honeypot) || cleanText($honeypot) !== '') {
    if (clientWantsJson()) {
        respondJson(200, ['ok' => true, 'answer' => 'ANO']);
    }
    respondHtml(200, 'Děkujeme', '<h1>Děkujeme</h1><p>Vaše registrace byla přijata.</p>' . $homeLink);
}

if ($answer !== 'ANO') {
    $message = 'Neplatný požadavek – odešlete prosím formulář ze stránky projektu.';
    if (clientWantsJson()) {
        respondJson(422, ['ok' => false, 'errors' => ['answer' => $message]]);
    }
    respondHtml(422, 'Zkontrolujte formulář', '<h1>Zkontrolujte prosím formulář</h1><p>'
        . e($message) . '</p>' . $backLink);
}

try {
    $pdo = getPdo();

    // Globální brzda proti floodu – záměrně bez ukládání IP adres (GDPR minimalizace).
    $recent = (int) $pdo->query(
        "SELECT COUNT(*) FROM responses WHERE created_at > datetime('now', '-60 seconds')"
    )->fetchColumn();
    if ($recent >= 20) {
        $message = 'Příliš mnoho požadavků, zkuste to prosím za chvíli.';
        if (clientWantsJson()) {
            respondJson(429, ['ok' => false, 'error' => 'rate_limited', 'message' => $message]);
        }
        respondHtml(429, 'Zkuste to za chvíli', '<h1>' . e($message) . '</h1>' . $backLink);
    }

    // Všechna pole povinná.
    $jmeno    = cleanText($_POST['jmeno'] ?? '');
    $prijmeni = cleanText($_POST['prijmeni'] ?? '');
    $profese  = cleanText($_POST['profese'] ?? '');
    $email    = mb_strtolower(cleanText($_POST['email'] ?? ''));

    $errors = [];
    foreach (['jmeno' => 'Vyplňte prosím jméno.', 'prijmeni' => 'Vyplňte prosím příjmení.', 'profese' => 'Vyplňte prosím profesi či oblast zájmu.'] as $field => $emptyMessage) {
        if ($$field === '') {
            $errors[$field] = $emptyMessage;
        } elseif (mb_strlen($$field) > 100) {
            $errors[$field] = 'Zadaný text je příliš dlouhý.';
        }
    }
    if ($email === '') {
        $errors['email'] = 'Zadejte prosím svou e-mailovou adresu.';
    } elseif (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Zkontrolujte prosím formát e-mailové adresy (např. jmeno@firma.cz).';
    }

    if ($errors !== []) {
        if (clientWantsJson()) {
            respondJson(422, ['ok' => false, 'errors' => $errors]);
        }
        $list = '';
        foreach ($errors as $message) {
            $list .= '<li>' . e($message) . '</li>';
        }
        respondHtml(422, 'Zkontrolujte formulář', '<h1>Zkontrolujte prosím formulář</h1><ul>'
            . $list . '</ul>' . $backLink);
    }

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO responses (answer, jmeno, prijmeni, profese, email) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute(['ANO', $jmeno, $prijmeni, $profese, $email]);

    // Duplicitní e-mail = tichý úspěch: bez druhého řádku i druhé notifikace.
    if ($insert->rowCount() > 0) {
        sendInterestNotification((int) $pdo->lastInsertId(), $jmeno, $prijmeni, $profese, $email);
    }

    if (clientWantsJson()) {
        respondJson(200, ['ok' => true, 'answer' => 'ANO']);
    }
    respondHtml(200, 'Registrace přijata', '<h1>Registrace přijata</h1>'
        . '<p>Děkujeme za registraci, budete informováni o vývoji tohoto projektu nejpozději'
        . ' do konce listopadu 2026.</p>' . $homeLink);
} catch (Throwable $exception) {
    error_log('submit.php: ' . $exception->getMessage());
    if (clientWantsJson()) {
        respondJson(500, ['ok' => false, 'error' => 'server']);
    }
    respondHtml(500, 'Chyba serveru', '<h1>Odeslání se nezdařilo</h1>'
        . '<p>Zkuste to prosím za chvíli znovu.</p>' . $backLink);
}
