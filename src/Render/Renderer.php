<?php
declare(strict_types=1);

namespace Ramonak\Render;

use Ramonak\Content\Publication;
use Ramonak\Content\Routes;
use Ramonak\Db;
use Ramonak\Site;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders a published item with its kind's template and the SEO contract, and records what the page was made from, so that
 * a built page is removed exactly when one of its sources changes.
 */
final class Renderer
{
    /** @var array<int,Environment> */
    private array $twig = [];
    /** @var array<string,true> */
    private array $deps = [];

    public function __construct(
        private Db $db,
        private Publication $publication,
        private Routes $routes,
        private string $varDir,
        private bool $experimental,
        private bool $autoReload = false,
    ) {}

    /** @return array{html:string, deps:list<string>} */
    public function page(Site $site, array $route): array
    {
        $this->deps = ['item:' . $route['item_id'] => true];
        $lang = (string) $route['lang'];
        $current = $this->publication->current((int) $route['item_id'], $lang);
        if ($current === null) {
            throw new \LogicException('page() needs a published item');
        }
        $kind = $site->kind((string) $route['kind']);
        $fields = $current['fields'];
        $path = $this->routes->pathOf((int) $route['item_id'], $lang) ?? '/';
        $context = [
            'site' => ['key' => $site->key, 'name' => $site->name($lang), 'lang' => $lang, 'settings' => $site->declaration['settings'] ?? []],
            'page' => ['key' => $route['item_key'], 'kind' => $route['kind'], 'fields' => $fields, 'path' => $path, 'url' => $site->url($path),
                'published_at' => (int) $current['published_at'], 'origin' => $current['origin']],
            'seo' => $this->seo($site, (int) $route['item_id'], $lang, $path, $fields),
            'languages' => $this->languageLinks($site, (int) $route['item_id'], $lang),
        ];
        $html = $this->env($site)->render((string) ($kind['template'] ?? $route['kind'] . '.twig'), $context);
        return ['html' => $html, 'deps' => array_keys($this->deps)];
    }

    public function notFound(Site $site, string $lang): string
    {
        $template = (string) ($site->declaration['not_found_template'] ?? '404.twig');
        try {
            return $this->env($site)->render($template, ['site' => ['key' => $site->key, 'name' => $site->name($lang), 'lang' => $lang],
                'seo' => ['title' => $site->name($lang), 'description' => '', 'canonical' => $site->url('/'), 'alternates' => [], 'x_default' => null,
                    'indexable' => false, 'locale' => $lang]]);
        } catch (\Twig\Error\LoaderError) {
            return '<!doctype html><title>404</title><h1>404</h1>';
        }
    }

    /** @return array<string,mixed> */
    private function seo(Site $site, int $itemId, string $lang, string $path, array $fields): array
    {
        $alternates = [];
        $default = null;
        foreach ($this->published($itemId) as $row) {
            $url = $site->url((string) $row['path']);
            $alternates[] = ['lang' => $row['lang'], 'url' => $url];
            if ($row['lang'] === $site->defaultLanguage) {
                $default = $url;
            }
        }
        $title = (string) ($fields['seo_title'] ?? $fields['title'] ?? $site->name($lang));
        if (($fields['seo_title'] ?? null) === null && $path !== '/' && !str_contains($title, $site->name($lang))) {
            $title .= ' | ' . $site->name($lang);
        }
        return ['title' => $title, 'description' => (string) ($fields['description'] ?? ''), 'canonical' => $site->url($path),
            'alternates' => count($alternates) > 1 ? $alternates : [],
            // x-default: the root that chooses a language, where every language has its prefix; otherwise the default language
            'x_default' => count($alternates) > 1 ? ($site->prefixedOnly() && $path === $this->routes->homePath($site, $lang) ? $site->url('/') : ($default ?? $alternates[0]['url'])) : null,
            'indexable' => $site->indexable && !$this->experimental, 'locale' => $lang];
    }

    /** @return list<array{lang:string, path:string}> the languages an item is published in, with their paths */
    private function published(int $itemId): array
    {
        return $this->db->all('SELECT r.lang, r.path FROM route r JOIN publication p ON p.item_id = r.item_id AND p.lang = r.lang AND p.current = 1
            WHERE r.item_id = ? AND r.status = 200', [$itemId]);
    }

    /** @return list<array{lang:string, url:string, path:string, current:bool}> */
    private function languageLinks(Site $site, int $itemId, string $lang): array
    {
        $paths = array_column($this->published($itemId), 'path', 'lang');
        $out = [];
        foreach ($site->languages as $code => $l) {
            $path = $paths[$code] ?? ($l['prefix'] === '' ? '/' : '/' . $l['prefix']);
            $out[] = ['lang' => $code, 'path' => $path, 'url' => $site->url($path), 'current' => $code === $lang];
        }
        return $out;
    }

    /**
     * Published items of a kind in a language, for lists in templates: items('news', {limit: 10, parent: 'news'}).
     * @return list<array<string,mixed>>
     */
    public function items(Site $site, string $lang, string $kind, array $options = []): array
    {
        $this->deps['kind:' . $site->id . ':' . $kind] = true;
        $limit = max(1, min(500, (int) ($options['limit'] ?? 50)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $order = ($options['order'] ?? 'newest') === 'position' ? 'i.position, i.id' : 'p.published_at DESC, i.id DESC';
        $params = [$site->id, $kind, $lang];
        $parent = '';
        if (isset($options['parent'])) {
            $parent = ' AND i.parent_id = (SELECT id FROM item WHERE site_id = ? AND item_key = ?)';
            array_push($params, $site->id, (string) $options['parent']);
        }
        $rows = $this->db->all("SELECT i.id, i.item_key, r.path, v.fields, p.published_at FROM item i
            JOIN publication p ON p.item_id = i.id AND p.lang = ? AND p.current = 1
            JOIN item_version v ON v.id = p.version_id
            JOIN route r ON r.item_id = i.id AND r.lang = p.lang AND r.status = 200
            WHERE i.site_id = ? AND i.kind = ? AND i.state = 'active'{$parent} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
            [$lang, ...array_slice($params, 0, 2), ...array_slice($params, 3)]);
        return array_map(fn ($r) => ['key' => $r['item_key'], 'path' => $r['path'], 'url' => $site->url((string) $r['path']),
            'fields' => json_decode((string) $r['fields'], true), 'published_at' => (int) $r['published_at']], $rows);
    }

    private function env(Site $site): Environment
    {
        if (isset($this->twig[$site->id])) {
            return $this->twig[$site->id];
        }
        $loader = new FilesystemLoader([$site->dir . '/' . ($site->declaration['templates'] ?? 'templates')]);
        $loader->addPath(dirname(__DIR__, 2) . '/templates', 'ramonak');
        $twig = new Environment($loader, ['cache' => $this->varDir . '/twig/' . $site->key, 'auto_reload' => $this->autoReload,
            'strict_variables' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('items', function (array $context, string $kind, array $options = []) use ($site): array {
            return $this->items($site, (string) ($context['site']['lang'] ?? $site->defaultLanguage), $kind, $options);
        }, ['needs_context' => true]));
        $twig->addFunction(new TwigFunction('path', function (array $context, string $itemKey) use ($site): ?string {
            $id = $this->db->value('SELECT id FROM item WHERE site_id = ? AND item_key = ?', [$site->id, $itemKey]);
            if ($id === null) {
                return null;
            }
            $this->deps['item:' . $id] = true;
            return $this->routes->pathOf((int) $id, (string) ($context['site']['lang'] ?? $site->defaultLanguage));
        }, ['needs_context' => true]));
        return $this->twig[$site->id] = $twig;
    }
}
