<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Parses OpenAI-style chat completion SSE {@code data: {...}} lines into a {@see StreamResult}.
 *
 * Used by {@see Lama::chatStream()} and test fixtures; keeps stream parsing in one place.
 *
 * Pass {@see SsePayloadCapture} as the fourth constructor argument to record verbatim JSON payload strings from each parsed {@code data:} line into {@see SsePayloadCapture::$lines}.
 */
final class ChatStreamAccumulator
{
    private string $contentBuffer = '';

    private string $reasoningBuffer = '';

    /** @var array<int, array{id: string, name: string, arguments: string}> */
    private array $toolCallsAccumulator = [];

    private string $finishReason = '';

    /** @var array<string, mixed>|null Last seen top-level {@code usage} object (typically final SSE chunk). */
    private ?array $usage = null;

    private ?string $responseModel = null;

    private ?string $responseId = null;

    /**
     * @param callable(string): void             $onDelta
     * @param (callable(int, string): void)|null  $onToolCallChunk
     * @param (callable(string): void)|null       $onReasoningDelta
     */
    public function __construct(
        private $onDelta,
        private $onToolCallChunk = null,
        private $onReasoningDelta = null,
        private readonly ?SsePayloadCapture $ssePayloadCapture = null,
    ) {
    }

    /**
     * Process one SSE line (without the trailing newline), like {@code data: {...}} or comments.
     */
    public function feedLine(string $line): void
    {
        $line = rtrim($line, "\r");
        if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
            return;
        }
        $data = trim(substr($line, strlen('data:')));
        if ($data === '' || $data === '[DONE]') {
            return;
        }
        $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($parsed)) {
            return;
        }

        if ($this->ssePayloadCapture !== null) {
            $this->ssePayloadCapture->lines[] = $data;
        }

        if (isset($parsed['usage']) && is_array($parsed['usage'])) {
            $this->usage = $parsed['usage'];
        }
        if (isset($parsed['model']) && is_string($parsed['model']) && $parsed['model'] !== '') {
            $this->responseModel = $parsed['model'];
        }
        if (isset($parsed['id']) && is_string($parsed['id']) && $parsed['id'] !== '') {
            $this->responseId = $parsed['id'];
        }

        $choice = $parsed['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }

        $delta = $choice['delta'] ?? null;
        $delta = is_array($delta) ? $delta : [];

        $textDelta = $delta['content'] ?? null;
        if (is_string($textDelta) && $textDelta !== '') {
            $this->contentBuffer .= $textDelta;
            ($this->onDelta)($textDelta);
        }

        $reasoningDelta = null;
        if (\array_key_exists('reasoning_content', $delta)) {
            $v = $delta['reasoning_content'];
            if (is_string($v) && $v !== '') {
                $reasoningDelta = $v;
            }
        }
        if ($reasoningDelta === null) {
            $msg = $choice['message'] ?? null;
            if (is_array($msg)
                && isset($msg['reasoning_content'])
                && is_string($msg['reasoning_content'])
                && $msg['reasoning_content'] !== '') {
                $reasoningDelta = $msg['reasoning_content'];
            }
        }
        if ($reasoningDelta !== null) {
            $this->reasoningBuffer .= $reasoningDelta;
            if ($this->onReasoningDelta !== null) {
                ($this->onReasoningDelta)($reasoningDelta);
            }
        }

        $tcDeltas = $delta['tool_calls'] ?? null;
        if (is_array($tcDeltas)) {
            foreach ($tcDeltas as $tc) {
                if (!is_array($tc)) {
                    continue;
                }
                $idx = (int) ($tc['index'] ?? 0);
                if (!isset($this->toolCallsAccumulator[$idx])) {
                    $this->toolCallsAccumulator[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                }
                if (isset($tc['id']) && is_string($tc['id'])) {
                    $this->toolCallsAccumulator[$idx]['id'] = $tc['id'];
                }
                $fn = $tc['function'] ?? null;
                if (is_array($fn)) {
                    if (isset($fn['name']) && is_string($fn['name'])) {
                        $this->toolCallsAccumulator[$idx]['name'] .= $fn['name'];
                    }
                    $frag = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '';
                    if ($frag !== '') {
                        $this->toolCallsAccumulator[$idx]['arguments'] .= $frag;
                        if ($this->onToolCallChunk !== null) {
                            ($this->onToolCallChunk)($idx, $frag);
                        }
                    }
                }
            }
        }

        $fr = $choice['finish_reason'] ?? null;
        if (is_string($fr) && $fr !== '') {
            $this->finishReason = $fr;
        }
    }

    public function buildResult(): StreamResult
    {
        ksort($this->toolCallsAccumulator);
        $toolCalls = array_values(array_map(
            static fn (array $entry): array => [
                'id'       => $entry['id'],
                'type'     => 'function',
                'function' => [
                    'name'      => $entry['name'],
                    'arguments' => $entry['arguments'],
                ],
            ],
            $this->toolCallsAccumulator,
        ));

        return new StreamResult(
            content: $this->contentBuffer,
            finishReason: $this->finishReason,
            toolCalls: $toolCalls,
            reasoningContent: $this->reasoningBuffer,
            usage: $this->usage,
            model: $this->responseModel,
            id: $this->responseId,
        );
    }
}
