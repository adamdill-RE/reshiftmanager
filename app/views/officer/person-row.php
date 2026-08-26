<?php
/**
 * One person, as every officer people-screen shows them.
 *
 * Name, state, position, phone as a tap-to-call link, and whatever actions the
 * screen offers. Shared so the five screens cannot drift apart on what a
 * roster row looks like.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $person
 * @var string $screen
 * @var int $teamId
 * @var int $shiftId
 * @var string $phase
 * @var bool $showSkills
 */

$clock = new Resm\ShiftClock($app->displayTimezone());
$showSkills = $showSkills ?? false;
$here = $app->url('officer/' . $screen);
$assignment = $person['assignments'][$phase] ?? null;
?>
<li class="person">
    <div class="person__head">
        <span class="person__name"><?= e($person['list_name']) ?></span>
        <?php if ($person['checked_in']): ?>
            <span class="badge badge--ok">IN</span>
        <?php elseif ($person['has_left']): ?>
            <span class="badge badge--warn">LEFT</span>
        <?php else: ?>
            <span class="badge badge--danger">NOT IN</span>
        <?php endif; ?>
        <?php if (($person['lunch'] ?? 'not_yet') === 'at_lunch'): ?>
            <span class="badge badge--warn">LUNCH</span>
        <?php endif; ?>
        <?php if ((int) $person['is_walkon'] === 1): ?>
            <span class="badge">WALK-ON</span>
        <?php endif; ?>
    </div>

    <div class="person__meta">
        <?php if ($assignment !== null): ?>
            <?= e($assignment['label']) ?>
            <?php if ($assignment['is_inherited']): ?><span class="badge">CARRIED</span><?php endif; ?>
        <?php elseif ($person['checked_in']): ?>
            <span class="muted">No position yet</span>
        <?php endif; ?>
        <?php if ($person['checked_at'] !== null): ?>
            &middot; <?= e($clock->display(
                new DateTimeImmutable((string) $person['checked_at'], new DateTimeZone('UTC')),
                'H:i'
            )) ?>
        <?php endif; ?>
    </div>

    <?php if ($showSkills): ?>
        <?= (new Resm\View($app))->render('officer/person-chips', [
            'app' => $app, 'person' => $person,
        ], layout: null) ?>
        <?php if (!empty($person['equipment'])): ?>
            <span class="chips">
                <?php foreach ($person['equipment'] as $skill): ?>
                    <span class="chip chip--equipment"><?= e($skill['label']) ?></span>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    <?php endif; ?>

    <div class="person__actions">
        <?php if ($person['phone_e164'] !== null): ?>
            <a class="button button--quiet" href="tel:<?= e($person['phone_e164']) ?>">
                Call<?= $person['phone'] === null ? '' : ' ' . e((string) $person['phone']) ?>
            </a>
        <?php endif; ?>

        <?php if ($user->can(Resm\Auth\Capability::CheckOthersInOut, $teamId)): ?>
            <form method="post" action="<?= e($here) ?>">
                <?= Resm\Csrf::field() ?>
                <input type="hidden" name="team" value="<?= e($teamId) ?>">
                <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                <input type="hidden" name="action" value="<?= $person['checked_in'] ? 'check-out' : 'check-in' ?>">
                <button class="button button--quiet" type="submit">
                    <?= $person['checked_in'] ? 'Check out' : 'Check in' ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($screen === 'roster' && $user->can(Resm\Auth\Capability::EditCertifiedSkills, $teamId)): ?>
            <a class="button button--quiet"
               href="<?= e($app->url('officer/skills/' . (int) $person['id'])
                   . Resm\Officer\OfficerMenu::query($teamId, $shiftId)) ?>">Skills</a>
        <?php endif; ?>

        <?php if (in_array($screen, ['roster', 'pins'], true)
            && $user->can(Resm\Auth\Capability::ResetCommitteemanPin, $teamId)): ?>
            <?php // Destructive enough to confirm: it signs the man out everywhere. ?>
            <details class="confirm">
                <summary class="button button--quiet">Reset PIN</summary>
                <form method="post" action="<?= e($here) ?>">
                    <?= Resm\Csrf::field() ?>
                    <input type="hidden" name="team" value="<?= e($teamId) ?>">
                    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                    <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                    <input type="hidden" name="action" value="reset-pin">
                    <p class="card__note">
                        Sets the PIN back to 1234 and signs this account out on every device.
                    </p>
                    <button class="button" type="submit">Reset to 1234</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</li>
