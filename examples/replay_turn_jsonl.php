<?php

declare(strict_types=1);

/**
 * Affiche un journal JSONL de tours assistant ({@see \Tivins\Llama\TurnJsonlLogger} / {@see \Tivins\Llama\Dto\TurnRecord})
 * dans le terminal avec couleurs (messages de requête, options, réponse, raisonnement, outils).
 *
 * Usage:
 *   php examples/replay_turn_jsonl.php chemin/vers/fichier.jsonl [--sse] [--no-ansi] [--no-dividers]
 *
 * - {@code --sse} : rejoue les fragments bruts SSE ({@code raw_data_lines}) ou les {@code events} structurés quand présents.
 *   Sans ce drapeau, seul le résumé agrégé ({@code stream_result} / {@code raw_completion}) est affiché pour éviter des sorties énormes.
 */

use Tivins\Llama\Dto\StreamEvent;
use Tivins\Llama\Dto\StreamEventKind;
use Tivins\Llama\Dto\TurnRecord;
use Tivins\Llama\HumanTurnRenderer;
use Tivins\Llama\RenderOptions;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_helpers.php';

/**
 * @param list<string> $argv
 */
function replay_parse_cli_args(array $argv): array
{
    $path = '';
    $sse = false;
    $noAnsi = false;
    $noDividers = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--sse') {
            $sse = true;

            continue;
        }
        if ($arg === '--no-ansi') {
            $noAnsi = true;

            continue;
        }
        if ($arg === '--no-dividers') {
            $noDividers = true;

            continue;
        }
        if ($arg === '-h' || $arg === '--help') {
            return ['help' => true, 'path' => '', 'sse' => false, 'noAnsi' => false, 'noDividers' => false];
        }
        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Option inconnue: {$arg}\n");

            return ['help' => true, 'path' => '', 'sse' => false, 'noAnsi' => false, 'noDividers' => false];
        }
        if ($path === '') {
            $path = $arg;
        }
    }

    return ['help' => false, 'path' => $path, 'sse' => $sse, 'noAnsi' => $noAnsi, 'noDividers' => $noDividers];
}

function replay_print_help(): void
{
    $msg = <<<'TXT'
Usage: php examples/replay_turn_jsonl.php <fichier.jsonl> [--sse] [--no-ansi] [--no-dividers]

  --sse           Rejoue les chunks SSE (raw_data_lines) ou la trace structurée (events).
  --no-ansi       Désactive les codes couleur (ou définir TIVINS_LLAMA_NO_ANSI=1).
  --no-dividers   Masque les filets entre tours assistant.

TXT;
    fwrite(STDOUT, $msg);
}

/** @param mixed $payload */
function replay_stream_event_payload_to_string(mixed $payload): string
{
    if (is_string($payload)) {
        return $payload;
    }
    if (is_array($payload)) {
        if (isset($payload['text']) && is_string($payload['text'])) {
            return $payload['text'];
        }
        if (isset($payload['content']) && is_string($payload['content'])) {
            return $payload['content'];
        }
        if (isset($payload['fragment']) && is_string($payload['fragment'])) {
            return $payload['fragment'];
        }

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($payload === null) {
        return '';
    }

    return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function replay_stream_events(RenderOptions $opts, array $events): void
{
    if ($events === []) {
        return;
    }

    $stdout = $opts->stdout();
    $stderr = $opts->stderr();

    $banner = HumanTurnRenderer::stylize($opts, '── Trace stream (events) ──', '36;1');
    HumanTurnRenderer::fwriteNl($stdout, '');
    HumanTurnRenderer::fwriteNl($stdout, $banner);

    foreach ($events as $event) {
        if (!$event instanceof StreamEvent) {
            continue;
        }
        $text = replay_stream_event_payload_to_string($event->payload);

        match ($event->kind) {
            StreamEventKind::ContentDelta => fwrite($stdout, HumanTurnRenderer::stylize($opts, $text, '37')),
            StreamEventKind::ReasoningDelta => fwrite($opts->reasoningDestination(), HumanTurnRenderer::stylize($opts, $text, '33')),
            StreamEventKind::ToolArgumentsFragment => fwrite($stderr, HumanTurnRenderer::stylize($opts, $text, '32')),
            StreamEventKind::Finish => HumanTurnRenderer::fwriteNl($stdout, HumanTurnRenderer::stylize($opts, '[finish] ' . $text, '2')),
            StreamEventKind::Usage => HumanTurnRenderer::fwriteNl($stdout, HumanTurnRenderer::stylize($opts, '[usage] ' . $text, '2')),
            StreamEventKind::RawChunk => HumanTurnRenderer::fwriteNl($stderr, HumanTurnRenderer::stylize($opts, $text, '35')),
        };
    }

    HumanTurnRenderer::fwriteNl($stdout, '');
}

/** @param list<string> $rawDataLines */
function replay_raw_sse_payloads(RenderOptions $opts, array $rawDataLines): void
{
    if ($rawDataLines === []) {
        return;
    }

    $stdout = $opts->stdout();
    $stderr = $opts->stderr();

    $banner = HumanTurnRenderer::stylize($opts, '── Fragments SSE bruts ({raw_data_lines}) ──', '36;1');
    HumanTurnRenderer::fwriteNl($stdout, '');
    HumanTurnRenderer::fwriteNl($stdout, $banner);

    $lastLane = '';

    foreach ($rawDataLines as $i => $line) {
        if (!is_string($line) || $line === '') {
            continue;
        }

        try {
            $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            HumanTurnRenderer::fwriteNl($stderr, HumanTurnRenderer::stylize($opts, "[json invalide #" . ($i + 1) . '] ' . $line, '31'));

            continue;
        }

        if (!is_array($parsed)) {
            continue;
        }

        $choice = $parsed['choices'][0] ?? null;
        if (!is_array($choice)) {
            continue;
        }

        $delta = $choice['delta'] ?? null;
        $delta = is_array($delta) ? $delta : [];

        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            if ($lastLane !== 'content' && $opts->blankLineBetweenStreamLanesOnStdout) {
                fwrite($stdout, "\n");
            }
            $lastLane = 'content';
            fwrite($stdout, HumanTurnRenderer::stylize($opts, $delta['content'], '37'));
        }

        $reasoning = null;
        if (array_key_exists('reasoning_content', $delta)) {
            $r = $delta['reasoning_content'];
            if (is_string($r) && $r !== '') {
                $reasoning = $r;
            }
        }
        if ($reasoning !== null) {
            if ($lastLane !== 'reasoning' && $opts->blankLineBetweenStreamLanesOnStdout) {
                fwrite($stdout, "\n");
            }
            $lastLane = 'reasoning';
            fwrite($opts->reasoningDestination(), HumanTurnRenderer::stylize($opts, $reasoning, '33'));
        }

        $tcDeltas = $delta['tool_calls'] ?? null;
        if (is_array($tcDeltas) && $tcDeltas !== []) {
            if ($lastLane !== 'tool' && $opts->blankLineBetweenStreamLanesOnStdout) {
                fwrite($stdout, "\n");
            }
            $lastLane = 'tool';
            $tcJson = json_encode($tcDeltas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            HumanTurnRenderer::fwriteNl($stderr, HumanTurnRenderer::stylize($opts, '[tool Δ] ' . $tcJson, '36'));
        }
    }

    HumanTurnRenderer::fwriteNl($stdout, '');
}

$parsed = replay_parse_cli_args($argv);

if ($parsed['help'] || $parsed['path'] === '') {
    replay_print_help();
    exit($parsed['help'] ? 0 : 1);
}

$path = $parsed['path'];
if (!is_readable($path)) {
    fwrite(STDERR, "Fichier illisible ou absent: {$path}\n");
    exit(1);
}

$envOpts = example_render_options_from_env();
$baseOpts = new RenderOptions(
    ansiColors: $parsed['noAnsi'] ? false : $envOpts->ansiColors,
    reasoningOnStderr: $envOpts->reasoningOnStderr,
    showSectionDividers: $parsed['noDividers'] ? false : $envOpts->showSectionDividers,
    showTurnMetadata: $envOpts->showTurnMetadata,
    blankLineBetweenStreamLanesOnStdout: $envOpts->blankLineBetweenStreamLanesOnStdout,
);

$handle = fopen($path, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Impossible d'ouvrir: {$path}\n");
    exit(1);
}

$lineNum = 0;
$turnIndex = 0;

try {
    while (($line = fgets($handle)) !== false) {
        ++$lineNum;
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Ligne {$lineNum}: JSON invalide — {$e->getMessage()}\n");
            exit(1);
        }

        if (!is_array($data)) {
            fwrite(STDERR, "Ligne {$lineNum}: attendu un objet JSON.\n");
            exit(1);
        }

        try {
            $record = TurnRecord::fromLogArray($data);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Ligne {$lineNum}: journal mal formé — {$e->getMessage()}\n");
            exit(1);
        }

        ++$turnIndex;

        $stdout = $baseOpts->stdout();
        HumanTurnRenderer::fwriteNl($stdout, '');
        HumanTurnRenderer::fwriteNl(
            $stdout,
            HumanTurnRenderer::stylize(
                $baseOpts,
                '════ Tour #' . $turnIndex . ' (ligne JSONL ' . $lineNum . ') ════',
                '35;1',
            ),
        );

        HumanTurnRenderer::renderTurnRecordRequestMessages($record, $baseOpts);

        if ($record->requestOptions !== null && $record->requestOptions !== []) {
            $ro = json_encode($record->requestOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            HumanTurnRenderer::fwriteNl($stdout, HumanTurnRenderer::stylize($baseOpts, 'Options de requête', '2'));
            HumanTurnRenderer::fwriteNl($stdout, $ro);
            HumanTurnRenderer::fwriteNl($stdout, '');
        }

        if ($parsed['sse'] && $record->mode === 'stream' && $record->streamTrace !== null) {
            $trace = $record->streamTrace;
            if ($trace->events !== []) {
                replay_stream_events($baseOpts, $trace->events);
            }
            if ($trace->rawDataLines !== null && $trace->rawDataLines !== []) {
                replay_raw_sse_payloads($baseOpts, $trace->rawDataLines);
            }
        }

        HumanTurnRenderer::renderTurnRecord($record, $baseOpts, omitRequestMessages: true);
    }
} finally {
    fclose($handle);
}

exit(0);
