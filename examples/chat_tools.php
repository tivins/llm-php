<?php

declare(strict_types=1);

/**
 * Declares function tools on the request; prints the raw JSON-decoded completion (may include assistant `tool_calls`).
 * Does not execute tools or send `role: tool` follow-ups — see {@see \Tivins\Llama\Lama::chatCompletions()}.
 *
 * Usage: php examples/chat_tools.php
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

try {
    if ($lama->getHealth() !== 'ok') {
        throw new RuntimeException('Server health is not ok');
    }

    $getWeather = new ChatFunctionTool(
        name: 'get_weather',
        description: 'Get the current weather for a city.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'City name, e.g. Paris',
                ],
                'unit' => [
                    'type' => 'string',
                    'enum' => ['celsius', 'fahrenheit'],
                    'description' => 'Temperature unit',
                ],
            ],
            'required' => ['city'],
        ],
    );

    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));
    $conversation->addMessage(new Message(
        Role::User,
        'What is the weather like in Lyon right now? Use a tool if available, else answer briefly.',
    ));

    $options = new ChatCompletionOptions(
        temperature: 0.2,
        tools: [$getWeather->toToolArray()],
        tool_choice: 'auto',
    );

    $raw = $lama->chatCompletions($conversation, $options);

    echo json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
