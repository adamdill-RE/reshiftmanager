<?php
/**
 * Roster-first, second tap (spec 6.9.4): a name was tapped, and this is the
 * sheet of vacancies he could fill.
 *
 * The mode that clears a list of sixty people at the start of a shift, which
 * is why vacant critical positions are pinned to the top here too.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $person
 * @var array<int, array<string, mixed>> $vacancies
 * @var string $phase
 */

$self = 'officer/assign/' . $phase;
$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$action = $app->url($self);

// Vacant critical first: the same order the board itself uses.
usort($vacancies, static function (array $a, array $b): int {
    return ($b['is_critical'] <=> $a['is_critical']);
});
?>
<h1><?= e($person['list_name']) ?></h1>

<p class="muted">
    <?= (new Resm\View($app))->render('officer/person-chips', [
        'app' => $app, 'person' => $person,
    ], layout: null) ?>
</p>

<?php if (!empty($person['equipment'])): ?>
    <p class="muted">
        Also certified:
        <?= e(implode(', ', array_map(static fn (array $s): string => $s['label'], $person['equipment']))) ?>
    </p>
<?php endif; ?>

<h2>Where should he stand?</h2>

<?php if ($vacancies === []): ?>
    <p class="muted">Every position on this board is filled.</p>
<?php else: ?>
    <ul class="picks">
        <?php foreach ($vacancies as $position): ?>
            <li>
                <form method="post" action="<?= e($action) ?>">
                    <?= Resm\Csrf::field() ?>
                    <input type="hidden" name="team" value="<?= e($teamId) ?>">
                    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
                    <input type="hidden" name="mode" value="roster">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="position_id" value="<?= e($position['id']) ?>">
                    <input type="hidden" name="user_id" value="<?= e($person['id']) ?>">
                    <button class="pick pick--button <?= $position['is_critical'] && $position['holders'] === [] ? 'pick--critical' : '' ?>"
                            type="submit">
                        <span class="pick__name"><?= e($position['label']) ?></span>
                        <span class="pick__meta">
                            <?= e($position['group_label']) ?>
                            <?php if ($position['skill_label'] !== null): ?>
                                &middot; <?= e($position['skill_label']) ?>
                            <?php endif; ?>
                            <?php if ($position['is_radio']): ?> &middot; radio<?php endif; ?>
                            <?php if ($position['multi_assign'] && $position['holders'] !== []): ?>
                                &middot; <?= count($position['holders']) ?> already here
                            <?php endif; ?>
                        </span>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p>
    <a class="button button--quiet"
       href="<?= e($action . Resm\Officer\OfficerMenu::query($teamId, $shiftId, 'mode=roster')) ?>">
        Back to the list
    </a>
</p>
