<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the organization the current request belongs to. The UI is
 * single-tenant, so this is the signed-in user's organization, falling back to
 * the only active row for guest routes (login, the public branding endpoint).
 *
 * Registered as a scoped singleton so one request resolves it once.
 */
class CurrentOrganization
{
    private ?int $id = null;

    private bool $resolved = false;

    public function id(): ?int
    {
        if ($this->resolved) {
            return $this->id;
        }

        $this->resolved = true;

        $user = Auth::user();

        if ($user && $user->organization_id) {
            return $this->id = (int) $user->organization_id;
        }

        return $this->id = Organization::where('status', 'active')->value('id');
    }

    public function get(): ?Organization
    {
        $id = $this->id();

        return $id ? Organization::find($id) : null;
    }

    /** Pin the organization explicitly — used by tests and console commands. */
    public function set(?int $id): void
    {
        $this->id       = $id;
        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->id       = null;
        $this->resolved = false;
    }
}
