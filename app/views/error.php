<?php
/**
 * @var string $heading
 * @var string $message
 */
?>
<h1><?= e($heading) ?></h1>
<p class="muted"><?= e($message) ?></p>
<p><a class="button button--quiet" href="<?= e($app->url()) ?>">Back to the start</a></p>
