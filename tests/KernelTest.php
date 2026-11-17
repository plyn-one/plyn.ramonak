<?php
declare(strict_types=1);

namespace Ramonak\Tests;

use PHPUnit\Framework\TestCase;
use Ramonak\Bridge\Bridge;
use Ramonak\Config;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Jobs\Translator;
use Ramonak\Kernel;
use Ramonak\Operation\Actor;
use Ramonak\OperationError;
use Ramonak\Site;

/** The first slice end to end on SQLite, with the example site of examples/sites/example. */
final class KernelTest extends TestCase
{
    private string $dir;
    private Kernel $k;
    private Site $site;
    private Actor $agent;
    /** @var list<array{string,string}> */
    private array $translated = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ramonak-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0777, true);
        $this->k = $this->kernel(experimental: false);
        $this->k->migrate();
        $this->site = $this->k->sites->sync('example');
        $this->agent = new Actor('m-1', 'davierany', 'claude');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function kernel(bool $experimental): Kernel
    {
        $test = $this;
        $translator = new class($test) implements Translator {
            public function __construct(private KernelTest $test) {}
            public function translate(array $fields, array $types, string $from, string $to, array $glossary = []): array
            {
                $this->test->noteTranslation($from, $to);
                return array_map(fn ($v) => "[{$to}] " . $v, $fields);
            }
        };
        return new Kernel(new Config(['database' => ['dsn' => 'sqlite:' . $this->dir . '/db.sqlite'], 'var_dir' => $this->dir . '/var',
            'sites_dir' => dirname(__DIR__) . '/examples/sites', 'cache_dir' => $this->dir . '/public/_cache', 'experimental' => $experimental,
            'template_auto_reload' => true, 'bridge' => ['example' => str_repeat('k', 40)]]), $translator);
    }

    public function noteTranslation(string $from, string $to): void
    {
        $this->translated[] = [$from, $to];
    }

    private function op(string $name, array $input, ?Actor $actor = null): array
    {
        return $this->k->operations->run($this->site, $actor ?? $this->agent, $name, $input);
    }

    private function get(string $path, string $host = 'example.test', string $method = 'GET', string $query = ''): Response
    {
        return $this->k->handle(new Request($method, $host, $path, $query));
    }

    private function page(string $key, string $kind, string $path, array $fields, bool $publish = true): array
    {
        $this->op('content.create', ['item' => $key, 'kind' => $kind, 'lang' => 'ru', 'path' => $path]);
        return $this->op('content.save', ['item' => $key, 'lang' => 'ru', 'fields' => $fields, 'publish' => $publish]);
    }

    public function testPublishedPageIsRenderedWithTheSeoContractAndBuilt(): void
    {
        $this->page('home', 'home', '/', ['title' => 'Бюро', 'description' => 'Адвокаты', 'body' => '<p>Текст</p>']);
        $r = $this->get('/');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<title>Бюро</title>', $r->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/">', $r->body);
        $this->assertStringContainsString('<meta name="description" content="Адвокаты">', $r->body);
        $this->assertStringNotContainsString('noindex', $r->body);
        $this->assertStringNotContainsString('hreflang', $r->body, 'one language published: no alternates');
        $this->assertFileExists($this->dir . '/public/_cache/example.test/index.html');
        $this->assertSame($r->body, file_get_contents($this->dir . '/public/_cache/example.test/index.html'));
    }

    public function testDraftsAreNotPublicAndRollbackRepublishesTheEarlierVersion(): void
    {
        $this->page('about', 'page', '/about-us', ['title' => 'О нас v1'], publish: false);
        $this->assertSame(404, $this->get('/about-us')->status, 'a draft is not public');
        $this->op('publication.publish', ['item' => 'about', 'lang' => 'ru']);
        $this->assertStringContainsString('О нас v1', $this->get('/about-us')->body);
        $this->op('content.save', ['item' => 'about', 'lang' => 'ru', 'fields' => ['title' => 'О нас v2'], 'publish' => true]);
        $this->assertStringContainsString('О нас v2', $this->get('/about-us')->body);
        $this->assertFileExists($this->dir . '/public/_cache/example.test/about-us/index.html');
        $back = $this->op('publication.rollback', ['item' => 'about', 'lang' => 'ru']);
        $this->assertSame(1, $back['pages_removed']);
        $this->assertStringContainsString('О нас v1', $this->get('/about-us')->body);
        $read = $this->op('content.read', ['item' => 'about']);
        $this->assertSame(3, count($read['languages']['ru']['history']), 'every publication is kept');
    }

    public function testAddressesKeepAnswering(): void
    {
        $this->page('asylum', 'page', '/services/asylum', ['title' => 'Убежище']);
        $this->assertSame(200, $this->get('/services/asylum')->status);
        $slash = $this->get('/services/asylum/');
        $this->assertSame([301, 'https://example.test/services/asylum'], [$slash->status, $slash->headers['Location']]);
        $alias = $this->get('/services/asylum', 'www.example.test');
        $this->assertSame([301, 'https://example.test/services/asylum'], [$alias->status, $alias->headers['Location']]);
        $this->op('route.set', ['item' => 'asylum', 'lang' => 'ru', 'path' => '/uslugi/ubezhishhe']);
        $this->op('route.set', ['item' => 'asylum', 'lang' => 'ru', 'path' => '/uslugi/asylum']);
        foreach (['/services/asylum', '/uslugi/ubezhishhe'] as $old) {
            $r = $this->get($old);
            $this->assertSame([301, 'https://example.test/uslugi/asylum'], [$r->status, $r->headers['Location']], "{$old} leads straight to the newest path");
        }
        $import = $this->op('redirect.import', ['redirects' => [['path' => '/services/services/asylum', 'target' => '/uslugi/asylum'],
            ['path' => '/old-page', 'status' => 410], ['path' => '/uslugi/asylum', 'target' => '/x']]]);
        $this->assertSame(2, $import['imported']);
        $this->assertSame('path_taken', $import['refused'][0]['error'], 'a redirect never hides a page');
        $this->assertSame(410, $this->get('/old-page')->status);
        $this->assertSame(404, $this->get('/nothing-here')->status);
        $this->assertSame(405, $this->get('/uslugi/asylum', method: 'DELETE')->status);
    }

    public function testListsAreRebuiltWhenAnItemOfTheirKindIsPublished(): void
    {
        $this->page('home', 'home', '/', ['title' => 'Бюро']);
        $this->page('n1', 'news', '/news/one', ['title' => 'Первая', 'date' => '2026-11-01']);
        $this->assertStringContainsString('Первая', $this->get('/')->body);
        $this->get('/news/one');
        $this->assertFileExists($this->dir . '/public/_cache/example.test/index.html');
        $this->page('n2', 'news', '/news/two', ['title' => 'Вторая', 'date' => '2026-11-02']);
        $this->assertFileDoesNotExist($this->dir . '/public/_cache/example.test/index.html', 'the list page went with the new item of its kind');
        $this->assertStringContainsString('Вторая', $this->get('/')->body);
        $this->assertFileExists($this->dir . '/public/_cache/example.test/news/one/index.html', 'a page that lists nothing stays built');
    }

    public function testItemsAreOrderedByTheirOwnDateWhenAsked(): void
    {
        // news moved from an old site in one day: published here in any order, read by the date each was written
        $this->page('n1', 'news', '/news/later', ['title' => 'Позже', 'date' => '2021-05-14']);
        $this->page('n2', 'news', '/news/earlier', ['title' => 'Раньше', 'date' => '2017-04-21']);
        $this->page('n3', 'news', '/news/middle', ['title' => 'Между', 'date' => '2018-04-28']);
        $r = new \Ramonak\Render\Renderer($this->k->db, $this->k->publication, $this->k->routes, $this->dir . '/var', false, true);
        $this->assertSame(['n1', 'n3', 'n2'], array_column($r->items($this->site, 'ru', 'news', ['order' => 'date']), 'key'));
        $this->assertSame(['n3', 'n2', 'n1'], array_column($r->items($this->site, 'ru', 'news'), 'key'), 'newest is the order of publication here');
        $this->assertSame(['n1'], array_column($r->items($this->site, 'ru', 'news', ['order' => 'date', 'limit' => 1]), 'key'));
    }

    public function testMachineTranslationIsPublishedUntilAnEditedVersionReplacesIt(): void
    {
        $published = $this->page('asylum', 'page', '/services/asylum', ['title' => 'Убежище', 'body' => '<p>Да</p>']);
        $this->assertSame(['be', 'en'], $published['publication']['translations_queued']);
        $this->k->queue->run($this->k->jobHandlers());
        $en = $this->get('/en/services/asylum');
        $this->assertSame(200, $en->status);
        $this->assertStringContainsString('[en] Убежище', $en->body);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $en->body, 'a machine translation is closed until reviewed');
        $this->assertStringNotContainsString('hreflang', $en->body);
        $this->assertStringNotContainsString('/en/services/asylum', $this->get('/sitemap.xml')->body);
        // two distinct reviewers open it (machine_reviews: 2); one is not enough
        foreach (['be', 'en'] as $lang) {
            $this->op('review.add', ['item' => 'asylum', 'lang' => $lang, 'verdict' => 'ok_for_now'], new Actor('m-3', 'supracoŭnik', 'reviewer-a'));
        }
        $this->assertStringContainsString('noindex', $this->get('/en/services/asylum')->body);
        foreach (['be', 'en'] as $lang) {
            $this->op('review.add', ['item' => 'asylum', 'lang' => $lang, 'verdict' => 'ok_for_now'], new Actor('m-3', 'supracoŭnik', 'reviewer-b'));
        }
        $en = $this->get('/en/services/asylum');
        $this->assertStringNotContainsString('noindex', $en->body);
        $this->assertStringContainsString('/en/services/asylum', $this->get('/sitemap.xml')->body);
        $this->assertStringContainsString('hreflang="be" href="https://example.test/be/services/asylum"', $en->body);
        $this->assertStringContainsString('hreflang="x-default" href="https://example.test/services/asylum"', $en->body);
        // an edited English text replaces the machine one and is not overwritten by the next source version
        $this->op('content.save', ['item' => 'asylum', 'lang' => 'en', 'fields' => ['title' => 'Asylum'], 'publish' => true]);
        $this->assertStringContainsString('Asylum', $this->get('/en/services/asylum')->body);
        $again = $this->op('content.save', ['item' => 'asylum', 'lang' => 'ru', 'fields' => ['title' => 'Убежище 2'], 'publish' => true]);
        $this->assertSame(['be'], $again['publication']['translations_queued']);
        $this->k->queue->run($this->k->jobHandlers());
        $this->assertStringContainsString('Asylum', $this->get('/en/services/asylum')->body);
        $this->assertStringContainsString('[be] Убежище 2', $this->get('/be/services/asylum')->body);
        $this->assertTrue($this->op('content.read', ['item' => 'asylum'])['languages']['en']['latest']['stale'], 'the edited translation is marked stale');
    }

    public function testAnyLanguageCanBeTheSourceAndTheOthersAreTranslated(): void
    {
        $this->op('content.create', ['item' => 'interview', 'kind' => 'page', 'lang' => 'en', 'path' => '/en/interview']);
        $published = $this->op('content.save', ['item' => 'interview', 'lang' => 'en', 'fields' => ['title' => 'Interview'], 'publish' => true]);
        $this->assertSame('source', $published['origin'], 'the first text of an item is its source, in whatever language');
        $this->assertSame(['ru', 'be'], $published['publication']['translations_queued']);
        $this->k->queue->run($this->k->jobHandlers());
        $this->assertStringContainsString('[ru] Interview', $this->get('/interview')->body, 'the Russian path is the source path without its prefix');
        $this->assertStringContainsString('[be] Interview', $this->get('/be/interview')->body);
        $ru = $this->op('content.save', ['item' => 'interview', 'lang' => 'ru', 'fields' => ['title' => 'Интервью']]);
        $this->assertSame('edited', $ru['origin'], 'a text in another language is a translation');
    }

    public function testEveryLanguageHasItsPrefixAndTheRootChoosesForTheVisitor(): void
    {
        $this->site = $this->k->sites->sync('prefixed');
        $get = fn (string $path, string $accept = '') => $this->k->handle(new Request('GET', 'prefixed.test', $path, '', $accept === '' ? [] : ['accept-language' => $accept]));
        $this->op('content.create', ['item' => 'home', 'kind' => 'home', 'lang' => 'ru', 'path' => '/ru']);
        $this->op('content.save', ['item' => 'home', 'lang' => 'ru', 'fields' => ['title' => 'Бюро'], 'publish' => true]);
        $this->op('content.create', ['item' => 'asylum', 'kind' => 'page', 'lang' => 'ru', 'path' => '/ru/services/asylum']);
        $this->op('content.save', ['item' => 'asylum', 'lang' => 'ru', 'fields' => ['title' => 'Убежище'], 'publish' => true]);
        $this->k->queue->run($this->k->jobHandlers());
        foreach (['' => '/en', 'ru-RU,ru;q=0.9,en;q=0.8' => '/ru', 'de-DE,de;q=0.9,be;q=0.5' => '/be', 'fr' => '/en', 'en;q=0.1,ru;q=0.2' => '/ru'] as $accept => $to) {
            $r = $get('/', $accept);
            $this->assertSame([302, 'https://prefixed.test' . $to, 'Accept-Language'], [$r->status, $r->headers['Location'], $r->headers['Vary']], "Accept-Language: {$accept}");
        }
        $this->assertFileDoesNotExist($this->dir . '/public/_cache/prefixed.test/index.html', 'the root is never a built file');
        $home = $get('/ru')->body;
        $this->assertStringContainsString('hreflang="x-default" href="https://prefixed.test/"', $home, 'the home pages point x-default to the root');
        $page = $get('/en/services/asylum');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('hreflang="x-default" href="https://prefixed.test/en/services/asylum"', $page->body, 'other pages: the English one');
        // the old unprefixed addresses lead to the Russian pages
        $this->op('redirect.import', ['redirects' => [['path' => '/services/asylum', 'target' => '/ru/services/asylum']]]);
        $old = $get('/services/asylum');
        $this->assertSame([301, 'https://prefixed.test/ru/services/asylum'], [$old->status, $old->headers['Location']]);
    }

    public function testAReviewThatFindsWorkClosesTheTranslationAgain(): void
    {
        $this->page('asylum', 'page', '/services/asylum', ['title' => 'Убежище']);
        $this->k->queue->run($this->k->jobHandlers());
        foreach (['a', 'b'] as $r) {
            $this->op('review.add', ['item' => 'asylum', 'lang' => 'en', 'verdict' => 'ok_for_now'], new Actor('m-3', 'supracoŭnik', $r));
        }
        $this->assertStringNotContainsString('noindex', $this->get('/en/services/asylum')->body);
        $this->op('review.add', ['item' => 'asylum', 'lang' => 'en', 'verdict' => 'needs_work', 'note' => 'term'], new Actor('m-3', 'supracoŭnik', 'c'));
        $this->assertStringContainsString('noindex', $this->get('/en/services/asylum')->body);
    }

    public function testScheduledPublicationAndTheDateOfAnArticle(): void
    {
        $this->op('content.create', ['item' => 'n1', 'kind' => 'news', 'lang' => 'ru', 'path' => '/news/old-case']);
        $this->op('content.save', ['item' => 'n1', 'lang' => 'ru', 'fields' => ['title' => 'Дело', 'date' => '2019-03-04', 'author' => 'Анна']]);
        try {
            $this->op('publication.publish', ['item' => 'n1', 'lang' => 'ru', 'at' => time() - 10]);
            $this->fail('a past time is refused');
        } catch (OperationError $e) {
            $this->assertSame('at_invalid', $e->error);
        }
        $s = $this->op('publication.publish', ['item' => 'n1', 'lang' => 'ru', 'at' => gmdate('c', time() + 3600)]);
        $this->assertSame(404, $this->get('/news/old-case')->status, 'not public before its time');
        $this->k->queue->run($this->k->jobHandlers());
        $this->assertSame(404, $this->get('/news/old-case')->status);
        $this->k->db->exec('UPDATE job SET run_after = ? WHERE id = ?', [time() - 1, $s['scheduled']]);
        $this->k->queue->run($this->k->jobHandlers());
        $page = $this->get('/news/old-case');
        $this->assertSame(200, $page->status);
        $ld = json_decode(preg_replace('#^.*<script type="application/ld\+json">(.*?)</script>.*$#s', '$1', $page->body), true);
        $article = $ld['@graph'][0];
        $this->assertSame(['NewsArticle', '2019-03-04', 'Анна', 'Organization', 'Пример'], [$article['@type'], $article['datePublished'], $article['author']['name'], $article['publisher']['@type'], $article['publisher']['name']],
            'the date of the article is its own field; the record of when it was published stays true');
        $this->assertSame('2019-03-04', $article['dateModified'], 'moved or republished, a material keeps its own date');
        $this->op('content.save', ['item' => 'n1', 'lang' => 'ru', 'fields' => ['title' => 'Дело', 'date' => '2019-03-04', 'updated' => '2026-11-14', 'author' => 'Анна'], 'publish' => true]);
        $ld = json_decode(preg_replace('#^.*<script type="application/ld\+json">(.*?)</script>.*$#s', '$1', $this->get('/news/old-case')->body), true);
        $this->assertSame(['2019-03-04', '2026-11-14'], [$ld['@graph'][0]['datePublished'], $ld['@graph'][0]['dateModified']], 'a revision in substance dates itself');
        $s2 = $this->op('publication.schedule', ['item' => 'n1', 'lang' => 'ru', 'at' => time() + 600]);
        $this->assertSame($s2['scheduled'], $this->op('publication.unschedule', ['job' => $s2['scheduled']])['cancelled']);
    }

    public function testOneSchemeOneCaseAndIndexNow(): void
    {
        $this->page('asylum', 'page', '/services/asylum', ['title' => 'Убежище']);
        $http = $this->k->handle(new Request('GET', 'example.test', '/services/asylum', '', [], '', false));
        $this->assertSame([301, 'https://example.test/services/asylum'], [$http->status, $http->headers['Location']]);
        $caps = $this->get('/Services/Asylum');
        $this->assertSame([301, 'https://example.test/services/asylum'], [$caps->status, $caps->headers['Location']]);
        $sent = [];
        $this->k->indexNow = function (array $job) use (&$sent): string { $sent[] = $job['paths']; return 'sent'; };
        $this->k->queue->run($this->k->jobHandlers());
        $this->assertContains(['/services/asylum'], $sent, 'a publication tells IndexNow');
    }

    public function testAPersonPageNamesThePersonForSearchEngines(): void
    {
        $this->op('content.create', ['item' => 'michalevic', 'kind' => 'person', 'lang' => 'en', 'path' => '/en/ales-michalevic']);
        $this->op('content.save', ['item' => 'michalevic', 'lang' => 'en', 'publish' => true, 'fields' => ['title' => 'Ales (Alaksiej) Michalevic',
            'job_title' => 'Lawyer', 'same_as' => ['https://en.wikipedia.org/wiki/Ales_Michalevic']]]);
        $body = $this->get('/en/ales-michalevic')->body;
        $ld = json_decode(preg_replace('#^.*<script type="application/ld\+json">(.*?)</script>.*$#s', '$1', $body), true);
        $page = $ld['@graph'][0];
        $this->assertSame(['ProfilePage', 'Person', 'Ales (Alaksiej) Michalevic', ['https://en.wikipedia.org/wiki/Ales_Michalevic'], 'Example'],
            [$page['@type'], $page['mainEntity']['@type'], $page['mainEntity']['name'], $page['mainEntity']['sameAs'], $page['mainEntity']['worksFor']['name']],
            'the organization speaks the language of the page');
    }

    public function testTheAuditFindsWhatASearchEngineWould(): void
    {
        $this->page('home', 'home', '/', ['title' => 'Бюро', 'description' => 'Адвокаты']);
        $this->page('a', 'page', '/a', ['title' => 'Убежище', 'description' => 'x', 'body' => '<a href="/b">b</a> <a href="/gone">g</a> <a href="https://example.test/old">o</a>']);
        $this->page('b', 'page', '/b', ['title' => 'убежище', 'body' => str_repeat('x', 10)]);
        $this->op('redirect.set', ['path' => '/old', 'target' => '/b']);
        $issues = $this->op('seo.audit', [])['issues'];
        $this->assertSame([['from' => '/a', 'to' => '/gone']], $issues['broken_links']);
        $this->assertSame([['from' => '/a', 'to' => '/old']], $issues['links_through_redirect']);
        $this->assertSame([['/a', '/b']], $issues['title_duplicate']);
        $this->assertSame(['/b'], $issues['description_missing']);
        $this->assertSame(['/a'], $issues['orphans']);
        $robots = $this->get('/robots.txt')->body;
        $this->assertStringContainsString('Clean-param: utm_source&', $robots);
        $this->assertSame('max-age=31536000', $this->get('/a')->headers['Strict-Transport-Security']);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $this->get('/a')->body);
    }

    public function testExperimentalInstallationIsClosedToIndexing(): void
    {
        $this->page('home', 'home', '/', ['title' => 'Бюро']);
        $this->assertStringContainsString('<loc>https://example.test/</loc>', $this->get('/sitemap.xml')->body);
        $this->assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $this->get('/robots.txt')->body);
        $this->k = $this->kernel(experimental: true);
        $r = $this->get('/');
        $this->assertSame('noindex, nofollow', $r->headers['X-Robots-Tag']);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $r->body);
        $this->assertStringNotContainsString('<loc>', $this->get('/sitemap.xml')->body);
        $this->assertStringContainsString('Disallow: /', $this->get('/robots.txt')->body);
    }

    public function testLevelsAndFieldsAreChecked(): void
    {
        $staff = new Actor('m-2', 'supracoŭnik');
        $this->page('about', 'page', '/about-us', ['title' => 'О нас'], publish: false);
        $this->op('content.save', ['item' => 'about', 'lang' => 'ru', 'fields' => ['title' => 'Черновик']], $staff);
        try {
            $this->op('publication.publish', ['item' => 'about', 'lang' => 'ru'], $staff);
            $this->fail('staff cannot publish');
        } catch (OperationError $e) {
            $this->assertSame('operation_forbidden', $e->error);
        }
        foreach ([[['title' => 'x', 'colour' => 'red'], 'field_unknown'], [['body' => 'x'], 'field_required']] as [$fields, $error]) {
            try {
                $this->op('content.save', ['item' => 'about', 'lang' => 'ru', 'fields' => $fields]);
                $this->fail($error);
            } catch (OperationError $e) {
                $this->assertSame($error, $e->error);
            }
        }
        try {
            $this->op('content.save', ['item' => 'about', 'lang' => 'ru', 'fields' => ['title' => 'x'], 'origin' => 'machine']);
            $this->fail('machine versions come from the job only');
        } catch (OperationError $e) {
            $this->assertSame('origin_invalid', $e->error);
        }
        $events = $this->op('event.list', []);
        $this->assertGreaterThanOrEqual(3, count($events['events']));
    }

    public function testBridgeAcceptsOnlySignedFreshUnrepeatedRequests(): void
    {
        $key = str_repeat('k', 40);
        $call = function (array $body, array $actor, ?string $signWith = null, ?int $time = null, ?string $nonce = null) use ($key): Response {
            $json = json_encode($body);
            $time ??= time();
            $nonce ??= bin2hex(random_bytes(12));
            $actorHeader = rtrim(strtr(base64_encode(json_encode($actor)), '+/', '-_'), '=');
            $sig = Bridge::sign($signWith ?? $key, 'POST', 'example.test', '/_ramonak/api', $time, $nonce, $actorHeader, $json);
            return $this->k->handle(new Request('POST', 'example.test', '/_ramonak/api', '', ['x-ramonak-time' => (string) $time,
                'x-ramonak-nonce' => $nonce, 'x-ramonak-actor' => $actorHeader, 'x-ramonak-signature' => $sig], $json));
        };
        $admin = ['member' => 'm-1', 'level' => 'administratar', 'agent' => 'claude'];
        $ok = $call(['operation' => 'site.status', 'input' => []], $admin);
        $this->assertSame(200, $ok->status, $ok->body);
        $this->assertSame('example', json_decode($ok->body, true)['result']['site']);
        $this->assertSame(401, $call(['operation' => 'site.status'], $admin, signWith: str_repeat('z', 40))->status);
        $this->assertSame(401, $call(['operation' => 'site.status'], $admin, time: time() - 600)->status);
        $nonce = bin2hex(random_bytes(12));
        $this->assertSame(200, $call(['operation' => 'site.status'], $admin, nonce: $nonce)->status);
        $this->assertSame('nonce_reused', json_decode($call(['operation' => 'site.status'], $admin, nonce: $nonce)->body, true)['error']);
        $denied = $call(['operation' => 'cache.purge'], ['member' => 'm-2', 'level' => 'supracoŭnik']);
        $this->assertSame([403, 'operation_forbidden'], [$denied->status, json_decode($denied->body, true)['error']]);
    }

    public function testMigrationsRunOnce(): void
    {
        $this->assertSame([], $this->k->migrate());
    }
}
