<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Setup -> Workflow. The rules the college runs on, held as data so a change of
 * process is an edit here rather than a deployment.
 */
class WorkflowSettingController extends Controller
{
    private const KEYS = ['review_stages', 'delegation', 'opcr', 'rating'];

    public function index(WorkflowSettings $settings)
    {
        return response()->json([
            'settings' => $settings->all(),
            'defaults' => config('pms'),
            'roles'    => User::ROLES,
            'statuses' => [
                ['status' => 'head_review', 'label' => 'Head review', 'slot' => 'head_user_id'],
                ['status' => 'vp_review', 'label' => 'VP review', 'slot' => 'vp_user_id'],
                ['status' => 'qa_rating', 'label' => 'QA rating', 'slot' => null],
            ],
        ]);
    }

    public function update(Request $request, WorkflowSettings $settings)
    {
        $data = $request->validate([
            'key'   => ['required', Rule::in(self::KEYS)],
            'value' => 'required|array',
        ]);

        if ($problem = $this->rejectBrokenRules($data['key'], $data['value'])) {
            return response()->json(['message' => $problem], 422);
        }

        $settings->put($data['key'], $data['value'], $request->user());

        ActivityLog::record('WorkflowSetting', null, 'update', "Workflow rules updated: {$data['key']}");

        return response()->json(['data' => 'updated', 'settings' => $settings->all()]);
    }

    public function reset(Request $request, WorkflowSettings $settings)
    {
        $data = $request->validate(['key' => ['required', Rule::in(self::KEYS)]]);

        $settings->reset($data['key']);

        ActivityLog::record('WorkflowSetting', null, 'reset', "Workflow rules reset to default: {$data['key']}");

        return response()->json(['data' => 'reset', 'settings' => $settings->all()]);
    }

    /**
     * Rules that would strand work are refused here rather than discovered later
     * by somebody whose form cannot move.
     */
    private function rejectBrokenRules(string $key, array $value): ?string
    {
        $reviewOnly = fn (array $slots) => collect($slots)->contains(
            fn ($slot) => array_intersect((array) ($value[$slot] ?? []), WorkflowSettings::REVIEW_ONLY_ROLES)
        );

        if ($key === 'delegation' && $reviewOnly(['assign_outputs'])) {
            return 'A VP names people on a success indicator. A whole heading is not theirs to hand out.';
        }

        if ($key === 'opcr' && $reviewOnly(WorkflowSettings::REVIEW_ONLY_OPCR_SLOTS)) {
            return 'A VP reviews only and cannot be given this step.';
        }

        if ($key === 'review_stages') {
            if (empty($value)) {
                return 'A form needs at least one review stage, or nothing could ever be rated.';
            }

            $last = end($value);

            if ($last['skippable'] ?? true) {
                return 'The final stage cannot be skippable — something has to close the form.';
            }

            foreach ($value as $stage) {
                if (($stage['source'] ?? '') === 'unit_slot' && empty($stage['slot'])) {
                    return 'A stage filled from the org unit needs to say which slot fills it.';
                }

                if (($stage['source'] ?? '') === 'role' && empty($stage['role'])) {
                    return 'A stage filled by a role needs to say which role.';
                }
            }
        }

        if ($key === 'opcr') {
            foreach (['creator_roles', 'approver_roles', 'publisher_roles', 'rating_trigger_roles'] as $slot) {
                if (empty($value[$slot])) {
                    return 'Every step of the OPCR needs somebody who can perform it.';
                }
            }
        }

        if ($key === 'rating') {
            if (empty($value['bands'])) {
                return 'The rating needs at least one adjectival band.';
            }

            if (empty($value['dimensions'])) {
                return 'The rating needs at least one dimension to score.';
            }
        }

        if ($key === 'delegation' && empty($value['assign_outputs']) && empty($value['assign_indicators'])) {
            return 'Somebody has to be able to hand work out, or nothing cascades.';
        }

        return null;
    }
}
