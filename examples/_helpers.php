<?php

declare(strict_types=1);

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\HumanTurnRenderer;
use Tivins\Llama\RenderOptions;
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

/**
 * Resolved after {@see example_load_examples_env_file()} (does not overwrite already-set getenv values).
 *
 * - {@code TIVINS_LLAMA_NO_ANSI}=1/true/yes: disable ANSI in {@see HumanTurnRenderer} / {@see HumanTurnStreamDisplay}.
 * - {@code TIVINS_LLAMA_REASONING_STDOUT}=1/true/yes: print reasoning blocks on stdout instead of stderr (non-stream and stream summaries).
 *
 * Omit both to mirror default console demos (ANSI on unless your terminal cannot render escapes; streamed reasoning mirrors stderr tooling output).
 */
function example_render_options_from_env(): RenderOptions
{
    example_load_examples_env_file();

    static $truthy = static function (?string $v): bool {
        if ($v === false || $v === null || $v === '') {
            return false;
        }
        $t = strtolower(trim($v));

        return in_array($t, ['1', 'true', 'yes', 'on'], true);
    };

    $noAnsi = $truthy(getenv('TIVINS_LLAMA_NO_ANSI') !== false ? (string) getenv('TIVINS_LLAMA_NO_ANSI') : null);

    return new RenderOptions(
        ansiColors: !$noAnsi,
        reasoningOnStderr: !$truthy(
            getenv('TIVINS_LLAMA_REASONING_STDOUT') !== false ? (string) getenv('TIVINS_LLAMA_REASONING_STDOUT') : null,
        ),
    );
}

/** When truthy ({@code 1/true/yes/on}), {@see print_output()} prints the verbose legacy diagnostics instead of {@see HumanTurnRenderer}. */
function example_completion_dump_raw_requested(): bool
{
    example_load_examples_env_file();

    static $truthy = static function (?string $v): bool {
        if ($v === false || $v === null || $v === '') {
            return false;
        }
        $t = strtolower(trim($v));

        return in_array($t, ['1', 'true', 'yes', 'on'], true);
    };

    return $truthy(getenv('TIVINS_LLAMA_COMPLETION_DUMP_RAW') !== false ? (string) getenv('TIVINS_LLAMA_COMPLETION_DUMP_RAW') : null);
}

/**
 * Verbose diagnostics for non-stream chat completions payloads (token usage per field, booleans).
 * Prefer {@see print_output()} ({@see HumanTurnRenderer}) unless debugging wire shapes.
 *
 * @param array<string, mixed> $output
 */
function print_completion_payload_debug(array $output): void
{
    echo "\n-----------------------------------------Response-----------------------------------------\n";

    $usage = isset($output['usage']) && is_array($output['usage']) ? $output['usage'] : null;
    echo "Usage:\n";
    if ($usage !== null) {
        echo "- Prompt tokens: " . ($usage['prompt_tokens'] ?? '?') . "\n";
        echo "- Completion tokens: " . ($usage['completion_tokens'] ?? '?') . "\n";
        echo "- Total tokens: " . ($usage['total_tokens'] ?? '?') . "\n";
    } else {
        echo "- (no usage block)\n";
    }

    echo "\n";
    $choices = isset($output['choices']) && is_array($output['choices']) ? $output['choices'] : [];
    echo "Choices count: " . count($choices) . "\n";
    foreach ($choices as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $finishReason = $choice['finish_reason'] ?? 'unknown';
        $isStop = $finishReason === 'stop';
        $isToolCall = $finishReason === 'tool_calls';
        $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : [];
        $content = array_key_exists('content', $message) ? $message['content'] : 'unknown';
        if (!is_string($content)) {
            $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
            $content = $encoded !== false ? $encoded : '?';
        }
        $index = $choice['index'] ?? 0;
        $hasReasoningContent = isset($message['reasoning_content']);
        $reasoningContent = array_key_exists('reasoning_content', $message) ? $message['reasoning_content'] : 'unknown';
        if (!is_string($reasoningContent)) {
            $encoded = json_encode($reasoningContent, JSON_UNESCAPED_UNICODE);
            $reasoningContent = $encoded !== false ? $encoded : '?';
        }

        echo "-- Choice " . ((int) $index + 1) . " --\n";
        echo "- Is stop: " . ($isStop ? 'yes' : 'no') . "\n";
        echo "- Finish reason: " . $finishReason . "\n";
        echo "- Is tool call: " . ($isToolCall ? 'yes' : 'no') . "\n";
        echo "- Content: " . $content . "\n";
        echo '- Tool calls: ' . json_encode($message['tool_calls'] ?? []) . "\n";
        echo '- Has reasoning content: ' . ($hasReasoningContent ? 'yes' : 'no') . "\n";
        echo '- Reasoning content: ' . $reasoningContent . "\n";
        echo "\n";
    }
    echo "-----------------------------------------End of Response-----------------------------------------\n\n";
}

/**
 * Human-readable rendering of a chat completion JSON payload via {@see HumanTurnRenderer::renderCompletionPayload()}.
 *
 * Set {@code TIVINS_LLAMA_COMPLETION_DUMP_RAW=1} (or {@code true}) to print the legacy verbose layout from
 * {@see print_completion_payload_debug()} instead --- useful when inspecting multi-field wire shapes.
 *
 * @param array<string, mixed> $output Decoded response from {@see \Tivins\Llama\Lama::chatCompletions()}.
 */
function print_output(array $output): void
{
    if (example_completion_dump_raw_requested()) {
        print_completion_payload_debug($output);

        return;
    }

    HumanTurnRenderer::renderCompletionPayload($output, example_render_options_from_env());
}
