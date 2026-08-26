<?php
/**
 * Preferred Skills (spec 7.3), under Tools.
 *
 * What this man would rather do. Officers see it beside his name on the assign
 * board, next to what he is certified for — two independent facts, and neither
 * decides anything. Who stands where is settled on the ground (7.4).
 *
 * @var Resm\App $app
 * @var array<int, array<string, mixed>> $skills
 * @var string|null $error
 * @var string|null $notice
 */
?>
<h1>Preferred Skills</h1>

<?php if ($error !== null): ?>
    <p class="alert alert--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <p class="alert alert--ok" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<p class="muted">
    Tick what you would rather do. An officer sees this beside your name when
    he is filling the board. It does not decide where you stand — that is a
    game-time call — and you can say you would rather do something you have not
    been signed off for yet.
</p>

<form method="post" action="<?= e($app->url('tools/skills')) ?>">
    <?= Resm\Csrf::field() ?>

    <div class="check-grid">
        <?php foreach ($skills as $skill): ?>
            <label class="check">
                <input type="checkbox" name="skills[]" value="<?= e($skill['code']) ?>"
                       <?= (int) ($skill['is_preferred'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>
                    <?= e($skill['label']) ?>
                    <?php if ($skill['granted_at'] !== null): ?>
                        <span class="chip chip--certified">certified</span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <button class="button button--primary" type="submit">Save</button>
</form>
