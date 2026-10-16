<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;

class AuditService
{
    public function __construct(private readonly OrganizationContext $organizationContext)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(string $action, ?User $actor = null, mixed $subject = null, array $metadata = [], ?Organization $organization = null): AuditLog
    {
        $request = request();

        return AuditLog::query()->create([
            'organization_id' => ($organization ?? $this->organizationContext->organization())?->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => is_object($subject) ? $subject::class : null,
            'subject_id' => is_object($subject) && method_exists($subject, 'getKey') ? (string) $subject->getKey() : null,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
