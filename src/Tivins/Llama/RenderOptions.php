<?php

declare(strict_types=1);

namespace Tivins\Llama;

/**
 * Console layout for {@see HumanTurnRenderer} / {@see HumanTurnStreamDisplay}.
 *
 * Defaults match migrated examples under {@code examples/}:
 *
 * - **Assistant text**, usage, identifiers, finish reason → **stdout**.
 * - **Reasoning fragments** ({@code reasoning_content}) → **stderr** when {@see $reasoningOnStderr}
 *   is {@code true} (so streamed thinking does not mix with plain reply text on stdout).
 *
 * ANSI colors: enable {@see $ansiColors}; on older Windows consoles unsupported escape sequences
 * may appear as stray characters---disable via {@see $ansiColors} or set env
 * {@code TIVINS_LLAMA_NO_ANSI} (see {@code examples/_helpers.php}). PowerShell 7 / Windows Terminal
 * generally render ANSI as expected with PHP 8.x.
 *
 * Writable streams {@see $stdoutStream} / {@see $stderrStream} default to PHP's {@see STDOUT} /
 * {@see STDERR} constants when omitted (use {@code fopen('php://memory','r+')} in tests).
 */
final readonly class RenderOptions
{
    /**
     * @param resource|null $stdoutStream Writable handle; {@code null} → {@see STDOUT}
     * @param resource|null $stderrStream Writable handle; {@code null} → {@see STDERR}
     */
    public function __construct(
        public bool $ansiColors = true,
        public bool $reasoningOnStderr = true,
        public bool $showSectionDividers = true,
        public bool $showTurnMetadata = true,
        /** When {@code true}, stream callbacks print a newline to stdout between lane transitions (matches prior examples). */
        public bool $blankLineBetweenStreamLanesOnStdout = true,
        private mixed $stdoutStream = null,
        private mixed $stderrStream = null,
    ) {
    }

    /** @return resource */
    public function stdout(): mixed
    {
        return $this->stdoutStream ?? STDOUT;
    }

    /** @return resource */
    public function stderr(): mixed
    {
        return $this->stderrStream ?? STDERR;
    }

    /** Main narrative channel ({@see $reasoningOnStderr} selects where reasoning goes vs this). */
    public function primaryOut(): mixed
    {
        return $this->stdout();
    }

    /** Stream for reasoning when {@see $reasoningOnStderr} is true; otherwise primary. */
    public function reasoningDestination(): mixed
    {
        return $this->reasoningOnStderr ? $this->stderr() : $this->stdout();
    }
}
