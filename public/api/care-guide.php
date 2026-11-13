<?php
// AI care guide endpoint (Phase 5, ML-12): POST plant=<id> or prediction=<id> →
// {"state": "ready", "sections": {...}, "species": "…", "date": "…"} or {"state": "offline" | "invalid" | "no-species"}.
// Called by assets/js/care-guide.js when a Plant Profile or an Identify result shows the Generating card. Writes the
// guide with the local LLM once per species and saves it in care_guides.
// The species is never taken from the request: it is read from a plant the viewer may see (public, or their own), or
// from the viewer's own Identify result of 60% or more, so the endpoint cannot make the LLM write about anything else.

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/care-guide.php';
require_once __DIR__ . '/../../includes/ml-client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['state' => 'error', 'message' => 'Use POST.']));
}
check_csrf();   // the page's token: another site cannot start generations in a visitor's name

$viewer        = current_user();
$plant_id      = filter_var(post_text('plant'), FILTER_VALIDATE_INT);
$prediction_id = filter_var(post_text('prediction'), FILTER_VALIDATE_INT);

// The session is not needed any more. Releasing it now matters: PHP locks a session while a request uses it, and the
// LLM can take a minute or two, which would make every other page of this member wait.
session_write_close();

if ($prediction_id) {
    // Identify a plant: the member's own species result. Below 60% the page gives no answer, so no guide either.
    $prediction = $viewer ? get_prediction($viewer['id'], $prediction_id, 'species') : null;
    if (!$prediction || confidence_level($prediction['confidence'])['key'] === 'low') {
        http_response_code(404);   // missing, unsure or another member's: the same answer
        exit(json_encode(['state' => 'error', 'message' => 'Result not found.']));
    }
    $species = $prediction['predicted_label'];
} else {
    $stmt = db()->prepare(
        'SELECT p.id, p.user_id, p.is_public, p.species_id, p.custom_species, s.scientific_name
         FROM plants p LEFT JOIN plant_species s ON s.id = p.species_id
         WHERE p.id = :id AND (p.is_public = 1 OR p.user_id = :viewer)'
    );
    $stmt->execute(['id' => $plant_id ?: 0, 'viewer' => $viewer['id'] ?? 0]);
    $plant = $stmt->fetch();
    if (!$plant) {
        http_response_code(404);   // missing, or private and not yours: the same answer (privacy rule)
        exit(json_encode(['state' => 'error', 'message' => 'Plant not found.']));
    }
    $species = care_guide_species($plant);
}

if ($species === null || trim($species) === '') {
    exit(json_encode(['state' => 'no-species']));
}

set_time_limit((int) (config('llm_timeout') ?? 120) + 30);   // room for the LLM's own time limit
$result = care_guide_generate($species);
if ($result['state'] !== 'ready') {
    exit(json_encode(['state' => $result['state']]));
}

// json_encode() escapes the text for JSON; care-guide.js puts it on the page with textContent, never as HTML
echo json_encode([
    'state'    => 'ready',
    'species'  => $species,
    'date'     => date('j M Y', strtotime($result['row']['created_at'])),
    'sections' => $result['row']['guide'],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
