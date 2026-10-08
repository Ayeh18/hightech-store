<?php

namespace App\Services\PcBuilder;

use App\Enums\BuilderSlot;

/** One problem with a build, shown to the customer. `error` blocks buying; `warning` does not. */
final readonly class BuildIssue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?BuilderSlot $slot = null,
    ) {}
}
