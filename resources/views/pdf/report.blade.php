@php
    $totals = $payload['totals'];
    $label  = fn ($value) => $value === null ? '—' : ucfirst(str_replace('_', ' ', $value));
    $score  = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $pct    = fn ($value) => $value === null ? '—' : $value . '%';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #000; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 2px; }
        h2 { font-size: 10px; margin: 14px 0 5px; border-bottom: 0.6px solid #000; padding-bottom: 2px; }
        .sub { text-align: center; margin: 0 0 10px; font-size: 8.5px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 0.6px solid #000; padding: 3px 4px; vertical-align: top; }
        th { text-align: left; font-weight: bold; background: #f2f2f2; }
        td.num, th.num { text-align: right; }
        .totals td { border: 0.6px solid #999; width: 20%; }
        .totals .k { color: #444; font-size: 7.5px; }
        .totals .v { font-size: 13px; font-weight: bold; }
        .muted { color: #666; }
        .foot { margin-top: 12px; font-size: 7.5px; color: #666; }
    </style>
</head>
<body>
    <h1>PERFORMANCE REPORT — {{ mb_strtoupper($organization?->name ?? 'OPOL COMMUNITY COLLEGE') }}</h1>
    <p class="sub">
        {{ $year?->label }} · {{ $period?->label ?? 'Whole year (both review periods)' }}
        · prepared {{ now()->format('F j, Y') }}@if($preparedBy) by {{ $preparedBy }}@endif
    </p>

    <table class="totals">
        <tr>
            <td><div class="k">College average</div><div class="v">{{ $score($totals['college_average']) }}</div>
                <div class="k">{{ $totals['college_rating'] ?? 'Not yet rated' }}</div></td>
            <td><div class="k">Forms in the cycle</div><div class="v">{{ $totals['forms'] }}</div>
                <div class="k">{{ $totals['submitted'] }} submitted · {{ $totals['rated'] }} rated</div></td>
            <td><div class="k">Commitments</div><div class="v">{{ $totals['commitments'] }}</div>
                <div class="k">{{ $totals['completed'] }} completed</div></td>
            <td><div class="k">Progress</div><div class="v">{{ $pct($totals['progress_pct']) }}</div>
                <div class="k">average across every line</div></td>
            <td><div class="k">Overdue</div><div class="v">{{ $totals['overdue'] }}</div>
                <div class="k">{{ $totals['due_soon'] }} due within 7 days</div></td>
        </tr>
    </table>

    <h2>Where the forms are</h2>
    <table>
        <tr>
            @foreach ($payload['by_status'] as $status => $count)
                <th>{{ $label($status) }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($payload['by_status'] as $status => $count)
                <td class="num">{{ $count }}</td>
            @endforeach
        </tr>
    </table>

    <h2>Rating spread</h2>
    <table>
        <tr>
            @foreach ($bands as $band)
                <th>{{ $band['value'] }} — {{ $band['label'] }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($bands as $band)
                <td class="num">{{ $payload['adjectival'][$band['label']] ?? 0 }}</td>
            @endforeach
        </tr>
    </table>

    <h2>By unit</h2>
    <table>
        <thead>
            <tr>
                <th width="26%">Unit</th><th width="7%">Code</th><th class="num">Forms</th>
                <th class="num">Submitted</th><th class="num">Rated</th><th class="num">Commitments</th>
                <th class="num">Progress</th><th class="num">Overdue</th><th class="num">Average</th><th>Adjectival</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['units'] as $unit)
                <tr>
                    <td>{{ $unit['name'] }}</td>
                    <td>{{ $unit['code'] ?: '—' }}</td>
                    <td class="num">{{ $unit['total_forms'] }}</td>
                    <td class="num">{{ $unit['submitted'] }}</td>
                    <td class="num">{{ $unit['rated'] }}</td>
                    <td class="num">{{ $unit['commitments'] }}</td>
                    <td class="num">{{ $pct($unit['progress_pct']) }}</td>
                    <td class="num">{{ $unit['overdue'] }}</td>
                    <td class="num">{{ $score($unit['average']) }}</td>
                    <td>{{ $unit['adjectival'] ?? 'Not yet rated' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>By head</h2>
    <table>
        <thead>
            <tr>
                <th width="20%">Head</th><th width="18%">Position</th><th width="22%">Unit</th>
                <th class="num">Forms</th><th class="num">Submitted</th><th class="num">Rated</th>
                <th class="num">Commitments</th><th class="num">Progress</th><th class="num">Overdue</th>
                <th class="num">Average</th><th>Adjectival</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['heads'] as $head)
                <tr>
                    <td>{{ $head['head'] }}</td>
                    <td>{{ $head['position'] ?? '—' }}</td>
                    <td>{{ $head['unit'] }}</td>
                    <td class="num">{{ $head['forms'] }}</td>
                    <td class="num">{{ $head['submitted'] }}</td>
                    <td class="num">{{ $head['rated'] }}</td>
                    <td class="num">{{ $head['commitments'] }}</td>
                    <td class="num">{{ $pct($head['progress_pct']) }}</td>
                    <td class="num">{{ $head['overdue'] }}</td>
                    <td class="num">{{ $score($head['average']) }}</td>
                    <td>{{ $head['adjectival'] ?? 'Not yet rated' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>By individual</h2>
    <table>
        <thead>
            <tr>
                <th width="20%">Name</th><th width="18%">Position</th><th width="20%">Unit</th>
                <th class="num">Forms</th><th class="num">Commitments</th><th class="num">Completed</th>
                <th class="num">Progress</th><th class="num">Overdue</th><th class="num">Days late</th>
                <th class="num">Average</th><th>Adjectival</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['people'] as $person)
                <tr>
                    <td>{{ $person['name'] }}</td>
                    <td>{{ $person['position'] ?? '—' }}</td>
                    <td>{{ $person['unit'] }}</td>
                    <td class="num">{{ $person['forms'] }}</td>
                    <td class="num">{{ $person['commitments'] }}</td>
                    <td class="num">{{ $person['completed'] }}</td>
                    <td class="num">{{ $pct($person['progress_pct']) }}</td>
                    <td class="num">{{ $person['overdue'] }}</td>
                    <td class="num">{{ $person['days_late'] ?: '—' }}</td>
                    <td class="num">{{ $score($person['average']) }}</td>
                    <td>{{ $person['adjectival'] ?? 'Not yet rated' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Forms in the cycle</h2>
    <table>
        <thead>
            <tr>
                <th width="6%">Type</th><th width="20%">Owner</th><th width="20%">Unit</th><th width="11%">Status</th>
                <th width="9%">Submitted</th><th class="num">Lines</th><th class="num">Progress</th>
                <th class="num">Overdue</th><th class="num">Final</th><th>Adjectival</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['forms'] as $form)
                <tr>
                    <td>{{ $form['type'] }}</td>
                    <td>{{ $form['owner'] }}</td>
                    <td>{{ $form['unit'] }}</td>
                    <td>{{ $label($form['status']) }}</td>
                    <td>{{ $form['submitted_at'] ?? '—' }}</td>
                    <td class="num">{{ $form['commitments'] }}</td>
                    <td class="num">{{ $pct($form['progress_pct']) }}</td>
                    <td class="num">{{ $form['overdue'] }}</td>
                    <td class="num">{{ $score($form['average']) }}</td>
                    <td>{{ $form['adjectival'] ?? 'Not yet rated' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (count($payload['at_risk']))
        <h2>Commitments past their target date</h2>
        <table>
            <thead>
                <tr>
                    <th width="40%">Success indicator</th><th width="20%">Owner</th><th width="20%">Unit</th>
                    <th width="9%">Target date</th><th class="num">Days late</th><th class="num">Progress</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['at_risk'] as $line)
                    <tr>
                        <td>{{ $line['description'] }}</td>
                        <td>{{ $line['owner'] }}</td>
                        <td>{{ $line['unit'] }}</td>
                        <td>{{ $line['target_date'] ?? '—' }}</td>
                        <td class="num">{{ $line['days_late'] }}</td>
                        <td class="num">{{ $line['progress_pct'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">
        Progress is monitoring only — it never feeds the Q/E/T score. Generated by OCC PMS.
    </p>
</body>
</html>
