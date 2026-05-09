<?php

declare(strict_types=1);

namespace Tivins\Llama;

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
     * @return list<array{role: string, content: string}>
     */
    public function toChatCompletionMessages(): array
    {
        return array_values(array_map(
            static fn(Message $m): array => [
                'role' => $m->role->value,
                'content' => $m->content,
            ],
            $this->messages,
        ));
    }

//    public function addToolResult(string $toolName, string $toolResult): void
//    {
//        $this->messages[] = new Message(Role::Tool, $toolName, $toolResult);
//    }
}