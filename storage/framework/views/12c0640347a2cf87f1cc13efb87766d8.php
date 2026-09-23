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
    <h1><?php echo e($user->name); ?></h1>
    <p class="role"><?php echo e($user->position_title); ?><?php if($user->orgUnit): ?> &middot; <?php echo e($user->orgUnit->name); ?><?php endif; ?></p>
    <p class="contact"><?php echo e($user->email); ?><?php if($profile?->mobile): ?> &middot; <?php echo e($profile->mobile); ?><?php endif; ?></p>

    <?php if($profile): ?>
        <h2>PERSONAL INFORMATION</h2>
        <table class="pairs">
            <tr>
                <td class="k">Date of birth</td><td><?php echo e($profile->date_of_birth?->format('F j, Y') ?: '—'); ?></td>
                <td class="k">Place of birth</td><td><?php echo e($profile->place_of_birth ?: '—'); ?></td>
            </tr>
            <tr>
                <td class="k">Sex</td><td><?php echo e(ucfirst($profile->sex ?: '—')); ?></td>
                <td class="k">Civil status</td><td><?php echo e(ucfirst($profile->civil_status ?: '—')); ?></td>
            </tr>
            <tr>
                <td class="k">Citizenship</td><td><?php echo e($profile->citizenship ?: '—'); ?></td>
                <td class="k">Blood type</td><td><?php echo e($profile->blood_type ?: '—'); ?></td>
            </tr>
            <tr>
                <td class="k">Address</td><td colspan="3"><?php echo e($profile->residential_address ?: '—'); ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <?php $__currentLoopData = [
        ['EDUCATIONAL BACKGROUND', $educations, ['Level' => 'level', 'School' => 'school', 'Degree' => 'degree', 'Graduated' => 'year_graduated']],
        ['CIVIL SERVICE ELIGIBILITY', $eligibilities, ['Eligibility' => 'eligibility', 'Rating' => 'rating', 'Licence' => 'licence_number']],
        ['WORK EXPERIENCE', $workExperiences, ['Position' => 'position', 'Office' => 'company', 'Status' => 'appointment_status']],
        ['TRAINING AND SEMINARS', $trainings, ['Title' => 'title', 'Hours' => 'hours', 'Conducted by' => 'conducted_by']],
        ['VOLUNTARY WORK', $voluntaryWorks, ['Organisation' => 'organization', 'Position' => 'position', 'Hours' => 'hours']],
    ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$heading, $rows, $columns]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <h2><?php echo e($heading); ?></h2>
        <?php if($rows->isEmpty()): ?>
            <p class="empty">Nothing recorded.</p>
        <?php else: ?>
            <table>
                <tr><?php $__currentLoopData = array_keys($columns); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><th><?php echo e($label); ?></th><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></tr>
                <?php $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr><?php $__currentLoopData = $columns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><td><?php echo e($row->{$field} ?: '—'); ?></td><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </table>
        <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/OCC_PMS/resources/views/pdf/profile.blade.php ENDPATH**/ ?>