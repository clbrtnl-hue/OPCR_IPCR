<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\CurrentOrganization;
use App\Support\Html;
use App\Support\PcrFormExcel;
use App\Support\PcrOutline;
use App\Support\PcrPrint;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\OrgUnit;
use App\Models\PcrAccomplishment;
use App\Models\PcrForm;
use App\Models\PcrTargetAssignment;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrPeriodSummary;
use App\Models\PcrRating;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\PcrWorkflow;
use App\Services\RatingScale;
use App\Services\WorkflowSettings;
use App\Models\RatingPeriod;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function summary(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'required|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        return response()->json($this->build((int) $data['school_year_id'], $data['rating_period_id'] ?? null));
    }

    private function build(int $yearId, ?int $periodId): array
    {
        $data = ['school_year_id' => $yearId];

        $forms = PcrForm::with([
            'orgUnit:id,name,code',
            'owner:id,name,image,position_title,role,org_unit_id',
            'owner.orgUnit:id,name,code',
        ])
            ->where('school_year_id', $data['school_year_id'])
            ->when(
                $periodId,
                fn ($q, $p) => $q->where(
                    fn ($w) => $w->whereNull('rating_period_id')->orWhere('rating_period_id', $p)
                )
            )
            ->get();

        $summaries = PcrPeriodSummary::whereIn('form_id', $forms->pluck('id'))
            ->when($periodId, fn ($q, $p) => $q->where('rating_period_id', $p))
            ->orderBy('rating_period_id')
            ->get()
            ->keyBy('form_id');

        $year = SchoolYear::find($data['school_year_id']);

        $byStatus = [];

        foreach (PcrForm::STATUSES as $status) {
            $byStatus[$status] = $forms->where('status', $status)->count();
        }

        $adjectival = [];

        foreach (RatingScale::bands() as $band) {
            $adjectival[$band['label']] = 0;
        }

        foreach ($summaries as $summary) {
            if ($summary->adjectival && isset($adjectival[$summary->adjectival])) {
                $adjectival[$summary->adjectival]++;
            }
        }

        $work = $this->commitmentsFor($forms, $periodId);

        $orgUnits = OrgUnit::orderBy('type')->orderBy('name')->get();
        $unitNames = $orgUnits->pluck('name', 'id');

        $units = $orgUnits->map(function ($unit) use ($forms, $summaries, $work, $unitNames) {
            $unitForms = $forms->where('org_unit_id', $unit->id);
            $scores    = $unitForms
                ->map(fn ($form) => $summaries[$form->id]->final_average ?? null)
                ->values()
                ->all();

            $average = RatingScale::mean($scores);
            $lines   = $work['leaves']->whereIn('form_id', $unitForms->pluck('id'));
            // Each person on the unit counts once, so one faculty member's four
            // lines at a single finish read as 25%, not as four separate votes
            // mixed with everyone else's lines.
            $personAverages = $lines
                ->groupBy(fn ($line) => $line['user_id'] ?: 'form-' . $line['form_id'])
                ->map(fn ($group) => (int) round($group->avg('progress_pct')));

            return [
                'id'            => $unit->id,
                'name'          => $unit->name,
                'code'          => $unit->code,
                'type'          => $unit->type,
                'parent_id'     => $unit->parent_id,
                'parent_name'   => $unit->parent_id ? ($unitNames[$unit->parent_id] ?? null) : null,
                'total_forms'   => $unitForms->count(),
                'submitted'     => $unitForms->where('status', '!=', 'draft')->count(),
                'rated'         => $unitForms->whereIn('status', ['rated', 'final'])->count(),
                'average'       => $average,
                'adjectival'    => RatingScale::adjectival($average),
                'commitments'   => $lines->count(),
                'progress_pct'  => $personAverages->count() ? (int) round($personAverages->avg()) : null,
                'overdue'       => $lines->where('is_overdue', true)->count(),
            ];
        });

        // A VP office often has no forms of its own. Its number is the work
        // filed on it (a secretary sitting there) averaged with each child
        // unit, so faculty progress climbs to the dean, the VP, and the President.
        $units = $this->rollProgressUp($units);

        $collegeScores = $summaries->pluck('final_average')->values()->all();
        $collegeMean   = RatingScale::mean($collegeScores);

        return [
            'totals' => [
                'forms'           => $forms->count(),
                'ipcr'            => $forms->where('type', 'ipcr')->count(),
                'opcr'            => $forms->where('type', 'opcr')->count(),
                'submitted'       => $forms->where('status', '!=', 'draft')->count(),
                'awaiting_rating' => $forms->where('status', 'qa_rating')->count(),
                'rated'           => $forms->whereIn('status', ['rated', 'final'])->count(),
                'college_average' => $collegeMean,
                'college_rating'  => RatingScale::adjectival($collegeMean),
                'returned'        => $forms->where('status', 'returned')->count(),
                'in_review'       => $forms->whereIn('status', ['head_review', 'vp_review'])->count(),
                'commitments'     => $work['leaves']->count(),
                'completed'       => $work['completed'],
                'in_flight'       => $work['leaves']->where('progress_status', 'ongoing')->count(),
                'not_started'     => $work['leaves']->where('progress_status', 'not_started')->count(),
                'progress_pct'    => $work['progress_pct'],
                'overdue'         => $work['overdue'],
                'due_soon'        => $work['due_soon'],
                'units_reporting' => $forms->pluck('org_unit_id')->filter()->unique()->count(),
                'units_total'     => OrgUnit::count(),
            ],
            'coverage'    => $this->coverageFor($forms),
            'by_status'   => $byStatus,
            'adjectival'  => $adjectival,
            'units'       => $units,
            'sections'    => $this->sectionsFor($work['leaves'], $summaries),
            'submissions' => $this->submissionsFor($forms, $year),
            'trend'       => $this->trendFor(),
            'at_risk'     => $this->atRiskFor($work['leaves']),
            'turnaround'  => $this->turnaroundFor($forms),
            'dimensions'  => $this->dimensionsFor($work['leaves'], $periodId),
            'evidence'    => $this->evidenceFor($work['leaves'], $periodId),
            'deadlines'   => $this->deadlinesFor($forms, $year, $periodId),
            'forms'       => $this->formRowsFor($forms, $summaries, $work['leaves'], $units),
            'commitments' => $work['leaves']->values()->all(),
            'people'      => $this->applySupervisorProgress(
                $this->peopleFor($forms, $summaries, $work['leaves']),
                $units,
                $orgUnits
            ),
            'heads'       => $this->headsFor($units, $orgUnits),
        ];
    }

    public function mySummary(Request $request)
    {
        $data = $request->validate([
            'school_year_id' => 'nullable|integer|exists:school_years,id',
        ]);

        $user = $request->user();
        $year = ($data['school_year_id'] ?? null)
            ? SchoolYear::find($data['school_year_id'])
            : SchoolYear::where('is_active', true)->first() ?? SchoolYear::orderByDesc('start_date')->first();

        if (! $year) {
            return response()->json(['year' => null]);
        }

        $forms = PcrForm::with(['orgUnit:id,name,code', 'owner:id,name', 'ratingPeriod:id,label,seq'])
            ->where('school_year_id', $year->id)
            ->get()
            ->filter(fn ($form) => PcrWorkflow::owns($user, $form) && ! $user->isAdmin())
            ->values();

        if ($user->isAdmin()) {
            $forms = PcrForm::with(['orgUnit:id,name,code', 'owner:id,name', 'ratingPeriod:id,label,seq'])
                ->where('school_year_id', $year->id)
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('org_unit_id', $user->org_unit_id))
                ->get();
        }

        $work      = $this->commitmentsFor($forms, null);
        $summaries = PcrPeriodSummary::whereIn('form_id', $forms->pluck('id'))
            ->orderByDesc('rating_period_id')
            ->get();

        $latest = $summaries->firstWhere('final_average', '!==', null);

        return response()->json([
            'year'  => ['id' => $year->id, 'label' => $year->label],
            'forms' => [
                'total'     => $forms->count(),
                'drafts'    => $forms->whereIn('status', ['draft'])->count(),
                'returned'  => $forms->where('status', 'returned')->count(),
                'submitted' => $forms->where('status', '!=', 'draft')->count(),
                'in_review' => $forms->whereIn('status', ['head_review', 'vp_review', 'qa_approval'])->count(),
                'with_qa'   => $forms->where('status', 'qa_rating')->count(),
                'rated'     => $forms->whereIn('status', ['rated', 'final'])->count(),
            ],
            'commitments' => [
                'total'        => $work['leaves']->count(),
                'completed'    => $work['completed'],
                'in_flight'    => $work['leaves']->where('progress_status', 'ongoing')->count(),
                'not_started'  => $work['leaves']->where('progress_status', 'not_started')->count(),
                'progress_pct' => $work['progress_pct'],
                'overdue'      => $work['overdue'],
                'due_soon'     => $work['due_soon'],
            ],
            'latest' => $latest ? [
                'average'    => (float) $latest->final_average,
                'adjectival' => $latest->adjectival,
            ] : null,
            'at_risk' => $this->atRiskFor($work['leaves']),
        ]);
    }

    public function summaryExport(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'required|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
            'table'            => ['required', Rule::in(['units', 'forms', 'commitments', 'ratings', 'people', 'heads'])],
            'format'           => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);

        $payload = $this->build((int) $data['school_year_id'], $data['rating_period_id'] ?? null);
        $sheet   = $this->sheetFor($data['table'], $payload);
        $year    = SchoolYear::find($data['school_year_id']);
        $period  = ($data['rating_period_id'] ?? null) ? RatingPeriod::find($data['rating_period_id']) : null;

        $format = $data['format'] ?? 'csv';

        $name = collect([
            'OCC PMS', $sheet['title'], $year?->label, $period?->label,
        ])->filter()->implode(' - ') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->xlsxDownload($sheet, $name);
        }

        return response()->streamDownload(function () use ($sheet) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $sheet['headers'], ',', '"', '\\');

            foreach ($sheet['rows'] as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsxDownload(array $sheet, string $name)
    {
        $spreadsheet = new Spreadsheet();
        $worksheet   = $spreadsheet->getActiveSheet();

        $worksheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheet['title']), 0, 31));

        $headers = array_values($sheet['headers']);
        $rows    = collect($sheet['rows'])->map(fn ($row) => array_values((array) $row))->all();

        foreach ($headers as $column => $heading) {
            $worksheet->setCellValueExplicit(
                [$column + 1, 1],
                (string) $heading,
                DataType::TYPE_STRING
            );
        }

        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $coordinate = [$column + 1, $index + 2];

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value) || preg_match('/^-?\d+(\.\d+)?$/', (string) $value)) {
                    $worksheet->setCellValueExplicit($coordinate, (float) $value, DataType::TYPE_NUMERIC);

                    continue;
                }

                $worksheet->setCellValueExplicit($coordinate, Html::toText((string) $value), DataType::TYPE_STRING);
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(count($headers), 1));
        $lastRow    = count($rows) + 1;

        $worksheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $worksheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F2F5');
        $worksheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(true);
        $worksheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $worksheet->freezePane('A2');

        foreach (range(1, count($headers)) as $column) {
            $worksheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer, $spreadsheet) {
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function summaryPdf(Request $request)
    {
        $data = $request->validate([
            'school_year_id'   => 'required|integer|exists:school_years,id',
            'rating_period_id' => 'nullable|integer|exists:rating_periods,id',
        ]);

        $payload = $this->build((int) $data['school_year_id'], $data['rating_period_id'] ?? null);
        $year    = SchoolYear::find($data['school_year_id']);
        $period  = ($data['rating_period_id'] ?? null) ? RatingPeriod::find($data['rating_period_id']) : null;

        $pdf = Pdf::loadView('pdf.report', [
            'payload'      => $payload,
            'year'         => $year,
            'period'       => $period,
            'organization' => app(CurrentOrganization::class)->get(),
            'preparedBy'   => $request->user()?->name,
            'bands'        => RatingScale::bands(),
        ])->setPaper('legal', 'landscape');

        $name = 'OCC PMS Report - ' . ($year?->label ?? '') . ($period ? ' - ' . $period->label : '');

        return $pdf->stream(trim($name) . '.pdf');
    }

    private function sheetFor(string $table, array $payload): array
    {
        $label = fn (?string $status) => $status ? ucfirst(str_replace('_', ' ', $status)) : '';
        $score = fn ($value) => $value === null ? '' : number_format((float) $value, 2);

        if ($table === 'units') {
            return [
                'title'   => 'By unit',
                'headers' => ['Unit', 'Code', 'Forms', 'Submitted', 'Rated', 'Commitments', 'Progress %', 'Overdue', 'Average', 'Adjectival'],
                'rows'    => collect($payload['units'])->map(fn ($unit) => [
                    $unit['name'], $unit['code'], $unit['total_forms'], $unit['submitted'], $unit['rated'],
                    $unit['commitments'], $unit['progress_pct'] ?? '', $unit['overdue'],
                    $score($unit['average']), $unit['adjectival'] ?? 'Not yet rated',
                ])->all(),
            ];
        }

        if ($table === 'commitments') {
            return [
                'title'   => 'Commitments',
                'headers' => ['Owner', 'Unit', 'Section', 'Success indicator', 'Target date', 'Progress %', 'Status', 'Days late'],
                'rows'    => collect($payload['commitments'])->map(fn ($line) => [
                    $line['owner'], $line['unit'], $label($line['section']), $line['description'],
                    $line['target_date'], $line['progress_pct'], $label($line['progress_status']),
                    $line['days_late'] ?: '',
                ])->all(),
            ];
        }

        if ($table === 'people') {
            return [
                'title'   => 'By individual',
                'headers' => ['Name', 'Position', 'Unit', 'Forms', 'Submitted', 'Commitments', 'Completed', 'Progress %', 'Overdue', 'Days late', 'Average', 'Adjectival'],
                'rows'    => collect($payload['people'])->map(fn ($person) => [
                    $person['name'], $person['position'], $person['unit'], $person['forms'], $person['submitted'],
                    $person['commitments'], $person['completed'], $person['progress_pct'] ?? '',
                    $person['overdue'], $person['days_late'] ?: '',
                    $score($person['average']), $person['adjectival'] ?? 'Not yet rated',
                ])->all(),
            ];
        }

        if ($table === 'heads') {
            return [
                'title'   => 'By head',
                'headers' => ['Head', 'Position', 'Unit', 'Forms', 'Submitted', 'Rated', 'Commitments', 'Progress %', 'Overdue', 'Average', 'Adjectival'],
                'rows'    => collect($payload['heads'])->map(fn ($head) => [
                    $head['head'], $head['position'], $head['unit'], $head['forms'], $head['submitted'], $head['rated'],
                    $head['commitments'], $head['progress_pct'] ?? '', $head['overdue'],
                    $score($head['average']), $head['adjectival'] ?? 'Not yet rated',
                ])->all(),
            ];
        }

        if ($table === 'ratings') {
            return [
                'title'   => 'Ratings',
                'headers' => ['Type', 'Ratee', 'Unit', 'Strategic', 'Core', 'Support', 'Final', 'Adjectival', 'Rated on'],
                'rows'    => collect($payload['forms'])
                    ->filter(fn ($form) => $form['average'] !== null)
                    ->map(fn ($form) => [
                        $form['type'], $form['owner'], $form['unit'],
                        $score($form['strategic']), $score($form['core']), $score($form['support']),
                        $score($form['average']), $form['adjectival'], $form['rated_at'],
                    ])->values()->all(),
            ];
        }

        return [
            'title'   => 'Forms',
            'headers' => ['Type', 'Owner', 'Unit', 'Status', 'Submitted', 'Commitments', 'Progress %', 'Overdue', 'Final', 'Adjectival'],
            'rows'    => collect($payload['forms'])->map(fn ($form) => [
                $form['type'], $form['owner'], $form['unit'], $label($form['status']), $form['submitted_at'],
                $form['commitments'], $form['progress_pct'] ?? '', $form['overdue'],
                $score($form['average']), $form['adjectival'] ?? 'Not yet rated',
            ])->all(),
        ];
    }

    private function formRowsFor($forms, $summaries, $leaves, $units): array
    {
        $president = User::with('orgUnit:id,name,code')->where('role', 'president')->first();
        $rolled    = collect($units)->keyBy('id');

        return $forms
            ->sortBy(fn ($form) => [$form->orgUnit?->name, $form->owner?->name])
            ->map(function ($form) use ($summaries, $leaves, $president, $rolled) {
                $summary = $summaries[$form->id] ?? null;
                $lines   = $leaves->where('form_id', $form->id);
                $isOpcr  = $form->type === 'opcr';

                return [
                    'id'           => $form->id,
                    'type'         => strtoupper($form->type),
                    'owner'        => $isOpcr
                        ? ($president?->name ?? $form->orgUnit?->name)
                        : ($form->owner?->name ?? $form->orgUnit?->name),
                    'unit'         => $isOpcr
                        ? ($president?->orgUnit?->name ?? $form->orgUnit?->name)
                        : $form->orgUnit?->name,
                    'unit_code'    => $isOpcr
                        ? ($president?->orgUnit?->code ?? $form->orgUnit?->code)
                        : $form->orgUnit?->code,
                    'status'       => $form->status,
                    'submitted_at' => $form->submitted_at?->toDateString(),
                    'rated_at'     => $form->rated_at?->toDateString(),
                    'commitments'  => $lines->count(),
                    'progress_pct' => $isOpcr
                        ? ($rolled[$form->org_unit_id]['progress_pct'] ?? null)
                        : ($lines->count() ? (int) round($lines->avg('progress_pct')) : null),
                    'overdue'      => $lines->where('is_overdue', true)->count(),
                    'strategic'    => $summary?->strategic_average !== null ? (float) $summary->strategic_average : null,
                    'core'         => $summary?->core_average !== null ? (float) $summary->core_average : null,
                    'support'      => $summary?->support_average !== null ? (float) $summary->support_average : null,
                    'average'      => $summary?->final_average !== null ? (float) $summary->final_average : null,
                    'adjectival'   => $summary?->adjectival,
                ];
            })
            ->values()
            ->all();
    }

    private function peopleFor($forms, $summaries, $leaves): array
    {
        return $forms
            ->filter(fn ($form) => $form->user_id && $form->owner && $form->owner->role !== 'president')
            ->groupBy('user_id')
            ->map(function ($theirs) use ($summaries, $leaves) {
                $owner  = $theirs->first()->owner;
                $lines  = $leaves->whereIn('form_id', $theirs->pluck('id'));
                $scores = $theirs->map(fn ($form) => $summaries[$form->id]->final_average ?? null)
                    ->values()->all();

                $average = RatingScale::mean($scores);
                $late    = $lines->where('is_overdue', true);

                return [
                    'id'           => $owner->id,
                    'name'         => $owner->name,
                    'position'     => $owner->position_title,
                    'role'         => $owner->role,
                    'unit'         => $owner->orgUnit?->name ?? $theirs->first()->orgUnit?->name,
                    'unit_code'    => $owner->orgUnit?->code ?? $theirs->first()->orgUnit?->code,
                    'forms'        => $theirs->count(),
                    'submitted'    => $theirs->where('status', '!=', 'draft')->count(),
                    'commitments'  => $lines->count(),
                    'completed'    => $lines->where('progress_status', 'completed')->count(),
                    'progress_pct' => $lines->count() ? (int) round($lines->avg('progress_pct')) : null,
                    'overdue'      => $late->count(),
                    'days_late'    => (int) $late->sum('days_late'),
                    'average'      => $average,
                    'adjectival'   => RatingScale::adjectival($average),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * A dean's number is the office they head, already rolled up through the
     * faculty on it. A VP's number is the office they review, which already
     * includes each college. Someone who is neither keeps the average of
     * their own lines.
     */
    private function applySupervisorProgress(array $people, $units, $orgUnits): array
    {
        $rolled = collect($units)->keyBy('id');
        $byId   = $orgUnits->keyBy('id');

        return array_map(function (array $person) use ($rolled, $orgUnits, $byId) {
            $overseen = $orgUnits->filter(
                fn ($unit) => (int) $unit->head_user_id === (int) $person['id']
                    || (int) $unit->vp_user_id === (int) $person['id']
            );

            if ($overseen->isEmpty()) {
                return $person;
            }

            $ids = $overseen->pluck('id')->map(fn ($id) => (int) $id)->all();

            $tops = $overseen->filter(function ($unit) use ($ids, $byId) {
                $parent = $unit->parent_id ? (int) $unit->parent_id : null;

                while ($parent && $byId->has($parent)) {
                    if (in_array($parent, $ids, true)) {
                        return false;
                    }

                    $parent = $byId[$parent]->parent_id ? (int) $byId[$parent]->parent_id : null;
                }

                return true;
            });

            $pcts = $tops
                ->map(fn ($unit) => $rolled[$unit->id]['progress_pct'] ?? null)
                ->filter(fn ($pct) => $pct !== null)
                ->values();

            if ($pcts->isNotEmpty()) {
                $person['progress_pct'] = (int) round($pcts->avg());
            }

            return $person;
        }, $people);
    }

    /**
     * Each child unit counts once, already rolled up, so a line is not averaged
     * again at every level above it. A unit with no lines and no working
     * children stays blank rather than reading as zero.
     */
    private function rollProgressUp($units)
    {
        $byId = $units->keyBy('id');
        $childrenOf = [];

        foreach ($units as $unit) {
            $parentId = $unit['parent_id'] ?? null;

            if ($parentId && (int) $parentId !== (int) $unit['id'] && $byId->has($parentId)) {
                $childrenOf[$parentId][] = $unit['id'];
            }
        }

        $rolled = [];

        $visit = function (int $id, array $stack) use (&$visit, &$rolled, $byId, $childrenOf) {
            if (array_key_exists($id, $rolled)) {
                return $rolled[$id];
            }

            if (isset($stack[$id])) {
                return $byId[$id]['progress_pct'];
            }

            $stack[$id] = true;
            $parts = [];

            if ($byId[$id]['progress_pct'] !== null) {
                $parts[] = $byId[$id]['progress_pct'];
            }

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $child = $visit((int) $childId, $stack);

                if ($child !== null) {
                    $parts[] = $child;
                }
            }

            return $rolled[$id] = $parts === [] ? null : (int) round(array_sum($parts) / count($parts));
        };

        return $units->map(function (array $unit) use ($visit) {
            $unit['progress_pct'] = $visit((int) $unit['id'], []);

            return $unit;
        })->values();
    }

    private function headsFor($units, $orgUnits): array
    {
        $heads = $orgUnits->whereNotNull('head_user_id');

        $people = User::whereIn('id', $heads->pluck('head_user_id')->unique())
            ->get(['id', 'name', 'position_title'])
            ->keyBy('id');

        $byUnit = collect($units)->keyBy('id');

        return $heads
            ->map(function ($unit) use ($byUnit, $people) {
                $row  = $byUnit[$unit->id] ?? null;
                $head = $people[$unit->head_user_id] ?? null;

                return [
                    'id'           => $unit->id,
                    'head'         => $head?->name,
                    'position'     => $head?->position_title,
                    'unit'         => $unit->name,
                    'unit_code'    => $unit->code,
                    'forms'        => $row['total_forms'] ?? 0,
                    'submitted'    => $row['submitted'] ?? 0,
                    'rated'        => $row['rated'] ?? 0,
                    'commitments'  => $row['commitments'] ?? 0,
                    'progress_pct' => $row['progress_pct'] ?? null,
                    'overdue'      => $row['overdue'] ?? 0,
                    'average'      => $row['average'] ?? null,
                    'adjectival'   => $row['adjectival'] ?? null,
                ];
            })
            ->sortBy('unit')
            ->values()
            ->all();
    }

    private function commitmentsFor($forms, ?int $periodId): array
    {
        $outputs = PcrOutput::whereIn('form_id', $forms->pluck('id'))->get(['id', 'form_id', 'section']);

        $indicators = PcrIndicator::whereIn('output_id', $outputs->pluck('id'))
            ->when(
                $periodId,
                fn ($q, $p) => $q->where(
                    fn ($w) => $w->whereNull('rating_period_id')->orWhere('rating_period_id', $p)
                )
            )
            ->get(['id', 'output_id', 'description', 'target_date', 'progress_pct', 'progress_status', 'parent_indicator_id']);

        $parents = PcrIndicator::whereIn('parent_indicator_id', $indicators->pluck('id'))
            ->distinct()
            ->pluck('parent_indicator_id')
            ->flip();

        $assigned = PcrTargetAssignment::whereIn('indicator_id', $indicators->pluck('id'))
            ->pluck('indicator_id')
            ->flip();

        $outputById = $outputs->keyBy('id');
        $formById   = $forms->keyBy('id');
        $today      = now()->startOfDay();

        $leaves = $indicators
            ->reject(fn ($line) => $parents->has($line->id) || $assigned->has($line->id))
            ->map(function ($line) use ($outputById, $formById, $today) {
                $output = $outputById[$line->output_id] ?? null;
                $form   = $output ? ($formById[$output->form_id] ?? null) : null;
                $due    = $line->target_date;
                $done   = $line->progress_status === 'completed';

                return [
                    'id'             => $line->id,
                    'form_id'        => $form?->id,
                    'user_id'        => $form?->user_id,
                    'unit_id'        => $form?->org_unit_id,
                    'section'        => $output?->section,
                    'description'    => Html::toText($line->description),
                    'owner'          => $form?->owner?->name ?? $form?->orgUnit?->name,
                    'unit'           => $form?->orgUnit?->name,
                    'target_date'    => $due?->toDateString(),
                    'progress_pct'   => (int) ($line->progress_pct ?? 0),
                    'progress_status'=> $line->progress_status,
                    'is_overdue'     => (bool) ($due && ! $done && $due->startOfDay()->lt($today)),
                    'days_late'      => $due && ! $done && $due->startOfDay()->lt($today)
                        ? (int) $due->startOfDay()->diffInDays($today)
                        : 0,
                    'due_soon'       => (bool) ($due && ! $done && $due->startOfDay()->gte($today)
                        && $due->startOfDay()->lte($today->copy()->addDays(7))),
                ];
            })
            ->values();

        return [
            'leaves'       => $leaves,
            'completed'    => $leaves->where('progress_status', 'completed')->count(),
            'progress_pct' => $leaves->count() ? (int) round($leaves->avg('progress_pct')) : null,
            'overdue'      => $leaves->where('is_overdue', true)->count(),
            'due_soon'     => $leaves->where('due_soon', true)->count(),
        ];
    }

    private const TURNAROUND_STAGES = [
        ['key' => 'submitted_to_head', 'label' => 'Submitted → Head', 'ends' => 'reviewed_at',     'reviewer' => 'head_reviewer_id', 'stage' => 'head'],
        ['key' => 'head_to_vp',        'label' => 'Head → VP',        'ends' => 'vp_reviewed_at',  'reviewer' => 'vp_reviewer_id',   'stage' => 'vp'],
        ['key' => 'vp_to_qa',          'label' => 'VP → QA',          'ends' => 'rated_at',        'reviewer' => null,               'stage' => 'qa'],
    ];

    private const TURNAROUND_LADDER = ['submitted_at', 'reviewed_at', 'vp_reviewed_at', 'rated_at'];

    private const WAITING_STATUSES = [
        'head_review' => 'With Head',
        'vp_review'   => 'With VP',
        'qa_rating'   => 'With QA',
    ];

    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));

        if (! $values) {
            return null;
        }

        sort($values);
        $count  = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return round((float) $median, 1);
    }

    private function spanStart(PcrForm $form, string $endsAt): ?\Illuminate\Support\Carbon
    {
        $ladder = array_slice(self::TURNAROUND_LADDER, 0, array_search($endsAt, self::TURNAROUND_LADDER, true));

        foreach (array_reverse($ladder) as $column) {
            if ($form->{$column}) {
                return $form->{$column};
            }
        }

        return null;
    }

    private function gapDays($from, $to): ?float
    {
        if (! $from || ! $to) {
            return null;
        }

        return round(max(0, $from->floatDiffInDays($to)), 1);
    }

    private function turnaroundFor($forms): array
    {
        $stages    = [];
        $reviewers = [];
        $slowest   = null;

        foreach (self::TURNAROUND_STAGES as $stage) {
            $gaps    = [];
            $skipped = 0;

            foreach ($forms as $form) {
                if (! $form->{$stage['ends']}) {
                    continue;
                }

                $held = $stage['reviewer'] ? $form->{$stage['reviewer']} : true;

                if (! $held) {
                    $skipped++;

                    continue;
                }

                $gap = $this->gapDays($this->spanStart($form, $stage['ends']), $form->{$stage['ends']});

                if ($gap === null) {
                    continue;
                }

                $gaps[] = $gap;

                if ($stage['reviewer']) {
                    $reviewers[$stage['stage']][$form->{$stage['reviewer']}]['gaps'][] = $gap;
                }
            }

            $median = $this->median($gaps);

            $stages[] = [
                'key'         => $stage['key'],
                'label'       => $stage['label'],
                'median_days' => $median,
                'mean_days'   => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
                'forms'       => count($gaps),
                'skipped'     => $skipped,
            ];

            if ($median !== null && ($slowest === null || $median > $slowest['median'])) {
                $slowest = ['key' => $stage['key'], 'median' => $median];
            }
        }

        $endToEnd = $forms
            ->filter(fn ($form) => $form->submitted_at && $form->rated_at)
            ->map(fn ($form) => $this->gapDays($form->submitted_at, $form->rated_at))
            ->values()
            ->all();

        $stages[] = [
            'key'         => 'end_to_end',
            'label'       => 'Submitted → Rated',
            'median_days' => $this->median($endToEnd),
            'mean_days'   => $endToEnd ? round(array_sum($endToEnd) / count($endToEnd), 1) : null,
            'forms'       => count($endToEnd),
            'skipped'     => 0,
        ];

        return [
            'stages'        => $stages,
            'slowest_stage' => $slowest['key'] ?? null,
            'reviewers'     => $this->reviewerRowsFor($forms, $reviewers),
            'waiting'       => $this->waitingFor($forms),
        ];
    }

    private function reviewerRowsFor($forms, array $reviewers): array
    {
        $pending = [];

        foreach ($forms as $form) {
            $column = match ($form->status) {
                'head_review' => 'head_reviewer_id',
                'vp_review'   => 'vp_reviewer_id',
                default       => null,
            };

            if (! $column || ! $form->{$column}) {
                continue;
            }

            $stage = $column === 'head_reviewer_id' ? 'head' : 'vp';
            $age   = $this->gapDays($this->spanStart($form, $stage === 'head' ? 'reviewed_at' : 'vp_reviewed_at'), now());

            $pending[$stage][$form->{$column}][] = $age ?? 0;
        }

        $snapshots = [];

        foreach ($forms as $form) {
            if ($form->head_reviewer_id && $form->reviewed_by === $form->head_reviewer_id) {
                $snapshots[$form->head_reviewer_id] = $form->reviewed_by_name;
            }

            if ($form->vp_reviewer_id && $form->vp_reviewed_by === $form->vp_reviewer_id) {
                $snapshots[$form->vp_reviewer_id] = $form->vp_reviewed_by_name;
            }
        }

        $ids = collect(['head', 'vp'])
            ->flatMap(fn ($stage) => array_merge(
                array_keys($reviewers[$stage] ?? []),
                array_keys($pending[$stage] ?? [])
            ))
            ->unique();

        $names = User::whereIn('id', $ids)->pluck('name', 'id');
        $rows  = [];

        foreach (['head', 'vp'] as $stage) {
            foreach (array_keys(($reviewers[$stage] ?? []) + ($pending[$stage] ?? [])) as $id) {
                $gaps  = $reviewers[$stage][$id]['gaps'] ?? [];
                $waits = $pending[$stage][$id] ?? [];

                $rows[] = [
                    'id'                  => (int) $id,
                    'name'                => $snapshots[$id] ?? $names[$id] ?? null,
                    'stage'               => $stage,
                    'forms'               => count($gaps),
                    'median_days'         => $this->median($gaps),
                    'mean_days'           => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
                    'pending'             => count($waits),
                    'oldest_pending_days' => $waits ? round(max($waits), 1) : null,
                ];
            }
        }

        return collect($rows)
            ->sortByDesc(fn ($row) => $row['median_days'] ?? -1)
            ->values()
            ->all();
    }

    private function waitingFor($forms): array
    {
        $rows = [];

        foreach (self::WAITING_STATUSES as $status => $label) {
            $ends = match ($status) {
                'head_review' => 'reviewed_at',
                'vp_review'   => 'vp_reviewed_at',
                default       => 'rated_at',
            };

            $ages = $forms
                ->where('status', $status)
                ->map(fn ($form) => $this->gapDays($this->spanStart($form, $ends), now()))
                ->filter(fn ($age) => $age !== null)
                ->values()
                ->all();

            $rows[] = [
                'status'           => $status,
                'label'            => $label,
                'forms'            => $forms->where('status', $status)->count(),
                'median_age_days'  => $this->median($ages),
                'oldest_days'      => $ages ? round(max($ages), 1) : null,
            ];
        }

        return $rows;
    }

    private function dimensionsFor($leaves, ?int $periodId): array
    {
        $rules   = app(WorkflowSettings::class);
        $keys    = array_values(array_intersect($rules->ratingDimensions(), PcrRating::DIMENSIONS));
        [$min, $max] = $rules->ratingBounds();

        $empty = [
            'keys'          => $keys,
            'bounds'        => ['min' => $min, 'max' => $max],
            'period_id'     => $periodId,
            'rated_lines'   => 0,
            'unrated_lines' => $leaves->count(),
            'college'       => [],
            'units'         => [],
        ];

        if (! $keys || $leaves->isEmpty()) {
            return $empty;
        }

        $ratings = PcrRating::whereIn('indicator_id', $leaves->pluck('id'))
            ->when($periodId, fn ($q, $p) => $q->where('rating_period_id', $p))
            ->get(array_merge(['indicator_id'], $keys));

        if ($ratings->isEmpty()) {
            return $empty;
        }

        $unitOf = $leaves->pluck('unit_id', 'id');

        $college = collect($keys)->map(fn ($key) => [
            'key'   => $key,
            'label' => RatingScale::DIMENSION_LABELS[$key] ?? strtoupper($key),
            'mean'  => RatingScale::mean($ratings->pluck($key)->all()),
            'rated' => $ratings->whereNotNull($key)->count(),
        ])->all();

        $units = $ratings
            ->groupBy(fn ($rating) => $unitOf[$rating->indicator_id] ?? null)
            ->filter(fn ($rows, $unitId) => $unitId !== '' && $unitId !== null)
            ->map(function ($rows, $unitId) use ($keys, $leaves) {
                $scores = collect($keys)
                    ->mapWithKeys(fn ($key) => [$key => RatingScale::mean($rows->pluck($key)->all())])
                    ->all();

                $present = array_filter($scores, fn ($score) => $score !== null);

                return [
                    'id'      => (int) $unitId,
                    'name'    => $leaves->firstWhere('unit_id', (int) $unitId)['unit'] ?? null,
                    'rated'   => $rows->count(),
                    'scores'  => $scores,
                    'average' => RatingScale::mean(array_values($scores)),
                    'spread'  => $present ? round(max($present) - min($present), 2) : null,
                    'weakest' => $present ? array_search(min($present), $present, true) : null,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'keys'          => $keys,
            'bounds'        => ['min' => $min, 'max' => $max],
            'period_id'     => $periodId,
            'rated_lines'   => $ratings->count(),
            'unrated_lines' => max($leaves->count() - $ratings->pluck('indicator_id')->unique()->count(), 0),
            'college'       => $college,
            'units'         => $units,
        ];
    }

    private function evidenceFor($leaves, ?int $periodId): array
    {
        $done = $leaves->where('progress_status', 'completed');

        $awaitingFile = $this->awaitingFileCount($leaves, $periodId);

        $blank = [
            'completed' => 0, 'compliant' => 0, 'pct' => null,
            'missing_evidence' => 0, 'blank_narrative' => 0, 'no_accomplishment' => 0,
            'awaiting_file' => $awaitingFile,
            'attachments' => 0, 'units' => [], 'gaps' => [],
        ];

        if ($done->isEmpty()) {
            return $blank;
        }

        $records = PcrAccomplishment::whereIn('indicator_id', $done->pluck('id'))
            ->when($periodId, fn ($q, $p) => $q->where('rating_period_id', $p))
            ->withCount('attachments')
            ->get(['id', 'indicator_id', 'actual_accomplishment'])
            ->keyBy('indicator_id');

        $rows = $done->map(function ($line) use ($records) {
            $record = $records[$line['id']] ?? null;

            return [
                'id'                => $line['id'],
                'unit_id'           => $line['unit_id'],
                'unit'              => $line['unit'],
                'owner'             => $line['owner'],
                'description'       => $line['description'],
                'attachments'       => (int) ($record?->attachments_count ?? 0),
                'missing_evidence'  => ! $record || (int) $record->attachments_count === 0,
                'blank_narrative'   => ! $record || Html::isBlank($record->actual_accomplishment),
                'no_accomplishment' => ! $record,
            ];
        })->values();

        $compliant = $rows->reject(fn ($row) => $row['missing_evidence'] || $row['blank_narrative'])->count();

        $units = $rows
            ->filter(fn ($row) => $row['unit_id'])
            ->groupBy('unit_id')
            ->map(function ($group, $unitId) {
                $ok = $group->reject(fn ($row) => $row['missing_evidence'] || $row['blank_narrative'])->count();

                return [
                    'id'               => (int) $unitId,
                    'name'             => $group->first()['unit'],
                    'completed'        => $group->count(),
                    'compliant'        => $ok,
                    'pct'              => (int) round($ok / $group->count() * 100),
                    'missing_evidence' => $group->where('missing_evidence', true)->count(),
                    'blank_narrative'  => $group->where('blank_narrative', true)->count(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'completed'         => $rows->count(),
            'compliant'         => $compliant,
            'pct'               => (int) round($compliant / $rows->count() * 100),
            'missing_evidence'  => $rows->where('missing_evidence', true)->count(),
            'blank_narrative'   => $rows->where('blank_narrative', true)->count(),
            'no_accomplishment' => $rows->where('no_accomplishment', true)->count(),
            'awaiting_file'     => $awaitingFile,
            'attachments'       => (int) $rows->sum('attachments'),
            'units'             => $units,
            'gaps'              => $rows
                ->filter(fn ($row) => $row['missing_evidence'] || $row['blank_narrative'])
                ->take(8)
                ->values()
                ->all(),
        ];
    }

    /**
     * A written accomplishment with no file is not progress yet. Count the
     * lines, not only the ones already marked completed.
     */
    private function awaitingFileCount($leaves, ?int $periodId): int
    {
        if ($leaves->isEmpty()) {
            return 0;
        }

        $records = PcrAccomplishment::whereIn('indicator_id', $leaves->pluck('id'))
            ->when($periodId, fn ($q, $p) => $q->where('rating_period_id', $p))
            ->withCount('attachments')
            ->get(['id', 'indicator_id', 'actual_accomplishment'])
            ->groupBy('indicator_id');

        return $leaves->filter(function ($line) use ($records) {
            return ($records[$line['id']] ?? collect())->contains(
                fn ($record) => ! Html::isBlank($record->actual_accomplishment)
                    && (int) $record->attachments_count === 0
            );
        })->count();
    }

    private function deadlinesFor($forms, ?SchoolYear $year, ?int $periodId): array
    {
        $blank = ['active' => null, 'periods' => [], 'late' => null, 'late_forms' => []];

        if (! $year) {
            return $blank;
        }

        $periods = RatingPeriod::where('school_year_id', $year->id)->orderBy('seq')->get();

        if ($periods->isEmpty()) {
            return $blank;
        }

        $today = now()->startOfDay();

        $shape = fn ($period) => [
            'id'        => $period->id,
            'label'     => $period->label,
            'seq'       => (int) $period->seq,
            'opens_at'  => $period->opens_at?->toDateString(),
            'closes_at' => $period->closes_at?->toDateString(),
            'status'    => $period->status,
            'is_active' => (bool) $period->is_active,
            'month'     => $period->closes_at?->format('Y-m'),
            'days_left' => $period->closes_at ? (int) round($today->diffInDays($period->closes_at->endOfDay(), false)) : null,
            'state'     => $this->deadlineState($period, $today),
        ];

        $active = ($periodId ? $periods->firstWhere('id', $periodId) : null)
            ?? $periods->firstWhere('is_active', true)
            ?? $periods->first(fn ($p) => $p->opens_at && $p->closes_at
                && $today->betweenIncluded($p->opens_at, $p->closes_at))
            ?? $periods->first(fn ($p) => $p->closes_at && $p->closes_at->gte($today))
            ?? $periods->last();

        $closesBy = $periods->whereNotNull('closes_at')->keyBy('id');
        $late     = [];
        $judged   = 0;
        $unjudged = 0;

        foreach ($forms as $form) {
            $period = $form->rating_period_id ? ($closesBy[$form->rating_period_id] ?? null) : null;

            if (! $period || ! $form->submitted_at) {
                $unjudged++;

                continue;
            }

            $judged++;
            $deadline = $period->closes_at->copy()->endOfDay();

            if ($form->submitted_at->lte($deadline)) {
                continue;
            }

            $late[] = [
                'id'           => $form->id,
                'owner'        => $form->owner?->name ?? $form->orgUnit?->name,
                'unit'         => $form->orgUnit?->name,
                'period'       => $period->label,
                'submitted_at' => $form->submitted_at->toDateString(),
                'closes_at'    => $period->closes_at->toDateString(),
                'days_late'    => (int) ceil($deadline->floatDiffInDays($form->submitted_at)),
            ];
        }

        usort($late, fn ($a, $b) => $b['days_late'] <=> $a['days_late']);

        return [
            'active'  => $active ? $shape($active) : null,
            'periods' => $periods->map($shape)->all(),
            'late'    => [
                'forms'      => count($late),
                'judged'     => $judged,
                'unjudged'   => $unjudged,
                'pct'        => $judged ? (int) round(count($late) / $judged * 100) : null,
                'worst_days' => $late ? $late[0]['days_late'] : 0,
            ],
            'late_forms' => array_slice($late, 0, 8),
        ];
    }

    private function deadlineState($period, $today): string
    {
        if (! $period->closes_at) {
            return $period->is_active ? 'open' : 'upcoming';
        }

        if ($period->closes_at->endOfDay()->lt($today)) {
            return 'closed';
        }

        if ($period->opens_at && $period->opens_at->gt($today)) {
            return 'upcoming';
        }

        return $today->diffInDays($period->closes_at, false) <= 7 ? 'closing' : 'open';
    }

    private function coverageFor($forms): array
    {
        $expected = User::where('status', 'active')
            ->whereIn('role', ['employee', 'program_head', 'vp'])
            ->pluck('id');

        $filed = $forms->where('type', 'ipcr')
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->intersect($expected)
            ->count();

        return [
            'expected' => $expected->count(),
            'filed'    => $filed,
            'missing'  => max($expected->count() - $filed, 0),
            'pct'      => $expected->count() ? (int) round($filed / $expected->count() * 100) : null,
        ];
    }

    private function sectionsFor($leaves, $summaries): array
    {
        $columns = [
            'strategic' => 'strategic_average',
            'core'      => 'core_average',
            'support'   => 'support_average',
        ];

        $sections = [];

        foreach ($columns as $section => $column) {
            $lines  = $leaves->where('section', $section);
            $scores = $summaries->pluck($column)->values()->all();

            $sections[] = [
                'section'      => $section,
                'commitments'  => $lines->count(),
                'progress_pct' => $lines->count() ? (int) round($lines->avg('progress_pct')) : null,
                'completed'    => $lines->where('progress_status', 'completed')->count(),
                'average'      => RatingScale::mean($scores),
            ];
        }

        return $sections;
    }

    private function submissionsFor($forms, ?SchoolYear $year): array
    {
        if (! $year?->start_date || ! $year?->end_date) {
            return [];
        }

        $counted = $forms
            ->filter(fn ($form) => $form->submitted_at)
            ->groupBy(fn ($form) => $form->submitted_at->format('Y-m'))
            ->map(fn ($group) => $group->count());

        $months = [];
        $cursor = $year->start_date->copy()->startOfMonth();
        $end    = $year->end_date->copy()->startOfMonth();
        $running = 0;

        while ($cursor->lte($end) && count($months) < 24) {
            $key      = $cursor->format('Y-m');
            $count    = (int) ($counted[$key] ?? 0);
            $running += $count;

            $months[] = [
                'month'      => $key,
                'label'      => $cursor->format('M'),
                'full_label' => $cursor->format('F Y'),
                'submitted'  => $count,
                'cumulative' => $running,
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    private function trendFor(): array
    {
        return PcrPeriodSummary::query()
            ->join('rating_periods', 'pcr_period_summaries.rating_period_id', '=', 'rating_periods.id')
            ->join('school_years', 'rating_periods.school_year_id', '=', 'school_years.id')
            ->whereIn('pcr_period_summaries.form_id', PcrForm::query()->select('pcr_forms.id'))
            ->whereNotNull('pcr_period_summaries.final_average')
            ->groupBy('school_years.label', 'school_years.start_date', 'rating_periods.label', 'rating_periods.seq')
            ->orderBy('school_years.start_date')
            ->orderBy('rating_periods.seq')
            ->get([
                DB::raw('school_years.label as year_label'),
                DB::raw('rating_periods.label as period_label'),
                DB::raw('avg(pcr_period_summaries.final_average) as average'),
                DB::raw('count(*) as forms'),
                DB::raw('school_years.start_date as start_date'),
                DB::raw('rating_periods.seq as seq'),
            ])
            ->map(fn ($row) => [
                'label'       => $row->year_label . ' · ' . $row->period_label,
                'short'       => $row->year_label . ' H' . $row->seq,
                'year_label'  => $row->year_label,
                'period_label'=> $row->period_label,
                'average'     => round((float) $row->average, 2),
                'forms'       => (int) $row->forms,
            ])
            ->take(-8)
            ->values()
            ->all();
    }

    private function atRiskFor($leaves): array
    {
        return $leaves
            ->where('is_overdue', true)
            ->sortByDesc('days_late')
            ->take(8)
            ->map(fn ($line) => [
                'id'           => $line['id'],
                'description'  => $line['description'],
                'owner'        => $line['owner'],
                'unit'         => $line['unit'],
                'target_date'  => $line['target_date'],
                'days_late'    => $line['days_late'],
                'progress_pct' => $line['progress_pct'],
            ])
            ->values()
            ->all();
    }

    public function printForm(Request $request, $id)
    {
        $form = PcrForm::with([
            'schoolYear.periods',
            'ratingPeriod:id,label,seq,is_active',
            'orgUnit.head:id,name,position_title',
            'orgUnit.vp:id,name,position_title',
            'owner:id,name,position_title,image',
            'outputs.indicators.accomplishments.attachments',
            'outputs.indicators.ratings',
            'outputs.indicators.parent:id,output_id,description',
            'outputs.indicators.children.output.form.owner:id,name,position_title,image',
            'outputs.indicators.assignments.user:id,name,position_title,image',
            'headReviewer:id,name,position_title',
            'vpReviewer:id,name,position_title',
            'summaries',
        ])->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $form->setRelation('outputs', PcrOutline::numbered($form->outputs));

        return response()->json([
            'form'        => $form,
            'scale'       => PcrPrint::scale(),
            'seal_url'    => PcrPrint::sealUrl(),
            'office_head' => PcrPrint::officeHead()?->only(['id', 'name', 'position_title']),
        ]);
    }

    /**
     * The trail is mostly sign-ins, so the page needs to be able to look past
     * them. Filters are applied here rather than in the browser: the point of
     * an audit trail is that it can be searched once it is long.
     */
    /**
     * The form as a document. Rendered server-side so what people file is the
     * same every time, rather than depending on a browser's print dialogue.
     */
    public function formPdf(Request $request, $id)
    {
        $form = PcrForm::with([
            'schoolYear.periods', 'orgUnit', 'owner:id,name,position_title,image',
            'headReviewer:id,name,position_title', 'vpReviewer:id,name,position_title',
            'outputs.indicators.accomplishments', 'outputs.indicators.ratings',
            'outputs.indicators.children.output.form.owner:id,name,image',
            'outputs.indicators.assignments.user:id,name',
            'summaries',
        ])->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $form->setRelation('outputs', PcrOutline::numbered($form->outputs));

        $period = $form->rating_period_id
            ? $form->schoolYear?->periods->firstWhere('id', $form->rating_period_id)
            : ($request->query('rating_period_id')
                ? $form->schoolYear?->periods->firstWhere('id', (int) $request->query('rating_period_id'))
                : $form->schoolYear?->periods->firstWhere('is_active', true) ?? $form->schoolYear?->periods->first());

        $pdf = Pdf::loadView('pdf.form', [
            'form'         => $form,
            'period'       => $period,
            'summary'      => $form->summaries->firstWhere('rating_period_id', $period?->id),
            'organization' => app(CurrentOrganization::class)->get(),
            'seal'         => PcrPrint::sealSrc(),
            'officeHead'   => PcrPrint::officeHead(),
            'scale'        => PcrPrint::scale(),
        ])->setPaper('legal', 'landscape');

        $name = strtoupper($form->type) . ' - ' . ($form->owner?->name ?? $form->orgUnit?->name);

        return $pdf->stream("{$name}.pdf");
    }

    public function formExcel(Request $request, $id)
    {
        $form = PcrForm::with([
            'schoolYear.periods', 'orgUnit', 'owner:id,name,position_title,image',
            'headReviewer:id,name,position_title', 'vpReviewer:id,name,position_title',
            'outputs.indicators.accomplishments', 'outputs.indicators.ratings',
            'outputs.indicators.children.output.form.owner:id,name,image',
            'outputs.indicators.assignments.user:id,name',
            'summaries',
        ])->findOrFail($id);

        if (! PcrWorkflow::canView($request->user(), $form)) {
            return response()->json(['message' => 'You do not have access to this form.'], 403);
        }

        $form->setRelation('outputs', PcrOutline::numbered($form->outputs));

        $period = $form->rating_period_id
            ? $form->schoolYear?->periods->firstWhere('id', $form->rating_period_id)
            : ($request->query('rating_period_id')
                ? $form->schoolYear?->periods->firstWhere('id', (int) $request->query('rating_period_id'))
                : $form->schoolYear?->periods->firstWhere('is_active', true) ?? $form->schoolYear?->periods->first());

        return (new PcrFormExcel(
            $form,
            $period,
            $form->summaries->firstWhere('rating_period_id', $period?->id),
            app(CurrentOrganization::class)->get(),
            PcrPrint::officeHead(),
        ))->stream();
    }

    public function auditLogs(Request $request)
    {
        $query = ActivityLog::with('user:id,name')->orderByDesc('created_at');

        if ($subject = $request->query('subject_type')) {
            $query->where('subject_type', $subject);
        }

        if ($action = $request->query('action')) {
            $query->whereIn('action', (array) $action);
        }

        if ($actor = $request->query('user_id')) {
            $query->where('user_id', $actor);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($term = $request->query('q')) {
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('user_name', 'like', "%{$term}%")
                    ->orWhere('subject_type', 'like', "%{$term}%");
            });
        }

        $logs = $query->limit((int) $request->query('limit', 300))->get()
            ->each(fn ($log) => $log->description = Html::toText($log->description));

        return response()->json([
            'logs' => $logs,
            // What is actually present, so the filters offer real choices
            // rather than a hardcoded list that drifts.
            'actions'  => ActivityLog::distinct()->orderBy('action')->pluck('action'),
            'subjects' => ActivityLog::distinct()->orderBy('subject_type')->pluck('subject_type'),
            'actors'   => ActivityLog::whereNotNull('user_id')
                ->select('user_id', 'user_name')
                ->distinct()
                ->orderBy('user_name')
                ->get(),
        ]);
    }
}
