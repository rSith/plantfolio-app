<?php
// Live username check for Sign up (CORE-08): GET ?username=kasun.silva → {"available": true, "message": "…"}.
// Called by assets/js/register.js while the visitor types. It only helps: register.php checks again on submit.
// Read-only, so no CSRF token is needed. Whether a username is taken is public anyway (user.php?u=… shows every member).

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');   // the answer changes as people sign up, so never reuse an old one

$username = mb_strtolower(trim(get_text('username')));   // the same clean-up as register.php
$problem  = username_problem($username);

// json_encode() escapes quotes and <, > itself, so a name like <script> comes back as harmless text.
// JSON_HEX_TAG turns < and > into < / > as an extra guard.
echo json_encode([
    'available' => $problem === null,
    'message'   => $problem ?? '@' . $username . ' is available',
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
