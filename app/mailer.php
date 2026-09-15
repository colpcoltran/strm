<?php
declare(strict_types=1);

/**
 * Minimální SMTP klient bez závislostí pro notifikace o zájemcích.
 * Zprávu odesílá přihlášeně ze skutečné schránky domény (Hostinger:
 * smtp.hostinger.com, 465 SSL nebo 587 STARTTLS), takže ji poštovní server
 * podepíše DKIM a SPF i DMARC projdou. PHP mail() z webového serveru tohle
 * nesplní – obálkový odesílatel je systémový účet hostingu, DKIM chybí
 * a filtr příjemce zprávu odloží do spamu (viz README).
 *
 * Do logů se nikdy nezapisuje heslo ani odesílané příkazy AUTH; chybové
 * hlášky obsahují jen odpověď serveru.
 */
final class SmtpMailer
{
    /** @var resource|null */
    private $socket = null;
    private string $lastError = '';

    public function __construct(
        private string $host,
        private int $port,
        private string $secure,   // ssl | tls | none
        private string $user,
        private string $pass,
        private int $timeout = 10
    ) {
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** Odešle hotovou zprávu (hlavičky + prázdný řádek + tělo). */
    public function send(string $envelopeFrom, string $recipient, string $rawMessage): bool
    {
        try {
            $this->connect();
            $this->expect([220], 'uvítání');
            $this->command('EHLO ' . $this->ehloName(), [250], 'EHLO');
            if ($this->secure === 'tls') {
                $this->command('STARTTLS', [220], 'STARTTLS');
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS: šifrování se nepodařilo vyjednat');
                }
                $this->command('EHLO ' . $this->ehloName(), [250], 'EHLO po STARTTLS');
            }
            if ($this->user !== '') {
                $this->command('AUTH LOGIN', [334], 'AUTH');
                $this->command(base64_encode($this->user), [334], 'AUTH (jméno)');
                $this->command(base64_encode($this->pass), [235], 'AUTH (heslo)');
            }
            $this->command('MAIL FROM:<' . $envelopeFrom . '>', [250], 'MAIL FROM');
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO');
            $this->command('DATA', [354], 'DATA');
            $data = (string) preg_replace('/(?<!\r)\n/', "\r\n", $rawMessage);
            $data = (string) preg_replace('/^\./m', '..', $data); // dot-stuffing (RFC 5321)
            $this->write(rtrim($data, "\r\n") . "\r\n.\r\n");
            $this->expect([250], 'odeslání dat');
            $this->command('QUIT', [221], 'QUIT');
            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            return false;
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $scheme = $this->secure === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException('připojení k ' . $this->host . ':' . $this->port . ' selhalo: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    /** Pošle příkaz a ověří kód odpovědi; text příkazu se do chyby nedostane. */
    private function command(string $line, array $okCodes, string $label): void
    {
        $this->write($line . "\r\n");
        $this->expect($okCodes, $label);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || @fwrite($this->socket, $data) === false) {
            throw new RuntimeException('zápis do spojení selhal');
        }
    }

    /** Přečte (i víceřádkovou) odpověď a zkontroluje kód. */
    private function expect(array $okCodes, string $label): void
    {
        $last = '';
        while (true) {
            $line = $this->socket === null ? false : fgets($this->socket, 2048);
            if ($line === false) {
                $meta = $this->socket === null ? ['timed_out' => false] : stream_get_meta_data($this->socket);
                throw new RuntimeException($label . ': ' . (($meta['timed_out'] ?? false) ? 'vypršel čas' : 'server ukončil spojení'));
            }
            $last = rtrim($line, "\r\n");
            // Poslední řádek víceřádkové odpovědi má za kódem mezeru ("250 ok"), pokračování pomlčku ("250-…").
            if (strlen($last) < 4 || $last[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException($label . ': server odpověděl „' . $last . '“');
        }
    }

    private function ehloName(): string
    {
        $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = (string) preg_replace('/[^a-z0-9.-]/', '', $host);
        return $host !== '' ? $host : 'localhost';
    }
}
