<?php

declare(strict_types=1);

/**
 * Unit checks for {@see \Tivins\Llama\ToolCallingLoop} (no LLM server).
 *
 * Usage: php tests/tool_calling_loop_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\ToolCallingLoop;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if (!$cond) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

final class FakeLama extends Lama
{
    /** @var list<array<string, mixed>> */
    private array $responses = [];

    private int $index = 0;

    /**
     * @param list<array<string, mixed>> $responses
     */
    public function __construct(array $responses)
    {
        parent::__construct('http://127.0.0.1:9', 'fake');
        $this->responses = array_values($responses);
    }

    public function chatCompletions(Conversation $conversation, ?ChatCompletionOptions $options = null): array
    {
        if ($this->index >= count($this->responses)) {
            throw new RuntimeException('FakeLama: no more scripted responses');
        }

        return $this->responses[$this->index++];
    }
}

// 1) No tool calls — no extra HTTP, conversation unchanged
$conv1 = new Conversation();
$conv1->addMessage(new Message(Role::User, 'hi'));
$initial1 = [
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => 'hello',
                'tool_calls' => [],
            ],
        ],
    ],
];
$fake1 = new FakeLama([]);
$loop1 = new ToolCallingLoop($fake1);
$out1 = $loop1->runUntilIdle($conv1, null, $initial1, static fn () => 'unused');
$assert($out1 === $initial1, 'idle output should equal initial when no tool_calls');
$assert(count($conv1->getMessages()) === 1, 'conversation unchanged when no tools');

// 2) One tool round
$toolCalls = [
    [
        'id' => 'call_1',
        'type' => 'function',
        'function' => ['name' => 'add', 'arguments' => '{"a":1,"b":2}'],
    ],
];
$initial2 = [
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => $toolCalls,
            ],
        ],
    ],
];
$afterTool = [
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => '3',
            ],
        ],
    ],
];
$conv2 = new Conversation();
$conv2->addMessage(new Message(Role::User, 'compute'));
$executed = [];
$fake2 = new FakeLama([$afterTool]);
$loop2 = new ToolCallingLoop($fake2);
$out2 = $loop2->runUntilIdle(
    $conv2,
    null,
    $initial2,
    static function (string $name, array $args) use (&$executed): string {
        $executed[] = [$name, $args];

        return (string) ($args['a'] + $args['b']);
    },
);
$assert($out2 === $afterTool, 'final output is second completion');
$assert($executed === [['add', ['a' => 1, 'b' => 2]]], 'tool executor invoked once');
$msgs = $conv2->getMessages();
$assert(count($msgs) === 3, 'user + assistant + tool messages');
$assert($msgs[1]->role === Role::Assistant && $msgs[1]->toolCalls !== null, 'assistant has tool_calls');
$assert($msgs[2]->role === Role::Tool && $msgs[2]->toolCallId === 'call_1', 'tool message');

// 3) Invalid JSON args → error tool message, executeTool not called
$badArgsCall = [
    [
        'id' => 'call_bad',
        'type' => 'function',
        'function' => ['name' => 'x', 'arguments' => 'not-json'],
    ],
];
$initial3 = [
    'choices' => [['message' => ['content' => '', 'tool_calls' => $badArgsCall]]],
];
$afterBad = [
    'choices' => [['message' => ['content' => 'recovered']]],
];
$conv3 = new Conversation();
$conv3->addMessage(new Message(Role::User, 'u'));
$fake3 = new FakeLama([$afterBad]);
$loop3 = new ToolCallingLoop($fake3);
$called = 0;
$out3 = $loop3->runUntilIdle(
    $conv3,
    null,
    $initial3,
    static function () use (&$called): string {
        ++$called;

        return '';
    },
);
$assert($called === 0, 'executeTool not called when JSON invalid');
$assert($out3 === $afterBad, 'completion after bad args');
$assert(str_contains($conv3->getMessages()[2]->content, 'invalid tool arguments JSON'), 'error tool content');

// 4) afterRoundCompletion invoked once per follow-up completion
$afterCb = [];
$conv4 = new Conversation();
$conv4->addMessage(new Message(Role::User, 'x'));
$fake4 = new FakeLama([$afterTool]);
$loop4 = new ToolCallingLoop($fake4);
$loop4->runUntilIdle(
    $conv4,
    null,
    $initial2,
    static fn (string $n, array $a): string => 'ok',
    16,
    null,
    static function (array $o) use (&$afterCb): void {
        $afterCb[] = $o;
    },
);
$assert(count($afterCb) === 1 && $afterCb[0] === $afterTool, 'afterRoundCompletion');

// 5) Missing choices throws
try {
    (new ToolCallingLoop(new FakeLama([])))->runUntilIdle(
        new Conversation(),
        null,
        ['choices' => []],
        static fn () => '',
    );
    fwrite(STDERR, "FAIL: expected RuntimeException for empty choices\n");
    ++$failed;
} catch (RuntimeException) {
}

exit($failed > 0 ? 1 : 0);
