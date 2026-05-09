<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Result of a two-phase chat: public reasoning trace then final answer.
 */
readonly class ThinkingTurnResult
{
    public function __construct(
        public string $userMessage,
        public string $thinking,
        public string $answer,
    ) {
    }
}