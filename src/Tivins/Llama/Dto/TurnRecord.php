<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\StreamResult;

/**
 * One logical turn for audit / JSONL logs: raw API payload or stream trace plus metadata.
 *
 * **JSON shape from {@see self::toLogArray()}** (all keys optional except where noted):
 * - `id` (string), `created_at` (ISO-8601), `mode` (`completion`|`stream`)
 * - `request_options` (object): snapshot from {@see ChatCompletionOptions::toRequestBody()} when provided at construction
 * - `raw_completion` (object): full non-stream response JSON when `mode === completion`
 * - `raw_stream` (object): `{ events: [...], raw_data_lines?: [...] }` when `mode === stream`
 * - `stream_result` (object): aggregated {@see StreamResult} fields when `mode === stream`
 */
final readonly class TurnRecord
{
    private function __construct(
        public string $id,
        public string $createdAt,
        public string $mode,
        public ?array $requestOptions,
        public ?RawChatCompletionResponse $completionResponse,
        public ?RawStreamTrace $streamTrace,
        public ?StreamResult $streamResult,
    ) {
        if ($mode === 'completion') {
            if ($completionResponse === null || $streamTrace !== null || $streamResult !== null) {
                throw new InvalidArgumentException('completion mode requires raw completion only.');
            }
        } elseif ($mode === 'stream') {
            if ($streamTrace === null || $streamResult === null || $completionResponse !== null) {
                throw new InvalidArgumentException('stream mode requires trace + StreamResult only.');
            }
        } else {
            throw new InvalidArgumentException('mode must be "completion" or "stream".');
        }
    }

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    public static function forCompletion(
        string $id,
        RawChatCompletionResponse $response,
        ?ChatCompletionOptions $requestOptions = null,
        ?string $createdAtIso8601 = null,
    ): self {
        return new self(
            id: $id,
            createdAt: $createdAtIso8601 ?? self::nowUtc(),
            mode: 'completion',
            requestOptions: $requestOptions !== null ? $requestOptions->toRequestBody() : null,
            completionResponse: $response,
            streamTrace: null,
            streamResult: null,
        );
    }

    public static function forStream(
        string $id,
        RawStreamTrace $trace,
        StreamResult $result,
        ?ChatCompletionOptions $requestOptions = null,
        ?string $createdAtIso8601 = null,
    ): self {
        return new self(
            id: $id,
            createdAt: $createdAtIso8601 ?? self::nowUtc(),
            mode: 'stream',
            requestOptions: $requestOptions !== null ? $requestOptions->toRequestBody() : null,
            completionResponse: null,
            streamTrace: $trace,
            streamResult: $result,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        $out = [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'mode' => $this->mode,
        ];
        if ($this->requestOptions !== null && $this->requestOptions !== []) {
            $out['request_options'] = $this->requestOptions;
        }
        if ($this->mode === 'completion') {
            $out['raw_completion'] = $this->completionResponse->toLogArray();
        } else {
            $out['raw_stream'] = $this->streamTrace->toLogArray();
            $out['stream_result'] = [
                'content' => $this->streamResult->content,
                'finish_reason' => $this->streamResult->finishReason,
                'tool_calls' => $this->streamResult->toolCalls,
                'reasoning_content' => $this->streamResult->reasoningContent,
            ];
        }

        return $out;
    }
}
