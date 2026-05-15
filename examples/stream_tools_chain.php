<?php

declare(strict_types=1);

/**
 * Demonstrates streaming tool use: the model streams text tokens in real time while
 * tool calls are accumulated behind the scenes and executed between rounds.
 *
 * Mirrors examples/tools_chain.php but uses {@see \Tivins\Llama\StreamingToolCallingLoop}
 * with {@see \Tivins\Llama\Lama::chatStream()} instead of non-streaming completions.
 *
 * Optional JSONL via {@code TIVINS_LLAMA_CONVERSATION_LOG}: one line per streamed assistant round (each {@see Lama::chatStream()} completion before tool execution splits), same notion as {@code examples/tools_chain.php} rounds — see {@see example_turn_jsonl_logger_from_env()} in {@code examples/_helpers.php}.
 *
 * Console output uses {@see \Tivins\Llama\HumanTurnStreamDisplay} ({@see example_render_options_from_env()}).
 *
 * Usage: php examples/stream_tools_chain.php
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Dto\RawStreamTrace;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\HumanTurnStreamDisplay;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;
use Tivins\Llama\StreamResult;
use Tivins\Llama\StreamingToolCallingLoop;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$toolSchemas = ChatFunctionTool::toToolArrays(PredefinedTools::all());

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(
    Role::User,
    "Read the file 'story.txt' and return only the content translated into French, without markdown formatting.",
));

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');

$logger = example_turn_jsonl_logger_from_env();

$streamDisplay = new HumanTurnStreamDisplay(example_render_options_from_env());

try {
    (new StreamingToolCallingLoop($lama))->runUntilIdle(
        conversation: $conversation,
        options: $options,
        onDelta: $streamDisplay->onDelta(...),
        executeTool: PredefinedTools::runTool(...),
        maxRounds: 16,
        onToolCall: $streamDisplay->onToolCall(...),
        onToolCallChunk: $streamDisplay->onToolArgumentChunk(...),
        onReasoningDelta: $streamDisplay->onReasoningDelta(...),
        onAssistantStreamRound: static function (StreamResult $result, RawStreamTrace $trace, int $roundIdx) use ($logger, $options, $conversation): void {
            if ($logger === null) {
                return;
            }
            $turnId = $result->id ?? uniqid('stream_round_', true);
            $logger->logTurn(TurnRecord::forStream(
                id: $turnId . '-round-' . $roundIdx,
                trace: $trace,
                result: $result,
                requestOptions: $options,
                requestMessages: $conversation->toChatCompletionMessages(),
            ));
        },
    );
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
