<?php

declare(strict_types=1);

/**
 * Diagnostic streaming: détecte deltas cumulatifs (snapshot du texte complet qui grandit)
 * vs incrémentaux (morceaux successifs), doublons consécutifs identiques, et propose une fusion.
 *
 * Usage:
 *   php stream_probe.php [--verbose] [--compare] [--short-prompt]
 *   php stream_probe.php --synthetic    # tests sans serveur
 */

use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @param list<string> $deltas
 *
 * @return list<string>
 */
function stream_probe_collapse_consecutive_duplicates(array $deltas): array
{
    $out = [];
    $last = null;
    foreach ($deltas as $d) {
        if (!is_string($d) || $d === '') {
            continue;
        }
        if ($d === $last) {
            continue;
        }
        $out[] = $d;
        $last = $d;
    }

    return $out;
}

/**
 * @param list<string> $deltas
 *
 * @return array{
 *     delta_count: int,
 *     total_delta_bytes: int,
 *     consecutive_duplicate_count: int,
 *     prefix_extension_pairs: int,
 *     prefix_extension_ratio: float,
 *     suggested_mode: 'cumulative'|'incremental'|'ambiguous',
 * }
 */
function stream_probe_analyze(array $deltas): array
{
    $nonEmpty = [];
    foreach ($deltas as $d) {
        if (is_string($d) && $d !== '') {
            $nonEmpty[] = $d;
        }
    }

    $n = count($nonEmpty);
    $totalBytes = 0;
    foreach ($nonEmpty as $d) {
        $totalBytes += strlen($d);
    }

    $dupRuns = 0;
    for ($i = 1; $i < $n; $i++) {
        if ($nonEmpty[$i] === $nonEmpty[$i - 1]) {
            ++$dupRuns;
        }
    }

    $collapsed = stream_probe_collapse_consecutive_duplicates($nonEmpty);
    $cn = count($collapsed);

    $prefixPairs = 0;
    $pairs = max(0, $cn - 1);
    for ($i = 0; $i + 1 < $cn; $i++) {
        $a = $collapsed[$i];
        $b = $collapsed[$i + 1];
        if ($b !== $a && str_starts_with($b, $a) && strlen($b) > strlen($a)) {
            ++$prefixPairs;
        }
    }

    $ratio = $pairs > 0 ? $prefixPairs / $pairs : 0.0;
    $suggestedMode = 'ambiguous';
    if ($pairs === 0) {
        $suggestedMode = 'incremental';
    } elseif ($ratio >= 0.55) {
        $suggestedMode = 'cumulative';
    } elseif ($ratio <= 0.15) {
        $suggestedMode = 'incremental';
    }

    return [
        'delta_count' => $n,
        'total_delta_bytes' => $totalBytes,
        'consecutive_duplicate_count' => $dupRuns,
        'prefix_extension_pairs' => $prefixPairs,
        'prefix_extension_ratio' => $ratio,
        'suggested_mode' => $suggestedMode,
    ];
}

/**
 * Fusion simple « toujours concaténer », en sautant les doublons consécutifs exacts.
 *
 * @param list<string> $deltas
 */
function merge_stream_deltas_incremental(array $deltas): string
{
    $out = '';
    $last = '';
    foreach ($deltas as $d) {
        if (!is_string($d) || $d === '') {
            continue;
        }
        if ($d === $last) {
            continue;
        }
        $out .= $d;
        $last = $d;
    }

    return $out;
}

/**
 * Hypothèse « chaque fragment est un snapshot du texte complet jusqu’ici » : le résultat
 * est le dernier fragment non vide (après suppression des répétitions consécutives identiques).
 *
 * @param list<string> $deltas
 */
function merge_stream_deltas_cumulative(array $deltas): string
{
    $last = '';
    foreach ($deltas as $d) {
        if (!is_string($d) || $d === '') {
            continue;
        }
        if ($d === $last) {
            continue;
        }
        $last = $d;
    }

    return $last;
}

/**
 * Choisit la fusion selon l’heuristique d’analyse (préfixes stricts qui s’allongent).
 *
 * @param list<string> $deltas
 */
function merge_stream_deltas_smart(array $deltas): string
{
    $info = stream_probe_analyze($deltas);
    if ($info['suggested_mode'] === 'cumulative') {
        return merge_stream_deltas_cumulative($deltas);
    }

    return merge_stream_deltas_incremental($deltas);
}

/**
 * @return list<string>
 *
 * @throws JsonException|RuntimeException
 */
function stream_probe_collect_deltas(Lama $lama, Conversation $conversation): array
{
    $url = $lama->url . '/v1/chat/completions';
    $payload = [
        'model' => $lama->model,
        'messages' => $conversation->toChatCompletionMessages(),
        'stream' => true,
    ];

    $collected = [];
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
            &$collected
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
                    $collected[] = $delta;
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

    if ($response === false && $errno !== 0) {
        throw new RuntimeException("cURL error ($errno): $curlError");
    }

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
                        $collected[] = $delta;
                    }
                }
            }
        }
    }

    return $collected;
}

function stream_preview(string $s, int $max = 72): string
{
    $one = preg_replace('/\s+/', ' ', $s) ?? $s;

    return strlen($one) <= $max ? $one : substr($one, 0, $max) . '…';
}

/** @throws Exception */
function stream_probe_main(bool $verbose, bool $compare, bool $shortPrompt): void
{
    $lama = Lama::fromServerUrl('http://127.0.0.1:8080');
    if ($lama->getHealth() !== 'ok') {
        throw new Exception('System is down (health !== ok)');
    }

    $userText = $shortPrompt
        ? 'Say hello in one short sentence.'
        : 'List and briefly explain five practical habits that improve learning retention, with one short paragraph per habit (about 3–5 sentences each).';

    $streamConv = new Conversation();
    $streamConv->addMessage(new Message(Role::System, 'You are a helpful assistant. Answer in clear prose.'));
    $streamConv->addMessage(new Message(Role::User, $userText));

    echo "Model: {$lama->model}\n";
    echo "Prompt: " . stream_preview($userText, 100) . "\n\n";

    $deltas = stream_probe_collect_deltas($lama, $streamConv);
    $analysis = stream_probe_analyze($deltas);

    echo "--- Analysis ---\n";
    foreach ($analysis as $k => $v) {
        if (is_float($v)) {
            echo sprintf("%s: %.3f\n", $k, $v);
        } else {
            echo "{$k}: {$v}\n";
        }
    }

    $naive = merge_stream_deltas_incremental($deltas);
    $smart = merge_stream_deltas_smart($deltas);
    $cumulativeOnly = merge_stream_deltas_cumulative($deltas);

    echo "\n--- Merged lengths (bytes) ---\n";
    echo 'incremental (naive): ' . strlen($naive) . "\n";
    echo 'cumulative (last delta): ' . strlen($cumulativeOnly) . "\n";
    echo 'smart (heuristic): ' . strlen($smart) . "\n";

    if ($verbose && $deltas !== []) {
        echo "\n--- Deltas (index, bytes, preview) ---\n";
        foreach ($deltas as $i => $d) {
            $len = strlen($d);
            echo sprintf("#%d len=%d %s\n", $i, $len, stream_preview($d, 64));
        }
    }

    if ($compare) {
        $refConv = new Conversation();
        $refConv->addMessage(new Message(Role::System, 'You are a helpful assistant. Answer in clear prose.'));
        $refConv->addMessage(new Message(Role::User, $userText));
        $reference = trim($lama->chat($refConv));
        echo "\n--- Compare with non-stream chat() ---\n";
        echo 'reference length: ' . strlen($reference) . "\n";
        echo 'smart === reference: ' . ($smart === $reference ? 'yes' : 'no') . "\n";
        echo 'incremental === reference: ' . ($naive === $reference ? 'yes' : 'no') . "\n";
        if ($smart !== $reference && $naive !== $reference) {
            echo "(Neither merged stream equals blocking response; sampler variance or truncation is normal.)\n";
        }
        echo "\n--- Reference preview ---\n" . stream_preview($reference, 400) . "\n";
        echo "\n--- Smart-merge preview ---\n" . stream_preview($smart, 400) . "\n";
    }

    echo "\n--- Smart-merge full output ---\n" . $smart . "\n";
}

function stream_probe_run_synthetic_tests(): void
{
    $cases = [
        'incremental_tokens' => [
            'deltas' => ['Hel', 'lo', ', ', 'world', '!'],
            'expect_incremental' => 'Hello, world!',
            'expect_cumulative' => '!',
            'expect_smart_mode' => 'incremental',
        ],
        'cumulative_snapshots' => [
            'deltas' => ['H', 'He', 'Hel', 'Hell', 'Hello'],
            'expect_incremental' => 'HHeHelHellHello',
            'expect_cumulative' => 'Hello',
            'expect_smart_mode' => 'cumulative',
        ],
        'consecutive_duplicates' => [
            'deltas' => ['a', 'a', 'b', 'b', 'b', 'c'],
            'expect_incremental' => 'abc',
            'expect_cumulative' => 'c',
            'expect_smart_mode' => 'incremental',
        ],
        'cumulative_with_dup_runs' => [
            'deltas' => ['Hello', 'Hello', 'Hello world', 'Hello world'],
            'expect_incremental' => 'HelloHello world',
            'expect_cumulative' => 'Hello world',
            'expect_smart_mode' => 'cumulative',
        ],
    ];

    $failed = 0;
    foreach ($cases as $name => $case) {
        /** @var list<string> $deltas */
        $deltas = $case['deltas'];
        $analysis = stream_probe_analyze($deltas);
        $inc = merge_stream_deltas_incremental($deltas);
        $cum = merge_stream_deltas_cumulative($deltas);
        $smart = merge_stream_deltas_smart($deltas);

        $ok = ($inc === $case['expect_incremental'])
            && ($cum === $case['expect_cumulative'])
            && ($analysis['suggested_mode'] === $case['expect_smart_mode'])
            && (($case['expect_smart_mode'] === 'cumulative' && $smart === $cum)
                || ($case['expect_smart_mode'] !== 'cumulative' && $smart === $inc));

        if (!$ok) {
            ++$failed;
            fwrite(STDERR, "FAIL synthetic: {$name}\n");
            fwrite(STDERR, '  suggested_mode=' . $analysis['suggested_mode'] . "\n");
            fwrite(STDERR, '  incremental got=' . json_encode($inc) . "\n");
            fwrite(STDERR, '  cumulative got=' . json_encode($cum) . "\n");
            fwrite(STDERR, '  smart got=' . json_encode($smart) . "\n");
        } else {
            echo "OK synthetic: {$name}\n";
        }
    }

    if ($failed > 0) {
        exit(1);
    }
}

// --- CLI ---

if (PHP_SAPI !== 'cli') {
    exit('Run from CLI: php stream_probe.php' . PHP_EOL);
}

$argv = array_slice($argv, 1);
if (in_array('--synthetic', $argv, true)) {
    stream_probe_run_synthetic_tests();
    exit(0);
}

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$compare = in_array('--compare', $argv, true);
$shortPrompt = in_array('--short-prompt', $argv, true);

try {
    stream_probe_main($verbose, $compare, $shortPrompt);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
