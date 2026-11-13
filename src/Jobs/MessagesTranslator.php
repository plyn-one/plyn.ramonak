<?php
declare(strict_types=1);

namespace Ramonak\Jobs;

/**
 * A translator over a messages API of Anthropic's shape: the model route of the frame's Klučnik (its monthly limit and
 * accounting apply), or a provider directly. Configured in shared/config.php: translator => [url, key, model].
 */
final class MessagesTranslator implements Translator
{
    public function __construct(private string $url, private string $key, private string $model, private int $timeout = 120) {}

    public function translate(array $fields, array $types, string $from, string $to, array $glossary = []): array
    {
        $terms = $glossary ? "\nKeep these terms as given:\n" . implode("\n", array_map(fn ($g) => "{$g['source']} => {$g['target']}", $glossary)) : '';
        $prompt = "Translate the values of this JSON object from {$from} to {$to} for a law office's website. Keep the meaning exact and the "
            . "register professional. Fields of type html keep every tag and attribute as they are and translate only the text; markdown keeps its marks. "
            . "Answer with the JSON object only, the same keys.{$terms}\nTypes: " . json_encode($types) . "\nJSON:\n"
            . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = json_encode(['model' => $this->model, 'max_tokens' => 16000, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01']]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status !== 200) {
            throw new \RuntimeException("translator answered {$status}");
        }
        $text = '';
        foreach ((json_decode($raw, true)['content'] ?? []) as $block) {
            $text .= ($block['type'] ?? '') === 'text' ? $block['text'] : '';
        }
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $text) ?? ''), true);
        if (!is_array($json) || array_diff_key($fields, $json)) {
            throw new \RuntimeException('translator gave no JSON with the same keys');
        }
        return array_intersect_key($json, $fields);
    }
}
