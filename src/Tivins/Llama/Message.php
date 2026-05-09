<?php

declare(strict_types=1);

namespace Tivins\Llama;

readonly class Message
{
    public function __construct(
        public Role   $role,
        public string $content,
    )
    {
    }
}