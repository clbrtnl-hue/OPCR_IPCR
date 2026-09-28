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
            'K' => 7.29, 'L' => 4.29, 'M' => 3.29, 'N' => 13.71, 'O' => 9.14,
        ]);
        $this->sheet->getDefaultRowDimension()->setRowHeight(12.75);
        $this->sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(1);
        $this->sheet->getPageMargins()
            ->setLeft(0.197)->setRight(0.118)->setTop(0.236)->setBottom(0.236)
            ->setHeader(0)->setFooter(0);
        $this->sheet->getSheetView()->setZoomScale(100);

        $this->seal('A1', 92, 136, 7);
        $this->merge('A2:N4', $this->title(), 18, true, 'center', 'center');
        $this->height(2, 12.75);
        $this->height(3, 12.75);
        $this->height(4, 12.75);

        $this->merge('A7:N7', $this->commitment(), 12, true, 'left', 'center');
        $this->outline('A7:N7');
        $this->height(7, 34.5);

        $this->merge('H8:K8', $this->upper($this->officeHead?->name ?? $this->ratee()), 12, true, 'center', 'bottom');
        $this->merge('H9:K9', 'Office Head', 12, true, 'center', 'top');
        $this->merge('H10:K10', 'Date: '.$this->periodLabel(), 9, false, 'center', 'top');
        $this->underline('H8:K8');
        $this->height(8, 35.25);
        $this->height(9, 16.5);
        $this->height(10, 15);

        // Approved-by is a gray bar across A–F, not one merged cell. The name
        // sits on A16:E16 and the approval date on G16:H16, same row.
        $this->write('A12', 'Approved by:', 12, true, 'left', 'center');
        $this->fill('A12:F12', 'D8D8D8');
        $this->outline('A12:F12');
        $this->edge('A13:A18', 'left');
        $this->edge('F13:F18', 'right');

        $this->merge('G12:H12', 'Date', 12, true, 'center', 'center', 'D8D8D8');
        $this->outline('G12:H12');
        $this->edge('G13:G18', 'left');
        $this->edge('H13:H18', 'right');

        $this->fill('I12:N12', 'D8D8D8');
        $this->edge('I12:N12', 'top');
        $this->edge('I12:N12', 'bottom');
        $this->edge('N12', 'right');
        $this->height(12, 12.75);

        foreach ([
            ['5 - Outstanding', '100%', '91-100'],
            ['4 - Very Satisfactory', '90-99%', '81-90'],
            ['3 - Satisfactory', '70-89%', '71-80'],
            ['2 - Unsatisfactory', '50-69%', '61-70'],
            ['1 - Poor', 'Below 50%', 'Below 60'],
        ] as $index => [$label, $percent, $band]) {
            $scaleRow = 13 + $index;
            $this->write("J{$scaleRow}", $label, 11, false, 'left');
            $this->write("N{$scaleRow}", $percent, 10, true, 'left');
            $this->write("O{$scaleRow}", $band, 10, false, 'left');
            $this->edge("N{$scaleRow}", 'right');
            $this->height($scaleRow, $scaleRow === 16 ? 15 : 12.75);
        }

        $approvedOn = $this->form->vp_reviewed_at
            ? mb_strtoupper($this->form->vp_reviewed_at->format('F d, Y'))
            : '';
        $this->merge('A16:E16', $this->upper($this->form->vpReviewer?->name), 12, true, 'center');
        $this->merge('A17:E17', $this->form->vpReviewer?->position_title ?? 'Municipal Administrator', 12, true, 'center');
        $this->merge('G16:H16', $approvedOn, 12, true, 'center');
        $this->height(18, 12.75);

        $this->headerRow(19);
        $this->height(19, 14.25);
        $this->height(20, 15);
        $this->writeBody(21);
    }

    private function buildIpcr(): void
    {
        $this->page('IPCR', [
            'A' => 45.57, 'B' => 8.71, 'C' => 8.71, 'D' => 44.43, 'E' => 8.71,
            'F' => 8.71, 'G' => 27.14, 'H' => 8.71, 'I' => 8.71, 'J' => 8.71,
            'K' => 8.71, 'L' => 9.14, 'M' => 105.14,
        ]);
        $this->sheet->getDefaultRowDimension()->setRowHeight(15);
        $this->sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(1);
        $this->sheet->getPageMargins()
            ->setLeft(0.7)->setRight(0.7)->setTop(0.75)->setBottom(0.75)
            ->setHeader(0)->setFooter(0);
        $this->sheet->getSheetView()->setZoomScale(100);

        $signed = $this->longDate($this->form->submitted_at) ?: $this->longDate($this->form->created_at);

        $this->merge('A1:M1', $this->title(), 14, true, 'center', 'bottom');
        $this->merge('A2:M2', $this->commitment(), 14, false, 'left', 'top');
        $this->height(2, 32);
        $this->merge('G3:M3', $this->partyName('ratee'), 14, false, 'center', 'top');
        $this->merge('G4:M4', 'Ratee', 14, false, 'center', 'top');
        $this->merge('G5:M5', 'Date: '.$signed, 14, false, 'center', 'top');
        $this->outline('A1:M5');
        $this->underline('G3:M3');

        $this->merge('A6:C6', 'Reviewed by', 14, true, 'left', 'top');
        $this->write('D6', 'Date', 14, true, 'center', 'top');
        $this->merge('E6:K6', 'Approved by', 14, true, 'left', 'top');
        $this->merge('L6:M6', 'Date', 14, true, 'center', 'top');

        $this->merge('A7:C8', '', 14);
        $this->merge('D7:D9', $this->longDate($this->form->reviewed_at), 14, false, 'center', 'center');
        $this->merge('E7:K8', '', 14);
        $this->merge('L7:M9', $this->longDate($this->form->vp_reviewed_at), 14, false, 'center', 'center');

        $this->merge('A9:C9', $this->partyName('reviewer'), 14, true, 'center', 'top');
        $this->merge('E9:K9', $this->partyName('approver'), 14, true, 'center', 'center');
        $this->merge('A10:C10', 'Immediate Supervisor', 14, true, 'center', 'top');
        $this->merge('E10:K10', 'Head of Office', 14, true, 'center', 'top');
        $this->merge('L10:M10', '', 14);

        $this->outline('A6:M10');
        $this->edge('A6:M6', 'bottom');
        $this->edge('C6:C10', 'right');
        $this->edge('D6:D10', 'right');
        $this->edge('K6:K10', 'right');
        $this->edge('A8:C8', 'bottom');
        $this->edge('A9:C9', 'bottom');
        $this->edge('E9:K9', 'bottom');

        $this->merge('A11:M11', '', 14);
        $this->outline('A11:M11');

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

            $row = $this->opcrSignOff($row);
        } else {
            foreach ([
                ['Final Average Rating', $score($this->summary?->final_average), false],
                ['Adjectival Rating:', $this->summary?->adjectival ?? '', true],
            ] as [$label, $value, $bold]) {
                $this->merge("A{$row}:A{$row}", $label, $size, false, 'left', 'top');
                $this->merge("B{$row}:{$last}{$row}", $value, $size, $bold, 'center', 'top');
                $this->box("A{$row}:{$last}{$row}");
                $this->height($row, 15.75);
                $row++;
            }

            $this->merge("A{$row}:{$last}{$row}", 'Comments and Recommendations for Development Purposes:', $size, false, 'left', 'top');
            $this->box("A{$row}:{$last}{$row}");
            $this->height($row, 15.75);
            $row++;
            $note = Html::toText($this->form->header_note);
            $this->merge("A{$row}:{$last}{$row}", $note, $size, false, 'left', 'top');
            $this->box("A{$row}:{$last}{$row}");
            $this->height($row, max(15.75, $this->rowHeight($note)));
            $row++;

            $row = $this->ipcrSignOff($row);
        }

        $this->merge(
            "A{$row}:{$last}{$row}",
            'Legend     Q – Quality     E – Efficiency     T – Timeliness     A – Average',
            $this->isOpcr ? 10 : 14,
            $this->isOpcr,
            'left',
            $this->isOpcr ? 'center' : 'top'
        );
        $this->box("A{$row}:{$last}{$row}");

        $printCol = $this->isOpcr ? 'O' : $last;
        $this->sheet->getPageSetup()->setPrintArea("A1:{$printCol}{$row}");
    }

    /**
     * The OPCR sign-off grid from the revised V3 sheet.
     *
     * Row 0 of the block is Date | Final Rating by | Date.
     * Assessed by and its Date start on the next row.
     * Names sit four rows down, titles under the names, and each Date
     * column is one tall merged cell left blank for the wet signature.
     */
    private function opcrSignOff(int $row): int
    {
        $s    = $row;
        $size = 12;

        $this->height($s, 19.5);
        $this->height($s + 1, 27.75);
        $this->height($s + 2, 16.5);
        $this->height($s + 3, 15);
        $this->height($s + 4, 30);
        $this->height($s + 5, 15);
        $this->height($s + 6, 12);

        $this->edge("A{$s}:F{$s}", 'top');
        $this->edge("A{$s}:F{$s}", 'bottom');
        $this->edge("A{$s}", 'left');
        $this->write("G{$s}", 'Date', $size, true, 'center');
        $this->outline("G{$s}");
        $this->merge("H{$s}:K{$s}", 'Final Rating by:', $size, true, 'left', 'center');
        $this->outline("H{$s}:L{$s}");
        $this->merge("M{$s}:N{$s}", 'Date', $size, true, 'center');
        $this->outline("M{$s}:N{$s}");

        $this->merge('A'.($s + 1).':B'.($s + 1), 'Assessed by:', $size, true, 'left');
        $this->outline('A'.($s + 1).':B'.($s + 1));
        $this->merge('C'.($s + 1).':D'.($s + 1), 'Date', $size, true, 'center');
        $this->outline('C'.($s + 1).':D'.($s + 1));

        $this->merge('G'.($s + 1).':G'.($s + 5), '', 10, false, 'center');
        $this->outline('G'.($s + 1).':G'.($s + 5));
        $this->outline('G'.($s + 6));

        $this->merge('M'.($s + 1).':N'.($s + 5), '', 10, false, 'center');
        $this->outline('M'.($s + 1).':N'.($s + 5));
        $this->merge('M'.($s + 6).':N'.($s + 6), '', $size);
        $this->outline('M'.($s + 6).':N'.($s + 6));

        foreach ([1, 2, 3, 6] as $offset) {
            $line = $s + $offset;
            $this->merge("H{$line}:L{$line}", '', $size);
            $this->outline("H{$line}:L{$line}");
        }

        foreach ([2, 3, 6] as $offset) {
            $this->merge('A'.($s + $offset).':B'.($s + $offset), '', $size);
        }
        $this->outline('A'.($s + 2).':B'.($s + 6));

        $this->merge('C'.($s + 2).':D'.($s + 6), '', $size, true, 'center');
        $this->outline('C'.($s + 2).':D'.($s + 6));
        $this->outline('E'.($s + 1).':F'.($s + 6));

        $this->edge('A'.($s + 3).':B'.($s + 3), 'bottom');
        $this->edge('E'.($s + 3).':F'.($s + 3), 'bottom');
        $this->edge('H'.($s + 3).':L'.($s + 3), 'bottom');

        $this->merge('A'.($s + 4).':B'.($s + 4), $this->upper($this->form->rated_by_name), $size, true, 'center', 'bottom');
        $this->merge('E'.($s + 4).':F'.($s + 4), $this->upper($this->form->vpReviewer?->name), $size, true, 'center', 'center');
        $this->merge('H'.($s + 4).':L'.($s + 4), $this->upper($this->officeHead?->name), $size, true, 'center', 'top');

        $this->merge('A'.($s + 5).':B'.($s + 5), "Mun. Planning & Dev't Coordinator", $size, false, 'center', 'center');
        $this->merge('E'.($s + 5).':F'.($s + 5), 'Municipal Administrator - PMT Chairperson', $size, false, 'center', 'center');
        $this->merge('H'.($s + 5).':L'.($s + 5), 'Head of Agency', $size, false, 'center', 'top');

        return $s + 7;
    }

    /**
     * The IPCR sign-off from the Madriaga sheet.
     * Discussed with shows the ratee. Final Rating by shows the approver.
     * Assessed by holds the certification, then the word Supervisor.
     * Only the Discussed-with date is one merged cell (column C).
     */
    private function ipcrSignOff(int $row): int
    {
        $s    = $row;
        $size = 14;

        foreach (range(0, 3) as $offset) {
            $this->height($s + $offset, 15.75);
        }

        $this->merge("A{$s}:B{$s}", 'Discussed with', $size, true, 'center', 'top');
        $this->write("C{$s}", 'Date', $size, true, 'center', 'top');
        $this->merge("D{$s}:F{$s}", 'Assessed by', $size, true, 'center', 'top');
        $this->write("G{$s}", 'Date', $size, true, 'center', 'top');
        $this->merge("H{$s}:L{$s}", 'Final Rating by', $size, true, 'center', 'top');
        $this->write("M{$s}", 'Date', $size, true, 'center', 'top');

        $this->merge('A'.($s + 1).':B'.($s + 1), '', $size);
        $this->merge('C'.($s + 1).':C'.($s + 3), $this->longDate($this->form->submitted_at), $size, false, 'center', 'center');
        $this->merge(
            'D'.($s + 1).':F'.($s + 2),
            'I certify that I discussed my assessment of the performance with the employee',
            $size,
            false,
            'center',
            'top'
        );
        $this->merge('H'.($s + 1).':L'.($s + 1), '', $size);

        $this->merge('A'.($s + 2).':B'.($s + 2), $this->partyName('ratee'), $size, false, 'center', 'top');
        $this->write('G'.($s + 2), $this->longDate($this->form->reviewed_at), $size, false, 'center', 'center');
        $this->merge('H'.($s + 2).':L'.($s + 2), $this->partyName('approver'), $size, false, 'center', 'bottom');
        $this->write('M'.($s + 2), $this->longDate($this->form->vp_reviewed_at ?: $this->form->rated_at), $size, false, 'center', 'center');

        $this->merge('A'.($s + 3).':B'.($s + 3), 'Employee', $size, false, 'center', 'top');
        $this->merge('D'.($s + 3).':F'.($s + 3), 'Supervisor', $size, false, 'center', 'top');
        $this->merge('H'.($s + 3).':L'.($s + 3), 'Head of Office', $size, false, 'center', 'bottom');

        $this->outline("A{$s}:M".($s + 3));
        $this->edge("A{$s}:M{$s}", 'bottom');
        $this->edge('B'.$s.':B'.($s + 3), 'right');
        $this->edge('C'.$s.':C'.($s + 3), 'right');
        $this->edge('F'.$s.':F'.($s + 3), 'right');
        $this->edge('G'.$s.':G'.($s + 3), 'right');
        $this->edge('L'.$s.':L'.($s + 3), 'right');
        $this->edge('A'.($s + 2).':B'.($s + 2), 'bottom');
        $this->edge('D'.($s + 2).':F'.($s + 2), 'bottom');
        $this->edge('H'.($s + 2).':L'.($s + 2), 'bottom');

        return $s + 4;
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

    private function seal(string $cell, int $height, int $offsetX = 4, int $offsetY = 2): void
    {
        $path = PcrPrint::sealPath();

        if (! $path || ! preg_match('/\.(png|jpe?g|gif)$/i', $path)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setPath($path);
        $drawing->setHeight($height);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
        $drawing->setWorksheet($this->sheet);
    }

    private function fill(string $range, string $rgb): void
    {
        $this->sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($rgb);
    }

    private function outline(string $range): void
    {
        $this->border($range, 'outline');
    }

    private function edge(string $range, string $side): void
    {
        $this->border($range, $side);
    }

    private function border(string $range, string $side): void
    {
        $this->sheet->getStyle($range)->applyFromArray([
            'borders' => [
                $side => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => '000000'],
                ],
            ],
        ]);
    }

    private function upper(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : mb_strtoupper($name);
    }

    private function partyName(string $who): string
    {
        $name = match ($who) {
            'reviewer' => $this->form->headReviewer?->name ?? $this->form->reviewed_by_name,
            'approver' => $this->form->vpReviewer?->name ?? $this->form->vp_reviewed_by_name,
            default    => $this->form->owner?->name ?? $this->ratee(),
        };

        return $this->upper($name);
    }

    private function longDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! $value instanceof \DateTimeInterface) {
            try {
                $value = \Illuminate\Support\Carbon::parse($value);
            } catch (\Throwable) {
                return '';
            }
        }

        return $value->format('j F Y');
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
