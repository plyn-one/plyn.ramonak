<?php
declare(strict_types=1);

namespace Ramonak\Bridge;

use Ramonak\Db;
use Ramonak\Http\Request;
use Ramonak\Http\Response;
use Ramonak\Operation\Actor;
use Ramonak\Operation\Operations;
use Ramonak\OperationError;
use Ramonak\Site;

/**
 * POST /_ramonak/api: one operation, signed by the frame's Klučnik with the site's bridge key (shared/config.php, bridge.<site>).
 *
 * Headers: X-Ramonak-Time (unix seconds), X-Ramonak-Nonce (16-64 of [A-Za-z0-9_-]), X-Ramonak-Actor (base64url of JSON
 * {member, level, agent}), X-Ramonak-Signature: hex HMAC-SHA256 of
 * "ramonak-v1\n{METHOD}\n{host}\n{path}\n{time}\n{nonce}\n{actor header}\n{hex SHA-256 of the body}".
 * Body: JSON {operation, input}. A request older or newer than five minutes, or a nonce seen before, is refused.
 */
final class Bridge
{
    public const WINDOW = 300;

    /** @param array<string,string> $keys site key => bridge key */
    public function __construct(private Db $db, private Operations $operations, private array $keys) {}

    public static function sign(string $key, string $method, string $host, string $path, int $time, string $nonce, string $actor, string $body): string
    {
        return hash_hmac('sha256', implode("\n", ['ramonak-v1', strtoupper($method), strtolower($host), $path, (string) $time, $nonce, $actor, hash('sha256', $body)]), $key);
    }

    public function handle(Request $request, Site $site): Response
    {
        if ($request->method !== 'POST') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }
        $key = $this->keys[$site->key] ?? null;
        if (!is_string($key) || strlen($key) < 32) {
            return Response::json(['error' => 'bridge_not_configured'], 503);
        }
        $time = (int) ($request->header('x-ramonak-time') ?? 0);
        $nonce = (string) ($request->header('x-ramonak-nonce') ?? '');
        $actorHeader = (string) ($request->header('x-ramonak-actor') ?? '');
        $signature = (string) ($request->header('x-ramonak-signature') ?? '');
        $expected = self::sign($key, $request->method, $request->host, $request->path, $time, $nonce, $actorHeader, $request->body);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) || abs(time() - $time) > self::WINDOW || !hash_equals($expected, $signature)) {
            return Response::json(['error' => 'signature_refused'], 401);
        }
        $this->db->exec('DELETE FROM bridge_nonce WHERE seen_at < ?', [time() - 2 * self::WINDOW]);
        try {
            $this->db->exec('INSERT INTO bridge_nonce (nonce, seen_at) VALUES (?, ?)', [$nonce, time()]);
        } catch (\PDOException) {
            return Response::json(['error' => 'nonce_reused'], 401);
        }
        $actor = json_decode((string) base64_decode(strtr($actorHeader, '-_', '+/'), true), true);
        if (!is_array($actor) || !is_string($actor['member'] ?? null) || !isset(Actor::LEVELS[$actor['level'] ?? ''])) {
            return Response::json(['error' => 'actor_invalid'], 400);
        }
        $call = json_decode($request->body, true);
        if (!is_array($call) || !is_string($call['operation'] ?? null)) {
            return Response::json(['error' => 'body_invalid', 'message' => 'The body is JSON {operation, input}'], 400);
        }
        try {
            $result = $this->operations->run($site, new Actor($actor['member'], $actor['level'], isset($actor['agent']) ? (string) $actor['agent'] : null),
                $call['operation'], is_array($call['input'] ?? null) ? $call['input'] : []);
            return Response::json(['ok' => true, 'result' => $result]);
        } catch (OperationError $e) {
            return Response::json(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
        }
    }
}
