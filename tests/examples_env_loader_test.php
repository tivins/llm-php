<?php

declare(strict_types=1);

/**
 * Smoke test for {@see example_load_examples_env_file()} and {@code examples/.env}.
 *
 * Usage: php tests/examples_env_loader_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../examples/_helpers.php';

$failed = 0;

$assert = static function (bool $ok, string $msg) use (&$failed): void {
    if (!$ok) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$expectedFromFile = 'examples/logs/example.{session}.jsonl';

$before = getenv('TIVINS_LLAMA_CONVERSATION_LOG');
example_load_examples_env_file();
$after = getenv('TIVINS_LLAMA_CONVERSATION_LOG');

if ($before !== false) {
    $assert($after === $before, 'existing TIVINS_LLAMA_CONVERSATION_LOG must not be overridden by .env');
} else {
    $assert($after === $expectedFromFile, 'TIVINS_LLAMA_CONVERSATION_LOG from examples/.env');
}

exit($failed > 0 ? 1 : 0);
