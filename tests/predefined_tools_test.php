<?php

declare(strict_types=1);

/**
 * Unit checks for {@see \Tivins\Llama\PredefinedTools}.
 *
 * Usage: php tests/predefined_tools_test.php
 */

use Tivins\Llama\PredefinedTools;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$unknown = PredefinedTools::runTool('not_a_tool', []);
$assert(str_contains($unknown, 'unknown tool'), 'unknown tool should encode error');

$readable = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'predefined_tools_test_read_' . uniqid('', true);
file_put_contents($readable, "hello-tools");
$content = PredefinedTools::runTool('read_file', ['file_path' => $readable]);
$assert($content === 'hello-tools', 'read_file should return raw file contents');
@unlink($readable);

$emptyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'predefined_tools_test_empty_' . uniqid('', true);
file_put_contents($emptyFile, '');
$emptyRead = PredefinedTools::runTool('read_file', ['file_path' => $emptyFile]);
$assert($emptyRead === '', 'read_file on an empty file should return an empty string, not failure');
@unlink($emptyFile);

$missing = PredefinedTools::runTool('read_file', ['file_path' => $readable . '__missing']);
$assert(str_contains($missing, 'failed to read'), 'read_file failure should encode error');

$writable = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'predefined_tools_test_write_' . uniqid('', true);
$okWrite = PredefinedTools::runTool('write_file', ['file_path' => $writable, 'content' => 'x']);
$assert($okWrite === '{"ok":true}', 'successful write_file should JSON-encode ok flag');
@unlink($writable);

$nope = DIRECTORY_SEPARATOR . 'nope___' . uniqid('', true) . DIRECTORY_SEPARATOR . 'impossible.bin';
$badWrite = PredefinedTools::runTool('write_file', ['file_path' => $nope, 'content' => 'x']);
$assert(str_contains($badWrite, 'failed to write'), 'write_file failure should encode error');

$dt = PredefinedTools::runTool('get_date_time', []);
$assert(str_contains($dt, '-'), 'get_date_time should return a dated string');

$assert(
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /', PredefinedTools::getDateTime()) === 1,
    'getDateTime local format should start with YYYY-MM-DD HH:MM:SS',
);

$tools = PredefinedTools::getExecuteTools();
$assert(isset($tools['read_file'], $tools['write_file'], $tools['get_date_time']), 'executors registry should define known tools');

if ($failed !== 0) {
    fwrite(STDERR, "\nTests failed ({$failed} assertion(s))\n");
    exit(1);
}

fwrite(STDOUT, "PredefinedTools tests passed.\n");
exit(0);
