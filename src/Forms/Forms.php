<?php
declare(strict_types=1);

namespace Ramonak\Forms;

use Ramonak\Db;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Jobs\Queue;
use Ramonak\Site;

/**
 * POST /_ramonak/form/<name>: a form the site declares in site.json under "forms". The entry is checked against the
 * declaration, kept in the site's database and handed to the job form.notify, which sends it by email; the visitor is
 * sent back to the page with #<name>-sent or #<name>-error, so built pages stay the same for everyone and need no script.
 *
 * A declaration: {"fields": {"name": {"type": "text", "required": true, "max": 120, "label": {"en": "Name"}}, ...},
 * "notify": ["office@example.org"], "honeypot": "website", "title": {"en": "Contact form"}}. Types: text, textarea,
 * email, checkbox.
 */
final class Forms
{
    /** How many entries one address may send within WINDOW seconds. */
    public const LIMIT = 5;
    public const WINDOW = 600;
    private const MAX = ['text' => 200, 'email' => 254, 'textarea' => 5000, 'checkbox' => 1];

    public function __construct(private Db $db, private Queue $queue, private string $salt) {}

    public function handle(Site $site, Request $request, string $name): Response
    {
        $form = $site->declaration['forms'][$name] ?? null;
        if (!is_array($form) || !preg_match('/^[a-z0-9_-]{1,64}$/', $name)) {
            return Response::text('No such form', 404);
        }
        if ($request->method !== 'POST') {
            return new Response(405, '', ['Allow' => 'POST']);
        }
        if (!$this->sameSite($site, $request)) {
            return Response::text('A form of this site is sent from its own pages', 403);
        }
        parse_str($request->body, $data);
        $back = self::back($site, (string) ($data['_back'] ?? ''));
        $lang = $site->language($back);
        $to = fn (string $outcome) => self::seeOther($site->url($back) . '#' . $name . '-' . $outcome);

        $trap = (string) ($form['honeypot'] ?? '');
        if ($trap !== '' && trim((string) ($data[$trap] ?? '')) !== '') {
            return $to('sent'); // a robot filled the field people do not see: it is told it succeeded and nothing is kept
        }
        $fields = self::fields((array) ($form['fields'] ?? []), $data);
        if ($fields === null) {
            return $to('error');
        }
        $client = hash_hmac('sha256', $request->client !== '' ? $request->client : 'unknown', $this->salt);
        $recent = (int) $this->db->value('SELECT COUNT(*) FROM form_entry WHERE client = ? AND created_at > ?', [$client, $this->db->now() - self::WINDOW]);
        if ($recent >= self::LIMIT) {
            return $to('error');
        }
        $this->db->tx(function () use ($site, $name, $lang, $back, $fields, $client): void {
            $id = $this->db->insert('INSERT INTO form_entry (site_id, form, lang, page, fields, client, state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$site->id, $name, $lang, $back, json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $client, 'new', $this->db->now()]);
            $this->queue->push('form.notify', ['entry' => $id]);
        });
        return $to('sent');
    }

    /**
     * Forgets what a form keeps longer than its declaration allows ("keep_days" of a form in site.json): the entries older than
     * that are deleted, mailed or not. A form without keep_days keeps its entries until form.delete.
     * @return array<string,int> deleted entries by site and form
     */
    public static function expire(Db $db, \Ramonak\Sites $sites): array
    {
        $done = [];
        foreach ($sites->keys() as $key) {
            $site = $sites->byKey($key);
            foreach ((array) ($site?->declaration['forms'] ?? []) as $name => $form) {
                $days = (int) ($form['keep_days'] ?? 0);
                if ($site !== null && $days > 0) {
                    $n = $db->exec('DELETE FROM form_entry WHERE site_id = ? AND form = ? AND created_at < ?', [$site->id, (string) $name, $db->now() - $days * 86400]);
                    if ($n > 0) {
                        $done[$key . '/' . $name] = $n;
                    }
                }
            }
        }
        return $done;
    }

    /**
     * The values a declaration asks for, trimmed and checked; null when one is missing, too long or of the wrong form.
     * @return array<string,string|bool>|null
     */
    public static function fields(array $declared, array $data): ?array
    {
        $fields = [];
        foreach ($declared as $key => $f) {
            $type = (string) ($f['type'] ?? 'text');
            $raw = $data[$key] ?? null;
            if (is_array($raw)) {
                return null;
            }
            if ($type === 'checkbox') {
                $value = $raw !== null && $raw !== '';
                if (!empty($f['required']) && !$value) {
                    return null;
                }
                $fields[$key] = $value;
                continue;
            }
            $value = trim(str_replace("\r\n", "\n", (string) $raw));
            if ($type !== 'textarea') {
                $value = preg_replace('/\s+/u', ' ', $value) ?? '';
            }
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > (int) ($f['max'] ?? self::MAX[$type] ?? 200)) {
                return null;
            }
            if ($value === '' && !empty($f['required'])) {
                return null;
            }
            if ($value !== '' && $type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
            $fields[$key] = $value;
        }
        return $fields;
    }

    /** A form is sent from the site's own pages: Origin, else Referer, names one of its domains when a browser sends it. */
    private function sameSite(Site $site, Request $request): bool
    {
        $from = $request->header('origin') ?? $request->header('referer');
        if ($from === null || $from === '' || $from === 'null') {
            return $from !== 'null';
        }
        $host = strtolower((string) parse_url($from, PHP_URL_HOST));
        return isset($site->domains[$host]);
    }

    /** The page to return to: a path of this site, else the home of its default language. */
    private static function back(Site $site, string $back): string
    {
        return preg_match('~^/(?!/)[^\s#?\\\\]*$~', $back) ? $back : $site->home($site->defaultLanguage);
    }

    private static function seeOther(string $location): Response
    {
        return new Response(303, '', ['Location' => $location, 'Cache-Control' => 'no-store']);
    }
}
