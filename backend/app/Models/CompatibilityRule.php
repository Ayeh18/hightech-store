<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Admin-editable settings for one compatibility rule. The rule's logic is the class named by `key`. */
#[Fillable(['key', 'is_enabled', 'severity', 'params'])]
class CompatibilityRule extends Model
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'params' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
