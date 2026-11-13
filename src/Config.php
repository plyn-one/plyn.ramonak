<?php
declare(strict_types=1);

namespace Ramonak;

/**
 * The configuration of one installation: shared/config.php returns an array, outside every release and the document
 * root. Sites, their kinds and templates come from the sites' own directories (sites_dir/<site_key>/site.json).
 */
final class Config
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values) {}

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Ramonak: no configuration at ' . $file);
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('Ramonak: the configuration must return an array');
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new \RuntimeException('Ramonak: the configuration has no ' . $key);
        }
        return $this->values[$key];
    }

    /** An experimental installation answers noindex everywhere, whatever a site says. */
    public function experimental(): bool
    {
        return (bool) ($this->values['experimental'] ?? true);
    }
}
