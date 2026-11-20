<h1>HR dashboard</h1>
<p class="intro">Manage organizational accounts, departments, and supervisor coverage.</p>
<div class="card-grid">
    <article class="card hr-metric">
        <h2>Active departments</h2>
        <strong><?= (int) $data['metrics']['departments'] ?></strong>
        <a href="/hr-manager/departments">View departments</a>
    </article>
    <article class="card hr-metric">
        <h2>Active staff</h2>
        <strong><?= (int) $data['metrics']['staff'] ?></strong>
        <a href="/hr-manager/staff?role=staff&amp;status=active">View active staff</a>
    </article>
    <article class="card hr-metric">
        <h2>Current supervisor assignments</h2>
        <strong><?= (int) $data['metrics']['assignments'] ?></strong>
        <a href="/hr-manager/staff?role=staff">Review staff coverage</a>
    </article>
</div>
<div class="hr-actions">
    <a class="button" href="/hr-manager/staff/edit">Create account</a>
    <a class="button button-secondary" href="/hr-manager/departments/edit">Create department</a>
</div>
