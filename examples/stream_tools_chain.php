<?php

declare(strict_types=1);

/**
 * Demonstrates streaming tool use: the model streams text tokens in real time while
 * tool calls are accumulated behind the scenes and executed between rounds.
 *
 * Mirrors examples/tools_chain.php but uses {@see \Tivins\Llama\StreamingToolCallingLoop}
 * with {@see \Tivins\Llama\Lama::chatStream()} instead of non-streaming completions.
 *
 * Usage: php examples/stream_tools_chain.php
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;
use Tivins\Llama\StreamingToolCallingLoop;

require __DIR__ . '/../vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$toolSchemas = array_map(
    static fn (ChatFunctionTool $tool): array => $tool->toToolArray(),
    PredefinedTools::all(),
);

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(
    Role::User,
    "Read the file 'story.txt' and return only the content translated into French, without markdown formatting.",
));

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');

try {
    $result = (new StreamingToolCallingLoop($lama))->runUntilIdle(
        conversation: $conversation,
        options: $options,
        onDelta: static function (string $delta): void {
            echo $delta;
            flush();
        },
        executeTool: PredefinedTools::runTool(...),
        maxRounds: 16,
        onToolCall: static function (string $name, array $args): void {
            fwrite(STDERR, "\n[tool] " . $name . ' ' . json_encode($args) . "\n");
        },
        onToolCallChunk: static function (int $index, string $fragment): void {
            // Uncomment to watch argument fragments arrive in real time:
            fwrite(STDERR, $fragment);
        },
    );
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
