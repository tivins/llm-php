<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Holds the two system-prompt templates used by ThinkingChat.
 *
 * Both fields default to the built-in reasoning/answering instructions so
 * existing code that never passes ThinkingPrompts keeps the same behaviour.
 * Override one or both to adapt the persona or domain without touching the
 * orchestration logic.
 */
class ThinkingPrompts
{
    public function __construct(
        public readonly string $phase1 = self::DEFAULT_PHASE1,
        public readonly string $phase2 = self::DEFAULT_PHASE2,
    ) {
    }

    private const DEFAULT_PHASE1 = <<<'TXT'
You are a reasoning assistant. For the user's message, produce a careful analysis only:
break down the problem, state assumptions, outline steps, note uncertainties.
Do not deliver the final user-facing answer yet; do not add greetings or filler.
Write in clear prose.
TXT;

    private const DEFAULT_PHASE2 = <<<'TXT'
You are a helpful assistant. You receive the user's original message and a reasoning trace.
Produce the final answer for the user: accurate, direct, and well structured.
Do not paste the full reasoning block back verbatim; synthesize the conclusion.
TXT;

}
