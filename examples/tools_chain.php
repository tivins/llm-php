<?php

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$toolSchemas = array_map(
    static fn (ChatFunctionTool $tool): array => $tool->toToolArray(),
    PredefinedTools::all(),
);

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
$conversation->addMessage(new Message(
    Role::User,
    "Read the file 'story.txt' and return only the content, without markdown formatting.",
));

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');
$output = $lama->chatCompletions($conversation, $options);
if ($output === null || !isset($output['choices'][0])) {
    fwrite(STDERR, "No completion response (null or missing choices).\n");
    exit(1);
}
print_output($output);

$maxToolRounds = 16;
for ($round = 0; $round < $maxToolRounds; $round++) {
    $assistantPayload = $output['choices'][0]['message'];
    $toolCalls = $assistantPayload['tool_calls'] ?? [];
    if ($toolCalls === []) {
        break;
    }

    // OpenAI-style tool flow: replay the assistant turn with `tool_calls`, then one `tool`
    // message per call (each must use the same `tool_call_id` as in the assistant message).
    $conversation->addMessage(new Message(
        Role::Assistant,
        (string) ($assistantPayload['content'] ?? ''),
        toolCalls: $toolCalls,
    ));

    foreach ($toolCalls as $toolCall) {
        $toolCallId = $toolCall['id'] ?? '';
        $toolName = $toolCall['function']['name'] ?? 'unknown';
        $argumentsJson = $toolCall['function']['arguments'] ?? '{}';

        try {
            $toolArgs = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($toolArgs)) {
                throw new \JsonException('tool arguments must be a JSON object');
            }
        } catch (\JsonException) {
            $conversation->addMessage(new Message(
                Role::Tool,
                json_encode(['error' => 'invalid tool arguments JSON'], JSON_THROW_ON_ERROR),
                toolCallId: $toolCallId,
                name: $toolName,
            ));
            continue;
        }

        echo 'Tool call: ' . $toolName . ' with args: ' . json_encode($toolArgs) . "\n";

        $toolContent = PredefinedTools::runTool($toolName, $toolArgs);
        $conversation->addMessage(new Message(
            Role::Tool,
            $toolContent,
            toolCallId: $toolCallId,
            name: $toolName,
        ));
    }

    $output = $lama->chatCompletions($conversation, $options);
    if ($output === null || !isset($output['choices'][0])) {
        fwrite(STDERR, "No completion response after tool round (null or missing choices).\n");
        exit(1);
    }
    print_output($output);
}
