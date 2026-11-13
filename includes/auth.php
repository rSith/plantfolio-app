<?php
// Sessions, CSRF tokens and the logged-in member: the account helpers every page shares (CORE-10, CORE-11).
// require_login() and ownership checks join this file with Log in (CORE-09, CORE-10).

require_once __DIR__ . '/db.php';   // also loads helpers.php: config(), e()

/**
 * Start the session with safe cookie settings. Safe to call more than once.
 * Must run before any HTML is sent, because the session cookie travels in the response headers.
 */
function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Refuse session ids the server never created, so an attacker cannot hand a victim a known id (session fixation).
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,                            // a browser-session cookie; "Remember me" (CORE-09) adds its own
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),    // HTTPS-only on a hosted copy; XAMPP runs on plain http
        'httponly' => true,                         // JavaScript cannot read it, so an XSS bug cannot steal the session
        'samesite' => 'Lax',                        // other sites' forms cannot send it along (a second CSRF defence)
    ]);
    session_start();
}

// ── CSRF tokens ──────────────────────────────────────────────────────────────
// A random secret kept in the session and copied into every POST form. Another website can make a visitor's browser
// send a form to PlantFolio, but it cannot read our pages, so it never knows the token, and check_csrf() refuses it.

/** This session's token, made on first use. */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));   // 64 hex characters from a secure random source
    }
    return $_SESSION['csrf_token'];
}

/** The hidden field to print inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Stop the request unless the POSTed token matches the session's. Call it first thing when handling a POST. */
function check_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    // hash_equals() takes the same time however many characters match, so the token cannot be guessed bit by bit.
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('This form has expired. Go back, refresh the page and try again.');
    }
}

// ── The logged-in member ─────────────────────────────────────────────────────

/**
 * The logged-in member's users row (with towns.name AS location, as the mock rows call it), or null for a visitor.
 * Read once per request. The password hash and lockout columns are never selected: pages don't need them.
 */
function current_user(): ?array
{
    static $user = false;   // false = not looked up yet; null = visitor

    if ($user === false) {
        start_session();
        $user = null;
        if (empty($_SESSION['user_id'])) {
            remember_me_log_in();   // a "Remember me" cookie from an earlier visit starts a new session
        }
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare(
                'SELECT u.id, u.username, u.full_name, u.email, u.phone, u.bio, u.town_id, t.name AS location,
                        u.km_from_town, u.avatar, u.new_plants_public, u.dashboard_reminders, u.email_reminders, u.created_at
                 FROM users u LEFT JOIN towns t ON t.id = u.town_id
                 WHERE u.id = :id'
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            if ($user === null) {
                unset($_SESSION['user_id']);   // the account was deleted: treat the browser as a visitor
            }
        }
    }

    return $user;
}

/**
 * First line of every members-only page (CORE-10). A visitor is sent to Log in, with ?next= this page, so they come
 * straight back after logging in (login.php checks next with return_path()). A member carries on.
 */
function require_login(): void
{
    if (current_user() !== null) {
        return;
    }
    $here = current_page() . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: ' . query_url('login.php', ['next' => return_path($here)]));
    exit;
}

/**
 * What is wrong with a username, as a message for the form, or null when it is free to take (CORE-07, CORE-08).
 * The one copy of the rule: Sign up checks it on submit, and api/check-username.php while the visitor types.
 * Expects the name already trimmed and lowercased.
 */
function username_problem(string $username): ?string
{
    if ($username === '') {
        return 'Choose a username.';
    }
    if (!preg_match('/^[a-z0-9._]{3,30}\z/', $username)) {
        return 'Use 3 to 30 lowercase letters, numbers, dots or underscores.';
    }
    $stmt = db()->prepare('SELECT 1 FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    if ($stmt->fetchColumn()) {
        return '@' . $username . ' is already taken. Try another username.';
    }
    return null;
}

// ── Logging in (CORE-09) with the lockout (CORE-12) ──────────────────────────

const LOGIN_MAX_FAILURES = 5;    // wrong passwords in a row before the account locks
const LOGIN_LOCK_MINUTES = 15;

/**
 * Check a login (username or email) and password. Returns ['ok' => true, 'user_id' => 7] or
 * ['ok' => false, 'error' => 'message for the form']. Does not start the session: the page calls log_in().
 */
function attempt_login(string $login, string $password): array
{
    $generic = 'Email or password is incorrect.';

    // Named placeholders can be used once each with real prepared statements, so the same value is sent twice.
    // minutes_locked is worked out by MySQL, on the same clock as locked_until.
    $stmt = db()->prepare(
        'SELECT id, password_hash,
                CEIL(TIMESTAMPDIFF(SECOND, NOW(), locked_until) / 60) AS minutes_locked
         FROM users WHERE username = :username OR email = :email'
    );
    $stmt->execute(['username' => $login, 'email' => $login]);
    $user = $stmt->fetch();

    if ($user === false) {
        // No such member. Check the password against a made-up hash anyway, so this answer takes as long as a
        // wrong password does: a quick reply would tell an attacker which usernames do not exist.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return ['ok' => false, 'error' => $generic];
    }

    $result = check_password_and_count($user, $password);
    if ($result === 'locked') {
        return ['ok' => false, 'error' => locked_message((int) $user['minutes_locked'])];
    }
    if ($result === 'wrong') {
        return ['ok' => false, 'error' => $generic];
    }
    return ['ok' => true, 'user_id' => (int) $user['id']];
}

/**
 * The lockout rule in one place, for Log in and for the "current password" fields in Settings (CORE-12, CORE-26).
 * $user needs id, password_hash and minutes_locked (see the SELECTs). Returns 'locked', 'wrong' or 'ok'.
 * A wrong password counts towards the lock; a right one clears the count.
 */
function check_password_and_count(array $user, string $password): string
{
    // Locked: even the right password is refused until the time is up
    if ($user['minutes_locked'] !== null && $user['minutes_locked'] > 0) {
        return 'locked';
    }

    if (!password_verify($password, $user['password_hash'])) {
        // One more failure, counted by MySQL itself, so two attempts at the same moment both count. On the 5th the
        // account locks and the count starts again. MySQL runs SET from left to right, so locked_until is decided
        // while failed_logins still holds the old number.
        $stmt = db()->prepare(
            'UPDATE users
             SET locked_until  = IF(failed_logins + 1 >= :max1, NOW() + INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE, locked_until),
                 failed_logins = IF(failed_logins + 1 >= :max2, 0, failed_logins + 1)
             WHERE id = :id'
        );
        $stmt->execute(['max1' => LOGIN_MAX_FAILURES, 'max2' => LOGIN_MAX_FAILURES, 'id' => $user['id']]);
        return 'wrong';
    }

    // Right password: clear the counter, and store a stronger hash if PHP's default has improved since sign-up
    $stmt = db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $stmt = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
    }
    return 'ok';
}

/** "Too many attempts. Try again in 12 minutes." */
function locked_message(int $minutes): string
{
    return 'Too many attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

/**
 * Check the logged-in member's current password before a sensitive change (new email, new password, delete account).
 * Returns null when it is right, or the message for the form. Wrong answers count towards the same 5-try lockout as
 * Log in, so a computer left logged in cannot be used to guess the password here without limit.
 */
function current_password_problem(int $user_id, string $password): ?string
{
    if ($password === '') {
        return 'Enter your current password.';
    }
    $stmt = db()->prepare(
        'SELECT id, password_hash, CEIL(TIMESTAMPDIFF(SECOND, NOW(), locked_until) / 60) AS minutes_locked
         FROM users WHERE id = :id'
    );
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();

    $result = $user ? check_password_and_count($user, $password) : 'wrong';
    if ($result === 'locked') {
        return locked_message((int) $user['minutes_locked']);
    }
    return $result === 'wrong' ? 'Current password is incorrect.' : null;
}

/** Log a member in after their password (or a new registration) has been checked. */
function log_in(int $user_id): void
{
    start_session();
    // A new session id at the moment of login: an id someone saw before login is worthless afterwards.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    unset($_SESSION['csrf_token']);   // a fresh token for the logged-in session
}

// ── "Remember me" (CORE-09, DES-07 decision 5) ───────────────────────────────
// PHP deletes an idle session on the server after about 24 minutes, so a long session cookie alone cannot keep a member
// logged in for days. Instead the browser keeps a second cookie, "selector:validator":
//   selector  — 24 random hex characters, stored as is, used to find the row (like a username)
//   validator — 64 random hex characters, stored only as hash('sha256', …) (like a password)
// A stolen copy of the database therefore cannot log anyone in. Each token works once: using it replaces it.

const REMEMBER_COOKIE = 'plantfolio_remember';
const REMEMBER_DAYS   = 30;

/** Give this browser a new "Remember me" token for the member, valid for 30 days. */
function remember_me_set(int $user_id): void
{
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));

    // Expired tokens of this member are no use to anyone: clear them while we are here
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = :id AND expires_at < NOW()')->execute(['id' => $user_id]);
    db()->prepare(
        'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (:user_id, :selector, :token_hash, NOW() + INTERVAL ' . REMEMBER_DAYS . ' DAY)'
    )->execute(['user_id' => $user_id, 'selector' => $selector, 'token_hash' => hash('sha256', $validator)]);

    remember_cookie($selector . ':' . $validator, time() + REMEMBER_DAYS * 86400);
}

/** Log in from the "Remember me" cookie, if it holds a valid token. Called by current_user() when there is no session login. */
function remember_me_log_in(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})\z/', $cookie, $parts)) {
        if ($cookie !== '') {
            remember_cookie('', time() - 3600);   // a broken cookie: delete it
        }
        return;
    }
    [, $selector, $validator] = $parts;

    $stmt = db()->prepare('SELECT id, user_id, token_hash, expires_at > NOW() AS valid FROM remember_tokens WHERE selector = :selector');
    $stmt->execute(['selector' => $selector]);
    $token = $stmt->fetch();

    // The token is used up either way: a right one is replaced below, a wrong or expired one must not be tried again
    if ($token) {
        db()->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute(['id' => $token['id']]);
    }
    if (!$token || !$token['valid'] || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        remember_cookie('', time() - 3600);
        return;
    }

    log_in($token['user_id']);
    remember_me_set($token['user_id']);   // a fresh token for next time
}

/** Set or delete the "Remember me" cookie, with the same safe flags as the session cookie. */
function remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Log out: forget this browser's "Remember me" token, empty the session, delete its cookie and destroy it. */
function log_out(): void
{
    start_session();
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && preg_match('/^([0-9a-f]{24}):/', $cookie, $parts)) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = :selector')->execute(['selector' => $parts[1]]);
    }
    remember_cookie('', time() - 3600);
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $cookie['path'],
        'secure'   => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}
