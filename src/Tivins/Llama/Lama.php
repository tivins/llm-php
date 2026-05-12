<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;

class Lama
{
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
     * @throws JsonException
     */
    public function chat(Conversation $conversation): string
    {
        $response = $this->chatCompletions($conversation);
        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * @throws JsonException
     */
    public function chatCompletions(Conversation $conversation): array
    {
        return $this->request($this->url . '/v1/chat/completions', 'POST', [
            'model' => $this->model,
            'messages' => $conversation->toChatCompletionMessages(),
        ]);
    }

    /**
     * Streams OpenAI-compatible SSE (Server-Sent Events) from POST /v1/chat/completions with stream: true.
     * Invokes $onDelta for each non-empty text fragment in choices[0].delta.content.
     *
     * @param callable(string): void $onDelta
     *
     * @throws JsonException
     */
    public function chatStream(Conversation $conversation, callable $onDelta): void
    {
        $url = $this->url . '/v1/chat/completions';
        $payload = [
            'model' => $this->model,
            'messages' => $conversation->toChatCompletionMessages(),
            'stream' => true,
        ];

        $lineBuffer = '';
        $errorBody = '';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$lineBuffer,
                &$errorBody,
                $onDelta
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
                    $line = rtrim($line, "\r");
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, strlen('data:')));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($parsed)) {
                        continue;
                    }
                    $delta = $parsed['choices'][0]['delta']['content'] ?? null;
                    if (is_string($delta) && $delta !== '') {
                        $onDelta($delta);
                    }
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
            $line = rtrim($lineBuffer, "\r");
            if ($line !== '' && !str_starts_with($line, ':') && str_starts_with($line, 'data:')) {
                $data = trim(substr($line, strlen('data:')));
                if ($data !== '' && $data !== '[DONE]') {
                    $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($parsed)) {
                        $delta = $parsed['choices'][0]['delta']['content'] ?? null;
                        if (is_string($delta) && $delta !== '') {
                            $onDelta($delta);
                        }
                    }
                }
            }
        }
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