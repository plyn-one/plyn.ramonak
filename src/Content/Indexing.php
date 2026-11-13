<?php
declare(strict_types=1);

namespace Ramonak\Content;

use Ramonak\Db;
use Ramonak\Site;

/**
 * Whether a published version is open to search engines. A version written by a person or an agent is; a machine
 * translation is once enough distinct reviewers (site.json machine_reviews, 1 by default) have reviewed its current
 * publication as ok_for_now and nobody as needs_work. A closed version is still public to visitors, with noindex, and is
 * left out of hreflang and the sitemap.
 */
final class Indexing
{
    public function __construct(private Db $db) {}

    public static function reviewsNeeded(Site $site): int
    {
        return max(0, (int) ($site->declaration['machine_reviews'] ?? 1));
    }

    public function open(Site $site, int $itemId, string $lang): bool
    {
        $row = $this->db->one('SELECT p.id, v.origin FROM publication p JOIN item_version v ON v.id = p.version_id
            WHERE p.item_id = ? AND p.lang = ? AND p.current = 1', [$itemId, $lang]);
        return $row !== null && ($row['origin'] !== 'machine' || $this->reviewed($site, (int) $row['id']));
    }

    private function reviewed(Site $site, int $publicationId): bool
    {
        $needed = self::reviewsNeeded($site);
        if ($needed === 0) {
            return true;
        }
        $verdicts = $this->db->all('SELECT reviewer, verdict FROM review WHERE publication_id = ? ORDER BY id', [$publicationId]);
        $last = [];
        foreach ($verdicts as $v) {
            $last[$v['reviewer']] = $v['verdict'];
        }
        return !in_array('needs_work', $last, true) && count(array_filter($last, fn ($v) => $v === 'ok_for_now')) >= $needed;
    }

    /** @return list<array{lang:string, path:string}> the published versions of an item open to search engines */
    public function openVersions(Site $site, int $itemId): array
    {
        $rows = $this->db->all('SELECT r.lang, r.path FROM route r JOIN publication p ON p.item_id = r.item_id AND p.lang = r.lang AND p.current = 1
            WHERE r.item_id = ? AND r.status = 200', [$itemId]);
        return array_values(array_filter($rows, fn ($r) => $this->open($site, $itemId, (string) $r['lang'])));
    }
}
