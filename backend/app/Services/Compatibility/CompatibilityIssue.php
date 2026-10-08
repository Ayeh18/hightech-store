<?php

namespace App\Services\Compatibility;

use App\Enums\BuilderSlot;

/** One finding of a rule. `slots` lists the parts involved, so the UI can show it next to the right rows. */
final readonly class CompatibilityIssue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /** @param  array<int, BuilderSlot>  $slots */
    public function __construct(
        public string $rule,
        public string $severity,
        public string $message,
        public array $slots,
    ) {}

    public function involves(BuilderSlot $slot): bool
    {
        return in_array($slot, $this->slots, true);
    }
}
