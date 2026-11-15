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

        return $this->cache[$key] = $stored ?: $default;
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

    public function mayAssignOutputs(string $role): bool
    {
        return in_array($role, $this->get('delegation')['assign_outputs'] ?? [], true);
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
        return array_values($this->get('opcr')[$which] ?? []);
    }

    /**
     * Whoever fills the last role-gated review stage rates and closes the
     * rating. Setup → Workflow names that role on the final step.
     */
    public function raterRoles(): array
    {
        $stages = $this->reviewStages();

        for ($i = count($stages) - 1; $i >= 0; $i--) {
            $role = $stages[$i]['role'] ?? null;

            if (($stages[$i]['source'] ?? '') === 'role' && $role) {
                return [$role];
            }
        }

        return ['qa'];
    }

    public function isRater(string $role): bool
    {
        return in_array($role, $this->raterRoles(), true);
    }

    /** Q, E and T, limited to the dimensions Setup left switched on. */
    public function ratingDimensionKeys(): array
    {
        $keys = array_values(array_intersect($this->ratingDimensions(), ['q', 'e', 't']));

        return $keys ?: ['q', 'e', 't'];
    }

    /** What the signed-in screen needs in order to offer the same actions the server would accept. */
    public function forClient(): array
    {
        [$min, $max] = $this->ratingBounds();

        return [
            'rating' => [
                'dimensions' => $this->ratingDimensionKeys(),
                'min'        => (int) $min,
                'max'        => (int) $max,
                'bands'      => $this->ratingBands(),
            ],
            'opcr' => [
                'creator_roles'        => $this->opcr('creator_roles'),
                'approver_roles'       => $this->opcr('approver_roles'),
                'publisher_roles'      => $this->opcr('publisher_roles'),
                'rating_trigger_roles' => $this->opcr('rating_trigger_roles'),
            ],
            'rater_roles' => $this->raterRoles(),
        ];
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
