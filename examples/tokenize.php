<?php

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\ThinkingChat;
use Tivins\Llama\ThinkingPrompts;
use Tivins\Llama\Translator;

require __dir__ . '/../vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
$tokens = $lama->tokenize('Hello, world!');
echo "Tokens count: " . count($tokens) . "\n";
echo "Tokens: " . json_encode($tokens) . "\n";
exit(0);