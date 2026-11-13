<?php
declare(strict_types=1);

namespace Ramonak\Jobs;

use Ramonak\Sites;

/**
 * The job indexnow: tells the search engines of the IndexNow protocol (Bing, Yandex and others share it) that addresses of
 * a site changed, so that they crawl them within hours. Never from an experimental installation or a site closed to
 * indexing. The key is the installation's (shared/config.php indexnow.<site>), and the site serves it at /<key>.txt.
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** @param array<string,string> $keys @param (callable(string,string):int)|null $post for tests */
    public function __construct(private Sites $sites, private array $keys, private bool $experimental, private $post = null) {}

    public function __invoke(array $job): string
    {
        $site = $this->sites->byKey((string) $job['site']) ?? throw new \RuntimeException('no site ' . $job['site']);
        $key = $this->keys[$site->key] ?? null;
        if ($this->experimental || !$site->indexable || !is_string($key)) {
            return 'not sent: ' . ($this->experimental ? 'experimental installation' : (!$site->indexable ? 'site closed to indexing' : 'no key'));
        }
        $urls = array_values(array_unique(array_map(fn ($p) => $site->url((string) $p), (array) $job['paths'])));
        $host = $site->primaryDomain();
        $body = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => "https://{$host}/{$key}.txt", 'urlList' => array_slice($urls, 0, 10000)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $status = $this->post ? ($this->post)(self::ENDPOINT, $body) : self::send($body);
        if (!in_array($status, [200, 202], true)) {
            throw new \RuntimeException("IndexNow answered {$status}");
        }
        return "sent " . count($urls) . " addresses ({$status})";
    }

    private static function send(string $body): int
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }
}
