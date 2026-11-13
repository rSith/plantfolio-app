<?php
// Sign up (P02, wireframe p9): a new member registers in under a minute (CORE-07).
// Public page. On POST: checks the CSRF token and every field, saves the users row with password_hash(),
// logs the new member in and opens their (empty) Dashboard.

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';   // header data and the community panel; loads auth.php

// A logged-in member does not need this page: they go to their Dashboard (wireframe p8).
if ($current_user) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

// The town picker's list: every town in the towns table, A–Z, as id => name
$towns      = db()->query('SELECT id, name FROM towns ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$town_names = array_values($towns);

// What the form shows: empty at first, the member's own input after a failed attempt (never the password)
$values = ['full_name' => '', 'username' => '', 'email' => '', 'location' => '', 'km_from_town' => 0];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $values['full_name']    = trim(post_text('full_name'));
    $values['username']     = mb_strtolower(trim(post_text('username')));   // usernames are stored lowercase
    $values['email']        = mb_strtolower(trim(post_text('email')));
    $values['location']     = post_text('location');
    $password               = post_text('password');                         // not trimmed: spaces may be part of it

    // ── Check every field on the server. The browser's checks help honest visitors, but anyone can skip them. ──
    if ($values['full_name'] === '') {
        $errors['full_name'] = 'Enter your name.';
    } elseif (mb_strlen($values['full_name']) > 100) {
        $errors['full_name'] = 'Keep your name to 100 characters or fewer.';
    }

    // Format and "already taken", the same rule the live check uses while typing (includes/auth.php)
    $username_problem = username_problem($values['username']);
    if ($username_problem !== null) {
        $errors['username'] = $username_problem;
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (mb_strlen($values['email']) > 255 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter an email address like name@example.com.';
    }

    // password_hash() (bcrypt) uses only the first 72 bytes, so a longer password would not be fully checked at login.
    if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        $errors['password'] = 'Use at least 8 characters, including a number.';
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Use 72 characters or fewer.';
    }

    // The town is optional. When given, it must be one of the list, and the slider must be a whole number 0–30.
    $town_id      = null;
    $km_from_town = null;
    if ($values['location'] !== '') {
        $town_id = array_search($values['location'], $towns, true);
        $km      = filter_var(post_text('km_from_town'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
        if ($town_id === false) {
            $errors['location'] = 'Choose a town from the list.';
            $values['location'] = '';
        } elseif ($km === false) {
            $errors['location'] = 'Set how far from the town you are, from 0 to 30 km.';
        } else {
            $km_from_town           = $km;
            $values['km_from_town'] = $km;
        }
    }

    if (post_text('agree') !== '1') {
        $errors['agree'] = 'Tick the box to agree to the community guidelines.';
    }

    // ── The email must be free too (the username was checked above) ──
    if (!isset($errors['email'])) {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE email = :email');
        $stmt->execute(['email' => $values['email']]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = 'There is already an account with this email. Log in instead.';
        }
    }

    // ── Save, log in, and leave the page (Post/Redirect/Get: refreshing the Dashboard cannot sign up twice). ──
    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO users (username, full_name, email, password_hash, town_id, km_from_town)
                 VALUES (:username, :full_name, :email, :password_hash, :town_id, :km_from_town)'
            );
            $stmt->execute([
                'username'      => $values['username'],
                'full_name'     => $values['full_name'],
                'email'         => $values['email'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'town_id'       => $town_id,
                'km_from_town'  => $km_from_town,
            ]);
            log_in((int) db()->lastInsertId());
            header('Location: ' . url('dashboard.php'));
            exit;
        } catch (PDOException $error) {
            // Two people signing up with the same name at the same moment: both pass the check above, and the UNIQUE
            // key stops the second INSERT (SQLSTATE 23000). Any other database error is a real fault and is rethrown.
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            if (str_contains($error->getMessage(), 'uq_users_username')) {
                $errors['username'] = '@' . $values['username'] . ' was just taken. Try another username.';
            } else {
                $errors['email'] = 'There is already an account with this email. Log in instead.';
            }
        }
    }
}

// aria-invalid and aria-describedby for one field, so a screen reader reads the error with the field
$field_attributes = function ($field, $hint_id = '') use ($errors) {
    $describe = trim($hint_id . (isset($errors[$field]) ? ' ' . $field . '-error' : ''));
    return (isset($errors[$field]) ? ' aria-invalid="true"' : '')
         . ($describe !== '' ? ' aria-describedby="' . e($describe) . '"' : '');
};

$page_title = 'Sign up';
$page_css   = 'auth.css';
$page_js    = 'username-check.js';   // the live username check

include __DIR__ . '/../includes/header.php';
?>
<div class="container auth-page">
  <div class="auth-shell">
    <div class="auth-main">
      <?php partial('auth-tabs', ['active' => 'register']); ?>

      <h1 class="auth-title">Create your garden</h1>
      <p class="auth-intro">Free for plant lovers. Takes under a minute.</p>

      <?php if ($errors): ?>
        <p class="field-error" role="alert">Please check the <?= count($errors) === 1 ? 'field' : count($errors) . ' fields' ?> marked below.</p>
      <?php endif; ?>

      <form method="post" action="<?= e(url('register.php')) ?>">
        <?= csrf_field() ?>

        <div class="field">
          <label class="field-label" for="full-name">Full name</label>
          <input class="input" type="text" id="full-name" name="full_name" placeholder="Nimali Perera"
                 value="<?= e($values['full_name']) ?>" autocomplete="name" maxlength="100" required<?= $field_attributes('full_name') ?>>
          <?php if (isset($errors['full_name'])): ?>
            <p class="field-error" id="full_name-error"><?= e($errors['full_name']) ?></p>
          <?php endif; ?>
        </div>

        <?php // The "@" is drawn before the field, not typed: usernames power @mentions and profile links ?>
        <div class="field">
          <label class="field-label" for="username">Username</label>
          <div class="input-icon">
            <span class="input-prefix" aria-hidden="true">@</span>
            <input class="input" type="text" id="username" name="username" placeholder="nimali.grows"
                   value="<?= e($values['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false"
                   minlength="3" maxlength="30" pattern="[a-z0-9._]+" required<?= $field_attributes('username', 'username-hint username-status') ?>>
          </div>
          <span class="field-hint" id="username-hint">Lowercase letters, numbers, dots and underscores. It becomes your profile link.</span>
          <?php // Filled by register.js while typing (CORE-08). aria-live: a screen reader reads each new answer. ?>
          <p class="field-hint username-status" id="username-status" aria-live="polite"
             data-username-status data-check-url="<?= e(url('api/check-username.php')) ?>"></p>
          <?php if (isset($errors['username'])): ?>
            <p class="field-error" id="username-error"><?= e($errors['username']) ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="field-label" for="email">Email</label>
          <input class="input" type="email" id="email" name="email" placeholder="nimali.p@example.com"
                 value="<?= e($values['email']) ?>" autocomplete="email" maxlength="255" required<?= $field_attributes('email') ?>>
          <?php if (isset($errors['email'])): ?>
            <p class="field-error" id="email-error"><?= e($errors['email']) ?></p>
          <?php endif; ?>
        </div>

        <?php // One clear rule instead of a list of requirements (p9, element 3). The password is never sent back. ?>
        <div class="field">
          <label class="field-label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password"
                 autocomplete="new-password" minlength="8" maxlength="72" pattern="(?=.*\d).{8,}" required<?= $field_attributes('password', 'password-hint') ?>>
          <div class="strength" data-level="0" aria-hidden="true">
            <div class="strength-bars"><span></span><span></span><span></span><span></span></div>
            <span class="strength-label"></span>
          </div>
          <span class="field-hint" id="password-hint">At least 8 characters, including a number.</span>
          <?php if (isset($errors['password'])): ?>
            <p class="field-error" id="password-error"><?= e($errors['password']) ?></p>
          <?php endif; ?>
        </div>

        <?php // Optional: the nearest town, then how far from it. The exchange measures distances from the town. ?>
        <?php partial('town-picker', [
            'town_names' => $town_names,
            'town'       => $values['location'],
            'km'         => $values['km_from_town'],
            'label'      => 'Your nearest town',
            'hint'       => 'Helps you find exchange listings near you. Only the town is shown to others.',
            'error'      => $errors['location'] ?? '',
        ]); ?>

        <?php // A new tab, so reading the guidelines never loses what the visitor has typed ?>
        <label class="check auth-agree">
          <input type="checkbox" name="agree" value="1" required<?= $field_attributes('agree') ?>>
          <span>I agree to the <a href="<?= e(url('about.php#guidelines')) ?>" target="_blank" rel="noopener">community guidelines<span class="visually-hidden"> (opens in a new tab)</span></a></span>
        </label>
        <?php if (isset($errors['agree'])): ?>
          <p class="field-error" id="agree-error"><?= e($errors['agree']) ?></p>
        <?php endif; ?>

        <button class="btn btn-primary btn-lg auth-submit" type="submit">Create account</button>
      </form>

      <p class="auth-switch">Already have a garden? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
    </div>

    <?php partial('auth-panel', ['member' => $mock_quote_member]); ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
