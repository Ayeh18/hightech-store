<?php

namespace App\Http\Resources\Compatibility;

use App\Services\Compatibility\Rule;
use App\Services\Compatibility\RuleSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One rule with its current settings. The wrapped value is ['rule' => Rule, 'settings' => RuleSettings].
 *
 * @property array{rule: Rule, settings: RuleSettings} $resource
 */
class CompatibilityRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        ['rule' => $rule, 'settings' => $settings] = $this->resource;

        return [
            'key' => $rule->key(),
            'name' => $rule->name(),
            'description' => $rule->description(),
            /** @var bool */
            'is_enabled' => $settings->enabled,
            /** @var 'error'|'warning' */
            'severity' => $settings->severity,
            /** @var 'error'|'warning' */
            'default_severity' => $rule->defaultSeverity(),
            /**
             * The rule's own settings, by name (for example the spare power a PSU must have).
             *
             * @var array<string, mixed>
             */
            'params' => (object) $settings->params,
            /** @var array<string, mixed> */
            'default_params' => (object) $rule->defaultParams(),
            /**
             * The builder slots this rule looks at.
             *
             * @var list<string>
             */
            'slots' => array_keys($rule->specs()),
        ];
    }
}
