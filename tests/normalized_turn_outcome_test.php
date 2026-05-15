<?php

declare(strict_types=1);

/**
 * Tests {@see \Tivins\Llama\Dto\NormalizedTurnOutcome} and {@see \Tivins\Llama\ChatStreamAccumulator}.
 *
 * Usage: php tests/normalized_turn_outcome_test.php
 */

use Tivins\Llama\ChatStreamAccumulator;
use Tivins\Llama\Dto\NormalizedTurnOutcome;
use Tivins\Llama\SsePayloadCapture;
use Tivins\Llama\StreamResult;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$fixturePath = __DIR__ . '/fixtures/chat_completion_response_min.json';
$fixtureJson = file_get_contents($fixturePath);
$assert($fixtureJson !== false, 'chat_completion fixture readable');

/** @var array<string, mixed> $completion */
$completion = json_decode((string) $fixtureJson, true, flags: JSON_THROW_ON_ERROR);
$norm = NormalizedTurnOutcome::fromChatCompletionArray($completion);
$assert($norm->content === 'Hello!', 'completion content');
$assert($norm->finishReason === 'stop', 'completion finish_reason');
$assert($norm->model === 'fixture-model', 'completion model');
$assert($norm->id === 'chatcmpl-test', 'completion id');
$assert($norm->reasoningContent === '', 'completion reasoning empty');
$assert($norm->toolCalls === [], 'completion tool_calls');
$assert($norm->usage !== null && ($norm->usage['total_tokens'] ?? null) === 15, 'completion usage');

$completionFromNorm = NormalizedTurnOutcome::fromStreamResult(new StreamResult('x', 'stop'));
$assert($completionFromNorm->usage === null, 'fromStreamResult without usage');

$explicitUsage = ['total_tokens' => 99];
$withOverride = NormalizedTurnOutcome::fromStreamResult(new StreamResult('x', 'stop', usage: ['a' => 1]), $explicitUsage);
$assert(($withOverride->usage ?? []) === $explicitUsage, 'fromStreamResult usage override wins');

$toolCompletion = [
    'id' => 'tc-1',
    'model' => 'm-tc',
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'add', 'arguments' => '{}'],
                    ],
                ],
            ],
            'finish_reason' => 'tool_calls',
        ],
    ],
];
$normTools = NormalizedTurnOutcome::fromChatCompletionArray($toolCompletion);
$assert($normTools->finishReason === 'tool_calls', 'tool_calls finish_reason');
$assert(count($normTools->toolCalls) === 1, 'tool_calls count');
$assert(($normTools->toolCalls[0]['function']['name'] ?? null) === 'add', 'tool call name');

// SSE fixture: ChatStreamAccumulator
$ssePath = __DIR__ . '/fixtures/sse_chat_stream_enriched_fixture.sse.txt';
$sseRaw = file_get_contents($ssePath);
$assert($sseRaw !== false, 'SSE fixture readable');

$contentSeen = '';
$reasSeen = '';
$chunks = [];
$captureFixture = new SsePayloadCapture();
$accum = new ChatStreamAccumulator(
    onDelta: static function (string $f) use (&$contentSeen): void {
        $contentSeen .= $f;
    },
    onToolCallChunk: static function (int $i, string $frag) use (&$chunks): void {
        $chunks[] = [$i, $frag];
    },
    onReasoningDelta: static function (string $f) use (&$reasSeen): void {
        $reasSeen .= $f;
    },
    ssePayloadCapture: $captureFixture,
);
$sseNormalized = str_replace(["\r\n", "\r"], "\n", (string) $sseRaw);
foreach (explode("\n", $sseNormalized) as $line) {
    $accum->feedLine($line);
}
$result = $accum->buildResult();

$assert($contentSeen === 'Hi', 'SSE onDelta aggregates');
$assert($reasSeen === 'Step A', 'SSE reasoning');
$assert($result->finishReason === 'tool_calls', 'SSE finish_reason');
$assert($result->content === 'Hi', 'SSE StreamResult content');
$assert(($result->usage['total_tokens'] ?? null) === 6, 'SSE usage from chunk');
$assert($result->model === 'fixture-stream-model', 'SSE model');
$assert($result->id === 'sse-fix-1', 'SSE id');
$assert(count($result->toolCalls) === 1, 'SSE tool_calls count');
$assert(($result->toolCalls[0]['function']['name'] ?? null) === 'sqrt', 'SSE tool name');
$assert(($result->toolCalls[0]['function']['arguments'] ?? null) === '{"x":9}', 'SSE tool args merged');
$assert(count($captureFixture->lines) === 7, 'SSE JSON payloads captured for trace');

$normFromStream = NormalizedTurnOutcome::fromStreamResult($result);
$assert($normFromStream->content === $result->content, 'Normalized matches stream content');
$assert($normFromStream->reasoningContent === 'Step A', 'Normalized reasoning');
$assert($normFromStream->finishReason === 'tool_calls', 'Normalized finish_reason');
$assert($normFromStream->usage === $result->usage, 'Normalized usage carries');

exit($failed ? 1 : 0);
