<?php
/**
 * Create Shifts, spec 6.10.5.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $teams active teams in the season
 * @var array<int, array<string, mixed>> $groups every position group
 * @var array<int, int> $defaultGroups ticked on a new shift
 * @var array<int, Resm\ShiftType> $types
 * @var array<int, array<string, mixed>> $shifts
 * @var Resm\ShiftClock $clock
 * @var int|null $filterTeam
 * @var array<string, mixed> $form values to put back after a rejected submit
 * @var string|null $error
 * @var string|null $notice
 */

$url = $app->url('admin/shifts');
$formGroups = array_map('intval', (array) ($form['group_ids'] ?? $defaultGroups));
$formType = (string) ($form['shift_type'] ?? Resm\ShiftType::Weeknight->value);
$weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$formDays = array_map('intval', (array) ($form['weekdays'] ?? []));
?>
<h1>Create Shifts</h1>

<?php if ($season === null): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE SEASON</span>
        <p class="card__note">Shifts belong to a season. Create one and activate it first.</p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/seasons')) ?>">Go to Seasons</a></p>
    <?php return; ?>
<?php endif; ?>

<?php if ($teams === []): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE TEAMS</span>
        <p class="card__note">A shift belongs to a team, and <?= e((string) $season['name']) ?> has none yet.</p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/teams')) ?>">Go to Teams</a></p>
    <?php return; ?>
<?php endif; ?>

<p class="muted">
    In <strong><?= e((string) $season['name']) ?></strong>. Times are Houston
    local and stored as UTC, so the night the clocks change still runs the
    right length.
</p>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= e($url) ?>" id="shift-form">
    <?= Resm\Csrf::field() ?>

    <div class="field">
        <label class="field__label" for="team_id">Team</label>
        <select class="field__input" id="team_id" name="team_id" required>
            <?php foreach ($teams as $team): ?>
                <option value="<?= e($team['id']) ?>"
                    <?= (int) ($form['team_id'] ?? 0) === (int) $team['id'] ? 'selected' : '' ?>>
                    <?= e($team['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <fieldset class="field">
        <legend class="field__label">Type</legend>
        <?php foreach ($types as $type): ?>
            <label class="check">
                <input type="radio" name="shift_type" value="<?= e($type->value) ?>"
                       data-start="<?= e($type->defaultStart()) ?>"
                       data-end="<?= e($type->defaultEnd()) ?>"
                    <?= $formType === $type->value ? 'checked' : '' ?>>
                <span><?= e($type->label()) ?>
                    <span class="muted">— <?= e($type->summary()) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <div class="check-grid">
        <div class="field">
            <label class="field__label" for="start_time">Starts</label>
            <input class="field__input" id="start_time" name="start_time" type="time" required
                   value="<?= e((string) ($form['start_time'] ?? Resm\ShiftType::Weeknight->defaultStart())) ?>">
        </div>
        <div class="field">
            <label class="field__label" for="end_time">Ends</label>
            <input class="field__input" id="end_time" name="end_time" type="time" required
                   value="<?= e((string) ($form['end_time'] ?? Resm\ShiftType::Weeknight->defaultEnd())) ?>">
            <p class="field__hint">Earlier than the start means the next morning.</p>
        </div>
    </div>

    <hr class="divider">

    <fieldset class="field">
        <legend class="field__label">One night, or a whole pattern</legend>

        <label class="check">
            <input type="radio" name="mode" value="single"
                <?= ($form['mode'] ?? 'single') === 'single' ? 'checked' : '' ?>>
            <span>A single date</span>
        </label>

        <div class="field">
            <label class="field__label" for="date">Date</label>
            <input class="field__input" id="date" name="date" type="date"
                   value="<?= e((string) ($form['date'] ?? $season['start_date'] ?? '')) ?>">
        </div>

        <label class="check">
            <input type="radio" name="mode" value="range"
                <?= ($form['mode'] ?? '') === 'range' ? 'checked' : '' ?>>
            <span>Every chosen weekday in a range</span>
        </label>

        <div class="check-grid">
            <div class="field">
                <label class="field__label" for="from_date">From</label>
                <input class="field__input" id="from_date" name="from_date" type="date"
                       value="<?= e((string) ($form['from_date'] ?? $season['start_date'] ?? '')) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="to_date">To</label>
                <input class="field__input" id="to_date" name="to_date" type="date"
                       value="<?= e((string) ($form['to_date'] ?? $season['end_date'] ?? '')) ?>">
            </div>
        </div>

        <div class="check-grid">
            <?php foreach ($weekdayNames as $iso => $name): ?>
                <label class="check">
                    <input type="checkbox" name="weekdays[]" value="<?= e((string) $iso) ?>"
                        <?= in_array($iso, $formDays, true) ? 'checked' : '' ?>>
                    <span><?= e($name) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="field__hint">
            Tick no days and every date in the range is used, which is what the
            fair itself runs. At most <?= e((string) Resm\Admin\Shifts::MAX_RANGE) ?> days.
            A date that already has a shift starting at that time is left alone,
            so widening a range later adds only the new nights.
        </p>
    </fieldset>

    <fieldset class="field">
        <legend class="field__label">Position groups staffed</legend>
        <div class="check-grid">
            <?php foreach ($groups as $group): ?>
                <label class="check">
                    <input type="checkbox" name="group_ids[]" value="<?= e($group['id']) ?>"
                        <?= in_array((int) $group['id'], $formGroups, true) ? 'checked' : '' ?>>
                    <span><?= e($group['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="field__hint">
            All ten by default. The phase matrix already decides which of them
            appear in each phase, so untick only what genuinely is not running —
            a closed crosswalk, a stop out for weather.
        </p>
    </fieldset>

    <button class="button button--primary" type="submit">Create</button>
</form>

<hr class="divider">

<h2><?= count($shifts) ?> shift<?= count($shifts) === 1 ? '' : 's' ?></h2>

<form method="get" action="<?= e($url) ?>" class="field">
    <label class="field__label" for="team">Show</label>
    <select class="field__input" id="team" name="team" onchange="this.form.submit()">
        <option value="">Every team</option>
        <?php foreach ($teams as $team): ?>
            <option value="<?= e($team['id']) ?>" <?= $filterTeam === (int) $team['id'] ? 'selected' : '' ?>>
                <?= e($team['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="button button--quiet" type="submit">Filter</button></noscript>
</form>

<?php if ($shifts === []): ?>
    <p class="muted">None yet.</p>
<?php endif; ?>

<?php foreach ($shifts as $shift): ?>
    <?php
    $starts = new DateTimeImmutable((string) $shift['starts_at'], new DateTimeZone('UTC'));
    $ends = new DateTimeImmutable((string) $shift['ends_at'], new DateTimeZone('UTC'));
    $shiftGroups = Resm\Admin\Shifts::rowGroupIds($shift);
    $used = (int) $shift['check_count'] + (int) $shift['assignment_count'];
    ?>
    <div class="card">
        <div class="card__value">
            <?= e($clock->display($starts)) ?> &ndash; <?= e($clock->display($ends, 'H:i')) ?>
        </div>
        <p class="muted card__note">
            <?= e($shift['team_name']) ?> &middot;
            <?= e(Resm\ShiftType::from((string) $shift['shift_type'])->label()) ?> &middot;
            opens in <?= (string) $shift['current_phase'] === 'bump_run' ? 'Bump and Run' : 'Unload' ?> &middot;
            <?= e($shift['group_count']) ?> group<?= (int) $shift['group_count'] === 1 ? '' : 's' ?>
            <?php if ($used > 0): ?>
                &middot; <span class="badge badge--ok">IN USE</span>
            <?php endif; ?>
        </p>

        <details>
            <summary class="disclosure">Groups staffed</summary>
            <form method="post" action="<?= e($url) ?>" class="stack">
                <?= Resm\Csrf::field() ?>
                <input type="hidden" name="action" value="groups">
                <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
                <div class="check-grid">
                    <?php foreach ($groups as $group): ?>
                        <label class="check">
                            <input type="checkbox" name="group_ids[]" value="<?= e($group['id']) ?>"
                                <?= in_array((int) $group['id'], $shiftGroups, true) ? 'checked' : '' ?>>
                            <span><?= e($group['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="button button--quiet" type="submit">Save groups</button>
            </form>
        </details>

        <?php if ($used === 0): ?>
            <form method="post" action="<?= e($url) ?>">
                <?= Resm\Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
                <button class="button button--quiet" type="submit">Delete</button>
            </form>
        <?php else: ?>
            <p class="field__hint">
                People have checked in or been assigned on this shift, so it stays — the season is a record.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
