<?php
/**
 * The Tarmac Map (spec 6.5, 11.4).
 *
 * The drawing is owed by Rodeo Express and has the longest lead time of
 * anything outstanding. Until it arrives this screen says so plainly and
 * stays useful anyway — it still names the position and the group, which is
 * what the man actually needs to find his spot by asking someone.
 *
 * There is deliberately no drawn tarmac here. A made-up layout would make the
 * screen look finished and would send a new committeeman confidently to the
 * wrong place, which is worse than sending him to ask.
 *
 * @var Resm\App $app
 * @var array<string, mixed>|null $shift
 * @var array<string, mixed>|null $assignment
 * @var string|null $svg the drawing, inlined, or null while it is owed
 * @var array<int, string> $needed what is still required, from spec 11.4
 * @var string|null $me this user's map_ref
 * @var array<int, string> $mates his group's map_refs
 */
?>
<h1>Tarmac Map</h1>

<?php if ($shift === null): ?>

    <div class="notice">
        <span class="badge">NO SHIFT</span>
        <p class="card__note">You are not on a shift right now.</p>
    </div>

<?php else: ?>

    <?php if ($assignment !== null): ?>
        <div class="card">
            <div class="card__label">Your position</div>
            <div class="card__value"><?= e((string) $assignment['position']) ?></div>
            <p class="card__note">
                <?= e((string) $assignment['group_label']) ?>
                <?php if ((int) $assignment['is_critical'] === 1): ?>
                    &middot; <span class="badge badge--danger">CRITICAL</span>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="notice">
            <span class="badge">NOT PLACED</span>
            <p class="card__note">
                An officer has not put you anywhere yet, so there is nothing to
                mark on the map.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($svg !== null): ?>

        <?php
        // Inlined rather than an <img>, because the highlight has to reach
        // individual elements inside the drawing and map.js addresses them by
        // the id that matches each position's map_ref.
        ?>
        <div class="map"
             data-map
             data-me="<?= e($me ?? '') ?>"
             data-mates="<?= e(implode(',', $mates)) ?>">
            <?= $svg ?>
        </div>

        <p class="field__hint">
            <span class="map__key map__key--me"></span> you
            &middot;
            <span class="map__key map__key--mate"></span> others in your group
        </p>

    <?php else: ?>

        <div class="notice">
            <span class="badge badge--warn">NOT SUPPLIED YET</span>
            <p class="card__note">
                The tarmac drawing has not been supplied. It is one of the
                things Rodeo Express still owes (spec&nbsp;11.4), and it is the
                one with the longest lead time &mdash; all 98 positions have to
                be marked on a site plan before any of it can be traced.
            </p>
        </div>

        <p class="muted">
            Rather than guess at a layout, this screen waits. A map that was
            invented would look finished and would send somebody confidently to
            the wrong place.
        </p>

        <h2>What is needed</h2>

        <ol class="install__steps">
            <?php foreach ($needed as $item): ?>
                <li><?= e($item) ?></li>
            <?php endforeach; ?>
        </ol>

        <p class="field__hint">
            Once it is supplied it drops in as
            <code>public/assets/map/tarmac.svg</code>, with each position's
            element id matching its <code>map_ref</code>. Nothing on this screen
            changes.
        </p>

    <?php endif; ?>

<?php endif; ?>
