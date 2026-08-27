<?php
/**
 * Position Matrix Editor, spec 6.10.8 — the listing.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $groups   each with ['positions']
 * @var array{positions: int, phase_records: int, radio: int, critical: int, multi: int} $counts
 * @var array{positions: int, phase_records: int, radio: int, critical: int, multi: int} $baseline
 * @var string|null $notice
 * @var string|null $error
 */

$drifted = $counts !== $baseline;
?>
<h1>Position Matrix</h1>

<div class="card">
    <div class="card__label">Live counts <?= $drifted ? '· drifted from the seed' : '· matches the seed' ?></div>
    <div class="card__value">
        <?= (int) $counts['positions'] ?> positions ·
        <?= (int) $counts['phase_records'] ?> phase records
    </div>
    <p class="muted card__note">
        <?= (int) $counts['radio'] ?> radio · <?= (int) $counts['critical'] ?> critical ·
        <?= (int) $counts['multi'] ?> multi-assign.
        Seed baseline (spec 8.3): <?= (int) $baseline['positions'] ?> ·
        <?= (int) $baseline['phase_records'] ?> · <?= (int) $baseline['radio'] ?> radio ·
        <?= (int) $baseline['critical'] ?> critical · <?= (int) $baseline['multi'] ?> multi.
        Every change here is in the <a href="<?= e($app->url('admin/audit?action=position_update')) ?>">audit log</a>.
    </p>
</div>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
    <h2><?= e($group['label']) ?></h2>

    <?php if ($group['positions'] === []): ?>
        <p class="muted">No positions.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="checks">
                <thead>
                    <tr><th>Position</th><th>Phases</th><th>Flags</th><th>Skill</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($group['positions'] as $position): ?>
                        <tr>
                            <td>
                                <?= e($position['label']) ?>
                                <?php if ((int) $position['is_active'] !== 1): ?>
                                    <span class="badge badge--warn">RETIRED</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int) $position['in_unload'] === 1 ? 'U' : '' ?><?=
                                    (int) $position['in_unload'] === 1 && (int) $position['in_bump_run'] === 1 ? ' · ' : ''
                                ?><?= (int) $position['in_bump_run'] === 1 ? 'B&amp;R' : '' ?>
                            </td>
                            <td>
                                <?= (int) $position['is_radio'] === 1 ? 'Radio ' : '' ?>
                                <?= (int) $position['any_critical'] === 1 ? 'Critical ' : '' ?>
                                <?= (int) $position['any_multi'] === 1 ? 'Multi ' : '' ?>
                                <?= (int) $position['any_carry'] === 1 ? 'Carry' : '' ?>
                            </td>
                            <td><?= $position['skill_label'] === null ? '—' : e($position['skill_label']) ?></td>
                            <td>
                                <a class="button button--quiet"
                                   href="<?= e($app->url('admin/matrix/edit?id=' . (int) $position['id'])) ?>">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p>
        <a class="button button--quiet"
           href="<?= e($app->url('admin/matrix/edit?group=' . (int) $group['id'])) ?>">
            Add a position to <?= e($group['label']) ?>
        </a>
    </p>
<?php endforeach; ?>
