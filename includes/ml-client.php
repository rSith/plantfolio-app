<?php
// The Flask ML service (Phase 6): send a photo, read the top 3, log every result in ml_predictions, read results back
// (ML-02, ML-09 – ML-11). Only PHP talks to Flask, with cURL; the browser never does. If Flask is not running or takes
// longer than ml_timeout_seconds (10 s), the pages show their "couldn't reach" state and the member goes on by hand:
// the site never breaks because of ML.

require_once __DIR__ . '/db.php';

/**
 * Send a saved photo to Flask: POST /predict/species or /predict/disease, multipart field "image".
 * Returns ['ok' => true, 'model_version' => 'disease-mobilenetv2-v1', 'predictions' => [['label' => …, 'confidence' => 0.92], …]]
 * (most likely first, at most 3) or ['ok' => false] when Flask did not answer or answered something unusable.
 */
function ml_predict(string $type, string $file, string $mime): array
{
    if (!in_array($type, ['species', 'disease'], true)) {
        throw new InvalidArgumentException('Unknown prediction type: ' . $type);
    }
    $base = rtrim(config('ml_service_url') ?? 'http://127.0.0.1:5000', '/');
    $curl = curl_init($base . '/predict/' . $type);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['image' => new CURLFile($file, $mime, basename($file))],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => (int) (config('ml_timeout_seconds') ?? 10),
    ]);
    $body   = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $answer = ($body !== false && $status === 200) ? json_decode($body, true) : null;
    if (!is_array($answer) || !is_string($answer['model_version'] ?? null) || !is_array($answer['predictions'] ?? null)) {
        error_log('PlantFolio ML: no usable answer from ' . $base . '/predict/' . $type . ' (HTTP ' . $status . ')');
        return ['ok' => false];
    }
    // An answer from another program is checked like any input: each prediction a text label and a number 0–1
    $predictions = [];
    foreach ($answer['predictions'] as $prediction) {
        $label      = $prediction['label'] ?? null;
        $confidence = $prediction['confidence'] ?? null;
        if (is_string($label) && $label !== '' && mb_strlen($label) <= 150 && is_numeric($confidence)
            && $confidence >= 0 && $confidence <= 1) {
            $predictions[] = ['label' => $label, 'confidence' => (float) $confidence];
        }
    }
    usort($predictions, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
    if (!$predictions) {
        return ['ok' => false];
    }
    return ['ok' => true, 'model_version' => mb_substr($answer['model_version'], 0, 50), 'predictions' => array_slice($predictions, 0, 3)];
}

/**
 * Log a result in ml_predictions (ML-11): every one, sure or not, so accuracy can be measured for the report.
 * The top prediction gets its own columns; the two runners-up go in alternatives (JSON). Returns the new id.
 */
function save_prediction(int $user_id, ?int $plant_id, string $type, array $result, string $image_path, ?string $upload_name, ?int $upload_size): int
{
    $top  = $result['predictions'][0];
    $stmt = db()->prepare(
        'INSERT INTO ml_predictions (user_id, plant_id, type, predicted_label, confidence, alternatives, image_path, upload_name, upload_size, model_version)
         VALUES (:user_id, :plant_id, :type, :label, :confidence, :alternatives, :image_path, :upload_name, :upload_size, :model_version)'
    );
    $stmt->execute([
        'user_id'       => $user_id,
        'plant_id'      => $plant_id,
        'type'          => $type,
        'label'         => $top['label'],
        'confidence'    => round($top['confidence'], 4),
        'alternatives'  => json_encode(array_slice($result['predictions'], 1)),
        'image_path'    => $image_path,
        'upload_name'   => $upload_name === null ? null : mb_substr($upload_name, 0, 255),
        'upload_size'   => $upload_size,
        'model_version' => $result['model_version'],
    ]);
    return (int) db()->lastInsertId();
}

/**
 * One of the member's own results of a type, in the shape of the stage F mock results: the row, plus 'predictions',
 * the top 3 (top first). null when it does not exist, is another member's, or is of the other type.
 */
function get_prediction(int $user_id, int $id, string $type): ?array
{
    $stmt = db()->prepare(
        'SELECT id, user_id, plant_id, type, predicted_label, confidence, alternatives, image_path, upload_name, upload_size,
                model_version, created_at
         FROM ml_predictions WHERE id = :id AND user_id = :user_id AND type = :type'
    );
    $stmt->execute(['id' => $id, 'user_id' => $user_id, 'type' => $type]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['confidence']  = (float) $row['confidence'];
    $alternatives       = json_decode((string) $row['alternatives'], true);
    $row['predictions'] = array_merge(
        [['label' => $row['predicted_label'], 'confidence' => $row['confidence']]],
        is_array($alternatives) ? $alternatives : []
    );
    $row['upload_size'] = (int) $row['upload_size'];
    $row['upload_name'] = $row['upload_name'] ?? basename($row['image_path']);
    return $row;
}

/**
 * The species result for a Health Check photo: the Health Check also asks which plant is in the photo when no garden
 * plant was chosen, and logs that answer as a species row with the same image_path. Null when there is none.
 */
function species_for_photo(int $user_id, string $image_path): ?array
{
    $stmt = db()->prepare(
        'SELECT id FROM ml_predictions WHERE user_id = :user_id AND type = :type AND image_path = :path ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['user_id' => $user_id, 'type' => 'species', 'path' => $image_path]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : get_prediction($user_id, (int) $id, 'species');
}

/** Attach one of the member's results to one of their plants (a saved diagnosis, or a plant added from a result). */
function attach_prediction(int $user_id, int $prediction_id, int $plant_id): void
{
    $stmt = db()->prepare(
        'UPDATE ml_predictions m JOIN plants p ON p.id = :plant_id AND p.user_id = :user_id
         SET m.plant_id = p.id WHERE m.id = :id AND m.user_id = :user_id2'
    );
    $stmt->execute(['plant_id' => $plant_id, 'user_id' => $user_id, 'id' => $prediction_id, 'user_id2' => $user_id]);
}

/**
 * The disease_guides key for a label: our model's whole class name ("Tomato___Early_blight"), or for a Pl@ntNet label
 * only its EPPO code ("PHYTIN" from "PHYTIN___Phytophthora infestans - Late blight of potato"), which never changes.
 */
function disease_guide_key(string $label): string
{
    return is_plantnet_disease($label) ? explode('___', $label, 2)[0] : $label;
}

/**
 * What the Health Check says about a disease class, from disease_guides: crop, status, cause, symptoms, steps (list).
 * Our model's guides were written by hand (seed.sql); Pl@ntNet ones by the local LLM (includes/disease-guide.php).
 */
function disease_guide(string $label): ?array
{
    $stmt = db()->prepare('SELECT class_label, crop, cause, symptoms, steps, status FROM disease_guides WHERE class_label = :label');
    $stmt->execute(['label' => disease_guide_key($label)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['steps'] = json_decode((string) $row['steps'], true) ?: [];
    return $row;
}
