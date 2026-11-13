<?php
declare(strict_types=1);

namespace Ramonak\Content;

use Ramonak\Db;
use Ramonak\OperationError;
use Ramonak\Site;

/**
 * What is public: only what a current publication points to. Publishing replaces the current publication of an item in a
 * language; rollback is a publication of an earlier version. Nothing is final: a reviewed publication is replaced like any.
 */
final class Publication
{
    public function __construct(private Db $db) {}

    /** @return array{publication:int, replaced:?int, version:int} */
    public function publish(Site $site, int $itemId, string $lang, int $versionId, string $actor): array
    {
        $version = $this->db->one('SELECT v.id, v.lang, i.site_id FROM item_version v JOIN item i ON i.id = v.item_id WHERE v.id = ? AND v.item_id = ?',
            [$versionId, $itemId]);
        if ($version === null || (int) $version['site_id'] !== $site->id || $version['lang'] !== $lang) {
            throw new OperationError('version_unknown', "Version {$versionId} is not a version of item {$itemId} in {$lang}", 404);
        }
        return $this->db->tx(function () use ($itemId, $lang, $versionId, $actor): array {
            $current = $this->db->one('SELECT id, version_id FROM publication WHERE item_id = ? AND lang = ? AND current = 1', [$itemId, $lang]);
            if ($current !== null && (int) $current['version_id'] === $versionId) {
                return ['publication' => (int) $current['id'], 'replaced' => null, 'version' => $versionId];
            }
            if ($current !== null) {
                $this->db->exec('UPDATE publication SET current = 0 WHERE id = ?', [$current['id']]);
            }
            $id = $this->db->insert('INSERT INTO publication (item_id, lang, version_id, replaces_id, current, published_by, published_at) VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$itemId, $lang, $versionId, $current['id'] ?? null, $actor, $this->db->now()]);
            return ['publication' => $id, 'replaced' => isset($current['id']) ? (int) $current['id'] : null, 'version' => $versionId];
        });
    }

    public function unpublish(int $itemId, string $lang): bool
    {
        return $this->db->exec('UPDATE publication SET current = 0 WHERE item_id = ? AND lang = ? AND current = 1', [$itemId, $lang]) > 0;
    }

    /** @return array<string,mixed>|null the current publication with its version's fields */
    public function current(int $itemId, string $lang): ?array
    {
        $row = $this->db->one('SELECT p.id AS publication_id, p.published_at, p.published_by, v.id AS version_id, v.revision, v.origin, v.fields
            FROM publication p JOIN item_version v ON v.id = p.version_id WHERE p.item_id = ? AND p.lang = ? AND p.current = 1', [$itemId, $lang]);
        if ($row !== null) {
            $row['fields'] = json_decode((string) $row['fields'], true);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function history(int $itemId, string $lang, int $limit = 20): array
    {
        return $this->db->all('SELECT p.id, p.version_id, v.revision, v.origin, p.current, p.published_by, p.published_at, p.replaces_id
            FROM publication p JOIN item_version v ON v.id = p.version_id WHERE p.item_id = ? AND p.lang = ? ORDER BY p.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$itemId, $lang]);
    }

    public function review(int $publicationId, string $verdict, string $note, string $reviewer): int
    {
        if (!in_array($verdict, ['ok_for_now', 'needs_work'], true)) {
            throw new OperationError('verdict_invalid', 'verdict is ok_for_now or needs_work');
        }
        if (!$this->db->value('SELECT 1 FROM publication WHERE id = ?', [$publicationId])) {
            throw new OperationError('publication_unknown', "No publication {$publicationId}", 404);
        }
        return $this->db->insert('INSERT INTO review (publication_id, verdict, note, reviewer, created_at) VALUES (?, ?, ?, ?, ?)',
            [$publicationId, $verdict, $note, $reviewer, $this->db->now()]);
    }
}
