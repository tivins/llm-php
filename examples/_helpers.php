<?php

declare(strict_types=1);

function print_output(array $output): void
{
    echo "\n-----------------------------------------Response-----------------------------------------\n";
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
        $hasReasoningContent = isset($choice['message']['reasoning_content']);
        $reasoningContent = $choice['message']['reasoning_content'] ?? 'unknown';

        echo "-- Choice " . ($index + 1) . " --\n";
        echo "- Is stop: " . ($isStop ? 'yes' : 'no') . "\n";
        echo "- Finish reason: " . $finishReason . "\n";
        echo "- Is tool call: " . ($isToolCall ? 'yes' : 'no') . "\n";
        echo "- Content: " . $content . "\n";
        echo "- Tool calls: " . json_encode($choice['message']['tool_calls'] ?? []) . "\n";
        echo "- Has reasoning content: " . ($hasReasoningContent ? 'yes' : 'no') . "\n";
        echo "- Reasoning content: " . $reasoningContent . "\n";
        echo "\n";
    }
    echo "-----------------------------------------End of Response-----------------------------------------\n\n";
}