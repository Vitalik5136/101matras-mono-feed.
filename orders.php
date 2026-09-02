<?php
// ==========================================================
// Мономаркет <-> KeyCRM order integration
// ==========================================================
// Implements the four methods Мономаркет's docs describe:
//   POST   /api/v1/market/orders            -- Create Order
//   GET    /api/v1/market/orders/{id}        -- Get Order
//   POST   /api/v1/market/orders/batch       -- Get Order Batch
//   PUT    /api/v1/market/orders/{id}/cancel -- Cancel Order
//
// Design choice: the {id} we return to Мономаркет on order creation IS
// KeyCRM's own order id. That means Get Order / Cancel Order need no
// separate database to remember the mapping -- we just look the id up
// directly in KeyCRM every time.

// ---------------- CONFIGURATION -- fill these in ----------------
// KEYCRM_SOURCE_ID: the numeric id of the "Мономаркет" source in your
// KeyCRM account (Settings -> Sources). Create it there first, then open
// it and copy the id from the URL, or call GET /source and find it by
// name in the response.
define('KEYCRM_SOURCE_ID', 8); // Мономаркет2

// KEYCRM_NOVAPOST_DELIVERY_SERVICE_ID: the numeric id KeyCRM uses
// internally for "Нова Пошта" as a delivery service (Settings ->
// Delivery services, or GET /order/delivery-service).
define('KEYCRM_NOVAPOST_DELIVERY_SERVICE_ID', 1); // confirmed from real orders: "Новою поштою на Склад"

// Status mapping: KeyCRM's numeric status_id (as they appear in your
// pipeline, Settings -> Воронки) -> Мономаркет's status enum
// (new/accepted/sent/arrived/completed/canceled). The values below are
// BEST-GUESS defaults from a sample of real orders (status_on_source
// text observed alongside each id) -- confirm the exact stage names for
// each id against your actual pipeline settings and adjust as needed.
const KEYCRM_TO_MONO_STATUS = [
    1 => 'accepted',   // observed status_on_source: "В обробці"
    2 => 'new',        // observed status_on_source: "Новий"
    9 => 'sent',       // observed alongside a tracking_code -> likely "Відправлено"
    15 => 'canceled',  // observed status_on_source: "Не доставлений"
    19 => 'canceled',  // observed with closed_from set -- likely a final/closed stage
];

// Optional shared-secret check: if set, Мономаркет must send this exact
// value in the "X-Api-Key" header on every request to our endpoints. Set
// it as a Render environment variable (MONOMARKET_SHARED_SECRET) and give
// Мономаркет the same value under "Static header" auth (see their docs).
// Leave the env var unset to disable this check entirely.
$expectedSecret = getenv('MONOMARKET_SHARED_SECRET');
if ($expectedSecret) {
    $gotSecret = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($expectedSecret, $gotSecret)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Unauthorized', 'code' => 'UNAUTHORIZED']);
        exit;
    }
}

// ---------------- KeyCRM API helper ----------------
function keycrmRequest($method, $path, $body = null) {
    $apiKey = getenv('KEYCRM_API_KEY');
    if (!$apiKey) {
        return [0, ['message' => 'KEYCRM_API_KEY is not configured on the server', 'code' => 'SERVER_ERROR']];
    }
    $ch = curl_init('https://openapi.keycrm.app/v1' . $path);
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    return [$httpCode, $decoded];
}

function sendJson($statusCode, $payload) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ---------------- Status mapping ----------------
function mapKeycrmStatusToMono($keycrmOrder) {
    $statusId = $keycrmOrder['status_id'] ?? null;
    return KEYCRM_TO_MONO_STATUS[$statusId] ?? 'accepted'; // unrecognized status -> safest default
}

// Builds the "Success order get response" shape Мономаркет expects, from
// a KeyCRM order object.
function buildMonoOrderResponse($keycrmOrder) {
    $status = mapKeycrmStatusToMono($keycrmOrder);

    $ttn = $keycrmOrder['shipping']['tracking_code'] ?? null;
    $shipmentType = null;
    $shipment = null;
    if ($ttn) {
        // Real field observed: shipping_preferred_method, e.g. "Новою поштою
        // на Склад" (warehouse) vs "Кур'єром по ..." (courier).
        $preferredMethod = (string)($keycrmOrder['shipping']['shipping_preferred_method'] ?? '');
        $isCourier = stripos($preferredMethod, 'кур') !== false;
        $shipmentType = $isCourier ? 'courier:nova-post' : 'nova-post';
        $shipment = ['ttn' => (string)$ttn];
    }

    // cancelStatus: KeyCRM doesn't have a native concept matching
    // Мономаркет's cancelId/quantity-based partial cancellation model, so
    // this reflects only whether the order's KeyCRM status itself looks
    // like a cancellation -- refine this once you see real KeyCRM
    // cancellation data.
    $cancelStatus = ($status === 'canceled') ? 'canceled' : null;

    return [
        'id' => (string)$keycrmOrder['id'],
        'status' => $status,
        'cancelStatus' => $cancelStatus,
        'shipmentType' => $shipmentType,
        'shipment' => $shipment,
    ];
}

// Builds a KeyCRM CreateNewOrderRequest-shaped body from a Мономаркет
// "Order create request" body.
// Products in KeyCRM are matched by their "Артикул" (SKU) field, which is
// Horoshop's vendorCode -- NOT the numeric offer id we use as "code" in
// the Мономаркет product feed. So before creating a KeyCRM order we look
// up each item's real vendorCode from the same Horoshop source feed our
// price/catalog script reads, keyed by that numeric id.
define('HOROSHOP_FEED_URL_FOR_ORDERS', 'https://101matras.ua/content/export/bf5ada79a4036e96ecc39bc3173ff7a2.xml');

function lookupVendorCodeByOfferId($offerId) {
    static $cache = null; // built once per request, reused across all items in one order
    if ($cache === null) {
        $cache = [];
        $tmp = tempnam(sys_get_temp_dir(), 'hf_');
        $ch = curl_init(HOROSHOP_FEED_URL_FOR_ORDERS);
        $fp = fopen($tmp, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        $reader = new XMLReader();
        $reader->open($tmp);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
                $node = $reader->expand();
                $dom = new DOMDocument();
                $imported = $dom->importNode($node, true);
                $dom->appendChild($imported);
                $offer = simplexml_import_dom($imported);
                $id = (string)$offer['id'];
                $vc = isset($offer->vendorCode) ? (string)$offer->vendorCode : '';
                if ($id !== '' && $vc !== '') $cache[$id] = $vc;
                $reader->next();
            }
        }
        $reader->close();
        @unlink($tmp);
    }
    return $cache[$offerId] ?? null;
}

function buildKeycrmCreateRequest($mono) {
    $first = $mono['client']['name']['first'] ?? '';
    $last = $mono['client']['name']['last'] ?? '';
    $phone = $mono['client']['phone'] ?? '';

    $products = [];
    foreach (($mono['items'] ?? []) as $item) {
        $horoshopId = $item['code'] ?? '';
        $vendorCode = lookupVendorCodeByOfferId($horoshopId);
        $products[] = [
            // Real KeyCRM article when we found it; falls back to the
            // Horoshop id so the order still gets created (as an
            // unmatched line item) rather than silently dropping the
            // product if the lookup fails for some reason.
            'sku' => $vendorCode ?? $horoshopId,
            'price' => $item['price'] ?? 0,
            'quantity' => $item['quantity'] ?? 1,
        ];
    }

    $shippingBlock = [];
    $deliveryType = $mono['deliveryType'] ?? '';
    $delivery = $mono['delivery'] ?? [];
    if ($deliveryType === 'nova-post') {
        $shippingBlock = [
            'delivery_service_id' => KEYCRM_NOVAPOST_DELIVERY_SERVICE_ID,
            'shipping_address_city' => $delivery['settlementName'] ?? '',
            'shipping_receive_point' => $delivery['warehouseNumber'] ?? '',
        ];
    } elseif ($deliveryType === 'courier:nova-post') {
        $addr = trim(($delivery['address'] ?? '') . ' ' . ($delivery['house'] ?? ''));
        $shippingBlock = [
            'delivery_service_id' => KEYCRM_NOVAPOST_DELIVERY_SERVICE_ID,
            'shipping_address_city' => $delivery['settlement'] ?? '',
            'shipping_address_region' => $delivery['area'] ?? '',
            'shipping_secondary_line' => $addr . (isset($delivery['flat']) ? (', кв. ' . $delivery['flat']) : ''),
        ];
        // NOTE: Nova Poshta requires "MarketplacePartnerToken" (confirmed
        // real value from Мономаркет: 1ba2a77906a9-a827-46f4-3555-e60089ac)
        // when creating the actual express waybill (ЕН) for this order --
        // that happens at shipment time via Nova Poshta's InternetDocument.save
        // API method, not at this KeyCRM order-creation step, so it isn't
        // set here. As of now this is still done manually in Nova
        // Poshta's own cabinet; TBD whether their manual UI even exposes
        // a field for this token, or whether ТТН creation needs to move
        // to an automated API call (with this token) to get the special
        // marketplace apartment-delivery terms to apply. For large items
        // (>30kg or bulky, warehouse-only), you must additionally set
        // "DeliveryLargeHouseholdAppliances" = the floor number, and use
        // NP's string-based creation method (not the standard one, since
        // it requires a filled "streetId" which marketplace orders won't
        // have). For standard items, set "NumberOfFloorsLifting" = floor.
        // Always set "RecipientFlat" = the delivery.flat value.
    }
    // in-store-pickup: intentionally left minimal; add your pickup point
    // mapping here if/when you support self-pickup orders.

    return [
        'source_id' => KEYCRM_SOURCE_ID,
        'buyer' => [
            'full_name' => trim($first . ' ' . $last),
            'phone' => $phone,
        ],
        'shipping' => $shippingBlock,
        'products' => $products,
        'manager_comment' => 'Мономаркет замовлення №' . ($mono['number'] ?? ''),
    ];
}

// ---------------- Routing ----------------
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Match: /api/v1/market/orders/{id}/cancel
if ($method === 'PUT' && preg_match('#/api/v1/market/orders/([^/]+)/cancel$#', $uri, $m)) {
    $orderId = $m[1];
    $cancelBody = readJsonBody();

    [$httpCode, $existing] = keycrmRequest('GET', "/order/{$orderId}");
    if ($httpCode === 404 || !$existing) {
        sendJson(404, ['message' => 'Order does not exist', 'code' => 'NOT_FOUND']);
    }

    // Best-effort: move the KeyCRM order to a status you've mapped to
    // "canceled" above. Adjust the status_id/status name below to match
    // an actual cancelled-stage in your pipeline.
    keycrmRequest('PUT', "/order/{$orderId}", [
        'manager_comment' => trim(($existing['manager_comment'] ?? '') . "\nСкасування від Мономаркету: cancelId=" . ($cancelBody['cancelId'] ?? '')),
    ]);

    [, $updated] = keycrmRequest('GET', "/order/{$orderId}");
    sendJson(200, buildMonoOrderResponse($updated ?: $existing));
}

// Match: /api/v1/market/orders/batch
if ($method === 'POST' && preg_match('#/api/v1/market/orders/batch$#', $uri)) {
    $body = readJsonBody();
    $ids = $body['orders'] ?? [];
    $orders = [];
    $errors = [];
    foreach ($ids as $id) {
        [$httpCode, $order] = keycrmRequest('GET', "/order/{$id}");
        if ($httpCode === 200 && $order) {
            $orders[] = buildMonoOrderResponse($order);
        } else {
            $errors[] = ['id' => (string)$id, 'message' => 'Order does not exist', 'code' => 'NOT_FOUND'];
        }
    }
    sendJson(200, ['orders' => $orders, 'errors' => $errors]);
}

// Match: /api/v1/market/orders/{id}
if ($method === 'GET' && preg_match('#/api/v1/market/orders/([^/]+)$#', $uri, $m)) {
    $orderId = $m[1];
    [$httpCode, $order] = keycrmRequest('GET', "/order/{$orderId}");
    if ($httpCode !== 200 || !$order) {
        sendJson(404, ['message' => 'Order does not exist', 'code' => 'NOT_FOUND']);
    }
    sendJson(200, buildMonoOrderResponse($order));
}

// Match: /api/v1/market/orders (create)
if ($method === 'POST' && preg_match('#/api/v1/market/orders/?$#', $uri)) {
    $mono = readJsonBody();
    if (empty($mono['number'])) {
        sendJson(400, [
            'message' => 'Some validation errors happened',
            'code' => 'VALIDATION_ERROR',
            'errors' => ['number' => 'number is required'],
        ]);
    }

    $keycrmBody = buildKeycrmCreateRequest($mono);
    [$httpCode, $result] = keycrmRequest('POST', '/order', $keycrmBody);

    if ($httpCode >= 200 && $httpCode < 300 && isset($result['id'])) {
        sendJson(201, ['id' => (string)$result['id']]);
    }

    sendJson(500, [
        'message' => 'Failed to create order in KeyCRM: ' . json_encode($result),
        'code' => 'SERVER_ERROR',
    ]);
}

// Nothing matched
sendJson(404, ['message' => 'Not found', 'code' => 'NOT_FOUND']);
