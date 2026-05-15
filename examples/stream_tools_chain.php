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

$toolSchemas = ChatFunctionTool::toToolArrays(PredefinedTools::all());

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(
    Role::User,
    "Read the file 'story.txt' and return only the content translated into French, without markdown formatting.",
));

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');

try {
    $lastDeltaSource = '';
    $result = (new StreamingToolCallingLoop($lama))->runUntilIdle(
        conversation: $conversation,
        options: $options,
        onDelta: static function (string $delta) use(&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'delta') {
                echo "\n";
            }
            $lastDeltaSource = 'delta';
            echo $delta;
            flush();
        },
        executeTool: PredefinedTools::runTool(...),
        maxRounds: 16,
        onToolCall: static function (string $name, array $args) use (&$lastDeltaSource): void {
            fwrite(STDERR, "\n[tool] " . $name . ' ' . json_encode($args) . "\n");
            $lastDeltaSource = 'tool';
        },
        onToolCallChunk: static function (int $index, string $fragment) use (&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'tool') {
                echo "\n";
            }
            fwrite(STDERR, "\e[32m$fragment\e[0m");
            $lastDeltaSource = 'tool';
        },
        onReasoningDelta: static function (string $s) use (&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'reasoning') {
                echo "\n";
            }
            $lastDeltaSource = 'reasoning';
            fwrite(STDERR, "\e[33m$s\e[0m");
        },
    );
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
