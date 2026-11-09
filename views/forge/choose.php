<?php /* ui: hand-made: the first question when summoning a game, a 2D game or a 3D game, as two big choices (controls/Forge.php summon). */ ?>
<?= \app\Ui::page(['eyebrow' => 'The forge', 'title' => 'What kind of game?', 'lead' => 'Pick one. Your game starts empty, and the builder makes it from what you say it is.', 'back' => ['label' => 'Games', 'url' => '/forge']]) ?>
<style>
.kinds { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; max-width: 900px; margin: 8px auto 18px; }
.kind { display: grid; gap: 10px; justify-items: center; text-align: center; padding: 34px 22px 28px; border-radius: 22px; border: 2px solid var(--bs-border-color, rgba(255,255,255,.15)); background: var(--bs-tertiary-bg, rgba(255,255,255,.04)); color: inherit; text-decoration: none; transition: transform .12s, border-color .12s; min-height: 280px; }
.kind:hover, .kind:focus-visible { transform: translateY(-3px); border-color: #ffcc00; color: inherit; }
.kind .pic { width: 150px; height: 110px; } .kind b { font-size: 30px; line-height: 1.1; } .kind span { color: var(--bs-secondary-color, #b9b0cc); font-size: 16px; max-width: 34ch; }
.kinds-more { text-align: center; } .kinds-more a { display: inline-flex; align-items: center; min-height: 40px; }
@media (max-width: 640px) { .kinds { grid-template-columns: 1fr; } .kind { min-height: 0; } }
</style>
<div class="kinds">
  <a class="kind" href="/forge/summon?kind=2d<?= $idea !== '' ? '&amp;brief=' . h(rawurlencode($idea)) : '' ?>">
    <svg class="pic" viewBox="0 0 150 110" aria-hidden="true"><rect x="6" y="6" width="138" height="98" rx="12" fill="#2a2440"/><rect x="20" y="78" width="110" height="10" rx="3" fill="#6a5cc0"/><rect x="62" y="56" width="22" height="22" rx="4" fill="#ffcc00"/><circle cx="104" cy="40" r="9" fill="#9bf06a"/><rect x="34" y="44" width="18" height="10" rx="3" fill="#ff6b9d"/></svg>
    <b>2D game</b><span>Flat, drawn from the side or from above: platformers, puzzles, arcade, top-down.</span>
  </a>
  <a class="kind" href="/forge/summon?kind=3d<?= $idea !== '' ? '&amp;brief=' . h(rawurlencode($idea)) : '' ?>">
    <svg class="pic" viewBox="0 0 150 110" aria-hidden="true"><rect x="6" y="6" width="138" height="98" rx="12" fill="#1f2a3d"/><path d="M20 84 L75 62 L130 84 L75 104 Z" fill="#3b4a6b"/><path d="M62 52 L75 46 L88 52 L88 68 L75 74 L62 68 Z" fill="#ffcc00"/><path d="M62 52 L75 58 L88 52" fill="none" stroke="#b38f00" stroke-width="2"/><path d="M75 58 L75 74" stroke="#b38f00" stroke-width="2"/></svg>
    <b>3D game</b><span>A world with depth, seen through a camera: adventures, racers, explorers.</span>
  </a>
</div>
<p class="kinds-more"><a href="/forge/summon?kind=existing<?= $idea !== '' ? '&amp;brief=' . h(rawurlencode($idea)) : '' ?>">or start from an existing game</a></p>
