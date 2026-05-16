<?php

declare(strict_types=1);

/**
 * Unit checks for {@see \Tivins\Llama\ToolCallingLoop} and {@see \Tivins\Llama\StreamingToolCallingLoop} (no LLM server).
 *
 * Usage: php tests/tool_calling_loop_test.php
 */

use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\Conversation;
use Tivins\Llama\Dto\RawStreamTrace;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;
use Tivins\Llama\SsePayloadCapture;
use Tivins\Llama\StreamResult;
use Tivins\Llama\StreamingToolCallingLoop;
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
        $this->responses = $responses;
    }

    public function chatCompletions(Conversation $conversation, ?ChatCompletionOptions $options = null): array
    {
        if ($this->index >= count($this->responses)) {
            throw new RuntimeException('FakeLama: no more scripted responses');
        }

        return $this->responses[$this->index++];
    }
}

final class FakeStreamLama extends Lama
{
    /** @var list<StreamResult> */
    private array $results = [];

    private int $index = 0;

    /**
     * @param list<StreamResult> $results
     */
    public function __construct(array $results)
    {
        parent::__construct('http://127.0.0.1:9', 'fake');
        $this->results = $results;
    }

    /**
     * @param callable(string): void                             $onDelta
     * @param (callable(int, string): void)|null                 $onToolCallChunk
     * @param (callable(string): void)|null                     $onReasoningDelta
     */
    public function chatStream(
        Conversation $conversation,
        callable $onDelta,
        ?ChatCompletionOptions $options = null,
        ?callable $onToolCallChunk = null,
        ?callable $onReasoningDelta = null,
        ?SsePayloadCapture $captureSsePayloads = null,
    ): StreamResult {
        if ($this->index >= count($this->results)) {
            throw new RuntimeException('FakeStreamLama: no more scripted stream results');
        }

        return $this->results[$this->index++];
    }
}

// 1) No tool calls — no extra HTTP ; final assistant appended
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
$msgs1 = $conv1->getMessages();
$assert(count($msgs1) === 2, 'conversation gains final assistant message when no tools');
$assert($msgs1[1]->role === Role::Assistant && $msgs1[1]->toolCalls === null && $msgs1[1]->content === 'hello', 'final assistant content');

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
$assert(count($msgs) === 4, 'user + assistant(tool) + tool + assistant final');
$assert($msgs[1]->role === Role::Assistant && $msgs[1]->toolCalls !== null, 'assistant has tool_calls');
$assert($msgs[2]->role === Role::Tool && $msgs[2]->toolCallId === 'call_1', 'tool message');
$assert($msgs[3]->role === Role::Assistant && $msgs[3]->toolCalls === null && $msgs[3]->content === '3', 'final assistant without tool_calls');

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
$m3 = $conv3->getMessages();
$assert(count($m3) === 4 && str_contains($m3[2]->content, 'invalid tool arguments JSON'), 'error tool content');
$assert($m3[3]->role === Role::Assistant && $m3[3]->content === 'recovered', 'final assistant appended after recovery');

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

// 6) Exhaust $maxRounds while model still asks for tools → RuntimeException (non-stream)
$stillWantTools = [
    'choices' => [
        [
            'message' => [
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_repeat',
                        'type' => 'function',
                        'function' => ['name' => 'noop', 'arguments' => '{}'],
                    ],
                ],
            ],
        ],
    ],
];
$conv6 = new Conversation();
$conv6->addMessage(new Message(Role::User, 'go'));
$fake6 = new FakeLama([$stillWantTools]);
$loop6 = new ToolCallingLoop($fake6);
$threw6 = false;
try {
    $loop6->runUntilIdle($conv6, null, $initial2, static fn () => 'x', 1);
} catch (RuntimeException $e) {
    $threw6 = true;
    $assert(str_contains($e->getMessage(), 'exhausted $maxRounds'), 'exhaustion message mentions maxRounds');
}
$assert($threw6, 'expected RuntimeException when maxRounds exhausted with pending tool_calls');

// 7) Streaming — idle: final assistant in conversation + StreamResult
$convS1 = new Conversation();
$convS1->addMessage(new Message(Role::User, 'hi'));
$rHello = new StreamResult(content: 'hello', finishReason: 'stop');
$loops1 = new StreamingToolCallingLoop(new FakeStreamLama([$rHello]));
$rOut1 = $loops1->runUntilIdle(
    $convS1,
    null,
    static function (string $_): void {},
    static fn (): string => 'noop',
);
$assert($rOut1 === $rHello, 'stream idle returns scripted result');
$msS1 = $convS1->getMessages();
$assert(count($msS1) === 2 && $msS1[1]->content === 'hello', 'streaming conversation gets final assistant');

// 8) Streaming — mirror one tool round vs non-stream
$rTools = new StreamResult('', 'tool_calls', toolCalls: $toolCalls);
$rFinalStream = new StreamResult('3', 'stop');
$convS2 = new Conversation();
$convS2->addMessage(new Message(Role::User, 'compute'));
$executedStream = [];
$loops2 = new StreamingToolCallingLoop(new FakeStreamLama([$rTools, $rFinalStream]));
$loops2->runUntilIdle(
    $convS2,
    null,
    static function (string $_): void {},
    static function (string $name, array $args) use (&$executedStream): string {
        $executedStream[] = [$name, $args];

        return (string) ($args['a'] + $args['b']);
    },
);
$assert($executedStream === [['add', ['a' => 1, 'b' => 2]]], 'streaming tool executor mirror');
$mS2 = $convS2->getMessages();
$assert(count($mS2) === 4, 'streaming: user + assistant(tool) + tool + assistant final');
$assert($mS2[3]->toolCalls === null && $mS2[3]->content === '3', 'streaming final assistant');

// 9) Streaming — maxRounds exhausted with tool_calls pending
$rToolsOnly = new StreamResult('', 'tool_calls', toolCalls: $toolCalls);
$convS9 = new Conversation();
$convS9->addMessage(new Message(Role::User, 'x'));
$loops9 = new StreamingToolCallingLoop(new FakeStreamLama([$rToolsOnly]));
$threwS9 = false;
try {
    $loops9->runUntilIdle(
        $convS9,
        null,
        static function (string $_): void {},
        static fn (): string => '',
        1,
    );
} catch (RuntimeException $e) {
    $threwS9 = true;
    $assert(str_contains($e->getMessage(), 'exhausted $maxRounds'), 'stream exhaustion msg');
}
$assert($threwS9, 'streaming throws when exhausted with tool_calls');

// 11) onAssistantStreamRound once per streamed assistant round (indices 0..n)
$streamRoundCb = [];
$rToolsCb = new StreamResult('', 'tool_calls', toolCalls: $toolCalls);
$rFinalCb = new StreamResult('done', 'stop');
$convS11 = new Conversation();
$convS11->addMessage(new Message(Role::User, 'compute'));
$loops11 = new StreamingToolCallingLoop(new FakeStreamLama([$rToolsCb, $rFinalCb]));
$loops11->runUntilIdle(
    $convS11,
    null,
    static function (string $_): void {},
    static fn (): string => '0',
    maxRounds: 16,
    onAssistantStreamRound: static function (StreamResult $_r, RawStreamTrace $_t, int $idx) use (&$streamRoundCb): void {
        $streamRoundCb[] = $idx;
    },
);
$assert($streamRoundCb === [0, 1], 'onAssistantStreamRound indices');

// 12) Missing choices throws
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
