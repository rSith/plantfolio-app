<?php
// AI advice for a Health Check result (ML-14, decision D27): Pl@ntNet names a disease or pest but gives no advice, so the
// local LLM writes what it looks like and what to do, once per problem, and the answer is saved in disease_guides
// (keyed by the EPPO code). Same pattern as the AI care guide (includes/care-guide.php): cached, checked, never half saved.

require_once __DIR__ . '/ml-client.php';   // disease_guide(), disease_guide_key()

/**
 * The saved guide for a Pl@ntNet label, writing it with the LLM first when there is none.
 * Returns ['state' => 'ready', 'guide' => disease_guide() row] or ['state' => 'offline' | 'invalid'] (nothing saved).
 * INSERT IGNORE: if two members asked at the same moment, the second answer is simply dropped (UNIQUE class_label).
 */
function disease_guide_generate(string $label, ?string $model = null): array
{
    $guide = disease_guide($label);
    if ($guide) {
        return ['state' => 'ready', 'guide' => $guide];
    }

    require_once __DIR__ . '/llm-client.php';
    $name       = prediction_name(['type' => 'disease', 'predicted_label' => $label]);
    $scientific = disease_scientific_name($label);
    $prompt = 'You are a friendly plant doctor writing for beginner home gardeners in Sri Lanka (a tropical climate with a '
            . 'rainy season). A photo of a plant possibly shows this disease or pest: "' . $name . '"'
            . ($scientific ? ' (' . $scientific . ')' : '') . '. '
            . 'Answer with a JSON object with exactly these keys: symptoms, steps, status. '
            . 'symptoms: two short sentences on what it looks like on the leaves or plant, so the gardener can compare. '
            . 'steps: an array of 3 or 4 short practical steps to take now, gentle and safe options first, no brand names. '
            . 'status: "sick" if it usually badly damages or kills the plant, otherwise "needs_attention". '
            . 'Plain text only: no markdown, no HTML.';
    $answer = llm_json($prompt, $model);
    if (!$answer['ok']) {
        return ['state' => $answer['state']];
    }
    $clean = disease_guide_clean($answer['data']);
    if ($clean === null) {
        return ['state' => 'invalid'];   // a missing or empty part: nothing is saved
    }

    // crop is NOT NULL but Pl@ntNet names no crop: an empty text marks a guide written by the AI
    $stmt = db()->prepare(
        'INSERT IGNORE INTO disease_guides (class_label, crop, cause, symptoms, steps, status)
         VALUES (:label, :crop, :cause, :symptoms, :steps, :status)'
    );
    $stmt->execute([
        'label'    => disease_guide_key($label),
        'crop'     => '',
        'cause'    => mb_substr($scientific ?? $name, 0, 255),
        'symptoms' => $clean['symptoms'],
        'steps'    => json_encode($clean['steps'], JSON_UNESCAPED_UNICODE),
        'status'   => $clean['status'],
    ]);
    return ['state' => 'ready', 'guide' => disease_guide($label)];
}

/**
 * Keep the LLM's answer only when it has everything: symptoms as text, 2–6 steps as text, and a status the database
 * allows for a problem ('needs_attention' or 'sick'). Otherwise null. An AI answer is checked like any other input.
 */
function disease_guide_clean($data): ?array
{
    if (!is_array($data) || !is_string($data['symptoms'] ?? null) || trim($data['symptoms']) === ''
        || !is_array($data['steps'] ?? null)) {
        return null;
    }
    $steps = [];
    foreach ($data['steps'] as $step) {
        if (is_string($step) && trim($step) !== '') {
            $steps[] = mb_substr(trim($step), 0, 300);
        }
    }
    if (count($steps) < 2) {
        return null;
    }
    $status = in_array($data['status'] ?? null, ['needs_attention', 'sick'], true) ? $data['status'] : 'needs_attention';
    return ['symptoms' => mb_substr(trim($data['symptoms']), 0, 1000), 'steps' => array_slice($steps, 0, 6), 'status' => $status];
}
