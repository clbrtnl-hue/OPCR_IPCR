<?php

namespace App\Services;

use App\Models\PcrForm;
use App\Models\User;

/**
 * A Personal Data Sheet is a personal record. Anyone signed in may see the card
 * — name, position, office, contact — because that is what a tag is for. The
 * sheet itself is need-to-know: yourself, the people who review your work, and
 * those who oversee the cycle.
 */
class UserProfileAccess
{
    public static function mayViewSheet(User $viewer, User $subject): bool
    {
        if ((int) $viewer->id === (int) $subject->id) {
            return true;
        }

        if (in_array($viewer->role, ['admin', 'qa', 'president'], true)) {
            return true;
        }

        // Whoever is named to review one of this person's forms — which is how
        // the hierarchy already decides who reads their work.
        return PcrForm::where('user_id', $subject->id)
            ->where(function ($query) use ($viewer) {
                $query->where('head_reviewer_id', $viewer->id)
                    ->orWhere('vp_reviewer_id', $viewer->id);
            })
            ->exists();
    }
}
