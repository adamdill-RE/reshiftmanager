<?php
/**
 * Import Roster, spec 6.10.3.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $season
 * @var array<int, array<string, mixed>> $teams
 * @var array<string, mixed>|null $plan the dry run, when one is pending
 * @var string|null $fileName
 * @var string|null $error
 * @var string|null $notice
 */

$url = $app->url('admin/import');
$counts = $plan['counts'] ?? null;
$problems = $plan === null
    ? []
    : array_values(array_filter(
        $plan['rows'],
        static fn (array $r): bool => $r['action'] === 'error' || $r['action'] === 'skip'
    ));
?>
<h1>Import Roster</h1>

<?php if ($season === null): ?>
    <div class="notice">
        <span class="badge badge--warn">NO ACTIVE SEASON</span>
        <p class="card__note">A roster is imported into the teams of a season. Create one and activate it first.</p>
    </div>
    <p><a class="button button--quiet" href="<?= e($app->url('admin/seasons')) ?>">Go to Seasons</a></p>
    <?php return; ?>
<?php endif; ?>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($plan === null): ?>

    <p class="muted">
        Into <strong><?= e((string) $season['name']) ?></strong>. Nothing is
        written when you upload &mdash; the file is read, checked, and shown
        back to you first.
    </p>

    <form method="post" action="<?= e($url) ?>" enctype="multipart/form-data">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="action" value="dry-run">

        <div class="field">
            <label class="field__label" for="roster">Roster file</label>
            <input class="field__input" id="roster" name="roster" type="file"
                   accept=".csv,text/csv" required>
            <p class="field__hint">
                Columns: Lastname, Firstname, Member_ID, Phone, Email, Team.
                A header row is read by name in any order; without one the
                columns are taken in that order.
            </p>
        </div>

        <button class="button button--primary" type="submit">Check the file</button>
    </form>

    <hr class="divider">

    <h2>What the import does</h2>
    <div class="card">
        <p class="card__note">
            People are matched on <strong>Member ID</strong>, never email &mdash;
            spouses share an address, so it is not a safe key.
        </p>
        <p class="card__note">
            A new person is created as a Committeeman with PIN
            <strong><?= e($app->config->string('auth.default_pin', '1234')) ?></strong>.
            An existing committeeman is updated. An Officer or Admin is left
            entirely alone and reported.
        </p>
        <p class="card__note">
            The Team column must name an active team in this season:
            <?= $teams === [] ? 'none yet' : e(implode(', ', array_map(
                static fn (array $t): string => (string) $t['name'],
                $teams
            ))) ?>. Leave it blank to add someone without a team.
        </p>
    </div>

<?php else: ?>

    <p class="muted">
        <strong><?= e((string) $fileName) ?></strong> read into
        <strong><?= e((string) $season['name']) ?></strong>. Nothing has been
        written yet.
    </p>

    <div class="card">
        <div class="card__label">Dry run</div>
        <div class="card__value">
            <?= e((string) $counts['new']) ?> new &middot;
            <?= e((string) $counts['update']) ?> updated
            <?php if ($counts['reactivate'] > 0): ?>
                &middot; <?= e((string) $counts['reactivate']) ?> reactivated
            <?php endif; ?>
            &middot; <?= e((string) $counts['skip']) ?> skipped
            &middot; <?= e((string) $counts['error']) ?> error<?= $counts['error'] === 1 ? '' : 's' ?>
        </div>
        <?php if ($counts['reactivate'] > 0): ?>
            <p class="muted card__note">
                Reactivated accounts were switched off and are on this roster,
                so importing turns them back on.
            </p>
        <?php endif; ?>
    </div>

    <?php foreach ($plan['warnings'] as $warning): ?>
        <p class="alert alert--warn" role="status"><?= e($warning) ?></p>
    <?php endforeach; ?>

    <?php if ($problems !== []): ?>
        <h2><?= count($problems) ?> row<?= count($problems) === 1 ? '' : 's' ?> needing attention</h2>
        <p>
            <a class="button button--quiet" href="<?= e($app->url('admin/import/errors')) ?>">
                Download the report
            </a>
        </p>

        <?php foreach (array_slice($problems, 0, 30) as $row): ?>
            <div class="card">
                <div class="card__value">
                    <?= e(trim($row['last_name'] . ', ' . $row['first_name'], ', ')) ?: 'No name' ?>
                    <span class="badge badge--<?= $row['action'] === 'skip' ? 'warn' : 'danger' ?>">
                        <?= $row['action'] === 'skip' ? 'SKIPPED' : 'ERROR' ?>
                    </span>
                </div>
                <p class="muted card__note">
                    Line <?= e((string) $row['line']) ?><?php if ($row['member_id'] !== ''): ?>
                        &middot; <?= e((string) $row['member_id']) ?>
                    <?php endif; ?>
                </p>
                <p class="card__note"><?= e((string) $row['reason']) ?></p>
            </div>
        <?php endforeach; ?>

        <?php if (count($problems) > 30): ?>
            <p class="field__hint">
                The first 30 are shown. The report has all <?= count($problems) ?>.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <hr class="divider">

    <?php if ($counts['new'] + $counts['update'] + $counts['reactivate'] === 0): ?>
        <p class="alert alert--error" role="alert">
            There is nothing here to import. Fix the file and upload it again.
        </p>
    <?php else: ?>
        <form method="post" action="<?= e($url) ?>">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="action" value="commit">
            <p class="field__hint">
                Skipped and errored rows are not written. Everything else is.
            </p>
            <button class="button button--primary" type="submit">
                Import <?= e((string) ($counts['new'] + $counts['update'] + $counts['reactivate'])) ?> people
            </button>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= e($url) ?>">
        <?= Resm\Csrf::field() ?>
        <input type="hidden" name="action" value="discard">
        <button class="button button--quiet" type="submit">Start over</button>
    </form>

<?php endif; ?>
