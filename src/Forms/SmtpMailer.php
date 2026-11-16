<?php
declare(strict_types=1);

namespace Ramonak\Forms;

/** Sends through a mail server over SSL or STARTTLS, with AUTH LOGIN when a username is given. */
final class SmtpMailer extends Mailer
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private array $c) {}

    public function send(array $to, string $subject, string $body, ?string $replyTo = null): void
    {
        [$from, $name] = self::from($this->c);
        $host = (string) ($this->c['host'] ?? throw new \RuntimeException('mail: smtp needs a host'));
        $security = (string) ($this->c['security'] ?? 'ssl');
        $port = (int) ($this->c['port'] ?? ($security === 'ssl' ? 465 : 587));
        $this->socket = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, 20)
            ?: throw new \RuntimeException("mail: {$host}:{$port} does not answer: {$error}");
        stream_set_timeout($this->socket, 30);
        try {
            $this->expect(220);
            $me = gethostname() ?: 'localhost';
            $this->say('EHLO ' . $me, 250);
            if ($security === 'starttls') {
                $this->say('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('mail: STARTTLS failed');
                }
                $this->say('EHLO ' . $me, 250);
            }
            if (isset($this->c['username'])) {
                $this->say('AUTH LOGIN', 334);
                $this->say(base64_encode((string) $this->c['username']), 334);
                $this->say(base64_encode((string) ($this->c['password'] ?? '')), 235, secret: true);
            }
            $this->say('MAIL FROM:<' . $from . '>', 250);
            foreach ($to as $address) {
                $this->say('RCPT TO:<' . $address . '>', 250);
            }
            $this->say('DATA', 354);
            $message = preg_replace('/^\./m', '..', self::message($from, $name, $to, $subject, $body, $replyTo));
            $this->say($message . '.', 250);
            $this->say('QUIT', 221);
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function say(string $line, int $code, bool $secret = false): void
    {
        fwrite($this->socket, $line . "\r\n");
        $this->expect($code, $secret ? '(credentials)' : strtok($line, "\r\n"));
    }

    private function expect(int $code, string $after = 'connect'): void
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new \RuntimeException('mail: after ' . $after . ' the server said ' . trim($reply ?: '(nothing)'));
        }
    }
}
