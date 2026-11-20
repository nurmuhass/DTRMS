<h1><?= e($data['title']) ?></h1>
<?php $alertMessage = $data['message']; ?>
<?php require $directory . '/components/alert.php'; ?>
<p><a class="button button-secondary" href="/">Return to the system overview</a></p>
