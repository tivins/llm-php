<?php

declare(strict_types=1);

namespace Tivins\Llama\Dto;

/**
 * Classification of one logical streaming chunk after SSE parsing (for traces / audit logs).
 */
enum StreamEventKind: string
{
    case ContentDelta = 'content_delta';
    case ReasoningDelta = 'reasoning_delta';
    case ToolArgumentsFragment = 'tool_arguments_fragment';
    case Finish = 'finish';
    case Usage = 'usage';
    case RawChunk = 'raw_chunk';
}
