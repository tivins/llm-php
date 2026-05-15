<?php

declare(strict_types=1);

namespace Tivins\Llama;

use Tivins\Llama\Dto\NormalizedTurnOutcome;
use Tivins\Llama\Dto\TurnRecord;

/**
 * Writes a unified human-readable view of {@see NormalizedTurnOutcome} or archived {@see TurnRecord} lines.
 */
final class HumanTurnRenderer
{
    /** @internal */
    public static function stylize(RenderOptions $opts, string $text, ?string $ansiCodes): string
    {
        if ($ansiCodes === null || !$opts->ansiColors) {
            return $text;
        }

        return "\e[" . $ansiCodes . 'm' . $text . "\e[0m";
    }

    /** @internal */
    public static function fwriteNl(mixed $stream, string $text = ''): void
    {
        fwrite($stream, $text . "\n");
    }

    public static function renderNormalized(
        NormalizedTurnOutcome $out,
        RenderOptions $opts,
        ?string $heading = null,
    ): void {
        $stdout = $opts->stdout();

        $dim = fn (string $s): string => self::stylize($opts, $s, '2');

        if ($opts->showSectionDividers) {
            self::fwriteNl($stdout, $dim(str_repeat('-', 20) . ' Assistant turn ' . str_repeat('-', 36)));
        }
        if ($heading !== null && $heading !== '') {
            self::fwriteNl($stdout, self::stylize($opts, $heading, '36;1'));
        }

        if ($out->usage !== null && $out->usage !== []) {
            self::fwriteNl($stdout, self::stylize($opts, 'Usage', '1'));
            foreach ($out->usage as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                $label = ucfirst(str_replace('_', ' ', $k));
                self::fwriteNl($stdout, '  - ' . $label . ': ' . self::scalarToLine($v));
            }
            self::fwriteNl($stdout, '');
        }

        self::renderMetaLine($stdout, $opts, 'Model', $out->model ?? '');
        self::renderMetaLine($stdout, $opts, 'Completion id', $out->id ?? '');
        self::renderMetaLine($stdout, $opts, 'Finish reason', $out->finishReason);

        if ($out->reasoningContent !== '') {
            self::renderReasoningBlock($out->reasoningContent, $opts);
        }

        if ($out->content !== '') {
            self::fwriteNl($stdout, '');
            self::fwriteNl($stdout, self::stylize($opts, 'Content', '1'));
            self::fwriteNl($stdout, $out->content);
        }

        if ($out->toolCalls !== []) {
            self::fwriteNl($stdout, '');
            self::fwriteNl($stdout, self::stylize($opts, 'Tool calls', '1'));
            $json = json_encode($out->toolCalls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            self::fwriteNl($stdout, $json);
        }

        if ($opts->showSectionDividers) {
            self::fwriteNl($stdout, '');
            self::fwriteNl($stdout, $dim(str_repeat('-', 20) . ' End of turn ' . str_repeat('-', 39)));
        }
        self::fwriteNl($stdout, '');
    }

    /**
     * Replay the first choice repeatedly when {@code choices} has multiple entries (demo / {@code n > 1}).
     *
     * @param array<string, mixed> $response Decoded chat completions payload
     */
    public static function renderCompletionPayload(array $response, RenderOptions $opts): void
    {
        $choices = $response['choices'] ?? [];
        if (!is_array($choices)) {
            $choices = [];
        }

        $n = count($choices);
        if ($n <= 1) {
            self::renderNormalized(NormalizedTurnOutcome::fromChatCompletionArray($response), $opts);

            return;
        }

        foreach ($choices as $i => $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $synthetic = $response;
            $synthetic['choices'] = [$choice];
            self::renderNormalized(
                NormalizedTurnOutcome::fromChatCompletionArray($synthetic),
                $opts,
                sprintf('Choice %d of %d', $i + 1, $n),
            );
        }
    }

    public static function renderTurnRecord(TurnRecord $record, RenderOptions $opts): void
    {
        $stdout = $opts->stdout();
        $dim = fn (string $s): string => self::stylize($opts, $s, '2');

        if ($opts->showTurnMetadata) {
            self::fwriteNl($stdout, '');
            self::fwriteNl(
                $stdout,
                self::stylize($opts, '[Turn log]', '35;1') . ' '
                . $dim('id=' . $record->id . ' mode=' . $record->mode . ' at=' . $record->createdAt),
            );
        }

        if ($record->mode === 'completion') {
            $data = $record->completionResponse !== null ? $record->completionResponse->data : [];
            $outcome = NormalizedTurnOutcome::fromChatCompletionArray($data);
        } elseif ($record->mode === 'stream') {
            if ($record->streamResult === null) {
                throw new \InvalidArgumentException('stream TurnRecord expects streamResult');
            }
            $outcome = NormalizedTurnOutcome::fromStreamResult($record->streamResult);
        } else {
            throw new \InvalidArgumentException('Unknown TurnRecord mode: ' . $record->mode);
        }

        self::renderNormalized($outcome, $opts);

        fflush($stdout);
    }

    private static function renderMetaLine(mixed $stdout, RenderOptions $opts, string $label, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        self::fwriteNl($stdout, self::stylize($opts, $label . ': ', '1') . $value);
    }

    private static function renderReasoningBlock(string $reasoningContent, RenderOptions $opts): void
    {
        $dest = $opts->reasoningDestination();
        $header = HumanTurnRenderer::stylize($opts, '[reasoning]', '33;1');

        fwrite($dest, "\n");
        self::fwriteNl($dest, $header);

        foreach (preg_split("/\r\n|\n|\r/", $reasoningContent) ?: [] as $line) {
            self::fwriteNl($dest, $opts->ansiColors ? ("\e[33m" . $line . "\e[0m") : $line);
        }
        fwrite($dest, "\n");

        fflush($dest);
    }

    /**
     * @param mixed $v
     */
    private static function scalarToLine(mixed $v): string
    {
        if (is_scalar($v) || $v === null) {
            return (string) json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

}
