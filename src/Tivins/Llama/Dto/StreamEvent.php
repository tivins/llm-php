<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

use InvalidArgumentException;

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

    /**
     * @param array<string, mixed> $row One element of {@see RawStreamTrace::toLogArray()} {@code events}.
     */
    public static function fromLogArray(array $row): self
    {
        $kindRaw = $row['kind'] ?? null;
        if (!is_string($kindRaw) || $kindRaw === '') {
            throw new InvalidArgumentException('StreamEvent log row missing string kind.');
        }

        $kind = StreamEventKind::tryFrom($kindRaw);
        if ($kind === null) {
            throw new InvalidArgumentException('Unknown StreamEvent kind: ' . $kindRaw);
        }

        $payload = $row['payload'] ?? '';
        if (is_string($payload)) {
            return new self($kind, $payload);
        }
        if (is_array($payload)) {
            return new self($kind, $payload);
        }
        if ($payload === null) {
            return new self($kind, '');
        }

        return new self($kind, (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
