<?php
/**
 * First-run setup, for an account with no shell access.
 *
 * @var Resm\App $app
 * @var string $key
 * @var array<string, mixed> $state
 * @var string|null $error
 * @var string|null $notice
 * @var array<int, string> $log
 */

$admin = $state['admin'] ?? null;
$adminLocked = $admin !== null && !str_starts_with((string) $admin['pin_hash'], '$2y$');
?>
<h1>Setup</h1>

<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($log !== []): ?>
    <div class="card">
        <div class="card__label">Migration output</div>
        <?php foreach ($log as $line): ?>
            <div class="muted"><?= e($line) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>1. Database</h2>

<?php if ($state['dbError'] !== null): ?>
    <p class="alert alert--error">
        Cannot connect: <?= e($state['dbError']) ?>
    </p>

    <?php if ($state['dbDetail'] !== null): ?>
        <div class="card">
            <div class="card__label">What the database driver said</div>
            <p class="card__note"><?= e($state['dbDetail']) ?></p>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__label">What the application is trying</div>
        <p class="card__note"><?= e($state['dbTarget']) ?></p>
        <p class="muted card__note">
            user@host / database. If that is not what you set up, then
            <code>config.local.php</code> is not being read and these are the
            committed defaults.
        </p>
    </div>

    <p class="muted">
        Check the credentials in <code>config.local.php</code>. Nothing below
        will work until this connects.
    </p>
<?php else: ?>
    <p><span class="badge badge--ok">CONNECTED</span></p>

    <h2>2. Migrations</h2>

    <?php if ($state['drift'] !== []): ?>
        <p class="alert alert--error">
            <?php foreach ($state['drift'] as $problem): ?>
                <?= e($problem) ?><br>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <div class="card">
        <div class="card__label">Applied</div>
        <div class="card__value"><?= count($state['applied']) ?></div>
        <div class="card__label">Pending</div>
        <div class="card__value"><?= count($state['pending']) ?></div>
        <?php foreach ($state['pending'] as $name): ?>
            <p class="muted card__note"><?= e($name) ?></p>
        <?php endforeach; ?>
    </div>

    <?php if ($state['pending'] !== []): ?>
        <form method="post" action="<?= e($app->url('setup')) ?>">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="migrate">
            <button class="button button--primary" type="submit">
                Run <?= count($state['pending']) ?> pending migration<?= count($state['pending']) === 1 ? '' : 's' ?>
            </button>
        </form>
    <?php else: ?>
        <p><span class="badge badge--ok">UP TO DATE</span></p>
    <?php endif; ?>

    <h2>3. Administrator PIN</h2>

    <?php if ($admin === null): ?>
        <p class="muted">
            No administrator account yet — run the migrations above first.
        </p>
    <?php else: ?>
        <div class="card">
            <div class="card__label">Account</div>
            <div class="card__value">
                <?= e($admin['first_name']) ?> <?= e($admin['last_name']) ?>
            </div>
            <p class="muted card__note">
                Member ID <?= e($admin['member_id']) ?> &middot;
                <?php if ($adminLocked): ?>
                    <span class="badge badge--warn">NO PIN SET</span>
                <?php else: ?>
                    <span class="badge badge--ok">PIN SET</span>
                <?php endif; ?>
            </p>
        </div>

        <form method="post" action="<?= e($app->url('setup')) ?>">
            <?= Resm\Csrf::field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="set-pin">

            <div class="field">
                <label class="field__label" for="member_id">Member ID</label>
                <input class="field__input" id="member_id" name="member_id" type="text"
                       inputmode="numeric" required value="<?= e($admin['member_id']) ?>">
            </div>

            <div class="field">
                <label class="field__label" for="pin">New PIN</label>
                <input class="field__input" id="pin" name="pin" type="password"
                       inputmode="numeric" pattern="[0-9]*" maxlength="4"
                       autocomplete="new-password" required>
                <p class="field__hint">Four digits.</p>
            </div>

            <div class="field">
                <label class="field__label" for="confirm">New PIN again</label>
                <input class="field__input" id="confirm" name="confirm" type="password"
                       inputmode="numeric" pattern="[0-9]*" maxlength="4"
                       autocomplete="new-password" required>
            </div>

            <button class="button button--primary" type="submit">Set PIN</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<hr class="divider">

<div class="notice">
    <span class="badge badge--warn">LOCK THIS DOOR</span>
    <p class="card__note">
        Anyone with this page's key can take the administrator account. When
        the app is running, delete the <code>setup_key</code> line from
        <code>config.local.php</code> — with no key configured, this page stops
        existing.
    </p>
</div>
