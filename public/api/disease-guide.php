<?php
// AI advice endpoint for the Health Check (ML-14, D27): POST prediction=<id> → {"state": "ready", "symptoms": "…",
// "steps": ["…"]} or {"state": "offline" | "invalid"}. Called by assets/js/disease-guide.js when a Pl@ntNet result has
// no saved advice yet. The problem's name is never taken from the request: it is read from one of the member's own
// results, so the endpoint cannot be used to make the LLM write about anything else.

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/disease-guide.php';

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

// The LLM can take a minute or two: release the session lock so the member's other pages do not wait
session_write_close();

$result = $prediction_id ? get_prediction($viewer['id'], $prediction_id, 'disease') : null;
// Only a Pl@ntNet answer the page would show (a Possible match): our model's guides are written by hand
if (!$result || !is_plantnet_disease($result['predicted_label']) || disease_level($result['predictions'])['key'] === 'low') {
    http_response_code(404);
    exit(json_encode(['state' => 'error', 'message' => 'Result not found.']));
}

set_time_limit((int) (config('llm_timeout') ?? 120) + 30);
$answer = disease_guide_generate($result['predicted_label']);
if ($answer['state'] !== 'ready') {
    exit(json_encode(['state' => $answer['state']]));
}

// json_encode() escapes the text for JSON; disease-guide.js puts it on the page with textContent, never as HTML
echo json_encode([
    'state'    => 'ready',
    'symptoms' => $answer['guide']['symptoms'],
    'steps'    => $answer['guide']['steps'],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
