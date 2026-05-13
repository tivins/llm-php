<?php

declare(strict_types=1);

/**
 * Demo: boucle d’outils pour trouver une **information précise** sur le web.
 *
 * Flux attendu : `web_search` (résumés DuckDuckGo) pour repérer une source pertinente,
 * puis `fetch_web_page` (GET HTTP/S) pour lire la page et extraire la donnée exacte.
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
 */

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\ChatCompletionOptions;
use Tivins\Llama\ChatFunctionTool;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\PredefinedTools;
use Tivins\Llama\Role;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

// Décommentez si nécessaire (voir docblock ci‑dessus) :
// putenv('TIVINS_LLAMA_CURL_WINDOWS_NATIVE_CA=1'); // inspection TLS entreprise + cacert.pem inadapté
// putenv('TIVINS_LLAMA_CURL_CAINFO=' . str_replace('\\', '/', __DIR__) . '/cacert.pem');
// PredefinedTools::setHttpSslVerifyPeer(false);

$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

$toolSchemas = array_map(
    static fn (ChatFunctionTool $tool): array => $tool->toToolArray(),
    [
        PredefinedTools::getWebSearchTool(),
        PredefinedTools::getFetchWebPageTool(),
    ],
);

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
Quelle est la date de publication officielle annoncée pour PHP 8.3.0 (première version stable) ?
Je veux la date exacte telle qu’elle figure sur php.net (pas une approximation), avec l’URL de la page où tu l’as lue et une citation courte du passage pertinent.
USER,
));

$options = new ChatCompletionOptions(tools: $toolSchemas, tool_choice: 'auto');
$output = $lama->chatCompletions($conversation, $options);
if ($output === null || !isset($output['choices'][0])) {
    fwrite(STDERR, "No completion response (null or missing choices).\n");
    exit(1);
}
print_output($output);

$maxToolRounds = 16;
for ($round = 0; $round < $maxToolRounds; $round++) {
    $assistantPayload = $output['choices'][0]['message'];
    $toolCalls = $assistantPayload['tool_calls'] ?? [];
    if ($toolCalls === []) {
        break;
    }

    $conversation->addMessage(new Message(
        Role::Assistant,
        (string) ($assistantPayload['content'] ?? ''),
        toolCalls: $toolCalls,
    ));

    foreach ($toolCalls as $toolCall) {
        $toolCallId = $toolCall['id'] ?? '';
        $toolName = $toolCall['function']['name'] ?? 'unknown';
        $argumentsJson = $toolCall['function']['arguments'] ?? '{}';

        try {
            $toolArgs = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($toolArgs)) {
                throw new \JsonException('tool arguments must be a JSON object');
            }
        } catch (\JsonException) {
            $conversation->addMessage(new Message(
                Role::Tool,
                json_encode(['error' => 'invalid tool arguments JSON'], JSON_THROW_ON_ERROR),
                toolCallId: $toolCallId,
                name: $toolName,
            ));
            continue;
        }

        echo 'Tool call: ' . $toolName . ' with args: ' . json_encode($toolArgs) . "\n";

        $toolContent = PredefinedTools::runTool($toolName, $toolArgs);
        
        $conversation->addMessage(new Message(
            Role::Tool,
            $toolContent,
            toolCallId: $toolCallId,
            name: $toolName,
        ));
    }

    $output = $lama->chatCompletions($conversation, $options);
    if ($output === null || !isset($output['choices'][0])) {
        fwrite(STDERR, "No completion response after tool round (null or missing choices).\n");
        exit(1);
    }
    print_output($output);
}
