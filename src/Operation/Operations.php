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
        'publication.schedule' => ['davierany', 'schedule', true],
        'publication.unschedule' => ['davierany', 'unschedule', true],
        'review.add' => ['supracoŭnik', 'reviewAdd', true],
        'route.set' => ['davierany', 'routeSet', true],
        'route.list' => ['supracoŭnik', 'routeList', false],
        'redirect.set' => ['davierany', 'redirectSet', true],
        'redirect.import' => ['davierany', 'redirectImport', true],
        'translation.request' => ['davierany', 'translationRequest', true],
        'cache.purge' => ['davierany', 'cachePurge', true],
        'job.list' => ['davierany', 'jobList', false],
        'job.cancel' => ['davierany', 'jobCancel', true],
        'event.list' => ['davierany', 'eventList', false],
        'form.list' => ['davierany', 'formList', false],
        'form.delete' => ['davierany', 'formDelete', true],
        'content.import' => ['davierany', 'contentImport', true],
        'media.add' => ['davierany', 'mediaAdd', true],
        'seo.audit' => ['supracoŭnik', 'seoAudit', false],
    ];

    public function __construct(
        private Db $db,
        private Content $content,
        private Publication $publication,
        private Routes $routes,
        private PageCache $cache,
        private Queue $queue,
        private bool $experimental,
        private string $mediaDir = '',
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
        // the source language is the item's own: the language its first text was written in, whichever it is; a text in another
        // language is a translation (edited), unless the caller says it is now the source
        $source = $this->content->latestSource((int) $item['id']);
        $origin = (string) ($input['origin'] ?? ($source === null || $source['lang'] === $lang ? 'source' : 'edited'));
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
        if (isset($input['at'])) {
            return $this->schedule($site, $actor, $input);
        }
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
        $path = $this->routes->pathOf((int) $item['id'], $lang);
        if ($path === null) {
            throw new OperationError('route_missing', "Item {$item['item_key']} has no path in {$lang}: set one with route.set");
        }
        $published = $this->publication->publish($site, (int) $item['id'], $lang, $version, $actor->label());
        $published['pages_removed'] = $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        $this->notify($site, [$path]);
        $origin = (string) $this->db->value('SELECT origin FROM item_version WHERE id = ?', [$version]);
        if ($origin === 'source') {
            $published['translations_queued'] = $this->queueTranslations($site, $item, $lang, $version);
        }
        return $published;
    }

    /** Search engines of IndexNow are told of changed addresses by a job; it sends nothing from an experimental installation. */
    private function notify(Site $site, array $paths): void
    {
        if (!$this->experimental && $site->indexable && $paths) {
            $this->queue->push('indexnow', ['site' => $site->key, 'paths' => array_values($paths)]);
        }
    }

    /** A publication at a future time: a job publish runs it then (bin/ramonak jobs:run, every minute). */
    private function schedule(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $lang = (string) self::need($input, 'lang');
        $at = is_numeric($input['at'] ?? null) ? (int) $input['at'] : (int) strtotime((string) self::need($input, 'at'));
        if ($at <= 0 || $at < $this->db->now() + 30) {
            throw new OperationError('at_invalid', 'at is a time at least a minute ahead: unix seconds or ISO 8601 with its offset, such as 2026-12-01T09:00:00+01:00');
        }
        $version = isset($input['version']) ? (int) $input['version'] : (int) ($this->content->latest((int) $item['id'], $lang)['id'] ?? 0);
        if ($version === 0 || $this->content->version($version)['item_id'] != $item['id']) {
            throw new OperationError('version_unknown', "Item {$item['item_key']} has no such version in {$lang}", 404);
        }
        if ($this->routes->pathOf((int) $item['id'], $lang) === null) {
            throw new OperationError('route_missing', "Item {$item['item_key']} has no path in {$lang}: set one with route.set");
        }
        $job = $this->queue->push('publish', ['site' => $site->key, 'item' => $item['item_key'], 'lang' => $lang, 'version' => $version, 'by' => $actor->member,
            'agent' => $actor->agent], $at - $this->db->now());
        return ['scheduled' => $job, 'item' => $item['item_key'], 'lang' => $lang, 'version' => $version, 'at' => gmdate('c', $at)];
    }

    private function unschedule(Site $site, Actor $actor, array $input): array
    {
        $done = $this->db->exec("UPDATE job SET state = 'done', result = ? WHERE id = ? AND kind = 'publish' AND state = 'queued'",
            ['cancelled by ' . $actor->label(), (int) self::need($input, 'job')]);
        if ($done !== 1) {
            throw new OperationError('schedule_unknown', 'No waiting scheduled publication with this job id', 404);
        }
        return ['cancelled' => (int) $input['job']];
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
        $path = $this->routes->pathOf((int) $item['id'], (string) $input['lang']);
        $this->notify($site, $path === null ? [] : [$path]);
        return ['unpublished' => $done, 'pages_removed' => $removed];
    }

    private function reviewAdd(Site $site, Actor $actor, array $input): array
    {
        $item = $this->content->item($site, (string) self::need($input, 'item'));
        $current = $this->publication->current((int) $item['id'], (string) self::need($input, 'lang'))
            ?? throw new OperationError('publication_unknown', 'Nothing is published to review', 404);
        $id = $this->publication->review((int) $current['publication_id'], (string) self::need($input, 'verdict'), (string) ($input['note'] ?? ''), $actor->label());
        // a review can open a machine translation to search engines, or close it again: its pages are built anew
        $this->cache->invalidate(['item:' . $item['id']]);
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
        $this->notify($site, array_values(array_filter([$result['path'], $result['moved_from']])));
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

    /** A job still queued is cancelled: it is not claimed again. A job running, done or failed is left as it is. */
    private function jobCancel(Site $site, Actor $actor, array $input): array
    {
        $id = (int) self::need($input, 'id');
        $state = (string) $this->db->value('SELECT state FROM job WHERE id = ?', [$id]);
        if ($state === '') {
            throw new OperationError('job_unknown', "No job {$id}", 404);
        }
        if ($state !== 'queued') {
            throw new OperationError('job_not_queued', "Job {$id} is {$state}; only a queued job can be cancelled");
        }
        // the jobs table has no 'cancelled' state: a cancelled job is failed, with the reason written as its result
        $this->db->exec("UPDATE job SET state = 'failed', result = 'cancelled by an administrator', locked_until = NULL WHERE id = ? AND state = 'queued'", [$id]);
        return ['id' => $id, 'state' => 'failed', 'result' => 'cancelled by an administrator'];
    }

    private function jobList(Site $site, Actor $actor, array $input): array
    {
        $state = (string) ($input['state'] ?? '');
        return ['jobs' => $this->db->all('SELECT id, kind, payload, state, attempts, run_after, result, created_at FROM job'
            . ($state !== '' ? ' WHERE state = ?' : '') . ' ORDER BY id DESC LIMIT 100', $state !== '' ? [$state] : [])];
    }

    /** Entries of the site's forms, newest first: what a visitor sent, the page and language, and whether it was mailed. */
    private function formList(Site $site, Actor $actor, array $input): array
    {
        $where = 'site_id = ?';
        $params = [$site->id];
        foreach (['form', 'state'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $where .= " AND {$key} = ?";
                $params[] = (string) $input[$key];
            }
        }
        $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
        $rows = $this->db->all("SELECT id, form, lang, page, fields, state, created_at FROM form_entry WHERE {$where} ORDER BY id DESC LIMIT {$limit}", $params);
        foreach ($rows as &$row) {
            $row['fields'] = json_decode((string) $row['fields'], true);
        }
        return ['entries' => $rows];
    }

    /** Removes form entries for good, such as spam or what a visitor asked to be erased; the event keeps only their ids. */
    private function formDelete(Site $site, Actor $actor, array $input): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($input['entries'] ?? [])), fn ($i) => $i > 0));
        if ($ids === []) {
            throw new OperationError('input_invalid', 'form.delete needs entries: a list of entry ids');
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        return ['deleted' => $this->db->exec("DELETE FROM form_entry WHERE site_id = ? AND id IN ({$marks})", [$site->id, ...$ids]), 'entries' => $ids];
    }

    /**
     * What a search engine would find wrong in what is published: pages no other page links to, internal links that lead
     * nowhere or through a redirect, missing, duplicate or overlong titles and descriptions, a missing date where the kind
     * has one, machine translations waiting for review.
     */
    private function seoAudit(Site $site, Actor $actor, array $input): array
    {
        $pages = $this->db->all("SELECT i.id, i.item_key, i.kind, r.lang, r.path, v.fields, v.origin FROM route r
            JOIN item i ON i.id = r.item_id JOIN publication p ON p.item_id = r.item_id AND p.lang = r.lang AND p.current = 1
            JOIN item_version v ON v.id = p.version_id WHERE r.site_id = ? AND r.status = 200 ORDER BY r.path", [$site->id]);
        $routes = [];
        foreach ($this->db->all('SELECT path, status FROM route WHERE site_id = ?', [$site->id]) as $r) {
            $routes[$r['path']] = (int) $r['status'];
        }
        $inbound = [];
        $issues = ['broken_links' => [], 'links_through_redirect' => [], 'title_missing' => [], 'title_too_long' => [], 'title_duplicate' => [],
            'description_missing' => [], 'description_too_long' => [], 'date_missing' => [], 'orphans' => [], 'machine_waiting_review' => []];
        $titles = [];
        $hosts = array_keys($site->domains);
        foreach ($pages as $p) {
            $fields = json_decode((string) $p['fields'], true);
            $title = trim((string) ($fields['seo_title'] ?? $fields['title'] ?? ''));
            if ($title === '') {
                $issues['title_missing'][] = $p['path'];
            } elseif (mb_strlen($title) > 70) {
                $issues['title_too_long'][] = ['path' => $p['path'], 'length' => mb_strlen($title)];
            }
            $titles[$p['lang'] . "\n" . mb_strtolower($title)][] = $p['path'];
            $description = trim((string) ($fields['description'] ?? ''));
            if ($description === '') {
                $issues['description_missing'][] = $p['path'];
            } elseif (mb_strlen($description) > 170) {
                $issues['description_too_long'][] = ['path' => $p['path'], 'length' => mb_strlen($description)];
            }
            if (isset($site->kind((string) $p['kind'])['fields']['date']) && empty($fields['date'])) {
                $issues['date_missing'][] = $p['path'];
            }
            if ($p['origin'] === 'machine') {
                $issues['machine_waiting_review'][] = $p['path'];
            }
            foreach ($fields as $value) {
                if (!is_string($value) || !preg_match_all('#href="([^"\#?]+)#i', $value, $m)) {
                    continue;
                }
                foreach ($m[1] as $href) {
                    $u = parse_url(html_entity_decode($href));
                    if (isset($u['host']) && !in_array(strtolower($u['host']), $hosts, true)) {
                        continue;
                    }
                    $target = '/' . trim(rawurldecode($u['path'] ?? '/'), '/');
                    $status = $routes[$target] ?? null;
                    if ($status === null || $status === 410) {
                        $issues['broken_links'][] = ['from' => $p['path'], 'to' => $target];
                    } elseif ($status !== 200) {
                        $issues['links_through_redirect'][] = ['from' => $p['path'], 'to' => $target];
                    } else {
                        $inbound[$target] = true;
                    }
                }
            }
        }
        foreach ($titles as $paths) {
            if (count($paths) > 1) {
                $issues['title_duplicate'][] = $paths;
            }
        }
        $homes = array_map(fn ($l) => $site->home($l), array_keys($site->languages));
        foreach ($pages as $p) {
            if (!isset($inbound[$p['path']]) && !in_array($p['path'], $homes, true)) {
                $issues['orphans'][] = $p['path'];
            }
        }
        // review waits only where the site asks for it
        if (\Ramonak\Content\Indexing::reviewsNeeded($site) === 0) {
            $issues['machine_waiting_review'] = [];
        }
        return ['pages' => count($pages), 'note' => 'orphans count links in the texts of pages; menus and lists of the templates also link',
            'issues' => array_filter($issues, fn ($v) => $v !== [])];
    }

    /**
     * Writes many items of a site at once, all or nothing. Each item: {item, kind, lang, fields, path?, origin?, parent?,
     * position?, publish?}. A new item is created with its kind and path; an existing one keeps its kind. Fields that did not
     * change leave the version as it is; a publish of the version already published is skipped.
     */
    private function contentImport(Site $site, Actor $actor, array $input): array
    {
        $items = self::need($input, 'items');
        if (!is_array($items) || !array_is_list($items) || count($items) > 500) {
            throw new OperationError('input_invalid', 'items is a list of at most 500 items');
        }
        $counts = ['created' => 0, 'saved' => 0, 'unchanged' => 0, 'published' => 0];
        foreach ($items as $n => $spec) {
            if (!is_array($spec) || !isset($spec['item'], $spec['kind'], $spec['lang']) || !is_array($spec['fields'] ?? null)) {
                throw new OperationError('input_invalid', "items[{$n}] needs item, kind, lang and fields (an object)");
            }
            $key = (string) $spec['item'];
            $lang = (string) $spec['lang'];
            $kind = (string) $spec['kind'];
            try {
                $item = $this->content->item($site, $key);
            } catch (OperationError $e) {
                if ($e->error !== 'item_unknown') {
                    throw $e;
                }
                $this->content->create($site, $kind, $key, isset($spec['parent']) ? (string) $spec['parent'] : null, (int) ($spec['position'] ?? 0));
                $item = $this->content->item($site, $key);
                $counts['created']++;
            }
            if ($item['kind'] !== $kind) {
                throw new OperationError('input_invalid', "items[{$n}]: {$key} is a {$item['kind']}, not a {$kind}");
            }
            if (isset($spec['path']) && $this->routes->pathOf((int) $item['id'], $lang) !== (string) $spec['path']) {
                $this->routes->set($site, (int) $item['id'], $lang, (string) $spec['path']);
            }
            $origin = (string) ($spec['origin'] ?? 'source');
            $clean = Content::check($site->kind($kind), $spec['fields']);
            $latest = $this->content->latest((int) $item['id'], $lang);
            if ($latest !== null && $latest['origin'] === $origin && $latest['fields'] === $clean) {
                $version = (int) $latest['id'];
                $counts['unchanged']++;
            } else {
                $version = $this->content->save($site, (int) $item['id'], $lang, $spec['fields'], $origin, $actor->label());
                $counts['saved']++;
            }
            if (!empty($spec['publish'])) {
                $current = $this->publication->current((int) $item['id'], $lang);
                if ($current === null || (int) $current['version_id'] !== $version) {
                    $this->publishVersion($site, $actor, $item, $lang, $version);
                    $counts['published']++;
                }
            }
        }
        return ['items' => count($items)] + $counts;
    }

    /**
     * Stores one file of a site (an image, a PDF): {data_base64, alt?: {lang: text}, caption?: {lang: text}}. The file is kept
     * once, by its checksum, in the media directory of the installation, which the web server sends as /media/; the same bytes
     * always get the same path, so adding a file twice changes nothing.
     */
    private function mediaAdd(Site $site, Actor $actor, array $input): array
    {
        if ($this->mediaDir === '') {
            throw new OperationError('media_unavailable', 'This installation has no media directory (media_dir in shared/config.php)', 503);
        }
        $data = base64_decode((string) self::need($input, 'data_base64'), true);
        if ($data === false || $data === '') {
            throw new OperationError('input_invalid', 'data_base64 is the file, in base64');
        }
        if (strlen($data) > 20 * 1024 * 1024) {
            throw new OperationError('input_invalid', 'A file is at most 20 MB');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => throw new OperationError('media_type_unsupported', "A file of the type {$mime} is not kept as media"),
        };
        $sha = hash('sha256', $data);
        $path = '/media/' . substr($sha, 0, 2) . '/' . $sha . '.' . $ext;
        $width = $height = null;
        if (str_starts_with($mime, 'image/') && ($info = @getimagesizefromstring($data)) !== false) {
            [$width, $height] = [$info[0], $info[1]];
        }
        $file = rtrim($this->mediaDir, '/') . substr($path, strlen('/media'));
        if (!is_file($file)) {
            @mkdir(dirname($file), 0755, true);
            $tmp = $file . '.' . getmypid();
            if (file_put_contents($tmp, $data) === false || !rename($tmp, $file)) {
                throw new OperationError('media_write_failed', 'The file could not be written to the media directory', 500);
            }
        }
        $langs = array_unique([...array_keys((array) ($input['alt'] ?? [])), ...array_keys((array) ($input['caption'] ?? []))]);
        foreach ($langs as $lang) {
            if (!isset($site->languages[$lang])) {
                throw new OperationError('language_unknown', "The site {$site->key} has no language {$lang}");
            }
        }
        $id = (int) $this->db->value('SELECT id FROM media WHERE site_id = ? AND path = ?', [$site->id, $path]);
        if ($id === 0) {
            $id = $this->db->insert('INSERT INTO media (site_id, path, mime, bytes, sha256, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$site->id, $path, $mime, strlen($data), $sha, $width, $height, $this->db->now()]);
        }
        foreach ($langs as $lang) {
            $this->db->exec('DELETE FROM media_text WHERE media_id = ? AND lang = ?', [$id, $lang]);
            $this->db->exec('INSERT INTO media_text (media_id, lang, alt, caption) VALUES (?, ?, ?, ?)',
                [$id, $lang, (string) ($input['alt'][$lang] ?? ''), isset($input['caption'][$lang]) ? (string) $input['caption'][$lang] : null]);
        }
        return ['path' => $path, 'mime' => $mime, 'bytes' => strlen($data), 'width' => $width, 'height' => $height, 'sha256' => $sha];
    }

    private function eventList(Site $site, Actor $actor, array $input): array
    {
        return ['events' => $this->db->all('SELECT id, actor, operation, subject, data, created_at FROM event WHERE site_id = ? ORDER BY id DESC LIMIT '
            . max(1, min(500, (int) ($input['limit'] ?? 50))), [$site->id])];
    }
}
