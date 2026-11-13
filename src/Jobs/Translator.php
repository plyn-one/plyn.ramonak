<?php
declare(strict_types=1);

namespace Ramonak\Jobs;

/** Translates the translatable fields of a version from one language to another. */
interface Translator
{
    /**
     * @param array<string,mixed> $fields only the fields to translate
     * @param array<string,string> $types field => text, html or markdown
     * @param list<array{source:string, target:string}> $glossary terms to keep as given
     * @return array<string,mixed> the same keys, translated
     */
    public function translate(array $fields, array $types, string $from, string $to, array $glossary = []): array;
}
