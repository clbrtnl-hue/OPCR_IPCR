<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\RatingPeriod;
use App\Services\AnnouncementService;
use App\Support\Html;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual megaphone. Period open/lock notices fire automatically from School
 * Years; closing-soon stays on the daily reminder job.
 */
class AnnouncementController extends Controller
{
    public function store(Request $request, AnnouncementService $announcements)
    {
        $data = $request->validate([
            'title'             => ['required', 'string', 'max:160'],
            'body'              => ['required', 'string', 'max:1000'],
            'audiences'         => ['required', 'array', 'min:1'],
            'audiences.*'       => ['string', Rule::in(['all', 'incomplete', 'in_review', 'roles'])],
            'roles'             => ['nullable', 'array'],
            'roles.*'           => ['string', Rule::in([
                'employee', 'program_head', 'vp', 'qa', 'hr', 'president',
            ])],
            'rating_period_id'  => ['nullable', 'integer', 'exists:rating_periods,id'],
        ]);

        $audiences = array_values(array_unique($data['audiences']));

        if (in_array('all', $audiences, true)) {
            $audiences = ['all'];
        }

        if (in_array('roles', $audiences, true) && empty($data['roles'])) {
            return response()->json(['message' => 'Pick at least one role.'], 422);
        }

        $title = trim(Html::toText($data['title']));
        $body  = Html::clean($data['body']);
        $plain = Html::toText($body);

        if ($title === '' || Html::isBlank($body)) {
            return response()->json(['message' => 'Enter a title and a message.'], 422);
        }

        $period = ! empty($data['rating_period_id'])
            ? RatingPeriod::with('schoolYear:id,label')->find($data['rating_period_id'])
            : null;

        if ($period?->closes_at) {
            $plain .= ' Closes '.$period->closes_at->format('M j, Y').'.';
        }

        $recent = ActivityLog::query()
            ->where('subject_type', 'Announcement')
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recent && in_array('all', $audiences, true)) {
            return response()->json([
                'message' => 'Wait a few minutes before broadcasting to everyone again.',
            ], 429);
        }

        $sent = $announcements->broadcast(
            title: $title,
            body: $plain,
            audiences: $audiences,
            roles: $data['roles'] ?? [],
            period: $period,
            link: $period ? '/my-forms?period='.$period->id : '/notifications',
            automatic: false,
        );

        if ($sent === 0) {
            return response()->json([
                'message' => 'Nobody matched that audience.',
            ], 422);
        }

        return response()->json([
            'data'    => 'sent',
            'sent'    => $sent,
            'message' => 'Announcement sent to '.$sent.' '
                .($sent === 1 ? 'person' : 'people').'.',
        ]);
    }
}
