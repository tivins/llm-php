<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * One OpenAI-style function tool for {@see ChatCompletionOptions::$tools}.
 *
 * Serialises to `{ "type": "function", "function": { "name", "description", "parameters" } }`
 * where `parameters` is a [JSON Schema](https://json-schema.org/) object (`type`, `properties`, `required`, …).
 * Avoid shorthand maps like `['file_path' => 'string']`: they are not valid schema and models may invent keys (`path`, …).
 */
final readonly class ChatFunctionTool
{
    /**
     * @param array<string, mixed> $parameters Full JSON Schema for arguments (`type` => `object`, typed `properties`, `required`).
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {
    }

    /**
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function toToolArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }
}
