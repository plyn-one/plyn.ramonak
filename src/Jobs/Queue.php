<?php
declare(strict_types=1);

namespace Ramonak\Jobs;

use Ramonak\Db;

/**
 * Background work, run from cron by bin/ramonak jobs:run: claimed with a lock, retried with backoff, left failed with its
 * reason after the last attempt. Nothing slow runs while a page is served.
 */
final class Queue
{
    public const ATTEMPTS = 5;

    public function __construct(private Db $db) {}

    public function push(string $kind, array $payload, int $delay = 0): int
    {
        return $this->db->insert('INSERT INTO job (kind, payload, state, attempts, run_after, created_at) VALUES (?, ?, ?, 0, ?, ?)',
            [$kind, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'queued', $this->db->now() + $delay, $this->db->now()]);
    }

    /**
     * @param array<string,callable(array):string> $handlers kind => handler returning a short result
     * @return list<array{id:int, kind:string, state:string, result:string}>
     */
    public function run(array $handlers, int $limit = 20, int $lockSeconds = 300): array
    {
        $done = [];
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claim($lockSeconds);
            if ($job === null) {
                break;
            }
            $handler = $handlers[$job['kind']] ?? null;
            try {
                if ($handler === null) {
                    throw new \RuntimeException('no handler for ' . $job['kind']);
                }
                $result = $handler(json_decode((string) $job['payload'], true));
                $this->db->exec("UPDATE job SET state = 'done', result = ?, locked_until = NULL WHERE id = ?", [mb_substr($result, 0, 2000), $job['id']]);
                $done[] = ['id' => (int) $job['id'], 'kind' => $job['kind'], 'state' => 'done', 'result' => $result];
            } catch (\Throwable $e) {
                $attempts = (int) $job['attempts'];
                $state = $attempts >= self::ATTEMPTS ? 'failed' : 'queued';
                $this->db->exec('UPDATE job SET state = ?, result = ?, locked_until = NULL, run_after = ? WHERE id = ?',
                    [$state, mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000), $this->db->now() + 60 * 2 ** $attempts, $job['id']]);
                $done[] = ['id' => (int) $job['id'], 'kind' => $job['kind'], 'state' => $state, 'result' => $e->getMessage()];
            }
        }
        return $done;
    }

    /** @return array<string,mixed>|null */
    private function claim(int $lockSeconds): ?array
    {
        $now = $this->db->now();
        // a job whose worker died is taken again once its lock has passed
        $this->db->exec("UPDATE job SET state = 'queued' WHERE state = 'running' AND locked_until < ?", [$now]);
        $job = $this->db->one("SELECT * FROM job WHERE state = 'queued' AND run_after <= ? ORDER BY id LIMIT 1", [$now]);
        if ($job === null) {
            return null;
        }
        $taken = $this->db->exec("UPDATE job SET state = 'running', attempts = attempts + 1, locked_until = ? WHERE id = ? AND state = 'queued'",
            [$now + $lockSeconds, $job['id']]);
        if ($taken !== 1) {
            return $this->claim($lockSeconds);
        }
        $job['attempts'] = (int) $job['attempts'] + 1;
        return $job;
    }
}
