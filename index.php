<?php
// ==========================================
// CONFIGURATION
// ==========================================
define('HOROSHOP_FEED_URL', 'https://101matras.ua/content/export/48f97829a88a8ed0506e4cb76c65f605.xml');

ini_set('memory_limit', '512M');
set_time_limit(120);

header('Content-Type: application/xml; charset=utf-8');

function getFeedData($url, &$errorMsg = '') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($data)) {
        $errorMsg = "HTTP Code: {$httpCode}. cURL Error: {$curlErr}";
        return false;
    }
    return $data;
}

$fetchError = '';
$rawXml = getFeedData(HOROSHOP_FEED_URL, $fetchError);

if (!$rawXml) {
    http_response_code(500);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Error fetching source Horoshop XML feed. Details: " . htmlspecialchars($fetchError) . "</error>";
    exit;
}

libxml_use_internal_errors(true);
$sourceXml = simplexml_load_string($rawXml);

if (!$sourceXml) {
    http_response_code(500);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Failed to parse source XML feed</error>";
    exit;
}

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'catalog';

function cleanText($text) {
    $text = strip_tags($text, '<p><b><i><ul><li><br>');
    return trim($text);
}

// Generate Catalog Feed
if ($type === 'catalog') {
    $out = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><yml_catalog></yml_catalog>');
    $out->addAttribute('date', date('Y-m-d H:i'));
    
    $shop = $out->addChild('shop');
    $shop->addChild('name', '101matras');
    $shop->addChild('company', '101matras');
    $shop->addChild('url', 'https://101matras.ua/');

    if (isset($sourceXml->shop->categories)) {
        $categoriesOut = $shop->addChild('categories');
        foreach ($sourceXml->shop->categories->category as $cat) {
            $c = $categoriesOut->addChild('category', htmlspecialchars((string)$cat));
            $c->addAttribute('id', (string)$cat['id']);
            if (isset($cat['parentId'])) {
                $c->addAttribute('parentId', (string)$cat['parentId']);
            }
        }
    }

    $offersOut = $shop->addChild('offers');
    if (isset($sourceXml->shop->offers->offer)) {
        foreach ($sourceXml->shop->offers->offer as $offer) {
            $o = $offersOut->addChild('offer');
            $o->addAttribute('id', (string)$offer['id']);
            
            if (isset($offer->url)) $o->addChild('url', htmlspecialchars((string)$offer->url));
            if (isset($offer->categoryId)) $o->addChild('categoryId', (string)$offer->categoryId);
            if (isset($offer->name)) $o->addChild('name', htmlspecialchars(cleanText((string)$offer->name)));
            if (isset($offer->vendor)) $o->addChild('vendor', htmlspecialchars((string)$offer->vendor));
            if (isset($offer->vendorCode)) $o->addChild('vendorCode', htmlspecialchars((string)$offer->vendorCode));
            if (isset($offer->barcode)) $o->addChild('barcode', htmlspecialchars((string)$offer->barcode));
            if (isset($offer->description)) $o->addChild('description', htmlspecialchars(cleanText((string)$offer->description)));

            foreach ($offer->picture as $pic) {
                $o->addChild('picture', htmlspecialchars((string)$pic));
            }

            foreach ($offer->param as $param) {
                $p = $o->addChild('param', htmlspecialchars((string)$param));
                if (isset($param['name'])) {
                    $p->addAttribute('name', (string)$param['name']);
                }
            }
        }
    }

    echo $out->asXML();
    exit;
}

// Generate Price & Stock Feed
if ($type === 'prices') {
    $out = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><yml_catalog></yml_catalog>');
    $out->addAttribute('date', date('Y-m-d H:i'));
    
    $shop = $out->addChild('shop');
    $offersOut = $shop->addChild('offers');

    if (isset($sourceXml->shop->offers->offer)) {
        foreach ($sourceXml->shop->offers->offer as $offer) {
            $o = $offersOut->addChild('offer');
            $o->addAttribute('id', (string)$offer['id']);
            
            // Universal availability check (handles true, 1, yes)
            $availAttr = isset($offer['available']) ? strtolower((string)$offer['available']) : 'false';
            $isAvailable = ($availAttr === 'true' || $availAttr === '1' || $availAttr === 'yes') ? 'true' : 'false';
            $o->addAttribute('available', $isAvailable);

            if (isset($offer->price)) {
                $o->addChild('price', (string)$offer->price);
            }
            if (isset($offer->oldprice)) {
                $o->addChild('oldprice', (string)$offer->oldprice);
            }
            
            // Currency fallback to UAH if not defined in source
            $currency = isset($offer->currencyId) ? (string)$offer->currencyId : 'UAH';
            $o->addChild('currencyId', $currency);
        }
    }

    echo $out->asXML();
    exit;
}

http_response_code(400);
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Invalid type parameter. Use ?type=catalog or ?type=prices</error>";
