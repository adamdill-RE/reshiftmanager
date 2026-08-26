<?php
/**
 * The officer's aids on the assign board (spec 6.9.4): a chip row of the eight
 * position skills, and search by last name.
 *
 * Both are optional and advisory. The chips narrow the list because the
 * officer tapped one; nothing here decides who may stand where (7.4). Forklift
 * and Golf Cart are deliberately absent — they correspond to no position, and
 * a filter that cannot change the outcome costs the other chips their
 * credibility (7.1).
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $chips
 * @var array{search: string, skill: string} $filters
 * @var string $action
 * @var int $teamId
 * @var int $shiftId
 * @var string $mode
 */

$base = static fn (string $extra): string
    => $action . Resm\Officer\OfficerMenu::query($teamId, $shiftId, $extra);
?>
<form class="filters" method="get" action="<?= e($action) ?>">
    <input type="hidden" name="team" value="<?= e($teamId) ?>">
    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <?php if ($filters['skill'] !== ''): ?>
        <input type="hidden" name="skill" value="<?= e($filters['skill']) ?>">
    <?php endif; ?>

    <label class="field">
        <span class="field__label">Search by last name</span>
        <input class="field__input" type="search" name="q" value="<?= e($filters['search']) ?>"
               autocomplete="off" enterkeyhint="search">
    </label>
    <button class="button" type="submit">Search</button>
</form>

<div class="chiprow" role="group" aria-label="Filter by skill">
    <a class="chip chip--filter <?= $filters['skill'] === '' ? 'chip--on' : '' ?>"
       href="<?= e($base('mode=' . rawurlencode($mode))) ?>">All</a>
    <?php foreach ($chips as $chip): ?>
        <?php $on = $filters['skill'] === (string) $chip['code']; ?>
        <a class="chip chip--filter <?= $on ? 'chip--on' : '' ?>"
           href="<?= e($base('mode=' . rawurlencode($mode) . '&skill=' . rawurlencode((string) $chip['code']))) ?>">
            <?= e($chip['label']) ?>
        </a>
    <?php endforeach; ?>
</div>
