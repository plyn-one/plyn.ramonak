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
use Ramonak\OperationError;
use Ramonak\Site;

/**
 * The media library end to end on the example site, with a codec that scales as the real one does but writes PNG (the test
 * machine has no AVIF): one file per image at the size the site needs, copies made on request and cleaned, the image markup
 * of pages, the refusal of what is not kept.
 */
final class MediaTest extends TestCase
{
    private string $dir;
    private Kernel $k;
    private Site $site;
    /** @var list<array{int,int,int}> what the codec was asked: max width, max side, quality */
    public array $asked = [];

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            $this->markTestSkipped('GD is needed to make test images');
        }
        $this->dir = sys_get_temp_dir() . '/ramonak-media-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0777, true);
        $test = $this;
        $codec = new class($test) implements Codec {
            public function __construct(private MediaTest $test) {}
            public function avif(string $data, int $maxWidth, int $maxSide, int $quality): array
            {
                $this->test->asked[] = [$maxWidth, $maxSide, $quality];
                $gd = imagecreatefromstring($data);
                [$w, $h] = AvifCodec::fit(imagesx($gd), imagesy($gd), $maxWidth, $maxSide);
                $gd = imagescale($gd, $w, $h);
                ob_start();
                imagepng($gd);
                return [(string) ob_get_clean(), $w, $h];
            }
        };
        $this->k = new Kernel(new Config(['database' => ['dsn' => 'sqlite:' . $this->dir . '/db.sqlite'], 'var_dir' => $this->dir . '/var',
            'sites_dir' => dirname(__DIR__) . '/examples/sites', 'cache_dir' => $this->dir . '/public/_cache', 'experimental' => false,
            'media_dir' => $this->dir . '/public/media', 'media_quality' => 50]), null, $codec);
        $this->k->migrate();
        $this->site = $this->k->sites->sync('example');
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

    private static function png(int $w, int $h): string
    {
        $gd = imagecreatetruecolor($w, $h);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);
        return (string) ob_get_clean();
    }

    public function testAnImageIsKeptOnceAsAvifAtTheSizeTheSiteNeeds(): void
    {
        $data = self::png(3000, 1500);
        $added = $this->op('media.add', ['data_base64' => base64_encode($data), 'alt' => ['ru' => 'Красный квадрат']]);
        $this->assertSame('/media/' . substr(hash('sha256', $data), 0, 2) . '/' . hash('sha256', $data) . '.avif', $added['path'],
            'named by the checksum of what was sent');
        $this->assertSame(['image/avif', 2000, 1000], [$added['mime'], $added['width'], $added['height']]);
        $this->assertSame([[2000, 2000, 50]], $this->asked, 'scaled to the largest side the site needs, at the installation quality');
        $this->assertFileExists($this->dir . '/public' . $added['path']);
        $this->assertCount(1, glob($this->dir . '/public/media/*/*') ?: [], 'the original is not kept');
        $this->assertSame($added['path'], $this->op('media.add', ['data_base64' => base64_encode($data)])['path']);
        $this->assertSame(1, (int) $this->k->db->value('SELECT COUNT(*) FROM media'), 'the same file sent twice is one image');
    }

    public function testWhatIsNotAnImageOrAPdfIsRefused(): void
    {
        try {
            $this->op('media.add', ['data_base64' => base64_encode('just text')]);
            $this->fail('text kept as media');
        } catch (OperationError $e) {
            $this->assertSame('media_type_unsupported', $e->error);
        }
        $pdf = $this->op('media.add', ['data_base64' => base64_encode("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")]);
        $this->assertStringEndsWith('.pdf', $pdf['path'], 'a PDF is kept as it is');
    }

    public function testASmallerCopyIsMadeOnItsFirstRequestOnly(): void
    {
        $path = $this->op('media.add', ['data_base64' => base64_encode(self::png(1200, 800))])['path'];
        $copy = preg_replace('~^/media/~', '/media/w320/', preg_replace('~\.avif$~', '.avif', $path));
        $r = $this->k->handle(new Request('GET', 'example.test', $copy, ''));
        $this->assertSame([200, 'image/avif'], [$r->status, $r->headers['Content-Type']]);
        $this->assertStringContainsString('immutable', $r->headers['Cache-Control']);
        $this->assertSame([320, 100000], array_slice(end($this->asked), 0, 2));
        $this->assertFileExists($this->dir . '/public' . $copy, 'written where the web server sends it from then on');
        $n = count($this->asked);
        $this->k->handle(new Request('GET', 'example.test', $copy, ''));
        $this->assertCount($n, $this->asked, 'made once');
        $this->assertSame(404, $this->k->handle(new Request('GET', 'example.test', str_replace('/w320/', '/w333/', $copy), ''))->status, 'only the widths of the ladder');
        $this->assertSame(404, $this->k->handle(new Request('GET', 'example.test', '/media/w320/ab/' . str_repeat('ab', 32) . '.avif', ''))->status);
    }

    public function testAPageGetsTheFullMarkupOfItsImages(): void
    {
        $path = $this->op('media.add', ['data_base64' => base64_encode(self::png(1000, 500)), 'alt' => ['ru' => 'Красный прямоугольник']])['path'];
        [$aa, $file] = [substr($path, 7, 2), basename($path)];
        $this->op('content.create', ['item' => 'p1', 'kind' => 'page', 'lang' => 'ru', 'path' => '/p1']);
        $this->op('content.save', ['item' => 'p1', 'lang' => 'ru', 'publish' => true, 'fields' => ['title' => 'Страница',
            'body' => '<p><img src="' . $path . '" alt=""></p><p><img src="' . $path . '" width="160" alt="Своё"></p><img src="/assets/logo.svg" alt="Лого">']]);
        $html = $this->k->handle(new Request('GET', 'example.test', '/p1', ''))->body;
        $this->assertStringContainsString('<img src="/media/w1280/' . $aa . '/' . $file . '" alt="Красный прямоугольник" srcset="/media/w160/' . $aa . '/' . $file
            . ' 160w, /media/w320/' . $aa . '/' . $file . ' 320w, /media/w480/' . $aa . '/' . $file . ' 480w, /media/w640/' . $aa . '/' . $file
            . ' 640w, /media/w960/' . $aa . '/' . $file . ' 960w, /media/w1280/' . $aa . '/' . $file . ' 1000w" sizes="(width &lt;= 50em) 100vw, 50rem" width="1000" height="500" loading="lazy" decoding="async">', $html,
            'copies up to the image\'s own width, its alt in the language of the page, its size against layout shifts');
        $this->assertStringContainsString('width="160" alt="Своё" srcset=', $html, 'an alt of its own stays');
        $this->assertStringContainsString('sizes="160px" height="80"', $html, 'a given width is the size it takes, the height in proportion');
        $this->assertStringContainsString('<img src="/assets/logo.svg" alt="Лого">', $html, 'what is not of the library stays as it is');
    }

    public function testTheCacheOfCopiesCleansItselfOfWhatWasAskedLongestAgo(): void
    {
        $base = $this->dir . '/public/media';
        @mkdir($base . '/w320/ab', 0777, true);
        @mkdir($base . '/ab', 0777, true);
        file_put_contents($base . '/ab/' . str_repeat('a', 64) . '.avif', 'master');
        foreach (range(1, 10) as $i) {
            $f = $base . '/w320/ab/' . str_pad((string) $i, 64, '0', STR_PAD_LEFT) . '.avif';
            file_put_contents($f, str_repeat('x', 100));
            touch($f, 1000 + $i, 1000 + $i);
        }
        $r = $this->k->images->clean(5, 10_000_000);
        $this->assertSame(['files' => 4, 'bytes' => 400, 'removed' => 6], $r);
        $this->assertFileDoesNotExist($base . '/w320/ab/' . str_pad('1', 64, '0', STR_PAD_LEFT) . '.avif', 'the oldest went first');
        $this->assertFileExists($base . '/w320/ab/' . str_pad('10', 64, '0', STR_PAD_LEFT) . '.avif');
        $this->assertFileExists($base . '/ab/' . str_repeat('a', 64) . '.avif', 'an image of the library is never touched');
    }
}
