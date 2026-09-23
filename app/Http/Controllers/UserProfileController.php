<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\UserProfileAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The person behind the tag. Everyone signed in gets the card; the Personal
 * Data Sheet is served only to those UserProfileAccess allows — and is omitted
 * from the payload entirely rather than sent and hidden.
 */
class UserProfileController extends Controller
{
    /** Which repeating section maps to which relation and rules. */
    private const SECTIONS = [
        'educations' => [
            'level'          => 'required|string|max:40',
            'school'         => 'required|string|max:255',
            'degree'         => 'nullable|string|max:255',
            'period_from'    => 'nullable|string|max:12',
            'period_to'      => 'nullable|string|max:12',
            'units_earned'   => 'nullable|string|max:60',
            'year_graduated' => 'nullable|string|max:12',
            'honours'        => 'nullable|string|max:255',
        ],
        'eligibilities' => [
            'eligibility'         => 'required|string|max:255',
            'rating'              => 'nullable|string|max:20',
            'examination_date'    => 'nullable|date',
            'examination_place'   => 'nullable|string|max:255',
            'licence_number'      => 'nullable|string|max:60',
            'licence_valid_until' => 'nullable|date',
        ],
        'workExperiences' => [
            'position'           => 'required|string|max:255',
            'company'            => 'required|string|max:255',
            'started_on'         => 'nullable|date',
            'ended_on'           => 'nullable|date',
            'is_current'         => 'nullable|boolean',
            'monthly_salary'     => 'nullable|string|max:40',
            'salary_grade'       => 'nullable|string|max:20',
            'appointment_status' => 'nullable|string|max:60',
            'is_government'      => 'nullable|boolean',
        ],
        'trainings' => [
            'title'        => 'required|string|max:255',
            'started_on'   => 'nullable|date',
            'ended_on'     => 'nullable|date',
            'hours'        => 'nullable|integer|min:0',
            'kind'         => 'nullable|string|max:60',
            'conducted_by' => 'nullable|string|max:255',
        ],
        'voluntaryWorks' => [
            'organization' => 'required|string|max:255',
            'started_on'   => 'nullable|date',
            'ended_on'     => 'nullable|date',
            'hours'        => 'nullable|integer|min:0',
            'position'     => 'nullable|string|max:255',
        ],
    ];

    public function show(Request $request, $id)
    {
        $user   = User::with('orgUnit:id,name')->findOrFail($id);
        $viewer = $request->user();

        $card = [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'image'          => $user->image,
            'role'           => $user->role,
            'position_title' => $user->position_title,
            'org_unit'       => $user->orgUnit?->only(['id', 'name']),
        ];

        if (! UserProfileAccess::mayViewSheet($viewer, $user)) {
            return response()->json(['card' => $card, 'may_view_sheet' => false]);
        }

        $user->load(['profile', 'educations', 'eligibilities', 'workExperiences', 'trainings', 'voluntaryWorks']);

        return response()->json([
            'card'            => $card,
            'may_view_sheet'  => true,
            'may_edit'        => (int) $viewer->id === (int) $user->id || $viewer->isAdmin(),
            'profile'         => $user->profile,
            'educations'      => $user->educations,
            'eligibilities'   => $user->eligibilities,
            'workExperiences' => $user->workExperiences,
            'trainings'       => $user->trainings,
            'voluntaryWorks'  => $user->voluntaryWorks,
        ]);
    }

    /** The Personal Data Sheet as a document, for whoever may read it. */
    public function pdf(Request $request, $id)
    {
        $user = User::with([
            'orgUnit:id,name', 'profile', 'educations', 'eligibilities',
            'workExperiences', 'trainings', 'voluntaryWorks',
        ])->findOrFail($id);

        if (! UserProfileAccess::mayViewSheet($request->user(), $user)) {
            return response()->json(['message' => 'That personal data sheet is private.'], 403);
        }

        $pdf = Pdf::loadView('pdf.profile', [
            'user'            => $user,
            'profile'         => $user->profile,
            'educations'      => $user->educations,
            'eligibilities'   => $user->eligibilities,
            'workExperiences' => $user->workExperiences,
            'trainings'       => $user->trainings,
            'voluntaryWorks'  => $user->voluntaryWorks,
        ])->setPaper('a4');

        return $pdf->stream("{$user->name} - Personal Data Sheet.pdf");
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'date_of_birth'       => 'nullable|date',
            'place_of_birth'      => 'nullable|string|max:255',
            'sex'                 => ['nullable', Rule::in(['male', 'female'])],
            'civil_status'        => ['nullable', Rule::in(['single', 'married', 'widowed', 'separated', 'other'])],
            'citizenship'         => 'nullable|string|max:60',
            'height_m'            => 'nullable|numeric|min:0|max:3',
            'weight_kg'           => 'nullable|numeric|min:0|max:500',
            'blood_type'          => 'nullable|string|max:6',
            'gsis_id'             => 'nullable|string|max:40',
            'pagibig_id'          => 'nullable|string|max:40',
            'philhealth_id'       => 'nullable|string|max:40',
            'sss_id'              => 'nullable|string|max:40',
            'tin'                 => 'nullable|string|max:40',
            'agency_employee_no'  => 'nullable|string|max:40',
            'residential_address' => 'nullable|string|max:255',
            'permanent_address'   => 'nullable|string|max:255',
            'telephone'           => 'nullable|string|max:40',
            'mobile'              => 'nullable|string|max:40',
        ]);

        $profile = UserProfile::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json(['data' => 'updated', 'profile' => $profile]);
    }

    /** One entry in a repeating section; an id in the body means edit. */
    public function storeSection(Request $request, string $section)
    {
        $rules = self::SECTIONS[$section] ?? abort(404);

        $data = $request->validate($rules + [
            'id'         => 'nullable|integer',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $relation = $request->user()->{$section}();
        $id       = $request->input('id');

        if ($id) {
            $row = $relation->findOrFail($id);
            $row->update($data);

            return response()->json(['data' => 'updated', 'row' => $row]);
        }

        $row = $relation->create($data + [
            'sort_order' => $data['sort_order'] ?? ($relation->max('sort_order') ?? 0) + 1,
        ]);

        return response()->json(['data' => 'created', 'row' => $row], 201);
    }

    public function destroySection(Request $request, string $section, $id)
    {
        if (! isset(self::SECTIONS[$section])) {
            abort(404);
        }

        $request->user()->{$section}()->findOrFail($id)->delete();

        return response()->json(['data' => 'deleted']);
    }
}
