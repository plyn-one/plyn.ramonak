<?php
declare(strict_types=1);

namespace Ramonak;

use Ramonak\Bridge\Bridge;
use Ramonak\Content\Content;
use Ramonak\Content\Publication;
use Ramonak\Content\Routes;
use Ramonak\Forms\Forms;
use Ramonak\Forms\Mailer;
use Ramonak\Forms\Notify;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Jobs\IndexNow;
use Ramonak\Jobs\MessagesTranslator;
use Ramonak\Jobs\Queue;
use Ramonak\Jobs\Translate;
use Ramonak\Jobs\Translator;
use Ramonak\Media\AvifCodec;
use Ramonak\Media\Codec;
use Ramonak\Media\Images;
use Ramonak\Operation\Operations;
use Ramonak\Render\PageCache;
use Ramonak\Render\Renderer;
use Ramonak\Render\Sitemap;
use Ramonak\Render\TemplateWatch;

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
    public readonly TemplateWatch $templates;
    public readonly Operations $operations;
    /** the images of the media library as pages get them; null without a media directory */
    public readonly ?Images $images;
    private ?Renderer $renderer = null;
    private ?Translator $translator;
    /** @var (callable(array):string)|null a stand-in for the IndexNow job, for tests */
    public $indexNow = null;
    /** @var Mailer|null a stand-in for the configured mail, for tests */
    public ?Mailer $mailer = null;

    public function __construct(public readonly Config $config, ?Translator $translator = null, ?Codec $codec = null)
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
        $this->templates = new TemplateWatch($this->db, $this->sites, $this->cache, $var, dirname(__DIR__) . '/templates');
        $mediaDir = (string) $config->get('media_dir', '');
        $codec ??= AvifCodec::usable() ? new AvifCodec() : null;
        $quality = (int) $config->get('media_quality', 55);
        $this->images = $mediaDir !== '' && $codec !== null ? new Images($this->db, $mediaDir, $codec, $quality) : null;
        $this->operations = new Operations($this->db, $this->content, $this->publication, $this->routes, $this->cache, $this->queue, $config->experimental(), $mediaDir, $codec, $quality);
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
        return [
            'translate' => new Translate($this->db, $this->sites, $this->content, $this->publication, $this->routes, $this->cache, $this->translator),
            'publish' => function (array $job): string {
                $site = $this->sites->byKey((string) $job['site']) ?? throw new \RuntimeException('no site ' . $job['site']);
                $done = $this->operations->run($site, new Operation\Actor((string) $job['by'], 'davierany', isset($job['agent']) ? (string) $job['agent'] . '/scheduled' : 'scheduled'),
                    'publication.publish', ['item' => $job['item'], 'lang' => $job['lang'], 'version' => $job['version']]);
                return 'published as publication ' . $done['publication'];
            },
            'form.notify' => new Notify($this->db, $this->sites, \Closure::fromCallable([$this, 'mailerFor'])),
            'indexnow' => $this->indexNow ?? new IndexNow($this->sites, (array) $this->config->get('indexnow', []), $this->config->experimental()),
        ];
    }

    /** The mail of a site: its own under mail.sites.<key>, else the installation's; null when none is configured. */
    public function mailerFor(string $site): ?Mailer
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }
        $mail = (array) $this->config->get('mail', []);
        $own = $mail['sites'][$site] ?? null;
        unset($mail['sites']);
        $c = is_array($own) ? $own : $mail;
        return isset($c['from']) ? Mailer::fromConfig($c) : null;
    }

    private function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer($this->db, $this->publication, $this->routes, (string) $this->config->require('var_dir'),
            $this->config->experimental(), (bool) $this->config->get('template_auto_reload', false), null, $this->images);
    }

    public function handle(Request $request): Response
    {
        $response = $this->respond($request);
        if ($this->config->experimental()) {
            $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['X-Frame-Options'] = 'SAMEORIGIN';
        $response->headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        if (!$this->config->experimental()) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $response;
    }

    private function respond(Request $request): Response
    {
        $site = $this->sites->byHost($request->host);
        if ($site === null) {
            return Response::text('No site at this address', 404);
        }
        if (!$request->https && $this->config->get('force_https', true)) {
            return Response::redirect('https://' . $request->host . $request->path . ($request->query !== '' ? '?' . $request->query : ''));
        }
        if (($site->domains[$request->host] ?? '') === 'alias') {
            return Response::redirect($site->url($request->path) . ($request->query !== '' ? '?' . $request->query : ''));
        }
        if ($this->images !== null && str_starts_with($request->path, '/media/w')) {
            // a smaller copy of a library image, made on its first request; the web server sends it by itself from then on
            return $this->images->serve($request->path) ?? Response::text('No such image', 404);
        }
        if ($request->path === '/_ramonak/api') {
            return (new Bridge($this->db, $this->operations, (array) $this->config->get('bridge', [])))->handle($request, $site);
        }
        if (str_starts_with($request->path, '/_ramonak/form/')) {
            $salt = (string) $this->config->get('forms_salt', hash('sha256', (string) $this->config->require('var_dir') . $site->key));
            return (new Forms($this->db, $this->queue, $salt))->handle($site, $request, substr($request->path, strlen('/_ramonak/form/')));
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response(405, '', ['Allow' => 'GET, HEAD']);
        }
        if ($request->path === '/robots.txt') {
            return Response::text((new Sitemap($this->db, $this->config->experimental()))->robots($site));
        }
        $indexNowKey = ((array) $this->config->get('indexnow', []))[$site->key] ?? null;
        if (is_string($indexNowKey) && $request->path === '/' . $indexNowKey . '.txt') {
            return Response::text($indexNowKey);
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
        if ($route === null && $path !== mb_strtolower($path) && $this->routes->resolve($site, mb_strtolower($path)) !== null) {
            // an address typed or linked in capitals leads to its one form
            return Response::redirect($site->url(mb_strtolower($path)) . ($request->query !== '' ? '?' . $request->query : ''));
        }
        if ($route === null && $path === '/' && $site->prefixedOnly()) {
            // the root of a site whose languages all have prefixes chooses one for the visitor: their browser's, else the
            // default (x-default); never built as a file, as it differs by visitor
            $to = $site->home($site->negotiate($request->header('accept-language')));
            return new Response(302, '', ['Location' => $site->url($to), 'Vary' => 'Accept-Language', 'Cache-Control' => 'private, no-store']);
        }
        if ($route === null) {
            return $this->notFound($site, $site->language($path));
        }
        $status = (int) $route['status'];
        if ($status === 301 || $status === 302) {
            // a redirect is followed at once, also from another form of its address (/old/ as /old): one hop, not two
            $target = (string) $route['target'];
            return Response::redirect(str_starts_with($target, '/') ? $site->url($target) : $target, $status);
        }
        if ($path !== $request->path) {
            // one form of every address: /a/ and /a//b lead to /a and /a/b
            return Response::redirect($site->url($path) . ($request->query !== '' ? '?' . $request->query : ''));
        }
        if ($status === 410) {
            $notFound = $this->notFound($site, (string) $route['lang']);
            $notFound->status = 410;
            return $notFound;
        }
        if ($this->publication->current((int) $route['item_id'], (string) $route['lang']) === null) {
            return $this->notFound($site, (string) $route['lang']);
        }
        $page = $this->renderer()->page($site, $route, self::requestQuery($request->query));
        if ($request->method === 'GET' && $request->query === '' && $request->host === $site->primaryDomain() && ($site->declaration['build'] ?? true)) {
            $this->cache->store($site->id, $request->host, $path, $page['html'], $page['deps']);
        }
        return Response::html($page['html']);
    }

    /** The query string of a page: its values as text, for the template (request.query.<name>). */
    private static function requestQuery(string $query): array
    {
        parse_str($query, $values);
        return array_filter($values, fn ($v) => is_string($v) && mb_strlen($v) <= 200);
    }

    private function notFound(Site $site, string $lang): Response
    {
        return Response::html($this->renderer()->notFound($site, $lang), 404);
    }
}
