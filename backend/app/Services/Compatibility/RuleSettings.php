<?php

namespace App\Services\Compatibility;

/** How one rule is configured right now: switched on or off, how serious its findings are, and its numbers. */
final readonly class RuleSettings
{
    /** @param  array<string, int|float>  $params */
    public function __construct(
        public bool $enabled,
        public string $severity,
        public array $params = [],
    ) {}

    public function param(string $key, int|float $default = 0): int|float
    {
        return $this->params[$key] ?? $default;
    }
}
