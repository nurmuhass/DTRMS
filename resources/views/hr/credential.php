<h1><?= e($data['title']) ?></h1>
<section class="card hr-form">
    <h2>Temporary password</h2>
    <p>Deliver this password to <?= e($data['email']) ?> through the approved private channel. The user must change it at sign-in.</p>
    <p><code class="hr-credential"><?= e($data['password']) ?></code></p>
    <p>This password is shown once.</p>
    <a class="button" href="/hr-manager/staff">Return to staff directory</a>
</section>
