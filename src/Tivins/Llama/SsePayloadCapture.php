<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Collects verbatim JSON payload strings from each parsed {@code data:} SSE line when passed to
 * {@see Lama::chatStream()} / {@see ChatStreamAccumulator}, for building {@see \Tivins\Llama\Dto\RawStreamTrace::$rawDataLines}.
 *
 * Each entry is the trimmed payload after the {@code data:} prefix (excluding {@code [DONE]} and empty lines).
 */
final class SsePayloadCapture
{
    /** @var list<string> */
    public array $lines = [];
}
