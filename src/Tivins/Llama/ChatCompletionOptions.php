<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Optional parameters for OpenAI-compatible `POST /v1/chat/completions` requests.
 *
 * Only non-null properties are serialized into the JSON body. This matches typical client behaviour:
 * omitted keys let the inference server apply its own defaults.
 *
 * **Specification** — Field names and general meaning follow the
 * [OpenAI Chat Completions API](https://platform.openai.com/docs/api-reference/chat/create).
 * Local servers (e.g. llama.cpp `llama-server`) often support a subset; unsupported keys may be
 * ignored or rejected depending on the server implementation.
 *
 * **Sampling** — {@see self::$temperature} scales randomness in token choice.
 * {@see self::$top_p} (nucleus sampling) restricts the candidate mass; it interacts with temperature.
 * **`temperature` = 0** is valid and usually yields nearly deterministic output (server-dependent).
 *
 * **Penalties** — {@see self::$frequency_penalty} and {@see self::$presence_penalty} reduce repetition;
 * range is typically −2.0 to 2.0 in the OpenAI API.
 *
 * **Stopping** — {@see self::$stop} is either one sequence or several sequences that cut generation
 * when emitted at the end of new text (the stop string itself is usually not included in the reply).
 *
 * **Tools (function calling)** — {@see self::$tools} declares functions the model may call; {@see self::$tool_choice}
 * steers whether/how tools are selected. The assistant reply may contain `tool_calls` instead of plain `content`;
 * this library does not execute tools or build follow-up `tool` messages — use {@see Lama::chatCompletions()} and
 * inspect the decoded array. See {@see ChatFunctionTool} to build each tool entry.
 */
final class ChatCompletionOptions
{
    /**
     * @param ?float $temperature Sampling temperature. Higher values increase randomness. OpenAI documents 0–2;
     *                             many local servers treat ~0.8 as a common default.
     * @param ?float $top_p Nucleus sampling: keep tokens whose cumulative probability mass is at most this value (0–1 typical).
     * @param ?int $max_tokens Maximum tokens to generate in the completion (not including prompt tokens).
     * @param ?float $frequency_penalty Penalise tokens by their frequency in the generated text so far (−2..2 typical).
     * @param ?float $presence_penalty Penalise tokens that have already appeared at least once (−2..2 typical).
     * @param ?int $seed If supported, fixes the random seed for reproducible sampling (integer).
     * @param string|list<string>|null $stop One or more stop sequences. Empty string or empty list is treated as “not set”.
     * @param ?int $n How many chat completion choices to generate. Note: {@see Lama::chat()} still reads only the first choice.
     * @param list<array<string, mixed>>|null $tools OpenAI `tools` array; use {@see ChatFunctionTool::toToolArrays()} or {@see ChatFunctionTool::toToolArray()} per function. Empty list is omitted.
     * @param string|array<string, mixed>|null $tool_choice e.g. `'auto'`, `'none'`, `'required'`, or a forcing object `['type' => 'function', 'function' => ['name' => '…']]`. Empty string is omitted.
     */
    public function __construct(
        public ?float $temperature = null,
        public ?float $top_p = null,
        public ?int $max_tokens = null,
        public ?float $frequency_penalty = null,
        public ?float $presence_penalty = null,
        public ?int $seed = null,
        public string|array|null $stop = null,
        public ?int $n = null,
        public ?array $tools = null,
        public string|array|null $tool_choice = null,
    ) {
    }

    /**
     * Builds the fragment to merge into the chat-completions JSON body (only set fields).
     *
     * @return array<string, mixed>
     */
    public function toRequestBody(): array
    {
        $out = [];

        if ($this->temperature !== null) {
            $out['temperature'] = $this->temperature;
        }
        if ($this->top_p !== null) {
            $out['top_p'] = $this->top_p;
        }
        if ($this->max_tokens !== null) {
            $out['max_tokens'] = $this->max_tokens;
        }
        if ($this->frequency_penalty !== null) {
            $out['frequency_penalty'] = $this->frequency_penalty;
        }
        if ($this->presence_penalty !== null) {
            $out['presence_penalty'] = $this->presence_penalty;
        }
        if ($this->seed !== null) {
            $out['seed'] = $this->seed;
        }
        if ($this->n !== null) {
            $out['n'] = $this->n;
        }

        if ($this->stop !== null) {
            if (is_string($this->stop)) {
                if ($this->stop !== '') {
                    $out['stop'] = $this->stop;
                }
            } elseif ($this->stop !== []) {
                $out['stop'] = array_values($this->stop);
            }
        }

        if ($this->tools !== null && $this->tools !== []) {
            $out['tools'] = array_values($this->tools);
        }
        if ($this->tool_choice !== null) {
            if (!is_string($this->tool_choice) || $this->tool_choice !== '') {
                $out['tool_choice'] = $this->tool_choice;
            }
        }

        return $out;
    }
}
