<?php
declare(strict_types=1);

namespace Ramonak;

use Ramonak\Bridge\Bridge;
use Ramonak\Content\Content;
use Ramonak\Content\Publication;
use Ramonak\Content\Routes;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Jobs\MessagesTranslator;
use Ramonak\Jobs\Queue;
use Ramonak\Jobs\Translate;
use Ramonak\Jobs\Translator;
use Ramonak\Operation\Operations;
use Ramonak\Render\PageCache;
use Ramonak\Render\Renderer;
use Ramonak\Render\Sitemap;

/**
 * The kernel: reads the installation's configuration, composes the parts, and answers one request. A published page it
 * renders is written as a built file, which the web server sends by itself from then on.
 */
final class Kernel
{
    public readonly Db $db;
    public readonly Sites $sites;
    public readonly Content $content;
    public readonly Publication $publication;
    public readonly Routes $routes;
    public readonly PageCache $cache;
    public readonly Queue $queue;
    public readonly Operations $operations;
    private ?Renderer $renderer = null;
    private ?Translator $translator;

    public function __construct(public readonly Config $config, ?Translator $translator = null)
    {
        $db = $config->require('database');
        $this->db = new Db((string) $db['dsn'], $db['user'] ?? null, $db['password'] ?? null);
        $var = (string) $config->require('var_dir');
        $this->sites = new Sites($this->db, (string) $config->require('sites_dir'), $var);
        $this->content = new Content($this->db);
        $this->publication = new Publication($this->db);
        $this->routes = new Routes($this->db);
        $this->cache = new PageCache($this->db, (string) $config->require('cache_dir'));
        $this->queue = new Queue($this->db);
        $this->operations = new Operations($this->db, $this->content, $this->publication, $this->routes, $this->cache, $this->queue, $config->experimental());
        $this->translator = $translator ?? $this->configuredTranslator();
    }

    private function configuredTranslator(): ?Translator
    {
        $t = $this->config->get('translator');
        return is_array($t) && !empty($t['url']) && !empty($t['key'])
            ? new MessagesTranslator((string) $t['url'], (string) $t['key'], (string) ($t['model'] ?? 'claude-sonnet-5-5')) : null;
    }

    public function migrate(): array
    {
        return (new Migrator($this->db, dirname(__DIR__) . '/migrations'))->migrate();
    }

    /** @return array<string,callable(array):string> */
    public function jobHandlers(): array
    {
        return ['translate' => new Translate($this->db, $this->sites, $this->content, $this->publication, $this->routes, $this->cache, $this->translator)];
    }

    private function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer($this->db, $this->publication, $this->routes, (string) $this->config->require('var_dir'),
            $this->config->experimental(), (bool) $this->config->get('template_auto_reload', false));
    }

    public function handle(Request $request): Response
    {
        $response = $this->respond($request);
        if ($this->config->experimental()) {
            $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        return $response;
    }

    private function respond(Request $request): Response
    {
        $site = $this->sites->byHost($request->host);
        if ($site === null) {
            return Response::text('No site at this address', 404);
        }
        if (($site->domains[$request->host] ?? '') === 'alias') {
            return Response::redirect($site->url($request->path) . ($request->query !== '' ? '?' . $request->query : ''));
        }
        if ($request->path === '/_ramonak/api') {
            return (new Bridge($this->db, $this->operations, (array) $this->config->get('bridge', [])))->handle($request, $site);
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response(405, '', ['Allow' => 'GET, HEAD']);
        }
        if ($request->path === '/robots.txt') {
            return Response::text((new Sitemap($this->db, $this->config->experimental()))->robots($site));
        }
        if ($request->path === '/sitemap.xml') {
            return Response::text((new Sitemap($this->db, $this->config->experimental()))->xml($site), 200, 'application/xml');
        }
        try {
            $path = Routes::normalize($request->path);
        } catch (OperationError) {
            return $this->notFound($site, $site->defaultLanguage);
        }
        $route = $this->routes->resolve($site, $path);
        if ($route === null && $path === '/' && $site->prefixedOnly()) {
            // the root of a site whose languages all have prefixes chooses one for the visitor: their browser's, else the
            // default (x-default); never built as a file, as it differs by visitor
            $to = $site->home($site->negotiate($request->header('accept-language')));
            return new Response(302, '', ['Location' => $site->url($to), 'Vary' => 'Accept-Language', 'Cache-Control' => 'private, no-store']);
        }
        if ($route === null) {
            return $this->notFound($site, $site->language($path));
        }
        if ($path !== $request->path) {
            // one form of every address: /a/ and /a//b lead to /a and /a/b
            return Response::redirect($site->url($path) . ($request->query !== '' ? '?' . $request->query : ''));
        }
        $status = (int) $route['status'];
        if ($status === 301 || $status === 302) {
            $target = (string) $route['target'];
            return Response::redirect(str_starts_with($target, '/') ? $site->url($target) : $target, $status);
        }
        if ($status === 410) {
            $notFound = $this->notFound($site, (string) $route['lang']);
            $notFound->status = 410;
            return $notFound;
        }
        if ($this->publication->current((int) $route['item_id'], (string) $route['lang']) === null) {
            return $this->notFound($site, (string) $route['lang']);
        }
        $page = $this->renderer()->page($site, $route);
        if ($request->method === 'GET' && $request->query === '' && $request->host === $site->primaryDomain() && ($site->declaration['build'] ?? true)) {
            $this->cache->store($site->id, $request->host, $path, $page['html'], $page['deps']);
        }
        return Response::html($page['html']);
    }

    private function notFound(Site $site, string $lang): Response
    {
        return Response::html($this->renderer()->notFound($site, $lang), 404);
    }
}
