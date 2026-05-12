<?php

declare(strict_types=1);


use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\PredefinedTools;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

//
// example tools (read_file tool)
//
$toolSchemas = [
    PredefinedTools::getReadFileTool()->toToolArray(),
    PredefinedTools::getWriteFileTool()->toToolArray(),
    PredefinedTools::getDateTimeTool()->toToolArray(),
];
$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(Role::User, "Read the file 'story.txt' and return only the content, without markdown formatting."));
$output = $lama->chatCompletions($conversation, new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto'));
print_output($output ?? []);
if (count($output['choices']) > 0) {
    $assistantPayload = $output['choices'][0]['message'];
    $toolCalls = $assistantPayload['tool_calls'] ?? [];
    if ($toolCalls !== []) {
        // OpenAI-style tool flow: replay the assistant turn with `tool_calls`, then one `tool` message per call
        // (each must use the same `tool_call_id` as in the assistant message).
        $conversation->addMessage(new Message(
            Role::Assistant,
            (string) ($assistantPayload['content'] ?? ''),
            toolCalls: $toolCalls,
        ));
        foreach ($toolCalls as $toolCall) {
            $toolCallId = $toolCall['id'] ?? '';
            $toolName = $toolCall['function']['name'] ?? 'unknown';
            $toolArgs = json_decode($toolCall['function']['arguments'] ?? '[]', true);
            echo "Tool call: " . $toolName . " with args: " . json_encode($toolArgs) . "\n";
            if ($toolName === 'read_file' && isset($toolArgs['file_path'])) {
                $filePath = $toolArgs['file_path'];
                echo "Reading file '$filePath'\n";
                $fileContent = PredefinedTools::getExecuteTools()['read_file']($toolArgs);
                if ($fileContent === false) {
                    $conversation->addMessage(new Message(
                        Role::Tool,
                        json_encode(['error' => 'failed to read file'], JSON_THROW_ON_ERROR),
                        toolCallId: $toolCallId,
                        name: $toolName,
                    ));
                } 
                else {
                    $conversation->addMessage(new Message(
                        Role::Tool,
                        $fileContent,
                        toolCallId: $toolCallId,
                        name: $toolName,
                    ));
                }
            } else {
                // Still return a result for every call id so the model is not left with a dangling tool_call.
                $conversation->addMessage(new Message(
                    Role::Tool,
                    json_encode(['error' => 'unsupported or unknown tool arguments'], JSON_THROW_ON_ERROR),
                    toolCallId: $toolCallId,
                    name: $toolName,
                ));
            }
        }
        $output = $lama->chatCompletions($conversation, new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto'));
        print_output($output ?? []);
    }
}










