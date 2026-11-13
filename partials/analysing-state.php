<?php
// Analysing a photo (state S2, wireframe p24): shown while the Flask service runs the model (ML-17, UI half).
// Expects $title — what is happening, e.g. "Analysing leaves…" — and $cancel_url, the page to go back to.
// role="status" makes a screen reader announce the title. The moving bar only shows that something is happening.
// Shown in the style guide only since the release cleanup: the pages wait for Flask (10 seconds at most) before they
// draw, then show the result or the "couldn't reach" state, so a slow service never leaves the member stuck.
?>
<section class="form-card analysing" aria-labelledby="analysing-title" aria-busy="true">
  <h2 class="analysing-title" id="analysing-title" role="status"><span class="spinner" aria-hidden="true"></span> <?= e($title) ?></h2>
  <p class="analysing-text">This usually takes a few seconds.</p>
  <div class="analysing-bar" aria-hidden="true"><span></span></div>
  <a class="btn btn-ghost btn-sm" href="<?= e($cancel_url) ?>">Cancel</a>
</section>
