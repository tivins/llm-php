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
$assert(
    isset($tools['grep'], $tools['web_search'], $tools['apply_diff'], $tools['git_status'], $tools['run_phpunit']),
    'executors registry should define grep / web_search / apply_diff / git_status / run_phpunit',
);

$grepDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'predefined_tools_grep_' . uniqid('', true);
mkdir($grepDir, 0700, true);
$grepFileA = $grepDir . DIRECTORY_SEPARATOR . 'a.txt';
$grepFileB = $grepDir . DIRECTORY_SEPARATOR . 'b.txt';
file_put_contents($grepFileA, "line one\nneedle here\n");
file_put_contents($grepFileB, "other\nnot here\n");
$grepOut = PredefinedTools::runTool('grep', ['pattern' => 'needle', 'path' => $grepDir]);
$grepJson = json_decode($grepOut, true);
$assert(is_array($grepJson) && isset($grepJson['matches']) && count($grepJson['matches']) === 1, 'grep should find one line in temp tree');
@unlink($grepFileA);
@unlink($grepFileB);
@rmdir($grepDir);

$badGrep = PredefinedTools::runTool('grep', ['pattern' => '', 'path' => __DIR__]);
$assert(str_contains($badGrep, 'error'), 'grep with empty pattern should report error');

$repoRoot = realpath(__DIR__ . '/..');
$assert($repoRoot !== false, 'test suite should live inside a resolvable repo root');
if ($repoRoot !== false) {
    $gs = PredefinedTools::runTool('git_status', ['working_directory' => $repoRoot]);
    $gsJson = json_decode($gs, true);
    $assert(
        is_array($gsJson) && array_key_exists('exit_code', $gsJson) && $gsJson['exit_code'] === 0,
        'git_status in repo root should exit 0',
    );
}

$noPhpunit = PredefinedTools::runTool('run_phpunit', [
    'working_directory' => $repoRoot ?: __DIR__,
    'phpunit_path' => 'vendor/bin/phpunit___missing___',
]);
$assert(str_contains($noPhpunit, 'not found'), 'run_phpunit should error when script is missing');

$ws = PredefinedTools::runTool('web_search', ['query' => 'OpenAI']);
$wsJson = json_decode($ws, true);
$assert(
    is_array($wsJson) && (isset($wsJson['abstract']) || isset($wsJson['error'])),
    'web_search should return JSON with abstract or error',
);

$patchDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'predefined_tools_patch_' . uniqid('', true);
mkdir($patchDir, 0700, true);
$pFile = $patchDir . DIRECTORY_SEPARATOR . 'patchme.txt';
file_put_contents($pFile, "a\n");
$unifiedDiff = <<<DIFF
diff --git a/patchme.txt b/patchme.txt
--- a/patchme.txt
+++ b/patchme.txt
@@ -1 +1 @@
-a
+b
DIFF;
$patchOut = PredefinedTools::runTool('apply_diff', [
    'diff' => $unifiedDiff,
    'working_directory' => $patchDir,
    'strip' => 1,
]);
$patchJson = json_decode($patchOut, true);
if (is_array($patchJson) && ($patchJson['ok'] ?? false) === true) {
    $assert(trim((string) file_get_contents($pFile)) === 'b', 'apply_diff should apply unified diff when patch is available');
} else {
    $assert(is_array($patchJson), 'apply_diff should always return a JSON object describing outcome');
}
@unlink($pFile);
@rmdir($patchDir);

if ($failed !== 0) {
    fwrite(STDERR, "\nTests failed ({$failed} assertion(s))\n");
    exit(1);
}

fwrite(STDOUT, "PredefinedTools tests passed.\n");
exit(0);
