<?php

namespace App\Support;

use App\Models\PcrForm;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Official OPCR / IPCR paper as an .xlsx — same columns, merges and
 * signature blocks as the attached V3 and Madriaga forms.
 */
class PcrFormExcel
{
    private Spreadsheet $book;

    private Worksheet $sheet;

    private bool $isOpcr;

    private string $font;

    private string $lastCol;

    public function __construct(
        private PcrForm $form,
        private mixed $period,
        private mixed $summary,
        private mixed $organization,
        private mixed $officeHead,
    ) {
        $this->isOpcr  = $form->type === 'opcr';
        $this->font    = $this->isOpcr ? 'Arial' : 'Calibri';
        $this->lastCol = $this->isOpcr ? 'N' : 'M';
        $this->book    = new Spreadsheet();
        $this->sheet   = $this->book->getActiveSheet();
    }

    public function stream()
    {
        $this->isOpcr ? $this->buildOpcr() : $this->buildIpcr();

        $writer = new Xlsx($this->book);
        $name   = strtoupper($this->form->type).' - '.($this->form->owner?->name ?? $this->form->orgUnit?->name).'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
            $this->book->disconnectWorksheets();
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function buildOpcr(): void
    {
        $this->page('OPCR', [
            'A' => 30.86, 'B' => 10.0, 'C' => 10.43, 'D' => 9.43, 'E' => 13.0,
            'F' => 21.43, 'G' => 19.29, 'H' => 8.71, 'I' => 7.29, 'J' => 7.43,
            'K' => 7.29, 'L' => 4.29, 'M' => 3.29, 'N' => 13.71,
        ]);

        $this->seal('A1', 56);
        $this->merge('A2:N4', $this->title(), 18, true, 'center', 'center');
        $this->height(2, 16);
        $this->height(3, 16);
        $this->height(4, 16);

        $this->merge('A7:N7', $this->commitment(), 12, true, 'left', 'center');
        $this->height(7, 36);

        $this->merge('H8:K8', mb_strtoupper($this->officeHead?->name ?? $this->ratee()), 12, true, 'center');
        $this->merge('H9:K9', 'Office Head', 12, true, 'center', 'top');
        $this->merge('H10:K10', 'Date: '.$this->periodLabel(), 9, false, 'center', 'top');
        $this->underline('H8:K8');

        $this->merge('A12:E12', 'Approved by:', 12, true, 'left', 'center', 'D8D8D8');
        $this->merge('G12:H12', 'Date', 12, true, 'center', 'center', 'D8D8D8');

        $row = 13;
        foreach (PcrPrint::scale() as $band) {
            $this->write("J{$row}", $band['value'].' - '.$band['label'], 11);
            $this->write("N{$row}", $band['range'] ?? '', 10, true);
            $row++;
        }

        $this->merge('A16:E16', $this->form->vpReviewer?->name ?? '', 12, true, 'center');
        $this->merge('A17:E17', $this->form->vpReviewer?->position_title ?? 'Municipal Administrator', 12, true, 'center');
        $this->merge('G16:H16', $this->form->vp_reviewed_at?->format('F d, Y') ?? '', 12, true, 'center');
        $this->box('A12:E17');
        $this->box('G12:H17');

        $this->headerRow(19);
        $this->writeBody(21);
    }

    private function buildIpcr(): void
    {
        $this->page('IPCR', [
            'A' => 45.57, 'B' => 8.71, 'C' => 13.0, 'D' => 44.43, 'E' => 8.71,
            'F' => 13.0, 'G' => 27.14, 'H' => 8.71, 'I' => 13.0, 'J' => 13.0,
            'K' => 13.0, 'L' => 9.14, 'M' => 22.0,
        ]);

        $this->seal('A1', 48);
        $this->merge('A1:M1', $this->title(), 14, true, 'center');
        $this->merge('A2:M2', $this->commitment(), 14, false, 'left', 'top');
        $this->height(2, 36);

        $this->merge('G3:M3', mb_strtoupper($this->form->owner?->name ?? $this->ratee()), 14, false, 'center', 'top');
        $this->merge('G4:M4', 'Ratee', 14, false, 'center', 'top');
        $this->merge('G5:M5', 'Date: '.$this->periodLabel(), 14, false, 'center', 'top');
        $this->underline('G3:M3');

        $this->merge('A6:C6', 'Reviewed by', 14, true, 'left', 'top');
        $this->write('D6', 'Date', 14, true, 'center');
        $this->merge('E6:K6', 'Approved by', 14, true, 'left', 'top');
        $this->merge('L6:M6', 'Date', 14, true, 'center');

        $this->merge('A9:C9', $this->form->headReviewer?->name ?? $this->form->reviewed_by_name ?? '', 14, true, 'center');
        $this->write('D9', $this->form->reviewed_at?->format('F d, Y') ?? '', 14, false, 'center');
        $this->merge('E9:K9', $this->form->vpReviewer?->name ?? $this->form->vp_reviewed_by_name ?? '', 14, true, 'center');
        $this->merge('L9:M9', $this->form->vp_reviewed_at?->format('F d, Y') ?? '', 14, false, 'center');
        $this->merge('A10:C10', $this->form->headReviewer?->position_title ?? 'Immediate Supervisor', 14, true, 'center');
        $this->merge('E10:K10', $this->form->vpReviewer?->position_title ?? 'Head of Office', 14, true, 'center');
        $this->box('A6:M10');

        $this->headerRow(12);
        $this->writeBody(14);
    }

    private function headerRow(int $row): void
    {
        $next = $row + 1;
        $size = $this->isOpcr ? 12 : 14;

        if ($this->isOpcr) {
            $this->merge("A{$row}:A{$next}", 'MFO/PPA', $size, true, 'center');
            $this->merge("B{$row}:D{$row}", 'Success Indicator', $size, true, 'center');
            $this->merge("B{$next}:D{$next}", '(Target + Measure)', $size, true, 'center');
            $this->merge("E{$row}:E{$next}", 'Alloted Budget', $size, true, 'center');
            $this->merge("F{$row}:F{$next}", 'Individual / Accountable', $size, true, 'center');
            $this->merge("G{$row}:G{$next}", 'Actual Accomplishments', $size, true, 'center');
            $this->merge("H{$row}:K{$row}", 'Rating', $size, true, 'center');
            foreach (['H' => 'Q', 'I' => 'E', 'J' => 'T', 'K' => 'A'] as $col => $label) {
                $this->write("{$col}{$next}", $label, $size, true, 'center');
            }
            $this->merge("L{$row}:N{$next}", 'Remarks', $size, true, 'center');
            $this->box("A{$row}:N{$next}");
        } else {
            $this->merge("A{$row}:A{$next}", 'MFO/PPA', $size, true, 'center', 'top');
            $this->merge("B{$row}:D{$row}", 'Success Indicator', $size, true, 'center', 'top');
            $this->merge("B{$next}:D{$next}", '(Target + Measure)', $size, true, 'center', 'top');
            $this->merge("E{$row}:G{$row}", 'Actual', $size, true, 'center', 'top');
            $this->merge("E{$next}:G{$next}", 'Accomplishments', $size, true, 'center', 'top');
            $this->merge("H{$row}:K{$row}", 'Rating', $size, true, 'center', 'top');
            foreach (['H' => 'Q', 'I' => 'E', 'J' => 'T', 'K' => 'A'] as $col => $label) {
                $this->write("{$col}{$next}", $label, $size, true, 'center');
            }
            $this->merge("L{$row}:M{$next}", 'Remarks', $size, true, 'center');
            $this->box("A{$row}:M{$next}");
        }
    }

    private function writeBody(int $start): void
    {
        $row   = $start;
        $size  = $this->isOpcr ? 10 : 14;
        $last  = $this->lastCol;
        $score = fn ($value) => $value != null ? number_format((float) $value, 2) : '';

        foreach ($this->bodyRows() as $item) {
            if ($item['kind'] === 'section') {
                $this->merge("A{$row}:{$last}{$row}", $item['label'], $this->isOpcr ? 12 : 14, true, 'left', 'center', 'D8D8D8');
                $this->box("A{$row}:{$last}{$row}");
                $this->height($row, $this->isOpcr ? 16.5 : 18);
                $row++;

                continue;
            }

            if ($item['kind'] === 'average') {
                $this->merge("A{$row}:J{$row}", $item['label'], 12, true, 'left', 'center', 'D8D8D8');
                $this->write("K{$row}", $score($item['value']), 12, true, 'center');
                $this->box("A{$row}:{$last}{$row}");
                $this->height($row, 24);
                $row++;

                continue;
            }

            if ($item['kind'] === 'total') {
                $this->merge("A{$row}:J{$row}", $item['label'], 12, true);
                $this->write("K{$row}", $item['value'], 12, true, 'center');
                $this->box("A{$row}:{$last}{$row}");
                $this->height($row, 24);
                $row++;

                continue;
            }

            $span = max((int) ($item['span'] ?? 1), 1);
            $end  = $row + $span - 1;
            $line = $item['indicator'];
            $rating = $line?->ratings->firstWhere('rating_period_id', $this->period?->id);
            $acc    = $line?->accomplishments->firstWhere('rating_period_id', $this->period?->id);

            if ($item['title'] !== null) {
                $this->merge("A{$row}:A{$end}", $item['title'], $size, false, 'left', 'center');
            }

            if ($this->isOpcr) {
                $this->merge("B{$row}:D{$row}", Html::toText($line?->description), $size);
                $this->write("E{$row}", $line?->allotted_budget ? number_format((float) $line->allotted_budget, 2) : '', $size, false, 'right');
                $this->write("F{$row}", $this->accountable($line), $size);
                $this->write("G{$row}", Html::toText($acc?->actual_accomplishment), $size);
                $this->write("H{$row}", $rating?->q, $size, false, 'center');
                $this->write("I{$row}", $rating?->e, $size, false, 'center');
                $this->write("J{$row}", $rating?->t, $size, false, 'center');
                $this->write("K{$row}", $score($rating?->a), $size, false, 'center');
                $this->merge("L{$row}:N{$row}", Html::toText($rating?->remarks), $size);
            } else {
                $this->merge("B{$row}:D{$row}", Html::toText($line?->description), $size);
                $this->merge("E{$row}:G{$row}", Html::toText($acc?->actual_accomplishment), $size);
                $this->write("H{$row}", $rating?->q, $size, false, 'center');
                $this->write("I{$row}", $rating?->e, $size, false, 'center');
                $this->write("J{$row}", $rating?->t, $size, false, 'center');
                $this->write("K{$row}", $score($rating?->a), $size, false, 'center');
                $this->merge("L{$row}:M{$row}", Html::toText($rating?->remarks), $size);
            }

            $this->box("A{$row}:{$last}{$row}");
            $this->height($row, $this->rowHeight($item['title'].' '.Html::toText($line?->description)));
            $row++;
        }

        $this->footer($row);
    }

    private function footer(int $row): void
    {
        $last  = $this->lastCol;
        $size  = $this->isOpcr ? 12 : 14;
        $score = fn ($value) => $value != null ? number_format((float) $value, 2) : '';

        if ($this->isOpcr) {
            foreach ([
                ['Total Overall Rating', $score($this->summary?->final_average)],
                ['Final Average Rating', $score($this->summary?->final_average)],
                ['Adjectival Rating', $this->summary?->adjectival ?? ''],
            ] as [$label, $value]) {
                $this->merge("A{$row}:J{$row}", $label, $size, true);
                $this->write("K{$row}", $value, $size, true, 'center');
                $this->box("A{$row}:{$last}{$row}");
                $this->height($row, 24);
                $row++;
            }

            $row++;
            $this->merge("A{$row}:B{$row}", 'Assessed by:', $size, true);
            $this->merge("C{$row}:D{$row}", 'Date', $size, true, 'center');
            $this->merge("H{$row}:L{$row}", 'Final Rating by:', $size, true);
            $this->merge("M{$row}:N{$row}", 'Date', $size, true, 'center');
            $this->box("A{$row}:{$last}{$row}");
            $row += 3;

            $this->merge("A{$row}:B{$row}", $this->form->rated_by_name ?? '', $size, true, 'center');
            $this->merge("E{$row}:F{$row}", $this->form->vpReviewer?->name ?? '', $size, true, 'center');
            $this->merge("H{$row}:L{$row}", $this->officeHead?->name ?? $this->ratee(), $size, true, 'center');
            $row++;
            $this->merge("A{$row}:B{$row}", "Mun. Planning & Dev't Coordinator", $size, false, 'center');
            $this->merge("E{$row}:F{$row}", 'Municipal Administrator - PMT Chairperson', $size, false, 'center');
            $this->merge("H{$row}:L{$row}", $this->officeHead?->position_title ?? 'Head of Agency', $size, false, 'center');
            $this->box('A'.($row - 4).":{$last}{$row}");
            $row += 2;
        } else {
            foreach ([
                ['Final Average Rating', $score($this->summary?->final_average)],
                ['Adjectival Rating:', $this->summary?->adjectival ?? ''],
            ] as [$label, $value]) {
                $this->merge("A{$row}:A{$row}", $label, $size, false);
                $this->merge("B{$row}:{$last}{$row}", $value, $size, true, 'center');
                $this->box("A{$row}:{$last}{$row}");
                $row++;
            }

            $this->merge("A{$row}:{$last}{$row}", 'Comments and Recommendations for Development Purposes:', $size);
            $row++;
            $this->merge("A{$row}:{$last}{$row}", Html::toText($this->form->header_note), $size, false, 'left', 'top');
            $this->height($row, 36);
            $this->box('A'.($row - 1).":{$last}{$row}");
            $row += 2;

            $this->merge("A{$row}:B{$row}", 'Discussed with', $size, true, 'center');
            $this->write("C{$row}", 'Date', $size, true, 'center');
            $this->merge("D{$row}:F{$row}", 'Assessed by', $size, true, 'center');
            $this->write("G{$row}", 'Date', $size, true, 'center');
            $this->merge("H{$row}:L{$row}", 'Final Rating by', $size, true, 'center');
            $this->write("M{$row}", 'Date', $size, true, 'center');
            $this->box("A{$row}:{$last}{$row}");
            $head = $row;
            $row++;
            $this->merge("D{$row}:F".($row + 1), 'I certify that I discussed my assessment of the performance with the employee', $size, false, 'center', 'top');
            $row += 2;
            $this->merge("A{$row}:B{$row}", $this->form->owner?->name ?? '', $size, false, 'center');
            $this->merge("D{$row}:F{$row}", $this->form->headReviewer?->name ?? $this->form->reviewed_by_name ?? '', $size, false, 'center');
            $this->merge("H{$row}:L{$row}", $this->form->rated_by_name ?? $this->form->vpReviewer?->name ?? '', $size, false, 'center');
            $row++;
            $this->merge("A{$row}:B{$row}", 'Employee', $size, false, 'center');
            $this->merge("D{$row}:F{$row}", 'Supervisor', $size, false, 'center');
            $this->merge("H{$row}:L{$row}", 'Head of Office', $size, false, 'center');
            $this->box("A{$head}:{$last}{$row}");
            $row += 2;
        }

        $this->merge(
            "A{$row}:{$last}{$row}",
            'Legend     Q – Quality     E – Efficiency     T – Timeliness     A – Average',
            $this->isOpcr ? 10 : 14,
            $this->isOpcr,
            'left',
            'center'
        );
        $this->sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        $this->box("A{$row}:{$last}{$row}");

        $this->sheet->getPageSetup()->setPrintArea("A1:{$last}{$row}");
    }

    private function bodyRows(): array
    {
        $rows = [];
        $bands = $this->isOpcr
            ? ['strategic' => 'STRATEGIC PRIORITY', 'core' => 'CORE FUNCTIONS', 'support' => 'SUPPORT FUNCTIONS']
            : ['strategic' => 'STRATEGIC PRIORITY', 'core' => 'CORE FUNCTIONS:', 'support' => 'SUPPORT FUNCTIONS:'];

        foreach ($bands as $key => $label) {
            $outputs = $this->form->outputs->where('section', $key);

            if ($outputs->isEmpty()) {
                continue;
            }

            $rows[] = ['kind' => 'section', 'label' => $label];

            foreach (PcrOutline::numbered($outputs) as $output) {
                $lines = $output->indicators
                    ->filter(fn ($line) => ! $line->rating_period_id || (int) $line->rating_period_id === (int) $this->period?->id)
                    ->values();

                if ($lines->isEmpty()) {
                    $rows[] = ['kind' => 'line', 'title' => $this->outputTitle($output), 'span' => 1, 'indicator' => null];

                    continue;
                }

                foreach ($lines as $index => $line) {
                    $rows[] = [
                        'kind'      => 'line',
                        'title'     => $index === 0 ? $this->outputTitle($output) : null,
                        'span'      => $index === 0 ? $lines->count() : 0,
                        'indicator' => $line,
                    ];
                }
            }

            if ($this->isOpcr && in_array($key, ['core', 'support'], true)) {
                $rows[] = [
                    'kind'  => 'average',
                    'label' => 'AVERAGE RATING',
                    'value' => $this->summary?->{$key.'_average'},
                ];
            }
        }

        return $rows;
    }

    private function outputTitle($output): string
    {
        $number = $output->outline_number ?? '';

        return trim($number !== '' ? $number.'. '.$output->title : (string) $output->title);
    }

    private function title(): string
    {
        return $this->isOpcr
            ? 'OFFICE PERFORMANCE COMMITMENT AND REVIEW (OPCR)'
            : 'INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW';
    }

    private function commitment(): string
    {
        $org = $this->organization?->name ?? $this->form->orgUnit?->name ?? 'Opol Community College';
        $who = mb_strtoupper($this->ratee());
        $as  = $this->isOpcr ? "Head of the {$org}" : "of the {$org}";

        return "I, {$who}, {$as}, commit to deliver and agree to be rated on the attainment of the following targets in accordance with the indicated measures for the period {$this->periodLabel()}.";
    }

    private function ratee(): string
    {
        return $this->isOpcr
            ? ($this->officeHead?->name ?? $this->form->orgUnit?->name ?? '')
            : ($this->form->owner?->name ?? $this->form->orgUnit?->name ?? '');
    }

    private function periodLabel(): string
    {
        if ($this->isOpcr) {
            $year = $this->form->schoolYear?->start_date
                ? $this->form->schoolYear->start_date->format('Y')
                : $this->form->schoolYear?->label;

            return trim('January to December '.$year);
        }

        return $this->period?->label ?? $this->form->schoolYear?->label ?? '';
    }

    private function accountable($line): string
    {
        if (! $line) {
            return '';
        }

        $people = $line->assignments->map(fn ($row) => $row->user?->name)->filter();

        if ($people->isEmpty()) {
            $people = $line->children->map(fn ($child) => $child->output?->form?->owner?->name)->filter();
        }

        return $people->isNotEmpty() ? $people->implode(' / ') : (string) ($line->accountable ?? '');
    }

    private function page(string $title, array $widths): void
    {
        $this->sheet->setTitle($title);
        $this->book->getDefaultStyle()->getFont()->setName($this->font)->setSize($this->isOpcr ? 10 : 14);
        $this->sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_LEGAL)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $this->sheet->getPageMargins()
            ->setLeft(0.2)->setRight(0.12)->setTop(0.24)->setBottom(0.24);
        $this->sheet->getSheetView()->setZoomScale(90);

        foreach ($widths as $col => $width) {
            $this->sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    private function seal(string $cell, int $height): void
    {
        $path = PcrPrint::sealPath();

        if (! $path || ! preg_match('/\.(png|jpe?g|gif)$/i', $path)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setPath($path);
        $drawing->setHeight($height);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($this->sheet);
    }

    private function write(string $cell, mixed $value, float $size, bool $bold = false, string $h = 'left', string $v = 'center'): void
    {
        $this->sheet->setCellValue($cell, $value ?? '');
        $style = $this->sheet->getStyle($cell);
        $style->getFont()->setName($this->font)->setSize($size)->setBold($bold);
        $style->getAlignment()->setHorizontal($h)->setVertical($v)->setWrapText(true);
    }

    private function merge(string $range, mixed $value, float $size, bool $bold = false, string $h = 'left', string $v = 'center', ?string $fill = null): void
    {
        [$start, $end] = array_pad(explode(':', $range, 2), 2, null);

        if ($end && $start !== $end) {
            $this->sheet->mergeCells($range);
        }

        $this->write($start, $value, $size, $bold, $h, $v);

        if ($fill) {
            $this->sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($fill);
        }

        $this->sheet->getStyle($range)->getAlignment()
            ->setHorizontal($h)->setVertical($v)->setWrapText(true);
        $this->sheet->getStyle($range)->getFont()
            ->setName($this->font)->setSize($size)->setBold($bold);
    }

    private function box(string $range): void
    {
        $this->sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
    }

    private function underline(string $range): void
    {
        $this->sheet->getStyle($range)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
    }

    private function height(int $row, float $points): void
    {
        $this->sheet->getRowDimension($row)->setRowHeight($points);
    }

    private function rowHeight(string $text): float
    {
        $lines = max(1, (int) ceil(max(strlen($text), 1) / 42));

        return min(78, max($this->isOpcr ? 16.5 : 22, 14 + ($lines * 12)));
    }
}
