<?php

declare(strict_types=1);

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\TurnJsonlLogger;

/**
 * Loads {@code examples/.env} once (KEY=VALUE lines, {@code #} comments, no export keyword).
 * Does not overwrite variables already present in the process environment ({@see getenv()}).
 */
function example_load_examples_env_file(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path)) {
        return;
    }

    $raw = file($path, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        return;
    }

    foreach ($raw as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($name === '') {
            continue;
        }
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $q = $value[0];
            if (str_ends_with($value, $q) && strlen($value) >= 2) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($name) !== false) {
            continue;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

/**
 * Optional JSONL conversation log when env {@code TIVINS_LLAMA_CONVERSATION_LOG} is set to a file path (append mode).
 * Reads {@code examples/.env} via {@see example_load_examples_env_file()} when this function runs, unless the variable is already set in the environment.
 *
 * Example (bash): {@code export TIVINS_LLAMA_CONVERSATION_LOG=examples/logs/demo.session.jsonl}
 *
 * Convention in migrated examples:
 * - Non-stream tool loops: one JSONL line per HTTP assistant completion ("round"): initial completion plus each follow-up after tool execution (see {@see print_output} / {@see ToolCallingLoop::runUntilIdle()} {@code $afterRoundCompletion}).
 * - Streaming tool loops: one line per streamed assistant round via {@see \Tivins\Llama\StreamingToolCallingLoop} callback (same notion of round).
 *
 * Logs contain model payloads only — avoid pointing this at shared storage if you later plug in backends that echo secrets.
 */
function example_turn_jsonl_logger_from_env(): ?TurnJsonlLogger
{
    example_load_examples_env_file();

    $path = getenv('TIVINS_LLAMA_CONVERSATION_LOG');
    if ($path === false || !is_string($path) || $path === '') {
        return null;
    }

    return new TurnJsonlLogger($path, append: true);
}

/**
 * Stable id segment for JSONL lines derived from OpenAI-shaped completion payloads.
 */
function example_completion_turn_id(array $payload, int $roundIndex): string
{
    $base = isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
        ? $payload['id']
        : 'completion';

    return $base . '-round-' . $roundIndex;
}

function example_log_completion_turn(?TurnJsonlLogger $logger, array $payload, ?ChatCompletionOptions $options, int $roundIndex): void
{
    if ($logger === null) {
        return;
    }

    $logger->logTurn(TurnRecord::forCompletion(
        id: example_completion_turn_id($payload, $roundIndex),
        response: new RawChatCompletionResponse($payload),
        requestOptions: $options,
    ));
}

function print_output(array $output): void
{
    echo "\n-----------------------------------------Response-----------------------------------------\n";
    echo "Usage:\n";
    echo "- Prompt tokens: " . $output['usage']['prompt_tokens'] . "\n";
    echo "- Completion tokens: " . $output['usage']['completion_tokens'] . "\n";
    echo "- Total tokens: " . $output['usage']['total_tokens'] . "\n";

    echo "\n";
    echo "Choices count: " . count($output['choices']) . "\n";
    foreach ($output['choices'] as $choice) {
        $finishReason = $choice['finish_reason'] ?? 'unknown';
        $isStop = $finishReason === 'stop';
        $isToolCall = $finishReason === 'tool_calls';
        $content = $choice['message']['content'] ?? 'unknown';
        $index = $choice['index'] ?? 0;
        $hasReasoningContent = isset($choice['message']['reasoning_content']);
        $reasoningContent = $choice['message']['reasoning_content'] ?? 'unknown';

        echo "-- Choice " . ($index + 1) . " --\n";
        echo "- Is stop: " . ($isStop ? 'yes' : 'no') . "\n";
        echo "- Finish reason: " . $finishReason . "\n";
        echo "- Is tool call: " . ($isToolCall ? 'yes' : 'no') . "\n";
        echo "- Content: " . $content . "\n";
        echo "- Tool calls: " . json_encode($choice['message']['tool_calls'] ?? []) . "\n";
        echo "- Has reasoning content: " . ($hasReasoningContent ? 'yes' : 'no') . "\n";
        echo "- Reasoning content: " . $reasoningContent . "\n";
        echo "\n";
    }
    echo "-----------------------------------------End of Response-----------------------------------------\n\n";
}