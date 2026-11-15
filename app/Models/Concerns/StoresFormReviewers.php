<?php

namespace App\Models\Concerns;

use App\Models\PcrFormReviewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

/**
 * Who was named for a stage, and who signed it, lives on pcr_form_reviewers.
 * The old form field names still read and write that one row.
 */
trait StoresFormReviewers
{
    /** @var array<string, array<string, mixed>> */
    public array $pendingStages = [];

    private ?Collection $stageRowCache = null;

    public static function bootStoresFormReviewers(): void
    {
        static::saved(function (self $form) {
            $form->flushPendingStages();
        });
    }

    protected function headReviewerId(): Attribute
    {
        return $this->stageAttribute('head_review', 'reviewer_id');
    }

    protected function deanReviewerId(): Attribute
    {
        return $this->stageAttribute('dean_review', 'reviewer_id');
    }

    protected function vpReviewerId(): Attribute
    {
        return $this->stageAttribute('vp_review', 'reviewer_id');
    }

    protected function reviewedBy(): Attribute
    {
        return $this->stageAttribute('head_review', 'reviewed_by');
    }

    protected function reviewedByName(): Attribute
    {
        return $this->stageAttribute('head_review', 'reviewed_by_name');
    }

    protected function reviewedAt(): Attribute
    {
        return $this->stageAttribute('head_review', 'reviewed_at');
    }

    protected function deanReviewedBy(): Attribute
    {
        return $this->stageAttribute('dean_review', 'reviewed_by');
    }

    protected function deanReviewedByName(): Attribute
    {
        return $this->stageAttribute('dean_review', 'reviewed_by_name');
    }

    protected function deanReviewedAt(): Attribute
    {
        return $this->stageAttribute('dean_review', 'reviewed_at');
    }

    protected function vpReviewedBy(): Attribute
    {
        return $this->stageAttribute('vp_review', 'reviewed_by');
    }

    protected function vpReviewedByName(): Attribute
    {
        return $this->stageAttribute('vp_review', 'reviewed_by_name');
    }

    protected function vpReviewedAt(): Attribute
    {
        return $this->stageAttribute('vp_review', 'reviewed_at');
    }

    private function stageAttribute(string $stage, string $column)
    {
        return Attribute::make(
            get: function () use ($stage, $column) {
                $value = $this->stageField($stage, $column);

                if ($column === 'reviewed_at') {
                    return $value ? $this->asDateTime($value) : null;
                }

                if ($column === 'reviewed_by_name') {
                    return $value !== null && $value !== '' ? (string) $value : null;
                }

                return $value ? (int) $value : null;
            },
            set: function ($value) use ($stage, $column) {
                if ($column === 'reviewed_by_name') {
                    $stored = $value !== null && $value !== '' ? (string) $value : null;
                } elseif ($column === 'reviewed_at') {
                    $stored = $value ? $this->fromDateTime($value) : null;
                } else {
                    $stored = $value ? (int) $value : null;
                }

                $this->pendingStages[$stage][$column] = $stored;

                return [];
            },
        )->withoutObjectCaching();
    }

    public function flushPendingStages(): void
    {
        if ($this->pendingStages === [] || ! $this->id) {
            return;
        }

        foreach ($this->pendingStages as $stage => $fields) {
            PcrFormReviewer::updateOrCreate(
                ['form_id' => $this->id, 'stage' => $stage],
                $fields
            );
        }

        $this->pendingStages = [];
        $this->stageRowCache = null;
        $this->unsetRelation('stageReviewers');
    }

    private function stageField(string $stage, string $column): mixed
    {
        if (isset($this->pendingStages[$stage]) && array_key_exists($column, $this->pendingStages[$stage])) {
            return $this->pendingStages[$stage][$column];
        }

        return $this->stageRows()->firstWhere('stage', $stage)?->{$column};
    }

    private function stageRows(): Collection
    {
        if ($this->relationLoaded('stageReviewers')) {
            return $this->stageReviewers;
        }

        if ($this->stageRowCache !== null) {
            return $this->stageRowCache;
        }

        if (! $this->exists) {
            return $this->stageRowCache = collect();
        }

        return $this->stageRowCache = $this->stageReviewers()->get();
    }

    /** Forms where this person is the named reviewer. Current stage only, when asked. */
    public function scopeNamedFor(Builder $query, int $userId, bool $onlyCurrentStage = false): Builder
    {
        return $query->whereExists(function ($inner) use ($userId, $onlyCurrentStage) {
            $inner->selectRaw('1')
                ->from('pcr_form_reviewers')
                ->whereColumn('pcr_form_reviewers.form_id', 'pcr_forms.id')
                ->where('pcr_form_reviewers.reviewer_id', $userId);

            if ($onlyCurrentStage) {
                $inner->whereColumn('pcr_form_reviewers.stage', 'pcr_forms.status');
            }
        });
    }
}
