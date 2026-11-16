<?php
declare(strict_types=1);

namespace Ramonak\Render;

use Ramonak\Db;
use Ramonak\Site;
use Ramonak\Sites;

/**
 * The templates a page is made with: the site's own and the framework's. Their fingerprint names the compiled templates
 * (so a changed template is compiled again) and tells when built pages are out of date: check() removes a site's built
 * pages once its templates differ from the last check, so a deploy of templates needs no step of its own. The job
 * runner calls check() every minute.
 */
final class TemplateWatch
{
    public function __construct(private Db $db, private Sites $sites, private PageCache $cache, private string $varDir, private string $frameworkDir) {}

    /** The templates directory of a site, as the renderer reads it. */
    public function siteDir(Site $site): string
    {
        return $site->dir . '/' . ($site->declaration['templates'] ?? 'templates');
    }

    /** The fingerprint of a site's templates and the framework's: a hash of every file's path and content. */
    public function fingerprint(Site $site): string
    {
        return self::of([$this->siteDir($site), $this->frameworkDir]);
    }

    /** @param list<string> $dirs */
    public static function of(array $dirs): string
    {
        $hash = hash_init('sha256');
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = [];
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                hash_update($hash, substr($file, strlen($dir)) . "\0" . hash_file('sha256', $file) . "\n");
            }
        }
        return hash_final($hash);
    }

    /**
     * Removes the built pages of every site whose templates changed since the last check. The first check of a site
     * removes its built pages too, since nothing is known of what they were made with.
     *
     * @return array<string,int> pages removed, by site key (only the sites that changed)
     */
    public function check(): array
    {
        $removed = [];
        foreach ($this->db->all('SELECT site_key FROM site ORDER BY id') as $row) {
            $site = $this->sites->byKey((string) $row['site_key']);
            if ($site === null) {
                continue;
            }
            $now = $this->fingerprint($site);
            $file = $this->stateFile($site->key);
            $was = is_file($file) ? trim((string) file_get_contents($file)) : null;
            if ($was === $now) {
                continue;
            }
            $removed[$site->key] = $this->cache->purgeSite($site->id);
            $this->remember($file, $now);
        }
        return $removed;
    }

    private function stateFile(string $key): string
    {
        return $this->varDir . '/templates/' . $key . '.fingerprint';
    }

    private function remember(string $file, string $fingerprint): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $fingerprint) !== false) {
            rename($tmp, $file);
        }
    }
}
