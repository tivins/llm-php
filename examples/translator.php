<?php

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\Translator;

require __dir__ . '/../vendor/autoload.php';

$from = 'English';
$to = 'French';

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');
$translator = new Translator($lama);

while (true) {
    $input = fgets(STDIN);
    if ($input === false) {
        break;
    }
    $input = trim($input);
    if ($input === '') {
        continue;
    }
    $translation = $translator->translate($input, $from, $to);
    echo "\e[32m$translation\e[0m\n";
}

exit(0);