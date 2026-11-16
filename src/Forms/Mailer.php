<?php
declare(strict_types=1);

namespace Ramonak\Forms;

/**
 * Sends a plain-text letter. The installation's configuration chooses how, under "mail":
 * {"transport": "sendmail", "from": "forms@example.org", "from_name": "Example"} through the host's sendmail, or
 * {"transport": "smtp", "host": "smtp.example.org", "port": 465, "security": "ssl"|"starttls", "username": ..., "password": ...,
 * "from": ...} through a mail server. A site may have its own under "mail" => ["sites" => [<site key> => {...}]].
 */
abstract class Mailer
{
    /** @param list<string> $to */
    abstract public function send(array $to, string $subject, string $body, ?string $replyTo = null): void;

    public static function fromConfig(array $c): self
    {
        return match ((string) ($c['transport'] ?? 'sendmail')) {
            'smtp' => new SmtpMailer($c),
            'sendmail' => new SendmailMailer($c),
            default => throw new \RuntimeException('mail: no transport ' . $c['transport']),
        };
    }

    /** The letter with its headers; values cannot carry a header of their own. */
    public static function message(string $from, string $fromName, array $to, string $subject, string $body, ?string $replyTo): string
    {
        $clean = fn (string $v) => trim(str_replace(["\r", "\n"], ' ', $v));
        $word = fn (string $v) => preg_match('/^[\x20-\x7e]*$/', $v) ? $v : '=?UTF-8?B?' . base64_encode($v) . '?=';
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $word($clean($fromName)) . ' ' : '') . '<' . $clean($from) . '>',
            'To: ' . implode(', ', array_map($clean, $to)),
            'Subject: ' . $word($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $clean($replyTo);
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n")) . "\r\n";
    }

    protected static function from(array $c): array
    {
        $from = (string) ($c['from'] ?? '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('mail: the configuration names no sender (from)');
        }
        return [$from, (string) ($c['from_name'] ?? '')];
    }
}
