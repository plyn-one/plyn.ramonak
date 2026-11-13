<?php
declare(strict_types=1);

namespace Ramonak;

/** The sites of an installation: written from their declarations by sync(), found by host on every request. */
final class Sites
{
    /** @var array<string,Site|null> */
    private array $byHost = [];
    /** @var array<int,Site> */
    private array $byId = [];

    public function __construct(private Db $db, private string $sitesDir, private string $varDir) {}

    /** Writes a site's row, domains and languages from sites_dir/<key>/site.json. */
    public function sync(string $key): Site
    {
        $dir = $this->sitesDir . '/' . $key;
        $declaration = json_decode((string) @file_get_contents($dir . '/site.json'), true);
        if (!is_array($declaration) || ($declaration['key'] ?? null) !== $key) {
            throw new OperationError('site_declaration_invalid', "No valid site.json with key {$key} in {$dir}");
        }
        foreach (['default_language', 'languages', 'domains', 'kinds'] as $field) {
            if (empty($declaration[$field])) {
                throw new OperationError('site_declaration_invalid', "site.json of {$key} has no {$field}");
            }
        }
        $this->db->tx(function () use ($key, $declaration): void {
            $id = $this->db->value('SELECT id FROM site WHERE site_key = ?', [$key]);
            $settings = json_encode(array_diff_key($declaration, array_flip(['kinds', 'domains', 'languages'])), JSON_UNESCAPED_UNICODE);
            if ($id === null) {
                $id = $this->db->insert('INSERT INTO site (site_key, default_language, indexable, settings) VALUES (?, ?, ?, ?)',
                    [$key, $declaration['default_language'], (int) !empty($declaration['indexable']), $settings]);
            } else {
                $this->db->exec('UPDATE site SET default_language = ?, indexable = ?, settings = ? WHERE id = ?',
                    [$declaration['default_language'], (int) !empty($declaration['indexable']), $settings, $id]);
            }
            $this->db->exec('DELETE FROM site_domain WHERE site_id = ?', [$id]);
            foreach ($declaration['domains'] as $domain => $role) {
                $this->db->exec('INSERT INTO site_domain (domain, site_id, role) VALUES (?, ?, ?)', [strtolower($domain), $id, $role]);
            }
            $this->db->exec('DELETE FROM site_language WHERE site_id = ?', [$id]);
            foreach (array_values($declaration['languages']) as $i => $l) {
                $this->db->exec('INSERT INTO site_language (site_id, lang, prefix, position, machine) VALUES (?, ?, ?, ?, ?)',
                    [$id, $l['lang'], $l['prefix'] ?? '', $i, (int) ($l['machine'] ?? true)]);
            }
        });
        @unlink($this->compiled($key));
        $this->byHost = [];
        $this->byId = [];
        return $this->byKey($key) ?? throw new \LogicException('site not written');
    }

    public function byHost(string $host): ?Site
    {
        if (array_key_exists($host, $this->byHost)) {
            return $this->byHost[$host];
        }
        $key = $this->db->value('SELECT s.site_key FROM site_domain d JOIN site s ON s.id = d.site_id WHERE d.domain = ?', [$host]);
        return $this->byHost[$host] = $key === null ? null : $this->byKey((string) $key);
    }

    public function byId(int $id): Site
    {
        if (isset($this->byId[$id])) {
            return $this->byId[$id];
        }
        $key = $this->db->value('SELECT site_key FROM site WHERE id = ?', [$id]);
        return $this->byKey((string) $key) ?? throw new OperationError('site_unknown', "No site {$id}", 404);
    }

    public function byKey(string $key): ?Site
    {
        $file = $this->compiled($key);
        $data = is_file($file) ? require $file : null;
        if (!is_array($data)) {
            $row = $this->db->one('SELECT * FROM site WHERE site_key = ?', [$key]);
            if ($row === null) {
                return null;
            }
            $languages = [];
            foreach ($this->db->all('SELECT lang, prefix, position, machine FROM site_language WHERE site_id = ? ORDER BY position', [$row['id']]) as $l) {
                $languages[$l['lang']] = ['prefix' => (string) $l['prefix'], 'machine' => (bool) $l['machine'], 'position' => (int) $l['position']];
            }
            $domains = [];
            foreach ($this->db->all('SELECT domain, role FROM site_domain WHERE site_id = ? ORDER BY role, domain', [$row['id']]) as $d) {
                $domains[$d['domain']] = $d['role'];
            }
            $declaration = json_decode((string) file_get_contents($this->sitesDir . '/' . $key . '/site.json'), true);
            $data = ['id' => (int) $row['id'], 'key' => $key, 'default' => (string) $row['default_language'], 'indexable' => (bool) $row['indexable'],
                'languages' => $languages, 'domains' => $domains, 'declaration' => $declaration];
            // compiled once, then served from OPcache: no JSON or queries for the site on later requests
            @mkdir(dirname($file), 0750, true);
            $tmp = $file . '.' . getmypid();
            if (@file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';') !== false) {
                @rename($tmp, $file);
            }
        }
        return $this->byId[$data['id']] = new Site($data['id'], $key, $data['default'], $data['indexable'], $data['languages'],
            $data['domains'], $data['declaration'], $this->sitesDir . '/' . $key);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->db->all('SELECT site_key FROM site ORDER BY site_key'), 'site_key');
    }

    private function compiled(string $key): string
    {
        return $this->varDir . '/sites/' . preg_replace('/[^a-z0-9_-]/', '_', $key) . '.php';
    }
}
