<?php

declare(strict_types=1);

/**
 * Unit checks for {@see \Tivins\Llama\HumanTurnRenderer}, {@see \Tivins\Llama\HumanTurnStreamDisplay},
 * and {@see \Tivins\Llama\RenderOptions} (memory streams, ANSI off).
 *
 * Usage: php tests/human_turn_renderer_test.php
 */

use Tivins\Llama\Dto\NormalizedTurnOutcome;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\HumanTurnRenderer;
use Tivins\Llama\HumanTurnStreamDisplay;
use Tivins\Llama\RenderOptions;
use Tivins\Llama\StreamResult;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$assertContains = static function (string $haystack, string $needle, string $msg) use (&$failed, $assert): void {
    $assert(str_contains($haystack, $needle), $msg . ' — got: ' . $haystack);
};

$readStream = static function (mixed $handle): string {
    rewind($handle);
    $got = stream_get_contents($handle);

    return $got === false ? '' : $got;
};

// --- renderNormalized: reasoning to stderr by default ---

$stdout = fopen('php://memory', 'r+');
$stderr = fopen('php://memory', 'r+');
$opts = new RenderOptions(
    ansiColors: false,
    reasoningOnStderr: true,
    showSectionDividers: false,
    stdoutStream: $stdout,
    stderrStream: $stderr,
);

$outcome = new NormalizedTurnOutcome(
    content: 'Visible reply',
    reasoningContent: "step one\nstep two",
    toolCalls: [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}']]],
    finishReason: 'tool_calls',
    usage: ['prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12],
    model: 'test-model',
    id: 'chatcmpl-x',
);

HumanTurnRenderer::renderNormalized($outcome, $opts);

$so = $readStream($stdout);
$se = $readStream($stderr);

$assertContains($so, 'Visible reply', 'content on stdout');
$assertContains($so, 'Tool calls', 'tool section label on stdout');
$assertContains($so, 'weather', 'tool name echoed on stdout');
$assertContains($so, 'Usage', 'usage header on stdout');
$assertContains($se, 'step one', 'reasoning line on stderr');
$assertContains($se, '[reasoning]', 'reasoning banner on stderr');
$assert(strpos($so, 'step one') === false, 'reasoning must not leak to stdout when reasoningOnStderr=true');

// --- renderTurnRecord from completion fixture ---

$fixturePath = __DIR__ . '/fixtures/turn_record_completion_expected.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$assert(is_array($fixture) && isset($fixture['raw_completion']) && is_array($fixture['raw_completion']), 'fixture shape');

$record = TurnRecord::forCompletion(
    id: 'replay-1',
    response: new RawChatCompletionResponse($fixture['raw_completion']),
    createdAtIso8601: '2026-01-01T12:00:00+00:00',
);

$stdout2 = fopen('php://memory', 'r+');
$stderr2 = fopen('php://memory', 'r+');
$opts2 = new RenderOptions(
    ansiColors: false,
    showSectionDividers: false,
    showTurnMetadata: true,
    stdoutStream: $stdout2,
    stderrStream: $stderr2,
);

HumanTurnRenderer::renderTurnRecord($record, $opts2);

$so2 = $readStream($stdout2);
$assertContains($so2, 'replay-1', 'turn id in metadata');
$assertContains($so2, 'mode=completion', 'mode in metadata');
$assertContains($so2, 'Hello!', 'rendered assistant content from TurnRecord');

// --- renderCompletionPayload with two choices ---

$stdout3 = fopen('php://memory', 'r+');
$opts3 = new RenderOptions(ansiColors: false, showSectionDividers: false, stdoutStream: $stdout3, stderrStream: $stderr2);

HumanTurnRenderer::renderCompletionPayload([
    'model' => 'm',
    'choices' => [
        [
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'A'],
            'finish_reason' => 'stop',
        ],
        [
            'index' => 1,
            'message' => ['role' => 'assistant', 'content' => 'B'],
            'finish_reason' => 'stop',
        ],
    ],
], $opts3);

$so3 = $readStream($stdout3);
$assertContains($so3, 'Choice 1 of 2', 'multi-choice heading');
$assertContains($so3, 'Choice 2 of 2', 'second choice heading');
$assertContains($so3, 'A', 'first body');
$assertContains($so3, 'B', 'second body');

// --- HumanTurnStreamDisplay lane breaks (stdout newlines only, no ANSI) ---

$stdout4 = fopen('php://memory', 'r+');
$stderr4 = fopen('php://memory', 'r+');
$opts4 = new RenderOptions(ansiColors: false, stdoutStream: $stdout4, stderrStream: $stderr4);
$display = new HumanTurnStreamDisplay($opts4);
$display->onDelta('one');
$display->onReasoningDelta('think');
$display->onToolArgumentChunk(0, '{"a"');
$display->onToolCall('echo', ['x' => 1]);

$so4 = $readStream($stdout4);
$se4 = $readStream($stderr4);

$assertContains($so4, 'one', 'stream content');
$assert(str_starts_with($so4, "\none") || str_contains($so4, "\none"), 'newline before first delta lane');
$assertContains($se4, 'think', 'stream reasoning');
$assertContains($se4, '{"a"', 'tool fragment');
$assertContains($se4, '[tool] echo', 'tool summary');

exit($failed > 0 ? 1 : 0);
