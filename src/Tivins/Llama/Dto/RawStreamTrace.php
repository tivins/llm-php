<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

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
}
