<?php

declare(strict_types=1);

use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\ThinkingChat;

require __dir__ . '/../vendor/autoload.php';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
try {
    if ($lama->getHealth() !== 'ok') {
        throw new Exception("System is down");
    }

    

    echo "--- thinking conversation (2-pass) ---\n";
    $thinkingChat = new ThinkingChat($lama);
    $turn = $thinkingChat->reply('If I walk 3 km north then 4 km east, how far am I from the start (straight line)? One short sentence for the distance.');
    echo "[thinking]\n" . $turn->thinking . "\n\n[answer]\n" . $turn->answer . "\n";
    echo "--- end thinking ---\n";

    
    echo "--- two step conversation ---\n";
    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, "You are a helpful assistant. Answer in clear prose."));
    $conversation->addMessage(new Message(Role::User, "What is the capital of France?"));
    $outStr = trim($lama->chat($conversation));
    echo $outStr . "\n";
    $conversation->addMessage(new Message(Role::Assistant, $outStr));

    $conversation->addMessage(new Message(Role::User, "What are the 3 biggest cities of this country ? Answer ONLY in JSON format (without markdown decoration)."));
    $outStr = $lama->chat($conversation);
    echo $outStr . "\n";

    echo "--- stream conversation ---\n";
    $streamConv = new Conversation();
    $streamConv->addMessage(new Message(Role::System, "You are a helpful assistant. Answer in clear prose."));
    $streamConv->addMessage(new Message(
        Role::User,
        'List and briefly explain five practical habits that improve learning retention, with one short paragraph per habit (about 3–5 sentences each).',
    ));
    $fullStream = '';
    $lama->chatStream($streamConv, static function (string $delta) use (&$fullStream): void {
        $fullStream .= $delta;
        echo $delta;
    });
    echo "\n--- end stream (length " . strlen($fullStream) . " bytes) ---\n";

    exit(0);
}
catch (Throwable $error) {
    echo "Error:" . PHP_EOL;
    echo $error->getMessage() . PHP_EOL;
    exit(1);
}

