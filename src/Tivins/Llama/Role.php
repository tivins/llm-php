<?php

declare(strict_types=1);

namespace Tivins\Llama;

enum Role: string
{
    case System = "system";
    case User = "user";
    case Assistant = "assistant";
    case Tool = "tool";
}