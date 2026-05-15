<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Stateful console helper for streamed chat: keeps assistant text ({@see onDelta}), reasoning deltas,
 * and tool-argument fragments visually separated ({@see RenderOptions::$blankLineBetweenStreamLanesOnStdout})
 * consistent with migrated {@code examples/stream_*.php} scripts before {@see HumanTurnRenderer}
 * summarizes completed rounds offline.
 *
 * Typical wiring with {@see StreamingToolCallingLoop}:
 *
 * {@code $display = new HumanTurnStreamDisplay(example_render_options_from_env());}
 * {@code onDelta: $display->onDelta(...), onReasoningDelta: $display->onReasoningDelta(...), ...}
 *
 * **Channels:** streamed assistant **content** prints to {@see RenderOptions::stdout()};
 * streamed **reasoning** defaults to {@see RenderOptions::reasoningDestination()} (stderr unless
 * {@see RenderOptions::$reasoningOnStderr} is false); **tool summaries** ({@see onToolCall}) and streamed
 * **tool JSON fragments** ({@see onToolArgumentChunk}) mirror prior stream examples---printed on {@see RenderOptions::stderr()}.
 *
 * ANSI: green fragments for accumulating tool JSON, yellow accents for reasoning (when colors enabled).
 */
final class HumanTurnStreamDisplay
{
    private string $lastLane = '';

    public function __construct(
        private readonly RenderOptions $opts,
    ) {
    }

    public function resetLaneState(): void
    {
        $this->lastLane = '';
    }

    public function onDelta(string $delta): void
    {
        $this->enterLane('content');
        fwrite($this->opts->stdout(), $delta);
        fflush($this->opts->stdout());
    }

    public function onReasoningDelta(string $fragment): void
    {
        $this->enterLane('reasoning');

        $out = HumanTurnRenderer::stylize($this->opts, $fragment, '33');
        fwrite($this->opts->reasoningDestination(), $out);
        fflush($this->opts->reasoningDestination());
    }

    /**
     * Streamed incremental JSON/text arguments for pending tool_calls (SSE {@code delta.tool_calls}).
     */
    public function onToolArgumentChunk(int $index, string $fragment): void
    {
        $this->enterLane('tool_chunk');
        $coloured = HumanTurnRenderer::stylize($this->opts, $fragment, '32');
        fwrite($this->opts->stderr(), $coloured);
        fflush($this->opts->stderr());
    }

    /**
     * One line per finalized tool invocation (after arguments have been reconstructed).
     */
    public function onToolCall(string $name, array $args): void
    {
        $line = '[tool] ' . $name . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        HumanTurnRenderer::fwriteNl($this->opts->stderr(), "\n" . $line);
        $this->lastLane = 'tool_call';
        fflush($this->opts->stderr());
    }

    /** @internal */
    private function enterLane(string $lane): void
    {
        if ($this->lastLane === $lane) {
            return;
        }
        if ($this->opts->blankLineBetweenStreamLanesOnStdout) {
            fwrite($this->opts->stdout(), "\n");
            fflush($this->opts->stdout());
        }
        $this->lastLane = $lane;
    }
}
