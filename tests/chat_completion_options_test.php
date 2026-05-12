<?php

declare(strict_types=1);

/**
 * Unit checks for {@see \Tivins\Llama\ChatCompletionOptions} and {@see \Tivins\Llama\Lama} payload assembly.
 *
 * Usage: php tests/chat_completion_options_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$empty = new ChatCompletionOptions();
$assert($empty->toRequestBody() === [], 'empty options should serialize to []');

$zeroTemp = new ChatCompletionOptions(temperature: 0.0);
$assert(
    $zeroTemp->toRequestBody() === ['temperature' => 0.0],
    'temperature 0 must be included (distinct from null)',
);

$full = new ChatCompletionOptions(
    temperature: 0.7,
    top_p: 0.9,
    max_tokens: 128,
    frequency_penalty: 0.2,
    presence_penalty: 0.1,
    seed: 99,
    stop: ["\n\n", 'END'],
    n: 1,
);
$body = $full->toRequestBody();
$assert($body['temperature'] === 0.7, 'temperature');
$assert($body['top_p'] === 0.9, 'top_p');
$assert($body['max_tokens'] === 128, 'max_tokens');
$assert($body['frequency_penalty'] === 0.2, 'frequency_penalty');
$assert($body['presence_penalty'] === 0.1, 'presence_penalty');
$assert($body['seed'] === 99, 'seed');
$assert($body['n'] === 1, 'n');
$assert($body['stop'] === ["\n\n", 'END'], 'stop list');

$stringStop = new ChatCompletionOptions(stop: '<|end|>');
$assert($stringStop->toRequestBody() === ['stop' => '<|end|>'], 'stop string');

$skipEmptyStopString = new ChatCompletionOptions(stop: '');
$assert($skipEmptyStopString->toRequestBody() === [], 'empty stop string omitted');

$skipEmptyStopList = new ChatCompletionOptions(stop: []);
$assert($skipEmptyStopList->toRequestBody() === [], 'empty stop list omitted');

$lama = new Lama('http://127.0.0.1:8080', 'test-model');
$conv = new Conversation();
$conv->addMessage(new Message(Role::User, 'hi'));
$ref = new ReflectionClass($lama);
$method = $ref->getMethod('chatCompletionRequestBody');
$method->setAccessible(true);

$base = $method->invoke($lama, $conv, null, false);
$assert($base === [
    'model' => 'test-model',
    'messages' => $conv->toChatCompletionMessages(),
], 'base body without stream');

$streamBase = $method->invoke($lama, $conv, null, true);
$assert($streamBase === [
    'model' => 'test-model',
    'messages' => $conv->toChatCompletionMessages(),
    'stream' => true,
], 'base body with stream');

$merged = $method->invoke($lama, $conv, new ChatCompletionOptions(temperature: 0.5, top_p: 0.95), false);
$assert(
    $merged['temperature'] === 0.5 && $merged['top_p'] === 0.95 && $merged['model'] === 'test-model',
    'options merged into body',
);

$tool = new ChatFunctionTool('fn', 'desc', ['type' => 'object', 'properties' => []]);
$withTools = new ChatCompletionOptions(tools: [$tool->toToolArray()], tool_choice: 'auto');
$tBody = $withTools->toRequestBody();
$assert(isset($tBody['tools']) && count($tBody['tools']) === 1, 'tools in body');
$assert($tBody['tool_choice'] === 'auto', 'tool_choice string');
$assert(
    $tBody['tools'][0]['function']['name'] === 'fn',
    'tool function name',
);

$force = new ChatCompletionOptions(tool_choice: ['type' => 'function', 'function' => ['name' => 'get_weather']]);
$assert($force->toRequestBody()['tool_choice']['function']['name'] === 'get_weather', 'tool_choice object');

$skipEmptyToolChoice = new ChatCompletionOptions(tool_choice: '');
$assert(!array_key_exists('tool_choice', $skipEmptyToolChoice->toRequestBody()), 'empty tool_choice omitted');

$skipEmptyTools = new ChatCompletionOptions(tools: []);
$assert(!array_key_exists('tools', $skipEmptyTools->toRequestBody()), 'empty tools list omitted');

if ($failed > 0) {
    exit(1);
}

echo "OK chat_completion_options_test\n";
exit(0);
