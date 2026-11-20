<div class="report-heading">
    <div><h1>HR reporting dashboard</h1><p class="intro">Organizational reports and departmental submissions.</p></div>
    <a class="button" href="/hr/reports">Open approval queue</a>
</div>
<section class="staff-stats" aria-label="Organizational report totals">
    <?php foreach ([
        'total' => 'Total organizational reports',
        'pending_hr' => 'Awaiting HR review',
        'approved' => 'HR-approved reports',
        'returned' => 'Returned for correction',
        'pending_supervisor' => 'Pending supervisor approval',
    ] as $key => $label): ?>
        <div class="staff-stat card"><span><?= e($label) ?></span><strong><?= (int) $data['counts'][$key] ?></strong></div>
    <?php endforeach; ?>
</section>
<section class="card staff-panel">
    <h2>Departmental submission statistics</h2>
    <div class="table-scroll" role="region" aria-label="Departmental submission statistics" tabindex="0">
        <table class="hr-table">
            <thead><tr><th scope="col">Department</th><th scope="col">Submitted reports</th><th scope="col">Pending supervisor</th>
                <th scope="col">Awaiting HR</th><th scope="col">HR approved</th><th scope="col">Returned</th></tr></thead>
            <tbody>
                <?php foreach ($data['departments'] as $department): ?>
                    <tr><th scope="row"><?= e($department['name']) ?><small><?= e($department['code']) ?></small></th>
                        <td><?= (int) $department['submitted'] ?></td><td><?= (int) $department['pending_supervisor'] ?></td>
                        <td><?= (int) $department['pending_hr'] ?></td><td><?= (int) $department['approved'] ?></td>
                        <td><?= (int) $department['returned'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$data['departments']): ?><tr><td colspan="6">No departments have been configured.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
