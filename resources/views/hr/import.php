<h1>Import staff</h1>
<section class="card hr-form">
    <h2>Excel staff template</h2>
    <?php if ($data['available']): ?>
        <p>Use one Staff worksheet with the original headers. Enter staff IDs and department codes as text. Each department code must belong to an active department.</p>
        <p><a href="/hr-manager/staff/template">Download Excel template</a></p>
        <form action="/hr-manager/staff/import" method="post" enctype="multipart/form-data" data-confirm="Import all staff accounts in this workbook?">
            <?php require $directory . '/auth/csrf.php'; ?>
            <div class="form-field">
                <label for="workbook">Completed workbook</label>
                <input type="file" id="workbook" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="workbook-limits" required>
                <small id="workbook-limits">.xlsx, up to 1 MB and 50 staff rows.</small>
            </div>
            <button class="button">Import staff</button>
        </form>
        <p>After importing, issue a temporary password to each user from Account access.</p>
    <?php else: ?>
        <p>Excel import is unavailable. Ask the system administrator to install the required Composer packages.</p>
    <?php endif; ?>
</section>
