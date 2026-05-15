<?php

declare(strict_types=1);

namespace Tivins\Llama;

use JsonException;
use RuntimeException;
use Tivins\Llama\Dto\TurnRecord;

/**
 * Append-only JSONL writer for {@see TurnRecord} audit lines (one JSON object per line, no pretty-print).
 */
final class TurnJsonlLogger
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly string $path,
        private readonly bool $append = true,
    ) {
    }

    public function logTurn(TurnRecord $record): void
    {
        try {
            $line = json_encode($record->toLogArray(), self::JSON_FLAGS) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('TurnRecord JSON encoding failed: ' . $e->getMessage(), 0, $e);
        }

        $dir = dirname($this->path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create log directory: ' . $dir);
            }
        }

        $flags = $this->append ? FILE_APPEND | LOCK_EX : LOCK_EX;
        if (file_put_contents($this->path, $line, $flags) === false) {
            throw new RuntimeException('Cannot write conversation log: ' . $this->path);
        }
    }
}
