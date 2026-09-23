<?php

namespace App\Services;

class RatingScale
{
    /** The shipped default; an organization may revise the bands in Setup. */
    public const SCALE = [
        ['min' => 4.5, 'label' => 'Outstanding',       'value' => 5],
        ['min' => 3.5, 'label' => 'Very Satisfactory', 'value' => 4],
        ['min' => 2.5, 'label' => 'Satisfactory',      'value' => 3],
        ['min' => 1.5, 'label' => 'Unsatisfactory',    'value' => 2],
        ['min' => 0.0, 'label' => 'Poor',              'value' => 1],
    ];

    public const DIMENSION_LABELS = [
        'q' => 'Quality',
        'e' => 'Efficiency',
        't' => 'Timeliness',
    ];

    public static function bands(): array
    {
        // Usable without a booted application, so the scale can be reasoned
        // about in isolation.
        if (! app()->bound(WorkflowSettings::class)) {
            return self::SCALE;
        }

        try {
            return app(WorkflowSettings::class)->ratingBands() ?: self::SCALE;
        } catch (\Throwable) {
            return self::SCALE;
        }
    }

    public static function average(?int $q, ?int $e, ?int $t): ?float
    {
        $given = array_values(array_filter([$q, $e, $t], fn ($v) => $v !== null));

        if (empty($given)) {
            return null;
        }

        return round(array_sum($given) / count($given), 2);
    }

    public static function adjectival(?float $average): ?string
    {
        if ($average === null) {
            return null;
        }

        foreach (self::bands() as $band) {
            if ($average >= $band['min']) {
                return $band['label'];
            }
        }

        return 'Poor';
    }

    public static function mean(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        if (empty($values)) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }
}
