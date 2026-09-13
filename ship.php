<?php
// ==========================================================
// Автоматичне створення кур'єрської ТТН Нової Пошти з
// MarketplacePartnerToken для замовлень з Мономаркету -- в обхід
// звичайного інтерфейсу KeyCRM (він не підтримує цей параметр).
// ==========================================================
//
// USAGE: відкрий /ship.php?order_id=<KeyCRM_order_id> у браузері,
// коли готовий відправити конкретне замовлення кур'єром.
//
// Це стосується ТІЛЬКИ кур'єрської доставки до квартири
// (courier:nova-post). Доставку на відділення/склад KeyCRM і так
// створює сам через свою вбудовану інтеграцію -- там спеціальний
// токен не потрібен для звичайних сум (тільки понад 100 000 грн
// у поштомат, що не потребує окремого коду).

define('NOVA_POSHTA_MARKETPLACE_PARTNER_TOKEN', '1ba2a77906a9-a827-46f4-3555-e60089ac');

// Реквізити відправника -- отримані раніше через Нову Пошту API.
// Якщо відправник в акаунті зміниться, ці Ref потрібно буде оновити.
define('NP_SENDER_REF', '6bda5786-cf6a-11f0-a1d5-48df37b921da');
define('NP_SENDER_CONTACT_REF', '9d12989e-cf79-11f0-a1d5-48df37b921da');
define('NP_SENDER_ADDRESS_REF', '93d0e241-da6b-11f0-a1d5-48df37b921da');
define('NP_SENDER_CITY_REF', 'db5c8904-391c-11dd-90d9-001a92567626');

function keycrmRequestShip($method, $path, $body = null) {
    $apiKey = getenv('KEYCRM_API_KEY');
    $ch = curl_init('https://openapi.keycrm.app/v1' . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true)];
}

function novaPoshtaRequest($modelName, $calledMethod, $methodProperties) {
    $apiKey = getenv('NP_API_KEY');
    $payload = [
        'apiKey' => $apiKey,
        'modelName' => $modelName,
        'calledMethod' => $calledMethod,
        'methodProperties' => $methodProperties,
    ];
    $ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true);
}

header('Content-Type: text/plain; charset=utf-8');

$orderId = $_GET['order_id'] ?? null;
if (!$orderId) {
    die("Використання: ?order_id=<KeyCRM_order_id>\n");
}
if (!getenv('NP_API_KEY')) {
    die("Змінна середовища NP_API_KEY не задана в Render.\n");
}

echo "=== 1. Отримую замовлення $orderId з KeyCRM ===\n";
[$code, $order] = keycrmRequestShip('GET', "/order/{$orderId}?include=shipping");
if ($code !== 200 || !$order) {
    die("Не вдалось отримати замовлення (HTTP $code)\n");
}

$shipping = $order['shipping'] ?? [];
$buyer = $order['buyer'] ?? [];
$comment = $order['manager_comment'] ?? '';

if (!empty($shipping['tracking_code'])) {
    die("⚠️ У цього замовлення вже є ТТН: {$shipping['tracking_code']} -- нічого не роблю.\n");
}

if (!empty($shipping['is_warehouse'])) {
    die("Це доставка на відділення/склад -- KeyCRM створює таку ТТН сам, цей скрипт тут не потрібен.\n");
}

$fullName = $shipping['recipient_full_name'] ?? $buyer['full_name'] ?? '';
$nameParts = preg_split('/\s+/', trim($fullName));
$lastName = $nameParts[0] ?? '';
$firstName = $nameParts[1] ?? '';
$middleName = $nameParts[2] ?? '';
$phone = preg_replace('/\D/', '', $shipping['recipient_phone'] ?? $buyer['phone'] ?? '');
if (strlen($phone) === 10) $phone = '38' . $phone; // ensure country code

$secondaryLine = $shipping['shipping_secondary_line'] ?? '';
preg_match('/кв\.\s*(\S+)/u', $secondaryLine, $flatMatch);
preg_match('/поверх\s*(\S+)/u', $secondaryLine, $floorMatch);
$flat = $flatMatch[1] ?? '';
$floor = $floorMatch[1] ?? '1';
$streetAddress = trim(preg_replace('/,\s*кв\..*$/u', '', $secondaryLine));

preg_match('/np_settlement_id=([a-f0-9\-]+)/i', $comment, $settlementMatch);
$settlementId = $settlementMatch[1] ?? null;
$cityName = $shipping['shipping_address_city'] ?? '';

echo "Отримувач: $lastName $firstName $middleName | $phone\n";
echo "Місто: $cityName" . ($settlementId ? " ($settlementId)" : " (settlementId НЕ знайдено в коментарі!)") . "\n";
echo "Адреса: $streetAddress, кв. $flat, поверх $floor\n\n";

if (!$settlementId) {
    die("❌ Не можу створити ТТН без settlementId -- це замовлення створене до того, як ми почали його зберігати. Впиши вручну через кабінет НП цього разу.\n");
}

echo "=== 2. Створюю отримувача-контрагента в Новій Пошті ===\n";
$recipientResult = novaPoshtaRequest('Counterparty', 'save', [
    'CounterpartyType' => 'PrivatePerson',
    'CounterpartyProperty' => 'Recipient',
    'FirstName' => $firstName,
    'LastName' => $lastName,
    'MiddleName' => $middleName,
    'Phone' => $phone,
]);
if (empty($recipientResult['success']) || empty($recipientResult['data'][0]['Ref'])) {
    echo json_encode($recipientResult, JSON_UNESCAPED_UNICODE) . "\n";
    die("❌ Не вдалось створити отримувача в НП.\n");
}
$recipientRef = $recipientResult['data'][0]['Ref'];
$recipientContactRef = $recipientResult['data'][0]['ContactPerson']['data'][0]['Ref'] ?? null;
echo "Recipient Ref: $recipientRef\n";

echo "\n=== 3. Створюю ЕН (кур'єр до квартири) ===\n";

$isLargeItem = false; // TODO: підʼєднати реальну вагу товару, якщо буде потрібно

$methodProperties = [
    'PayerType' => 'Sender',
    'PaymentMethod' => 'Cash',
    'CargoType' => 'Parcel',
    'ServiceType' => 'WarehouseDoors',
    'SeatsAmount' => '1',
    'Description' => 'Замовлення з Мономаркету №' . $orderId,
    'Cost' => (string)($order['grand_total'] ?? 500),
    'Weight' => '20',
    'VolumeGeneral' => '0.1',
    'CitySender' => NP_SENDER_CITY_REF,
    'Sender' => NP_SENDER_REF,
    'SenderAddress' => NP_SENDER_ADDRESS_REF,
    'ContactSender' => NP_SENDER_CONTACT_REF,
    'SendersPhone' => '',
    'CityRecipient' => $settlementId,
    'Recipient' => $recipientRef,
    'RecipientAddressName' => $streetAddress,
    'RecipientHouse' => '',
    'RecipientFlat' => $flat,
    'ContactRecipient' => $recipientContactRef,
    'RecipientsPhone' => $phone,
    'RecipientCityName' => $cityName,
    'RecipientArea' => $shipping['shipping_address_region'] ?? '',
    'MarketplacePartnerToken' => NOVA_POSHTA_MARKETPLACE_PARTNER_TOKEN,
];

if ($isLargeItem) {
    $methodProperties['DeliveryLargeHouseholdAppliances'] = $floor;
} else {
    $methodProperties['NumberOfFloorsLifting'] = $floor;
}

$ttnResult = novaPoshtaRequest('InternetDocument', 'save', $methodProperties);
echo json_encode($ttnResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

if (empty($ttnResult['success']) || empty($ttnResult['data'][0]['IntDocNumber'])) {
    die("\n❌ Не вдалось створити ЕН.\n");
}

$ttn = $ttnResult['data'][0]['IntDocNumber'];
echo "\n✅ ТТН створено: $ttn\n";

echo "\n=== 4. Записую ТТН назад у KeyCRM ===\n";
[$updCode, $updResult] = keycrmRequestShip('PUT', "/order/{$orderId}", [
    'shipping' => ['tracking_code' => $ttn],
]);
echo "HTTP $updCode\n";
echo ($updCode >= 200 && $updCode < 300) ? "✅ Записано в KeyCRM.\n" : "⚠️ Не вдалось записати ТТН у KeyCRM автоматично -- впиши вручну: $ttn\n";
