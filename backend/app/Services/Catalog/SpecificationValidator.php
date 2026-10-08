<?php

namespace App\Services\Catalog;

use App\Enums\SpecType;
use App\Models\Category;
use App\Models\SpecDefinition;
use Illuminate\Validation\ValidationException;

/**
 * Checks a product's `specs` payload against the spec definitions stored in the database for its
 * category, and converts it into rows ready for product_specifications.
 *
 * Because the rules come from the database, adding a new category or spec needs no code change.
 */
class SpecificationValidator
{
    /**
     * @param  array<string, mixed>  $input  e.g. ['socket' => 'AM5', 'cores' => 6]
     * @return array<string, array{value_string: ?string, value_number: ?float, value_json: ?array}>
     *
     * @throws ValidationException
     */
    public function normalize(Category $category, array $input): array
    {
        $definitions = $category->specDefinitions->keyBy('key');
        $errors = [];
        $rows = [];

        foreach (array_diff_key($input, $definitions->all()) as $key => $_) {
            $errors["specs.{$key}"][] = "\"{$key}\" is not a specification of the {$category->name} category.";
        }

        foreach ($definitions as $key => $definition) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                if ($definition->is_required) {
                    $errors["specs.{$key}"][] = "{$definition->label} is required.";
                }

                continue;
            }

            $problem = $this->problem($definition, $value);

            if ($problem !== null) {
                $errors["specs.{$key}"][] = $problem;

                continue;
            }

            $rows[$key] = $this->row($definition, $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    private function problem(SpecDefinition $definition, mixed $value): ?string
    {
        $label = $definition->label;

        return match ($definition->type) {
            SpecType::String => is_string($value) && mb_strlen($value) <= 255
                ? null : "{$label} must be text up to 255 characters.",
            SpecType::Enum => is_string($value) && in_array($value, $definition->options ?? [], true)
                ? null : "{$label} must be one of: ".implode(', ', $definition->options ?? []).'.',
            SpecType::Number => $this->numberProblem($label, $value),
            SpecType::List => $this->listProblem($definition, $value),
        };
    }

    private function numberProblem(string $label, mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return "{$label} must be a number.";
        }

        return (float) $value < 0 ? "{$label} cannot be negative." : null;
    }

    private function listProblem(SpecDefinition $definition, mixed $value): ?string
    {
        $allowed = $definition->options ?? [];

        if (! is_array($value) || ! array_is_list($value) || array_diff($value, $allowed) !== [] || array_filter($value, fn ($v) => ! is_string($v)) !== []) {
            return "{$definition->label} must be a list containing only: ".implode(', ', $allowed).'.';
        }

        return null;
    }

    /** @return array{value_string: ?string, value_number: ?float, value_json: ?array} */
    private function row(SpecDefinition $definition, mixed $value): array
    {
        return [
            'value_string' => in_array($definition->type, [SpecType::String, SpecType::Enum], true) ? (string) $value : null,
            'value_number' => $definition->type === SpecType::Number ? (float) $value : null,
            'value_json' => $definition->type === SpecType::List ? array_values(array_unique($value)) : null,
        ];
    }
}
