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



