<?php
/**
 * Manage Seasons, spec 6.10.1.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $seasons
 * @var string|null $error
 * @var string|null $notice
 */
?>
<h1>Seasons</h1>

<p class="muted">
    A season wraps every roster, team, shift and check-in, so a finished year
    archives cleanly instead of accumulating. Exactly one is active at a time.
</p>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h2>Add a season</h2>

<form method="post" action="<?= e($app->url('admin/seasons')) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <div class="field">
        <label class="field__label" for="name">Name</label>
        <input class="field__input" id="name" name="name" type="text" required
               maxlength="80" placeholder="2027">
    </div>

    <div class="field">
        <label class="field__label" for="start_date">First day</label>
        <input class="field__input" id="start_date" name="start_date" type="date" required>
    </div>

    <div class="field">
        <label class="field__label" for="end_date">Last day</label>
        <input class="field__input" id="end_date" name="end_date" type="date" required>
    </div>

    <button class="button button--primary" type="submit">Create season</button>
</form>

<hr class="divider">

<h2><?= count($seasons) ?> season<?= count($seasons) === 1 ? '' : 's' ?></h2>

<?php if ($seasons === []): ?>
    <p class="muted">None yet.</p>
<?php endif; ?>

<?php foreach ($seasons as $season): ?>
    <div class="card">
        <div class="card__value">
            <?= e($season['name']) ?>
            <?php if ((int) $season['is_active'] === 1): ?>
                <span class="badge badge--ok">ACTIVE</span>
            <?php endif; ?>
        </div>
        <p class="muted card__note">
            <?= e($season['start_date']) ?> to <?= e($season['end_date']) ?> &middot;
            <?= e($season['team_count']) ?> team<?= (int) $season['team_count'] === 1 ? '' : 's' ?>
        </p>

        <?php if ((int) $season['is_active'] !== 1): ?>
            <form method="post" action="<?= e($app->url('admin/seasons')) ?>">
                <?= Resm\Csrf::field() ?>
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="season_id" value="<?= e($season['id']) ?>">
                <button class="button button--quiet" type="submit">Make this the active season</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
