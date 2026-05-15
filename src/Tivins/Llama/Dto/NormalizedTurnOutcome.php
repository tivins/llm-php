<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

use Tivins\Llama\StreamResult;

/**
 * Normalized aggregate for one assistant turn across streaming and non-streaming APIs.
 *
 * Built from decoded chat completion JSON or {@see StreamResult}; suitable for logs, UI,
 * or completing {@see TurnRecord}-style artifacts without duplicating parsing in examples.
 */
final readonly class NormalizedTurnOutcome
{
    /**
     * @param string                     $content
     * @param string                     $reasoningContent  Concatenated native {@code reasoning_content} when present.
     * @param list<array<string, mixed>> $toolCalls         OpenAI-style {@code tool_calls} entries (empty list if none).
     * @param string                     $finishReason      e.g. {@code stop}, {@code tool_calls}, {@code length}.
     * @param array<string, mixed>|null $usage              Top-level completion {@code usage} when available.
     * @param string|null                $model
     * @param string|null                $id                 Completion/stream response id when available.
     */
    public function __construct(
        public string $content,
        public string $reasoningContent,
        public array $toolCalls,
        public string $finishReason,
        public ?array $usage,
        public ?string $model,
        public ?string $id,
    ) {
    }

    /**
     * Normalize from raw {@see Lama::chatCompletions()} payload (decoded JSON array).
     *
     * @param array<string, mixed> $response
     */
    public static function fromChatCompletionArray(array $response): self
    {
        $choices = $response['choices'] ?? null;
        $choice0 = (is_array($choices) && isset($choices[0]) && is_array($choices[0]))
            ? $choices[0]
            : [];

        $message = $choice0['message'] ?? null;
        $message = is_array($message) ? $message : [];

        $content = $message['content'] ?? '';
        $content = is_string($content) ? $content : '';

        $reasoning = $message['reasoning_content'] ?? '';
        $reasoning = is_string($reasoning) ? $reasoning : '';

        $toolCallsRaw = $message['tool_calls'] ?? null;
        $toolCalls = [];
        if (is_array($toolCallsRaw)) {
            foreach ($toolCallsRaw as $tc) {
                if (is_array($tc)) {
                    $toolCalls[] = $tc;
                }
            }
        }

        $finishReason = $choice0['finish_reason'] ?? '';
        $finishReason = is_string($finishReason) ? $finishReason : '';

        $usage = isset($response['usage']) && is_array($response['usage'])
            ? $response['usage']
            : null;

        $model = isset($response['model']) && is_string($response['model']) && $response['model'] !== ''
            ? $response['model']
            : null;

        $id = isset($response['id']) && is_string($response['id']) && $response['id'] !== ''
            ? $response['id']
            : null;

        return new self(
            content: $content,
            reasoningContent: $reasoning,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            model: $model,
            id: $id,
        );
    }

    /**
     * @param array<string, mixed>|null $usage Optional override; defaults to {@see StreamResult::$usage}.
     */
    public static function fromStreamResult(StreamResult $result, ?array $usage = null): self
    {
        $mergedUsage = $usage ?? $result->usage;

        return new self(
            content: $result->content,
            reasoningContent: $result->reasoningContent,
            toolCalls: $result->toolCalls,
            finishReason: $result->finishReason,
            usage: $mergedUsage,
            model: $result->model,
            id: $result->id,
        );
    }
}
