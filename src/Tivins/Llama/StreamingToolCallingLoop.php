<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;

/**
 * Streaming counterpart of {@see ToolCallingLoop}.
 *
 * Drives an OpenAI-style tool loop using {@see Lama::chatStream()} instead of
 * {@see Lama::chatCompletions()}, so text tokens are delivered to `$onDelta` in real
 * time while tool arguments are accumulated behind the scenes.
 *
 * Each iteration:
 *  1. Streams one completion round, calling `$onDelta` per text token and optionally `$onReasoningDelta`
 *     per `reasoning_content` fragment when the backend supports it.
 *  2. If `finish_reason` is `"tool_calls"`, executes each tool via `$executeTool`, appends
 *     the assistant + tool messages to `$conversation`, then streams again.
 *  3. Stops when no tool calls are present in the result or `$maxRounds` is exhausted.
 *
 * When the final stream round has no tool calls, appends that assistant turn to {@see $conversation}
 * (content only, no {@see Message::$toolCalls}) so the history matches the non-streaming loop.
 * If `$maxRounds` is exhausted while the latest {@see StreamResult} still carries tool calls,
 * throws {@see RuntimeException}.
 */
final class StreamingToolCallingLoop
{
    public function __construct(
        private readonly Lama $lama,
    ) {
    }

    /**
     * @param callable(string): void                          $onDelta         Called for every text token across all rounds.
     * @param callable(string, array<string, mixed>): string  $executeTool     Dispatches a tool call; receives `($name, $args)` and returns the result string.
     * @param (callable(string, array<string, mixed>): void)|null $onToolCall  Optional observer invoked just before each tool is executed (e.g. for logging).
     * @param (callable(string): void)|null                     $onReasoningDelta Optional: receives each `reasoning_content` fragment ({@see Lama::chatStream()}).
     *
     * @return StreamResult Last streaming result (final round with no tool calls).
     *
     * @throws RuntimeException When `$maxRounds` is less than 1, when `$maxRounds` is exhausted while the assistant
     *                            still returns tool calls, or when JSON tool arguments are unparseable.
     * @throws JsonException    From JSON helpers in this path.
     */
    public function runUntilIdle(
        Conversation $conversation,
        ?ChatCompletionOptions $options,
        callable $onDelta,
        callable $executeTool,
        int $maxRounds = 16,
        ?callable $onToolCall = null,
        ?callable $onToolCallChunk = null,
        ?callable $onReasoningDelta = null,
    ): StreamResult {
        if ($maxRounds < 1) {
            throw new RuntimeException('$maxRounds must be at least 1');
        }

        $result = null;

        for ($round = 0; $round < $maxRounds; $round++) {
            $result = $this->lama->chatStream($conversation, $onDelta, $options, $onToolCallChunk, $onReasoningDelta);

            if (!$result->hasToolCalls()) {
                break;
            }

            $conversation->addMessage(new Message(
                Role::Assistant,
                $result->content,
                toolCalls: $result->toolCalls,
            ));

            foreach ($result->toolCalls as $toolCall) {
                $toolCallId    = is_string($toolCall['id'] ?? null)                              ? $toolCall['id']                    : '';
                $toolName      = is_string($toolCall['function']['name'] ?? null)                ? $toolCall['function']['name']      : 'unknown';
                $argumentsJson = is_string($toolCall['function']['arguments'] ?? null)           ? $toolCall['function']['arguments'] : '{}';

                try {
                    $toolArgs = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($toolArgs)) {
                        throw new JsonException('tool arguments must be a JSON object');
                    }
                } catch (JsonException) {
                    $conversation->addMessage(new Message(
                        Role::Tool,
                        json_encode(['error' => 'invalid tool arguments JSON'], JSON_THROW_ON_ERROR),
                        toolCallId: $toolCallId,
                        name: $toolName,
                    ));
                    continue;
                }

                if ($onToolCall !== null) {
                    $onToolCall($toolName, $toolArgs);
                }

                $toolContent = $executeTool($toolName, $toolArgs);
                $conversation->addMessage(new Message(
                    Role::Tool,
                    $toolContent,
                    toolCallId: $toolCallId,
                    name: $toolName,
                ));
            }
        }

        if ($result === null) {
            throw new RuntimeException('No stream result produced (maxRounds was 0)');
        }

        if ($result->hasToolCalls()) {
            throw new RuntimeException(
                'Streaming tool calling loop exhausted $maxRounds while the assistant still returned tool_calls; '
                . 'increase $maxRounds or inspect the stream result.',
            );
        }

        $conversation->addMessage(new Message(
            Role::Assistant,
            $result->content,
        ));

        return $result;
    }
}
