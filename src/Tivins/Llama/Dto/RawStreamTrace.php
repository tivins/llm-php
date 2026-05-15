<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

use InvalidArgumentException;

/**
 * Ordered stream replay plus optional verbatim `data:` lines for byte-level debugging.
 */
final readonly class RawStreamTrace
{
    /**
     * @param list<StreamEvent>         $events
     * @param ?list<string>             $rawDataLines Verbatim SSE payload lines (without `data:` prefix), optional.
     */
    public function __construct(
        public array $events,
        public ?array $rawDataLines = null,
    ) {
    }

    /**
     * @return array{
     *     events: list<array{kind: string, payload: mixed}>,
     *     raw_data_lines?: list<string>
     * }
     */
    public function toLogArray(): array
    {
        $events = [];
        foreach ($this->events as $event) {
            $events[] = $event->toLogArray();
        }
        $out = ['events' => $events];
        if ($this->rawDataLines !== null) {
            $out['raw_data_lines'] = array_values($this->rawDataLines);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data Decoded {@see RawStreamTrace::toLogArray()} object.
     */
    public static function fromLogArray(array $data): self
    {
        $eventsIn = $data['events'] ?? [];
        if (!is_array($eventsIn)) {
            throw new InvalidArgumentException('raw_stream.events must be an array when present.');
        }

        $events = [];
        foreach ($eventsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = StreamEvent::fromLogArray($row);
        }

        $rawDataLines = null;
        if (isset($data['raw_data_lines']) && is_array($data['raw_data_lines'])) {
            $lines = [];
            foreach ($data['raw_data_lines'] as $ln) {
                if (is_string($ln)) {
                    $lines[] = $ln;
                }
            }
            if ($lines !== []) {
                $rawDataLines = array_values($lines);
            }
        }

        return new self($events, $rawDataLines);
    }
}
