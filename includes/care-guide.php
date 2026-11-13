<?php
// AI care guide: the five sections, which species a plant's guide is for, and the care_guides cache (one saved guide
// per species, so the local LLM writes each guide once). The call to the LLM itself is in includes/llm-client.php.

require_once __DIR__ . '/db.php';

/**
 * The five sections in the order the card shows them: key in guide_json → label and Lucide icon.
 * The model is asked for exactly these keys, and stage B saves a guide only when all five are present.
 */
function care_guide_sections()
{
    return [
        'light'             => ['label' => 'Light',                 'icon' => 'sun'],
        'soil_fertilizer'   => ['label' => 'Soil and fertiliser',   'icon' => 'shovel'],
        'watering'          => ['label' => 'Watering',              'icon' => 'droplet'],
        'disease_control'   => ['label' => 'Disease control',       'icon' => 'shield'],
        'structure_pruning' => ['label' => 'Structure and pruning', 'icon' => 'scissors'],
    ];
}

/**
 * The species name a plant's guide is written for, or null when the plant has none.
 * A species from our list gives its scientific name, so "Tomato" and "Solanum lycopersicum" share one guide;
 * a name typed by the member (custom_species) is used as it is.
 * Later the ML plant identifier may supply the name: only this function needs to change.
 */
function care_guide_species($plant)
{
    if ($plant['species_id'] !== null) {
        return $plant['scientific_name'];
    }
    $custom = trim($plant['custom_species'] ?? '');
    return $custom === '' ? null : $custom;
}

/**
 * The cache key for a species name: trimmed, lower case, single spaces.
 * "Tomato " and "tomato" give the same key, so they share one row of care_guides.
 */
function normalise_species($name)
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
}

/**
 * The saved guide for a species name: the care_guides row (species, model_used, created_at) with 'guide' holding the
 * five sections as key => text; null when there is none yet. A saved row missing any of the five sections counts as
 * no guide, so the card never shows half a guide.
 */
function care_guide_get($species)
{
    $stmt = db()->prepare('SELECT species, guide_json, model_used, created_at FROM care_guides WHERE species = :species');
    $stmt->execute(['species' => normalise_species($species)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['guide'] = care_guide_clean(json_decode($row['guide_json'], true));
    return $row['guide'] === null ? null : $row;
}

/**
 * Write a care guide for a species with the local LLM and save it, unless one is saved already.
 * Returns ['state' => 'ready', 'row' => care_guide_get() row] or ['state' => 'offline' | 'invalid'] (nothing saved).
 * The answer is saved only when all five sections are present (care_guide_clean()). INSERT IGNORE: if two visitors
 * asked at the same moment, the second answer is simply dropped (UNIQUE species).
 */
function care_guide_generate($species, $model = null)
{
    $row = care_guide_get($species);
    if ($row) {
        return ['state' => 'ready', 'row' => $row];
    }

    require_once __DIR__ . '/llm-client.php';
    $keys   = array_keys(care_guide_sections());
    $prompt = 'You are a friendly plant care expert writing for beginners in Sri Lanka (a tropical climate with a '
            . 'rainy season). Write a short care guide for the plant species ' . species_for_prompt($species) . '. '
            . 'Answer with a JSON object with exactly these keys: about, ' . implode(', ', $keys) . '. '
            . 'about: two or three sentences introducing the plant: what it is, where it comes from, what it looks like. '
            . 'Each value is plain text of one or two short sentences, with no lists, no markdown and no HTML. '
            . 'light: how much light it needs. soil_fertilizer: the soil and how often to feed it. '
            . 'watering: when and how much to water. disease_control: the common problems and how to prevent them. '
            . 'structure_pruning: support, pruning and repotting.';
    $answer = llm_json($prompt, $model);
    if (!$answer['ok']) {
        return ['state' => $answer['state']];
    }
    $guide = care_guide_clean($answer['data']);
    if ($guide === null) {
        return ['state' => 'invalid'];   // a missing or empty section: nothing is saved
    }

    $stmt = db()->prepare('INSERT IGNORE INTO care_guides (species, guide_json, model_used) VALUES (:species, :json, :model)');
    $stmt->execute(['species' => normalise_species($species), 'json' => json_encode($guide, JSON_UNESCAPED_UNICODE),
                    'model' => mb_substr($answer['model'], 0, 50)]);
    return ['state' => 'ready', 'row' => care_guide_get($species)];
}

/**
 * A species as the LLM prompt names it: '"Ficus lyrata" (common name: Fiddle-leaf fig)' when our species list knows it,
 * else just '"<name>"'. With the scientific name alone, llama3 mixed Ficus lyrata up with other figs (tested 12 Nov 2026).
 */
function species_for_prompt($species)
{
    $stmt = db()->prepare('SELECT common_name FROM plant_species WHERE scientific_name = :name');
    $stmt->execute(['name' => $species]);
    $common = $stmt->fetchColumn();
    return '"' . $species . '"' . ($common !== false && strcasecmp($common, $species) !== 0 ? ' (common name: ' . $common . ')' : '');
}

/** Forget the saved guide for a species, so the next visit writes a new one (the owner's Regenerate). */
function care_guide_forget($species)
{
    $stmt = db()->prepare('DELETE FROM care_guides WHERE species = :species');
    $stmt->execute(['species' => normalise_species($species)]);
}

/**
 * Keep exactly the five sections, each a non-empty piece of text, or return null when any is missing. Used for the
 * saved rows and for the LLM's answer before it is saved (an AI answer is checked like any other input).
 * 'about' (a short introduction shown on Add plant, ML-13) is kept when it is there but never required, so guides
 * saved before it existed still count.
 */
function care_guide_clean($guide)
{
    if (!is_array($guide)) {
        return null;
    }
    $clean = [];
    foreach (array_keys(care_guide_sections()) as $key) {
        $text = $guide[$key] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }
        $clean[$key] = trim($text);
    }
    if (is_string($guide['about'] ?? null) && trim($guide['about']) !== '') {
        $clean['about'] = mb_substr(trim($guide['about']), 0, 1000);
    }
    return $clean;
}

/**
 * The "About <species>" text for Add plant (ML-13), writing it with the LLM when it is not saved yet.
 * No guide for the species: the whole care guide is written, with 'about' in the same answer, so the new plant's
 * profile opens with its guide ready. A guide saved before 'about' existed gets only the about text added to its row.
 * Returns ['state' => 'ready', 'about' => '…'] or ['state' => 'offline' | 'invalid'] (nothing saved).
 */
function care_guide_about($species, $model = null)
{
    $result = care_guide_generate($species, $model);
    if ($result['state'] !== 'ready') {
        return $result;
    }
    if (isset($result['row']['guide']['about'])) {
        return ['state' => 'ready', 'about' => $result['row']['guide']['about']];
    }

    require_once __DIR__ . '/llm-client.php';
    // The answer's shape is shown, because llama3 tends to use the plant's name as the key when only told "one key"
    $answer = llm_json('You are a friendly plant expert writing for beginners. Introduce the plant species '
                     . species_for_prompt($species) . ' in two or three sentences: what it is, where it comes from, '
                     . 'what it looks like. Plain text only: no markdown, no HTML. '
                     . 'Answer with exactly this JSON shape: {"about": "your sentences"}', $model);
    if (!$answer['ok']) {
        return ['state' => $answer['state']];
    }
    $about = $answer['data']['about'] ?? null;
    if ($about === null && count($answer['data']) === 1) {
        // Still one piece of text under another key ({"Ficus lyrata": "…"}): it is the introduction, so keep it
        $about = reset($answer['data']);
    }
    if (!is_string($about) || trim($about) === '') {
        return ['state' => 'invalid'];
    }
    $about = mb_substr(trim($about), 0, 1000);
    // JSON_SET adds the one key to the saved JSON and leaves the five sections as they are
    $stmt = db()->prepare("UPDATE care_guides SET guide_json = JSON_SET(guide_json, '$.about', :about) WHERE species = :species");
    $stmt->execute(['about' => $about, 'species' => normalise_species($species)]);
    return ['state' => 'ready', 'about' => $about];
}
