<?php

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __dir__ . '/../vendor/autoload.php';
try {

    $session_file = __DIR__ . "/chat-" . date('Y-m-d-m_H-i-s') . ".json";


    $lama = Lama::fromServerUrl('http://127.0.0.1:8080');

    if ($lama->getHealth() !== 'ok') {
        throw new Exception("System is down");
    }

    $streamConv = new Conversation();
    $streamConv->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));


    $update_session = function () use ($streamConv, $session_file) {
        $messages = array_map(fn (Message $m) => ['role' => $m->role->value, 'content' => $m->content], $streamConv->getMessages());
        file_put_contents($session_file, json_encode($messages, JSON_PRETTY_PRINT));
    };
    $update_session();

    while (true) {
        echo "\e[36mYou> ";
        $user = trim(fgets(STDIN) ?: '');
        echo "\e[0m";
        if (empty($user) || $user === 'q' || $user === 'quit') {
            break;
        }
        $streamConv->addMessage(new Message(Role::User, $user));
        $update_session();

        echo "Bot> ";
        $fullStream = '';
        $lama->chatStream(
            $streamConv,
            static function (string $delta) use (&$fullStream): void {
                $fullStream .= $delta;
                echo $delta;
            },
            new ChatCompletionOptions(temperature: 0.7, top_p: 0.95),
        );
        echo "\n\n";
        $streamConv->addMessage(new Message(Role::Assistant, $fullStream));
        $update_session();
    }
}
catch (\Exception $e) {
    echo $e->getMessage() . PHP_EOL;
    exit(1);
}
