<?php

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit entry. Needs `user`. Passwords, tokens and secrets were stripped when it was written.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'subject' => $this->subject_type ? ['type' => class_basename($this->subject_type), 'id' => $this->subject_id] : null,
            /** @var object|null */
            'old_values' => $this->old_values,
            /** @var object|null */
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
