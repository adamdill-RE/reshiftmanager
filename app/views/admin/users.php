<?php
/**
 * Create Committeeman (spec 6.10.7) and Create Officer / Admin (spec 6.10.6).
 *
 * One template for both, because the fields are identical and only the role
 * differs. The route decides which roles it offers; when there is only one the
 * chooser disappears rather than showing a radio group with a single option.
 *
 * @var Resm\App $app
 * @var string $endpoint route this form posts to
 * @var string $heading
 * @var string $intro
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $teams active teams in the season
 * @var array<int, Resm\Auth\Role> $roles roles this screen may create
 * @var array<int, array<string, mixed>> $people
 * @var array<string, mixed> $form values to put back after a rejected submit
 * @var string $noun what one row on this screen is called
 * @var string $nounPlural
 * @var string $search
 * @var int $actorId the signed-in admin, who may not deactivate themselves
 * @var string $defaultPin
 * @var string|null $error
 * @var string|null $notice
 */

$url = $app->url($endpoint);
$showRole = count($roles) > 1;
$formTeams = array_map('intval', (array) ($form['team_ids'] ?? []));
?>
<h1><?= e($heading) ?></h1>

<?php if ($season === null): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE SEASON</span>
        <p class="card__note">
            People are added to the teams of a season. Create one and activate
            it first.
        </p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/seasons')) ?>">Go to Seasons</a></p>
    <?php return; ?>
<?php endif; ?>

<p class="muted"><?= e($intro) ?></p>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h2>Add a person</h2>

<form method="post" action="<?= e($url) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <div class="field">
        <label class="field__label" for="member_id">Member ID</label>
        <input class="field__input" id="member_id" name="member_id" type="text"
               inputmode="numeric" autocomplete="off" required maxlength="32"
               value="<?= e((string) ($form['member_id'] ?? '')) ?>">
        <p class="field__hint">How they sign in. It must be unique.</p>
    </div>

    <div class="field">
        <label class="field__label" for="first_name">First name</label>
        <input class="field__input" id="first_name" name="first_name" type="text"
               required maxlength="80" value="<?= e((string) ($form['first_name'] ?? '')) ?>">
    </div>

    <div class="field">
        <label class="field__label" for="last_name">Last name</label>
        <input class="field__input" id="last_name" name="last_name" type="text"
               required maxlength="80" value="<?= e((string) ($form['last_name'] ?? '')) ?>">
    </div>

    <div class="field">
        <label class="field__label" for="phone">Mobile phone</label>
        <input class="field__input" id="phone" name="phone" type="tel"
               inputmode="tel" autocomplete="off" maxlength="40"
               value="<?= e((string) ($form['phone'] ?? '')) ?>">
        <p class="field__hint">Optional. Becomes a tap-to-call link on the roster.</p>
    </div>

    <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="field__input" id="email" name="email" type="email"
               autocomplete="off" maxlength="190"
               value="<?= e((string) ($form['email'] ?? '')) ?>">
        <p class="field__hint">Optional, and never a login.</p>
    </div>

    <?php if ($showRole): ?>
        <fieldset class="field">
            <legend class="field__label">Role</legend>
            <?php foreach ($roles as $role): ?>
                <label class="check">
                    <input type="radio" name="role" value="<?= e($role->value) ?>"
                        <?= ($form['role'] ?? $roles[0]->value) === $role->value ? 'checked' : '' ?>>
                    <span><?= e($role->label()) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php else: ?>
        <input type="hidden" name="role" value="<?= e($roles[0]->value) ?>">
    <?php endif; ?>

    <fieldset class="field">
        <legend class="field__label">Teams in <?= e((string) $season['name']) ?></legend>

        <?php if ($teams === []): ?>
            <p class="field__hint">
                This season has no active teams yet.
                <a href="<?= e($app->url('admin/teams')) ?>">Create one</a> and
                the checkboxes appear here. A person can be added now and put
                on a team afterwards.
            </p>
        <?php else: ?>
            <div class="check-grid">
                <?php foreach ($teams as $team): ?>
                    <label class="check">
                        <input type="checkbox" name="team_ids[]" value="<?= e($team['id']) ?>"
                            <?= in_array((int) $team['id'], $formTeams, true) ? 'checked' : '' ?>>
                        <span><?= e($team['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="field__hint">
                Officers and Admins may cover several teams; a committeeman may
                belong to more than one.
            </p>
        <?php endif; ?>
    </fieldset>

    <p class="field__hint">
        The PIN starts at <strong><?= e($defaultPin) ?></strong>. Tell them to
        change it under Tools once they are in.
    </p>

    <button class="button button--primary" type="submit">Create account</button>
</form>

<hr class="divider">

<h2><?= count($people) ?> <?= e(count($people) === 1 ? $noun : $nounPlural) ?></h2>

<form method="get" action="<?= e($url) ?>" class="field">
    <label class="field__label" for="q">Search</label>
    <input class="field__input" id="q" name="q" type="search" maxlength="60"
           placeholder="Name or Member ID" value="<?= e($search) ?>">
    <p class="field__hint">
        Everyone with this role is listed, from every season — that is what
        stops a returning volunteer being created twice.
    </p>
</form>

<?php if ($people === []): ?>
    <p class="muted"><?= $search === '' ? 'None yet.' : 'Nobody matches that.' ?></p>
<?php endif; ?>

<?php foreach ($people as $person): ?>
    <?php $personTeams = Resm\Admin\Users::rowTeamIds($person); ?>
    <div class="card">
        <div class="card__value">
            <?= e($person['last_name']) ?>, <?= e($person['first_name']) ?>
            <?php if ((int) $person['is_active'] !== 1): ?>
                <span class="badge badge--warn">INACTIVE</span>
            <?php endif; ?>
            <?php if ($showRole): ?>
                <span class="badge"><?= e(Resm\Auth\Role::from((string) $person['role'])->label()) ?></span>
            <?php endif; ?>
            <?php if ($personTeams === []): ?>
                <span class="badge">NO TEAM</span>
            <?php endif; ?>
        </div>

        <p class="muted card__note">
            <?= e((string) ($person['member_id'] ?? 'no Member ID')) ?>
            <?php if (($person['phone'] ?? null) !== null): ?>
                &middot; <?= e((string) $person['phone']) ?>
            <?php endif; ?>
            <?php if (($person['team_names'] ?? null) !== null): ?>
                &middot; <?= e((string) $person['team_names']) ?>
            <?php endif; ?>
        </p>

        <?php if ($teams !== []): ?>
            <details>
                <summary class="disclosure">Teams in <?= e((string) $season['name']) ?></summary>
                <form method="post" action="<?= e($url) ?>" class="stack">
                    <?= Resm\Csrf::field() ?>
                    <input type="hidden" name="action" value="teams">
                    <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">

                    <div class="check-grid">
                        <?php foreach ($teams as $team): ?>
                            <label class="check">
                                <input type="checkbox" name="team_ids[]" value="<?= e($team['id']) ?>"
                                    <?= in_array((int) $team['id'], $personTeams, true) ? 'checked' : '' ?>>
                                <span><?= e($team['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button class="button button--quiet" type="submit">Save teams</button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ((int) $person['id'] !== $actorId): ?>
            <form method="post" action="<?= e($url) ?>">
                <?= Resm\Csrf::field() ?>
                <input type="hidden" name="action" value="<?= (int) $person['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                <button class="button button--quiet" type="submit">
                    <?= (int) $person['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                </button>
            </form>
        <?php else: ?>
            <p class="field__hint">This is you. Deactivating it would lock you out of this screen.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
