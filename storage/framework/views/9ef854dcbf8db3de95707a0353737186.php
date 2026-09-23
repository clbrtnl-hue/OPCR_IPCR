<?php
    $isOpcr = $form->type === 'opcr';
    $ratee  = $form->owner?->name ?? $form->orgUnit?->name;
    $lines  = fn ($output) => $output->indicators->filter(
        fn ($i) => ! $i->rating_period_id || (int) $i->rating_period_id === (int) $period?->id
    );
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #000; }
        h1 { font-size: 12px; text-align: center; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.6px solid #000; padding: 3px 4px; vertical-align: top; }
        th { text-align: center; font-weight: bold; background: #f2f2f2; }
        .lead { margin: 0 0 10px; line-height: 1.45; }
        .band td { background: #e6e6e6; font-weight: bold; letter-spacing: 0.4px; }
        .num { color: #444; }
        .sign { width: 100%; margin-top: 26px; border: 0; }
        .sign td { border: 0; text-align: center; padding-top: 26px; font-size: 8px; }
        .sign .rule { border-top: 0.6px solid #000; padding-top: 3px; }
        .legend { margin-top: 10px; font-size: 7.5px; font-style: italic; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1><?php echo e($isOpcr ? 'OFFICE' : 'INDIVIDUAL'); ?> PERFORMANCE COMMITMENT AND REVIEW
        (<?php echo e($isOpcr ? 'OPCR' : 'IPCR'); ?>)</h1>

    <p class="lead">
        I, <strong><?php echo e(mb_strtoupper($ratee)); ?></strong>,
        <?php echo e($isOpcr ? 'Head of the ' . $organization->name : 'of the ' . $organization->name); ?>,
        commit to deliver and agree to be rated on the attainment of the following targets in
        accordance with the indicated measures for the period&nbsp;<strong><?php echo e($form->schoolYear?->label); ?></strong><?php if($isOpcr): ?> (January to December)<?php elseif($period): ?> (<?php echo e($period->label); ?>)<?php endif; ?>.
    </p>

    <table>
        <thead>
            <tr>
                <th rowspan="2" width="15%"><?php echo e($isOpcr ? 'MFO/PPA' : 'Output'); ?></th>
                <th rowspan="2" width="26%">Success Indicator<br><span class="muted">(Target + Measure)</span></th>
                <?php if($isOpcr): ?><th rowspan="2" width="9%">Alloted Budget</th><?php endif; ?>
                <?php if($isOpcr): ?><th rowspan="2" width="14%">Individual / Accountable</th><?php endif; ?>
                <th rowspan="2" width="<?php echo e($isOpcr ? 18 : 30); ?>%">Actual Accomplishments</th>
                <th colspan="4" width="12%">Rating</th>
                <th rowspan="2">Remarks</th>
            </tr>
            <tr><th>Q</th><th>E</th><th>T</th><th>A</th></tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = ['strategic' => 'STRATEGIC PRIORITY', 'core' => 'CORE FUNCTIONS', 'support' => 'SUPPORT FUNCTIONS']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $band): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php $outputs = $form->outputs->where('section', $key); ?>
                <?php if($outputs->isEmpty()) continue; ?>

                <tr class="band"><td colspan="<?php echo e($isOpcr ? 10 : 8); ?>"><?php echo e($band); ?></td></tr>

                <?php $__currentLoopData = $outputs->values(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $oi => $output): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $rows = $lines($output)->values(); ?>

                    <?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $li => $line): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <?php
                            $rating = $line->ratings->firstWhere('rating_period_id', $period?->id);
                            $acc    = $line->accomplishments->firstWhere('rating_period_id', $period?->id);
                            $people = $line->children->map(fn ($c) => $c->output?->form?->owner?->name)->filter();
                        ?>
                        <tr>
                            <?php if($li === 0): ?>
                                <td rowspan="<?php echo e(max($rows->count(), 1)); ?>">
                                    <span class="num"><?php echo e($oi + 1); ?>.</span> <?php echo e($output->title); ?>

                                </td>
                            <?php endif; ?>
                            <td>
                                <span class="num"><?php echo e($oi + 1); ?>.<?php echo e($li + 1); ?>.</span>
                                <?php echo e(trim(strip_tags($line->description))); ?>

                            </td>
                            <?php if($isOpcr): ?>
                                <td align="right">
                                    <?php echo e($line->allotted_budget ? number_format($line->allotted_budget, 2) : ''); ?>

                                </td>
                                <td><?php echo e($people->isNotEmpty() ? $people->implode(' / ') : $line->accountable); ?></td>
                            <?php endif; ?>
                            <td><?php echo $acc?->actual_accomplishment; ?></td>
                            <td align="center"><?php echo e($rating?->q); ?></td>
                            <td align="center"><?php echo e($rating?->e); ?></td>
                            <td align="center"><?php echo e($rating?->t); ?></td>
                            <td align="center"><?php echo e($rating?->a ? number_format($rating->a, 2) : ''); ?></td>
                            <td><?php echo $rating?->remarks; ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td><span class="num"><?php echo e($oi + 1); ?>.</span> <?php echo e($output->title); ?></td>
                            <td colspan="<?php echo e($isOpcr ? 9 : 7); ?>"></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

            <?php if($summary): ?>
                <tr class="band">
                    <td colspan="<?php echo e($isOpcr ? 8 : 6); ?>" align="right"><strong>Final Average Rating</strong></td>
                    <td align="center"><strong><?php echo e(number_format($summary->final_average, 2)); ?></strong></td>
                    <td><strong><?php echo e($summary->adjectival); ?></strong></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p class="legend">
        Legend: Q &ndash; Quality &nbsp; E &ndash; Efficiency &nbsp; T &ndash; Timeliness &nbsp; A &ndash; Average
    </p>

    <table class="sign">
        <tr>
            <td><div class="rule"><?php echo e($ratee); ?><br>Ratee</div></td>
            <td><div class="rule"><?php echo e($form->headReviewer?->name ?? $form->reviewed_by_name ?? ' '); ?><br>
                <?php echo e($form->headReviewer?->position_title ?? 'Immediate Supervisor'); ?></div></td>
            <td><div class="rule"><?php echo e($form->vpReviewer?->name ?? $form->vp_reviewed_by_name ?? ' '); ?><br>
                <?php echo e($organization->head_title ?? 'Head of Office'); ?></div></td>
        </tr>
    </table>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/OCC_PMS/resources/views/pdf/form.blade.php ENDPATH**/ ?>