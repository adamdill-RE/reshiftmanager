<?php
/**
 * @var Resm\App $app
 * @var array<int, array{name: string, status: string, detail: string}> $checks
 * @var string $overall
 */

$badge = [
    'pass' => 'badge--ok',
    'warn' => 'badge--warn',
    'fail' => 'badge--danger',
];
$word = ['pass' => 'OK', 'warn' => 'CHECK', 'fail' => 'FAILED'];
?>
<h1>Deployment status</h1>

<div class="notice">
    <span class="badge <?= e($badge[$overall]) ?>"><?= e($word[$overall]) ?></span>
    <span>
        <?= $overall === 'pass'
            ? 'Every check passed.'
            : 'Something below needs attention before this deploy is trusted.' ?>
    </span>
</div>

<table class="checks">
    <tbody>
    <?php foreach ($checks as $check): ?>
        <tr>
            <th scope="row"><?= e($check['name']) ?></th>
            <td>
                <span class="badge <?= e($badge[$check['status']]) ?>"><?= e($word[$check['status']]) ?></span>
                <div class="muted"><?= e($check['detail']) ?></div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="footnote">
    Served from <?= e($app->basePath()) ?> &middot; <?= e(gmdate('Y-m-d H:i:s')) ?> UTC
</p>
