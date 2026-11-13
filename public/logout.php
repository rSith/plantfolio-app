<?php
// Log out (P02, CORE-09): ends the member's visit and returns to the Landing page.
// Only a POST with the CSRF token logs out (the "Log out" button in the header is a small form). Opening this address
// as a plain link does nothing: otherwise any other website could log a member out with a hidden image or link.
// log_out() (includes/auth.php) empties the session, deletes its cookie and destroys it on the server,
// so a new browser session starts as a visitor.

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    log_out();
}

// A visitor lands on the Landing page; a member who only opened the address goes on to their Dashboard from there
header('Location: ' . url('index.php'));
exit;
