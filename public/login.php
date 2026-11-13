<?php
// Log in (P02, wireframe p9, state B): a returning member gets back into their garden (CORE-09, CORE-12).
// Public page. On POST: checks the CSRF token, then attempt_login() (includes/auth.php) finds the member by email or
// username and checks the password. Every failure shows the same message; 5 in a row lock the account for 15 minutes.

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';   // header data and the community panel; loads auth.php

// Where to go after logging in. "Log in to show interest" on a listing (P11) sends login.php?next=listing.php?id=8,
// so the member comes back to that listing (p18, UX notes). return_path() (helpers.php) accepts only that kind of local
// address; anything else is ignored and the member goes to the Dashboard. The form carries the value on in a hidden field.
$next = return_path($_SERVER['REQUEST_METHOD'] === 'POST' ? post_text('next') : get_text('next'));
// #summary is added only after return_path() has checked the plain address. On a phone it scrolls past the photos to
// the listing's title and the I'm interested button (listing.php).
$after_login = $next === '' ? 'dashboard.php' : $next . (str_starts_with($next, 'listing.php') ? '#summary' : '');

// A logged-in member does not need this page: they go to their Dashboard (or back to the listing) (wireframe p8).
if ($current_user) {
    header('Location: ' . url($after_login));
    exit;
}

$login_value = '';   // what was typed in "Email or username", shown again after a failed attempt (never the password)
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $login_value = trim(post_text('login'));
    $password    = post_text('password');

    if ($login_value === '' || $password === '') {
        $login_error = 'Enter your email or username and your password.';
    } else {
        // Usernames and emails are stored lowercase (register.php), so the member may type them in any case
        $result = attempt_login(mb_strtolower($login_value), $password);
        if ($result['ok']) {
            log_in($result['user_id']);
            if (post_text('remember') === '1') {
                remember_me_set($result['user_id']);   // stay logged in on this browser for 30 days
            }
            header('Location: ' . url($after_login));
            exit;
        }
        $login_error = $result['error'];
    }
}

$page_title = 'Log in';
$page_css   = 'auth.css';

include __DIR__ . '/../includes/header.php';
?>
<div class="container auth-page">
  <div class="auth-shell">
    <div class="auth-main">
      <?php partial('auth-tabs', ['active' => 'login']); ?>

      <h1 class="auth-title">Welcome back</h1>
      <?php // $next is set by "I'm interested" on a listing, and by every members-only page a visitor opened ?>
      <?php if (str_starts_with($next, 'listing.php')): ?>
        <p class="auth-intro">Log in to show your interest. You will come straight back to the listing.</p>
      <?php elseif ($next !== ''): ?>
        <p class="auth-intro">Log in to continue. You will come straight back to the page you opened.</p>
      <?php else: ?>
        <p class="auth-intro">Log in to see what your plants need today.</p>
      <?php endif; ?>

      <?php // One message for every failure, above both fields: it never says which of the two was wrong ?>
      <?php if ($login_error !== ''): ?>
        <p class="field-error" id="login-error" role="alert"><?= e($login_error) ?></p>
      <?php endif; ?>

      <form method="post" action="<?= e(url('login.php')) ?>">
        <?= csrf_field() ?>
        <?php if ($next !== ''): ?>
          <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>
        <?php // One field for both, so nobody has to remember which one they signed up with ?>
        <div class="field">
          <label class="field-label" for="login">Email or username</label>
          <input class="input" type="text" id="login" name="login" placeholder="nimali.p@example.com"
                 value="<?= e($login_value) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required
                 <?= $login_error !== '' ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>
        </div>

        <div class="field">
          <label class="field-label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password" autocomplete="current-password" required
                 <?= $login_error !== '' ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>
        </div>

        <div class="auth-row">
          <label class="check">
            <input type="checkbox" name="remember" value="1"<?= post_text('remember') === '1' ? ' checked' : '' ?>>
            <span>Remember me</span>
          </label>
          <?php // No reset page exists yet (it is not in the page list), so members are sent to Contact for now ?>
          <a href="<?= e(url('about.php#contact')) ?>">Forgot password?</a>
        </div>

        <button class="btn btn-primary btn-lg auth-submit" type="submit">Log in</button>
      </form>

      <p class="auth-switch">New to PlantFolio? <a href="<?= e(url('register.php')) ?>">Create your garden</a></p>
    </div>

    <?php partial('auth-panel', ['member' => $mock_quote_member]); ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
