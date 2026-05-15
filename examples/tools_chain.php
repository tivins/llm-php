<?php

declare(strict_types=1);

/**
 * Non-stream tool calling demo. Optional JSONL log via {@code TIVINS_LLAMA_CONVERSATION_LOG} (see {@see example_turn_jsonl_logger_from_env()} in {@code examples/_helpers.php}): one line per assistant HTTP completion round (initial + each post-tool completion).
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;
use Tivins\Llama\ToolCallingLoop;

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
$output = $lama->chatCompletions($conversation, $options);
if ($output === null || !isset($output['choices'][0])) {
    fwrite(STDERR, "No completion response (null or missing choices).\n");
    exit(1);
}
print_output($output);

$logger = example_turn_jsonl_logger_from_env();
$roundIdx = 0;
example_log_completion_turn($logger, $output, $options, $roundIdx++);

try {
    $output = (new ToolCallingLoop($lama))->runUntilIdle(
        $conversation,
        $options,
        $output,
        PredefinedTools::runTool(...),
        16,
        static function (string $name, array $args): void {
            echo 'Tool call: ' . $name . ' with args: ' . json_encode($args) . "\n";
        },
        static function (array $followUp) use ($logger, $options, &$roundIdx): void {
            print_output($followUp);
            example_log_completion_turn($logger, $followUp, $options, $roundIdx++);
        },
    );
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
