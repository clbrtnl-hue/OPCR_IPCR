<?php

namespace App\Support;

use Mews\Purifier\Facades\Purifier;

/**
 * Commitments, accomplishments and remarks are written by one person and read
 * by another — and printed onto the CSC-facing form. Cleaning at the boundary
 * means the database never holds anything unsafe, so every reader downstream is
 * safe without having to remember.
 */
class Html
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = Purifier::clean($value, 'pms');

        // Empty markup from an editor the writer cleared out is just nothing.
        return static::isBlank($clean) ? null : $clean;
    }

    /** Whether the markup carries any words, as opposed to empty tags. */
    public static function isBlank(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5)) === '';
    }

    /** The words alone — for notifications, logs and anywhere markup would be noise. */
    public static function toText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $spaced = preg_replace('/<(br|\/p|\/li)[^>]*>/i', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5)));
    }

    /** Visible words only, so markup does not eat the writer's character budget. */
    public static function maxChars(int $max): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($max) {
            if ($value === null || $value === '') {
                return;
            }

            if (mb_strlen(static::toText((string) $value)) > $max) {
                $fail("Keep this to {$max} characters.");
            }
        };
    }
}
