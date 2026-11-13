<?php
// Plant Identifier (P07, wireframe p14): answers "What is this plant?" from one photo and hands the result straight
// into Add Plant (ML-12). Members only.
// The page has four states, each with its own address:
//   identify.php                → no photo yet: the upload box, the photo tips and what the result will look like
//   identify.php?prediction=5   → a result: the top match, care chips, Add to my garden and the alternatives (p14)
//   identify.php?prediction=6   → S3: the model is not sure, so no answer is given (p24)
//   identify.php?offline        → the ML service did not answer: go on by hand (the site never breaks because of ML)
// Sending a photo: PHP checks and saves it (includes/upload.php), sends it to Flask with cURL (includes/ml-client.php),
// logs the answer in ml_predictions and opens identify.php?prediction= of the new row. No answer: ?offline.

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/ml-client.php';
require_once __DIR__ . '/../includes/care-guide.php';

// Members only: a visitor is sent to Log in and comes back here afterwards (includes/auth.php, CORE-10)
require_login();

// ── Sending a photo (Post/Redirect/Get) ─────────────────
// The upload box and the Retake / Upload new buttons each have their own file field. The CSRF token first, then the
// photo's real type (finfo, JPG/PNG) and size (5 MB) in save_upload(), as on the plant form.

$photo_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $field = null;
    foreach (['photo', 'photo_camera', 'photo_upload'] as $name) {
        // No upload error means a file arrived. A hand-made photo[] makes 'error' a list: not a photo.
        if (($_FILES[$name]['error'] ?? null) === UPLOAD_ERR_OK) {
            $field = $name;
        }
    }
    $upload = $field ? save_upload($field, 'scans') : ['path' => null];
    if (isset($upload['error'])) {
        $photo_error = $upload['error'];
    } elseif ($upload['path'] === null) {
        $photo_error = 'Choose a photo of your plant first.';
    } else {
        $file   = rtrim(config('upload_dir'), '/\\') . '/' . $upload['path'];
        $answer = ml_predict('species', $file, (new finfo(FILEINFO_MIME_TYPE))->file($file));
        if (!$answer['ok']) {
            // Flask is not running or too slow: the page says so, with the member's own photo, and offers the manual way
            $_SESSION['ml_offline_photo'] = ['image_path' => $upload['path'], 'upload_name' => $_FILES[$field]['name'],
                                             'upload_size' => (int) $_FILES[$field]['size'], 'created_at' => date('Y-m-d H:i:s')];
            header('Location: ' . url('identify.php?offline'));
            exit;
        }
        $id = save_prediction($current_user['id'], null, 'species', $answer, $upload['path'], $_FILES[$field]['name'], (int) $_FILES[$field]['size']);
        header('Location: ' . url('identify.php?prediction=' . $id));
        exit;
    }
}

// ── Which state ─────────────────────────────────────────
// ?prediction= must be one of the member's own results: another member's photo is none of their business.

$prediction_param = get_text('prediction');
$result = ctype_digit($prediction_param) ? get_prediction($current_user['id'], (int) $prediction_param, 'species') : null;

$is_offline = !$result && isset($_GET['offline']);
// After a "couldn't reach", the member's own photo (kept in the session)
$offline_photo = $is_offline ? ($_SESSION['ml_offline_photo'] ?? null) : null;
$photo = $result ?? $offline_photo;   // no photo yet: the page shows the upload box

$species_by_name = array_column($mock_species, null, 'scientific_name');

if ($result) {
    $top       = $result['predictions'][0];
    $top_level = confidence_level($top['confidence']);
    $is_unsure = $top_level['key'] === 'low';   // below 60%: shown as S3, never as an answer
    $guide     = $species_by_name[$top['label']] ?? null;   // the model may name a species the guide does not have yet
    $others    = array_slice($result['predictions'], 1);

    // AI care guide (ML-12): the same saved guide per species as the Plant Profile, for any species the model names,
    // not only those in our species list. None saved yet: the card shows Generating, and care-guide.js asks the LLM
    // once the member opens "Read care guide". No guide for an unsure result: it is not an answer.
    if (!$is_unsure) {
        $guide_species = $top['label'];
        $guide_row     = care_guide_get($guide_species);
        $guide_state   = $guide_row ? 'ready' : 'generating';
        // After a problem, care-guide.js reloads with &guide=offline or invalid: the card then opens with the message
        $guide_problem = get_text('guide');
        if (in_array($guide_problem, ['generating', 'offline', 'invalid'], true)) {
            $guide_state = $guide_problem;
        }
    }
}

/** "Water about once a week" from a species' interval: 7 days reads as a week, 14 as two weeks. */
function water_words($days)
{
    if ($days === 7) {
        return 'Water about once a week';
    }
    if ($days % 7 === 0) {
        return 'Water about every ' . ($days / 7) . ' weeks';
    }
    return 'Water about every ' . $days . ' days';
}

// Add to my garden (p14, element 6): Add Plant with this result's photo, species and reminders already filled in
$add_url = $result ? url('plant-form.php?prediction=' . $result['id']) : url('plant-form.php');

$page_title = 'Identify a plant';
$page_css   = 'tools.css';
$page_js    = 'care-guide.js';   // writes a missing AI care guide once "Read care guide" is opened

include __DIR__ . '/../includes/header.php';
?>
<div class="container page">
  <div class="tool-head">
    <div>
      <p class="label tool-eyebrow"><?= icon('sparkles') ?> Plant tools</p>
      <h1 class="tool-title">Identify a plant</h1>
      <p class="tool-intro">Upload a clear photo of the whole plant or one leaf. We'll suggest the most likely species.</p>
    </div>
    <?php partial('tool-switch', ['active' => 'identify']); ?>
  </div>

  <div class="tool-grid<?= $photo ? ' has-result' : '' ?>">
    <div class="tool-side">
      <?php if (!$photo): ?>
        <?php // No photo yet: the upload box (p14, element 2 before a photo). multipart/form-data carries the file. ?>
        <form class="form-card tool-upload" method="post" action="<?= e(url('identify.php')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <h2 class="visually-hidden">Your photo</h2>
          <label class="dropzone" for="tool-photo">
            <?php // app.js shows the chosen photo here (data-photo-preview); components.css then hides the words ?>
            <img class="dropzone-preview" id="tool-photo-preview" alt="" hidden>
            <span class="dropzone-icon"><?= icon('image-up') ?></span>
            <span class="dropzone-title">Add a photo of your plant</span>
            <span class="dropzone-text">The whole plant or one leaf, from your files or your phone camera</span>
            <span class="btn btn-secondary btn-sm"><?= icon('camera') ?> Choose photo</span>
          </label>
          <input class="visually-hidden" type="file" id="tool-photo" name="photo" accept="image/jpeg,image/png" required
                 aria-describedby="tool-photo-hint<?= $photo_error ? ' tool-photo-error' : '' ?>"<?= $photo_error ? ' aria-invalid="true"' : '' ?>
                 data-photo-input data-photo-hint="tool-photo-hint" data-photo-preview="tool-photo-preview">
          <p class="field-hint photo-hint" id="tool-photo-hint"><?= icon('info') ?> JPG or PNG, up to 5 MB.</p>
          <?php if ($photo_error): ?>
            <p class="field-error" id="tool-photo-error" role="alert"><?= e($photo_error) ?></p>
          <?php endif; ?>
          <button class="btn btn-primary tool-submit" type="submit"><?= icon('sparkles') ?> Identify plant</button>
        </form>
      <?php else: ?>
        <?php // Photo preview (p14, element 2): the photo with Retake (the phone camera) and Upload new ?>
        <section class="form-card tool-photo" aria-labelledby="photo-title">
          <h2 class="visually-hidden" id="photo-title">Your photo</h2>
          <div class="tool-photo-frame">
            <img src="<?= e(photo_url($photo['image_path'])) ?>" alt="Your photo, <?= e($photo['upload_name']) ?>" id="tool-photo-current">
          </div>
          <?php // Two file fields: capture="environment" opens the rear camera at once; the other offers the photo
                // library too. Choosing a photo shows "Identify this photo" (tools.css, :has(), no JavaScript).
                // novalidate lets the form go with only one of the two filled in; the server checks that one is. ?>
          <form class="tool-photo-foot" method="post" action="<?= e(url('identify.php')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <span class="tool-photo-name" id="tool-photo-name"><?= e($photo['upload_name']) ?> · <?= e(number_format($photo['upload_size'] / 1048576, 1)) ?> MB</span>
            <span class="tool-photo-actions">
              <label class="btn btn-secondary btn-sm" for="photo-camera"><?= icon('camera') ?> Retake</label>
              <input class="visually-hidden" type="file" id="photo-camera" name="photo_camera" accept="image/jpeg,image/png" capture="environment" required
                     data-photo-input data-photo-hint="tool-photo-name" data-photo-preview="tool-photo-current">
              <label class="btn btn-secondary btn-sm" for="photo-upload"><?= icon('upload') ?> Upload new</label>
              <input class="visually-hidden" type="file" id="photo-upload" name="photo_upload" accept="image/jpeg,image/png" required
                     data-photo-input data-photo-hint="tool-photo-name" data-photo-preview="tool-photo-current">
              <button class="btn btn-primary btn-sm tool-photo-send" type="submit"><?= icon('sparkles') ?> Identify this photo</button>
            </span>
          </form>
        </section>
      <?php endif; ?>

      <?php // Photo tips (p14, element 3): four quick rules that improve accuracy before the member even tries ?>
      <section class="form-card tool-tips" aria-labelledby="tips-title">
        <h2 class="form-card-title tool-card-title" id="tips-title">For the best result</h2>
        <ul class="tips-list">
          <li class="tip-do"><?= icon('check') ?> One plant in the frame</li>
          <li class="tip-do"><?= icon('check') ?> Natural daylight</li>
          <li class="tip-do"><?= icon('check') ?> Leaves in focus</li>
          <li class="tip-dont"><?= icon('x') ?> <span><span class="visually-hidden">Avoid </span>Dark or blurry photos</span></li>
        </ul>
      </section>
    </div>

    <div class="tool-main">
      <?php if ($is_offline): ?>
        <?php // Flask is offline or took longer than 10 seconds: the page says so honestly and offers the manual way ?>
        <section class="form-card tool-offline" aria-labelledby="offline-title">
          <span class="badge badge-neutral"><?= icon('clock') ?> Not available right now</span>
          <h2 class="not-sure-title" id="offline-title">We couldn't reach the Plant Identifier</h2>
          <p class="not-sure-text">Your photo is fine. Add the plant and choose the species yourself, or try again in a few minutes.</p>
          <div class="not-sure-actions">
            <a class="btn btn-primary" href="<?= e(url('plant-form.php')) ?>"><?= icon('plus') ?> Add the plant yourself</a>
            <a class="btn btn-secondary" href="<?= e(url('identify.php')) ?>"><?= icon('refresh-cw') ?> Try again</a>
          </div>
        </section>

      <?php elseif ($result && $is_unsure): ?>
        <?php // S3: below 60% the model's guesses are possibilities, not an answer. The member chooses. ?>
        <?php partial('not-sure', [
            'confidence' => $top['confidence'],
            'title'      => "We couldn't identify this confidently",
            'text'       => 'Try a closer photo of one leaf in daylight, or pick the species yourself.',
            'actions'    => [
                ['label' => 'Retake photo',    'url' => url('identify.php'), 'icon' => 'camera'],
                ['label' => 'Choose manually', 'url' => url('plant-form.php?prediction=' . $result['id']) . '#species', 'icon' => 'pencil'],
            ],
        ]); ?>

      <?php elseif ($result): ?>
        <?php // Top match (p14, element 4): name, scientific name, reference photo and a labelled confidence bar ?>
        <section class="form-card tool-result" aria-labelledby="match-title">
          <div class="tool-result-head">
            <p class="label">Top match</p>
            <span class="badge badge-confidence-<?= e($top_level['key']) ?>"><?= icon('check') ?> <?= e($top_level['label']) ?> confidence</span>
          </div>

          <div class="match">
            <?php if ($guide): ?>
              <img class="match-photo" src="<?= e(photo_url($guide['photo'])) ?>" alt="">
            <?php endif; ?>
            <div>
              <?php // A species the guide does not know yet is named by its scientific name only ?>
              <h2 class="match-name" id="match-title"><?= e($guide['common_name'] ?? $top['label']) ?></h2>
              <?php if ($guide): ?>
                <p class="scientific-name match-scientific"><?= e($top['label']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <?php partial('confidence-meter', ['prediction' => $top]); ?>
          <p class="confidence-note"><?= $top_level['key'] === 'high' ? 'High means 80% or more.' : 'Medium means 60 to 79%: check the name against your plant.' ?></p>

          <?php if ($guide): ?>
            <?php // Care and safety chips (p14, element 5), from the species guide ?>
            <ul class="care-chips" aria-label="Care at a glance">
              <li class="care-chip"><?= icon('sun') ?> <?= e($guide['light']) ?> light</li>
              <li class="care-chip"><?= icon('droplet') ?> <?= e(water_words($guide['water_interval_days'])) ?></li>
              <?php if ($guide['pet_safe']): ?>
                <li class="care-chip"><?= icon('paw-print') ?> Safe for cats and dogs</li>
              <?php else: ?>
                <li class="care-chip care-chip-warning"><?= icon('triangle-alert') ?> Toxic to cats and dogs</li>
              <?php endif; ?>
            </ul>
          <?php endif; ?>

          <div class="tool-actions">
            <?php // Nothing is saved until Save on the plant form: the AI suggests, the member decides ?>
            <a class="btn btn-primary" href="<?= e($add_url) ?>"><?= icon('plus') ?> Add to my garden</a>
            <?php // "Read care guide" opens the AI care guide right here. <details> opens without JavaScript; a saved
                  // guide is already in the page, a new one is written once it is opened. Open after a problem. ?>
            <details class="care-guide"<?= in_array($guide_state, ['offline', 'invalid'], true) ? ' open' : '' ?>>
              <summary class="btn btn-secondary"><?= icon('book-open') ?> Read care guide</summary>
              <?php partial('care-guide', [
                  'plant'     => null,
                  'is_owner'  => false,
                  'state'     => $guide_state,
                  'guide'     => $guide_row['guide'] ?? null,
                  'guide_row' => $guide_row,
                  'species'   => $guide_species,
                  'source'    => ['prediction' => $result['id']],
                  'retry_url' => url('identify.php?prediction=' . $result['id']),
              ]); ?>
            </details>
          </div>
        </section>

      <?php else: ?>
        <?php // Before a photo: what the result will look like, and what the confidence words mean ?>
        <section class="form-card tool-waiting" aria-labelledby="waiting-title">
          <h2 class="tool-card-title tool-waiting-title" id="waiting-title"><?= icon('sparkles') ?> Your result appears here</h2>
          <p class="text-small">PlantFolio suggests the most likely species and says how sure it is. You always decide.</p>
          <ul class="confidence-legend">
            <li><span class="legend-dot legend-high" aria-hidden="true"></span><span><strong>High</strong> · 80% or more</span></li>
            <li><span class="legend-dot legend-medium" aria-hidden="true"></span><span><strong>Medium</strong> · 60 to 79%</span></li>
            <li><span class="legend-dot legend-low" aria-hidden="true"></span><span><strong>Not sure</strong> · below 60%, so you choose the species</span></li>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($result): ?>
        <?php // Alternatives and honesty note (p14, element 7). Below 60% the list is all there is, under a plainer title. ?>
        <section class="form-card tool-alternatives" aria-labelledby="others-title">
          <h2 class="form-card-title tool-card-title" id="others-title"><?= $is_unsure ? 'Could it be one of these?' : 'Other possibilities' ?></h2>
          <ul class="alt-list">
            <?php foreach ($is_unsure ? $result['predictions'] : $others as $option): ?>
              <?php
              $known   = $species_by_name[$option['label']] ?? null;
              $percent = round($option['confidence'] * 100);
              ?>
              <li class="alt">
                <span class="alt-icon"><?= icon('leaf') ?></span>
                <div class="alt-body">
                  <p class="alt-name">
                    <span class="scientific-name"><?= e($option['label']) ?></span>
                    <?php if ($known): ?><span class="alt-common">· <?= e($known['common_name']) ?></span><?php endif; ?>
                  </p>
                  <div class="confidence-row">
                    <div class="meter" aria-hidden="true"><span class="meter-fill" style="width: <?= e($percent) ?>%"></span></div>
                    <span class="confidence-value"><?= e($percent) ?>%</span>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="tool-honesty"><?= icon('info') ?> <span>PlantFolio suggests, you decide. When confidence is below 60% we show "Not sure" and ask you to choose the species yourself.</span></p>
        </section>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
