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
            'is_enabled' => $settings->enabled,
            'severity' => $settings->severity,
            'default_severity' => $rule->defaultSeverity(),
            'params' => (object) $settings->params,
            'default_params' => (object) $rule->defaultParams(),
            'slots' => array_keys($rule->specs()),
        ];
    }
}
