<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM
| PROFESSIONAL PACKAGES PAGE
| DATE-WISE HOTEL CATEGORY SEARCH VERSION: 2026-08-05-R2-DETAIL-LINK-FIX
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/package-action-mode.php';
require_once __DIR__ . '/package-currency-functions.php';
require_once __DIR__ . '/package-type-assignments.php';


/*
|--------------------------------------------------------------------------
| OPTIONAL LOGGED-IN USER
|--------------------------------------------------------------------------
*/

$user = null;
$userId = 0;

if (isLoggedIn()) {

    $user = currentUser($pdo);

    if ($user && strtolower((string)$user['status']) === 'active') {

        $userId = (int)$user['id'];

    } else {

        unset($_SESSION['user_id']);
        $user = null;
        $userId = 0;
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




/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function packageImage(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1200&q=85';
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    return BASE_URL . ltrim($image, '/');
}


function packageDiscount(
    $regularPrice,
    $salePrice
): int {

    $regular = (float)$regularPrice;
    $sale = (float)$salePrice;

    if ($regular <= 0 || $sale <= 0 || $sale >= $regular) {
        return 0;
    }

    return (int)round(
        (($regular - $sale) / $regular) * 100
    );
}



function normalizeHotelCategory(string $value): string
{
    $value = trim($value);
    return in_array($value, ['3', '4', '5'], true) ? $value : '';
}

function packagePriceFieldForCategory(string $category): string
{
    return match ($category) {
        '3' => 'price_3_star',
        '4' => 'price_4_star',
        '5' => 'price_5_star',
        default => ''
    };
}

function packageDisplayPrice(array $package): float
{
    $dynamic = (float)($package['matched_price'] ?? 0);
    if ($dynamic > 0) return $dynamic;

    $sale = (float)($package['sale_price'] ?? 0);
    $regular = (float)($package['regular_price'] ?? 0);
    return $sale > 0 ? $sale : $regular;
}

function packageDisplayCategoryLabel(array $package): string
{
    $category = trim((string)($package['matched_hotel_category'] ?? ''));
    return in_array($category, ['3', '4', '5'], true)
        ? $category . ' Star'
        : 'Best Available';
}

/*
|--------------------------------------------------------------------------
| WISHLIST ACTION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['wishlist_action'])
) {

    /*
    |--------------------------------------------------------------------------
    | LOGIN REQUIRED
    |--------------------------------------------------------------------------
    */

    if (!$userId) {

        setFlash(
            'error',
            'Please login to save packages to your wishlist.'
        );

        redirect(
            BASE_URL . 'login.php'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($csrf)) {

        setFlash(
            'error',
            'Your session expired. Please try again.'
        );

        redirect(
            BASE_URL . 'packages.php'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PACKAGE ID
    |--------------------------------------------------------------------------
    */

    $packageId =
        isset($_POST['package_id'])
            ? (int)$_POST['package_id']
            : 0;

    if ($packageId <= 0) {

        setFlash(
            'error',
            'Invalid package selected.'
        );

        redirect(
            BASE_URL . 'packages.php'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY PACKAGE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM packages
        WHERE
            id = ?
            AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$packageId]);

    if (!$stmt->fetchColumn()) {

        setFlash(
            'error',
            'This travel package is not available.'
        );

        redirect(
            BASE_URL . 'packages.php'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK WISHLIST
    |--------------------------------------------------------------------------
    */

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

    $wishlistId =
        $stmt->fetchColumn();


    if ($wishlistId) {

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
            'Package removed from your wishlist.'
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
            'Package saved to your wishlist.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RETURN WITH CURRENT FILTERS
    |--------------------------------------------------------------------------
    */

    $returnUrl =
        trim(
            (string)(
                $_POST['return_url']
                ?? 'packages.php'
            )
        );

    /*
     * Prevent external redirects.
     */
    if (
        $returnUrl === ''
        ||
        preg_match('#^https?://#i', $returnUrl)
        ||
        str_contains($returnUrl, "\r")
        ||
        str_contains($returnUrl, "\n")
    ) {
        $returnUrl = 'packages.php';
    }

    redirect(
        BASE_URL . ltrim($returnUrl, '/')
    );
}


/*
|--------------------------------------------------------------------------
| FILTER VALUES
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

$destinationId =
    isset($_GET['destination'])
        ? max(0, (int)$_GET['destination'])
        : 0;

$packageType =
    strtolower(
        trim(
            (string)(
                $_GET['type']
                ?? ''
            )
        )
    );

$journeyDate =
    trim(
        (string)(
            $_GET['journey_date']
            ?? ''
        )
    );

if (
    $journeyDate !== ''
    &&
    !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $journeyDate)
) {
    $journeyDate = '';
}

$hotelCategory =
    normalizeHotelCategory(
        (string)($_GET['hotel_category'] ?? '')
    );

$priceRange =
    trim(
        (string)(
            $_GET['price']
            ?? ''
        )
    );

$sort =
    trim(
        (string)(
            $_GET['sort']
            ?? 'recommended'
        )
    );


/*
|--------------------------------------------------------------------------
| ALLOWED PACKAGE TYPES
|--------------------------------------------------------------------------
|
| Your enum may contain additional values.
| We retrieve the actual active values from packages so we do not depend
| on guessing the full enum.
|--------------------------------------------------------------------------
*/

$packageTypes = [];
$packageTypeSlugs = [];

try {
    $typeStmt = $pdo->query("
        SELECT id, name, slug
        FROM package_types
        WHERE status = 'active'
        ORDER BY sort_order ASC, name ASC, id ASC
    ");
    $packageTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packageTypes as $typeRow) {
        $slug = strtolower(trim((string)($typeRow['slug'] ?? '')));
        if ($slug !== '') {
            $packageTypeSlugs[] = $slug;
        }
    }
} catch (Throwable $e) {
    error_log('Packages package types: ' . $e->getMessage());
}

$packageType = strtolower($packageType);

if (
    $packageType !== ''
    &&
    !in_array($packageType, $packageTypeSlugs, true)
) {
    $packageType = '';
}


/*
|--------------------------------------------------------------------------
| SORT OPTIONS
|--------------------------------------------------------------------------
*/

$allowedSorts = [
    'recommended',
    'price_low',
    'price_high',
    'duration_short',
    'duration_long',
    'popular',
    'newest'
];

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = 'recommended';
}


/*
|--------------------------------------------------------------------------
| DESTINATIONS
|--------------------------------------------------------------------------
*/

$destinationStmt = $pdo->query("
    SELECT
        id,
        name,
        country,
        continent
    FROM destinations
    WHERE status = 'active'
    ORDER BY
        sort_order ASC,
        name ASC
");

$destinations =
    $destinationStmt->fetchAll();

$tsSelectedDestinationName = '';
if ($tsDestinationListing) {
    foreach ($destinations as $tsDestinationRow) {
        if ((int)$tsDestinationRow['id'] === $destinationId) {
            $tsSelectedDestinationName = trim((string)$tsDestinationRow['name']);
            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| DATE-WISE HOTEL CATEGORY PRICE EXPRESSIONS
|--------------------------------------------------------------------------
*/

$effectiveJourneyDate =
    $journeyDate !== ''
        ? $journeyDate
        : date('Y-m-d');

$selectedPriceField =
    packagePriceFieldForCategory($hotelCategory);

if ($selectedPriceField !== '') {
    $matchedPriceExpression = "
        (
            SELECT pdp.{$selectedPriceField}
            FROM package_date_prices pdp
            WHERE pdp.package_id = p.id
              AND ? BETWEEN pdp.valid_from AND pdp.valid_to
              AND pdp.{$selectedPriceField} IS NOT NULL
              AND pdp.{$selectedPriceField} > 0
            ORDER BY pdp.valid_from DESC, pdp.id DESC
            LIMIT 1
        )
    ";

    $matchedCategoryExpression = $pdo->quote($hotelCategory);
} else {
    $matchedPriceExpression = "
        (
            SELECT LEAST(
                COALESCE(NULLIF(pdp.price_3_star, 0), 999999999),
                COALESCE(NULLIF(pdp.price_4_star, 0), 999999999),
                COALESCE(NULLIF(pdp.price_5_star, 0), 999999999)
            )
            FROM package_date_prices pdp
            WHERE pdp.package_id = p.id
              AND ? BETWEEN pdp.valid_from AND pdp.valid_to
              AND (
                    COALESCE(pdp.price_3_star, 0) > 0
                 OR COALESCE(pdp.price_4_star, 0) > 0
                 OR COALESCE(pdp.price_5_star, 0) > 0
              )
            ORDER BY pdp.valid_from DESC, pdp.id DESC
            LIMIT 1
        )
    ";

    $matchedCategoryExpression = "
        (
            SELECT CASE
                WHEN COALESCE(pdp.price_3_star, 0) > 0
                     AND pdp.price_3_star = LEAST(
                        COALESCE(NULLIF(pdp.price_3_star, 0), 999999999),
                        COALESCE(NULLIF(pdp.price_4_star, 0), 999999999),
                        COALESCE(NULLIF(pdp.price_5_star, 0), 999999999)
                     ) THEN '3'
                WHEN COALESCE(pdp.price_4_star, 0) > 0
                     AND pdp.price_4_star = LEAST(
                        COALESCE(NULLIF(pdp.price_3_star, 0), 999999999),
                        COALESCE(NULLIF(pdp.price_4_star, 0), 999999999),
                        COALESCE(NULLIF(pdp.price_5_star, 0), 999999999)
                     ) THEN '4'
                WHEN COALESCE(pdp.price_5_star, 0) > 0 THEN '5'
                ELSE ''
            END
            FROM package_date_prices pdp
            WHERE pdp.package_id = p.id
              AND ? BETWEEN pdp.valid_from AND pdp.valid_to
              AND (
                    COALESCE(pdp.price_3_star, 0) > 0
                 OR COALESCE(pdp.price_4_star, 0) > 0
                 OR COALESCE(pdp.price_5_star, 0) > 0
              )
            ORDER BY pdp.valid_from DESC, pdp.id DESC
            LIMIT 1
        )
    ";
}

/*
|--------------------------------------------------------------------------
| BUILD PACKAGE QUERY
|--------------------------------------------------------------------------
*/

$where = [
    "p.status = 'active'"
];

$params = [];


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "
        (
            p.title LIKE ?
            OR p.short_description LIKE ?
            OR p.description LIKE ?
            OR p.package_code LIKE ?
            OR d.name LIKE ?
            OR d.country LIKE ?
            OR d.continent LIKE ?
        )
    ";

    $searchTerm =
        '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchTerm;
    }
}


/*
|--------------------------------------------------------------------------
| DESTINATION
|--------------------------------------------------------------------------
*/

if ($destinationId > 0) {

    $where[] =
        "p.destination_id = ?";

    $params[] =
        $destinationId;
}


/*
|--------------------------------------------------------------------------
| PACKAGE TYPE
|--------------------------------------------------------------------------
*/

if ($packageType !== '') {

    if (tsPackageTypeAssignmentsReady($pdo)) {
        $where[] = "(LOWER(TRIM(p.package_type)) = ? OR EXISTS (SELECT 1 FROM package_type_assignments pta WHERE pta.package_id = p.id AND pta.type_slug = ?))";
        $params[] = $packageType;
        $params[] = $packageType;
    } else {
        $where[] = "LOWER(TRIM(p.package_type)) = ?";
        $params[] = $packageType;
    }
}


/*
|--------------------------------------------------------------------------
| JOURNEY DATE / HOTEL CATEGORY AVAILABILITY
|--------------------------------------------------------------------------
*/

if ($journeyDate !== '' || $hotelCategory !== '') {
    if ($selectedPriceField !== '') {
        $where[] = "
            EXISTS (
                SELECT 1
                FROM package_date_prices pdp_filter
                WHERE pdp_filter.package_id = p.id
                  AND ? BETWEEN pdp_filter.valid_from AND pdp_filter.valid_to
                  AND pdp_filter.{$selectedPriceField} IS NOT NULL
                  AND pdp_filter.{$selectedPriceField} > 0
            )
        ";
    } else {
        $where[] = "
            EXISTS (
                SELECT 1
                FROM package_date_prices pdp_filter
                WHERE pdp_filter.package_id = p.id
                  AND ? BETWEEN pdp_filter.valid_from AND pdp_filter.valid_to
                  AND (
                        COALESCE(pdp_filter.price_3_star, 0) > 0
                     OR COALESCE(pdp_filter.price_4_star, 0) > 0
                     OR COALESCE(pdp_filter.price_5_star, 0) > 0
                  )
            )
        ";
    }

    $params[] = $effectiveJourneyDate;
}


/*
|--------------------------------------------------------------------------
| PRICE
|--------------------------------------------------------------------------
*/

switch ($priceRange) {

    case 'under_10000':
        $where[] =
            "COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0) < 10000";
        $params[] = $effectiveJourneyDate;
        break;

    case '10000_25000':
        $where[] =
            "COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0)
             BETWEEN 10000 AND 25000";
        $params[] = $effectiveJourneyDate;
        break;

    case '25000_50000':
        $where[] =
            "COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0)
             > 25000
             AND COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0)
             <= 50000";
        $params[] = $effectiveJourneyDate;
        $params[] = $effectiveJourneyDate;
        break;

    case '50000_100000':
        $where[] =
            "COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0)
             > 50000
             AND COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0)
             <= 100000";
        $params[] = $effectiveJourneyDate;
        $params[] = $effectiveJourneyDate;
        break;

    case 'above_100000':
        $where[] =
            "COALESCE({$matchedPriceExpression}, p.sale_price, p.regular_price, 0) > 100000";
        $params[] = $effectiveJourneyDate;
        break;

    default:
        $priceRange = '';
}


/*
|--------------------------------------------------------------------------
| SORT SQL
|--------------------------------------------------------------------------
*/

switch ($sort) {

    case 'price_low':

        $orderBy =
            "matched_price ASC,
             p.id DESC";

        break;


    case 'price_high':

        $orderBy =
            "matched_price DESC,
             p.id DESC";

        break;


    case 'duration_short':

        $orderBy =
            "p.duration_days ASC,
             p.id DESC";

        break;


    case 'duration_long':

        $orderBy =
            "p.duration_days DESC,
             p.id DESC";

        break;


    case 'popular':

        $orderBy =
            "p.views DESC,
             p.bestseller DESC,
             p.id DESC";

        break;


    case 'newest':

        $orderBy =
            "p.id DESC";

        break;


    default:

        $orderBy =
            "p.featured DESC,
             p.bestseller DESC,
             p.views DESC,
             p.id DESC";
}


/*
|--------------------------------------------------------------------------
| WHERE SQL
|--------------------------------------------------------------------------
*/

$whereSql =
    implode(
        ' AND ',
        $where
    );


/*
|--------------------------------------------------------------------------
| COUNT RESULTS
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*)

    FROM packages p

    LEFT JOIN destinations d
        ON d.id = p.destination_id

    WHERE {$whereSql}
";

$countStmt =
    $pdo->prepare($countSql);

$countStmt->execute($params);

$totalResults =
    (int)$countStmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$perPage = 9;

$totalPages =
    max(
        1,
        (int)ceil(
            $totalResults / $perPage
        )
    );

$page =
    isset($_GET['page'])
        ? max(1, (int)$_GET['page'])
        : 1;

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;


/*
|--------------------------------------------------------------------------
| FETCH PACKAGES
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id,
        p.destination_id,
        p.title,
        p.slug,
        p.package_code,
        p.short_description,
        p.duration_days,
        p.duration_nights,
        p.regular_price,
        p.sale_price,
        p.currency,
        p.package_type,
        p.main_image,
        p.featured,
        p.bestseller,
        p.customer_action_mode,
        p.views,
        p.created_at,

        COALESCE(
            {$matchedPriceExpression},
            p.sale_price,
            p.regular_price,
            0
        ) AS matched_price,

        {$matchedCategoryExpression}
            AS matched_hotel_category,

        (
            SELECT pdp_period.valid_from
            FROM package_date_prices pdp_period
            WHERE pdp_period.package_id = p.id
              AND ? BETWEEN pdp_period.valid_from AND pdp_period.valid_to
            ORDER BY pdp_period.valid_from DESC, pdp_period.id DESC
            LIMIT 1
        ) AS matched_valid_from,

        (
            SELECT pdp_period.valid_to
            FROM package_date_prices pdp_period
            WHERE pdp_period.package_id = p.id
              AND ? BETWEEN pdp_period.valid_from AND pdp_period.valid_to
            ORDER BY pdp_period.valid_from DESC, pdp_period.id DESC
            LIMIT 1
        ) AS matched_valid_to,

        d.name AS destination_name,
        d.slug AS destination_slug,
        d.country AS destination_country,
        d.continent AS destination_continent

    FROM packages p

    LEFT JOIN destinations d
        ON d.id = p.destination_id

    WHERE {$whereSql}

    ORDER BY {$orderBy}

    LIMIT {$perPage}
    OFFSET {$offset}
";

$stmt =
    $pdo->prepare($sql);

$selectParams = [
    $effectiveJourneyDate
];

if ($selectedPriceField === '') {
    $selectParams[] =
        $effectiveJourneyDate;
}

$selectParams[] =
    $effectiveJourneyDate;

$selectParams[] =
    $effectiveJourneyDate;

$stmt->execute(
    array_merge(
        $selectParams,
        $params
    )
);

$packages =
    $stmt->fetchAll();



/*
|--------------------------------------------------------------------------
| PACKAGE ITINERARY PREVIEWS
|--------------------------------------------------------------------------
*/

$packageItineraryPreviews = [];

if ($packages) {

    $packageIds =
        array_values(
            array_filter(
                array_map(
                    static fn($row) => (int)($row['id'] ?? 0),
                    $packages
                )
            )
        );

    if ($packageIds) {

        try {

            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($packageIds),
                        '?'
                    )
                );

            $stmt = $pdo->prepare("
                SELECT
                    package_id,
                    day_number,
                    title
                FROM package_itinerary_days
                WHERE package_id IN ($placeholders)
                ORDER BY
                    package_id ASC,
                    sort_order ASC,
                    day_number ASC,
                    id ASC
            ");

            $stmt->execute($packageIds);

            foreach (
                $stmt->fetchAll(PDO::FETCH_ASSOC)
                as $itineraryRow
            ) {

                $pid =
                    (int)$itineraryRow['package_id'];

                if (!isset($packageItineraryPreviews[$pid])) {
                    $packageItineraryPreviews[$pid] = [];
                }

                $packageItineraryPreviews[$pid][] =
                    $itineraryRow;
            }

        } catch (Throwable $e) {

            error_log(
                'Package itinerary preview error: '
                . $e->getMessage()
            );

            $packageItineraryPreviews = [];
        }
    }
}


/*
|--------------------------------------------------------------------------
| WISHLIST PACKAGE IDS
|--------------------------------------------------------------------------
*/

$wishlistPackageIds = [];

if ($userId > 0) {

    $stmt = $pdo->prepare("
        SELECT package_id
        FROM wishlists
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);

    $wishlistPackageIds =
        array_map(
            'intval',
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            )
        );
}


/*
|--------------------------------------------------------------------------
| FEATURED DESTINATIONS
|--------------------------------------------------------------------------
*/

$featuredDestinationStmt =
    $pdo->query("
        SELECT
            id,
            name,
            slug,
            country,
            image,
            banner_image

        FROM destinations

        WHERE
            status = 'active'
            AND featured = 1

        ORDER BY
            sort_order ASC,
            id DESC

        LIMIT 4
    ");

$featuredDestinations =
    $featuredDestinationStmt->fetchAll();


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
| CURRENT RETURN URL
|--------------------------------------------------------------------------
*/

$tsDestinationListing = !empty($tsDestinationListing)
    && isset($_GET['destination'])
    && (int)$_GET['destination'] > 0;
$tsPublicListingUrl = $tsDestinationListing ? 'destinations.php' : 'packages.php';

$returnUrl = $tsPublicListingUrl;

if (!empty($_SERVER['QUERY_STRING'])) {

    $returnUrl .=
        '?' . $_SERVER['QUERY_STRING'];
}


/*
|--------------------------------------------------------------------------
| PAGINATION URL HELPER
|--------------------------------------------------------------------------
*/

function packagePageUrl(int $page): string
{
    $query = $_GET;

    $query['page'] = $page;

    return ($GLOBALS['tsPublicListingUrl'] ?? 'packages.php') . '?'
        . http_build_query($query);
}

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
    content="Explore handpicked holiday packages, honeymoon trips, family holidays and unforgettable travel experiences with TRAVSCOPE."
>

<title>
    <?= $tsDestinationListing ? 'Destination Holidays' : 'Holiday Packages'; ?> | <?= e($siteName); ?>
</title>


<!-- GOOGLE FONTS -->

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


<!-- FONT AWESOME -->

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>


<style>

/* =========================================================
   VARIABLES
========================================================= */

:root {

    --navy:#07192d;
    --navy-soft:#0d2743;

    --primary:#0b74ff;
    --primary-dark:#075fd2;

    --cyan:#00b8ff;

    --background:#f6f8fb;

    --white:#ffffff;

    --text:#172033;
    --muted:#778294;

    --border:#e4e9ef;

    --green:#0aa66a;
    --orange:#eb8b22;
    --red:#e34759;
    --purple:#7557d8;

    --shadow:
        0 12px 35px
        rgba(7,25,45,.07);
}


/* =========================================================
   RESET
========================================================= */

* {

    margin:0;
    padding:0;

    box-sizing:border-box;
}


html {

    scroll-behavior:smooth;
}


body {

    min-height:100vh;

    background:var(--background);

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

    font-family:inherit;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    height:78px;

    padding:0 5%;

    position:sticky;

    top:0;

    z-index:1000;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:25px;

    background:
        rgba(255,255,255,.96);

    backdrop-filter:
        blur(15px);

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

    width:42px;
    height:42px;

    border-radius:12px;

    display:grid;

    place-items:center;

    background:
        linear-gradient(
            135deg,
            var(--primary),
            var(--cyan)
        );

    color:white;

    box-shadow:
        0 8px 20px
        rgba(11,116,255,.22);

    transform:
        rotate(-5deg);
}


.logo span {

    color:var(--primary);
}


.nav {

    display:flex;

    align-items:center;

    gap:28px;
}


.nav a {

    color:#566274;

    font-size:12px;

    font-weight:600;

    transition:.2s;
}


.nav a:hover,
.nav a.active {

    color:var(--primary);
}


.header-actions {

    display:flex;

    align-items:center;

    gap:9px;
}


.header-button {

    min-height:41px;

    padding:0 14px;

    border-radius:9px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:7px;

    font-size:10px;

    font-weight:800;
}


.header-button.light {

    border:
        1px solid
        var(--border);

    background:white;

    color:var(--navy);
}


.header-button.primary {

    background:var(--primary);

    color:white;

    box-shadow:
        0 8px 20px
        rgba(11,116,255,.17);
}


.mobile-menu {

    display:none;

    width:41px;
    height:41px;

    border:0;

    border-radius:9px;

    background:#edf4fb;

    color:var(--navy);

    cursor:pointer;
}


/* =========================================================
   MOBILE NAV
========================================================= */

.mobile-nav {

    display:none;

    position:fixed;

    top:78px;
    left:0;
    right:0;

    z-index:999;

    padding:14px;

    background:white;

    border-bottom:
        1px solid
        var(--border);

    box-shadow:
        0 15px 30px
        rgba(7,25,45,.08);
}


.mobile-nav.open {

    display:block;
}


.mobile-nav a {

    min-height:44px;

    padding:0 13px;

    border-radius:8px;

    display:flex;

    align-items:center;

    gap:10px;

    color:#536174;

    font-size:11px;

    font-weight:700;
}


.mobile-nav a:hover {

    background:#f5f8fb;

    color:var(--primary);
}


/* =========================================================
   HERO
========================================================= */

.hero {

    min-height:460px;

    padding:
        80px
        5%
        105px;

    position:relative;

    overflow:hidden;

    display:flex;

    align-items:center;

    background:

        linear-gradient(
            90deg,
            rgba(3,18,34,.95) 0%,
            rgba(4,32,58,.80) 48%,
            rgba(4,32,58,.28) 100%
        ),

        url(
            'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1900&q=88'
        )
        center/cover
        no-repeat;
}


.hero-content {

    width:100%;

    max-width:1250px;

    margin:auto;

    position:relative;

    z-index:2;

    color:white;
}


.hero-tag {

    display:inline-flex;

    align-items:center;

    gap:7px;

    padding:7px 11px;

    border:
        1px solid
        rgba(255,255,255,.16);

    border-radius:50px;

    background:
        rgba(255,255,255,.08);

    backdrop-filter:
        blur(8px);

    color:#d9edff;

    font-size:9px;

    font-weight:800;

    letter-spacing:.7px;
}


.hero h1 {

    max-width:760px;

    margin-top:18px;

    font-family:
        'Manrope',
        sans-serif;

    font-size:
        clamp(
            40px,
            5vw,
            66px
        );

    line-height:1.02;

    letter-spacing:-2px;
}


.hero h1 span {

    color:#6bc0ff;
}


.hero p {

    max-width:590px;

    margin-top:17px;

    color:
        rgba(255,255,255,.73);

    font-size:13px;

    line-height:1.75;
}


/* =========================================================
   SEARCH BAR
========================================================= */

.search-area {

    max-width:1250px;

    margin:
        -55px
        auto
        0;

    padding:0 25px;

    position:relative;

    z-index:50;
}


.search-card {

    padding:16px;

    border:
        1px solid
        var(--border);

    border-radius:17px;

    display:grid;

    grid-template-columns:
        minmax(180px,1.35fr)
        minmax(145px,1fr)
        minmax(145px,1fr)
        minmax(145px,1fr)
        auto
        auto;

    gap:10px;

    background:white;

    box-shadow:
        0 20px 50px
        rgba(7,25,45,.13);
}


.field {

    min-height:60px;

    padding:8px 13px;

    border:
        1px solid
        #e8ecf1;

    border-radius:11px;

    background:#fafbfd;
}


.field label {

    display:block;

    margin-bottom:4px;

    color:#8994a4;

    font-size:8px;

    font-weight:800;

    letter-spacing:.6px;

    text-transform:uppercase;
}


.field-control {

    display:flex;

    align-items:center;

    gap:8px;
}


.field-control i {

    color:var(--primary);

    font-size:13px;
}


.field input,
.field select {

    width:100%;

    border:0;
    outline:0;

    background:transparent;

    color:var(--navy);

    font-size:11px;

    font-weight:600;
}


.search-button {

    min-width:125px;
    min-height:60px;

    border:0;

    border-radius:11px;

    background:var(--primary);

    color:white;

    cursor:pointer;

    font-size:11px;

    font-weight:800;

    transition:.2s;
}


.search-button:hover {

    background:var(--primary-dark);
}


/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {

    max-width:1300px;

    margin:auto;

    padding:
        55px
        25px
        70px;
}


/* =========================================================
   FEATURED DESTINATIONS
========================================================= */

.destination-section {

    margin-bottom:45px;
}


.section-heading {

    margin-bottom:18px;

    display:flex;

    align-items:flex-end;

    justify-content:space-between;

    gap:20px;
}


.section-heading h2 {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:25px;

    letter-spacing:-.6px;
}


.section-heading p {

    margin-top:5px;

    color:var(--muted);

    font-size:10px;
}


.destinations-grid {

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:14px;
}


.destination-card {

    min-height:190px;

    padding:18px;

    border-radius:15px;

    overflow:hidden;

    position:relative;

    display:flex;

    align-items:flex-end;

    background:
        linear-gradient(
            180deg,
            rgba(3,18,34,.05),
            rgba(3,18,34,.82)
        ),
        var(--destination-image)
        center/cover
        no-repeat;

    box-shadow:
        var(--shadow);

    transition:.25s;
}


.destination-card:hover {

    transform:
        translateY(-4px);
}


.destination-card strong {

    display:block;

    color:white;

    font-family:
        'Manrope',
        sans-serif;

    font-size:17px;
}


.destination-card span {

    display:block;

    margin-top:2px;

    color:
        rgba(255,255,255,.7);

    font-size:9px;
}


/* =========================================================
   CONTENT LAYOUT
========================================================= */

.package-layout {

    display:grid;

    grid-template-columns:
        245px
        minmax(0,1fr);

    gap:24px;
}


/* =========================================================
   FILTER SIDEBAR
========================================================= */

.filters {

    align-self:start;

    position:sticky;

    top:98px;

    border:
        1px solid
        var(--border);

    border-radius:15px;

    overflow:hidden;

    background:white;

    box-shadow:
        var(--shadow);
}


.filter-title {

    min-height:62px;

    padding:0 17px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    border-bottom:
        1px solid
        var(--border);
}


.filter-title strong {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:13px;
}


.filter-title a {

    color:var(--primary);

    font-size:8px;

    font-weight:800;
}


.filter-body {

    padding:17px;
}


.filter-group {

    margin-bottom:20px;
}


.filter-group:last-child {

    margin-bottom:0;
}


.filter-group label.title {

    display:block;

    margin-bottom:7px;

    color:#6d798a;

    font-size:8px;

    font-weight:800;

    letter-spacing:.5px;

    text-transform:uppercase;
}


.filter-select {

    width:100%;

    height:41px;

    padding:0 10px;

    border:
        1px solid
        var(--border);

    border-radius:8px;

    outline:0;

    background:white;

    color:var(--text);

    font-size:10px;
}


.apply-filter {

    width:100%;

    min-height:42px;

    border:0;

    border-radius:9px;

    background:var(--navy);

    color:white;

    cursor:pointer;

    font-size:10px;

    font-weight:800;
}


/* =========================================================
   RESULT HEADER
========================================================= */

.results-top {

    min-height:58px;

    margin-bottom:15px;

    padding:
        0
        4px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;
}


.results-top h2 {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:19px;
}


.results-top p {

    margin-top:3px;

    color:var(--muted);

    font-size:9px;
}


.sort-box {

    height:40px;

    padding:0 9px;

    border:
        1px solid
        var(--border);

    border-radius:8px;

    outline:0;

    background:white;

    color:#536174;

    font-size:9px;

    font-weight:600;
}


/* =========================================================
   ITINERARY PREVIEW
========================================================= */

.itinerary-preview{
    margin-top:11px;
    padding:10px;
    border:1px solid #e7edf4;
    border-radius:9px;
    background:#f8fbff;
}

.itinerary-preview-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    color:var(--navy);
    font-size:7px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}

.itinerary-preview-head i{
    color:var(--primary);
}

.itinerary-preview-days{
    margin-top:7px;
    display:flex;
    flex-direction:column;
    gap:5px;
}

.itinerary-preview-day{
    display:flex;
    align-items:center;
    gap:6px;
    min-width:0;
    color:#687587;
    font-size:7px;
}

.itinerary-preview-day strong{
    flex:none;
    color:var(--primary);
    font-size:7px;
}

.itinerary-preview-day span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.itinerary-more{
    color:#8b96a5;
    font-size:7px;
    font-weight:700;
}


/* =========================================================
   PACKAGE GRID
========================================================= */

.packages-grid {

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:17px;
}


.package-card {

    overflow:hidden;

    border:
        1px solid
        var(--border);

    border-radius:16px;

    background:white;

    box-shadow:
        var(--shadow);

    transition:
        transform .25s,
        box-shadow .25s;
}


.package-card:hover {

    transform:
        translateY(-5px);

    box-shadow:
        0 18px 45px
        rgba(7,25,45,.11);
}


.package-image {

    height:205px;

    position:relative;

    overflow:hidden;

    background:#e9eef4;
}


.package-image img {

    width:100%;
    height:100%;

    object-fit:cover;

    transition:
        transform .45s ease;
}


.package-card:hover
.package-image img {

    transform:
        scale(1.06);
}


.badges {

    position:absolute;

    top:12px;
    left:12px;

    display:flex;

    flex-wrap:wrap;

    gap:5px;
}


.badge {

    padding:5px 8px;

    border-radius:50px;

    background:
        rgba(7,25,45,.82);

    color:white;

    backdrop-filter:
        blur(8px);

    font-size:7px;

    font-weight:800;

    text-transform:uppercase;
}


.badge.red {

    background:
        rgba(224,65,82,.92);
}


.badge.green {

    background:
        rgba(5,148,91,.92);
}


.wishlist-form {

    position:absolute;

    top:11px;
    right:11px;
}


.wishlist-button {

    width:36px;
    height:36px;

    border:0;

    border-radius:50%;

    display:grid;

    place-items:center;

    background:
        rgba(255,255,255,.94);

    color:#657285;

    cursor:pointer;

    box-shadow:
        0 5px 15px
        rgba(0,0,0,.1);

    transition:.2s;
}


.wishlist-button:hover,
.wishlist-button.active {

    color:var(--red);

    transform:
        scale(1.06);
}


.package-body {

    padding:17px;
}


.location {

    display:flex;

    align-items:center;

    gap:5px;

    color:var(--primary);

    font-size:8px;

    font-weight:800;
}


.package-body h3 {

    min-height:44px;

    margin-top:7px;

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:16px;

    line-height:1.35;
}


.package-description {

    height:47px;

    margin-top:6px;

    overflow:hidden;

    color:var(--muted);

    font-size:9px;

    line-height:1.7;
}


.package-meta {

    margin-top:12px;

    padding:
        11px
        0;

    border-top:
        1px solid
        #edf0f3;

    border-bottom:
        1px solid
        #edf0f3;

    display:flex;

    flex-wrap:wrap;

    gap:12px;

    color:#758195;

    font-size:8px;
}


.package-meta span {

    display:flex;

    align-items:center;

    gap:5px;
}


.package-meta i {

    color:var(--primary);
}


.package-bottom {

    margin-top:14px;

    display:flex;

    align-items:flex-end;

    justify-content:space-between;

    gap:10px;
}


.price-label {

    color:#8c97a6;

    font-size:7px;

    text-transform:uppercase;
}


.old-price {

    margin-top:2px;

    color:#a1aab7;

    font-size:8px;

    text-decoration:line-through;
}


.current-price {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:18px;

    font-weight:800;
}


.current-price small {

    color:#8c97a6;

    font-family:
        'DM Sans',
        sans-serif;

    font-size:7px;

    font-weight:500;
}


.card-actions {

    display:flex;

    gap:6px;
}


.details-button {

    min-height:36px;

    padding:0 10px;

    border:
        1px solid
        #d9e1e9;

    border-radius:8px;

    display:flex;

    align-items:center;

    justify-content:center;

    color:var(--navy);

    font-size:8px;

    font-weight:800;
}


.book-button {

    min-height:36px;

    padding:0 10px;

    border-radius:8px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:5px;

    background:var(--primary);

    color:white;

    font-size:8px;

    font-weight:800;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {

    padding:65px 25px;

    border:
        1px solid
        var(--border);

    border-radius:16px;

    background:white;

    text-align:center;

    box-shadow:
        var(--shadow);
}


.empty-icon {

    width:70px;
    height:70px;

    margin:
        0
        auto
        17px;

    border-radius:20px;

    display:grid;

    place-items:center;

    background:#eaf4ff;

    color:var(--primary);

    font-size:26px;
}


.empty-state h3 {

    color:var(--navy);

    font-family:
        'Manrope',
        sans-serif;

    font-size:20px;
}


.empty-state p {

    max-width:420px;

    margin:
        8px
        auto
        17px;

    color:var(--muted);

    font-size:10px;

    line-height:1.7;
}


.reset-button {

    min-height:40px;

    padding:0 14px;

    border-radius:8px;

    display:inline-flex;

    align-items:center;

    gap:6px;

    background:var(--primary);

    color:white;

    font-size:9px;

    font-weight:800;
}


/* =========================================================
   PAGINATION
========================================================= */

.pagination {

    margin-top:28px;

    display:flex;

    align-items:center;

    justify-content:center;

    flex-wrap:wrap;

    gap:6px;
}


.page-link {

    min-width:38px;
    height:38px;

    padding:0 10px;

    border:
        1px solid
        var(--border);

    border-radius:8px;

    display:grid;

    place-items:center;

    background:white;

    color:#5f6c7e;

    font-size:9px;

    font-weight:800;
}


.page-link:hover {

    border-color:#bcd8fa;

    color:var(--primary);
}


.page-link.active {

    border-color:var(--primary);

    background:var(--primary);

    color:white;
}


/* =========================================================
   FLASH
========================================================= */

.flash {

    max-width:1250px;

    margin:
        18px
        auto
        -25px;

    padding:
        0
        25px;

    position:relative;

    z-index:60;
}


.flash-message {

    min-height:48px;

    padding:0 15px;

    border-radius:10px;

    display:flex;

    align-items:center;

    gap:9px;

    font-size:10px;

    font-weight:700;

    box-shadow:
        var(--shadow);
}


.flash-success {

    border:
        1px solid
        #bfead7;

    background:#eafaf3;

    color:#08784c;
}


.flash-error {

    border:
        1px solid
        #f2c6cc;

    background:#fff0f2;

    color:#b83246;
}


/* =========================================================
   CTA
========================================================= */

.cta {

    max-width:1250px;

    min-height:220px;

    margin:
        0
        auto
        65px;

    padding:35px;

    border-radius:20px;

    overflow:hidden;

    position:relative;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:30px;

    background:
        linear-gradient(
            120deg,
            #07192d,
            #0b416f
        );

    color:white;
}


.cta::after {

    content:'';

    width:340px;
    height:340px;

    position:absolute;

    right:-110px;
    top:-180px;

    border-radius:50%;

    background:
        rgba(11,116,255,.2);
}


.cta-content {

    position:relative;

    z-index:2;
}


.cta h2 {

    font-family:
        'Manrope',
        sans-serif;

    font-size:27px;
}


.cta p {

    max-width:590px;

    margin-top:8px;

    color:
        rgba(255,255,255,.68);

    font-size:10px;

    line-height:1.7;
}


.cta a {

    min-height:45px;

    padding:0 16px;

    position:relative;

    z-index:2;

    border-radius:9px;

    display:flex;

    align-items:center;

    gap:7px;

    background:white;

    color:var(--navy);

    font-size:9px;

    font-weight:800;

    white-space:nowrap;
}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    padding:
        28px
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



/* PROFESSIONAL AUTOMATIC RESPONSIVE TYPOGRAPHY */
body{font-size:16px}.logo{font-size:21px}.nav a,.mobile-nav a{font-size:14px}.header-button{font-size:13px}
.hero-tag{font-size:12px}.hero h1{font-size:clamp(2.5rem,5.5vw,4.2rem)}.hero p{font-size:clamp(1rem,1.4vw,1.125rem)}
.field label{font-size:12px}.field input,.field select{font-size:15px}.search-button{font-size:14px}
.section-heading h2{font-size:clamp(1.8rem,3vw,2.4rem)}.section-heading p,.section-heading>a{font-size:14px}
.destination-card strong{font-size:17px}.destination-card span{font-size:13px}.filter-title strong{font-size:16px}
.filter-title a,.filter-group label.title{font-size:13px}.filter-select,.apply-filter{font-size:14px}
.results-top h2{font-size:24px}.results-top p,.sort-box{font-size:13px}.badge{font-size:11px}.location{font-size:13px}
.package-body h3{font-size:18px}.package-description{font-size:14px}
.itinerary-preview-head,.itinerary-preview-day,.itinerary-preview-day strong,.itinerary-more{font-size:12px}.package-meta{font-size:13px}
.price-label{font-size:12px}.old-price{font-size:13px}.current-price{font-size:21px}.current-price small{font-size:12px}
.details-button,.book-button,.reset-button{font-size:13px}.empty-state h3{font-size:22px}.empty-state p{font-size:14px}
.page-link{font-size:13px}.flash-message{font-size:14px}.cta h2{font-size:clamp(1.8rem,3vw,2.4rem)}.cta p{font-size:15px}
.cta a{font-size:13px}.footer strong{font-size:17px}.footer p{font-size:13px}
/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:1150px) {

    .nav {

        display:none;
    }


    .mobile-menu {

        display:grid;

        place-items:center;
    }


    .packages-grid {

        grid-template-columns:
            repeat(2,1fr);
    }


    .destinations-grid {

        grid-template-columns:
            repeat(2,1fr);
    }
}


@media(max-width:900px) {

    .search-card {

        grid-template-columns:
            repeat(2,1fr);
    }


    .search-button {

        min-height:55px;
    }


    .package-layout {

        grid-template-columns:1fr;
    }


    .filters {

        position:static;
    }


    .filter-body {

        display:grid;

        grid-template-columns:
            repeat(3,1fr);

        gap:10px;
    }


    .filter-group {

        margin:0;
    }
}


@media(max-width:650px) {

    .header {

        height:70px;

        padding:0 15px;
    }


    .mobile-nav {

        top:70px;
    }


    .header-actions
    .header-button.light {

        display:none;
    }


    .header-button.primary span {

        display:none;
    }


    .hero {

        min-height:500px;

        padding:
            70px
            20px
            110px;

        align-items:flex-end;

        background:

            linear-gradient(
                180deg,
                rgba(3,18,34,.2),
                rgba(3,18,34,.94)
            ),

            url(
                'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=900&q=85'
            )
            center/cover
            no-repeat;
    }


    .search-area {

        padding:0 14px;
    }


    .search-card {

        grid-template-columns:1fr;
    }


    .container {

        padding:
            45px
            14px
            55px;
    }


    .destinations-grid {

        grid-template-columns:1fr;
    }


    .destination-card {

        min-height:220px;
    }


    .filter-body {

        grid-template-columns:1fr;
    }


    .results-top {

        align-items:flex-start;

        flex-direction:column;
    }


    .sort-box {

        width:100%;
    }


    .packages-grid {

        grid-template-columns:1fr;
    }


    .package-image {

        height:245px;
    }


    .package-bottom {

        align-items:stretch;

        flex-direction:column;
    }


    .card-actions {

        width:100%;
    }


    .details-button,
    .book-button {

        flex:1;
    }


    .cta {

        margin:
            0
            14px
            45px;

        padding:27px;

        align-items:flex-start;

        flex-direction:column;
    }


    .cta a {

        width:100%;

        justify-content:center;
    }


    .footer-inner {

        align-items:flex-start;

        flex-direction:column;
    }
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


/* V25.15 package CTA options, consistent across category and destination filters */
.ts-package-card-ctas{display:flex;flex-wrap:wrap;gap:7px;min-width:0;align-items:center}
.ts-package-card-ctas a{flex:1 1 0;min-width:66px;text-decoration:none;white-space:nowrap}
.ts-package-card-ctas .enquire-button{display:flex;align-items:center;justify-content:center;gap:5px;min-height:36px;padding:0 10px;border:1px solid #f5d5ba;border-radius:8px;background:#fff4e9;color:#9b4b0f;font-size:9px;font-weight:800}
.ts-package-card-ctas .enquire-button:hover{background:#ffebd9;border-color:#ecb886}
.ts-package-three-actions a{padding-left:6px;padding-right:6px;font-size:9px}
@media(max-width:480px){.ts-package-card-ctas{width:100%}.ts-package-card-ctas a{min-width:0}}
</style>


<!-- TRAVSCOPE PRO UNIFORM UI -->
<link rel="stylesheet" href="assets/travscope-pro-public.css?v=20260916-pro">

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-public-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<!-- V25.13: uniform premium package-list cards; no changes to package data, hotel lookup or prices -->
<link rel="stylesheet" href="assets/travscope-packages-v2513.css?v=2513" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="public"></script>
<link rel="stylesheet" href="assets/travscope-world-v2525.css?v=20261015" media="screen">
<link rel="stylesheet" href="assets/travscope-world-catalog-v2525.css?v=20261015" media="screen">
</head>


<body class="ts-world-public ts-world-packages">


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
            class="<?= $tsDestinationListing ? '' : 'active'; ?>"
        >
            Packages
        </a>

        <a href="destinations.php" class="<?= $tsDestinationListing ? 'active' : ''; ?>">
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
                class="header-button light"
            >

                <i class="fa-regular fa-heart"></i>

                Wishlist

            </a>


            <a
                href="dashboard.php"
                class="header-button primary"
            >

                <i class="fa-regular fa-user"></i>

                <span>
                    My Account
                </span>

            </a>


        <?php else: ?>


            <a
                href="login.php"
                class="header-button light"
            >
                Login
            </a>


            <a
                href="register.php"
                class="header-button primary"
            >

                <i class="fa-regular fa-user"></i>

                <span>
                    Join TRAVSCOPE
                </span>

            </a>


        <?php endif; ?>


        <button
            type="button"
            class="mobile-menu"
            id="mobileMenu"
            aria-label="Open navigation"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


    </div>


</header>


<!-- MOBILE NAV -->

<nav
    class="mobile-nav"
    id="mobileNav"
>

    <a href="<?= e(BASE_URL); ?>">

        <i class="fa-solid fa-house"></i>

        Home

    </a>


    <a href="packages.php" class="<?= $tsDestinationListing ? '' : 'active'; ?>">

        <i class="fa-solid fa-suitcase-rolling"></i>

        Packages

    </a>


    <a href="destinations.php" class="<?= $tsDestinationListing ? 'active' : ''; ?>">

        <i class="fa-solid fa-earth-americas"></i>

        Destinations

    </a>


    <?php if ($userId): ?>

        <a href="dashboard.php">

            <i class="fa-solid fa-gauge"></i>

            My Dashboard

        </a>


        <a href="wishlist.php">

            <i class="fa-regular fa-heart"></i>

            Wishlist

        </a>

    <?php else: ?>

        <a href="login.php">

            <i class="fa-solid fa-right-to-bracket"></i>

            Login

        </a>

    <?php endif; ?>


</nav>


<!-- =========================================================
     HERO
========================================================= -->

<section class="hero">


    <div class="hero-content">


        <div class="hero-tag">

            <i class="fa-solid fa-earth-americas"></i>

            TRAVEL BEYOND THE ORDINARY

        </div>


        <h1>

            <?php if ($tsDestinationListing): ?>
                Explore holidays in
                <span><?= e($tsSelectedDestinationName ?: 'your chosen destination'); ?></span>
            <?php else: ?>
                Find a journey you'll
                <span>never forget.</span>
            <?php endif; ?>

        </h1>


        <p>
            <?php if ($tsDestinationListing): ?>
                Browse available packages for <?= e($tsSelectedDestinationName ?: 'this destination'); ?>,
                compare itineraries and find the right holiday for your journey.
            <?php else: ?>
                Explore thoughtfully designed holidays,
                romantic escapes, family adventures and
                unforgettable experiences created to make
                every journey worth remembering.
            <?php endif; ?>
        </p>


    </div>


</section>


<!-- =========================================================
     SEARCH
========================================================= -->

<div class="search-area">


    <form
        method="get"
        action="<?= e($tsPublicListingUrl); ?>"
        class="search-card"
    >
        <input type="hidden" name="page" value="1">

        <div class="field">
            <label>Destination</label>
            <div class="field-control">
                <i class="fa-solid fa-location-dot"></i>
                <select name="destination" required>
                    <option value="">Where do you want to go?</option>
                    <?php foreach ($destinations as $destination): ?>
                        <option
                            value="<?= (int)$destination['id']; ?>"
                            <?= $destinationId === (int)$destination['id'] ? 'selected' : ''; ?>
                        >
                            <?= e($destination['name']); ?>
                            <?= !empty($destination['country']) ? ' — ' . e($destination['country']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Journey Date</label>
            <div class="field-control">
                <i class="fa-regular fa-calendar"></i>
                <input
                    type="date"
                    name="journey_date"
                    value="<?= e($journeyDate); ?>"
                    min="<?= e(date('Y-m-d')); ?>"
                >
            </div>
        </div>

        <div class="field">
            <label>Hotel Category</label>
            <div class="field-control">
                <i class="fa-solid fa-hotel"></i>
                <select name="hotel_category">
                    <option value="">All Hotel Categories</option>
                    <option value="3" <?= $hotelCategory === '3' ? 'selected' : ''; ?>>3 Star Hotel</option>
                    <option value="4" <?= $hotelCategory === '4' ? 'selected' : ''; ?>>4 Star Hotel</option>
                    <option value="5" <?= $hotelCategory === '5' ? 'selected' : ''; ?>>5 Star Hotel</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Travel Type</label>
            <div class="field-control">
                <i class="fa-solid fa-compass"></i>
                <select name="type">
                    <option value="">All Travel Types</option>
                    <?php foreach ($packageTypes as $type): ?>
                        <?php
                        $typeSlug = strtolower(trim((string)($type['slug'] ?? '')));
                        $typeName = trim((string)($type['name'] ?? ''));

                        if ($typeSlug === '') {
                            continue;
                        }

                        if ($typeName === '') {
                            $typeName = ucwords(str_replace(['_', '-'], ' ', $typeSlug));
                        }
                        ?>
                        <option
                            value="<?= e($typeSlug); ?>"
                            <?= $packageType === $typeSlug ? 'selected' : ''; ?>
                        >
                            <?= e($typeName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button
            type="submit"
            class="search-button"
        >
            <i class="fa-solid fa-magnifying-glass"></i>
            Search Trips
        </button>

        <?php if ($destinationId > 0 || $journeyDate !== '' || $hotelCategory !== '' || $packageType !== ''): ?>
            <a
                href="<?= e($tsDestinationListing ? 'destinations.php' : 'packages.php'); ?>"
                class="search-button"
                style="display:flex;align-items:center;justify-content:center;gap:6px;background:#0d2743;"
            >
                <i class="fa-solid fa-rotate-left"></i>
                Reset
            </a>
        <?php endif; ?>

    </form>


</div>


<!-- =========================================================
     FLASH
========================================================= -->

<?php if (
    $successMessage
    ||
    $errorMessage
): ?>

<div class="flash">


    <?php if ($successMessage): ?>

        <div class="flash-message flash-success">

            <i class="fa-solid fa-circle-check"></i>

            <?= e($successMessage); ?>

        </div>

    <?php endif; ?>


    <?php if ($errorMessage): ?>

        <div class="flash-message flash-error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?= e($errorMessage); ?>

        </div>

    <?php endif; ?>


</div>

<?php endif; ?>


<!-- =========================================================
     CONTENT
========================================================= -->

<div class="container">


    <!-- =====================================================
         FEATURED DESTINATIONS
    ====================================================== -->

    <?php if ($featuredDestinations): ?>


        <section class="destination-section">


            <div class="section-heading">


                <div>

                    <h2>
                        Destinations worth dreaming about
                    </h2>

                    <p>

                        Start with some of our featured
                        places around the world.

                    </p>

                </div>


                <a
                    href="destinations.php"
                    style="
                        color:#0b74ff;
                        font-size:9px;
                        font-weight:800;
                    "
                >

                    Explore Destinations

                    <i class="fa-solid fa-arrow-right"></i>

                </a>


            </div>


            <div class="destinations-grid">


                <?php foreach (
                    $featuredDestinations
                    as $destination
                ): ?>


                    <?php

                    $destinationImage =
                        $destination['banner_image']
                        ?: $destination['image'];

                    ?>


                    <a
                        href="destinations.php?destination=<?= (int)$destination['id']; ?>"
                        class="destination-card"
                        style="
                            --destination-image:
                            url('<?= e(
                                packageImage(
                                    $destinationImage
                                )
                            ); ?>');
                        "
                    >


                        <div>

                            <strong>

                                <?= e(
                                    $destination['name']
                                ); ?>

                            </strong>

                            <span>

                                <?= e(
                                    $destination['country']
                                ); ?>

                                <i class="fa-solid fa-arrow-right"></i>

                            </span>

                        </div>


                    </a>


                <?php endforeach; ?>


            </div>


        </section>


    <?php endif; ?>


    <!-- =====================================================
         PACKAGE AREA
    ====================================================== -->

    <div class="package-layout">


        <!-- =================================================
             FILTERS
        ================================================== -->

        <aside class="filters">


            <div class="filter-title">

                <strong>

                    <i class="fa-solid fa-sliders"></i>

                    Filters

                </strong>


                <a href="<?= e($tsPublicListingUrl); ?>">
                    Clear All
                </a>

            </div>


            <form
                method="get"
                action="<?= e($tsPublicListingUrl); ?>"
                class="filter-body"
            >


                <?php if ($search !== ''): ?>

                    <input
                        type="hidden"
                        name="q"
                        value="<?= e($search); ?>"
                    >

                <?php endif; ?>

                <?php if ($journeyDate !== ''): ?>

                    <input
                        type="hidden"
                        name="journey_date"
                        value="<?= e($journeyDate); ?>"
                    >

                <?php endif; ?>


                <div class="filter-group">

                    <label class="title">
                        Destination
                    </label>

                    <select
                        name="destination"
                        class="filter-select"
                    >

                        <option value="0">
                            All Destinations
                        </option>

                        <?php foreach (
                            $destinations
                            as $destination
                        ): ?>

                            <option
                                value="<?= (int)$destination['id']; ?>"
                                <?= $destinationId === (int)$destination['id']
                                    ? 'selected'
                                    : ''; ?>
                            >

                                <?= e(
                                    $destination['name']
                                ); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-group">

                    <label class="title">
                        Hotel Category
                    </label>

                    <select name="hotel_category" class="filter-select">
                        <option value="">All Categories</option>
                        <option value="3" <?= $hotelCategory === '3' ? 'selected' : ''; ?>>3 Star Hotel</option>
                        <option value="4" <?= $hotelCategory === '4' ? 'selected' : ''; ?>>4 Star Hotel</option>
                        <option value="5" <?= $hotelCategory === '5' ? 'selected' : ''; ?>>5 Star Hotel</option>
                    </select>

                </div>


                <div class="filter-group">

                    <label class="title">
                        Travel Style
                    </label>

                    <select
                        name="type"
                        class="filter-select"
                    >

                        <option value="">
                            All Types
                        </option>

                        <?php foreach (
                            $packageTypes
                            as $type
                        ): ?>

                            <?php
                            $typeSlug = strtolower(trim((string)($type['slug'] ?? '')));
                            $typeName = trim((string)($type['name'] ?? ''));

                            if ($typeSlug === '') {
                                continue;
                            }

                            if ($typeName === '') {
                                $typeName = ucwords(str_replace(['_', '-'], ' ', $typeSlug));
                            }
                            ?>

                            <option
                                value="<?= e($typeSlug); ?>"
                                <?= $packageType === $typeSlug
                                    ? 'selected'
                                    : ''; ?>
                            >

                                <?= e($typeName); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-group">

                    <label class="title">
                        Budget
                    </label>

                    <select
                        name="price"
                        class="filter-select"
                    >

                        <option value="">
                            Any Budget
                        </option>

                        <option
                            value="under_10000"
                            <?= $priceRange === 'under_10000'
                                ? 'selected'
                                : ''; ?>
                        >
                            Under <?= e($currencySymbol); ?>10,000
                        </option>

                        <option
                            value="10000_25000"
                            <?= $priceRange === '10000_25000'
                                ? 'selected'
                                : ''; ?>
                        >
                            <?= e($currencySymbol); ?>10k – 25k
                        </option>

                        <option
                            value="25000_50000"
                            <?= $priceRange === '25000_50000'
                                ? 'selected'
                                : ''; ?>
                        >
                            <?= e($currencySymbol); ?>25k – 50k
                        </option>

                        <option
                            value="50000_100000"
                            <?= $priceRange === '50000_100000'
                                ? 'selected'
                                : ''; ?>
                        >
                            <?= e($currencySymbol); ?>50k – 1 Lakh
                        </option>

                        <option
                            value="above_100000"
                            <?= $priceRange === 'above_100000'
                                ? 'selected'
                                : ''; ?>
                        >
                            Above <?= e($currencySymbol); ?>1 Lakh
                        </option>

                    </select>

                </div>


                <input
                    type="hidden"
                    name="sort"
                    value="<?= e($sort); ?>"
                >


                <div class="filter-group">

                    <button
                        type="submit"
                        class="apply-filter"
                    >

                        Apply Filters

                    </button>

                </div>


            </form>


        </aside>


        <!-- =================================================
             RESULTS
        ================================================== -->

        <main>


            <div class="results-top">


                <div>

                    <h2>

                        <?php if ($search !== ''): ?>

                            Trips for
                            “<?= e($search); ?>”

                        <?php else: ?>

                            Explore Our Holiday Packages

                        <?php endif; ?>

                    </h2>


                    <p>

                        <?= number_format($totalResults); ?>

                        travel experience<?= $totalResults === 1 ? '' : 's'; ?>
                        found

                        <?php if ($journeyDate !== ''): ?>
                            for <?= e(date('d M Y', strtotime($journeyDate))); ?>
                        <?php endif; ?>

                        <?php if ($hotelCategory !== ''): ?>
                            · <?= e($hotelCategory); ?> Star Hotel
                        <?php endif; ?>

                    </p>

                </div>


                <select
                    class="sort-box"
                    id="sortSelect"
                >

                    <option
                        value="recommended"
                        <?= $sort === 'recommended'
                            ? 'selected'
                            : ''; ?>
                    >
                        Recommended
                    </option>

                    <option
                        value="popular"
                        <?= $sort === 'popular'
                            ? 'selected'
                            : ''; ?>
                    >
                        Most Popular
                    </option>

                    <option
                        value="price_low"
                        <?= $sort === 'price_low'
                            ? 'selected'
                            : ''; ?>
                    >
                        Price: Low to High
                    </option>

                    <option
                        value="price_high"
                        <?= $sort === 'price_high'
                            ? 'selected'
                            : ''; ?>
                    >
                        Price: High to Low
                    </option>

                    <option
                        value="duration_short"
                        <?= $sort === 'duration_short'
                            ? 'selected'
                            : ''; ?>
                    >
                        Shortest Duration
                    </option>

                    <option
                        value="duration_long"
                        <?= $sort === 'duration_long'
                            ? 'selected'
                            : ''; ?>
                    >
                        Longest Duration
                    </option>

                    <option
                        value="newest"
                        <?= $sort === 'newest'
                            ? 'selected'
                            : ''; ?>
                    >
                        Newest
                    </option>

                </select>


            </div>


            <?php if ($packages): ?>


                <div class="packages-grid">


                    <?php foreach (
                        $packages
                        as $package
                    ): ?>


                        <?php

                        $discount = 0;

                        $isWishlisted =
                            in_array(
                                (int)$package['id'],
                                $wishlistPackageIds,
                                true
                            );

                        $displayPrice =
                            packageDisplayPrice($package);

                        $displayCategory =
                            packageDisplayCategoryLabel($package);

                        $detailQuery = [
                            'slug' => $package['slug'],
                            'travel_date' => $effectiveJourneyDate,
                            'hotel_category' =>
                                (string)(
                                    $package['matched_hotel_category']
                                    ?: $hotelCategory
                                )
                        ];

                        $detailUrl =
                            'package-details.php?'
                            . http_build_query($detailQuery);

                        $bookQuery = [
                            'package' => (int)$package['id'],
                            'travel_date' => $effectiveJourneyDate,
                            'hotel_category' =>
                                (string)(
                                    $package['matched_hotel_category']
                                    ?: $hotelCategory
                                )
                        ];

                        if ($displayPrice > 0) {
                            $bookQuery['unit_price'] = $displayPrice;
                        }

                        $bookUrl =
                            'book-package.php?'
                            . http_build_query($bookQuery);

                        $customerActionMode = tsCustomerActionMode($package);
                        $enquiryUrl = 'contact.php?' . http_build_query([
                            'package' => (int)$package['id'],
                            'package_title' => (string)$package['title']
                        ]);

                        ?>


                        <article class="package-card">


                            <!-- IMAGE -->

                            <div class="package-image">


                                <a
                                    href="<?= e($detailUrl); ?>"
                                >

                                    <img
                                        src="<?= e(
                                            packageImage(
                                                $package['main_image']
                                            )
                                        ); ?>"
                                        alt="<?= e(
                                            $package['title']
                                        ); ?>"
                                        loading="lazy"
                                    >

                                </a>


                                <div class="badges">


                                    <?php if (
                                        (int)$package['bestseller'] === 1
                                    ): ?>

                                        <span class="badge red">

                                            <i class="fa-solid fa-fire"></i>

                                            Bestseller

                                        </span>

                                    <?php elseif (
                                        (int)$package['featured'] === 1
                                    ): ?>

                                        <span class="badge">

                                            Featured

                                        </span>

                                    <?php endif; ?>


                                    <?php if ($discount > 0): ?>

                                        <span class="badge green">

                                            <?= $discount; ?>% OFF

                                        </span>

                                    <?php endif; ?>


                                </div>


                                <form
                                    method="post"
                                    action="packages.php"
                                    class="wishlist-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(
                                            csrfToken()
                                        ); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="wishlist_action"
                                        value="toggle"
                                    >

                                    <input
                                        type="hidden"
                                        name="package_id"
                                        value="<?= (int)$package['id']; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="return_url"
                                        value="<?= e($returnUrl); ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="wishlist-button <?= $isWishlisted ? 'active' : ''; ?>"
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


                            <!-- BODY -->

                            <div class="package-body">


                                <div class="location">

                                    <i class="fa-solid fa-location-dot"></i>

                                    <?php if (
                                        $package['destination_name']
                                    ): ?>

                                        <?= e(
                                            $package['destination_name']
                                        ); ?>,

                                        <?= e(
                                            $package['destination_country']
                                        ); ?>

                                    <?php else: ?>

                                        Worldwide

                                    <?php endif; ?>

                                </div>


                                <h3>

                                    <a
                                        href="<?= e($detailUrl); ?>"
                                    >

                                        <?= e(
                                            $package['title']
                                        ); ?>

                                    </a>

                                </h3>


                                <div class="package-description">

                                    <?= e(
                                        $package['short_description']
                                        ?: 'Discover an unforgettable travel experience thoughtfully designed by TRAVSCOPE.'
                                    ); ?>

                                </div>


                                
                                <?php
                                $cardItinerary =
                                    $packageItineraryPreviews[
                                        (int)$package['id']
                                    ] ?? [];

                                $cardItineraryCount =
                                    count($cardItinerary);
                                ?>

                                <?php if ($cardItineraryCount > 0): ?>

                                <div class="itinerary-preview">

                                    <div class="itinerary-preview-head">

                                        <span>
                                            <i class="fa-solid fa-route"></i>
                                            Day-wise Itinerary
                                        </span>

                                        <span>
                                            <?= $cardItineraryCount; ?>
                                            Day<?= $cardItineraryCount === 1 ? '' : 's'; ?>
                                        </span>

                                    </div>

                                    <div class="itinerary-preview-days">

                                        <?php foreach (
                                            array_slice(
                                                $cardItinerary,
                                                0,
                                                2
                                            )
                                            as $dayIndex => $day
                                        ): ?>

                                        <div class="itinerary-preview-day">

                                            <strong>
                                                Day <?= (int)(
                                                    $day['day_number']
                                                    ?: ($dayIndex + 1)
                                                ); ?>
                                            </strong>

                                            <span>
                                                <?= e(
                                                    trim(
                                                        (string)(
                                                            $day['title']
                                                            ?? ''
                                                        )
                                                    )
                                                    ?: 'Planned activities'
                                                ); ?>
                                            </span>

                                        </div>

                                        <?php endforeach; ?>

                                        <?php if ($cardItineraryCount > 2): ?>

                                        <div class="itinerary-more">
                                            + <?= $cardItineraryCount - 2; ?>
                                            more day<?= ($cardItineraryCount - 2) === 1 ? '' : 's'; ?>
                                            — view full itinerary
                                        </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <?php endif; ?>


<div class="package-meta">


                                    <span>

                                        <i class="fa-regular fa-clock"></i>

                                        <?= (int)$package['duration_days']; ?>
                                        Days

                                        /

                                        <?= (int)$package['duration_nights']; ?>
                                        Nights

                                    </span>


                                    <span>

                                        <i class="fa-solid fa-compass"></i>

                                        <?= e(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $package['package_type']
                                                )
                                            )
                                        ); ?>

                                    </span>


                                    <span>

                                        <i class="fa-regular fa-eye"></i>

                                        <?= number_format(
                                            (int)$package['views']
                                        ); ?>

                                    </span>


                                </div>


                                <div class="package-bottom">


                                    <div>


                                        <div class="price-label">

                                            Starting from

                                        </div>


                                        <div class="old-price" style="text-decoration:none;">

                                            <?= e($displayCategory); ?>

                                            <?php if (!empty($package['matched_valid_from'])): ?>
                                                ·
                                                <?= e(date('d M', strtotime($package['matched_valid_from']))); ?>
                                                –
                                                <?= e(date('d M Y', strtotime($package['matched_valid_to']))); ?>
                                            <?php endif; ?>

                                        </div>


                                        <div class="current-price">

                                            <?php if ($displayPrice > 0): ?>

                                                <?= e(tsPackageCurrencySymbol((string)($package['currency'] ?? 'INR'))); ?>
                                                <?= number_format($displayPrice, 0); ?>

                                                <small>/ person</small>

                                            <?php else: ?>

                                                Price on request

                                            <?php endif; ?>

                                        </div>
                                    </div>


                                    <div class="card-actions ts-package-card-ctas<?= $customerActionMode === 'both' ? ' ts-package-three-actions' : ''; ?>">
                                        <a href="<?= e($detailUrl); ?>" class="details-button">Details</a>
                                        <?php if ($customerActionMode !== 'booking'): ?>
                                        <a href="<?= e($enquiryUrl); ?>" class="enquire-button"><i class="fa-regular fa-message"></i> Enquire</a>
                                        <?php endif; ?>
                                        <?php if ($customerActionMode !== 'enquiry'): ?>
                                        <a href="<?= e($bookUrl); ?>" class="book-button"><i class="fa-solid fa-calendar-check"></i> Book</a>
                                        <?php endif; ?>
                                    </div>


                                </div>


                            </div>


                        </article>


                    <?php endforeach; ?>


                </div>


                <!-- =========================================
                     PAGINATION
                ========================================== -->

                <?php if ($totalPages > 1): ?>


                    <nav
                        class="pagination"
                        aria-label="Package pages"
                    >


                        <?php if ($page > 1): ?>

                            <a
                                href="<?= e(
                                    packagePageUrl(
                                        $page - 1
                                    )
                                ); ?>"
                                class="page-link"
                            >

                                <i class="fa-solid fa-chevron-left"></i>

                            </a>

                        <?php endif; ?>


                        <?php

                        $startPage =
                            max(
                                1,
                                $page - 2
                            );

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        ?>


                        <?php for (
                            $i = $startPage;
                            $i <= $endPage;
                            $i++
                        ): ?>


                            <a
                                href="<?= e(
                                    packagePageUrl($i)
                                ); ?>"
                                class="page-link <?= $i === $page ? 'active' : ''; ?>"
                            >

                                <?= $i; ?>

                            </a>


                        <?php endfor; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= e(
                                    packagePageUrl(
                                        $page + 1
                                    )
                                ); ?>"
                                class="page-link"
                            >

                                <i class="fa-solid fa-chevron-right"></i>

                            </a>

                        <?php endif; ?>


                    </nav>


                <?php endif; ?>


            <?php else: ?>


                <!-- =========================================
                     NO RESULTS
                ========================================== -->

                <div class="empty-state">


                    <div class="empty-icon">

                        <i class="fa-solid fa-map-location-dot"></i>

                    </div>


                    <h3>
                        We couldn't find that perfect trip
                    </h3>


                    <p>

                        Try another destination, travel style
                        or budget. Your next unforgettable
                        journey may be just one search away.

                    </p>


                    <a
                        href="packages.php"
                        class="reset-button"
                    >

                        <i class="fa-solid fa-rotate-left"></i>

                        Show All Packages

                    </a>


                </div>


            <?php endif; ?>


        </main>


    </div>


</div>


<!-- =========================================================
     CTA
========================================================= -->

<section class="cta">


    <div class="cta-content">


        <h2>
            Can't find exactly what you're dreaming of?
        </h2>


        <p>

            Tell TRAVSCOPE where you want to go and
            what kind of experience you're looking for.
            We can help turn your travel idea into a
            journey made for you.

        </p>


    </div>


    <a href="contact.php">

        Plan My Trip

        <i class="fa-solid fa-arrow-right"></i>

    </a>


</section>


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


<script>

/*
|--------------------------------------------------------------------------
| MOBILE NAVIGATION
|--------------------------------------------------------------------------
*/

const mobileMenu =
    document.getElementById('mobileMenu');

const mobileNav =
    document.getElementById('mobileNav');


if (
    mobileMenu
    &&
    mobileNav
) {

    mobileMenu.addEventListener(
        'click',
        function () {

            mobileNav.classList.toggle(
                'open'
            );

        }
    );

}


/*
|--------------------------------------------------------------------------
| SORT
|--------------------------------------------------------------------------
*/

const sortSelect =
    document.getElementById('sortSelect');


if (sortSelect) {

    sortSelect.addEventListener(
        'change',
        function () {

            const url =
                new URL(
                    window.location.href
                );

            url.searchParams.set(
                'sort',
                this.value
            );

            url.searchParams.delete(
                'page'
            );

            window.location.href =
                url.toString();

        }
    );

}

</script>



<script src="assets/travscope-pro-public.js?v=20260916-pro" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
</body>
</html>