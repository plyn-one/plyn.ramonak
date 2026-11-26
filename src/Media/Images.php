<?php
declare(strict_types=1);

namespace Ramonak\Media;

use Ramonak\Db;
use Ramonak\Http\Response;

/**
 * The images of the media library as pages get them. A site keeps one file per image (/media/ab/<sha>.avif); a smaller width,
 * from a short fixed ladder, is made on its first request (/media/w<width>/ab/<sha>.avif) and written where the web server
 * sends it from then on; that cache is bounded and cleans itself of what was not asked for longest, so removing it loses
 * nothing. Pages get their image markup here: an img of a library file becomes the same img with srcset, sizes, width,
 * height, lazy loading and its alt in the page's language, so an agent writes only <img src="/media/...">.
 */
final class Images
{
    /** The widths a smaller copy may have: few, so that the cache stays small and every copy is shared by many pages. */
    public const WIDTHS = [160, 320, 480, 640, 960, 1280, 1600, 2000];
    private const MASTER = '~^/media/([0-9a-f]{2})/([0-9a-f]{64})\.(avif|jpg|png|webp|gif)$~';
    private const VARIANT = '~^/media/w([0-9]{2,4})/([0-9a-f]{2})/([0-9a-f]{64})\.avif$~';

    public function __construct(private Db $db, private string $mediaDir, private Codec $codec, private int $quality = 55) {}

    /** /media/w<width>/... : the copy of that width, made now if it is not yet on disk. */
    public function serve(string $path): ?Response
    {
        if (!preg_match(self::VARIANT, $path, $m) || !in_array((int) $m[1], self::WIDTHS, true)) {
            return null;
        }
        $master = null;
        foreach (['avif', 'jpg', 'png', 'webp', 'gif'] as $ext) {
            $f = $this->dir() . "/{$m[2]}/{$m[3]}.{$ext}";
            if (is_file($f)) {
                $master = $f;
                break;
            }
        }
        if ($master === null) {
            return null;
        }
        $file = $this->dir() . substr($path, strlen('/media'));
        if (!is_file($file)) {
            [$bytes] = $this->codec->avif((string) file_get_contents($master), (int) $m[1], 100000, $this->quality);
            @mkdir(dirname($file), 0755, true);
            $tmp = $file . '.' . getmypid();
            if (file_put_contents($tmp, $bytes) !== false) {
                rename($tmp, $file);
            }
        } else {
            $bytes = (string) file_get_contents($file);
        }
        return new Response(200, $bytes, ['Content-Type' => 'image/avif', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /**
     * Every img of a library file in a rendered page, without srcset of its own, gets its full markup; $sizes is the size the
     * site declares for the images of its text (site.json media.sizes). A tag ends at the first '>' outside its quoted values,
     * so a value may hold '>', as a media query of sizes does.
     */
    public function rewrite(string $html, int $siteId, string $lang, ?string $sizes = null): string
    {
        return (string) preg_replace_callback('~<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i', function (array $m) use ($siteId, $lang, $sizes): string {
            $attrs = self::attributes($m[0]);
            if (isset($attrs['srcset']) || !preg_match(self::MASTER, $attrs['src'] ?? '')) {
                return $m[0];
            }
            return $this->tag($siteId, $lang, $attrs['src'], $attrs, $sizes) ?? $m[0];
        }, $html);
    }

    /**
     * The img of a library file: src the largest copy up to 1280 px, srcset every copy up to the file's own width, sizes as given
     * (else the width the img is given, else the size the site declares for the images of its text, else the width of a text
     * column of 50rem), width and height of the file (or in its proportion to a given width), lazy loading, and the alt of the
     * file in this language when the img has none.
     * @param array<string,string> $attrs
     */
    public function tag(int $siteId, string $lang, string $src, array $attrs = [], ?string $sizes = null): ?string
    {
        if (!preg_match(self::MASTER, $src, $m)) {
            return null;
        }
        $row = $this->db->one('SELECT id, width, height FROM media WHERE site_id = ? AND path = ?', [$siteId, $src]);
        if ($row === null || !(int) $row['width']) {
            return null;
        }
        [$w, $h] = [(int) $row['width'], (int) $row['height']];
        $widths = [];
        foreach (self::WIDTHS as $width) {
            $widths[] = $width;
            if ($width >= $w) {
                break;
            }
        }
        $url = fn (int $width) => "/media/w{$width}/{$m[1]}/{$m[2]}.avif";
        $fallback = array_values(array_filter($widths, fn ($x) => $x <= 1280));
        $given = isset($attrs['width']) && ctype_digit((string) $attrs['width']) ? (int) $attrs['width'] : null;
        $attrs['src'] = $url($fallback ? end($fallback) : $widths[0]);
        $attrs['srcset'] = implode(', ', array_map(fn ($x) => $url($x) . ' ' . min($x, $w) . 'w', $widths));
        $attrs['sizes'] ??= $given !== null ? $given . 'px' : ($sizes ?? '(width <= 50em) 100vw, 50rem');
        $attrs['width'] = (string) ($given ?? $w);
        if (!isset($attrs['height']) || $given !== null && !ctype_digit((string) $attrs['height'])) {
            $attrs['height'] = (string) (int) round(($given ?? $w) * $h / max(1, $w));
        }
        $attrs['loading'] ??= 'lazy';
        $attrs['decoding'] ??= 'async';
        if (($attrs['alt'] ?? '') === '') {
            $alt = $this->db->value('SELECT alt FROM media_text WHERE media_id = ? AND lang = ?', [$row['id'], $lang]);
            $attrs['alt'] = (string) ($alt ?? '');
        }
        $out = '<img';
        foreach ($attrs as $name => $value) {
            $out .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $out . '>';
    }

    /**
     * Keeps the cache of copies within bounds: when it holds more files or bytes than allowed, the copies asked for longest ago
     * (by access time, else modification) go until it is at 80 % of both. Masters are never touched.
     * @return array{files:int, bytes:int, removed:int}
     */
    public function clean(int $maxFiles, int $maxBytes): array
    {
        $files = [];
        $bytes = 0;
        foreach (glob($this->dir() . '/w[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[] = [$f->getPathname(), max($f->getATime(), $f->getMTime()), $f->getSize()];
                    $bytes += $f->getSize();
                }
            }
        }
        $count = count($files);
        $removed = 0;
        if ($count > $maxFiles || $bytes > $maxBytes) {
            usort($files, fn ($a, $b) => $a[1] <=> $b[1]);
            foreach ($files as [$path, , $size]) {
                if ($count - $removed <= (int) ($maxFiles * 0.8) && $bytes <= (int) ($maxBytes * 0.8)) {
                    break;
                }
                if (@unlink($path)) {
                    $removed++;
                    $bytes -= $size;
                }
            }
        }
        return ['files' => $count - $removed, 'bytes' => $bytes, 'removed' => $removed];
    }

    /** @return array<string,string> */
    private static function attributes(string $tag): array
    {
        preg_match_all('~([a-zA-Z-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')~', $tag, $all, PREG_SET_ORDER);
        $out = [];
        foreach ($all as $a) {
            $out[strtolower($a[1])] = html_entity_decode($a[3] !== '' ? $a[3] : ($a[4] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }

    private function dir(): string
    {
        return rtrim($this->mediaDir, '/');
    }
}
