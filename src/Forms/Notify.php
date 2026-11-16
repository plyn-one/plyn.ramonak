<?php
declare(strict_types=1);

namespace Ramonak\Forms;

use Ramonak\Db;
use Ramonak\Sites;

/**
 * The job form.notify: sends one form entry by email to the addresses its form declares under "notify", with the
 * field labels in the language the visitor wrote in, and marks the entry sent. Reply-To is the visitor's address when
 * one of the fields holds one.
 */
final class Notify
{
    /** @param \Closure(string):?Mailer $mailer the mailer of a site, by its key; null when the installation has none */
    public function __construct(private Db $db, private Sites $sites, private \Closure $mailer) {}

    public function __invoke(array $job): string
    {
        $entry = $this->db->one('SELECT * FROM form_entry WHERE id = ?', [(int) $job['entry']]) ?? throw new \RuntimeException('no form entry ' . $job['entry']);
        if ($entry['state'] === 'sent') {
            return 'entry ' . $entry['id'] . ' was sent already';
        }
        $site = $this->sites->byId((int) $entry['site_id']);
        $form = (array) ($site->declaration['forms'][$entry['form']] ?? []);
        $to = array_values(array_filter((array) ($form['notify'] ?? []), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if ($to === []) {
            throw new \RuntimeException("the form {$entry['form']} of {$site->key} names no one to notify");
        }
        $mailer = ($this->mailer)($site->key) ?? throw new \RuntimeException('the installation has no mail configured');
        $lang = (string) $entry['lang'];
        $fields = (array) json_decode((string) $entry['fields'], true);
        [$subject, $body, $replyTo] = self::letter($site->name($lang), $form, $fields, $lang, $site->url((string) $entry['page']), (int) $entry['id'], (int) $entry['created_at']);
        try {
            $mailer->send($to, $subject, $body, $replyTo);
        } catch (\Throwable $e) {
            $this->db->exec("UPDATE form_entry SET state = 'failed' WHERE id = ?", [$entry['id']]);
            throw $e;
        }
        $this->db->exec("UPDATE form_entry SET state = 'sent' WHERE id = ?", [$entry['id']]);
        return 'entry ' . $entry['id'] . ' sent to ' . count($to);
    }

    /** @return array{string,string,?string} subject, body and the visitor's address to reply to */
    public static function letter(string $siteName, array $form, array $fields, string $lang, string $page, int $id, int $at): array
    {
        $t = fn ($v, string $fallback) => is_array($v) ? (string) ($v[$lang] ?? $v['en'] ?? reset($v)) : (is_string($v) ? $v : $fallback);
        $words = ['ru' => ['yes' => 'да', 'no' => 'нет', 'page' => 'Страница', 'received' => 'Получено', 'entry' => 'Запись'],
                  'be' => ['yes' => 'так', 'no' => 'не', 'page' => 'Старонка', 'received' => 'Атрымана', 'entry' => 'Запіс']][$lang]
            ?? ['yes' => 'yes', 'no' => 'no', 'page' => 'Page', 'received' => 'Received', 'entry' => 'Entry'];
        $lines = [];
        $who = null;
        $replyTo = null;
        foreach ((array) ($form['fields'] ?? []) as $key => $f) {
            $label = $t($f['label'] ?? null, (string) $key);
            $value = $fields[$key] ?? '';
            if (is_bool($value)) {
                $value = $value ? $words['yes'] : $words['no'];
            }
            $who ??= ($f['type'] ?? 'text') === 'text' && $value !== '' ? $value : null;
            if ($replyTo === null && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $replyTo = $value;
            }
            $lines[] = str_contains($value, "\n") ? $label . ":\n" . $value . "\n" : $label . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = $words['page'] . ': ' . $page;
        $lines[] = $words['received'] . ': ' . gmdate('Y-m-d H:i', $at) . ' UTC';
        $lines[] = $words['entry'] . ': #' . $id;
        $subject = $t($form['title'] ?? null, 'Form') . ($who !== null ? ': ' . mb_substr($who, 0, 60) : '') . ' | ' . $siteName;
        return [$subject, implode("\n", $lines) . "\n", $replyTo];
    }
}
