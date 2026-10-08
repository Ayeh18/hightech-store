<?php

namespace App\Services\Compatibility;

use App\Enums\BuilderSlot;

/** The parts of a build, one per slot. Immutable: `with` and `without` return a changed copy. */
final readonly class BuildContext
{
    /** @param  array<string, Part>  $parts  keyed by slot value */
    private function __construct(private array $parts) {}

    /** @param  iterable<int, Part>  $parts */
    public static function of(iterable $parts): self
    {
        $keyed = [];

        foreach ($parts as $part) {
            $keyed[$part->slot->value] = $part;
        }

        return new self($keyed);
    }

    public function part(BuilderSlot $slot): ?Part
    {
        return $this->parts[$slot->value] ?? null;
    }

    public function with(Part $part): self
    {
        return new self([...$this->parts, $part->slot->value => $part]);
    }

    public function without(BuilderSlot $slot): self
    {
        $parts = $this->parts;
        unset($parts[$slot->value]);

        return new self($parts);
    }

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }
}
