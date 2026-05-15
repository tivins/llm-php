<?php

declare(strict_types=1);

/**
 * Verifies {@code {session}} in {@code TIVINS_LLAMA_CONVERSATION_LOG} resolves once per process in {@see example_turn_jsonl_logger_from_env()}.
 *
 * Usage: php tests/conversation_log_path_session_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Dto\RawChatCompletionResponse;
use Tivins\Llama\Dto\TurnRecord;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../examples/_helpers.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$prevLog = getenv('TIVINS_LLAMA_CONVERSATION_LOG');

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm-php-session-log-' . bin2hex(random_bytes(4));
$assert(@mkdir($dir, 0700, true) || is_dir($dir), 'temp log dir');

$template = $dir . DIRECTORY_SEPARATOR . 'audit.{session}.jsonl';
putenv('TIVINS_LLAMA_CONVERSATION_LOG=' . $template);
$_ENV['TIVINS_LLAMA_CONVERSATION_LOG'] = $template;

try {
    $fixturePath = __DIR__ . '/fixtures/chat_completion_response_min.json';
    $fixtureJson = file_get_contents($fixturePath);
    $assert($fixtureJson !== false, 'fixture readable');
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $fixtureJson, true, flags: JSON_THROW_ON_ERROR);

    $record = TurnRecord::forCompletion(
        id: 'sess-test',
        response: new RawChatCompletionResponse($payload),
        requestOptions: new ChatCompletionOptions(temperature: 0.25),
        createdAtIso8601: '2026-05-15T10:00:00+00:00',
    );

    $assert(getenv('TIVINS_LLAMA_CONVERSATION_LOG') === $template, 'getenv still holds template path');

    $log1 = example_turn_jsonl_logger_from_env();
    $assert($log1 !== null, 'logger from template');
    $log1->logTurn($record);

    $log2 = example_turn_jsonl_logger_from_env();
    $assert($log2 !== null, 'second logger from template');
    $log2->logTurn(TurnRecord::forCompletion(
        id: 'sess-test-2',
        response: new RawChatCompletionResponse($payload),
        requestOptions: null,
        createdAtIso8601: '2026-05-15T10:01:00+00:00',
    ));

    $files = glob($dir . DIRECTORY_SEPARATOR . 'audit.*.jsonl') ?: [];
    $assert(count($files) === 1, 'single resolved log file for both loggers in one process');

    $lines = file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
    $assert(count($lines) === 2, 'both turns appended to same file');
    $assert(strpos($files[0], '{session}') === false, 'resolved path has no placeholder');
} finally {
    if (is_dir($dir)) {
        $gone = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($gone as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    if ($prevLog === false) {
        putenv('TIVINS_LLAMA_CONVERSATION_LOG');
        unset($_ENV['TIVINS_LLAMA_CONVERSATION_LOG']);
    } else {
        putenv('TIVINS_LLAMA_CONVERSATION_LOG=' . $prevLog);
        $_ENV['TIVINS_LLAMA_CONVERSATION_LOG'] = $prevLog;
    }
}

exit($failed ? 1 : 0);
