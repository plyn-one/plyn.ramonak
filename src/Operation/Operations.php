<?php
declare(strict_types=1);

namespace Ramonak\Operation;

use Ramonak\Content\Content;
use Ramonak\Content\Publication;
use Ramonak\Content\Routes;
use Ramonak\Db;
use Ramonak\Jobs\Queue;
use Ramonak\OperationError;
use Ramonak\Render\PageCache;
use Ramonak\Site;

/**
 * The operations of a site, the same for agents (through Klučnik), a later administration and an API. Each declares the
 * level it needs; each that changes something runs in one transaction with its event, and removes the built pages it changes.
 */
final class Operations
{
    /** name => [level, method, changes] */
    public const TABLE = [
        'site.status' => ['supracoŭnik', 'siteStatus', false],
        'content.list' => ['supracoŭnik', 'contentList', false],
        'content.read' => ['supracoŭnik', 'contentRead', false],
        'content.create' => ['davierany', 'contentCreate', true],
        'content.save' => ['supracoŭnik', 'contentSave', true],
        'publication.publish' => ['davierany', 'publish', true],
        'publication.rollback' => ['davierany', 'rollback', true],
        'publication.unpublish' => ['davierany', 'unpublish', true],
        'review.add' => ['supracoŭnik', 'reviewAdd', true],
        'route.set' => ['davierany', 'routeSet', true],
        'route.list' => ['supracoŭnik', 'routeList', false],
        'redirect.set' => ['davierany', 'redirectSet', true],
        'redirect.import' => ['davierany', 'redirectImport', true],
        'translation.request' => ['davierany', 'translationRequest', true],
        'cache.purge' => ['davierany', 'cachePurge', true],
        'job.list' => ['davierany', 'jobList', false],
        'event.list' => ['davierany', 'eventList', false],
    ];

    public function __construct(
        private Db $db,
        private Content $content,
        private Publication $publication,
        private Routes $routes,
        private PageCache $cache,
        private Queue $queue,
        private bool $experimental,
    ) {}

    /** @return array<string,mixed> */
    public function run(Site $site, Actor $actor, string $name, array $input): array
    {
        [$level, $method, $changes] = self::TABLE[$name] ?? throw new OperationError('operation_unknown', "No operation {$name}", 404);
        if ($actor->rank() < Actor::LEVELS[$level]) {
            throw new OperationError('operation_forbidden', "{$name} needs the level {$level}", 403);
        }
        if (!$changes) {
            return $this->{$method}($site, $actor, $input);
        }
        return $this->db->tx(function () use ($site, $actor, $name, $method, $input): array {
            $result = $this->{$method}($site, $actor, $input);
            $this->db->insert('INSERT INTO event (site_id, actor, operation, subject, data, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$site->id, $actor->label(), $name, isset($input['item']) ? (string) $input['item'] : ($input['path'] ?? null),
                    json_encode(['input' => self::summary($input), 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->db->now()]);
            return $result;
        });
    }

    private static function summary(array $input): array
    {
        // an event keeps what was asked, not whole texts: a field of a version is in the version itself
        if (isset($input['fields']) && is_array($input['fields'])) {
            $input['fields'] = array_keys($input['fields']);
        }
        if (isset($input['redirects']) && is_array($input['redirects'])) {
            $input['redirects'] = count($input['redirects']);
        }
        return $input;
    }

    private static function need(array $input, string $key): mixed
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            throw new OperationError('input_missing', "The operation needs {$key}");
        }
        return $input[$key];
    }

    private function siteStatus(Site $site, Actor $actor, array $input): array
    {
        return ['site' => $site->key, 'domains' => $site->domains, 'languages' => $site->languages, 'default_language' => $site->defaultLanguage,
            'indexable' => $site->indexable && !$this->experimental, 'experimental' => $this->experimental, 'kinds' => array_keys($site->declaration['kinds'] ?? []),
            'items' => (int) $this->db->value('SELECT COUNT(*) FROM item WHERE site_id = ?', [$site->id]),
            'published' => (int) $this->db->value('SELECT COUNT(*) FROM publication p JOIN item i ON i.id = p.item_id WHERE i.site_id = ? AND p.current = 1', [$site->id]),
            'jobs_waiting' => (int) $this->db->value("SELECT COUNT(*) FROM job WHERE state IN ('queued', 'running')"),
            'you' => ['member' => $actor->member, 'level' => $actor->level]];
    }

    private function contentList(Site $site, Actor $actor, array $input): array
    {
        $params = [$site->id];
        $where = '';
        if (!empty($input['kind'])) {
            $where = ' AND i.kind = ?';
            $params[] = (string) $input['kind'];
        }
        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $items = $this->db->all("SELECT i.id, i.item_key, i.kind, i.state, i.position FROM item i WHERE i.site_id = ?{$where} ORDER BY i.kind, i.position, i.id LIMIT {$limit} OFFSET {$offset}", $params);
        foreach ($items as &$i) {
            $i['languages'] = [];
            foreach ($this->db->all('SELECT v.lang, MAX(v.revision) AS revision, (SELECT v2.origin FROM item_version v2 WHERE v2.item_id = v.item_id AND v2.lang = v.lang ORDER BY v2.revision DESC LIMIT 1) AS origin,
                (SELECT pv.revision FROM publication p JOIN item_version pv ON pv.id = p.version_id WHERE p.item_id = v.item_id AND p.lang = v.lang AND p.current = 1) AS published
                FROM item_version v WHERE v.item_id = ? GROUP BY v.lang', [$i['id']]) as $l) {
                $i['languages'][$l['lang']] = ['latest' => (int) $l['revision'], 'origin' => $l['origin'], 'published' => $l['published'] === null ? null : (int) $l['published'],
                    'path' => $this->routes->pathOf((int) $i['id'], (string) $l['lang'])];
            }
            unset($i['id']);
        }
        return ['items' => $items];
    }

    private function contentRead(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $out = ['item' => $item['item_key'], 'kind' => $item['kind'], 'state' => $item['state'], 'languages' => []];
        $source = $this->content->latestSource((int) $item['id']);
        foreach (array_keys($site->languages) as $lang) {
            $latest = $this->content->latest((int) $item['id'], $lang);
            $current = $this->publication->current((int) $item['id'], $lang);
            if ($latest === null && $current === null) {
                continue;
            }
            $out['languages'][$lang] = [
                'path' => $this->routes->pathOf((int) $item['id'], $lang),
                'latest' => $latest === null ? null : ['version' => (int) $latest['id'], 'revision' => (int) $latest['revision'], 'origin' => $latest['origin'],
                    'author' => $latest['author'], 'fields' => $latest['fields'],
                    'stale' => $latest['origin'] !== 'source' && $source !== null && (int) ($latest['source_version_id'] ?? 0) < (int) $source['id']],
                'published' => $current === null ? null : ['version' => (int) $current['version_id'], 'revision' => (int) $current['revision'],
                    'publication' => (int) $current['publication_id'], 'by' => $current['published_by'], 'at' => (int) $current['published_at']],
                'history' => $this->publication->history((int) $item['id'], $lang, (int) ($input['history'] ?? 5)),
            ];
        }
        return $out;
    }

    private function contentCreate(Site $site, Actor $actor, array $input): array
    {
        $id = $this->content->create($site, (string) self::need($input, 'kind'), (string) self::need($input, 'item'),
            isset($input['parent']) ? (string) $input['parent'] : null, (int) ($input['position'] ?? 0));
        $result = ['item' => $input['item'], 'created' => true];
        if (isset($input['path'], $input['lang'])) {
            $result['route'] = $this->routes->set($site, $id, (string) $input['lang'], (string) $input['path']);
        }
        return $result;
    }

    private function contentSave(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $lang = (string) self::need($input, 'lang');
        $origin = (string) ($input['origin'] ?? ($lang === $site->defaultLanguage ? 'source' : 'edited'));
        if ($origin === 'machine') {
            throw new OperationError('origin_invalid', 'A machine version is written by the translation job only');
        }
        $fields = self::need($input, 'fields');
        if (!is_array($fields)) {
            throw new OperationError('input_invalid', 'fields is an object');
        }
        $version = $this->content->save($site, (int) $item['id'], $lang, $fields, $origin, $actor->label());
        $result = ['item' => $item['item_key'], 'lang' => $lang, 'version' => $version, 'origin' => $origin];
        if (!empty($input['publish'])) {
            if ($actor->rank() < Actor::LEVELS['davierany']) {
                throw new OperationError('operation_forbidden', 'Publishing needs the level davierany', 403);
            }
            $result['publication'] = $this->publishVersion($site, $actor, $item, $lang, $version);
        }
        return $result;
    }

    private function publish(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $lang = (string) self::need($input, 'lang');
        $version = isset($input['version']) ? (int) $input['version'] : (int) ($this->content->latest((int) $item['id'], $lang)['id'] ?? 0);
        if ($version === 0) {
            throw new OperationError('version_unknown', "Item {$item['item_key']} has no version in {$lang}", 404);
        }
        return $this->publishVersion($site, $actor, $item, $lang, $version);
    }

    private function publishVersion(Site $site, Actor $actor, array $item, string $lang, int $version): array
    {
        if ($this->routes->pathOf((int) $item['id'], $lang) === null) {
            throw new OperationError('route_missing', "Item {$item['item_key']} has no path in {$lang}: set one with route.set");
        }
        $published = $this->publication->publish($site, (int) $item['id'], $lang, $version, $actor->label());
        $published['pages_removed'] = $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        $origin = (string) $this->db->value('SELECT origin FROM item_version WHERE id = ?', [$version]);
        if ($origin === 'source') {
            $published['translations_queued'] = $this->queueTranslations($site, $item, $lang, $version);
        }
        return $published;
    }

    /** @return list<string> */
    private function queueTranslations(Site $site, array $item, string $from, int $sourceVersion): array
    {
        $queued = [];
        foreach ($site->languages as $lang => $l) {
            if ($lang === $from || !$l['machine']) {
                continue;
            }
            $latest = $this->content->latest((int) $item['id'], $lang);
            // an edited translation is not overwritten: it becomes stale and waits for a person or an agent
            if ($latest !== null && $latest['origin'] === 'edited') {
                continue;
            }
            $this->queue->push('translate', ['site' => $site->key, 'item' => $item['item_key'], 'from' => $from, 'to' => $lang, 'source_version' => $sourceVersion]);
            $queued[] = $lang;
        }
        return $queued;
    }

    private function rollback(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $lang = (string) self::need($input, 'lang');
        $current = $this->publication->current((int) $item['id'], $lang);
        $version = isset($input['version']) ? (int) $input['version']
            : (int) ($this->db->value('SELECT p.version_id FROM publication p WHERE p.id = (SELECT replaces_id FROM publication WHERE item_id = ? AND lang = ? AND current = 1)',
                [$item['id'], $lang]) ?? 0);
        if ($version === 0 || $current === null) {
            throw new OperationError('rollback_impossible', "Item {$item['item_key']} in {$lang} has no earlier publication", 409);
        }
        $result = $this->publication->publish($site, (int) $item['id'], $lang, $version, $actor->label());
        $result['pages_removed'] = $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        $result['from_version'] = (int) $current['version_id'];
        return $result;
    }

    private function unpublish(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $done = $this->publication->unpublish((int) $item['id'], (string) self::need($input, 'lang'));
        $removed = $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        return ['unpublished' => $done, 'pages_removed' => $removed];
    }

    private function reviewAdd(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $current = $this->publication->current((int) $item['id'], (string) self::need($input, 'lang'))
            ?? throw new OperationError('publication_unknown', 'Nothing is published to review', 404);
        $id = $this->publication->review((int) $current['publication_id'], (string) self::need($input, 'verdict'), (string) ($input['note'] ?? ''), $actor->label());
        return ['review' => $id, 'publication' => (int) $current['publication_id'], 'version' => (int) $current['version_id']];
    }

    private function routeSet(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $result = $this->routes->set($site, (int) $item['id'], (string) self::need($input, 'lang'), (string) self::need($input, 'path'));
        $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        if ($result['moved_from'] !== null) {
            $this->cache->purgePath($site->primaryDomain(), $result['moved_from']);
        }
        return $result;
    }

    private function routeList(Site $site, Actor $actor, array $input): array
    {
        return ['routes' => $this->routes->all($site)];
    }

    private function redirectSet(Site $site, Actor $actor, array $input): array
    {
        $path = Routes::normalize((string) self::need($input, 'path'));
        $this->routes->redirect($site, $path, isset($input['target']) ? (string) $input['target'] : null, (int) ($input['status'] ?? 301));
        $this->cache->purgePath($site->primaryDomain(), $path);
        return ['path' => $path, 'status' => (int) ($input['status'] ?? 301)];
    }

    private function redirectImport(Site $site, Actor $actor, array $input): array
    {
        $list = self::need($input, 'redirects');
        if (!is_array($list) || count($list) > 5000) {
            throw new OperationError('input_invalid', 'redirects is a list of at most 5000 {path, target, status}');
        }
        $done = 0;
        $refused = [];
        foreach ($list as $r) {
            try {
                $this->routes->redirect($site, (string) ($r['path'] ?? ''), isset($r['target']) ? (string) $r['target'] : null, (int) ($r['status'] ?? 301));
                $done++;
            } catch (OperationError $e) {
                $refused[] = ['path' => $r['path'] ?? null, 'error' => $e->error, 'message' => $e->getMessage()];
            }
        }
        return ['imported' => $done, 'refused' => $refused];
    }

    private function translationRequest(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $source = $this->content->latestSource((int) $item['id']) ?? throw new OperationError('source_missing', 'The item has no source version');
        $to = (array) ($input['languages'] ?? array_keys(array_filter($site->languages, fn ($l, $lang) => $lang !== $source['lang'], ARRAY_FILTER_USE_BOTH)));
        foreach ($to as $lang) {
            if (!isset($site->languages[$lang])) {
                throw new OperationError('language_unknown', "No language {$lang}");
            }
            $this->queue->push('translate', ['site' => $site->key, 'item' => $item['item_key'], 'from' => $source['lang'], 'to' => $lang,
                'source_version' => (int) $source['id'], 'force' => !empty($input['replace_edited'])]);
        }
        return ['queued' => array_values($to)];
    }

    private function cachePurge(Site $site, Actor $actor, array $input): array
    {
        return ['pages_removed' => $this->cache->purgeSite($site->id)];
    }

    private function jobList(Site $site, Actor $actor, array $input): array
    {
        $state = (string) ($input['state'] ?? '');
        return ['jobs' => $this->db->all('SELECT id, kind, payload, state, attempts, run_after, result, created_at FROM job'
            . ($state !== '' ? ' WHERE state = ?' : '') . ' ORDER BY id DESC LIMIT 100', $state !== '' ? [$state] : [])];
    }

    private function eventList(Site $site, Actor $actor, array $input): array
    {
        return ['events' => $this->db->all('SELECT id, actor, operation, subject, data, created_at FROM event WHERE site_id = ? ORDER BY id DESC LIMIT '
            . max(1, min(500, (int) ($input['limit'] ?? 50))), [$site->id])];
    }
}
