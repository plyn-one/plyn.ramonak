<?php
declare(strict_types=1);

namespace Ramonak\Content;

use Ramonak\Db;
use Ramonak\OperationError;
use Ramonak\Site;

/**
 * Paths of a site. A path is unique per site and leads to an item in a language, or redirects (301, 302) or is gone (410).
 * Moving an item to a new path keeps the old one as a 301 to the new: an address once given keeps answering.
 */
final class Routes
{
    public function __construct(private Db $db) {}

    public static function normalize(string $path): string
    {
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
        if (!preg_match('#^/[\p{L}\p{N}._~!$&\'()*+,;=:@/%-]*$#u', $path) || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new OperationError('path_invalid', "Not a path: {$path}");
        }
        return $path;
    }

    /** @return array<string,mixed>|null */
    public function resolve(Site $site, string $path): ?array
    {
        return $this->db->one('SELECT r.id, r.lang, r.item_id, r.status, r.target, i.kind, i.item_key FROM route r LEFT JOIN item i ON i.id = r.item_id
            WHERE r.site_id = ? AND r.path = ?', [$site->id, $path]);
    }

    /** The path an item answers at in a language, if it has one. */
    public function pathOf(int $itemId, string $lang): ?string
    {
        $path = $this->db->value('SELECT path FROM route WHERE item_id = ? AND lang = ? AND status = 200', [$itemId, $lang]);
        return $path === null ? null : (string) $path;
    }

    /** @return array{path:string, moved_from:?string} */
    public function set(Site $site, int $itemId, string $lang, string $path): array
    {
        $path = self::normalize($path);
        $prefix = $site->languages[$lang]['prefix'] ?? throw new OperationError('language_unknown', "The site has no language {$lang}");
        if ($prefix !== '' && $path !== '/' . $prefix && !str_starts_with($path, '/' . $prefix . '/')) {
            throw new OperationError('path_language_mismatch', "A path in {$lang} begins with /{$prefix}/");
        }
        return $this->db->tx(function () use ($site, $itemId, $lang, $path): array {
            $taken = $this->resolve($site, $path);
            if ($taken !== null && $taken['status'] == 200 && (int) $taken['item_id'] !== $itemId) {
                throw new OperationError('path_taken', "{$path} leads to item {$taken['item_key']}", 409);
            }
            $old = $this->pathOf($itemId, $lang);
            if ($old === $path) {
                return ['path' => $path, 'moved_from' => null];
            }
            if ($taken !== null) {
                $this->db->exec('DELETE FROM route WHERE id = ?', [$taken['id']]);
            }
            if ($old !== null) {
                $this->db->exec('UPDATE route SET item_id = NULL, status = 301, target = ? WHERE site_id = ? AND path = ?', [$path, $site->id, $old]);
                // a chain of moves stays one hop: every older address now leads straight to the newest
                $this->db->exec('UPDATE route SET target = ? WHERE site_id = ? AND status IN (301, 302) AND target = ?', [$path, $site->id, $old]);
            }
            $this->db->exec('INSERT INTO route (site_id, path, lang, item_id, status, target, created_at) VALUES (?, ?, ?, ?, 200, NULL, ?)',
                [$site->id, $path, $lang, $itemId, $this->db->now()]);
            return ['path' => $path, 'moved_from' => $old];
        });
    }

    public function redirect(Site $site, string $path, ?string $target, int $status = 301, ?string $lang = null): void
    {
        $path = self::normalize($path);
        if (!in_array($status, [301, 302, 410], true)) {
            throw new OperationError('status_invalid', 'A redirect is 301, 302 or 410');
        }
        if ($status !== 410 && ($target === null || !(str_starts_with($target, '/') || preg_match('#^https://[^\s]+$#', $target)))) {
            throw new OperationError('target_invalid', 'A redirect leads to a path of the site or an https URL');
        }
        $existing = $this->resolve($site, $path);
        if ($existing !== null && $existing['status'] == 200) {
            throw new OperationError('path_taken', "{$path} leads to item {$existing['item_key']}; move the item instead", 409);
        }
        $lang ??= $site->language($path);
        $this->db->tx(function () use ($site, $path, $target, $status, $lang, $existing): void {
            if ($existing !== null) {
                $this->db->exec('UPDATE route SET status = ?, target = ?, lang = ? WHERE id = ?', [$status, $status === 410 ? null : $target, $lang, $existing['id']]);
            } else {
                $this->db->exec('INSERT INTO route (site_id, path, lang, item_id, status, target, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?)',
                    [$site->id, $path, $lang, $status, $status === 410 ? null : $target, $this->db->now()]);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function all(Site $site): array
    {
        return $this->db->all('SELECT r.path, r.lang, r.status, r.target, i.item_key FROM route r LEFT JOIN item i ON i.id = r.item_id
            WHERE r.site_id = ? ORDER BY r.path', [$site->id]);
    }
}
