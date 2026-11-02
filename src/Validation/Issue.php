<?php

declare(strict_types=1);

namespace Ambit\Validation;

final class Issue
{
    private string $severity;
    private string $type;
    private string $message;
    private ?string $set;
    private ?string $key;
    private ?string $path;

    public function __construct(
        string $severity,
        string $type,
        string $message,
        ?string $set = null,
        ?string $key = null,
        ?string $path = null
    ) {
        $this->severity = $severity;
        $this->type = $type;
        $this->message = $message;
        $this->set = $set;
        $this->key = $key;
        $this->path = $path;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function set(): ?string
    {
        return $this->set;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
