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
    private const PHASE1_SYSTEM = <<<'TXT'
You are a reasoning assistant. For the user's message, produce a careful analysis only:
break down the problem, state assumptions, outline steps, note uncertainties.
Do not deliver the final user-facing answer yet; do not add greetings or filler.
Write in clear prose.
TXT;

    private const PHASE2_SYSTEM = <<<'TXT'
You are a helpful assistant. You receive the user's original message and a reasoning trace.
Produce the final answer for the user: accurate, direct, and well structured.
Do not paste the full reasoning block back verbatim; synthesize the conclusion.
TXT;

    public function __construct(
        private readonly Lama $lama,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function reply(string $userMessage): ThinkingTurnResult
    {
        $phase1 = new Conversation();
        $phase1->addMessage(new Message(Role::System, self::PHASE1_SYSTEM));
        $phase1->addMessage(new Message(Role::User, $userMessage));

        $thinking = trim($this->lama->chat($phase1));

        $phase2User = "Original user message:\n$userMessage\n\nReasoning trace:\n$thinking\n\nProvide the final answer to the user.";

        $phase2 = new Conversation();
        $phase2->addMessage(new Message(Role::System, self::PHASE2_SYSTEM));
        $phase2->addMessage(new Message(Role::User, $phase2User));

        $answer = trim($this->lama->chat($phase2));

        return new ThinkingTurnResult($userMessage, $thinking, $answer);
    }
}