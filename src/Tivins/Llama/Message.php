<?php

declare(strict_types=1);

namespace Tivins\Llama;

readonly class Message
{
    /**
     * OpenAI-style {@code reasoning_content} for assistant turns (native model reasoning stream).
     * {@code null} means omit when serializing to chat completions; {@see self::normalizeReasoningContent()}
     * maps {@code ''} to {@code null} so empty and absent are equivalent in memory.
     */
    public readonly ?string $reasoningContent;

    /**
     * @param array<int, array<string, mixed>>|null $toolCalls OpenAI-style `tool_calls` on an assistant message (after a model requests tools).
     * @param string|null                           $reasoningContent Native {@code reasoning_content} text; {@code null} or {@code ''} omit key when serializing.
     */
    public function __construct(
        public Role $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
        public ?array $toolCalls = null,
        ?string $reasoningContent = null,
    ) {
        $this->reasoningContent = self::normalizeReasoningContent($reasoningContent);
    }

    /**
     * Canonical empty/absent handling for {@see self::$reasoningContent}: {@code null} and {@code ''} both mean absent for JSON output.
     *
     * @return non-empty-string|null
     */
    public static function normalizeReasoningContent(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
