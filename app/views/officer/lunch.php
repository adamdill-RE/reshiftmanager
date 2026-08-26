<?php
/**
 * Lunch Management (spec 6.9.9).
 *
 * The roster filtered to lunch state, with counts across the three. Moving
 * someone to At Lunch vacates his position, because a spot held by a man who
 * is eating is a spot the board says is covered and is not. Moving him to Done
 * does not restore it: the officer places him again deliberately, which is
 * also what stops two men landing on one position by a return nobody saw.
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $people
 * @var array{not_yet: int, at_lunch: int, done: int} $lunchCounts
 * @var string $lunchFilter one of the three states, or '' for all
 * @var string|null $error
 * @var string|null $notice
 */

$self = 'officer/lunch';
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$states = ['not_yet' => 'Not yet', 'at_lunch' => 'At lunch', 'done' => 'Done'];
$only = $lunchFilter;
$here = $app->url($self);
?>
<h1>Lunch Management</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?= officerHeader($app, get_defined_vars(), $self) ?>

<div class="coverage" role="group" aria-label="Lunch state">
    <?php foreach ($states as $code => $label): ?>
        <a class="coverage__item <?= $only === $code ? 'coverage__item--on' : '' ?>"
           href="<?= e($here . Resm\Officer\OfficerMenu::query($teamId, $shiftId, 'state=' . $code)) ?>">
            <span class="coverage__figure"><?= e($lunchCounts[$code]) ?></span>
            <span class="coverage__label"><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
    <a class="coverage__item <?= $only === '' ? 'coverage__item--on' : '' ?>"
       href="<?= e($here . Resm\Officer\OfficerMenu::query($teamId, $shiftId)) ?>">
        <span class="coverage__figure"><?= e(count($people)) ?></span>
        <span class="coverage__label">All</span>
    </a>
</div>

<ul class="people">
    <?php foreach ($people as $person): ?>
        <?php
        $state = (string) ($person['lunch'] ?? 'not_yet');
        if ($only !== '' && $state !== $only) {
            continue;
        }
        $assignment = $person['assignments'][$phase] ?? null;
        ?>
        <li class="person">
            <div class="person__head">
                <span class="person__name"><?= e($person['list_name']) ?></span>
                <span class="badge <?= $state === 'at_lunch' ? 'badge--warn' : ($state === 'done' ? 'badge--ok' : '') ?>">
                    <?= e(mb_strtoupper($states[$state])) ?>
                </span>
                <?php if (!$person['checked_in']): ?>
                    <span class="badge badge--danger">NOT IN</span>
                <?php endif; ?>
            </div>

            <div class="person__meta">
                <?php if ($assignment !== null): ?>
                    <?= e($assignment['label']) ?>
                <?php else: ?>
                    <span class="muted">No position</span>
                <?php endif; ?>
            </div>

            <div class="person__actions">
                <?php foreach ($states as $code => $label): ?>
                    <?php if ($code === $state) { continue; } ?>
                    <form method="post" action="<?= e($here) ?>">
                        <?= Resm\Csrf::field() ?>
                        <input type="hidden" name="team" value="<?= e($teamId) ?>">
                        <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                        <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                        <input type="hidden" name="action" value="lunch">
                        <input type="hidden" name="state" value="<?= e($code) ?>">
                        <button class="button button--quiet" type="submit">
                            <?= e($label) ?><?= $code === 'at_lunch' && $assignment !== null ? ' (frees spot)' : '' ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
