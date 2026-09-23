<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #000; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 10px; margin: 16px 0 5px; padding-bottom: 3px;
             border-bottom: 0.8px solid #2f6f57; color: #2f6f57; letter-spacing: 0.4px; }
        .role { color: #555; margin: 0 0 2px; }
        .contact { color: #555; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-top: 2px; }
        th, td { border: 0.5px solid #bbb; padding: 3px 5px; vertical-align: top; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        .pairs td { border: 0; padding: 2px 0; }
        .pairs .k { color: #555; width: 26%; }
        .empty { color: #888; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $user->name }}</h1>
    <p class="role">{{ $user->position_title }}@if($user->orgUnit) &middot; {{ $user->orgUnit->name }}@endif</p>
    <p class="contact">{{ $user->email }}@if($profile?->mobile) &middot; {{ $profile->mobile }}@endif</p>

    @if ($profile)
        <h2>PERSONAL INFORMATION</h2>
        <table class="pairs">
            <tr>
                <td class="k">Date of birth</td><td>{{ $profile->date_of_birth?->format('F j, Y') ?: '—' }}</td>
                <td class="k">Place of birth</td><td>{{ $profile->place_of_birth ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Sex</td><td>{{ ucfirst($profile->sex ?: '—') }}</td>
                <td class="k">Civil status</td><td>{{ ucfirst($profile->civil_status ?: '—') }}</td>
            </tr>
            <tr>
                <td class="k">Citizenship</td><td>{{ $profile->citizenship ?: '—' }}</td>
                <td class="k">Blood type</td><td>{{ $profile->blood_type ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Address</td><td colspan="3">{{ $profile->residential_address ?: '—' }}</td>
            </tr>
        </table>
    @endif

    @foreach ([
        ['EDUCATIONAL BACKGROUND', $educations, ['Level' => 'level', 'School' => 'school', 'Degree' => 'degree', 'Graduated' => 'year_graduated']],
        ['CIVIL SERVICE ELIGIBILITY', $eligibilities, ['Eligibility' => 'eligibility', 'Rating' => 'rating', 'Licence' => 'licence_number']],
        ['WORK EXPERIENCE', $workExperiences, ['Position' => 'position', 'Office' => 'company', 'Status' => 'appointment_status']],
        ['TRAINING AND SEMINARS', $trainings, ['Title' => 'title', 'Hours' => 'hours', 'Conducted by' => 'conducted_by']],
        ['VOLUNTARY WORK', $voluntaryWorks, ['Organisation' => 'organization', 'Position' => 'position', 'Hours' => 'hours']],
    ] as [$heading, $rows, $columns])
        <h2>{{ $heading }}</h2>
        @if ($rows->isEmpty())
            <p class="empty">Nothing recorded.</p>
        @else
            <table>
                <tr>@foreach (array_keys($columns) as $label)<th>{{ $label }}</th>@endforeach</tr>
                @foreach ($rows as $row)
                    <tr>@foreach ($columns as $field)<td>{{ $row->{$field} ?: '—' }}</td>@endforeach</tr>
                @endforeach
            </table>
        @endif
    @endforeach
</body>
</html>
