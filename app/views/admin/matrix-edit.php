<?php
/**
 * Position Matrix Editor, spec 6.10.8 — one position's form, shared by add
 * and edit. $position is null when adding.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $position
 * @var array<int, array<string, mixed>> $groups
 * @var int|null $groupId   preselected group when adding
 * @var string|null $error
 */

$editing = $position !== null;
$phases = $position['phases'] ?? [
    'unload' => ['present' => false, 'multi_assign' => false, 'carry_forward' => false, 'is_critical' => false],
    'bump_run' => ['present' => false, 'multi_assign' => false, 'carry_forward' => false, 'is_critical' => false],
];
$selectedGroup = $editing ? (int) $position['group_id'] : ($groupId ?? 0);
?>
<h1><?= $editing ? 'Edit ' . e($position['label']) : 'Add a position' ?></h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($editing && (int) $position['is_active'] !== 1): ?>
    <p class="notice">
        <span class="badge badge--warn">RETIRED</span>
        Off every board, history intact. Restore it below to bring it back.
    </p>
<?php endif; ?>

<form method="post" action="<?= e($app->url('admin/matrix')) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int) $position['id'] ?>">
    <?php endif; ?>

    <div class="field">
        <label class="field__label" for="label">Name</label>
        <input class="field__input" id="label" name="label" type="text" required maxlength="80"
               value="<?= $editing ? e($position['label']) : '' ?>">
        <p class="muted card__note">
            The job skill follows the name by the spec 7.2 rule — Gate names
            take Gate, Starter names take Starter, and so on. Radio is the one
            flag that stays a choice, below.
        </p>
    </div>

    <div class="field">
        <label class="field__label" for="group_id">Group</label>
        <select class="field__input" id="group_id" name="group_id">
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"
                    <?= (int) $group['id'] === $selectedGroup ? 'selected' : '' ?>>
                    <?= e($group['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label class="field__label" for="sort_order">Sort order</label>
        <input class="field__input" id="sort_order" name="sort_order" type="number"
               min="0" max="65535" inputmode="numeric"
               value="<?= $editing ? (int) $position['sort_order'] : 0 ?>">
        <p class="muted card__note">Lower sorts first within the group.</p>
    </div>

    <div class="field">
        <label class="check">
            <input type="checkbox" name="is_radio" value="1"
                <?= $editing && (int) $position['is_radio'] === 1 ? 'checked' : '' ?>>
            Radio position
        </label>
    </div>

    <?php foreach (['unload' => 'Unload', 'bump_run' => 'Bump and Run'] as $phase => $phaseLabel): ?>
        <h2><?= e($phaseLabel) ?></h2>
        <div class="field">
            <label class="check">
                <input type="checkbox" name="phases[<?= e($phase) ?>][present]" value="1"
                    <?= $phases[$phase]['present'] ? 'checked' : '' ?>>
                Present in <?= e($phaseLabel) ?>
            </label>
            <label class="check">
                <input type="checkbox" name="phases[<?= e($phase) ?>][is_critical]" value="1"
                    <?= $phases[$phase]['is_critical'] ? 'checked' : '' ?>>
                Critical
            </label>
            <label class="check">
                <input type="checkbox" name="phases[<?= e($phase) ?>][multi_assign]" value="1"
                    <?= $phases[$phase]['multi_assign'] ? 'checked' : '' ?>>
                Multi-assign
            </label>
            <label class="check">
                <input type="checkbox" name="phases[<?= e($phase) ?>][carry_forward]" value="1"
                    <?= $phases[$phase]['carry_forward'] ? 'checked' : '' ?>>
                Carry forward (both phases or neither)
            </label>
        </div>
    <?php endforeach; ?>

    <button class="button button--primary" type="submit">
        <?= $editing ? 'Save changes' : 'Create position' ?>
    </button>
    <a class="button button--quiet" href="<?= e($app->url('admin/matrix')) ?>">Cancel</a>
</form>

<?php if ($editing): ?>
    <hr class="divider">

    <form method="post" action="<?= e($app->url('admin/matrix')) ?>">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $position['id'] ?>">
        <?php if ((int) $position['is_active'] === 1): ?>
            <input type="hidden" name="action" value="retire">
            <p class="muted">
                Retiring takes it off every board and keeps every record that
                points at it. Nothing is deleted.
            </p>
            <button class="button button--quiet" type="submit">Retire this position</button>
        <?php else: ?>
            <input type="hidden" name="action" value="restore">
            <button class="button button--primary" type="submit">Restore this position</button>
        <?php endif; ?>
    </form>
<?php endif; ?>
