<?php
declare(strict_types=1);

namespace Ramonak\Render;

use Ramonak\Content\Indexing;
use Ramonak\Db;
use Ramonak\Site;

/** sitemap.xml and robots.txt of a site, from what is published; empty and closed on an experimental installation. */
final class Sitemap
{
    public function __construct(private Db $db, private bool $experimental) {}

    public function xml(Site $site): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        if (!$this->experimental && $site->indexable) {
            $rows = $this->db->all('SELECT r.item_id, r.lang, r.path, p.published_at FROM route r
                JOIN publication p ON p.item_id = r.item_id AND p.lang = r.lang AND p.current = 1
                WHERE r.site_id = ? AND r.status = 200 ORDER BY r.path', [$site->id]);
            $indexing = new Indexing($this->db);
            $rows = array_values(array_filter($rows, fn ($r) => $indexing->open($site, (int) $r['item_id'], (string) $r['lang'])));
            $byItem = [];
            foreach ($rows as $r) {
                $byItem[$r['item_id']][] = $r;
            }
            foreach ($rows as $r) {
                $out .= '<url><loc>' . htmlspecialchars($site->url((string) $r['path']), ENT_XML1) . '</loc><lastmod>' . gmdate('Y-m-d', (int) $r['published_at']) . '</lastmod>';
                if (count($byItem[$r['item_id']]) > 1) {
                    foreach ($byItem[$r['item_id']] as $a) {
                        $out .= '<xhtml:link rel="alternate" hreflang="' . htmlspecialchars((string) $a['lang'], ENT_XML1) . '" href="' . htmlspecialchars($site->url((string) $a['path']), ENT_XML1) . '"/>';
                    }
                }
                $out .= "</url>\n";
            }
        }
        return $out . "</urlset>\n";
    }

    public function robots(Site $site): string
    {
        if ($this->experimental || !$site->indexable) {
            return "User-agent: *\nDisallow: /\n";
        }
        // Clean-param: Yandex drops these parameters, so an address shared with a campaign tag is not a second page
        // (Google reads the canonical instead). AI crawlers are not blocked: being cited in answers is wanted.
        return "User-agent: *\nAllow: /\nDisallow: /_ramonak/\n\nUser-agent: Yandex\nAllow: /\nDisallow: /_ramonak/\n"
            . "Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid&fbclid&_openstat&from&ref\n\n"
            . "Sitemap: " . $site->url('/sitemap.xml') . "\n";
    }
}
