<h1>Report locked</h1>
<p class="alert">You can edit drafts and returned reports. This report is currently <?= e(strtolower(\Dtrms\Models\StaffReports::STATUSES[$data['report']['status']])) ?>.</p>
<a class="button" href="/staff/reports/view?id=<?= (int) $data['report']['id'] ?>">View report</a>
