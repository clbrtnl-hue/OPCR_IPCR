<?php
    $totals = $payload['totals'];
    $label  = fn ($value) => $value === null ? '—' : ucfirst(str_replace('_', ' ', $value));
    $score  = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $pct    = fn ($value) => $value === null ? '—' : $value . '%';
?>
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
    <h1>PERFORMANCE REPORT — <?php echo e(mb_strtoupper($organization?->name ?? 'OPOL COMMUNITY COLLEGE')); ?></h1>
    <p class="sub">
        <?php echo e($year?->label); ?> · <?php echo e($period?->label ?? 'Whole year (both review periods)'); ?>

        · prepared <?php echo e(now()->format('F j, Y')); ?><?php if($preparedBy): ?> by <?php echo e($preparedBy); ?><?php endif; ?>
    </p>

    <table class="totals">
        <tr>
            <td><div class="k">College average</div><div class="v"><?php echo e($score($totals['college_average'])); ?></div>
                <div class="k"><?php echo e($totals['college_rating'] ?? 'Not yet rated'); ?></div></td>
            <td><div class="k">Forms in the cycle</div><div class="v"><?php echo e($totals['forms']); ?></div>
                <div class="k"><?php echo e($totals['submitted']); ?> submitted · <?php echo e($totals['rated']); ?> rated</div></td>
            <td><div class="k">Commitments</div><div class="v"><?php echo e($totals['commitments']); ?></div>
                <div class="k"><?php echo e($totals['completed']); ?> completed</div></td>
            <td><div class="k">Progress</div><div class="v"><?php echo e($pct($totals['progress_pct'])); ?></div>
                <div class="k">average across every line</div></td>
            <td><div class="k">Overdue</div><div class="v"><?php echo e($totals['overdue']); ?></div>
                <div class="k"><?php echo e($totals['due_soon']); ?> due within 7 days</div></td>
        </tr>
    </table>

    <h2>Where the forms are</h2>
    <table>
        <tr>
            <?php $__currentLoopData = $payload['by_status']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <th><?php echo e($label($status)); ?></th>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tr>
        <tr>
            <?php $__currentLoopData = $payload['by_status']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <td class="num"><?php echo e($count); ?></td>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tr>
    </table>

    <h2>Rating spread</h2>
    <table>
        <tr>
            <?php $__currentLoopData = $bands; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $band): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <th><?php echo e($band['value']); ?> — <?php echo e($band['label']); ?></th>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tr>
        <tr>
            <?php $__currentLoopData = $bands; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $band): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <td class="num"><?php echo e($payload['adjectival'][$band['label']] ?? 0); ?></td>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
            <?php $__currentLoopData = $payload['units']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $unit): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td><?php echo e($unit['name']); ?></td>
                    <td><?php echo e($unit['code'] ?: '—'); ?></td>
                    <td class="num"><?php echo e($unit['total_forms']); ?></td>
                    <td class="num"><?php echo e($unit['submitted']); ?></td>
                    <td class="num"><?php echo e($unit['rated']); ?></td>
                    <td class="num"><?php echo e($unit['commitments']); ?></td>
                    <td class="num"><?php echo e($pct($unit['progress_pct'])); ?></td>
                    <td class="num"><?php echo e($unit['overdue']); ?></td>
                    <td class="num"><?php echo e($score($unit['average'])); ?></td>
                    <td><?php echo e($unit['adjectival'] ?? 'Not yet rated'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
            <?php $__currentLoopData = $payload['heads']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $head): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td><?php echo e($head['head']); ?></td>
                    <td><?php echo e($head['position'] ?? '—'); ?></td>
                    <td><?php echo e($head['unit']); ?></td>
                    <td class="num"><?php echo e($head['forms']); ?></td>
                    <td class="num"><?php echo e($head['submitted']); ?></td>
                    <td class="num"><?php echo e($head['rated']); ?></td>
                    <td class="num"><?php echo e($head['commitments']); ?></td>
                    <td class="num"><?php echo e($pct($head['progress_pct'])); ?></td>
                    <td class="num"><?php echo e($head['overdue']); ?></td>
                    <td class="num"><?php echo e($score($head['average'])); ?></td>
                    <td><?php echo e($head['adjectival'] ?? 'Not yet rated'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
            <?php $__currentLoopData = $payload['people']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $person): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td><?php echo e($person['name']); ?></td>
                    <td><?php echo e($person['position'] ?? '—'); ?></td>
                    <td><?php echo e($person['unit']); ?></td>
                    <td class="num"><?php echo e($person['forms']); ?></td>
                    <td class="num"><?php echo e($person['commitments']); ?></td>
                    <td class="num"><?php echo e($person['completed']); ?></td>
                    <td class="num"><?php echo e($pct($person['progress_pct'])); ?></td>
                    <td class="num"><?php echo e($person['overdue']); ?></td>
                    <td class="num"><?php echo e($person['days_late'] ?: '—'); ?></td>
                    <td class="num"><?php echo e($score($person['average'])); ?></td>
                    <td><?php echo e($person['adjectival'] ?? 'Not yet rated'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
            <?php $__currentLoopData = $payload['forms']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $form): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td><?php echo e($form['type']); ?></td>
                    <td><?php echo e($form['owner']); ?></td>
                    <td><?php echo e($form['unit']); ?></td>
                    <td><?php echo e($label($form['status'])); ?></td>
                    <td><?php echo e($form['submitted_at'] ?? '—'); ?></td>
                    <td class="num"><?php echo e($form['commitments']); ?></td>
                    <td class="num"><?php echo e($pct($form['progress_pct'])); ?></td>
                    <td class="num"><?php echo e($form['overdue']); ?></td>
                    <td class="num"><?php echo e($score($form['average'])); ?></td>
                    <td><?php echo e($form['adjectival'] ?? 'Not yet rated'); ?></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>

    <?php if(count($payload['at_risk'])): ?>
        <h2>Commitments past their target date</h2>
        <table>
            <thead>
                <tr>
                    <th width="40%">Success indicator</th><th width="20%">Owner</th><th width="20%">Unit</th>
                    <th width="9%">Target date</th><th class="num">Days late</th><th class="num">Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $payload['at_risk']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $line): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td><?php echo e($line['description']); ?></td>
                        <td><?php echo e($line['owner']); ?></td>
                        <td><?php echo e($line['unit']); ?></td>
                        <td><?php echo e($line['target_date'] ?? '—'); ?></td>
                        <td class="num"><?php echo e($line['days_late']); ?></td>
                        <td class="num"><?php echo e($line['progress_pct']); ?>%</td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="foot">
        Progress is monitoring only — it never feeds the Q/E/T score. Generated by OCC PMS.
    </p>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/OCC_PMS/resources/views/pdf/report.blade.php ENDPATH**/ ?>