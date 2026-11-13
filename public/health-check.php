<?php
// Plant Health Check (P08, wireframe p15): answers "What is wrong with my plant?" with the Pl@ntNet disease API (through
// Flask, decision D27; our own model later if there is time), then turns the result into clear, practical next steps
// (ML-14, ML-16). Members only. How sure a result is comes from disease_level() (helpers.php, D28).
// The page has five states, each with its own address:
//   health-check.php               → no photo yet; ?plant=47 links the check to Tommy (the Plant Profile's button)
//   health-check.php?prediction=7  → a diagnosis: what we see, what to do now, save and follow up (p15)
//   health-check.php?prediction=8  → S3: the model is not sure, so no diagnosis is given (p24)
//   health-check.php?prediction=9  → S3: a plant the model does not know (Monty), so it does not guess (p24)
//   health-check.php?offline       → the ML service did not answer: log the update by hand
// &plant= changes the plant a result is attached to (&plant=none: none). Sending a leaf: PHP checks and saves the
// photo, sends it to Flask with cURL (includes/ml-client.php), logs the answer in ml_predictions and opens
// health-check.php?prediction= of the new row; no answer: ?offline. Save adds the result to the plant's Story.

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/ml-client.php';

// Members only: a visitor is sent to Log in and comes back here afterwards (includes/auth.php, CORE-10)
require_login();

$my_plants = array_column($mock_plants, null, 'id');

// ── Which result ────────────────────────────────────────
// ?prediction= must be one of the member's own results, as on the Plant Identifier.

$prediction_param = get_text('prediction');
$result = ctype_digit($prediction_param) ? get_prediction($current_user['id'], (int) $prediction_param, 'disease') : null;

// ── Which plant (p15, element 1) ────────────────────────
// ?plant= wins (the plant menu, the Plant Profile's button); otherwise the plant the result is attached to.
// Only the member's own plants can be linked; 'none' means "Not from my garden".

/** The member's plant for a 'plant' value from the address or a form, or null for 'none', nothing or a wrong id. */
function chosen_plant($value, $my_plants)
{
    return ctype_digit($value) ? ($my_plants[(int) $value] ?? null) : null;
}

$plant_param = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('plant') : get_text('plant');
$plant = $plant_param !== '' ? chosen_plant($plant_param, $my_plants)
                             : ($result && $result['plant_id'] !== null ? ($my_plants[$result['plant_id']] ?? null) : null);

// ── Check a leaf · Save · Re-check (Post/Redirect/Get) ──
// The CSRF token first. Then, for a photo: finfo (JPG/PNG) and 5 MB in save_upload(), as on the plant form.

$photo_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action      = post_text('action');
    $plant_value = $plant ? $plant['id'] : 'none';

    if ($action === 'check') {
        $upload = save_upload('photo', 'scans');
        if (isset($upload['error'])) {
            $photo_error = $upload['error'];
        } elseif ($upload['path'] === null) {
            $photo_error = 'Choose a photo of the leaf first.';
        } else {
            $file   = rtrim(config('upload_dir'), '/\\') . '/' . $upload['path'];
            $answer = ml_predict('disease', $file, (new finfo(FILEINFO_MIME_TYPE))->file($file));
            if (!$answer['ok']) {
                $_SESSION['ml_offline_photo'] = ['image_path' => $upload['path'], 'upload_name' => $_FILES['photo']['name'],
                                                 'upload_size' => (int) $_FILES['photo']['size'], 'created_at' => date('Y-m-d H:i:s')];
                header('Location: ' . query_url('health-check.php', ['offline' => 1, 'plant' => $plant_value]));
                exit;
            }
            $id = save_prediction($current_user['id'], $plant['id'] ?? null, 'disease', $answer, $upload['path'],
                                  $_FILES['photo']['name'], (int) $_FILES['photo']['size']);
            // No garden plant chosen: also ask which plant this is, so the result can name it. Logged like any species
            // result (same photo); if it fails, the diagnosis is shown without a plant name.
            if (!$plant) {
                $species_answer = ml_predict('species', $file, (new finfo(FILEINFO_MIME_TYPE))->file($file));
                if ($species_answer['ok']) {
                    save_prediction($current_user['id'], null, 'species', $species_answer, $upload['path'],
                                    $_FILES['photo']['name'], (int) $_FILES['photo']['size']);
                }
            }
            header('Location:' . query_url('health-check.php', ['prediction' => $id, 'plant' => $plant_value]));
            exit;
        }
    } elseif ($result && $plant && in_array($action, ['save', 'recheck'], true)
              && disease_level($result['predictions'])['key'] !== 'low') {   // a "not sure" result is never saved as an answer
        if ($action === 'save') {
            // "Save to Tommy's story" (p15, element 6): a health_check entry linked to this result, with the status the
            // disease gives the plant (disease_guides.status), and the result attached to the plant (ML-16)
            $guide  = disease_guide($result['predicted_label']);
            $status = $guide['status'] ?? (str_ends_with(strtolower($result['predicted_label']), 'healthy') ? 'thriving' : 'needs_attention');
            // One Story entry per result and plant: Save pressed twice (a double click, or Back and Save again)
            // must not write the same diagnosis into the Story a second time.
            $saved = db()->prepare('SELECT 1 FROM health_logs WHERE prediction_id = :prediction AND plant_id = :plant');
            $saved->execute(['prediction' => $result['id'], 'plant' => $plant['id']]);
            if (!$saved->fetchColumn()) {
                add_story_entry($current_user['id'], $plant['id'], [
                    'type' => 'health_check', 'care_task' => null, 'status' => $status, 'source' => 'ml',
                    'prediction_id' => $result['id'], 'note' => prediction_name($result) . ' found by the Health Check.',
                    'photo' => $result['image_path'], 'logged_at' => date('Y-m-d H:i:s'),
                ]);
            }
            attach_prediction($current_user['id'], $result['id'], $plant['id']);
            header('Location: ' . url('plant.php?id=' . $plant['id']) . '#story-title');
            exit;
        }
        // "Remind me to re-check in 7 days": a one-off recheck reminder (DES-07); a second one moves its date
        save_reminder($current_user['id'], [
            'plant_id' => $plant['id'], 'task' => 'recheck', 'remind_time' => '08:00:00', 'repeat_type' => 'once',
            'repeat_days' => [], 'interval_days' => null, 'last_done' => null,
            'next_due' => date('Y-m-d', strtotime('+7 days')),
        ]);
        header('Location: ' . query_url('health-check.php', ['prediction' => $result['id'], 'plant' => $plant['id'], 'reminder' => 1]));
        exit;
    }
}

// ── Which state ─────────────────────────────────────────

$is_offline = !$result && isset($_GET['offline']);
// After a "couldn't reach", the member's own photo (kept in the session)
$offline_photo = $is_offline ? ($_SESSION['ml_offline_photo'] ?? null) : null;
$photo = $result ?? $offline_photo;   // no photo yet: the page shows the upload box

// Our own model (if we train one) only knows the crops it was trained on: a linked plant of another species is never
// guessed at (p24, S3). Pl@ntNet decides for itself which plants it knows, so its results skip this check.
$plant_species = $plant ? ($mock_species[$plant['species_id']] ?? null) : null;
$is_plantnet   = $result && is_plantnet_disease($result['predictions'][0]['label']);
$is_supported  = $is_plantnet || !$plant_species || in_array($plant_species['common_name'], $mock_supported_crops, true);

if ($result) {
    $top          = $result['predictions'][0];
    $top_level    = disease_level($result['predictions']);
    $is_unsure    = $top_level['key'] === 'low';
    $guide        = disease_guide($top['label']);
    $is_healthy   = str_ends_with(strtolower($top['label']), 'healthy');
    // "Tomato leaf": our model's crop from the guide or the class name ("Pepper,_bell" gives "Pepper, bell");
    // Pl@ntNet names no crop, so the linked plant's species is used, or no tag at all
    // Which plant is in the photo when no garden plant was chosen: the species answer logged with the same photo.
    // Our species list gives the common name ("Swiss cheese plant"); a species we do not list keeps its scientific name.
    $identified = null;
    if (!$plant && ($species_result = species_for_photo($current_user['id'], $result['image_path']))) {
        $species_top   = $species_result['predictions'][0];
        $known_species = array_column($mock_species, null, 'scientific_name')[$species_top['label']] ?? null;
        $identified    = [
            'name'       => $known_species['common_name'] ?? $species_top['label'],
            'scientific' => $species_top['label'],
            'percent'    => round($species_top['confidence'] * 100),
            'level'      => confidence_level($species_top['confidence']),   // species keep the fixed words
        ];
    }
    $is_identified = $identified && $identified['level']['key'] !== 'low';
    $crop         = $is_plantnet ? ($plant['common_name'] ?? ($is_identified ? $identified['name'] : null))
                                 : ($guide['crop'] ?? str_replace('_', ' ', explode('___', $top['label'])[0]));
    // The line under the name: our guide's cause, or Pl@ntNet's scientific name ("Phytophthora infestans")
    $cause        = ($guide['cause'] ?? '') !== '' ? $guide['cause'] : disease_scientific_name($top['label']);
    $others_words = implode(' · ', array_map(
        fn ($option) => prediction_name(['type' => 'disease', 'predicted_label' => $option['label']]) . ' ' . round($option['confidence'] * 100) . '%',
        array_slice($result['predictions'], 1)
    ));
}

// "Reminder set" after the redirect above, only when the plant and result really match
$reminder_set = $result && $plant && get_text('reminder') === '1';

// The plant menu (p15, element 1) and the forms keep the result and change only the plant
$plant_url = fn ($value) => query_url('health-check.php', ['prediction' => $result['id'] ?? null, 'plant' => $value]);

/** "Tommy · Tomato, Balcony": a plant as the plant link names it. */
function plant_label($plant)
{
    return $plant['nickname'] . ' · ' . $plant['common_name'] . ($plant['collection_name'] ? ', ' . $plant['collection_name'] : '');
}

$story_url   = $plant ? url('plant.php?id=' . $plant['id']) : url('garden.php');
$retake_url  = query_url('health-check.php', ['plant' => $plant['id'] ?? '']);

$page_title = 'Plant health check';
$page_css   = 'tools.css';
$page_js    = 'disease-guide.js';   // AI advice for a Pl@ntNet result that has none saved yet (does nothing otherwise)

include __DIR__ . '/../includes/header.php';
?>
<div class="container page">
  <div class="tool-head">
    <div>
      <p class="label tool-eyebrow"><?= icon('activity') ?> Plant tools</p>
      <h1 class="tool-title">Plant health check</h1>
      <p class="tool-intro">Upload a photo of an affected leaf. We'll check it for common diseases and pests and suggest what to do.</p>
    </div>
    <?php partial('tool-switch', ['active' => 'health']); ?>
  </div>

  <div class="tool-grid<?= $photo ? ' has-result' : '' ?>">
    <div class="tool-side">
      <?php if (!$photo): ?>
        <?php // No photo yet: the leaf photo and the plant it belongs to, in one form ?>
        <form class="form-card tool-upload" method="post" action="<?= e(url('health-check.php')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="check">
          <h2 class="visually-hidden">Your photo</h2>
          <label class="dropzone" for="tool-photo">
            <?php // app.js shows the chosen photo here (data-photo-preview); components.css then hides the words ?>
            <img class="dropzone-preview" id="tool-photo-preview" alt="" hidden>
            <span class="dropzone-icon"><?= icon('image-up') ?></span>
            <span class="dropzone-title">Add a photo of an affected leaf</span>
            <span class="dropzone-text">One leaf, close up and sharp, in daylight</span>
            <span class="btn btn-secondary btn-sm"><?= icon('camera') ?> Choose photo</span>
          </label>
          <input class="visually-hidden" type="file" id="tool-photo" name="photo" accept="image/jpeg,image/png" required
                 aria-describedby="tool-photo-hint<?= $photo_error ? ' tool-photo-error' : '' ?>"<?= $photo_error ? ' aria-invalid="true"' : '' ?>
                 data-photo-input data-photo-hint="tool-photo-hint" data-photo-preview="tool-photo-preview">
          <p class="field-hint photo-hint" id="tool-photo-hint"><?= icon('info') ?> JPG or PNG, up to 5 MB.</p>
          <?php if ($photo_error): ?>
            <p class="field-error" id="tool-photo-error" role="alert"><?= e($photo_error) ?></p>
          <?php endif; ?>

          <?php // Plant link (p15, element 1): optional, so the result can join that plant's story ?>
          <div class="field tool-plant-field">
            <label class="field-label" for="check-plant">Checking a plant from your garden <span class="field-optional">(optional)</span></label>
            <select class="input" id="check-plant" name="plant" aria-describedby="check-plant-hint">
              <option value="none">Not from my garden</option>
              <?php foreach ($my_plants as $option): ?>
                <option value="<?= e($option['id']) ?>"<?= $plant && $plant['id'] === $option['id'] ? ' selected' : '' ?>><?= e(plant_label($option)) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint" id="check-plant-hint">The result can then be saved to its story.</span>
          </div>

          <button class="btn btn-primary tool-submit" type="submit"><?= icon('activity') ?> Check leaf</button>
        </form>
      <?php else: ?>
        <?php // Leaf photo (p15, element 2): the photo with its time, and a quick way to try another photo ?>
        <section class="form-card tool-photo" aria-labelledby="photo-title">
          <h2 class="visually-hidden" id="photo-title">Your photo</h2>
          <div class="tool-photo-frame">
            <img src="<?= e(photo_url($photo['image_path'])) ?>" alt="Your photo of the leaf, <?= e($photo['upload_name']) ?>" id="tool-photo-current">
          </div>
          <?php // One file field; "Check this photo" appears once a photo is chosen (tools.css, :has(), no JavaScript) ?>
          <form class="tool-photo-foot tool-photo-foot-stacked" method="post" action="<?= e(url('health-check.php')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="check">
            <input type="hidden" name="plant" value="<?= e($plant['id'] ?? 'none') ?>">
            <span class="tool-photo-name" id="tool-photo-name">Uploaded <?= e(date('j M, H:i', strtotime($photo['created_at']))) ?> · <?= e($photo['upload_name']) ?></span>
            <label class="btn btn-secondary btn-sm" for="another-photo"><?= icon('refresh-cw') ?> Check another photo</label>
            <input class="visually-hidden" type="file" id="another-photo" name="photo" accept="image/jpeg,image/png" required
                   data-photo-input data-photo-hint="tool-photo-name" data-photo-preview="tool-photo-current">
            <button class="btn btn-primary btn-sm tool-photo-send" type="submit"><?= icon('activity') ?> Check this photo</button>
          </form>
        </section>
      <?php endif; ?>

      <?php // Scope statement (p15, element 7): which plants the model knows, so members know when not to trust it.
            // It opens by itself when the plant is not one of them. ?>
      <section class="form-card tool-scope" id="scope" aria-labelledby="scope-title">
        <h2 class="tool-card-title" id="scope-title">What this tool can check</h2>
        <p class="tool-scope-text">About 2,500 plant diseases and pests, from the Pl@ntNet database. It knows crops, fruit trees and garden plants best; houseplant problems are matched less often.</p>
        <details class="tool-scope-more">
          <summary>How sure is it?</summary>
          <p class="text-small">Pl@ntNet compares your photo with thousands of diseases at once, so even a right answer often scores only 15–25%. PlantFolio shows a <strong>Possible match</strong> when one answer clearly stands out (at least 10%, and twice the next one); otherwise it says it is not sure. Always compare the leaf with the description.</p>
        </details>
      </section>
    </div>

    <div class="tool-main">
      <?php if ($result): ?>
        <?php // Plant link (p15, element 1) for a result: a menu of the member's plants; choosing one re-attaches the check ?>
        <div class="tool-plant">
          <span class="tool-plant-label" id="plant-link-label">Checking a plant from your garden</span>
          <details class="menu tool-plant-menu">
            <summary class="tool-plant-toggle" aria-describedby="plant-link-label">
              <?php if ($plant): ?>
                <img class="tool-plant-thumb" src="<?= e(photo_url($plant['photo'])) ?>" alt="">
                <span><strong><?= e($plant['nickname']) ?></strong> <span class="tool-plant-meta">· <?= e($plant['common_name']) ?><?= $plant['collection_name'] ? ', ' . e($plant['collection_name']) : '' ?></span></span>
              <?php else: ?>
                <?= icon('leaf') ?> <span>Not from my garden</span>
              <?php endif; ?>
              <span class="menu-chevron"><?= icon('chevron-down') ?></span>
            </summary>
            <div class="menu-panel menu-panel-right">
              <?php foreach ($my_plants as $option): ?>
                <a class="menu-item" href="<?= e($plant_url($option['id'])) ?>"<?= $plant && $plant['id'] === $option['id'] ? ' aria-current="true"' : '' ?>><?= e(plant_label($option)) ?></a>
              <?php endforeach; ?>
              <hr class="menu-divider">
              <a class="menu-item" href="<?= e($plant_url('none')) ?>"<?= $plant ? '' : ' aria-current="true"' ?>>Not from my garden</a>
            </div>
          </details>
        </div>
      <?php endif; ?>

      <?php if ($is_offline): ?>
        <?php // Flask is offline or took longer than 10 seconds: say so, and offer the manual way ?>
        <section class="form-card tool-offline" aria-labelledby="offline-title">
          <span class="badge badge-neutral"><?= icon('clock') ?> Not available right now</span>
          <h2 class="not-sure-title" id="offline-title">We couldn't reach the Health Check</h2>
          <p class="not-sure-text">Your photo is fine. Log what you see in the plant's story yourself, or try again in a few minutes.</p>
          <div class="not-sure-actions">
            <a class="btn btn-primary" href="<?= e($story_url) ?>"><?= icon('pencil') ?> Log an update yourself</a>
            <a class="btn btn-secondary" href="<?= e($retake_url) ?>"><?= icon('refresh-cw') ?> Try again</a>
          </div>
        </section>

      <?php elseif ($result && !$is_supported): ?>
        <?php // S3 for a plant the model does not know: whatever Flask answered is not shown at all (p24) ?>
        <?php partial('not-sure', [
            'title'   => "We can't check " . $plant['nickname'] . ' yet',
            'text'    => 'The Health Check knows leaf diseases of ' . count($mock_supported_crops) . ' food crops. ' . $plant_species['common_name']
                         . ' is not one of them, so it does not guess. Houseplant support is planned.',
            'actions' => [
                ['label' => 'Log an update yourself', 'url' => $story_url, 'icon' => 'pencil'],
                ['label' => 'See supported plants',   'url' => '#scope',   'icon' => 'info'],
            ],
        ]); ?>

      <?php elseif ($result && $is_unsure): ?>
        <?php // S3: no clear answer, so no disease is named. The member is told how to get a clearer answer. ?>
        <?php partial('not-sure', [
            'confidence' => $top['confidence'],
            'title'      => $is_plantnet ? 'No clear problem found' : "We couldn't check this leaf confidently",
            'text'       => ($is_identified ? 'This looks like a ' . $identified['name'] . ' (' . $identified['percent'] . '%). ' : '')
                            . ($is_plantnet ? 'The photo does not clearly match a disease or pest. If the plant looks well, it probably is. ' : '')
                            . 'Try a sharp photo of one affected leaf in daylight. If the plant gets worse, ask a local agriculture officer.',
            'actions'    => [
                ['label' => 'Retake photo',           'url' => $retake_url, 'icon' => 'camera'],
                ['label' => 'Log an update yourself', 'url' => $story_url,  'icon' => 'pencil'],
            ],
        ]); ?>

      <?php elseif ($result): ?>
        <?php // Diagnosis (p15, element 3): status badge, disease name, cause, confidence bar and runners-up.
              // Soft red for a disease, never alarming words (p15, UX notes). ?>
        <section class="form-card tool-result" aria-labelledby="diagnosis-title">
          <?php if ($reminder_set): ?>
            <p class="alert alert-success tool-alert" role="status"><?= icon('check') ?> <span>Reminder set: re-check <?= e($plant['nickname']) ?> on <?= e(format_day(date('Y-m-d', strtotime('+7 days')))) ?>.</span></p>
          <?php endif; ?>

          <div class="diagnosis-tags">
            <?php if ($is_healthy): ?>
              <span class="badge badge-thriving">Looks healthy</span>
            <?php elseif ($top_level['key'] === 'high'): ?>
              <span class="badge badge-sick">Likely disease</span>
            <?php else: ?>
              <span class="badge badge-needs-attention"><?= $is_plantnet ? 'Possible problem' : 'Possible disease' ?></span>
            <?php endif; ?>
            <?php if ($crop !== null): ?>
              <span class="badge badge-neutral"><?= e($crop) ?> leaf</span>
            <?php endif; ?>
          </div>

          <h2 class="match-name" id="diagnosis-title"><?= e(prediction_name(['type' => 'disease', 'predicted_label' => $top['label']])) ?></h2>
          <?php if ($cause !== null): ?>
            <p class="scientific-name diagnosis-cause"><?= e($cause) ?></p>
          <?php endif; ?>
          <?php if ($identified): ?>
            <?php // Which plant the AI thinks this is (asked only when no garden plant was chosen); fixed confidence words ?>
            <p class="diagnosis-plant"><?= icon('leaf') ?>
              <span>
                <?php if ($is_identified): ?>
                  Plant: <strong><?= e($identified['name']) ?></strong><?php if ($identified['name'] !== $identified['scientific']): ?> (<em><?= e($identified['scientific']) ?></em>)<?php endif; ?>
                  · identified by AI, <?= e($identified['level']['label']) ?> <?= e($identified['percent']) ?>%
                <?php else: ?>
                  Plant not identified for sure (maybe <em><?= e($identified['scientific']) ?></em>, <?= e($identified['percent']) ?>%)
                <?php endif; ?>
              </span>
            </p>
          <?php endif; ?>

          <div class="diagnosis-confidence">
            <?php partial('confidence-meter', ['prediction' => $top, 'level' => $top_level]); ?>
            <?php if ($others_words !== ''): ?>
              <p class="confidence-note">Other possibilities: <?= e($others_words) ?></p>
            <?php endif; ?>
          </div>

          <?php if ($guide): ?>
            <?php // Plain explanation (p15, element 4). The model classifies the whole photo; it does not find the spots,
                  // so no boxes are drawn on the photo (p15, UX notes). ?>
            <h3 class="diagnosis-heading">What we see</h3>
            <p class="diagnosis-text"><?= e($guide['symptoms']) ?></p>

            <?php // Action plan (p15, element 5): four numbered steps to follow today ?>
            <h3 class="diagnosis-heading">What to do now</h3>
            <ol class="action-plan">
              <?php foreach ($guide['steps'] as $step): ?>
                <li><?= e($step) ?></li>
              <?php endforeach; ?>
            </ol>
            <?php if ($is_plantnet): ?>
              <p class="diagnosis-ai-note"><?= icon('sparkles') ?> <span>Advice written by AI. Check it against the leaf.</span></p>
            <?php endif; ?>

          <?php elseif ($is_plantnet): ?>
            <?php // No advice saved for this problem yet: grey bars, and disease-guide.js asks the local LLM to write it
                  // (api/disease-guide.php), as the AI care guide does on the Plant Profile ?>
            <div class="diagnosis-guide" data-disease-guide data-guide-url="<?= e(url('api/disease-guide.php')) ?>"
                 data-prediction="<?= e($result['id']) ?>" data-token="<?= e(csrf_token()) ?>">
              <h3 class="diagnosis-heading" data-guide-part>What we see</h3>
              <p class="diagnosis-text diagnosis-skeleton" data-guide-part data-guide-symptoms aria-hidden="true"><span></span><span></span></p>
              <h3 class="diagnosis-heading" data-guide-part>What to do now</h3>
              <ol class="action-plan diagnosis-skeleton" data-guide-part data-guide-steps aria-hidden="true"><li><span></span></li><li><span></span></li><li><span></span></li></ol>
              <p class="diagnosis-ai-note" role="status" data-guide-status><?= icon('sparkles') ?> <span>Writing advice with AI. This can take a minute…</span></p>
            </div>
          <?php endif; ?>

          <p class="alert alert-warning diagnosis-warning"><?= icon('triangle-alert') ?> <span>This is a suggestion, not a diagnosis. If more than a third of the plant is affected, get expert advice.</span></p>

          <?php // Save and follow up (p15, element 6): only with a linked plant, because a story belongs to a plant ?>
          <?php if ($plant): ?>
            <div class="tool-actions diagnosis-actions">
              <form method="post" action="<?= e($plant_url($plant['id'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="plant" value="<?= e($plant['id']) ?>">
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save to <?= e($plant['nickname']) ?>'s story</button>
              </form>
              <form method="post" action="<?= e($plant_url($plant['id'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="recheck">
                <input type="hidden" name="plant" value="<?= e($plant['id']) ?>">
                <button class="btn btn-secondary" type="submit"><?= icon('calendar') ?> Remind me to re-check in 7 days</button>
              </form>
            </div>
          <?php else: ?>
            <p class="tool-honesty"><?= icon('info') ?> <span>Choose a plant from your garden above to save this result to its story.</span></p>
          <?php endif; ?>
        </section>

      <?php else: ?>
        <?php // Before a photo: what the result will look like ?>
        <section class="form-card tool-waiting" aria-labelledby="waiting-title">
          <h2 class="tool-card-title tool-waiting-title" id="waiting-title"><?= icon('activity') ?> Your diagnosis appears here</h2>
          <p class="text-small">PlantFolio checks the leaf for the diseases and pests it knows, says how sure it is, and suggests what to do today. Nothing is saved until you choose to.</p>
          <ul class="confidence-legend">
            <li><span class="legend-dot legend-possible" aria-hidden="true"></span><span><strong>Possible match</strong> · one answer clearly stands out</span></li>
            <li><span class="legend-dot legend-low" aria-hidden="true"></span><span><strong>Not sure</strong> · no clear answer, so no problem is named</span></li>
          </ul>
        </section>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
