<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

/**
 * Typed wrapper around the decoded JSON body from a non-streaming chat completions response.
 *
 * @see \Tivins\Llama\Lama::chatCompletions()
 */
final readonly class RawChatCompletionResponse
{
    /**
     * @param array<string, mixed> $data Raw decoded JSON (OpenAI-shaped).
     */
    public function __construct(public array $data)
    {
    }

    /**
     * First choice's assistant {@see Message}-like fragment (`choices[0].message`), or null if absent.
     *
     * @return array<string, mixed>|null
     */
    public function firstChoiceMessage(): ?array
    {
        $choices = $this->data['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return null;
        }
        $first = $choices[0];
        if (!is_array($first)) {
            return null;
        }
        $message = $first['message'] ?? null;

        return is_array($message) ? $message : null;
    }

    /**
     * @return array<string, mixed>|null Usage statistics block when present.
     */
    public function usage(): ?array
    {
        $usage = $this->data['usage'] ?? null;

        return is_array($usage) ? $usage : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return $this->data;
    }
}
