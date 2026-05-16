<?php

declare(strict_types=1);

/**
 * Interactive streamed chat with optional JSONL audit log.
 *
 * Streams assistant **reply** tokens to stdout and native **{@code reasoning_content}** chunks to stderr when the backend provides them ({@see \Tivins\Llama\HumanTurnStreamDisplay} + {@see example_render_options_from_env()}).
 *
 * Set {@code TIVINS_LLAMA_CONVERSATION_LOG} to a path (e.g. {@code examples/logs/chat.{session}.jsonl}) to append one JSON line per user turn:
 * each line is a {@see \Tivins\Llama\Dto\TurnRecord} in stream mode with {@code request_messages} mirroring {@see \Tivins\Llama\Conversation::toChatCompletionMessages()} for that request, and {@see \Tivins\Llama\Dto\RawStreamTrace::$rawDataLines} holding verbatim SSE JSON payloads (event replay via {@see \Tivins\Llama\Dto\StreamEvent} is optional / empty here).
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Conversation;
use Tivins\Llama\Dto\RawStreamTrace;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\HumanTurnStreamDisplay;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\SsePayloadCapture;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

try {

    $session_file = __DIR__ . '/chat-' . date('Y-m-d-m_H-i-s') . '.json';


    $lama = Lama::fromServerUrl('http://127.0.0.1:8080');

    if (!$lama->isHealthy()) {
        throw new Exception('Server is not healthy. Aborting.');
    }

    $logger = example_turn_jsonl_logger_from_env();

    $streamConv = new Conversation();
    $streamConv->addMessage(new Message(Role::System, BehaviorPrompts::HELPFUL));


    $update_session = function () use ($streamConv, $session_file): void {
        $messages = array_map(static function (Message $m): array {
            $row = ['role' => $m->role->value, 'content' => $m->content];
            if ($m->reasoningContent !== null) {
                $row['reasoning_content'] = $m->reasoningContent;
            }

            return $row;
        }, $streamConv->getMessages());
        file_put_contents($session_file, json_encode($messages, JSON_PRETTY_PRINT));
    };
    $update_session();

    while (true) {
        echo "\e[36mYou> ";
        $user = trim(fgets(STDIN) ?: '');
        echo "\e[0m";
        if ($user === '' || $user === 'q' || $user === 'quit') {
            break;
        }
        $streamConv->addMessage(new Message(Role::User, $user));
        $update_session();

        echo 'Bot> ';
        $opts = new ChatCompletionOptions(temperature: 0.7, top_p: 0.95);
        $capture = new SsePayloadCapture();
        $streamDisplay = new HumanTurnStreamDisplay(example_render_options_from_env());
        $streamResult = $lama->chatStream(
            $streamConv,
            $streamDisplay->onDelta(...),
            $opts,
            null,
            $streamDisplay->onReasoningDelta(...),
            $capture,
        );
        fwrite(STDOUT, "\n");

        if ($logger !== null) {
            $turnId = $streamResult->id ?? uniqid('stream_', true);
            $logger->logTurn(TurnRecord::forStream(
                id: $turnId,
                trace: new RawStreamTrace(events: [], rawDataLines: array_values($capture->lines)),
                result: $streamResult,
                requestOptions: $opts,
                requestMessages: $streamConv->toChatCompletionMessages(),
            ));
        }

        $streamConv->addMessage(new Message(
            Role::Assistant,
            $streamResult->content,
            reasoningContent: $streamResult->reasoningContent,
        ));
        $update_session();
    }
} catch (\Exception $e) {
    echo $e->getMessage() . PHP_EOL;
    exit(1);
}
