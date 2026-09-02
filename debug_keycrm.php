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

echo "=== SOURCES (шукай тут id для 'Мономаркет2') ===\n";
[$code, $raw] = kc('/source');
echo "HTTP $code\n$raw\n\n";

echo "=== ОСТАННІ 10 ЗАМОВЛЕНЬ з доставкою (шукай delivery_service_id для Нової Пошти) ===\n";
[$code, $raw] = kc('/order?limit=10&include=shipping&sort=-id');
echo "HTTP $code\n$raw\n";
