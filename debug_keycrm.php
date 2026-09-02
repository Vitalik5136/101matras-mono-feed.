<?php
// TEMPORARY DEBUG SCRIPT -- delete this file once you've noted the IDs
// you need. It exposes data from your KeyCRM account to anyone who knows
// this URL, so it should not stay live long-term.

$apiKey = getenv('KEYCRM_API_KEY');
if (!$apiKey) {
    die('KEYCRM_API_KEY is not set as an environment variable.');
}

function kc($path) {
    global $apiKey;
    $ch = curl_init('https://openapi.keycrm.app/v1' . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== СТАТУСИ ВОРОНКИ ===\n";
[$code, $raw] = kc('/pipelines/1/statuses');
echo "HTTP $code\n" . substr($raw, 0, 6000) . "\n\n";

