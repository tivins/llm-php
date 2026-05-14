<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Result returned by {@see Lama::chatStream()}.
 *
 * Accumulates the full streamed text, optional reasoning text (for models that expose
 * `reasoning_content`), and any tool calls emitted during the stream.
 * When {@see self::$finishReason} is `"tool_calls"`, {@see self::$toolCalls} contains
 * OpenAI-style tool call entries ready to attach to an assistant {@see Message}.
 */
final readonly class StreamResult
{
    /**
     * @param string                     $content           Concatenated text deltas (may be empty when only tool calls were emitted).
     * @param string                     $finishReason      OpenAI finish_reason: `"stop"`, `"tool_calls"`, `"length"`, etc.
     * @param list<array<string, mixed>> $toolCalls         Reconstructed OpenAI-style tool_calls (empty when no tools were called).
     * @param string                     $reasoningContent  Concatenated `reasoning_content` deltas when the server streams them (thinking models).
     */
    public function __construct(
        public string $content,
        public string $finishReason,
        public array $toolCalls = [],
        public string $reasoningContent = '',
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
