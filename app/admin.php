<?php
declare(strict_types=1);

/**
 * Společný základ administrace: /admin/ (přehled registrací, index.php)
 * a /admin/export.csv (CSV, export.php). Přihlašuje se vlastním formulářem
 * jen heslem (bez uživatelského jména – správce je jediný); heslo je na
 * přání klienta při psaní viditelné. Přihlášení drží podepsaná cookie
 * (HMAC z hashe hesla, HttpOnly, SameSite=Strict, jen pro /admin/); změna
 * hesla i odhlášení všechna vydaná přihlášení zneplatní. Pro skripty
 * a curl funguje i HTTP Basic Auth s prázdným jménem. Bez nastaveného hesla
 * je administrace zamčená. Proti hádání hesla je globální brzda: po
 * AUTH_MAX_FAILS neúspěšných pokusech za AUTH_WINDOW_MIN minut se přihlášení
 * na tu dobu odmítá – bez ukládání IP adres.
 */

require __DIR__ . '/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');

const AUTH_MAX_FAILS = 10;
const AUTH_WINDOW_MIN = 15;

/** Hash hesla administrace (v dev režimu lze dodat proměnnou TB_EXPORT_HASH). */
function adminPassHash(): string
{
    $hash = EXPORT_PASS_HASH;
    if ($hash === '' && DEV_MODE) {
        $hash = getenv('TB_EXPORT_HASH') ?: '';
    }
    return $hash;
}

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
        error_log('admin auth_fail: ' . $exception->getMessage());
        return 0;
    }
}

const ADMIN_COOKIE = 'tb_admin';
const ADMIN_COOKIE_DAYS = 30;

/** Hodnota přihlašovací cookie: expirace, čas vydání a HMAC z hashe hesla. */
function adminCookieValue(int $expires, int $issued): string
{
    return $expires . '.' . $issued . '.' . hash_hmac('sha256', 'admin:' . $expires . ':' . $issued, adminPassHash());
}

/** Tabulka stavu administrace (zatím jen hranice pro zneplatnění cookies odhlášením). */
function ensureAdminState(?PDO $pdo): void
{
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    } catch (Throwable $exception) {
        error_log('admin_state: ' . $exception->getMessage());
    }
}

/** Cookie vydané před tímto časem už neplatí (nastavuje odhlášení). */
function cookieMinIssued(?PDO $pdo): int
{
    if ($pdo === null) {
        return 0;
    }
    try {
        return (int) $pdo->query("SELECT value FROM admin_state WHERE key = 'cookie_min_issued'")->fetchColumn();
    } catch (Throwable $exception) {
        error_log('admin_state read: ' . $exception->getMessage());
        return 0;
    }
}

function adminCookieValid(?PDO $pdo): bool
{
    $value = (string) ($_COOKIE[ADMIN_COOKIE] ?? '');
    if (!preg_match('/^(\d{1,12})\.(\d{1,12})\.([0-9a-f]{64})$/', $value, $m)) {
        return false;
    }
    $expires = (int) $m[1];
    $issued = (int) $m[2];
    return $expires > time()
        && $issued > cookieMinIssued($pdo)
        && hash_equals(adminCookieValue($expires, $issued), $value);
}

/** Nastaví (expires > 0) nebo smaže (null) přihlašovací cookie. */
function setAdminCookie(?int $expires, int $issued = 0): void
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(ADMIN_COOKIE, $expires === null ? '' : adminCookieValue($expires, $issued), [
        'expires' => $expires ?? time() - 86400,
        'path' => '/admin/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** Přihlašovací stránka (401 bez WWW-Authenticate – prohlížeč nesmí otevřít vlastní dialog). */
function loginPage(string $error): void
{
    respondHtml(401, 'Přihlášení – Technická bezpečnost',
        '<p class="admin-eyebrow">Technická bezpečnost · administrace</p>'
        . '<h1>Přihlášení</h1>'
        . ($error !== '' ? '<p class="login-error" role="alert">' . e($error) . '</p>' : '')
        . '<form method="post" action="index.php" class="login-form">'
        . '<label for="heslo">Heslo</label>'
        . '<input type="text" id="heslo" name="heslo" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus>'
        . '<button type="submit" class="btn btn-primary">Přihlásit</button>'
        . '</form>',
        'fallback-page admin-page login-page');
}

/**
 * Vynutí přihlášení (503 bez nastaveného hesla, 429 při brzdě, jinak
 * přihlašovací formulář) a vrátí připojení k databázi. Zpracuje i POST
 * z přihlašovacího formuláře a odhlášení.
 */
function requireAdmin(): PDO
{
    $passHash = adminPassHash();
    if ($passHash === '') {
        respondHtml(503, 'Administrace není nastavena', '<h1>Administrace není nastavena</h1>'
            . '<p>V souboru <code>app/config.php</code> vyplňte konstantu <code>EXPORT_PASS_HASH</code>'
            . ' podle návodu v README. Do té doby je administrace z bezpečnostních důvodů nedostupná.</p>');
    }

    try {
        $pdo = getPdo();
    } catch (Throwable $exception) {
        error_log('admin getPdo: ' . $exception->getMessage());
        $pdo = null;
    }

    ensureAdminState($pdo);

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost && isset($_POST['odhlasit'])) {
        // Odhlášení zneplatní všechny dosud vydané cookies (správce je jediný),
        // ne jen tu v prohlížeči – ukradená hodnota tak přestane platit.
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO admin_state (key, value) VALUES ('cookie_min_issued', :t)");
                $stmt->execute([':t' => (string) time()]);
            } catch (Throwable $exception) {
                error_log('admin_state write: ' . $exception->getMessage());
            }
        }
        setAdminCookie(null);
        header('Location: ./', true, 303);
        exit;
    }

    if (adminCookieValid($pdo)) {
        return $pdo ?? getPdo();
    }

    $braked = recentAuthFails($pdo) >= AUTH_MAX_FAILS;

    // HTTP Basic Auth (curl, skripty) – jméno se ignoruje, stačí heslo.
    [$authUser, $basicPass] = basicAuthCredentials();
    $basicTried = $basicPass !== null && $basicPass !== '';
    // password_verify má z konstrukce stejnou dobu odezvy pro každý vstup.
    if ($basicTried && !$braked && password_verify((string) $basicPass, $passHash)) {
        return $pdo ?? getPdo();
    }

    if ($braked) {
        header('Retry-After: ' . (AUTH_WINDOW_MIN * 60));
        respondHtml(429, 'Příliš mnoho pokusů', '<h1>Příliš mnoho neúspěšných pokusů</h1>'
            . '<p>Přihlášení do administrace je na ' . AUTH_WINDOW_MIN . ' minut pozastaveno. Zkuste to prosím později.</p>');
    }

    $error = '';
    $formPass = $isPost && isset($_POST['heslo']) ? (string) $_POST['heslo'] : null;
    if ($formPass !== null && $formPass !== '' && password_verify($formPass, $passHash)) {
        // Čas vydání musí být ostře za hranicí z posledního odhlášení, i v téže sekundě.
        setAdminCookie(time() + ADMIN_COOKIE_DAYS * 86400, max(time(), cookieMinIssued($pdo) + 1));
        header('Location: ./', true, 303);
        exit;
    }
    if (($formPass !== null && $formPass !== '') || $basicTried) {
        // Skutečný (ne prázdný) pokus o heslo se počítá do brzdy; bez IP adresy.
        if ($pdo !== null) {
            try {
                $pdo->exec('INSERT INTO auth_fail DEFAULT VALUES');
            } catch (Throwable $exception) {
                error_log('admin auth_fail insert: ' . $exception->getMessage());
            }
        }
        usleep(300000); // zdražení online hádání hesla
        $error = 'Nesprávné heslo. Zkuste to prosím znovu.';
    }
    loginPage($error);
}
