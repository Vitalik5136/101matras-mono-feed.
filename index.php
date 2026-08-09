<?php
// ==========================================
// CONFIGURATION
// ==========================================
define('HOROSHOP_FEED_URL', 'https://101matras.ua/content/export/48f97829a88a8ed0506e4cb76c65f605.xml');

ini_set('memory_limit', '512M');
set_time_limit(120);

// ==========================================
// FETCH SOURCE FEED
// ==========================================
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
    header('Content-Type: application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Error fetching source Horoshop XML feed. Details: " . htmlspecialchars($fetchError) . "</error>";
    exit;
}

libxml_use_internal_errors(true);
$sourceXml = simplexml_load_string($rawXml);
if (!$sourceXml) {
    http_response_code(500);
    header('Content-Type: application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Failed to parse source XML feed</error>";
    exit;
}

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'catalog';

// ==========================================
// SHARED HELPERS
// ==========================================

// GS1 restricted-circulation prefix ("20") -- reserved for internal/company
// use, will never collide with a real manufacturer barcode. Deterministic:
// same offer id always produces the same barcode.
function generateBarcode($offerId) {
    $digits = preg_replace('/\D/', '', (string)$offerId);
    if ($digits === '') $digits = '0';
    $body = '20' . str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $n = (int)$body[$i];
        $sum += ($i % 2 === 0) ? $n : $n * 3;
    }
    $check = (10 - ($sum % 10)) % 10;
    return $body . $check;
}

// Forbidden topics that must not appear inside <description>: warranty,
// delivery, payment, discounts/promos, "why buy from us" sales pitches.
$FORBIDDEN_KEYWORDS = [
    'гарант', 'доставк', 'оплат', 'розрахун', 'знижк', 'акці', 'розстроч',
    'чому необхідно купити', 'чому потрібно купити', 'вигідні умови',
    'консультац', 'шоурум', 'магазин',
];

function containsForbidden($text, $keywords) {
    $low = mb_strtolower($text);
    foreach ($keywords as $kw) {
        if (mb_strpos($low, $kw) !== false) return true;
    }
    return false;
}

// Cleans description HTML down to the allowed tag set (h5, br, ul, li, p,
// img) and strips out paragraphs/headings that talk about forbidden topics.
function cleanDescription($html, $forbiddenKeywords) {
    if (trim($html) === '') return '';

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return strip_tags($html, '<h5><br><ul><li><p><img>');

    // Remove headings (and following siblings up to next heading) whose
    // text matches a forbidden topic.
    $headings = [];
    foreach (['h2', 'h3', 'h4'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $h) $headings[] = $h;
    }
    foreach ($headings as $h) {
        if (!$h->parentNode) continue; // already removed
        $text = trim($h->textContent);
        if (containsForbidden($text, $forbiddenKeywords)) {
            $sibling = $h->nextSibling;
            while ($sibling) {
                $next = $sibling->nextSibling;
                if ($sibling->nodeType === XML_ELEMENT_NODE && in_array($sibling->tagName, ['h2', 'h3', 'h4'])) {
                    break;
                }
                $sibling->parentNode->removeChild($sibling);
                $sibling = $next;
            }
            $h->parentNode->removeChild($h);
        }
    }

    // Remove any remaining <p>/<div> whose own text mentions a forbidden topic.
    $blocks = [];
    foreach (['p', 'div'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $b) $blocks[] = $b;
    }
    foreach ($blocks as $b) {
        if (!$b->parentNode) continue;
        $text = trim($b->textContent);
        if ($text !== '' && containsForbidden($text, $forbiddenKeywords)) {
            $b->parentNode->removeChild($b);
        }
    }

    // Normalize h2/h3/h4 -> h5 (only h5 is allowed)
    foreach (['h2', 'h3', 'h4'] as $tag) {
        $nodes = [];
        foreach ($doc->getElementsByTagName($tag) as $n) $nodes[] = $n;
        foreach ($nodes as $n) {
            $h5 = $doc->createElement('h5');
            while ($n->firstChild) $h5->appendChild($n->firstChild);
            $n->parentNode->replaceChild($h5, $n);
        }
    }

    $html2 = '';
    foreach ($root->childNodes as $child) {
        $html2 .= $doc->saveHTML($child);
    }

    // Strip to allowed tags, drop all attributes except img's src/alt.
    $html2 = preg_replace_callback(
        '/<img[^>]*>/i',
        function ($m) {
            preg_match('/src="([^"]*)"/i', $m[0], $srcM);
            preg_match('/alt="([^"]*)"/i', $m[0], $altM);
            $src = $srcM[1] ?? '';
            $alt = $altM[1] ?? '';
            if ($src === '') return '';
            return '<img alt="' . htmlspecialchars($alt) . '" src="' . htmlspecialchars($src) . '">';
        },
        $html2
    );
    $html2 = strip_tags($html2, '<h5><br><ul><li><p><img>');
    // strip_tags keeps attributes on non-img tags too -- remove them.
    $html2 = preg_replace('/<(h5|br|ul|li|p)\s+[^>]*>/i', '<$1>', $html2);
    $html2 = preg_replace('/\n\s*\n+/', "\n", trim($html2));
    return $html2;
}

// Extracts "Label: value" spec lines (Тип, Жорсткість, Навантаження,
// Об'ємна висота, Країна виробник) from raw description text.
function extractSpecs($html) {
    $specs = [];
    $labels = [
        "Тип", "Жорсткість", "Навантаження",
        "Максимальне навантаження на 1 спальне місце",
        "Об'ємна висота", "Об’ємна висота",
        "Країна виробник", "Країна-виробник", "Країна - виробник",
    ];
    $text = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $html));
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        foreach ($labels as $label) {
            if (preg_match('/^' . preg_quote($label, '/') . '\s*[:\-]\s*(.+)$/u', $line, $m)) {
                $value = rtrim(trim($m[1]), ';');
                $normLabel = $label;
                if (mb_strpos($label, 'висота') !== false) $normLabel = "Об'ємна висота";
                elseif (mb_strpos($label, 'виробник') !== false) $normLabel = "Країна виробник";
                elseif (mb_strpos($label, 'навантаження') !== false) $normLabel = "Максимальне навантаження";
                if ($value !== '') $specs[$normLabel] = $value;
            }
        }
    }
    return $specs;
}

// Keyword-based leaf category classifier (title first, description as
// fallback) -- same rules as suggest_categories.py.
function suggestCategory($title, $descriptionText, $topCategoryId) {
    $rules = [
        '1061' => [
            ['/рулон|\broll\b/ui', 'Матраци в рулоні'],
            ['/кокос/ui', 'З Кокосом'],
            ['/мемор[іi]|memory/ui', 'З меморі'],
            ['/латекс/ui', 'З Латексом'],
            ['/дитяч/ui', 'Дитячі матраци'],
            ['/ортопед/ui', 'Ортопедичні матраци'],
            ['/бонел|bonnel/ui', 'Матраци Bonnel'],
            ['/ел[іi]т|прем[іi]ум|premium|люкс/ui', 'Елітні'],
            ['/топ[ое]р|topper/ui', 'Матраци на диван'],
        ],
        '1064' => [
            ['/подорож|travel/ui', 'Для подорожей'],
            ['/мемор[іi]|memory/ui', 'Меморі'],
            ["/пух|пір'ям|пір'я/ui", 'Пухові та пір\'яні'],
            ['/\bгел\b|gel/ui', 'З гелем'],
            ['/латекс/ui', 'Латексні'],
            ['/антиалерг/ui', 'Антиалергенні'],
            ['/дит/ui', 'Для дітей'],
            ['/ортопед/ui', 'Ортопедичні'],
        ],
        '1059' => [
            ['/\bпм\b|підйомн/ui', 'З Підйомним Механізмом'],
            ["/м'яке узголів'я|узголів'я/ui", 'З м\'яким узголів\'ям'],
            ['/метал/ui', 'Металеві'],
            ['/лофт|loft/ui', 'Лофт'],
            ['/каркас/ui', 'Каркаси'],
            ['/бук|дерев|natural|еко/ui', 'Дерев\'яні'],
        ],
        '1062' => [
            ['/водонепрон|непромок|waterproof|aquastop/ui', 'Водонепроникний'],
            ['/на гумц|гумка по кут|резинка по периметру/ui', 'На гумках по кутах'],
            ['/натяжн.*борт|з бортом/ui', 'Натяжний з бортом'],
            ['/чохол.*блискавц|блискавц.*чохол/ui', 'Чохол на блискавці'],
        ],
        '1063' => [
            ['/\bзима\b|winter/ui', 'Зимові'],
            ['/\bл[іi]то\b|summer/ui', 'Літні'],
            ['/дем[іi]сезон|весна|осінь|autumn/ui', 'Весна - Осінь'],
            ['/пух/ui', 'Пухові'],
            ['/вовн|шерст/ui', 'Вовняні'],
            ['/дит/ui', 'Дитячі'],
            ['/антиалерг/ui', 'Антиалергенні'],
        ],
    ];
    $descFallback = [
        '1061' => [
            ['/без\s*пружин|безпружин/ui', 'Безпружинні матраци'],
            ['/незалежних пружин|pocket spring|блок пружин|independent spring/ui', 'Ортопедичні матраци'],
            ['/кокос/ui', 'З Кокосом'],
            ['/мемор[іi]|memory/ui', 'З меморі'],
            ['/латекс/ui', 'З Латексом'],
        ],
    ];
    foreach ($rules[$topCategoryId] ?? [] as [$pattern, $leaf]) {
        if (preg_match($pattern, $title)) return $leaf;
    }
    foreach ($descFallback[$topCategoryId] ?? [] as [$pattern, $leaf]) {
        if (preg_match($pattern, $descriptionText)) return $leaf;
    }
    return '';
}

// Estimates package L/W/H (cm) from a "WxL" + optional "(NNсм)" pattern
// already stated in the title. Returns null if no size found. Weight is
// intentionally NOT estimated (too unreliable from a simple formula).
function estimateDimensions($title) {
    if (!preg_match('/(\d{2,3})\s*[xхХ×]\s*(\d{2,3})/u', $title, $m)) return null;
    $width = (float)$m[1];
    $length = (float)$m[2];
    if (preg_match('/\(\s*(\d{1,2}(?:[.,]\d)?)\s*см\s*\)/u', $title, $tm)) {
        $height = (float)str_replace(',', '.', $tm[1]);
    } else {
        $height = 20.0; // generic fallback, low confidence
    }
    return ['width' => round($width), 'length' => round($length), 'height' => round($height)];
}

function warrantyMonths($text) {
    if (preg_match('/(\d+)\s*мес/ui', $text, $m)) return (int)$m[1];
    return 0;
}

$categoryNames = [];
$categoryHasChildren = [];
if (isset($sourceXml->shop->categories)) {
    foreach ($sourceXml->shop->categories->category as $cat) {
        $categoryNames[(string)$cat['id']] = trim((string)$cat);
        if (isset($cat['parentId'])) {
            $categoryHasChildren[(string)$cat['parentId']] = true;
        }
    }
}

// ==========================================
// type=catalog -- товарний XML-фід (Market > offers > offer)
// ==========================================
if ($type === 'catalog') {
    header('Content-Type: application/xml; charset=utf-8');

    $out = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Market></Market>');
    $offersOut = $out->addChild('offers');

    if (isset($sourceXml->shop->offers->offer)) {
        foreach ($sourceXml->shop->offers->offer as $offer) {
            $offerId = (string)$offer['id'];
            $categoryIdSrc = isset($offer->categoryId) ? (string)$offer->categoryId : '';
            $vendorCode = isset($offer->vendorCode) ? (string)$offer->vendorCode : '';
            $brand = isset($offer->vendor) ? (string)$offer->vendor : '';
            $titleRaw = isset($offer->name) ? (string)$offer->name : '';
            $title = preg_replace('/\bМатрас\b/u', 'Матрац', $titleRaw);
            $descriptionHtml = isset($offer->description) ? (string)$offer->description : '';

            $specs = extractSpecs($descriptionHtml);
            $cleanedDesc = cleanDescription($descriptionHtml, $GLOBALS['FORBIDDEN_KEYWORDS']);

            $leaf = suggestCategory($title, strip_tags($descriptionHtml), $categoryIdSrc);
            $categoryText = $leaf !== '' ? $leaf : ($categoryNames[$categoryIdSrc] ?? '');

            $barcode = generateBarcode($offerId);
            $dims = estimateDimensions($title);

            $o = $offersOut->addChild('offer');
            $o->addChild('code', htmlspecialchars($offerId));
            $o->addChild('id', htmlspecialchars($offerId));
            if ($categoryIdSrc !== '') $o->addChild('category_id', htmlspecialchars($categoryIdSrc));
            $o->addChild('vendor_code', htmlspecialchars($vendorCode));

            $titleNode = $o->addChild('title');
            $titleDom = dom_import_simplexml($titleNode);
            $titleDom->appendChild($titleDom->ownerDocument->createCDATASection($title));

            $o->addChild('category', htmlspecialchars($categoryText));
            $o->addChild('brand', htmlspecialchars($brand));
            $o->addChild('barcode', htmlspecialchars($barcode));

            $availAttr = isset($offer['available']) ? strtolower((string)$offer['available']) : 'false';
            $isAvailable = in_array($availAttr, ['true', '1', 'yes']);
            $o->addChild('availability', $isAvailable ? 'Є в наявності' : 'Немає в наявності');

            $o->addChild('weight', '0'); // not fabricated -- see notes
            $o->addChild('height', (string)($dims['height'] ?? 0));
            $o->addChild('width', (string)($dims['width'] ?? 0));
            $o->addChild('length', (string)($dims['length'] ?? 0));

            if (isset($offer->picture)) {
                $imageLink = $o->addChild('image_link');
                foreach ($offer->picture as $pic) {
                    $imageLink->addChild('picture', htmlspecialchars((string)$pic));
                }
            }

            if (!empty($specs)) {
                $tags = $o->addChild('tags');
                foreach ($specs as $label => $value) {
                    $p = $tags->addChild('param', htmlspecialchars($value));
                    $p->addAttribute('name', $label);
                }
            }

            $descNode = $o->addChild('description');
            $descDom = dom_import_simplexml($descNode);
            $descDom->appendChild($descDom->ownerDocument->createCDATASection($cleanedDesc));
        }
    }

    echo $out->asXML();
    exit;
}

// ==========================================
// type=prices -- JSON прайс-лист
// ==========================================
if ($type === 'prices') {
    header('Content-Type: application/json; charset=utf-8');

    $data = [];
    if (isset($sourceXml->shop->offers->offer)) {
        foreach ($sourceXml->shop->offers->offer as $offer) {
            $offerId = (string)$offer['id'];
            $availAttr = isset($offer['available']) ? strtolower((string)$offer['available']) : 'false';
            $isAvailable = in_array($availAttr, ['true', '1', 'yes']);

            $price = isset($offer->price) ? (int)$offer->price : null;
            $oldPrice = isset($offer->oldprice) ? (int)$offer->oldprice : null;

            $warrantyMonthsVal = 0;
            foreach ($offer->param as $param) {
                if ((string)$param['name'] === 'Гарантия') {
                    $warrantyMonthsVal = warrantyMonths((string)$param);
                }
            }

            $data[] = [
                'code' => $offerId,
                'price' => $price,
                'old_price' => $oldPrice,
                'availability' => $isAvailable,
                'warranty_type' => $warrantyMonthsVal > 0 ? 'manufacturer' : 'no',
                'warranty_period' => $warrantyMonthsVal,
                'max_pay_in_parts' => null, // TODO: set your installment policy
                'days_to_dispatch' => $isAvailable ? 3 : 30, // in stock -> 3 days, out of stock -> 30 days
                'stock' => null,            // TODO: needs your inventory system
                'warehouses' => [],         // TODO: needs your inventory system
            ];
        }
    }

    $priceList = [
        'total' => count($data),
        'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'data' => $data,
    ];

    echo json_encode($priceList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
header('Content-Type: application/xml; charset=utf-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Invalid type parameter. Use ?type=catalog or ?type=prices</error>";
