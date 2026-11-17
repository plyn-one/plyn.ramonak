<?php
declare(strict_types=1);

namespace Ramonak\Jobs;

use Ramonak\Content\Content;
use Ramonak\Content\Publication;
use Ramonak\Content\Routes;
use Ramonak\Db;
use Ramonak\Render\PageCache;
use Ramonak\Sites;

/**
 * The job translate: a machine version of an item in a language, from its newest source version, published at once. An
 * edited version stops it (unless the request said replace_edited); the path follows the source path under the language's prefix.
 */
final class Translate
{
    public function __construct(private Db $db, private Sites $sites, private Content $content, private Publication $publication,
        private Routes $routes, private PageCache $cache, private ?Translator $translator) {}

    public function __invoke(array $job): string
    {
        $site = $this->sites->byKey((string) $job['site']) ?? throw new \RuntimeException('no site ' . $job['site']);
        $item = $this->content->item($site, (string) $job['item']);
        $source = $this->content->latestSource((int) $item['id']);
        if ($source === null) {
            return 'no source version';
        }
        if ((int) $source['id'] !== (int) $job['source_version']) {
            return 'superseded by source version ' . $source['id'];
        }
        $to = (string) $job['to'];
        $latest = $this->content->latest((int) $item['id'], $to);
        if ($latest !== null && $latest['origin'] === 'edited' && empty($job['force'])) {
            return 'kept the edited version ' . $latest['id'];
        }
        if ($latest !== null && $latest['origin'] === 'machine' && (int) $latest['source_version_id'] === (int) $source['id']) {
            return 'already translated';
        }
        if ($this->translator === null) {
            throw new \RuntimeException('no translator configured');
        }
        $spec = $site->kind((string) $item['kind'])['fields'] ?? [];
        $fields = json_decode((string) $source['fields'], true);
        $translatable = [];
        $types = [];
        foreach ($fields as $name => $value) {
            $type = $spec[$name]['type'] ?? 'text';
            if (in_array($type, ['text', 'html', 'markdown'], true) && ($spec[$name]['translate'] ?? true)) {
                $translatable[$name] = $value;
                $types[$name] = $type;
            }
        }
        $translated = $this->translator->translate($translatable, $types, (string) $source['lang'], $to, $site->declaration['glossary'][(string) $source['lang']][$to] ?? []);
        $version = $this->content->save($site, (int) $item['id'], $to, array_merge($fields, $translated), 'machine', 'ramonak/translate', (int) $source['id']);
        if ($this->routes->pathOf((int) $item['id'], $to) === null) {
            $sourcePath = $this->routes->pathOf((int) $item['id'], (string) $source['lang']);
            if ($sourcePath === null) {
                return "translated as version {$version}; not published: the source has no path";
            }
            $from = $site->languages[(string) $source['lang']]['prefix'];
            $bare = $from === '' ? $sourcePath : (substr($sourcePath, strlen('/' . $from)) ?: '/');
            $prefix = $site->languages[$to]['prefix'];
            $this->routes->set($site, (int) $item['id'], $to, $prefix === '' ? $bare : '/' . $prefix . ($bare === '/' ? '' : $bare));
        }
        $this->publication->publish($site, (int) $item['id'], $to, $version, 'ramonak/translate');
        $this->cache->invalidate(['item:' . $item['id'], 'kind:' . $site->id . ':' . $item['kind']]);
        return "published machine version {$version} in {$to}";
    }
}
