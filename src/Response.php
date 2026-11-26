<?php

namespace NodeService\CrmAiPhp;

final class Response
{
    public function __construct(public readonly int $status, public readonly string $body) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        try {
            $value = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('CRM returned invalid JSON.');
        }
        if (! is_array($value)) {
            throw new \RuntimeException('CRM returned an unexpected JSON value.');
        }
        return $value;
    }
}
