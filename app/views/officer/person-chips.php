<?php
/**
 * What is known about a man, shown beside his name (spec 7.3, 7.4).
 *
 * Certified is what an officer has signed off; preferred is what he told us he
 * would rather do. Two independent facts, and neither is a permission — they
 * are here so the officer can decide, and nothing else.
 *
 * @var Resm\App $app
 * @var array<string, mixed> $person
 */
?>
<span class="chips">
    <?php foreach ($person['certified'] as $skill): ?>
        <span class="chip chip--certified"><?= e($skill['label']) ?></span>
    <?php endforeach; ?>
    <?php foreach ($person['preferred'] as $skill): ?>
        <span class="chip chip--preferred"><?= e($skill['label']) ?> &#9734;</span>
    <?php endforeach; ?>
    <?php if (($person['lunch'] ?? 'not_yet') === 'at_lunch'): ?>
        <span class="chip chip--lunch">AT LUNCH</span>
    <?php endif; ?>
    <?php if (!empty($person['overlap'])): ?>
        <?php
        // Spec 5.5 and 6.9.4 rule 7. Neither officer can see the other's
        // board, so this is the only place the clash is visible to anybody.
        $clock = new Resm\ShiftClock($app->displayTimezone());
        $ends = new DateTimeImmutable((string) $person['overlap']['ends_at'], new DateTimeZone('UTC'));
        ?>
        <span class="chip chip--overlap">
            Also on <?= e($person['overlap']['team_name']) ?> until <?= e($clock->display($ends, 'H:i')) ?>
        </span>
    <?php endif; ?>
</span>
