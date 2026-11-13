<?php
// "About <species>" endpoint for Add plant (ML-13): POST prediction=<id>&pick=<0-2> → {"state": "ready", "about": "…"}
// or {"state": "offline" | "invalid"}. Called by assets/js/plant-form.js when the species the AI filled in has no
// saved introduction yet. The species is never taken from the request as text: it is the picked suggestion of one of
// the member's own results, so the endpoint cannot be used to make the LLM write about anything else.

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ml-client.php';
require_once __DIR__ . '/../../includes/care-guide.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['state' => 'error', 'message' => 'Use POST.']));
}
check_csrf();

$viewer = current_user();
if (!$viewer) {
    http_response_code(401);
    exit(json_encode(['state' => 'error', 'message' => 'Log in first.']));
}
$prediction_id = filter_var(post_text('prediction'), FILTER_VALIDATE_INT);
$pick          = post_text('pick');

// The LLM can take a minute or two: release the session lock so the member's other pages do not wait
session_write_close();

$result = $prediction_id ? get_prediction($viewer['id'], $prediction_id, 'species') : null;
if (!$result || !ctype_digit($pick) || !isset($result['predictions'][(int) $pick])) {
    http_response_code(404);
    exit(json_encode(['state' => 'error', 'message' => 'Result not found.']));
}

set_time_limit((int) (config('llm_timeout') ?? 120) + 30);
$answer = care_guide_about($result['predictions'][(int) $pick]['label']);
if ($answer['state'] !== 'ready') {
    exit(json_encode(['state' => $answer['state']]));
}

// json_encode() escapes the text for JSON; plant-form.js puts it on the page with textContent, never as HTML
echo json_encode(['state' => 'ready', 'about' => $answer['about']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
