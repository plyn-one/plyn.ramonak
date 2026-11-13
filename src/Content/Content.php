<?php
declare(strict_types=1);

namespace Ramonak\Content;

use Ramonak\Db;
use Ramonak\OperationError;
use Ramonak\Site;

/**
 * Items and their versions. A version is written once and never changed; an edit is a new version. Fields are checked
 * against the kind the site declares before they are written.
 */
final class Content
{
    public function __construct(private Db $db) {}

    /** @return array<string,mixed> */
    public function item(Site $site, string $key): array
    {
        $row = $this->db->one('SELECT * FROM item WHERE site_id = ? AND item_key = ?', [$site->id, $key]);
        return $row ?? throw new OperationError('item_unknown', "No item {$key} on {$site->key}", 404);
    }

    public function create(Site $site, string $kind, string $key, ?string $parentKey = null, int $position = 0): int
    {
        $site->kind($kind);
        if (!preg_match('/^[a-z0-9][a-z0-9._\/-]{0,150}$/', $key)) {
            throw new OperationError('item_key_invalid', 'An item key is lower-case letters, digits, dot, dash, underscore and slash');
        }
        if ($this->db->value('SELECT 1 FROM item WHERE site_id = ? AND item_key = ?', [$site->id, $key])) {
            throw new OperationError('item_exists', "Item {$key} exists on {$site->key}", 409);
        }
        $parent = $parentKey === null ? null : (int) $this->item($site, $parentKey)['id'];
        return $this->db->insert('INSERT INTO item (site_id, kind, item_key, parent_id, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$site->id, $kind, $key, $parent, $position, $this->db->now()]);
    }

    /**
     * A new version of an item in a language. origin: source (written in this language), edited (a translation a person
     * or agent wrote or corrected), machine (made by the translation job only).
     * @param array<string,mixed> $fields
     */
    public function save(Site $site, int $itemId, string $lang, array $fields, string $origin, string $author, ?int $sourceVersionId = null): int
    {
        if (!isset($site->languages[$lang])) {
            throw new OperationError('language_unknown', "The site {$site->key} has no language {$lang}");
        }
        if (!in_array($origin, ['source', 'edited', 'machine'], true)) {
            throw new OperationError('origin_invalid', 'origin is source, edited or machine');
        }
        if ($origin !== 'source' && $sourceVersionId === null) {
            $sourceVersionId = $this->latestSource($itemId)['id'] ?? null;
        }
        $kind = (string) $this->db->value('SELECT kind FROM item WHERE id = ? AND site_id = ?', [$itemId, $site->id]);
        if ($kind === '') {
            throw new OperationError('item_unknown', "No item {$itemId} on {$site->key}", 404);
        }
        $clean = self::check($site->kind($kind), $fields);
        return $this->db->tx(function () use ($itemId, $lang, $clean, $origin, $author, $sourceVersionId): int {
            $revision = 1 + (int) $this->db->value('SELECT MAX(revision) FROM item_version WHERE item_id = ? AND lang = ?', [$itemId, $lang]);
            return $this->db->insert('INSERT INTO item_version (item_id, lang, revision, origin, source_version_id, fields, author, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$itemId, $lang, $revision, $origin, $sourceVersionId,
                json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $author, $this->db->now()]);
        });
    }

    /** @return array<string,mixed>|null */
    public function version(int $versionId): ?array
    {
        $row = $this->db->one('SELECT * FROM item_version WHERE id = ?', [$versionId]);
        if ($row !== null) {
            $row['fields'] = json_decode((string) $row['fields'], true);
        }
        return $row;
    }

    /** @return array<string,mixed>|null the newest version written in the item's source language */
    public function latestSource(int $itemId): ?array
    {
        return $this->db->one("SELECT * FROM item_version WHERE item_id = ? AND origin = 'source' ORDER BY id DESC LIMIT 1", [$itemId]);
    }

    /** @return array<string,mixed>|null */
    public function latest(int $itemId, string $lang): ?array
    {
        $row = $this->db->one('SELECT * FROM item_version WHERE item_id = ? AND lang = ? ORDER BY revision DESC LIMIT 1', [$itemId, $lang]);
        if ($row !== null) {
            $row['fields'] = json_decode((string) $row['fields'], true);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $kind
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function check(array $kind, array $fields): array
    {
        $declared = $kind['fields'] ?? [];
        $unknown = array_diff(array_keys($fields), array_keys($declared));
        if ($unknown) {
            throw new OperationError('field_unknown', 'Fields not declared by the kind: ' . implode(', ', $unknown));
        }
        $clean = [];
        foreach ($declared as $name => $spec) {
            $value = $fields[$name] ?? null;
            if ($value === null || $value === '') {
                if (!empty($spec['required'])) {
                    throw new OperationError('field_required', "Field {$name} is required");
                }
                continue;
            }
            $type = $spec['type'] ?? 'text';
            $clean[$name] = match ($type) {
                'text', 'html', 'markdown' => is_string($value) && mb_strlen($value) <= (int) ($spec['max'] ?? 200000) ? $value
                    : throw new OperationError('field_invalid', "Field {$name} is a string of at most " . ($spec['max'] ?? 200000) . ' characters'),
                'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value
                    : throw new OperationError('field_invalid', "Field {$name} is a date YYYY-MM-DD"),
                'number' => is_int($value) || is_float($value) ? $value : throw new OperationError('field_invalid', "Field {$name} is a number"),
                'list' => is_array($value) && array_is_list($value) ? $value : throw new OperationError('field_invalid', "Field {$name} is a list"),
                'media' => is_string($value) ? $value : throw new OperationError('field_invalid', "Field {$name} is a media path"),
                default => throw new OperationError('field_type_unknown', "The kind declares an unknown type {$type} for {$name}"),
            };
        }
        return $clean;
    }
}
