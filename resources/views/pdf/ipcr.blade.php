@php
    $upper = fn ($name) => $name ? mb_strtoupper($name) : '';
    $when = function ($value) {
        if (! $value) {
            return '';
        }

        $date = $value instanceof \DateTimeInterface
            ? $value
            : \Illuminate\Support\Carbon::parse($value);

        return $date->format('j F Y');
    };
    $plain = fn ($html) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html))));
    $ratee = $upper($form->owner?->name ?? $form->orgUnit?->name);
    $reviewer = $upper($form->headReviewer?->name ?? $form->reviewed_by_name);
    $approver = $upper($form->vpReviewer?->name ?? $form->vp_reviewed_by_name);
    $signed = $when($form->submitted_at) ?: $when($form->created_at);
    $bands = [
        'strategic' => 'STRATEGIC PRIORITY',
        'core' => 'CORE FUNCTIONS:',
        'support' => 'SUPPORT FUNCTIONS:',
    ];
@endphp
<style>
    @page { margin: 0.75in 0.7in; }
    .ipcr { width: 100%; border-collapse: collapse; table-layout: fixed; font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #000; }
    .ipcr td { padding: 2px 3px; vertical-align: top; }
    .c { text-align: center; }
    .m { vertical-align: middle; }
    .b { font-weight: bold; }
    .bt { border-top: 0.6px solid #000; }
    .bb { border-bottom: 0.6px solid #000; }
    .bl { border-left: 0.6px solid #000; }
    .br { border-right: 0.6px solid #000; }
    .ba { border: 0.6px solid #000; }
    .mt { border-top: 1.2px solid #000; }
    .mb { border-bottom: 1.2px solid #000; }
    .ml { border-left: 1.2px solid #000; }
    .mr { border-right: 1.2px solid #000; }
    .ma { border: 1.2px solid #000; }
</style>
<table class="ipcr">
    <colgroup>
        <col width="15.13%"><col width="2.89%"><col width="2.89%"><col width="14.76%">
        <col width="2.89%"><col width="2.89%"><col width="9.01%"><col width="2.89%">
        <col width="2.89%"><col width="2.89%"><col width="2.89%"><col width="3.04%">
        <col width="34.92%">
    </colgroup>
    <tr>
        <td colspan="13" class="c b bt bl br" style="vertical-align: bottom;">INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW</td>
    </tr>
    <tr>
        <td colspan="13" class="bl br">
            I, {{ $ratee }}, of the {{ $org }} commit to deliver and agree to be rated on the attainment of the following targets in accordance with the indicated measures for the period {{ $periodLabel }}.
        </td>
    </tr>
    <tr>
        <td colspan="6" class="bl"></td>
        <td colspan="7" class="c br">{{ $ratee }}</td>
    </tr>
    <tr>
        <td colspan="6" class="bl"></td>
        <td colspan="7" class="c br">Ratee</td>
    </tr>
    <tr>
        <td colspan="6" class="bl bb"></td>
        <td colspan="7" class="c br bb">Date: {{ $signed }}</td>
    </tr>
    <tr>
        <td colspan="3" class="b bl bt">Reviewed by</td>
        <td class="b c ba">Date</td>
        <td colspan="7" class="b bt bb br">Approved by</td>
        <td colspan="2" class="b c bt bb br">Date</td>
    </tr>
    <tr>
        <td colspan="3" class="bl bt" style="height: 14px;"></td>
        <td rowspan="3" class="c m br bb">{{ $when($form->reviewed_at) }}</td>
        <td colspan="7" class="bl bt"></td>
        <td colspan="2" rowspan="3" class="c m bt br bb">{{ $when($form->vp_reviewed_at) }}</td>
    </tr>
    <tr>
        <td colspan="3" class="bl bb" style="height: 14px;"></td>
        <td colspan="7" class="bl"></td>
    </tr>
    <tr>
        <td colspan="3" class="c b ml mr mb">{{ $reviewer }}</td>
        <td colspan="7" class="c b bl br bb">{{ $approver }}</td>
    </tr>
    <tr>
        <td colspan="3" class="c b ml mr mt">Immediate Supervisor</td>
        <td class="br bt"></td>
        <td colspan="7" class="c b bt br">Head of Office</td>
        <td colspan="2" class="bt br"></td>
    </tr>
    <tr>
        <td colspan="13" class="ma" style="height: 12px;"></td>
    </tr>
    <tr>
        <td rowspan="2" class="c b m ma">MFO/PPA</td>
        <td colspan="3" class="c b bl bt">Success Indicator</td>
        <td colspan="3" class="c b bl bt br">Actual</td>
        <td colspan="4" class="c b mt mb br">Rating</td>
        <td colspan="2" rowspan="2" class="c b m ma">Remarks</td>
    </tr>
    <tr>
        <td colspan="3" class="c b bl bb">(Target + Measure)</td>
        <td colspan="3" class="c b bl br bb">Accomplishments</td>
        <td class="c b br bb">Q</td>
        <td class="c b bl br bb">E</td>
        <td class="c b bl br bb">T</td>
        <td class="c b bl bb">A</td>
    </tr>
    @foreach ($bands as $key => $band)
        @php $outputs = $form->outputs->where('section', $key); @endphp
        @continue($outputs->isEmpty())
        <tr>
            <td colspan="13" class="b bl br">{{ $band }}</td>
        </tr>
        @foreach (\App\Support\PcrOutline::numbered($outputs) as $output)
            @php
                $kept = $lines($output)->values();
                $number = $output->outline_number ?? '';
                $title = trim(($number !== '' ? $number.'. ' : '').$output->title);
            @endphp
            @forelse ($kept as $li => $line)
                @php
                    $rating = $line->ratings->firstWhere('rating_period_id', $period?->id);
                    $acc = $line->accomplishments->firstWhere('rating_period_id', $period?->id);
                @endphp
                <tr>
                    @if ($li === 0)
                        <td rowspan="{{ max($kept->count(), 1) }}" class="m ma">{{ $title }}</td>
                    @endif
                    <td colspan="3" class="ba">{{ $plain($line->description) }}</td>
                    <td colspan="3" class="mt mb mr">{{ $plain($acc?->actual_accomplishment) }}</td>
                    <td class="c ma">{{ $rating?->q }}</td>
                    <td class="c ma">{{ $rating?->e }}</td>
                    <td class="c ma">{{ $rating?->t }}</td>
                    <td class="c ma">{{ $rating?->a != null ? number_format((float) $rating->a, 2) : '' }}</td>
                    <td colspan="2" class="ma">{{ $plain($rating?->remarks) }}</td>
                </tr>
            @empty
                <tr>
                    <td class="m ma">{{ $title }}</td>
                    <td colspan="3" class="ba"></td>
                    <td colspan="3" class="mt mb mr"></td>
                    <td class="ma"></td>
                    <td class="ma"></td>
                    <td class="ma"></td>
                    <td class="ma"></td>
                    <td colspan="2" class="ma"></td>
                </tr>
            @endforelse
        @endforeach
    @endforeach
    <tr>
        <td class="ml mt mb">Final Average Rating</td>
        <td colspan="12" class="c mr mt mb">{{ $score($summary?->final_average) }}</td>
    </tr>
    <tr>
        <td class="c ml mt mb">Adjectival Rating:</td>
        <td colspan="12" class="c b mr mt mb">{{ $summary?->adjectival }}</td>
    </tr>
    <tr>
        <td colspan="13" class="ma">Comments and Recommendations for Development Purposes:</td>
    </tr>
    <tr>
        <td colspan="13" class="ma" style="height: 18px;">{{ $plain($form->header_note) }}</td>
    </tr>
    <tr>
        <td colspan="2" class="c b bl bt bb br">Discussed with</td>
        <td class="c b bl br bb">Date</td>
        <td colspan="3" class="c b bl bb br">Assessed by</td>
        <td class="c b bl br bb">Date</td>
        <td colspan="5" class="c b bl bb br">Final Rating by</td>
        <td class="c b bl br bb">Date</td>
    </tr>
    <tr>
        <td colspan="2" class="bl bt br" style="height: 14px;"></td>
        <td rowspan="3" class="c m bl br bt">{{ $when($form->submitted_at) }}</td>
        <td colspan="3" rowspan="2" class="c bl bt br">I certify that I discussed my assessment of the performance with the employee</td>
        <td class="bl br bt"></td>
        <td colspan="5" class="bl bt br"></td>
        <td class="bl br bt"></td>
    </tr>
    <tr>
        <td colspan="2" class="c bl br bb">{{ $ratee }}</td>
        <td class="c m bl br">{{ $when($form->reviewed_at) }}</td>
        <td colspan="5" class="c bl br bb" style="vertical-align: bottom;">{{ $approver }}</td>
        <td class="c m bl br">{{ $when($form->vp_reviewed_at ?: $form->rated_at) }}</td>
    </tr>
    <tr>
        <td colspan="2" class="c bl bt br bb">Employee</td>
        <td colspan="3" class="c bl br bt">Supervisor</td>
        <td class="bl br"></td>
        <td colspan="5" class="c bl br" style="vertical-align: bottom;">Head of Office</td>
        <td class="bl br"></td>
    </tr>
    <tr>
        <td colspan="13" class="ma">Legend&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Q – Quality&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;E – Efficiency&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;T – Timeliness&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;A – Average</td>
    </tr>
</table>
