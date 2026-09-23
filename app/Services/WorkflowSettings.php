<?php

namespace App\Services;

use App\Models\WorkflowSetting;
use Illuminate\Support\Facades\Schema;

/**
 * The organization's rules. Reads whatever Setup -> Workflow has saved, falling
 * back to config/pms.php for anything never touched, so a fresh organization
 * works before anybody has configured it.
 *
 * Resolved once per request: these are read on nearly every form action.
 */
class WorkflowSettings
{
    private array $cache = [];

    public function get(string $key): array
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $default = config("pms.{$key}", []);

        // Guard the very first migrate, when the table does not exist yet.
        if (! Schema::hasTable('workflow_settings')) {
            return $this->cache[$key] = $default;
        }

        $stored = WorkflowSetting::where('key', $key)->value('value');

        $value = $stored ?: $default;

        // The VP names the heads accountable on their own IPCR. A saved rule
        // from before that was allowed must not keep the control hidden.
        if ($key === 'delegation') {
            $value['assign_indicators'] = array_values(array_unique(array_merge(
                $value['assign_indicators'] ?? [],
                ['vp']
            )));
        }

        return $this->cache[$key] = $value;
    }

    public function all(): array
    {
        return collect(array_keys(config('pms', [])))
            ->mapWithKeys(fn ($key) => [$key => $this->get($key)])
            ->all();
    }

    public function put(string $key, array $value, $actor = null): void
    {
        WorkflowSetting::updateOrCreate(
            ['key' => $key],
            [
                'value'           => $value,
                'updated_by'      => $actor?->id,
                'updated_by_name' => $actor?->name,
            ]
        );

        unset($this->cache[$key]);
    }

    /** Drop an override so the organization falls back to the shipped default. */
    public function reset(string $key): void
    {
        WorkflowSetting::where('key', $key)->delete();
        unset($this->cache[$key]);
    }

    // ---- Convenience readers used across the workflow ----

    public function reviewStages(): array
    {
        return $this->get('review_stages');
    }

    public const REVIEW_ONLY_ROLES = ['vp'];

    public const REVIEW_ONLY_OPCR_SLOTS = ['creator_roles', 'publisher_roles'];

    public function mayAssignOutputs(string $role): bool
    {
        return ! in_array($role, self::REVIEW_ONLY_ROLES, true)
            && in_array($role, $this->get('delegation')['assign_outputs'] ?? [], true);
    }

    public function mayAssignIndicators(string $role): bool
    {
        return in_array($role, $this->get('delegation')['assign_indicators'] ?? [], true);
    }

    public function isTerminalRole(string $role): bool
    {
        return in_array($role, $this->get('delegation')['terminal_roles'] ?? [], true);
    }

    public function opcr(string $which): array
    {
        $roles = $this->get('opcr')[$which] ?? [];

        if (! in_array($which, self::REVIEW_ONLY_OPCR_SLOTS, true)) {
            return $roles;
        }

        return array_values(array_diff($roles, self::REVIEW_ONLY_ROLES));
    }

    public function opcrOnePerOrganization(): bool
    {
        return ($this->get('opcr')['one_per'] ?? 'organization') === 'organization';
    }

    public function ratingBands(): array
    {
        return $this->get('rating')['bands'] ?? [];
    }

    public function ratingDimensions(): array
    {
        return $this->get('rating')['dimensions'] ?? ['q', 'e', 't'];
    }

    public function ratingBounds(): array
    {
        $rating = $this->get('rating');

        return [$rating['min'] ?? 1, $rating['max'] ?? 5];
    }
}
