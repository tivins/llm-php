<?php

declare(strict_types=1);

/**
 * Démo streaming : même boucle que {@see examples/web_lookup_chain.php} (`web_search` puis `fetch_web_page`),
 * mais via {@see StreamingToolCallingLoop} et {@see Lama::chatStream()} — le backend envoie du SSE (`stream: true`)
 * et les jetons texte sont affichés au fil de l’eau pendant que les appels d’outils s’accumulent.
 *
 * Prérequis : serveur compatible chat completions sur {@see Lama::fromServerUrl()},
 * réseau sortant pour DuckDuckGo et pour les URLs récupérées.
 *
 * HTTPS / Windows : erreurs SSL (`http_status: 0`, chaîne « certificate », erreur 19 OpenSSL)
 * surviennent souvent sans bundle CA ou avec une **inspection TLS** (proxy/antivirus d’entreprise) :
 * le fichier mozilla `cacert.pem` seul ne contient pas la racine interne.
 * — Sans variable : sous Windows et PHP ≥ 8.2, la bibliothèque utilise le **magasin de certificats**
 *   système lorsque `TIVINS_LLAMA_CURL_CAINFO` n’est pas défini.
 * — Si vous forcez `putenv('TIVINS_LLAMA_CURL_CAINFO=.../cacert.pem')` et que ça échoue encore :
 *   `putenv('TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1');` pour préférer le magasin Windows (ignore ce PEM).
 * — Sinon : concaténez la CA interne dans le PEM, ou `PredefinedTools::setHttpSslVerifyPeer(false)` hors prod.
 *
 * Journal JSONL (optionnel) : {@code TIVINS_LLAMA_CONVERSATION_LOG} — une ligne par tour assistant stream (voir {@see example_turn_jsonl_logger_from_env()} dans {@code examples/_helpers.php}).
 *
 * Usage: php examples/stream_web_lookup_chain.php
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Dto\RawStreamTrace;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;
use Tivins\Llama\StreamResult;
use Tivins\Llama\StreamingToolCallingLoop;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

// Décommentez si nécessaire (voir docblock ci‑dessus) :
// putenv('TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1'); // inspection TLS entreprise + cacert.pem inadapté
// putenv('TIVINS_LLAMA_CURL_CAINFO=' . str_replace('\\', '/', __DIR__) . '/cacert.pem');
// PredefinedTools::setHttpSslVerifyPeer(false);

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$toolSchemas = ChatFunctionTool::toToolArrays([
    PredefinedTools::getWebSearchTool(),
    PredefinedTools::getFetchWebPageTool(),
]);

$systemPrompt = BehaviorPrompts::HELPFUL . <<<'TEXT'


When the user asks for a precise fact from the web:
1. Call `web_search` with a focused query to locate authoritative URLs (official docs, standards bodies, primary sources).
2. Pick one or two relevant URLs from the abstract URL or related topics when useful.
3. Call `fetch_web_page` on the chosen URL(s) to read the actual page body (GET only); responses may be truncated—prefer primary sources that answer directly.
4. Answer using only what you verified from fetched content and cite the URL(s). If fetching fails, say so and rely on clearly labelled web_search summaries only.

Respond in the same language as the user's message unless they ask otherwise.
TEXT;

$conversation = new Conversation();
$conversation->addMessage(new Message(Role::System, $systemPrompt));
$conversation->addMessage(new Message(
    Role::User,
    <<<'USER'
Selon les sources en ligne les plus récentes, quel est le cours actuel du pétrole Brent par baril ? Veuillez indiquer la source financière qui fournit cette donnée.
USER,
));

//Quelle est la date de publication officielle annoncée pour PHP 8.3.0 (première version stable) ?
//Je veux la date exacte telle qu’elle figure sur php.net (pas une approximation), avec l’URL de la page où tu l’as lue et une citation courte du passage pertinent.

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');

$logger = example_turn_jsonl_logger_from_env();

try {
    $lastDeltaSource = '';
    (new StreamingToolCallingLoop($lama))->runUntilIdle(
        conversation: $conversation,
        options: $options,
        onDelta: static function (string $delta) use (&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'delta') {
                echo "\n";
            }
            $lastDeltaSource = 'delta';
            echo $delta;
            flush();
        },
        executeTool: PredefinedTools::runTool(...),
        maxRounds: 16,
        onToolCall: static function (string $name, array $args) use (&$lastDeltaSource): void {
            fwrite(STDERR, "\n[tool] " . $name . ' ' . json_encode($args) . "\n");
            $lastDeltaSource = 'tool';
        },
        onToolCallChunk: static function (int $index, string $fragment) use (&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'tool') {
                echo "\n";
            }
            fwrite(STDERR, "\e[32m$fragment\e[0m");
            $lastDeltaSource = 'tool';
        },
        onReasoningDelta: static function (string $s) use (&$lastDeltaSource): void {
            if ($lastDeltaSource !== 'reasoning') {
                echo "\n";
            }
            $lastDeltaSource = 'reasoning';
            fwrite(STDERR, "\e[33m$s\e[0m");
        },
        onAssistantStreamRound: static function (StreamResult $result, RawStreamTrace $trace, int $roundIdx) use ($logger, $options): void {
            if ($logger === null) {
                return;
            }
            $turnId = $result->id ?? uniqid('stream_round_', true);
            $logger->logTurn(TurnRecord::forStream(
                id: $turnId . '-round-' . $roundIdx,
                trace: $trace,
                result: $result,
                requestOptions: $options,
            ));
        },
    );
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
