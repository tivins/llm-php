<?php

declare(strict_types=1);

/**
 * Tests {@see \Tivins\Llama\TurnJsonlLogger} (append vs overwrite, valid JSON lines).
 *
 * Usage: php tests/turn_jsonl_logger_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\TurnJsonlLogger;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm-php-turn-jsonl-test-' . bin2hex(random_bytes(4));
$assert(@mkdir($dir, 0700, true) || is_dir($dir), 'temp log dir');

$pathAppend = $dir . DIRECTORY_SEPARATOR . 'a.jsonl';
$logAppend = new TurnJsonlLogger($pathAppend, append: true);
$fixturePath = __DIR__ . '/fixtures/chat_completion_response_min.json';
$fixtureJson = file_get_contents($fixturePath);
$assert($fixtureJson !== false, 'fixture readable');
/** @var array<string, mixed> $payload */
$payload = json_decode((string) $fixtureJson, true, flags: JSON_THROW_ON_ERROR);

$record1 = TurnRecord::forCompletion(
    id: 't1',
    response: new RawChatCompletionResponse($payload),
    requestOptions: new ChatCompletionOptions(temperature: 0.25),
    createdAtIso8601: '2026-05-15T10:00:00+00:00',
);
$logAppend->logTurn($record1);
$logAppend->logTurn(TurnRecord::forCompletion(
    id: 't2',
    response: new RawChatCompletionResponse($payload),
    requestOptions: null,
    createdAtIso8601: '2026-05-15T10:01:00+00:00',
));

$lines = file($pathAppend, FILE_IGNORE_NEW_LINES) ?: [];
$assert(count($lines) === 2, 'append writes two lines');
$assert(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['id'] === 't1', 'first line id');
$assert(json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['id'] === 't2', 'second line id');

$pathOverwrite = $dir . DIRECTORY_SEPARATOR . 'b.jsonl';
$logOnce = new TurnJsonlLogger($pathOverwrite, append: false);
$logOnce->logTurn($record1);
$logOnce->logTurn(TurnRecord::forCompletion(
    id: 'only',
    response: new RawChatCompletionResponse($payload),
));
$linesB = file($pathOverwrite, FILE_IGNORE_NEW_LINES) ?: [];
$assert(count($linesB) === 1, 'non-append replaces file each logTurn');
$assert(json_decode($linesB[0], true, flags: JSON_THROW_ON_ERROR)['id'] === 'only', 'last record wins');

exit($failed ? 1 : 0);
