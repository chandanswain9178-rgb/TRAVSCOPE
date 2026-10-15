<?php

/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM
| PROFESSIONAL PACKAGE DETAILS PAGE
| PEXELS ATTRIBUTION + PACKAGE CATEGORY + CHILD AGE PRICING + FIXED BOOKING ACTIONS VERSION: 2026-08-08-R9
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/package-action-mode.php';
require_once __DIR__ . '/package-currency-functions.php';


/*
|--------------------------------------------------------------------------
| CURRENT USER - OPTIONAL LOGIN
|--------------------------------------------------------------------------
*/

$user = null;
$userId = 0;

if (isLoggedIn()) {

    $user = currentUser($pdo);

    if (
        $user
        && strtolower((string)$user['status']) === 'active'
    ) {
        $userId = (int)$user['id'];
    } else {
        unset($_SESSION['user_id']);
        $user = null;
    }
}


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$siteName = getSetting(
    $pdo,
    'site_name',
    'TRAVSCOPE.COM'
);

$currencySymbol = getSetting(
    $pdo,
    'currency_symbol',
    '₹'
);

$documentLogo = trim((string)getSetting(
    $pdo,
    'document_logo',
    'assets/travscope-document-logo-wide.png'
));
if ($documentLogo === '') {
    $documentLogo = 'assets/travscope-document-logo-wide.png';
}
$documentLogoUrl = preg_match('#^https?://#i', $documentLogo)
    ? $documentLogo
    : rtrim((string)BASE_URL, '/') . '/' . ltrim($documentLogo, '/');


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function travelImage(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1400&q=85';
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    return BASE_URL . ltrim($image, '/');
}


/*
|--------------------------------------------------------------------------
| PUBLIC CONTENT WORD-RANGE GUARDS — V24.89
|--------------------------------------------------------------------------
| Short package copy is capped at 100 words; full package copy and the
| customer-facing day narrative are capped at 200 words. Day route facts are
| separated from the narrative so Start/Via/End/Distance/Travel Time do not
| consume the 190–200 word intellectual-client narrative allowance.
|--------------------------------------------------------------------------
*/

function publicWordLimit(?string $value, int $limit): string
{
    $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
    $value = trim($value);
    if ($value === '' || $limit <= 0) return $value;
    if (!preg_match_all('/\S+/u', $value, $words, PREG_OFFSET_CAPTURE) || count($words[0]) <= $limit) return $value;
    $last = $words[0][$limit - 1];
    $end = (int)$last[1] + strlen((string)$last[0]);
    return rtrim(substr($value, 0, $end), " \t\n\r\0\x0B,;:-") . '…';
}

function publicItineraryDayParts(?string $value, int $currentDay = 0, int $limit = 200): array
{
    $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
    $value = trim($value);
    if ($value === '') return ['narrative'=>'','metadata'=>''];

    /* Remove accidental next-day spillover such as "Day 04: ..." inside Day 3. */
    if (preg_match_all('/(?:^|[\r\n]+|(?<=[.!?])\s+)\s*Day\s*0*(\d{1,2})\b\s*(?:[:\-–—.]|\s)/iu', $value, $dayMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($dayMatches[0] as $i => $wholeMatch) {
            $offset = (int)($wholeMatch[1] ?? 0);
            $foundDay = (int)($dayMatches[1][$i][0] ?? 0);
            if ($offset > 0 && ($currentDay <= 0 || $foundDay !== $currentDay)) {
                $value = rtrim(substr($value, 0, $offset));
                break;
            }
        }
    }

    $narrative = [];
    $metadata = [];

    /*
     * V24.92: route facts may arrive inline at the end of the narrative, e.g.
     * "Overnight stay... [Start: ... | Via/Sightseeing: ... | End: ...]".
     * Extract every such bracket block before the word-limit is applied so it
     * appears once, in the dedicated route strip, instead of being duplicated
     * inside the customer-facing paragraph.
     */
    if (preg_match_all('/\[(?=[^\]]*(?:Start:|Via\/?Sightseeing:|End:|Distance:|Approx(?:\.|imate)?\s*travel\s*time:|Overnight:|Meals:))[^\]]+\]/iu', $value, $inlineMeta)) {
        foreach (($inlineMeta[0] ?? []) as $metaBlock) {
            $metaBlock = trim((string)$metaBlock);
            if ($metaBlock !== '') $metadata[] = $metaBlock;
        }
        $value = preg_replace('/\[(?=[^\]]*(?:Start:|Via\/?Sightseeing:|End:|Distance:|Approx(?:\.|imate)?\s*travel\s*time:|Overnight:|Meals:))[^\]]+\]/iu', '', $value) ?? $value;
        $value = preg_replace('/[ \t]{2,}/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
        $value = trim($value);
    }

    foreach (preg_split('/\n+/u', $value) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $isBracketMeta = (bool)preg_match('/^\[(?=.*(?:Start:|Via\/?Sightseeing:|End:|Distance:|Approx(?:\.|imate)?\s*travel\s*time:|Overnight:|Meals:)).*\]$/iu', $line);
        $isLegacyMeta = (bool)preg_match('/^(?:Route:|Starting point:|End point:|Sightseeing\s*\/\s*route:|Distance:|Approx\.?\s*travel\s*time:|Overnight stay:|Meals:)/iu', $line);
        if ($isBracketMeta || $isLegacyMeta) $metadata[] = $line;
        else $narrative[] = $line;
    }

    /* Avoid repeated route strips when the importer/source saved the same block twice. */
    $seenMeta = [];
    $cleanMeta = [];
    foreach ($metadata as $metaLine) {
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$metaLine)) ?? trim((string)$metaLine), 'UTF-8');
        if ($key === '' || isset($seenMeta[$key])) continue;
        $seenMeta[$key] = true;
        $cleanMeta[] = trim((string)$metaLine);
    }

    return [
        'narrative' => publicWordLimit(implode("\n", $narrative), $limit),
        'metadata' => trim(implode("\n", $cleanMeta))
    ];
}

function publicItineraryMetaItems(?string $value): array
{
    $value = trim((string)$value);
    if ($value === '') return [];

    $items = [];
    $seen = [];
    foreach (preg_split('/\n+/u', $value) ?: [] as $line) {
        $line = trim((string)$line, " \t\n\r\0\x0B[]");
        if ($line === '') continue;

        foreach (preg_split('/\s*\|\s*/u', $line) ?: [] as $item) {
            $item = trim((string)$item, " \t\n\r\0\x0B[]");
            $item = preg_replace('/\s+/u', ' ', $item) ?? $item;
            if ($item === '') continue;

            $key = mb_strtolower($item, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = $item;
        }
    }

    return $items;
}

function publicItineraryDayText(?string $value, int $currentDay = 0, int $limit = 200): string
{
    $parts=publicItineraryDayParts($value,$currentDay,$limit);
    return (string)$parts['narrative'];
}


/*
|--------------------------------------------------------------------------
| IMAGE ATTRIBUTION HELPERS
|--------------------------------------------------------------------------
| Reads attribution saved by the Pexels importer.
|--------------------------------------------------------------------------
*/

function normalizeAttributionImagePath(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $image)) {
        $path = (string)parse_url($image, PHP_URL_PATH);
        return ltrim($path, '/');
    }

    return ltrim($image, '/');
}

function loadImageAttributions(PDO $pdo, array $imagePaths): array
{
    $normalized = [];

    foreach ($imagePaths as $path) {
        $path = normalizeAttributionImagePath((string)$path);

        if ($path !== '') {
            $normalized[$path] = $path;
        }
    }

    if (!$normalized) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalized), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT
                image_path,
                provider,
                photographer_name,
                photographer_url,
                photo_page_url,
                attribution_text,
                license_name
            FROM image_attributions
            WHERE image_path IN ($placeholders)
        ");

        $stmt->execute(array_values($normalized));

        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = normalizeAttributionImagePath(
                (string)($row['image_path'] ?? '')
            );

            if ($key !== '') {
                $out[$key] = $row;
            }
        }

        return $out;

    } catch (Throwable $e) {
        error_log(
            'Package image attribution load error: '
            . $e->getMessage()
        );

        return [];
    }
}

function imageAttributionFor(array $map, ?string $image): ?array
{
    $key = normalizeAttributionImagePath($image);

    if ($key === '' || empty($map[$key])) {
        return null;
    }

    return $map[$key];
}

function renderImageAttribution(?array $credit): string
{
    if (!$credit) {
        return '';
    }

    $photographer = trim((string)($credit['photographer_name'] ?? ''));
    $photographerUrl = trim((string)($credit['photographer_url'] ?? ''));
    $provider = trim((string)($credit['provider'] ?? 'Pexels'));
    $photoPageUrl = trim((string)($credit['photo_page_url'] ?? ''));

    if ($photographer === '' && $provider === '') {
        return '';
    }

    $html = '<div class="photo-attribution">';

    if ($photographer !== '') {
        $html .= '<span>Photo by ';

        if ($photographerUrl !== '') {
            $html .= '<a href="'
                . e($photographerUrl)
                . '" target="_blank" rel="noopener noreferrer">'
                . e($photographer)
                . '</a>';
        } else {
            $html .= e($photographer);
        }

        $html .= '</span>';
    }

    if ($provider !== '') {
        $html .= '<span class="photo-attribution-separator">/</span>';

        if ($photoPageUrl !== '') {
            $html .= '<a href="'
                . e($photoPageUrl)
                . '" target="_blank" rel="noopener noreferrer">'
                . e($provider)
                . '</a>';
        } else {
            $html .= '<span>' . e($provider) . '</span>';
        }
    }

    $html .= '</div>';

    return $html;
}

function discountPercent($regular, $sale): int
{
    $regular = (float)$regular;
    $sale = (float)$sale;

    if (
        $regular <= 0
        || $sale <= 0
        || $sale >= $regular
    ) {
        return 0;
    }

    return (int)round(
        (($regular - $sale) / $regular) * 100
    );
}


/*
|--------------------------------------------------------------------------
| FORMAT LONG TEXT AS LIST
|--------------------------------------------------------------------------
|
| Works with:
| new lines
| bullets
| commas
| semicolons
|--------------------------------------------------------------------------
*/

function textItems(?string $text): array
{
    $text = trim((string)$text);

    if ($text === '') {
        return [];
    }

    $text = str_replace(
        ["\r\n", "\r", "•"],
        ["\n", "\n", "\n"],
        $text
    );

    $parts = preg_split(
        '/\n+|;\s*/',
        $text
    );

    $items = [];

    foreach ($parts as $part) {

        $part = trim(
            $part,
            " \t\n\r\0\x0B-*"
        );

        if ($part !== '') {
            $items[] = $part;
        }
    }

    return $items;
}


/*
|--------------------------------------------------------------------------
| PROFESSIONAL LONG-TEXT FORMATTER
|--------------------------------------------------------------------------
|
| Safely formats admin-entered text into readable headings, numbered
| points, bullet points and paragraphs without allowing unsafe HTML.
|
*/
function professionalText(?string $text): string
{
    $text = trim((string)$text);

    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = preg_split('/\n/', $text);
    $html = '';

    foreach ($lines as $line) {
        $line = trim((string)$line);

        if ($line === '') {
            continue;
        }

        $plainLength = function_exists('mb_strlen')
            ? mb_strlen($line)
            : strlen($line);

        $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $line);
        $isUppercaseHeading =
            $lettersOnly !== ''
            && $plainLength <= 90
            && function_exists('mb_strtoupper')
            && $lettersOnly === mb_strtoupper($lettersOnly);

        $isColonHeading =
            $plainLength <= 90
            && str_ends_with($line, ':');

        if ($isUppercaseHeading || $isColonHeading) {
            $html .= '<h3 class="information-subheading">'
                . e(rtrim($line, ':'))
                . '</h3>';
            continue;
        }

        if (preg_match('/^(\d+|[A-Za-z]|[ivxlcdmIVXLCDM]+)[\.\)]\s*(.+)$/u', $line, $matches)) {
            $html .= '<div class="information-point">'
                . '<span class="information-marker">' . e($matches[1]) . '</span>'
                . '<span class="information-point-text">' . e($matches[2]) . '</span>'
                . '</div>';
            continue;
        }

        if (preg_match('/^[•\-–—*✓✔]\s*(.+)$/u', $line, $matches)) {
            $html .= '<div class="information-point information-bullet">'
                . '<span class="information-marker"><i class="fa-solid fa-check"></i></span>'
                . '<span class="information-point-text">' . e($matches[1]) . '</span>'
                . '</div>';
            continue;
        }

        $html .= '<p>' . e($line) . '</p>';
    }

    return $html;
}


/*
|--------------------------------------------------------------------------
| SLUG
|--------------------------------------------------------------------------
*/

$slug = trim(
    (string)(
        $_GET['slug']
        ?? ''
    )
);

if ($slug === '') {

    http_response_code(404);

    exit('Package not found.');
}


/*
|--------------------------------------------------------------------------
| FETCH PACKAGE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        p.*,

        d.name AS destination_name,
        d.slug AS destination_slug,
        d.country AS destination_country,
        d.continent AS destination_continent,
        d.short_description AS destination_short_description,
        d.description AS destination_description,
        d.image AS destination_image,
        d.banner_image AS destination_banner

    FROM packages p

    LEFT JOIN destinations d
        ON d.id = p.destination_id

    WHERE
        p.slug = ?
        AND p.status = 'active'

    LIMIT 1
");

$stmt->execute([$slug]);

$package = $stmt->fetch();

if (!$package) {

    http_response_code(404);

    exit('Package not found or currently unavailable.');
}

$packageId = (int)$package['id'];
$currencySymbol = tsPackageCurrencySymbol((string)($package['currency'] ?? 'INR'));
$customerActionMode = tsCustomerActionMode($package);

/*
|--------------------------------------------------------------------------
| CHILD AGE + PER CHILD PRICE
|--------------------------------------------------------------------------
| Load every child price row configured by Admin.
| Existing single child fields remain as a safe fallback for old packages.
*/

$packageChildPrices = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            age_from,
            age_to,
            price,
            sort_order
        FROM package_child_prices
        WHERE package_id = ?
        ORDER BY
            sort_order ASC,
            age_from ASC,
            id ASC
    ");

    $stmt->execute([$packageId]);

    $packageChildPrices =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'Package child pricing fetch error: '
        . $e->getMessage()
    );

    $packageChildPrices = [];
}

/*
| Backward compatibility for packages saved before multiple child rows.
*/
if (!$packageChildPrices) {
    $legacyChildAgeMin = max(0, (int)($package['child_age_min'] ?? 0));
    $legacyChildAgeMax = max(0, (int)($package['child_age_max'] ?? 0));
    $legacyChildPrice = max(0, (float)($package['child_price'] ?? 0));

    if (
        $legacyChildAgeMax >= $legacyChildAgeMin
        && (
            $legacyChildAgeMax > 0
            || $legacyChildAgeMin > 0
            || $legacyChildPrice > 0
        )
    ) {
        $packageChildPrices[] = [
            'age_from' => $legacyChildAgeMin,
            'age_to' => $legacyChildAgeMax,
            'price' => $legacyChildPrice,
            'sort_order' => 1
        ];
    }
}

$childAgeMin = 0;
$childAgeMax = 17;
$childUnitPrice = 0.0;

if ($packageChildPrices) {
    $childAgeMin = min(
        array_map(
            static fn(array $row): int => max(0, (int)($row['age_from'] ?? 0)),
            $packageChildPrices
        )
    );

    $childAgeMax = max(
        array_map(
            static fn(array $row): int => max(0, (int)($row['age_to'] ?? 0)),
            $packageChildPrices
        )
    );

    $childUnitPrice = max(
        0,
        (float)($packageChildPrices[0]['price'] ?? 0)
    );
}

$hasChildAgeRange =
    !empty($packageChildPrices)
    && $childAgeMax >= $childAgeMin;



/*
|--------------------------------------------------------------------------
| UPDATE VIEWS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        UPDATE packages
        SET views = views + 1
        WHERE id = ?
    ");

    $stmt->execute([$packageId]);

    $package['views'] =
        (int)$package['views'] + 1;

} catch (Throwable $e) {

    /*
     * Page should still load if view update fails.
     */
}


/*
|--------------------------------------------------------------------------
| PACKAGE GALLERY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        image,
        alt_text,
        sort_order
    FROM package_images
    WHERE package_id = ?
    ORDER BY
        sort_order ASC,
        id ASC
");

$stmt->execute([$packageId]);

$gallery = $stmt->fetchAll();



/*
|--------------------------------------------------------------------------
| DAY-WISE ITINERARY
|--------------------------------------------------------------------------
*/

$itineraryDays = [];

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            day_number,
            title,
            description,
            image,
            sort_order
        FROM package_itinerary_days
        WHERE package_id = ?
        ORDER BY
            sort_order ASC,
            day_number ASC,
            id ASC
    ");

    $stmt->execute([$packageId]);

    $itineraryDays =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log(
        'Package itinerary fetch error: '
        . $e->getMessage()
    );

    $itineraryDays = [];
}




/*
|--------------------------------------------------------------------------
| PACKAGE MASTER V2 - AUTOMATIC HOTEL NAMES
|--------------------------------------------------------------------------
*/
$packageHotels = [3 => null, 4 => null, 5 => null];

try {
    $stmt = $pdo->prepare("
        SELECT
            ph.star_category,
            ph.hotel_name_snapshot,
            ph.room_type,
            ph.meal_plan,
            h.hotel_name,
            s.company_name AS supplier_name
        FROM package_hotels ph
        LEFT JOIN hotels h ON h.id = ph.hotel_id
        LEFT JOIN suppliers s ON s.id = ph.supplier_id
        WHERE ph.package_id = ?
        ORDER BY ph.star_category ASC
    ");
    $stmt->execute([$packageId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $star = (int)$row['star_category'];
        if (isset($packageHotels[$star])) {
            $packageHotels[$star] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('Package hotel fetch error: ' . $e->getMessage());
}

$packageHotelNames = [];
foreach ([3,4,5] as $star) {
    $row = $packageHotels[$star];
    if (!$row) continue;
    $name = trim((string)($row['hotel_name'] ?? ''));
    if ($name === '') $name = trim((string)($row['hotel_name_snapshot'] ?? ''));
    if ($name !== '') $packageHotelNames[(string)$star] = $name;
}

/*
|--------------------------------------------------------------------------
| DATE-WISE 3 / 4 / 5 STAR PACKAGE PRICES
|--------------------------------------------------------------------------
*/

$packageDatePrices = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            valid_from,
            valid_to,
            price_3_star,
            price_4_star,
            price_5_star,
            sort_order
        FROM package_date_prices
        WHERE package_id = ?
        ORDER BY
            valid_from ASC,
            sort_order ASC,
            id ASC
    ");

    $stmt->execute([
        $packageId
    ]);

    $packageDatePrices =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'Package date-wise pricing fetch error: '
        . $e->getMessage()
    );

    $packageDatePrices = [];
}


/*
|--------------------------------------------------------------------------
| STEP 08 - SMART OCCUPANCY PRICING
|--------------------------------------------------------------------------
| Optional pricing layer. If no rows exist, the original date/star and
| child-age pricing continues unchanged.
|--------------------------------------------------------------------------
*/

$packageOccupancyPrices = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            valid_from,
            valid_to,
            star_category,
            twin_price,
            triple_price,
            single_price,
            child_with_bed_price,
            child_without_bed_price,
            infant_price,
            infant_age_to
        FROM package_occupancy_prices
        WHERE package_id = ?
        ORDER BY valid_from ASC, star_category ASC, sort_order ASC, id ASC
    ");
    $stmt->execute([$packageId]);
    $packageOccupancyPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Backward compatible when Step 08 has not been installed yet.
    $packageOccupancyPrices = [];
}

function packageOccupancyRowForDate(
    array $rows,
    string $date,
    int $star
): ?array {
    foreach ($rows as $row) {
        if (
            (int)($row['star_category'] ?? 0) === $star
            && $date >= (string)($row['valid_from'] ?? '')
            && $date <= (string)($row['valid_to'] ?? '')
        ) {
            return $row;
        }
    }
    return null;
}

function packageOccupancyAdultPrice(?array $row, string $type): float
{
    if (!$row) return 0.0;

    $field = [
        'twin' => 'twin_price',
        'triple' => 'triple_price',
        'single' => 'single_price',
    ][$type] ?? 'twin_price';

    return isset($row[$field]) && $row[$field] !== null
        ? max(0, (float)$row[$field])
        : 0.0;
}

function packageOccupancyFirstAdultType(?array $row): string
{
    if (!$row) return 'twin';

    foreach (['twin','triple','single'] as $type) {
        if (packageOccupancyAdultPrice($row, $type) > 0) {
            return $type;
        }
    }

    return 'twin';
}

function activePackagePricePeriod(array $rows, string $date): ?array
{
    foreach ($rows as $row) {
        if (
            $date >= (string)$row['valid_from']
            && $date <= (string)$row['valid_to']
        ) {
            return $row;
        }
    }

    foreach ($rows as $row) {
        if ($date < (string)$row['valid_from']) {
            return $row;
        }
    }

    return $rows
        ? $rows[count($rows) - 1]
        : null;
}

function lowestHotelPrice(?array $row): float
{
    if (!$row) {
        return 0.0;
    }

    $prices = [];

    foreach (['price_3_star', 'price_4_star', 'price_5_star'] as $field) {
        if (
            isset($row[$field])
            && $row[$field] !== null
            && (float)$row[$field] > 0
        ) {
            $prices[] = (float)$row[$field];
        }
    }

    return $prices ? min($prices) : 0.0;
}

$requestedTravelDate =
    trim((string)($_GET['travel_date'] ?? ''));

if (
    $requestedTravelDate !== ''
    && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $requestedTravelDate)
) {
    $requestedTravelDate = '';
}

$requestedHotelCategory =
    trim((string)($_GET['hotel_category'] ?? ''));

$requestedHotelCategory =
    in_array($requestedHotelCategory, ['3', '4', '5'], true)
        ? $requestedHotelCategory
        : '';

$requestedOccupancyType =
    strtolower(trim((string)($_GET['occupancy_type'] ?? 'twin')));

$requestedOccupancyType =
    in_array($requestedOccupancyType, ['twin', 'triple', 'single'], true)
        ? $requestedOccupancyType
        : 'twin';

$priceLookupDate =
    $requestedTravelDate !== ''
        ? $requestedTravelDate
        : date('Y-m-d');

$currentPricePeriod =
    activePackagePricePeriod(
        $packageDatePrices,
        $priceLookupDate
    );

$currentSelectedHotelCategory =
    $requestedHotelCategory;

if (
    $currentPricePeriod
    && (
        $currentSelectedHotelCategory === ''
        || (float)(
            $currentPricePeriod[
                'price_' . $currentSelectedHotelCategory . '_star'
            ] ?? 0
        ) <= 0
    )
) {
    $currentSelectedHotelCategory = '';

    foreach (['3', '4', '5'] as $star) {
        if (
            (float)(
                $currentPricePeriod[
                    'price_' . $star . '_star'
                ] ?? 0
            ) > 0
        ) {
            $currentSelectedHotelCategory = $star;
            break;
        }
    }
}

if (
    $currentSelectedHotelCategory === ''
    && $packageOccupancyPrices
) {
    foreach ([3,4,5] as $star) {
        $occRow = packageOccupancyRowForDate(
            $packageOccupancyPrices,
            $priceLookupDate,
            $star
        );

        if (!$occRow) continue;

        $type = $requestedOccupancyType;

        if (packageOccupancyAdultPrice($occRow, $type) <= 0) {
            $type = packageOccupancyFirstAdultType($occRow);
        }

        if (packageOccupancyAdultPrice($occRow, $type) > 0) {
            $currentSelectedHotelCategory = (string)$star;
            $requestedOccupancyType = $type;
            break;
        }
    }
}

$currentStartingPrice = 0.0;

if (
    $currentPricePeriod
    && $currentSelectedHotelCategory !== ''
) {
    $currentStartingPrice =
        (float)(
            $currentPricePeriod[
                'price_' . $currentSelectedHotelCategory . '_star'
            ] ?? 0
        );
}

if ($currentStartingPrice <= 0) {
    $currentStartingPrice =
        lowestHotelPrice($currentPricePeriod);
}

if ($currentStartingPrice <= 0) {
    $currentStartingPrice =
        (float)($package['sale_price'] ?? 0);
}

$currentOccupancyRow = null;

if ($currentSelectedHotelCategory !== '') {
    $currentOccupancyRow =
        packageOccupancyRowForDate(
            $packageOccupancyPrices,
            $priceLookupDate,
            (int)$currentSelectedHotelCategory
        );
}

if ($currentOccupancyRow) {
    if (
        packageOccupancyAdultPrice(
            $currentOccupancyRow,
            $requestedOccupancyType
        ) <= 0
    ) {
        $requestedOccupancyType =
            packageOccupancyFirstAdultType(
                $currentOccupancyRow
            );
    }

    $smartStartingPrice =
        packageOccupancyAdultPrice(
            $currentOccupancyRow,
            $requestedOccupancyType
        );

    if ($smartStartingPrice > 0) {
        $currentStartingPrice = $smartStartingPrice;
    }
}


/*
|--------------------------------------------------------------------------
| BUILD COMPLETE IMAGE LIST
|--------------------------------------------------------------------------
*/

$images = [];

if (!empty($package['main_image'])) {

    $images[] = [
        'image' => $package['main_image'],
        'alt_text' => $package['title']
    ];
}

foreach ($gallery as $image) {

    if (
        !empty($image['image'])
        && $image['image'] !== $package['main_image']
    ) {

        $images[] = $image;
    }
}

if (!$images) {

    $images[] = [
        'image' => '',
        'alt_text' => $package['title']
    ];
}


/*
|--------------------------------------------------------------------------
| LOAD IMAGE ATTRIBUTIONS
|--------------------------------------------------------------------------
*/

$attributionImagePaths = [];

foreach ($images as $imageRow) {
    $attributionImagePaths[] = (string)($imageRow['image'] ?? '');
}

foreach ($itineraryDays as $dayRow) {
    $attributionImagePaths[] = (string)($dayRow['image'] ?? '');
}

$imageAttributions = loadImageAttributions(
    $pdo,
    $attributionImagePaths
);

foreach ($images as $index => $imageRow) {
    $images[$index]['attribution'] = imageAttributionFor(
        $imageAttributions,
        (string)($imageRow['image'] ?? '')
    );
}

foreach ($itineraryDays as $index => $dayRow) {
    $itineraryDays[$index]['attribution'] = imageAttributionFor(
        $imageAttributions,
        (string)($dayRow['image'] ?? '')
    );
}


/*
|--------------------------------------------------------------------------
| WISHLIST
|--------------------------------------------------------------------------
*/

$isWishlisted = false;

if ($userId > 0) {

    $stmt = $pdo->prepare("
        SELECT id
        FROM wishlists
        WHERE
            user_id = ?
            AND package_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId,
        $packageId
    ]);

    $isWishlisted =
        (bool)$stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| WISHLIST POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['wishlist_action'])
) {

    if (!$userId) {

        setFlash(
            'error',
            'Please login to save this trip.'
        );

        redirect(
            BASE_URL
            . 'login.php'
        );
    }

    $csrf =
        $_POST['csrf_token']
        ?? '';

    if (!verifyCsrf($csrf)) {

        setFlash(
            'error',
            'Your session expired. Please try again.'
        );

        redirect(
            BASE_URL
            . 'package-details.php?slug='
            . urlencode($slug)
        );
    }


    if ($isWishlisted) {

        $stmt = $pdo->prepare("
            DELETE FROM wishlists
            WHERE
                user_id = ?
                AND package_id = ?
        ");

        $stmt->execute([
            $userId,
            $packageId
        ]);

        setFlash(
            'success',
            'Trip removed from your wishlist.'
        );

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO wishlists
            (
                user_id,
                package_id
            )
            VALUES
            (?, ?)
        ");

        $stmt->execute([
            $userId,
            $packageId
        ]);

        setFlash(
            'success',
            'Trip saved to your wishlist.'
        );
    }

    redirect(
        BASE_URL
        . 'package-details.php?slug='
        . urlencode($slug)
    );
}


/*
|--------------------------------------------------------------------------
| PUBLIC PACKAGE REVIEWS
|--------------------------------------------------------------------------
*/

$reviews = [];
$reviewTotal = 0;
$averageRating = 0.0;

$ratingCounts = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];

$ratingPercentages = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];

try {

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_reviews,
            COALESCE(AVG(rating), 0) AS average_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS rating_5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS rating_4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS rating_3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS rating_2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS rating_1
        FROM reviews
        WHERE
            package_id = ?
            AND status = 'approved'
    ");

    $stmt->execute([$packageId]);
    $reviewStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reviewStats) {

        $reviewTotal = (int)($reviewStats['total_reviews'] ?? 0);

        $averageRating = round(
            (float)($reviewStats['average_rating'] ?? 0),
            1
        );

        for ($star = 1; $star <= 5; $star++) {

            $ratingCounts[$star] =
                (int)($reviewStats['rating_' . $star] ?? 0);

            if ($reviewTotal > 0) {

                $ratingPercentages[$star] = round(
                    ($ratingCounts[$star] / $reviewTotal) * 100
                );
            }
        }
    }

} catch (Throwable $e) {

    error_log(
        'Package review statistics error: '
        . $e->getMessage()
    );
}

try {

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.user_id,
            r.booking_id,
            r.rating,
            r.title,
            r.review_text,
            r.admin_reply,
            r.admin_reply_at,
            r.featured,
            r.created_at,
            u.full_name,
            u.profile_image,
            b.booking_number
        FROM reviews r
        LEFT JOIN users u
            ON u.id = r.user_id
        LEFT JOIN bookings b
            ON b.id = r.booking_id
        WHERE
            r.package_id = ?
            AND r.status = 'approved'
        ORDER BY
            r.featured DESC,
            r.created_at DESC,
            r.id DESC
        LIMIT 20
    ");

    $stmt->execute([$packageId]);

    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log(
        'Package reviews fetch error: '
        . $e->getMessage()
    );

    $reviews = [];
}

function packageReviewStars($rating): string
{
    $rating = max(0, min(5, (int)$rating));
    $html = '';

    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $rating
            ? '<i class="fa-solid fa-star"></i>'
            : '<i class="fa-regular fa-star"></i>';
    }

    return $html;
}

function packageReviewDate($date): string
{
    if (empty($date)) {
        return '';
    }

    $time = strtotime((string)$date);

    return $time
        ? date('d M Y', $time)
        : '';
}

function packageReviewInitial($name): string
{
    $name = trim((string)$name);

    if ($name === '') {
        return 'T';
    }

    if (function_exists('mb_substr')) {
        return strtoupper(mb_substr($name, 0, 1));
    }

    return strtoupper(substr($name, 0, 1));
}


/*
|--------------------------------------------------------------------------
| RELATED PACKAGES
|--------------------------------------------------------------------------
*/

$related = [];

if (!empty($package['destination_id'])) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            slug,
            short_description,
            duration_days,
            duration_nights,
            regular_price,
            sale_price,
            currency,
            package_type,
            main_image,
            featured,
            bestseller

        FROM packages

        WHERE
            status = 'active'
            AND destination_id = ?
            AND id != ?

        ORDER BY
            featured DESC,
            bestseller DESC,
            views DESC,
            id DESC

        LIMIT 3
    ");

    $stmt->execute([
        $package['destination_id'],
        $packageId
    ]);

    $related = $stmt->fetchAll();
}


/*
|--------------------------------------------------------------------------
| CONTENT
|--------------------------------------------------------------------------
*/

$inclusions =
    textItems(
        $package['inclusions']
        ?? ''
    );

$exclusions =
    textItems(
        $package['exclusions']
        ?? ''
    );

$discount = 0;
$saving = 0;

/*
| Legacy package columns remain available as a fallback, but the public
| booking card uses package_date_prices whenever date-wise prices exist.
*/


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$successMessage = '';
$errorMessage = '';

if (
    isset($_SESSION['flash']['success'])
) {

    $successMessage =
        (string)$_SESSION['flash']['success'];

    unset(
        $_SESSION['flash']['success']
    );
}

if (
    isset($_SESSION['flash']['error'])
) {

    $errorMessage =
        (string)$_SESSION['flash']['error'];

    unset(
        $_SESSION['flash']['error']
    );
}


/*
|--------------------------------------------------------------------------
| MINIMUM TRAVEL DATE
|--------------------------------------------------------------------------
*/

$minimumTravelDate =
    date(
        'Y-m-d',
        strtotime('+1 day')
    );

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="theme-color"
    content="#07192d"
>

<meta
    name="description"
    content="<?= e(
        !empty($package['short_description'])
            ? publicWordLimit((string)$package['short_description'], 100)
            : (string)$package['title']
    ); ?>"
>

<title>
<?= e($package['title']); ?>
| <?= e($siteName); ?>
</title>


<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>


<style>

/* =========================================================
   GLOBAL
========================================================= */

:root {

    --navy:#07192d;
    --navy2:#0c2a48;

    --blue:#0b74ff;
    --blue-dark:#075fd2;

    --cyan:#00b8ff;

    --green:#079669;
    --green-bg:#e9faf3;

    --red:#e14456;
    --red-bg:#fff0f2;

    --orange:#f59a23;

    --white:#fff;

    --bg:#f5f7fa;

    --text:#182235;

    --muted:#778395;

    --border:#e3e8ef;

    --shadow:
        0 14px 40px
        rgba(7,25,45,.08);
}


* {

    margin:0;
    padding:0;

    box-sizing:border-box;
}


html {

    scroll-behavior:smooth;
}


body {

    background:var(--bg);

    color:var(--text);

    font-family:
        'DM Sans',
        sans-serif;
}


a {

    color:inherit;
    text-decoration:none;
}


button,
input,
select {

    font:inherit;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    height:76px;

    padding:0 5%;

    position:sticky;

    top:0;

    z-index:1000;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;

    background:
        rgba(255,255,255,.96);

    backdrop-filter:
        blur(14px);

    border-bottom:
        1px solid
        var(--border);
}


.logo {

    display:flex;

    align-items:center;

    gap:10px;

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:20px;

    font-weight:800;
}


.logo-icon {

    width:41px;
    height:41px;

    border-radius:12px;

    display:grid;

    place-items:center;

    background:
        linear-gradient(
            135deg,
            var(--blue),
            var(--cyan)
        );

    color:white;

    box-shadow:
        0 8px 20px
        rgba(11,116,255,.2);
}


.logo span {

    color:var(--blue);
}


.nav {

    display:flex;

    align-items:center;

    gap:27px;
}


.nav a {

    color:#596678;

    font-size:11px;

    font-weight:600;
}


.nav a:hover,
.nav a.active {

    color:var(--blue);
}


.header-actions {

    display:flex;

    gap:8px;
}


.header-btn {

    min-height:40px;

    padding:0 14px;

    border-radius:9px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:7px;

    font-size:9px;

    font-weight:800;
}


.header-btn.light {

    border:
        1px solid
        var(--border);

    background:white;
}


.header-btn.blue {

    background:var(--blue);

    color:white;
}


/* =========================================================
   BREADCRUMB
========================================================= */

.breadcrumb-wrap {

    background:white;

    border-bottom:
        1px solid
        var(--border);
}


.breadcrumb {

    max-width:1250px;

    min-height:53px;

    margin:auto;

    padding:0 25px;

    display:flex;

    align-items:center;

    flex-wrap:wrap;

    gap:8px;

    color:#8993a2;

    font-size:9px;
}


.breadcrumb a:hover {

    color:var(--blue);
}


.breadcrumb i {

    font-size:7px;
}


/* =========================================================
   GALLERY
========================================================= */

.gallery-wrap {

    max-width:1300px;

    margin:auto;

    padding:24px 25px 0;
}


.gallery {

    height:470px;

    display:grid;

    grid-template-columns:
        2fr 1fr;

    gap:8px;

    overflow:hidden;

    border-radius:18px;
}


.gallery-main {

    position:relative;

    overflow:hidden;

    background:#e6ebf0;

    cursor:pointer;
}


.gallery-main img {

    width:100%;
    height:100%;

    object-fit:cover;

    transition:.4s;
}


.gallery-main:hover img {

    transform:scale(1.02);
}


.gallery-side {

    display:grid;

    grid-template-columns:
        1fr 1fr;

    grid-template-rows:
        1fr 1fr;

    gap:8px;
}


.gallery-small {

    position:relative;

    overflow:hidden;

    background:#e6ebf0;

    cursor:pointer;
}


.gallery-small img {

    width:100%;
    height:100%;

    object-fit:cover;

    transition:.35s;
}


.gallery-small:hover img {

    transform:scale(1.05);
}


.view-photos {

    position:absolute;

    right:15px;
    bottom:15px;

    min-height:38px;

    padding:0 12px;

    border:0;

    border-radius:8px;

    display:flex;

    align-items:center;

    gap:7px;

    background:
        rgba(255,255,255,.94);

    color:var(--navy);

    cursor:pointer;

    font-size:9px;

    font-weight:800;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,.15);
}



/* =========================================================
   PHOTO ATTRIBUTION
========================================================= */

.gallery-photo-frame{
    min-width:0;
    min-height:0;
}

.gallery-main-frame{
    grid-row:1;
}

.gallery-main-frame .gallery-main{
    width:100%;
    height:100%;
    min-height:0;
    border-radius:18px 0 0 18px;
}

.gallery-side{
    min-height:0;
}

.gallery-small-frame{
    min-width:0;
    min-height:0;
}

.gallery-small-frame .gallery-small{
    width:100%;
    height:100%;
    min-height:0;
}

.photo-attribution{
    min-height:18px;
    padding:0 3px;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    flex-wrap:wrap;
    gap:4px;
    color:#697688;
    font-size:11px;
    line-height:1.4;
    text-align:right;
}

.photo-attribution a{
    color:inherit;
    font-weight:600;
    text-decoration:none;
}

.photo-attribution a:hover{
    color:inherit;
    text-decoration:underline;
}

.photo-attribution-separator{
    color:inherit;
}

.gallery-photo-attribution{
    position:absolute;
    right:10px;
    bottom:10px;
    z-index:5;
    min-height:auto;
    max-width:calc(100% - 20px);
    padding:0;
    border:0;
    border-radius:0;
    background:transparent;
    color:#fff;
    font-size:10px;
    line-height:1.35;
    box-shadow:none;
    backdrop-filter:none;
    text-shadow:
        0 1px 2px rgba(0,0,0,.95),
        0 0 8px rgba(0,0,0,.75);
}

.gallery-main .gallery-photo-attribution{
    right:15px;
    bottom:62px;
    font-size:11px;
}

.gallery-small .gallery-photo-attribution{
    right:8px;
    bottom:8px;
    padding:0;
    font-size:8px;
}

.itinerary-photo-attribution{
    padding:7px 11px 0;
}

.modal-attribution{
    position:absolute;
    left:50%;
    bottom:18px;
    max-width:calc(100% - 140px);
    padding:0;
    border:0;
    border-radius:0;
    justify-content:center;
    background:transparent;
    color:#fff;
    transform:translateX(-50%);
    box-shadow:none;
    text-shadow:
        0 1px 2px rgba(0,0,0,.95),
        0 0 10px rgba(0,0,0,.8);
}

.gallery-photo-attribution a,
.modal-attribution a{
    color:inherit !important;
    font-weight:600;
    text-decoration:none;
}

.gallery-photo-attribution a:hover,
.modal-attribution a:hover{
    color:inherit !important;
    text-decoration:underline;
}


@media(max-width:700px){
    .gallery-main-frame .gallery-main{
        border-radius:18px;
    }

    .photo-attribution{
        font-size:10px;
    }

    .modal-attribution{
        bottom:14px;
        max-width:calc(100% - 30px);
        font-size:10px;
    }
}


/* =========================================================
   MAIN
========================================================= */

.container {

    max-width:1250px;

    margin:auto;

    padding:
        30px
        25px
        80px;
}


.layout {

    display:grid;

    grid-template-columns:
        minmax(0,1fr)
        355px;

    gap:28px;
}


/* =========================================================
   TITLE
========================================================= */

.badges {

    display:flex;

    flex-wrap:wrap;

    gap:6px;

    margin-bottom:11px;
}


.badge {

    padding:6px 9px;

    border-radius:50px;

    background:#e9f3ff;

    color:var(--blue);

    font-size:7px;

    font-weight:800;

    letter-spacing:.3px;

    text-transform:uppercase;
}


.badge.best {

    background:#fff0f1;

    color:#d63f51;
}


.badge.discount {

    background:#e9faf3;

    color:#08794f;
}


.title-row {

    display:flex;

    align-items:flex-start;

    justify-content:space-between;

    gap:20px;
}


.package-title {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:
        clamp(
            28px,
            4vw,
            42px
        );

    line-height:1.15;

    letter-spacing:-1.2px;
}


.wishlist-btn {

    width:45px;
    height:45px;

    flex:0 0 45px;

    border:
        1px solid
        var(--border);

    border-radius:50%;

    display:grid;

    place-items:center;

    background:white;

    color:#738093;

    cursor:pointer;

    font-size:16px;
}


.wishlist-btn.active {

    color:var(--red);

    border-color:#f1bdc4;

    background:#fff5f6;
}


/* =========================================================
   PACKAGE SHARE
========================================================= */

.package-actions{
    flex:0 0 auto;
    display:flex;
    align-items:center;
    gap:9px;
}

.package-actions form{
    margin:0;
}

.share-wrap{
    position:relative;
}

.share-btn{
    width:45px;
    height:45px;
    border:0;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg,#0b74ff,#00b8ff);
    color:#fff;
    cursor:pointer;
    font-size:15px;
    box-shadow:0 9px 22px rgba(11,116,255,.24);
    transition:.2s;
}

.share-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 12px 27px rgba(11,116,255,.30);
}

.share-menu{
    width:250px;
    position:absolute;
    top:56px;
    right:0;
    z-index:1200;
    display:none;
    padding:12px;
    border:1px solid var(--border);
    border-radius:14px;
    background:#fff;
    box-shadow:0 18px 45px rgba(7,25,45,.16);
}

.share-menu.open{
    display:block;
}

.share-menu-title{
    padding:2px 3px 10px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:11px;
    font-weight:800;
}

.share-options{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:7px;
}

.share-option{
    min-height:40px;
    padding:0 9px;
    border:1px solid #e5eaf0;
    border-radius:9px;
    display:flex;
    align-items:center;
    gap:7px;
    background:#f9fbfd;
    color:#526174;
    cursor:pointer;
    font-size:8px;
    font-weight:800;
    transition:.2s;
}

.share-option:hover{
    border-color:#bcd9ff;
    background:#f0f7ff;
    color:var(--blue);
}

.share-option i{
    width:20px;
    text-align:center;
    font-size:13px;
}

.share-option.whatsapp i{color:#19a85b}
.share-option.facebook i{color:#1877f2}
.share-option.twitter i{color:#111}
.share-option.email i{color:#e65c45}
.share-option.copy i{color:#0b74ff}
.share-option.native i{color:#7b61ff}

.share-copy-status{
    display:none;
    margin-top:8px;
    padding:7px 9px;
    border-radius:7px;
    background:#e9faf3;
    color:#08794f;
    font-size:7px;
    font-weight:800;
    text-align:center;
}

.share-copy-status.show{
    display:block;
}

@media(max-width:700px){
    .package-actions{gap:7px}
    .share-btn,.wishlist-btn{
        width:42px;
        height:42px;
        flex-basis:42px;
    }
    .share-menu{
        width:238px;
        right:0;
    }
}


.destination {

    margin-top:11px;

    display:flex;

    align-items:center;

    flex-wrap:wrap;

    gap:8px;

    color:#687587;

    font-size:10px;
}


.destination i {

    color:var(--blue);
}


.package-code {

    padding-left:9px;

    border-left:
        1px solid
        var(--border);
}


/* =========================================================
   QUICK INFO
========================================================= */

.quick-info {

    margin-top:23px;

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:9px;
}


.quick-item {

    min-height:82px;

    padding:13px;

    border:
        1px solid
        var(--border);

    border-radius:12px;

    display:flex;

    align-items:center;

    gap:10px;

    background:white;
}


.quick-icon {

    width:37px;
    height:37px;

    flex:0 0 37px;

    border-radius:10px;

    display:grid;

    place-items:center;

    background:#eaf4ff;

    color:var(--blue);
}


.quick-item small {

    display:block;

    color:#929baa;

    font-size:7px;

    font-weight:700;

    text-transform:uppercase;
}


.quick-item strong {

    display:block;

    margin-top:3px;

    color:var(--navy);

    font-size:10px;
}


/* =========================================================
   CONTENT CARDS
========================================================= */

.content-card {

    margin-top:20px;

    padding:24px;

    border:
        1px solid
        var(--border);

    border-radius:15px;

    background:white;

    box-shadow:
        0 7px 25px
        rgba(7,25,45,.035);
}


.content-card h2 {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:20px;
}


.section-sub {

    margin-top:5px;

    color:var(--muted);

    font-size:9px;
}


.description {

    margin-top:16px;

    color:#5f6c7d;

    font-size:11px;

    line-height:1.9;

    white-space:pre-line;
}


/* =========================================================
   INCLUDED
========================================================= */

.include-grid {

    margin-top:18px;

    display:grid;

    grid-template-columns:
        1fr 1fr;

    gap:9px;
}


.include-item {

    min-height:44px;

    padding:10px 12px;

    border-radius:9px;

    display:flex;

    align-items:flex-start;

    gap:9px;

    background:#f8fafc;

    color:#566476;

    font-size:9px;

    line-height:1.6;
}


.include-item i {

    margin-top:2px;

    color:var(--green);
}


.include-item.exclude i {

    color:var(--red);
}

/* =========================================================
   TOUR-HIGHLIGHTS
========================================================= */

.tour-highlights{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
gap:15px;
margin-top:20px;
}

.tour-highlight-item{
display:flex;
align-items:center;
gap:12px;
padding:15px;
background:#f8fbff;
border:1px solid #dfeeff;
border-radius:12px;
font-weight:600;
}

.tour-highlight-item i{
color:#0b74ff;
font-size:18px;
}


/* =========================================================
   DAY-WISE ITINERARY
========================================================= */

.itinerary-timeline{
    margin-top:22px;
    position:relative;
}

.itinerary-day{
    position:relative;
    display:grid;
    grid-template-columns:54px minmax(0,1fr);
    gap:16px;
    padding-bottom:18px;
}

.itinerary-day:last-child{
    padding-bottom:0;
}

.itinerary-day:not(:last-child)::before{
    content:'';
    width:2px;
    position:absolute;
    top:46px;
    bottom:0;
    left:26px;
    background:#dcecff;
}

.itinerary-number{
    width:54px;
    height:54px;
    position:relative;
    z-index:2;
    border:5px solid white;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:var(--blue);
    color:white;
    box-shadow:0 0 0 1px #bcd9ff;
    font-family:'Manrope',sans-serif;
    font-size:9px;
    font-weight:800;
    text-align:center;
    line-height:1.15;
}

.itinerary-day-card{
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:13px;
    background:#fbfcfe;
}

.itinerary-day-image{
    width:100%;
    height:230px;
    overflow:hidden;
    background:#edf2f7;
}

.itinerary-day-image img{
    width:100%;
    height:100%;
    object-fit:cover;
    transition:.35s;
}

.itinerary-day-card:hover .itinerary-day-image img{
    transform:scale(1.025);
}

.itinerary-day-body{
    padding:17px 18px;
}

.itinerary-day-body h3{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:15px;
    line-height:1.4;
}

.itinerary-day-body p{
    margin-top:7px;
    color:#5e6b7c;
    font-size:10px;
    line-height:1.85;
    white-space:pre-line;
}

.itinerary-content{
    margin-top:20px;
    padding-left:20px;
    border-left:2px solid #dcecff;
    color:#5e6b7c;
    font-size:11px;
    line-height:1.9;
    white-space:pre-line;
    position:relative;
}

.itinerary-content::before{
    content:'';
    width:12px;
    height:12px;
    position:absolute;
    top:3px;
    left:-7px;
    border:3px solid white;
    border-radius:50%;
    background:var(--blue);
    box-shadow:0 0 0 1px var(--blue);
}


/* =========================================================
   DESTINATION
========================================================= */

.destination-box {

    margin-top:18px;

    min-height:190px;

    padding:25px;

    border-radius:14px;

    overflow:hidden;

    position:relative;

    display:flex;

    align-items:flex-end;

    background:
        linear-gradient(
            180deg,
            rgba(3,18,34,.1),
            rgba(3,18,34,.86)
        ),
        var(--destination-bg)
        center/cover
        no-repeat;

    color:white;
}


.destination-box h3 {

    font-family:
        'Manrope',
        sans-serif;

    font-size:22px;
}


.destination-box p {

    max-width:550px;

    margin-top:5px;

    color:
        rgba(255,255,255,.75);

    font-size:9px;

    line-height:1.7;
}



/* =========================================================
   DATE-WISE HOTEL PRICING
========================================================= */

.current-price-period{
    margin-top:8px;
    color:#6f7c8d;
    font-size:12px;
    line-height:1.45;
}

.hotel-price-options{
    margin-top:13px;
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:7px;
}

.hotel-price-option{
    position:relative;
}

.hotel-price-option input{
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.hotel-price-option label{
    min-height:70px;
    padding:10px 8px;
    border:1px solid #dfe6ee;
    border-radius:10px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:3px;
    background:#fff;
    color:#657286;
    cursor:pointer;
    text-align:center;
    transition:.2s;
}

.hotel-price-option label strong{
    color:var(--navy);
    font-size:14px;
}

.hotel-price-option label span{
    font-size:10px;
    font-weight:700;
}

.hotel-price-option input:checked + label{
    border-color:var(--blue);
    background:#edf5ff;
    box-shadow:0 0 0 3px rgba(11,116,255,.08);
}

.hotel-price-option.unavailable{
    opacity:.45;
}

.hotel-price-option.unavailable label{
    cursor:not-allowed;
}

.price-unavailable-note{
    margin-top:10px;
    padding:9px 10px;
    border:1px solid #f0c7cd;
    border-radius:8px;
    background:#fff1f3;
    color:#b53a4a;
    font-size:11px;
    line-height:1.5;
}

@media(max-width:420px){
    .hotel-price-options{
        grid-template-columns:1fr;
    }

    .hotel-price-option label{
        min-height:54px;
        flex-direction:row;
        justify-content:space-between;
    }
}



.hotel-category-select-wrap{
    position:relative;
    display:flex;
    align-items:center;
}

.hotel-category-select-wrap > i:first-child{
    position:absolute;
    left:13px;
    z-index:2;
    color:var(--blue);
    pointer-events:none;
}

.hotel-category-select{
    height:50px;
    padding-left:13px;
    padding-right:38px;
    appearance:none;
    cursor:pointer;
    font-size:14px;
}

.hotel-category-arrow{
    position:absolute;
    right:14px;
    color:#758296;
    pointer-events:none;
}

.selected-hotel-price{
    margin-top:9px;
    padding:11px 12px;
    border:1px solid #dce9f8;
    border-radius:9px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    background:#f5f9ff;
}

.selected-hotel-price span{
    color:#728095;
    font-size:12px;
}

.selected-hotel-price strong{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:14px;
    text-align:right;
}

@media(max-width:420px){
    .selected-hotel-price{
        align-items:flex-start;
        flex-direction:column;
    }

    .selected-hotel-price strong{
        text-align:left;
    }
}


/* =========================================================
   BOOKING CARD
========================================================= */

.booking-column {

    position:relative;
}


.booking-card {

    position:sticky;

    top:90px;

    max-height:
        calc(100vh - 105px);

    overflow:hidden;

    display:flex;

    flex-direction:column;

    border:
        1px solid
        var(--border);

    border-radius:17px;

    background:white;

    box-shadow:
        0 18px 50px
        rgba(7,25,45,.11);
}


.booking-top {

    flex:0 0 auto;

    padding:18px 20px;

    border-bottom:
        1px solid
        var(--border);

    background:
        linear-gradient(
            135deg,
            #f8fbff,
            white
        );
}


.booking-label {

    color:#8994a4;

    font-size:8px;

    font-weight:700;

    text-transform:uppercase;
}


.regular-price {

    margin-top:5px;

    color:#9ca5b2;

    font-size:10px;

    text-decoration:line-through;
}


.price {

    margin-top:2px;

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:31px;

    font-weight:800;
}


.price small {

    color:#8490a0;

    font-family:
        'DM Sans',
        sans-serif;

    font-size:9px;

    font-weight:500;
}


.saving {

    margin-top:7px;

    display:inline-flex;

    padding:5px 8px;

    border-radius:6px;

    background:var(--green-bg);

    color:var(--green);

    font-size:8px;

    font-weight:800;
}


.booking-body {

    min-height:0;

    flex:1 1 auto;

    padding:17px 18px 0;

    overflow-y:auto;

    overscroll-behavior:contain;

    scrollbar-width:thin;

    scrollbar-color:#c7d4e2 #f1f4f8;
}

.booking-body::-webkit-scrollbar{
    width:7px;
}

.booking-body::-webkit-scrollbar-track{
    background:#f1f4f8;
}

.booking-body::-webkit-scrollbar-thumb{
    background:#c7d4e2;
    border-radius:20px;
}


.form-group {

    margin-bottom:13px;
}


.form-group label {

    display:block;

    margin-bottom:6px;

    color:#677487;

    font-size:8px;

    font-weight:800;
}


.form-control {

    width:100%;

    height:45px;

    padding:0 12px;

    border:
        1px solid
        var(--border);

    border-radius:9px;

    outline:0;

    background:white;

    color:var(--navy);

    font-size:10px;

    font-weight:600;
}


.form-control:focus {

    border-color:#9bc8ff;

    box-shadow:
        0 0 0 3px
        rgba(11,116,255,.07);
}


.traveller-grid {

    display:grid;

    grid-template-columns:1fr 1fr;

    gap:9px;
}


/* =========================================================
   CHILD AGE + CHILD PRICE
========================================================= */

.child-age-section{
    margin-top:3px;
    margin-bottom:13px;
    padding:13px;
    border:1px solid #d9e7f6;
    border-radius:10px;
    background:#f8fbff;
}

.child-age-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
}

.child-age-head strong{
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--navy);
    font-size:12px;
    font-weight:800;
}

.child-age-head strong i{
    color:var(--blue);
}

.child-age-head span{
    display:block;
    margin-top:4px;
    color:#738197;
    font-size:11px;
    line-height:1.45;
}

.child-age-head b{
    flex:0 0 auto;
    color:var(--blue);
    font-size:12px;
    white-space:nowrap;
}

.child-age-grid{
    margin-top:9px;
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
}

.child-age-item label{
    display:block;
    margin-bottom:5px;
    color:#677487;
    font-size:11px;
    font-weight:800;
}

.child-age-item select{
    width:100%;
    height:42px;
    padding:0 10px;
    border:1px solid var(--border);
    border-radius:8px;
    outline:0;
    background:#fff;
    color:var(--navy);
    font-size:13px;
    font-weight:600;
}

.child-age-item select:focus{
    border-color:#9bc8ff;
    box-shadow:0 0 0 3px rgba(11,116,255,.07);
}

@media(max-width:420px){
    .child-age-head{
        flex-direction:column;
    }

    .child-age-grid{
        grid-template-columns:1fr;
    }
}

.child-price-public-list{
    margin-top:10px;
    display:grid;
    gap:7px;
}

.child-price-public-row{
    min-height:42px;
    padding:9px 11px;
    border:1px solid #dce9f7;
    border-radius:9px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    background:#fff;
}

.child-price-public-row span{
    color:#627187;
    font-size:11px;
    font-weight:700;
}

.child-price-public-row strong{
    color:var(--blue);
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

@media(max-width:420px){
    .child-price-public-row{
        align-items:flex-start;
        flex-direction:column;
        gap:4px;
    }
}


/* =========================================================
   PRICE SUMMARY
========================================================= */

.estimate {

    margin-top:16px;

    padding:14px;

    border-radius:10px;

    background:#f6f8fb;
}


.estimate-row {

    padding:5px 0;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:10px;

    color:#718093;

    font-size:9px;
}


.estimate-row.total {

    margin-top:6px;

    padding-top:11px;

    border-top:
        1px solid
        #dfe5eb;

    color:var(--navy);

    font-size:11px;

    font-weight:800;
}


.estimate-row.total strong {

    color:var(--blue);

    font-family:
        'Manrope',
        sans-serif;

    font-size:17px;
}


/* =========================================================
   ALWAYS-VISIBLE BOOKING ACTIONS
========================================================= */

.booking-actions-sticky{
    position:sticky;
    left:0;
    right:0;
    bottom:0;
    z-index:30;
    margin:15px -18px 0;
    padding:12px 18px 15px;
    border-top:1px solid #e6ebf1;
    background:
        linear-gradient(
            180deg,
            rgba(255,255,255,.96),
            #fff 18%
        );
    box-shadow:
        0 -10px 24px
        rgba(7,25,45,.07);
}

.booking-actions-sticky .book-now{
    margin-top:0;
}

.booking-actions-sticky .enquire-now{
    margin-top:8px;
}

.booking-actions-sticky .secure-note{
    margin-top:8px;
}


/* =========================================================
   BOOK BUTTON
========================================================= */

.book-now {

    width:100%;

    min-height:50px;

    margin-top:15px;

    border:0;

    border-radius:10px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:8px;

    background:
        linear-gradient(
            135deg,
            var(--blue),
            #005dd6
        );

    color:white;

    cursor:pointer;

    font-size:10px;

    font-weight:800;

    box-shadow:
        0 10px 25px
        rgba(11,116,255,.22);

    transition:.2s;
}


.book-now:hover {

    transform:translateY(-1px);

    box-shadow:
        0 14px 28px
        rgba(11,116,255,.28);
}


.secure-note {

    margin-top:11px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:6px;

    color:#8792a1;

    font-size:8px;
}


.secure-note i {

    color:var(--green);
}


/* =========================================================
   BOOK BENEFITS
========================================================= */

.booking-benefits {

    padding:
        5px
        20px
        20px;
}


.booking-benefit {

    padding:9px 0;

    border-top:
        1px solid
        #edf0f3;

    display:flex;

    align-items:center;

    gap:9px;

    color:#667486;

    font-size:8px;
}


.booking-benefit i {

    width:26px;
    height:26px;

    border-radius:7px;

    display:grid;

    place-items:center;

    background:#edf6ff;

    color:var(--blue);
}


/* =========================================================
   RELATED
========================================================= */

.related-section {

    margin-top:45px;
}


.related-title {

    margin-bottom:17px;
}


.related-title h2 {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:24px;
}


.related-title p {

    margin-top:4px;

    color:var(--muted);

    font-size:9px;
}


.related-grid {

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:15px;
}


.related-card {

    overflow:hidden;

    border:
        1px solid
        var(--border);

    border-radius:14px;

    background:white;

    box-shadow:var(--shadow);
}


.related-image {

    height:190px;

    overflow:hidden;
}


.related-image img {

    width:100%;
    height:100%;

    object-fit:cover;

    transition:.35s;
}


.related-card:hover img {

    transform:scale(1.05);
}


.related-body {

    padding:15px;
}


.related-body h3 {

    min-height:40px;

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:14px;

    line-height:1.4;
}


.related-meta {

    margin-top:7px;

    color:#7d8999;

    font-size:8px;
}


.related-bottom {

    margin-top:12px;

    padding-top:11px;

    border-top:
        1px solid
        var(--border);

    display:flex;

    align-items:center;

    justify-content:space-between;
}


.related-price {

    color:var(--navy);

    font-size:15px;

    font-weight:800;
}


.related-link {

    color:var(--blue);

    font-size:8px;

    font-weight:800;
}


/* =========================================================
   FLASH
========================================================= */

.flash {

    max-width:1250px;

    margin:
        18px
        auto
        -10px;

    padding:0 25px;
}


.flash-box {

    min-height:47px;

    padding:0 14px;

    border-radius:9px;

    display:flex;

    align-items:center;

    gap:8px;

    font-size:9px;

    font-weight:700;
}


.flash-success {

    border:
        1px solid
        #bde7d4;

    background:#eafaf3;

    color:#08784d;
}


.flash-error {

    border:
        1px solid
        #f0c1c8;

    background:#fff0f2;

    color:#b73548;
}


/* =========================================================
   PACKAGE RATING + REVIEWS
========================================================= */

.package-rating-summary {
    margin-top:10px;
}

.package-rating-summary a,
.new-review-label {
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    border-radius:30px;
    font-size:8px;
    font-weight:700;
}

.package-rating-summary a {
    border:1px solid #f0e0b8;
    background:#fffaf0;
    color:#657286;
    transition:.2s;
}

.package-rating-summary a:hover {
    border-color:#f2c65c;
    transform:translateY(-1px);
}

.package-rating-summary strong {
    color:#172235;
    font-size:9px;
}

.package-rating-stars,
.header-stars,
.big-stars,
.review-stars {
    color:#f5a800;
}

.package-rating-stars {
    display:flex;
    gap:2px;
}

.package-rating-summary .fa-chevron-right {
    margin-left:2px;
    color:#a2aab4;
    font-size:6px;
}

.new-review-label {
    background:#f6f8fb;
    color:#8290a0;
}

.review-section {
    scroll-margin-top:90px;
}

.review-section-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}

.review-eyebrow {
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:7px;
    color:#087952;
    font-size:7px;
    font-weight:800;
    letter-spacing:.7px;
}

.review-header-score {
    flex:none;
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 13px;
    border:1px solid #ece2c6;
    border-radius:12px;
    background:#fffaf0;
}

.review-header-score > strong {
    color:#07192d;
    font-family:'Manrope',sans-serif;
    font-size:25px;
    line-height:1;
}

.header-stars {
    font-size:9px;
    letter-spacing:1px;
}

.review-header-score span {
    display:block;
    margin-top:3px;
    color:#8994a2;
    font-size:7px;
}

.review-overview {
    display:grid;
    grid-template-columns:190px 1fr;
    gap:28px;
    margin-bottom:24px;
    padding:20px;
    border:1px solid #e7ebf0;
    border-radius:14px;
    background:linear-gradient(135deg,#fbfcfe,#f7f9fc);
}

.big-rating {
    padding-right:25px;
    border-right:1px solid #e4e9ef;
    text-align:center;
}

.big-rating > strong {
    display:block;
    color:#07192d;
    font-family:'Manrope',sans-serif;
    font-size:43px;
    line-height:1;
}

.big-stars {
    margin-top:8px;
    font-size:14px;
    letter-spacing:2px;
}

.big-rating > span {
    display:block;
    margin-top:7px;
    color:#8995a3;
    font-size:7px;
    line-height:1.6;
}

.rating-bars {
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
}

.rating-row {
    display:grid;
    grid-template-columns:38px 1fr 28px;
    align-items:center;
    gap:8px;
}

.rating-label {
    color:#667487;
    font-size:8px;
    font-weight:700;
}

.rating-label i {
    color:#f5a800;
    font-size:7px;
}

.rating-track {
    height:7px;
    overflow:hidden;
    border-radius:20px;
    background:#e5e9ee;
}

.rating-fill {
    height:100%;
    border-radius:20px;
    background:linear-gradient(90deg,#ffb20c,#f39400);
}

.rating-count {
    color:#8793a1;
    font-size:7px;
    text-align:right;
}

.review-list {
    display:flex;
    flex-direction:column;
    gap:13px;
}

.traveller-review {
    position:relative;
    padding:18px;
    border:1px solid #e4e9ef;
    border-radius:14px;
    background:#fff;
    transition:.2s;
}

.traveller-review:hover {
    border-color:#ced9e4;
    box-shadow:0 10px 30px rgba(7,25,45,.05);
}

.featured-review {
    border-color:#f0d89a;
    background:linear-gradient(135deg,#fffdf8,#fff);
}

.featured-review-label {
    position:absolute;
    top:0;
    right:18px;
    padding:5px 9px;
    border-radius:0 0 8px 8px;
    background:#fff2c9;
    color:#a66a00;
    font-size:6px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.5px;
}

.review-customer-row {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
}

.review-customer {
    display:flex;
    align-items:center;
    gap:10px;
}

.review-avatar {
    width:43px;
    height:43px;
    flex:none;
    overflow:hidden;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg,#e6f2ff,#f2f8ff);
    color:#0b74ff;
    font-family:'Manrope',sans-serif;
    font-size:14px;
    font-weight:800;
}

.review-avatar img {
    width:100%;
    height:100%;
    object-fit:cover;
}

.review-customer h3 {
    color:#07192d;
    font-family:'Manrope',sans-serif;
    font-size:10px;
}

.review-verification {
    margin-top:4px;
    display:flex;
    align-items:center;
    gap:5px;
    flex-wrap:wrap;
}

.verified-badge {
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:4px 7px;
    border-radius:20px;
    background:#e9f9f2;
    color:#087952;
    font-size:6px;
    font-weight:800;
}

.booking-reference {
    color:#9aa4af;
    font-size:6px;
}

.review-date {
    padding-top:3px;
    color:#98a2ae;
    font-size:7px;
}

.review-rating-row {
    margin-top:13px;
    display:flex;
    align-items:center;
    gap:7px;
}

.review-stars {
    font-size:10px;
    letter-spacing:1px;
}

.review-rating-row > strong {
    color:#59687a;
    font-size:8px;
}

.traveller-review-title {
    margin-top:10px;
    color:#172235;
    font-family:'Manrope',sans-serif;
    font-size:11px;
}

.traveller-review-text {
    margin-top:6px;
    color:#657386;
    font-size:8px;
    line-height:1.8;
}

.official-response {
    margin-top:14px;
    padding:13px;
    border-left:3px solid #0b74ff;
    border-radius:9px;
    background:#f2f7ff;
}

.official-response-head {
    display:flex;
    align-items:center;
    gap:8px;
}

.official-logo {
    width:29px;
    height:29px;
    flex:none;
    border-radius:8px;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg,#0b74ff,#00a9ed);
    color:#fff;
    font-size:9px;
}

.official-response-head strong {
    display:block;
    color:#0b5fbf;
    font-size:8px;
}

.official-response-head span {
    display:block;
    margin-top:2px;
    color:#93a1b0;
    font-size:6px;
}

.official-response p {
    margin-top:8px;
    color:#5f7185;
    font-size:8px;
    line-height:1.7;
}

.review-trust-note {
    margin-top:17px;
    padding:13px;
    border-radius:10px;
    display:flex;
    align-items:flex-start;
    gap:10px;
    background:#f4faf7;
    color:#087952;
}

.review-trust-note > i {
    margin-top:2px;
    font-size:15px;
}

.review-trust-note strong {
    display:block;
    font-size:8px;
}

.review-trust-note span {
    display:block;
    margin-top:3px;
    color:#72867d;
    font-size:7px;
    line-height:1.6;
}

.no-reviews {
    padding:35px 20px 20px;
    text-align:center;
}

.no-review-icon {
    width:65px;
    height:65px;
    margin:0 auto 13px;
    border-radius:18px;
    display:grid;
    place-items:center;
    background:#fff6dc;
    color:#e89b00;
    font-size:24px;
}

.no-reviews h3 {
    color:#07192d;
    font-family:'Manrope',sans-serif;
    font-size:14px;
}

.no-reviews p {
    max-width:480px;
    margin:7px auto 15px;
    color:#8390a0;
    font-size:8px;
    line-height:1.7;
}

.first-review-book {
    min-height:39px;
    padding:0 13px;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    background:#0b74ff;
    color:#fff;
    font-size:8px;
    font-weight:800;
}

@media(max-width:700px) {

    .review-section-header {
        flex-direction:column;
    }

    .review-header-score {
        width:100%;
    }

    .review-overview {
        grid-template-columns:1fr;
        gap:18px;
    }

    .big-rating {
        padding-right:0;
        padding-bottom:18px;
        border-right:0;
        border-bottom:1px solid #e4e9ef;
    }

    .review-customer-row {
        flex-direction:column;
    }

    .review-date {
        padding-top:0;
    }
}


/* =========================================================
   ENQUIRY BEFORE BOOKING
========================================================= */
.enquire-now,.mobile-enquire-button{border:0;display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,#ff8a00,#ff4d4d);color:#fff;cursor:pointer;font-weight:800;box-shadow:0 10px 25px rgba(255,100,50,.25);transition:.2s}
.enquire-now{width:100%;min-height:50px;margin-top:10px;border-radius:10px;font-size:10px}
.mobile-enquire-button{min-height:47px;padding:0 13px;border-radius:9px;font-size:8px}
.enquire-now:hover,.mobile-enquire-button:hover{transform:translateY(-1px);box-shadow:0 14px 28px rgba(255,100,50,.32)}
@media(max-width:700px){.mobile-book-bar{gap:7px}.mobile-book-button{min-width:0!important;flex:1;padding:0 11px}.mobile-enquire-button{flex:1}.mobile-price{flex:0 0 auto}}

/* =========================================================
   PHOTO MODAL
========================================================= */

.modal {

    display:none;

    position:fixed;

    inset:0;

    z-index:3000;

    padding:25px;

    align-items:center;

    justify-content:center;

    background:
        rgba(0,0,0,.92);
}


.modal.open {

    display:flex;
}


.modal-image {

    max-width:95%;
    max-height:90vh;

    object-fit:contain;

    border-radius:10px;
}


.modal-close {

    width:43px;
    height:43px;

    position:absolute;

    top:20px;
    right:20px;

    border:0;

    border-radius:50%;

    display:grid;

    place-items:center;

    background:white;

    color:var(--navy);

    cursor:pointer;

    font-size:16px;
}


.modal-arrow {

    width:45px;
    height:45px;

    position:absolute;

    top:50%;

    border:0;

    border-radius:50%;

    display:grid;

    place-items:center;

    background:
        rgba(255,255,255,.92);

    cursor:pointer;

    transform:
        translateY(-50%);
}


.modal-prev {

    left:20px;
}


.modal-next {

    right:20px;
}


/* =========================================================
   MOBILE BOOK BAR
========================================================= */

.mobile-book-bar {

    display:none;
}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    padding:
        30px
        5%;

    background:var(--navy);

    color:#8ea0b2;
}


.footer-inner {

    max-width:1250px;

    margin:auto;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;
}


.footer strong {

    color:white;

    font-family:
        'Manrope',
        sans-serif;
}


.footer p {

    font-size:9px;
}


/* =========================================================
   RESPONSIVE
========================================================= */


@media(max-height:820px) and (min-width:701px){

    .booking-card{
        top:82px;
        max-height:calc(100vh - 94px);
    }

    .booking-top{
        padding:13px 17px;
    }

    .booking-body{
        padding:13px 15px 0;
    }

    .form-group{
        margin-bottom:9px;
    }

    .form-control{
        height:40px;
    }

    .child-age-section{
        padding:10px;
        margin-bottom:9px;
    }

    .child-age-grid{
        margin-top:7px;
    }

    .child-age-item select{
        height:38px;
    }

    .estimate{
        margin-top:10px;
        padding:10px 12px;
    }

    .booking-actions-sticky{
        margin:11px -15px 0;
        padding:9px 15px 11px;
    }

    .book-now,
    .enquire-now{
        min-height:44px;
    }

    .booking-benefits{
        display:none;
    }
}


@media(max-width:1050px) {

    .nav {

        display:none;
    }


    .layout {

        grid-template-columns:1fr;
    }


    .booking-card {

        position:static;

        max-height:none;
    }

    .booking-body{
        overflow:visible;
    }

    .booking-actions-sticky{
        position:sticky;
        bottom:8px;
    }


    .quick-info {

        grid-template-columns:
            repeat(2,1fr);
    }
}


@media(max-width:700px) {

    body {

        padding-bottom:75px;
    }


    .header {

        height:68px;

        padding:0 15px;
    }


    .logo {

        font-size:17px;
    }


    .logo-icon {

        width:37px;
        height:37px;
    }


    .header-btn.light {

        display:none;
    }


    .header-btn.blue span {

        display:none;
    }


    .breadcrumb {

        padding:0 15px;
    }


    .gallery-wrap {

        padding:
            14px
            14px
            0;
    }


    .gallery {

        height:360px;

        grid-template-columns:1fr;
    }


    .gallery-side {

        display:none;
    }


    .container {

        padding:
            23px
            14px
            50px;
    }


    .title-row {

        gap:10px;
    }


    .package-title {

        font-size:29px;
    }


    .quick-info {

        grid-template-columns:
            1fr 1fr;
    }


    .quick-item {

        min-height:70px;

        padding:10px;
    }


    .quick-icon {

        width:32px;
        height:32px;

        flex-basis:32px;
    }


    .content-card {

        padding:18px;
    }


    .include-grid {

        grid-template-columns:1fr;
    }


    .booking-column {

        display:none;
    }


    .related-grid {

        grid-template-columns:1fr;
    }


    .related-image {

        height:230px;
    }


    .mobile-book-bar {

        height:70px;

        padding:
            9px
            13px;

        position:fixed;

        left:0;
        right:0;
        bottom:0;

        z-index:1500;

        display:flex;

        align-items:center;

        justify-content:space-between;

        gap:12px;

        background:white;

        border-top:
            1px solid
            var(--border);

        box-shadow:
            0 -8px 25px
            rgba(7,25,45,.09);
    }


    .mobile-price small {

        display:block;

        color:#8b95a4;

        font-size:7px;
    }


    .mobile-price strong {

        color:var(--navy);

        font-size:17px;
    }


    .mobile-book-button {

        min-width:155px;

        min-height:47px;

        border:0;

        border-radius:9px;

        display:flex;

        align-items:center;

        justify-content:center;

        gap:6px;

        background:var(--blue);

        color:white;

        cursor:pointer;

        font-size:9px;

        font-weight:800;
    }


    .footer-inner {

        align-items:flex-start;

        flex-direction:column;
    }
}


@media(max-width:600px){
    .itinerary-day{
        grid-template-columns:42px minmax(0,1fr);
        gap:10px;
    }

    .itinerary-number{
        width:42px;
        height:42px;
        border-width:4px;
        font-size:7px;
    }

    .itinerary-day:not(:last-child)::before{
        left:20px;
        top:38px;
    }

    .itinerary-day-image{
        height:175px;
    }

    .itinerary-day-body{
        padding:14px;
    }
}


/* =========================================================
   PROFESSIONAL RESPONSIVE TYPOGRAPHY OVERRIDES
   Improves readability on desktop, tablet and mobile.
========================================================= */

body{
    font-size:16px;
    line-height:1.65;
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
}

.nav a{font-size:14px;}
.header-btn{font-size:13px;}
.breadcrumb{font-size:13px; line-height:1.5;}
.breadcrumb i{font-size:9px;}
.view-photos{font-size:13px;}

.badge{
    font-size:11px;
    line-height:1.2;
    padding:7px 11px;
}

.destination{font-size:15px; line-height:1.55;}

.quick-item small{
    font-size:11px;
    line-height:1.35;
    letter-spacing:.35px;
}

.quick-item strong{
    font-size:15px;
    line-height:1.45;
}

.content-card h2{
    font-size:clamp(22px,2.4vw,28px);
    line-height:1.3;
    font-weight:800;
}

.section-sub{
    margin-top:7px;
    font-size:15px;
    line-height:1.65;
}

.description{
    margin-top:18px;
    font-size:16px;
    line-height:1.85;
    color:#4f5d70;
}

.include-item{
    min-height:54px;
    padding:13px 15px;
    font-size:15px;
    line-height:1.65;
    font-weight:500;
}

.include-item i{font-size:16px; margin-top:4px;}

.tour-highlight-item{
    min-height:58px;
    font-size:15px;
    line-height:1.55;
    font-weight:600;
}

.itinerary-number{font-size:11px;}
.itinerary-day-body h3{font-size:19px; line-height:1.45;}
.itinerary-day-body p,
.itinerary-content{font-size:16px; line-height:1.8;}

.destination-box p{
    font-size:15px;
    line-height:1.75;
}

.booking-label,
.form-group label{
    font-size:12px;
    line-height:1.4;
}

.regular-price{font-size:14px;}
.price small{font-size:13px;}
.saving{font-size:12px;}
.form-control{font-size:15px;}
.estimate-row{font-size:14px;}
.estimate-row.total{font-size:16px;}
.book-now,.enquire-now{font-size:15px;}
.secure-note{font-size:12px; line-height:1.5;}
.booking-benefit{font-size:13px; line-height:1.55;}

.related-title p{font-size:15px;}
.related-body h3{font-size:18px;}
.related-meta{font-size:13px;}
.related-price{font-size:18px;}
.related-link{font-size:13px;}
.flash-box{font-size:14px; line-height:1.5;}

.package-rating-summary a,
.new-review-label{font-size:13px; line-height:1.45;}
.package-rating-summary strong{font-size:14px;}
.share-menu-title{font-size:14px;}
.share-option{font-size:12px;}
.share-copy-status{font-size:12px;}

.review-eyebrow{font-size:11px;}
.header-stars{font-size:12px;}
.review-header-score span{font-size:12px;}
.big-rating > span{font-size:13px;}
.rating-label{font-size:13px;}
.rating-count{font-size:12px;}
.featured-review-label{font-size:10px;}
.review-customer h3{font-size:15px;}
.verified-badge{font-size:11px;}
.booking-reference,.review-date{font-size:12px;}
.review-rating-row > strong{font-size:13px;}
.traveller-review-title{font-size:17px;}
.traveller-review-text{font-size:15px; line-height:1.75;}
.official-response-head strong{font-size:13px;}
.official-response-head span{font-size:11px;}
.official-response p{font-size:14px; line-height:1.7;}
.review-trust-note strong{font-size:13px;}
.review-trust-note span{font-size:12px;}
.no-reviews h3{font-size:18px;}
.no-reviews p{font-size:14px;}
.first-review-book{font-size:13px;}

.footer p{font-size:13px; line-height:1.6;}

/* Important Information: structured, bold and easy to scan */
.important-information-content{
    margin-top:20px;
    padding:22px;
    border:1px solid #dce9f8;
    border-left:5px solid var(--blue);
    border-radius:13px;
    background:linear-gradient(135deg,#f8fbff,#ffffff);
    color:#435166;
    font-size:16px;
    line-height:1.8;
}

.important-information-content p{
    margin:0 0 14px;
}

.important-information-content p:last-child{
    margin-bottom:0;
}

.information-subheading{
    margin:24px 0 12px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:18px;
    font-weight:800;
    line-height:1.4;
}

.information-subheading:first-child{
    margin-top:0;
}

.information-point{
    display:grid;
    grid-template-columns:34px minmax(0,1fr);
    align-items:start;
    gap:11px;
    margin:10px 0;
    padding:12px 14px;
    border:1px solid #e4edf7;
    border-radius:10px;
    background:#fff;
}

.information-marker{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#eaf4ff;
    color:var(--blue);
    font-size:13px;
    font-weight:800;
}

.information-bullet .information-marker{
    background:#e9faf3;
    color:var(--green);
}

.information-point-text{
    padding-top:1px;
    color:#405066;
    font-size:16px;
    line-height:1.7;
    font-weight:500;
}

@media(max-width:700px){
    body{font-size:16px;}
    .breadcrumb{font-size:12px;}
    .badge{font-size:10px;}
    .destination{font-size:14px;}
    .quick-item small{font-size:10px;}
    .quick-item strong{font-size:14px;}
    .content-card{padding:20px 17px;}
    .content-card h2{font-size:22px;}
    .section-sub{font-size:14px;}
    .description,
    .itinerary-day-body p,
    .itinerary-content,
    .include-item,
    .tour-highlight-item,
    .important-information-content,
    .information-point-text{font-size:15px;}
    .itinerary-day-body h3{font-size:18px;}
    .destination-box p{font-size:14px;}
    .mobile-price small{font-size:11px;}
    .mobile-book-button,.mobile-enquire-button{font-size:13px;}
    .important-information-content{padding:17px 14px;}
    .information-subheading{font-size:17px;}
    .information-point{
        grid-template-columns:30px minmax(0,1fr);
        gap:9px;
        padding:11px;
    }
    .information-marker{width:26px;height:26px;font-size:12px;}
}

@media(max-width:380px){
    .package-title{font-size:26px;}
    .quick-info{grid-template-columns:1fr;}
    .tour-highlights{grid-template-columns:1fr;}
    .content-card{padding:18px 14px;}
}




/* ============================================================
   TRAVSCOPE UNIFIED PROFESSIONAL PUBLIC UI
   Visual-only override for a clean international travel website.
============================================================ */
:root{
    --ts-navy:#0b1f36;
    --ts-blue:#2563eb;
    --ts-blue-hover:#1d4ed8;
    --ts-cyan:#0ea5e9;
    --ts-bg:#f6f8fb;
    --ts-surface:#ffffff;
    --ts-soft:#f8fafc;
    --ts-text:#1e293b;
    --ts-muted:#64748b;
    --ts-border:#e2e8f0;
    --ts-success:#15803d;
    --ts-danger:#b91c1c;
    --ts-radius:12px;
    --ts-shadow:0 8px 24px rgba(15,23,42,.06);
    --ts-shadow-hover:0 12px 30px rgba(15,23,42,.10);
    --ts-focus:0 0 0 3px rgba(37,99,235,.14);
}
html{-webkit-text-size-adjust:100%;text-rendering:optimizeLegibility}
body{
    color:var(--ts-text)!important;
    font-family:"DM Sans",Arial,Helvetica,sans-serif!important;
    font-size:14px!important;
    line-height:1.55!important;
}
button,input,select,textarea{font:inherit}
a,button,input,select,textarea{-webkit-tap-highlight-color:transparent}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{
    outline:none!important;
    box-shadow:var(--ts-focus)!important;
}

/* Global brand/header */
.header,.site-header,.navbar,.topbar{
    border-bottom:1px solid var(--ts-border)!important;
    box-shadow:0 1px 0 rgba(15,23,42,.02)!important;
}
.logo,.brand,.site-logo{
    font-weight:800!important;
    letter-spacing:-.4px!important;
}
.nav a,.navbar a,.menu a{
    font-size:13px!important;
    font-weight:700!important;
    border-radius:8px!important;
}

/* Headings */
h1,h2,h3,h4{
    color:var(--ts-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif!important;
}
h1{letter-spacing:-.7px}
h2{letter-spacing:-.4px}

/* Cards */
.card,.package-card,.booking-card,.review-card,.blog-card,.wishlist-card,.profile-card,.section,.panel{
    border-color:var(--ts-border)!important;
    border-radius:var(--ts-radius)!important;
    box-shadow:var(--ts-shadow)!important;
}
.card:hover,.package-card:hover,.blog-card:hover,.wishlist-card:hover{
    box-shadow:var(--ts-shadow-hover)!important;
}

/* Forms */
label,.form-group label,.field label{
    color:#334155!important;
    font-size:12px!important;
    font-weight:700!important;
}
input:not([type="checkbox"]):not([type="radio"]),select,textarea,.form-control,.control{
    border:1px solid #cbd5e1!important;
    border-radius:8px!important;
    color:var(--ts-text)!important;
    font-size:13px!important;
}
input:not([type="checkbox"]):not([type="radio"]):focus,select:focus,textarea:focus,.form-control:focus,.control:focus{
    border-color:#60a5fa!important;
    box-shadow:var(--ts-focus)!important;
    outline:none!important;
}
input::placeholder,textarea::placeholder{color:#94a3b8!important}

/* Buttons */
.btn,.button,.cta,.submit-btn,.book-btn,.login-btn,.register-btn{
    border-radius:8px!important;
    font-size:12px!important;
    font-weight:800!important;
}
.btn-primary,.button.primary,.cta,.submit-btn,.book-btn{
    background:var(--ts-blue)!important;
    border-color:var(--ts-blue)!important;
    color:#fff!important;
}
.btn-primary:hover,.button.primary:hover,.cta:hover,.submit-btn:hover,.book-btn:hover{
    background:var(--ts-blue-hover)!important;
    border-color:var(--ts-blue-hover)!important;
}

/* Tables / account history */
table{font-size:12px!important}
th{
    background:var(--ts-soft)!important;
    color:#64748b!important;
    font-size:11px!important;
    font-weight:800!important;
}
td{font-size:12px!important}

/* Status / alerts */
.badge,.status,.tag,.chip{
    border-radius:999px!important;
    font-size:10px!important;
    font-weight:800!important;
}
.alert{border-radius:9px!important;font-size:12px!important}

/* Readable supporting text */
small,.helper,.help,.muted,.subtext{font-size:11px!important;color:var(--ts-muted)!important}

/* Responsive touch sizes */
@media(max-width:700px){
    body{font-size:14px!important}
    input:not([type="checkbox"]):not([type="radio"]),select,textarea,.form-control,.control{
        min-height:44px!important;
        font-size:14px!important;
    }
    .btn,.button,.cta,.submit-btn,.book-btn,.login-btn,.register-btn{
        min-height:42px!important;
        font-size:12px!important;
    }
}


/* V24.40 – editorial day-wise journey, full uncropped photos and compact copy.
   Scoped to the public package itinerary; other page modules are untouched. */
.itinerary-timeline .itinerary-day{
    padding-bottom:21px;
    align-items:start;
}
.itinerary-timeline .itinerary-day-card{
    display:flow-root;
    min-width:0;
    padding:17px;
    overflow:visible;
    border:1px solid #e2eaf4;
    border-radius:18px;
    background:#fff;
    box-shadow:0 9px 24px rgba(17,42,76,.045);
}
.itinerary-timeline .itinerary-day-media{
    float:left;
    width:44%;
    max-width:430px;
    min-width:0;
    margin:0 20px 10px 0;
}
.itinerary-timeline .itinerary-day-media.is-unavailable{
    display:none; /* A missing/broken image must not create a giant empty block. */
}
.itinerary-timeline .itinerary-day-image{
    display:flex;
    align-items:center;
    justify-content:center;
    width:100%;
    height:auto;
    min-height:0;
    border-radius:12px;
    overflow:hidden;
    background:#f0f5fa;
    text-decoration:none;
}
.itinerary-timeline .itinerary-day-image img{
    display:block;
    width:100%;
    height:auto;
    max-height:360px;
    object-fit:contain; /* preserve the entire image, no cover/cropping */
    object-position:center;
    transition:none;
}
.itinerary-timeline .itinerary-day-card:hover .itinerary-day-image img{
    transform:none;
}
.itinerary-timeline .itinerary-day-image:focus-visible{
    outline:3px solid #3b82f6;
    outline-offset:2px;
}
.itinerary-timeline .itinerary-day-media-caption{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    justify-content:space-between;
    gap:5px 9px;
    padding:7px 2px 0;
    color:#718196;
    font-size:10px;
    line-height:1.4;
}
.itinerary-timeline .itinerary-day-media-caption .itinerary-photo-attribution{
    flex:1 1 auto;
    width:auto;
    min-width:0;
    min-height:0;
    margin:0;
    padding:0;
    justify-content:flex-start;
    font-size:10px;
    text-align:left;
}
.itinerary-timeline .itinerary-photo-open{
    margin-left:auto;
    color:#1866be;
    font-weight:750;
    text-decoration:none;
    white-space:nowrap;
}
.itinerary-timeline .itinerary-photo-open:hover{
    text-decoration:underline;
}
.itinerary-timeline .itinerary-day-body{
    display:block;
    min-width:0;
    padding:1px 1px 3px;
}
.itinerary-timeline .itinerary-day-eyebrow{
    display:block;
    margin:0 0 6px;
    color:#1878b5;
    font-size:10px;
    font-weight:850;
    line-height:1.5;
    letter-spacing:.075em;
}
.itinerary-timeline .itinerary-day-body h3{
    margin:0 0 8px;
    color:#15304f;
    font-size:clamp(17px,1.5vw,21px);
    font-weight:850;
    line-height:1.35;
}
.itinerary-timeline .itinerary-day-body p{
    margin:0;
    color:#44566d;
    font-size:14px;
    font-weight:460;
    line-height:1.65;
    white-space:pre-line;
    overflow-wrap:anywhere;
}
.itinerary-timeline .itinerary-day-meta{
    margin-top:10px;
    padding-top:9px;
    border-top:1px solid #e6edf5;
    color:#264c72;
    font-size:12px;
    font-weight:650;
    line-height:1.6;
    white-space:pre-line;
    overflow-wrap:anywhere;
}
.itinerary-timeline .itinerary-day-card--text-only{
    border-left:4px solid #36a0d9;
}
/* V24.92 – premium aligned itinerary cards.
   Photo and narrative sit in two clean columns; route facts span the full card.
   Narrative copy is justified for a brochure-style professional reading rhythm. */
.itinerary-timeline .itinerary-day-card{
    display:grid;
    grid-template-columns:minmax(220px,42%) minmax(0,1fr);
    column-gap:20px;
    row-gap:0;
    align-items:start;
}
.itinerary-timeline .itinerary-day-media{
    float:none;
    width:auto;
    max-width:none;
    margin:0;
    grid-column:1;
    grid-row:1;
}
.itinerary-timeline .itinerary-day-body{
    grid-column:2;
    grid-row:1;
    padding:0 1px 2px;
}
.itinerary-timeline .itinerary-day-body p{
    text-align:justify;
    text-justify:inter-word;
    text-align-last:left;
    hyphens:auto;
    -webkit-hyphens:auto;
    letter-spacing:.002em;
}
.itinerary-timeline .itinerary-day-meta{
    grid-column:1 / -1;
    grid-row:2;
    width:100%;
    margin-top:12px;
    padding-top:10px;
    border-top:1px solid #e6edf5;
}
.itinerary-timeline .itinerary-day-card--text-only{
    grid-template-columns:minmax(0,1fr);
}
.itinerary-timeline .itinerary-day-card--text-only .itinerary-day-body,
.itinerary-timeline .itinerary-day-card--text-only .itinerary-day-meta{
    grid-column:1;
}

@media(max-width:650px){
    .itinerary-timeline .itinerary-day{
        gap:10px;
        padding-bottom:16px;
    }
    .itinerary-timeline .itinerary-day-card{
        padding:12px;
        border-radius:14px;
    }
    .itinerary-timeline .itinerary-day-card{
        grid-template-columns:minmax(0,1fr);
        row-gap:12px;
    }
    .itinerary-timeline .itinerary-day-media{
        width:100%;
        max-width:none;
        margin:0;
        grid-column:1;
        grid-row:1;
    }
    .itinerary-timeline .itinerary-day-body{
        grid-column:1;
        grid-row:2;
    }
    .itinerary-timeline .itinerary-day-meta{
        grid-column:1;
        grid-row:3;
        margin-top:0;
    }
    .itinerary-timeline .itinerary-day-image img{
        max-height:340px;
    }
    .itinerary-timeline .itinerary-day-body p{
        font-size:14px;
        line-height:1.62;
        text-align:justify;
        text-justify:inter-word;
        text-align-last:left;
    }
}

/* V24.93 – international-standard spacing and route-fact rhythm.
   Keeps the V24.92 visual structure while removing excess whitespace,
   balancing image/text proportions and turning route facts into compact
   readable segments. */
.itinerary-timeline{
    margin-top:18px;
}
.itinerary-timeline .itinerary-day{
    grid-template-columns:48px minmax(0,1fr);
    gap:13px;
    padding-bottom:16px;
}
.itinerary-timeline .itinerary-day:not(:last-child)::before{
    left:23px;
    top:43px;
    width:1px;
    background:#d9e7f5;
}
.itinerary-timeline .itinerary-number{
    width:48px;
    height:48px;
    border-width:4px;
    font-size:8px;
    box-shadow:0 0 0 1px #bdd7f2,0 4px 10px rgba(13,98,196,.08);
}
.itinerary-timeline .itinerary-day-card{
    grid-template-columns:minmax(210px,38%) minmax(0,1fr);
    column-gap:16px;
    padding:14px 15px 13px;
    border-radius:15px;
    border-color:#dfe8f2;
    box-shadow:0 8px 24px rgba(17,42,76,.04);
}
.itinerary-timeline .itinerary-day-media{
    align-self:start;
}
.itinerary-timeline .itinerary-day-image{
    border-radius:10px;
    background:#f5f8fb;
}
.itinerary-timeline .itinerary-day-image img{
    max-height:205px;
}
.itinerary-timeline .itinerary-day-media-caption{
    gap:3px 8px;
    padding:5px 1px 0;
    font-size:9px;
    line-height:1.3;
}
.itinerary-timeline .itinerary-day-media-caption .itinerary-photo-attribution{
    font-size:9px;
}
.itinerary-timeline .itinerary-photo-open{
    font-size:9px;
}
.itinerary-timeline .itinerary-day-body{
    padding:0;
}
.itinerary-timeline .itinerary-day-eyebrow{
    margin-bottom:3px;
    font-size:9px;
    line-height:1.35;
    letter-spacing:.065em;
}
.itinerary-timeline .itinerary-day-body h3{
    margin-bottom:5px;
    font-size:clamp(17px,1.32vw,20px);
    line-height:1.3;
    letter-spacing:-.012em;
    text-wrap:balance;
}
.itinerary-timeline .itinerary-day-body p{
    color:#3f5167;
    font-size:13.5px;
    line-height:1.58;
    letter-spacing:0;
    word-spacing:.015em;
    text-align:justify;
    text-justify:inter-word;
    text-align-last:left;
    hyphens:none;
    -webkit-hyphens:none;
}
.itinerary-timeline .itinerary-day-meta{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:5px 0;
    margin-top:8px;
    padding-top:8px;
    border-top:1px solid #e8eef5;
    color:#355574;
    font-size:11px;
    font-weight:650;
    line-height:1.42;
    white-space:normal;
}
.itinerary-timeline .itinerary-day-meta-item{
    display:inline-flex;
    align-items:center;
    min-width:0;
}
.itinerary-timeline .itinerary-day-meta-item:not(:last-child){
    margin-right:9px;
    padding-right:9px;
    border-right:1px solid #cbd9e7;
}
.itinerary-timeline .itinerary-day-card--text-only{
    grid-template-columns:minmax(0,1fr);
}

@media(max-width:900px) and (min-width:721px){
    .itinerary-timeline .itinerary-day-card{
        grid-template-columns:minmax(200px,40%) minmax(0,1fr);
        column-gap:14px;
    }
    .itinerary-timeline .itinerary-day-image img{
        max-height:190px;
    }
    .itinerary-timeline .itinerary-day-body p{
        font-size:13px;
        line-height:1.55;
    }
}

@media(max-width:720px){
    .itinerary-timeline{
        margin-top:14px;
    }
    .itinerary-timeline .itinerary-day{
        grid-template-columns:40px minmax(0,1fr);
        gap:8px;
        padding-bottom:13px;
    }
    .itinerary-timeline .itinerary-number{
        width:40px;
        height:40px;
        border-width:3px;
        font-size:7px;
    }
    .itinerary-timeline .itinerary-day:not(:last-child)::before{
        left:19px;
        top:36px;
    }
    .itinerary-timeline .itinerary-day-card{
        grid-template-columns:minmax(0,1fr);
        row-gap:9px;
        padding:10px;
        border-radius:12px;
    }
    .itinerary-timeline .itinerary-day-media{
        grid-column:1;
        grid-row:1;
    }
    .itinerary-timeline .itinerary-day-body{
        grid-column:1;
        grid-row:2;
    }
    .itinerary-timeline .itinerary-day-meta{
        grid-column:1;
        grid-row:3;
        margin-top:0;
        padding-top:7px;
        font-size:10.5px;
    }
    .itinerary-timeline .itinerary-day-image img{
        max-height:260px;
    }
    .itinerary-timeline .itinerary-day-body h3{
        margin-bottom:4px;
        font-size:17px;
        line-height:1.3;
    }
    .itinerary-timeline .itinerary-day-body p{
        font-size:13px;
        line-height:1.56;
    }
    .itinerary-timeline .itinerary-day-meta-item:not(:last-child){
        margin-right:7px;
        padding-right:7px;
    }
}

/* V24.94 – premium editorial itinerary presentation.
   The day heading spans the card; the image floats into the narrative so long
   text naturally continues full-width below the photo, matching high-end tour
   operator brochures without leaving an empty right-column gutter. */
.itinerary-timeline .itinerary-day-card{
    display:block;
    padding:14px 15px 13px;
    overflow:hidden;
}
.itinerary-timeline .itinerary-day-heading{
    display:block;
    margin:0 0 8px;
    min-width:0;
}
.itinerary-timeline .itinerary-day-heading .itinerary-day-eyebrow{
    margin:0 0 3px;
}
.itinerary-timeline .itinerary-day-heading h3{
    margin:0;
    color:#102c49;
    font-size:clamp(18px,1.42vw,21px);
    font-weight:850;
    line-height:1.3;
    letter-spacing:-.014em;
    text-wrap:pretty;
}
.itinerary-timeline .itinerary-day-flow{
    display:flow-root;
    min-width:0;
}
.itinerary-timeline .itinerary-day-media{
    float:left;
    width:min(42%,310px);
    max-width:310px;
    margin:0 16px 7px 0;
    grid-column:auto;
    grid-row:auto;
}
.itinerary-timeline .itinerary-day-media.is-unavailable{
    display:none;
}
.itinerary-timeline .itinerary-day-image{
    width:100%;
    border-radius:10px;
    overflow:hidden;
    background:#f3f7fb;
}
.itinerary-timeline .itinerary-day-image img{
    width:100%;
    height:auto;
    max-height:225px;
    object-fit:contain;
    object-position:center;
}
.itinerary-timeline .itinerary-day-media-caption{
    padding:5px 1px 0;
    gap:3px 8px;
    font-size:9px;
    line-height:1.3;
}
.itinerary-timeline .itinerary-day-body{
    display:block;
    min-width:0;
    padding:0;
    grid-column:auto;
    grid-row:auto;
}
.itinerary-timeline .itinerary-day-body p{
    margin:0;
    color:#3c4e63;
    font-size:13.6px;
    font-weight:450;
    line-height:1.62;
    letter-spacing:.001em;
    word-spacing:.018em;
    text-align:justify;
    text-justify:inter-word;
    text-align-last:left;
    white-space:pre-line;
    overflow-wrap:break-word;
    hyphens:none;
    -webkit-hyphens:none;
}
.itinerary-timeline .itinerary-day-meta{
    clear:both;
    display:flex;
    flex-wrap:wrap;
    width:100%;
    margin-top:9px;
    padding:8px 0 0;
    border-top:1px solid #e7eef5;
    color:#335574;
    font-size:10.8px;
    font-weight:650;
    line-height:1.45;
}
.itinerary-timeline .itinerary-day-card--text-only .itinerary-day-flow{
    display:block;
}

@media(min-width:1100px){
    .itinerary-timeline .itinerary-day-media{
        width:min(40%,325px);
        max-width:325px;
        margin-right:18px;
    }
    .itinerary-timeline .itinerary-day-body p{
        font-size:13.8px;
        line-height:1.64;
    }
}

@media(max-width:720px){
    .itinerary-timeline .itinerary-day-card{
        display:block;
        padding:11px;
    }
    .itinerary-timeline .itinerary-day-heading{
        margin-bottom:8px;
    }
    .itinerary-timeline .itinerary-day-heading h3{
        font-size:17px;
        line-height:1.32;
    }
    .itinerary-timeline .itinerary-day-media{
        float:none;
        width:100%;
        max-width:none;
        margin:0 0 10px;
    }
    .itinerary-timeline .itinerary-day-image img{
        max-height:290px;
    }
    .itinerary-timeline .itinerary-day-body p{
        font-size:13px;
        line-height:1.58;
        text-align:left;
    }
    .itinerary-timeline .itinerary-day-meta{
        margin-top:8px;
        font-size:10.3px;
    }
}

.document-print-header{display:none}
@media print{
    .header{display:none!important}
    .document-print-header{display:grid!important;grid-template-columns:3in 1fr auto;align-items:center;gap:18px;padding:0 0 14px;margin:0 0 18px;border-top:5px solid #07192d;border-bottom:1px solid #dbe5ef}
    .document-print-header img{display:block;width:3in;max-width:3in;height:auto;max-height:1.2in;object-fit:contain}
    .document-print-company{font:800 15px/1.3 Arial,sans-serif;color:#07192d}.document-print-company small{display:block;margin-top:4px;color:#64748b;font-size:10px;font-weight:700}
    .document-print-type{text-align:right;font:800 11px/1.3 Arial,sans-serif;color:#0b67c7;text-transform:uppercase}
    .itinerary-timeline .itinerary-day-media{
        break-inside:avoid;
        page-break-inside:avoid;
    }
    .itinerary-timeline .itinerary-day-image img{
        max-height:290px;
        object-fit:contain;
    }
    .itinerary-timeline .itinerary-day-card{
        box-shadow:none;
    }
}



/* ============================================================
   V24.95 PREMIUM CONTENT PRESENTATION
   International-standard overview, highlights and package coverage
============================================================ */
.premium-section-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
    margin-bottom:22px;
}
.premium-section-kicker{
    display:inline-flex;
    align-items:center;
    gap:7px;
    margin-bottom:8px;
    color:#0b75c9;
    font-size:11px;
    line-height:1.2;
    font-weight:900;
    letter-spacing:1.15px;
    text-transform:uppercase;
}
.premium-section-kicker i{font-size:11px}
.premium-section-mark{
    flex:0 0 46px;
    width:46px;
    height:46px;
    border:1px solid #dce8f4;
    border-radius:14px;
    display:grid;
    place-items:center;
    background:linear-gradient(145deg,#f8fbff,#eef6ff);
    color:#126ab7;
    box-shadow:0 8px 20px rgba(15,78,132,.07);
}
.premium-section-mark i{font-size:18px}
.journey-overview-card,
.tour-highlights-card,
.trip-coverage-card{
    position:relative;
    overflow:hidden;
    border-color:#dce7f1;
    box-shadow:0 12px 34px rgba(18,46,78,.055);
}
.journey-overview-card:before,
.tour-highlights-card:before,
.trip-coverage-card:before{
    content:'';
    position:absolute;
    inset:0 auto auto 0;
    width:100%;
    height:3px;
    background:linear-gradient(90deg,#1376d4 0%,#4bb4d8 52%,transparent 100%);
}
.journey-fact-strip{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
    margin:2px 0 18px;
}
.journey-fact{
    min-width:0;
    padding:12px 14px;
    border:1px solid #e1eaf3;
    border-radius:12px;
    background:#fbfdff;
}
.journey-fact span{
    display:block;
    margin-bottom:4px;
    color:#7b8ba0;
    font-size:10px;
    font-weight:800;
    letter-spacing:.55px;
    text-transform:uppercase;
}
.journey-fact strong{
    display:block;
    color:#142d49;
    font-size:13px;
    line-height:1.45;
    font-weight:800;
}
.journey-description{
    position:relative;
    margin-top:0;
    padding:20px 22px 20px 25px;
    border:1px solid #e2eaf2;
    border-radius:14px;
    background:linear-gradient(135deg,#ffffff 0%,#f9fbfe 100%);
    color:#44566b;
    text-align:justify;
    text-justify:inter-word;
    text-align-last:left;
    line-height:1.82;
}
.journey-description:before{
    content:'';
    position:absolute;
    top:20px;
    bottom:20px;
    left:0;
    width:4px;
    border-radius:0 4px 4px 0;
    background:#1682d4;
}
.tour-highlights{
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-top:0;
}
.tour-highlight-item{
    position:relative;
    min-height:88px;
    padding:15px 44px 15px 14px;
    gap:12px;
    align-items:center;
    border:1px solid #dce7f1;
    border-radius:14px;
    background:#fff;
    box-shadow:0 5px 14px rgba(14,48,78,.035);
    transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;
}
.tour-highlight-item:hover{
    transform:translateY(-2px);
    border-color:#b9d6ee;
    box-shadow:0 10px 24px rgba(18,78,125,.08);
}
.tour-highlight-number{
    flex:0 0 36px;
    width:36px;
    height:36px;
    border-radius:11px;
    display:grid;
    place-items:center;
    background:#edf6ff;
    color:#0d71c8;
    font-size:11px;
    font-weight:900;
    letter-spacing:.5px;
}
.tour-highlight-item:first-child:last-child{grid-column:1/-1;max-width:100%}
.tour-highlight-copy{min-width:0}
.tour-highlight-copy small{
    display:block;
    margin-bottom:3px;
    color:#8a98a9;
    font-size:9px;
    font-weight:800;
    letter-spacing:.65px;
    text-transform:uppercase;
}
.tour-highlight-copy strong{
    display:block;
    color:#17314e;
    font-size:14px;
    line-height:1.5;
    font-weight:800;
}
.tour-highlight-arrow{
    position:absolute;
    top:16px;
    right:15px;
    color:#b2c0ce!important;
    font-size:11px!important;
}
.trip-coverage-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}
.coverage-panel{
    min-width:0;
    overflow:hidden;
    border:1px solid #dce7f0;
    border-radius:15px;
    background:#fff;
}
.coverage-panel--included{border-top:3px solid #11a36a}
.coverage-panel--excluded{border-top:3px solid #e45865}
.coverage-panel-head{
    display:grid;
    grid-template-columns:40px minmax(0,1fr) auto;
    gap:11px;
    align-items:center;
    padding:14px 15px 13px;
    border-bottom:1px solid #e7edf3;
    background:#fbfdff;
}
.coverage-panel-icon{
    width:38px;
    height:38px;
    border-radius:11px;
    display:grid;
    place-items:center;
    font-size:13px;
}
.coverage-panel--included .coverage-panel-icon{background:#eaf8f2;color:#079761}
.coverage-panel--excluded .coverage-panel-icon{background:#fff0f2;color:#d94757}
.coverage-panel-kicker{
    display:block;
    margin-bottom:2px;
    color:#8897a8;
    font-size:9px;
    font-weight:800;
    letter-spacing:.5px;
    text-transform:uppercase;
}
.coverage-panel h3{
    margin:0;
    color:#18324f;
    font-size:16px;
    line-height:1.25;
    font-weight:900;
}
.coverage-count{
    white-space:nowrap;
    padding:5px 8px;
    border:1px solid #e0e8f0;
    border-radius:999px;
    background:#fff;
    color:#75859a;
    font-size:9px;
    font-weight:800;
}
.coverage-list{padding:6px 14px 10px}
.coverage-list .include-item{
    min-height:0;
    padding:11px 2px;
    border-bottom:1px solid #edf1f5;
    border-radius:0;
    background:transparent;
    color:#405268;
    font-size:13px;
    line-height:1.55;
    font-weight:600;
}
.coverage-list .include-item:last-child{border-bottom:0}
.coverage-list .include-item i{
    flex:0 0 24px;
    width:24px;
    height:24px;
    margin:0;
    border-radius:7px;
    display:grid;
    place-items:center;
    font-size:9px;
}
.coverage-panel--included .include-item i{background:#eaf8f2;color:#079761}
.coverage-panel--excluded .include-item i{background:#fff0f2;color:#d94757}
@media(max-width:760px){
    .premium-section-head{gap:14px;margin-bottom:18px}
    .premium-section-mark{width:40px;height:40px;flex-basis:40px;border-radius:12px}
    .journey-fact-strip{grid-template-columns:1fr}
    .tour-highlights,
    .trip-coverage-grid{grid-template-columns:1fr}
    .journey-description{padding:17px 17px 17px 20px;text-align:left}
    .tour-highlight-item{min-height:76px}
}
</style>


<!-- TRAVSCOPE PRO UNIFORM UI -->
<link rel="stylesheet" href="assets/travscope-pro-public.css?v=20260916-pro">

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-public-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="public"></script>
<link rel="stylesheet" href="assets/travscope-world-v2526.css?v=20261015" media="screen">
<link rel="stylesheet" href="assets/travscope-world-catalog-v2526.css?v=20261015" media="screen">
</head>


<body class="ts-world-public ts-world-detail">

<div class="document-print-header">
    <img src="<?= e($documentLogoUrl); ?>" alt="Travscope document logo">
    <div class="document-print-company">Memorable Hospitality Pvt. Ltd.<small>Trade Name: Travscope</small></div>
    <div class="document-print-type">Package Itinerary</div>
</div>

<!-- =========================================================
     HEADER
========================================================= -->

<header class="header">


<a
    href="<?= e(BASE_URL); ?>"
    class="logo"
>

    <div class="logo-icon">

        <i class="fa-solid fa-plane"></i>

    </div>

    TRAV<span>SCOPE</span>

</a>


<nav class="nav">

    <a href="<?= e(BASE_URL); ?>">
        Home
    </a>

    <a
        href="packages.php"
        class="active"
    >
        Packages
    </a>

    <a href="destinations.php">
        Destinations
    </a>

    <?php if ($userId): ?>

        <a href="my-bookings.php">
            My Trips
        </a>

    <?php endif; ?>

    <a href="contact.php">
        Contact
    </a>

</nav>


<div class="header-actions">


<?php if ($userId): ?>

    <a
        href="wishlist.php"
        class="header-btn light"
    >

        <i class="fa-regular fa-heart"></i>

        Wishlist

    </a>


    <a
        href="dashboard.php"
        class="header-btn blue"
    >

        <i class="fa-regular fa-user"></i>

        <span>
            My Account
        </span>

    </a>

<?php else: ?>

    <a
        href="login.php"
        class="header-btn light"
    >
        Login
    </a>

    <a
        href="register.php"
        class="header-btn blue"
    >

        <i class="fa-regular fa-user"></i>

        <span>
            Join TRAVSCOPE
        </span>

    </a>

<?php endif; ?>


</div>


</header>


<!-- =========================================================
     BREADCRUMB
========================================================= -->

<div class="breadcrumb-wrap">

<div class="breadcrumb">

    <a href="<?= e(BASE_URL); ?>">
        Home
    </a>

    <i class="fa-solid fa-chevron-right"></i>

    <a href="packages.php">
        Packages
    </a>


    <?php if (
        !empty(
            $package['destination_name']
        )
    ): ?>

        <i class="fa-solid fa-chevron-right"></i>

        <a
            href="destinations.php?destination=<?= (int)$package['destination_id']; ?>"
        >

            <?= e(
                $package['destination_name']
            ); ?>

        </a>

    <?php endif; ?>


    <i class="fa-solid fa-chevron-right"></i>

    <span>

        <?= e($package['title']); ?>

    </span>

</div>

</div>


<!-- =========================================================
     FLASH
========================================================= -->

<?php if (
    $successMessage
    || $errorMessage
): ?>

<div class="flash">

<?php if ($successMessage): ?>

    <div class="flash-box flash-success">

        <i class="fa-solid fa-circle-check"></i>

        <?= e($successMessage); ?>

    </div>

<?php endif; ?>


<?php if ($errorMessage): ?>

    <div class="flash-box flash-error">

        <i class="fa-solid fa-circle-exclamation"></i>

        <?= e($errorMessage); ?>

    </div>

<?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     GALLERY
========================================================= -->

<section class="gallery-wrap">

<div class="gallery">

    <div class="gallery-photo-frame gallery-main-frame">

        <div
            class="gallery-main gallery-click"
            data-index="0"
        >

            <img
                src="<?= e(
                    travelImage(
                        $images[0]['image']
                    )
                ); ?>"
                alt="<?= e(
                    $images[0]['alt_text']
                    ?: $package['title']
                ); ?>"
            >

            <button
                type="button"
                class="view-photos"
            >
                <i class="fa-regular fa-images"></i>

                View
                <?= count($images); ?>
                Photo<?= count($images) === 1 ? '' : 's'; ?>
            </button>

            <?php
            $mainCreditHtml = renderImageAttribution(
                $images[0]['attribution']
                ?? null
            );

            if ($mainCreditHtml !== '') {
                echo str_replace(
                    'photo-attribution',
                    'photo-attribution gallery-photo-attribution',
                    $mainCreditHtml
                );
            }
            ?>

        </div>

    </div>

    <div class="gallery-side">

        <?php for (
            $i = 1;
            $i <= 4;
            $i++
        ): ?>

            <?php
            $galleryIndex =
                isset($images[$i])
                    ? $i
                    : 0;
            ?>

            <div class="gallery-small-frame">

                <div
                    class="gallery-small gallery-click"
                    data-index="<?= $galleryIndex; ?>"
                >

                    <img
                        src="<?= e(
                            travelImage(
                                $images[$galleryIndex]['image']
                            )
                        ); ?>"
                        alt="<?= e(
                            $images[$galleryIndex]['alt_text']
                            ?: $package['title']
                        ); ?>"
                    >

                    <?php
                    $smallCreditHtml = renderImageAttribution(
                        $images[$galleryIndex]['attribution']
                        ?? null
                    );

                    if ($smallCreditHtml !== '') {
                        echo str_replace(
                            'photo-attribution',
                            'photo-attribution gallery-photo-attribution',
                            $smallCreditHtml
                        );
                    }
                    ?>

                </div>

            </div>

        <?php endfor; ?>

    </div>

</div>

</section>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="container">


<div class="layout">


<!-- =========================================================
     LEFT CONTENT
========================================================= -->

<div>


    <div class="badges">


        <?php if (
            (int)$package['bestseller'] === 1
        ): ?>

            <span class="badge best">

                <i class="fa-solid fa-fire"></i>

                Bestseller

            </span>

        <?php endif; ?>


        <?php if (
            (int)$package['featured'] === 1
        ): ?>

            <span class="badge">

                <i class="fa-solid fa-star"></i>

                Featured Trip

            </span>

        <?php endif; ?>


        <?php if ($discount > 0): ?>

            <span class="badge discount">

                Save <?= $discount; ?>%

            </span>

        <?php endif; ?>


    </div>


    <div class="title-row">


        <div>

            <h1 class="package-title">

                <?= e($package['title']); ?>

            </h1>


            <div class="package-rating-summary">

                <?php if ($reviewTotal > 0): ?>

                    <a href="#travellerReviews">

                        <span class="package-rating-stars">
                            <?= packageReviewStars((int)round($averageRating)); ?>
                        </span>

                        <strong>
                            <?= number_format($averageRating, 1); ?>
                        </strong>

                        <span>
                            <?= number_format($reviewTotal); ?>
                            verified
                            <?= $reviewTotal === 1 ? 'review' : 'reviews'; ?>
                        </span>

                        <i class="fa-solid fa-chevron-right"></i>

                    </a>

                <?php else: ?>

                    <span class="new-review-label">
                        <i class="fa-regular fa-star"></i>
                        Be among the first travellers to review this trip
                    </span>

                <?php endif; ?>

            </div>


            <div class="destination">


                <span>

                    <i class="fa-solid fa-location-dot"></i>

                    <?= e(
                        $package['destination_name']
                        ?: 'Explore the World'
                    ); ?>

                    <?php if (
                        !empty(
                            $package['destination_country']
                        )
                    ): ?>

                        ,
                        <?= e(
                            $package['destination_country']
                        ); ?>

                    <?php endif; ?>

                </span>


                <?php if (
                    !empty(
                        $package['package_code']
                    )
                ): ?>

                    <span class="package-code">

                        Package:
                        <?= e(
                            $package['package_code']
                        ); ?>

                    </span>

                <?php endif; ?>


            </div>

        </div>


        <div class="package-actions">

            <div class="share-wrap">

                <button
                    type="button"
                    class="share-btn"
                    id="packageShareButton"
                    title="Share this package"
                    aria-label="Share this package"
                    aria-expanded="false"
                >
                    <i class="fa-solid fa-share-nodes"></i>
                </button>

                <div
                    class="share-menu"
                    id="packageShareMenu"
                >

                    <div class="share-menu-title">
                        Share this experience
                    </div>

                    <div class="share-options">

                        <button type="button" class="share-option whatsapp" data-share="whatsapp">
                            <i class="fa-brands fa-whatsapp"></i>
                            WhatsApp
                        </button>

                        <button type="button" class="share-option facebook" data-share="facebook">
                            <i class="fa-brands fa-facebook-f"></i>
                            Facebook
                        </button>

                        <button type="button" class="share-option twitter" data-share="twitter">
                            <i class="fa-brands fa-x-twitter"></i>
                            X / Twitter
                        </button>

                        <button type="button" class="share-option email" data-share="email">
                            <i class="fa-regular fa-envelope"></i>
                            Email
                        </button>

                        <button type="button" class="share-option copy" data-share="copy">
                            <i class="fa-regular fa-copy"></i>
                            Copy Link
                        </button>

                        <button type="button" class="share-option native" data-share="native">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i>
                            More
                        </button>

                    </div>

                    <div
                        class="share-copy-status"
                        id="shareCopyStatus"
                    >
                        <i class="fa-solid fa-circle-check"></i>
                        Package link copied
                    </div>

                </div>

            </div>

        <form
            method="post"
            action=""
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()); ?>"
            >

            <input
                type="hidden"
                name="wishlist_action"
                value="toggle"
            >


            <button
                type="submit"
                class="wishlist-btn <?= $isWishlisted ? 'active' : ''; ?>"
                title="<?= $isWishlisted
                    ? 'Remove from wishlist'
                    : 'Save to wishlist'; ?>"
            >

                <i
                    class="<?= $isWishlisted
                        ? 'fa-solid'
                        : 'fa-regular'; ?> fa-heart"
                ></i>

            </button>

        </form>

        </div>


    </div>


    <!-- =====================================================
         QUICK INFO
    ====================================================== -->

    <div class="quick-info">


        <div class="quick-item">

            <div class="quick-icon">

                <i class="fa-regular fa-clock"></i>

            </div>

            <div>

                <small>
                    Duration
                </small>

                <strong>

                    <?= (int)$package['duration_days']; ?>
                    Days /
                    <?= (int)$package['duration_nights']; ?>
                    Nights

                </strong>

            </div>

        </div>


        <div class="quick-item">

            <div class="quick-icon">

                <i class="fa-solid fa-compass"></i>

            </div>

            <div>

                <small>
                    Travel Style
                </small>

                <strong>

                    <?= e(
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $package['package_type']
                            )
                        )
                    ); ?>

                </strong>

            </div>

        </div>


        <div class="quick-item">

            <div class="quick-icon">

                <i class="fa-solid fa-location-dot"></i>

            </div>

            <div>

                <small>
                    Destination
                </small>

                <strong>

                    <?= e(
                        $package['destination_name']
                        ?: 'Worldwide'
                    ); ?>

                </strong>

            </div>

        </div>


        <div class="quick-item">

            <div class="quick-icon">

                <i class="fa-regular fa-eye"></i>

            </div>

            <div>

                <small>
                    Travellers Viewed
                </small>

                <strong>

                    <?= number_format(
                        (int)$package['views']
                    ); ?>

                </strong>

            </div>

        </div>


    </div>


    <!-- =====================================================
         OVERVIEW
    ====================================================== -->

    <section class="content-card journey-overview-card">

        <div class="premium-section-head">
            <div>
                <span class="premium-section-kicker">
                    <i class="fa-regular fa-compass"></i> TRIP OVERVIEW
                </span>
                <h2>About this journey</h2>
                <div class="section-sub">
                    A concise travel brief with the route, pace and experience profile at a glance.
                </div>
            </div>
            <span class="premium-section-mark" aria-hidden="true">
                <i class="fa-solid fa-route"></i>
            </span>
        </div>

        <div class="journey-fact-strip" aria-label="Journey overview facts">
            <div class="journey-fact">
                <span>Duration</span>
                <strong><?= (int)$package['duration_nights']; ?> Nights / <?= (int)$package['duration_days']; ?> Days</strong>
            </div>
            <div class="journey-fact">
                <span>Destination</span>
                <strong><?= e($package['destination_name'] ?: 'Worldwide'); ?></strong>
            </div>
            <div class="journey-fact">
                <span>Travel style</span>
                <strong><?= e(ucwords(str_replace('_', ' ', (string)$package['package_type']))); ?></strong>
            </div>
        </div>

        <div class="description journey-description">
<?php
if (!empty($package['description'])) {
    echo e(publicWordLimit((string)$package['description'], 200));
} elseif (!empty($package['short_description'])) {
    echo e(publicWordLimit((string)$package['short_description'], 100));
} else {
    echo 'A carefully selected travel experience designed to help you explore, relax and create unforgettable memories.';
}
?>
        </div>

    </section>


<?php
/**
 * V25.17: route strings from a supplier Word import are not tour highlights.
 * Retain genuine concise highlights; derive attraction names only where
 * the stored itinerary actually mentions them (no invented inclusions).
 */
function tsPublicTourHighlights(?string $stored, array $days): array
{
    $value = trim(html_entity_decode(strip_tags((string)$stored), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $raw = preg_split('/[\r\n]+|\s*;\s*/u', $value) ?: [];
    $clean = [];
    foreach ($raw as $item) {
        $item = trim(preg_replace('/\s+/u', ' ', $item) ?? $item);
        if ($item === '' || strlen($item) > 180) continue;
        if (preg_match('/\b(?:approx(?:imately)?|hrs?|hours?|minutes?|drive|airport\s*\/|\/\s*airport)\b/iu', $item)) continue;
        if (preg_match('/^\s*\[.*\/.*\]\s*$/u', $item)) continue;
        if (preg_match('/^(?:day\s*\d+|\d+\s*days?\s*\/|\d+\s*nights?)\b/iu', $item)) continue;
        $clean[] = trim($item, "-–—[] ");
    }
    if (count($clean) > 0) return array_slice(array_values(array_unique($clean)), 0, 8);

    $itineraryText = '';
    foreach ($days as $day) {
        $itineraryText .= ' '.strip_tags((string)($day['description'] ?? '')).' '.strip_tags((string)($day['title'] ?? ''));
    }
    // The vocabulary is only a matcher: a candidate is shown iff the itinerary
    // explicitly contains it. It is not a generic recommendation list.
    $sights = [
        'Pinnawala Elephant Orphanage', 'Dambulla Cave Temple', 'Sigiriya Rock Fortress',
        'Minneriya National Park', 'Temple of the Sacred Tooth Relic', 'Royal Botanical Garden',
        'Kandyan Dance', 'Ramboda Falls', 'Ramboda Hanuman Temple', 'Tea Factory',
        'Tea Estate', 'Gregory Lake', 'Seeta Amman Temple', 'Ashoka Vatika',
        'Madu River', 'Turtle Hatchery', 'Galle Fort', 'Colombo City Tour',
        'Munneswaram Temple', 'Manavari Temple', 'Koneswaram Temple',
        'Shankari Devi Temple', 'Trincomalee', 'Kelaniya Temple',
        'Panchamuga Anjaneyar Temple', 'Bentota Beach', 'Nuwara Eliya',
    ];
    $aliases = [
        'Temple of the Sacred Tooth Relic' => '/(?:Temple of (?:the )?Sacred Tooth|Tooth Relic)/iu',
        'Ramboda Falls' => '/Ramboda[^.\n]{0,40}(?:Falls|Water\s*Falls)|Water\s*Falls[^.\n]{0,30}Ramboda/iu',
        'Ramboda Hanuman Temple' => '/(?:Sri\s*)?(?:Bhakta|Baktha)\s*Hanuman\s*Temple/iu',
        'Dambulla Cave Temple' => '/Dambulla\s*(?:Rock\s*)?Cave\s*Temple/iu',
        'Madu River' => '/(?:Madhu?|Madu)\s*River/iu',
        'Koneswaram Temple' => '/(?:Thiru\s*)?Konesh?waram/iu',
        'Kelaniya Temple' => '/Kelaniya\s*(?:Vibee?shana\s*)?Temple/iu',
        'Panchamuga Anjaneyar Temple' => '/Panchamuga\s*Anjaney(?:r|ar)\s*Temple/iu',
    ];
    $found = [];
    foreach ($sights as $name) {
        $pattern = $aliases[$name] ?? '/'.preg_quote($name, '/').'/iu';
        if (preg_match($pattern, $itineraryText)) $found[] = $name;
    }
    return array_slice($found, 0, 8);
}
?>

<!-- =====================================================
     TOUR HIGHLIGHTS
====================================================== -->

<?php
$highlights = tsPublicTourHighlights((string)($package['tour_highlights'] ?? ''), $itineraryDays);
?>

<?php if ($highlights): ?>
<section class="content-card tour-highlights-card">

    <div class="premium-section-head">
        <div>
            <span class="premium-section-kicker">
                <i class="fa-regular fa-star"></i> SIGNATURE EXPERIENCES
            </span>
            <h2>Tour Highlights</h2>
            <div class="section-sub">
                The defining experiences and route moments that shape this journey.
            </div>
        </div>
        <span class="premium-section-mark" aria-hidden="true">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
        </span>
    </div>

    <div class="tour-highlights">

        <?php foreach ($highlights as $highlightIndex => $item): ?>
            <?php if (trim((string)$item) === '') continue; ?>

            <div class="tour-highlight-item">
                <span class="tour-highlight-number"><?= str_pad((string)($highlightIndex + 1), 2, '0', STR_PAD_LEFT); ?></span>
                <span class="tour-highlight-copy">
                    <small>Experience</small>
                    <strong><?= e(trim($item)); ?></strong>
                </span>
                <i class="fa-solid fa-arrow-up-right-from-square tour-highlight-arrow" aria-hidden="true"></i>
            </div>

        <?php endforeach; ?>

    </div>

</section>
<?php endif; ?>

    <!-- =====================================================
         DAY-WISE ITINERARY
    ====================================================== -->

    <?php if (
        $itineraryDays
        || !empty($package['itinerary'])
    ): ?>

    <section class="content-card">

        <h2>
            Your journey, day by day
        </h2>

        <div class="section-sub">
            See how your travel experience unfolds.
        </div>

        <?php if ($itineraryDays): ?>

            <div class="itinerary-timeline">

                <?php foreach ($itineraryDays as $index => $day): ?>

                    <?php
                    $displayDay =
                        (int)($day['day_number'] ?? 0);

                    if ($displayDay <= 0) {
                        $displayDay = $index + 1;
                    }

                    $dayTitle =
                        trim((string)($day['title'] ?? ''));

                    $dayParts =
                        publicItineraryDayParts(
                            (string)($day['description'] ?? ''),
                            $displayDay,
                            200
                        );

                    $dayDescription = (string)($dayParts['narrative'] ?? '');
                    $dayMetadata = (string)($dayParts['metadata'] ?? '');
                    $dayMetadataItems = publicItineraryMetaItems($dayMetadata);

                    $dayImage =
                        trim((string)($day['image'] ?? ''));
                    ?>

                    <div class="itinerary-day">

                        <div class="itinerary-number">
                            DAY<br><?= $displayDay; ?>
                        </div>

                        <article class="itinerary-day-card<?= $dayImage === '' ? ' itinerary-day-card--text-only' : ''; ?>">

                            <header class="itinerary-day-heading">
                                <span class="itinerary-day-eyebrow">DAY <?= $displayDay; ?> · YOUR JOURNEY</span>
                                <h3><?= e($dayTitle !== '' ? $dayTitle : 'Day ' . $displayDay); ?></h3>
                            </header>

                            <div class="itinerary-day-flow">
                                <?php if ($dayImage !== ''): ?>
                                    <?php $dayImageUrl = travelImage($dayImage); ?>
                                    <figure class="itinerary-day-media">
                                        <a class="itinerary-day-image"
                                           href="<?= e($dayImageUrl); ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           aria-label="Open photo for <?= e($dayTitle !== '' ? $dayTitle : 'Day ' . $displayDay); ?>">
                                            <img
                                                src="<?= e($dayImageUrl); ?>"
                                                alt="<?= e($dayTitle !== '' ? $dayTitle : 'Travel photo for day ' . $displayDay); ?>"
                                                loading="eager"
                                                decoding="async"
                                                onerror="this.closest('.itinerary-day-media').classList.add('is-unavailable')"
                                            >
                                        </a>
                                        <figcaption class="itinerary-day-media-caption">
                                            <?php
                                            $itineraryCreditHtml = renderImageAttribution($day['attribution'] ?? null);
                                            if ($itineraryCreditHtml !== '') {
                                                echo str_replace(
                                                    'photo-attribution',
                                                    'photo-attribution itinerary-photo-attribution',
                                                    $itineraryCreditHtml
                                                );
                                            }
                                            ?>
                                            <a class="itinerary-photo-open"
                                               href="<?= e($dayImageUrl); ?>"
                                               target="_blank"
                                               rel="noopener noreferrer">Open photo <span aria-hidden="true">↗</span></a>
                                        </figcaption>
                                    </figure>
                                <?php endif; ?>

                                <div class="itinerary-day-body">
                                    <?php if ($dayDescription !== ''): ?>
                                        <p><?= e($dayDescription); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($dayMetadataItems): ?>
                                <div class="itinerary-day-meta" aria-label="Day <?= $displayDay; ?> route summary">
                                    <?php foreach ($dayMetadataItems as $metaItem): ?>
                                        <span class="itinerary-day-meta-item"><?= e($metaItem); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </article>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <!-- Backward compatibility for old packages -->
            <div class="itinerary-content"><?= e(
                $package['itinerary']
            ); ?></div>

        <?php endif; ?>

    </section>

    <?php endif; ?>


    <!-- =====================================================
         INCLUSIONS
    ====================================================== -->

    <?php if (
        $inclusions
        || $exclusions
    ): ?>

    <section class="content-card trip-coverage-card">

        <div class="premium-section-head">
            <div>
                <span class="premium-section-kicker">
                    <i class="fa-solid fa-shield-halved"></i> PACKAGE COVERAGE
                </span>
                <h2>What's included in your trip?</h2>
                <div class="section-sub">
                    A transparent view of what is covered and what remains outside the package price.
                </div>
            </div>
            <span class="premium-section-mark" aria-hidden="true">
                <i class="fa-solid fa-list-check"></i>
            </span>
        </div>

        <div class="trip-coverage-grid">

            <?php if ($inclusions): ?>
                <section class="coverage-panel coverage-panel--included" aria-label="Included services">
                    <div class="coverage-panel-head">
                        <span class="coverage-panel-icon"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <span class="coverage-panel-kicker">Covered in your package</span>
                            <h3>Included</h3>
                        </div>
                        <span class="coverage-count"><?= count($inclusions); ?> items</span>
                    </div>

                    <div class="coverage-list">
                        <?php foreach ($inclusions as $item): ?>
                            <div class="include-item">
                                <i class="fa-solid fa-check"></i>
                                <span><?= e($item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($exclusions): ?>
                <section class="coverage-panel coverage-panel--excluded" aria-label="Excluded services">
                    <div class="coverage-panel-head">
                        <span class="coverage-panel-icon"><i class="fa-solid fa-minus"></i></span>
                        <div>
                            <span class="coverage-panel-kicker">Payable separately</span>
                            <h3>Not included</h3>
                        </div>
                        <span class="coverage-count"><?= count($exclusions); ?> items</span>
                    </div>

                    <div class="coverage-list">
                        <?php foreach ($exclusions as $item): ?>
                            <div class="include-item exclude">
                                <i class="fa-solid fa-minus"></i>
                                <span><?= e($item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        </div>

    </section>

    <?php endif; ?>


    

<!-- =====================================================
         DESTINATION
    ====================================================== -->

    <?php if (
        !empty(
            $package['destination_name']
        )
    ): ?>

    <section class="content-card">


        <h2>
            Discover the destination
        </h2>

        <div class="section-sub">

            A little inspiration before you pack
            your bags.

        </div>


        <div
            class="destination-box"
            style="
                --destination-bg:
                url('<?= e(
                    travelImage(
                        $package['destination_banner']
                        ?: $package['destination_image']
                    )
                ); ?>');
            "
        >


            <div>

                <h3>

                    <?= e(
                        $package['destination_name']
                    ); ?>

                    <?php if (
                        !empty(
                            $package['destination_country']
                        )
                    ): ?>

                        ,
                        <?= e(
                            $package['destination_country']
                        ); ?>

                    <?php endif; ?>

                </h3>


                <p>

                    <?= e(
                        $package['destination_short_description']
                        ?: 'Explore a destination filled with memorable experiences, beautiful places and stories worth bringing home.'
                    ); ?>

                </p>

            </div>


        </div>


    </section>

    <?php endif; ?>


    <!-- =====================================================
         TERMS
    ====================================================== -->

    <?php if (
        !empty(
            $package['terms']
        )
    ): ?>

    <section class="content-card">


        <h2>
            Important information
        </h2>

        <div class="section-sub">

            Please review these details before booking.

        </div>


        <div class="important-information-content">
            <?= professionalText($package['terms']); ?>
        </div>


    </section>

    <?php endif; ?>



    <!-- =====================================================
         TRAVELLER REVIEWS
    ====================================================== -->

    <section
        class="content-card review-section"
        id="travellerReviews"
    >

        <div class="review-section-header">

            <div>

                <span class="review-eyebrow">
                    <i class="fa-solid fa-shield-heart"></i>
                    VERIFIED TRAVELLER EXPERIENCES
                </span>

                <h2>
                    What travellers say
                </h2>

                <div class="section-sub">
                    Genuine experiences from customers who booked through TRAVSCOPE.
                </div>

            </div>

            <?php if ($reviewTotal > 0): ?>

                <div class="review-header-score">

                    <strong>
                        <?= number_format($averageRating, 1); ?>
                    </strong>

                    <div>

                        <div class="header-stars">
                            <?= packageReviewStars((int)round($averageRating)); ?>
                        </div>

                        <span>
                            <?= number_format($reviewTotal); ?>
                            <?= $reviewTotal === 1 ? 'review' : 'reviews'; ?>
                        </span>

                    </div>

                </div>

            <?php endif; ?>

        </div>


        <?php if ($reviewTotal > 0): ?>

            <div class="review-overview">

                <div class="big-rating">

                    <strong>
                        <?= number_format($averageRating, 1); ?>
                    </strong>

                    <div class="big-stars">
                        <?= packageReviewStars((int)round($averageRating)); ?>
                    </div>

                    <span>
                        Based on
                        <?= number_format($reviewTotal); ?>
                        verified
                        <?= $reviewTotal === 1 ? 'review' : 'reviews'; ?>
                    </span>

                </div>


                <div class="rating-bars">

                    <?php for ($star = 5; $star >= 1; $star--): ?>

                        <div class="rating-row">

                            <div class="rating-label">
                                <?= $star; ?>
                                <i class="fa-solid fa-star"></i>
                            </div>

                            <div class="rating-track">
                                <div
                                    class="rating-fill"
                                    style="width:<?= (int)$ratingPercentages[$star]; ?>%;"
                                ></div>
                            </div>

                            <div class="rating-count">
                                <?= number_format($ratingCounts[$star]); ?>
                            </div>

                        </div>

                    <?php endfor; ?>

                </div>

            </div>


            <div class="review-list">

                <?php foreach ($reviews as $review): ?>

                    <?php

                    $customerName = trim(
                        (string)($review['full_name'] ?? '')
                    );

                    if ($customerName === '') {
                        $customerName = 'TRAVSCOPE Traveller';
                    }

                    $profileImage = '';

                    if (!empty($review['profile_image'])) {
                        $profileImage = travelImage(
                            $review['profile_image']
                        );
                    }

                    ?>

                    <article
                        class="traveller-review <?= (int)$review['featured'] === 1 ? 'featured-review' : ''; ?>"
                    >

                        <?php if ((int)$review['featured'] === 1): ?>

                            <div class="featured-review-label">
                                <i class="fa-solid fa-award"></i>
                                Featured Experience
                            </div>

                        <?php endif; ?>


                        <div class="review-customer-row">

                            <div class="review-customer">

                                <div class="review-avatar">

                                    <?php if ($profileImage !== ''): ?>

                                        <img
                                            src="<?= e($profileImage); ?>"
                                            alt="<?= e($customerName); ?>"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <?= e(
                                            packageReviewInitial(
                                                $customerName
                                            )
                                        ); ?>

                                    <?php endif; ?>

                                </div>


                                <div>

                                    <h3>
                                        <?= e($customerName); ?>
                                    </h3>

                                    <div class="review-verification">

                                        <?php if (!empty($review['booking_id'])): ?>

                                            <span class="verified-badge">
                                                <i class="fa-solid fa-circle-check"></i>
                                                Verified Booking
                                            </span>

                                        <?php endif; ?>

                                        <?php if (!empty($review['booking_number'])): ?>

                                            <span class="booking-reference">
                                                <?= e($review['booking_number']); ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                            <div class="review-date">
                                <?= e(
                                    packageReviewDate(
                                        $review['created_at']
                                    )
                                ); ?>
                            </div>

                        </div>


                        <div class="review-rating-row">

                            <div class="review-stars">
                                <?= packageReviewStars(
                                    $review['rating']
                                ); ?>
                            </div>

                            <strong>
                                <?= (int)$review['rating']; ?>.0
                            </strong>

                        </div>


                        <?php if (!empty($review['title'])): ?>

                            <h3 class="traveller-review-title">
                                <?= e($review['title']); ?>
                            </h3>

                        <?php endif; ?>


                        <div class="traveller-review-text">
                            <?= nl2br(
                                e(
                                    $review['review_text']
                                )
                            ); ?>
                        </div>


                        <?php if (!empty($review['admin_reply'])): ?>

                            <div class="official-response">

                                <div class="official-response-head">

                                    <div class="official-logo">
                                        <i class="fa-solid fa-plane"></i>
                                    </div>

                                    <div>

                                        <strong>
                                            Response from TRAVSCOPE
                                        </strong>

                                        <?php if (!empty($review['admin_reply_at'])): ?>

                                            <span>
                                                <?= e(
                                                    packageReviewDate(
                                                        $review['admin_reply_at']
                                                    )
                                                ); ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <p>
                                    <?= nl2br(
                                        e(
                                            $review['admin_reply']
                                        )
                                    ); ?>
                                </p>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>


            <div class="review-trust-note">

                <i class="fa-solid fa-shield-halved"></i>

                <div>

                    <strong>
                        Reviews you can trust
                    </strong>

                    <span>
                        Reviews shown here are approved customer reviews.
                        A Verified Booking badge means the review is linked
                        to a TRAVSCOPE booking.
                    </span>

                </div>

            </div>


        <?php else: ?>

            <div class="no-reviews">

                <div class="no-review-icon">
                    <i class="fa-regular fa-star"></i>
                </div>

                <h3>
                    No traveller reviews yet
                </h3>

                <p>
                    This package hasn't received an approved traveller
                    review yet. Complete your journey with TRAVSCOPE and
                    you may be among the first to share your experience.
                </p>

                <a
                    href="#bookingForm"
                    class="first-review-book"
                >
                    <i class="fa-solid fa-plane-departure"></i>
                    Book This Experience
                </a>

            </div>

        <?php endif; ?>

    </section>


</div>


<!-- =========================================================
     BOOKING COLUMN
========================================================= -->

<aside class="booking-column">


<div class="booking-card">


    <div class="booking-top">

        <div class="booking-label">
            Starting price per adult
        </div>

        <div class="price" id="displayPackagePrice">

            <?= e($currencySymbol); ?>

            <span id="displayPackagePriceAmount">
                <?= number_format(
                    $currentStartingPrice,
                    0
                ); ?>
            </span>

            <small>
                / person
            </small>

        </div>

        <div
            class="current-price-period"
            id="currentPricePeriod"
        >
            <?php if ($currentPricePeriod): ?>
                Valid
                <?= e(date('d M Y', strtotime($currentPricePeriod['valid_from']))); ?>
                –
                <?= e(date('d M Y', strtotime($currentPricePeriod['valid_to']))); ?>
            <?php else: ?>
                Select a travel date to check availability.
            <?php endif; ?>
        </div>

    </div>


    <form
        method="get"
        action="book-package.php"
        class="booking-body"
        id="bookingForm"
    >


        <input
            type="hidden"
            name="package"
            value="<?= $packageId; ?>"
        >


        <div class="form-group">

            <label for="travelDate">

                <i class="fa-regular fa-calendar"></i>

                TRAVEL DATE

            </label>

            <input
                type="date"
                name="travel_date"
                id="travelDate"
                class="form-control"
                min="<?= e(
                    $minimumTravelDate
                ); ?>"
                value="<?= e($requestedTravelDate); ?>"
                required
            >

        </div>


        <div class="form-group">

            <label for="hotelCategorySelect">
                <i class="fa-solid fa-hotel"></i>
                PACKAGE CATEGORY
            </label>

            <div class="hotel-category-select-wrap">

                <select
                    name="hotel_category"
                    id="hotelCategorySelect"
                    class="form-control hotel-category-select"
                    required
                >

                    <option value="">
                        Choose package category
                    </option>

                    <?php foreach ([3, 4, 5] as $star): ?>

                        <?php
                        $field =
                            'price_' . $star . '_star';

                        $starPrice =
                            $currentPricePeriod[$field]
                            ?? null;

                        $available =
                            $starPrice !== null
                            && (float)$starPrice > 0;
                        ?>

                        <option
                            value="<?= $star; ?>"
                            data-star="<?= $star; ?>"
                            <?= $available ? '' : 'disabled'; ?>
                            <?= $available
                                && (string)$star === (string)$currentSelectedHotelCategory
                                    ? 'selected'
                                    : ''; ?>
                        >
                            <?= $star; ?> Star Hotel<?= !empty($packageHotelNames[(string)$star]) ? ' — ' . e($packageHotelNames[(string)$star]) : ''; ?>
                            <?= $available
                                ? ' — '
                                    . e($currencySymbol)
                                    . number_format((float)$starPrice, 0)
                                    . ' / adult'
                                : ' — Unavailable'; ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <i class="fa-solid fa-chevron-down hotel-category-arrow"></i>

            </div>

            <div
                class="selected-hotel-price"
                id="selectedHotelPriceBox"
            >

                <span>
                    Selected category price
                </span>

                <strong id="selectedHotelPriceText">
                    <?= $currentStartingPrice > 0
                        ? e($currencySymbol)
                            . number_format($currentStartingPrice, 0)
                            . ' / adult'
                        : 'Select a package category'; ?>
                </strong>

            </div>

            <div
                class="price-unavailable-note"
                id="priceUnavailableNote"
                style="display:none;"
            >
                No package price is configured for the selected travel date.
            </div>

        </div>


        <?php if ($packageOccupancyPrices): ?>
        <div class="form-group">

            <label for="occupancyTypeSelect">
                <i class="fa-solid fa-bed"></i>
                ROOM / OCCUPANCY
            </label>

            <select
                name="occupancy_type"
                id="occupancyTypeSelect"
                class="form-control"
                required
            >
                <option value="twin" <?= $requestedOccupancyType === 'twin' ? 'selected' : ''; ?>>
                    Twin / Double Sharing
                </option>
                <option value="triple" <?= $requestedOccupancyType === 'triple' ? 'selected' : ''; ?>>
                    Triple Sharing
                </option>
                <option value="single" <?= $requestedOccupancyType === 'single' ? 'selected' : ''; ?>>
                    Single Occupancy
                </option>
            </select>

            <div class="help">
                Adult package price changes automatically by occupancy.
            </div>

        </div>
        <?php else: ?>
        <input type="hidden" name="occupancy_type" id="occupancyTypeSelect" value="twin">
        <?php endif; ?>


        <input
            type="hidden"
            name="unit_price"
            id="selectedUnitPrice"
            value="<?= e($currentStartingPrice); ?>"
        >


        <div class="traveller-grid">


            <div class="form-group">

                <label for="adults">

                    ADULTS

                </label>

                <select
                    name="adults"
                    id="adults"
                    class="form-control"
                >

                    <?php for (
                        $i = 1;
                        $i <= 10;
                        $i++
                    ): ?>

                        <option
                            value="<?= $i; ?>"
                            <?= $i === 2
                                ? 'selected'
                                : ''; ?>
                        >

                            <?= $i; ?>

                        </option>

                    <?php endfor; ?>

                </select>

            </div>


            <div class="form-group">

                <label for="children">

                    CHILDREN

                </label>

                <select
                    name="children"
                    id="children"
                    class="form-control"
                >

                    <?php for (
                        $i = 0;
                        $i <= 10;
                        $i++
                    ): ?>

                        <option value="<?= $i; ?>">

                            <?= $i; ?>

                        </option>

                    <?php endfor; ?>

                </select>

            </div>


        </div>


        <div
            class="child-age-section"
            id="childAgeSection"
            style="display:none;"
        >

            <div class="child-age-head">

                <div>

                    <strong>
                        <i class="fa-solid fa-child-reaching"></i>
                        CHILD AGE & PRICE
                    </strong>

                    <?php if ($packageOccupancyPrices): ?>

                        <span>
                            Child fare changes by age and With Bed / Without Bed selection. Infant fare is automatic.
                        </span>

                    <?php elseif ($packageChildPrices): ?>

                        <span>
                            Child price is applied automatically according to each child's age.
                        </span>

                    <?php else: ?>

                        <span>
                            Enter the age of each child.
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <?php if ($packageChildPrices && !$packageOccupancyPrices): ?>

                <div class="child-price-public-list">

                    <?php foreach ($packageChildPrices as $childPriceRow): ?>

                        <div class="child-price-public-row">

                            <span>
                                Age
                                <?= (int)($childPriceRow['age_from'] ?? 0); ?>
                                –
                                <?= (int)($childPriceRow['age_to'] ?? 0); ?>
                                years
                            </span>

                            <strong>
                                <?= e($currencySymbol); ?>
                                <?= number_format(
                                    max(0, (float)($childPriceRow['price'] ?? 0)),
                                    0
                                ); ?>
                                / child
                            </strong>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <div
                class="child-age-grid"
                id="childAgeFields"
            ></div>

        </div>


        <input
            type="hidden"
            name="child_unit_price"
            value="<?= e((string)$childUnitPrice); ?>"
        >

        <input
            type="hidden"
            name="child_price_rows"
            value="<?= e(json_encode($packageChildPrices, JSON_UNESCAPED_SLASHES)); ?>"
        >


        <div class="estimate">


            <div class="estimate-row">

                <span id="adultLabel">
                    2 Adults
                </span>

                <strong id="adultAmount">
                    -
                </strong>

            </div>


            <div
                class="estimate-row"
                id="childRow"
                style="display:none;"
            >

                <span id="childLabel">
                    Children
                </span>

                <strong id="childAmount">
                    -
                </strong>

            </div>


            <div class="estimate-row total">

                <span>
                    Estimated Total
                </span>

                <strong id="estimatedTotal">
                    -
                </strong>

            </div>


        </div>


        <div class="booking-actions-sticky">

            <?php if ($customerActionMode !== 'enquiry'): ?>
            <button type="submit" class="book-now">
                Continue to Booking <i class="fa-solid fa-arrow-right"></i>
            </button>
            <?php endif; ?>


            <?php if ($customerActionMode !== 'booking'): ?>
            <a href="<?= e(BASE_URL . 'contact.php?package=' . $packageId . '&package_title=' . urlencode($package['title'])); ?>" class="enquire-now">
                <i class="fa-regular fa-message"></i>
                <?= $customerActionMode === 'enquiry' ? 'Enquire About This Trip' : 'Enquire Before Booking'; ?>
            </a>
            <?php endif; ?>


            <div class="secure-note">

                <i class="fa-solid fa-shield-halved"></i>

                <?= $customerActionMode === 'enquiry' ? 'Personalised assistance • No payment required to enquire' : 'Secure booking • Clear pricing'; ?>

            </div>

        </div>


    </form>


    <div class="booking-benefits">


        <div class="booking-benefit">

            <i class="fa-solid fa-headset"></i>

            <span>
                Travel assistance when you need it
            </span>

        </div>


        <div class="booking-benefit">

            <i class="fa-solid fa-lock"></i>

            <span>
                Secure customer information
            </span>

        </div>


        <div class="booking-benefit">

            <i class="fa-solid fa-receipt"></i>

            <span>
                Booking confirmation and trip details
            </span>

        </div>


    </div>


</div>


</aside>


</div>


<!-- =========================================================
     RELATED PACKAGES
========================================================= -->

<?php if ($related): ?>

<section class="related-section">


<div class="related-title">

    <h2>
        You may also love these trips
    </h2>

    <p>

        More experiences from
        <?= e(
            $package['destination_name']
            ?: 'TRAVSCOPE'
        ); ?>.

    </p>

</div>


<div class="related-grid">


<?php foreach (
    $related
    as $item
): ?>


    <article class="related-card">


        <a
            href="package-details.php?slug=<?= urlencode(
                $item['slug']
            ); ?>"
            class="related-image"
            style="display:block;"
        >

            <img
                src="<?= e(
                    travelImage(
                        $item['main_image']
                    )
                ); ?>"
                alt="<?= e(
                    $item['title']
                ); ?>"
                loading="lazy"
            >

        </a>


        <div class="related-body">


            <h3>

                <a
                    href="package-details.php?slug=<?= urlencode(
                        $item['slug']
                    ); ?>"
                >

                    <?= e(
                        $item['title']
                    ); ?>

                </a>

            </h3>


            <div class="related-meta">

                <i class="fa-regular fa-clock"></i>

                <?= (int)$item['duration_days']; ?>
                Days /

                <?= (int)$item['duration_nights']; ?>
                Nights

                &nbsp; • &nbsp;

                <?= e(
                    ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $item['package_type']
                        )
                    )
                ); ?>

            </div>


            <div class="related-bottom">


                <div class="related-price">

                    <?= e(tsPackageCurrencySymbol((string)($item['currency'] ?? 'INR'))); ?>

                    <?= number_format(
                        (float)$item['sale_price'],
                        0
                    ); ?>

                </div>


                <a
                    href="package-details.php?slug=<?= urlencode(
                        $item['slug']
                    ); ?>"
                    class="related-link"
                >

                    View Trip

                    <i class="fa-solid fa-arrow-right"></i>

                </a>


            </div>


        </div>


    </article>


<?php endforeach; ?>


</div>


</section>

<?php endif; ?>


</main>


<!-- =========================================================
     FOOTER
========================================================= -->

<?php require_once __DIR__ . '/credential-display.php'; echo tsCredentialsHtml($pdo, 'website'); ?>
<footer class="footer">


<div class="footer-inner">


    <div>

        <strong>
            TRAVSCOPE.COM
        </strong>

        <p>
            Explore more. Experience more. Remember more.
        </p>

    </div>


    <p>

        &copy;
        <?= date('Y'); ?>
        TRAVSCOPE.COM.
        All rights reserved.

    </p>


</div>


</footer>


<!-- =========================================================
     MOBILE BOOK BAR
========================================================= -->

<div class="mobile-book-bar">


    <div class="mobile-price">

        <small>
            Starting from
        </small>

        <strong id="mobileCurrentPrice">

            <?= e($currencySymbol); ?>

            <?= number_format(
                $currentStartingPrice,
                0
            ); ?>

        </strong>

    </div>


    <?php if ($customerActionMode !== 'booking'): ?>
    <a href="<?= e(BASE_URL . 'contact.php?package=' . $packageId . '&package_title=' . urlencode($package['title'])); ?>" class="mobile-enquire-button"><i class="fa-regular fa-message"></i> Enquire</a>
    <?php endif; ?>


    <?php if ($customerActionMode !== 'enquiry'): ?>
    <button type="button" class="mobile-book-button" id="mobileBookButton">
        Book This Trip <i class="fa-solid fa-arrow-right"></i>
    </button>
    <?php endif; ?>


</div>


<!-- =========================================================
     PHOTO MODAL
========================================================= -->

<div
    class="modal"
    id="photoModal"
>


    <button
        type="button"
        class="modal-close"
        id="modalClose"
    >

        <i class="fa-solid fa-xmark"></i>

    </button>


    <button
        type="button"
        class="modal-arrow modal-prev"
        id="modalPrev"
    >

        <i class="fa-solid fa-chevron-left"></i>

    </button>


    <img
        src=""
        alt="Travel photo"
        class="modal-image"
        id="modalImage"
    >

    <div
        class="photo-attribution modal-attribution"
        id="modalAttribution"
        hidden
    ></div>


    <button
        type="button"
        class="modal-arrow modal-next"
        id="modalNext"
    >

        <i class="fa-solid fa-chevron-right"></i>

    </button>


</div>


<script>

/* =========================================================
   DATE-WISE PACKAGE CATEGORY BOOKING CALCULATOR
========================================================= */

const packageDatePrices =
<?= json_encode(
    array_map(
        function ($row) {
            return [
                'valid_from' => (string)$row['valid_from'],
                'valid_to' => (string)$row['valid_to'],
                '3' => $row['price_3_star'] !== null
                    ? (float)$row['price_3_star']
                    : null,
                '4' => $row['price_4_star'] !== null
                    ? (float)$row['price_4_star']
                    : null,
                '5' => $row['price_5_star'] !== null
                    ? (float)$row['price_5_star']
                    : null
            ];
        },
        $packageDatePrices
    ),
    JSON_UNESCAPED_SLASHES
); ?>;

const packageOccupancyPrices =
<?= json_encode(
    array_map(
        static function(array $row): array {
            return [
                'valid_from' => (string)($row['valid_from'] ?? ''),
                'valid_to' => (string)($row['valid_to'] ?? ''),
                'star' => (string)($row['star_category'] ?? ''),
                'twin_price' => $row['twin_price'] !== null ? (float)$row['twin_price'] : null,
                'triple_price' => $row['triple_price'] !== null ? (float)$row['triple_price'] : null,
                'single_price' => $row['single_price'] !== null ? (float)$row['single_price'] : null,
                'child_with_bed_price' => $row['child_with_bed_price'] !== null ? (float)$row['child_with_bed_price'] : null,
                'child_without_bed_price' => $row['child_without_bed_price'] !== null ? (float)$row['child_without_bed_price'] : null,
                'infant_price' => $row['infant_price'] !== null ? (float)$row['infant_price'] : null,
                'infant_age_to' => max(0, (int)($row['infant_age_to'] ?? 2)),
            ];
        },
        $packageOccupancyPrices
    ),
    JSON_UNESCAPED_SLASHES
); ?>;

const legacyPackagePrice =
    <?= json_encode((float)($package['sale_price'] ?? 0)); ?>;

const currency =
    <?= json_encode($currencySymbol); ?>;

const packageChildPrices =
<?= json_encode(
    array_map(
        static function (array $row): array {
            return [
                'age_from' => max(0, (int)($row['age_from'] ?? 0)),
                'age_to' => max(0, (int)($row['age_to'] ?? 0)),
                'price' => max(0, (float)($row['price'] ?? 0))
            ];
        },
        $packageChildPrices
    ),
    JSON_UNESCAPED_SLASHES
); ?>;

const childUnitPrice =
    <?= json_encode($childUnitPrice); ?>;

const childAgeMin =
    <?= json_encode($childAgeMin); ?>;

const childAgeMax =
    <?= json_encode($childAgeMax); ?>;

const hasChildAgeRange =
    <?= $hasChildAgeRange ? 'true' : 'false'; ?>;

function childPriceForAge(age) {
    const numericAge = Number(age);

    if (!Number.isFinite(numericAge)) {
        return 0;
    }

    const matchedRow =
        packageChildPrices.find(function(row) {
            return numericAge >= Number(row.age_from)
                && numericAge <= Number(row.age_to);
        });

    return matchedRow
        ? Number(matchedRow.price || 0)
        : 0;
}

function occupancyRowFor(dateValue, star) {
    if (!dateValue || !star) {
        return null;
    }

    return packageOccupancyPrices.find(function(row) {
        return String(row.star) === String(star)
            && dateValue >= row.valid_from
            && dateValue <= row.valid_to;
    }) || null;
}

function occupancyAdultPrice(row, type) {
    if (!row) return 0;

    const field = {
        twin: 'twin_price',
        triple: 'triple_price',
        single: 'single_price'
    }[type] || 'twin_price';

    return row[field] === null || row[field] === undefined
        ? 0
        : Number(row[field] || 0);
}

function selectedOccupancyType() {
    const select = document.getElementById('occupancyTypeSelect');
    const value = String(select?.value || 'twin');

    return ['twin','triple','single'].includes(value)
        ? value
        : 'twin';
}

function smartChildPrice(age, bedType, row) {
    const numericAge = Number(age);

    if (!Number.isFinite(numericAge)) {
        return 0;
    }

    if (row) {
        const infantAgeTo = Number(row.infant_age_to ?? 2);

        if (
            numericAge <= infantAgeTo
            && row.infant_price !== null
            && row.infant_price !== undefined
        ) {
            return Number(row.infant_price || 0);
        }

        if (
            bedType === 'with_bed'
            && row.child_with_bed_price !== null
            && row.child_with_bed_price !== undefined
        ) {
            return Number(row.child_with_bed_price || 0);
        }

        if (
            bedType === 'without_bed'
            && row.child_without_bed_price !== null
            && row.child_without_bed_price !== undefined
        ) {
            return Number(row.child_without_bed_price || 0);
        }
    }

    return childPriceForAge(numericAge);
}

const adults =
    document.getElementById('adults');

const children =
    document.getElementById('children');

const travelDateInput =
    document.getElementById('travelDate');

const occupancyTypeSelect =
    document.getElementById('occupancyTypeSelect');

const adultLabel =
    document.getElementById('adultLabel');

const adultAmount =
    document.getElementById('adultAmount');

const childLabel =
    document.getElementById('childLabel');

const childAmount =
    document.getElementById('childAmount');

const childRow =
    document.getElementById('childRow');

const childAgeSection =
    document.getElementById('childAgeSection');

const childAgeFields =
    document.getElementById('childAgeFields');

const estimatedTotal =
    document.getElementById('estimatedTotal');

const selectedUnitPrice =
    document.getElementById('selectedUnitPrice');

const displayPackagePriceAmount =
    document.getElementById('displayPackagePriceAmount');

const currentPricePeriod =
    document.getElementById('currentPricePeriod');

const priceUnavailableNote =
    document.getElementById('priceUnavailableNote');

const mobileCurrentPrice =
    document.getElementById('mobileCurrentPrice');

let activePriceRow =
    null;

let activeOccupancyRow =
    null;

const requestedHotelCategory =
    <?= json_encode($currentSelectedHotelCategory); ?>;

const packageHotelNames =
    <?= json_encode($packageHotelNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

let packagePrice =
    <?= json_encode($currentStartingPrice); ?>;


function money(amount) {
    return currency
        + Number(amount).toLocaleString(
            'en-IN',
            {
                maximumFractionDigits:0
            }
        );
}


function displayDate(dateValue) {
    if (!dateValue) {
        return '';
    }

    const date = new Date(dateValue + 'T00:00:00');

    return date.toLocaleDateString(
        'en-IN',
        {
            day:'2-digit',
            month:'short',
            year:'numeric'
        }
    );
}


function findPriceRow(dateValue) {
    if (!dateValue) {
        return packageDatePrices.find(function (row) {
            const today = new Date().toISOString().slice(0, 10);

            return today >= row.valid_from
                && today <= row.valid_to;
        }) || packageDatePrices[0] || null;
    }

    return packageDatePrices.find(function (row) {
        return dateValue >= row.valid_from
            && dateValue <= row.valid_to;
    }) || null;
}


function selectedHotelCategory() {
    const select =
        document.getElementById(
            'hotelCategorySelect'
        );

    return select
        ? String(select.value || '')
        : '';
}


function availableAdultPrice(row, dateValue, star) {
    const smartRow =
        occupancyRowFor(
            dateValue,
            star
        );

    const smartPrice =
        occupancyAdultPrice(
            smartRow,
            selectedOccupancyType()
        );

    if (smartRow) {
        return smartPrice;
    }

    return row
        ? Number(row[star] || 0)
        : 0;
}

function firstAvailableCategory(row, dateValue) {
    for (const star of ['3', '4', '5']) {
        if (availableAdultPrice(row, dateValue, star) > 0) {
            return star;
        }
    }

    return '';
}


function updateHotelOptions(row, dateValue) {
    const select =
        document.getElementById(
            'hotelCategorySelect'
        );

    const priceBox =
        document.getElementById(
            'selectedHotelPriceText'
        );

    if (!select) {
        return;
    }

    const previousCategory =
        String(select.value || '');

    let categoryToSelect = '';

    if (
        previousCategory
        && availableAdultPrice(row, dateValue, previousCategory) > 0
    ) {
        categoryToSelect =
            previousCategory;

    } else if (
        requestedHotelCategory
        && availableAdultPrice(row, dateValue, requestedHotelCategory) > 0
    ) {
        categoryToSelect =
            requestedHotelCategory;

    } else {
        categoryToSelect =
            firstAvailableCategory(row, dateValue);
    }

    ['3', '4', '5'].forEach(function (star) {
        const option =
            select.querySelector(
                'option[value="' + star + '"]'
            );

        if (!option) {
            return;
        }

        const price =
            availableAdultPrice(
                row,
                dateValue,
                star
            );

        const available =
            price > 0;

        option.disabled =
            !available;

        const hotelName = packageHotelNames[star] || '';

        option.textContent =
            star
            + ' Star Package'
            + (hotelName ? ' — ' + hotelName : '')
            + (
                available
                    ? ' — '
                        + money(price)
                        + ' / adult'
                    : ' — Unavailable'
            );
    });

    select.value =
        categoryToSelect;

    const selectedPrice =
        categoryToSelect
            ? availableAdultPrice(
                row,
                dateValue,
                categoryToSelect
            )
            : 0;

    if (priceBox) {
        priceBox.textContent =
            selectedPrice > 0
                ? money(selectedPrice)
                    + ' / adult'
                : 'Select a package category';
    }
}

function updatePricePeriod() {
    const dateValue =
        travelDateInput?.value || '';

    activePriceRow =
        findPriceRow(dateValue);

    const effectiveDate =
        dateValue
        || new Date().toISOString().slice(0, 10);

    updateHotelOptions(
        activePriceRow,
        effectiveDate
    );

    const category =
        selectedHotelCategory();

    activeOccupancyRow =
        category
            ? occupancyRowFor(
                effectiveDate,
                category
            )
            : null;

    const smartAdultPrice =
        occupancyAdultPrice(
            activeOccupancyRow,
            selectedOccupancyType()
        );

    const legacyAdultPrice =
        activePriceRow
        && category
            ? Number(activePriceRow[category] || 0)
            : 0;

    const effectiveAdultPrice =
        activeOccupancyRow
            ? smartAdultPrice
            : legacyAdultPrice;

    if (
        category
        && effectiveAdultPrice > 0
    ) {
        packagePrice =
            effectiveAdultPrice;

        if (selectedUnitPrice) {
            selectedUnitPrice.value =
                String(packagePrice);
        }

        if (displayPackagePriceAmount) {
            displayPackagePriceAmount.textContent =
                Number(packagePrice).toLocaleString(
                    'en-IN',
                    {
                        maximumFractionDigits:0
                    }
                );
        }

        if (mobileCurrentPrice) {
            mobileCurrentPrice.textContent =
                money(packagePrice);
        }

        if (currentPricePeriod) {
            currentPricePeriod.textContent =
                'Valid '
                + displayDate(activePriceRow.valid_from)
                + ' – '
                + displayDate(activePriceRow.valid_to);
        }

        if (priceUnavailableNote) {
            priceUnavailableNote.style.display =
                'none';
        }

    } else {
        packagePrice =
            packageDatePrices.length
                ? 0
                : legacyPackagePrice;

        if (selectedUnitPrice) {
            selectedUnitPrice.value =
                String(packagePrice);
        }

        if (displayPackagePriceAmount) {
            displayPackagePriceAmount.textContent =
                packagePrice > 0
                    ? Number(packagePrice).toLocaleString('en-IN')
                    : 'Unavailable';
        }

        if (mobileCurrentPrice) {
            mobileCurrentPrice.textContent =
                packagePrice > 0
                    ? money(packagePrice)
                    : 'Unavailable';
        }

        if (currentPricePeriod) {
            currentPricePeriod.textContent =
                dateValue
                    ? 'No configured price for this travel date.'
                    : 'Select a travel date to check availability.';
        }

        if (priceUnavailableNote) {
            priceUnavailableNote.style.display =
                packageDatePrices.length
                    ? 'block'
                    : 'none';
        }
    }

    updateEstimate();
}


function renderChildAgeFields() {
    const childCount =
        parseInt(
            children?.value || 0,
            10
        );

    if (!childAgeSection || !childAgeFields) {
        return;
    }

    if (childCount <= 0) {
        childAgeSection.style.display = 'none';
        childAgeFields.innerHTML = '';
        return;
    }

    childAgeSection.style.display = 'block';

    const previousAges =
        Array.from(
            childAgeFields.querySelectorAll(
                'select[name="child_ages[]"]'
            )
        ).map(function(select) {
            return String(select.value || '');
        });

    const previousBeds =
        Array.from(
            childAgeFields.querySelectorAll(
                'select[name="child_bed_types[]"]'
            )
        ).map(function(select) {
            return String(select.value || '');
        });

    childAgeFields.innerHTML = '';

    const minimumAge =
        packageOccupancyPrices.length
            ? 0
            : (
                packageChildPrices.length
                    ? childAgeMin
                    : (hasChildAgeRange ? childAgeMin : 0)
            );

    const maximumAge =
        packageOccupancyPrices.length
            ? 17
            : (
                packageChildPrices.length
                    ? childAgeMax
                    : (hasChildAgeRange ? childAgeMax : 17)
            );

    for (let index = 0; index < childCount; index++) {
        const item =
            document.createElement('div');

        item.className =
            'child-age-item';

        item.style.display = 'grid';
        item.style.gridTemplateColumns = '1fr 1fr';
        item.style.gap = '8px';

        const ageWrap =
            document.createElement('div');

        const label =
            document.createElement('label');

        label.textContent =
            'Child '
            + (index + 1)
            + ' Age';

        const select =
            document.createElement('select');

        select.name =
            'child_ages[]';

        select.required =
            true;

        for (
            let age = minimumAge;
            age <= maximumAge;
            age++
        ) {
            const option =
                document.createElement('option');

            option.value =
                String(age);

            option.textContent =
                age
                + (
                    age === 1
                        ? ' year'
                        : ' years'
                );

            select.appendChild(option);
        }

        if (
            previousAges[index]
            && Number(previousAges[index]) >= minimumAge
            && Number(previousAges[index]) <= maximumAge
        ) {
            select.value =
                previousAges[index];
        }

        const bedWrap =
            document.createElement('div');

        const bedLabel =
            document.createElement('label');

        bedLabel.textContent =
            'Fare Basis';

        const bedSelect =
            document.createElement('select');

        bedSelect.name =
            'child_bed_types[]';

        bedSelect.required =
            true;

        function rebuildBedOptions() {
            const saved =
                previousBeds[index]
                || bedSelect.value
                || 'with_bed';

            const age =
                Number(select.value || 0);

            bedSelect.innerHTML = '';

            if (activeOccupancyRow) {
                const infantAgeTo =
                    Number(
                        activeOccupancyRow.infant_age_to
                        ?? 2
                    );

                if (age <= infantAgeTo) {
                    const option =
                        document.createElement('option');

                    option.value = 'infant';
                    option.textContent =
                        'Infant'
                        + (
                            activeOccupancyRow.infant_price !== null
                            && activeOccupancyRow.infant_price !== undefined
                                ? ' — ' + money(activeOccupancyRow.infant_price)
                                : ''
                        );

                    bedSelect.appendChild(option);
                    bedSelect.value = 'infant';
                    return;
                }

                [
                    ['with_bed', 'With Bed', 'child_with_bed_price'],
                    ['without_bed', 'Without Bed', 'child_without_bed_price']
                ].forEach(function(entry) {
                    const option =
                        document.createElement('option');

                    option.value = entry[0];

                    const configured =
                        activeOccupancyRow[entry[2]] !== null
                        && activeOccupancyRow[entry[2]] !== undefined;

                    option.textContent =
                        entry[1]
                        + (
                            configured
                                ? ' — ' + money(activeOccupancyRow[entry[2]])
                                : ' — age fare'
                        );

                    bedSelect.appendChild(option);
                });

                if (
                    ['with_bed','without_bed'].includes(saved)
                ) {
                    bedSelect.value = saved;
                }

            } else {
                const option =
                    document.createElement('option');

                option.value = 'legacy';
                option.textContent = 'Age-based fare';
                bedSelect.appendChild(option);
            }
        }

        select.addEventListener(
            'change',
            function() {
                rebuildBedOptions();
                updateEstimate();
            }
        );

        bedSelect.addEventListener(
            'change',
            updateEstimate
        );

        ageWrap.appendChild(label);
        ageWrap.appendChild(select);

        bedWrap.appendChild(bedLabel);
        bedWrap.appendChild(bedSelect);

        item.appendChild(ageWrap);
        item.appendChild(bedWrap);

        childAgeFields.appendChild(item);

        rebuildBedOptions();
    }
}

function updateEstimate() {
    const adultCount =
        parseInt(
            adults?.value || 1,
            10
        );

    const childCount =
        parseInt(
            children?.value || 0,
            10
        );

    renderChildAgeFields();

    const adultTotal =
        adultCount * packagePrice;

    const childAgeInputs =
        Array.from(
            document.querySelectorAll(
                'select[name="child_ages[]"]'
            )
        );

    const childBedInputs =
        Array.from(
            document.querySelectorAll(
                'select[name="child_bed_types[]"]'
            )
        );

    const childTotal =
        childAgeInputs.reduce(
            function(total, select, index) {
                const bedType =
                    childBedInputs[index]?.value
                    || 'legacy';

                return total
                    + smartChildPrice(
                        select.value,
                        bedType,
                        activeOccupancyRow
                    );
            },
            0
        );

    const grandTotal =
        adultTotal + childTotal;

    if (adultLabel) {
        adultLabel.textContent =
            adultCount
            + (
                adultCount === 1
                    ? ' Adult'
                    : ' Adults'
            );
    }

    if (adultAmount) {
        adultAmount.textContent =
            packagePrice > 0
                ? money(adultTotal)
                : '-';
    }

    if (childCount > 0) {
        if (childRow) {
            childRow.style.display = 'flex';
        }

        if (childLabel) {
            childLabel.textContent =
                childCount
                + (
                    childCount === 1
                        ? ' Child'
                        : ' Children'
                );
        }

        if (childAmount) {
            childAmount.textContent =
                packageChildPrices.length
                    ? money(childTotal)
                    : (
                        childUnitPrice > 0
                            ? money(childCount * childUnitPrice)
                            : 'Included / No charge'
                    );
        }

    } else if (childRow) {
        childRow.style.display = 'none';
    }

    if (estimatedTotal) {
        estimatedTotal.textContent =
            packagePrice > 0
                ? money(grandTotal)
                : '-';
    }


}


adults?.addEventListener(
    'change',
    updateEstimate
);

children?.addEventListener(
    'change',
    updateEstimate
);

travelDateInput?.addEventListener(
    'change',
    updatePricePeriod
);

const hotelCategorySelect =
    document.getElementById(
        'hotelCategorySelect'
    );

hotelCategorySelect?.addEventListener(
    'change',
    updatePricePeriod
);

occupancyTypeSelect?.addEventListener(
    'change',
    updatePricePeriod
);

updatePricePeriod();


/* =========================================================
   BOOKING FORM VALIDATION
========================================================= */

const bookingForm =
    document.getElementById(
        'bookingForm'
    );


bookingForm.addEventListener(
    'submit',
    function(event) {

        const travelDate =
            document.getElementById(
                'travelDate'
            );

        if (!travelDate.value) {

            event.preventDefault();

            travelDate.focus();

            alert(
                'Please select your travel date.'
            );

            return;
        }

        const hotelCategory =
            selectedHotelCategory();

        if (
            !hotelCategory
            || Number(selectedUnitPrice?.value || 0) <= 0
        ) {
            event.preventDefault();

            alert(
                'No package price is available for the selected date and package category.'
            );

            return;
        }

        const childCount =
            parseInt(
                children?.value || 0,
                10
            );

        if (childCount > 0) {
            const childAgeInputs =
                document.querySelectorAll(
                    'select[name="child_ages[]"]'
                );

            if (childAgeInputs.length !== childCount) {
                event.preventDefault();

                alert(
                    'Please enter the age of every child.'
                );

                return;
            }

            if (packageOccupancyPrices.length) {
                const childBedInputs =
                    document.querySelectorAll(
                        'select[name="child_bed_types[]"]'
                    );

                if (childBedInputs.length !== childCount) {
                    event.preventDefault();

                    alert(
                        'Please select the fare basis for every child.'
                    );

                    return;
                }
            }

            if (hasChildAgeRange && !packageOccupancyPrices.length) {
                for (const ageInput of childAgeInputs) {
                    const age =
                        parseInt(
                            ageInput.value,
                            10
                        );

                    if (
                        Number.isNaN(age)
                        || age < childAgeMin
                        || age > childAgeMax
                    ) {
                        event.preventDefault();

                        alert(
                            'Child age must be between '
                            + childAgeMin
                            + ' and '
                            + childAgeMax
                            + ' years for this package.'
                        );

                        ageInput.focus();

                        return;
                    }
                }
            }
        }

    }
);


/* =========================================================
   MOBILE BOOK
========================================================= */

const mobileBookButton =
    document.getElementById(
        'mobileBookButton'
    );


mobileBookButton?.addEventListener(
    'click',
    function() {

        /*
         * Mobile booking card is hidden,
         * therefore take user directly to
         * booking page with default travellers.
         */

        const url =
            new URL(
                'book-package.php',
                window.location.href
            );

        url.searchParams.set(
            'package',
            <?= json_encode($packageId); ?>
        );

        url.searchParams.set(
            'adults',
            '2'
        );

        url.searchParams.set(
            'children',
            '0'
        );

        url.searchParams.set(
            'child_unit_price',
            <?= json_encode($childUnitPrice); ?>
        );

        const selectedCategory =
            selectedHotelCategory();

        if (travelDateInput?.value) {
            url.searchParams.set(
                'travel_date',
                travelDateInput.value
            );
        }

        if (selectedCategory) {
            url.searchParams.set(
                'hotel_category',
                selectedCategory
            );
        }

        url.searchParams.set(
            'occupancy_type',
            selectedOccupancyType()
        );

        if (packagePrice > 0) {
            url.searchParams.set(
                'unit_price',
                String(packagePrice)
            );
        }

        window.location.href =
            url.toString();

    }
);


/* =========================================================
   IMAGE GALLERY
========================================================= */

const galleryImages =
<?= json_encode(
    array_map(
        function($image) {

            return travelImage(
                $image['image']
            );

        },
        $images
    ),
    JSON_UNESCAPED_SLASHES
); ?>;


const galleryAttributions =
<?= json_encode(
    array_map(
        function($image) {

            $credit =
                $image['attribution']
                ?? null;

            if (!$credit) {
                return null;
            }

            return [
                'photographer' =>
                    (string)(
                        $credit['photographer_name']
                        ?? ''
                    ),

                'photographer_url' =>
                    (string)(
                        $credit['photographer_url']
                        ?? ''
                    ),

                'provider' =>
                    (string)(
                        $credit['provider']
                        ?? 'Pexels'
                    ),

                'photo_url' =>
                    (string)(
                        $credit['photo_page_url']
                        ?? ''
                    )
            ];

        },
        $images
    ),
    JSON_UNESCAPED_SLASHES
); ?>;


let currentImage = 0;

const modal =
    document.getElementById(
        'photoModal'
    );

const modalImage =
    document.getElementById(
        'modalImage'
    );


const modalAttribution =
    document.getElementById(
        'modalAttribution'
    );


function showImage(index) {

    if (!galleryImages.length) {
        return;
    }

    if (index < 0) {

        index =
            galleryImages.length - 1;
    }

    if (
        index
        >=
        galleryImages.length
    ) {

        index = 0;
    }

    currentImage = index;

    modalImage.src =
        galleryImages[
            currentImage
        ];


    const credit =
        galleryAttributions[
            currentImage
        ]
        ?? null;


    if (modalAttribution) {

        if (
            credit
            &&
            (
                credit.photographer
                ||
                credit.provider
            )
        ) {

            const photographerHtml =
                credit.photographer

                    ? (
                        credit.photographer_url

                            ? '<a href="'
                                + credit.photographer_url
                                + '" target="_blank" rel="noopener noreferrer">'
                                + 'Photo by '
                                + credit.photographer
                                + '</a>'

                            : '<span>Photo by '
                                + credit.photographer
                                + '</span>'
                    )

                    : '';


            const providerHtml =
                credit.provider

                    ? (
                        credit.photo_url

                            ? '<a href="'
                                + credit.photo_url
                                + '" target="_blank" rel="noopener noreferrer">'
                                + credit.provider
                                + '</a>'

                            : '<span>'
                                + credit.provider
                                + '</span>'
                    )

                    : '';


            modalAttribution.innerHTML =
                photographerHtml

                + (
                    photographerHtml
                    &&
                    providerHtml

                        ? '<span class="photo-attribution-separator">/</span>'

                        : ''
                )

                + providerHtml;


            modalAttribution.hidden =
                false;

        } else {

            modalAttribution.innerHTML =
                '';

            modalAttribution.hidden =
                true;
        }
    }


    modal.classList.add(
        'open'
    );

    document.body.style.overflow =
        'hidden';
}


function closeGallery() {

    modal.classList.remove(
        'open'
    );

    document.body.style.overflow =
        '';
}


document
.querySelectorAll(
    '.gallery-click'
)
.forEach(
    function(element) {

        element.addEventListener(
            'click',
            function(event) {

                event.preventDefault();

                showImage(
                    parseInt(
                        this.dataset.index
                        || 0
                    )
                );

            }
        );

    }
);


document
.getElementById(
    'modalClose'
)
.addEventListener(
    'click',
    closeGallery
);


document
.getElementById(
    'modalPrev'
)
.addEventListener(
    'click',
    function() {

        showImage(
            currentImage - 1
        );

    }
);


document
.getElementById(
    'modalNext'
)
.addEventListener(
    'click',
    function() {

        showImage(
            currentImage + 1
        );

    }
);


modal.addEventListener(
    'click',
    function(event) {

        if (event.target === modal) {
            closeGallery();
        }

    }
);


document.addEventListener(
    'keydown',
    function(event) {

        if (
            !modal.classList.contains(
                'open'
            )
        ) {
            return;
        }

        if (event.key === 'Escape') {
            closeGallery();
        }

        if (event.key === 'ArrowLeft') {

            showImage(
                currentImage - 1
            );
        }

        if (event.key === 'ArrowRight') {

            showImage(
                currentImage + 1
            );
        }

    }
);

</script>




<script>
(function () {
    const button = document.getElementById('packageShareButton');
    const menu = document.getElementById('packageShareMenu');
    const copyStatus = document.getElementById('shareCopyStatus');

    if (!button || !menu) return;

    const shareTitle = <?= json_encode($package['title'] . ' | ' . $siteName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const shareText = <?= json_encode('Check out this travel package: ' . $package['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const shareUrl = window.location.href;

    function closeShareMenu() {
        menu.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    }

    function openWindow(url) {
        window.open(url, '_blank', 'noopener,noreferrer,width=720,height=620');
    }

    async function copyPackageLink() {
        try {
            await navigator.clipboard.writeText(shareUrl);
        } catch (error) {
            const textarea = document.createElement('textarea');
            textarea.value = shareUrl;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }

        if (copyStatus) {
            copyStatus.classList.add('show');
            setTimeout(() => copyStatus.classList.remove('show'), 2200);
        }
    }

    button.addEventListener('click', function (event) {
        event.stopPropagation();
        const isOpen = menu.classList.toggle('open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    menu.addEventListener('click', async function (event) {
        const option = event.target.closest('[data-share]');
        if (!option) return;

        event.stopPropagation();

        const type = option.dataset.share;
        const encodedUrl = encodeURIComponent(shareUrl);
        const encodedText = encodeURIComponent(shareText);
        const encodedTitle = encodeURIComponent(shareTitle);

        if (type === 'whatsapp') {
            openWindow('https://wa.me/?text=' + encodedText + '%20' + encodedUrl);
        } else if (type === 'facebook') {
            openWindow('https://www.facebook.com/sharer/sharer.php?u=' + encodedUrl);
        } else if (type === 'twitter') {
            openWindow('https://twitter.com/intent/tweet?text=' + encodedText + '&url=' + encodedUrl);
        } else if (type === 'email') {
            window.location.href = 'mailto:?subject=' + encodedTitle + '&body=' + encodedText + '%0A%0A' + encodedUrl;
        } else if (type === 'copy') {
            await copyPackageLink();
            return;
        } else if (type === 'native') {
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: shareTitle,
                        text: shareText,
                        url: shareUrl
                    });
                } catch (error) {
                    if (error && error.name !== 'AbortError') {
                        await copyPackageLink();
                    }
                }
            } else {
                await copyPackageLink();
                return;
            }
        }

        closeShareMenu();
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target) && event.target !== button) {
            closeShareMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeShareMenu();
        }
    });
})();
</script>


<script src="assets/travscope-pro-public.js?v=20260916-pro" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
</body>
</html>