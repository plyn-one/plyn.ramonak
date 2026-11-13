<?php
declare(strict_types=1);

namespace Ramonak;

/**
 * One site as a request sees it: its row, its domains and languages, and its declaration (site.json in its own
 * directory): kinds of content with their fields and templates. Read once per process and kept compiled.
 */
final class Site
{
    /**
     * @param array<string,array{prefix:string,machine:bool,position:int}> $languages
     * @param array<string,string> $domains domain => role
     * @param array<string,mixed> $declaration
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $defaultLanguage,
        public readonly bool $indexable,
        public readonly array $languages,
        public readonly array $domains,
        public readonly array $declaration,
        public readonly string $dir,
    ) {}

    public function primaryDomain(): string
    {
        return (string) (array_search('primary', $this->domains, true) ?: array_key_first($this->domains));
    }

    /** @return array<string,mixed> */
    public function kind(string $kind): array
    {
        $k = $this->declaration['kinds'][$kind] ?? null;
        if (!is_array($k)) {
            throw new OperationError('kind_unknown', "The site {$this->key} declares no kind {$kind}");
        }
        return $k;
    }

    /** The language a path is in, and the path without its prefix. */
    public function language(string $path): string
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';
        foreach ($this->languages as $lang => $l) {
            if ($l['prefix'] !== '' && $l['prefix'] === $first) {
                return $lang;
            }
        }
        foreach ($this->languages as $lang => $l) {
            if ($l['prefix'] === '') {
                return $lang;
            }
        }
        return $this->defaultLanguage;
    }

    public function url(string $path): string
    {
        return 'https://' . $this->primaryDomain() . $path;
    }

    public function name(string $lang): string
    {
        $name = $this->declaration['name'] ?? $this->key;
        return is_array($name) ? (string) ($name[$lang] ?? $name[$this->defaultLanguage] ?? reset($name)) : (string) $name;
    }
}
