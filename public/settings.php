<?php
/// Account Settings (P15, wireframe p22): public profile, password, privacy defaults and reminder preferences
// in one place (CORE-26). Members only.
// Each card is its own <form>, so saving one card never touches, or throws away, what was typed in another (p22, UX notes).

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';
require_once __DIR__ . '/../includes/upload.php';   // the profile photo (CORE-17)

// Members only: a visitor is sent to Log in and comes back here afterwards (includes/auth.php, CORE-10)
require_login();

// The member's own users row, read from the database (current_user(), auth.php). Every UPDATE below uses this id,
// never one sent by the form, so nobody can change another member's account by editing a hidden field.
$me = current_user();

// The town picker's list: every town in the towns table, A–Z, as id => name
$towns      = db()->query('SELECT id, name FROM towns ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$town_names = array_values($towns);

$bio_limit = 160;   // p22: "92 / 160"

// ── Saving a card ───────────────────────────────────────
// Every form sends a hidden field "form" naming its card. A card that passes its checks is saved, then the page
// redirects back with a green message in that card (Post/Redirect/Get).

$saved_messages = [
    'profile'   => 'Profile saved.',
    'email'     => 'Email updated.',
    'password'  => 'Password updated.',
    'privacy'   => 'Privacy saved.',
    'reminders' => 'Reminder settings saved.',
];
$card_of = ['profile' => 'profile', 'email' => 'account', 'password' => 'account',
            'privacy' => 'privacy', 'reminders' => 'reminders'];

$errors = [];   // field name => message, printed under that field

// What the Profile card shows: the saved values, or what the member typed after a failed save
$profile = [
    'full_name'    => $me['full_name'],
    'username'     => $me['username'],
    'bio'          => $me['bio'] ?? '',
    'location'     => $me['location'] ?? '',
    'km_from_town' => $me['km_from_town'] ?? 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // post_text() (helpers.php) accepts only one piece of valid text: a hand-made form[]=x counts as "not given"
    $form = post_text('form');

    // Delete account, confirmed in the popup (S6) with the current password: deleting everything is the one action
    // nobody should be able to do with one click at a computer the member left logged in.
    if ($form === 'delete_account') {
        $problem = current_password_problem($me['id'], post_text('current_password'));
        if ($problem !== null) {
            $errors['delete_password'] = $problem;   // the popup opens again with this message
        } else {
            // The foreign keys do almost everything (database/data-dictionary.md): their collections, plants, stories,
            // reminders, likes, AI results, listings, interests and the ratings about them go (CASCADE); reviews they
            // wrote stay, shown as "Former member" (rater_id SET NULL). The rows only hold file names, so the member's
            // photo files are listed first (member_photo_files()) and deleted after the rows are gone: a deleted
            // account must not leave its photos reachable in public/uploads/.
            // One step is done by hand first. A review the member wrote on their OWN listing would be reached twice in
            // one DELETE: the listing's cascade sets its listing_id to NULL while its rater_id still points at the
            // member being deleted, and InnoDB refuses that (error 1452). Clearing rater_id first leaves one path.
            // The transaction makes the two statements all-or-nothing.
            $files = member_photo_files($me['id']);
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('UPDATE user_ratings SET rater_id = NULL WHERE rater_id = :id');
                $stmt->execute(['id' => $me['id']]);
                $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute(['id' => $me['id']]);
                db()->commit();
            } catch (Throwable $error) {
                db()->rollBack();   // nothing half deleted, and no file touched
                throw $error;
            }
            foreach ($files as $file) {
                forget_photo($file);   // skips the shared sample drawings and any file another row still uses
            }
            log_out();   // also forgets this browser's Remember me token (already gone with the row)
            header('Location: ' . url('index.php'));
            exit;
        }
    }

    if ($form === 'profile') {
        $profile['full_name'] = trim(post_text('full_name'));
        $profile['username']  = mb_strtolower(trim(post_text('username')));
        $profile['bio']       = trim(post_text('bio'));
        $profile['location']  = post_text('location');

        if ($profile['full_name'] === '') {
            $errors['full_name'] = 'Enter your name.';
        } elseif (mb_strlen($profile['full_name']) > 100) {
            $errors['full_name'] = 'Keep your name to 100 characters or fewer.';
        }

        // Keeping your own username is always fine; a new one must pass the same rule as Sign up
        if ($profile['username'] !== $me['username']) {
            $problem = username_problem($profile['username']);
            if ($problem !== null) {
                $errors['username'] = $problem;
            }
        }

        if (mb_strlen($profile['bio']) > $bio_limit) {
            $errors['bio'] = 'Keep your bio to ' . $bio_limit . ' characters or fewer.';
        }

        // The town is required here (unlike Sign up): the profile and the exchange distances need it
        $town_id = array_search($profile['location'], $towns, true);
        $km      = filter_var(post_text('km_from_town'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
        if ($town_id === false) {
            $errors['location'] = 'Choose your nearest town from the list.';
            $profile['location'] = '';
        } elseif ($km === false) {
            $errors['location'] = 'Set how far from the town you are, from 0 to 30 km.';
        } else {
            $profile['km_from_town'] = $km;
        }

        // The photo: Remove, or a new upload (saved only once the other fields are fine, so a failed save leaves no
        // unused file behind). The old file is deleted after the database has the new value.
        $avatar = $me['avatar'];
        if (!$errors) {
            if (post_text('remove_avatar') === '1') {
                $avatar = null;
            } else {
                $upload = save_upload('avatar', 'avatars');
                if (isset($upload['error'])) {
                    $errors['avatar'] = $upload['error'];
                } elseif ($upload['path'] !== null) {
                    $avatar = $upload['path'];
                }
            }
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'UPDATE users SET full_name = :full_name, username = :username, bio = :bio,
                                      town_id = :town_id, km_from_town = :km_from_town, avatar = :avatar
                     WHERE id = :id'
                );
                $stmt->execute([
                    'full_name'    => $profile['full_name'],
                    'username'     => $profile['username'],
                    'bio'          => $profile['bio'] === '' ? null : $profile['bio'],   // an empty bio is "no bio"
                    'town_id'      => $town_id,
                    'km_from_town' => $km,
                    'avatar'       => $avatar,
                    'id'           => $me['id'],
                ]);
                if ($avatar !== $me['avatar']) {
                    delete_upload($me['avatar']);   // the old photo file is no longer used
                }
            } catch (PDOException $error) {
                // Someone took the new username between the check above and this UPDATE (the UNIQUE key stops it)
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
                $errors['username'] = '@' . $profile['username'] . ' was just taken. Try another username.';
                if ($avatar !== $me['avatar']) {
                    delete_upload($avatar);   // the new photo was saved for nothing
                }
            }
        }
    } elseif ($form === 'privacy') {
        // An unticked checkbox is not sent at all, so "not sent" means 0
        $stmt = db()->prepare('UPDATE users SET new_plants_public = :value WHERE id = :id');
        $stmt->execute(['value' => post_text('new_plants_public') === '1' ? 1 : 0, 'id' => $me['id']]);
    } elseif ($form === 'reminders') {
        $stmt = db()->prepare('UPDATE users SET dashboard_reminders = :value WHERE id = :id');
        $stmt->execute(['value' => post_text('dashboard_reminders') === '1' ? 1 : 0, 'id' => $me['id']]);
    } elseif ($form === 'email') {
        $new_email = mb_strtolower(trim(post_text('email')));   // emails are stored lowercase (register.php)

        if ($new_email === '') {
            $errors['email'] = 'Enter your new email address.';
        } elseif (mb_strlen($new_email) > 255 || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter an email address like name@example.com.';
        } elseif ($new_email === $me['email']) {
            $errors['email'] = 'That is already your email.';
        } else {
            $stmt = db()->prepare('SELECT 1 FROM users WHERE email = :email');
            $stmt->execute(['email' => $new_email]);
            if ($stmt->fetchColumn()) {
                $errors['email'] = 'Another account already uses this email.';
            }
        }

        // The current password proves it is really the member, not someone at a computer they left logged in.
        // A wrong one counts towards the lockout, like on Log in.
        if (!$errors) {
            $problem = current_password_problem($me['id'], post_text('current_password'));
            if ($problem !== null) {
                $errors['email_password'] = $problem;
            }
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare('UPDATE users SET email = :email WHERE id = :id');
                $stmt->execute(['email' => $new_email, 'id' => $me['id']]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '23000') {
                    throw $error;
                }
                $errors['email'] = 'Another account already uses this email.';
            }
        }
    } elseif ($form === 'password') {
        // The same rules as Sign up, checked again here: the browser's checks can be switched off
        $new     = post_text('new_password');
        $confirm = post_text('confirm_password');
        if (strlen($new) < 8 || !preg_match('/\d/', $new)) {
            $errors['new_password'] = 'Use at least 8 characters, including a number.';
        } elseif (strlen($new) > 72) {
            $errors['new_password'] = 'Use 72 characters or fewer.';   // bcrypt reads only the first 72 bytes
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = "Passwords don't match";
        }

        if (!$errors) {
            $problem = current_password_problem($me['id'], post_text('current_password'));
            if ($problem !== null) {
                $errors['current_password'] = $problem;
            }
        }

        if (!$errors) {
            $stmt = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $me['id']]);

            // A new password should shut out anyone who had the old one: every "Remember me" token of this member
            // stops working (on every device), and this browser gets a fresh session id. This browser stays logged in.
            $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = :id');
            $stmt->execute(['id' => $me['id']]);
            remember_cookie('', time() - 3600);
            session_regenerate_id(true);
        }
    }

    if (array_key_exists($form, $saved_messages) && !$errors) {
        header('Location: ' . url('settings.php?saved=' . $form . '#' . $card_of[$form]));
        exit;
    }
}

// Which card just saved, from the redirect above; anything else in ?saved= is ignored
$saved = get_text('saved');
if (!array_key_exists($saved, $saved_messages)) {
    $saved = '';
}


/** The green "saved" message for one card, or nothing. role="status" makes a screen reader announce it. */
function saved_message($forms, $saved, $saved_messages)
{
    if (!in_array($saved, $forms, true)) {
        return '';
    }
    return '<p class="alert alert-success settings-alert" role="status">' . icon('check') . ' ' . e($saved_messages[$saved]) . '</p>';
}

// Section menu (p22, element 1): jump links to each card. The ids are the cards' id attributes.
$sections = [
    'profile'   => ['label' => 'Profile',              'icon' => 'user'],
    'account'   => ['label' => 'Account and security', 'icon' => 'lock'],
    'privacy'   => ['label' => 'Privacy',              'icon' => 'eye'],
    'reminders' => ['label' => 'Reminders',            'icon' => 'bell'],
];

// aria-invalid and aria-describedby for one field, so a screen reader reads the error with the field
$field_attributes = function ($field, $hint_id = '') use ($errors) {
    $describe = trim($hint_id . (isset($errors[$field]) ? ' ' . $field . '-error' : ''));
    return (isset($errors[$field]) ? ' aria-invalid="true"' : '')
         . ($describe !== '' ? ' aria-describedby="' . e($describe) . '"' : '');
};

$page_title = 'Settings';
$page_css   = 'settings.css';
$page_js    = 'username-check.js';   // the live "is this username free?" line under Username

include __DIR__ . '/../includes/header.php';
?>
<div class="container page">
  <h1 class="settings-title">Settings</h1>

  <div class="settings-grid">
    <nav class="settings-menu" aria-label="Settings sections">
      <ul>
        <?php foreach ($sections as $id => $section): ?>
          <li><a href="#<?= e($id) ?>"><?= icon($section['icon']) ?> <?= e($section['label']) ?></a></li>
        <?php endforeach; ?>
        <li class="settings-menu-danger"><a href="#delete"><?= icon('trash-2') ?> Delete account</a></li>
      </ul>
    </nav>

    <div class="settings-cards">
      <?php // Profile (p22, element 2): what other members see. multipart/form-data because it can carry a photo. ?>
      <section class="settings-card" id="profile" aria-labelledby="profile-title">
        <h2 class="settings-card-title" id="profile-title">Profile</h2>
        <p class="settings-card-intro">What other members see on your profile.</p>
        <?= saved_message(['profile'], $saved, $saved_messages) ?>

        <form method="post" action="<?= e(url('settings.php#profile')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="profile">

          <div class="settings-photo">
            <?php partial('avatar', ['member' => $current_user, 'size' => 'lg']); ?>
            <?php // app.js shows a newly chosen photo here; settings.css then hides the avatar above ?>
            <img class="avatar avatar-lg settings-avatar-preview" id="avatar-preview" alt="" hidden>
            <?php // The label opens the hidden file input, like the photo area on the plant form ?>
            <label class="btn btn-secondary btn-sm" for="avatar-file"><?= icon('upload') ?> Change photo</label>
            <input class="visually-hidden" type="file" id="avatar-file" name="avatar" accept="image/jpeg,image/png"
                   aria-describedby="avatar-hint" data-photo-input data-photo-hint="avatar-hint" data-photo-preview="avatar-preview">
            <?php // Remove only appears when there is a photo to remove; without one the initials are shown ?>
            <?php if ($me['avatar']): ?>
              <button type="submit" class="btn btn-ghost btn-sm" name="remove_avatar" value="1">Remove</button>
            <?php endif; ?>
            <p class="field-hint photo-hint settings-photo-hint" id="avatar-hint">JPG or PNG, up to 5 MB.</p>
            <?php if (isset($errors['avatar'])): ?>
              <p class="field-error" id="avatar-error"><?= e($errors['avatar']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="field-label" for="full-name">Display name</label>
              <input class="input" type="text" id="full-name" name="full_name" value="<?= e($profile['full_name']) ?>"
                     autocomplete="name" maxlength="100" required<?= $field_attributes('full_name') ?>>
              <?php if (isset($errors['full_name'])): ?>
                <p class="field-error" id="full_name-error"><?= e($errors['full_name']) ?></p>
              <?php endif; ?>
            </div>

            <?php // Same rule as on Sign up (username_problem()). The database keeps no old usernames, so the profile
                  // link changes with it (decided 11 Nov 2026, instead of p22's "old @link keeps working"). ?>
            <div class="field">
              <label class="field-label" for="username">Username</label>
              <div class="input-icon">
                <span class="input-prefix" aria-hidden="true">@</span>
                <input class="input" type="text" id="username" name="username" value="<?= e($profile['username']) ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       minlength="3" maxlength="30" pattern="[a-z0-9._]+" required<?= $field_attributes('username', 'username-hint username-status') ?>>
              </div>
              <span class="field-hint" id="username-hint">Your profile link changes too.</span>
              <?php // Filled by username-check.js while typing; your own current name is never called "taken" ?>
              <p class="field-hint username-status" id="username-status" aria-live="polite" data-username-status
                 data-check-url="<?= e(url('api/check-username.php')) ?>" data-current-username="<?= e($me['username']) ?>"></p>
              <?php if (isset($errors['username'])): ?>
                <p class="field-error" id="username-error"><?= e($errors['username']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="bio">Bio</label>
            <textarea class="input" id="bio" name="bio" maxlength="<?= e($bio_limit) ?>" rows="3"
                     <?= $field_attributes('bio', 'bio-count') ?>><?= e($profile['bio']) ?></textarea>
            <?php // The count is worked out once here; week 6 JavaScript updates it while typing ?>
            <span class="field-hint bio-count" id="bio-count"><?= e(mb_strlen($profile['bio'])) ?> / <?= e($bio_limit) ?></span>
            <?php if (isset($errors['bio'])): ?>
              <p class="field-error" id="bio-error"><?= e($errors['bio']) ?></p>
            <?php endif; ?>
          </div>

          <?php // The member's place: the nearest town (shown on the profile), then how far from it (kept private: it is
                // only the starting value for the pick-up distance on Create Listing) ?>
          <?php partial('town-picker', [
              'town_names' => $town_names,
              'town'       => $profile['location'],
              'km'         => $profile['km_from_town'],
              'error'      => $errors['location'] ?? '',
              'label'      => 'Nearest town',
              'required'   => true,
              'hint'       => 'Your profile shows the town only. The distance is used as the starting pick-up distance when you list a plant.',
          ]); ?>

          <button class="btn btn-primary settings-save" type="submit">Save profile</button>
        </form>
      </section>

      <div class="settings-column">
        <?php // Account and security (p22, element 3): a new password needs the current one ?>
        <section class="settings-card" id="account" aria-labelledby="account-title">
          <h2 class="settings-card-title" id="account-title">Account and security</h2>
          <?= saved_message(['email', 'password'], $saved, $saved_messages) ?>

          <?php // "Change email" opens a small form in place: <details> needs no JavaScript ?>
          <?php // After a failed attempt the form stays open, with the typed address (never the password) ?>
          <?php $email_failed = isset($errors['email']) || isset($errors['email_password']); ?>
          <details class="settings-email"<?= $email_failed ? ' open' : '' ?>>
            <summary class="settings-card-intro"><?= e($me['email']) ?> · <span class="settings-link">Change email</span></summary>
            <form method="post" action="<?= e(url('settings.php#account')) ?>" class="settings-email-form">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="email">
              <div class="field">
                <label class="field-label" for="new-email">New email</label>
                <input class="input" type="email" id="new-email" name="email" autocomplete="email" maxlength="255" required
                       value="<?= e($email_failed ? post_text('email') : '') ?>"<?= $field_attributes('email') ?>>
                <?php if (isset($errors['email'])): ?>
                  <p class="field-error" id="email-error"><?= e($errors['email']) ?></p>
                <?php endif; ?>
              </div>
              <div class="field">
                <label class="field-label" for="email-password">Current password</label>
                <input class="input" type="password" id="email-password" name="current_password" autocomplete="current-password" required<?= $field_attributes('email_password') ?>>
                <?php if (isset($errors['email_password'])): ?>
                  <p class="field-error" id="email_password-error"><?= e($errors['email_password']) ?></p>
                <?php endif; ?>
              </div>
              <button class="btn btn-secondary settings-save" type="submit">Update email</button>
            </form>
          </details>

          <form method="post" action="<?= e(url('settings.php#account')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="password">
            <div class="field">
              <label class="field-label" for="current-password">Current password</label>
              <input class="input" type="password" id="current-password" name="current_password" autocomplete="current-password" required<?= $field_attributes('current_password') ?>>
              <?php if (isset($errors['current_password'])): ?>
                <p class="field-error" id="current_password-error"><?= e($errors['current_password']) ?></p>
              <?php endif; ?>
            </div>
            <?php // An error is printed under its field and linked to it with aria-describedby, so a screen reader reads it
                  // with the field. aria-invalid turns the border red. Passwords are never printed back into the form. ?>
            <div class="field">
              <label class="field-label" for="new-password">New password</label>
              <input class="input" type="password" id="new-password" name="new_password" autocomplete="new-password"
                     minlength="8" maxlength="72" pattern="(?=.*\d).{8,}" required
                     aria-describedby="new-password-hint<?= isset($errors['new_password']) ? ' new-password-error' : '' ?>"<?= isset($errors['new_password']) ? ' aria-invalid="true"' : '' ?>>
              <span class="field-hint" id="new-password-hint">At least 8 characters, including a number.</span>
              <?php if (isset($errors['new_password'])): ?>
                <span class="field-error" id="new-password-error"><?= e($errors['new_password']) ?></span>
              <?php endif; ?>
            </div>
            <div class="field">
              <label class="field-label" for="confirm-password">Confirm new password</label>
              <input class="input" type="password" id="confirm-password" name="confirm_password" autocomplete="new-password" required
                     <?= isset($errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="confirm-password-error"' : '' ?>>
              <?php if (isset($errors['confirm_password'])): ?>
                <span class="field-error" id="confirm-password-error"><?= e($errors['confirm_password']) ?></span>
              <?php endif; ?>
            </div>
            <button class="btn btn-secondary settings-save" type="submit">Update password</button>
          </form>
        </section>

        <?php // Privacy defaults (p22, element 4). A switch is a checkbox: an unticked one is not sent at all,
              // so stage B reads a missing value as 0. ?>
        <section class="settings-card" id="privacy" aria-labelledby="privacy-title">
          <h2 class="settings-card-title" id="privacy-title">Privacy</h2>
          <?= saved_message(['privacy'], $saved, $saved_messages) ?>
          <form method="post" action="<?= e(url('settings.php#privacy')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="privacy">
            <label class="toggle settings-toggle">
              <span>New plants are public <span class="field-hint">Shown in Explore. You can still make one plant private.</span></span>
              <input type="checkbox" name="new_plants_public" value="1"<?= $current_user['new_plants_public'] ? ' checked' : '' ?>>
            </label>
            <button class="btn btn-secondary btn-sm settings-save" type="submit">Save privacy</button>
          </form>
        </section>
      </div>

      <?php // Reminder preferences (p22, element 5). Email reminders are a later feature: shown, disabled and labelled,
            // never hidden and never faked (p22, UX notes). ?>
      <section class="settings-card" id="reminders" aria-labelledby="reminders-title">
        <h2 class="settings-card-title" id="reminders-title">Reminders</h2>
        <?= saved_message(['reminders'], $saved, $saved_messages) ?>
        <form method="post" action="<?= e(url('settings.php#reminders')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="reminders">
          <label class="toggle settings-toggle">
            <span>Show care tasks on Dashboard</span>
            <input type="checkbox" name="dashboard_reminders" value="1"<?= $current_user['dashboard_reminders'] ? ' checked' : '' ?>>
          </label>
          <label class="toggle settings-toggle is-disabled">
            <span>Email reminders <span class="badge badge-neutral settings-later">Coming later</span></span>
            <input type="checkbox" name="email_reminders" value="1" disabled>
          </label>
          <button class="btn btn-secondary btn-sm settings-save" type="submit">Save reminders</button>
        </form>
      </section>

      <?php // Delete account (p22, element 6): kept apart in a red card, and always confirmed in a dialog (S6, week 5) ?>
      <section class="settings-card settings-card-danger" id="delete" aria-labelledby="delete-title">
        <h2 class="settings-card-title" id="delete-title">Delete account</h2>
        <p class="settings-card-intro">Removes your plants, listings and ratings for good.</p>
        <?php // Opens the confirmation popup below (app.js, data-dialog) ?>
        <button type="button" class="btn btn-danger btn-sm" data-dialog="delete-account" aria-haspopup="dialog"><?= icon('trash-2') ?> Delete account</button>
      </section>
    </div>
  </div>

  <?php // Delete account: destructive, so it is confirmed in a popup that names what goes (S6), like Delete plant on My Garden ?>
  <?php // A wrong password brings the popup back open, with the message under the field (app.js, data-open-on-load) ?>
  <dialog class="modal" id="delete-account" aria-labelledby="delete-account-title"<?= isset($errors['delete_password']) ? ' data-open-on-load' : '' ?>>
    <form class="modal-form" method="post" action="<?= e(url('settings.php#delete')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="delete_account">

      <div class="modal-head">
        <h2 class="modal-title" id="delete-account-title">Delete your account, @<?= e($me['username']) ?>?</h2>
        <?php // formnovalidate: closing must work while the required password field is still empty ?>
        <button class="icon-btn" formmethod="dialog" formnovalidate aria-label="Close"><?= icon('x') ?></button>
      </div>
      <div class="modal-body">
        <p>Your plants, stories, listings and ratings are deleted too. This can’t be undone.</p>
        <div class="field">
          <label class="field-label" for="delete-password">Your password</label>
          <input class="input" type="password" id="delete-password" name="current_password" autocomplete="current-password" required<?= $field_attributes('delete_password') ?>>
          <?php if (isset($errors['delete_password'])): ?>
            <p class="field-error" id="delete_password-error"><?= e($errors['delete_password']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-foot">
        <?php // Cancel has the focus, so pressing Enter by habit never deletes ?>
        <button class="btn btn-secondary" formmethod="dialog" formnovalidate autofocus>Cancel</button>
        <button class="btn btn-danger" type="submit"><?= icon('trash-2') ?> Delete account</button>
      </div>
    </form>
  </dialog>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
