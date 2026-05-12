<?php

declare(strict_types=1);

namespace Tivins\Llama;

readonly class Message
{
    /**
     * @param array<int, array<string, mixed>>|null $toolCalls OpenAI-style `tool_calls` on an assistant message (after a model requests tools).
     */
    public function __construct(
        public Role $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $name = null,
        public ?array $toolCalls = null,
    ) {
    }
}