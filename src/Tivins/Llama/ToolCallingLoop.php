<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;

/**
 * Runs OpenAI-style tool rounds: replays assistant messages with {@see Message::$toolCalls}, appends
 * {@see Role::Tool} results, and calls {@see Lama::chatCompletions} until the model returns no tool calls
 * or {@see self::runUntilIdle()} exhausts {@code $maxRounds}.
 */
final class ToolCallingLoop
{
    public function __construct(
        private readonly Lama $lama,
    ) {
    }

    /**
     * Continues from an initial {@see Lama::chatCompletions} response that may include {@code tool_calls}.
     *
     * @param callable(string, array<string, mixed>): string $executeTool
     * @param (callable(string, array<string, mixed>): void)|null $onToolCall Optional observer (e.g. logging).
     * @param (callable(array<string, mixed>): void)|null $afterRoundCompletion Invoked after each follow-up {@see Lama::chatCompletions} inside the loop (not after the initial response).
     *
     * @return array<string, mixed> Last completion payload (same shape as {@see Lama::chatCompletions}).
     *
     * @throws RuntimeException When a completion is missing {@code choices[0]}.
     * @throws JsonException From JSON helpers in this path (tool error payloads, request encoding in {@see Lama}).
     */
    public function runUntilIdle(
        Conversation $conversation,
        ?ChatCompletionOptions $options,
        array $output,
        callable $executeTool,
        int $maxRounds = 16,
        ?callable $onToolCall = null,
        ?callable $afterRoundCompletion = null,
    ): array {
        for ($round = 0; $round < $maxRounds; $round++) {
            if (!$this->hasFirstChoice($output)) {
                throw new RuntimeException('Chat completion response missing choices[0] during tool loop');
            }

            $assistantPayload = $output['choices'][0]['message'];
            if (!is_array($assistantPayload)) {
                throw new RuntimeException('Chat completion choices[0].message is not an array');
            }

            $toolCalls = $assistantPayload['tool_calls'] ?? [];
            if ($toolCalls === []) {
                break;
            }

            $conversation->addMessage(new Message(
                Role::Assistant,
                (string) ($assistantPayload['content'] ?? ''),
                toolCalls: $toolCalls,
            ));

            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    throw new RuntimeException('tool_calls entries must be arrays');
                }

                $toolCallId = $toolCall['id'] ?? '';
                $toolCallId = is_string($toolCallId) ? $toolCallId : '';
                $function = $toolCall['function'] ?? null;
                $toolName = 'unknown';
                $argumentsJson = '{}';
                if (is_array($function)) {
                    $fnName = $function['name'] ?? 'unknown';
                    $toolName = is_string($fnName) ? $fnName : 'unknown';
                    $fnArgs = $function['arguments'] ?? '{}';
                    $argumentsJson = is_string($fnArgs) ? $fnArgs : '{}';
                }

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

            $output = $this->lama->chatCompletions($conversation, $options);
            if (!$this->hasFirstChoice($output)) {
                throw new RuntimeException('Chat completion response missing choices[0] after tool round');
            }
            if ($afterRoundCompletion !== null) {
                $afterRoundCompletion($output);
            }
        }

        if (!$this->hasFirstChoice($output)) {
            throw new RuntimeException('Chat completion response missing choices[0] after tool loop');
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $output
     */
    private function hasFirstChoice(array $output): bool
    {
        $choices = $output['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return false;
        }

        $first = $choices[0];

        return is_array($first);
    }
}
