<?php
/**
 * Export Roster, spec 6.10.4.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $shifts   selector options, oldest first
 * @var array<string, mixed>|null $shift           the one being previewed
 * @var array<int, array<string, mixed>> $rows     preview rows for $shift
 * @var string|null $error
 */

use Resm\ShiftType;

$clock = static function (string $utc) use ($app): DateTimeImmutable {
    return $app->forDisplay(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
};
?>
<h1>Export Roster</h1>

<?php if ($season === null): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE SEASON</span>
        <p class="card__note">
            The export ranges over the active season's shifts. Create and
            activate a season first.
        </p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/seasons')) ?>">Go to Seasons</a></p>
    <?php return; ?>
<?php endif; ?>

<p class="muted">
    One shift at a time, as a CSV that Import Roster can take straight back.
    Phone numbers and emails are in it — it is personal data in bulk, so it
    belongs on an Admin's machine and nowhere else.
</p>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($shifts === []): ?>
    <p class="muted">No shifts in <strong><?= e($season['name']) ?></strong> yet.</p>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/shifts')) ?>">Go to Create Shifts</a></p>
    <?php return; ?>
<?php endif; ?>

<form method="get" action="<?= e($app->url('admin/export')) ?>">
    <div class="field">
        <label class="field__label" for="shift">Shift</label>
        <select class="field__input" id="shift" name="shift">
            <?php foreach ($shifts as $option): ?>
                <?php $start = $clock((string) $option['starts_at']); ?>
                <option value="<?= (int) $option['id'] ?>"
                    <?= $shift !== null && (int) $shift['id'] === (int) $option['id'] ? 'selected' : '' ?>>
                    <?= e($start->format('D M j')) ?> ·
                    <?= e(ShiftType::from((string) $option['shift_type'])->label()) ?> ·
                    <?= e($option['team_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button class="button button--primary" type="submit">Preview</button>
</form>

<?php if ($shift !== null): ?>
    <hr class="divider">

    <?php $checkedIn = count(array_filter($rows, static fn (array $r): bool => $r['check_in'] !== null)); ?>

    <h2>
        <?= e($clock((string) $shift['starts_at'])->format('l, F j')) ?> ·
        <?= e($shift['team_name']) ?>
    </h2>
    <p class="muted">
        <?= count($rows) ?> <?= count($rows) === 1 ? 'person' : 'people' ?>,
        <?= $checkedIn ?> with a check-in on record.
    </p>

    <p>
        <a class="button button--primary"
           href="<?= e($app->url('admin/export/csv?shift=' . (int) $shift['id'])) ?>">
            Download CSV
        </a>
    </p>

    <?php if ($rows !== []): ?>
        <div class="table-scroll">
            <table class="checks">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>In</th>
                        <th>Out</th>
                        <th>Unload</th>
                        <th>Bump &amp; Run</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['last_name']) ?>, <?= e($row['first_name']) ?></td>
                            <td><?= $row['check_in'] === null ? '—' : e($clock($row['check_in'])->format('H:i')) ?></td>
                            <td><?= $row['check_out'] === null ? '—' : e($clock($row['check_out'])->format('H:i')) ?></td>
                            <td><?= $row['position_unload'] === '' ? '—' : e($row['position_unload']) ?></td>
                            <td><?= $row['position_bump_run'] === '' ? '—' : e($row['position_bump_run']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
