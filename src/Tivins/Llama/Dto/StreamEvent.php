<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

/**
 * One replayable streaming step derived from parsed SSE chunks (payload stays JSON-safe).
 */
final readonly class StreamEvent
{
    /**
     * @param array<string, mixed>|string $payload Structured fragments as arrays; opaque text via {@see StreamEventKind::RawChunk}.
     */
    public function __construct(
        public StreamEventKind $kind,
        public array|string $payload,
    ) {
    }

    /**
     * @return array{kind: string, payload: mixed}
     */
    public function toLogArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'payload' => $this->payload,
        ];
    }
}
