<?php
declare(strict_types=1);

namespace Ramonak\Render;

use Ramonak\Content\Indexing;
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
        private ?Indexing $indexing = null,
        private ?\Ramonak\Media\Images $images = null,
        private ?\Ramonak\Media\Videos $videos = null,
    ) {
        $this->indexing ??= new Indexing($db);
    }

    /** @return array{html:string, deps:list<string>} */
    /** @param array<string,string> $query the query string of the request, as the template reads it: request.query */
    public function page(Site $site, array $route, array $query = []): array
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
            'site' => ['key' => $site->key, 'name' => $site->name($lang), 'lang' => $lang, 'settings' => $site->declaration['settings'] ?? [],
                'forms' => array_map(static fn (array $form) => ['title' => $form['title'] ?? [], 'fields' => $form['fields'] ?? [], 'honeypot' => $form['honeypot'] ?? ''], (array) ($site->declaration['forms'] ?? [])),
                'prastora' => self::prastoraConfig($site->declaration['prastora'] ?? null)],
            'page' => ['key' => $route['item_key'], 'kind' => $route['kind'], 'fields' => $fields, 'path' => $path, 'url' => $site->url($path),
                'published_at' => (int) $current['published_at'], 'origin' => $current['origin']],
            'seo' => $this->seo($site, (int) $route['item_id'], (string) $route['kind'], $lang, $path, $fields, (int) $current['published_at']),
            'languages' => $this->languageLinks($site, (int) $route['item_id'], $lang),
            'request' => ['query' => $query],
        ];
        // a page of a list in pages (?page=N, N above 1) names itself as canonical: each page is indexed on its own
        $number = (string) ($query['page'] ?? '');
        if (preg_match('/^[0-9]{1,6}$/', $number) && (int) $number > 1) {
            $context['seo']['canonical'] = $site->url($path) . '?page=' . (int) $number;
        }
        $html = $this->env($site)->render((string) ($kind['template'] ?? $route['kind'] . '.twig'), $context);
        if ($this->videos !== null) {
            $html = $this->videos->rewrite($html, $site->id, $lang);
        }
        if ($this->images !== null) {
            $html = $this->images->rewrite($html, $site->id, $lang);
        }
        return ['html' => $html, 'deps' => array_keys($this->deps)];
    }

    public function notFound(Site $site, string $lang): string
    {
        $template = (string) ($site->declaration['not_found_template'] ?? '404.twig');
        try {
            // a missing page has no versions of its own: its language switch leads to the home of each language
            return $this->env($site)->render($template, ['site' => ['key' => $site->key, 'name' => $site->name($lang), 'lang' => $lang],
                'languages' => $this->languageLinks($site, 0, $lang),
                'seo' => ['title' => $site->name($lang), 'description' => '', 'canonical' => $site->url('/'), 'alternates' => [], 'x_default' => null,
                    'indexable' => false, 'locale' => $lang, 'og_type' => 'website', 'site_name' => $site->name($lang), 'image' => null, 'jsonld' => null]]);
        } catch (\Twig\Error\LoaderError) {
            return '<!doctype html><title>404</title><h1>404</h1>';
        }
    }

    /** @return array<string,mixed> */
    private function seo(Site $site, int $itemId, string $kind, string $lang, string $path, array $fields, int $publishedAt): array
    {
        $alternates = [];
        $default = null;
        $open = $this->indexing->open($site, $itemId, $lang);
        // hreflang names only the versions open to search engines, and only from a page that is open itself
        foreach ($open ? $this->indexing->openVersions($site, $itemId) : [] as $row) {
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
            'indexable' => $site->indexable && !$this->experimental && $open, 'locale' => $lang,
            'og_type' => is_string($site->declaration['kinds'][$kind]['schema'] ?? null) ? 'article' : 'website', 'site_name' => $site->name($lang),
            'image' => !empty($fields['image']) ? $site->url('/' . ltrim((string) $fields['image'], '/')) : (isset($site->declaration['image']) ? $site->url('/' . ltrim((string) $site->declaration['image'], '/')) : null),
            'jsonld' => $this->jsonLd($site, $itemId, $kind, $lang, $path, $fields, $publishedAt, $title)];
    }

    /**
     * Structured data as schema.org JSON-LD: the organization of the site (site.json schema.organization) on its home pages,
     * and the kind's own type (kinds.<kind>.schema: Article, NewsArticle, BlogPosting, WebPage) with its dates, the ones a
     * reader sees: datePublished is the field date (the date the material belongs to: of the interview, of the original
     * article), else the first publication in this language; dateModified is the field updated, set only when the material
     * was revised in substance, else the same as datePublished. Moving a text between servers or fixing a typo dates nothing.
     */
    private function jsonLd(Site $site, int $itemId, string $kind, string $lang, string $path, array $fields, int $publishedAt, string $title): ?string
    {
        $graph = [];
        $organization = self::localize($site->declaration['schema']['organization'] ?? null, $lang, $site->defaultLanguage);
        $organization = $this->withPeople($site, $organization, $lang);
        if (is_array($organization) && $path === $site->home($lang)) {
            $graph[] = ['@type' => $organization['@type'] ?? 'Organization', 'url' => $site->url($site->home($lang))] + $organization;
        }
        // a person the site describes (site.json schema.people) is given in full on the page about them (its key page)
        $itemKey = (string) $this->db->value('SELECT item_key FROM item WHERE id = ?', [$itemId]);
        foreach ((array) ($site->declaration['schema']['people'] ?? []) as $key => $person) {
            if (is_array($person) && ($person['page'] ?? null) === $itemKey) {
                $graph[] = $this->person($site, (string) $key, $lang, true);
            }
        }
        $type = $site->declaration['kinds'][$kind]['schema'] ?? null;
        if ($type === 'Person') {
            // a page about a person: ProfilePage with the person as its main entity; same_as names the same person elsewhere
            // (Wikipedia, profiles), which is how a search engine joins them into one
            $person = ['@type' => 'Person', 'name' => (string) ($fields['title'] ?? $title), 'url' => $site->url($path)];
            foreach (['alternate_name' => 'alternateName', 'job_title' => 'jobTitle', 'description' => 'description'] as $field => $property) {
                if (!empty($fields[$field])) {
                    $person[$property] = (string) $fields[$field];
                }
            }
            if (!empty($fields['image'])) {
                $person['image'] = $site->url('/' . ltrim((string) $fields['image'], '/'));
            }
            if (!empty($fields['same_as']) && is_array($fields['same_as'])) {
                $person['sameAs'] = array_values(array_filter($fields['same_as'], 'is_string'));
            }
            if (is_array($organization)) {
                $person['worksFor'] = ['@type' => $organization['@type'] ?? 'Organization', 'name' => $organization['name'] ?? $site->name($lang)];
            }
            $graph[] = ['@type' => 'ProfilePage', 'inLanguage' => $lang, 'url' => $site->url($path), 'dateModified' => gmdate('c', $publishedAt), 'mainEntity' => $person];
        } elseif (is_string($type)) {
            $first = (int) ($this->db->value('SELECT MIN(published_at) FROM publication WHERE item_id = ? AND lang = ?', [$itemId, $lang]) ?? $publishedAt);
            $published = isset($fields['date']) && is_string($fields['date']) ? $fields['date'] : gmdate('c', $first);
            $node = ['@type' => $type, 'headline' => (string) ($fields['title'] ?? $title), 'inLanguage' => $lang, 'mainEntityOfPage' => $site->url($path),
                'datePublished' => $published,
                'dateModified' => isset($fields['updated']) && is_string($fields['updated']) ? $fields['updated'] : $published];
            if (!empty($fields['description'])) {
                $node['description'] = (string) $fields['description'];
            }
            if (!empty($fields['author'])) {
                // an author the site describes is the same person on every page: a reference by @id, whatever form of the name
                $key = $this->personKey($site, (string) $fields['author']);
                $node['author'] = $key !== null ? $this->person($site, $key, $lang, false) : ['@type' => 'Person', 'name' => (string) $fields['author']];
            }
            if (!empty($fields['image'])) {
                $node['image'] = $site->url('/' . ltrim((string) $fields['image'], '/'));
            }
            if (!empty($fields['source_url'])) {
                // what the text was made from: the original article, the recording of an interview
                $node['isBasedOn'] = (string) $fields['source_url'];
            }
            if (is_array($organization)) {
                $node['publisher'] = ['@type' => $organization['@type'] ?? 'Organization', 'name' => $organization['name'] ?? $site->name($lang)];
            }
            $graph[] = $node;
        }
        if (!$graph) {
            return null;
        }
        return json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * A value of the site's declaration in one language: an object whose keys are all language tags of the site gives the
     * one of this language (else of the default); everything else is kept, its parts read the same way.
     */
    /**
     * A person of site.json schema.people in the page's language: in full (every property the site gives, links made absolute)
     * on the page about them, else a reference: @id, name and the page about them. The @id is the site's address with
     * #key, the same on every page and in every language, so that search engines join every mention into one person.
     */
    private function person(Site $site, string $key, string $lang, bool $full): array
    {
        $p = (array) self::localize($site->declaration['schema']['people'][$key] ?? [], $lang, $site->defaultLanguage);
        $node = ['@type' => $p['@type'] ?? 'Person', '@id' => $site->url('/') . '#' . $key, 'name' => (string) ($p['name'] ?? $key)];
        $page = isset($p['page']) ? $this->db->value('SELECT id FROM item WHERE site_id = ? AND item_key = ?', [$site->id, (string) $p['page']]) : null;
        $path = $page !== null ? $this->routes->pathOf((int) $page, $lang) : null;
        if ($path !== null) {
            $node['url'] = $site->url($path);
        }
        if (!$full) {
            return $node;
        }
        unset($p['@type'], $p['name'], $p['page']);
        if (isset($p['image']) && is_string($p['image']) && str_starts_with($p['image'], '/')) {
            $p['image'] = $site->url($p['image']);
        }
        return $node + (array) $this->withPeople($site, $p, $lang);
    }

    /** The key of the person of site.json schema.people a name stands for: the key itself, or any of their names in any language. */
    private function personKey(Site $site, string $name): ?string
    {
        $norm = fn (string $v) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
        foreach ((array) ($site->declaration['schema']['people'] ?? []) as $key => $p) {
            $names = [(string) $key];
            foreach (['name', 'alternateName'] as $field) {
                $value = $p[$field] ?? [];
                foreach (is_array($value) ? $value : [$value] as $v) {
                    foreach (is_array($v) ? $v : [$v] as $one) {
                        $names[] = (string) $one;
                    }
                }
            }
            if (in_array($norm($name), array_map($norm, $names), true)) {
                return (string) $key;
            }
        }
        return null;
    }

    /** Values "#key" that name a person of site.json schema.people become references to them (as an organization's founder). */
    private function withPeople(Site $site, mixed $value, string $lang): mixed
    {
        if (is_string($value) && str_starts_with($value, '#') && isset($site->declaration['schema']['people'][substr($value, 1)])) {
            return $this->person($site, substr($value, 1), $lang, false);
        }
        return is_array($value) ? array_map(fn ($v) => $this->withPeople($site, $v, $lang), $value) : $value;
    }

    public static function localize(mixed $value, string $lang, string $default): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && !array_is_list($value) && count(array_filter(array_keys($value), fn ($k) => !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/', (string) $k))) === 0) {
            return $value[$lang] ?? $value[$default] ?? reset($value);
        }
        return array_map(fn ($v) => self::localize($v, $lang, $default), $value);
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
        // newest: last published on this server first; date: by the item's own date (of the article, the interview), newest
        // first, which is what a reader expects of news moved from an old site in one day; position: as the site orders them
        $order = match ((string) ($options['order'] ?? 'newest')) {
            'position' => 'i.position, i.id',
            'date' => "json_extract(v.fields, '$.date') DESC, p.published_at DESC, i.id DESC",
            default => 'p.published_at DESC, i.id DESC',
        };
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

    /** The published items of a kind in a language, for pagers in templates: count_items('news'). */
    public function count(Site $site, string $lang, string $kind): int
    {
        $this->deps['kind:' . $site->id . ':' . $kind] = true;
        return (int) $this->db->value("SELECT COUNT(*) FROM item i
            JOIN publication p ON p.item_id = i.id AND p.lang = ? AND p.current = 1
            JOIN route r ON r.item_id = i.id AND r.lang = p.lang AND r.status = 200
            WHERE i.site_id = ? AND i.kind = ? AND i.state = 'active'", [$lang, $site->id, $kind]);
    }

    /** Optional UI selection, validated before any Prastora asset URL is rendered. */
    private static function prastoraConfig(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !is_string($raw['base'] ?? null)) {
            throw new \InvalidArgumentException('prastora requires a base asset path');
        }
        $base = rtrim($raw['base'], '/');
        if (!preg_match('~^/(?!/)[A-Za-z0-9_./-]+$~', $base) || str_contains($base, '..')) {
            throw new \InvalidArgumentException('prastora base must be a site-local pinned asset path');
        }
        $modules = $raw['modules'] ?? [];
        $enhance = $raw['enhance'] ?? [];
        if (!is_array($modules) || !array_is_list($modules) || !is_array($enhance) || !array_is_list($enhance)) {
            throw new \InvalidArgumentException('prastora modules and enhance must be lists');
        }
        $allowed = ['header', 'forms', 'news', 'languages', 'video', 'map'];
        if (count($modules) !== count(array_unique($modules)) || count($enhance) !== count(array_unique($enhance))) {
            throw new \InvalidArgumentException('prastora module names must not repeat');
        }
        foreach ($modules as $module) {
            if (!is_string($module) || !in_array($module, $allowed, true)) {
                throw new \InvalidArgumentException('unknown prastora module');
            }
        }
        foreach ($enhance as $module) {
            if (!is_string($module) || !in_array($module, ['header', 'video', 'map'], true) || !in_array($module, $modules, true)) {
                throw new \InvalidArgumentException('prastora enhancement must name an enabled script module');
            }
        }
        return ['base' => $base, 'modules' => $modules, 'enhance' => $enhance];
    }

    private function env(Site $site): Environment
    {
        if (isset($this->twig[$site->id])) {
            return $this->twig[$site->id];
        }
        $frameworkDir = dirname(__DIR__, 2) . '/templates';
        $loader = new FilesystemLoader([$site->dir . '/' . ($site->declaration['templates'] ?? 'templates')]);
        $loader->addPath($frameworkDir, 'ramonak');
        // compiled templates live under the fingerprint of the templates: a changed template is compiled again, never a stale one
        $fingerprint = substr(TemplateWatch::of([$site->dir . '/' . ($site->declaration['templates'] ?? 'templates'), $frameworkDir]), 0, 16);
        $twig = new Environment($loader, ['cache' => $this->varDir . '/twig/' . $site->key . '/' . $fingerprint, 'auto_reload' => $this->autoReload,
            'strict_variables' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('items', function (array $context, string $kind, array $options = []) use ($site): array {
            return $this->items($site, (string) ($context['site']['lang'] ?? $site->defaultLanguage), $kind, $options);
        }, ['needs_context' => true]));
        // image('/media/ab/<sha>.avif', {sizes: '20rem', class: '...'}): the full img of a library image, as rewrite() makes it
        $twig->addFunction(new TwigFunction('image', function (array $context, string $src, array $attrs = []) use ($site): string {
            $attrs = array_map('strval', $attrs);
            return $this->images?->tag($site->id, (string) ($context['site']['lang'] ?? $site->defaultLanguage), $src, $attrs)
                ?? '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars($attrs['alt'] ?? '', ENT_QUOTES) . '">';
        }, ['needs_context' => true, 'is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('count_items', function (array $context, string $kind) use ($site): int {
            return $this->count($site, (string) ($context['site']['lang'] ?? $site->defaultLanguage), $kind);
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
