<?php
declare(strict_types=1);

namespace Ramonak\Forms;

/** Sends through the host's sendmail, with the sender as the envelope sender. */
final class SendmailMailer extends Mailer
{
    public function __construct(private array $c) {}

    public function send(array $to, string $subject, string $body, ?string $replyTo = null): void
    {
        [$from, $name] = self::from($this->c);
        $command = (string) ($this->c['command'] ?? '/usr/sbin/sendmail -t -i') . ' -f ' . escapeshellarg($from);
        $pipe = popen($command, 'w') ?: throw new \RuntimeException('mail: sendmail does not start');
        fwrite($pipe, self::message($from, $name, $to, $subject, $body, $replyTo));
        $status = pclose($pipe);
        if ($status !== 0) {
            throw new \RuntimeException('mail: sendmail ended with ' . $status);
        }
    }
}
