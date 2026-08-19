<?php
// ==========================================
// CONFIGURATION
// ==========================================
define('HOROSHOP_FEED_URL', 'https://101matras.ua/content/export/bf5ada79a4036e96ecc39bc3173ff7a2.xml');
define('SUPPLIER_STOCK_CSV_URL', 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRGcRlGkFyXq5e7fp6crNoKM3iOyp7A96vCHGjTBvK_FJz0uHXkkf8kqUCFPkAbHBPHWDM_aHcqeClU/pub?gid=1912985661&single=true&output=tsv');

// ------------------------------------------------------------------
// BLOCKED MANUFACTURERS (UTech)
// ------------------------------------------------------------------
// Two modes:
//   'unavailable' -- products stay in the catalog feed but can never be
//                    ordered: availability=false, stock 0, in both feeds.
//                    The Мономаркет product cards survive, so switching
//                    the brand back on later is one edit here.
//   'remove'      -- products are dropped from both feeds entirely. The
//                    cards disappear from Мономаркет; bringing the brand
//                    back later means going through their moderation again.
define('BLOCKED_BRAND_MODE', 'unavailable');

// Matched case-insensitively against <vendor> AND the product title, as a
// substring. The brand is spelled "U-tek" on the site; the rest are
// spelling variants kept so a change on the Horoshop side can't quietly
// let the brand back into the feed.
$BLOCKED_BRANDS = ['u-tek', 'utek', 'u tek', 'u-tech', 'utech', 'ю-тек', 'ютек', 'ютех'];

// ------------------------------------------------------------------
// 6-DAY DISPATCH BRANDS
// ------------------------------------------------------------------
// Manufacturers whose products always get days_to_dispatch = 6 in the
// price feed, whatever the stock signals say. Matched case-insensitively
// as a substring of <vendor>. The only rule that still overrides this is
// "розмір під замовлення" (custom size), which stays at 30 days.
// ------------------------------------------------------------------
// TURKISH SUPPLIER BRANDS
// ------------------------------------------------------------------
// Everything we sell from the Turkish supplier. These products exist only
// in two places: their stock file and our own warehouse. So if the supplier
// file doesn't confirm stock (zero, or no matching row at all) AND Horoshop
// says we don't have it either, the product genuinely does not exist and
// must not be orderable -- no 12-day "we'll get it somehow" fallback.
// Matched case-insensitively as a substring of <vendor>.
$TURKISH_SUPPLIER_BRANDS = ['fdm', 'silence'];

$SIX_DAY_BRANDS = [
    'eurosleep', 'euroslip', 'euro slip', 'єврослип', 'еврослип',
    'chef', 'шеф',
];

// ------------------------------------------------------------------
// PRODUCT LINE  ->  REAL MANUFACTURER
// ------------------------------------------------------------------
// Horoshop puts the marketing LINE name in <vendor> ("Sleep&Fly"), but
// Мономаркет expects the MANUFACTURER there ("EMM"), with the line name
// staying in the product title -- e.g. brand "EMM", title
// "Матрац EMM Sleep&Fly 180x200".
//
// Key   = the line name as it appears in Horoshop's <vendor> (substring,
//         case-insensitive)
// Value = the manufacturer to send to Мономаркет instead
//
// Only lines listed here are rewritten; every other vendor passes through
// untouched. Add a line, and it starts being reported under its real
// manufacturer on the next feed refresh.
// Format: 'text to look for in <vendor>' => ['Manufacturer', 'Line name as
// it should be written in the title'].
// NOTE: the manufacturer is written in CYRILLIC -- "ЕММ" (Е, М, М), not the
// Latin "EMM". They look identical on screen but are different characters,
// and Мономаркет would treat them as two separate brands. Do not "fix" the
// spelling by retyping it on a Latin keyboard.
$BRAND_LINE_MAP = [
    'sleep&fly'   => ['ЕММ', 'Sleep&Fly'],
    'sleep & fly' => ['ЕММ', 'Sleep&Fly'],
    'sleepandfly' => ['ЕММ', 'Sleep&Fly'],
    'take&go'     => ['ЕММ', 'Take&Go'],
    'take & go'   => ['ЕММ', 'Take&Go'],
    // Products already labelled just "EMM" (Latin) or "ЕММ" -- normalised to
    // the Cyrillic spelling so Мономаркет doesn't end up with two brands
    // that look identical. Empty line name = leave the title alone.
    // Keep these LAST: the entries above are checked first, so "EMM Take&Go"
    // is still recognised as the Take&Go line rather than matching here.
    'emm'         => ['ЕММ', ''],
    'емм'         => ['ЕММ', ''],
];

// Rewrite titles into the shape Мономаркет asked for:
//     <product type> <MANUFACTURER> <Line> <the rest>
// e.g. "Матрац ЕММ Sleep&Fly 180x200". The manufacturer and the line name
// are inserted right after the leading product-type word; whichever of the
// two is already present in the title is not duplicated.
define('BRAND_LINE_IN_TITLE', true);

// Leading words after which the brand is inserted. If a title starts with
// something else, the brand goes to the very front instead.
$TITLE_TYPE_WORDS = [
    'матрац', 'матрас', 'подушка', 'ковдра', 'одеяло', 'наматрацник',
    'наматрасник', 'топер', 'топпер', 'простирадло', 'простыня', 'ліжко',
    'кровать', 'чохол', 'чехол', 'захисний',
];

ini_set('memory_limit', '512M');
set_time_limit(120);

// ==========================================
// FETCH SOURCE FEED (streamed to disk, not memory)
// ==========================================
function downloadFeedToFile($url, $tmpPath, &$errorMsg = '') {
    $fp = fopen($tmpPath, 'w');
    if (!$fp) {
        $errorMsg = "could not open temp file for writing";
        return false;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fp); // stream response body straight to disk
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $httpCode !== 200) {
        $errorMsg = "HTTP Code: {$httpCode}. cURL Error: {$curlErr}";
        @unlink($tmpPath);
        return false;
    }
    return true;
}

$tmpFeedFile = tempnam(sys_get_temp_dir(), 'feed_');
$fetchError = '';
if (!downloadFeedToFile(HOROSHOP_FEED_URL, $tmpFeedFile, $fetchError)) {
    http_response_code(500);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Error fetching source Horoshop XML feed. Details: " . htmlspecialchars($fetchError) . "</error>";
    exit;
}
register_shutdown_function(function () use ($tmpFeedFile) { @unlink($tmpFeedFile); });

// Reads <offer> elements one at a time from the downloaded file using
// XMLReader (a forward-only pull parser) instead of loading the whole feed
// into one big SimpleXML tree. This is what actually fixes the memory
// limit crashes on a large catalog: peak memory now stays roughly the size
// of ONE offer at a time, not the whole feed. Each yielded $offer behaves
// exactly like the old SimpleXMLElement (same ->tag and ->tag['attr']
// access), so the rest of the per-offer logic below is unchanged.
function iterateOffers($tmpPath) {
    $reader = new XMLReader();
    $reader->open($tmpPath);
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
            $node = $reader->expand();
            $dom = new DOMDocument();
            $imported = $dom->importNode($node, true);
            $dom->appendChild($imported);
            yield simplexml_import_dom($imported);
            $reader->next(); // skip past this offer's children, move to the next sibling
        }
    }
    $reader->close();
}

// Small, cheap first pass just to build the category id -> name map
// (categories are a short section near the top of the file, so this pass
// stops as soon as it reaches <offers>).
$categoryNames = [];
$catReader = new XMLReader();
$catReader->open($tmpFeedFile);
$sourceFeedDate = ''; // <yml_catalog date="..."> -- when Horoshop generated the export
while ($catReader->read()) {
    if ($catReader->nodeType === XMLReader::ELEMENT && $catReader->name === 'yml_catalog') {
        $sourceFeedDate = (string)$catReader->getAttribute('date');
    } elseif ($catReader->nodeType === XMLReader::ELEMENT && $catReader->name === 'category') {
        $id = $catReader->getAttribute('id');
        $catNode = $catReader->expand();
        if ($id !== null) $categoryNames[$id] = trim($catNode->textContent);
    } elseif ($catReader->nodeType === XMLReader::ELEMENT && $catReader->name === 'offers') {
        break; // categories are always listed before offers in this feed
    }
}
$catReader->close();

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'catalog';

// ==========================================
// SHARED HELPERS
// ==========================================

// GS1 restricted-circulation prefix ("20") -- reserved for internal/company
// use, will never collide with a real manufacturer barcode. Deterministic:
// same offer id always produces the same barcode.
//
// !!! DO NOT CHANGE THIS FUNCTION'S LOGIC !!!
// Changing the formula here would reassign a DIFFERENT barcode to every
// existing product the next time this runs -- breaking the "permanently
// pinned to the product" guarantee. If a real barcode is ever needed
// instead, add it to the source feed (see the barcode override check
// below) rather than editing this function.
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

// A paragraph mentioning a currency amount ("+2170 грн", "-3640 грн") is
// always configuration/option pricing that leaked into the description --
// this never belongs there (price lives in the separate price-list feed,
// not as free text), so it's removed regardless of the heading above it.
function containsPriceAmount($text) {
    return (bool)preg_match('/\d+\s*(грн|₴|uah)\b/ui', $text);
}

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

    // Remove any remaining <p>/<div> whose own text mentions a forbidden
    // topic. IMPORTANT: never match against $root itself -- $root's
    // aggregated textContent includes EVERY descendant's text (including
    // heading titles), so checking it against the same keyword list can
    // catastrophically wipe the entire description if a keyword merely
    // appears anywhere at all in the document (e.g. inside a heading like
    // "Додаткові опції:").
    $blocks = [];
    foreach (['p', 'div'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $b) {
            if ($b !== $root) $blocks[] = $b;
        }
    }
    foreach ($blocks as $b) {
        if (!$b->parentNode) continue;
        $text = trim($b->textContent);
        if ($text !== '' && (containsForbidden($text, $forbiddenKeywords) || containsPriceAmount($text))) {
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

    // Drop any heading that no longer has content under it (its
    // paragraphs got removed above, e.g. "Додаткові опції:" once all the
    // priced option lines beneath it are gone).
    $headingsNow = [];
    foreach ($doc->getElementsByTagName('h5') as $h) $headingsNow[] = $h;
    foreach ($headingsNow as $h) {
        if (!$h->parentNode) continue;
        $sibling = $h->nextSibling;
        while ($sibling && $sibling->nodeType === XML_TEXT_NODE && trim($sibling->textContent) === '') {
            $sibling = $sibling->nextSibling;
        }
        $hasContent = $sibling && !($sibling->nodeType === XML_ELEMENT_NODE && $sibling->tagName === 'h5');
        if (!$hasContent) {
            $h->parentNode->removeChild($h);
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

// Rewrites a line name in <vendor> to the real manufacturer, and reports
// which line was matched so the caller can keep it visible in the title.
// Returns [brandToSend, matchedLineName]; the line is '' when nothing matched.
function resolveBrandLine($vendor, array $map) {
    foreach ($map as $needle => $pair) {
        if (mb_stripos($vendor, $needle) !== false) {
            return [$pair[0], $pair[1]]; // [manufacturer, properly spelled line name]
        }
    }
    return [$vendor, ''];
}

// Puts the manufacturer (and, when missing, the line name) into the title
// right after the leading product-type word, e.g.
//   "Матрац Sleep&Fly Standart 180x200" -> "Матрац ЕММ Sleep&Fly Standart 180x200"
//   "Матрац ортопедичний 160x200"        -> "Матрац ЕММ Sleep&Fly ортопедичний 160x200"
// Anything already present is left alone, so running this twice is safe.
function placeBrandInTitle($title, $manufacturer, $line, array $typeWords) {
    $insert = [];

    // "EMM" in Latin counts as already present -- don't add a second one.
    $hasBrand = mb_stripos($title, $manufacturer) !== false
        || mb_stripos($title, 'emm') !== false;
    if (!$hasBrand) $insert[] = $manufacturer;

    if ($line !== '' && mb_stripos($title, $line) === false) $insert[] = $line;
    if (empty($insert)) return $title;

    $addition = implode(' ', $insert);
    $parts = preg_split('/\s+/u', trim($title), 2);
    $first = $parts[0] ?? '';
    $rest = $parts[1] ?? '';

    $firstIsType = false;
    foreach ($typeWords as $w) {
        if (mb_strtolower($first) === mb_strtolower($w)) { $firstIsType = true; break; }
    }
    if ($firstIsType) {
        return trim($first . ' ' . $addition . ($rest !== '' ? ' ' . $rest : ''));
    }
    return trim($addition . ' ' . $title);
}

// Мономаркет treats a NEW updatedAt as "the seller refreshed their stock".
// So emitting the current time on every request -- which is what a plain
// gmdate() here does -- tells them stock was replenished every 30 minutes,
// even when nothing changed, and their system then ships items we no longer
// have. The timestamp must therefore move only when the payload really
// changes.
//
// The service is stateless, so the previous payload's fingerprint is kept in
// a small temp file. If the payload is byte-for-byte what we sent last time,
// the stored timestamp is returned unchanged.
//
// If that file is lost (Render restarts or redeploys the instance), we fall
// back to the timestamp Horoshop itself put on the export rather than to
// "now" -- that value is stable across our restarts, so a restart alone
// cannot fake a stock refresh.
function priceFeedUpdatedAt(array $data, $sourceFeedDate) {
    $fingerprint = md5(json_encode($data, JSON_UNESCAPED_UNICODE));
    $cacheFile = sys_get_temp_dir() . '/mono_price_state.json';

    $state = json_decode((string)@file_get_contents($cacheFile), true);
    $haveState = is_array($state) && !empty($state['updatedAt']) && !empty($state['fingerprint']);

    if ($haveState && $state['fingerprint'] === $fingerprint) {
        return $state['updatedAt']; // nothing changed -> keep the old timestamp
    }

    $updatedAt = '';
    if ($haveState) {
        // We have a previous payload to compare against and it differs, so a
        // real change happened just now -- stamp it with the current time.
        // (Falling back to the export's own date here would be wrong: a
        // change can come from the supplier's stock sheet, which does not
        // touch Horoshop's export date at all.)
        $updatedAt = gmdate('Y-m-d\TH:i:s\Z');
    } elseif ($sourceFeedDate !== '') {
        // Cold start: nothing to compare against, so we must not claim a
        // refresh happened now. Horoshop writes local Kyiv time, e.g.
        // "2026-08-17 17:35" -- a value that survives our restarts.
        $dt = date_create_from_format('Y-m-d H:i', trim($sourceFeedDate), new DateTimeZone('Europe/Kyiv'))
            ?: date_create_from_format('Y-m-d H:i:s', trim($sourceFeedDate), new DateTimeZone('Europe/Kyiv'));
        if ($dt) {
            $dt->setTimezone(new DateTimeZone('UTC'));
            $updatedAt = $dt->format('Y-m-d\TH:i:s\Z');
        }
    }
    if ($updatedAt === '') $updatedAt = gmdate('Y-m-d\TH:i:s\Z');

    @file_put_contents($cacheFile, json_encode([
        'fingerprint' => $fingerprint,
        'updatedAt' => $updatedAt,
    ]));
    return $updatedAt;
}

// True when this offer belongs to a manufacturer we must not sell.
// Checks <vendor> first, then the title -- some feeds carry the brand only
// in the product name.
function isBlockedBrand($vendor, $title, array $brands) {
    $hay = mb_strtolower($vendor . ' ' . $title);
    foreach ($brands as $b) {
        if (mb_strpos($hay, mb_strtolower($b)) !== false) return true;
    }
    return false;
}

function warrantyMonths($text) {
    if (preg_match('/(\d+)\s*мес/ui', $text, $m)) return (int)$m[1];
    return 0;
}

// ==========================================
// Supplier stock matching (mattresses only)
// ==========================================
// The supplier's stock spreadsheet has no shared article/SKU with our
// catalog -- only free-text model names ("FORTUNA FDM MATTRESS 90*200cm").
// We match by (model keyword, size) instead. This is inherently fuzzier
// than matching by code, but it's the best available signal given what
// the supplier provides.

function extractSizeKey($text) {
    if (preg_match('/(\d{2,3})\s*[*xхX×]\s*(\d{2,3})/u', $text ?? '', $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        return $a < $b ? "$a-$b" : "$b-$a"; // order-independent (90x200 == 200x90)
    }
    return null;
}

// Words that appear in nearly every row/title and carry no product-model
// identity, so they must never be used as the matching key.
const SUPPLIER_MATCH_STOPWORDS = ['MATTRESS', 'TM', 'FDM', 'SILENCE', 'МАТРАЦ', 'МАТРАС'];

function extractSignificantWords($text) {
    preg_match_all('/[A-Za-zА-Яа-яІіЇїЄєҐґ]{3,}/u', $text ?? '', $m);
    $out = [];
    foreach ($m[0] as $w) {
        $up = mb_strtoupper($w);
        if (!in_array($up, SUPPLIER_MATCH_STOPWORDS)) $out[] = $up;
    }
    return $out;
}

// Downloads and parses the supplier's stock CSV (a Google Sheets "publish
// to web as CSV" link -- see the setup instructions). Group/subtotal rows
// (e.g. "FDM ТМ", "ИТОГО:") have no WxH size in them and are skipped
// automatically. Returns [(model, sizeKey) => freeStockQty, ...], or an
// empty array if the URL isn't configured or the fetch fails (callers
// treat that the same as "no match found" for every offer).
function loadSupplierStock($url) {
    $map = [];
    if (!$url) return $map;
    $csv = @file_get_contents($url);
    if ($csv === false) return $map;
    $lines = preg_split('/\r\n|\r|\n/', $csv);
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line, "\t"); // published sheet is tab-separated (TSV)
        $name = $cols[0] ?? '';
        $sizeKey = extractSizeKey($name);
        if ($sizeKey === null) continue; // group/subtotal/total row -- skip
        $words = extractSignificantWords($name);
        if (empty($words)) continue;
        $model = $words[0]; // supplier's own naming reliably puts the model word first
        $freeStock = isset($cols[3]) && is_numeric(trim($cols[3])) ? (int)$cols[3] : 0;
        $map["$model|$sizeKey"] = max(0, $freeStock);
    }
    return $map;
}

// Looks up a catalog offer's title in the supplier stock map. Tries every
// significant word in the title (not just the first) against the map,
// since our catalog's word order differs from the supplier's ("Матрац FDM
// Fortuna 90х200" vs "FORTUNA FDM MATTRESS 90*200cm"). Returns the real
// free-stock quantity if found, or null if this product has no
// corresponding row in the supplier's file.
function lookupSupplierStock($title, $supplierStock) {
    $sizeKey = extractSizeKey($title);
    if ($sizeKey === null) return null;
    foreach (extractSignificantWords($title) as $w) {
        $key = "$w|$sizeKey";
        if (isset($supplierStock[$key])) return $supplierStock[$key];
    }
    return null;
}

$categoryHasChildren = [];

// ==========================================
// type=catalog -- товарний XML-фід (Market > offers > offer)
// ==========================================
if ($type === 'catalog') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Stream the output with XMLWriter instead of building one giant
    // SimpleXMLElement tree in memory -- for a large catalog, holding the
    // full source parse AND a full duplicate output tree at once is what
    // was exceeding the instance's memory limit. XMLWriter writes each
    // offer straight to output and frees it, keeping peak memory flat
    // regardless of catalog size.
    $writer = new XMLWriter();
    $writer->openURI('php://output');
    $writer->setIndent(true);
    $writer->startDocument('1.0', 'UTF-8');
    $writer->startElement('Market');
    $writer->startElement('offers');

    foreach (iterateOffers($tmpFeedFile) as $offer) {
            $offerId = (string)$offer['id'];
            $categoryIdSrc = isset($offer->categoryId) ? (string)$offer->categoryId : '';
            if ($categoryIdSrc === '1059') continue; // beds removed from the feed entirely, per agreement with Мономаркет
            $vendorCode = isset($offer->vendorCode) ? (string)$offer->vendorCode : '';
            $brand = isset($offer->vendor) ? (string)$offer->vendor : '';
            $titleRaw = isset($offer->name) ? (string)$offer->name : '';
            $title = preg_replace('/\bМатрас\b/u', 'Матрац', $titleRaw);

            // Send the manufacturer as the brand, not the marketing line.
            $vendorRaw = $brand;
            [$brand, $matchedLine] = resolveBrandLine($vendorRaw, $GLOBALS['BRAND_LINE_MAP']);
            if (BRAND_LINE_IN_TITLE && $brand !== $vendorRaw) {
                $title = placeBrandInTitle($title, $brand, $matchedLine, $GLOBALS['TITLE_TYPE_WORDS']);
            }
            $descriptionHtml = isset($offer->description) ? (string)$offer->description : '';

            $specs = extractSpecs($descriptionHtml);
            $cleanedDesc = cleanDescription($descriptionHtml, $GLOBALS['FORBIDDEN_KEYWORDS']);

            $leaf = suggestCategory($title, strip_tags($descriptionHtml), $categoryIdSrc);
            $categoryText = $leaf !== '' ? $leaf : ($categoryNames[$categoryIdSrc] ?? '');

            // Use the real barcode from the source feed if Horoshop ever
            // starts providing one; only self-generate when it's missing.
            $sourceBarcode = isset($offer->barcode) ? trim((string)$offer->barcode) : '';
            $barcode = $sourceBarcode !== '' ? $sourceBarcode : generateBarcode($offerId);
            $dims = estimateDimensions($title);
            $realMattressSize = null; // for the "Розмір" spec (actual WxL, not rolled package)
            // Weight/dimensions are only reliable for mattresses (category
            // 1061) -- the rolled-width override and volumetric formula
            // below assume a mattress. Everything else (pillows, blankets,
            // beds...) gets 0, not a wrong guess.
            if ($categoryIdSrc !== '1061') {
                $dims = null;
            } elseif ($dims) {
                $realMattressSize = $dims['width'] . 'x' . $dims['length']; // real size, before override
                // All mattresses ship rolled/compressed -- the real package
                // width is a fixed roll diameter, not the flat mattress
                // width from the title. Length and height stay as estimated.
                $dims['width'] = 30;
            }

            $availAttr = isset($offer['available']) ? strtolower((string)$offer['available']) : 'false';
            $isAvailable = in_array($availAttr, ['true', '1', 'yes']);

            // Blocked manufacturer: either skip the offer entirely, or keep
            // the card but force it to "out of stock" so it can't be ordered.
            if (isBlockedBrand($brand, $title, $GLOBALS['BLOCKED_BRANDS'])) {
                if (BLOCKED_BRAND_MODE === 'remove') continue;
                $isAvailable = false;
            }

            if ($realMattressSize !== null) {
                $specs['Розмір'] = $realMattressSize;
            }

            $writer->startElement('offer');
            $writer->writeElement('code', $offerId);
            $writer->writeElement('id', $offerId);
            if ($categoryIdSrc !== '') $writer->writeElement('category_id', $categoryIdSrc);
            $writer->writeElement('vendor_code', $vendorCode);

            $writer->startElement('title');
            $writer->writeCdata($title);
            $writer->endElement();

            $writer->writeElement('category', $categoryText);
            $writer->writeElement('brand', $brand);
            $writer->writeElement('barcode', $barcode);
            $writer->writeElement('availability', $isAvailable ? 'Є в наявності' : 'Немає в наявності');

            $writer->writeElement('weight', (string)($dims ? round(($dims['length'] * $dims['width'] * $dims['height']) / 4000, 1) : 0));
            $writer->writeElement('height', (string)($dims['height'] ?? 0));
            $writer->writeElement('width', (string)($dims['width'] ?? 0));
            $writer->writeElement('length', (string)($dims['length'] ?? 0));

            if (isset($offer->picture)) {
                $writer->startElement('image_link');
                foreach ($offer->picture as $pic) {
                    $writer->writeElement('picture', (string)$pic);
                }
                $writer->endElement(); // image_link
            }

            if (!empty($specs)) {
                $writer->startElement('tags');
                foreach ($specs as $label => $value) {
                    $writer->startElement('param');
                    $writer->writeAttribute('name', $label);
                    $writer->text($value);
                    $writer->endElement(); // param
                }
                $writer->endElement(); // tags
            }

            $writer->startElement('description');
            $writer->writeCdata($cleanedDesc);
            $writer->endElement(); // description

            $writer->endElement(); // offer

            // Free per-offer variables and flush this offer to output now
            // rather than accumulating everything in memory.
            unset($specs, $cleanedDesc, $dims, $descriptionHtml, $titleRaw, $title);
            $writer->flush();
        }

    $writer->endElement(); // offers
    $writer->endElement(); // Market
    $writer->endDocument();
    $writer->flush();
    exit;
}

// ==========================================
// type=prices -- JSON прайс-лист
// ==========================================
if ($type === 'prices') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $supplierStock = loadSupplierStock(SUPPLIER_STOCK_CSV_URL);

    $data = [];
    foreach (iterateOffers($tmpFeedFile) as $offer) {
            $offerId = (string)$offer['id'];
            $categoryIdSrc = isset($offer->categoryId) ? (string)$offer->categoryId : '';
            if ($categoryIdSrc === '1059') continue; // beds removed from the feed entirely, per agreement with Мономаркет
            $availAttr = isset($offer['available']) ? strtolower((string)$offer['available']) : 'false';
            $horoshopAvailable = in_array($availAttr, ['true', '1', 'yes']);
            // If Horoshop says "not in stock", we do NOT hide the product --
            // it is still shown as available, just with a longer dispatch
            // time (12 days instead of 3).
            //
            // NOTE: this line is load-bearing. Tying availability to
            // $horoshopAvailable instead makes the large majority of the
            // catalogue disappear from Мономаркет at once, because most of
            // the feed carries available="false". Do not change it without
            // first counting how many offers that actually affects.
            $isAvailable = true;

            // "Розмір під замовлення" (custom/made-to-order size) variants
            // exist for every mattress but aren't real purchasable stock --
            // force these to unavailable regardless of category or what
            // the source feed (or the supplier stock match below) says.
            $titleForCustomSizeCheck = isset($offer->name) ? (string)$offer->name : '';
            $isCustomSizeOrder = mb_stripos($titleForCustomSizeCheck, 'під замов') !== false // catches "під замовлення" even truncated to "під замов" (Horoshop cuts long titles)
                || mb_stripos($titleForCustomSizeCheck, 'розмір під замовлення') !== false
                || mb_stripos($titleForCustomSizeCheck, 'под заказ') !== false
                || mb_stripos($titleForCustomSizeCheck, 'размер под') !== false
                || mb_stripos($titleForCustomSizeCheck, 'нестандарт') !== false
                || mb_stripos($titleForCustomSizeCheck, 'ціна за м2') !== false
                || mb_stripos($titleForCustomSizeCheck, 'цена за м2') !== false;
            if ($isCustomSizeOrder) {
                $isAvailable = false;
            }

            // Mattresses (category 1061): override with the supplier's
            // real stock quantity when we can match this offer's title to
            // a row in their stock sheet. If no match is found, the
            // product shows as unavailable rather than guessing.
            $realStock = null;
            $mattressMatched = false;
            $supplierOutOfStock = false;
            if ($categoryIdSrc === '1061' && !$isCustomSizeOrder) {
                $titleForMatch = isset($offer->name) ? (string)$offer->name : '';
                $realStock = lookupSupplierStock($titleForMatch, $supplierStock);
                $mattressMatched = $realStock !== null;
                $isAvailable = true; // matched-but-empty is handled by $supplierOutOfStock below; unmatched mattresses stay available
                // Zero (or negative) free stock in the supplier's file is not
                // the final word -- the mattress may still be sitting in our
                // own warehouse. In that case Horoshop's flag decides:
                //   Horoshop in stock -> orderable, 3 days
                //   Horoshop empty    -> not orderable
                // Enforced further down, AFTER the brand exceptions.
                $supplierOutOfStock = $mattressMatched && $realStock <= 0;
            }

            $brandForCheck = isset($offer->vendor) ? (string)$offer->vendor : '';

            // Brand exception: Billerbeck strictly follows Horoshop's own
            // availability flag -- in stock -> available (3 days),
            // out of stock -> fully unavailable (not shown as available
            // at all). This still yields to the custom-size rule above.
            $isBillerbeck = mb_stripos($brandForCheck, 'billerbeck') !== false
                || mb_stripos($brandForCheck, 'біллербек') !== false
                || mb_stripos($brandForCheck, 'биллербек') !== false;
            if ($isBillerbeck && !$isCustomSizeOrder) {
                $isAvailable = $horoshopAvailable;
            }

            $price = isset($offer->price) ? (int)$offer->price : null;
            $oldPrice = isset($offer->oldprice) ? (int)$offer->oldprice : null;

            $warrantyMonthsVal = 0;
            foreach ($offer->param as $param) {
                if ((string)$param['name'] === 'Гарантия') {
                    $warrantyMonthsVal = warrantyMonths((string)$param);
                }
            }

            // Use the installment count from the source feed if Horoshop
            // ever starts providing one (checked under a few plausible
            // tag names); default to 10 payments when it's missing.
            $maxPayInParts = 6;
            foreach (['max_pay_in_parts', 'installment', 'parts', 'rassrochka'] as $tagName) {
                if (isset($offer->{$tagName}) && trim((string)$offer->{$tagName}) !== '') {
                    $maxPayInParts = (int)$offer->{$tagName};
                    break;
                }
            }

            // These manufacturers get 7 installments instead of 6.
            $sevenInstallmentBrands = ['artisan', 'fdm', 'silence'];
            foreach ($sevenInstallmentBrands as $b) {
                if (mb_stripos($brandForCheck, $b) !== false) {
                    $maxPayInParts = 7;
                    break;
                }
            }

            // Dispatch time:
            // - custom/made-to-order size -> always 30
            // - matched mattress -> 3 if the supplier confirms stock, else 12
            // - everything else -> 3 if Horoshop's own flag says in stock, else 12
            if ($isCustomSizeOrder) {
                $daysToDispatch = 30;
            } elseif ($mattressMatched) {
                // Matched mattresses always dispatch in 3 days: either the
                // supplier confirms stock, or it's on our own shelf (an
                // empty supplier row plus an empty Horoshop flag makes the
                // product unavailable above, so the number is moot there).
                $daysToDispatch = 3;
            } else {
                $daysToDispatch = $horoshopAvailable ? 3 : 12;
            }

            // Brand exception: these manufacturers always ship in 6 days,
            // regardless of stock/availability signals -- yields only to the
            // custom-size rule above. Matched against <vendor> only (not the
            // title), so a model name can never trigger it by accident.
            // To add a brand, put its spelling variants in $SIX_DAY_BRANDS
            // at the top of this file -- nothing else needs changing.
            foreach ($GLOBALS['SIX_DAY_BRANDS'] as $b) {
                if (mb_stripos($brandForCheck, $b) !== false && !$isCustomSizeOrder) {
                    $daysToDispatch = 6;
                    break;
                }
            }

            // Turkish-supplier goods with no confirmed stock anywhere ->
            // Horoshop's own flag decides, and when that is empty too the
            // product becomes unavailable. Covers both cases: a matched row
            // showing zero, and no row in the supplier file at all.
            // Placed after the brand exceptions on purpose.
            $supplierConfirmedStock = ($realStock !== null && $realStock > 0);
            $isTurkishSupplierBrand = false;
            foreach ($GLOBALS['TURKISH_SUPPLIER_BRANDS'] as $b) {
                if (mb_stripos($brandForCheck, $b) !== false) { $isTurkishSupplierBrand = true; break; }
            }
            if (($supplierOutOfStock || $isTurkishSupplierBrand) && !$supplierConfirmedStock) {
                $isAvailable = $horoshopAvailable;
            }

            // Blocked manufacturer. Deliberately placed after every rule
            // above (mattress stock match, Billerbeck, Eurosleep), so
            // nothing can flip these back to orderable.
            if (isBlockedBrand($brandForCheck, $titleForCustomSizeCheck, $GLOBALS['BLOCKED_BRANDS'])) {
                if (BLOCKED_BRAND_MODE === 'remove') continue;
                $isAvailable = false;
                $realStock = 0;
            }

            $data[] = [
                'code' => $offerId,
                'price' => $price,
                'old_price' => $oldPrice,
                'availability' => $isAvailable,
                'warranty_type' => $warrantyMonthsVal > 0 ? 'manufacturer' : 'no',
                'warranty_period' => $warrantyMonthsVal,
                'max_pay_in_parts' => $maxPayInParts,
                'days_to_dispatch' => $daysToDispatch,
                'stock' => ($realStock !== null && $realStock > 0) ? $realStock : ($isAvailable ? 10 : 0), // real supplier qty when confirmed; otherwise a placeholder for in-stock items, 0 when not orderable
                'warehouses' => [
                    [
                        'id' => 'Main',
                        'stock' => ($realStock !== null && $realStock > 0) ? $realStock : ($isAvailable ? 10 : 0),
                    ],
                ],
            ];
        }

    $priceList = [
        'total' => count($data),
        'updatedAt' => priceFeedUpdatedAt($data, $sourceFeedDate),
        'data' => $data,
    ];

    echo json_encode($priceList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Invalid type parameter. Use ?type=catalog or ?type=prices</error>";
