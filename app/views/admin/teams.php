<?php
/**
 * Manage Teams, spec 6.10.2.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $teams
 * @var string|null $error
 * @var string|null $notice
 */
?>
<h1>Teams</h1>

<?php if ($season === null): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE SEASON</span>
        <p class="card__note">
            Teams belong to a season. Create one and activate it first.
        </p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/seasons')) ?>">Go to Seasons</a></p>
    <?php return; ?>
<?php endif; ?>

<p class="muted">
    In <strong><?= e($season['name']) ?></strong>. Teams are deactivated rather
    than deleted — shifts and check-in history point at them.
</p>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h2>Add a team</h2>

<form method="post" action="<?= e($app->url('admin/teams')) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <div class="field">
        <label class="field__label" for="name">Name</label>
        <input class="field__input" id="name" name="name" type="text" required
               maxlength="80" placeholder="Team A">
    </div>

    <button class="button button--primary" type="submit">Create team</button>
</form>

<hr class="divider">

<h2><?= count($teams) ?> team<?= count($teams) === 1 ? '' : 's' ?></h2>

<?php if ($teams === []): ?>
    <p class="muted">None yet.</p>
<?php endif; ?>

<?php foreach ($teams as $team): ?>
    <div class="card">
        <div class="card__value">
            <?= e($team['name']) ?>
            <?php if ((int) $team['is_active'] !== 1): ?>
                <span class="badge badge--warn">INACTIVE</span>
            <?php endif; ?>
        </div>
        <p class="muted card__note">
            <?= e($team['member_count']) ?> member<?= (int) $team['member_count'] === 1 ? '' : 's' ?> &middot;
            <?= e($team['shift_count']) ?> shift<?= (int) $team['shift_count'] === 1 ? '' : 's' ?>
        </p>

        <form method="post" action="<?= e($app->url('admin/teams')) ?>" class="stack">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="team_id" value="<?= e($team['id']) ?>">
            <label class="field__label" for="name-<?= e($team['id']) ?>">Rename</label>
            <input class="field__input" id="name-<?= e($team['id']) ?>" name="name"
                   type="text" required maxlength="80" value="<?= e($team['name']) ?>">
            <button class="button button--quiet" type="submit">Save name</button>
        </form>

        <form method="post" action="<?= e($app->url('admin/teams')) ?>">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="action" value="<?= (int) $team['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="team_id" value="<?= e($team['id']) ?>">
            <button class="button button--quiet" type="submit">
                <?= (int) $team['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
            </button>
        </form>
    </div>
<?php endforeach; ?>
