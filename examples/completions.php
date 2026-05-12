<?php

declare(strict_types=1);


use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');


// 
// Example 1: Simple completion
//
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, "What is the capital of France?"));
$output = $lama->chatCompletions($conversation);
print_output($output ?? []);

//
// Example 2: option N
//
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, "Write a short story (100 words max) about a cat."));
$output = $lama->chatCompletions($conversation, new ChatCompletionOptions(n: 3));
print_output($output ?? []);

//
// Example 3: tools (read_file tool)
//
// `parameters` must be a JSON Schema object (`type`, `properties`, `required`, …). A shorthand like
// `['file_path' => 'string']` is not valid schema; servers and models then ignore property names and may emit
// `path`, `filename`, etc. See `examples/chat_tools.php` for the same pattern.
$toolSchemas = [
    new ChatFunctionTool(
        'read_file',
        'Read the contents of a file.',
        [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path of the file to read.',
                ],
            ],
            'required' => ['file_path'],
            'additionalProperties' => false,
        ],
    )->toToolArray(),
    new ChatFunctionTool(
        'write_file',
        'Write text to a file.',
        [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path of the file to write.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Full content to write.',
                ],
            ],
            'required' => ['file_path', 'content'],
            'additionalProperties' => false,
        ],
    )->toToolArray(),
];
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, "Read the file 'story.txt' and return the content."));
$output = $lama->chatCompletions($conversation, new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto'));
print_output($output ?? []);
if (count($output['choices']) > 0) {
    $toolCalls = $output['choices'][0]['message']['tool_calls'] ?? [];
    foreach ($toolCalls as $toolCall) {
        $toolName = $toolCall['function']['name'] ?? 'unknown';
        $toolArgs = json_decode($toolCall['function']['arguments'] ?? '[]', true);
        echo "Tool call: " . $toolName . " with args: " . json_encode($toolArgs) . "\n";
        if ($toolName === 'read_file' && $toolArgs['file_path'] === 'story.txt') {
            echo "Reading file 'story.txt'\n";
            $fileContent = "story.txt content";

            $conversation->addMessage(new Message(Role::Tool, $toolName, $fileContent));
            $output = $lama->chatCompletions($conversation, new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto'));
            print_output($output ?? []);
        }
    }
}










function print_output(array $output): void
{
    echo "=== Output ===\n";


    echo "Usage:\n";
    echo "- Prompt tokens: " . $output['usage']['prompt_tokens'] . "\n";
    echo "- Completion tokens: " . $output['usage']['completion_tokens'] . "\n";
    echo "- Total tokens: " . $output['usage']['total_tokens'] . "\n";

    echo "\n";
    echo "Choices count: " . count($output['choices']) . "\n";
    foreach ($output['choices'] as $choice) {
        $finishReason = $choice['finish_reason'] ?? 'unknown';
        $isStop = $finishReason === 'stop';
        $isToolCall = $finishReason === 'tool_calls';
        $content = $choice['message']['content'] ?? 'unknown';
        $index = $choice['index'] ?? 0;

        echo "-- Choice " . ($index + 1) . " --\n";
        echo "- Is stop: " . ($isStop ? 'yes' : 'no') . "\n";
        echo "- Finish reason: " . $finishReason . "\n";
        echo "- Is tool call: " . ($isToolCall ? 'yes' : 'no') . "\n";
        echo "- Content: " . $content . "\n";
        echo "- Tool calls: " . json_encode($choice['message']['tool_calls'] ?? []) . "\n";
        echo "\n";
    }
}