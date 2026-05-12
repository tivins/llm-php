<?php

declare(strict_types=1);

namespace Tivins\Llama;

use InvalidArgumentException;

class Conversation
{
    public function __construct(
        public array $messages = [],
    )
    {
    }

    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * OpenAI-compatible chat messages, including optional `tool_calls` / `tool_call_id` when set on {@see Message}.
     *
     * @return list<array<string, mixed>>
     */
    public function toChatCompletionMessages(): array
    {
        return array_values(array_map(
            static function (Message $m): array {
                if ($m->toolCalls !== null) {
                    if ($m->role !== Role::Assistant) {
                        throw new InvalidArgumentException(
                            'Message::toolCalls is only valid when role is Role::Assistant',
                        );
                    }
                    $row = [
                        'role' => $m->role->value,
                        'tool_calls' => $m->toolCalls,
                    ];
                    $row['content'] = $m->content === '' ? null : $m->content;

                    return $row;
                }
                if ($m->role === Role::Tool) {
                    if ($m->toolCallId === null || $m->toolCallId === '') {
                        throw new InvalidArgumentException(
                            'Tool role messages require a non-empty toolCallId (from assistant tool_calls[].id)',
                        );
                    }
                    $row = [
                        'role' => Role::Tool->value,
                        'tool_call_id' => $m->toolCallId,
                        'content' => $m->content,
                    ];
                    if ($m->name !== null && $m->name !== '') {
                        $row['name'] = $m->name;
                    }

                    return $row;
                }

                return [
                    'role' => $m->role->value,
                    'content' => $m->content,
                ];
            },
            $this->messages,
        ));
    }
}