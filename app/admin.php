<?php
declare(strict_types=1);

/**
 * Společný základ administrace: /admin/ (přehled registrací, index.php)
 * a /admin/export.csv (CSV, export.php). Přístup hlídá HTTP Basic Auth
 * řešená v PHP – funguje na Apache, LiteSpeed, nginx i vestavěném PHP
 * serveru a bez nastaveného hesla je zamčená. Správce je jediný, proto se
 * ověřuje pouze heslo (jméno v dialogu prohlížeče může zůstat prázdné).
 * Proti hádání hesla je globální brzda: po AUTH_MAX_FAILS neúspěšných
 * pokusech za AUTH_WINDOW_MIN minut se přihlášení na tu dobu odmítá – bez
 * ukládání IP adres. Mazání záznamů chrání HMAC podpis z hashe hesla.
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

/**
 * Vynutí přihlášení (503 bez nastaveného hesla, 429 při brzdě, 401 bez
 * platného hesla) a vrátí připojení k databázi.
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

    if (recentAuthFails($pdo) >= AUTH_MAX_FAILS) {
        header('Retry-After: ' . (AUTH_WINDOW_MIN * 60));
        respondHtml(429, 'Příliš mnoho pokusů', '<h1>Příliš mnoho neúspěšných pokusů</h1>'
            . '<p>Přihlášení do administrace je na ' . AUTH_WINDOW_MIN . ' minut pozastaveno. Zkuste to prosím později.</p>');
    }

    [$authUser, $authPass] = basicAuthCredentials();
    // Jediný správce – ověřuje se pouze heslo, jméno v dialogu je libovolné.
    // password_verify má z konstrukce stejnou dobu odezvy pro každý vstup.
    if (!password_verify((string) $authPass, $passHash)) {
        if ($authPass !== null && $authPass !== '' && $pdo !== null) {
            // Skutečný (ne prázdný) pokus o heslo se počítá do brzdy; bez IP adresy.
            try {
                $pdo->exec('INSERT INTO auth_fail DEFAULT VALUES');
            } catch (Throwable $exception) {
                error_log('admin auth_fail insert: ' . $exception->getMessage());
            }
        }
        usleep(300000); // zdražení online hádání hesla
        header('WWW-Authenticate: Basic realm="Technicka bezpecnost - administrace (staci heslo)"');
        respondHtml(401, 'Vyžadováno přihlášení', '<h1>Vyžadováno přihlášení</h1>'
            . '<p>Zadejte prosím heslo k administraci (jméno může zůstat prázdné). Viz README.</p>');
    }

    return $pdo ?? getPdo();
}

/**
 * Podpis pro mazání – administrace nemá sezení ani cookies, ochranou proti
 * CSRF je HMAC z hashe hesla (ten útočník nezná) přes identifikátor mazaného.
 */
function deleteToken(string $subject): string
{
    return hash_hmac('sha256', 'delete:' . $subject, adminPassHash());
}

/** Požadavek přišel z tohoto webu (druhá vrstva ochrany proti CSRF). */
function sameOriginRequest(): bool
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $ownHost = strtolower((string) explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        return $originHost !== '' && $originHost === $ownHost;
    }
    return $origin !== 'null';
}
