<?php

namespace App\Http\Requests\Compatibility;

use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\RuleRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompatibilityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced by the route's can:compatibility.manage middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rule = app(RuleRegistry::class)->get((string) $this->route('key'));

        // Each tunable number brings its own validation rules from the rule class.
        $params = collect($rule?->paramRules() ?? [])->mapWithKeys(fn (array $rules, string $name) => ["params.{$name}" => ['sometimes', 'required', ...$rules]]);

        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'severity' => ['sometimes', Rule::in([CompatibilityIssue::ERROR, CompatibilityIssue::WARNING])],
            'params' => ['sometimes', 'array'],
            ...$params,
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $rule = app(RuleRegistry::class)->get((string) $this->route('key'));
            $allowed = array_keys($rule?->paramRules() ?? []);

            foreach (array_diff(array_keys((array) $this->input('params', [])), $allowed) as $unknown) {
                $validator->errors()->add("params.{$unknown}", "\"{$unknown}\" is not a setting of this rule.");
            }
        }];
    }
}
