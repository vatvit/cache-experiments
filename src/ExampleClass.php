<?php

declare(strict_types=1);

namespace Cache;

class ExampleClass
{
    public function __construct(private string $message = 'Hello World!')
    {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }
}
