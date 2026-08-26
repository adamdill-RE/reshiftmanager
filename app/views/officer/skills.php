<?php
/**
 * Edit Certified Skills (spec 7.3, Capability::EditCertifiedSkills).
 *
 * What an officer has signed this man off for. It is not a permission and it
 * gates nothing on the assign board (7.4) — it is a fact shown beside his name
 * so whoever is placing people can decide.
 *
 * His own preferences are shown here and are not editable: only he sets those,
 * under Tools. The two are independent on purpose, and a man preferring
 * something he is not certified for is a training list nobody had to compile.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $person
 * @var array<int, array<string, mixed>> $allSkills
 * @var array<string, bool> $held
 * @var string|null $error
 */

$teamId = (int) $team['id'];
$shiftId = (int) $shift['id'];
$preferred = [];
foreach ($person['preferred'] as $skill) {
    $preferred[$skill['code']] = true;
}
?>
<h1><?= e($person['list_name']) ?></h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<p class="muted">Certified skills persist across shifts and seasons once set.</p>

<form method="post" action="<?= e($app->url('officer/skills/' . (int) $person['id'])) ?>">
    <?= Resm\Csrf::field() ?>
    <input type="hidden" name="team" value="<?= e($teamId) ?>">
    <input type="hidden" name="shift" value="<?= e($shiftId) ?>">

    <?php foreach (['position' => 'Position skills', 'equipment' => 'Equipment certifications'] as $kind => $heading): ?>
        <h2><?= e($heading) ?></h2>
        <?php if ($kind === 'equipment'): ?>
            <p class="muted">
                Forklift and Golf Cart correspond to no position. They are roster
                information and stay off the assign board's chip row (7.1).
            </p>
        <?php endif; ?>

        <div class="check-grid">
            <?php foreach ($allSkills as $skill): ?>
                <?php if ((string) $skill['kind'] !== $kind) { continue; } ?>
                <label class="check">
                    <input type="checkbox" name="skills[]" value="<?= e($skill['code']) ?>"
                           <?= isset($held[(string) $skill['code']]) ? 'checked' : '' ?>>
                    <span>
                        <?= e($skill['label']) ?>
                        <?php if (isset($preferred[(string) $skill['code']])): ?>
                            <span class="chip chip--preferred">prefers &#9734;</span>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <button class="button button--primary" type="submit">Save certifications</button>
</form>

<p>
    <a class="button button--quiet"
       href="<?= e($app->url('officer/roster') . Resm\Officer\OfficerMenu::query($teamId, $shiftId)) ?>">
        Back to the roster
    </a>
</p>
