<?php
declare(strict_types=1);

namespace Ramonak\Tests;

use PHPUnit\Framework\TestCase;
use Ramonak\Config;
use Ramonak\Forms\Forms;
use Ramonak\Forms\Mailer;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Kernel;
use Ramonak\Operation\Actor;
use Ramonak\OperationError;
use Ramonak\Site;

/** Forms of the example site: an entry kept and mailed, refusals, the trap for robots, the limit per address, the operations. */
final class FormsTest extends TestCase
{
    private string $dir;
    private Kernel $k;
    private Site $site;
    /** @var list<array{to:list<string>,subject:string,body:string,replyTo:?string}> */
    public array $sent = [];
    public bool $fail = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ramonak-forms-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0777, true);
        $this->k = new Kernel(new Config(['database' => ['dsn' => 'sqlite:' . $this->dir . '/db.sqlite'], 'var_dir' => $this->dir . '/var',
            'sites_dir' => dirname(__DIR__) . '/examples/sites', 'cache_dir' => $this->dir . '/public/_cache', 'experimental' => false]));
        $this->k->migrate();
        $this->site = $this->k->sites->sync('example');
        $test = $this;
        $this->k->mailer = new class($test) extends Mailer {
            public function __construct(private FormsTest $test) {}
            public function send(array $to, string $subject, string $body, ?string $replyTo = null): void
            {
                if ($this->test->fail) {
                    throw new \RuntimeException('the server is away');
                }
                $this->test->sent[] = compact('to', 'subject', 'body', 'replyTo');
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function post(array $data, string $form = 'contact', array $headers = ['origin' => 'https://example.test'], string $client = '203.0.113.7'): Response
    {
        return $this->k->handle(new Request('POST', 'example.test', '/_ramonak/form/' . $form, '', $headers, http_build_query($data), true, $client));
    }

    private function valid(array $over = []): array
    {
        return $over + ['_back' => '/en/contacts', 'name' => '  Anna  ', 'contact' => 'anna@example.org', 'message' => "Line one\r\nLine two", 'consent' => 'on', 'website' => ''];
    }

    private function entries(): array
    {
        return $this->k->operations->run($this->site, new Actor('m-1', 'davierany', 'claude'), 'form.list', [])['entries'];
    }

    public function testAnEntryIsKeptAndMailedInTheVisitorsLanguage(): void
    {
        $r = $this->post($this->valid());
        $this->assertSame(303, $r->status);
        $this->assertSame('https://example.test/en/contacts#contact-sent', $r->headers['Location']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        [$entry] = $this->entries();
        $this->assertSame(['name' => 'Anna', 'contact' => 'anna@example.org', 'message' => "Line one\nLine two", 'consent' => true], $entry['fields']);
        $this->assertSame(['en', '/en/contacts', 'new'], [$entry['lang'], $entry['page'], $entry['state']]);

        $this->k->queue->run($this->k->jobHandlers());
        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertSame(['office@example.test'], $mail['to']);
        $this->assertSame('anna@example.org', $mail['replyTo']);
        $this->assertStringStartsWith('Contact form: Anna | ', $mail['subject']);
        $this->assertStringContainsString("Message:\nLine one\nLine two\n", $mail['body']);
        $this->assertStringContainsString('Consent: yes', $mail['body']);
        $this->assertStringContainsString('Page: https://example.test/en/contacts', $mail['body']);
        $this->assertSame('sent', $this->entries()[0]['state']);

        $this->post($this->valid(['_back' => '/', 'contact' => '+48 571 000 000']));
        $this->k->queue->run($this->k->jobHandlers());
        $this->assertStringContainsString('Согласие: да', $this->sent[1]['body']);
        $this->assertNull($this->sent[1]['replyTo']);
    }

    public function testWhatIsMissingOrWrongIsNotKept(): void
    {
        foreach ([['consent' => ''], ['name' => '   '], ['message' => str_repeat('a', 5001)], ['name' => ['a', 'b']]] as $bad) {
            $r = $this->post($this->valid($bad));
            $this->assertSame('https://example.test/en/contacts#contact-error', $r->headers['Location'], json_encode($bad));
        }
        $this->assertSame([], $this->entries());
    }

    public function testARobotIsToldItSucceededAndNothingIsKept(): void
    {
        $r = $this->post($this->valid(['website' => 'http://spam.example']));
        $this->assertSame('https://example.test/en/contacts#contact-sent', $r->headers['Location']);
        $this->assertSame([], $this->entries());
    }

    public function testAFormIsSentOnlyFromTheSitesOwnPages(): void
    {
        $this->assertSame(403, $this->post($this->valid(), headers: ['origin' => 'https://evil.example'])->status);
        $this->assertSame(403, $this->post($this->valid(), headers: ['referer' => 'https://evil.example/page'])->status);
        $this->assertSame(403, $this->post($this->valid(), headers: ['origin' => 'null'])->status);
        $this->assertSame(303, $this->post($this->valid(), headers: ['referer' => 'https://www.example.test/en/contacts'])->status);
        $this->assertSame(404, $this->post($this->valid(), 'unknown')->status);
        $get = $this->k->handle(new Request('GET', 'example.test', '/_ramonak/form/contact', ''));
        $this->assertSame(405, $get->status);
    }

    public function testThePageToReturnToIsAPathOfTheSite(): void
    {
        foreach (['https://evil.example/', '//evil.example/x', '/a b', 'javascript:alert(1)'] as $back) {
            $this->assertSame('https://example.test/#contact-sent', $this->post($this->valid(['_back' => $back]))->headers['Location'], $back);
        }
    }

    public function testOneAddressMaySendOnlySoOften(): void
    {
        for ($i = 0; $i < Forms::LIMIT; $i++) {
            $this->assertStringEndsWith('#contact-sent', $this->post($this->valid())->headers['Location']);
        }
        $this->assertStringEndsWith('#contact-error', $this->post($this->valid())->headers['Location']);
        $this->assertStringEndsWith('#contact-sent', $this->post($this->valid(), client: '198.51.100.9')->headers['Location']);
        $this->assertCount(Forms::LIMIT + 1, $this->entries());
    }

    public function testAFailedLetterIsRetriedAndTheEntryStays(): void
    {
        $this->post($this->valid());
        $this->fail = true;
        $this->assertSame('queued', $this->k->queue->run($this->k->jobHandlers())[0]['state']);
        $this->assertSame('failed', $this->entries()[0]['state']);
        $this->fail = false;
        $this->k->db->exec('UPDATE job SET run_after = 0');
        $this->assertSame('done', $this->k->queue->run($this->k->jobHandlers())[0]['state']);
        $this->assertSame('sent', $this->entries()[0]['state']);
    }

    public function testEntriesAreReadAndDeletedOnlyByTrustedStaff(): void
    {
        $this->post($this->valid());
        $staff = new Actor('m-2', 'supracoŭnik', 'claude');
        try {
            $this->k->operations->run($this->site, $staff, 'form.list', []);
            $this->fail('staff read form entries');
        } catch (OperationError $e) {
            $this->assertSame('operation_forbidden', $e->error);
        }
        $id = (int) $this->entries()[0]['id'];
        $done = $this->k->operations->run($this->site, new Actor('m-1', 'davierany', 'claude'), 'form.delete', ['entries' => [$id]]);
        $this->assertSame(1, $done['deleted']);
        $this->assertSame([], $this->entries());
    }

    public function testEntriesAreForgottenAfterTheDaysTheirFormKeepsThem(): void
    {
        $this->post($this->valid());
        $this->post($this->valid(), client: '198.51.100.9');
        $this->k->db->exec('UPDATE form_entry SET created_at = ? WHERE id = 1', [$this->k->db->now() - 366 * 86400]);
        $this->assertSame(['example/contact' => 1], Forms::expire($this->k->db, $this->k->sites), 'older than keep_days: gone, mailed or not');
        $this->assertCount(1, $this->entries());
        $this->assertSame([], Forms::expire($this->k->db, $this->k->sites));
    }

    public function testALetterCannotCarryHeadersOfItsOwn(): void
    {
        $m = Mailer::message('forms@example.test', 'Example', ['office@example.test'], "Hi\r\nBcc: victim@example.org", "Body\n.", 'x@example.org');
        $this->assertStringNotContainsString("\r\nBcc:", $m);
        $this->assertStringContainsString("Reply-To: x@example.org\r\n", $m);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $m);
        $this->assertStringContainsString("Subject: Hi  Bcc: victim@example.org\r\n", $m);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Форма') . '?=', Mailer::message('f@example.test', '', ['o@example.test'], 'Форма', 'x', null));
    }
}
