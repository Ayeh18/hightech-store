<?php

namespace App\Http\Controllers\Api\Compatibility;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compatibility\UpdateCompatibilityRuleRequest;
use App\Http\Resources\Compatibility\CompatibilityRuleResource;
use App\Models\CompatibilityRule;
use App\Services\AuditLogger;
use App\Services\Compatibility\CompatibilityService;
use App\Services\Compatibility\RuleRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Admin screen data for the compatibility rules: list them and tune them without a deployment. */
class CompatibilityRuleController extends Controller
{
    public function __construct(private readonly CompatibilityService $compatibility, private readonly RuleRegistry $registry) {}

    /**
     * List the compatibility rules.
     *
     * List the compatibility rules with their current settings.
     */
    public function index(): AnonymousResourceCollection
    {
        return CompatibilityRuleResource::collection(array_values($this->compatibility->rules()->all()));
    }

    /**
     * Change a compatibility rule.
     *
     * Switch a compatibility rule on or off, or change its severity and settings.
     */
    public function update(UpdateCompatibilityRuleRequest $request, string $key, AuditLogger $audit): CompatibilityRuleResource
    {
        abort_unless($this->registry->get($key), 404);

        $row = CompatibilityRule::firstOrNew(['key' => $key]);
        $current = $this->compatibility->rules()->settings($key);
        $old = ['is_enabled' => $current->enabled, 'severity' => $current->severity, 'params' => $current->params];

        $row->fill([
            'is_enabled' => $request->input('is_enabled', $current->enabled),
            'severity' => $request->input('severity', $current->severity),
            'params' => [...$current->params, ...(array) $request->input('params', [])],
        ])->save();

        $new = ['is_enabled' => $row->is_enabled, 'severity' => $row->severity, 'params' => $row->params];

        if ($old !== $new) {
            $audit->log('compatibility_rule.updated', $request->user(), $row, [...$old, 'rule' => $key], $new);
        }

        return new CompatibilityRuleResource($this->compatibility->rules()->all()[$key]);
    }
}
