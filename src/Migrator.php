<?php
declare(strict_types=1);

namespace Ramonak;

/** Applies migrations/NNN-*.sql once each, in order, each in its own transaction where the database allows it. */
final class Migrator
{
    public function __construct(private Db $db, private string $dir) {}

    /** @return list<string> the migrations applied now */
    public function migrate(): array
    {
        $this->db->exec($this->db->dialect('CREATE TABLE IF NOT EXISTS ramonak_migration (name {{KEY}} NOT NULL PRIMARY KEY, applied_at BIGINT NOT NULL) {{TABLE}}'));
        $done = array_column($this->db->all('SELECT name FROM ramonak_migration'), 'name');
        $applied = [];
        $files = glob($this->dir . '/[0-9][0-9][0-9]-*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $statements = self::statements($this->db->dialect((string) file_get_contents($file)));
            // MariaDB commits DDL by itself: a failed migration stops here, and the record below is not written
            $this->db->tx(function () use ($statements, $name): void {
                foreach ($statements as $sql) {
                    $this->db->exec($sql);
                }
                $this->db->exec('INSERT INTO ramonak_migration (name, applied_at) VALUES (?, ?)', [$name, $this->db->now()]);
            });
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), fn ($l) => !str_starts_with(ltrim($l), '--'));
        return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines))), fn ($s) => $s !== ''));
    }
}
