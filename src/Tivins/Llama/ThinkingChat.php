<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;

/**
 * Orchestrates two Lama calls: reasoning pass, then answer grounded in that trace.
 * Does not modify Lama.
 */
class ThinkingChat
{
    public function __construct(
        private readonly Lama $lama,
        private readonly ThinkingPrompts $prompts = new ThinkingPrompts(),
    ) {
    }

    /**
     * @throws JsonException
     */
    public function reply(string $userMessage): ThinkingTurnResult
    {
        $phase1 = new Conversation();
        $phase1->addMessage(new Message(Role::System, $this->prompts->phase1));
        $phase1->addMessage(new Message(Role::User, $userMessage));

        $thinking = trim($this->lama->chat($phase1));

        $phase2User = "Original user message:\n$userMessage\n\nReasoning trace:\n$thinking\n\nProvide the final answer to the user.";

        $phase2 = new Conversation();
        $phase2->addMessage(new Message(Role::System, $this->prompts->phase2));
        $phase2->addMessage(new Message(Role::User, $phase2User));

        $answer = trim($this->lama->chat($phase2));

        return new ThinkingTurnResult($userMessage, $thinking, $answer);
    }
}