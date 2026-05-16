<?php

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\ThinkingChat;
use Tivins\Llama\ThinkingPrompts;
use Tivins\Llama\Translator;

require __dir__ . '/../vendor/autoload.php';

function usage(string $message = ''): void
{
    if ($message !== '') {
        echo "Error: " . $message . "\n\n";
    }
    echo "Usage: php tokenize.php 'text to tokenize'\n";
    echo "Or: php tokenize.php 'file to tokenize'\n";
    echo "\nOptions:\n";
    echo "  --tokens, -t: Show the tokens\n";
    echo "  --tokens-count, -tc: Show the tokens count\n";
    echo "\nEnvironment variables:\n";
    echo "  TIVINS_LLAMA_SERVER_URL: Server URL (default: http://127.0.0.1:8080)\n";
    exit(1);
}

$text = $argv[1] ?? 'Hello, world!';
if ($text === '') {
    usage('Text is empty');
}
if (is_file($text)) {
    $text = file_get_contents($text);
}
if (is_dir($text)) {
    usage('Text is a directory');
}
if (!is_string($text)) {
    usage('Text is not a string');
}

$showTokens = in_array('--tokens', $argv ?? []) || in_array('-t', $argv ?? []);
$showTokensCount = in_array('--tokens-count', $argv ?? []) || in_array('-tc', $argv ?? []);

if (!$showTokens && !$showTokensCount) {
    usage('No output option selected');
}

$serverUrl = 'http://127.0.0.1:8080';
if (getenv('TIVINS_LLAMA_SERVER_URL')) {
    $serverUrl = getenv('TIVINS_LLAMA_SERVER_URL');
}
if ($serverUrl === '') {
    usage('Server URL is empty');
}
try {
    $lama = Lama::fromServerUrl($serverUrl);
    if (!$lama->isHealthy()) {
        throw new Exception('Server is not healthy. Aborting.');
    }
    $tokens = $lama->tokenize($text);
    if ($showTokensCount) {
        echo count($tokens) . "\n";
    }
    if ($showTokens) {
        echo json_encode($tokens) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    usage($e->getMessage());
}