<?php

namespace Database\Seeders;

use App\Models\CompatibilityRule;
use App\Services\Compatibility\RuleRegistry;
use Illuminate\Database\Seeder;

/**
 * Creates a settings row for every registered rule that does not have one yet.
 * Existing rows (admin changes) are never overwritten, so it is safe to run on every deploy.
 */
class CompatibilityRuleSeeder extends Seeder
{
    public function run(RuleRegistry $registry): void
    {
        foreach ($registry->all() as $key => $rule) {
            CompatibilityRule::firstOrCreate(['key' => $key], [
                'is_enabled' => true,
                'severity' => $rule->defaultSeverity(),
                'params' => $rule->defaultParams() ?: null,
            ]);
        }
    }
}
