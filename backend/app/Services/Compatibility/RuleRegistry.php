<?php

namespace App\Services\Compatibility;

use InvalidArgumentException;

/** Every rule class listed in config/compatibility.php, looked up by key. */
final class RuleRegistry
{
    /** @var array<string, Rule> */
    private array $rules = [];

    /** @param  iterable<int, Rule>  $rules */
    public function __construct(iterable $rules)
    {
        foreach ($rules as $rule) {
            if (isset($this->rules[$rule->key()])) {
                throw new InvalidArgumentException("Two compatibility rules use the key \"{$rule->key()}\".");
            }

            $this->rules[$rule->key()] = $rule;
        }
    }

    /** @return array<string, Rule> */
    public function all(): array
    {
        return $this->rules;
    }

    public function get(string $key): ?Rule
    {
        return $this->rules[$key] ?? null;
    }
}
