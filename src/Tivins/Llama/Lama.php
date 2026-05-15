<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;

/**
 * HTTP client for an OpenAI-compatible chat/tokenize/health API (e.g. llama.cpp server).
 *
 * Use {@see ChatCompletionOptions} with {@see self::chat}, {@see self::chatCompletions}, and {@see self::chatStream}
 * to control sampling (`temperature`, `top_p`, …) and generation limits; omitted option fields are not sent so the
 * server keeps its defaults.
 */
class Lama
{
    /**
     * @param string $url The base URL of the API server.
     * @param string $model The model to use.
     * @see fromServerUrl() for a more convenient way to create a client.
     */
    public function __construct(
        public string $url,
        public string $model,
    )
    {
    }

    /**
     * Builds a client using the first model id from GET /v1/models (OpenAI-compatible list).
     *
     * @throws RuntimeException
     */
    public static function fromServerUrl(string $baseUrl): self
    {
        $baseUrl = rtrim($baseUrl, '/');
        $decoded = self::httpGetJson($baseUrl . '/v1/models');
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new RuntimeException('/v1/models returned no models');
        }
        $first = $data[0];
        if (!is_array($first) || !isset($first['id']) || !is_string($first['id']) || $first['id'] === '') {
            throw new RuntimeException('Invalid first model entry in /v1/models response');
        }

        return new self($baseUrl, $first['id']);
    }

    /**
     * @throws JsonException
     */
    public function getHealth(): string
    {
        $raw = $this->getHealthRaw();
        return (string) ($raw['status'] ?? '');
    }

    /**
     * @throws JsonException
     */
    public function getHealthRaw(): array
    {
        return $this->request($this->url . '/health', 'GET', []);
    }

    /**
     * @throws JsonException
     */
    public function tokenize(string $text): array
    {
        $data = $this->request($this->url . '/tokenize', 'POST', [
            'content' => $text,
        ]);
        return $data['tokens'] ?? [];
    }

    /**
     * This function is a shortcut for {@see chatCompletions} and returns the assistant message content for the first completion choice.
     *
     * @param ChatCompletionOptions|null $options Optional sampling and generation parameters (temperature, top_p, …).
     *                                            See {@see ChatCompletionOptions}.
     *
     * @throws JsonException
     */
    public function chat(Conversation $conversation, ?ChatCompletionOptions $options = null): string
    {
        $response = $this->chatCompletions($conversation, $options);
        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Raw OpenAI-style chat completion response (decoded JSON).
     *
     * @param ChatCompletionOptions|null $options Optional parameters merged into the request body; see {@see ChatCompletionOptions}.
     *
     * @throws JsonException
     */
    public function chatCompletions(Conversation $conversation, ?ChatCompletionOptions $options = null): array
    {
        return $this->request(
            $this->url . '/v1/chat/completions',
            'POST',
            $this->chatCompletionRequestBody($conversation, $options, stream: false),
        );
    }

    /**
     * Streams OpenAI-compatible SSE from POST /v1/chat/completions with stream: true.
     *
     * Invokes `$onDelta` for each non-empty text fragment in `choices[0].delta.content`.
     * When the backend exposes chain-of-thought style output, `$onReasoningDelta` is invoked for each
     * non-empty fragment from {@code choices[0].delta.reasoning_content}. If {@code reasoning_content}
     * is omitted from {@code delta} entirely, falls back to {@code choices[0].message.reasoning_content}
     * on that chunk (avoids doubling when both fields carry the same text). Concatenated reasoning is exposed
     * as {@see StreamResult::$reasoningContent}.
     * Tool call argument fragments (when the model decides to call a function) are accumulated
     * internally and returned via {@see StreamResult::$toolCalls}; pass `$onToolCallChunk` to
     * receive live argument fragments as they arrive (useful for progress display).
     * When streamed chunks carry top-level {@code usage}, {@see StreamResult::$usage} is populated
     * with the latest object ({@code null} when the backend omits usage on stream responses).
     *
     * @param callable(string): void                    $onDelta            Called for each visible text token.
     * @param ChatCompletionOptions|null                $options            Sampling / tool schema options.
     * @param (callable(int, string): void)|null        $onToolCallChunk    Optional: called with (toolIndex, argFragment) for every streaming argument chunk.
     * @param (callable(string): void)|null             $onReasoningDelta   Optional: called for each reasoning_content fragment.
     * @param SsePayloadCapture|null                     $captureSsePayloads When non-null, each successfully parsed {@code data:} JSON payload string is appended for {@see \Tivins\Llama\Dto\RawStreamTrace} logs (fine-grained {@see \Tivins\Llama\Dto\StreamEvent} replay stays optional).
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function chatStream(
        Conversation $conversation,
        callable $onDelta,
        ?ChatCompletionOptions $options = null,
        ?callable $onToolCallChunk = null,
        ?callable $onReasoningDelta = null,
        ?SsePayloadCapture $captureSsePayloads = null,
    ): StreamResult {
        $url = $this->url . '/v1/chat/completions';
        $payload = $this->chatCompletionRequestBody($conversation, $options, stream: true);

        $lineBuffer = '';
        $errorBody = '';
        $accumulator = new ChatStreamAccumulator($onDelta, $onToolCallChunk, $onReasoningDelta, $captureSsePayloads);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$lineBuffer,
                &$errorBody,
                $accumulator,
            ): int {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code >= 400) {
                    $errorBody .= $chunk;

                    return strlen($chunk);
                }

                $lineBuffer .= $chunk;
                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    $accumulator->feedLine($line);
                }

                return strlen($chunk);
            },
        ]);

        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        self::assertCurlSucceeded($response, $errno, $curlError);

        if ($httpCode !== 200) {
            $msg = 'HTTP ' . $httpCode;
            if ($errorBody !== '') {
                $msg .= ': ' . substr($errorBody, 0, 800);
            }

            throw new RuntimeException($msg);
        }

        if ($lineBuffer !== '') {
            $accumulator->feedLine($lineBuffer);
        }

        return $accumulator->buildResult();
    }

    /**
     * Assembles the JSON body for {@see chatCompletions} and {@see chatStream}.
     *
     * @return array<string, mixed>
     */
    private function chatCompletionRequestBody(Conversation $conversation, ?ChatCompletionOptions $options, bool $stream): array
    {
        $body = [
            'model' => $this->model,
            'messages' => $conversation->toChatCompletionMessages(),
        ];
        if ($stream) {
            $body['stream'] = true;
        }
        if ($options !== null) {
            $body = array_merge($body, $options->toRequestBody());
        }

        return $body;
    }

    // -- requests --

    /**
     * GET JSON helper used before an instance exists (e.g. /v1/models).
     *
     * @throws RuntimeException
     */
    private static function httpGetJson(string $fullUrl): array
    {
        return self::executeCurlForJson([
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
        ]);
    }

    /**
     * @param array<int, mixed> $curlOptions
     *
     * @throws RuntimeException
     */
    private static function executeCurlForJson(array $curlOptions): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || !is_string($response)) {
            throw new RuntimeException("cURL error ($errno): $error");
        }

        return self::decodeJsonArrayResponse($response);
    }

    /**
     * @throws RuntimeException
     */
    private static function decodeJsonArrayResponse(string $response): array
    {
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Invalid JSON in response: ' . json_last_error_msg() . ' — ' . substr($response, 0, 200)
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('API returned JSON that is not an object or array');
        }

        return $decoded;
    }

    /**
     * @param string|bool $response Value from curl_exec (false on failure, true when using `WRITEFUNCTION` only).
     *
     * @throws RuntimeException
     */
    private static function assertCurlSucceeded(string|bool $response, int $errno, string $curlError): void
    {
        if ($response === false) {
            throw new RuntimeException("cURL error ($errno): $curlError");
        }
    }

    /**
     * @throws JsonException
     */
    private function request(string $url, string $method, array $body): array
    {
        $method = strtoupper($method);
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
            if ($body !== []) {
                $query = http_build_query($body);
                $sep = str_contains($url, '?') ? '&' : '?';
                $options[CURLOPT_URL] = $url . $sep . $query;
            }
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        return self::executeCurlForJson($options);
    }

}