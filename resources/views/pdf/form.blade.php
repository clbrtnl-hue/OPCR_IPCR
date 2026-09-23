@php
    $isOpcr = $form->type === 'opcr';
    $org    = $organization->name ?? 'Opol Community College';
    $year   = $form->schoolYear?->start_date
        ? \Illuminate\Support\Carbon::parse($form->schoolYear->start_date)->format('Y')
        : $form->schoolYear?->label;
    $officeHead = $officeHead ?? null;
    $ratee = $isOpcr
        ? ($officeHead?->name ?? $form->orgUnit?->name)
        : ($form->owner?->name ?? $form->orgUnit?->name);
    $periodLabel = $isOpcr
        ? 'January to December ' . $year
        : ($period?->label ?? $form->schoolYear?->label);
    $lines = fn ($output) => $output->indicators->filter(
        fn ($i) => ! $i->rating_period_id || (int) $i->rating_period_id === (int) $period?->id
    );
    $accountable = function ($line) {
        $people = $line->assignments->map(fn ($a) => $a->user?->name)->filter();
        if ($people->isEmpty()) {
            $people = $line->children->map(fn ($c) => $c->output?->form?->owner?->name)->filter();
        }

        return $people->isNotEmpty() ? $people->implode(' / ') : ($line->accountable ?? '');
    };
    $score = fn ($value) => $value != null ? number_format((float) $value, 2) : '';
    $colspan = $isOpcr ? 10 : 8;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        .open td { border: 0; vertical-align: top; }
        .letterhead td { border: 0; vertical-align: middle; }
        .title { font-size: 12px; font-weight: bold; text-align: center; letter-spacing: 0.2px; }
        .lead { margin: 6px 0 8px; line-height: 1.4; text-align: justify; }
        .meta { font-size: 8px; }
        .scale { font-size: 7.5px; line-height: 1.35; }
        .grid th, .grid td { border: 0.6px solid #000; padding: 3px 4px; vertical-align: top; }
        .grid th { text-align: center; font-weight: bold; background: #f2f2f2; }
        .band td { background: #e6e6e6; font-weight: bold; letter-spacing: 0.3px; }
        .num { color: #333; }
        .center { text-align: center; }
        .right { text-align: right; }
        .sign { margin-top: 16px; }
        .sign .rule { border-top: 0.6px solid #000; padding-top: 3px; text-align: center; }
        .legend { margin-top: 8px; font-size: 7px; font-style: italic; }
        .muted { color: #555; }
        .comments { min-height: 36px; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td width="72">
                @if (! empty($seal))
                    <img src="{{ $seal }}" width="56" height="56" alt="LGU seal">
                @endif
            </td>
            <td class="title">
                {{ $isOpcr
                    ? 'OFFICE PERFORMANCE COMMITMENT AND REVIEW (OPCR)'
                    : 'INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW' }}
            </td>
            <td width="72"></td>
        </tr>
    </table>

    <p class="lead">
        I, <strong>{{ mb_strtoupper($ratee) }}</strong>,
        {{ $isOpcr ? 'Head of the ' . $org : 'of the ' . $org }},
        commit to deliver and agree to be rated on the attainment of the following targets in
        accordance with the indicated measures for the period&nbsp;<strong>{{ $periodLabel }}</strong>.
    </p>

    @if ($isOpcr)
        <table class="open">
            <tr>
                <td width="55%"></td>
                <td class="center meta">
                    <strong>{{ mb_strtoupper($officeHead?->name ?? $ratee) }}</strong><br>
                    Office Head<br>
                    Date: {{ $periodLabel }}
                </td>
            </tr>
        </table>

        <table class="open" style="margin-top: 8px;">
            <tr>
                <td width="40%">
                    Approved by:<br><br>
                    <strong>{{ $form->vpReviewer?->name ?? '' }}</strong><br>
                    {{ $form->vpReviewer?->position_title ?? 'Municipal Administrator' }}
                </td>
                <td width="20%">
                    Date<br><br>
                    {{ $form->vp_reviewed_at ? $form->vp_reviewed_at->format('F d, Y') : '' }}
                </td>
                <td width="40%" class="scale">
                    @foreach (($scale ?? []) as $band)
                        {{ $band['value'] }} - {{ $band['label'] }}
                        @if (! empty($band['range']))
                            ({{ $band['range'] }})
                        @endif
                        <br>
                    @endforeach
                </td>
            </tr>
        </table>
    @else
        <table class="open">
            <tr>
                <td width="55%"></td>
                <td class="center meta">
                    <strong>{{ mb_strtoupper($form->owner?->name ?? $ratee) }}</strong><br>
                    Ratee<br>
                    Date: {{ $periodLabel }}
                </td>
            </tr>
        </table>

        <table class="grid" style="margin-top: 8px;">
            <tr>
                <th width="25%">Reviewed by</th>
                <th width="15%">Date</th>
                <th width="35%">Approved by</th>
                <th width="25%">Date</th>
            </tr>
            <tr>
                <td class="center">
                    <strong>{{ $form->headReviewer?->name ?? $form->reviewed_by_name }}</strong><br>
                    <span class="muted">{{ $form->headReviewer?->position_title ?? 'Immediate Supervisor' }}</span>
                </td>
                <td class="center">{{ $form->reviewed_at ? $form->reviewed_at->format('F d, Y') : '' }}</td>
                <td class="center">
                    <strong>{{ $form->vpReviewer?->name ?? $form->vp_reviewed_by_name }}</strong><br>
                    <span class="muted">{{ $form->vpReviewer?->position_title ?? 'Head of Office' }}</span>
                </td>
                <td class="center">{{ $form->vp_reviewed_at ? $form->vp_reviewed_at->format('F d, Y') : '' }}</td>
            </tr>
        </table>
    @endif

    <table class="grid" style="margin-top: 8px;">
        <thead>
            <tr>
                <th rowspan="2" width="{{ $isOpcr ? 15 : 18 }}%">MFO/PPA</th>
                <th rowspan="2" width="{{ $isOpcr ? 24 : 30 }}%">Success Indicator<br><span class="muted">(Target + Measure)</span></th>
                @if ($isOpcr)
                    <th rowspan="2" width="8%">Alloted Budget</th>
                    <th rowspan="2" width="13%">Individual / Accountable</th>
                @endif
                <th rowspan="2" width="{{ $isOpcr ? 16 : 26 }}%">Actual Accomplishments</th>
                <th colspan="4" width="14%">Rating</th>
                <th rowspan="2" width="10%">Remarks</th>
            </tr>
            <tr><th>Q</th><th>E</th><th>T</th><th>A</th></tr>
        </thead>
        <tbody>
            @foreach (['strategic' => 'STRATEGIC PRIORITY', 'core' => 'CORE FUNCTIONS', 'support' => 'SUPPORT FUNCTIONS'] as $key => $band)
                @php $outputs = $form->outputs->where('section', $key); @endphp
                @continue($outputs->isEmpty())

                <tr class="band"><td colspan="{{ $colspan }}">{{ $band }}</td></tr>

                @foreach (\App\Support\PcrOutline::numbered($outputs) as $output)
                    @php
                        $rows = $lines($output)->values();
                        $number = $output->outline_number ?? '';
                    @endphp

                    @forelse ($rows as $li => $line)
                        @php
                            $rating = $line->ratings->firstWhere('rating_period_id', $period?->id);
                            $acc    = $line->accomplishments->firstWhere('rating_period_id', $period?->id);
                        @endphp
                        <tr>
                            @if ($li === 0)
                                <td rowspan="{{ max($rows->count(), 1) }}" style="padding-left: {{ 3 + (int) ($output->outline_depth ?? 0) * 7 }}px">
                                    <span class="num">{{ $number }}.</span> {{ $output->title }}
                                </td>
                            @endif
                            <td>{{ trim(strip_tags($line->description)) }}</td>
                            @if ($isOpcr)
                                <td class="right">{{ $line->allotted_budget ? number_format($line->allotted_budget, 2) : '' }}</td>
                                <td>{{ $accountable($line) }}</td>
                            @endif
                            <td>{!! $acc?->actual_accomplishment !!}</td>
                            <td class="center">{{ $rating?->q }}</td>
                            <td class="center">{{ $rating?->e }}</td>
                            <td class="center">{{ $rating?->t }}</td>
                            <td class="center">{{ $rating?->a != null ? number_format($rating->a, 2) : '' }}</td>
                            <td>{!! $rating?->remarks !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td style="padding-left: {{ 3 + (int) ($output->outline_depth ?? 0) * 7 }}px">
                                <span class="num">{{ $number }}.</span> {{ $output->title }}
                            </td>
                            <td colspan="{{ $colspan - 1 }}"></td>
                        </tr>
                    @endforelse
                @endforeach

                @if ($isOpcr && in_array($key, ['core', 'support'], true))
                    <tr>
                        <td colspan="{{ $colspan - 1 }}"><strong>AVERAGE RATING</strong></td>
                        <td class="center"><strong>{{ $score($summary?->{$key . '_average'}) }}</strong></td>
                    </tr>
                @endif
            @endforeach

            @if ($isOpcr)
                <tr>
                    <td colspan="{{ $colspan - 1 }}"><strong>Total Overall Rating</strong></td>
                    <td class="center"><strong>{{ $score($summary?->final_average) }}</strong></td>
                </tr>
            @endif
            <tr>
                <td colspan="{{ $colspan - 1 }}"><strong>Final Average Rating</strong></td>
                <td class="center"><strong>{{ $score($summary?->final_average) }}</strong></td>
            </tr>
            <tr>
                <td colspan="{{ $colspan - 1 }}"><strong>Adjectival Rating</strong></td>
                <td class="center"><strong>{{ $summary?->adjectival }}</strong></td>
            </tr>
            @unless ($isOpcr)
                <tr>
                    <td colspan="{{ $colspan }}">
                        <strong>Comments and Recommendations for Development Purposes:</strong>
                        <div class="comments">{!! $form->header_note !!}</div>
                    </td>
                </tr>
            @endunless
        </tbody>
    </table>

    @if ($isOpcr)
        <table class="open sign">
            <tr>
                <td width="33%" class="center">
                    Assessed by:<br><br><br>
                    <div class="rule">
                        {{ $form->rated_by_name ?? '' }}<br>
                        Mun. Planning &amp; Dev&apos;t Coordinator
                    </div>
                </td>
                <td width="34%" class="center">
                    Final Rating by:<br><br><br>
                    <div class="rule">
                        {{ $form->vpReviewer?->name ?? '' }}<br>
                        Municipal Administrator — PMT Chairperson
                    </div>
                </td>
                <td width="33%" class="center">
                    Head of Agency:<br><br><br>
                    <div class="rule">
                        {{ $officeHead?->name ?? $ratee }}<br>
                        {{ $officeHead?->position_title ?? 'Head of Agency' }}
                    </div>
                </td>
            </tr>
        </table>
    @else
        <table class="open sign">
            <tr>
                <td width="33%" class="center">
                    Discussed with<br><br><br>
                    <div class="rule">{{ $form->owner?->name }}<br>Employee</div>
                </td>
                <td width="34%" class="center">
                    Assessed by<br>
                    <span class="muted">I certify that I discussed my assessment of the performance with the employee</span><br><br>
                    <div class="rule">
                        {{ $form->headReviewer?->name ?? $form->reviewed_by_name }}<br>
                        Supervisor
                    </div>
                </td>
                <td width="33%" class="center">
                    Final Rating by<br><br><br>
                    <div class="rule">
                        {{ $form->rated_by_name ?? $form->vpReviewer?->name }}<br>
                        Head of Office
                    </div>
                </td>
            </tr>
        </table>
    @endif

    <p class="legend">
        Legend: Q &ndash; Quality &nbsp; E &ndash; Efficiency &nbsp; T &ndash; Timeliness &nbsp; A &ndash; Average
    </p>
</body>
</html>
