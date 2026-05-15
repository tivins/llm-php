<?php

declare(strict_types=1);

/**
 * Unit checks for conversation logging DTOs ({@see \Tivins\Llama\Dto\TurnRecord} and related types).
 *
 * Usage: php tests/turn_record_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\RawStreamTrace;
use Tivins\Llama\Dto\StreamEvent;
use Tivins\Llama\Dto\StreamEventKind;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\StreamResult;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

/**
 * @return mixed
 */
function deep_sort_json_values(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $isList = array_is_list($v);
    foreach ($v as $k => $x) {
        $v[$k] = deep_sort_json_values($x);
    }
    if ($isList) {
        return array_values($v);
    }
    ksort($v);

    return $v;
}

$fixturePath = __DIR__ . '/fixtures/chat_completion_response_min.json';
$fixtureJson = file_get_contents($fixturePath);
$assert($fixtureJson !== false, 'fixture readable');

/** @var array<string, mixed> $fixtureData */
$fixtureData = json_decode((string) $fixtureJson, true, flags: JSON_THROW_ON_ERROR);

$raw = new RawChatCompletionResponse($fixtureData);
$msg = $raw->firstChoiceMessage();
$assert(is_array($msg) && ($msg['role'] ?? null) === 'assistant', 'firstChoiceMessage role');
$assert(($msg['content'] ?? null) === 'Hello!', 'firstChoiceMessage content');
$usage = $raw->usage();
$assert(is_array($usage) && ($usage['total_tokens'] ?? null) === 15, 'usage total_tokens');

$expectedPath = __DIR__ . '/fixtures/turn_record_completion_expected.json';
$expectedJson = file_get_contents($expectedPath);
$assert($expectedJson !== false, 'expected fixture readable');

$record = TurnRecord::forCompletion(
    id: 'turn-test',
    response: $raw,
    requestOptions: new ChatCompletionOptions(temperature: 0.5),
    createdAtIso8601: '2026-01-01T12:00:00+00:00',
);
$log = $record->toLogArray();
$encoded = json_encode($log, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(is_string($encoded), 'completion TurnRecord json_encode OK');

/** @var array<string, mixed> $expectedLog */
$expectedLog = json_decode((string) $expectedJson, true, flags: JSON_THROW_ON_ERROR);
$canonActual = json_encode(deep_sort_json_values($log), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$canonExpected = json_encode(deep_sort_json_values($expectedLog), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert($canonActual === $canonExpected, 'completion toLogArray matches golden fixture');

$roundCompletion = TurnRecord::fromLogArray($log);
$roundLog = $roundCompletion->toLogArray();
$canonRound = json_encode(deep_sort_json_values($roundLog), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert($canonRound === $canonActual, 'completion fromLogArray roundtrips toLogArray');

$emptyOptsRecord = TurnRecord::forCompletion(
    id: 'turn-empty-opts',
    response: $raw,
    requestOptions: new ChatCompletionOptions(),
    createdAtIso8601: '2026-01-01T12:00:00+00:00',
);
$emptyOptsLog = $emptyOptsRecord->toLogArray();
$assert(!array_key_exists('request_options', $emptyOptsLog), 'empty options omitted from log');

$trace = new RawStreamTrace(
    events: [
        new StreamEvent(StreamEventKind::ContentDelta, ['text' => 'Hi']),
        new StreamEvent(StreamEventKind::Finish, ['finish_reason' => 'stop']),
    ],
    rawDataLines: ['{"x":1}'],
);
$streamResult = new StreamResult('Hi', 'stop', [], '');
$streamRecord = TurnRecord::forStream(
    id: 'turn-stream',
    trace: $trace,
    result: $streamResult,
    requestOptions: null,
    createdAtIso8601: '2026-06-01T00:00:00+00:00',
    requestMessages: [
        ['role' => 'system', 'content' => 'You are helpful.'],
        ['role' => 'user', 'content' => 'Hi there'],
    ],
);
$streamLog = $streamRecord->toLogArray();
json_encode($streamLog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(($streamLog['mode'] ?? '') === 'stream', 'stream mode');
$assert(isset($streamLog['request_messages']) && count($streamLog['request_messages']) === 2, 'request_messages in log');
$assert(($streamLog['request_messages'][1]['content'] ?? '') === 'Hi there', 'user content in request_messages');
$assert(isset($streamLog['raw_stream']['events']) && count($streamLog['raw_stream']['events']) === 2, 'stream events');
$assert(($streamLog['stream_result']['finish_reason'] ?? '') === 'stop', 'stream_result finish_reason');
$assert(($streamLog['raw_stream']['raw_data_lines'][0] ?? '') === '{"x":1}', 'raw_data_lines preserved');

$roundStream = TurnRecord::fromLogArray($streamLog);
$roundStreamLog = $roundStream->toLogArray();
$canonStreamRound = json_encode(deep_sort_json_values($roundStreamLog), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$canonStreamOrig = json_encode(deep_sort_json_values($streamLog), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert($canonStreamRound === $canonStreamOrig, 'stream fromLogArray roundtrips toLogArray');

exit($failed ? 1 : 0);
