<?php
declare(strict_types=1);

namespace Ramonak\Tests;

use PHPUnit\Framework\TestCase;
use Ramonak\Config;
use Ramonak\Http\Request;
use Ramonak\Kernel;
use Ramonak\Media\AvifCodec;
use Ramonak\Media\Codec;
use Ramonak\Operation\Actor;
use Ramonak\Site;

/** A video of YouTube in a page: a cover and a link until play is pressed, its cover fetched once into the media library. */
final class VideoTest extends TestCase
{
    private string $dir;
    private Kernel $k;
    private Site $site;
    /** @var list<string> */
    public array $fetched = [];

    protected function setUp(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD is needed to make test images');
        }
        $this->dir = sys_get_temp_dir() . '/ramonak-video-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0777, true);
        $codec = new class implements Codec {
            public function avif(string $data, int $maxWidth, int $maxSide, int $quality): array
            {
                $gd = imagecreatefromstring($data);
                [$w, $h] = AvifCodec::fit(imagesx($gd), imagesy($gd), $maxWidth, $maxSide);
                ob_start();
                imagepng(imagescale($gd, $w, $h));
                return [(string) ob_get_clean(), $w, $h];
            }
        };
        $this->k = new Kernel(new Config(['database' => ['dsn' => 'sqlite:' . $this->dir . '/db.sqlite'], 'var_dir' => $this->dir . '/var',
            'sites_dir' => dirname(__DIR__) . '/examples/sites', 'cache_dir' => $this->dir . '/public/_cache', 'experimental' => false,
            'media_dir' => $this->dir . '/public/media']), null, $codec);
        $this->k->migrate();
        $this->site = $this->k->sites->sync('example');
        $this->k->fetch = function (string $url): ?string {
            $this->fetched[] = $url;
            if (str_contains($url, 'oembed')) {
                return json_encode(['title' => 'Андрей Розум - как избежать экстрадиции?', 'author_name' => 'Smartpress']);
            }
            if (str_contains($url, 'maxresdefault')) {
                $gd = imagecreatetruecolor(1280, 720);
                ob_start();
                imagepng($gd);
                return (string) ob_get_clean();
            }
            return null;
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function op(string $name, array $input): array
    {
        return $this->k->operations->run($this->site, new Actor('m-1', 'davierany', 'claude'), $name, $input);
    }

    public function testAVideoIsACoverAndALinkUntilPlayIsPressed(): void
    {
        $this->op('content.create', ['item' => 'n1', 'kind' => 'news', 'lang' => 'ru', 'path' => '/news/interview']);
        $this->op('content.save', ['item' => 'n1', 'lang' => 'ru', 'publish' => true, 'fields' => ['title' => 'Как избежать экстрадиции?', 'date' => '2021-05-14',
            'body' => '<p>Эфир.</p><p><iframe width="100%" height="480" src="https://www.youtube.com/embed/JSNhVoCNFOg" title="YouTube video player" allowfullscreen></iframe></p>',
            'source_url' => 'https://www.youtube.com/watch?v=JSNhVoCNFOg']]);
        $html = $this->k->handle(new Request('GET', 'example.test', '/news/interview', ''))->body;
        $this->assertStringNotContainsString('<iframe', $html, 'nothing of YouTube loads with the page');
        $this->assertStringContainsString('<p>Эфир.</p><figure class="ramonak-video">', $html, 'the paragraph that held the player is gone');
        $this->assertStringContainsString('<figure class="ramonak-video"><a href="https://www.youtube.com/watch?v=JSNhVoCNFOg" data-video="https://www.youtube-nocookie.com/embed/JSNhVoCNFOg?autoplay=1" aria-label="Смотреть видео" rel="noopener"><span class="ramonak-video-play" aria-hidden="true"></span></a></figure>', $html);
        $ld = json_decode(preg_replace('#^.*<script type="application/ld\+json">(.*?)</script>.*$#s', '$1', $html), true)['@graph'][0];
        $this->assertSame('https://www.youtube.com/watch?v=JSNhVoCNFOg', $ld['isBasedOn'], 'the recording the text was made from');
        $this->k->handle(new Request('GET', 'example.test', '/news/interview', ''));
        $this->assertSame(1, (int) $this->k->db->value("SELECT COUNT(*) FROM job WHERE kind = 'video.cover'"), 'its cover is asked for once');

        $done = $this->k->queue->run($this->k->jobHandlers());
        $this->assertSame('done', $done[0]['state']);
        $this->assertSame(['https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DJSNhVoCNFOg', 'https://i.ytimg.com/vi/JSNhVoCNFOg/maxresdefault.jpg'], $this->fetched);
        $html = $this->k->handle(new Request('GET', 'example.test', '/news/interview', ''))->body;
        $this->assertMatchesRegularExpression('~<figure class="ramonak-video"><a [^>]*aria-label="Смотреть видео: Андрей Розум - как избежать экстрадиции\?"[^>]*><img src="/media/w1280/[0-9a-f]{2}/[0-9a-f]{64}\.avif" alt="Андрей Розум - как избежать экстрадиции\?" srcset=~', $html,
            'the cover is the site\'s own image, with the full markup of the library');
        $this->assertStringNotContainsString('<figcaption>', $html, 'no caption the author did not give: the title is in the label');
        $this->op('content.save', ['item' => 'n1', 'lang' => 'ru', 'publish' => true, 'fields' => ['title' => 'Как избежать экстрадиции?', 'date' => '2021-05-14',
            'body' => '<figure><iframe src="https://www.youtube.com/embed/JSNhVoCNFOg"></iframe><figcaption>Эфир Смартпресс, ведущий — Вячеслав Зенькович</figcaption></figure>']]);
        $html = $this->k->handle(new Request('GET', 'example.test', '/news/interview', ''))->body;
        $this->assertStringContainsString('<span class="ramonak-video-play" aria-hidden="true"></span></a><figcaption>Эфир Смартпресс, ведущий — Вячеслав Зенькович</figcaption></figure>', $html,
            'the caption the author gave stays');
        $this->assertSame(1, substr_count($html, '<figure'), 'one figure, not one inside another');
    }
}
