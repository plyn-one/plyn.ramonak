<?php
declare(strict_types=1);

namespace Ramonak\Render;

use Ramonak\Db;

/**
 * Built pages: the HTML of a published page written as a file the web server sends without PHP
 * (public/_cache/<host><path>/index.html, see public/.htaccess). A file is removed when anything it was made from changes;
 * the next request builds it again. Writes are atomic: a reader sees the old file or the new, never a part.
 */
final class PageCache
{
    public function __construct(private Db $db, private string $root) {}

    public function file(string $host, string $path): string
    {
        return $this->root . '/' . $host . ($path === '/' ? '' : $path) . '/index.html';
    }

    /** @param list<string> $deps */
    public function store(int $siteId, string $host, string $path, string $html, array $deps): void
    {
        $file = $this->file($host, $path);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $dir . '/.index.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $html) === false) {
            return;
        }
        @chmod($tmp, 0644);
        $this->db->tx(function () use ($siteId, $host, $path, $file, $deps): void {
            $this->db->exec('DELETE FROM built_page_dep WHERE host = ? AND path = ?', [$host, $path]);
            $this->db->exec('DELETE FROM built_page WHERE host = ? AND path = ?', [$host, $path]);
            $this->db->exec('INSERT INTO built_page (site_id, host, path, file, built_at) VALUES (?, ?, ?, ?, ?)', [$siteId, $host, $path, $file, $this->db->now()]);
            foreach (array_unique($deps) as $dep) {
                $this->db->exec('INSERT INTO built_page_dep (host, path, dep) VALUES (?, ?, ?)', [$host, $path, $dep]);
            }
        });
        rename($tmp, $file);
    }

    /** Removes every built page made from any of these sources. @param list<string> $deps @return int pages removed */
    public function invalidate(array $deps): int
    {
        if (!$deps) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($deps), '?'));
        $pages = $this->db->all("SELECT DISTINCT b.host, b.path, b.file FROM built_page_dep d JOIN built_page b ON b.host = d.host AND b.path = d.path
            WHERE d.dep IN ({$marks})", array_values($deps));
        foreach ($pages as $p) {
            $this->remove($p);
        }
        return count($pages);
    }

    public function purgeSite(int $siteId): int
    {
        $pages = $this->db->all('SELECT host, path, file FROM built_page WHERE site_id = ?', [$siteId]);
        foreach ($pages as $p) {
            $this->remove($p);
        }
        return count($pages);
    }

    public function purgePath(string $host, string $path): void
    {
        $this->remove(['host' => $host, 'path' => $path, 'file' => $this->file($host, $path)]);
    }

    /** @param array{host:string, path:string, file:string} $page */
    private function remove(array $page): void
    {
        @unlink((string) $page['file']);
        $this->db->exec('DELETE FROM built_page_dep WHERE host = ? AND path = ?', [$page['host'], $page['path']]);
        $this->db->exec('DELETE FROM built_page WHERE host = ? AND path = ?', [$page['host'], $page['path']]);
    }
}
