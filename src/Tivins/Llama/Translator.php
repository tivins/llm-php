<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;

class Translator
{
    /** @var array<string, string> */
    private array $translationCache = [];

    /** @var list<string> */
    private array $translationCacheOrder = [];

    public function __construct(
        private readonly Lama $lama,
        /**
         * When greater than 0, identical (text, from, to) calls reuse the last result
         * up to this many distinct keys (FIFO eviction).
         */
        private readonly int $translationCacheMaxEntries = 0,
    ) {
    }

    public function translate(string $text, string $from, string $to): string
    {
        $from = trim($from);
        $to = trim($to);
        if ($text === '') {
            return '';
        }

        $cacheKey = $this->translationCacheKey($text, $from, $to);
        $cached = $this->translationCacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $conversation = new Conversation();
        $conversation->addMessage(new Message(Role::System, BehaviorPrompts::TRANSLATOR));
        $conversation->addMessage(new Message(Role::User, self::formatSingleTranslateUserMessage($text, $from, $to)));

        $raw = trim($this->lama->chat($conversation));
        $this->translationCacheSet($cacheKey, $raw);

        return $raw;
    }

    /**
     * Translates several segments in one model call (fewer round-trips than repeated translate()).
     *
     * @param list<string> $texts Same order is preserved in the returned list.
     *
     * @return list<string>
     *
     * @throws JsonException
     */
    public function translateBatch(array $texts, string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);
        if ($texts === []) {
            return [];
        }

        $normalized = array_values($texts);
        if (count($normalized) === 1) {
            return [$this->translate($normalized[0], $from, $to)];
        }

        $system = BehaviorPrompts::TRANSLATOR . <<<'TXT'


The user will send one JSON object: {"source_language": "...", "target_language": "...", "parts": ["...", ...]}.
You MUST reply with a single JSON array of strings only — same length and order as "parts", no markdown, no extra keys.
TXT;

        $payload = [
            'source_language' => $from,
            'target_language' => $to,
            'parts' => $normalized,
        ];

        $conversation = new Conversation();
        $conversation->addMessage(new Message(Role::System, $system));
        $conversation->addMessage(new Message(Role::User, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));

        $raw = trim($this->lama->chat($conversation));
        $decoded = self::decodeTranslationsJsonArray($raw);
        if (count($decoded) !== count($normalized)) {
            throw new RuntimeException(sprintf(
                'Batch translation expected %d segments, got %d',
                count($normalized),
                count($decoded),
            ));
        }

        foreach ($decoded as $i => $item) {
            if (!is_string($item)) {
                throw new RuntimeException(sprintf('Batch translation segment %d is not a string', $i));
            }
        }

        /** @var list<string> $decoded */
        return $decoded;
    }

    private static function formatSingleTranslateUserMessage(string $text, string $from, string $to): string
    {
        return <<<TXT
Source language: {$from}
Target language: {$to}

{$text}
TXT;
    }

    private function translationCacheKey(string $text, string $from, string $to): string
    {
        return hash('sha256', $from . "\0" . $to . "\0" . $text);
    }

    private function translationCacheGet(string $key): ?string
    {
        if ($this->translationCacheMaxEntries <= 0) {
            return null;
        }

        return $this->translationCache[$key] ?? null;
    }

    private function translationCacheSet(string $key, string $value): void
    {
        if ($this->translationCacheMaxEntries <= 0) {
            return;
        }

        if (isset($this->translationCache[$key])) {
            return;
        }

        $this->translationCache[$key] = $value;
        $this->translationCacheOrder[] = $key;

        while (count($this->translationCache) > $this->translationCacheMaxEntries) {
            $oldest = array_shift($this->translationCacheOrder);
            if ($oldest !== null) {
                unset($this->translationCache[$oldest]);
            }
        }
    }

    /**
     * @return list<mixed>
     */
    private static function decodeTranslationsJsonArray(string $raw): array
    {
        $trimmed = $raw;
        if (preg_match('/^```(?:json)?\s*\R(.*?)\R```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Batch translation response is not a JSON array');
        }

        return array_values($decoded);
    }
}
