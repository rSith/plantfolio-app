<?php
// The local LLM (Ollama) for the AI care guide: one function that asks for a JSON answer (Phase 5, care guide).
// PHP talks to Ollama with cURL on this machine only (http://127.0.0.1:11434); the browser never does. The provider's
// address, model and time limit come from config/config.php (llm_url, llm_model, llm_timeout), with the defaults
// below for a config.php made before these settings existed, so a free hosted API could be swapped in later.
// No API keys: Ollama runs locally and needs none.

require_once __DIR__ . '/helpers.php';   // config()

/**
 * Send a prompt to the local LLM and ask for a JSON object back.
 * Returns ['ok' => true, 'data' => [...decoded JSON...], 'model' => 'ollama:llama3.2'] or
 * ['ok' => false, 'state' => 'offline' | 'invalid'] — offline: no answer (not running, model missing, too slow);
 * invalid: an answer that is not a JSON object. $model overrides the configured model (used by tests).
 */
function llm_json(string $prompt, ?string $model = null): array
{
    $base    = rtrim(config('llm_url') ?? 'http://127.0.0.1:11434', '/');
    $model   = $model ?? (config('llm_model') ?? 'llama3.2');
    $timeout = (int) (config('llm_timeout') ?? 120);

    $curl = curl_init($base . '/api/generate');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        // format: json makes Ollama answer with valid JSON; stream: false gives one answer instead of a stream of words
        CURLOPT_POSTFIELDS     => json_encode([
            'model'   => $model,
            'prompt'  => $prompt,
            'format'  => 'json',
            'stream'  => false,
            'options' => ['temperature' => 0.3],   // low: plain, steady advice rather than creative writing
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,         // Ollama not running: give up quickly
        CURLOPT_TIMEOUT        => $timeout,  // a small laptop can take a minute or two to write a guide
    ]);
    $body   = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error  = curl_error($curl);
    curl_close($curl);

    if ($body === false || $status !== 200) {
        // Not running, timed out, or e.g. 404 "model not found": the reason goes to the log, the page says "offline"
        error_log('PlantFolio LLM: no answer from ' . $base . ' (' . $model . '): HTTP ' . $status . ' ' . $error . ' ' . substr((string) $body, 0, 200));
        return ['ok' => false, 'state' => 'offline'];
    }

    $outer = json_decode($body, true);
    $data  = is_array($outer) && isset($outer['response']) ? json_decode($outer['response'], true) : null;
    if (!is_array($data)) {
        return ['ok' => false, 'state' => 'invalid'];
    }
    return ['ok' => true, 'data' => $data, 'model' => 'ollama:' . $model];
}
