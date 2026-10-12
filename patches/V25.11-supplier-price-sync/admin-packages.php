<?php

/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM
| ADMIN PACKAGE MANAGEMENT
| PEXELS + ITINERARY + DATE-WISE PRICING + CHILD PRICING + INTELLIGENT PDF AUTO BUILDER VERSION: 2026-09-13-R18
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
$pexelsHttpFunctionsFile = __DIR__ . '/pexels-http-functions.php';
if (is_file($pexelsHttpFunctionsFile)) require_once $pexelsHttpFunctionsFile;
$unsplashPhotoFunctionsFile = __DIR__ . '/unsplash-photo-functions.php';
if (is_file($unsplashPhotoFunctionsFile)) require_once $unsplashPhotoFunctionsFile;
$packageSupplierFunctionsFile = __DIR__ . '/package-supplier-functions.php';
if (is_file($packageSupplierFunctionsFile)) require_once $packageSupplierFunctionsFile;


/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
|
| This assumes your admin login stores:
| $_SESSION['admin_id']
|
*/

if (empty($_SESSION['admin_id'])) {
    redirect(BASE_URL . 'admin-login.php');
}

$adminId = (int)$_SESSION['admin_id'];


/*
|--------------------------------------------------------------------------
| LOAD ADMIN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        role,
        status
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$adminId]);

$admin = $stmt->fetch();

if (
    !$admin
    ||
    $admin['status'] !== 'active'
) {
    unset($_SESSION['admin_id']);
    redirect(BASE_URL . 'admin-login.php');
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['admin_package_csrf'])) {
    $_SESSION['admin_package_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['admin_package_csrf'];


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function adminPackageImageUrl($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    return BASE_URL . ltrim($image, '/');
}


function makePackageSlug($text)
{
    $text = trim((string)$text);

    if ($text === '') {
        return 'package';
    }

    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $text
        );

        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = strtolower($text);

    $text = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $text
    );

    $text = trim($text, '-');

    return $text !== ''
        ? $text
        : 'package';
}


function uniquePackageSlug(
    PDO $pdo,
    string $baseSlug,
    int $ignoreId = 0
) {
    $slug = $baseSlug;
    $counter = 2;

    while (true) {

        if ($ignoreId > 0) {

            $stmt = $pdo->prepare("
                SELECT id
                FROM packages
                WHERE slug = ?
                AND id != ?
                LIMIT 1
            ");

            $stmt->execute([
                $slug,
                $ignoreId
            ]);

        } else {

            $stmt = $pdo->prepare("
                SELECT id
                FROM packages
                WHERE slug = ?
                LIMIT 1
            ");

            $stmt->execute([
                $slug
            ]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug =
            $baseSlug
            . '-'
            . $counter;

        $counter++;
    }
}



/*
|--------------------------------------------------------------------------
| STEP 07 - SMART PACKAGE BUILDER
|--------------------------------------------------------------------------
| Automatic package codes, publish-readiness checks, safe shared media and
| one-click package cloning. These helpers do not change booking data.
*/
function nextAutomaticPackageCode(PDO $pdo): string
{
    $prefix = 'PKG-' . date('ymd') . '-';

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $suffix = strtoupper(bin2hex(random_bytes(2)));
        $code = $prefix . $suffix;

        $stmt = $pdo->prepare("SELECT id FROM packages WHERE package_code = ? LIMIT 1");
        $stmt->execute([$code]);

        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }

    return $prefix . strtoupper(bin2hex(random_bytes(4)));
}

function packageMediaStillReferenced(string $image): bool
{
    $image = trim($image);
    if ($image === '') return false;

    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) return false;

    try {
        $checks = [
            "SELECT id FROM packages WHERE main_image = ? LIMIT 1",
            "SELECT id FROM package_images WHERE image = ? LIMIT 1",
            "SELECT id FROM package_itinerary_days WHERE image = ? LIMIT 1",
        ];

        foreach ($checks as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$image]);
            if ($stmt->fetchColumn()) return true;
        }
    } catch (Throwable $e) {
        error_log('Package shared-media reference check error: ' . $e->getMessage());
        return true; // fail safe: never destroy a file if reference safety is uncertain
    }

    return false;
}

function packagePublishReadiness(PDO $pdo, int $packageId): array
{
    $required = [];
    $warnings = [];

    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? LIMIT 1");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$package) {
        return [
            'ready' => false,
            'score' => 0,
            'required_missing' => ['Package record not found'],
            'warnings' => [],
            'has_selling_price' => false,
        ];
    }

    $supplierImported = (string)($package['source_type'] ?? '') === 'supplier_imported';
    $hasMainImage = trim((string)($package['main_image'] ?? '')) !== '';
    $checks = [
        'Destination' => !empty($package['destination_id']),
        'Package title' => trim((string)($package['title'] ?? '')) !== '',
        'Duration' => (int)($package['duration_days'] ?? 0) > 0,
        // Supplier-imported packages can safely use the public package fallback cover
        // until a package-specific image is selected. Do not penalise data readiness.
        'Main image' => $hasMainImage || $supplierImported,
        'Inclusions' => trim((string)($package['inclusions'] ?? '')) !== '',
        'Exclusions' => trim((string)($package['exclusions'] ?? '')) !== '',
        'Terms & Conditions' => trim((string)($package['terms'] ?? '')) !== '',
    ];

    $priceStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM package_date_prices\n        WHERE package_id = ?\n          AND (COALESCE(price_3_star,0) > 0 OR COALESCE(price_4_star,0) > 0 OR COALESCE(price_5_star,0) > 0)\n    ");
    $priceStmt->execute([$packageId]);
    $priceCount = (int)$priceStmt->fetchColumn();

    // V24.13 self-heal: older supplier imports could have blank TRAVSCOPE
    // category mapping, so their Package Master reference-price row was never
    // created. Rebuild it from the linked supplier package on first readiness check.
    if ($priceCount === 0 && $supplierImported && function_exists('tsPSOInsertPackageMasterReferencePrices')) {
        try {
            $contractId = 0;
            if ((int)($package['source_itinerary_id'] ?? 0) > 0) {
                $q = $pdo->prepare('SELECT contract_id FROM supplier_contract_itineraries WHERE id=? LIMIT 1');
                $q->execute([(int)$package['source_itinerary_id']]);
                $contractId = (int)($q->fetchColumn() ?: 0);
            }
            if ($contractId <= 0 && (int)($package['source_contract_version_id'] ?? 0) > 0) {
                $q = $pdo->prepare('SELECT contract_id FROM supplier_contract_versions WHERE id=? LIMIT 1');
                $q->execute([(int)$package['source_contract_version_id']]);
                $contractId = (int)($q->fetchColumn() ?: 0);
            }
            if ($contractId > 0) {
                tsPSOInsertPackageMasterReferencePrices($pdo, $packageId, $contractId);
                $priceStmt->execute([$packageId]);
                $priceCount = (int)$priceStmt->fetchColumn();
            }
        } catch (Throwable $priceRepairError) {
            error_log('Supplier package reference-price self-heal error: ' . $priceRepairError->getMessage());
        }
    }

    $checks['Selling price'] = $priceCount > 0;

    $itineraryStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM package_itinerary_days\n        WHERE package_id = ?\n          AND (COALESCE(title,'') <> '' OR COALESCE(description,'') <> '')\n    ");
    $itineraryStmt->execute([$packageId]);
    $itineraryCount = (int)$itineraryStmt->fetchColumn();
    $checks['Day-wise itinerary'] = $itineraryCount > 0;

    foreach ($checks as $label => $ok) {
        if (!$ok) $required[] = $label;
    }

    try {
        $hotelStmt = $pdo->prepare("SELECT COUNT(*) FROM package_hotels WHERE package_id = ?");
        $hotelStmt->execute([$packageId]);
        if ((int)$hotelStmt->fetchColumn() === 0) {
            $warnings[] = 'No automatic hotel is assigned yet.';
        }
    } catch (Throwable $e) {
        // Step 06 table may not exist on a partially-upgraded installation.
    }

    if (!$hasMainImage && $supplierImported) {
        $warnings[] = 'No package-specific cover image is selected; the public fallback cover will be used until an image is added.';
    }

    if (trim((string)($package['short_description'] ?? '')) === '') {
        $warnings[] = 'Short description is empty.';
    }

    if (trim((string)($package['tour_highlights'] ?? '')) === '') {
        $warnings[] = 'Tour highlights are empty.';
    }

    $totalRequired = count($checks);
    $passed = $totalRequired - count($required);
    $score = $totalRequired > 0 ? (int)round(($passed / $totalRequired) * 100) : 0;

    return [
        'ready' => !$required,
        'score' => $score,
        'required_missing' => $required,
        'warnings' => $warnings,
        'itinerary_count' => $itineraryCount,
        'has_selling_price' => $priceCount > 0,
    ];
}

function clonePackageRecord(PDO $pdo, int $sourcePackageId, int $adminId): int
{
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? LIMIT 1");
    $stmt->execute([$sourcePackageId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        throw new RuntimeException('Package not found.');
    }

    $newTitle = trim((string)$source['title']) . ' - Copy';
    $newSlug = uniquePackageSlug($pdo, makePackageSlug($newTitle));
    $newCode = nextAutomaticPackageCode($pdo);

    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare("\n            INSERT INTO packages\n            (\n                destination_id, title, slug, package_code, short_description, description,\n                duration_days, duration_nights, child_age_min, child_age_max, child_price,\n                regular_price, sale_price, currency, package_type, main_image, inclusions,\n                exclusions, itinerary, terms, tour_highlights, featured, bestseller, status, views\n            )\n            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)\n        ");

        $insert->execute([
            $source['destination_id'] ?: null,
            $newTitle,
            $newSlug,
            $newCode,
            $source['short_description'],
            $source['description'],
            $source['duration_days'],
            $source['duration_nights'],
            $source['child_age_min'],
            $source['child_age_max'],
            $source['child_price'],
            $source['regular_price'],
            $source['sale_price'],
            $source['currency'],
            $source['package_type'],
            $source['main_image'],
            $source['inclusions'],
            $source['exclusions'],
            $source['itinerary'],
            $source['terms'],
            $source['tour_highlights'],
            0,
            0,
            'draft',
        ]);

        $newId = (int)$pdo->lastInsertId();
        if ($newId <= 0) throw new RuntimeException('Unable to create package copy.');

        $copySql = [
            "INSERT INTO package_date_prices (package_id,valid_from,valid_to,price_3_star,price_4_star,price_5_star,sort_order)\n             SELECT ?,valid_from,valid_to,price_3_star,price_4_star,price_5_star,sort_order FROM package_date_prices WHERE package_id = ? ORDER BY id",
            "INSERT INTO package_child_prices (package_id,age_from,age_to,price,sort_order)\n             SELECT ?,age_from,age_to,price,sort_order FROM package_child_prices WHERE package_id = ? ORDER BY id",
            "INSERT INTO package_images (package_id,image,sort_order)\n             SELECT ?,image,sort_order FROM package_images WHERE package_id = ? ORDER BY id",
            "INSERT INTO package_itinerary_days (package_id,day_number,title,description,image,sort_order)\n             SELECT ?,day_number,title,description,image,sort_order FROM package_itinerary_days WHERE package_id = ? ORDER BY id",
        ];

        foreach ($copySql as $sql) {
            $copy = $pdo->prepare($sql);
            $copy->execute([$newId, $sourcePackageId]);
        }

        try {
            $copyHotels = $pdo->prepare("\n                INSERT INTO package_hotels\n                (package_id,hotel_id,destination_id,star_category,hotel_name_snapshot,supplier_id,room_type,meal_plan,nights,sort_order)\n                SELECT ?,hotel_id,destination_id,star_category,hotel_name_snapshot,supplier_id,room_type,meal_plan,nights,sort_order\n                FROM package_hotels WHERE package_id = ? ORDER BY id\n            ");
            $copyHotels->execute([$newId, $sourcePackageId]);
        } catch (Throwable $e) {
            // Allow cloning on a partially-upgraded installation without Step 06 table.
        }

        try {
            $copyOccupancy = $pdo->prepare("\n                INSERT INTO package_occupancy_prices\n                (package_id,valid_from,valid_to,star_category,twin_price,triple_price,single_price,child_with_bed_price,child_without_bed_price,infant_price,infant_age_to,sort_order)\n                SELECT ?,valid_from,valid_to,star_category,twin_price,triple_price,single_price,child_with_bed_price,child_without_bed_price,infant_price,infant_age_to,sort_order\n                FROM package_occupancy_prices WHERE package_id = ? ORDER BY id\n            ");
            $copyOccupancy->execute([$newId, $sourcePackageId]);
        } catch (Throwable $e) {
            // Allow cloning before Step 08 is installed.
        }


        /* STEP 10 - COPY PACKAGE SERVICE TEMPLATES */
        try {
            $copyServices = $pdo->prepare("
                INSERT INTO package_services
                (package_id,service_type,title,destination_id,source_package_hotel_id,hotel_star_category,preferred_supplier_id,service_day_from,service_day_to,quantity,quantity_basis,service_mode,specifications,auto_create_requirement,auto_prepare_supplier_request,sort_order,status,created_by_admin_id,updated_by_admin_id)
                SELECT ?,ps.service_type,ps.title,ps.destination_id,
                       CASE WHEN ps.source_package_hotel_id IS NOT NULL THEN phnew.id ELSE NULL END,
                       ps.hotel_star_category,ps.preferred_supplier_id,ps.service_day_from,ps.service_day_to,ps.quantity,ps.quantity_basis,ps.service_mode,ps.specifications,ps.auto_create_requirement,ps.auto_prepare_supplier_request,ps.sort_order,ps.status,?,?
                FROM package_services ps
                LEFT JOIN package_hotels phnew
                  ON phnew.package_id=? AND phnew.star_category=ps.hotel_star_category
                WHERE ps.package_id=?
                ORDER BY ps.sort_order,ps.id
            ");
            $copyServices->execute([
                $newId,
                $adminId > 0 ? $adminId : null,
                $adminId > 0 ? $adminId : null,
                $newId,
                $sourcePackageId
            ]);
        } catch (Throwable $e) {
            // Allow cloning before Step 10 is installed.
        }

        /* V20 - clone the sourcing map, but mark the new customer product as a copied package. */
        try {
            if (function_exists('tsPSOReady') && tsPSOReady($pdo)) {
                $pdo->prepare("UPDATE packages SET source_type='copy',source_supplier_id=NULL,source_contract_version_id=NULL,source_itinerary_id=NULL,source_supplier_package_code=NULL WHERE id=?")
                    ->execute([$newId]);
                $pdo->prepare("INSERT INTO package_supplier_options(package_id,supplier_id,contract_version_id,itinerary_id,supplier_package_code,relationship_type,is_primary,enabled_for_quotation,status,notes,created_by_admin_id,updated_by_admin_id) SELECT ?,supplier_id,contract_version_id,itinerary_id,supplier_package_code,relationship_type,is_primary,enabled_for_quotation,status,CONCAT('Copied from package #',?),?,? FROM package_supplier_options WHERE package_id=?")
                    ->execute([$newId,$sourcePackageId,$adminId?:null,$adminId?:null,$sourcePackageId]);
            }
        } catch (Throwable $e) {
            error_log('V20 package supplier clone warning: '.$e->getMessage());
        }

        $pdo->commit();
        return $newId;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function deletePackageImageFile($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return;
    }

    /*
    | Never delete remote URLs.
    */

    if (preg_match('#^https?://#i', $image)) {
        return;
    }

    /*
    | Only allow package upload directory.
    */

    if (
        strpos(
            $image,
            'uploads/packages/'
        ) !== 0
    ) {
        return;
    }

    $path =
        __DIR__
        . '/'
        . ltrim($image, '/');

    if (packageMediaStillReferenced($image)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
    }
}


function uploadPackageImage(
    array $file,
    int $packageId = 0
) {

    if (
        empty($file)
        ||
        !isset($file['error'])
        ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Package image upload failed.'
        );
    }

    /*
    | Maximum 5 MB.
    */

    if (
        (int)$file['size']
        > 5 * 1024 * 1024
    ) {
        throw new RuntimeException(
            'Package image must be smaller than 5 MB.'
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(
            'Invalid image upload.'
        );
    }

    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            'Only JPG, PNG and WEBP images are allowed.'
        );
    }

    if (
        @getimagesize(
            $file['tmp_name']
        ) === false
    ) {
        throw new RuntimeException(
            'Uploaded file is not a valid image.'
        );
    }

    $uploadDirectory =
        __DIR__
        . '/uploads/packages/';

    if (!is_dir($uploadDirectory)) {

        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            )
        ) {
            throw new RuntimeException(
                'Unable to create package image directory.'
            );
        }
    }

    $extension =
        $allowed[$mime];

    /*
    |--------------------------------------------------------------------------
    | KEEP THE REAL PC IMAGE NAME
    |--------------------------------------------------------------------------
    |
    | Example:
    |   PC file: bhutan-tiger-nest.jpg
    |   Saved:   bhutan-tiger-nest-a1b2c3d4.jpg
    |
    | The short suffix prevents duplicate filenames from overwriting each other,
    | while Web Gallery still shows the meaningful original image name.
    |
    */

    $originalBaseName =
        pathinfo(
            (string)($file['name'] ?? 'image'),
            PATHINFO_FILENAME
        );

    $originalBaseName =
        preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            $originalBaseName
        );

    $originalBaseName =
        trim(
            (string)$originalBaseName,
            '-_'
        );

    if ($originalBaseName === '') {
        $originalBaseName = 'image';
    }

    $originalBaseName =
        substr(
            $originalBaseName,
            0,
            100
        );

    /*
    | Save using the exact original PC filename.
    | Example: Bhutan Tiger Nest.jpg -> Bhutan Tiger Nest.jpg
    |
    | If that exact filename already exists, do NOT overwrite it.
    | Reuse the existing gallery image instead.
    */

    $exactOriginalName =
        basename(
            (string)($file['name'] ?? ('image.' . $extension))
        );

    /* Remove path/control characters but preserve spaces and readable name. */
    $exactOriginalName =
        str_replace(
            ["\\", "/", "\0", "\r", "\n"],
            '',
            $exactOriginalName
        );

    if ($exactOriginalName === '') {
        $exactOriginalName = 'image.' . $extension;
    }

    /*
    | Keep the verified MIME extension rather than trusting a fake extension,
    | but preserve the exact original base name including spaces.
    */
    $exactBaseName =
        pathinfo(
            $exactOriginalName,
            PATHINFO_FILENAME
        );

    $fileName =
        $exactBaseName
        . '.'
        . $extension;

    $destination =
        $uploadDirectory
        . $fileName;

    /*
    | If this exact filename is already in Web Gallery, reuse it.
    | This keeps the exact filename and avoids overwriting an existing image.
    */
    if (is_file($destination)) {
        return 'uploads/packages/' . $fileName;
    }

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            'Unable to save package image.'
        );
    }

    return
        'uploads/packages/'
        . $fileName;
}







/*
|--------------------------------------------------------------------------
| PEXELS ROYALTY-FREE IMAGE API
|--------------------------------------------------------------------------
| The API key remains server-side in config.php.
|--------------------------------------------------------------------------
*/

function ensureImageAttributionTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS image_attributions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            image_path VARCHAR(500) NOT NULL,
            provider VARCHAR(100) NOT NULL DEFAULT 'Pexels',
            provider_photo_id VARCHAR(100) DEFAULT NULL,
            photographer_name VARCHAR(255) DEFAULT NULL,
            photographer_url VARCHAR(1000) DEFAULT NULL,
            photo_page_url VARCHAR(1000) DEFAULT NULL,
            original_image_url LONGTEXT DEFAULT NULL,
            attribution_text VARCHAR(500) DEFAULT NULL,
            license_name VARCHAR(150) DEFAULT 'Pexels License',
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_image_path (image_path),
            KEY provider_photo_id (provider_photo_id),
            KEY provider (provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function pexelsApiKey(): string
{
    return defined('PEXELS_API_KEY')
        ? trim((string)PEXELS_API_KEY)
        : '';
}

function pexelsSearchPhotos(string $query, int $page = 1): array
{
    $apiKey = pexelsApiKey();

    if ($apiKey === '' || $apiKey === 'YOUR_PEXELS_API_KEY_HERE') {
        throw new RuntimeException(
            'Pexels API key is not configured in config.php.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL is required for Pexels image search.'
        );
    }

    $query = trim($query);

    if ($query === '') {
        throw new RuntimeException(
            'Please enter a photo search keyword.'
        );
    }

    $page = max(1, $page);

    $perPage = defined('PEXELS_API_PER_PAGE')
        ? max(1, min(40, (int)PEXELS_API_PER_PAGE))
        : 24;

    $baseUrl = defined('PEXELS_API_BASE_URL')
        ? rtrim((string)PEXELS_API_BASE_URL, '/')
        : 'https://api.pexels.com/v1';

    $url = $baseUrl
        . '/search?query=' . rawurlencode($query)
        . '&orientation=landscape'
        . '&size=large'
        . '&page=' . $page
        . '&per_page=' . $perPage;

    $request = tsPexelsHttpRequest(
        $url,
        [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
            'Connection: close',
        ],
        null,
        defined('PEXELS_API_TIMEOUT')
            ? max(30, (int)PEXELS_API_TIMEOUT)
            : 45
    );
    $response = $request['body'];
    $statusCode = (int)$request['status'];
    $data = json_decode((string)$response, true);

    if ($statusCode !== 200) {
        $message = is_array($data)
            ? trim((string)($data['error'] ?? ''))
            : '';

        throw new RuntimeException(
            $message !== ''
                ? $message
                : 'Pexels returned HTTP status ' . $statusCode . '.'
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            'Invalid response received from Pexels.'
        );
    }

    $photos = [];

    foreach (($data['photos'] ?? []) as $photo) {
        if (!is_array($photo)) {
            continue;
        }

        $src = is_array($photo['src'] ?? null)
            ? $photo['src']
            : [];

        $preview = trim((string)(
            $src['large']
            ?? $src['large2x']
            ?? $src['landscape']
            ?? $src['medium']
            ?? ''
        ));

        $download = trim((string)(
            $src['large2x']
            ?? $src['large']
            ?? $src['landscape']
            ?? $src['original']
            ?? ''
        ));

        $photoUrl = trim((string)($photo['url'] ?? ''));
        $photographer = trim((string)($photo['photographer'] ?? ''));
        $photographerUrl = trim((string)($photo['photographer_url'] ?? ''));
        $photoId = trim((string)($photo['id'] ?? ''));

        if (
            $photoId === ''
            || $preview === ''
            || $download === ''
            || $photoUrl === ''
            || $photographer === ''
        ) {
            continue;
        }

        $photos[] = [
            'provider' => 'pexels',
            'provider_label' => 'Pexels',
            'id' => $photoId,
            'preview' => $preview,
            'download' => $download,
            'photo_url' => $photoUrl,
            'photographer' => $photographer,
            'photographer_url' => $photographerUrl,
            'alt' => trim((string)($photo['alt'] ?? $query)),
            'width' => (int)($photo['width'] ?? 0),
            'height' => (int)($photo['height'] ?? 0),
        ];
    }

    return [
        'photos' => $photos,
        'page' => (int)($data['page'] ?? $page),
        'per_page' => (int)($data['per_page'] ?? $perPage),
        'total_results' => (int)($data['total_results'] ?? count($photos)),
        'next_page' => !empty($data['next_page']),
        'prev_page' => !empty($data['prev_page']),
    ];
}


function combinedPackagePhotoSearch(string $query, int $page = 1): array
{
    $pexelsPhotos=[]; $unsplashPhotos=[]; $status=[]; $pm=[]; $um=[];
    try { $pm=pexelsSearchPhotos($query,$page); $pexelsPhotos=array_values((array)($pm['photos']??[])); $status['pexels']=['ok'=>true,'count'=>count($pexelsPhotos),'message'=>count($pexelsPhotos)?'Pexels results loaded.':'Pexels returned no matches.']; }
    catch(Throwable $e){ $status['pexels']=['ok'=>false,'count'=>0,'message'=>'Pexels unavailable: '.$e->getMessage()]; }
    try { $um=tsUnsplashSearchPhotos($query,$page); $unsplashPhotos=array_values((array)($um['photos']??[])); $status['unsplash']=['ok'=>true,'count'=>count($unsplashPhotos),'message'=>count($unsplashPhotos)?'Unsplash results loaded.':'Unsplash returned no matches.']; }
    catch(Throwable $e){ $status['unsplash']=['ok'=>false,'count'=>0,'message'=>'Unsplash unavailable: '.$e->getMessage()]; }
    $photos=tsInterleavePhotoProviders($pexelsPhotos,$unsplashPhotos);
    if(!$photos && empty($status['pexels']['ok']) && empty($status['unsplash']['ok'])) throw new RuntimeException('Both photo providers are unavailable. '.$status['pexels']['message'].' '.$status['unsplash']['message']);
    return ['photos'=>$photos,'page'=>max(1,$page),'total_results'=>count($photos),'next_page'=>!empty($pm['next_page'])||!empty($um['next_page']),'prev_page'=>$page>1,'provider_status'=>$status,'counts'=>['all'=>count($photos),'pexels'=>count($pexelsPhotos),'unsplash'=>count($unsplashPhotos)]];
}

function importUnsplashPhoto(PDO $pdo, array $photo): array
{
    $id=trim((string)($photo['id']??'')); $imageUrl=trim((string)($photo['download']??''));
    $photoUrl=trim((string)($photo['photo_url']??'')); $photographer=trim((string)($photo['photographer']??'Unsplash contributor'));
    $photographerUrl=trim((string)($photo['photographer_url']??'')); $alt=trim((string)($photo['alt']??'Unsplash travel photo'));
    if($id==='' || !tsOfficialUnsplashImageUrl($imageUrl)) throw new RuntimeException('Invalid selected Unsplash photo.');
    tsTrackUnsplashDownload((string)($photo['download_location']??''));
    ensureImageAttributionTable($pdo);
    $attr='Photo by '.$photographer.' on Unsplash';
    $st=$pdo->prepare("INSERT INTO image_attributions
      (image_path,provider,provider_photo_id,photographer_name,photographer_url,photo_page_url,original_image_url,attribution_text,license_name)
      VALUES (?,'Unsplash',?,?,?,?,?,?, 'Unsplash API')
      ON DUPLICATE KEY UPDATE provider='Unsplash',provider_photo_id=VALUES(provider_photo_id),photographer_name=VALUES(photographer_name),photographer_url=VALUES(photographer_url),photo_page_url=VALUES(photo_page_url),original_image_url=VALUES(original_image_url),attribution_text=VALUES(attribution_text),license_name='Unsplash API'");
    $st->execute([$imageUrl,$id,$photographer,$photographerUrl?:null,$photoUrl?:null,$imageUrl,$attr]);
    return ['image'=>$imageUrl,'url'=>$imageUrl,'name'=>$alt !== ''?$alt:('Unsplash '.$id),'alt'=>$alt,'attribution'=>$attr,'photographer'=>$photographer,'photographer_url'=>$photographerUrl,'photo_url'=>$photoUrl,'provider'=>'Unsplash'];
}

function pexelsApprovedImageUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));

    return $scheme === 'https'
        && in_array($host, ['images.pexels.com'], true);
}

function pexelsSafeFileBase(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'Pexels Travel Photo';
    }

    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = preg_replace('/[^A-Za-z0-9 _-]+/', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    $value = trim((string)$value, " .-_");

    return $value !== ''
        ? mb_substr($value, 0, 90)
        : 'Pexels Travel Photo';
}

function optimizeImportedPexelsImage(
    string $sourceFile,
    string $destinationFile
): void {
    $targetKb = defined('PEXELS_IMAGE_MAX_SIZE_KB')
        ? max(60, (int)PEXELS_IMAGE_MAX_SIZE_KB)
        : 100;

    $targetBytes = $targetKb * 1024;

    $imageInfo = @getimagesize($sourceFile);

    if (!$imageInfo) {
        throw new RuntimeException(
            'The imported Pexels file is not a valid image.'
        );
    }

    $mime = strtolower((string)($imageInfo['mime'] ?? ''));

    if ($mime === 'image/jpeg') {
        $sourceImage = @imagecreatefromjpeg($sourceFile);
    } elseif ($mime === 'image/png') {
        $sourceImage = @imagecreatefrompng($sourceFile);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $sourceImage = @imagecreatefromwebp($sourceFile);
    } else {
        $sourceImage = false;
    }

    /*
    | If GD is unavailable, retain the validated source file.
    */
    if (!$sourceImage || !function_exists('imagejpeg')) {
        if (!@copy($sourceFile, $destinationFile)) {
            throw new RuntimeException(
                'Unable to store the imported image.'
            );
        }
        return;
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);
    $maxWidth = 1800;

    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = max(
            1,
            (int)round($height * ($newWidth / $width))
        );

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        imagecopyresampled(
            $resized,
            $sourceImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    $qualityLevels = [82, 74, 66, 58, 50, 42, 34];

    foreach ($qualityLevels as $quality) {
        imagejpeg($sourceImage, $destinationFile, $quality);

        if (
            is_file($destinationFile)
            && filesize($destinationFile) <= $targetBytes
        ) {
            break;
        }
    }

    imagedestroy($sourceImage);

    if (!is_file($destinationFile) || filesize($destinationFile) < 500) {
        throw new RuntimeException(
            'Unable to optimize the imported image.'
        );
    }
}

function importPexelsPhoto(
    PDO $pdo,
    array $photo
): array {
    $photoId = trim((string)($photo['id'] ?? ''));
    $imageUrl = trim((string)($photo['download'] ?? ''));
    $photoUrl = trim((string)($photo['photo_url'] ?? ''));
    $photographer = trim((string)($photo['photographer'] ?? ''));
    $photographerUrl = trim((string)($photo['photographer_url'] ?? ''));
    $alt = trim((string)($photo['alt'] ?? ''));

    if (
        $photoId === ''
        || $imageUrl === ''
        || $photoUrl === ''
        || $photographer === ''
    ) {
        throw new RuntimeException(
            'Incomplete Pexels photo information.'
        );
    }

    if (!pexelsApprovedImageUrl($imageUrl)) {
        throw new RuntimeException(
            'Only approved Pexels image URLs can be imported.'
        );
    }

    if (
        !filter_var($photoUrl, FILTER_VALIDATE_URL)
        || strtolower((string)parse_url($photoUrl, PHP_URL_HOST)) !== 'www.pexels.com'
    ) {
        throw new RuntimeException(
            'Invalid Pexels photo-page URL.'
        );
    }

    if (
        $photographerUrl !== ''
        && (
            !filter_var($photographerUrl, FILTER_VALIDATE_URL)
            || strtolower((string)parse_url($photographerUrl, PHP_URL_HOST)) !== 'www.pexels.com'
        )
    ) {
        throw new RuntimeException(
            'Invalid Pexels photographer URL.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL is required to import Pexels images.'
        );
    }

    $uploadDirectory = __DIR__ . '/uploads/packages/';

    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
    ) {
        throw new RuntimeException(
            'Unable to create the package image directory.'
        );
    }

    $preferredName = pexelsSafeFileBase(
        $alt !== ''
            ? $alt
            : ('Pexels Photo ' . $photoId)
    );

    $fileName = $preferredName
        . ' - Pexels '
        . $photoId
        . '.jpg';

    $destination = $uploadDirectory . $fileName;
    $relativePath = 'uploads/packages/' . $fileName;

    if (!is_file($destination)) {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'travscope_pexels_');

        if ($temporaryFile === false) {
            throw new RuntimeException(
                'Unable to create a temporary image file.'
            );
        }

        $request = tsPexelsHttpRequest(
            $imageUrl,
            ['Accept: image/*', 'Connection: close'],
            $temporaryFile,
            60
        );
        $statusCode = (int)$request['status'];
        $contentType = strtolower((string)$request['content_type']);

        if (
            $statusCode < 200
            || $statusCode >= 300
            || !str_starts_with($contentType, 'image/')
            || !is_file($temporaryFile)
            || filesize($temporaryFile) < 1000
            || @getimagesize($temporaryFile) === false
        ) {
            @unlink($temporaryFile);

            throw new RuntimeException(
                'Unable to download the selected Pexels image.'
            );
        }

        try {
            optimizeImportedPexelsImage(
                $temporaryFile,
                $destination
            );
        } finally {
            @unlink($temporaryFile);
        }
    }

    ensureImageAttributionTable($pdo);

    $attributionText = 'Photo by '
        . $photographer
        . ' on Pexels';

    $stmt = $pdo->prepare("
        INSERT INTO image_attributions
        (
            image_path,
            provider,
            provider_photo_id,
            photographer_name,
            photographer_url,
            photo_page_url,
            original_image_url,
            attribution_text,
            license_name
        )
        VALUES
        (
            ?,
            'Pexels',
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'Pexels License'
        )
        ON DUPLICATE KEY UPDATE
            provider_photo_id = VALUES(provider_photo_id),
            photographer_name = VALUES(photographer_name),
            photographer_url = VALUES(photographer_url),
            photo_page_url = VALUES(photo_page_url),
            original_image_url = VALUES(original_image_url),
            attribution_text = VALUES(attribution_text),
            license_name = VALUES(license_name)
    ");

    $stmt->execute([
        $relativePath,
        $photoId,
        $photographer,
        $photographerUrl !== '' ? $photographerUrl : null,
        $photoUrl,
        $imageUrl,
        $attributionText
    ]);

    return [
        'image' => $relativePath,
        'url' => adminPackageImageUrl($relativePath),
        'name' => $fileName,
        'attribution' => $attributionText,
        'photographer' => $photographer,
        'photographer_url' => $photographerUrl,
        'photo_url' => $photoUrl,
        'provider' => 'Pexels',
    ];
}


function packageMediaDisplayName(string $fileName): string
{
    /*
    | New uploads are stored with their exact original PC filename,
    | so return that exact filename unchanged.
    */
    return $fileName;
}


/* REUSABLE PACKAGE MEDIA LIBRARY */
function packageMediaLibrary(): array {
    $out = [];

    $sources = [
        ['dir' => __DIR__ . '/uploads/packages/',  'prefix' => 'uploads/packages/'],
        ['dir' => __DIR__ . '/uploads/itinerary/', 'prefix' => 'uploads/itinerary/'],
    ];

    foreach ($sources as $source) {
        $dir = $source['dir'];

        if (!is_dir($dir)) {
            continue;
        }

        foreach (scandir($dir) ?: [] as $n) {
            $f = $dir . $n;
            $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));

            if (
                is_file($f)
                && in_array($ext, ['jpg','jpeg','png','webp'], true)
            ) {
                $out[] = [
                    'name' => $n,
                    'display_name' => packageMediaDisplayName($n),
                    'image' => $source['prefix'] . $n,
                    'mtime' => (int)@filemtime($f)
                ];
            }
        }
    }

    usort($out, fn($a,$b) => $b['mtime'] <=> $a['mtime']);

    return $out;
}
function validPackageMediaPath(string $p): ?string {
    $p = trim($p);
    if (function_exists('tsOfficialUnsplashImageUrl') && tsOfficialUnsplashImageUrl($p)) return $p;
    foreach ([
        'uploads/packages/' => __DIR__ . '/uploads/packages/',
        'uploads/itinerary/' => __DIR__ . '/uploads/itinerary/'
    ] as $prefix => $root) {
        if (strpos($p, $prefix) !== 0) continue;
        $base = realpath($root);
        $file = realpath(__DIR__ . '/' . $p);
        if ($base && $file && is_file($file) &&
            strpos($file, $base . DIRECTORY_SEPARATOR) === 0) return $p;
    }
    return null;
}
function saveSelectedPackageGalleryMedia(PDO $pdo,int $packageId): void {
    $a=$_POST['library_gallery_images']??[]; if(!is_array($a)) return;
    $q=$pdo->prepare("SELECT image FROM package_images WHERE package_id=?");$q->execute([$packageId]);$have=$q->fetchAll(PDO::FETCH_COLUMN);
    $q=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM package_images WHERE package_id=?");$q->execute([$packageId]);$sort=(int)$q->fetchColumn();
    $ins=$pdo->prepare("INSERT INTO package_images(package_id,image,sort_order) VALUES(?,?,?)");
    foreach(array_unique($a) as $raw){$p=validPackageMediaPath((string)$raw);if($p&&!in_array($p,$have,true)){$ins->execute([$packageId,$p,++$sort]);$have[]=$p;}}
}

/*
|--------------------------------------------------------------------------
| MULTIPLE PACKAGE IMAGE HELPERS
|--------------------------------------------------------------------------
*/

function packageUploadFiles(string $field): array
{
    if (!isset($_FILES[$field]['name'])) return [];

    $u = $_FILES[$field];

    if (!is_array($u['name'])) return [$u];

    $files = [];
    foreach ($u['name'] as $i => $name) {
        $files[] = [
            'name' => $name,
            'type' => $u['type'][$i] ?? '',
            'tmp_name' => $u['tmp_name'][$i] ?? '',
            'error' => $u['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $u['size'][$i] ?? 0,
        ];
    }
    return $files;
}

function savePackageGalleryUploads(PDO $pdo, int $packageId): int
{
    $files = packageUploadFiles('gallery_images');
    if (!$files) return 0;

    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(sort_order),0)
        FROM package_images
        WHERE package_id = ?
    ");
    $stmt->execute([$packageId]);
    $sort = (int)$stmt->fetchColumn();
    $saved = 0;

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

        $image = uploadPackageImage($file, $packageId);
        if (!$image) continue;

        try {
            $sort++;
            $stmt = $pdo->prepare("
                INSERT INTO package_images (package_id, image, sort_order)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$packageId, $image, $sort]);
            $saved++;
        } catch (Throwable $e) {
            deletePackageImageFile($image);
            throw $e;
        }
    }
    return $saved;
}

/*
|--------------------------------------------------------------------------
| DAY-WISE ITINERARY HELPERS
|--------------------------------------------------------------------------
*/

function itineraryFileAt(string $field, int $index): array
{
    if (!isset($_FILES[$field]['name'][$index])) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    return [
        'name' => $_FILES[$field]['name'][$index] ?? '',
        'type' => $_FILES[$field]['type'][$index] ?? '',
        'tmp_name' => $_FILES[$field]['tmp_name'][$index] ?? '',
        'error' => $_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $_FILES[$field]['size'][$index] ?? 0,
    ];
}

function deleteItineraryImage(string $image): void
{
    $image = trim($image);

    if ($image === '' || preg_match('#^https?://#i', $image)) {
        return;
    }

    if (strpos($image, 'uploads/itinerary/') !== 0) {
        return;
    }

    $path = __DIR__ . '/' . ltrim($image, '/');

    if (packageMediaStillReferenced($image)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
    }
}

function uploadItineraryImage(array $file, int $packageId, int $dayNumber): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Day ' . $dayNumber . ' image upload failed.');
    }

    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Day ' . $dayNumber . ' image must be under 5 MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid itinerary image upload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
        throw new RuntimeException('Itinerary images must be JPG, PNG or WEBP.');
    }

    $dir = __DIR__ . '/uploads/itinerary/';

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Unable to create itinerary upload directory.');
    }

    /*
    | KEEP EXACT ORIGINAL PC FILENAME FOR ITINERARY IMAGE
    | Example: Tiger Nest View.jpg -> Tiger Nest View.jpg
    */
    $originalName = basename((string)($file['name'] ?? ('image.' . $allowed[$mime])));

    $originalName = str_replace(
        ["\\", "/", "\0", "\r", "\n"],
        '',
        $originalName
    );

    $originalBase = pathinfo($originalName, PATHINFO_FILENAME);

    if ($originalBase === '') {
        $originalBase = 'image';
    }

    $name = $originalBase . '.' . $allowed[$mime];
    $destination = $dir . $name;

    /*
    | If exact same filename already exists, reuse it instead of
    | creating package_1_day_1_random names.
    */
    if (is_file($destination)) {
        $counter = 2;
        do {
            $name = $originalBase . ' (' . $counter . ').' . $allowed[$mime];
            $destination = $dir . $name;
            $counter++;
        } while (is_file($destination));
    }

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Unable to save itinerary image.');
    }

    return 'uploads/itinerary/' . $name;
}

function saveItineraryDays(PDO $pdo, int $packageId): void
{
    $titles = $_POST['itinerary_title'] ?? [];
    $descriptions = $_POST['itinerary_description'] ?? [];
    $ids = $_POST['itinerary_id'] ?? [];
    $removeImages = $_POST['itinerary_remove_image'] ?? [];

    if (!is_array($titles)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, image
        FROM package_itinerary_days
        WHERE package_id = ?
    ");
    $stmt->execute([$packageId]);

    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[(int)$row['id']] = $row;
    }

    $kept = [];
    $dayNumber = 0;

    foreach ($titles as $index => $rawTitle) {
        $title = trim((string)$rawTitle);
        $description = trim((string)($descriptions[$index] ?? ''));
        $id = (int)($ids[$index] ?? 0);
        /*
        | Every itinerary row now uses the same stable numeric index
        | for title, description, ID, remove-image and file upload.
        */
        $file = itineraryFileAt('itinerary_image', (int)$index);

        /*
        | Image selected from Web Gallery for this itinerary day.
        */
        $libraryItineraryImages =
            $_POST['itinerary_library_image'] ?? [];

        $libraryImage = '';

        if (is_array($libraryItineraryImages)) {
            $libraryImage =
                validPackageMediaPath(
                    (string)($libraryItineraryImages[$index] ?? '')
                ) ?? '';
        }

        if (
            $id <= 0 &&
            $title === '' &&
            $description === '' &&
            $libraryImage === '' &&
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $dayNumber++;
        $image = '';

        if ($id > 0 && isset($existing[$id])) {
            $image = (string)($existing[$id]['image'] ?? '');
        }

        if (!empty($removeImages[$index]) && $image !== '') {
            deleteItineraryImage($image);
            $image = '';
        }

        /*
        | Apply Web Gallery selection first.
        | Do not delete the shared Web Gallery file when replacing an old
        | itinerary image because gallery media may be used elsewhere.
        */
        if ($libraryImage !== '') {
            if (
                $image !== ''
                && strpos($image, 'uploads/itinerary/') === 0
                && $image !== $libraryImage
            ) {
                deleteItineraryImage($image);
            }

            $image = $libraryImage;
        }

        /*
        | A Computer / PC upload has final priority when supplied.
        */
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newImage = uploadItineraryImage($file, $packageId, $dayNumber);

            if ($newImage !== null) {
                if ($image !== '') {
                    deleteItineraryImage($image);
                }
                $image = $newImage;
            }
        }

        if ($id > 0 && isset($existing[$id])) {
            $stmt = $pdo->prepare("
                UPDATE package_itinerary_days
                SET day_number = ?, title = ?, description = ?, image = ?, sort_order = ?
                WHERE id = ? AND package_id = ?
            ");
            $stmt->execute([
                $dayNumber,
                $title !== '' ? $title : null,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $dayNumber,
                $id,
                $packageId
            ]);
            $kept[] = $id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO package_itinerary_days
                    (package_id, day_number, title, description, image, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $packageId,
                $dayNumber,
                $title !== '' ? $title : null,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $dayNumber
            ]);
            $kept[] = (int)$pdo->lastInsertId();
        }
    }

    foreach ($existing as $id => $row) {
        if (!in_array($id, $kept, true)) {
            $stmt = $pdo->prepare("
                DELETE FROM package_itinerary_days
                WHERE id = ? AND package_id = ?
            ");
            $stmt->execute([$id, $packageId]);

            deleteItineraryImage((string)($row['image'] ?? ''));
        }
    }
}



/*
|--------------------------------------------------------------------------
| INDEPENDENT CHILD PRICING ROWS
|--------------------------------------------------------------------------
| Child rows are intentionally separate from hotel/date-wise pricing rows.
| The first child row is also mirrored to packages.child_age_min,
| packages.child_age_max and packages.child_price for backward compatibility.
*/

function normalizePackageChildPriceRows(array $source): array
{
    $ageFrom = is_array($source['child_row_age_from'] ?? null)
        ? $source['child_row_age_from'] : [];
    $ageTo = is_array($source['child_row_age_to'] ?? null)
        ? $source['child_row_age_to'] : [];
    $prices = is_array($source['child_row_price'] ?? null)
        ? $source['child_row_price'] : [];

    $count = max(count($ageFrom), count($ageTo), count($prices));
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $fromRaw = trim((string)($ageFrom[$i] ?? ''));
        $toRaw = trim((string)($ageTo[$i] ?? ''));
        $priceRaw = trim((string)($prices[$i] ?? ''));

        if ($fromRaw === '' && $toRaw === '' && $priceRaw === '') {
            continue;
        }

        $rows[] = [
            'age_from' => $fromRaw === '' ? 0 : max(0, (int)$fromRaw),
            'age_to' => $toRaw === '' ? 0 : max(0, (int)$toRaw),
            'price' => $priceRaw === '' ? null : max(0, (float)$priceRaw),
        ];
    }

    return $rows;
}

function validatePackageChildPriceRows(array $rows): void
{
    foreach ($rows as $index => $row) {
        $n = $index + 1;
        $from = (int)($row['age_from'] ?? 0);
        $to = (int)($row['age_to'] ?? 0);
        $price = $row['price'] ?? null;

        if ($from < 0 || $from > 18 || $to < 0 || $to > 18) {
            throw new RuntimeException('Child price row ' . $n . ': age must be between 0 and 18.');
        }

        if ($from > $to) {
            throw new RuntimeException('Child price row ' . $n . ': Age From cannot be greater than Age To.');
        }

        if ($price === null) {
            throw new RuntimeException('Child price row ' . $n . ': enter the child price.');
        }
    }
}

function savePackageChildPriceRows(PDO $pdo, int $packageId, array $rows): void
{
    validatePackageChildPriceRows($rows);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            DELETE FROM package_child_prices
            WHERE package_id = ?
        ");
        $stmt->execute([$packageId]);

        if ($rows) {
            $stmt = $pdo->prepare("
                INSERT INTO package_child_prices
                (package_id, age_from, age_to, price, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $stmt->execute([
                    $packageId,
                    (int)$row['age_from'],
                    (int)$row['age_to'],
                    $row['price'],
                    $index + 1
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| DATE-WISE HOTEL CATEGORY PRICING
|--------------------------------------------------------------------------
*/

function normalizePackagePriceRows(array $source): array
{
    $fromDates = is_array($source['price_valid_from'] ?? null)
        ? $source['price_valid_from']
        : [];

    $toDates = is_array($source['price_valid_to'] ?? null)
        ? $source['price_valid_to']
        : [];

    $threeStar = is_array($source['price_3_star'] ?? null)
        ? $source['price_3_star']
        : [];

    $fourStar = is_array($source['price_4_star'] ?? null)
        ? $source['price_4_star']
        : [];

    $fiveStar = is_array($source['price_5_star'] ?? null)
        ? $source['price_5_star']
        : [];

    $rowCount = max(
        count($fromDates),
        count($toDates),
        count($threeStar),
        count($fourStar),
        count($fiveStar)
    );

    $rows = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $validFrom = trim((string)($fromDates[$index] ?? ''));
        $validTo = trim((string)($toDates[$index] ?? ''));

        $price3Raw = trim((string)($threeStar[$index] ?? ''));
        $price4Raw = trim((string)($fourStar[$index] ?? ''));
        $price5Raw = trim((string)($fiveStar[$index] ?? ''));

        if (
            $validFrom === ''
            && $validTo === ''
            && $price3Raw === ''
            && $price4Raw === ''
            && $price5Raw === ''
        ) {
            continue;
        }

        $rows[] = [
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'price_3_star' => $price3Raw === '' ? null : (float)$price3Raw,
            'price_4_star' => $price4Raw === '' ? null : (float)$price4Raw,
            'price_5_star' => $price5Raw === '' ? null : (float)$price5Raw,
        ];
    }

    return $rows;
}

function validatePackagePriceRows(array $rows): void
{
    foreach ($rows as $index => $row) {
        $rowNumber = $index + 1;
        $validFrom = (string)($row['valid_from'] ?? '');
        $validTo = (string)($row['valid_to'] ?? '');

        if ($validFrom === '' || $validTo === '') {
            throw new RuntimeException(
                'Price row ' . $rowNumber . ': both Valid From and Valid To dates are required.'
            );
        }

        $fromTime = strtotime($validFrom);
        $toTime = strtotime($validTo);

        if (!$fromTime || !$toTime) {
            throw new RuntimeException(
                'Price row ' . $rowNumber . ': please enter valid dates.'
            );
        }

        if ($fromTime > $toTime) {
            throw new RuntimeException(
                'Price row ' . $rowNumber . ': Valid From cannot be after Valid To.'
            );
        }

        $hasPrice = false;

        foreach (['price_3_star', 'price_4_star', 'price_5_star'] as $field) {
            $price = $row[$field] ?? null;

            if ($price !== null) {
                if ((float)$price < 0) {
                    throw new RuntimeException(
                        'Price row ' . $rowNumber . ': hotel prices cannot be negative.'
                    );
                }

                if ((float)$price > 0) {
                    $hasPrice = true;
                }
            }
        }

        if (!$hasPrice) {
            throw new RuntimeException(
                'Price row ' . $rowNumber . ': enter at least one hotel category price.'
            );
        }
    }

    $sorted = $rows;

    usort($sorted, function (array $a, array $b): int {
        return strcmp(
            (string)$a['valid_from'],
            (string)$b['valid_from']
        );
    });

    for ($index = 1; $index < count($sorted); $index++) {
        $previousTo = strtotime((string)$sorted[$index - 1]['valid_to']);
        $currentFrom = strtotime((string)$sorted[$index]['valid_from']);

        if ($currentFrom <= $previousTo) {
            throw new RuntimeException(
                'Date-wise price periods cannot overlap. Please correct the pricing table.'
            );
        }
    }
}

function savePackagePriceRows(
    PDO $pdo,
    int $packageId,
    array $rows
): void {
    validatePackagePriceRows($rows);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            DELETE FROM package_date_prices
            WHERE package_id = ?
        ");

        $stmt->execute([$packageId]);

        if ($rows) {
            $stmt = $pdo->prepare("
                INSERT INTO package_date_prices
                (
                    package_id,
                    valid_from,
                    valid_to,
                    price_3_star,
                    price_4_star,
                    price_5_star,
                    sort_order
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $stmt->execute([
                    $packageId,
                    $row['valid_from'],
                    $row['valid_to'],
                    $row['price_3_star'],
                    $row['price_4_star'],
                    $row['price_5_star'],
                    $index + 1
                ]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function packagePriceFallback(array $rows): float
{
    $prices = [];

    foreach ($rows as $row) {
        foreach (['price_3_star', 'price_4_star', 'price_5_star'] as $field) {
            if (
                isset($row[$field])
                && $row[$field] !== null
                && (float)$row[$field] > 0
            ) {
                $prices[] = (float)$row[$field];
            }
        }
    }

    return $prices ? min($prices) : 0.0;
}




/*
|--------------------------------------------------------------------------
| PACKAGE MASTER V2 - AUTOMATIC HOTEL ENGINE
|--------------------------------------------------------------------------
| Hotels are selected from Hotel Master. If Admin does not choose a hotel,
| the preferred active hotel for the destination + star category is used.
*/
function packageHotelColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table,$column]);
        return (int)$st->fetchColumn()>0;
    } catch (Throwable $ignored) {
        return false;
    }
}

function packageHotelRelatedDestination(PDO $pdo, int $destinationId, string $alias='h'): array
{
    $destinationName='';
    try {
        $st=$pdo->prepare("SELECT name FROM destinations WHERE id=? LIMIT 1");
        $st->execute([$destinationId]);
        $destinationName=trim((string)($st->fetchColumn()?:''));
    } catch (Throwable $ignored) {}

    $parts=["{$alias}.destination_id=?"];
    $params=[$destinationId];

    if ($destinationName!=='') {
        // Legacy rows may only have the hotel city populated.
        $parts[]="LOWER(TRIM(COALESCE({$alias}.city,'')))=LOWER(TRIM(?))";
        $params[]=$destinationName;

        // V18+ Smart Hotel Location stores Country -> State -> City on the hotel.
        // These two matches bridge a state/tour-area package to a city hotel and vice versa.
        if (packageHotelColumnExists($pdo,'hotels','geo_city')) {
            $parts[]="LOWER(TRIM(COALESCE({$alias}.geo_city,'')))=LOWER(TRIM(?))";
            $params[]=$destinationName;
        }
        if (packageHotelColumnExists($pdo,'hotels','geo_state')) {
            $parts[]="LOWER(TRIM(COALESCE({$alias}.geo_state,'')))=LOWER(TRIM(?))";
            $params[]=$destinationName;
        }
    }

    return [
        'sql'=>'('.implode(' OR ',$parts).')',
        'params'=>$params,
        'name'=>$destinationName,
    ];
}

function packageHotelLocationName(PDO $pdo, int $locationId): string
{
    if ($locationId<=0) return '';
    try {
        $st=$pdo->prepare("SELECT location_name FROM destination_locations WHERE id=? LIMIT 1");
        $st->execute([$locationId]);
        return trim((string)($st->fetchColumn()?:''));
    } catch (Throwable $ignored) {
        return '';
    }
}

function packageResolveHotelSelections(PDO $pdo, int $destinationId, array $source): array
{
    $resolved = [];
    if ($destinationId <= 0) return $resolved;

    $locationId = max(0, (int)($source['package_hotel_location_id'] ?? 0));
    $locationName = packageHotelLocationName($pdo,$locationId);
    $related = packageHotelRelatedDestination($pdo,$destinationId,'h');

    foreach ([3, 4, 5] as $star) {
        $requested = (int)($source['hotel_' . $star . '_star_id'] ?? 0);
        $row = null;

        $locationSql='';
        $locationParams=[];
        if ($locationId>0) {
            if ($locationName!=='') {
                $locParts=[
                    'h.location_id=?',
                    "LOWER(TRIM(COALESCE(dl.location_name,'')))=LOWER(TRIM(?))",
                    "LOWER(TRIM(COALESCE(h.city,'')))=LOWER(TRIM(?))",
                ];
                $locationParams=[$locationId,$locationName,$locationName];
                if (packageHotelColumnExists($pdo,'hotels','geo_city')) {
                    $locParts[]="LOWER(TRIM(COALESCE(h.geo_city,'')))=LOWER(TRIM(?))";
                    $locationParams[]=$locationName;
                }
                $locationSql=' AND ('.implode(' OR ',$locParts).')';
            } else {
                $locationSql=' AND h.location_id=?';
                $locationParams=[$locationId];
            }
        }

        if ($requested>0) {
            $sql="SELECT h.*,dl.location_name,s.company_name supplier_name
                  FROM hotels h
                  LEFT JOIN destination_locations dl ON dl.id=h.location_id
                  LEFT JOIN suppliers s ON s.id=h.preferred_supplier_id
                  WHERE h.id=? AND {$related['sql']} AND h.star_category=? AND h.status='active'{$locationSql}
                  LIMIT 1";
            $params=array_merge([$requested],$related['params'],[$star],$locationParams);
            $st=$pdo->prepare($sql);$st->execute($params);$row=$st->fetch(PDO::FETCH_ASSOC)?:null;
        }

        if (!$row) {
            $sql="SELECT h.*,dl.location_name,s.company_name supplier_name
                  FROM hotels h
                  LEFT JOIN destination_locations dl ON dl.id=h.location_id
                  LEFT JOIN suppliers s ON s.id=h.preferred_supplier_id
                  WHERE {$related['sql']} AND h.star_category=? AND h.status='active'{$locationSql}
                  ORDER BY h.is_preferred DESC,h.priority_order ASC,h.id ASC LIMIT 1";
            $params=array_merge($related['params'],[$star],$locationParams);
            $st=$pdo->prepare($sql);$st->execute($params);$row=$st->fetch(PDO::FETCH_ASSOC)?:null;
        }

        // A stale location filter must never make an otherwise valid Hotel Master package un-saveable.
        // If nothing exists in that location, fall back to every related hotel for the package destination.
        if (!$row && $locationId>0) {
            $sql="SELECT h.*,dl.location_name,s.company_name supplier_name
                  FROM hotels h
                  LEFT JOIN destination_locations dl ON dl.id=h.location_id
                  LEFT JOIN suppliers s ON s.id=h.preferred_supplier_id
                  WHERE {$related['sql']} AND h.star_category=? AND h.status='active'
                  ORDER BY h.is_preferred DESC,h.priority_order ASC,h.id ASC LIMIT 1";
            $params=array_merge($related['params'],[$star]);
            $st=$pdo->prepare($sql);$st->execute($params);$row=$st->fetch(PDO::FETCH_ASSOC)?:null;
        }

        if ($row) $resolved[$star] = $row;
    }

    return $resolved;
}

function savePackageHotelAssignments(
    PDO $pdo,
    int $packageId,
    ?int $destinationId,
    int $durationNights,
    array $selections
): void {
    $pdo->prepare("DELETE FROM package_hotels WHERE package_id = ?")->execute([$packageId]);
    if ($packageId <= 0 || !$selections) return;

    $hasLocation = false;
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='package_hotels' AND column_name='location_id'");
        $st->execute();
        $hasLocation=(int)$st->fetchColumn()>0;
    } catch (Throwable $ignored) {}

    if ($hasLocation) {
        $insert = $pdo->prepare("
            INSERT INTO package_hotels
            (
                package_id, hotel_id, destination_id, location_id, star_category,
                hotel_name_snapshot, supplier_id, room_type, meal_plan,
                nights, sort_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    } else {
        $insert = $pdo->prepare("
            INSERT INTO package_hotels
            (
                package_id, hotel_id, destination_id, star_category,
                hotel_name_snapshot, supplier_id, room_type, meal_plan,
                nights, sort_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    }

    foreach ([3, 4, 5] as $sort => $star) {
        if (empty($selections[$star])) continue;
        $hotel = $selections[$star];
        $values = [
            $packageId,
            (int)$hotel['id'],
            $destinationId ?: null,
        ];
        if ($hasLocation) $values[] = !empty($hotel['location_id']) ? (int)$hotel['location_id'] : null;
        array_push(
            $values,
            $star,
            (string)$hotel['hotel_name'],
            !empty($hotel['preferred_supplier_id']) ? (int)$hotel['preferred_supplier_id'] : null,
            trim((string)($hotel['default_room_type'] ?? '')) ?: null,
            trim((string)($hotel['default_meal_plan'] ?? '')) ?: null,
            max(0, $durationNights),
            $sort + 1
        );
        $insert->execute($values);
    }
}



/*
|--------------------------------------------------------------------------
| LOCAL AI + OCR PACKAGE PDF IMPORT
|--------------------------------------------------------------------------
| High-quality import pipeline:
|   1. Native PDF text (pdftotext)
|   2. OCR fallback for scanned/image PDFs (pdftoppm + Tesseract)
|   3. Local AI parser (Ollama-compatible HTTP API on this server)
|   4. Safe deterministic fallback parser when AI is unavailable
|   5. Packages are always created as DRAFT for admin review
|
| Optional config.php constants:
|
| define('TRAVSCOPE_LOCAL_AI_URL', 'http://127.0.0.1:11434/api/generate');
| define('TRAVSCOPE_LOCAL_AI_MODEL', 'qwen2.5:7b');
| define('TRAVSCOPE_LOCAL_AI_TIMEOUT', 180);
| define('TRAVSCOPE_PDF_OCR_LANGUAGE', 'eng');
|
| Server packages recommended:
|   poppler-utils  -> pdftotext + pdftoppm
|   tesseract-ocr -> OCR for scanned/image-only PDF pages
|--------------------------------------------------------------------------
*/

function packagePdfCommandExists(string $command): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $path = trim((string)@shell_exec(
        'command -v ' . escapeshellarg($command) . ' 2>/dev/null'
    ));

    return $path !== '';
}

function packagePdfRunCommand(string $command): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }

    return (string)@shell_exec($command . ' 2>/dev/null');
}

function packagePdfCleanText(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
    $text = preg_replace('/\n{4,}/u', "\n\n\n", $text) ?? $text;

    return trim($text);
}

function packagePdfLiteralDecode(string $value): string
{
    $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static function ($m) {
        return chr(octdec($m[1]));
    }, $value);

    return strtr($value, [
        '\\\\' => '\\',
        '\\(' => '(',
        '\\)' => ')',
        '\\n' => "\n",
        '\\r' => "\r",
        '\\t' => "\t",
        '\\b' => "\b",
        '\\f' => "\f"
    ]);
}

function packagePdfTextFromContent(string $content): string
{
    $parts = [];

    if (preg_match_all('/BT(.*?)ET/s', $content, $blocks)) {
        foreach ($blocks[1] as $block) {

            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|[\'"])/s', $block, $matches)) {
                foreach ($matches[0] as $match) {
                    if (preg_match('/^\((.*)\)/s', $match, $literal)) {
                        $parts[] = packagePdfLiteralDecode($literal[1]);
                    }
                }
            }

            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $arrays)) {
                foreach ($arrays[1] as $arrayText) {
                    $line = '';

                    if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arrayText, $strings)) {
                        foreach ($strings[0] as $literal) {
                            $line .= packagePdfLiteralDecode(substr($literal, 1, -1));
                        }
                    }

                    if (trim($line) !== '') {
                        $parts[] = $line;
                    }
                }
            }
        }
    }

    return implode("\n", $parts);
}

function extractPackagePdfNativeText(string $file): string
{
    if (!is_file($file)) {
        throw new RuntimeException('Uploaded PDF could not be read.');
    }

    if (packagePdfCommandExists('pdftotext')) {
        $rawFile = tempnam(sys_get_temp_dir(), 'travscope_pdf_raw_');
        $layoutFile = tempnam(sys_get_temp_dir(), 'travscope_pdf_layout_');

        $rawText = '';
        $layoutText = '';

        if ($rawFile !== false) {
            packagePdfRunCommand(
                'pdftotext -raw -enc UTF-8 '
                . escapeshellarg($file)
                . ' '
                . escapeshellarg($rawFile)
            );

            if (is_file($rawFile)) {
                $rawText = packagePdfCleanText(
                    (string)@file_get_contents($rawFile)
                );
            }

            @unlink($rawFile);
        }

        if ($layoutFile !== false) {
            packagePdfRunCommand(
                'pdftotext -layout -enc UTF-8 '
                . escapeshellarg($file)
                . ' '
                . escapeshellarg($layoutFile)
            );

            if (is_file($layoutFile)) {
                $layoutText = packagePdfCleanText(
                    (string)@file_get_contents($layoutFile)
                );
            }

            @unlink($layoutFile);
        }

        /*
        | RAW order is much better for paragraphs, itinerary, inclusions,
        | exclusions and terms. Layout text is appended only as a secondary
        | table reference so AI can still understand pricing columns.
        */
        if (mb_strlen($rawText) >= 80) {
            if (
                mb_strlen($layoutText) >= 80
                && $layoutText !== $rawText
            ) {
                return $rawText
                    . "\n\n--- PDF LAYOUT TABLE REFERENCE ---\n"
                    . $layoutText;
            }

            return $rawText;
        }

        if (mb_strlen($layoutText) >= 80) {
            return $layoutText;
        }
    }

    /*
    | PHP-only fallback for ordinary text PDFs.
    */
    $raw = (string)@file_get_contents($file);

    if ($raw === '') {
        throw new RuntimeException('The PDF is empty or unreadable.');
    }

    $text = packagePdfTextFromContent($raw);

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }

            if ($decoded !== false) {
                $text .= "\n" . packagePdfTextFromContent($decoded);
            }
        }
    }

    return packagePdfCleanText($text);
}

function extractPackagePdfOcrText(string $file): string
{
    if (!packagePdfCommandExists('pdftoppm') || !packagePdfCommandExists('tesseract')) {
        return '';
    }

    $workDir = sys_get_temp_dir()
        . '/travscope_pdf_ocr_'
        . bin2hex(random_bytes(5));

    if (!mkdir($workDir, 0700, true)) {
        return '';
    }

    $prefix = $workDir . '/page';

    try {
        /*
        | PNG + 250 DPI is intentionally used here.
        | The earlier 180 DPI JPEG / PSM 6 mode could read only part of
        | quotation tables and sometimes returned about 4,000 characters.
        */
        packagePdfRunCommand(
            'pdftoppm -png -r 250 '
            . escapeshellarg($file)
            . ' '
            . escapeshellarg($prefix)
        );

        $pages = glob($prefix . '-*.png') ?: [];
        natsort($pages);

        if (!$pages) {
            return '';
        }

        $language = defined('TRAVSCOPE_PDF_OCR_LANGUAGE')
            ? preg_replace('/[^A-Za-z0-9_+\-]/', '', (string)TRAVSCOPE_PDF_OCR_LANGUAGE)
            : 'eng';

        if ($language === '') {
            $language = 'eng';
        }

        $all = [];

        foreach (array_values($pages) as $pageNumber => $image) {
            $pageCandidates = [];

            /*
            | PSM 3 = automatic page segmentation. Best primary mode for
            | mixed headings, tables and paragraphs.
            | PSM 11 = sparse text. It recovers text that PSM 3 can miss
            | inside quotation/table cells.
            */
            foreach ([3, 11] as $psm) {
                $outputBase =
                    $workDir
                    . '/ocr-'
                    . ($pageNumber + 1)
                    . '-psm'
                    . $psm;

                packagePdfRunCommand(
                    'tesseract '
                    . escapeshellarg($image)
                    . ' '
                    . escapeshellarg($outputBase)
                    . ' -l '
                    . escapeshellarg($language)
                    . ' --oem 1 --psm '
                    . $psm
                    . ' preserve_interword_spaces=1'
                );

                $txtFile = $outputBase . '.txt';

                if (is_file($txtFile)) {
                    $candidate = packagePdfCleanText(
                        (string)@file_get_contents($txtFile)
                    );

                    if ($candidate !== '') {
                        $pageCandidates[] = $candidate;
                    }
                }
            }

            if ($pageCandidates) {
                /*
                | Keep the richer OCR result first. Append the alternate OCR
                | only when materially different so AI/fallback parsing has
                | maximum recoverable information.
                */
                usort(
                    $pageCandidates,
                    static function (string $a, string $b): int {
                        return mb_strlen($b) <=> mb_strlen($a);
                    }
                );

                $pageText = $pageCandidates[0];

                if (
                    isset($pageCandidates[1])
                    && mb_strlen($pageCandidates[1]) >= 100
                    && $pageCandidates[1] !== $pageText
                ) {
                    $pageText .=
                        "\n\n--- ALTERNATE OCR READING ---\n"
                        . $pageCandidates[1];
                }

                $all[] =
                    '--- PAGE '
                    . ($pageNumber + 1)
                    . " ---\n"
                    . $pageText;
            }
        }

        return packagePdfCleanText(
            implode("\n\n", $all)
        );

    } finally {
        foreach (glob($workDir . '/*') ?: [] as $temporaryFile) {
            @unlink($temporaryFile);
        }

        @rmdir($workDir);
    }
}

function extractPackagePdfText(string $file): array
{
    $native = extractPackagePdfNativeText($file);
    $nativeLength = mb_strlen(trim($native));
    $ocr = '';

    if ($nativeLength < 500) {
        $ocr = extractPackagePdfOcrText($file);
    }

    if ($nativeLength >= 500) {
        return [
            'text' => $native,
            'method' => 'native',
            'native_chars' => $nativeLength,
            'ocr_chars' => 0
        ];
    }

    if (mb_strlen(trim($ocr)) > $nativeLength) {
        return [
            'text' => $ocr,
            'method' => 'ocr',
            'native_chars' => $nativeLength,
            'ocr_chars' => mb_strlen(trim($ocr))
        ];
    }

    if ($nativeLength >= 80) {
        return [
            'text' => $native,
            'method' => 'native-fallback',
            'native_chars' => $nativeLength,
            'ocr_chars' => mb_strlen(trim($ocr))
        ];
    }

    throw new RuntimeException(
        'PDF text/OCR extraction failed. Install poppler-utils and tesseract-ocr on the server, then retry.'
    );
}

function packagePdfAiUrl(): string
{
    return defined('TRAVSCOPE_LOCAL_AI_URL')
        ? trim((string)TRAVSCOPE_LOCAL_AI_URL)
        : 'http://127.0.0.1:11434/api/generate';
}

function packagePdfAiModel(): string
{
    return defined('TRAVSCOPE_LOCAL_AI_MODEL')
        ? trim((string)TRAVSCOPE_LOCAL_AI_MODEL)
        : 'qwen2.5:7b';
}

function packagePdfJsonFromText(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    $value = preg_replace('/^```(?:json)?\s*/i', '', $value);
    $value = preg_replace('/\s*```$/', '', (string)$value);

    $decoded = json_decode((string)$value, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    $firstBrace = strpos((string)$value, '{');
    $lastBrace = strrpos((string)$value, '}');

    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $candidate = substr(
            (string)$value,
            $firstBrace,
            $lastBrace - $firstBrace + 1
        );

        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function packagePdfLocalAiParse(string $text): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $url = packagePdfAiUrl();
    $model = packagePdfAiModel();

    if ($url === '' || $model === '') {
        return null;
    }

    if (mb_strlen($text) > 120000) {
        $text = mb_substr($text, 0, 120000);
    }

    $schemaInstruction = <<<'PROMPT'
You are a travel-package document parser for TRAVSCOPE.

Read the supplied extracted PDF text and return STRICT JSON only. Do not use markdown.

The PDF may have ANY design:
- quotation with Package Overview / Hotels / Total Price / Transportation / Day Wise Itinerary;
- brochure beginning directly with DAY-01 / DAY-02;
- tables for 3-star, 4-star and 5-star package categories;
- multiple date ranges;
- INR, USD or other currencies;
- child prices such as C/W/B and C/N/B;
- multiple passenger-count prices such as 02 PAX, 04 PAX, 06 PAX, 08 PAX;
- inclusions/exclusions/terms on later pages;
- more than one package in the same PDF.

Never invent missing facts.

MAPPING RULES:
1. 3 Star / 4 Star / 5 Star are PACKAGE CATEGORIES.
2. Existing TRAVSCOPE legacy date pricing stores one adult base price per star category per date period.
   If prices vary by passenger count, choose the 02 PAX per-person rate when available for legacy price_periods, but ALSO preserve occupancy-specific prices in occupancy_prices whenever printed.
3. If a quotation gives TOTAL amount, only divide by adult pax when adult count is clear and no child pax is present.
4. Preserve Child With Bed (C/W/B), Child Without Bed (C/N/B), infant, single, triple and twin/double rates in occupancy_prices. Never collapse different child rates into one invented universal child_price.
5. Detect all printed price validity periods.
6. Parse all day-wise itinerary rows into itinerary_days.
7. Parse printed hotel names into hotels. Keep the printed property name exactly; do not replace a printed hotel with another hotel.
8. Destination is the main tour destination/state/country, not a hotel city.
9. duration_days and duration_nights must match the document.
10. If title is absent, create a factual title using destination + duration only.
11. Never invent a hotel, price, meal plan, inclusion, exclusion, cancellation rule, validity date or legal term.
12. PHP saves all imports as Draft.

Return exactly:
{
  "packages": [
    {
      "title": "",
      "package_code": "",
      "destination": "",
      "short_description": "",
      "description": "",
      "tour_highlights": "",
      "duration_days": 0,
      "duration_nights": 0,
      "child_age_min": 0,
      "child_age_max": 0,
      "child_price": null,
      "regular_price": null,
      "sale_price": null,
      "currency": "INR",
      "package_type": "holiday",
      "hotels": [
        {
          "star_category": 3,
          "hotel_name": "",
          "city": "",
          "room_type": "",
          "meal_plan": "",
          "nights": 0
        }
      ],
      "occupancy_prices": [
        {
          "valid_from": "YYYY-MM-DD",
          "valid_to": "YYYY-MM-DD",
          "star_category": 3,
          "twin_price": null,
          "triple_price": null,
          "single_price": null,
          "child_with_bed_price": null,
          "child_without_bed_price": null,
          "infant_price": null,
          "infant_age_to": 2
        }
      ],
      "inclusions": "",
      "exclusions": "",
      "terms": "",
      "source_text_notes": "",
      "price_periods": [
        {
          "valid_from": "YYYY-MM-DD",
          "valid_to": "YYYY-MM-DD",
          "price_3_star": null,
          "price_4_star": null,
          "price_5_star": null
        }
      ],
      "itinerary_days": [
        {
          "day_number": 1,
          "title": "",
          "description": ""
        }
      ],
      "warnings": []
    }
  ],
  "warnings": []
}
PROMPT;

    $prompt = $schemaInstruction . "\n\nSOURCE PDF TEXT:\n" . $text;

    $payload = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'temperature' => 0.1,
            'num_ctx' => 32768
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return null;
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => defined('TRAVSCOPE_LOCAL_AI_TIMEOUT')
            ? max(30, (int)TRAVSCOPE_LOCAL_AI_TIMEOUT)
            : 180
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        curl_close($curl);
        return null;
    }

    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $outer = json_decode((string)$response, true);

    if (!is_array($outer)) {
        return null;
    }

    if (isset($outer['packages']) && is_array($outer['packages'])) {
        return $outer;
    }

    return packagePdfJsonFromText((string)($outer['response'] ?? ''));
}

function packagePdfNormalizeDate($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/(\d)(st|nd|rd|th)/i', '$1', $value);
    $timestamp = strtotime((string)$value);

    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function packagePdfMoney($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $number = (float)$value;
        return $number >= 0 ? $number : null;
    }

    $clean = str_ireplace(
        [',', '₹', 'INR', 'Rs.', 'Rs', 'USD.', 'USD', '$'],
        '',
        (string)$value
    );

    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $clean, $match)) {
        return null;
    }

    $number = (float)$match[1];

    return $number >= 0 ? $number : null;
}

function packagePdfNormalizePackage(array $source): array
{
    $title = trim((string)($source['title'] ?? ''));
    $destination = trim((string)($source['destination'] ?? ''));

    $days = max(0, (int)($source['duration_days'] ?? 0));
    $nights = max(0, (int)($source['duration_nights'] ?? 0));

    if ($days > 0 && $nights <= 0) {
        $nights = max(0, $days - 1);
    }

    if ($title === '' && $destination !== '' && $days > 0) {
        $title = $destination . ' ' . $days . ' Days ' . $nights . ' Nights Package';
    }

    $currency = strtoupper(trim((string)($source['currency'] ?? 'INR')));

    if ($currency === '') {
        $currency = 'INR';
    }

    $type = strtolower(trim((string)($source['package_type'] ?? 'holiday')));

    $pricePeriods = [];

    foreach (($source['price_periods'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $from = packagePdfNormalizeDate($row['valid_from'] ?? '');
        $to = packagePdfNormalizeDate($row['valid_to'] ?? '');

        $p3 = packagePdfMoney($row['price_3_star'] ?? null);
        $p4 = packagePdfMoney($row['price_4_star'] ?? null);
        $p5 = packagePdfMoney($row['price_5_star'] ?? null);

        if ($from === '' && $to === '' && $p3 === null && $p4 === null && $p5 === null) {
            continue;
        }

        $pricePeriods[] = [
            'valid_from' => $from,
            'valid_to' => $to,
            'price_3_star' => $p3,
            'price_4_star' => $p4,
            'price_5_star' => $p5
        ];
    }

    $itineraryDays = [];

    foreach (($source['itinerary_days'] ?? []) as $index => $day) {
        if (!is_array($day)) {
            continue;
        }

        $number = max(1, (int)($day['day_number'] ?? ($index + 1)));
        $dayTitle = trim((string)($day['title'] ?? ''));
        $dayDescription = trim((string)($day['description'] ?? ''));

        if ($dayTitle === '' && $dayDescription === '') {
            continue;
        }

        $itineraryDays[] = [
            'day_number' => $number,
            'title' => $dayTitle,
            'description' => $dayDescription
        ];
    }

    usort($itineraryDays, static function (array $a, array $b): int {
        return ((int)$a['day_number']) <=> ((int)$b['day_number']);
    });

    /* STEP 09A: preserve structured hotels detected by AI/PDF parser. */
    $hotels = [];

    foreach (($source['hotels'] ?? []) as $hotel) {
        if (!is_array($hotel)) {
            continue;
        }

        $star = (int)($hotel['star_category'] ?? 0);
        $name = trim((string)($hotel['hotel_name'] ?? ''));

        if (!in_array($star, [3, 4, 5], true) || $name === '') {
            continue;
        }

        $hotels[] = [
            'star_category' => $star,
            'hotel_name' => $name,
            'city' => trim((string)($hotel['city'] ?? '')),
            'room_type' => trim((string)($hotel['room_type'] ?? '')),
            'meal_plan' => trim((string)($hotel['meal_plan'] ?? '')),
            'nights' => max(0, (int)($hotel['nights'] ?? 0)),
        ];
    }

    /* STEP 09A: preserve detailed occupancy/child rates when the PDF has them. */
    $occupancyPrices = [];

    foreach (($source['occupancy_prices'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $star = (int)($row['star_category'] ?? 0);
        if (!in_array($star, [3, 4, 5], true)) {
            continue;
        }

        $from = packagePdfNormalizeDate($row['valid_from'] ?? '');
        $to = packagePdfNormalizeDate($row['valid_to'] ?? '');

        $normalizedOccupancy = [
            'valid_from' => $from,
            'valid_to' => $to,
            'star_category' => $star,
            'twin_price' => packagePdfMoney($row['twin_price'] ?? null),
            'triple_price' => packagePdfMoney($row['triple_price'] ?? null),
            'single_price' => packagePdfMoney($row['single_price'] ?? null),
            'child_with_bed_price' => packagePdfMoney($row['child_with_bed_price'] ?? null),
            'child_without_bed_price' => packagePdfMoney($row['child_without_bed_price'] ?? null),
            'infant_price' => packagePdfMoney($row['infant_price'] ?? null),
            'infant_age_to' => max(0, min(5, (int)($row['infant_age_to'] ?? 2))),
        ];

        $hasAmount = false;
        foreach ([
            'twin_price', 'triple_price', 'single_price',
            'child_with_bed_price', 'child_without_bed_price', 'infant_price'
        ] as $priceField) {
            if (($normalizedOccupancy[$priceField] ?? null) !== null) {
                $hasAmount = true;
                break;
            }
        }

        if ($hasAmount) {
            $occupancyPrices[] = $normalizedOccupancy;
        }
    }

    $warnings = [];

    foreach (($source['warnings'] ?? []) as $warning) {
        $warning = trim((string)$warning);

        if ($warning !== '') {
            $warnings[] = $warning;
        }
    }

    return [
        'title' => $title,
        'package_code' => trim((string)($source['package_code'] ?? '')),
        'destination' => $destination,
        'short_description' => trim((string)($source['short_description'] ?? '')),
        'description' => trim((string)($source['description'] ?? '')),
        'tour_highlights' => trim((string)($source['tour_highlights'] ?? '')),
        'duration_days' => $days,
        'duration_nights' => $nights,
        'child_age_min' => max(0, (int)($source['child_age_min'] ?? 0)),
        'child_age_max' => max(0, (int)($source['child_age_max'] ?? 0)),
        'child_price' => packagePdfMoney($source['child_price'] ?? null),
        'regular_price' => packagePdfMoney($source['regular_price'] ?? null),
        'sale_price' => packagePdfMoney($source['sale_price'] ?? null),
        'currency' => $currency,
        'package_type' => $type !== '' ? $type : 'holiday',
        'inclusions' => trim((string)($source['inclusions'] ?? '')),
        'exclusions' => trim((string)($source['exclusions'] ?? '')),
        'terms' => trim((string)($source['terms'] ?? '')),
        'source_text_notes' => trim((string)($source['source_text_notes'] ?? '')),
        'price_periods' => $pricePeriods,
        'itinerary_days' => $itineraryDays,
        'hotels' => $hotels,
        'occupancy_prices' => $occupancyPrices,
        'warnings' => array_values(array_unique($warnings))
    ];
}

function packagePdfExtractSection(string $text, array $starts, array $ends): string
{
    $startPattern = implode('|', array_map(
        static fn($v) => preg_quote($v, '/'),
        $starts
    ));

    $endPattern = $ends
        ? implode('|', array_map(
            static fn($v) => preg_quote($v, '/'),
            $ends
        ))
        : '$';

    if (preg_match(
        '/(?:^|\n)\s*(?:' . $startPattern . ')\s*:?\s*\n?(.*?)(?=(?:\n\s*(?:' . $endPattern . ')\b)|$)/isu',
        $text,
        $match
    )) {
        return trim((string)$match[1]);
    }

    return '';
}

function packagePdfPrimaryText(string $text): string
{
    $marker = "\n--- PDF LAYOUT TABLE REFERENCE ---\n";
    $position = strpos($text, $marker);

    if ($position !== false) {
        $text = substr($text, 0, $position);
    }

    return packagePdfCleanText($text);
}


function packagePdfStripDocumentNoise(string $text): string
{
    $text = packagePdfPrimaryText($text);

    $patterns = [
        '/^\s*MEMORABLE\s+HOSPITALITY\s+PVT\.?\s*LTD\.?\s*$/imu',
        '/^\s*Plot\s+No\s*:-?.*$/imu',
        '/^\s*Odisha,\s*India,.*$/imu',
        '/^\s*Mob\s*:-?.*$/imu',
        '/^\s*GSTIN\s+No.*$/imu',
        '/^\s*PROUDLY\s+REGISTERED\s+WITH\s*$/imu'
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', $text) ?? $text;
    }

    $text = str_replace("\f", "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}


function packagePdfBulletItems(string $block): array
{
    $block = trim($block);

    if ($block === '') {
        return [];
    }

    $block = str_replace(
        ["\xE2\x80\xA2", "•", "", "▪", "●", "◦"],
        "\n• ",
        $block
    );

    $block = preg_replace('/\n{2,}/', "\n", $block) ?? $block;

    $chunks = preg_split('/(?:^|\n)\s*•\s*/u', $block);
    $items = [];

    foreach ($chunks as $chunk) {
        $chunk = trim((string)$chunk);

        if ($chunk === '') {
            continue;
        }

        $chunk = preg_replace('/\s*\n\s*/u', ' ', $chunk) ?? $chunk;
        $chunk = preg_replace('/\s{2,}/u', ' ', $chunk) ?? $chunk;
        $chunk = trim($chunk, " \t\n\r\0\x0B-");

        if ($chunk !== '') {
            $items[] = $chunk;
        }
    }

    return $items;
}


function packagePdfParseInclusionsExclusions(string $text): array
{
    $inclusions = [];
    $exclusions = [];

    if (!preg_match(
        '/(?:^|\n)\s*Inclusions\s+Exclusions\s*\n(.*?)(?=\n\s*Day\s+Wise\s+Itinerary\b|\n\s*Terms\s+(?:and|&)\s+Conditions\b|\z)/isu',
        $text,
        $match
    )) {
        return [
            'inclusions' => '',
            'exclusions' => ''
        ];
    }

    $items = packagePdfBulletItems($match[1]);

    $inExclusions = false;

    foreach ($items as $item) {
        $lower = mb_strtolower($item);

        /*
        | In many quotation PDFs both columns are extracted sequentially.
        | These phrases reliably mark the beginning of the Exclusions column.
        */
        if (
            !$inExclusions
            && preg_match(
                '/^(?:airfare|air\s*fare|train\s*fare|airfare\/\s*train|5%\s*gst|gst\b|personal\s+expenses|any\s+additional\s+expenses)/iu',
                $item
            )
        ) {
            $inExclusions = true;
        }

        if ($inExclusions) {
            $exclusions[] = $item;
        } else {
            $inclusions[] = $item;
        }
    }

    return [
        'inclusions' => implode("\n", $inclusions),
        'exclusions' => implode("\n", $exclusions)
    ];
}


function packagePdfParseItineraryDays(string $text): array
{
    $days = [];

    $itineraryStart = stripos($text, 'Day Wise Itinerary');

    if ($itineraryStart !== false) {
        $text = substr($text, $itineraryStart + strlen('Day Wise Itinerary'));
    }

    $termsPosition = preg_match(
        '/(?:^|\n)\s*Terms\s+(?:and|&)\s+Conditions\b/iu',
        $text,
        $termsMatch,
        PREG_OFFSET_CAPTURE
    )
        ? (int)$termsMatch[0][1]
        : false;

    if ($termsPosition !== false) {
        $text = substr($text, 0, $termsPosition);
    }

    /*
    | Handles:
    | 1st Day (...) : Title
    | DAY-01 : Title
    | DAY 01 - Title
    */
    $pattern =
        '/(?:^|\n)\s*'
        . '(?:'
        . '(\d{1,2})(?:st|nd|rd|th)\s+Day(?:\s*\([^)]*\))?'
        . '|DAY\s*[-–—]?\s*0?(\d{1,2})'
        . ')'
        . '\s*(?:[:\-–—]\s*)?'
        . '([^\n]*)'
        . '\n'
        . '(.*?)'
        . '(?='
        . '\n\s*(?:\d{1,2}(?:st|nd|rd|th)\s+Day(?:\s*\([^)]*\))?|DAY\s*[-–—]?\s*0?\d{1,2})\b'
        . '|\z'
        . ')/isu';

    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $row) {
            $number = (int)($row[1] !== '' ? $row[1] : $row[2]);
            $title = trim((string)$row[3]);
            $description = trim((string)$row[4]);

            $description = preg_replace('/\s*\n\s*/u', ' ', $description) ?? $description;
            $description = preg_replace('/\s{2,}/u', ' ', $description) ?? $description;

            if ($number <= 0) {
                continue;
            }

            $days[] = [
                'day_number' => $number,
                'title' => $title,
                'description' => trim($description)
            ];
        }
    }

    usort($days, static function (array $a, array $b): int {
        return ((int)$a['day_number']) <=> ((int)$b['day_number']);
    });

    return $days;
}


function packagePdfParseTerms(string $text): string
{
    if (preg_match(
        '/(?:^|\n)\s*Terms\s+(?:and|&)\s+Conditions\s*\n(.*)\z/isu',
        $text,
        $match
    )) {
        $terms = trim((string)$match[1]);
        $terms = preg_replace('/\n{3,}/', "\n\n", $terms) ?? $terms;

        return $terms;
    }

    return '';
}


function packagePdfParseOverview(string $text): array
{
    $out = [
        'trip_id' => '',
        'destination' => '',
        'route' => '',
        'start_date' => '',
        'days' => 0,
        'nights' => 0,
        'adults' => 0
    ];

    /*
    | OCR tolerant: do not require labels to start on a new line.
    */
    if (preg_match(
        '/\bTrip\s*I[D0]\s*[:\-]?\s*([A-Za-z0-9_-]{3,})/iu',
        $text,
        $m
    )) {
        $out['trip_id'] = trim($m[1]);
    }

    if (preg_match(
        '/\bDestination\s*[:\-]?\s*([A-Za-z][A-Za-z .&-]{2,80}?)(?=\s+(?:Start\s*Date|Trip\s*Duration|Pax|Varanasi|Prayagraj|Ayodhya|Lucknow)|\n|\z)/iu',
        $text,
        $m
    )) {
        $out['destination'] = trim($m[1]);
    }

    if (preg_match(
        '/\bDestination\s*[:\-]?\s*[^\n]{1,100}\n(.*?)(?=\n\s*Start\s*Date\b)/isu',
        $text,
        $m
    )) {
        $route = trim((string)$m[1]);
        $route = preg_replace('/\s*\n\s*/u', ' ', $route) ?? $route;
        $out['route'] = trim($route);
    }

    if (preg_match(
        '/\bStart\s*Date\s*[:\-]?\s*([0-9]{1,2}\s+[A-Za-z]+\s*,?\s*[0-9]{4}|[0-9]{1,2}[\/.-][0-9]{1,2}[\/.-][0-9]{2,4})/iu',
        $text,
        $m
    )) {
        $out['start_date'] = packagePdfNormalizeDate($m[1]);
    }

    if (preg_match(
        '/\bTrip\s*Duration\s*[:\-]?\s*(\d+)\s*Nights?\s*[\/&|-]\s*(\d+)\s*Days?/iu',
        $text,
        $m
    )) {
        $out['nights'] = (int)$m[1];
        $out['days'] = (int)$m[2];
    } elseif (preg_match(
        '/\b(\d+)\s*Nights?\s*[\/&|-]\s*(\d+)\s*Days?\b/iu',
        $text,
        $m
    )) {
        $out['nights'] = (int)$m[1];
        $out['days'] = (int)$m[2];
    } elseif (preg_match(
        '/\bTrip\s*Duration\s*[:\-]?\s*(\d+)\s*Days?\s*[\/&|-]\s*(\d+)\s*Nights?/iu',
        $text,
        $m
    )) {
        $out['days'] = (int)$m[1];
        $out['nights'] = (int)$m[2];
    }

    if (preg_match(
        '/\bPax\s*[:\-]?\s*(\d+)\s*Adults?/iu',
        $text,
        $m
    )) {
        $out['adults'] = max(1, (int)$m[1]);
    } elseif (preg_match(
        '/\b(\d+)\s*Adults?\b/iu',
        $text,
        $m
    )) {
        $out['adults'] = max(1, (int)$m[1]);
    }

    /*
    | Last-resort destination recognition for quotation OCR.
    | This prevents "No package could be detected" when the Destination
    | label itself was lost but the state/country name is visible.
    */
    if ($out['destination'] === '') {
        $knownDestinations = [
            'Uttar Pradesh',
            'Odisha',
            'Rajasthan',
            'Kerala',
            'Goa',
            'Kashmir',
            'Himachal Pradesh',
            'Uttarakhand',
            'Sikkim',
            'West Bengal',
            'Madhya Pradesh',
            'Gujarat',
            'Maharashtra',
            'Tamil Nadu',
            'Karnataka',
            'Sri Lanka',
            'Nepal',
            'Bhutan',
            'Thailand',
            'Dubai',
            'Maldives'
        ];

        foreach ($knownDestinations as $candidate) {
            if (preg_match(
                '/\b' . preg_quote($candidate, '/') . '\b/iu',
                $text
            )) {
                $out['destination'] = $candidate;
                break;
            }
        }
    }

    return $out;
}

function packagePdfParseStructuredHotels(string $text): array
{
    $found = [];

    $sections = [
        3 => '/Option\s*1\s*:\s*3\s*Star(?:\s*Deluxe)?\s*Package(.*?)(?=Option\s*2\s*:\s*4\s*Star(?:\s*Deluxe)?\s*Package|Total\s+Price|\z)/isu',
        4 => '/Option\s*2\s*:\s*4\s*Star(?:\s*Deluxe)?\s*Package(.*?)(?=Option\s*3\s*:\s*5\s*Star(?:\s*Deluxe)?\s*Package|Total\s+Price|\z)/isu',
        5 => '/Option\s*3\s*:\s*5\s*Star(?:\s*Deluxe)?\s*Package(.*?)(?=Total\s+Price|\z)/isu'
    ];

    $knownCities = '(?:Varanasi|Prayagraj|Allahabad|Ayodhya|Lucknow|Agra|Delhi|New\s+Delhi|Jaipur|Goa|Kolkata|Mumbai|Chennai|Hyderabad|Bengaluru|Bangalore|Srinagar|Puri|Bhubaneswar|Kochi|Cochin|Munnar|Thekkady|Alleppey|Alappuzha|Kovalam|Kanyakumari|Madurai|Ooty|Kodaikanal|Mysore|Mysuru|Manali|Shimla|Dharamshala|Amritsar|Gangtok|Darjeeling|Shillong|Guwahati|Jaisalmer|Jodhpur|Udaipur|Ahmedabad|Dwarka|Somnath|Kathmandu|Pokhara|Thimphu|Paro|Colombo|Kandy|Bentota|Nuwara\s+Eliya|Bangkok|Pattaya|Phuket|Krabi|Dubai|Abu\s+Dhabi|Malé|Male)';

    foreach ($sections as $star => $pattern) {
        if (!preg_match($pattern, $text, $match)) {
            continue;
        }

        $body = (string)$match[1];

        if (preg_match_all(
            '/\b(' . $knownCities . ')\b\s+([^\n]{2,140}?)(?=\s+(?:3|4|5)\s*Star\b|\n|\z)/iu',
            $body,
            $rows,
            PREG_SET_ORDER
        )) {
            foreach ($rows as $row) {
                $city = trim((string)$row[1]);
                $hotel = trim((string)$row[2]);
                $hotel = preg_replace('/\s{2,}/u', ' ', $hotel) ?? $hotel;
                $hotel = preg_replace('/\b(?:CP|MAP|AP|EP)\s*$/iu', '', $hotel) ?? $hotel;
                $hotel = trim($hotel, " \t\n\r\0\x0B-|:");

                if ($hotel === '' || mb_strlen($hotel) < 2) {
                    continue;
                }

                $found[] = [
                    'star_category' => $star,
                    'hotel_name' => $hotel,
                    'city' => $city,
                    'room_type' => '',
                    'meal_plan' => '',
                    'nights' => 0,
                ];
            }
        }
    }

    /* Generic rows such as "Hotel: ABC Resort (4 Star)". */
    if (preg_match_all(
        '/(?:Hotel|Property)\s*(?:Name)?\s*[:\-]\s*([^\n,]{2,140}).{0,35}?\b([345])\s*Star\b/iu',
        $text,
        $generic,
        PREG_SET_ORDER
    )) {
        foreach ($generic as $row) {
            $name = trim((string)$row[1]);
            $star = (int)$row[2];
            if ($name !== '' && in_array($star, [3,4,5], true)) {
                $found[] = [
                    'star_category' => $star,
                    'hotel_name' => $name,
                    'city' => '',
                    'room_type' => '',
                    'meal_plan' => '',
                    'nights' => 0,
                ];
            }
        }
    }

    $unique = [];
    $out = [];
    foreach ($found as $hotel) {
        $key = $hotel['star_category'] . '|' . mb_strtolower(trim($hotel['city'] . '|' . $hotel['hotel_name']));
        if (isset($unique[$key])) continue;
        $unique[$key] = true;
        $out[] = $hotel;
    }

    return $out;
}

function packagePdfParseHotelSummary(string $text): string
{
    $byStar = [];

    foreach (packagePdfParseStructuredHotels($text) as $hotel) {
        $star = (int)$hotel['star_category'];
        $label = trim((string)$hotel['hotel_name']);
        $city = trim((string)$hotel['city']);
        if ($city !== '') {
            $label = $city . ': ' . $label;
        }
        $byStar[$star][] = $label;
    }

    $summaries = [];
    foreach ([3,4,5] as $star) {
        if (empty($byStar[$star])) continue;
        $summaries[] = $star . ' Star Package Hotels — ' . implode('; ', array_values(array_unique($byStar[$star])));
    }

    return implode("\n", $summaries);
}


function packagePdfParseQuotationPrices(string $text, array $overview): array
{
    $p3 = $p4 = $p5 = null;

    $priceText = $text;

    if (preg_match(
        '/\bTotal\s+Price\b(.*?)(?=\bTransportation\b|\bDay\s+Wise\s+Itinerary\b|\z)/isu',
        $text,
        $section
    )) {
        $priceText = $section[1];
    }

    if (preg_match(
        '/Option\s*1\s*:\s*3\s*Star(?:\s*Deluxe)?\s*Package.*?Total\s*:\s*([0-9,]+(?:\.[0-9]+)?)/isu',
        $priceText,
        $m
    )) {
        $p3 = packagePdfMoney($m[1]);
    }

    if (preg_match(
        '/Option\s*2\s*:\s*4\s*Star(?:\s*Deluxe)?\s*Package.*?Total\s*:\s*([0-9,]+(?:\.[0-9]+)?)/isu',
        $priceText,
        $m
    )) {
        $p4 = packagePdfMoney($m[1]);
    }

    if (preg_match(
        '/Option\s*3\s*:\s*5\s*Star(?:\s*Deluxe)?\s*Package.*?Total\s*:\s*([0-9,]+(?:\.[0-9]+)?)/isu',
        $priceText,
        $m
    )) {
        $p5 = packagePdfMoney($m[1]);
    }

    $adults = max(0, (int)($overview['adults'] ?? 0));

    if (
        $adults > 0
        && !preg_match('/\b(?:Child|Children)\s*[:\-]?\s*[1-9]\d*\b/iu', $text)
    ) {
        foreach (['p3', 'p4', 'p5'] as $key) {
            if ($$key !== null && $$key > 0) {
                $$key = round($$key / $adults, 2);
            }
        }
    }

    if ($p3 === null && $p4 === null && $p5 === null) {
        return [];
    }

    $date = trim((string)($overview['start_date'] ?? ''));

    if ($date === '') {
        $date = date('Y-m-d');
    }

    return [[
        'valid_from' => $date,
        'valid_to' => $date,
        'price_3_star' => $p3,
        'price_4_star' => $p4,
        'price_5_star' => $p5
    ]];
}

function packagePdfParseChildPolicy(string $terms): array
{
    $min = 0;
    $max = 0;

    if (preg_match(
        '/Child\s*(\d+)\s*[-–]\s*(\d+)\s*yrs?/iu',
        $terms,
        $m
    )) {
        $min = (int)$m[1];
        $max = (int)$m[2];
    } elseif (preg_match(
        '/Child\s+below\s+(\d+)\s*yrs?.*?(?:Child\s+)?(\d+)\s*[-–]\s*(\d+)\s*yrs?/isu',
        $terms,
        $m
    )) {
        $min = (int)$m[2];
        $max = (int)$m[3];
    }

    return [
        'child_age_min' => $min,
        'child_age_max' => $max,
        'child_price' => null
    ];
}


function packagePdfMergePackage(array $primary, array $fallback): array
{
    $merged = $primary;

    $stringFields = [
        'title',
        'package_code',
        'destination',
        'short_description',
        'description',
        'tour_highlights',
        'currency',
        'package_type',
        'inclusions',
        'exclusions',
        'terms',
        'source_text_notes'
    ];

    foreach ($stringFields as $field) {
        if (
            trim((string)($merged[$field] ?? '')) === ''
            && trim((string)($fallback[$field] ?? '')) !== ''
        ) {
            $merged[$field] = $fallback[$field];
        }
    }

    foreach (['duration_days', 'duration_nights', 'child_age_min', 'child_age_max'] as $field) {
        if (
            (int)($merged[$field] ?? 0) <= 0
            && (int)($fallback[$field] ?? 0) > 0
        ) {
            $merged[$field] = $fallback[$field];
        }
    }

    foreach (['child_price', 'regular_price', 'sale_price'] as $field) {
        if (
            ($merged[$field] ?? null) === null
            && ($fallback[$field] ?? null) !== null
        ) {
            $merged[$field] = $fallback[$field];
        }
    }

    if (
        empty($merged['price_periods'])
        && !empty($fallback['price_periods'])
    ) {
        $merged['price_periods'] = $fallback['price_periods'];
    }

    if (
        empty($merged['itinerary_days'])
        && !empty($fallback['itinerary_days'])
    ) {
        $merged['itinerary_days'] = $fallback['itinerary_days'];
    }

    foreach (['hotels', 'occupancy_prices'] as $arrayField) {
        if (
            empty($merged[$arrayField])
            && !empty($fallback[$arrayField])
            && is_array($fallback[$arrayField])
        ) {
            $merged[$arrayField] = $fallback[$arrayField];
        }
    }

    $merged['warnings'] = array_values(array_unique(array_merge(
        is_array($merged['warnings'] ?? null) ? $merged['warnings'] : [],
        is_array($fallback['warnings'] ?? null) ? $fallback['warnings'] : []
    )));

    return $merged;
}


function packagePdfFilenamePackage(string $originalName, string $text): array
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/\(\d+\)\s*$/', '', $base) ?? $base;
    $base = preg_replace('/[_-]+/', ' ', $base) ?? $base;
    $base = preg_replace('/\s{2,}/', ' ', $base) ?? $base;
    $base = trim($base);

    $days = 0;
    $nights = 0;

    if (preg_match('/(\d+)\s*Night(?:s)?\s*(\d+)\s*Day(?:s)?/iu', $base, $m)) {
        $nights = (int)$m[1];
        $days = (int)$m[2];
    } elseif (preg_match('/(\d+)\s*Day(?:s)?\s*(\d+)\s*Night(?:s)?/iu', $base, $m)) {
        $days = (int)$m[1];
        $nights = (int)$m[2];
    } elseif (preg_match('/\b(\d+)\s*Nights?\s*[\/&|-]\s*(\d+)\s*Days?\b/iu', $text, $m)) {
        $nights = (int)$m[1];
        $days = (int)$m[2];
    }

    $destination = trim((string)preg_replace(
        '/\b\d+\s*Night(?:s)?\s*\d+\s*Day(?:s)?\b.*$/iu',
        '',
        $base
    ));

    if ($destination === '') {
        if (preg_match('/\bDestination\s*[:\-]?\s*([A-Za-z][A-Za-z .&-]{2,80})/iu', $text, $m)) {
            $destination = trim($m[1]);
        }
    }

    $title = $base !== ''
        ? $base
        : (
            $destination !== ''
                ? $destination . ' Travel Package'
                : 'Imported Travel Package'
        );

    return [
        'title' => $title,
        'destination' => $destination,
        'duration_days' => $days,
        'duration_nights' => $nights
    ];
}


function packagePdfFallbackParse(string $text, string $originalName = ''): array
{
    $text = packagePdfStripDocumentNoise($text);

    $overview = packagePdfParseOverview($text);

    $destination = trim((string)$overview['destination']);
    $days = max(0, (int)$overview['days']);
    $nights = max(0, (int)$overview['nights']);

    $itineraryDays = packagePdfParseItineraryDays($text);

    if ($days <= 0 && $itineraryDays) {
        $days = max(array_column($itineraryDays, 'day_number'));
    }

    if ($nights <= 0 && $days > 0) {
        $nights = max(0, $days - 1);
    }

    if ($destination === '' && preg_match('/\bSri\s+Lanka\b/iu', $text)) {
        $destination = 'Sri Lanka';
    }

    $title = '';

    if (preg_match(
        '/(?:^|\n)\s*(?:Package\s*Title|Package\s*Name|Tour\s*Package)\s*[:\-]\s*([^\n]+)/iu',
        $text,
        $m
    )) {
        $title = trim($m[1]);
    }

    if ($title === '' && $destination !== '' && $days > 0) {
        $title =
            $destination
            . ' '
            . $days
            . ' Days '
            . $nights
            . ' Nights Package';
    }

    $incExc = packagePdfParseInclusionsExclusions($text);
    $terms = packagePdfParseTerms($text);
    $childPolicy = packagePdfParseChildPolicy($terms);

    $currency = preg_match('/\bUSD\b|\bUSD\./iu', $text)
        ? 'USD'
        : 'INR';

    /*
    | First try date-range rate tables. If this is a fixed-date quotation,
    | use the quotation totals and the exact Start Date instead.
    */
    $pricePeriods = [];

    if (preg_match_all(
        '/(\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+\s+\d{4})\s+TO\s+(\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+\s+\d{4})(.*?)(?=(?:\n\s*\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+\s+\d{4}\s+TO)|\z)/isu',
        $text,
        $ranges,
        PREG_SET_ORDER
    )) {
        foreach ($ranges as $range) {
            $from = packagePdfNormalizeDate($range[1]);
            $to = packagePdfNormalizeDate($range[2]);
            $body = $range[3];

            $p3 = $p4 = $p5 = null;

            if (preg_match('/03\s*STAR\s+(?:USD\.?\s*)?([0-9]+(?:\.[0-9]+)?)/iu', $body, $m)) {
                $p3 = (float)$m[1];
            }

            if (preg_match('/04\s*STAR\s+(?:USD\.?\s*)?([0-9]+(?:\.[0-9]+)?)/iu', $body, $m)) {
                $p4 = (float)$m[1];
            }

            if (preg_match('/05\s*STAR\s+(?:USD\.?\s*)?([0-9]+(?:\.[0-9]+)?)/iu', $body, $m)) {
                $p5 = (float)$m[1];
            }

            if (
                $from !== ''
                && $to !== ''
                && (
                    $p3 !== null
                    || $p4 !== null
                    || $p5 !== null
                )
            ) {
                $pricePeriods[] = [
                    'valid_from' => $from,
                    'valid_to' => $to,
                    'price_3_star' => $p3,
                    'price_4_star' => $p4,
                    'price_5_star' => $p5
                ];
            }
        }
    }

    if (!$pricePeriods) {
        $pricePeriods = packagePdfParseQuotationPrices(
            $text,
            $overview
        );
    }

    $hotelSummary = packagePdfParseHotelSummary($text);

    $route = trim((string)$overview['route']);
    $startDate = trim((string)$overview['start_date']);
    $adults = max(0, (int)$overview['adults']);

    $descriptionParts = [];

    if ($route !== '') {
        $descriptionParts[] = 'Route: ' . $route;
    }

    if ($startDate !== '') {
        $descriptionParts[] =
            'Travel start date: '
            . date('d M Y', strtotime($startDate));
    }

    if ($adults > 0) {
        $descriptionParts[] =
            'Quoted for '
            . $adults
            . ' Adult'
            . ($adults === 1 ? '' : 's')
            . '.';
    }

    if ($hotelSummary !== '') {
        $descriptionParts[] = $hotelSummary;
    }

    if (preg_match(
        '/Transportation\s+Used\s*:\s*([^\n]+)/iu',
        $text,
        $m
    )) {
        $descriptionParts[] =
            'Transportation: '
            . trim($m[1]);
    }

    $specialInclusions = '';

    if (preg_match(
        '/Other\s+Special\s+Inclusions\s*\(for\s+all\s+options\)(.*?)(?=\n\s*Inclusions\s+Exclusions\b|\n\s*Day\s+Wise\s+Itinerary\b)/isu',
        $text,
        $m
    )) {
        $specialInclusions = trim((string)$m[1]);
        $specialInclusions = preg_replace('/\s*\n\s*/u', ' ', $specialInclusions) ?? $specialInclusions;
        $specialInclusions = preg_replace('/\s{2,}/u', ' ', $specialInclusions) ?? $specialInclusions;
    }

    $highlights = [];

    foreach ($itineraryDays as $day) {
        $dayTitle = trim((string)($day['title'] ?? ''));

        if ($dayTitle !== '') {
            $highlights[] = $dayTitle;
        }
    }

    if ($specialInclusions !== '') {
        $highlights[] = $specialInclusions;
    }

    $shortDescriptionParts = [];

    if ($route !== '') {
        $shortDescriptionParts[] = $route;
    }

    if ($days > 0) {
        $shortDescriptionParts[] =
            $days
            . ' Days / '
            . $nights
            . ' Nights';
    }

    $sourceNotes = [];

    if ($adults > 0 && $pricePeriods) {
        $sourceNotes[] =
            'PDF quotation total prices were converted to per-adult prices using '
            . $adults
            . ' adults because the package pricing table is per adult.';
    }

    if (preg_match('/excluding\s+GST/iu', $text)) {
        $sourceNotes[] = 'Quoted prices exclude GST as stated in the PDF.';
    }

    if (preg_match('/FLIGHT\s+AND\s+TRAIN\s+TICKET\s+EXTRA/iu', $text)) {
        $sourceNotes[] = 'Flight and train tickets are extra as stated in the PDF.';
    }

    if ($title === '') {
        if ($destination !== '') {
            $title = $destination . ' Travel Package';

            if ($days > 0) {
                $title =
                    $destination
                    . ' '
                    . $days
                    . ' Days '
                    . $nights
                    . ' Nights Package';
            }
        } elseif (trim((string)$overview['trip_id']) !== '') {
            $title =
                'Travel Package '
                . trim((string)$overview['trip_id']);
        } elseif ($days > 0) {
            $title =
                $days
                . ' Days '
                . $nights
                . ' Nights Travel Package';
        }
    }

    /*
    | FINAL SAFETY FALLBACK:
    | Never discard a readable PDF only because OCR damaged the overview labels.
    | The original filename is used to recover title/destination/duration.
    */
    if ($title === '' || $destination === '' || $days <= 0) {
        $filenamePackage =
            packagePdfFilenamePackage(
                $originalName,
                $text
            );

        if ($title === '') {
            $title =
                trim((string)($filenamePackage['title'] ?? ''));
        }

        if ($destination === '') {
            $destination =
                trim((string)($filenamePackage['destination'] ?? ''));
        }

        if ($days <= 0) {
            $days =
                max(
                    0,
                    (int)($filenamePackage['duration_days'] ?? 0)
                );
        }

        if ($nights <= 0) {
            $nights =
                max(
                    0,
                    (int)($filenamePackage['duration_nights'] ?? 0)
                );
        }
    }

    if ($title === '') {
        $title =
            'Imported Travel Package '
            . date('YmdHis');
    }

    $package = packagePdfNormalizePackage([
        'title' => $title,
        'package_code' => trim((string)$overview['trip_id']),
        'destination' => $destination,
        'short_description' => implode(' • ', $shortDescriptionParts),
        'description' => implode("\n", $descriptionParts),
        'tour_highlights' => implode("\n", array_values(array_unique($highlights))),
        'duration_days' => $days,
        'duration_nights' => $nights,
        'child_age_min' => $childPolicy['child_age_min'],
        'child_age_max' => $childPolicy['child_age_max'],
        'child_price' => $childPolicy['child_price'],
        'currency' => $currency,
        'package_type' => 'holiday',
        'inclusions' => $incExc['inclusions'],
        'exclusions' => $incExc['exclusions'],
        'terms' => $terms,
        'source_text_notes' => implode("\n", $sourceNotes),
        'price_periods' => $pricePeriods,
        'itinerary_days' => $itineraryDays,
        'hotels' => packagePdfParseStructuredHotels($text),
        'occupancy_prices' => [],
        'warnings' => [
            'Deterministic detail parser also checked the PDF so missing AI fields could be completed automatically.'
        ]
    ]);

    return [
        'packages' => [$package],
        'warnings' => []
    ];
}

function packagePdfResolveDestinationId(array $destinations, string $requested): int
{
    $requested = mb_strtolower(trim($requested));

    if ($requested === '') {
        return 0;
    }

    foreach ($destinations as $destination) {
        $name = mb_strtolower(trim((string)($destination['name'] ?? '')));

        if ($name !== '' && $name === $requested) {
            return (int)$destination['id'];
        }
    }

    foreach ($destinations as $destination) {
        $name = mb_strtolower(trim((string)($destination['name'] ?? '')));

        if (
            $name !== ''
            && (
                str_contains($requested, $name)
                || str_contains($name, $requested)
            )
        ) {
            return (int)$destination['id'];
        }
    }

    return 0;
}

function packagePdfPriceFallback(array $periods): float
{
    $prices = [];

    foreach ($periods as $period) {
        foreach (['price_3_star', 'price_4_star', 'price_5_star'] as $key) {
            if (
                isset($period[$key])
                && $period[$key] !== null
                && (float)$period[$key] > 0
            ) {
                $prices[] = (float)$period[$key];
            }
        }
    }

    return $prices ? min($prices) : 0.0;
}

function packagePdfCombinedItineraryText(array $days): string
{
    $parts = [];

    foreach ($days as $day) {
        $number = max(1, (int)($day['day_number'] ?? 1));
        $title = trim((string)($day['title'] ?? ''));
        $description = trim((string)($day['description'] ?? ''));

        $heading = 'Day ' . $number;

        if ($title !== '') {
            $heading .= ': ' . $title;
        }

        $parts[] = trim(
            $heading
            . ($description !== '' ? "\n" . $description : '')
        );
    }

    return implode("\n\n", $parts);
}


/*
|--------------------------------------------------------------------------
| STEP 09A - INTELLIGENT PDF PACKAGE AUTO BUILDER
|--------------------------------------------------------------------------
| The importer now distinguishes:
|   - PDF Data Coverage: data genuinely captured/derived from the uploaded PDF.
|   - Website Package Readiness: the normal TRAVSCOPE publish-readiness score
|     after safe automatic preparation (code, descriptions, images, hotels).
|
| Facts such as hotel, price, tax, meal plan, inclusion, exclusion and legal
| terms are never invented. Only presentation text may be safely derived from
| already detected destination/duration/itinerary facts.
*/
function packagePdfHasPrice(array $package): bool
{
    foreach (($package['price_periods'] ?? []) as $period) {
        foreach (['price_3_star','price_4_star','price_5_star'] as $field) {
            if (($period[$field] ?? null) !== null && (float)$period[$field] > 0) {
                return true;
            }
        }
    }

    foreach (['regular_price','sale_price'] as $field) {
        if (($package[$field] ?? null) !== null && (float)$package[$field] > 0) {
            return true;
        }
    }

    return false;
}

function packagePdfDataCoverage(array $package, bool $hasPdfImage = false): array
{
    $checks = [
        'Title' => [6, trim((string)($package['title'] ?? '')) !== ''],
        'Destination' => [10, trim((string)($package['destination'] ?? '')) !== ''],
        'Duration' => [10, (int)($package['duration_days'] ?? 0) > 0],
        'Short description' => [4, trim((string)($package['short_description'] ?? '')) !== ''],
        'Full description' => [5, trim((string)($package['description'] ?? '')) !== ''],
        'Highlights' => [5, trim((string)($package['tour_highlights'] ?? '')) !== ''],
        'Pricing' => [15, packagePdfHasPrice($package)],
        'Itinerary' => [15, !empty($package['itinerary_days'])],
        'Inclusions' => [8, trim((string)($package['inclusions'] ?? '')) !== ''],
        'Exclusions' => [7, trim((string)($package['exclusions'] ?? '')) !== ''],
        'Terms' => [7, trim((string)($package['terms'] ?? '')) !== ''],
        'Hotels' => [4, !empty($package['hotels'])],
        'Child / occupancy pricing' => [2, !empty($package['occupancy_prices']) || ($package['child_price'] ?? null) !== null],
        'Usable PDF image' => [2, $hasPdfImage],
    ];

    $earned = 0;
    $total = 0;
    $missing = [];

    foreach ($checks as $label => [$weight, $ok]) {
        $total += $weight;
        if ($ok) {
            $earned += $weight;
        } else {
            $missing[] = $label;
        }
    }

    return [
        'score' => $total > 0 ? (int)round(($earned / $total) * 100) : 0,
        'missing' => $missing,
    ];
}

function packagePdfSafePreparePackage(array $package): array
{
    $auto = [];
    $destination = trim((string)($package['destination'] ?? ''));
    $days = max(0, (int)($package['duration_days'] ?? 0));
    $nights = max(0, (int)($package['duration_nights'] ?? 0));
    $itinerary = is_array($package['itinerary_days'] ?? null) ? $package['itinerary_days'] : [];

    if (trim((string)($package['short_description'] ?? '')) === '') {
        $parts = [];
        if ($destination !== '') $parts[] = $destination;
        if ($days > 0) $parts[] = $days . ' Days / ' . $nights . ' Nights';
        if ($parts) {
            $package['short_description'] = implode(' • ', $parts);
            $auto[] = 'Short description';
        }
    }

    if (trim((string)($package['tour_highlights'] ?? '')) === '' && $itinerary) {
        $highlights = [];
        foreach ($itinerary as $day) {
            $title = trim((string)($day['title'] ?? ''));
            if ($title !== '') $highlights[] = $title;
            if (count($highlights) >= 10) break;
        }
        if ($highlights) {
            $package['tour_highlights'] = implode("\n", array_values(array_unique($highlights)));
            $auto[] = 'Tour highlights';
        }
    }

    if (trim((string)($package['description'] ?? '')) === '') {
        $sentences = [];
        if ($destination !== '' && $days > 0) {
            $sentences[] = 'Explore ' . $destination . ' with this ' . $days . '-day / ' . $nights . '-night travel package.';
        } elseif ($destination !== '') {
            $sentences[] = 'Travel package for ' . $destination . '.';
        }

        $titles = [];
        foreach ($itinerary as $day) {
            $title = trim((string)($day['title'] ?? ''));
            if ($title !== '') $titles[] = $title;
            if (count($titles) >= 5) break;
        }
        if ($titles) {
            $sentences[] = 'Planned highlights include ' . implode(', ', $titles) . '.';
        }

        if ($sentences) {
            $package['description'] = implode("\n", $sentences);
            $auto[] = 'Full description';
        }
    }

    return ['package' => $package, 'auto' => $auto];
}

function packagePdfSafeFileBase(string $value): string
{
    $value = pathinfo($value, PATHINFO_FILENAME);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = preg_replace('/[^A-Za-z0-9 _-]+/', '', (string)$value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = trim($value, " .-_");
    return $value !== '' ? mb_substr($value, 0, 80) : 'Imported Package';
}

function packagePdfExtractPackageImages(string $pdfFile, string $originalName): array
{
    if (!packagePdfCommandExists('pdfimages')) {
        return [];
    }

    $workDir = sys_get_temp_dir() . '/travscope_pdf_images_' . bin2hex(random_bytes(5));
    if (!mkdir($workDir, 0700, true)) {
        return [];
    }

    $prefix = $workDir . '/img';
    $saved = [];

    try {
        packagePdfRunCommand(
            'pdfimages -all ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg($prefix)
        );

        $candidates = [];
        foreach (glob($workDir . '/img-*') ?: [] as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
            $info = @getimagesize($file);
            if (!$info) continue;
            $width = (int)$info[0];
            $height = (int)$info[1];
            if ($width < 420 || $height < 240) continue;
            $ratio = $height > 0 ? $width / $height : 0;
            if ($ratio < 1.05 || $ratio > 3.2) continue;
            $area = $width * $height;
            $candidates[] = ['file'=>$file,'area'=>$area,'width'=>$width,'height'=>$height];
        }

        usort($candidates, fn($a,$b) => $b['area'] <=> $a['area']);
        $seen = [];
        $base = packagePdfSafeFileBase($originalName);
        $uploadDir = __DIR__ . '/uploads/packages/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) return [];

        foreach (array_slice($candidates, 0, 8) as $index => $candidate) {
            $hash = @sha1_file($candidate['file']) ?: '';
            if ($hash !== '' && isset($seen[$hash])) continue;
            if ($hash !== '') $seen[$hash] = true;

            $fileName = $base . ' - PDF ' . str_pad((string)(count($saved)+1), 2, '0', STR_PAD_LEFT) . '.jpg';
            $destination = $uploadDir . $fileName;
            $relative = 'uploads/packages/' . $fileName;

            if (!is_file($destination)) {
                try {
                    optimizeImportedPexelsImage($candidate['file'], $destination);
                } catch (Throwable $e) {
                    continue;
                }
            }

            if (is_file($destination) && @getimagesize($destination)) {
                $saved[] = $relative;
            }

            if (count($saved) >= 6) break;
        }
    } catch (Throwable $e) {
        error_log('PDF embedded image extraction error: ' . $e->getMessage());
    } finally {
        foreach (glob($workDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($workDir);
    }

    return array_values(array_unique($saved));
}

function packagePdfFindLocalImage(array $package): ?string
{
    $destination = mb_strtolower(trim((string)($package['destination'] ?? '')));
    $title = mb_strtolower(trim((string)($package['title'] ?? '')));
    $needles = [];

    foreach (preg_split('/[^\pL\pN]+/u', $destination . ' ' . $title) ?: [] as $word) {
        $word = trim($word);
        if (mb_strlen($word) >= 4 && !in_array($word, ['package','travel','tour','days','nights','holiday'], true)) {
            $needles[] = $word;
        }
    }

    if (!$needles) return null;

    foreach (packageMediaLibrary() as $media) {
        $name = mb_strtolower((string)($media['name'] ?? ''));
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($name, $needle) !== false) {
                return (string)$media['image'];
            }
        }
    }

    return null;
}

function packagePdfAutoImage(PDO $pdo, array $package): array
{
    $local = packagePdfFindLocalImage($package);
    if ($local !== null) {
        return ['images'=>[$local], 'source'=>'Web Gallery'];
    }

    $apiKey = pexelsApiKey();
    if ($apiKey === '' || $apiKey === 'YOUR_PEXELS_API_KEY_HERE') {
        return ['images'=>[], 'source'=>''];
    }

    $query = trim((string)($package['destination'] ?? ''));
    if ($query === '') $query = trim((string)($package['title'] ?? ''));
    if ($query === '') return ['images'=>[], 'source'=>''];

    try {
        $result = pexelsSearchPhotos($query . ' travel landscape', 1);
        $photos = is_array($result['photos'] ?? null) ? $result['photos'] : [];
        if (!$photos) return ['images'=>[], 'source'=>''];

        $saved = importPexelsPhoto($pdo, $photos[0]);
        $path = trim((string)($saved['image'] ?? ''));
        return $path !== ''
            ? ['images'=>[$path], 'source'=>'Pexels']
            : ['images'=>[], 'source'=>''];
    } catch (Throwable $e) {
        error_log('PDF package automatic Pexels image error: ' . $e->getMessage());
        return ['images'=>[], 'source'=>''];
    }
}

function packagePdfInsertGalleryImages(PDO $pdo, int $packageId, array $images): void
{
    if (!$images) return;
    $stmt = $pdo->prepare("SELECT image FROM package_images WHERE package_id = ?");
    $stmt->execute([$packageId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM package_images WHERE package_id = ?");
    $max->execute([$packageId]);
    $sort = (int)$max->fetchColumn();

    $insert = $pdo->prepare("INSERT INTO package_images (package_id,image,sort_order) VALUES (?,?,?)");
    foreach (array_values(array_unique($images)) as $image) {
        $image = trim((string)$image);
        if ($image === '' || in_array($image, $existing, true)) continue;
        $insert->execute([$packageId, $image, ++$sort]);
        $existing[] = $image;
    }
}

function packagePdfFindHotelMaster(PDO $pdo, int $destinationId, int $star, string $name): ?array
{
    if ($destinationId <= 0 || $name === '' || !in_array($star,[3,4,5],true)) return null;

    try {
        $stmt = $pdo->prepare("\n            SELECT h.*\n            FROM hotels h\n            WHERE h.destination_id = ?\n              AND h.star_category = ?\n              AND h.status = 'active'\n              AND (LOWER(h.hotel_name) = LOWER(?) OR LOWER(h.hotel_name) LIKE LOWER(?))\n            ORDER BY (LOWER(h.hotel_name) = LOWER(?)) DESC, h.is_preferred DESC, h.priority_order ASC, h.id ASC\n            LIMIT 1\n        ");
        $stmt->execute([$destinationId, $star, $name, '%' . $name . '%', $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function packagePdfSaveHotelAssignments(PDO $pdo, int $packageId, int $destinationId, int $nights, array $printedHotels): array
{
    $result = ['count'=>0,'mode'=>'','details'=>[]];

    try {
        $pdo->prepare("DELETE FROM package_hotels WHERE package_id = ?")->execute([$packageId]);
    } catch (Throwable $e) {
        return $result; // Step 06 not installed.
    }

    $grouped = [];
    foreach ($printedHotels as $hotel) {
        if (!is_array($hotel)) continue;
        $star = (int)($hotel['star_category'] ?? 0);
        $name = trim((string)($hotel['hotel_name'] ?? ''));
        if (!in_array($star,[3,4,5],true) || $name === '') continue;
        $grouped[$star][] = $hotel;
    }

    $autoPreferred = [];
    try {
        $autoPreferred = packageResolveHotelSelections($pdo, $destinationId, []);
    } catch (Throwable $e) {
        $autoPreferred = [];
    }

    $insert = $pdo->prepare("\n        INSERT INTO package_hotels\n        (package_id,hotel_id,destination_id,star_category,hotel_name_snapshot,supplier_id,room_type,meal_plan,nights,sort_order)\n        VALUES (?,?,?,?,?,?,?,?,?,?)\n    ");

    foreach ([3,4,5] as $sort => $star) {
        if (!empty($grouped[$star])) {
            $labels = [];
            foreach ($grouped[$star] as $hotel) {
                $label = trim((string)($hotel['hotel_name'] ?? ''));
                $city = trim((string)($hotel['city'] ?? ''));
                if ($city !== '') $label = $city . ': ' . $label;
                if ($label !== '') $labels[] = $label;
            }
            $labels = array_values(array_unique($labels));
            $first = $grouped[$star][0];
            $firstName = trim((string)($first['hotel_name'] ?? ''));
            $match = count($labels) === 1
                ? packagePdfFindHotelMaster($pdo, $destinationId, $star, $firstName)
                : null;

            $snapshot = mb_substr(implode('; ', $labels), 0, 190);
            $room = trim((string)($first['room_type'] ?? ''));
            $meal = trim((string)($first['meal_plan'] ?? ''));
            $hotelNights = max(0, (int)($first['nights'] ?? 0));

            $insert->execute([
                $packageId,
                $match ? (int)$match['id'] : null,
                $destinationId > 0 ? $destinationId : null,
                $star,
                $snapshot !== '' ? $snapshot : $firstName,
                $match && !empty($match['preferred_supplier_id']) ? (int)$match['preferred_supplier_id'] : null,
                $room !== '' ? $room : ($match ? (trim((string)($match['default_room_type'] ?? '')) ?: null) : null),
                $meal !== '' ? $meal : ($match ? (trim((string)($match['default_meal_plan'] ?? '')) ?: null) : null),
                $hotelNights > 0 ? $hotelNights : max(0,$nights),
                $sort + 1
            ]);
            $result['count']++;
            $result['mode'] = 'PDF hotel';
            $result['details'][] = $star . '★ ' . $snapshot;
            continue;
        }

        if (!empty($autoPreferred[$star])) {
            $hotel = $autoPreferred[$star];
            $insert->execute([
                $packageId,
                (int)$hotel['id'],
                $destinationId > 0 ? $destinationId : null,
                $star,
                (string)$hotel['hotel_name'],
                !empty($hotel['preferred_supplier_id']) ? (int)$hotel['preferred_supplier_id'] : null,
                trim((string)($hotel['default_room_type'] ?? '')) ?: null,
                trim((string)($hotel['default_meal_plan'] ?? '')) ?: null,
                max(0,$nights),
                $sort + 1
            ]);
            $result['count']++;
            if ($result['mode'] === '') $result['mode'] = 'Preferred Hotel Master';
            $result['details'][] = $star . '★ ' . (string)$hotel['hotel_name'] . ' (auto)';
        }
    }

    return $result;
}

function packagePdfSaveOccupancyPricing(PDO $pdo, int $packageId, array $package): int
{
    try {
        $pdo->query("SELECT 1 FROM package_occupancy_prices LIMIT 1");
    } catch (Throwable $e) {
        return 0; // Step 08 not installed.
    }

    $rows = is_array($package['occupancy_prices'] ?? null) ? $package['occupancy_prices'] : [];

    /* If the PDF has only legacy 3/4/5-star prices, safely seed Twin/Double only. */
    if (!$rows) {
        foreach (($package['price_periods'] ?? []) as $period) {
            foreach ([3=>'price_3_star',4=>'price_4_star',5=>'price_5_star'] as $star=>$field) {
                $price = packagePdfMoney($period[$field] ?? null);
                if ($price === null || $price <= 0) continue;
                $rows[] = [
                    'valid_from' => (string)($period['valid_from'] ?? ''),
                    'valid_to' => (string)($period['valid_to'] ?? ''),
                    'star_category' => $star,
                    'twin_price' => $price,
                    'triple_price' => null,
                    'single_price' => null,
                    'child_with_bed_price' => null,
                    'child_without_bed_price' => null,
                    'infant_price' => null,
                    'infant_age_to' => 2,
                ];
            }
        }
    }

    if (!$rows) return 0;

    $insert = $pdo->prepare("\n        INSERT INTO package_occupancy_prices\n        (package_id,valid_from,valid_to,star_category,twin_price,triple_price,single_price,child_with_bed_price,child_without_bed_price,infant_price,infant_age_to,sort_order)\n        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)\n        ON DUPLICATE KEY UPDATE\n          twin_price=VALUES(twin_price),triple_price=VALUES(triple_price),single_price=VALUES(single_price),\n          child_with_bed_price=VALUES(child_with_bed_price),child_without_bed_price=VALUES(child_without_bed_price),\n          infant_price=VALUES(infant_price),infant_age_to=VALUES(infant_age_to),sort_order=VALUES(sort_order)\n    ");

    $count = 0;
    foreach ($rows as $row) {
        $star = (int)($row['star_category'] ?? 0);
        if (!in_array($star,[3,4,5],true)) continue;
        $from = packagePdfNormalizeDate($row['valid_from'] ?? '');
        $to = packagePdfNormalizeDate($row['valid_to'] ?? '');
        if ($from === '') $from = date('Y-m-d');
        if ($to === '') $to = $from;
        if (strtotime($from) > strtotime($to)) [$from,$to] = [$to,$from];

        $insert->execute([
            $packageId,$from,$to,$star,
            packagePdfMoney($row['twin_price'] ?? null),
            packagePdfMoney($row['triple_price'] ?? null),
            packagePdfMoney($row['single_price'] ?? null),
            packagePdfMoney($row['child_with_bed_price'] ?? null),
            packagePdfMoney($row['child_without_bed_price'] ?? null),
            packagePdfMoney($row['infant_price'] ?? null),
            max(0,min(5,(int)($row['infant_age_to'] ?? 2))),
            ++$count
        ]);
    }

    return $count;
}

function packagePdfSaveLegacyChildPricing(PDO $pdo, int $packageId, array $package): int
{
    $price = packagePdfMoney($package['child_price'] ?? null);
    $from = max(0,(int)($package['child_age_min'] ?? 0));
    $to = max(0,(int)($package['child_age_max'] ?? 0));
    if ($price === null || $to < $from || ($from === 0 && $to === 0)) return 0;

    try {
        $pdo->prepare("DELETE FROM package_child_prices WHERE package_id = ?")->execute([$packageId]);
        $stmt = $pdo->prepare("INSERT INTO package_child_prices (package_id,age_from,age_to,price,sort_order) VALUES (?,?,?,?,1)");
        $stmt->execute([$packageId,$from,$to,$price]);
        return 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function importPackagesFromPdf(
    PDO $pdo,
    string $tmpFile,
    array $destinations,
    array $activeTypes,
    string $originalName = ''
): array {
    $extracted = extractPackagePdfText($tmpFile);
    $text = (string)$extracted['text'];

    /* STEP 09A: extract usable brochure images once. */
    $pdfImages = packagePdfExtractPackageImages($tmpFile, $originalName);

    $aiParsed = packagePdfLocalAiParse($text);
    $aiUsed =
        is_array($aiParsed)
        && !empty($aiParsed['packages']);

    /*
    | Always run the deterministic detail parser too.
    | If AI returns only some fields, fallback values fill the missing ones.
    */
    $fallbackParsed =
        packagePdfFallbackParse(
            $text,
            $originalName
        );

    $parsed = $aiUsed
        ? $aiParsed
        : $fallbackParsed;

    $aiPackages =
        is_array($aiParsed['packages'] ?? null)
            ? array_values($aiParsed['packages'])
            : [];

    $fallbackPackages =
        is_array($fallbackParsed['packages'] ?? null)
            ? array_values($fallbackParsed['packages'])
            : [];

    $sourcePackages =
        $aiUsed
            ? $aiPackages
            : $fallbackPackages;

    $packages = [];

    foreach ($sourcePackages as $index => $package) {
        if (!is_array($package)) {
            continue;
        }

        if (
            $aiUsed
            && isset($fallbackPackages[$index])
            && is_array($fallbackPackages[$index])
        ) {
            $package = packagePdfMergePackage(
                $package,
                $fallbackPackages[$index]
            );
        }

        $normalized =
            packagePdfNormalizePackage($package);

        if ($normalized['title'] !== '') {
            $packages[] = $normalized;
        }
    }

    if (!$packages) {
        /*
        | Absolute last-resort recovery. If OCR produced readable text,
        | create a reviewable Draft instead of throwing the document away.
        */
        $filenamePackage =
            packagePdfFilenamePackage(
                $originalName,
                $text
            );

        $overview =
            packagePdfParseOverview($text);

        $itineraryDays =
            packagePdfParseItineraryDays($text);

        $incExc =
            packagePdfParseInclusionsExclusions($text);

        $terms =
            packagePdfParseTerms($text);

        $childPolicy =
            packagePdfParseChildPolicy($terms);

        $pricePeriods =
            packagePdfParseQuotationPrices(
                $text,
                $overview
            );

        $forced = packagePdfNormalizePackage([
            'title' =>
                trim((string)($filenamePackage['title'] ?? ''))
                !== ''
                    ? (string)$filenamePackage['title']
                    : 'Imported Travel Package ' . date('YmdHis'),

            'package_code' =>
                trim((string)($overview['trip_id'] ?? '')),

            'destination' =>
                trim((string)($overview['destination'] ?? ''))
                !== ''
                    ? (string)$overview['destination']
                    : (string)($filenamePackage['destination'] ?? ''),

            'duration_days' =>
                (int)($overview['days'] ?? 0) > 0
                    ? (int)$overview['days']
                    : (int)($filenamePackage['duration_days'] ?? 0),

            'duration_nights' =>
                (int)($overview['nights'] ?? 0) > 0
                    ? (int)$overview['nights']
                    : (int)($filenamePackage['duration_nights'] ?? 0),

            'child_age_min' =>
                (int)($childPolicy['child_age_min'] ?? 0),

            'child_age_max' =>
                (int)($childPolicy['child_age_max'] ?? 0),

            'child_price' => null,
            'currency' =>
                preg_match('/\bUSD\b/iu', $text)
                    ? 'USD'
                    : 'INR',

            'package_type' => 'holiday',
            'inclusions' =>
                (string)($incExc['inclusions'] ?? ''),
            'exclusions' =>
                (string)($incExc['exclusions'] ?? ''),
            'terms' => $terms,
            'price_periods' => $pricePeriods,
            'itinerary_days' => $itineraryDays,
            'hotels' => packagePdfParseStructuredHotels($text),
            'occupancy_prices' => [],
            'warnings' => [
                'Package was recovered using the guaranteed OCR fallback. Review the Draft before publishing.'
            ]
        ]);

        if ($forced['title'] !== '') {
            $packages[] = $forced;
        }
    }

    if (!$packages) {
        throw new RuntimeException(
            'PDF text was extracted but no safe draft package could be created.'
        );
    }

    $defaultType = in_array('holiday', $activeTypes, true)
        ? 'holiday'
        : (string)($activeTypes[0] ?? 'holiday');

    $inserted = 0;
    $skipped = 0;
    $warnings = [];
    $reports = [];

    foreach (
        array_merge(
            is_array($parsed['warnings'] ?? null)
                ? $parsed['warnings']
                : [],
            is_array($fallbackParsed['warnings'] ?? null)
                ? $fallbackParsed['warnings']
                : []
        )
        as $warning
    ) {
        $warning = trim((string)$warning);

        if ($warning !== '') {
            $warnings[] = $warning;
        }
    }

    $insertPackage = $pdo->prepare("
        INSERT INTO packages
        (
            destination_id,
            title,
            slug,
            package_code,
            short_description,
            description,
            duration_days,
            duration_nights,
            child_age_min,
            child_age_max,
            child_price,
            regular_price,
            sale_price,
            currency,
            package_type,
            main_image,
            inclusions,
            exclusions,
            itinerary,
            terms,
            tour_highlights,
            featured,
            bestseller,
            status
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");

    $insertPrice = $pdo->prepare("
        INSERT INTO package_date_prices
        (
            package_id,
            valid_from,
            valid_to,
            price_3_star,
            price_4_star,
            price_5_star,
            sort_order
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $insertDay = $pdo->prepare("
        INSERT INTO package_itinerary_days
        (
            package_id,
            day_number,
            title,
            description,
            image,
            sort_order
        )
        VALUES (?, ?, ?, ?, NULL, ?)
    ");

    foreach ($packages as $package) {
        $title = trim((string)$package['title']);

        if ($title === '') {
            $skipped++;
            continue;
        }

        /* Coverage is measured BEFORE safe website enrichment. */
        $coverage = packagePdfDataCoverage($package, !empty($pdfImages));
        $prepared = packagePdfSafePreparePackage($package);
        $package = $prepared['package'];
        $autoPrepared = $prepared['auto'];
        $title = trim((string)$package['title']);

        $duplicate = $pdo->prepare("
            SELECT id
            FROM packages
            WHERE LOWER(title) = LOWER(?)
            LIMIT 1
        ");

        $duplicate->execute([$title]);

        if ($duplicate->fetchColumn()) {
            $skipped++;
            $warnings[] = 'Skipped duplicate package: ' . $title;
            continue;
        }

        $destinationId = packagePdfResolveDestinationId(
            $destinations,
            (string)$package['destination']
        );

        if ($destinationId <= 0 && $package['destination'] !== '') {
            $warnings[] =
                'Destination needs review for '
                . $title
                . ': PDF says "'
                . $package['destination']
                . '".';
        }

        /* Use the PDF code only when unique; otherwise generate a safe TRAVSCOPE code. */
        $importCode = trim((string)($package['package_code'] ?? ''));
        if ($importCode !== '') {
            $codeCheck = $pdo->prepare("SELECT id FROM packages WHERE package_code = ? LIMIT 1");
            $codeCheck->execute([$importCode]);
            if ($codeCheck->fetchColumn()) {
                $warnings[] = $title . ': PDF package code already existed, so a new TRAVSCOPE code was generated.';
                $importCode = '';
            }
        }
        if ($importCode === '') {
            $importCode = nextAutomaticPackageCode($pdo);
            $autoPrepared[] = 'Package code';
        }
        $package['package_code'] = $importCode;

        $type = strtolower(trim((string)$package['package_type']));

        if (!in_array($type, $activeTypes, true)) {
            $type = $defaultType;
        }

        $fallbackPrice = packagePdfPriceFallback($package['price_periods']);

        $salePrice = $package['sale_price'] !== null
            ? (float)$package['sale_price']
            : $fallbackPrice;

        $regularPrice = $package['regular_price'] !== null
            ? (float)$package['regular_price']
            : ($salePrice > 0 ? $salePrice : null);

        $itineraryText = packagePdfCombinedItineraryText(
            $package['itinerary_days']
        );

        $terms = trim((string)$package['terms']);

        if ($package['source_text_notes'] !== '') {
            $terms .= ($terms !== '' ? "\n\n" : '')
                . "PDF Import Notes:\n"
                . $package['source_text_notes'];
        }

        $slug = uniquePackageSlug(
            $pdo,
            makePackageSlug($title)
        );

        /* Image priority: embedded PDF -> matching Web Gallery -> Pexels. */
        $packageImages = $pdfImages;
        $imageSource = $packageImages ? 'PDF' : '';
        if (!$packageImages) {
            $autoImage = packagePdfAutoImage($pdo, $package);
            $packageImages = $autoImage['images'];
            $imageSource = (string)$autoImage['source'];
        }
        $mainImage = $packageImages ? (string)$packageImages[0] : null;
        if ($mainImage) {
            $autoPrepared[] = 'Main image (' . ($imageSource !== '' ? $imageSource : 'auto') . ')';
        }

        $pdo->beginTransaction();

        try {
            $insertPackage->execute([
                $destinationId > 0 ? $destinationId : null,
                $title,
                $slug,
                $package['package_code'] !== '' ? $package['package_code'] : null,
                $package['short_description'] !== '' ? $package['short_description'] : null,
                $package['description'] !== '' ? $package['description'] : null,
                max(1, (int)$package['duration_days']),
                max(0, (int)$package['duration_nights']),
                max(0, (int)$package['child_age_min']),
                max(0, (int)$package['child_age_max']),
                $package['child_price'],
                $regularPrice,
                max(0, (float)$salePrice),
                $package['currency'] !== '' ? $package['currency'] : 'INR',
                $type,
                $mainImage,
                $package['inclusions'] !== '' ? $package['inclusions'] : null,
                $package['exclusions'] !== '' ? $package['exclusions'] : null,
                $itineraryText !== '' ? $itineraryText : null,
                $terms !== '' ? $terms : null,
                $package['tour_highlights'] !== '' ? $package['tour_highlights'] : null,
                0,
                0,
                'draft'
            ]);

            $packageId = (int)$pdo->lastInsertId();

            $sortOrder = 0;

            foreach ($package['price_periods'] as $period) {
                $from = packagePdfNormalizeDate($period['valid_from'] ?? '');
                $to = packagePdfNormalizeDate($period['valid_to'] ?? '');

                $p3 = packagePdfMoney($period['price_3_star'] ?? null);
                $p4 = packagePdfMoney($period['price_4_star'] ?? null);
                $p5 = packagePdfMoney($period['price_5_star'] ?? null);

                if ($p3 === null && $p4 === null && $p5 === null) {
                    continue;
                }

                if ($from === '') {
                    $from = date('Y-m-d');
                }

                if ($to === '') {
                    $to = date('Y-m-d', strtotime('+1 year'));
                }

                if (strtotime($from) > strtotime($to)) {
                    [$from, $to] = [$to, $from];
                }

                $sortOrder++;

                $insertPrice->execute([
                    $packageId,
                    $from,
                    $to,
                    $p3,
                    $p4,
                    $p5,
                    $sortOrder
                ]);
            }

            foreach ($package['itinerary_days'] as $index => $day) {
                $number = max(1, (int)($day['day_number'] ?? ($index + 1)));
                $titleValue = trim((string)($day['title'] ?? ''));
                $descriptionValue = trim((string)($day['description'] ?? ''));

                if ($titleValue === '' && $descriptionValue === '') {
                    continue;
                }

                $insertDay->execute([
                    $packageId,
                    $number,
                    $titleValue !== '' ? $titleValue : null,
                    $descriptionValue !== '' ? $descriptionValue : null,
                    $number
                ]);
            }

            /* Complete the rest of the current website package structure. */
            if ($packageImages) {
                packagePdfInsertGalleryImages($pdo, $packageId, array_slice($packageImages, 1));
            }

            $legacyChildCount = packagePdfSaveLegacyChildPricing($pdo, $packageId, $package);
            if ($legacyChildCount > 0) $autoPrepared[] = 'Child pricing';

            $occupancyCount = packagePdfSaveOccupancyPricing($pdo, $packageId, $package);
            if ($occupancyCount > 0) $autoPrepared[] = 'Smart occupancy pricing';

            $hotelResult = packagePdfSaveHotelAssignments(
                $pdo,
                $packageId,
                $destinationId,
                max(0, (int)$package['duration_nights']),
                is_array($package['hotels'] ?? null) ? $package['hotels'] : []
            );
            if (($hotelResult['count'] ?? 0) > 0) {
                $autoPrepared[] = (($hotelResult['mode'] ?? '') !== '' ? (string)$hotelResult['mode'] : 'Hotels');
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $readiness = packagePublishReadiness($pdo, $packageId);
        $reportWarnings = [];
        foreach ($package['warnings'] as $warning) {
            $warnings[] = $title . ': ' . $warning;
            $reportWarnings[] = (string)$warning;
        }

        if ($destinationId <= 0) {
            $reportWarnings[] = 'Destination could not be matched to Destination Master.';
        }
        if (!$mainImage) {
            $reportWarnings[] = 'No usable PDF/Web Gallery/Pexels cover image could be prepared automatically.';
        }

        $reports[] = [
            'package_id' => $packageId,
            'title' => $title,
            'package_code' => $package['package_code'],
            'pdf_coverage' => (int)$coverage['score'],
            'pdf_missing' => $coverage['missing'],
            'readiness' => (int)($readiness['score'] ?? 0),
            'ready' => !empty($readiness['ready']),
            'required_missing' => is_array($readiness['required_missing'] ?? null) ? $readiness['required_missing'] : [],
            'auto_prepared' => array_values(array_unique($autoPrepared)),
            'image_source' => $imageSource,
            'hotels' => is_array($hotelResult['details'] ?? null) ? $hotelResult['details'] : [],
            'warnings' => array_values(array_unique($reportWarnings)),
        ];

        $inserted++;
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'warnings' => array_values(array_unique($warnings)),
        'reports' => $reports,
        'pdf_images' => $pdfImages,
        'ai_used' => $aiUsed,
        'extraction_method' => (string)$extracted['method'],
        'extracted_chars' => mb_strlen($text)
    ];
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$success = '';
$error = '';

if (!empty($_SESSION['admin_package_success'])) {

    $success =
        (string)$_SESSION['admin_package_success'];

    unset(
        $_SESSION['admin_package_success']
    );
}

if (!empty($_SESSION['admin_package_error'])) {

    $error =
        (string)$_SESSION['admin_package_error'];

    unset(
        $_SESSION['admin_package_error']
    );
}

$packageImportReport = [];
if (!empty($_SESSION['admin_package_import_report']) && is_array($_SESSION['admin_package_import_report'])) {
    $packageImportReport = $_SESSION['admin_package_import_report'];
    unset($_SESSION['admin_package_import_report']);
}


/*
|--------------------------------------------------------------------------
| DESTINATIONS
|--------------------------------------------------------------------------
*/

$destinations = [];

try {

    $stmt = $pdo->query("
        SELECT
            id,
            name,
            country,
            status
        FROM destinations
        ORDER BY
            name ASC
    ");

    $destinations =
        $stmt->fetchAll();

} catch (Throwable $e) {

    error_log(
        'Destination load error: '
        . $e->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| PACKAGE TYPES
|--------------------------------------------------------------------------
*/

$packageTypes = [];
$activePackageTypeSlugs = [];

try {
    $stmt = $pdo->query("
        SELECT *
        FROM package_types
        ORDER BY sort_order ASC, name ASC, id ASC
    ");
    $packageTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packageTypes as $packageTypeRow) {
        $typeSlug = strtolower(trim((string)(
            $packageTypeRow['slug']
            ?? $packageTypeRow['code']
            ?? $packageTypeRow['type']
            ?? ''
        )));
        $typeStatus = strtolower(trim((string)(
            $packageTypeRow['status'] ?? 'active'
        )));

        if ($typeSlug !== '' && $typeStatus === 'active') {
            $activePackageTypeSlugs[] = $typeSlug;
        }
    }
} catch (Throwable $e) {
    error_log('Package type load error: ' . $e->getMessage());
    $error = 'Unable to load package types. Please check the package_types table.';
}



/*
|--------------------------------------------------------------------------
| PEXELS AJAX SEARCH / IMPORT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['pexels_ajax'] ?? '') === '1'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === ''
        || !hash_equals(
            (string)($_SESSION['admin_package_csrf'] ?? ''),
            $postedToken
        )
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Security verification failed. Refresh the page and try again.'
        ]);

        exit;
    }

    try {
        $pexelsAction = (string)($_POST['action'] ?? '');

        if ($pexelsAction === 'pexels_search') {
            $query = trim((string)($_POST['query'] ?? ''));
            $page = max(1, (int)($_POST['page'] ?? 1));

            $result = combinedPackagePhotoSearch($query, $page);

            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_SLASHES);

            exit;
        }

        if ($pexelsAction === 'pexels_import') {
            $photo = [
                'provider' => (string)($_POST['provider'] ?? 'pexels'),
                'id' => (string)($_POST['photo_id'] ?? ''),
                'download' => (string)($_POST['image_url'] ?? ''),
                'photo_url' => (string)($_POST['photo_url'] ?? ''),
                'photographer' => (string)($_POST['photographer'] ?? ''),
                'photographer_url' => (string)($_POST['photographer_url'] ?? ''),
                'download_location' => (string)($_POST['download_location'] ?? ''),
                'alt' => (string)($_POST['alt'] ?? ''),
            ];

            $provider = strtolower(trim($photo['provider']));
            $saved = $provider === 'unsplash' ? importUnsplashPhoto($pdo, $photo) : importPexelsPhoto($pdo, $photo);

            echo json_encode([
                'success' => true,
                'message' => ($provider === 'unsplash' ? 'Unsplash photo selected successfully.' : 'Royalty-free Pexels image imported successfully.'),
                'data' => $saved
            ], JSON_UNESCAPED_SLASHES);

            exit;
        }

        throw new RuntimeException('Invalid Pexels action.');

    } catch (Throwable $e) {
        error_log(
            'TRAVSCOPE Pexels AJAX error: '
            . $e->getMessage()
        );

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $postedToken === ''
        ||
        !hash_equals(
            $_SESSION['admin_package_csrf'] ?? '',
            $postedToken
        )
    ) {

        $error =
            'Security verification failed. Refresh the page and try again.';

    } else {

        $action =
            (string)(
                $_POST['action']
                ?? ''
            );


        /* BULK IMPORT PACKAGES FROM PDF */
        if ($action === 'import_packages_pdf') {
            try {
                if (!isset($_FILES['packages_pdf']) || ($_FILES['packages_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Please choose a packages PDF first.');
                }
                $pdf = $_FILES['packages_pdf'];
                if (($pdf['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('PDF upload failed.');
                if ((int)($pdf['size'] ?? 0) > 20 * 1024 * 1024) throw new RuntimeException('PDF must be 20 MB or smaller.');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file((string)$pdf['tmp_name']);
                if ($mime !== 'application/pdf' && strtolower(pathinfo((string)($pdf['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf') throw new RuntimeException('Only PDF files are allowed.');
                $result = importPackagesFromPdf(
                    $pdo,
                    (string)$pdf['tmp_name'],
                    $destinations,
                    $activePackageTypeSlugs,
                    (string)($pdf['name'] ?? '')
                );

                $reportRows = is_array($result['reports'] ?? null) ? $result['reports'] : [];
                $_SESSION['admin_package_import_report'] = $reportRows;

                $avgCoverage = 0;
                $avgReadiness = 0;
                if ($reportRows) {
                    $avgCoverage = (int)round(array_sum(array_column($reportRows, 'pdf_coverage')) / count($reportRows));
                    $avgReadiness = (int)round(array_sum(array_column($reportRows, 'readiness')) / count($reportRows));
                }

                $_SESSION['admin_package_success'] =
                    $result['inserted']
                    . ' package(s) intelligently prepared as Draft. '
                    . $result['skipped']
                    . ' duplicate/invalid package(s) skipped. '
                    . ($reportRows ? ('Average PDF coverage ' . $avgCoverage . '%, website readiness ' . $avgReadiness . '%. ') : '')
                    . 'Extraction: '
                    . strtoupper((string)($result['extraction_method'] ?? 'PDF'))
                    . '. '
                    . (!empty($result['ai_used'])
                        ? 'Local AI + deterministic parser used.'
                        : 'Deterministic fallback parser used.');

                if (!empty($result['warnings'])) {
                    $_SESSION['admin_package_error'] =
                        'Review imported packages: '
                        . implode(
                            ' | ',
                            array_slice($result['warnings'], 0, 7)
                        );
                }
                redirect(BASE_URL . 'admin-packages.php');
            } catch (Throwable $e) {
                $error = 'PDF import failed: ' . $e->getMessage();
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SAVE PACKAGE
        |--------------------------------------------------------------------------
        */

        if (
            $action === 'create'
            ||
            $action === 'update'
        ) {

            $packageId =
                (int)(
                    $_POST['package_id']
                    ?? 0
                );

            if ($action === 'create') {
                $packageId = 0;
            }


            $destinationId =
                (int)(
                    $_POST['destination_id']
                    ?? 0
                );

            $title =
                trim(
                    (string)(
                        $_POST['title']
                        ?? ''
                    )
                );

            $packageCode =
                trim(
                    (string)(
                        $_POST['package_code']
                        ?? ''
                    )
                );

            if ($action === 'create' && $packageCode === '') {
                $packageCode = nextAutomaticPackageCode($pdo);
            }

            $shortDescription =
                trim(
                    (string)(
                        $_POST['short_description']
                        ?? ''
                    )
                );

            $description =
                trim(
                    (string)(
                        $_POST['description']
                        ?? ''
                    )
                );

            $durationDays =
                max(
                    0,
                    (int)(
                        $_POST['duration_days']
                        ?? 0
                    )
                );

            $durationNights =
                max(
                    0,
                    (int)(
                        $_POST['duration_nights']
                        ?? 0
                    )
                );

            $childAgeMin =
                max(
                    0,
                    (int)(
                        $_POST['child_age_min']
                        ?? 0
                    )
                );

            $childAgeMax =
                max(
                    0,
                    (int)(
                        $_POST['child_age_max']
                        ?? 0
                    )
                );

            $childPriceRaw =
                trim(
                    (string)(
                        $_POST['child_price']
                        ?? ''
                    )
                );

            $childPrice =
                $childPriceRaw === ''
                    ? null
                    : max(
                        0,
                        (float)$childPriceRaw
                    );

            $packageChildPriceRows =
                normalizePackageChildPriceRows(
                    $_POST
                );

            /*
            | Keep the original single child fields populated from the first
            | child row so all existing package cards/pages remain compatible.
            */
            if ($packageChildPriceRows) {
                $childAgeMin = (int)$packageChildPriceRows[0]['age_from'];
                $childAgeMax = (int)$packageChildPriceRows[0]['age_to'];
                $childPrice = $packageChildPriceRows[0]['price'];
            }

            $packagePriceRows =
                normalizePackagePriceRows(
                    $_POST
                );

            /* Automatic hotel assignment from Hotel Master. */
            $packageHotelSelections =
                packageResolveHotelSelections(
                    $pdo,
                    $destinationId,
                    $_POST
                );

            /*
            | Keep packages.regular_price and packages.sale_price populated
            | for compatibility with existing package cards and older pages.
            | The lowest configured 3/4/5-star amount becomes the fallback.
            */
            $fallbackPackagePrice =
                packagePriceFallback(
                    $packagePriceRows
                );

            $regularPrice =
                $fallbackPackagePrice > 0
                    ? $fallbackPackagePrice
                    : null;

            $salePrice =
                $fallbackPackagePrice;

            $currency =
                strtoupper(
                    trim(
                        (string)(
                            $_POST['currency']
                            ?? 'INR'
                        )
                    )
                );

            $packageType =
                strtolower(
                    trim(
                        (string)(
                            $_POST['package_type']
                            ?? 'holiday'
                        )
                    )
                );

            $inclusions =
                trim(
                    (string)(
                        $_POST['inclusions']
                        ?? ''
                    )
                );

            $exclusions =
                trim(
                    (string)(
                        $_POST['exclusions']
                        ?? ''
                    )
                );

            $itinerary =
                trim(
                    (string)(
                        $_POST['itinerary']
                        ?? ''
                    )
                );

            $terms =
                trim(
                    (string)(
                        $_POST['terms']
                        ?? ''
                    )
                );
            $tourHighlights =
    trim(
        (string)(
            $_POST['tour_highlights']
            ?? ''
        )
    );
            $featured =
                !empty($_POST['featured'])
                    ? 1
                    : 0;

            $bestseller =
                !empty($_POST['bestseller'])
                    ? 1
                    : 0;

            $status =
                strtolower(
                    trim(
                        (string)(
                            $_POST['status']
                            ?? 'draft'
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validPackageType = in_array(
                $packageType,
                $activePackageTypeSlugs,
                true
            );

            if (!$validPackageType && $action === 'update' && $packageId > 0) {
                $stmt = $pdo->prepare("
                    SELECT package_type
                    FROM packages
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$packageId]);
                $existingPackageType = strtolower(trim((string)$stmt->fetchColumn()));

                $validPackageType =
                    $existingPackageType !== ''
                    && $packageType === $existingPackageType;
            }

            if (!$validPackageType) {
                $error = 'Please select a valid active package type.';
            }

            if (
                !in_array(
                    $status,
                    [
                        'active',
                        'inactive',
                        'draft'
                    ],
                    true
                )
            ) {
                $status = 'draft';
            }


            if ($error !== '') {

                // Preserve validation error already detected above.

            } elseif ($title === '') {

                $error =
                    'Package title is required.';

            } elseif (
                mb_strlen($title) > 220
            ) {

                $error =
                    'Package title is too long.';

            } elseif (
                $shortDescription !== ''
                &&
                mb_strlen($shortDescription) > 500
            ) {

                $error =
                    'Short description cannot exceed 500 characters.';

            } elseif (
                $packageCode !== ''
                &&
                mb_strlen($packageCode) > 50
            ) {

                $error =
                    'Package code cannot exceed 50 characters.';

            } elseif (
                strlen($currency) < 2
                ||
                strlen($currency) > 10
            ) {

                $error =
                    'Please enter a valid currency code.';

            } elseif (
                $childAgeMin > 0
                && $childAgeMax > 0
                && $childAgeMin > $childAgeMax
            ) {

                $error =
                    'Children minimum age cannot be greater than maximum age.';

            } else {

                try {

                    validatePackagePriceRows(
                        $packagePriceRows
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | VERIFY DESTINATION
                    |--------------------------------------------------------------------------
                    */

                    if ($destinationId > 0) {

                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM destinations
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $destinationId
                        ]);

                        if (!$stmt->fetch()) {
                            throw new RuntimeException(
                                'Selected destination does not exist.'
                            );
                        }

                    } else {

                        $destinationId = null;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | PACKAGE CODE UNIQUE CHECK
                    |--------------------------------------------------------------------------
                    */

                    if ($packageCode !== '') {

                        if ($packageId > 0) {

                            $stmt = $pdo->prepare("
                                SELECT id
                                FROM packages
                                WHERE package_code = ?
                                AND id != ?
                                LIMIT 1
                            ");

                            $stmt->execute([
                                $packageCode,
                                $packageId
                            ]);

                        } else {

                            $stmt = $pdo->prepare("
                                SELECT id
                                FROM packages
                                WHERE package_code = ?
                                LIMIT 1
                            ");

                            $stmt->execute([
                                $packageCode
                            ]);
                        }

                        if ($stmt->fetch()) {
                            throw new RuntimeException(
                                'This package code is already being used.'
                            );
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | CREATE
                    |--------------------------------------------------------------------------
                    */

                    if ($action === 'create') {

                        $slug =
                            uniquePackageSlug(
                                $pdo,
                                makePackageSlug($title)
                            );

                        $mainImage = null;
                        $libraryMain = validPackageMediaPath((string)($_POST['library_main_image'] ?? ''));
                        if ($libraryMain !== null) { $mainImage = $libraryMain; }


                        if (
                            isset(
                                $_FILES['main_image']
                            )
                        ) {

                            $uploadedMain =
                                uploadPackageImage(
                                    $_FILES['main_image']
                                );
                            if ($uploadedMain !== null) {
                                $mainImage = $uploadedMain;
                            }
                        }


                        try {

                            $stmt = $pdo->prepare("
                                INSERT INTO packages
                                (
                                    destination_id,
                                    title,
                                    slug,
                                    package_code,
                                    short_description,
                                    description,
                                    duration_days,
                                    duration_nights,
                                    child_age_min,
                                    child_age_max,
                                    child_price,
                                    regular_price,
                                    sale_price,
                                    currency,
                                    package_type,
                                    main_image,
                                    inclusions,
                                    exclusions,
                                    itinerary,
                                    terms,
                                    tour_highlights,
                                    featured,
                                    bestseller,
                                    status
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?
                                )
                            ");

                            $stmt->execute([
                                $destinationId,
                                $title,
                                $slug,
                                $packageCode !== ''
                                    ? $packageCode
                                    : null,
                                $shortDescription !== ''
                                    ? $shortDescription
                                    : null,
                                $description !== ''
                                    ? $description
                                    : null,
                                $durationDays,
                                $durationNights,
                                $childAgeMin,
                                $childAgeMax,
                                $childPrice,
                                $regularPrice,
                                $salePrice,
                                $currency,
                                $packageType,
                                $mainImage,
                                $inclusions !== ''
                                    ? $inclusions
                                    : null,
                                $exclusions !== ''
                                    ? $exclusions
                                    : null,
                                $itinerary !== ''
                                    ? $itinerary
                                    : null,
                                $terms !== ''
                                    ? $terms
                                    : null,

                                $tourHighlights !== ''
                                    ? $tourHighlights
                                    : null,

                                $featured,
                                $bestseller,
                                $status
                            ]);

                            /*
                            | Save day-wise itinerary only after the parent
                            | package exists, so every day gets the correct
                            | package_id.
                            */
                            $packageId =
                                (int)$pdo->lastInsertId();

                            if ($packageId <= 0) {
                                throw new RuntimeException(
                                    'Package was created but its ID could not be determined.'
                                );
                            }

                            saveItineraryDays(
                                $pdo,
                                $packageId
                            );

                            savePackageGalleryUploads(
                                $pdo,
                                $packageId
                            );

                            saveSelectedPackageGalleryMedia($pdo, $packageId);

                            savePackagePriceRows(
                                $pdo,
                                $packageId,
                                $packagePriceRows
                            );

                            savePackageChildPriceRows(
                                $pdo,
                                $packageId,
                                $packageChildPriceRows
                            );

                            savePackageHotelAssignments(
                                $pdo,
                                $packageId,
                                $destinationId,
                                $durationNights,
                                $packageHotelSelections
                            );

                        } catch (Throwable $e) {

                            if ($mainImage) {
                                deletePackageImageFile(
                                    $mainImage
                                );
                            }

                            throw $e;
                        }


                        $readiness = packagePublishReadiness($pdo, $packageId);
                        $publishDowngraded = false;

                        if ($status === 'active' && !$readiness['ready']) {
                            $pdo->prepare("UPDATE packages SET status = 'draft' WHERE id = ?")
                                ->execute([$packageId]);
                            $publishDowngraded = true;
                            $_SESSION['admin_package_error'] =
                                'Saved as Draft. Complete before publishing: ' .
                                implode(', ', $readiness['required_missing']) . '.';
                        }

                        $_SESSION['admin_package_success'] =
                            $publishDowngraded
                                ? 'Package created successfully with automatic code ' . $packageCode . '. It remains Draft until publish-ready.'
                                : 'Package created successfully. Code: ' . $packageCode . '.';

                        redirect(
                            BASE_URL
                            . 'admin-packages.php'
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE
                    |--------------------------------------------------------------------------
                    */

                    if ($action === 'update') {

                        if ($packageId <= 0) {
                            throw new RuntimeException(
                                'Invalid package.'
                            );
                        }

                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                main_image
                            FROM packages
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $packageId
                        ]);

                        $existingPackage =
                            $stmt->fetch();

                        if (!$existingPackage) {
                            throw new RuntimeException(
                                'Package not found.'
                            );
                        }


                        $slug =
                            uniquePackageSlug(
                                $pdo,
                                makePackageSlug($title),
                                $packageId
                            );

                        $mainImage =
                            $existingPackage['main_image'];
                        $libraryMain = validPackageMediaPath((string)($_POST['library_main_image'] ?? ''));
                        if ($libraryMain !== null) {
                            $mainImage = $libraryMain;
                        }

                        $newImage = null;


                        /*
                        | Remove existing image if requested.
                        */

                        if (
                            !empty(
                                $_POST['remove_main_image']
                            )
                        ) {

                            deletePackageImageFile(
                                $mainImage
                            );

                            $mainImage = null;
                        }


                        /*
                        | Upload replacement.
                        */

                        if (
                            isset(
                                $_FILES['main_image']
                            )
                            &&
                            $_FILES['main_image']['error']
                                !== UPLOAD_ERR_NO_FILE
                        ) {

                            $newImage =
                                uploadPackageImage(
                                    $_FILES['main_image'],
                                    $packageId
                                );

                            if ($mainImage) {
                                deletePackageImageFile(
                                    $mainImage
                                );
                            }

                            $mainImage =
                                $newImage;
                        }


                        $stmt = $pdo->prepare("
                            UPDATE packages

                            SET
                                destination_id = ?,
                                title = ?,
                                slug = ?,
                                package_code = ?,
                                short_description = ?,
                                description = ?,
                                duration_days = ?,
                                duration_nights = ?,
                                child_age_min = ?,
                                child_age_max = ?,
                                child_price = ?,
                                regular_price = ?,
                                sale_price = ?,
                                currency = ?,
                                package_type = ?,
                                main_image = ?,
                                inclusions = ?,
                                exclusions = ?,
                                itinerary = ?,
                                terms = ?,
                                tour_highlights = ?,
                                featured = ?,
                                bestseller = ?,
                                status = ?

                            WHERE id = ?

                            LIMIT 1
                        ");

                        $stmt->execute([
                            $destinationId,
                            $title,
                            $slug,
                            $packageCode !== ''
                                ? $packageCode
                                : null,
                            $shortDescription !== ''
                                ? $shortDescription
                                : null,
                            $description !== ''
                                ? $description
                                : null,
                            $durationDays,
                            $durationNights,
                            $childAgeMin,
                            $childAgeMax,
                            $childPrice,
                            $regularPrice,
                            $salePrice,
                            $currency,
                            $packageType,
                            $mainImage,
                            $inclusions !== ''
                                ? $inclusions
                                : null,
                            $exclusions !== ''
                                ? $exclusions
                                : null,
                            $itinerary !== ''
                                ? $itinerary
                                : null,
                            $terms !== ''
                                ? $terms
                                : null,

                            $tourHighlights !== ''
                                ? $tourHighlights
                                : null,

                            $featured,
                            $bestseller,
                            $status,
                            $packageId
                        ]);

                        /*
                        | Synchronize the day-wise itinerary for this package.
                        */
                        saveItineraryDays(
                            $pdo,
                            $packageId
                        );

                        savePackageGalleryUploads(
                            $pdo,
                            $packageId
                        );

                            saveSelectedPackageGalleryMedia($pdo, $packageId);

                        savePackagePriceRows(
                            $pdo,
                            $packageId,
                            $packagePriceRows
                        );

                        savePackageChildPriceRows(
                            $pdo,
                            $packageId,
                            $packageChildPriceRows
                        );

                            savePackageHotelAssignments(
                                $pdo,
                                $packageId,
                                $destinationId,
                                $durationNights,
                                $packageHotelSelections
                            );


                        $readiness = packagePublishReadiness($pdo, $packageId);
                        $publishDowngraded = false;

                        if ($status === 'active' && !$readiness['ready']) {
                            $pdo->prepare("UPDATE packages SET status = 'draft' WHERE id = ?")
                                ->execute([$packageId]);
                            $publishDowngraded = true;
                            $_SESSION['admin_package_error'] =
                                'Package was saved but moved to Draft. Complete before publishing: ' .
                                implode(', ', $readiness['required_missing']) . '.';
                        }

                        $_SESSION['admin_package_success'] =
                            $publishDowngraded
                                ? 'Package updated. Publish readiness protection kept it in Draft.'
                                : 'Package updated successfully.';

                        /*
                        | After a successful update, return to Package Management
                        | home so the success flash is visible and the editor does
                        | not reopen on the Basic Info tab.
                        */
                        redirect(
                            BASE_URL
                            . 'admin-packages.php'
                        );
                    }


                } catch (RuntimeException $e) {

                    $error =
                        $e->getMessage();

                } catch (Throwable $e) {

                    error_log(
                        'Package save error: '
                        . $e->getMessage()
                    );

                    $error =
                        'Unable to save package. Please check your package data and database structure.';
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | QUICK STATUS
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'change_status') {

            $packageId =
                (int)(
                    $_POST['package_id']
                    ?? 0
                );

            $newStatus =
                strtolower(
                    trim(
                        (string)(
                            $_POST['new_status']
                            ?? ''
                        )
                    )
                );

            if (
                $packageId > 0
                &&
                in_array(
                    $newStatus,
                    [
                        'active',
                        'inactive',
                        'draft'
                    ],
                    true
                )
            ) {

                try {

                    if ($newStatus === 'active') {
                        $readiness = packagePublishReadiness($pdo, $packageId);
                        if (!$readiness['ready']) {
                            throw new RuntimeException(
                                'Cannot publish yet. Complete: ' .
                                implode(', ', $readiness['required_missing']) . '.'
                            );
                        }
                    }

                    $stmt = $pdo->prepare("
                        UPDATE packages
                        SET status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $newStatus,
                        $packageId
                    ]);

                    $_SESSION['admin_package_success'] =
                        'Package status updated.';

                    redirect(
                        BASE_URL
                        . 'admin-packages.php'
                    );

                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {

                    error_log(
                        'Package status error: '
                        . $e->getMessage()
                    );

                    $error =
                        'Unable to update package status.';
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | FEATURED TOGGLE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'toggle_featured') {

            $packageId =
                (int)(
                    $_POST['package_id']
                    ?? 0
                );

            if ($packageId > 0) {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE packages
                        SET featured =
                            CASE
                                WHEN featured = 1
                                THEN 0
                                ELSE 1
                            END
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $packageId
                    ]);

                    $_SESSION['admin_package_success'] =
                        'Featured setting updated.';

                    redirect(
                        BASE_URL
                        . 'admin-packages.php'
                    );

                } catch (Throwable $e) {

                    $error =
                        'Unable to update featured setting.';
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | BESTSELLER TOGGLE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'toggle_bestseller') {

            $packageId =
                (int)(
                    $_POST['package_id']
                    ?? 0
                );

            if ($packageId > 0) {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE packages
                        SET bestseller =
                            CASE
                                WHEN bestseller = 1
                                THEN 0
                                ELSE 1
                            END
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $packageId
                    ]);

                    $_SESSION['admin_package_success'] =
                        'Bestseller setting updated.';

                    redirect(
                        BASE_URL
                        . 'admin-packages.php'
                    );

                } catch (Throwable $e) {

                    $error =
                        'Unable to update bestseller setting.';
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CLONE PACKAGE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'clone_package') {

            $packageId = (int)($_POST['package_id'] ?? 0);

            if ($packageId <= 0) {
                $error = 'Invalid package.';
            } else {
                try {
                    $newPackageId = clonePackageRecord($pdo, $packageId, $adminId);

                    $_SESSION['admin_package_success'] =
                        'Package cloned as Draft. Review dates, prices and hotels before publishing.';

                    redirect(
                        BASE_URL . 'admin-packages.php?edit=' . $newPackageId . '#package-form'
                    );
                } catch (Throwable $e) {
                    error_log('Package clone error: ' . $e->getMessage());
                    $error = 'Unable to clone package: ' . $e->getMessage();
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DELETE SELECTED PACKAGE MASTER RECORDS
        | Same safety checks and transactional ordering as the existing Delete action.
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'bulk_delete') {
            $submittedIds = $_POST['package_ids'] ?? [];
            $selectedIds = is_array($submittedIds)
                ? array_values(array_unique(array_filter(array_map('intval', $submittedIds), static fn($id) => $id > 0)))
                : [];

            if (!$selectedIds) {
                $error = 'Select at least one package to delete.';
            } elseif (count($selectedIds) > 200) {
                $error = 'You can delete a maximum of 200 packages in one request.';
            } else {
                $deleted = 0;
                $skipped = [];
                foreach ($selectedIds as $selectedId) {
                    try {
                        // Match the existing single-delete protection: booked packages cannot be deleted.
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE package_id = ?');
                        $stmt->execute([$selectedId]);
                        if ((int)$stmt->fetchColumn() > 0) {
                            throw new RuntimeException('Package has bookings.');
                        }

                        $stmt = $pdo->prepare('SELECT main_image FROM packages WHERE id = ? LIMIT 1');
                        $stmt->execute([$selectedId]);
                        $packageToDelete = $stmt->fetch();
                        if (!$packageToDelete) {
                            throw new RuntimeException('Package not found.');
                        }

                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM wishlists WHERE package_id = ?')->execute([$selectedId]);
                        $stmt = $pdo->prepare('SELECT image FROM package_images WHERE package_id = ?');
                        $stmt->execute([$selectedId]);
                        $galleryImagesToDelete = $stmt->fetchAll();
                        $pdo->prepare('DELETE FROM package_images WHERE package_id = ?')->execute([$selectedId]);
                        $stmt = $pdo->prepare('DELETE FROM packages WHERE id = ? LIMIT 1');
                        $stmt->execute([$selectedId]);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Package was not deleted.');
                        }
                        $pdo->commit();
                        $deleted++;

                        // Delete stored image files only after the package transaction succeeds.
                        // Use the existing file helper; any image issue must not roll back committed rows.
                        try {
                            deletePackageImageFile($packageToDelete['main_image'] ?? '');
                            foreach ($galleryImagesToDelete as $galleryImage) {
                                deletePackageImageFile($galleryImage['image'] ?? '');
                            }
                        } catch (Throwable $imageError) {
                            error_log('Package bulk-delete image cleanup: ' . $imageError->getMessage());
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $skipped[] = $selectedId;
                        error_log('Package bulk-delete ID ' . $selectedId . ': ' . $e->getMessage());
                    }
                }
                $_SESSION['admin_package_success'] = $deleted . ' package(s) deleted successfully.';
                if ($skipped) {
                    $_SESSION['admin_package_error'] = count($skipped)
                        . ' package(s) skipped because of existing bookings, linked records, or database restrictions. IDs: '
                        . implode(', ', array_slice($skipped, 0, 20))
                        . (count($skipped) > 20 ? '…' : '') . '. No protected package was force-deleted.';
                }
                redirect(BASE_URL . 'admin-packages.php');
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE PACKAGE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $packageId =
                (int)(
                    $_POST['package_id']
                    ?? 0
                );

            if ($packageId <= 0) {

                $error =
                    'Invalid package.';

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | DO NOT DELETE PACKAGE WITH BOOKINGS
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM bookings
                        WHERE package_id = ?
                    ");

                    $stmt->execute([
                        $packageId
                    ]);

                    $bookingCount =
                        (int)$stmt->fetchColumn();

                    if ($bookingCount > 0) {

                        throw new RuntimeException(
                            'This package has booking records and cannot be deleted. Set it to inactive instead.'
                        );
                    }


                    $stmt = $pdo->prepare("
                        SELECT main_image
                        FROM packages
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $packageId
                    ]);

                    $package =
                        $stmt->fetch();

                    if (!$package) {
                        throw new RuntimeException(
                            'Package not found.'
                        );
                    }


                    $pdo->beginTransaction();


                    /*
                    | Remove wishlist records.
                    */

                    $stmt = $pdo->prepare("
                        DELETE FROM wishlists
                        WHERE package_id = ?
                    ");

                    $stmt->execute([
                        $packageId
                    ]);


                    /*
                    | Remove gallery records.
                    */

                    $stmt = $pdo->prepare("
                        SELECT image
                        FROM package_images
                        WHERE package_id = ?
                    ");

                    $stmt->execute([
                        $packageId
                    ]);

                    $galleryImages =
                        $stmt->fetchAll();


                    $stmt = $pdo->prepare("
                        DELETE FROM package_images
                        WHERE package_id = ?
                    ");

                    $stmt->execute([
                        $packageId
                    ]);


                    /*
                    | Delete package.
                    */

                    $stmt = $pdo->prepare("
                        DELETE FROM packages
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $packageId
                    ]);


                    $pdo->commit();


                    /*
                    | Remove files only after successful DB transaction.
                    */

                    deletePackageImageFile(
                        $package['main_image']
                        ?? ''
                    );

                    foreach (
                        $galleryImages
                        as $gallery
                    ) {

                        deletePackageImageFile(
                            $gallery['image']
                            ?? ''
                        );
                    }


                    $_SESSION['admin_package_success'] =
                        'Package deleted successfully.';

                    redirect(
                        BASE_URL
                        . 'admin-packages.php'
                    );


                } catch (RuntimeException $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        $e->getMessage();

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'Package delete error: '
                        . $e->getMessage()
                    );

                    $error =
                        'Unable to delete this package.';
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| EDIT PACKAGE
|--------------------------------------------------------------------------
*/

$editPackage = null;

$editId =
    (int)(
        $_GET['edit']
        ?? 0
    );

if ($editId > 0) {

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM packages
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $editId
        ]);

        $editPackage =
            $stmt->fetch();

        if (!$editPackage) {
            $error =
                'Package not found.';
        } elseif (
            function_exists('tsPSOSyncPackageContentFromSupplier')
            && (string)($editPackage['source_type'] ?? '') === 'supplier_imported'
        ) {
            $forceSupplierRefresh = !empty($_GET['refresh_supplier_content']);
            $missingSupplierContent =
                trim((string)($editPackage['short_description'] ?? '')) === ''
                || trim((string)($editPackage['description'] ?? '')) === ''
                || trim((string)($editPackage['tour_highlights'] ?? '')) === ''
                || trim((string)($editPackage['terms'] ?? '')) === '';
            $missingSupplierPrice = false;
            try {
                $pc = $pdo->prepare("SELECT COUNT(*) FROM package_date_prices WHERE package_id=? AND (COALESCE(price_3_star,0)>0 OR COALESCE(price_4_star,0)>0 OR COALESCE(price_5_star,0)>0)");
                $pc->execute([(int)$editPackage['id']]);
                $missingSupplierPrice = (int)$pc->fetchColumn() === 0;
            } catch (Throwable $ignored) {}

            if ($forceSupplierRefresh || $missingSupplierContent || $missingSupplierPrice) {
                try {
                    $syncResult = tsPSOSyncPackageContentFromSupplier(
                        $pdo,
                        (int)$editPackage['id'],
                        (int)($_SESSION['admin_id'] ?? 0)
                    );
                    $stmt->execute([$editId]);
                    $editPackage = $stmt->fetch();
                    if ($forceSupplierRefresh) {
                        $success = 'Supplier package refreshed: descriptions, tour highlights, terms and '.number_format((int)($syncResult['days_synced'] ?? 0)).' itinerary day(s) updated; '.number_format((int)($syncResult['price_rows_added'] ?? 0)).' missing Package Master pricing period(s) restored'.(!empty($syncResult['cover_added'])?' and destination cover image applied':'').'. Existing itinerary images were preserved.';
                    }
                } catch (Throwable $syncError) {
                    error_log('Supplier package content sync error: '.$syncError->getMessage());
                    if ($forceSupplierRefresh) {
                        $error = 'Unable to refresh supplier content: '.$syncError->getMessage();
                    }
                }
            }
        }

    } catch (Throwable $e) {

        $error =
            'Unable to load package for editing.';
    }
}



/*
|--------------------------------------------------------------------------
| LOAD DAY-WISE ITINERARY
|--------------------------------------------------------------------------
*/

$editItineraryDays = [];

if (!empty($editPackage['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, day_number, title, description, image, sort_order
            FROM package_itinerary_days
            WHERE package_id = ?
            ORDER BY sort_order ASC, day_number ASC, id ASC
        ");
        $stmt->execute([(int)$editPackage['id']]);
        $editItineraryDays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Package itinerary load error: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| LOAD PACKAGE GALLERY FOR EDITING
|--------------------------------------------------------------------------
*/

$editGalleryImages = [];

if (!empty($editPackage['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, image, sort_order
            FROM package_images
            WHERE package_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$editPackage['id']]);
        $editGalleryImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Package gallery load error: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| LOAD CHILD PRICE ROWS FOR EDITING
|--------------------------------------------------------------------------
*/

if (!isset($editPackageChildPriceRows)) {
    $editPackageChildPriceRows = [];
}

if (!empty($editPackage['id']) && !$editPackageChildPriceRows) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, age_from, age_to, price, sort_order
            FROM package_child_prices
            WHERE package_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$editPackage['id']]);
        $editPackageChildPriceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Package child price load error: ' . $e->getMessage());
    }

    /*
    | Existing packages created before child-row support still open correctly.
    */
    if (
        !$editPackageChildPriceRows
        && (
            (int)($editPackage['child_age_min'] ?? 0) > 0
            || (int)($editPackage['child_age_max'] ?? 0) > 0
            || trim((string)($editPackage['child_price'] ?? '')) !== ''
        )
    ) {
        $editPackageChildPriceRows[] = [
            'age_from' => max(0, (int)($editPackage['child_age_min'] ?? 0)),
            'age_to' => max(0, (int)($editPackage['child_age_max'] ?? 0)),
            'price' => (string)($editPackage['child_price'] ?? '')
        ];
    }
}


/*
|--------------------------------------------------------------------------
| LOAD DATE-WISE HOTEL PRICES FOR EDITING
|--------------------------------------------------------------------------
*/

$editPackagePriceRows = [];

if (!empty($editPackage['id'])) {
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
            (int)$editPackage['id']
        ]);

        $editPackagePriceRows =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log(
            'Package date-wise price load error: '
            . $e->getMessage()
        );
    }
}




/*
|--------------------------------------------------------------------------
| LOAD AUTOMATIC PACKAGE HOTELS FOR EDITING
|--------------------------------------------------------------------------
*/
$editPackageHotels = [];

if (!empty($editPackage['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT ph.*, h.hotel_name, h.is_preferred
            FROM package_hotels ph
            LEFT JOIN hotels h ON h.id = ph.hotel_id
            WHERE ph.package_id = ?
            ORDER BY ph.star_category ASC
        ");
        $stmt->execute([(int)$editPackage['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $editPackageHotels[(int)$row['star_category']] = $row;
        }
    } catch (Throwable $e) {
        error_log('Package hotel assignment load error: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

$statusFilter =
    strtolower(
        trim(
            (string)(
                $_GET['status']
                ?? ''
            )
        )
    );

$typeFilter =
    strtolower(
        trim(
            (string)(
                $_GET['type']
                ?? ''
            )
        )
    );

$destinationFilter =
    (int)(
        $_GET['destination']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| FETCH PACKAGES
|--------------------------------------------------------------------------
*/

$packages = [];

try {

    $where = [];
    $params = [];


    if ($search !== '') {

        $where[] = "
            (
                p.title LIKE ?
                OR p.package_code LIKE ?
                OR p.slug LIKE ?
                OR d.name LIKE ?
                OR d.country LIKE ?
            )
        ";

        $term =
            '%' . $search . '%';

        array_push(
            $params,
            $term,
            $term,
            $term,
            $term,
            $term
        );
    }


    if (
        in_array(
            $statusFilter,
            [
                'active',
                'inactive',
                'draft'
            ],
            true
        )
    ) {

        $where[] =
            "p.status = ?";

        $params[] =
            $statusFilter;
    }


    if ($typeFilter !== '') {

        $where[] =
            "p.package_type = ?";

        $params[] =
            $typeFilter;
    }


    if ($destinationFilter > 0) {

        $where[] =
            "p.destination_id = ?";

        $params[] =
            $destinationFilter;
    }


    $sql = "
        SELECT

            p.*,

            d.name AS destination_name,
            d.country AS destination_country,

            (
                SELECT COUNT(*)
                FROM bookings b
                WHERE b.package_id = p.id
            ) AS booking_count,

            (
                SELECT COUNT(*)
                FROM wishlists w
                WHERE w.package_id = p.id
            ) AS wishlist_count,

            (
                SELECT COUNT(*)
                FROM package_images pi
                WHERE pi.package_id = p.id
            ) AS gallery_count,

            (
                SELECT COALESCE(
                    MIN(CASE WHEN COALESCE(pp.price_3_star,0) > 0 THEN pp.price_3_star END),
                    MIN(CASE WHEN COALESCE(pp.price_4_star,0) > 0 THEN pp.price_4_star END),
                    MIN(CASE WHEN COALESCE(pp.price_5_star,0) > 0 THEN pp.price_5_star END)
                )
                FROM package_date_prices pp
                WHERE pp.package_id = p.id
            ) AS reference_start_price

        FROM packages p

        LEFT JOIN destinations d
            ON d.id = p.destination_id
    ";


    if ($where) {

        $sql .=
            ' WHERE '
            . implode(
                ' AND ',
                $where
            );
    }


    $sql .= "
        ORDER BY
            p.id DESC
    ";


    $stmt =
        $pdo->prepare($sql);

    $stmt->execute($params);

    $packages =
        $stmt->fetchAll();


} catch (Throwable $e) {

    error_log(
        'Admin packages load error: '
        . $e->getMessage()
    );

    $error =
        'Unable to load packages.';
}


/*
|--------------------------------------------------------------------------
| DASHBOARD STATS
|--------------------------------------------------------------------------
*/

$stats = [
    'total'      => 0,
    'active'     => 0,
    'draft'      => 0,
    'featured'   => 0,
    'bestseller' => 0
];

try {

    $stmt = $pdo->query("
        SELECT

            COUNT(*) AS total,

            SUM(
                status = 'active'
            ) AS active,

            SUM(
                status = 'draft'
            ) AS draft,

            SUM(
                featured = 1
            ) AS featured,

            SUM(
                bestseller = 1
            ) AS bestseller

        FROM packages
    ");

    $row = $stmt->fetch();

    if ($row) {

        foreach ($stats as $key => $value) {
            $stats[$key] =
                (int)(
                    $row[$key]
                    ?? 0
                );
        }
    }

} catch (Throwable $e) {
    error_log(
        'Package stats error: '
        . $e->getMessage()
    );
}


$packageMediaLibrary = packageMediaLibrary();


/*
|--------------------------------------------------------------------------
| PRESERVE ADD / UPDATE FORM DATA AFTER VALIDATION ERROR
|--------------------------------------------------------------------------
| Add New Package now behaves exactly like Update Package:
| - Duration Days recreates the correct number of itinerary rows.
| - Existing entered titles and activities remain filled.
| - Only genuinely new days are blank.
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $error !== ''
    && in_array(
        (string)($_POST['action'] ?? ''),
        ['create', 'update'],
        true
    )
) {
    $postedPackageId =
        (int)($_POST['package_id'] ?? 0);

    $editPackage = [
        'id'                => $postedPackageId,
        'destination_id'    => (string)($_POST['destination_id'] ?? ''),
        'title'             => (string)($_POST['title'] ?? ''),
        'package_code'      => (string)($_POST['package_code'] ?? ''),
        'short_description' => (string)($_POST['short_description'] ?? ''),
        'description'       => (string)($_POST['description'] ?? ''),
        'duration_days'     => max(0, (int)($_POST['duration_days'] ?? 0)),
        'duration_nights'   => max(0, (int)($_POST['duration_nights'] ?? 0)),
        'child_age_min'     => max(0, (int)($_POST['child_age_min'] ?? 0)),
        'child_age_max'     => max(0, (int)($_POST['child_age_max'] ?? 0)),
        'child_price'       => (string)($_POST['child_price'] ?? ''),
        'regular_price'     => (string)($_POST['regular_price'] ?? ''),
        'sale_price'        => (string)($_POST['sale_price'] ?? ''),
        'currency'          => (string)($_POST['currency'] ?? 'INR'),
        'package_type'      => (string)($_POST['package_type'] ?? ''),
        'main_image'        => (string)($_POST['library_main_image'] ?? ''),
        'inclusions'        => (string)($_POST['inclusions'] ?? ''),
        'exclusions'        => (string)($_POST['exclusions'] ?? ''),
        'itinerary'         => (string)($_POST['itinerary'] ?? ''),
        'terms'             => (string)($_POST['terms'] ?? ''),
        'tour_highlights'   => (string)($_POST['tour_highlights'] ?? ''),
        'featured'          => !empty($_POST['featured']) ? 1 : 0,
        'bestseller'        => !empty($_POST['bestseller']) ? 1 : 0,
        'status'            => (string)($_POST['status'] ?? 'draft')
    ];

    $editPackageChildPriceRows =
        normalizePackageChildPriceRows(
            $_POST
        );

    $editPackagePriceRows =
        normalizePackagePriceRows(
            $_POST
        );

    $postedTitles =
        is_array($_POST['itinerary_title'] ?? null)
            ? $_POST['itinerary_title']
            : [];

    $postedDescriptions =
        is_array($_POST['itinerary_description'] ?? null)
            ? $_POST['itinerary_description']
            : [];

    $postedIds =
        is_array($_POST['itinerary_id'] ?? null)
            ? $_POST['itinerary_id']
            : [];

    $postedLibraryImages =
        is_array($_POST['itinerary_library_image'] ?? null)
            ? $_POST['itinerary_library_image']
            : [];

    $requiredDays =
        max(
            0,
            (int)($editPackage['duration_days'] ?? 0)
        );

    $editItineraryDays = [];

    for ($index = 0; $index < $requiredDays; $index++) {
        $savedImage = '';

        if (!empty($postedLibraryImages[$index])) {
            $savedImage =
                validPackageMediaPath(
                    (string)$postedLibraryImages[$index]
                ) ?? '';
        }

        /*
        | For update-mode validation errors, retain the existing image
        | when no new Web Gallery/Pexels image was selected.
        */
        if (
            $savedImage === ''
            && $postedPackageId > 0
            && !empty($postedIds[$index])
        ) {
            try {
                $imageStmt = $pdo->prepare("
                    SELECT image
                    FROM package_itinerary_days
                    WHERE id = ?
                      AND package_id = ?
                    LIMIT 1
                ");

                $imageStmt->execute([
                    (int)$postedIds[$index],
                    $postedPackageId
                ]);

                $savedImage =
                    (string)($imageStmt->fetchColumn() ?: '');

            } catch (Throwable $e) {
                error_log(
                    'Preserve itinerary image error: '
                    . $e->getMessage()
                );
            }
        }

        $editItineraryDays[] = [
            'id' =>
                (int)($postedIds[$index] ?? 0),

            'day_number' =>
                $index + 1,

            'title' =>
                (string)($postedTitles[$index] ?? ''),

            'description' =>
                (string)($postedDescriptions[$index] ?? ''),

            'image' =>
                $savedImage,

            'sort_order' =>
                $index + 1
        ];
    }
}


/*
|--------------------------------------------------------------------------
| FORM DEFAULTS
|--------------------------------------------------------------------------
*/

$form = $editPackage ?: [
    'id'                => 0,
    'destination_id'    => '',
    'title'             => '',
    'package_code'      => '',
    'short_description' => '',
    'description'       => '',
    'duration_days'     => 0,
    'duration_nights'   => 0,
    'child_age_min'     => 0,
    'child_age_max'     => 0,
    'child_price'       => '',
    'regular_price'     => '',
    'sale_price'        => '',
    'currency'          => 'INR',
    'package_type'      => $activePackageTypeSlugs[0] ?? '',
    'main_image'        => '',
    'inclusions'        => '',
    'exclusions'        => '',
    'itinerary'         => '',
    'terms'             => '',
    'tour_highlights'   => '',
    'featured'          => 0,
    'bestseller'        => 0,
    'status'            => 'draft'
];

/* V20 - Supplier/source context is separate from customer-facing Package Master content. */
$packageSupplierOptions = [];
$packageSupplierPrimary = null;
$packageSupplierReady = function_exists('tsPSOReady') ? tsPSOReady($pdo) : false;
if (!empty($editPackage['id']) && $packageSupplierReady && function_exists('tsPSOOptions')) {
    try {
        $packageSupplierOptions = tsPSOOptions($pdo, (int)$editPackage['id']);
        foreach ($packageSupplierOptions as $supplierOption) {
            if (!empty($supplierOption['is_primary'])) { $packageSupplierPrimary = $supplierOption; break; }
        }
    } catch (Throwable $e) {
        error_log('V20 package supplier context error: '.$e->getMessage());
    }
}

/* Hotel options available for the currently selected package destination/location. */
$packageHotelOptionsByStar = [3 => [], 4 => [], 5 => []];
$packageHotelSelectedIds = [3 => 0, 4 => 0, 5 => 0];
$packageHotelLocations = [];
$formDestinationId = (int)($form['destination_id'] ?? 0);
$formHotelLocationId = max(0, (int)($_POST['package_hotel_location_id'] ?? 0));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $error !== ''
    && in_array((string)($_POST['action'] ?? ''), ['create','update'], true)
) {
    foreach ([3,4,5] as $star) {
        $packageHotelSelectedIds[$star] = (int)($_POST['hotel_' . $star . '_star_id'] ?? 0);
    }
} else {
    foreach ([3,4,5] as $star) {
        $packageHotelSelectedIds[$star] = (int)($editPackageHotels[$star]['hotel_id'] ?? 0);
        if ($formHotelLocationId <= 0 && !empty($editPackageHotels[$star]['location_id'])) {
            $formHotelLocationId = (int)$editPackageHotels[$star]['location_id'];
        }
    }
}

if ($formDestinationId > 0) {
    try {
        $related = packageHotelRelatedDestination($pdo,$formDestinationId,'h');

        // Start with child locations already registered directly under the package destination.
        $locationMap=[];
        if (function_exists('tsLocationList')) {
            foreach (tsLocationList($pdo, $formDestinationId) as $loc) {
                $name=trim((string)($loc['location_name']??''));
                if ($name==='') continue;
                $locationMap[strtolower($name)]=$loc;
            }
        }

        // Load hotels not only by identical destination_id, but also by Smart Hotel geo city/state.
        // Example: Package destination = Meghalaya, hotel destination = Shillong, geo_state = Meghalaya.
        $stmt = $pdo->prepare("
            SELECT h.id,h.destination_id,h.hotel_name,h.city,h.location_id,h.star_category,h.default_room_type,
                   h.default_meal_plan,h.is_preferred,h.priority_order,h.primary_image,
                   dl.location_name,s.company_name AS supplier_name
            FROM hotels h
            LEFT JOIN destination_locations dl ON dl.id=h.location_id
            LEFT JOIN suppliers s ON s.id=h.preferred_supplier_id
            WHERE {$related['sql']} AND h.status='active'
            ORDER BY COALESCE(dl.location_name,h.city),h.star_category,h.is_preferred DESC,h.priority_order,h.hotel_name
        ");
        $stmt->execute($related['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hotelRow) {
            $star = (int)$hotelRow['star_category'];
            if (isset($packageHotelOptionsByStar[$star])) $packageHotelOptionsByStar[$star][] = $hotelRow;

            $locName=trim((string)($hotelRow['location_name'] ?: ($hotelRow['city'] ?? '')));
            $locId=(int)($hotelRow['location_id']??0);
            if($locName!=='' && $locId>0){
                $key=strtolower($locName);
                if(!isset($locationMap[$key])){
                    $locationMap[$key]=[
                        'id'=>$locId,
                        'destination_id'=>(int)($hotelRow['destination_id']??$formDestinationId),
                        'location_name'=>$locName,
                        'location_type'=>'city',
                        'source'=>'hotel_master',
                        'status'=>'active',
                    ];
                }
            }
        }
        $packageHotelLocations=array_values($locationMap);
        usort($packageHotelLocations,fn($a,$b)=>strcasecmp((string)($a['location_name']??''),(string)($b['location_name']??'')));

        // If the package is carrying an old location ID that no longer belongs to any related hotel,
        // reset the filter to All Locations instead of hiding every available Hotel Master property.
        if($formHotelLocationId>0){
            $validLocationIds=array_map(fn($x)=>(int)($x['id']??0),$packageHotelLocations);
            if(!in_array($formHotelLocationId,$validLocationIds,true))$formHotelLocationId=0;
        }
    } catch (Throwable $e) {
        error_log('Package hotel options error: ' . $e->getMessage());
    }
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

<title>
Package Management | TRAVSCOPE Admin
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

:root{
    --navy:#07192d;
    --navy2:#0d2b4a;
    --blue:#0b74ff;
    --blue2:#075fcf;
    --cyan:#00b8ff;
    --green:#07885f;
    --orange:#dd8314;
    --red:#d94355;
    --purple:#7258d8;
    --bg:#f4f7fb;
    --white:#fff;
    --text:#172235;
    --muted:#7e8a9b;
    --border:#e1e8ef;
    --shadow:0 14px 40px rgba(7,25,45,.07);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

body{
    background:var(--bg);
    color:var(--text);
    font-family:'DM Sans',sans-serif;
}

a{
    color:inherit;
    text-decoration:none;
}

button,
input,
select,
textarea{
    font:inherit;
}

/* CHILD AGE + PER CHILD PRICE */

.child-price-builder{
    padding:18px;
    border:1px solid #dce7f3;
    border-radius:14px;
    background:linear-gradient(135deg,#f8fbff,#ffffff);
}

.child-price-head{
    margin-bottom:15px;
}

.child-price-head h3{
    display:flex;
    align-items:center;
    gap:9px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:17px;
    font-weight:800;
}

.child-price-head h3 i{
    width:34px;
    height:34px;
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#eaf4ff;
    color:var(--blue);
}

.child-price-head p{
    margin-top:6px;
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
}

.child-price-grid{
    display:grid;
    grid-template-columns:1fr 1fr 1.2fr;
    gap:12px;
}

.child-price-grid .form-group{
    margin:0;
}

@media(max-width:760px){
    .child-price-grid{
        grid-template-columns:1fr;
    }
}


/* HEADER */

.header{
    height:70px;
    padding:0 28px;
    position:sticky;
    top:0;
    z-index:1000;
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:#fff;
    border-bottom:1px solid var(--border);
}

.logo{
    display:flex;
    align-items:center;
    gap:9px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:18px;
    font-weight:800;
}

.logo-box{
    width:38px;
    height:38px;
    border-radius:11px;
    display:grid;
    place-items:center;
    color:white;
    background:linear-gradient(135deg,var(--blue),var(--cyan));
}

.logo span{
    color:var(--blue);
}

.admin-user{
    display:flex;
    align-items:center;
    gap:10px;
}

.admin-avatar{
    width:38px;
    height:38px;
    border-radius:11px;
    display:grid;
    place-items:center;
    background:#edf5ff;
    color:var(--blue);
    font-weight:800;
}

.admin-user strong{
    display:block;
    font-size:10px;
}

.admin-user small{
    display:block;
    margin-top:2px;
    color:#929dab;
    font-size:8px;
}

/* LAYOUT */

.layout{
    min-height:calc(100vh - 70px);
    display:grid;
    grid-template-columns:230px minmax(0,1fr);
}

/* SIDEBAR */

.sidebar{
    padding:20px 13px;
    background:var(--navy);
    color:white;
}

.menu-title{
    padding:0 11px;
    margin:13px 0 7px;
    color:#71869c;
    font-size:7px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:1px;
}

.sidebar a{
    min-height:43px;
    margin-bottom:4px;
    padding:0 12px;
    border-radius:9px;
    display:flex;
    align-items:center;
    gap:10px;
    color:#b6c5d4;
    font-size:9px;
    font-weight:700;
    transition:.2s;
}

.sidebar a i{
    width:18px;
    text-align:center;
}

.sidebar a:hover,
.sidebar a.active{
    background:rgba(255,255,255,.10);
    color:white;
}

.sidebar a.active{
    background:linear-gradient(135deg,var(--blue),#0866dd);
}

/* MAIN */

.main{
    min-width:0;
    padding:27px;
}

.page-head{
    margin-bottom:22px;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:20px;
}

.page-head h1{
    font-family:'Manrope',sans-serif;
    font-size:25px;
    color:var(--navy);
}

.page-head p{
    margin-top:5px;
    color:var(--muted);
    font-size:9px;
}

.add-btn{
    min-height:40px;
    padding:0 14px;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:linear-gradient(135deg,var(--blue),var(--blue2));
    color:white;
    font-size:8px;
    font-weight:800;
    box-shadow:0 8px 18px rgba(11,116,255,.17);
}

/* ALERT */

.alert{
    margin-bottom:17px;
    padding:13px 15px;
    border-radius:10px;
    display:flex;
    gap:8px;
    font-size:9px;
}

.success{
    background:#eaf9f3;
    border:1px solid #bde8d6;
    color:#08724f;
}

.error{
    background:#fff0f2;
    border:1px solid #f1c7cd;
    color:#b43748;
}

/* STATS */

.stats{
    margin-bottom:22px;
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
}

.stat{
    padding:16px;
    border:1px solid var(--border);
    border-radius:13px;
    background:white;
    box-shadow:var(--shadow);
}

.stat-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.stat-icon{
    width:35px;
    height:35px;
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#edf5ff;
    color:var(--blue);
}

.stat strong{
    display:block;
    margin-top:13px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:22px;
}

.stat span{
    display:block;
    margin-top:2px;
    color:#8e99a8;
    font-size:8px;
}

/* CARD */

.card{
    margin-bottom:20px;
    border:1px solid var(--border);
    border-radius:15px;
    background:white;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.card-head{
    min-height:61px;
    padding:0 19px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.card-head h2{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:13px;
}

.card-head p{
    margin-top:2px;
    color:#929dab;
    font-size:8px;
}

.card-body{
    padding:19px;
}

/* FILTERS */

.filters{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:9px;
}

.control{
    width:100%;
    height:40px;
    padding:0 11px;
    border:1px solid #dfe6ed;
    border-radius:8px;
    outline:none;
    background:white;
    color:#38465a;
    font-size:9px;
}

.control:focus{
    border-color:#8abaff;
    box-shadow:0 0 0 3px rgba(11,116,255,.06);
}

.filter-btn{
    height:40px;
    padding:0 13px;
    border:0;
    border-radius:8px;
    cursor:pointer;
    background:var(--navy);
    color:white;
    font-size:8px;
    font-weight:800;
}

/* TABLE */

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1000px;
}

th{
    padding:11px;
    background:#f7f9fb;
    color:#758296;
    font-size:7px;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:.4px;
}

td{
    padding:12px 11px;
    border-top:1px solid #edf0f3;
    font-size:8px;
    vertical-align:middle;
}

.package-cell{
    display:flex;
    align-items:center;
    gap:10px;
}

.package-thumb{
    width:58px;
    height:46px;
    flex:0 0 58px;
    border-radius:8px;
    overflow:hidden;
    display:grid;
    place-items:center;
    background:#edf5ff;
    color:var(--blue);
}

.package-thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.package-cell strong{
    display:block;
    max-width:230px;
    color:var(--navy);
    font-size:9px;
}

.package-cell span{
    display:block;
    margin-top:3px;
    color:#929dab;
    font-size:7px;
}

.price{
    color:var(--navy);
    font-weight:800;
}

.badge{
    padding:5px 7px;
    border-radius:20px;
    display:inline-block;
    font-size:7px;
    font-weight:800;
    text-transform:capitalize;
}

.badge-active{
    background:#e8f8f1;
    color:var(--green);
}

.badge-inactive{
    background:#fff0f2;
    color:var(--red);
}

.badge-draft{
    background:#fff4e5;
    color:var(--orange);
}

.small-tags{
    margin-top:5px;
    display:flex;
    gap:4px;
    flex-wrap:wrap;
}

.mini-tag{
    padding:3px 5px;
    border-radius:4px;
    background:#edf5ff;
    color:var(--blue);
    font-size:6px;
    font-weight:800;
}

.actions{
    display:flex;
    align-items:center;
    gap:5px;
}

.icon-btn{
    width:31px;
    height:31px;
    border:1px solid var(--border);
    border-radius:7px;
    display:grid;
    place-items:center;
    background:white;
    color:#637286;
    cursor:pointer;
    font-size:8px;
}

.icon-btn:hover{
    color:var(--blue);
    border-color:#b9d6fb;
}

.icon-btn.danger:hover{
    color:var(--red);
    border-color:#f1c6cc;
}

/* FORM */

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:15px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    margin-bottom:6px;
    display:block;
    color:#566477;
    font-size:8px;
    font-weight:800;
}

.form-control{
    width:100%;
    height:43px;
    padding:0 12px;
    border:1px solid #dfe5ec;
    border-radius:8px;
    outline:none;
    background:white;
    color:#344257;
    font-size:9px;
}

textarea.form-control{
    height:110px;
    padding:11px 12px;
    resize:vertical;
    line-height:1.6;
}

textarea.large{
    height:155px;
}

.form-control:focus{
    border-color:#88baff;
    box-shadow:0 0 0 3px rgba(11,116,255,.06);
}

.help{
    margin-top:5px;
    color:#99a4b2;
    font-size:7px;
}

.switch-row{
    display:flex;
    gap:13px;
    flex-wrap:wrap;
}

.check-card{
    padding:11px 13px;
    border:1px solid var(--border);
    border-radius:9px;
    display:flex;
    align-items:center;
    gap:7px;
    cursor:pointer;
    color:#566477;
    font-size:8px;
    font-weight:700;
}

.check-card input{
    accent-color:var(--blue);
}

.current-image{
    margin-top:10px;
    width:150px;
    height:95px;
    border-radius:10px;
    overflow:hidden;
    border:1px solid var(--border);
}

.current-image img{
    width:100%;
    height:100%;
    object-fit:cover;
}



.multi-image-preview{
    margin-top:10px;
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(105px,1fr));
    gap:9px;
}
.multi-preview-item,
.existing-gallery-item{
    height:88px;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:9px;
    background:#eef3f8;
}
.multi-preview-item img,
.existing-gallery-item img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.existing-gallery{
    margin-top:14px;
    padding:13px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#f8fafc;
}
.existing-gallery-title{
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    gap:10px;
    color:var(--navy);
    font-size:9px;
}
.existing-gallery-title span{
    color:var(--muted);
    font-size:7px;
}
.existing-gallery-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(105px,1fr));
    gap:8px;
}
.manage-gallery-btn{
    margin-top:11px;
    min-height:34px;
    padding:0 11px;
    border:1px solid #bdd8fa;
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:white;
    color:var(--blue);
    font-size:8px;
    font-weight:800;
}
.itinerary-builder{
    border:1px solid var(--border);
    border-radius:12px;
    overflow:hidden;
    background:#f8fafc;
}
.itinerary-builder-head{
    padding:13px 15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    border-bottom:1px solid var(--border);
    background:white;
}

.itinerary-auto-note{
    min-height:34px;
    padding:8px 11px;
    border:1px solid #cfe5ff;
    border-radius:8px;
    display:flex;
    align-items:center;
    gap:7px;
    background:#f1f7ff;
    color:#356b9f;
    font-size:9px;
    font-weight:800;
    line-height:1.45;
}

.itinerary-auto-note i{
    color:var(--blue);
}
.itinerary-builder-head strong{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:10px;
}
.add-day-btn{
    min-height:34px;
    padding:0 11px;
    border:0;
    border-radius:7px;
    cursor:pointer;
    background:var(--navy);
    color:white;
    font-size:8px;
    font-weight:800;
}
.itinerary-days{
    max-height:680px;
    padding:13px;
    overflow-y:auto;
    overflow-x:hidden;
    display:grid;
    align-content:start;
    gap:12px;
    scroll-behavior:smooth;
    scrollbar-width:thin;
    scrollbar-color:#afbdcc #eef3f7;
}

.itinerary-days::-webkit-scrollbar{
    width:9px;
}

.itinerary-days::-webkit-scrollbar-track{
    background:#eef3f7;
    border-radius:10px;
}

.itinerary-days::-webkit-scrollbar-thumb{
    background:#afbdcc;
    border-radius:10px;
    border:2px solid #eef3f7;
}

.itinerary-days::-webkit-scrollbar-thumb:hover{
    background:#8999aa;
}

.itinerary-empty-state{
    padding:32px 18px;
    border:1px dashed #cdd8e3;
    border-radius:10px;
    background:#fff;
    color:#8491a1;
    text-align:center;
    font-size:10px;
    line-height:1.6;
}

.itinerary-empty-state i{
    display:block;
    margin-bottom:8px;
    color:#a6b3c1;
    font-size:25px;
}
.itinerary-day{
    padding:14px;
    border:1px solid #dfe6ed;
    border-radius:10px;
    background:white;
}
.itinerary-day-head{
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.day-label{
    display:flex;
    align-items:center;
    gap:7px;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:10px;
    font-weight:800;
}
.day-number{
    width:29px;
    height:29px;
    display:grid;
    place-items:center;
    border-radius:8px;
    background:#edf5ff;
    color:var(--blue);
}
.remove-day-btn{
    width:31px;
    height:31px;
    border:1px solid #f1c6cc;
    border-radius:7px;
    cursor:pointer;
    background:#fff7f8;
    color:var(--red);
}
.itinerary-day-grid{
    display:grid;
    grid-template-columns:minmax(0,1fr) 220px;
    gap:12px;
}
.itinerary-day textarea{height:105px;}
.day-image-box{
    padding:10px;
    border:1px dashed #ccd6e1;
    border-radius:9px;
    background:#fbfcfe;
}
.day-preview{
    width:100%;
    height:105px;
    margin-bottom:8px;
    border-radius:8px;
    overflow:hidden;
    display:grid;
    place-items:center;
    background:#eef3f8;
    color:#9aa7b6;
}
.day-preview img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.remove-image-label{
    margin-top:7px;
    display:flex !important;
    align-items:center;
    gap:6px;
    color:var(--red) !important;
    font-size:7px !important;
}

.form-footer{
    margin-top:20px;
    padding-top:17px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.btn{
    min-height:40px;
    padding:0 14px;
    border:0;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    cursor:pointer;
    font-size:8px;
    font-weight:800;
}

.btn-primary{
    background:linear-gradient(135deg,var(--blue),var(--blue2));
    color:white;
}

.btn-light{
    border:1px solid var(--border);
    background:white;
    color:#627084;
}

/* EMPTY */

.empty{
    padding:45px 20px;
    text-align:center;
    color:#8a96a5;
}

.empty i{
    margin-bottom:10px;
    color:#b7c1cd;
    font-size:28px;
}

.empty strong{
    display:block;
    color:var(--navy);
    font-size:12px;
}

.empty p{
    margin-top:5px;
    font-size:8px;
}

.pdf-import-btn{min-height:42px;padding:0 16px;border:0;border-radius:9px;background:#0b8f55;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:9px;font-weight:800;cursor:pointer;box-shadow:0 8px 22px rgba(11,143,85,.18)}
.page-head-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.pdf-import-box{display:none;margin:0 0 18px;padding:16px;border:1px solid #dbe7e1;border-radius:12px;background:#f8fffb}
.pdf-import-box.open{display:block}.pdf-import-box h3{font-family:'Manrope',sans-serif;color:var(--navy);font-size:14px;margin-bottom:5px}.pdf-import-box p{font-size:9px;color:var(--muted);line-height:1.6;margin-bottom:12px}.pdf-import-row{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.pdf-import-row input[type=file]{flex:1;min-width:250px;border:1px solid var(--border);border-radius:9px;background:#fff;padding:10px;font-size:9px}.pdf-note{margin-top:9px;font-size:8px;color:#718096}.pdf-note strong{color:#0b8f55}

/* RESPONSIVE */

@media(max-width:1100px){
    .stats{
        grid-template-columns:repeat(3,1fr);
    }

    .filters{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:850px){
    .layout{
        grid-template-columns:1fr;
    }

    .sidebar{
        display:none;
    }

    .main{
        padding:18px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .itinerary-day-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:600px){
    .header{
        padding:0 13px;
    }

    .admin-user div:last-child{
        display:none;
    }

    .main{
        padding:13px;
    }

    .page-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .stats{
        grid-template-columns:1fr 1fr;
    }

    .filters{
        grid-template-columns:1fr;
    }
}


/* ==========================================================================
   ADMIN READABILITY UPGRADE
   Clear default text sizing for laptop, desktop and mobile.
   Functionality, icons and package-management logic remain unchanged.
   ========================================================================== */

html{
    font-size:16px;
    -webkit-text-size-adjust:100%;
}

body{
    font-size:14px;
    line-height:1.5;
}

.admin-user strong{font-size:13px;}
.admin-user small{font-size:11px;}

.menu-title{
    font-size:10px;
    letter-spacing:.9px;
}

.sidebar a{
    font-size:13px;
}

.page-head h1{
    font-size:27px;
}

.page-head p{
    font-size:13px;
    line-height:1.55;
}

.add-btn{
    font-size:12px;
}

.alert{
    font-size:13px;
    line-height:1.5;
}

.stat span{
    font-size:12px;
}

.card-head h2{
    font-size:16px;
}

.card-head p{
    font-size:12px;
}

.control,
.form-control{
    font-size:13px;
}

.filter-btn,
.btn,
.add-day-btn,
.manage-gallery-btn{
    font-size:12px;
}

th{
    font-size:10px;
    letter-spacing:.45px;
}

td{
    font-size:12px;
    line-height:1.5;
}

.package-cell strong{
    font-size:13px;
    line-height:1.4;
}

.package-cell span{
    font-size:11px;
}

.price{
    font-size:13px;
}

.badge{
    font-size:10px;
}

.mini-tag{
    font-size:9px;
}

.icon-btn{
    font-size:12px;
}

.form-group label{
    font-size:12px;
}

textarea.form-control{
    line-height:1.65;
}

.help{
    font-size:11px;
    line-height:1.5;
}

.check-card{
    font-size:12px;
}

.existing-gallery-title{
    font-size:13px;
}

.existing-gallery-title span{
    font-size:11px;
}

.itinerary-builder-head strong,
.day-label{
    font-size:13px;
}

.remove-image-label{
    font-size:11px !important;
}

.empty strong{
    font-size:15px;
}

.empty p{
    font-size:12px;
}

/* Keep symbols/icons at their existing visual scale where practical. */
.sidebar a i,
.add-btn i,
.filter-btn i,
.btn i,
.alert i{
    font-size:1em;
}

@media(max-width:600px){
    body{font-size:14px;}
    .page-head h1{font-size:23px;}
    .control,.form-control{font-size:14px;}
}



.media-picker-row{display:flex;gap:9px;flex-wrap:wrap}
.open-media-picker{min-height:42px;padding:0 14px;border:0;border-radius:9px;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
.open-media-picker.secondary{background:#fff;color:var(--blue);border:1px solid #bdd8fa}
.media-selected{margin-top:10px;padding:10px 12px;border:1px solid #cfe0f7;border-radius:9px;background:#f7fbff;display:flex;align-items:center;gap:9px;font-size:11px}
.media-selected[hidden]{display:none}.media-selected img{width:58px;height:42px;object-fit:cover;border-radius:6px}.media-selected span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.media-selected button{border:0;border-radius:6px;padding:6px 9px;background:#fff0f2;color:var(--red);font-size:10px;font-weight:800;cursor:pointer}

.media-modal{position:fixed;inset:0;z-index:5000;display:none;align-items:center;justify-content:center;padding:22px}
.media-modal.open{display:flex}.media-modal-backdrop{position:absolute;inset:0;background:rgba(7,25,45,.68);backdrop-filter:blur(3px)}
.media-modal-dialog{position:relative;width:min(1050px,96vw);max-height:90vh;display:flex;flex-direction:column;background:#fff;border-radius:16px;box-shadow:0 30px 90px rgba(0,0,0,.25);overflow:hidden}
.media-modal-head{padding:17px 19px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:15px}
.media-modal-head h3{font-family:'Manrope',sans-serif;font-size:18px;color:var(--navy)}.media-modal-head p{margin-top:3px;font-size:11px;color:var(--muted)}
.media-modal-close{width:37px;height:37px;border:1px solid var(--border);border-radius:9px;background:#fff;color:#68788b;cursor:pointer;font-size:15px}
.media-modal-search{margin:14px 16px 0;height:44px;padding:0 12px;border:1px solid #dbe4ed;border-radius:10px;display:flex;align-items:center;gap:9px;background:#f9fbfd}
.media-modal-search i{color:#8c99a9}.media-modal-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font-size:13px}.media-modal-search button{border:0;background:transparent;color:var(--blue);font-size:11px;font-weight:800;cursor:pointer}
.media-modal-grid{padding:14px 16px 18px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:11px}
.media-popup-item{padding:7px;border:1px solid var(--border);border-radius:10px;background:#fff}.media-popup-item.hide{display:none}.media-popup-item.selected{border-color:var(--blue);box-shadow:0 0 0 2px rgba(11,116,255,.09)}
.media-popup-item img{width:100%;height:115px;display:block;object-fit:cover;border-radius:7px;background:#eef3f8}.media-popup-name{margin:7px 1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#5d6c7f;font-size:10px}
.media-popup-select{width:100%;min-height:33px;border:1px solid #cfe0f7;border-radius:7px;background:#f4f8ff;color:var(--blue);font-size:10px;font-weight:800;cursor:pointer}
.media-popup-item.selected .media-popup-select{background:var(--blue);color:#fff;border-color:var(--blue)}
.media-no-result,.media-empty{padding:35px;text-align:center;color:var(--muted);font-size:12px}
.media-modal-foot{padding:12px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;background:#f8fafc;font-size:11px}.media-modal-foot[hidden]{display:none}
.media-done-btn{min-height:36px;padding:0 14px;border:0;border-radius:8px;background:var(--navy);color:#fff;font-size:11px;font-weight:800;cursor:pointer}
body.media-modal-open{overflow:hidden}
@media(max-width:650px){.media-modal{padding:8px}.media-modal-dialog{width:100%;max-height:94vh}.media-modal-grid{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px}.media-popup-item img{height:105px}.media-picker-row{flex-direction:column}.open-media-picker{justify-content:center;width:100%}}


.native-main-image-upload{display:none!important}
.main-image-gallery-launch{width:100%;min-height:46px;border:1px dashed #9fc8fa;border-radius:10px;background:#f4f9ff;color:var(--blue);font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
.main-image-gallery-launch:hover{background:#eaf4ff;border-color:var(--blue)}
.main-image-choice-note{margin-top:6px;color:var(--muted);font-size:10px;line-height:1.45}
.media-modal-head-actions{display:flex;align-items:center;gap:8px}
.media-upload-new{min-height:37px;padding:0 11px;border:0;border-radius:8px;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;font-size:10px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px}
@media(max-width:560px){.media-upload-new{padding:0 8px}.media-upload-new{font-size:9px}}

.media-source-bar{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding:14px 16px 0}.media-source-btn{min-height:43px;border:1px solid #cfe0f7;border-radius:9px;background:#f5f9ff;color:var(--blue);font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px}.media-source-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}.native-gallery-image-upload{display:none!important}.gallery-image-gallery-launch{margin-top:7px}
/* ITINERARY IMAGE PICKER */
.native-itinerary-image-upload{display:none!important}
.itinerary-image-gallery-launch{
    width:100%;
    min-height:42px;
    margin-top:7px;
    border:1px dashed #9fc8fa;
    border-radius:9px;
    background:#f4f9ff;
    color:var(--blue);
    font-size:11px;
    font-weight:800;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
}
.itinerary-image-gallery-launch:hover{
    background:#eaf4ff;
    border-color:var(--blue);
}


/* FINAL IMAGE PICKER CLEANUP */
.native-main-image-upload,
.native-gallery-image-upload,
.native-itinerary-image-upload,
input[type="file"].itinerary-image-input{
    display:none!important;
}

.chosen-image-panel{
    margin-top:10px;
    padding:10px;
    border:1px solid #cfe2fa;
    border-radius:10px;
    background:#f7fbff;
}
.chosen-image-title{
    margin-bottom:8px;
    font-size:10px;
    font-weight:800;
    color:#0872df;
}
.chosen-image-items{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.chosen-image-thumb{
    position:relative;
    width:94px;
}
.chosen-image-thumb img{
    display:block;
    width:94px;
    height:68px;
    object-fit:cover;
    border:1px solid #d9e4ef;
    border-radius:8px;
    background:#fff;
}
.chosen-image-thumb span{
    display:block;
    margin-top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:8px;
    color:#667487;
}

/* Hide accidental duplicate PC/source controls if the old and new picker
   implementations are both present. JS marks extras with this class. */
.duplicate-media-source{
    display:none!important;
}




/* ================================================================
   DATE-WISE HOTEL CATEGORY PRICING
================================================================ */

.season-price-builder{
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:13px;
    background:#fff;
}

.season-price-head{
    padding:15px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    background:linear-gradient(135deg,#f7fbff,#fff);
}

.season-price-head h3{
    color:var(--navy);
    font:800 14px 'Manrope',sans-serif;
}

.season-price-head h3 i{
    margin-right:7px;
    color:var(--blue);
}

.season-price-head p{
    margin-top:4px;
    color:var(--muted);
    font-size:9px;
    line-height:1.5;
}

.add-price-row-btn{
    min-height:38px;
    padding:0 13px;
    border:0;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    background:var(--blue);
    color:#fff;
    font-size:9px;
    font-weight:800;
    cursor:pointer;
}

.season-price-table-wrap{
    padding:13px;
    overflow-x:auto;
}

.season-price-table-head,
.package-price-row{
    min-width:880px;
    display:grid;
    grid-template-columns:
        1.1fr 1.1fr 1fr 1fr 1fr 70px;
    gap:9px;
    align-items:center;
}

.season-price-table-head{
    padding:0 4px 8px;
    color:#788598;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
}

.package-price-rows{
    display:grid;
    gap:9px;
}

.package-price-row{
    padding:10px;
    border:1px solid #e5ebf1;
    border-radius:10px;
    background:#fafcfe;
}

.package-price-row .form-control{
    min-width:0;
}

.remove-price-row-btn{
    width:38px;
    height:38px;
    margin:auto;
    border:1px solid #f1c5cb;
    border-radius:8px;
    display:grid;
    place-items:center;
    background:#fff1f3;
    color:var(--red);
    cursor:pointer;
}

.pricing-help{
    padding:11px 14px;
    border-top:1px solid var(--border);
    background:#f8fafc;
    color:#748194;
    font-size:9px;
    line-height:1.55;
}

@media(max-width:700px){
    .season-price-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .add-price-row-btn{
        width:100%;
    }
}


/* ================================================================
   PEXELS ROYALTY-FREE PHOTO SEARCH
================================================================ */

.pexels-launch-button{
    min-height:42px;
    margin-left:7px;
    padding:0 14px;
    border:0;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    background:linear-gradient(135deg,#07885f,#0aa66a);
    color:#fff;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}

.pexels-launch-button.secondary{
    border:1px solid #bde7d6;
    background:#effaf5;
    color:#087a55;
}

.pexels-modal{
    position:fixed;
    inset:0;
    z-index:7000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.pexels-modal.open{
    display:flex;
}

.pexels-modal-backdrop{
    position:absolute;
    inset:0;
    background:rgba(7,25,45,.72);
    backdrop-filter:blur(4px);
}

.pexels-modal-dialog{
    position:relative;
    width:min(1120px,97vw);
    max-height:92vh;
    overflow:hidden;
    border-radius:17px;
    display:flex;
    flex-direction:column;
    background:#fff;
    box-shadow:0 30px 90px rgba(0,0,0,.27);
}

.pexels-modal-head{
    padding:19px 21px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
}

.pexels-modal-head h3{
    color:var(--navy);
    font:800 18px 'Manrope',sans-serif;
}

.pexels-modal-head p{
    margin-top:4px;
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
}

.pexels-close{
    width:39px;
    height:39px;
    flex:0 0 39px;
    border:1px solid var(--border);
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#fff;
    color:#627084;
    cursor:pointer;
}

.pexels-search-row{
    padding:16px 20px 10px;
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:10px;
}

.pexels-search-input{
    height:46px;
    padding:0 13px;
    border:1px solid var(--border);
    border-radius:10px;
    display:flex;
    align-items:center;
    gap:9px;
    background:#fff;
}

.pexels-search-input i{
    color:var(--blue);
}

.pexels-search-input input{
    width:100%;
    border:0;
    outline:0;
    color:#344257;
    font-size:14px;
}

.pexels-search-button{
    min-height:46px;
    padding:0 17px;
    border:0;
    border-radius:10px;
    background:var(--navy);
    color:#fff;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}

.pexels-policy-note{
    margin:0 20px 10px;
    padding:10px 12px;
    border:1px solid #cceadd;
    border-radius:9px;
    display:flex;
    align-items:flex-start;
    gap:8px;
    background:#f0faf6;
    color:#33715c;
    font-size:11px;
    line-height:1.5;
}

.pexels-status{
    margin:0 20px 10px;
    padding:11px 13px;
    border-radius:9px;
    background:#edf5ff;
    color:#285f9c;
    font-size:12px;
}

.pexels-status.error{
    border:1px solid #f1c7cd;
    background:#fff0f2;
    color:#b43748;
}

.pexels-results{
    min-height:260px;
    padding:14px 14px 22px;
    overflow-x:hidden;
    overflow-y:auto;
    display:grid !important;
    grid-template-columns:repeat(6,minmax(0,1fr)) !important;
    grid-auto-flow:row !important;
    grid-auto-rows:auto !important;
    align-items:start !important;
    align-content:start !important;
    gap:12px !important;
}

.pexels-empty{
    grid-column:1/-1;
    padding:60px 20px;
    text-align:center;
    color:var(--muted);
}

.pexels-empty i{
    display:block;
    margin-bottom:10px;
    font-size:34px;
    color:#aab5c2;
}

.pexels-photo-card{
    position:relative !important;
    inset:auto !important;
    width:100% !important;
    min-width:0 !important;
    max-width:none !important;
    height:auto !important;
    margin:0 !important;
    padding:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    align-self:start !important;
    isolation:isolate !important;
    contain:layout paint !important;
    border:1px solid var(--border) !important;
    border-radius:11px !important;
    background:#fff !important;
    box-shadow:0 7px 20px rgba(7,25,45,.07) !important;
    cursor:pointer;
    transition:transform .2s,border-color .2s,box-shadow .2s;
}

.pexels-photo-card:hover,
.pexels-photo-card:focus{
    transform:translateY(-2px);
    border-color:#8cc0ff !important;
    box-shadow:0 12px 28px rgba(7,25,45,.12) !important;
    outline:none;
}

.pexels-photo-card.selected{
    border:3px solid #07885f !important;
    box-shadow:0 0 0 3px rgba(7,136,95,.12) !important;
}

.free-photo-provider-badge{position:absolute;top:9px;left:9px;z-index:4;padding:5px 8px;border-radius:999px;background:rgba(15,23,42,.84);color:#fff;font-size:10px;font-weight:900;letter-spacing:.04em;box-shadow:0 3px 10px rgba(15,23,42,.18)}
.pexels-photo-image{
    position:relative !important;
    inset:auto !important;
    width:100% !important;
    height:auto !important;
    min-height:0 !important;
    aspect-ratio:4 / 3 !important;
    overflow:hidden !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    flex:0 0 auto !important;
    background:#edf2f7 !important;
    cursor:pointer;
}

.pexels-photo-image img{
    position:static !important;
    inset:auto !important;
    float:none !important;
    clear:both !important;
    display:block !important;
    width:100% !important;
    height:100% !important;
    min-width:0 !important;
    max-width:100% !important;
    min-height:0 !important;
    max-height:none !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
    object-fit:contain !important;
    object-position:center center !important;
    transform:none !important;
    background:#edf2f7 !important;
}

.pexels-photo-image::after{
    content:"Click photo to select";
    position:absolute;
    left:10px;
    right:10px;
    bottom:10px;
    padding:8px 10px;
    border-radius:8px;
    background:rgba(7,25,45,.84);
    color:#fff;
    text-align:center;
    font-size:11px;
    font-weight:800;
    opacity:0;
    transform:translateY(5px);
    transition:.2s;
}

.pexels-photo-card:hover .pexels-photo-image::after{
    opacity:1;
    transform:translateY(0);
}

.pexels-photo-body{
    position:relative !important;
    width:100% !important;
    height:auto !important;
    padding:9px !important;
    display:flex !important;
    flex-direction:column !important;
    flex:1 1 auto !important;
    background:#fff !important;
}

.pexels-photo-credit{
    display:block !important;
    width:100% !important;
    margin:8px 0 0 !important;
    padding:0 10px 8px !important;
    text-align:right !important;
    color:#5f6b7a !important;
    font-size:9px !important;
    line-height:1.4 !important;
    visibility:visible !important;
    opacity:1 !important;
}

.pexels-photo-credit-line{
    display:block !important;
    width:100% !important;
    text-align:right !important;
    white-space:normal !important;
}

.pexels-photo-credit a{
    display:inline !important;
    color:#0b74ff !important;
    font-weight:700 !important;
    text-decoration:none !important;
}

.pexels-photo-credit a:hover{
    text-decoration:underline !important;
}

.pexels-photo-name{
    min-height:35px;
    margin-bottom:3px;
    color:var(--navy);
    font-size:11px;
    font-weight:800;
    line-height:1.4;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
    word-break:break-word;
}



.pexels-import-button{
    width:100%;
    min-height:36px;
    margin-top:10px;
    border:0;
    border-radius:8px;
    background:linear-gradient(135deg,#07885f,#0aa66a);
    color:#fff;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}

.pexels-import-button:disabled{
    opacity:.65;
    cursor:wait;
}

.pexels-pagination{
    padding:12px 20px;
    border-top:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:15px;
    color:#627084;
    font-size:12px;
}

.pexels-page-button{
    min-height:36px;
    padding:0 12px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:var(--navy);
    font-size:11px;
    font-weight:800;
    cursor:pointer;
}

.pexels-page-button:disabled{
    opacity:.45;
    cursor:not-allowed;
}

.pexels-modal-foot{
    padding:13px 20px;
    border-top:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    background:#fafbfd;
}

.pexels-modal-foot a{
    color:#637286;
    font-size:11px;
    font-weight:700;
}

.pexels-done-button{
    min-height:38px;
    padding:0 15px;
    border:0;
    border-radius:8px;
    background:var(--blue);
    color:#fff;
    font-size:11px;
    font-weight:800;
    cursor:pointer;
}

.pexels-attribution-chip{
    margin-top:7px;
    padding:7px 9px;
    border:1px solid #cceadd;
    border-radius:7px;
    background:#f0faf6;
    color:#33715c;
    font-size:10px;
    line-height:1.45;
}

body.pexels-modal-open{
    overflow:hidden;
}

@media(max-width:1100px){
    .pexels-results{
        grid-template-columns:repeat(5,minmax(0,1fr)) !important;
    }
}

@media(max-width:900px){
    .pexels-results{
        grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    }
}

@media(max-width:700px){
    .itinerary-builder-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .itinerary-auto-note{
        width:100%;
    }

    .itinerary-days{
        max-height:560px;
    }
}

@media(max-width:700px){
    .pexels-modal{
        padding:8px;
    }

    .pexels-modal-dialog{
        max-height:96vh;
    }

    .pexels-search-row{
        grid-template-columns:1fr;
    }

    .pexels-results{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
        padding-left:10px;
        padding-right:10px;
        gap:10px !important;
    }
}

@media(max-width:430px){
    .pexels-results{
        grid-template-columns:1fr !important;
    }

    .pexels-modal-foot{
        align-items:flex-start;
        flex-direction:column;
    }

    .pexels-done-button{
        width:100%;
    }

    .pexels-launch-button{
        width:100%;
        margin:8px 0 0;
    }
}



/* ============================================================
   TRAVSCOPE UNIFIED PROFESSIONAL ADMIN UI
   Visual-only override: colors, typography, spacing, forms,
   cards, tables and responsive presentation.
============================================================ */
:root{
    --ts-navy:#0f172a;
    --ts-navy-2:#111c32;
    --ts-blue:#2563eb;
    --ts-blue-hover:#1d4ed8;
    --ts-blue-soft:#eff6ff;
    --ts-cyan:#0ea5e9;
    --ts-bg:#f4f7fb;
    --ts-surface:#ffffff;
    --ts-soft:#f8fafc;
    --ts-text:#1e293b;
    --ts-muted:#64748b;
    --ts-border:#e2e8f0;
    --ts-border-strong:#cbd5e1;
    --ts-success:#15803d;
    --ts-success-soft:#ecfdf3;
    --ts-warning:#b45309;
    --ts-warning-soft:#fff7ed;
    --ts-danger:#b91c1c;
    --ts-danger-soft:#fef2f2;
    --ts-radius:10px;
    --ts-shadow:0 6px 18px rgba(15,23,42,.055);
    --ts-shadow-hover:0 10px 26px rgba(15,23,42,.085);
    --ts-focus:0 0 0 3px rgba(37,99,235,.14);

    --navy:var(--ts-navy);
    --blue:var(--ts-blue);
    --cyan:var(--ts-cyan);
    --bg:var(--ts-bg);
    --white:var(--ts-surface);
    --text:var(--ts-text);
    --muted:var(--ts-muted);
    --border:var(--ts-border);
    --green:var(--ts-success);
    --orange:var(--ts-warning);
    --red:var(--ts-danger);
    --shadow:var(--ts-shadow);
}

html{-webkit-text-size-adjust:100%;text-rendering:optimizeLegibility}
body{
    background:var(--ts-bg)!important;
    color:var(--ts-text)!important;
    font-family:"DM Sans",Arial,Helvetica,sans-serif!important;
    font-size:14px!important;
    line-height:1.5!important;
}
button,input,select,textarea{font:inherit}
a,button,input,select,textarea{-webkit-tap-highlight-color:transparent}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{
    outline:none!important;
    box-shadow:var(--ts-focus)!important;
}

/* Side navigation */
.sidebar,.admin-sidebar,.odisha-style-sidebar{
    background:linear-gradient(180deg,var(--ts-navy) 0%,var(--ts-navy-2) 100%)!important;
    color:#fff!important;
}
.sidebar a,.admin-sidebar a,.odisha-style-nav-link,.menu-link{
    border-radius:8px!important;
    font-size:12px!important;
    font-weight:700!important;
}
.sidebar a.active,.admin-sidebar a.active,.odisha-style-nav-link.active,.menu-link.active{
    background:linear-gradient(135deg,var(--ts-blue),#3b82f6)!important;
    color:#fff!important;
    box-shadow:0 5px 14px rgba(37,99,235,.22)!important;
}
.menu-title,.sidebar-title,.odisha-style-menu-label{
    color:#64748b!important;
    font-size:10px!important;
    font-weight:800!important;
    letter-spacing:.9px!important;
}

/* Header/topbar */
.header,.topbar,.admin-header{
    background:rgba(255,255,255,.97)!important;
    border-bottom:1px solid var(--ts-border)!important;
    box-shadow:0 1px 0 rgba(15,23,42,.02)!important;
}
.logo,.topbar-title,.odisha-style-topbar-title{
    color:var(--ts-navy)!important;
    font-weight:800!important;
}
.admin strong,.header-user strong{font-size:13px!important}
.admin small,.header-user span{font-size:11px!important;color:var(--ts-muted)!important}

/* Main headings */
.page-head h1,.page-heading h1,.main h1{
    color:var(--ts-navy)!important;
    font-family:"Manrope","DM Sans",Arial,sans-serif!important;
    font-size:26px!important;
    line-height:1.15!important;
    font-weight:800!important;
    letter-spacing:-.55px!important;
}
.page-head p,.page-heading p{
    color:var(--ts-muted)!important;
    font-size:12px!important;
}

/* Cards */
.card,.panel,.customer-card,.stat,.stat-card,.profile-manager,.booking-card{
    border:1px solid var(--ts-border)!important;
    border-radius:var(--ts-radius)!important;
    background:#fff!important;
    box-shadow:var(--ts-shadow)!important;
}
.card-head,.card-header,.section-header{
    border-bottom:1px solid var(--ts-border)!important;
    background:#fff!important;
}
.card-head h2,.card-head h3,.card-header h2,.card-header h3,.section-title h2{
    color:var(--ts-navy)!important;
    font-family:"Manrope","DM Sans",Arial,sans-serif!important;
    font-size:15px!important;
    font-weight:800!important;
}
.card-head p,.card-header p,.section-title p{
    color:var(--ts-muted)!important;
    font-size:10px!important;
}

/* Statistics */
.stat span,.stat-card span,.metric span{
    color:var(--ts-muted)!important;
    font-size:10px!important;
    font-weight:800!important;
}
.stat strong,.stat-card strong{
    color:var(--ts-navy)!important;
    font-size:21px!important;
    font-weight:800!important;
}
.metric strong{font-size:13px!important}

/* Forms */
label,.field label,.form-group label,.admin-label{
    color:#334155!important;
    font-size:11px!important;
    font-weight:700!important;
}
input:not([type="checkbox"]):not([type="radio"]),
select,textarea,.control,.form-control,.admin-field,.pm-control{
    border:1px solid var(--ts-border-strong)!important;
    border-radius:7px!important;
    background:#fff!important;
    color:var(--ts-text)!important;
    font-size:13px!important;
}
input:not([type="checkbox"]):not([type="radio"]):focus,
select:focus,textarea:focus,.control:focus,.form-control:focus,.admin-field:focus,.pm-control:focus{
    border-color:#60a5fa!important;
    box-shadow:var(--ts-focus)!important;
    outline:none!important;
}
input::placeholder,textarea::placeholder{color:#94a3b8!important}

/* Buttons */
.btn,.button,.filter-btn,.refresh-btn,.save-top,.profile-save,.pm-save,.action,.view-button,.page-button,.page-btn{
    border-radius:7px!important;
    font-size:11px!important;
    font-weight:800!important;
}
.btn-primary,.button.blue,.profile-save,.pm-save,.filter-btn{
    background:var(--ts-blue)!important;
    border-color:var(--ts-blue)!important;
    color:#fff!important;
}
.btn-primary:hover,.button.blue:hover,.profile-save:hover,.pm-save:hover,.filter-btn:hover{
    background:var(--ts-blue-hover)!important;
    border-color:var(--ts-blue-hover)!important;
}
.btn-secondary,.button.light{
    background:#fff!important;
    border-color:var(--ts-border)!important;
    color:#475569!important;
}

/* Tables */
table{
    color:var(--ts-text)!important;
    font-size:11px!important;
}
th{
    background:var(--ts-soft)!important;
    color:#64748b!important;
    border-bottom:1px solid var(--ts-border)!important;
    font-size:10px!important;
    font-weight:800!important;
    letter-spacing:.45px!important;
}
td{
    border-top:1px solid #eef2f6!important;
    font-size:11px!important;
}
tbody tr:hover{background:#fbfdff!important}

/* Badges/status */
.badge,.status,.status-badge,.mini-status{
    border-radius:999px!important;
    font-size:10px!important;
    font-weight:800!important;
}
.alert{
    border-radius:8px!important;
    font-size:12px!important;
}
.alert-success{
    background:var(--ts-success-soft)!important;
    border-color:#bbf7d0!important;
    color:#166534!important;
}
.alert-error{
    background:var(--ts-danger-soft)!important;
    border-color:#fecaca!important;
    color:#991b1b!important;
}

/* Compact spacing */
.main,.content,.page{min-width:0}
.card-body,.section-body{padding:14px!important}
.form-grid,.filters,.info-grid{gap:10px!important}

/* Pagination */
.pagination{gap:5px!important}
.page-btn,.page-button{
    min-width:34px!important;
    height:34px!important;
    background:#fff!important;
    border:1px solid var(--ts-border)!important;
    color:#475569!important;
}
.page-btn.active,.page-button.active{
    background:var(--ts-blue)!important;
    border-color:var(--ts-blue)!important;
    color:#fff!important;
}

/* Responsive */
@media(max-width:900px){
    .page-head h1,.page-heading h1,.main h1{font-size:23px!important}
}
@media(max-width:700px){
    body{font-size:14px!important}
    .page-head h1,.page-heading h1,.main h1{font-size:22px!important}
    input:not([type="checkbox"]):not([type="radio"]),select,textarea,.control,.form-control,.admin-field,.pm-control{
        min-height:42px!important;
        font-size:14px!important;
    }
    .btn,.button,.filter-btn,.refresh-btn,.save-top,.profile-save,.pm-save,.action,.view-button{
        min-height:38px!important;
        font-size:11px!important;
    }
    .card-body,.section-body{padding:12px!important}
}



/* ============================================================
   PACKAGE MANAGEMENT — GRAPHICAL PROFESSIONAL ONE-PAGE VIEW
   VISUAL / SPACE MANAGEMENT ONLY. ALL EXISTING LOGIC INTACT.
============================================================ */

/* Main workspace */
.main{
    padding:15px 17px 18px !important;
}

.page-head{
    margin-bottom:10px !important;
    align-items:center !important;
}

.page-head h1{
    font-size:25px !important;
    line-height:1.15 !important;
}

.page-head p{
    margin-top:3px !important;
    font-size:11px !important;
    line-height:1.4 !important;
}

.add-btn{
    min-height:36px !important;
    padding:0 12px !important;
    border-radius:8px !important;
    font-size:10px !important;
}

/* KPI cards */
.stats{
    grid-template-columns:repeat(5,minmax(0,1fr)) !important;
    gap:8px !important;
    margin-bottom:9px !important;
}

.stat{
    min-height:80px !important;
    padding:10px 12px !important;
    border-radius:10px !important;
    position:relative;
    overflow:hidden;
    box-shadow:0 5px 16px rgba(15,23,42,.045) !important;
}

.stat:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:4px;
}

.stat:nth-child(1){
    background:linear-gradient(135deg,#fff,#eff6ff) !important;
    border-color:#dce9fb !important;
}
.stat:nth-child(1):before{background:#2563eb}
.stat:nth-child(1) .stat-icon{background:#dbeafe !important;color:#2563eb !important}

.stat:nth-child(2){
    background:linear-gradient(135deg,#fff,#ecfdf5) !important;
    border-color:#d8f1e5 !important;
}
.stat:nth-child(2):before{background:#0f9a68}
.stat:nth-child(2) .stat-icon{background:#d1fae5 !important;color:#059669 !important}

.stat:nth-child(3){
    background:linear-gradient(135deg,#fff,#fff7ed) !important;
    border-color:#f4e3ce !important;
}
.stat:nth-child(3):before{background:#e58b2b}
.stat:nth-child(3) .stat-icon{background:#ffedd5 !important;color:#d97706 !important}

.stat:nth-child(4){
    background:linear-gradient(135deg,#fff,#f5f3ff) !important;
    border-color:#e7e0fb !important;
}
.stat:nth-child(4):before{background:#7c5bd1}
.stat:nth-child(4) .stat-icon{background:#ede9fe !important;color:#7c3aed !important}

.stat:nth-child(5){
    background:linear-gradient(135deg,#fff,#eefbff) !important;
    border-color:#d9eef5 !important;
}
.stat:nth-child(5):before{background:#0d8eaf}
.stat:nth-child(5) .stat-icon{background:#cffafe !important;color:#0891b2 !important}

.stat-icon{
    width:31px !important;
    height:31px !important;
    border-radius:8px !important;
    font-size:12px !important;
}

.stat strong{
    margin-top:6px !important;
    font-size:19px !important;
    line-height:1 !important;
}

.stat span{
    margin-top:3px !important;
    font-size:10px !important;
}

/* General card density */
.card{
    margin-bottom:9px !important;
    border-radius:10px !important;
    box-shadow:0 5px 16px rgba(15,23,42,.045) !important;
}

.card-head{
    min-height:46px !important;
    padding:8px 12px !important;
}

.card-head h2{
    font-size:14px !important;
}

.card-head p{
    margin-top:2px !important;
    font-size:10px !important;
}

.card-body{
    padding:10px 12px !important;
}

/* Compact package filters */
.filters{
    grid-template-columns:minmax(280px,2fr) repeat(3,minmax(150px,1fr)) auto !important;
    gap:6px !important;
}

.control{
    height:36px !important;
    min-height:36px !important;
    padding:0 9px !important;
    border-radius:7px !important;
    font-size:11px !important;
}

.filter-btn{
    height:36px !important;
    min-height:36px !important;
    padding:0 12px !important;
    border-radius:7px !important;
    background:linear-gradient(135deg,#2563eb,#3b82f6) !important;
    font-size:10px !important;
    box-shadow:0 4px 10px rgba(37,99,235,.14);
}

/* Package table - one-screen management style */
table{
    min-width:900px !important;
}

th{
    padding:7px 8px !important;
    font-size:9px !important;
}

td{
    padding:7px 8px !important;
    font-size:10px !important;
    line-height:1.3 !important;
}

.package-cell{
    gap:8px !important;
}

.package-thumb{
    width:48px !important;
    height:38px !important;
    flex:0 0 48px !important;
    border-radius:7px !important;
}

.package-cell strong{
    max-width:250px !important;
    font-size:10px !important;
}

.package-cell span{
    margin-top:2px !important;
    font-size:8px !important;
}

.badge{
    padding:4px 7px !important;
    font-size:8px !important;
}

.small-tags{
    margin-top:3px !important;
    gap:3px !important;
}

.mini-tag{
    padding:2px 5px !important;
    font-size:8px !important;
}

.actions{
    gap:4px !important;
    flex-wrap:nowrap !important;
}

.icon-btn{
    width:29px !important;
    height:29px !important;
    border-radius:6px !important;
    font-size:9px !important;
}

/* Add/Edit Package editor — efficient desktop workspace */
.form-grid{
    grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    gap:9px 11px !important;
}

.form-group.full{
    grid-column:1/-1 !important;
}

.form-group label{
    margin-bottom:4px !important;
    font-size:10px !important;
}

.form-control{
    height:37px !important;
    min-height:37px !important;
    padding:0 9px !important;
    border-radius:7px !important;
    font-size:11px !important;
}

textarea.form-control{
    height:78px !important;
    min-height:78px !important;
    padding:8px 9px !important;
    line-height:1.45 !important;
}

textarea.large{
    height:100px !important;
    min-height:100px !important;
}

.help{
    margin-top:3px !important;
    font-size:9px !important;
    line-height:1.35 !important;
}

.switch-row{
    gap:7px !important;
}

.check-card{
    padding:7px 9px !important;
    border-radius:7px !important;
    font-size:10px !important;
}

/* Media / gallery */
.current-image{
    width:120px !important;
    height:76px !important;
    margin-top:7px !important;
}

.multi-image-preview{
    margin-top:7px !important;
    grid-template-columns:repeat(auto-fill,minmax(90px,1fr)) !important;
    gap:6px !important;
}

.multi-preview-item,
.existing-gallery-item{
    height:72px !important;
    border-radius:7px !important;
}

.existing-gallery{
    margin-top:9px !important;
    padding:9px !important;
    border-radius:8px !important;
}

.existing-gallery-title{
    margin-bottom:7px !important;
}

/* Child pricing builder */
.child-price-builder{
    padding:10px !important;
    border-radius:9px !important;
}

.child-price-head{
    margin-bottom:8px !important;
}

.child-price-head h3{
    gap:7px !important;
    font-size:14px !important;
}

.child-price-head h3 i{
    width:29px !important;
    height:29px !important;
    border-radius:7px !important;
}

.child-price-head p{
    margin-top:3px !important;
    font-size:10px !important;
    line-height:1.4 !important;
}

.child-price-grid{
    grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    gap:7px !important;
}

/* Common dynamic builder sections */
.itinerary-row,
.price-row,
.pricing-row,
.date-price-row,
.gallery-row,
.builder-row{
    padding:9px !important;
    margin-bottom:7px !important;
    border-radius:8px !important;
}

.itinerary-row .form-grid,
.price-row .form-grid,
.pricing-row .form-grid,
.date-price-row .form-grid{
    gap:7px !important;
}

/* Buttons in package editor */
.card button,
.card .btn,
.card .button,
.card .save-btn,
.card .secondary-btn{
    border-radius:7px !important;
}

/* Desktop one-page feeling: reduce unnecessary vertical gaps */
@media(min-width:1200px){
    .main{
        padding-top:13px !important;
    }

    .page-head{
        margin-bottom:8px !important;
    }

    .stats{
        margin-bottom:8px !important;
    }

    .card{
        margin-bottom:8px !important;
    }
}

/* Tablet */
@media(max-width:1120px){
    .stats{
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    }

    .filters{
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    }

    .form-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}

/* Mobile */
@media(max-width:760px){
    .main{
        padding:10px 8px 16px !important;
    }

    .page-head{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .page-head h1{
        font-size:22px !important;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .filters,
    .form-grid,
    .child-price-grid{
        grid-template-columns:1fr !important;
    }

    .form-group.full{
        grid-column:auto !important;
    }

    .control,
    .filter-btn,
    .form-control{
        min-height:41px !important;
        height:41px !important;
        font-size:13px !important;
    }

    textarea.form-control,
    textarea.large{
        height:92px !important;
        min-height:92px !important;
    }
}

@media(max-width:450px){
    .stats{
        grid-template-columns:1fr !important;
    }
}



/* ============================================================
   PACKAGE ADD / EDIT — TRUE ONE-SCREEN MANAGEMENT WORKSPACE
   Visual/navigation only. Existing form fields and PHP logic intact.
============================================================ */

body.package-editor-mode{
    overflow:hidden !important;
}

body.package-editor-mode .main{
    height:calc(100vh - 70px) !important;
    overflow:hidden !important;
    padding:10px 14px !important;
}

/* Hide list-management content while Add/Edit workspace is active */
body.package-editor-mode .main > .pdf-import-box,
body.package-editor-mode .main > .alert,
body.package-editor-mode .main > .stats,
body.package-editor-mode .main > .card:not(#package-form){
    display:none !important;
}

body.package-editor-mode .page-head{
    min-height:44px !important;
    margin:0 0 7px !important;
}

body.package-editor-mode .page-head h1{
    font-size:20px !important;
}

body.package-editor-mode .page-head p{
    display:none !important;
}

body.package-editor-mode #package-form{
    height:calc(100vh - 131px) !important;
    min-height:0 !important;
    margin:0 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow:hidden !important;
    border-radius:12px !important;
    box-shadow:0 10px 30px rgba(15,23,42,.08) !important;
}

body.package-editor-mode #package-form > .card-head{
    flex:0 0 47px !important;
    min-height:47px !important;
    padding:7px 12px !important;
}

body.package-editor-mode #package-form > .card-head h2{
    font-size:15px !important;
}

body.package-editor-mode #package-form > .card-head p{
    display:none !important;
}

body.package-editor-mode #package-form > .card-body{
    flex:1 1 auto !important;
    min-height:0 !important;
    padding:8px 10px 9px !important;
    overflow:hidden !important;
}

body.package-editor-mode #package-form form{
    height:100% !important;
    min-height:0 !important;
    display:flex !important;
    flex-direction:column !important;
}

/* Tab navigation */
.package-editor-tabs{
    flex:0 0 auto;
    display:flex;
    align-items:center;
    gap:5px;
    margin:0 0 7px;
    padding:5px;
    overflow-x:auto;
    border:1px solid #e0e8f1;
    border-radius:10px;
    background:#f7f9fc;
    scrollbar-width:thin;
}

.package-editor-tab{
    min-height:34px;
    padding:0 11px;
    border:1px solid transparent;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    background:transparent;
    color:#607087;
    font:800 10px/1 'DM Sans',sans-serif;
    white-space:nowrap;
    cursor:pointer;
    transition:.16s ease;
}

.package-editor-tab:hover{
    background:#fff;
    color:#16283e;
}

.package-editor-tab.active{
    background:#fff;
    border-color:#d7e3f0;
    color:#2563eb;
    box-shadow:0 3px 9px rgba(15,23,42,.06);
}

.package-editor-tab[data-package-tab="basic"].active{color:#2563eb}
.package-editor-tab[data-package-tab="pricing"].active{color:#0f9a68}
.package-editor-tab[data-package-tab="media"].active{color:#7c3aed}
.package-editor-tab[data-package-tab="itinerary"].active{color:#d97706}
.package-editor-tab[data-package-tab="content"].active{color:#0891b2}
.package-editor-tab[data-package-tab="publish"].active{color:#e11d48}

/* Form content occupies remaining screen, scrolls internally only */
body.package-editor-mode #package-form .form-grid{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    align-content:start !important;
    padding:2px 4px 10px 2px !important;
    scrollbar-width:thin;
    scrollbar-color:#c8d3df transparent;
}

body.package-editor-mode #package-form .form-group{
    display:none !important;
}

body.package-editor-mode #package-form .form-group.package-section-active{
    display:block !important;
}

/* Keep grid placement professional inside a selected section */
body.package-editor-mode #package-form .form-grid{
    grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    gap:8px 10px !important;
}

body.package-editor-mode #package-form .form-group.package-section-active.full{
    grid-column:1/-1 !important;
}

/* Footer always visible at bottom */
body.package-editor-mode #package-form .form-footer{
    flex:0 0 auto !important;
    min-height:48px !important;
    margin:0 !important;
    padding:7px 2px 0 !important;
    border-top:1px solid #e7edf4 !important;
    background:#fff !important;
}

body.package-editor-mode #package-form .form-footer .btn{
    min-height:35px !important;
    padding:0 12px !important;
    font-size:10px !important;
}

/* Compact dynamic builders */
body.package-editor-mode #package-form .itinerary-builder,
body.package-editor-mode #package-form .child-price-builder,
body.package-editor-mode #package-form .price-builder,
body.package-editor-mode #package-form .date-price-builder{
    margin-top:3px !important;
}

body.package-editor-mode #package-form .itinerary-days{
    max-height:none !important;
}

/* Small step indicator */
.package-tab-status{
    margin-left:auto;
    padding:0 7px;
    color:#8290a1;
    font-size:9px;
    font-weight:700;
    white-space:nowrap;
}

/* Mobile / tablet */
@media(max-width:1050px){
    body.package-editor-mode #package-form .form-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}

@media(max-width:760px){
    body.package-editor-mode{
        overflow:auto !important;
    }

    body.package-editor-mode .main{
        height:auto !important;
        min-height:calc(100vh - 70px) !important;
        overflow:visible !important;
        padding:8px !important;
    }

    body.package-editor-mode #package-form{
        height:calc(100vh - 126px) !important;
        min-height:560px !important;
    }

    body.package-editor-mode #package-form .form-grid{
        grid-template-columns:1fr !important;
    }

    body.package-editor-mode #package-form .form-group.package-section-active.full{
        grid-column:auto !important;
    }

    .package-editor-tab{
        min-height:35px;
        padding:0 10px;
        font-size:10px;
    }

    .package-tab-status{
        display:none;
    }
}



/* ============================================================
   PACKAGE EDITOR — COLORFUL PROFESSIONAL BOXES + TAB BUTTONS
   VISUAL ONLY
============================================================ */

/* Main editor container */
body.package-editor-mode #package-form{
    background:
        linear-gradient(180deg,#ffffff 0%,#fbfdff 100%) !important;
    border:1px solid #dce6f1 !important;
}

/* Colorful top management tabs */
.package-editor-tabs{
    padding:6px !important;
    gap:7px !important;
    border:1px solid #dce5ef !important;
    border-radius:12px !important;
    background:
        linear-gradient(135deg,#f8fbff,#f3f7fc) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.9) !important;
}

.package-editor-tab{
    min-height:38px !important;
    padding:0 13px !important;
    border-radius:9px !important;
    border:1px solid transparent !important;
    font-size:10px !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:
        transform .16s ease,
        box-shadow .16s ease,
        background .16s ease,
        color .16s ease !important;
}

.package-editor-tab i{
    width:23px !important;
    height:23px !important;
    border-radius:7px !important;
    display:grid !important;
    place-items:center !important;
    font-size:10px !important;
    background:rgba(255,255,255,.72) !important;
    box-shadow:0 2px 6px rgba(15,23,42,.06) !important;
}

.package-editor-tab:hover{
    transform:translateY(-1px) !important;
}

/* BASIC INFO — BLUE */
.package-editor-tab[data-package-tab="basic"]{
    background:#eef5ff !important;
    color:#2369c9 !important;
    border-color:#d9e8fb !important;
}
.package-editor-tab[data-package-tab="basic"] i{
    color:#2563eb !important;
}
.package-editor-tab[data-package-tab="basic"].active{
    background:linear-gradient(135deg,#2563eb,#3b82f6) !important;
    color:#fff !important;
    border-color:#2563eb !important;
    box-shadow:0 6px 14px rgba(37,99,235,.20) !important;
}
.package-editor-tab[data-package-tab="basic"].active i{
    color:#2563eb !important;
    background:#fff !important;
}

/* PRICING — GREEN */
.package-editor-tab[data-package-tab="hotels"]{background:#fff8e8 !important;color:#9a6700 !important;border-color:#f5e1aa !important}
.package-editor-tab[data-package-tab="hotels"] i{color:#d97706 !important}
.package-editor-tab[data-package-tab="hotels"].active{background:linear-gradient(135deg,#d97706,#f59e0b) !important;color:#fff !important;border-color:#d97706 !important;box-shadow:0 6px 14px rgba(217,119,6,.20) !important}
.package-editor-tab[data-package-tab="hotels"].active i{color:#d97706 !important;background:#fff !important}

.package-editor-tab[data-package-tab="pricing"]{
    background:#eefbf5 !important;
    color:#087c59 !important;
    border-color:#d7f0e5 !important;
}
.package-editor-tab[data-package-tab="pricing"] i{
    color:#0f9a68 !important;
}
.package-editor-tab[data-package-tab="pricing"].active{
    background:linear-gradient(135deg,#0f9a68,#18b67e) !important;
    color:#fff !important;
    border-color:#0f9a68 !important;
    box-shadow:0 6px 14px rgba(15,154,104,.20) !important;
}
.package-editor-tab[data-package-tab="pricing"].active i{
    color:#0f9a68 !important;
    background:#fff !important;
}

/* IMAGES — PURPLE */
.package-editor-tab[data-package-tab="media"]{
    background:#f6f1ff !important;
    color:#6f4fc4 !important;
    border-color:#e6dcfb !important;
}
.package-editor-tab[data-package-tab="media"] i{
    color:#7c5bd1 !important;
}
.package-editor-tab[data-package-tab="media"].active{
    background:linear-gradient(135deg,#7c5bd1,#936cf0) !important;
    color:#fff !important;
    border-color:#7c5bd1 !important;
    box-shadow:0 6px 14px rgba(124,91,209,.20) !important;
}
.package-editor-tab[data-package-tab="media"].active i{
    color:#7c5bd1 !important;
    background:#fff !important;
}

/* ITINERARY — ORANGE */
.package-editor-tab[data-package-tab="itinerary"]{
    background:#fff7ed !important;
    color:#b86e13 !important;
    border-color:#f3e1ca !important;
}
.package-editor-tab[data-package-tab="itinerary"] i{
    color:#d97706 !important;
}
.package-editor-tab[data-package-tab="itinerary"].active{
    background:linear-gradient(135deg,#d97706,#ee9a2c) !important;
    color:#fff !important;
    border-color:#d97706 !important;
    box-shadow:0 6px 14px rgba(217,119,6,.20) !important;
}
.package-editor-tab[data-package-tab="itinerary"].active i{
    color:#d97706 !important;
    background:#fff !important;
}

/* CONTENT — CYAN */
.package-editor-tab[data-package-tab="content"]{
    background:#eefaff !important;
    color:#0b7f9d !important;
    border-color:#d8edf4 !important;
}
.package-editor-tab[data-package-tab="content"] i{
    color:#0891b2 !important;
}
.package-editor-tab[data-package-tab="content"].active{
    background:linear-gradient(135deg,#0891b2,#13b3d4) !important;
    color:#fff !important;
    border-color:#0891b2 !important;
    box-shadow:0 6px 14px rgba(8,145,178,.20) !important;
}
.package-editor-tab[data-package-tab="content"].active i{
    color:#0891b2 !important;
    background:#fff !important;
}

/* PUBLISH — ROSE */
.package-editor-tab[data-package-tab="publish"]{
    background:#fff1f4 !important;
    color:#bc405a !important;
    border-color:#f3d9e0 !important;
}
.package-editor-tab[data-package-tab="publish"] i{
    color:#e11d48 !important;
}
.package-editor-tab[data-package-tab="publish"].active{
    background:linear-gradient(135deg,#e11d48,#f04468) !important;
    color:#fff !important;
    border-color:#e11d48 !important;
    box-shadow:0 6px 14px rgba(225,29,72,.20) !important;
}
.package-editor-tab[data-package-tab="publish"].active i{
    color:#e11d48 !important;
    background:#fff !important;
}

/* Selected management section boxes */
body.package-editor-mode #package-form .form-group.package-section-active{
    padding:9px 10px !important;
    border:1px solid #e1e8f0 !important;
    border-radius:9px !important;
    background:#fff !important;
    box-shadow:0 3px 10px rgba(15,23,42,.035) !important;
}

/* Give each active section family its own soft box colour */
body.package-editor-mode #package-form .form-group[data-package-section="basic"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#f5f9ff) !important;
    border-color:#dce9fb !important;
}

body.package-editor-mode #package-form .form-group[data-package-section="pricing"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#f3fcf8) !important;
    border-color:#d9efe5 !important;
}

body.package-editor-mode #package-form .form-group[data-package-section="media"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#faf7ff) !important;
    border-color:#e6def7 !important;
}

body.package-editor-mode #package-form .form-group[data-package-section="itinerary"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#fff9f2) !important;
    border-color:#f2e3d0 !important;
}

body.package-editor-mode #package-form .form-group[data-package-section="content"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#f4fcff) !important;
    border-color:#d9edf4 !important;
}

body.package-editor-mode #package-form .form-group[data-package-section="publish"].package-section-active{
    background:linear-gradient(135deg,#ffffff,#fff6f8) !important;
    border-color:#f2dce2 !important;
}

/* Inputs remain clear inside colorful boxes */
body.package-editor-mode #package-form .form-control{
    background:#fff !important;
    border-color:#ced9e5 !important;
    box-shadow:0 1px 2px rgba(15,23,42,.02) !important;
}

body.package-editor-mode #package-form .form-control:focus{
    border-color:#6ea8ff !important;
    box-shadow:0 0 0 3px rgba(37,99,235,.10) !important;
}

/* More polished footer action area */
body.package-editor-mode #package-form .form-footer{
    padding:8px 4px 0 !important;
    background:
        linear-gradient(180deg,rgba(255,255,255,.92),#fff) !important;
}

body.package-editor-mode #package-form .form-footer .btn-primary,
body.package-editor-mode #package-form .form-footer button[type="submit"]{
    background:linear-gradient(135deg,#2563eb,#4b6ff5) !important;
    border-color:#2563eb !important;
    color:#fff !important;
    box-shadow:0 5px 14px rgba(37,99,235,.18) !important;
}

body.package-editor-mode #package-form .form-footer .btn-secondary,
body.package-editor-mode #package-form .form-footer a{
    background:#fff !important;
    border-color:#d9e2ec !important;
}

/* Section counter pill */
.package-tab-status{
    padding:6px 9px !important;
    border:1px solid #e0e7ef !important;
    border-radius:999px !important;
    background:#fff !important;
    color:#708095 !important;
    box-shadow:0 2px 6px rgba(15,23,42,.04) !important;
}

/* Mobile keeps horizontal colorful tab scroll */
@media(max-width:760px){
    .package-editor-tabs{
        gap:5px !important;
    }

    .package-editor-tab{
        min-height:37px !important;
        padding:0 10px !important;
    }
}



/* ============================================================
   TRAVSCOPE MODERN LEFT PANEL — VISUAL ONLY
   Matches admin-dashboard.php navigation style.
   No PHP, database, form, action, link or page logic changed.
============================================================ */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    background:
        radial-gradient(circle at top left,rgba(40,117,255,.22),transparent 28%),
        radial-gradient(circle at bottom right,rgba(15,154,104,.12),transparent 24%),
        linear-gradient(180deg,#081225 0%,#0d1830 100%) !important;
    color:#fff !important;
    border-right:1px solid rgba(255,255,255,.06) !important;
    box-shadow:14px 0 38px rgba(4,10,24,.28),inset -1px 0 0 rgba(255,255,255,.03) !important;
}
.sidebar-logo,
.odisha-style-logo{
    margin:12px 12px 8px !important;
    padding:14px !important;
    min-height:68px !important;
    height:auto !important;
    border:1px solid rgba(255,255,255,.07) !important;
    border-radius:16px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03)) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 10px 24px rgba(0,0,0,.16) !important;
}
.logo-icon,
.odisha-style-logo-icon{
    box-shadow:0 10px 24px rgba(37,99,235,.28) !important;
}
.sidebar-menu{padding:6px 10px 14px !important;}
.menu-title,
.sidebar-title,
.odisha-style-menu-label{
    margin:16px 10px 8px !important;
    padding:0 2px !important;
    color:rgba(183,202,234,.70) !important;
    font-size:10px !important;
    font-weight:800 !important;
    letter-spacing:.13em !important;
    text-transform:uppercase !important;
}
.menu-link,
.odisha-style-nav-link,
.admin-sidebar a{
    position:relative !important;
    min-height:46px !important;
    margin:0 4px 7px !important;
    padding:8px 13px 8px 12px !important;
    border:1px solid transparent !important;
    border-radius:13px !important;
    display:flex !important;
    align-items:center !important;
    gap:11px !important;
    background:transparent !important;
    color:rgba(226,236,255,.76) !important;
    font-size:13px !important;
    font-weight:700 !important;
    line-height:1.15 !important;
    transition:transform .16s ease,background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease !important;
}
.menu-link:hover,
.odisha-style-nav-link:hover,
.admin-sidebar a:hover{
    transform:translateX(2px) !important;
    color:#fff !important;
    border-color:rgba(255,255,255,.08) !important;
    background:rgba(255,255,255,.045) !important;
    box-shadow:0 8px 20px rgba(0,0,0,.14) !important;
}
.menu-link i,
.odisha-style-nav-icon,
.admin-sidebar a i{
    width:30px !important;
    height:30px !important;
    flex:0 0 30px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:10px !important;
    background:rgba(255,255,255,.06) !important;
    color:#cfe0ff !important;
    font-size:12px !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.menu-link.active,
.odisha-style-nav-link.active,
.admin-sidebar a.active{
    color:#fff !important;
    border-color:rgba(75,138,255,.36) !important;
    background:linear-gradient(135deg,rgba(37,99,235,.95),rgba(80,140,255,.95)) !important;
    box-shadow:0 12px 24px rgba(15,23,42,.26),0 0 0 1px rgba(255,255,255,.03) inset !important;
}
.menu-link.active i,
.odisha-style-nav-link.active .odisha-style-nav-icon,
.admin-sidebar a.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}
.menu-link.active::after,
.odisha-style-nav-link.active::after,
.admin-sidebar a.active::after{
    content:"";
    position:absolute;
    left:-4px;
    top:9px;
    bottom:9px;
    width:4px;
    border-radius:999px;
    background:linear-gradient(180deg,#8dc2ff 0%,#fff 100%);
    box-shadow:0 0 12px rgba(141,194,255,.7);
}
.menu-badge,
.odisha-style-nav-badge{
    min-width:22px !important;
    height:22px !important;
    padding:0 6px !important;
    margin-left:auto !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.09) !important;
    font-size:10px !important;
    font-weight:800 !important;
}
.menu-link[href*="logout"],
.odisha-style-nav-link[href*="logout"],
.admin-sidebar a[href*="logout"]{
    margin-top:12px !important;
    border-color:rgba(255,107,107,.15) !important;
    background:rgba(255,78,78,.06) !important;
    color:#ffd6d6 !important;
}


/* ============================================================
   MODERN PROFESSIONAL SIDEBAR NAVIGATION
   VISUAL ONLY — LINKS, COUNTS AND DASHBOARD LOGIC UNCHANGED
============================================================ */

.sidebar{
    width:265px !important;
    background:
        linear-gradient(180deg,#0b172a 0%,#101d33 58%,#0c1829 100%) !important;
    border-right:1px solid rgba(255,255,255,.045) !important;
    box-shadow:10px 0 30px rgba(10,24,43,.08) !important;
}

.sidebar-logo{
    height:72px !important;
    padding:0 17px !important;
    gap:10px !important;
    border-bottom:1px solid rgba(255,255,255,.08) !important;
    font-size:16px !important;
    letter-spacing:-.2px !important;
}

.sidebar-logo .logo-icon{
    width:38px !important;
    height:38px !important;
    border-radius:11px !important;
    background:linear-gradient(135deg,#1577ff,#0bb9ee) !important;
    box-shadow:0 7px 18px rgba(11,116,255,.25) !important;
}

.sidebar-menu{
    padding:12px 10px 24px !important;
}

/* Group titles */
.menu-title{
    position:relative !important;
    margin:9px 7px 6px !important;
    padding:7px 8px 7px 10px !important;
    color:#75869c !important;
    font-size:9px !important;
    font-weight:800 !important;
    letter-spacing:1.1px !important;
    line-height:1 !important;
}

.menu-title:before{
    content:"";
    position:absolute;
    left:0;
    top:50%;
    width:3px;
    height:13px;
    border-radius:999px;
    transform:translateY(-50%);
    background:#2d7ff9;
    opacity:.55;
}

/* Navigation buttons */
.menu-link{
    min-height:42px !important;
    margin:3px 0 !important;
    padding:0 9px !important;
    gap:9px !important;
    border:1px solid transparent !important;
    border-radius:10px !important;
    color:#aebbd0 !important;
    font-size:11px !important;
    font-weight:700 !important;
    transition:
        background .16s ease,
        border-color .16s ease,
        color .16s ease,
        transform .16s ease,
        box-shadow .16s ease !important;
}

.menu-link i{
    width:29px !important;
    height:29px !important;
    flex:0 0 29px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:8px !important;
    background:rgba(255,255,255,.055) !important;
    color:#90a3bb !important;
    font-size:11px !important;
    transition:.16s ease !important;
}

.menu-link:hover{
    color:#fff !important;
    background:rgba(255,255,255,.055) !important;
    border-color:rgba(255,255,255,.055) !important;
    transform:translateX(2px) !important;
}

.menu-link:hover i{
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
}

/* Active item */
.menu-link.active{
    color:#fff !important;
    background:
        linear-gradient(135deg,#2563eb 0%,#3b82f6 58%,#2875f0 100%) !important;
    border-color:rgba(119,173,255,.45) !important;
    box-shadow:
        0 7px 16px rgba(37,99,235,.23),
        inset 0 1px 0 rgba(255,255,255,.14) !important;
}

.menu-link.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.10) !important;
}

/* Give menu categories subtle individual icon colours */
.sidebar-menu .menu-link[href*="crm"] i{
    background:rgba(124,91,209,.14) !important;
    color:#b5a1f8 !important;
}

.sidebar-menu .menu-link[href*="destination"] i{
    background:rgba(14,165,233,.13) !important;
    color:#77d2f3 !important;
}

.sidebar-menu .menu-link[href*="package"] i{
    background:rgba(245,158,11,.14) !important;
    color:#f7bf5b !important;
}

.sidebar-menu .menu-link[href*="gallery"] i{
    background:rgba(16,185,129,.13) !important;
    color:#67dcb6 !important;
}

.sidebar-menu .menu-link[href*="booking"] i{
    background:rgba(99,102,241,.14) !important;
    color:#a8aaf7 !important;
}

.sidebar-menu .menu-link[href*="cancellation"] i{
    background:rgba(244,63,94,.14) !important;
    color:#f394a6 !important;
}

.sidebar-menu .menu-link[href*="coupon"] i{
    background:rgba(6,182,212,.13) !important;
    color:#72d8e9 !important;
}

.sidebar-menu .menu-link[href*="enquir"] i{
    background:rgba(59,130,246,.14) !important;
    color:#8db9f6 !important;
}

.sidebar-menu .menu-link[href*="customer"] i,
.sidebar-menu .menu-link[href*="users"] i{
    background:rgba(20,184,166,.13) !important;
    color:#72d7ca !important;
}

.sidebar-menu .menu-link[href*="review"] i{
    background:rgba(234,179,8,.14) !important;
    color:#f4d35f !important;
}

.sidebar-menu .menu-link[href*="blog"] i,
.sidebar-menu .menu-link[href*="story"] i{
    background:rgba(168,85,247,.14) !important;
    color:#c9a0f8 !important;
}

.sidebar-menu .menu-link[href*="settings"] i{
    background:rgba(148,163,184,.14) !important;
    color:#bac5d2 !important;
}

.sidebar-menu .menu-link[href*="website"] i{
    background:rgba(34,197,94,.13) !important;
    color:#72dc92 !important;
}

/* Active icon always follows active design */
.sidebar-menu .menu-link.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}

/* Modern badges */
.menu-badge{
    min-width:21px !important;
    height:21px !important;
    padding:0 6px !important;
    border:1px solid rgba(255,255,255,.13) !important;
    border-radius:999px !important;
    background:linear-gradient(135deg,#1d75db,#3189ef) !important;
    color:#fff !important;
    font-size:9px !important;
    font-weight:800 !important;
    box-shadow:0 3px 8px rgba(0,0,0,.15) !important;
}

/* Override old inline badge colours for consistent modern look */
.menu-link .menu-badge[style]{
    background:linear-gradient(135deg,#1d75db,#3189ef) !important;
}

/* CRM badge slightly purple */
.sidebar-menu .menu-link[href*="crm"] .menu-badge{
    background:linear-gradient(135deg,#7450d5,#9369ed) !important;
}

/* Destructive / alert type badge */
.sidebar-menu .menu-link[href*="cancellation"] .menu-badge{
    background:linear-gradient(135deg,#d84b61,#ec6377) !important;
}

/* Bottom website/settings area feels separated */
.sidebar-menu .menu-title:last-of-type{
    margin-top:14px !important;
}

/* Slim scrollbar */
.sidebar{
    scrollbar-width:thin;
    scrollbar-color:#314158 transparent;
}

.sidebar::-webkit-scrollbar{
    width:5px;
}

.sidebar::-webkit-scrollbar-thumb{
    background:#314158;
    border-radius:999px;
}

.sidebar::-webkit-scrollbar-track{
    background:transparent;
}

/* Keep main alignment exactly with sidebar width */
.main{
    margin-left:265px !important;
}

@media(max-width:850px){
    .main{
        margin-left:0 !important;
    }

    .sidebar{
        width:265px !important;
    }
}



/* ============================================================
   TRAVSCOPE MODERN LEFT PANEL — VISUAL ONLY
   Matches admin-dashboard.php navigation style.
   No PHP, database, form, action, link or page logic changed.
============================================================ */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    background:
        radial-gradient(circle at top left,rgba(40,117,255,.22),transparent 28%),
        radial-gradient(circle at bottom right,rgba(15,154,104,.12),transparent 24%),
        linear-gradient(180deg,#081225 0%,#0d1830 100%) !important;
    color:#fff !important;
    border-right:1px solid rgba(255,255,255,.06) !important;
    box-shadow:14px 0 38px rgba(4,10,24,.28),inset -1px 0 0 rgba(255,255,255,.03) !important;
}
.sidebar-logo,
.odisha-style-logo{
    margin:12px 12px 8px !important;
    padding:14px !important;
    min-height:68px !important;
    height:auto !important;
    border:1px solid rgba(255,255,255,.07) !important;
    border-radius:16px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03)) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 10px 24px rgba(0,0,0,.16) !important;
}
.logo-icon,
.odisha-style-logo-icon{
    box-shadow:0 10px 24px rgba(37,99,235,.28) !important;
}
.sidebar-menu{padding:6px 10px 14px !important;}
.menu-title,
.sidebar-title,
.odisha-style-menu-label{
    margin:16px 10px 8px !important;
    padding:0 2px !important;
    color:rgba(183,202,234,.70) !important;
    font-size:10px !important;
    font-weight:800 !important;
    letter-spacing:.13em !important;
    text-transform:uppercase !important;
}
.menu-link,
.odisha-style-nav-link,
.admin-sidebar a{
    position:relative !important;
    min-height:46px !important;
    margin:0 4px 7px !important;
    padding:8px 13px 8px 12px !important;
    border:1px solid transparent !important;
    border-radius:13px !important;
    display:flex !important;
    align-items:center !important;
    gap:11px !important;
    background:transparent !important;
    color:rgba(226,236,255,.76) !important;
    font-size:13px !important;
    font-weight:700 !important;
    line-height:1.15 !important;
    transition:transform .16s ease,background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease !important;
}
.menu-link:hover,
.odisha-style-nav-link:hover,
.admin-sidebar a:hover{
    transform:translateX(2px) !important;
    color:#fff !important;
    border-color:rgba(255,255,255,.08) !important;
    background:rgba(255,255,255,.045) !important;
    box-shadow:0 8px 20px rgba(0,0,0,.14) !important;
}
.menu-link i,
.odisha-style-nav-icon,
.admin-sidebar a i{
    width:30px !important;
    height:30px !important;
    flex:0 0 30px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:10px !important;
    background:rgba(255,255,255,.06) !important;
    color:#cfe0ff !important;
    font-size:12px !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.menu-link.active,
.odisha-style-nav-link.active,
.admin-sidebar a.active{
    color:#fff !important;
    border-color:rgba(75,138,255,.36) !important;
    background:linear-gradient(135deg,rgba(37,99,235,.95),rgba(80,140,255,.95)) !important;
    box-shadow:0 12px 24px rgba(15,23,42,.26),0 0 0 1px rgba(255,255,255,.03) inset !important;
}
.menu-link.active i,
.odisha-style-nav-link.active .odisha-style-nav-icon,
.admin-sidebar a.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}
.menu-link.active::after,
.odisha-style-nav-link.active::after,
.admin-sidebar a.active::after{
    content:"";
    position:absolute;
    left:-4px;
    top:9px;
    bottom:9px;
    width:4px;
    border-radius:999px;
    background:linear-gradient(180deg,#8dc2ff 0%,#fff 100%);
    box-shadow:0 0 12px rgba(141,194,255,.7);
}
.menu-badge,
.odisha-style-nav-badge{
    min-width:22px !important;
    height:22px !important;
    padding:0 6px !important;
    margin-left:auto !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.09) !important;
    font-size:10px !important;
    font-weight:800 !important;
}
.menu-link[href*="logout"],
.odisha-style-nav-link[href*="logout"],
.admin-sidebar a[href*="logout"]{
    margin-top:12px !important;
    border-color:rgba(255,107,107,.15) !important;
    background:rgba(255,78,78,.06) !important;
    color:#ffd6d6 !important;
}



/* === EXACT DASHBOARD SIDEBAR — CROSS-ADMIN COMPATIBILITY === */
.menu-badge:empty{display:none!important}
.sidebar .menu-link{box-sizing:border-box!important}
.sidebar .menu-link span:not(.menu-badge){min-width:0}

/* Existing admin layouts with header above sidebar */
.layout > .sidebar{top:0!important}
.layout > .main{min-width:0}

/* CRM uses a different main wrapper */
.odisha-style-main{margin-left:265px!important;min-width:0!important}

/* Detail pages that originally had no navigation sidebar */
body.ts-dashboard-sidebar-added > .topbar{margin-left:265px!important;width:auto!important}
body.ts-dashboard-sidebar-added > .page{margin-left:265px!important;width:auto!important;max-width:none!important}
body.ts-dashboard-sidebar-added > main.page{padding-left:22px!important;padding-right:22px!important}

@media(max-width:850px){
    .odisha-style-main{margin-left:0!important}
    body.ts-dashboard-sidebar-added > .topbar{margin-left:0!important}
    body.ts-dashboard-sidebar-added > .page{margin-left:0!important}
}


/* ============================================================
   DASHBOARD-ALIGNED ADMIN WORKSPACE
   Layout spacing only: remove duplicate sidebar gap and keep
   content immediately beside the fixed 265px dashboard sidebar.
============================================================ */
:root{--ts-admin-sidebar-width:265px}

.layout{
    display:block!important;
    grid-template-columns:none!important;
    min-height:100vh!important;
}
.main{
    margin-left:var(--ts-admin-sidebar-width)!important;
    width:auto!important;
    max-width:none!important;
    min-width:0!important;
    padding:16px 18px 20px!important;
}

@media(max-width:850px){
    .main,.page,.admin-main{
        margin-left:0!important;
    }
    .main{padding:14px 12px 18px!important;}
    .page{padding:14px 12px 18px!important;}
    .main > .content,.main .content:first-child{padding:14px 12px 18px!important;}
}



/* ============================================================
   TRAVSCOPE FINAL ADMIN LAYOUT STANDARD
   Dashboard-aligned, edge-to-edge workspace, no page-sideways
   overflow, responsive on desktop/tablet/mobile.
   VISUAL/LAYOUT ONLY — business logic untouched.
============================================================ */

html,
body{
    width:100% !important;
    max-width:100% !important;
    overflow-x:hidden !important;
}

/* Fixed dashboard-standard sidebar */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    width:265px !important;
    min-width:265px !important;
    max-width:265px !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    bottom:0 !important;
    z-index:1100 !important;
}

/* Root wrappers must not reserve a second sidebar column */
.layout,
.admin-shell,
.odisha-style-admin-layout{
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
}

/* Common root layout wrappers containing the fixed sidebar */
.layout{
    display:block !important;
}

/* Main workspace: identical horizontal start to dashboard */
.main,
.admin-main,
.odisha-style-main{
    margin-left:265px !important;
    width:calc(100% - 265px) !important;
    max-width:none !important;
    min-width:0 !important;
    box-sizing:border-box !important;
}

/* Standalone page/content roots used by CRM/enquiry/detail screens */
body > .page,
body > .content{
    margin-left:265px !important;
    width:calc(100% - 265px) !important;
    max-width:none !important;
    min-width:0 !important;
    box-sizing:border-box !important;
}

/* Nested pages must fill their main workspace, never recentre/narrow */
.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    width:100% !important;
    max-width:none !important;
    min-width:0 !important;
    margin-left:0 !important;
    margin-right:0 !important;
}

/* Dashboard-like visual breathing room */
.main,
.admin-main,
.odisha-style-main,
body > .page,
body > .content{
    padding-left:16px !important;
    padding-right:16px !important;
}

.page,
.content,
.card,
.panel,
.profile-manager,
.form-wrap,
.editor-shell,
.settings-content,
.table-wrap,
.content-grid,
.details-grid,
.info-grid,
.summary-grid,
.customer-grid,
.form-grid,
.filters,
.grid{
    min-width:0 !important;
    max-width:100% !important;
    box-sizing:border-box !important;
}

/* Prevent nested width declarations from pushing the entire page sideways */
.card,
.panel,
.form-wrap,
.editor-shell,
.settings-content,
.profile-manager{
    width:auto !important;
}

/* Tables scroll inside their own card only — never the whole admin page */
.table-wrap,
.pricing-table-wrap,
.season-price-table-wrap{
    width:100% !important;
    max-width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
}

table,
.booking-table,
.customer-table,
.destination-table,
.pricing-table{
    max-width:none;
}

/* Media stays inside its card */
img,
video,
canvas{
    max-width:100%;
}

/* Common data grids adapt instead of forcing horizontal page scroll */
.stats,
.stats-grid,
.mini-stats,
.overview-grid,
.summary-grid,
.details-grid,
.info-grid,
.content-grid,
finance-grid,
.money-grid,
.action-grid{
    min-width:0 !important;
}

/* Desktop professional spacing */
.page-head,
.page-heading{
    margin-left:0 !important;
    margin-right:0 !important;
}

@media(min-width:851px){
    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        padding-top:16px !important;
        padding-bottom:20px !important;
    }

    /* CRM / enquiry pages that previously used centered 94–97% widths */
    .page{
        width:100% !important;
        max-width:none !important;
    }
}

/* Tablet: sidebar collapses exactly like dashboard */
@media(max-width:850px){
    .sidebar,
    .admin-sidebar,
    .odisha-style-sidebar{
        width:265px !important;
        min-width:265px !important;
        max-width:265px !important;
        transform:translateX(-100%);
    }

    .sidebar.open,
    .admin-sidebar.open,
    .odisha-style-sidebar.open{
        transform:translateX(0) !important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
        padding-left:12px !important;
        padding-right:12px !important;
    }

    .layout,
    .admin-shell,
    .odisha-style-admin-layout{
        display:block !important;
        width:100% !important;
    }

    .page,
    .content{
        width:100% !important;
        max-width:100% !important;
        margin-left:0 !important;
        margin-right:0 !important;
    }

    .stats,
    .stats-grid,
    .mini-stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .filters,
    .form-grid,
    .details-grid,
    .info-grid,
    .summary-grid,
    .content-grid{
        grid-template-columns:1fr !important;
    }
}

/* Phone */
@media(max-width:600px){
    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        padding-left:8px !important;
        padding-right:8px !important;
        padding-top:10px !important;
    }

    .stats,
    .stats-grid,
    .mini-stats,
    .overview-grid{
        grid-template-columns:1fr !important;
    }

    .page-head,
    .page-heading{
        gap:8px !important;
    }

    .page-head h1,
    .page-heading h1{
        font-size:22px !important;
    }

    /* Buttons/forms remain touch-friendly */
    input:not([type="checkbox"]):not([type="radio"]),
    select,
    textarea,
    .control,
    .form-control{
        max-width:100% !important;
    }
}



/* ============================================================
   TRAVSCOPE STABLE COMPACT SIDEBAR
   Same exact dimensions/position on every admin page.
   No desktop jump, slide, resize, or layout shift.
============================================================ */

:root{
    --ts-sidebar-width:230px;
    --ts-sidebar-bg:#09162b;
    --ts-sidebar-bg-2:#0d1c35;
    --ts-sidebar-text:#b9c7d9;
    --ts-sidebar-heading:#70839f;
    --ts-sidebar-active:#2f7df4;
}

/* Disable visual shifting on desktop */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar,
.main,
.admin-main,
.odisha-style-main,
body > .page,
body > .content{
    transition:none !important;
    animation:none !important;
}

/* Exact same compact sidebar everywhere */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    position:fixed !important;
    top:0 !important;
    left:0 !important;
    bottom:0 !important;
    width:var(--ts-sidebar-width) !important;
    min-width:var(--ts-sidebar-width) !important;
    max-width:var(--ts-sidebar-width) !important;
    height:100vh !important;
    background:
        linear-gradient(180deg,var(--ts-sidebar-bg) 0%,var(--ts-sidebar-bg-2) 100%) !important;
    overflow:hidden !important;
    z-index:1100 !important;
    box-shadow:8px 0 24px rgba(8,18,38,.16) !important;
}

/* Compact logo — identical height on every page */
.sidebar-logo{
    height:64px !important;
    min-height:64px !important;
    margin:8px 9px 5px !important;
    padding:0 11px !important;
    gap:9px !important;
    border:1px solid rgba(255,255,255,.065) !important;
    border-radius:12px !important;
    background:rgba(255,255,255,.035) !important;
    font-size:15px !important;
    line-height:1 !important;
    box-shadow:none !important;
}

.sidebar-logo .logo-icon,
.sidebar-logo > .logo-icon{
    width:34px !important;
    height:34px !important;
    flex:0 0 34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    transform:none !important;
}

/* Fit the full menu without the panel moving up/down */
.sidebar-menu{
    height:calc(100vh - 77px) !important;
    padding:3px 8px 8px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    scrollbar-width:none !important;
    overscroll-behavior:contain !important;
}

.sidebar-menu::-webkit-scrollbar{
    width:0 !important;
    height:0 !important;
}

/* Small organized section headings */
.menu-title{
    height:22px !important;
    min-height:22px !important;
    margin:4px 5px 2px !important;
    padding:6px 4px 2px !important;
    display:flex !important;
    align-items:flex-end !important;
    color:var(--ts-sidebar-heading) !important;
    font-size:8px !important;
    font-weight:800 !important;
    line-height:1 !important;
    letter-spacing:1px !important;
    white-space:nowrap !important;
}

/* Compact fixed-height menu buttons */
.menu-link,
.sidebar .menu-link{
    position:relative !important;
    width:100% !important;
    height:36px !important;
    min-height:36px !important;
    max-height:36px !important;
    margin:0 0 2px !important;
    padding:0 9px !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    border:1px solid transparent !important;
    border-radius:9px !important;
    background:transparent !important;
    color:var(--ts-sidebar-text) !important;
    font-size:11px !important;
    font-weight:700 !important;
    line-height:1 !important;
    white-space:nowrap !important;
    transform:none !important;
    box-shadow:none !important;
    transition:none !important;
}

/* Same icon box size everywhere */
.menu-link i,
.sidebar .menu-link i{
    width:24px !important;
    height:24px !important;
    min-width:24px !important;
    flex:0 0 24px !important;
    margin:0 !important;
    display:grid !important;
    place-items:center !important;
    border-radius:7px !important;
    background:rgba(255,255,255,.055) !important;
    color:#a9c9f8 !important;
    font-size:10px !important;
    line-height:1 !important;
}

/* Hover does not move the button */
.menu-link:hover,
.sidebar .menu-link:hover{
    transform:none !important;
    background:rgba(255,255,255,.055) !important;
    border-color:rgba(255,255,255,.055) !important;
    color:#fff !important;
    box-shadow:none !important;
}

/* Current page: only color changes, dimensions remain identical */
.menu-link.active,
.sidebar .menu-link.active{
    height:36px !important;
    min-height:36px !important;
    padding:0 9px !important;
    transform:none !important;
    background:linear-gradient(135deg,#256ee9,#3d8af7) !important;
    border-color:rgba(104,164,255,.42) !important;
    color:#fff !important;
    box-shadow:0 4px 12px rgba(23,92,210,.18) !important;
}

.menu-link.active i,
.sidebar .menu-link.active i{
    background:rgba(255,255,255,.16) !important;
    color:#fff !important;
}

/* Remove active side stripe that can visually shift */
.menu-link.active::after{
    display:none !important;
}

.menu-link::before{
    display:none !important;
}

/* Fixed small counters */
.menu-badge{
    margin-left:auto !important;
    min-width:18px !important;
    width:auto !important;
    height:18px !important;
    padding:0 5px !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.12) !important;
    border:0 !important;
    color:#fff !important;
    font-size:8px !important;
    font-weight:800 !important;
    line-height:1 !important;
    box-shadow:none !important;
}

/* Do not treat logout as a different-size button */
.menu-link[href*="logout"]{
    margin-top:2px !important;
    height:36px !important;
    min-height:36px !important;
}

/* Every desktop page begins at the exact same X coordinate */
.main,
.admin-main,
.odisha-style-main{
    margin-left:var(--ts-sidebar-width) !important;
    width:calc(100% - var(--ts-sidebar-width)) !important;
    max-width:none !important;
}

body > .page,
body > .content{
    margin-left:var(--ts-sidebar-width) !important;
    width:calc(100% - var(--ts-sidebar-width)) !important;
    max-width:none !important;
}

/* Do not let nested pages add their own left gap */
.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    margin-left:0 !important;
    width:100% !important;
    max-width:none !important;
}

/* Mobile retains slide drawer; desktop remains completely fixed */
@media(max-width:850px){
    .sidebar,
    .admin-sidebar,
    .odisha-style-sidebar{
        width:230px !important;
        min-width:230px !important;
        max-width:230px !important;
        overflow-y:auto !important;
        transform:translateX(-100%) !important;
        transition:transform .18s ease !important;
    }

    .sidebar.open,
    .admin-sidebar.open,
    .odisha-style-sidebar.open{
        transform:translateX(0) !important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0 !important;
        width:100% !important;
    }
}



/* ============================================================
   TRAVSCOPE CONSTANT ADMIN LEFT PANEL
   IDENTICAL ON EVERY ADMIN PHP.
   Only active menu item and page content change.
============================================================ */

:root{
    --ts-fixed-sidebar:230px;
    --ts-side-bg:#09162b;
    --ts-side-bg2:#0d1c35;
    --ts-side-text:#bdc9d9;
    --ts-side-muted:#71839e;
    --ts-side-blue:#2f7df4;
}

/* Lock sidebar dimensions before page content paints */
html{overflow-x:hidden!important}
body{overflow-x:hidden!important}

.sidebar{
    position:fixed!important;
    inset:0 auto 0 0!important;
    width:var(--ts-fixed-sidebar)!important;
    min-width:var(--ts-fixed-sidebar)!important;
    max-width:var(--ts-fixed-sidebar)!important;
    height:100vh!important;
    z-index:1100!important;

    display:flex!important;
    flex-direction:column!important;

    background:
        radial-gradient(circle at 15% 0%,rgba(55,126,255,.14),transparent 23%),
        linear-gradient(180deg,var(--ts-side-bg),var(--ts-side-bg2))!important;

    color:#fff!important;
    border-right:1px solid rgba(255,255,255,.055)!important;
    box-shadow:8px 0 24px rgba(7,18,39,.15)!important;

    overflow:hidden!important;
    transform:none!important;
    transition:none!important;
    animation:none!important;
}

/* Brand block */
.sidebar-logo{
    flex:0 0 62px!important;
    height:62px!important;
    min-height:62px!important;

    margin:8px 9px 5px!important;
    padding:0 11px!important;

    display:flex!important;
    align-items:center!important;
    gap:9px!important;

    border:1px solid rgba(255,255,255,.07)!important;
    border-radius:12px!important;
    background:rgba(255,255,255,.035)!important;

    color:#fff!important;
    font-family:'Manrope','DM Sans',Arial,sans-serif!important;
    font-size:14px!important;
    font-weight:800!important;
    letter-spacing:0!important;
    line-height:1!important;

    box-shadow:none!important;
    transition:none!important;
}

.sidebar-logo .logo-icon{
    width:34px!important;
    height:34px!important;
    min-width:34px!important;
    flex:0 0 34px!important;

    display:grid!important;
    place-items:center!important;

    border-radius:9px!important;
    background:linear-gradient(135deg,#1688ff,#27b7e9)!important;
    color:#fff!important;
    font-size:12px!important;

    transform:none!important;
    box-shadow:0 5px 12px rgba(22,136,255,.18)!important;
}

.sidebar-logo span{
    color:#6db7ff!important;
}

/* Menu body */
.sidebar-menu{
    flex:1 1 auto!important;
    min-height:0!important;

    padding:2px 8px 8px!important;

    overflow-y:auto!important;
    overflow-x:hidden!important;

    scrollbar-width:none!important;
    overscroll-behavior:contain!important;
}
.sidebar-menu::-webkit-scrollbar{display:none!important}

/* Section labels */
.menu-title{
    height:21px!important;
    min-height:21px!important;
    margin:4px 5px 2px!important;
    padding:5px 4px 2px!important;

    display:flex!important;
    align-items:flex-end!important;

    color:var(--ts-side-muted)!important;
    font-size:8px!important;
    font-weight:800!important;
    line-height:1!important;
    letter-spacing:1px!important;
    text-transform:uppercase!important;

    white-space:nowrap!important;
}

/* Every button uses EXACT same geometry */
.menu-link{
    position:relative!important;

    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;

    margin:0 0 2px!important;
    padding:0 9px!important;

    display:flex!important;
    align-items:center!important;
    gap:8px!important;

    border:1px solid transparent!important;
    border-radius:9px!important;

    background:transparent!important;
    color:var(--ts-side-text)!important;

    font-size:11px!important;
    font-weight:700!important;
    line-height:1!important;

    white-space:nowrap!important;

    box-shadow:none!important;
    transform:none!important;
    transition:none!important;
    animation:none!important;
}

.menu-link i{
    width:23px!important;
    height:23px!important;
    min-width:23px!important;
    flex:0 0 23px!important;

    margin:0!important;

    display:grid!important;
    place-items:center!important;

    border-radius:7px!important;
    background:rgba(255,255,255,.055)!important;
    color:#a8c8f6!important;

    font-size:10px!important;
    line-height:1!important;

    transition:none!important;
}

/* Hover only changes colour — never size/position */
.menu-link:hover{
    background:rgba(255,255,255,.055)!important;
    border-color:rgba(255,255,255,.055)!important;
    color:#fff!important;

    transform:none!important;
    box-shadow:none!important;
}
.menu-link:hover i{
    background:rgba(255,255,255,.085)!important;
    color:#fff!important;
}

/* Active state keeps EXACT same geometry */
.menu-link.active{
    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;

    margin:0 0 2px!important;
    padding:0 9px!important;

    background:linear-gradient(135deg,#246fe9,#3b88f6)!important;
    border-color:rgba(105,166,255,.35)!important;
    color:#fff!important;

    box-shadow:0 4px 11px rgba(35,111,233,.16)!important;
    transform:none!important;
}
.menu-link.active i{
    background:rgba(255,255,255,.16)!important;
    color:#fff!important;
}

.menu-link::before,
.menu-link::after{
    display:none!important;
}

/* Constant compact badges */
.menu-badge{
    margin-left:auto!important;
    min-width:18px!important;
    height:18px!important;
    padding:0 5px!important;

    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;

    border:0!important;
    border-radius:999px!important;

    background:rgba(255,255,255,.12)!important;
    color:#fff!important;

    font-size:8px!important;
    font-weight:800!important;
    line-height:1!important;

    box-shadow:none!important;
}

/* Main content always begins immediately after same sidebar */
.main,
.admin-main,
.odisha-style-main{
    margin-left:var(--ts-fixed-sidebar)!important;
    width:calc(100% - var(--ts-fixed-sidebar))!important;
    max-width:none!important;
    min-width:0!important;
}

body > .page,
body > .content{
    margin-left:var(--ts-fixed-sidebar)!important;
    width:calc(100% - var(--ts-fixed-sidebar))!important;
    max-width:none!important;
    min-width:0!important;
}

/* No second sidebar gap from old grid layouts */
.layout,
.admin-shell,
.odisha-style-admin-layout{
    display:block!important;
    width:100%!important;
    max-width:100%!important;
    min-width:0!important;
}

.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    margin-left:0!important;
    margin-right:0!important;
    width:100%!important;
    max-width:none!important;
}

/* Mobile drawer only */
@media(max-width:850px){
    .sidebar{
        width:230px!important;
        min-width:230px!important;
        max-width:230px!important;
        transform:translateX(-100%)!important;
        transition:transform .18s ease!important;
    }

    .sidebar.open{
        transform:translateX(0)!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
    }
}



/* ============================================================
   TRAVSCOPE CONSTANT SIDEBAR BEHAVIOUR
   Desktop: panel never moves.
   Mobile/tablet: one universal 3-line hamburger drawer.
============================================================ */

/* Universal mobile hamburger stays hidden on desktop */
.ts-mobile-sidebar-toggle{
    display:none;
    position:fixed;
    top:12px;
    left:12px;
    z-index:1305;

    width:40px;
    height:40px;
    padding:0;

    border:1px solid #d9e3ee;
    border-radius:10px;

    background:#ffffff;
    color:#12233b;

    align-items:center;
    justify-content:center;

    box-shadow:0 5px 16px rgba(15,23,42,.12);
    cursor:pointer;
}

.ts-mobile-sidebar-toggle .ts-bars{
    width:18px;
    height:14px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}

.ts-mobile-sidebar-toggle .ts-bars span{
    display:block;
    width:100%;
    height:2px;
    border-radius:999px;
    background:currentColor;
    transition:transform .18s ease, opacity .18s ease;
}

.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(1){
    transform:translateY(6px) rotate(45deg);
}
.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(2){
    opacity:0;
}
.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(3){
    transform:translateY(-6px) rotate(-45deg);
}

/* One universal overlay */
.ts-sidebar-overlay{
    display:none;
    position:fixed;
    inset:0;
    z-index:1090;
    background:rgba(4,12,25,.48);
    backdrop-filter:blur(2px);
}

/* Desktop must remain absolutely constant */
@media(min-width:851px){
    .sidebar{
        transform:none!important;
        visibility:visible!important;
    }

    .ts-sidebar-overlay,
    .ts-mobile-sidebar-toggle{
        display:none!important;
    }
}

/* Mobile/tablet drawer */
@media(max-width:850px){
    .ts-mobile-sidebar-toggle{
        display:flex!important;
    }

    .sidebar{
        transform:translateX(-100%)!important;
        visibility:visible!important;
        transition:transform .18s ease!important;
        box-shadow:12px 0 32px rgba(5,14,31,.24)!important;
    }

    .sidebar.ts-sidebar-open,
    .sidebar.open{
        transform:translateX(0)!important;
    }

    .ts-sidebar-overlay.ts-sidebar-open{
        display:block!important;
    }

    body.ts-sidebar-lock{
        overflow:hidden!important;
    }

    /* Give top area room for hamburger without changing content width */
    .topbar,
    .header,
    .admin-header{
        padding-left:60px!important;
    }
}

/* Small phones */
@media(max-width:520px){
    .ts-mobile-sidebar-toggle{
        top:9px;
        left:9px;
        width:38px;
        height:38px;
    }

    .topbar,
    .header,
    .admin-header{
        padding-left:55px!important;
    }
}



/* ============================================================
   TRAVSCOPE FINAL CONSTANT PANEL + DASHBOARD PAGE ALIGNMENT
   Same left panel on every page. Only right page content changes.
============================================================ */

/* ---------- 1. ONE EXACT MENU BUTTON DESIGN EVERYWHERE ---------- */
.sidebar .menu-link,
.sidebar a.menu-link{
    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;
    margin:0 0 2px!important;
    padding:0 9px!important;
    gap:8px!important;
    border:1px solid transparent!important;
    border-radius:9px!important;
    background:transparent!important;
    color:#bdc9d9!important;
    font-size:11px!important;
    font-weight:700!important;
    line-height:1!important;
    box-shadow:none!important;
    transform:none!important;
    transition:none!important;
}

/* Every inactive icon uses the same neutral panel style.
   Removes old yellow/green/purple differences on individual pages. */
.sidebar .menu-link i,
.sidebar a.menu-link i{
    width:23px!important;
    height:23px!important;
    min-width:23px!important;
    flex:0 0 23px!important;
    margin:0!important;
    display:grid!important;
    place-items:center!important;
    border-radius:7px!important;
    background:rgba(255,255,255,.06)!important;
    color:#abc9f3!important;
    font-size:10px!important;
    box-shadow:none!important;
}

.sidebar .menu-link:hover,
.sidebar a.menu-link:hover{
    background:rgba(255,255,255,.055)!important;
    border-color:rgba(255,255,255,.055)!important;
    color:#fff!important;
    transform:none!important;
}

.sidebar .menu-link.active,
.sidebar a.menu-link.active{
    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;
    margin:0 0 2px!important;
    padding:0 9px!important;
    background:linear-gradient(135deg,#246fe9,#3b88f6)!important;
    border-color:rgba(105,166,255,.35)!important;
    color:#fff!important;
    box-shadow:0 4px 11px rgba(35,111,233,.16)!important;
    transform:none!important;
}
.sidebar .menu-link.active i,
.sidebar a.menu-link.active i{
    background:rgba(255,255,255,.16)!important;
    color:#fff!important;
}
.sidebar .menu-link::before,
.sidebar .menu-link::after{
    display:none!important;
}

/* ---------- 2. DIRECT PAGE HEADERS MUST START AFTER SIDEBAR ---------- */
/* Some old admin pages use a body-level .header while Dashboard uses
   a topbar inside .main. Only body-level headers receive the offset. */
body > .header,
body > header.header,
body > .topbar,
body > header.topbar,
body > .admin-header,
body > header.admin-header{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
    box-sizing:border-box!important;
    left:auto!important;
    right:0!important;
}

/* Headers already inside .main must NOT receive a second offset */
.main > .header,
.main > .topbar,
.main > .admin-header,
.admin-main > .header,
.admin-main > .topbar,
.odisha-style-main > .header,
.odisha-style-main > .topbar{
    margin-left:0!important;
    width:100%!important;
    max-width:100%!important;
}

/* ---------- 3. ONE EXACT RIGHT-SIDE WORKSPACE GEOMETRY ---------- */
.main,
.admin-main,
.odisha-style-main{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
    min-width:0!important;
    box-sizing:border-box!important;
}

/* Pages that put .page/.content directly under body */
body > .page,
body > .content{
    margin-left:230px!important;
    margin-right:0!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
    min-width:0!important;
    box-sizing:border-box!important;
}

/* Old two-column shells must never reserve another sidebar column */
.layout,
.admin-shell,
.odisha-style-admin-layout{
    display:block!important;
    width:100%!important;
    max-width:100%!important;
    min-width:0!important;
    margin:0!important;
}

/* Nested page wrappers fill the right workspace exactly */
.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    width:100%!important;
    max-width:100%!important;
    min-width:0!important;
    margin-left:0!important;
    margin-right:0!important;
    box-sizing:border-box!important;
}

/* Standard dashboard-like internal spacing */
.main,
.admin-main,
.odisha-style-main,
body > .page,
body > .content{
    padding-left:14px!important;
    padding-right:14px!important;
}

.page,
.content{
    min-width:0!important;
    box-sizing:border-box!important;
}

/* ---------- 4. NO WHOLE-PAGE SIDEWAYS SCROLL ---------- */
html,
body{
    width:100%!important;
    max-width:100%!important;
    overflow-x:hidden!important;
}

.card,
.panel,
.form-wrap,
.profile-manager,
.editor-shell,
.settings-content,
.page,
.content,
.page-head,
.page-heading,
.stats,
.stats-grid,
.mini-stats,
.filters,
.form-grid,
.info-grid,
.details-grid,
.content-grid,
.summary-grid{
    min-width:0!important;
    max-width:100%!important;
    box-sizing:border-box!important;
}

/* Wide data stays scrollable inside its own card only */
.table-wrap,
.pricing-table-wrap,
.season-price-table-wrap{
    width:100%!important;
    max-width:100%!important;
    overflow-x:auto!important;
    overflow-y:hidden!important;
    -webkit-overflow-scrolling:touch;
}

/* ---------- 5. RESPONSIVE: SAME HAMBURGER / DRAWER ON ALL PAGES ---------- */
@media(max-width:850px){
    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
        padding-left:58px!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
        padding-left:10px!important;
        padding-right:10px!important;
    }

    .layout,
    .admin-shell,
    .odisha-style-admin-layout{
        display:block!important;
        width:100%!important;
    }

    .stats,
    .stats-grid,
    .mini-stats{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }

    .filters,
    .form-grid,
    .details-grid,
    .info-grid,
    .summary-grid,
    .content-grid{
        grid-template-columns:1fr!important;
    }
}

@media(max-width:560px){
    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        padding-left:54px!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        padding-left:7px!important;
        padding-right:7px!important;
    }

    .stats,
    .stats-grid,
    .mini-stats,
    .overview-grid{
        grid-template-columns:1fr!important;
    }

    .page-head,
    .page-heading{
        gap:7px!important;
    }

    .page-head h1,
    .page-heading h1{
        font-size:22px!important;
    }
}



/* ============================================================
   TRAVSCOPE SINGLE CLEAN PANEL
   Exactly one desktop sidebar and one mobile hamburger.
============================================================ */

.sidebar-brand-text{
    display:flex;
    flex-direction:column;
    gap:2px;
    min-width:0;
}
.sidebar-brand-text strong{
    color:#fff;
    font-size:13px;
    line-height:1;
    font-weight:800;
}
.sidebar-brand-text small{
    color:#8fa8c7;
    font-size:8px;
    line-height:1.1;
    font-weight:700;
    white-space:nowrap;
}

.sidebar{
    width:230px!important;
    min-width:230px!important;
    max-width:230px!important;
    position:fixed!important;
    left:0!important;
    top:0!important;
    bottom:0!important;
    z-index:1100!important;
    overflow:hidden!important;
}

.sidebar-menu{
    overflow-y:auto!important;
    overflow-x:hidden!important;
    scrollbar-width:none!important;
}
.sidebar-menu::-webkit-scrollbar{display:none!important}

.main,
.admin-main,
.odisha-style-main{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
    min-width:0!important;
}

body > .page,
body > .content{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
}

body > .header,
body > header.header,
body > .topbar,
body > header.topbar,
body > .admin-header,
body > header.admin-header{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
}

.main > .topbar,
.main > .header,
.admin-main > .topbar,
.admin-main > .header,
.odisha-style-main > .topbar,
.odisha-style-main > .header{
    margin-left:0!important;
    width:100%!important;
    max-width:100%!important;
}

.ts-mobile-sidebar-toggle{
    display:none!important;
}

.ts-sidebar-overlay{
    display:none!important;
}

@media(max-width:850px){
    .sidebar{
        transform:translateX(-100%)!important;
        transition:transform .18s ease!important;
        width:230px!important;
        min-width:230px!important;
        max-width:230px!important;
    }

    .sidebar.ts-sidebar-open{
        transform:translateX(0)!important;
    }

    .ts-mobile-sidebar-toggle{
        display:flex!important;
        position:fixed!important;
        top:10px!important;
        left:10px!important;
        z-index:1305!important;
        width:40px!important;
        height:40px!important;
        padding:0!important;
        align-items:center!important;
        justify-content:center!important;
        border:1px solid #d9e3ee!important;
        border-radius:10px!important;
        background:#fff!important;
        color:#12233b!important;
        box-shadow:0 5px 16px rgba(15,23,42,.12)!important;
    }

    .ts-mobile-sidebar-toggle .ts-bars{
        width:18px!important;
        height:14px!important;
        display:flex!important;
        flex-direction:column!important;
        justify-content:space-between!important;
    }

    .ts-mobile-sidebar-toggle .ts-bars span{
        display:block!important;
        width:100%!important;
        height:2px!important;
        border-radius:999px!important;
        background:currentColor!important;
    }

    .ts-sidebar-overlay.ts-sidebar-open{
        display:block!important;
        position:fixed!important;
        inset:0!important;
        z-index:1090!important;
        background:rgba(4,12,25,.48)!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content,
    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
    }

    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        padding-left:58px!important;
    }
}



/* STEP 07 - PACKAGE READINESS + CLONE */
.package-readiness{min-width:128px;max-width:170px}
.package-readiness-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px}
.package-readiness-top strong{font:800 12px/1 'DM Sans',sans-serif;color:#1e293b}
.package-readiness-top span{font:800 8px/1 'DM Sans',sans-serif;letter-spacing:.05em;padding:4px 6px;border-radius:999px}
.package-readiness-top span.ready{background:#e9fbf3;color:#087a55}
.package-readiness-top span.needs-work{background:#fff4e5;color:#a65d00}
.package-readiness-track{height:6px;border-radius:999px;background:#e8eef5;overflow:hidden}
.package-readiness-track i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#2563eb,#10b981)}
.package-readiness small{display:block;margin-top:5px;color:#7b8798;font-size:9px;line-height:1.25}
.clone-package-btn{color:#6d4bd1!important;background:#f3efff!important;border-color:#ded4ff!important}
.clone-package-btn:hover{background:#e9e1ff!important}
</style>


<style id="travscope-package-box-colours-only">
/* TRAVSCOPE PACKAGE MANAGEMENT — COLOUR-ONLY VISUAL OVERRIDES */

/* Main cards / panels: soft professional alternating colours */
.card:nth-of-type(4n+1),
.panel:nth-of-type(4n+1),
.section-card:nth-of-type(4n+1){
    background:linear-gradient(135deg,#eef7ff,#fbfdff)!important;
    border-color:#cfe4f8!important;
}
.card:nth-of-type(4n+2),
.panel:nth-of-type(4n+2),
.section-card:nth-of-type(4n+2){
    background:linear-gradient(135deg,#eefaf4,#fbfefc)!important;
    border-color:#cee8da!important;
}
.card:nth-of-type(4n+3),
.panel:nth-of-type(4n+3),
.section-card:nth-of-type(4n+3){
    background:linear-gradient(135deg,#f5efff,#fdfbff)!important;
    border-color:#dfd2f7!important;
}
.card:nth-of-type(4n+4),
.panel:nth-of-type(4n+4),
.section-card:nth-of-type(4n+4){
    background:linear-gradient(135deg,#fff5e8,#fffdf9)!important;
    border-color:#f0dbbc!important;
}

/* Form boxes */
.form-grid > .form-group:nth-child(4n+1),
.form-row > .form-group:nth-child(4n+1){
    background:#eef7ff!important;border-color:#cfe4f8!important;
}
.form-grid > .form-group:nth-child(4n+2),
.form-row > .form-group:nth-child(4n+2){
    background:#eefaf4!important;border-color:#cee8da!important;
}
.form-grid > .form-group:nth-child(4n+3),
.form-row > .form-group:nth-child(4n+3){
    background:#f5efff!important;border-color:#dfd2f7!important;
}
.form-grid > .form-group:nth-child(4n+4),
.form-row > .form-group:nth-child(4n+4){
    background:#fff5e8!important;border-color:#f0dbbc!important;
}

/* Apply only visual box treatment; no dimensions/text sizing changed */
.form-grid > .form-group,
.form-row > .form-group{
    border-style:solid!important;
}

/* Stats / summary boxes */
.stats .stat:nth-child(4n+1), .stat-card:nth-child(4n+1){
    background:linear-gradient(135deg,#e8f4ff,#f8fcff)!important;border-color:#c5e0f8!important;
}
.stats .stat:nth-child(4n+2), .stat-card:nth-child(4n+2){
    background:linear-gradient(135deg,#eaf9f1,#f8fdfb)!important;border-color:#c6e7d5!important;
}
.stats .stat:nth-child(4n+3), .stat-card:nth-child(4n+3){
    background:linear-gradient(135deg,#fff3e3,#fffaf4)!important;border-color:#f0d6b2!important;
}
.stats .stat:nth-child(4n+4), .stat-card:nth-child(4n+4){
    background:linear-gradient(135deg,#f1eaff,#fbf9ff)!important;border-color:#d9c9f5!important;
}

/* Itinerary/day boxes */
.itinerary-day:nth-child(4n+1), .itinerary-item:nth-child(4n+1){
    background:#eef7ff!important;border-color:#cfe4f8!important;
}
.itinerary-day:nth-child(4n+2), .itinerary-item:nth-child(4n+2){
    background:#eefaf4!important;border-color:#cee8da!important;
}
.itinerary-day:nth-child(4n+3), .itinerary-item:nth-child(4n+3){
    background:#f5efff!important;border-color:#dfd2f7!important;
}
.itinerary-day:nth-child(4n+4), .itinerary-item:nth-child(4n+4){
    background:#fff5e8!important;border-color:#f0dbbc!important;
}

/* Pricing / child pricing / import / gallery-type boxes */
.pricing-card,.price-card,.pricing-section{
    background:linear-gradient(135deg,#eaf7ff,#fbfdff)!important;border-color:#c9e2f7!important;
}
.child-pricing-card,.child-price-card,.child-pricing-section{
    background:linear-gradient(135deg,#f3edff,#fcfaff)!important;border-color:#ddd0f5!important;
}
.pdf-import-card,.import-card,.ai-import-card{
    background:linear-gradient(135deg,#fff4e5,#fffaf4)!important;border-color:#efd6b3!important;
}
.media-card,.gallery-card,.media-library{
    background:linear-gradient(135deg,#eafff4,#f9fffc)!important;border-color:#c5e9d5!important;
}

/* Table surfaces */
table thead th{background:#eef5fc!important;}
table tbody tr:nth-child(even){background:#fbfdff!important;}
table tbody tr:hover{background:#f2f8ff!important;}

/* Modal/search/selected media boxes */
.media-modal-search,.pexels-search-row,.search-box,.filter-box{
    background:#f3f8ff!important;border-color:#d7e5f4!important;
}
.chosen-image-panel,.selected-image,.image-preview-box{
    background:#ecfff5!important;border-color:#c0e8d3!important;
}

/* Keep inputs themselves clean/readable */
.form-control,input[type="text"],input[type="number"],input[type="date"],input[type="url"],
input[type="email"],select,textarea{
    background-color:rgba(255,255,255,.88)!important;
}
</style>


<style id="travscope-package-requested-fix-only">
/* ============================================================
   REQUESTED PACKAGE EDITOR FIX ONLY
============================================================ */

/* Show nested Child Age & Price fields inside the active Pricing section. */
body.package-editor-mode
#package-form
.form-grid > .form-group.package-section-active
.form-group{
    display:block !important;
}

body.package-editor-mode
#package-form
.form-group.package-section-active
.child-price-grid{
    display:grid !important;
}

/* Hide PDF browse/import UI only while Add/Edit Package editor is open.
   On the normal Package Management home/list page it remains visible. */
body.package-editor-mode #openPdfImport,
body.package-editor-mode .pdf-import-btn,
body.package-editor-mode #pdfImportBox{
    display:none !important;
}

/* Hide duplicate TRAVSCOPE ADMIN header on this page. */
body > header.header,
body > .header{
    display:none !important;
}

/* Reclaim top space for Package Management workspace. */
body.package-editor-mode .main{
    height:100vh !important;
}

body.package-editor-mode #package-form{
    height:calc(100vh - 72px) !important;
}

body:not(.package-editor-mode) .main{
    padding-top:15px !important;
}

@media(max-width:850px){
    body > header.header,
    body > .header{
        display:none !important;
    }

    body.package-editor-mode .main{
        height:auto !important;
        min-height:100vh !important;
    }

    body.package-editor-mode #package-form{
        height:auto !important;
        min-height:calc(100vh - 70px) !important;
    }
}
</style>


<style id="travscope-child-price-row-button-layout">
.child-price-head{
    display:flex !important;
    align-items:flex-start !important;
    justify-content:space-between !important;
    gap:14px !important;
}

#addPackagePriceRowFromChild{
    flex:0 0 auto !important;
    white-space:nowrap !important;
}

@media(max-width:640px){
    .child-price-head{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    #addPackagePriceRowFromChild{
        width:100% !important;
    }
}
</style>


<style id="travscope-independent-child-price-row-style">
.child-price-table-wrap{
    width:100%;
    overflow-x:auto;
}
.child-price-table-head,
.child-price-row{
    display:grid;
    grid-template-columns:minmax(130px,1fr) minmax(130px,1fr) minmax(170px,1.25fr) 70px;
    gap:10px;
    align-items:center;
}
.child-price-table-head{
    padding:10px 12px;
    margin-bottom:8px;
    border-radius:10px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}
.child-price-row{
    padding:8px 0;
}
.child-price-rows{
    min-width:600px;
}
@media(max-width:700px){
    .child-price-table-head,
    .child-price-row{
        grid-template-columns:1fr 1fr 1.2fr 56px;
    }
}
</style>


<!-- TRAVSCOPE PRO UNIFORM UI -->
<script>document.documentElement.classList.add('ts-pro-preload');</script>
<link rel="stylesheet" href="assets/travscope-pro-admin.css?v=20260916-v2">
<style id="travscope-v2450-compact-package-directory">
/* V24.50 – package listing only: merged KPIs and filter/table directory.
   All table action forms, POST names, filters, price/readiness logic are untouched. */
body:not(.package-editor-mode) .main{
    padding-top:12px !important;
}
.package-master-head{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:14px !important;
    padding:12px 15px !important;
    border:1px solid #dce7f3 !important;
    border-left:4px solid #0b74ff !important;
    border-radius:12px !important;
    background:linear-gradient(115deg,#fff,#f6fbff) !important;
    box-shadow:0 5px 16px rgba(15,38,77,.04) !important;
    margin:0 0 9px !important;
}
.package-master-summary{display:flex;flex:1 1 auto;flex-wrap:wrap;align-items:center;gap:7px 15px;min-width:0}
.package-master-titleline{display:flex;align-items:center;flex-wrap:wrap;gap:9px 11px;min-width:0}
.package-master-head h1{font-size:20px !important;line-height:1.3 !important;white-space:nowrap;margin:0 !important}
.package-master-total{display:inline-flex;align-items:center;gap:5px;border:1px solid #cddff8;border-radius:7px;background:#eaf3ff;color:#124b9d;padding:5px 8px;font-size:10px;font-weight:700;white-space:nowrap}
.package-master-total strong{font-size:14px;font-weight:850;font-variant-numeric:tabular-nums}
.package-master-metrics{display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-width:0}
.package-master-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 7px;border-radius:7px;border:1px solid #e0e7ef;background:#f8fafc;color:#41536c;white-space:nowrap;font-size:10px;font-weight:650;line-height:1.1}
.package-master-pill strong{font-weight:850;font-size:12px;font-variant-numeric:tabular-nums;color:#162a44}
.package-master-pill.is-active{background:#eafbf2;border-color:#caefdc;color:#087650}
.package-master-pill.is-draft{background:#fff7eb;border-color:#f6dfb8;color:#9a5c0d}
.package-master-pill.is-featured{background:#f3efff;border-color:#e1d6ff;color:#6742b5}
.package-master-pill.is-bestseller{background:#eafaff;border-color:#c8eff8;color:#04758c}
.package-master-head .page-head-actions{display:flex !important;flex:0 0 auto;align-items:center;justify-content:flex-end;gap:6px !important;flex-wrap:wrap}
.package-master-head .page-head-actions .pdf-import-btn,.package-master-head .page-head-actions .add-btn{height:34px !important;min-height:34px !important;padding:0 10px !important;border-radius:7px !important;font-size:10px !important;white-space:nowrap}
.package-directory{margin:0 0 9px !important;border:1px solid #dfe7ef !important;border-radius:11px !important;overflow:hidden}
.package-directory-toolbar{display:grid;grid-template-columns:minmax(130px,160px) minmax(0,1fr);align-items:center;gap:10px;padding:10px 11px;background:linear-gradient(180deg,#fff,#fbfdff);border-bottom:1px solid #e5ebf2}
.package-directory-caption{display:flex;align-items:center;flex-wrap:wrap;gap:5px 8px;min-width:0}
.package-directory-caption h2{color:#0d2c50;font-size:13px !important;line-height:1.2;margin:0}
.package-directory-count{font-size:10px;color:#667992;white-space:nowrap;font-weight:700}
.package-directory .package-directory-filters{display:grid !important;grid-template-columns:minmax(145px,2fr) repeat(3,minmax(100px,1fr)) auto !important;align-items:center;gap:6px !important;margin:0;min-width:0}
.package-directory .package-directory-filters .control{height:34px !important;min-height:34px !important;min-width:0 !important;font-size:11px !important;padding:0 8px !important;border:1px solid #d7e2ed !important;border-radius:7px !important;background-color:#fff !important}
.package-directory .package-directory-filters .filter-btn{height:34px !important;min-height:34px !important;min-width:76px;padding:0 11px !important;border-radius:7px !important;font-size:10px !important;white-space:nowrap}
.package-directory .table-wrap{border:0;margin:0;max-width:100%;overflow-x:auto}
.package-directory .table-wrap table{margin:0;width:100%}
.package-directory .table-wrap th{padding:8px 9px !important;background:#f2f6fb !important;font-size:9px !important;letter-spacing:.25px}
.package-directory .table-wrap td{padding:8px 9px !important;font-size:11px !important;vertical-align:middle}
.package-directory .table-wrap tbody tr:hover{background:#f8fbff}
.package-directory .table-wrap .package-cell strong{max-width:290px !important;font-size:11px !important;line-height:1.3}
.package-directory .table-wrap .package-cell span{font-size:9px !important}
.package-directory .table-wrap .package-thumb{width:50px !important;height:40px !important;flex:0 0 50px !important}
.package-directory .table-wrap .actions{display:flex !important;gap:3px !important;flex-wrap:nowrap !important}
.package-directory .table-wrap .actions form{margin:0 !important}
.package-directory .table-wrap .icon-btn{min-width:28px !important;width:28px !important;height:28px !important;min-height:28px !important;padding:0 !important;display:inline-grid !important;place-items:center !important;font-size:11px !important}
/* No page-wide horizontal scroll: keep the wide row-wise table inside its own pane. */
@media(max-width:1360px){
  .package-directory-toolbar{grid-template-columns:1fr;gap:7px}
  .package-directory-caption{justify-content:space-between}
}
@media(max-width:1080px){
  .package-master-head{align-items:flex-start !important;flex-direction:column !important}
  .package-master-head .page-head-actions{width:100%;justify-content:flex-start}
  .package-directory .package-directory-filters{grid-template-columns:minmax(160px,2fr) repeat(2,minmax(105px,1fr)) auto !important}
  .package-directory .package-directory-filters select[name="destination"]{grid-column:span 2}
  .package-directory .package-directory-filters .filter-btn{grid-column:span 2}
}
@media(max-width:700px){
  .package-master-head{padding:11px !important;gap:10px !important}
  .package-master-head h1{font-size:19px !important;white-space:normal}
  .package-master-summary{gap:8px}
  .package-master-metrics{gap:4px}
  .package-master-pill{font-size:10px;padding:5px 6px}
  .package-directory-toolbar{padding:9px}
  .package-directory .package-directory-filters{grid-template-columns:repeat(2,minmax(0,1fr)) !important}
  .package-directory .package-directory-filters input[name="q"]{grid-column:1/-1}
  .package-directory .package-directory-filters select[name="destination"]{grid-column:auto}
  .package-directory .package-directory-filters .filter-btn{grid-column:1/-1}
  .package-directory .package-directory-filters .control,.package-directory .package-directory-filters .filter-btn{height:38px !important;min-height:38px !important}
}
@media(max-width:420px){
  .package-master-head .page-head-actions .pdf-import-btn,.package-master-head .page-head-actions .add-btn{flex:1 1 auto;justify-content:center}
  .package-directory .package-directory-filters{grid-template-columns:1fr !important}
  .package-directory .package-directory-filters > *{grid-column:1/-1 !important}
}
/* Add/Edit already has its own independent one-page layout; no statistics there. */
body.package-editor-mode .package-master-total,body.package-editor-mode .package-master-metrics{display:none !important}
</style>

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-admin-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="admin"></script>
</head>

<body class="<?= (
    isset($_GET['edit'])
    || isset($_GET['new'])
) ? 'package-editor-mode' : ''; ?>">

<button type="button"
        class="ts-mobile-sidebar-toggle"
        id="tsMobileSidebarToggle"
        aria-label="Open admin menu"
        aria-controls="sidebar"
        aria-expanded="false">
    <span class="ts-bars" aria-hidden="true">
        <span></span><span></span><span></span>
    </span>
</button>
<div class="ts-sidebar-overlay" id="tsSidebarOverlay" aria-hidden="true"></div>

<!-- =========================================================
     TRAVSCOPE SINGLE CONSTANT ADMIN SIDEBAR
========================================================= -->
<aside class="sidebar" id="sidebar">
    <a href="admin-dashboard.php" class="sidebar-logo">
        <div class="logo-icon"><i class="fa-solid fa-plane"></i></div>
        <div class="sidebar-brand-text">
            <strong>TRAVSCOPE</strong>
            <small>Travel Business System</small>
        </div>
    </a>

    <nav class="sidebar-menu">
        <div class="menu-title">OVERVIEW</div>

        <a href="admin-dashboard.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-dashboard.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </a>

        <div class="menu-title">SALES & CRM</div>

        <a href="admin-enquiries.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-enquiries.php','admin-enquiry-details.php','admin-crm.php'], true) ? 'active' : ''; ?>">
            <i class="fa-regular fa-message"></i><span>Enquiries</span>
        </a>

        <a href="admin-customers.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-customers.php','admin-users.php','admin-user-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i><span>Customers</span>
        </a>
<div class="menu-title">TRAVEL PRODUCTS</div>

        <a href="admin-destinations.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-destinations.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-earth-americas"></i><span>Destinations</span>
        </a>

        <a href="admin-packages.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-packages.php','admin-package-images.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-suitcase-rolling"></i><span>Packages</span>
        </a>

        <a href="admin-hotels.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-hotels.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-hotel"></i><span>Hotel Master</span>
        </a>


        <div class="menu-title">SUPPLIERS</div>

        <a href="admin-suppliers.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-suppliers.php','admin-supplier-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-handshake"></i><span>Suppliers</span>
        </a>

        <div class="menu-title">OPERATIONS</div>

        <a href="admin-bookings.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-bookings.php','admin-booking-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
        </a>

        <a href="admin-cancellations.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-cancellations.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-ban"></i><span>Cancellations</span>
        </a>

        <div class="menu-title">FINANCE</div>

        <a href="admin-payments.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-payments.php','admin-payment-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-credit-card"></i><span>Payments</span>
        </a>

        <div class="menu-title">MARKETING</div>

        <a href="admin-coupons.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-coupons.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-ticket"></i><span>Coupons</span>
        </a>

        <a href="admin-reviews.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-reviews.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-star"></i><span>Reviews</span>
        </a>

        <div class="menu-title">CONTENT</div>

        <a href="admin-blogs.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-blogs.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-pen-nib"></i><span>Travel Stories</span>
        </a>

        <a href="admin-web-gallery.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-web-gallery.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-images"></i><span>Media Library</span>
        </a>

        <div class="menu-title">WEBSITE</div>

        <a href="admin-pages.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-pages.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-file-lines"></i><span>Website Content</span>
        </a>

        <a href="<?= defined('BASE_URL') ? e(BASE_URL) : '/'; ?>" target="_blank" class="menu-link">
            <i class="fa-solid fa-globe"></i><span>View Website</span>
        </a>

        <div class="menu-title">ADMINISTRATION</div>

        <a href="admin-settings.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-settings.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gear"></i><span>Settings</span>
        </a>

        <a href="logout.php" class="menu-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Logout</span>
        </a>
    </nav>
</aside>



<!-- UNIVERSAL MOBILE SIDEBAR CONTROLS -->







<!-- HEADER -->

<header class="header">

<a
    href="<?= e(BASE_URL . 'admin-dashboard.php'); ?>"
    class="logo"
>
    <div class="logo-box">
        <i class="fa-solid fa-plane"></i>
    </div>

    TRAV<span>SCOPE</span>
    ADMIN
</a>


<div class="admin-user">

    <div class="admin-avatar">
        <?= e(
            strtoupper(
                mb_substr(
                    $admin['name'],
                    0,
                    1
                )
            )
        ); ?>
    </div>

    <div>
        <strong>
            <?= e($admin['name']); ?>
        </strong>

        <small>
            <?= e(
                ucfirst(
                    str_replace(
                        '_',
                        ' ',
                        $admin['role']
                    )
                )
            ); ?>
        </small>
    </div>

</div>

</header>


<div class="layout">


<!-- SIDEBAR -->

<!-- =========================================================
     CONSTANT TRAVSCOPE ADMIN SIDEBAR
========================================================= -->




<!-- MAIN -->

<main class="main">


<div class="page-head package-master-head">

<div class="package-master-summary">
    <div class="package-master-titleline">
        <h1>Package Management</h1>
        <span class="package-master-total" title="Total packages in Package Master">
            <i class="fa-solid fa-suitcase-rolling" aria-hidden="true"></i>
            <strong><?= number_format($stats['total']); ?></strong> Total Packages
        </span>
    </div>
    <div class="package-master-metrics" aria-label="Live package counts">
        <span class="package-master-pill is-active"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Active <strong><?= number_format($stats['active']); ?></strong></span>
        <span class="package-master-pill is-draft"><i class="fa-solid fa-file-pen" aria-hidden="true"></i> Drafts <strong><?= number_format($stats['draft']); ?></strong></span>
        <span class="package-master-pill is-featured"><i class="fa-solid fa-star" aria-hidden="true"></i> Featured <strong><?= number_format($stats['featured']); ?></strong></span>
        <span class="package-master-pill is-bestseller"><i class="fa-solid fa-fire" aria-hidden="true"></i> Bestsellers <strong><?= number_format($stats['bestseller']); ?></strong></span>
    </div>
</div>


<div class="page-head-actions">
<a href="<?= e(BASE_URL . 'admin-hotels.php'); ?>" class="pdf-import-btn" style="text-decoration:none;background:#eefbf5;color:#087c59;border-color:#cfeedd;">
    <i class="fa-solid fa-hotel"></i> Hotel Master
</a>
<a
    href="<?= e(BASE_URL . 'admin-packages.php?new=1#package-form'); ?>"
    class="add-btn"
>
    <i class="fa-solid fa-plus"></i>
    Add New Package
</a>
</div>

</div>


<?php if ($success !== ''): ?>

<div class="alert success">
    <i class="fa-solid fa-circle-check"></i>
    <?= e($success); ?>
</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="alert error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= e($error); ?>
</div>

<?php endif; ?>



<style id="travscope-bulk-package-master-style">
.package-bulk-toolbar{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:12px;padding:10px 12px;border-bottom:1px solid #e4eaf2;background:#fbfdff}
.package-bulk-select-all{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:750;color:#18365b;cursor:pointer}
.package-bulk-select-all input,.package-select-checkbox{width:17px;height:17px;accent-color:#1769e5;cursor:pointer}
.package-bulk-count{font-size:12px;font-weight:700;color:#60758c}
.package-bulk-form{margin:0!important}
.package-bulk-delete{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:8px 13px;border:1px solid #fecaca;border-radius:8px;background:#fff2f2;color:#b42318;font-size:12px;font-weight:800;cursor:pointer}
.package-bulk-delete:hover:not(:disabled){background:#fee2e2}
.package-bulk-delete:disabled{opacity:.48;cursor:not-allowed}
.package-directory .table-wrap th.package-bulk-check-cell,.package-directory .table-wrap td.package-bulk-check-cell{width:52px;min-width:52px;padding:8px!important;text-align:center;vertical-align:middle}
@media(max-width:640px){.package-bulk-toolbar{justify-content:space-between;gap:9px;padding:9px}.package-bulk-delete{min-height:40px}}
</style>

<!-- PACKAGE MASTER DIRECTORY: unified search + listing toolbar -->
<section class="card package-directory" aria-label="Package directory">
    <div class="package-directory-toolbar">
        <div class="package-directory-caption">
            <h2>Travel Packages</h2>
            <span class="package-directory-count"><?= number_format(count($packages)); ?> found</span>
        </div>
<form
    method="get"
    class="filters package-directory-filters"
>

<input
    type="text"
    name="q"
    class="control"
    placeholder="Search title, code, destination..."
    value="<?= e($search); ?>"
>


<select
    name="status"
    class="control"
>
    <option value="">
        All Status
    </option>

    <option
        value="active"
        <?= $statusFilter === 'active'
            ? 'selected'
            : ''; ?>
    >
        Active
    </option>

    <option
        value="inactive"
        <?= $statusFilter === 'inactive'
            ? 'selected'
            : ''; ?>
    >
        Inactive
    </option>

    <option
        value="draft"
        <?= $statusFilter === 'draft'
            ? 'selected'
            : ''; ?>
    >
        Draft
    </option>
</select>


<select
    name="type"
    class="control"
>
    <option value="">
        All Types
    </option>

<?php foreach ($packageTypes as $packageTypeRow): ?>
<?php
$filterTypeSlug = strtolower(trim((string)(
    $packageTypeRow['slug']
    ?? $packageTypeRow['code']
    ?? $packageTypeRow['type']
    ?? ''
)));
$filterTypeName = trim((string)(
    $packageTypeRow['name']
    ?? $packageTypeRow['title']
    ?? ucwords(str_replace(['-', '_'], ' ', $filterTypeSlug))
));
$filterTypeStatus = strtolower(trim((string)(
    $packageTypeRow['status'] ?? 'active'
)));

if ($filterTypeSlug === '' || $filterTypeStatus !== 'active') {
    continue;
}
?>
<option
    value="<?= e($filterTypeSlug); ?>"
    <?= $typeFilter === $filterTypeSlug ? 'selected' : ''; ?>
>
    <?= e($filterTypeName); ?>
</option>
<?php endforeach; ?>

</select>


<select
    name="destination"
    class="control"
>

<option value="">
    All Destinations
</option>

<?php foreach ($destinations as $destination): ?>

<option
    value="<?= (int)$destination['id']; ?>"
    <?= $destinationFilter === (int)$destination['id']
        ? 'selected'
        : ''; ?>
>
    <?= e($destination['name']); ?>
    -
    <?= e($destination['country']); ?>
</option>

<?php endforeach; ?>

</select>


<button
    type="submit"
    class="filter-btn"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search
</button>

</form>
    </div>

<?php if ($packages): ?>

<div class="package-bulk-toolbar" role="group" aria-label="Bulk package actions">
    <label class="package-bulk-select-all">
        <input type="checkbox" id="selectAllPackages" aria-label="Select all visible packages">
        Select All Visible
    </label>
    <span id="selectedPackageCount" class="package-bulk-count" aria-live="polite">0 selected</span>
    <form id="bulkPackageDeleteForm" method="post" class="package-bulk-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
        <input type="hidden" name="action" value="bulk_delete">
        <button type="submit" class="package-bulk-delete" id="deleteSelectedPackages" disabled>
            <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete Selected
        </button>
    </form>
</div>

<div class="table-wrap">

<table>

<thead>

<tr>
    <th class="package-bulk-check-cell" scope="col">Select</th>
    <th>Package</th>
    <th>Destination</th>
    <th>Duration</th>
    <th>Price</th>
    <th>Status</th>
    <th>Readiness</th>
    <th>Activity</th>
    <th>Actions</th>
</tr>

</thead>

<tbody>

<?php foreach ($packages as $package): ?>

<?php

$image =
    adminPackageImageUrl(
        $package['main_image']
        ?? ''
    );

$packageReadiness = packagePublishReadiness($pdo, (int)$package['id']);

$displayPrice =
    (float)$package['sale_price'] > 0
        ? (float)$package['sale_price']
        : ((float)($package['regular_price'] ?? 0) > 0
            ? (float)$package['regular_price']
            : (float)($package['reference_start_price'] ?? 0));

// Readiness can recover a draft's saved supplier rates after this list was
// fetched. Show the recovered reference price on this same page visit.
if ($displayPrice <= 0 && !empty($packageReadiness['has_selling_price'])) {
    $freshReferencePrice = $pdo->prepare("SELECT COALESCE(
        MIN(CASE WHEN COALESCE(price_3_star,0)>0 THEN price_3_star END),
        MIN(CASE WHEN COALESCE(price_4_star,0)>0 THEN price_4_star END),
        MIN(CASE WHEN COALESCE(price_5_star,0)>0 THEN price_5_star END)
    ) FROM package_date_prices WHERE package_id=?");
    $freshReferencePrice->execute([(int)$package['id']]);
    $displayPrice = (float)($freshReferencePrice->fetchColumn() ?: 0);
}

?>

<tr>

<td class="package-bulk-check-cell">
    <input type="checkbox" class="package-select-checkbox" name="package_ids[]"
        value="<?= (int)$package['id']; ?>" form="bulkPackageDeleteForm"
        aria-label="Select <?= e((string)$package['title']); ?> for deletion">
</td>

<td>

<div class="package-cell">

<div class="package-thumb">

<?php if ($image !== ''): ?>

<img
    src="<?= e($image); ?>"
    alt="<?= e($package['title']); ?>"
>

<?php else: ?>

<i class="fa-solid fa-image"></i>

<?php endif; ?>

</div>


<div>

<strong>
    <?= e($package['title']); ?>
</strong>

<span>
    <?= e(
        $package['package_code']
        ?: 'No package code'
    ); ?>
</span>


<div class="small-tags">

<?php if ((int)$package['featured'] === 1): ?>

<span class="mini-tag">
    Featured
</span>

<?php endif; ?>


<?php if ((int)$package['bestseller'] === 1): ?>

<span class="mini-tag">
    Bestseller
</span>

<?php endif; ?>

</div>

</div>

</div>

</td>


<td>

<strong>
    <?= e(
        $package['destination_name']
        ?: 'Not assigned'
    ); ?>
</strong>

<br>

<span style="color:#929dab;">
    <?= e(
        $package['destination_country']
        ?: ''
    ); ?>
</span>

</td>


<td>

<?= (int)$package['duration_days']; ?>
D /

<?= (int)$package['duration_nights']; ?>
N

</td>


<td>

<span class="price">

<?= e(
    $package['currency']
    ?: 'INR'
); ?>

<?= number_format(
    $displayPrice,
    2
); ?>

</span>

</td>


<td>

<span
    class="badge badge-<?= e($package['status']); ?>"
>
    <?= e($package['status']); ?>
</span>

</td>


<td>
<div class="package-readiness" title="<?= e(
    $packageReadiness['ready']
        ? 'Ready to publish'
        : ('Missing: ' . implode(', ', $packageReadiness['required_missing']))
); ?>">
    <div class="package-readiness-top">
        <strong><?= (int)$packageReadiness['score']; ?>%</strong>
        <span class="<?= $packageReadiness['ready'] ? 'ready' : 'needs-work'; ?>">
            <?= $packageReadiness['ready'] ? 'READY' : 'CHECK'; ?>
        </span>
    </div>
    <div class="package-readiness-track">
        <i style="width:<?= max(0, min(100, (int)$packageReadiness['score'])); ?>%"></i>
    </div>
    <?php if (!$packageReadiness['ready']): ?>
        <small><?= e(implode(', ', array_slice($packageReadiness['required_missing'], 0, 2))); ?><?= count($packageReadiness['required_missing']) > 2 ? ' +' . (count($packageReadiness['required_missing']) - 2) : ''; ?></small>
    <?php else: ?>
        <small>Publish-ready</small>
    <?php endif; ?>
</div>
</td>


<td>

<?= number_format(
    (int)$package['booking_count']
); ?>
 bookings

<br>

<span style="color:#929dab;">

<?= number_format(
    (int)$package['wishlist_count']
); ?>
 saved

•

<?= number_format(
    (int)$package['gallery_count']
); ?>
 photos

</span>

</td>


<td>

<div class="actions">


<!-- VIEW -->

<a
    href="<?= e(
        BASE_URL
        . 'package-details.php?slug='
        . urlencode($package['slug'])
    ); ?>"
    target="_blank"
    class="icon-btn"
    title="View package"
>
    <i class="fa-regular fa-eye"></i>
</a>


<!-- EDIT -->

<a
    href="<?= e(
        BASE_URL
        . 'admin-packages.php?edit='
        . (int)$package['id']
        . '#package-form'
    ); ?>"
    class="icon-btn"
    title="Edit package"
>
    <i class="fa-solid fa-pen"></i>
</a>


<!-- SMART OCCUPANCY PRICING -->

<a
    href="<?= e(
        BASE_URL
        . 'admin-package-occupancy.php?package_id='
        . (int)$package['id']
    ); ?>"
    class="icon-btn"
    title="Smart Occupancy Pricing"
>
    <i class="fa-solid fa-people-roof"></i>
</a>


<!-- PACKAGE SERVICES AUTOMATION -->

<a
    href="<?= e(
        BASE_URL
        . 'admin-package-services.php?package_id='
        . (int)$package['id']
    ); ?>"
    class="icon-btn"
    title="Package Services Automation"
>
    <i class="fa-solid fa-diagram-project"></i>
</a>


<!-- MANAGE GALLERY -->

<a
    href="<?= e(
        BASE_URL
        . 'admin-package-images.php?package_id='
        . (int)$package['id']
    ); ?>"
    class="icon-btn"
    title="Manage Gallery"
>
    <i class="fa-regular fa-images"></i>
</a>


<!-- FEATURED -->

<form method="post">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($csrfToken); ?>"
>

<input
    type="hidden"
    name="action"
    value="toggle_featured"
>

<input
    type="hidden"
    name="package_id"
    value="<?= (int)$package['id']; ?>"
>

<button
    type="submit"
    class="icon-btn"
    title="Toggle featured"
>
    <i class="<?= (int)$package['featured'] === 1
        ? 'fa-solid'
        : 'fa-regular'; ?> fa-star"></i>
</button>

</form>


<!-- BESTSELLER -->

<form method="post">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($csrfToken); ?>"
>

<input
    type="hidden"
    name="action"
    value="toggle_bestseller"
>

<input
    type="hidden"
    name="package_id"
    value="<?= (int)$package['id']; ?>"
>

<button
    type="submit"
    class="icon-btn"
    title="Toggle bestseller"
>
    <i class="fa-solid fa-fire"></i>
</button>

</form>


<!-- CLONE -->

<form
    method="post"
    onsubmit="return confirm('Create a Draft copy of this package with the same pricing, itinerary, images and hotel assignments?');"
>

<input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
<input type="hidden" name="action" value="clone_package">
<input type="hidden" name="package_id" value="<?= (int)$package['id']; ?>">

<button
    type="submit"
    class="icon-btn clone-package-btn"
    title="Clone package as Draft"
>
    <i class="fa-regular fa-copy"></i>
</button>

</form>


<!-- SUPPLIER & COSTING -->
<a href="<?= e(BASE_URL . 'admin-package-suppliers.php?package_id=' . (int)$package['id']); ?>" class="icon-btn" title="Supplier & Costing">
    <i class="fa-solid fa-handshake-angle"></i>
</a>

<!-- DELETE -->

<form
    method="post"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($csrfToken); ?>"
>

<input
    type="hidden"
    name="action"
    value="delete"
>

<input
    type="hidden"
    name="package_id"
    value="<?= (int)$package['id']; ?>"
>

<button
    type="submit"
    class="icon-btn danger"
    title="Delete package"
>
    <i class="fa-regular fa-trash-can"></i>
</button>

</form>


</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>
<script id="travscope-bulk-package-delete-script">
(function () {
    const bulkForm = document.getElementById('bulkPackageDeleteForm');
    const selectAll = document.getElementById('selectAllPackages');
    const countEl = document.getElementById('selectedPackageCount');
    const button = document.getElementById('deleteSelectedPackages');
    if (!bulkForm || !selectAll || !countEl || !button) return;
    const checkboxes = Array.from(document.querySelectorAll('.package-select-checkbox'));
    function updateSelection() {
        const selected = checkboxes.filter(el => el.checked).length;
        countEl.textContent = selected + ' selected';
        button.disabled = selected === 0;
        selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
        selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
    }
    selectAll.addEventListener('change', function () {
        checkboxes.forEach(el => { el.checked = selectAll.checked; });
        updateSelection();
    });
    checkboxes.forEach(el => el.addEventListener('change', updateSelection));
    bulkForm.addEventListener('submit', function (event) {
        const total = checkboxes.filter(el => el.checked).length;
        if (!total || !window.confirm('Permanently delete ' + total + ' selected package(s)? Packages with bookings or protected links will be skipped. This cannot be undone.')) {
            event.preventDefault();
        }
    });
    updateSelection();
})();
</script>

<?php else: ?>

<div class="empty">

<i class="fa-solid fa-suitcase-rolling"></i>

<strong>
    No packages found
</strong>

<p>
    Create your first travel package or change the filters.
</p>

</div>

<?php endif; ?>

</section>


<!-- ADD / EDIT FORM -->
<?php
$showPackageEditor =
    isset($_GET['new'])
    || !empty($editPackage)
    || (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && $error !== ''
        && in_array(
            (string)($_POST['action'] ?? ''),
            ['create', 'update'],
            true
        )
    );
?>

<?php if ($showPackageEditor): ?>

<section
    class="card"
    id="package-form"
>

<div class="card-head">

<div>

<h2>

<?php if ($editPackage): ?>

    Edit Package

<?php else: ?>

    Create New Package

<?php endif; ?>

</h2>

<p>
    Complete the travel package information below.
</p>

</div>


<?php if ($editPackage): ?>

<div style="display:flex;gap:8px;flex-wrap:wrap">
<a href="<?= e(BASE_URL . 'admin-package-suppliers.php?package_id='.(int)$editPackage['id']); ?>" class="btn btn-light">
    <i class="fa-solid fa-handshake-angle"></i> Supplier & Costing
</a>
<a
    href="<?= e(BASE_URL . 'admin-packages.php?new=1#package-form'); ?>"
    class="btn btn-light"
>
    <i class="fa-solid fa-plus"></i>
    New Package
</a>
</div>

<?php endif; ?>

</div>


<div class="card-body">


<form
    method="post"
    enctype="multipart/form-data"
>


<input
    type="hidden"
    name="csrf_token"
    value="<?= e($csrfToken); ?>"
>


<input
    type="hidden"
    name="action"
    value="<?= $editPackage
        ? 'update'
        : 'create'; ?>"
>


<?php if ($editPackage): ?>

<input
    type="hidden"
    name="package_id"
    value="<?= (int)$editPackage['id']; ?>"
>

<?php endif; ?>

<?php if (!$editPackage): ?>
<div class="ts-v20-package-source" style="margin-bottom:18px;padding:16px;border:1px solid #dce7f4;border-radius:14px;background:#f9fbff">
    <div style="font-weight:900;margin-bottom:5px">How do you want to create this TRAVSCOPE package?</div>
    <div style="color:#6f7f93;font-size:12px;margin-bottom:12px">A supplier package is only a sourcing/cost option. The package you sell always belongs to TRAVSCOPE.</div>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
        <div style="padding:13px;border:1px solid #bcdcff;border-radius:12px;background:#eef7ff"><strong><i class="fa-solid fa-pen"></i> Manual Package</strong><div style="font-size:11px;color:#63758b;margin-top:5px">Continue below and build your own itinerary, hotels and selling content.</div></div>
        <a href="<?= e(BASE_URL.'admin-package-import-supplier.php'); ?>" style="padding:13px;border:1px solid #d8c5ff;border-radius:12px;background:#f7f0ff;text-decoration:none;color:inherit"><strong><i class="fa-solid fa-file-import"></i> From Supplier</strong><div style="font-size:11px;color:#63758b;margin-top:5px">Choose an ACTIVE manual Supplier Package and create an editable TRAVSCOPE Draft.</div></a>
        <a href="<?= e(BASE_URL.'admin-packages.php'); ?>" style="padding:13px;border:1px solid #f0d6a8;border-radius:12px;background:#fff7e9;text-decoration:none;color:inherit"><strong><i class="fa-regular fa-copy"></i> Copy Existing</strong><div style="font-size:11px;color:#63758b;margin-top:5px">Use the Copy icon beside an existing Package Master record.</div></a>
    </div>
</div>
<?php else: ?>
<?php
$sourceType = (string)($editPackage['source_type'] ?? 'manual');
$sourceLabel = match($sourceType){'supplier_imported'=>'Supplier Imported','supplier_customized'=>'Supplier Customised','copy'=>'Copied Package',default=>'Manual TRAVSCOPE Package'};
?>
<div class="ts-v20-package-source" style="margin-bottom:18px;padding:14px;border:1px solid #dce7f4;border-radius:14px;background:#f9fbff;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div><strong><i class="fa-solid fa-diagram-project"></i> Package Source: <?= e($sourceLabel); ?></strong>
    <div style="font-size:11px;color:#63758b;margin-top:4px">TRAVSCOPE owns this package. Supplier links control internal sourcing and seasonal cost only.</div>
    <?php if ($packageSupplierPrimary): ?><div style="font-size:12px;margin-top:5px"><strong>Primary DMC:</strong> <?= e((string)$packageSupplierPrimary['company_name']); ?> · <?= e((string)($packageSupplierPrimary['supplier_package_code'] ?: $packageSupplierPrimary['supplier_package_title'])); ?></div><?php endif; ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($sourceType === 'supplier_imported' && $packageSupplierPrimary): ?>
        <a class="btn btn-light" href="<?= e(BASE_URL.'admin-packages.php?edit='.(int)$editPackage['id'].'&refresh_supplier_content=1#package-form'); ?>" onclick="return confirm('Refresh descriptions, tour highlights, package terms and day-wise itinerary from the linked Supplier Package? Existing itinerary images will be preserved.');"><i class="fa-solid fa-arrows-rotate"></i> Refresh Supplier Content</a>
        <?php endif; ?>
        <a class="btn btn-light" href="<?= e(BASE_URL.'admin-package-suppliers.php?package_id='.(int)$editPackage['id']); ?>"><i class="fa-solid fa-handshake-angle"></i> Manage <?= count($packageSupplierOptions); ?> Supplier Option<?= count($packageSupplierOptions)===1?'':'s'; ?></a>
    </div>
</div>
<?php endif; ?>

<div class="form-grid">


<!-- TITLE -->

<div class="form-group full">

<label>
    Package Title *
</label>

<input
    type="text"
    name="title"
    class="form-control"
    maxlength="220"
    required
    value="<?= e($form['title']); ?>"
    placeholder="Example: Magical Kashmir Holiday Package"
>

</div>


<!-- DESTINATION -->

<div class="form-group">

<label>
    Destination
</label>

<select
    name="destination_id"
    class="form-control"
>

<option value="">
    Select Destination
</option>

<?php foreach ($destinations as $destination): ?>

<option
    value="<?= (int)$destination['id']; ?>"
    <?= (int)$form['destination_id'] === (int)$destination['id']
        ? 'selected'
        : ''; ?>
>

<?= e($destination['name']); ?>
-
<?= e($destination['country']); ?>

<?= $destination['status'] !== 'active'
    ? ' (Inactive)'
    : ''; ?>

</option>

<?php endforeach; ?>

</select>

</div>


<!-- CODE -->

<div class="form-group">

<label>
    Package Code
</label>

<input
    type="text"
    name="package_code"
    class="form-control"
    maxlength="50"
    value="<?= e($form['package_code']); ?>"
    placeholder="Example: KASHMIR-001"
>

<div class="help">
    Optional. Leave blank for an automatic code such as PKG-YYMMDD-A1B2.
</div>

</div>


<!-- SHORT DESCRIPTION -->

<div class="form-group full">

<label>
    Short Description
</label>

<textarea
    name="short_description"
    class="form-control"
    maxlength="500"
    placeholder="Short attractive summary for package cards..."
><?= e($form['short_description']); ?></textarea>

</div>


<!-- DESCRIPTION -->

<div class="form-group full">

<label>
    Full Package Description
</label>

<textarea
    name="description"
    class="form-control large"
    placeholder="Explain the complete travel experience..."
><?= e($form['description']); ?></textarea>

</div>


<!-- DAYS -->

<div class="form-group">

<label>
    Duration Days *
</label>

<input
    type="number"
    name="duration_days"
    id="durationDays"
    class="form-control"
    min="0"
    step="1"
    inputmode="numeric"
    required
    value="<?= max(0, (int)$form['duration_days']); ?>"
>

<div class="help">
    This number automatically controls the Day-wise Itinerary rows below.
</div>

</div>


<!-- NIGHTS -->

<div class="form-group">

<label>
    Duration Nights
</label>

<input
    type="number"
    name="duration_nights"
    class="form-control"
    min="0"
    value="<?= (int)$form['duration_nights']; ?>"
>

</div>


<!-- CHILD AGE + PER CHILD PRICE -->

<div class="form-group full">

<div class="child-price-builder">

    <div class="child-price-head">
        <div>
            <h3>
                <i class="fa-solid fa-child-reaching"></i>
                Children Age & Price
            </h3>
            <p>
                Add separate child age/price rows. These rows are independent from Hotel Category Pricing.
            </p>
        </div>

        <button
            type="button"
            class="add-price-row-btn"
            id="addChildPriceRow"
        >
            <i class="fa-solid fa-plus"></i>
            Add Child Price Row
        </button>
    </div>

    <div class="child-price-table-wrap">
        <div class="child-price-table-head">
            <span>Child Age From</span>
            <span>Child Age To</span>
            <span>Price Per Child</span>
            <span>Action</span>
        </div>

        <div id="childPriceRows" class="child-price-rows">
            <?php
            $childBuilderRows = $editPackageChildPriceRows ?? [];

            if (!$childBuilderRows) {
                $childBuilderRows = [[
                    'age_from' => '',
                    'age_to' => '',
                    'price' => ''
                ]];
            }

            foreach ($childBuilderRows as $childRow):
            ?>
            <div class="child-price-row">
                <input
                    type="number"
                    name="child_row_age_from[]"
                    class="form-control"
                    min="0"
                    max="18"
                    step="1"
                    value="<?= e((string)($childRow['age_from'] ?? '')); ?>"
                    placeholder="Age from"
                >

                <input
                    type="number"
                    name="child_row_age_to[]"
                    class="form-control"
                    min="0"
                    max="18"
                    step="1"
                    value="<?= e((string)($childRow['age_to'] ?? '')); ?>"
                    placeholder="Age to"
                >

                <input
                    type="number"
                    name="child_row_price[]"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= e((string)($childRow['price'] ?? '')); ?>"
                    placeholder="Child price"
                >

                <button
                    type="button"
                    class="remove-price-row-btn remove-child-price-row"
                    title="Remove child price row"
                >
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Backward-compatible original package child fields.
         PHP mirrors the first independent child row to these values on save. -->
    <input type="hidden" name="child_age_min" value="<?= max(0, (int)($form['child_age_min'] ?? 0)); ?>">
    <input type="hidden" name="child_age_max" value="<?= max(0, (int)($form['child_age_max'] ?? 0)); ?>">
    <input type="hidden" name="child_price" value="<?= e((string)($form['child_price'] ?? '')); ?>">

</div>

</div>


<!-- DATE-WISE 3 / 4 / 5 STAR PRICING -->

<div class="form-group full">

<div class="season-price-builder">

    <div class="season-price-head">

        <div>
            <h3>
                <i class="fa-solid fa-calendar-days"></i>
                Date-wise Hotel Category Pricing
            </h3>

            <p>
                Add unlimited date periods with separate 3-star, 4-star and 5-star prices.
            </p>
        </div>

        <button
            type="button"
            class="add-price-row-btn"
            id="addPackagePriceRow"
        >
            <i class="fa-solid fa-plus"></i>
            Add Price Row
        </button>

    </div>

    <div class="season-price-table-wrap">

        <div class="season-price-table-head">
            <span>Valid From</span>
            <span>Valid To</span>
            <span>3★ Hotel</span>
            <span>4★ Hotel</span>
            <span>5★ Hotel</span>
            <span>Action</span>
        </div>

        <div id="packagePriceRows" class="package-price-rows">

            <?php
            $priceBuilderRows =
                $editPackagePriceRows
                ?: [[
                    'valid_from' => date('Y-m-d'),
                    'valid_to' => date('Y-m-d', strtotime('+30 days')),
                    'price_3_star' => '',
                    'price_4_star' => '',
                    'price_5_star' => ''
                ]];

            foreach ($priceBuilderRows as $priceRow):
            ?>

            <div class="package-price-row">

                <input
                    type="date"
                    name="price_valid_from[]"
                    class="form-control price-date-from"
                    value="<?= e($priceRow['valid_from'] ?? ''); ?>"
                    required
                >

                <input
                    type="date"
                    name="price_valid_to[]"
                    class="form-control price-date-to"
                    value="<?= e($priceRow['valid_to'] ?? ''); ?>"
                    required
                >

                <input
                    type="number"
                    name="price_3_star[]"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= e($priceRow['price_3_star'] ?? ''); ?>"
                    placeholder="3 star price"
                >

                <input
                    type="number"
                    name="price_4_star[]"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= e($priceRow['price_4_star'] ?? ''); ?>"
                    placeholder="4 star price"
                >

                <input
                    type="number"
                    name="price_5_star[]"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= e($priceRow['price_5_star'] ?? ''); ?>"
                    placeholder="5 star price"
                >

                <button
                    type="button"
                    class="remove-price-row-btn"
                    title="Remove this price row"
                >
                    <i class="fa-solid fa-trash"></i>
                </button>

            </div>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="pricing-help">
        Date periods cannot overlap. The website automatically shows the matching prices for the selected travel date.
    </div>

</div>

</div>


<!-- CURRENCY -->

<div class="form-group">

<label>
    Currency
</label>

<input
    type="text"
    name="currency"
    class="form-control"
    maxlength="10"
    value="<?= e(
        $form['currency']
        ?: 'INR'
    ); ?>"
>

</div>


<!-- TYPE -->

<div class="form-group">

<label>
    Package Type
</label>

<select name="package_type" class="form-control" required>
<option value="">Select Package Type</option>

<?php
$currentPackageType = strtolower(trim((string)($form['package_type'] ?? '')));
$currentTypeFound = false;

foreach ($packageTypes as $packageTypeRow):
    $formTypeSlug = strtolower(trim((string)(
        $packageTypeRow['slug']
        ?? $packageTypeRow['code']
        ?? $packageTypeRow['type']
        ?? ''
    )));
    $formTypeName = trim((string)(
        $packageTypeRow['name']
        ?? $packageTypeRow['title']
        ?? ucwords(str_replace(['-', '_'], ' ', $formTypeSlug))
    ));
    $formTypeStatus = strtolower(trim((string)(
        $packageTypeRow['status'] ?? 'active'
    )));

    if ($formTypeSlug === '') continue;

    if ($formTypeSlug === $currentPackageType) {
        $currentTypeFound = true;
    }

    if ($formTypeStatus !== 'active' && $formTypeSlug !== $currentPackageType) {
        continue;
    }
?>
<option
    value="<?= e($formTypeSlug); ?>"
    <?= $currentPackageType === $formTypeSlug ? 'selected' : ''; ?>
>
    <?= e($formTypeName); ?><?= $formTypeStatus !== 'active' ? ' (Inactive)' : ''; ?>
</option>
<?php endforeach; ?>

<?php if ($currentPackageType !== '' && !$currentTypeFound): ?>
<option value="<?= e($currentPackageType); ?>" selected>
    <?= e(ucwords(str_replace(['-', '_'], ' ', $currentPackageType))); ?> (Existing)
</option>
<?php endif; ?>
</select>

</div>



<!-- AUTOMATIC HOTELS -->
<div class="form-group full automatic-hotel-group">
<style>
.automatic-hotel-builder{border:1px solid #d8eee4;border-radius:13px;background:linear-gradient(135deg,#f3fff9,#f8fbff);overflow:hidden}.automatic-hotel-head{padding:14px 15px;border-bottom:1px solid #dceee5;display:flex;align-items:center;justify-content:space-between;gap:10px}.automatic-hotel-head h3{margin:0;font:800 15px Manrope;color:#0b654b}.automatic-hotel-head p{margin:4px 0 0;color:#6e7c8e;font-size:11px}.automatic-hotel-badge{padding:5px 8px;border-radius:999px;background:#dcfce7;color:#08795a;font-size:10px;font-weight:900}.automatic-hotel-location{padding:12px 13px;border-bottom:1px solid #e3ece7;display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center}.automatic-hotel-location label{font-size:10px;font-weight:900;color:#426377}.automatic-hotel-grid{padding:13px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.automatic-hotel-card{background:#fff;border:1px solid #dfe8ef;border-radius:10px;padding:11px}.automatic-hotel-card strong{display:flex;align-items:center;gap:6px;margin-bottom:7px;font-size:12px}.automatic-hotel-card strong i{color:#d97706}.automatic-hotel-meta{font-size:10px;color:#7a8797;margin-top:6px;min-height:30px}.automatic-hotel-photo{height:82px;border-radius:9px;background:#eef3f7;overflow:hidden;margin:8px 0;display:flex;align-items:center;justify-content:center;color:#8ea0b1}.automatic-hotel-photo img{width:100%;height:100%;object-fit:cover}.automatic-hotel-foot{padding:10px 13px;border-top:1px solid #e3ece7;color:#66778a;font-size:10px;display:flex;align-items:center;justify-content:space-between;gap:10px}.automatic-hotel-foot a{font-weight:800;color:#0f7e5d;text-decoration:none}@media(max-width:800px){.automatic-hotel-grid{grid-template-columns:1fr}.automatic-hotel-location{grid-template-columns:1fr}}
</style>
<div class="automatic-hotel-builder">
    <div class="automatic-hotel-head">
        <div><h3><i class="fa-solid fa-wand-magic-sparkles"></i> Destination + Location Hotel Master</h3><p>Select the main destination, then optionally narrow to a city/location. All active Hotel Master properties for that destination are shown location-wise.</p></div>
        <span class="automatic-hotel-badge">HOTEL MASTER PRO</span>
    </div>
    <div class="automatic-hotel-location">
        <label for="packageHotelLocationFilter"><i class="fa-solid fa-location-dot"></i> Hotel City / Location</label>
        <select class="form-control" name="package_hotel_location_id" id="packageHotelLocationFilter" data-selected="<?= (int)$formHotelLocationId; ?>">
            <option value="0">All locations in selected destination</option>
            <?php foreach ($packageHotelLocations as $loc): ?>
                <option value="<?= (int)$loc['id']; ?>" <?= $formHotelLocationId === (int)$loc['id'] ? 'selected' : ''; ?>><?= e((string)$loc['location_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="automatic-hotel-grid" id="automaticHotelGrid">
        <?php foreach ([3,4,5] as $star): ?>
        <div class="automatic-hotel-card">
            <strong><i class="fa-solid fa-hotel"></i> <?= $star; ?> Star Package Hotel</strong>
            <div class="automatic-hotel-photo" id="hotelPhoto<?= $star; ?>"><i class="fa-regular fa-image"></i></div>
            <select class="form-control automatic-hotel-select" name="hotel_<?= $star; ?>_star_id" data-star="<?= $star; ?>" data-selected="<?= (int)$packageHotelSelectedIds[$star]; ?>">
                <option value="0">Auto select preferred hotel</option>
                <?php foreach ($packageHotelOptionsByStar[$star] as $hotelOption): ?>
                <option value="<?= (int)$hotelOption['id']; ?>"
                    data-location-id="<?= (int)($hotelOption['location_id'] ?? 0); ?>"
                    data-location="<?= e((string)($hotelOption['location_name'] ?: ($hotelOption['city'] ?? ''))); ?>"
                    data-image="<?= e((string)($hotelOption['primary_image'] ?? '')); ?>"
                    data-room="<?= e((string)($hotelOption['default_room_type'] ?? '')); ?>"
                    data-meal="<?= e((string)($hotelOption['default_meal_plan'] ?? '')); ?>"
                    <?= (int)$packageHotelSelectedIds[$star] === (int)$hotelOption['id'] ? 'selected' : ''; ?>>
                    <?= e((string)($hotelOption['location_name'] ?: ($hotelOption['city'] ?? ''))); ?> · <?= e($hotelOption['hotel_name']); ?><?= (int)$hotelOption['is_preferred']===1 ? ' · Preferred' : ''; ?><?= !empty($hotelOption['supplier_name']) ? ' · ' . e($hotelOption['supplier_name']) : ''; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="automatic-hotel-meta" id="hotelMeta<?= $star; ?>"><?= $formDestinationId>0 ? 'Choose a location to narrow the list, or keep all destination hotels visible.' : 'Choose a destination first.'; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="automatic-hotel-foot"><span><i class="fa-solid fa-circle-info"></i> Admin-added and supplier-synced hotels use one location-aware Hotel Master. Photos help verify the property before selection.</span><a href="<?= e(BASE_URL . 'admin-hotels.php'); ?>" target="_blank">Open Hotel Master Pro</a></div>
</div>
</div>

<!-- MAIN IMAGE -->

<div class="form-group full">

<label>
    Main Package Image
</label>

<input
    type="file"
    name="main_image"
    id="mainImage"
    class="native-main-image-upload"
    accept="image/jpeg,image/png,image/webp"
>
<button type="button" class="main-image-gallery-launch open-media-picker" data-mode="main">
    <i class="fa-regular fa-images"></i>
    Choose Photo
</button>
<button
    type="button"
    class="open-pexels-picker pexels-launch-button"
    data-pexels-mode="main"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search Pexels + Unsplash
</button>
<div class="chosen-image-panel" id="mainChosenPreview" hidden>
    <div class="chosen-image-title"><i class="fa-solid fa-check-circle"></i> Selected Main Image</div>
    <div class="chosen-image-items" id="mainChosenPreviewItems"></div>
</div>



<div class="help">
    JPG, PNG or WEBP. Maximum 5 MB.
</div>


<?php if (
    !empty($form['main_image'])
): ?>

<div class="current-image">

<img
    src="<?= e(
        adminPackageImageUrl(
            $form['main_image']
        )
    ); ?>"
    alt="Current package image"
>

</div>


<label
    style="
        margin-top:8px;
        display:flex;
        align-items:center;
        gap:6px;
    "
>

<input
    type="checkbox"
    name="remove_main_image"
    value="1"
>

Remove current image

</label>

<?php endif; ?>

</div>



<!-- MULTIPLE PACKAGE IMAGES -->

<div class="form-group full">

<label>
    Additional Package Images
</label>

<input
    type="file"
    name="gallery_images[]"
    id="galleryImages"
    class="native-gallery-image-upload"
    accept="image/jpeg,image/png,image/webp"
    multiple
><button type="button" class="gallery-image-gallery-launch open-media-picker secondary" data-mode="gallery"><i class="fa-regular fa-images"></i> Choose Gallery Photos</button>
<button
    type="button"
    class="open-pexels-picker pexels-launch-button secondary"
    data-pexels-mode="gallery"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search Pexels + Unsplash
</button>
<div class="chosen-image-panel" id="galleryChosenPreview" hidden>
    <div class="chosen-image-title"><i class="fa-solid fa-check-circle"></i> Selected Additional Images</div>
    <div class="chosen-image-items" id="galleryChosenPreviewItems"></div>
</div>


<div class="help">
    Select multiple images together. JPG, PNG or WEBP, maximum 5 MB each.
    The Main Package Image remains the cover image.
</div>

<div class="multi-image-preview" id="multiImagePreview"></div>

<?php if (!empty($editGalleryImages)): ?>

<div class="existing-gallery">

    <div class="existing-gallery-title">
        <strong>Current Package Gallery</strong>
        <span><?= count($editGalleryImages); ?> image(s)</span>
    </div>

    <div class="existing-gallery-grid">

    <?php foreach ($editGalleryImages as $galleryImage): ?>
        <?php $galleryUrl = adminPackageImageUrl($galleryImage['image'] ?? ''); ?>

        <?php if ($galleryUrl !== ''): ?>
        <div class="existing-gallery-item">
            <img
                src="<?= e($galleryUrl); ?>"
                alt="Package gallery image"
                loading="eager" decoding="async"
            >
        </div>
        <?php endif; ?>

    <?php endforeach; ?>

    </div>

    <a
        href="<?= e(
            BASE_URL
            . 'admin-package-images.php?package_id='
            . (int)$editPackage['id']
        ); ?>"
        class="manage-gallery-btn"
    >
        <i class="fa-regular fa-images"></i>
        Manage / Remove Gallery Images
    </a>

</div>

<?php endif; ?>

</div>


<!-- INCLUSIONS -->

<div class="form-group">

<label>
    Inclusions
</label>

<textarea
    name="inclusions"
    class="form-control large"
    placeholder="Hotel accommodation&#10;Breakfast&#10;Airport transfer&#10;Sightseeing"
><?= e($form['inclusions']); ?></textarea>

<div class="help">
    Enter each inclusion on a separate line.
</div>

</div>


<!-- EXCLUSIONS -->

<div class="form-group">

<label>
    Exclusions
</label>

<textarea
    name="exclusions"
    class="form-control large"
    placeholder="Flight tickets&#10;Personal expenses&#10;Travel insurance"
><?= e($form['exclusions']); ?></textarea>

<div class="help">
    Enter each exclusion on a separate line.
</div>

</div>


<!-- DAY-WISE ITINERARY -->

<div class="form-group full">
<label>Day-wise Itinerary</label>

<div class="itinerary-builder">
    <div class="itinerary-builder-head">
        <div>
            <strong>Itinerary Builder</strong>
            <div class="help">Existing titles, activities and images are preserved. Reducing days keeps the first saved days; increasing again restores old days first and adds only new blank days.</div>
        </div>

        <div class="itinerary-auto-note">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Days are created automatically from Duration Days.
        </div>
    </div>

    <div class="itinerary-days" id="itineraryDays">

    <?php
    $builderDays = $editItineraryDays;

    if (!$builderDays):
    ?>
        <div class="itinerary-empty-state" id="itineraryEmptyState">
            <i class="fa-regular fa-calendar-xmark"></i>
            Set Duration Days above. Add New and Update Package both create itinerary rows automatically.<br>
            You can enter 0 or any larger whole number.
        </div>
    <?php
    endif;

    foreach ($builderDays as $index => $day):
        $dayImage = adminPackageImageUrl($day['image'] ?? '');
    ?>

        <div class="itinerary-day">
            <input type="hidden" name="itinerary_id[]" value="<?= (int)($day['id'] ?? 0); ?>">

            <div class="itinerary-day-head">
                <div class="day-label">
                    <span class="day-number"><?= $index + 1; ?></span>
                    <span class="day-text">Day <?= $index + 1; ?></span>
                </div>

                <button type="button" class="remove-day-btn" title="Remove day">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>

            <div class="itinerary-day-grid">
                <div>
                    <label>Day Title</label>
                    <input
                        type="text"
                        name="itinerary_title[]"
                        class="form-control"
                        maxlength="220"
                        value="<?= e($day['title'] ?? ''); ?>"
                        placeholder="Example: Arrival & Local Sightseeing"
                    >

                    <label style="margin-top:10px;">Activities / Description</label>
                    <textarea
                        name="itinerary_description[]"
                        class="form-control"
                        placeholder="Hotel, sightseeing, meals, transfers and activities..."
                    ><?= e($day['description'] ?? ''); ?></textarea>
                </div>

                <div class="day-image-box">
                    <label>Day Image</label>

                    <div class="day-preview">
                        <?php if ($dayImage !== ''): ?>
                            <img src="<?= e($dayImage); ?>" alt="Day <?= $index + 1; ?>">
                        <?php else: ?>
                            <i class="fa-regular fa-image"></i>
                        <?php endif; ?>
                    </div>

                    <input
                        type="file"
                        name="itinerary_image[<?= (int)$index; ?>]"
                        class="itinerary-image-input native-itinerary-image-upload"
                        accept="image/jpeg,image/png,image/webp"
                    >
<button type="button" class="itinerary-image-gallery-launch open-itinerary-media-picker">
    <i class="fa-regular fa-image"></i>
    Choose Itinerary Photo
</button>
<button
    type="button"
    class="open-pexels-picker pexels-launch-button secondary"
    data-pexels-mode="itinerary"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search Pexels + Unsplash
</button>

                    <?php if ($dayImage !== ''): ?>
                        <label class="remove-image-label">
                            <input
                                type="checkbox"
                                name="itinerary_remove_image[<?= $index; ?>]"
                                value="1"
                            >
                            Remove current image
                        </label>
                    <?php endif; ?>

                    <div class="help">JPG, PNG or WEBP. Maximum 5 MB.</div>
                </div>
            </div>
        </div>

    <?php endforeach; ?>

    </div>
</div>
</div>

<!-- Tour -->
<div class="form-group full">

<label>
Tour Highlights
</label>

<textarea
class="form-control"
name="tour_highlights"
rows="6"
placeholder="One highlight per line"><?=
htmlspecialchars(
$form['tour_highlights'] ?? ''
)
?></textarea>

<div class="help">
Enter one highlight per line.
</div>

</div>

<!-- TERMS -->

<div class="form-group full">

<label>
    Package Terms & Conditions
</label>

<textarea
    name="terms"
    class="form-control"
    placeholder="Booking, cancellation and package conditions..."
><?= e($form['terms']); ?></textarea>

</div>


<!-- OPTIONS -->

<div class="form-group full">

<label>
    Package Promotion
</label>

<div class="switch-row">

<label class="check-card">

<input
    type="checkbox"
    name="featured"
    value="1"
    <?= (int)$form['featured'] === 1
        ? 'checked'
        : ''; ?>
>

<i class="fa-solid fa-star"></i>

Featured Package

</label>


<label class="check-card">

<input
    type="checkbox"
    name="bestseller"
    value="1"
    <?= (int)$form['bestseller'] === 1
        ? 'checked'
        : ''; ?>
>

<i class="fa-solid fa-fire"></i>

Bestseller

</label>

</div>

</div>


<!-- STATUS -->

<div class="form-group full">

<label>
    Package Status
</label>

<select
    name="status"
    class="form-control"
>

<option
    value="draft"
    <?= $form['status'] === 'draft'
        ? 'selected'
        : ''; ?>
>
    Draft
</option>

<option
    value="active"
    <?= $form['status'] === 'active'
        ? 'selected'
        : ''; ?>
>
    Active
</option>

<option
    value="inactive"
    <?= $form['status'] === 'inactive'
        ? 'selected'
        : ''; ?>
>
    Inactive
</option>

</select>

</div>


</div>






<input type="hidden" name="library_main_image" id="libraryMainImage">

<div id="mediaSelected" class="media-selected" hidden>
<strong>Selected main:</strong><img id="mediaSelectedPreview" src="" alt=""><span></span>
<button type="button" id="clearMediaMain">Clear</button>
</div>
<div class="media-modal" id="mediaModal" aria-hidden="true">
    <div class="media-modal-backdrop" data-close-media></div>

    <div class="media-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mediaModalTitle">
        <div class="media-modal-head">
            <div>
                <h3 id="mediaModalTitle">Web Gallery</h3>
                <p id="mediaModalHelp">Choose an existing website image.</p>
            </div>

            
<div class="media-modal-head-actions">
    <button type="button" class="media-upload-new" id="mediaUploadNew">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        Computer / PC
    </button>

<button type="button" class="media-modal-close" data-close-media aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
</div>
        </div>

        <div class="media-source-bar">
<button type="button" class="media-source-btn active" id="showWebGallery"><i class="fa-regular fa-images"></i> Web Gallery</button>
<button type="button" class="media-source-btn" id="chooseComputer"><i class="fa-solid fa-computer"></i> Computer / PC</button>
</div>
<div class="media-modal-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="mediaSearch" placeholder="Search photo by image name...">
            <button type="button" id="clearMediaSearch">Clear</button>
        </div>

        <?php if($packageMediaLibrary): ?>
        <div class="media-modal-grid" id="mediaModalGrid">
            <?php foreach($packageMediaLibrary as $m): ?>
            <div class="media-popup-item"
                 data-name="<?=e(strtolower($m['name']))?>"
                 data-image="<?=e($m['image'])?>">
                <img src="<?=e(adminPackageImageUrl($m['image']))?>" alt="<?=e($m['name'])?>">
                <div class="media-popup-name" title="<?=e($m['name'])?>"><?=e($m['name'])?></div>

                <button type="button"
                        class="media-popup-select"
                        data-image="<?=e($m['image'])?>"
                        data-url="<?=e(adminPackageImageUrl($m['image']))?>">
                    Choose Photo
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="media-no-result" id="mediaNoResult" hidden>No photo found with this name.</div>
        <?php else: ?>
        <div class="media-empty">No package images have been uploaded yet.</div>
        <?php endif; ?>

        <div class="media-modal-foot" id="mediaGalleryFoot" hidden>
            <span><strong id="mediaGalleryCount">0</strong> photo(s) selected for package gallery.</span>
            <button type="button" class="media-done-btn" data-close-media>Done</button>
        </div>
    </div>
</div>



<style>
/* =====================================================================
   TRAVSCOPE V24.29 — GLOBAL FAIR PHOTO PICKER
   Equal comparison cards + clean pagination across every API photo search.
   ===================================================================== */
#pexelsResults.pexels-results,
#pexelsGrid.pexels-grid,
#crmMediaGrid.crm-media-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(180px,1fr)) !important;
    grid-auto-flow:row !important;
    grid-auto-rows:1fr !important;
    align-items:stretch !important;
    align-content:start !important;
    gap:14px !important;
    padding:16px !important;
}
#pexelsResults.pexels-results,
#pexelsGrid.pexels-grid{max-height:56vh;overflow-y:auto !important;overflow-x:hidden !important}
.pexels-photo-card,
.pexels-card,
.crm-media-item[data-provider]{
    position:relative !important;
    display:flex !important;
    flex-direction:column !important;
    width:100% !important;
    height:100% !important;
    min-height:310px !important;
    margin:0 !important;
    overflow:hidden !important;
    border:1px solid #dbe4ef !important;
    border-radius:14px !important;
    background:#fff !important;
    box-shadow:0 6px 18px rgba(15,23,42,.07) !important;
    transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease !important;
}
.pexels-photo-card:hover,
.pexels-card:hover,
.crm-media-item[data-provider]:hover{
    transform:translateY(-2px) !important;
    border-color:#93c5fd !important;
    box-shadow:0 12px 28px rgba(37,99,235,.13) !important;
}
.pexels-photo-image,
.pexels-photo-wrap{
    position:relative !important;
    width:100% !important;
    height:158px !important;
    min-height:158px !important;
    aspect-ratio:auto !important;
    overflow:hidden !important;
    background:#eef2f7 !important;
    flex:0 0 158px !important;
}
.pexels-photo-image img,
.pexels-photo-wrap img,
.crm-media-item[data-provider] > img{
    display:block !important;
    width:100% !important;
    height:158px !important;
    min-height:158px !important;
    max-height:158px !important;
    object-fit:cover !important;
    object-position:center !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
}
.pexels-photo-body,
.pexels-card-body,
.crm-media-item[data-provider] .crm-media-item-body{
    display:flex !important;
    flex:1 1 auto !important;
    flex-direction:column !important;
    padding:10px !important;
    background:#fff !important;
}
.pexels-photo-credit,
.crm-media-item[data-provider] .crm-free-credit{
    min-height:30px !important;
    margin:0 0 6px !important;
    padding:0 !important;
    color:#64748b !important;
    font-size:9px !important;
    line-height:1.4 !important;
}
.pexels-photo-name,
.crm-media-item[data-provider] .crm-media-item-name{
    min-height:36px !important;
    margin:0 0 8px !important;
    color:#1e293b !important;
    font-size:11px !important;
    font-weight:800 !important;
    line-height:1.45 !important;
    white-space:normal !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}
.pexels-import-button,
.pexels-card button,
.crm-media-item[data-provider] .crm-media-select{
    width:100% !important;
    min-height:38px !important;
    margin-top:auto !important;
    border:0 !important;
    border-radius:9px !important;
    background:linear-gradient(135deg,#0f766e,#10b981) !important;
    color:#fff !important;
    font-size:10px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}
.free-photo-provider-badge,
.crm-provider-badge{
    border:0 !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 4px 12px rgba(15,23,42,.22) !important;
}
.free-photo-provider-badge.pexels,
[data-provider="pexels"] .free-photo-provider-badge,
.crm-provider-badge.pexels{background:#0f766e !important;color:#fff !important}
.free-photo-provider-badge.unsplash,
[data-provider="unsplash"] .free-photo-provider-badge,
.crm-provider-badge.unsplash{background:#111827 !important;color:#fff !important}
.pexels-click-hint{display:none !important}
.pexels-pagination,
.ts-photo-pagination,
.crm-photo-pagination{
    flex:0 0 auto !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:14px !important;
    min-height:58px !important;
    padding:10px 16px !important;
    border-top:1px solid #e2e8f0 !important;
    background:#fff !important;
    color:#64748b !important;
    font-size:12px !important;
}
.pexels-pagination[hidden],
.ts-photo-pagination[hidden],
.crm-photo-pagination[hidden]{display:none !important}
.pexels-pagination button,
.ts-photo-pagination button,
.crm-photo-pagination button{
    min-height:38px !important;
    padding:0 15px !important;
    border:1px solid #d7e0eb !important;
    border-radius:9px !important;
    background:#fff !important;
    color:#183153 !important;
    font-size:11px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}
.pexels-pagination button:hover:not(:disabled),
.ts-photo-pagination button:hover:not(:disabled),
.crm-photo-pagination button:hover:not(:disabled){background:#eff6ff !important;border-color:#93c5fd !important;color:#1d4ed8 !important}
.pexels-pagination button:disabled,
.ts-photo-pagination button:disabled,
.crm-photo-pagination button:disabled{opacity:.42 !important;cursor:not-allowed !important}
.pexels-pagination span,
.ts-photo-pagination span,
.crm-photo-pagination span{min-width:125px;text-align:center;font-weight:800;color:#64748b}
@media(max-width:900px){
    #pexelsResults.pexels-results,#pexelsGrid.pexels-grid,#crmMediaGrid.crm-media-grid{grid-template-columns:repeat(3,minmax(0,1fr)) !important}
}
@media(max-width:620px){
    #pexelsResults.pexels-results,#pexelsGrid.pexels-grid,#crmMediaGrid.crm-media-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;padding:10px !important;gap:10px !important}
    .pexels-photo-image,.pexels-photo-wrap,.pexels-photo-image img,.pexels-photo-wrap img,.crm-media-item[data-provider] > img{height:135px !important;min-height:135px !important;max-height:135px !important;flex-basis:135px !important}
}
</style>

<!-- ================================================================
     PEXELS ROYALTY-FREE PHOTO SEARCH
================================================================= -->
<div
    class="pexels-modal"
    id="pexelsModal"
    aria-hidden="true"
>
    <div class="pexels-modal-backdrop" data-close-pexels></div>

    <div
        class="pexels-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pexelsModalTitle"
    >
        <div class="pexels-modal-head">
            <div>
                <h3 id="pexelsModalTitle">
                    Search Pexels + Unsplash
                </h3>
                <p>
                    Pexels and Unsplash results are combined in one fair comparison grid. Photographer and source attribution are retained automatically.
                </p>
            </div>

            <button
                type="button"
                class="pexels-close"
                data-close-pexels
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="pexels-search-row">
            <div class="pexels-search-input">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="search"
                    id="pexelsSearchInput"
                    placeholder="Example: Bhutan Tiger Nest, Goa beach, Kashmir mountains"
                    autocomplete="off"
                >
            </div>

            <button
                type="button"
                id="pexelsSearchButton"
                class="pexels-search-button"
            >
                Search Photos
            </button>
        </div>

        <div class="pexels-policy-note">
            <i class="fa-solid fa-shield-halved"></i>
            Official Pexels and Unsplash API results are combined automatically.
            Review photos containing recognisable people, logos or private
            property before commercial use.
        </div>

        <div
            class="pexels-status"
            id="pexelsStatus"
            hidden
        ></div>

        <div
            class="pexels-results"
            id="pexelsResults"
        ></div>

        <div
            class="pexels-pagination"
            id="pexelsPagination"
            hidden
        >
            <button
                type="button"
                id="pexelsPrevious"
                class="pexels-page-button"
            >
                <i class="fa-solid fa-chevron-left"></i>
                Previous
            </button>

            <span id="pexelsPageText">Page 1</span>

            <button
                type="button"
                id="pexelsNext"
                class="pexels-page-button"
            >
                Next
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>

        <div class="pexels-modal-foot">
            <a
                href="https://www.pexels.com"
                target="_blank"
                rel="noopener noreferrer"
            >
                Photos provided by Pexels + Unsplash
            </a>

            <button
                type="button"
                class="pexels-done-button"
                data-close-pexels
            >
                Done
            </button>
        </div>
    </div>
</div>

<div class="form-footer">

<?php if ($editPackage): ?>

<a
    href="<?= e(BASE_URL . 'admin-packages.php'); ?>"
    class="btn btn-light"
>
    Cancel Edit
</a>

<?php endif; ?>


<button
    type="submit"
    class="btn btn-primary"
>

<i class="fa-solid fa-floppy-disk"></i>

<?= $editPackage
    ? 'Update Package'
    : 'Create Package'; ?>

</button>

</div>


</form>

</div>

</section>

<?php endif; ?>



</main>

</div>


<script>



/* AUTOMATIC HOTEL ENGINE - destination + child location aware */
(function(){
    const destination=document.querySelector('select[name="destination_id"]');
    const locationSelect=document.getElementById('packageHotelLocationFilter');
    const selects=[...document.querySelectorAll('.automatic-hotel-select')];
    if(!destination || !selects.length) return;
    const base='<?= e(rtrim(BASE_URL,'/') . '/'); ?>';

    function showPhoto(star,row){
        const box=document.getElementById('hotelPhoto'+star);if(!box)return;
        if(row&&row.image){box.innerHTML='<img src="'+base+String(row.image).replace(/^\/+/, '')+'" alt="">';}
        else box.innerHTML='<i class="fa-regular fa-image"></i>';
    }
    function rowLabel(row){return (row.location?row.location+' · ':'')+row.name+(row.preferred?' · Preferred':'')+(row.supplier?' · '+row.supplier:'');}
    function optionGroups(sel,rows,selectedId){
        sel.innerHTML='<option value="0">Auto select preferred hotel</option>';
        const groups=new Map();
        rows.forEach(row=>{const key=row.location||'Other';if(!groups.has(key))groups.set(key,[]);groups.get(key).push(row);});
        groups.forEach((group,label)=>{const og=document.createElement('optgroup');og.label=label;group.forEach(row=>{const option=document.createElement('option');option.value=String(row.id);option.textContent=rowLabel(row);option.dataset.location=String(row.location||'');option.dataset.image=String(row.image||'');option.dataset.room=String(row.room_type||'');option.dataset.meal=String(row.meal_plan||'');if(selectedId>0&&Number(row.id)===selectedId)option.selected=true;og.appendChild(option);});sel.appendChild(og);});
    }
    async function loadHotels(forceAutomatic){
        const destinationId=parseInt(destination.value||'0',10)||0;
        const previousLocation=parseInt(locationSelect?.dataset.selected||locationSelect?.value||'0',10)||0;
        if(destinationId<=0){
            if(locationSelect){locationSelect.innerHTML='<option value="0">Choose destination first</option>';}
            selects.forEach(sel=>{sel.innerHTML='<option value="0">Choose destination first</option>';showPhoto(sel.dataset.star,null);});
            [3,4,5].forEach(star=>{const meta=document.getElementById('hotelMeta'+star);if(meta)meta.textContent='Choose a destination first.';});
            return;
        }
        try{
            const requestedLocation=forceAutomatic?0:(parseInt(locationSelect?.value||previousLocation||'0',10)||0);
            const response=await fetch('<?= e(BASE_URL . 'package-hotels-api.php'); ?>?destination_id='+encodeURIComponent(destinationId)+'&location_id='+encodeURIComponent(requestedLocation),{credentials:'same-origin',headers:{'Accept':'application/json'}});
            const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Unable to load hotels');

            if(locationSelect){
                const keep=forceAutomatic?0:requestedLocation;
                locationSelect.innerHTML='<option value="0">All matching hotel cities / locations</option>';
                (data.locations||[]).forEach(loc=>{
                    const count=Number(loc.hotel_count||0);
                    const label=String(loc.name||'')+(count>0?' ('+count+')':'');
                    const o=new Option(label,loc.id,false,Number(loc.id)===keep);
                    locationSelect.add(o);
                });
                if(forceAutomatic || ![...locationSelect.options].some(o=>Number(o.value)===keep)) locationSelect.value='0';
                locationSelect.dataset.selected=locationSelect.value;
            }

            let effectiveLocation=parseInt(locationSelect?.value||'0',10)||0;
            let hotelData=data;
            if(effectiveLocation!==requestedLocation){
                const r2=await fetch('<?= e(BASE_URL . 'package-hotels-api.php'); ?>?destination_id='+encodeURIComponent(destinationId)+'&location_id='+encodeURIComponent(effectiveLocation),{credentials:'same-origin',headers:{'Accept':'application/json'}});
                hotelData=await r2.json();
            }

            const hotelCount=(d)=>[3,4,5].reduce((sum,star)=>sum+(((d.hotels||{})[String(star)]||[]).length),0);
            let locationReset=false;
            // Editing an older package can leave a stale location filter. If it hides every hotel,
            // automatically fall back to all related destination/city hotels instead of showing false "not found" messages.
            if(effectiveLocation>0 && hotelCount(hotelData)===0){
                const allResponse=await fetch('<?= e(BASE_URL . 'package-hotels-api.php'); ?>?destination_id='+encodeURIComponent(destinationId)+'&location_id=0',{credentials:'same-origin',headers:{'Accept':'application/json'}});
                const allData=await allResponse.json();
                if(allResponse.ok&&allData.success&&hotelCount(allData)>0){
                    hotelData=allData;effectiveLocation=0;locationReset=true;
                    if(locationSelect){locationSelect.value='0';locationSelect.dataset.selected='0';}
                }
            }

            selects.forEach(sel=>{
                const star=String(sel.dataset.star||'');const rows=(hotelData.hotels&&hotelData.hotels[star])||[];
                const old=forceAutomatic?0:(parseInt(sel.dataset.selected||sel.value||'0',10)||0);
                optionGroups(sel,rows,old);if(forceAutomatic)sel.value='0';
                const selectedRow=rows.find(row=>Number(row.id)===Number(sel.value));const preferred=selectedRow||rows.find(row=>row.preferred)||rows[0]||null;
                const meta=document.getElementById('hotelMeta'+star);
                if(meta){
                    if(preferred){
                        meta.textContent=(locationReset?'Previous location had no hotels · showing all matches · ':'')+(preferred.location?preferred.location+' · ':'')+(sel.value==='0'?'Auto: ':'')+preferred.name+(preferred.room_type?' · '+preferred.room_type:'')+(preferred.meal_plan?' · '+preferred.meal_plan:'');
                    }else{
                        meta.textContent='No '+star+'★ hotel found for this package destination or its matching city/state. Check Hotel Master destination, city and hotel status.';
                    }
                }
                showPhoto(star,preferred);sel.dataset.selected=sel.value;
            });
        }catch(err){[3,4,5].forEach(star=>{const meta=document.getElementById('hotelMeta'+star);if(meta)meta.textContent='Hotel load failed. Open Hotel Master Pro and check destination/city setup.';showPhoto(star,null);});}
    }
    destination.addEventListener('change',()=>loadHotels(true));
    if(locationSelect)locationSelect.addEventListener('change',()=>{locationSelect.dataset.selected=locationSelect.value;selects.forEach(s=>{s.dataset.selected='0';s.value='0';});loadHotels(false);});
    selects.forEach(sel=>sel.addEventListener('change',()=>{sel.dataset.selected=sel.value;const opt=sel.options[sel.selectedIndex];const star=sel.dataset.star;const meta=document.getElementById('hotelMeta'+star);if(opt&&sel.value!=='0'){if(meta)meta.textContent=(opt.dataset.location?opt.dataset.location+' · ':'')+opt.textContent;showPhoto(star,{image:opt.dataset.image||''});}else loadHotels(false);}));
    if(parseInt(destination.value||'0',10)>0) loadHotels(false);
})();

/*
|--------------------------------------------------------------------------
| IMAGE SIZE CHECK
|--------------------------------------------------------------------------
*/

const mainImage =
    document.getElementById(
        'mainImage'
    );

if (mainImage) {

    mainImage.addEventListener(
        'change',
        function () {

            const file =
                this.files[0];

            if (!file) {
                return;
            }

            const allowed = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            if (
                !allowed.includes(
                    file.type
                )
            ) {

                alert(
                    'Only JPG, PNG and WEBP images are allowed.'
                );

                this.value = '';

                return;
            }


            if (
                file.size
                >
                5 * 1024 * 1024
            ) {

                alert(
                    'Image must be smaller than 5 MB.'
                );

                this.value = '';
            }
        }
    );
}


const itineraryDays = document.getElementById('itineraryDays');
const durationDaysInput = document.getElementById('durationDays');

/*
| Preserve detached old itinerary rows during editing.
| Example:
| - Existing 7 days -> change duration to 4: Days 5-7 are cached.
| - Change duration to 10: Days 5-7 return with old data,
|   and only Days 8-10 are created blank.
*/
const preservedItineraryRows = [];

function itineraryRows(){
    return itineraryDays
        ? Array.from(itineraryDays.querySelectorAll('.itinerary-day'))
        : [];
}

function updateItineraryEmptyState(){
    if(!itineraryDays) return;

    const hasRows=itineraryRows().length>0;
    let empty=itineraryDays.querySelector('#itineraryEmptyState');

    if(hasRows){
        if(empty) empty.remove();
        return;
    }

    if(!empty){
        empty=document.createElement('div');
        empty.className='itinerary-empty-state';
        empty.id='itineraryEmptyState';
        empty.innerHTML=`
            <i class="fa-regular fa-calendar-xmark"></i>
            Set Duration Days above. Add New and Update Package both create itinerary rows automatically.<br>
            You can enter 0 or any larger whole number.
        `;
        itineraryDays.appendChild(empty);
    }
}

function normalizedDurationDays(){
    if(!durationDaysInput){
        return 0;
    }

    let value=Number.parseInt(String(durationDaysInput.value),10);

    if(!Number.isFinite(value) || value<0){
        value=0;
    }

    return value;
}

function renumberItineraryDays(){
    if(!itineraryDays) return;

    itineraryRows().forEach(function(day,index){
        const n=index+1;
        const number=day.querySelector('.day-number');
        const text=day.querySelector('.day-text');

        if(number) number.textContent=n;
        if(text) text.textContent='Day '+n;

        const fileInput=day.querySelector('.itinerary-image-input');
        if(fileInput){
            fileInput.name='itinerary_image['+index+']';
        }

        const libraryInput=day.querySelector('.itinerary-library-image');
        if(libraryInput){
            libraryInput.name='itinerary_library_image['+index+']';
        }

        const removeImage=day.querySelector(
            'input[type="checkbox"][name^="itinerary_remove_image"]'
        );

        if(removeImage){
            removeImage.name='itinerary_remove_image['+index+']';
        }

        const previewImage=day.querySelector('.day-preview img');
        if(previewImage){
            previewImage.alt='Day '+n;
        }
    });

    updateItineraryEmptyState();
}

function bindItineraryDay(day){
    if(!day || day.dataset.itineraryBound==='1'){
        return;
    }

    day.dataset.itineraryBound='1';

    const remove=day.querySelector('.remove-day-btn');

    if(remove){
        remove.addEventListener('click',function(){
            if(confirm('Remove this itinerary day?')){
                day.dataset.manualRemoved='1';
                day.remove();
                renumberItineraryDays();
            }
        });
    }

    const input=day.querySelector('.itinerary-image-input');

    if(input){
        input.addEventListener('change',function(){
            const file=this.files[0];
            if(!file) return;

            const allowed=['image/jpeg','image/png','image/webp'];

            if(!allowed.includes(file.type) || file.size>5*1024*1024){
                alert('Use a JPG, PNG or WEBP image under 5 MB.');
                this.value='';
                return;
            }

            const preview=day.querySelector('.day-preview');
            const reader=new FileReader();

            reader.onload=function(e){
                preview.innerHTML='<img src="'+e.target.result+'" alt="Day image">';
            };

            reader.readAsDataURL(file);
        });
    }
}

if(itineraryDays){
    itineraryDays.querySelectorAll('.itinerary-day').forEach(function(day,index){
        day.dataset.originalItineraryRow='1';
        day.dataset.originalItineraryIndex=String(index);
        bindItineraryDay(day);
    });

    renumberItineraryDays();
}

function createItineraryDay(){
    if(!itineraryDays) return null;

    const n=itineraryRows().length+1;
    const day=document.createElement('div');

    day.className='itinerary-day';
    day.innerHTML=`
        <input type="hidden" name="itinerary_id[]" value="0">

        <div class="itinerary-day-head">
            <div class="day-label">
                <span class="day-number">${n}</span>
                <span class="day-text">Day ${n}</span>
            </div>

            <button type="button" class="remove-day-btn" title="Remove day">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>

        <div class="itinerary-day-grid">
            <div>
                <label>Day Title</label>

                <input
                    type="text"
                    name="itinerary_title[]"
                    class="form-control"
                    maxlength="220"
                    placeholder="Example: Local Sightseeing & Activities"
                >

                <label style="margin-top:10px;">Activities / Description</label>

                <textarea
                    name="itinerary_description[]"
                    class="form-control"
                    placeholder="Hotel, sightseeing, meals, transfers and activities..."
                ></textarea>
            </div>

            <div class="day-image-box">
                <label>Day Image</label>

                <div class="day-preview">
                    <i class="fa-regular fa-image"></i>
                </div>

                <input
                    type="file"
                    name="itinerary_image[${n-1}]"
                    class="itinerary-image-input native-itinerary-image-upload"
                    accept="image/jpeg,image/png,image/webp"
                >

                <button
                    type="button"
                    class="itinerary-image-gallery-launch open-itinerary-media-picker"
                >
                    <i class="fa-regular fa-image"></i>
                    Choose Itinerary Photo
                </button>

                <button
                    type="button"
                    class="open-pexels-picker pexels-launch-button secondary"
                    data-pexels-mode="itinerary"
                >
                    <i class="fa-solid fa-magnifying-glass"></i>
                    Search Pexels + Unsplash
                </button>

                <div class="help">
                    JPG, PNG or WEBP. Maximum 5 MB.
                </div>
            </div>
        </div>
    `;

    itineraryDays.appendChild(day);
    bindItineraryDay(day);
    renumberItineraryDays();

    return day;
}

function setItineraryDayCount(rawCount){
    if(!itineraryDays) return;

    let requested=Number.parseInt(String(rawCount),10);

    if(!Number.isFinite(requested) || requested<0){
        requested=0;
    }

    let currentRows=itineraryRows();

    /*
    | Reduce count:
    | Detach rows from the end and cache them instead of destroying them.
    | Their titles, activities, image selections and database IDs remain.
    */
    if(requested<currentRows.length){
        for(let i=currentRows.length-1;i>=requested;i--){
            const row=currentRows[i];

            if(row.dataset.manualRemoved==='1'){
                row.remove();
                continue;
            }

            preservedItineraryRows.unshift(row);
            row.remove();
        }

        renumberItineraryDays();
        currentRows=itineraryRows();
    }

    /*
    | Increase count:
    | Restore cached previous rows first, then create only genuinely new
    | blank rows after all preserved rows are used.
    */
    if(requested>currentRows.length){
        let lastAdded=null;

        while(
            currentRows.length<requested
            && preservedItineraryRows.length>0
        ){
            const restored=preservedItineraryRows.shift();

            if(
                !restored
                || restored.dataset.manualRemoved==='1'
            ){
                continue;
            }

            itineraryDays.appendChild(restored);
            bindItineraryDay(restored);
            currentRows=itineraryRows();
            lastAdded=restored;
        }

        while(currentRows.length<requested){
            lastAdded=createItineraryDay();
            currentRows=itineraryRows();
        }

        renumberItineraryDays();

    }

    updateItineraryEmptyState();
}

if(itineraryDays){
    itineraryRows().forEach(bindItineraryDay);
    renumberItineraryDays();
}

if(durationDaysInput && itineraryDays){
    const applyDurationDays=function(scrollToNewDays){
        const requested=normalizedDurationDays();

        durationDaysInput.value=String(requested);

        const beforeCount=itineraryRows().length;

        setItineraryDayCount(requested);

    };

    /*
    | Initial edit behavior:
    | - 7 saved days + Duration 4 = first 4 saved days remain.
    | - 7 saved days + Duration 10 = 7 saved days + 3 blank days.
    */
    applyDurationDays(false);

    durationDaysInput.addEventListener('input',function(){
        applyDurationDays(false);
    });

    durationDaysInput.addEventListener('change',function(){
        applyDurationDays(false);
    });

    durationDaysInput.addEventListener('blur',function(){
        applyDurationDays(false);
    });
}


const galleryImagesInput = document.getElementById('galleryImages');
const multiImagePreview = document.getElementById('multiImagePreview');

if (galleryImagesInput && multiImagePreview) {
    galleryImagesInput.addEventListener('change', function () {
        multiImagePreview.innerHTML = '';

        const files = Array.from(this.files || []);
        const allowed = ['image/jpeg','image/png','image/webp'];

        for (const file of files) {
            if (!allowed.includes(file.type) || file.size > 5 * 1024 * 1024) {
                alert('Every package image must be JPG, PNG or WEBP and under 5 MB.');
                this.value = '';
                multiImagePreview.innerHTML = '';
                return;
            }
        }

        files.forEach(function (file) {
            const reader = new FileReader();

            reader.onload = function (event) {
                const box = document.createElement('div');
                box.className = 'multi-preview-item';

                const img = document.createElement('img');
                img.src = event.target.result;
                img.alt = 'Selected package image';

                box.appendChild(img);
                multiImagePreview.appendChild(box);
            };

            reader.readAsDataURL(file);
        });
    });
}

</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('mediaModal');
    const search = document.getElementById('mediaSearch');
    const clearSearch = document.getElementById('clearMediaSearch');
    const items = Array.from(document.querySelectorAll('.media-popup-item'));
    const mainInput = document.getElementById('libraryMainImage');
    const selectedBox = document.getElementById('mediaSelected');
    const selectedPreview = document.getElementById('mediaSelectedPreview');
    const selectedName = selectedBox ? selectedBox.querySelector('span') : null;
    const clearMain = document.getElementById('clearMediaMain');
    const title = document.getElementById('mediaModalTitle');
    const help = document.getElementById('mediaModalHelp');
    const foot = document.getElementById('mediaGalleryFoot');
    const count = document.getElementById('mediaGalleryCount');
    const noResult = document.getElementById('mediaNoResult');
    let mode = 'main';

    function updateGalleryCount() {
        const checked = document.querySelectorAll('input.media-gallery-hidden:checked').length;
        if (count) count.textContent = checked;
    }

    function ensureGalleryInput(image, checked) {
        let input = document.querySelector('input.media-gallery-hidden[value="' + CSS.escape(image) + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'library_gallery_images[]';
            input.value = image;
            input.className = 'media-gallery-hidden';
            input.hidden = true;
            modal.parentNode.insertBefore(input, modal);
        }
        input.checked = checked;
        updateGalleryCount();
    }

    function galleryIsSelected(image) {
        const input = document.querySelector('input.media-gallery-hidden[value="' + CSS.escape(image) + '"]');
        return !!(input && input.checked);
    }

    function refreshCards() {
        items.forEach(item => {
            const image = item.dataset.image || '';
            const button = item.querySelector('.media-popup-select');

            if (mode === 'main') {
                const selected = mainInput && mainInput.value === image;
                item.classList.toggle('selected', selected);
                if (button) button.textContent = selected ? 'Selected' : 'Choose Photo';
            } else {
                const selected = galleryIsSelected(image);
                item.classList.toggle('selected', selected);
                if (button) button.textContent = selected ? 'Remove Photo' : 'Add to Gallery';
            }
        });
    }

    function filterItems() {
        const q = search ? search.value.trim().toLowerCase() : '';
        let visible = 0;
        items.forEach(item => {
            const match = !q || (item.dataset.name || '').includes(q);
            item.classList.toggle('hide', !match);
            if (match) visible++;
        });
        if (noResult) noResult.hidden = visible !== 0;
    }

    function openModal(newMode) {
        mode = newMode;
        if (title) title.textContent = mode === 'main' ? 'Choose Main Image' : 'Choose Package Gallery Photos';
        if (help) help.textContent = mode === 'main'
            ? 'Search by image name and click one photo to use as the package main image.'
            : 'Search by image name and select one or more photos for the package gallery.';
        if (foot) foot.hidden = mode !== 'gallery';
        if (search) search.value = '';
        filterItems();
        refreshCards();
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('media-modal-open');
        setTimeout(() => search && search.focus(), 50);
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('media-modal-open');
    }

    document.querySelectorAll('.open-media-picker').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.mode || 'main'));
    });

    document.querySelectorAll('[data-close-media]').forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    if (search) search.addEventListener('input', filterItems);
    if (clearSearch) clearSearch.addEventListener('click', () => {
        search.value = '';
        filterItems();
        search.focus();
    });

    items.forEach(item => {
        const button = item.querySelector('.media-popup-select');
        if (!button) return;

        button.addEventListener('click', function () {
            const image = item.dataset.image || '';
            const url = this.dataset.url || item.querySelector('img')?.src || '';
            if (!image) return;

            if (mode === 'main') {
                mainInput.value = image;
                if (selectedPreview) selectedPreview.src = url;
                if (selectedName) selectedName.textContent = image.split('/').pop();
                if (selectedBox) selectedBox.hidden = false;
                refreshCards();
                closeModal();
            } else {
                ensureGalleryInput(image, !galleryIsSelected(image));
                refreshCards();
            }
        });
    });

    if (clearMain) clearMain.addEventListener('click', function () {
        mainInput.value = '';
        if (selectedBox) selectedBox.hidden = true;
        refreshCards();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    updateGalleryCount();
});

    const nativeMainUpload = document.getElementById('nativeMainImageUpload');
    const uploadNewBtn = document.getElementById('mediaUploadNew');
    const mainLauncher = document.querySelector('.main-image-gallery-launch');

    if (uploadNewBtn && nativeMainUpload) {
        uploadNewBtn.addEventListener('click', function () {
            nativeMainUpload.click();
        });
    }

    if (nativeMainUpload) {
        nativeMainUpload.addEventListener('change', function () {
            if (this.files && this.files.length) {
                /* A newly uploaded image takes priority over a library selection. */
                if (mainInput) mainInput.value = '';

                if (selectedName) selectedName.textContent = 'New upload: ' + this.files[0].name;

                if (selectedPreview) {
                    selectedPreview.src = URL.createObjectURL(this.files[0]);
                }

                if (selectedBox) selectedBox.hidden = false;

                if (mainLauncher) {
                    mainLauncher.innerHTML =
                        '<i class="fa-solid fa-circle-check"></i> New image selected: ' +
                        this.files[0].name;
                }

                closeModal();
            }
        });
    }


const showWebGalleryBtn=document.getElementById('showWebGallery');
const chooseComputerBtn=document.getElementById('chooseComputer');
const nativeGalleryUpload=document.getElementById('nativeGalleryImageUpload');
if(showWebGalleryBtn)showWebGalleryBtn.addEventListener('click',()=>{if(search)search.focus();});
if(chooseComputerBtn)chooseComputerBtn.addEventListener('click',()=>{
 if(mode==='gallery'&&nativeGalleryUpload){nativeGalleryUpload.click();}
 else if(nativeMainUpload){nativeMainUpload.click();}
});
if(nativeGalleryUpload)nativeGalleryUpload.addEventListener('change',()=>{if(nativeGalleryUpload.files&&nativeGalleryUpload.files.length)closeModal();});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeItineraryInput = null;

    /*
    | Use delegated click handling so itinerary rows created dynamically
    | while adding a NEW package work exactly like saved/update rows.
    */
    document.addEventListener('click', function(event){
        const button = event.target.closest('.open-itinerary-media-picker');

        if (!button) {
            return;
        }

        event.preventDefault();

        const day = button.closest('.itinerary-day');

        activeItineraryInput =
            day
                ? day.querySelector('.itinerary-image-input')
                : null;

        const modal = document.getElementById('mediaModal');

        if (!modal || !day) {
            return;
        }

        modal.dataset.itineraryMode = '1';

        const title = modal.querySelector(
            '.media-modal-head h2, .media-modal-head h3, .media-modal-title'
        );

        if (title) {
            if (!title.dataset.originalTitle) {
                title.dataset.originalTitle = title.textContent;
            }

            title.textContent = 'Choose Itinerary Photo';
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('media-modal-open');
    });

    /* Computer / PC: when itinerary chooser opened, open that day's hidden file input. */
    document.addEventListener('click', function(e){
        const pc = e.target.closest('.media-upload-new, [data-source="computer"], [data-media-source="computer"]');
        const modal = document.getElementById('mediaModal');
        if (!pc || !modal || modal.dataset.itineraryMode !== '1' || !activeItineraryInput) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.classList.remove('media-modal-open');
        activeItineraryInput.click();
    }, true);

    /*
     * Website Gallery selection for itinerary:
     * copy the chosen library image path into a hidden itinerary field.
     * Server-side support is added below.
     */
    document.addEventListener('click', function(e){
        const choose = e.target.closest('.media-popup-select');
        const modal = document.getElementById('mediaModal');
        if (!choose || !modal || modal.dataset.itineraryMode !== '1' || !activeItineraryInput) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        const day = activeItineraryInput.closest('.itinerary-day');
        if (!day) return;

        let hidden = day.querySelector('.itinerary-library-image');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'itinerary-library-image';

            const allDays = Array.from(document.querySelectorAll('.itinerary-day'));
            const idx = allDays.indexOf(day);
            hidden.name = 'itinerary_library_image[' + Math.max(idx,0) + ']';
            day.appendChild(hidden);
        }

        hidden.value = choose.dataset.image || '';

        let preview = day.querySelector('.itinerary-library-preview');
        if (!preview) {
            preview = document.createElement('div');
            preview.className = 'itinerary-library-preview';
            preview.style.marginTop = '8px';
            day.appendChild(preview);
        }
        preview.innerHTML = '<img src="' + (choose.dataset.url || '') + '" alt="Selected itinerary image" style="width:90px;height:65px;object-fit:cover;border-radius:8px;border:1px solid #dbe5f1">';

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        modal.dataset.itineraryMode = '0';
        document.body.classList.remove('media-modal-open');
    }, true);

    document.querySelectorAll('[data-close-media]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const modal = document.getElementById('mediaModal');
            if (modal) modal.dataset.itineraryMode = '0';
        });
    });
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const mainInput = document.getElementById('mainImage');
    const galleryInput = document.getElementById('galleryImages');
    const mainPanel = document.getElementById('mainChosenPreview');
    const mainItems = document.getElementById('mainChosenPreviewItems');
    const galleryPanel = document.getElementById('galleryChosenPreview');
    const galleryItems = document.getElementById('galleryChosenPreviewItems');
    const mediaModal = document.getElementById('mediaModal');

    let pickerMode = 'main';

    /* --------------------------------------------------------------
       Remove duplicate source-choice buttons from the popup visually.
       Keep one Web Gallery and one Computer / PC control only.
       -------------------------------------------------------------- */
    if (mediaModal) {
        const sourceButtons = Array.from(mediaModal.querySelectorAll('button, a'));
        const seen = { web:false, pc:false };

        sourceButtons.forEach(function(el){
            const text = (el.textContent || '').replace(/\s+/g,' ').trim().toLowerCase();
            let type = '';

            if (text === 'web gallery' || text === 'website gallery') type = 'web';
            if (
                text === 'computer / pc' ||
                text === 'choose from computer / pc' ||
                text === 'computer/pc'
            ) type = 'pc';

            if (!type) return;

            if (seen[type]) {
                const parent = el.parentElement;
                if (
                    parent &&
                    parent.children.length <= 2 &&
                    /source|choice|tabs|actions/i.test(parent.className || '')
                ) {
                    parent.classList.add('duplicate-media-source');
                } else {
                    el.classList.add('duplicate-media-source');
                }
            } else {
                seen[type] = true;
            }
        });
    }

    document.querySelectorAll('.open-media-picker').forEach(function(btn){
        btn.addEventListener('click', function(){
            pickerMode = this.dataset.mode === 'gallery' ? 'gallery' : 'main';
        }, true);
    });

    function addThumb(container, src, name, replaceAll) {
        if (!container || !src) return;
        if (replaceAll) container.innerHTML = '';

        const item = document.createElement('div');
        item.className = 'chosen-image-thumb';

        const img = document.createElement('img');
        img.src = src;
        img.alt = name || 'Selected image';

        const text = document.createElement('span');
        text.textContent = name || 'Selected image';

        item.appendChild(img);
        item.appendChild(text);
        container.appendChild(item);
    }

    function showMain(src, name) {
        if (!mainPanel || !mainItems || !src) return;
        addThumb(mainItems, src, name, true);
        mainPanel.hidden = false;
    }

    function showAdditional(src, name) {
        if (!galleryPanel || !galleryItems || !src) return;
        addThumb(galleryItems, src, name, false);
        galleryPanel.hidden = false;
    }

    /* Local computer selection preview. */
    if (mainInput) {
        mainInput.addEventListener('change', function(){
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];
            showMain(URL.createObjectURL(file), file.name);
        });
    }

    if (galleryInput) {
        galleryInput.addEventListener('change', function(){
            if (!this.files || !this.files.length) return;
            if (galleryItems) galleryItems.innerHTML = '';
            Array.from(this.files).forEach(function(file){
                showAdditional(URL.createObjectURL(file), file.name);
            });
        });
    }

    /*
       Web Gallery preview.
       The existing gallery chooser uses .media-popup-select.
       Read its image URL/name without interfering with existing submit logic.
    */
    document.addEventListener('click', function(e){
        const choose = e.target.closest('.media-popup-select');
        if (!choose) return;

        /* Itinerary has its own working preview logic; leave it untouched. */
        if (mediaModal && mediaModal.dataset.itineraryMode === '1') return;

        const src =
            choose.dataset.url ||
            choose.dataset.preview ||
            (choose.closest('.media-popup-item') &&
             choose.closest('.media-popup-item').querySelector('img')
                ? choose.closest('.media-popup-item').querySelector('img').src
                : '');

        const name =
            choose.dataset.name ||
            choose.dataset.image ||
            (choose.closest('.media-popup-item') &&
             choose.closest('.media-popup-item').querySelector('img')
                ? choose.closest('.media-popup-item').querySelector('img').alt
                : 'Selected image');

        if (!src) return;

        if (pickerMode === 'gallery') {
            showAdditional(src, name);
        } else {
            showMain(src, name);
        }
    }, true);

});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    let activePackageImageMode = 'main';

    const modal = document.getElementById('mediaModal');
    const mainInput = document.getElementById('mainImage');
    const galleryInput = document.getElementById('galleryImages');

    /* Remember whether Main or Additional Images opened the gallery. */
    document.querySelectorAll('.open-media-picker').forEach(function(btn){
        btn.addEventListener('click', function(){
            activePackageImageMode =
                this.dataset.mode === 'gallery' ? 'gallery' : 'main';
        }, true);
    });

    /*
     * The page has an existing Computer / PC button in the media popup.
     * Catch it by its visible text instead of depending on an old class name.
     * Itinerary mode is excluded because its own chooser already works.
     */
    if (modal) {
        modal.addEventListener('click', function(e){
            const control = e.target.closest('button, a');
            if (!control) return;

            if (modal.dataset.itineraryMode === '1') {
                return;
            }

            const text = (control.textContent || '')
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase();

            const isComputerButton =
                text === 'computer / pc' ||
                text === 'computer/pc' ||
                text === 'choose from computer / pc';

            if (!isComputerButton) return;

            e.preventDefault();
            e.stopImmediatePropagation();

            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('media-modal-open');

            window.setTimeout(function(){
                if (activePackageImageMode === 'gallery') {
                    if (galleryInput) {
                        galleryInput.value = '';
                        galleryInput.click();
                    }
                } else {
                    if (mainInput) {
                        mainInput.value = '';
                        mainInput.click();
                    }
                }
            }, 50);
        }, true);
    }
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    function syncItineraryUploadIndexes() {
        document.querySelectorAll('#itineraryDays .itinerary-day').forEach(function(day, index) {
            const file = day.querySelector('input.itinerary-image-input[type="file"]');
            if (file) {
                file.name = 'itinerary_image[' + index + ']';
            }

            const id = day.querySelector('input[name^="itinerary_id"]');
            if (id) id.name = 'itinerary_id[' + index + ']';

            const title = day.querySelector('[name^="itinerary_title"]');
            if (title) title.name = 'itinerary_title[' + index + ']';

            const desc = day.querySelector('[name^="itinerary_description"]');
            if (desc) desc.name = 'itinerary_description[' + index + ']';

            const remove = day.querySelector('input[name^="itinerary_remove_image"]');
            if (remove) remove.name = 'itinerary_remove_image[' + index + ']';
        });
    }

    syncItineraryUploadIndexes();

    const container = document.getElementById('itineraryDays');
    if (container) {
        const observer = new MutationObserver(function() {
            syncItineraryUploadIndexes();
        });
        observer.observe(container, {childList:true});
    }

    document.addEventListener('click', function(e) {
        if (
            e.target.closest('#addItineraryDay') ||
            e.target.closest('.remove-itinerary-day') ||
            e.target.closest('.remove-day-btn')
        ) {
            setTimeout(syncItineraryUploadIndexes, 0);
        }
    });

    const form = container ? container.closest('form') : null;
    if (form) {
        form.addEventListener('submit', syncItineraryUploadIndexes, true);
    }
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeItineraryDay = null;
    const modal = document.getElementById('mediaModal');

    function itineraryRows() {
        return Array.from(document.querySelectorAll('#itineraryDays .itinerary-day'));
    }

    function syncItineraryLibraryFields() {
        itineraryRows().forEach(function(day, index) {
            let hidden = day.querySelector('.itinerary-library-image');
            if (hidden) {
                hidden.name = 'itinerary_library_image[' + index + ']';
            }
        });
    }

    document.addEventListener('click', function(e) {
        const button = e.target.closest('.open-itinerary-media-picker');
        if (!button) return;

        activeItineraryDay = button.closest('.itinerary-day');
        syncItineraryLibraryFields();

        if (modal) {
            modal.dataset.itineraryMode = '1';
        }
    }, true);

    /*
    | Capture Web Gallery selection for itinerary BEFORE the generic
    | main/additional gallery handler gets it.
    */
    document.addEventListener('click', function(e) {
        const choose = e.target.closest('.media-popup-select');

        if (
            !choose ||
            !modal ||
            modal.dataset.itineraryMode !== '1' ||
            !activeItineraryDay
        ) {
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        const imagePath =
            choose.dataset.image ||
            (choose.closest('.media-popup-item')
                ? choose.closest('.media-popup-item').dataset.image || ''
                : '');

        const imageUrl =
            choose.dataset.url ||
            (choose.closest('.media-popup-item') &&
             choose.closest('.media-popup-item').querySelector('img')
                ? choose.closest('.media-popup-item').querySelector('img').src
                : '');

        if (!imagePath) {
            return;
        }

        let hidden =
            activeItineraryDay.querySelector('.itinerary-library-image');

        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'itinerary-library-image';
            activeItineraryDay.appendChild(hidden);
        }

        const index = itineraryRows().indexOf(activeItineraryDay);

        hidden.name =
            'itinerary_library_image[' + Math.max(index, 0) + ']';

        hidden.value = imagePath;

        /*
        | Show selected Web Gallery image immediately in the day's preview.
        */
        const preview =
            activeItineraryDay.querySelector('.day-preview');

        if (preview && imageUrl) {
            preview.innerHTML = '';

            const img = document.createElement('img');
            img.src = imageUrl;
            img.alt = 'Selected itinerary image';
            preview.appendChild(img);
        }

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modal.dataset.itineraryMode = '0';
        document.body.classList.remove('media-modal-open');
    }, true);

    const itineraryContainer = document.getElementById('itineraryDays');

    if (itineraryContainer) {
        new MutationObserver(syncItineraryLibraryFields)
            .observe(itineraryContainer, {childList:true});
    }

    syncItineraryLibraryFields();
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('pexelsModal');
    const searchInput = document.getElementById('pexelsSearchInput');
    const searchButton = document.getElementById('pexelsSearchButton');
    const results = document.getElementById('pexelsResults');
    const statusBox = document.getElementById('pexelsStatus');
    const pagination = document.getElementById('pexelsPagination');
    const previousButton = document.getElementById('pexelsPrevious');
    const nextButton = document.getElementById('pexelsNext');
    const pageText = document.getElementById('pexelsPageText');
    const csrfToken = <?= json_encode($csrfToken); ?>;

    let activeMode = 'main';
    let activeItineraryDay = null;
    let currentQuery = '';
    let currentPage = 1;
    let hasNextPage = false;
    let hasPreviousPage = false;

    if (!modal) {
        return;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function showStatus(message, isError) {
        statusBox.textContent = message;
        statusBox.hidden = !message;
        statusBox.classList.toggle('error', Boolean(isError));
    }

    function openModal(button) {
        activeMode = button.dataset.pexelsMode || 'main';
        activeItineraryDay = activeMode === 'itinerary'
            ? button.closest('.itinerary-day')
            : null;

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pexels-modal-open');

        showStatus('', false);

        if (!results.children.length) {
            results.innerHTML = `
                <div class="pexels-empty">
                    <i class="fa-regular fa-images"></i>
                    Enter a destination or travel keyword to search Pexels + Unsplash.
                </div>
            `;
        }

        setTimeout(function () {
            searchInput?.focus();
        }, 60);
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pexels-modal-open');
    }

    async function postPexels(data) {
        const formData = new FormData();

        formData.append('pexels_ajax', '1');
        formData.append('csrf_token', csrfToken);

        Object.entries(data).forEach(function ([key, value]) {
            formData.append(key, value ?? '');
        });

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let payload;

        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(
                'The server returned an invalid response. Check PHP error logs.'
            );
        }

        if (!response.ok || !payload.success) {
            throw new Error(
                payload.message || 'The photo search request failed.'
            );
        }

        return payload;
    }

    function renderPhotos(photos) {
        if (!Array.isArray(photos) || photos.length === 0) {
            results.innerHTML = `
                <div class="pexels-empty">
                    <i class="fa-regular fa-face-frown"></i>
                    No royalty-free photos were found for this search.
                </div>
            `;
            return;
        }

        results.innerHTML = photos.map(function (photo) {
            const encoded = encodeURIComponent(JSON.stringify(photo));
            const provider = String(photo.provider || 'pexels').toLowerCase();
            const photoTitle = String(
                photo.alt
                || currentQuery
                || (((photo.provider || '').toLowerCase() === 'unsplash' ? 'Unsplash Travel Photo ' : 'Pexels Travel Photo ') + photo.id)
            ).trim();

            return `
                <article
                    class="pexels-photo-card"
                    data-provider="${provider}"
                    data-photo="${encoded}"
                    tabindex="0"
                    role="button"
                    aria-label="Select ${escapeHtml(photoTitle)}"
                >
                    <div class="pexels-photo-image">
                        <span class="free-photo-provider-badge ${provider}">${escapeHtml(photo.provider_label || (provider === 'unsplash' ? 'Unsplash' : 'Pexels'))}</span>
                        <img
                            src="${escapeHtml(photo.preview)}"
                            alt="${escapeHtml(photoTitle)}"
                            loading="lazy"
                        >
                    </div>

                    <div class="pexels-photo-credit">
                        <span class="pexels-photo-credit-line">
                            ©
                            <a
                                href="${escapeHtml(photo.photographer_url || photo.photo_url)}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                ${escapeHtml(photo.photographer || ((photo.provider || '').toLowerCase() === 'unsplash' ? 'Unsplash contributor' : 'Pexels Contributor'))}
                            </a>
                            /
                            <a
                                href="${escapeHtml(photo.photo_url)}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                ${escapeHtml(photo.provider_label || ((photo.provider || '').toLowerCase() === 'unsplash' ? 'Unsplash' : 'Pexels'))}
                            </a>
                        </span>
                    </div>

                    <div class="pexels-photo-body">
                        <div class="pexels-photo-name">
                            ${escapeHtml(photoTitle)}
                        </div>

                        <button
                            type="button"
                            class="pexels-import-button"
                            data-photo="${encoded}"
                        >
                            <i class="fa-solid fa-cloud-arrow-down"></i>
                            Select &amp; Use Photo
                        </button>
                    </div>
                </article>
            `;
        }).join('');
    }

    async function searchPhotos(page) {
        const query = searchInput.value.trim();

        if (!query) {
            showStatus('Please enter a photo search keyword.', true);
            searchInput.focus();
            return;
        }

        currentQuery = query;
        currentPage = Math.max(1, Number(page || 1));

        searchButton.disabled = true;
        searchButton.textContent = 'Searching...';

        results.innerHTML = `
            <div class="pexels-empty">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Searching Pexels + Unsplash photos...
            </div>
        `;

        showStatus('', false);
        pagination.hidden = true;

        try {
            const payload = await postPexels({
                action: 'pexels_search',
                query: currentQuery,
                page: currentPage
            });

            const data = payload.data || {};

            renderPhotos(data.photos || []);

            hasNextPage = Boolean(data.next_page);
            hasPreviousPage = Boolean(data.prev_page);

            previousButton.disabled = !hasPreviousPage;
            nextButton.disabled = !hasNextPage;
            pageText.textContent =
                'Page ' + Number(data.page || currentPage)
                + ' • ' + Number(data.total_results || 0)
                + ' result(s)';

            pagination.hidden = false;

        } catch (error) {
            results.innerHTML = `
                <div class="pexels-empty">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Unable to load photos.
                </div>
            `;

            showStatus(error.message, true);

        } finally {
            searchButton.disabled = false;
            searchButton.textContent = 'Search Photos';
        }
    }

    function addAttributionChip(container, saved) {
        if (!container || !saved.attribution) {
            return;
        }

        let chip = container.querySelector('.pexels-attribution-chip');

        if (!chip) {
            chip = document.createElement('div');
            chip.className = 'pexels-attribution-chip';
            container.appendChild(chip);
        }

        chip.textContent = saved.attribution;
    }

    function applyImportedImage(saved) {
        const imagePath = saved.image || '';
        const imageUrl = saved.url || '';
        const imageName = saved.name || 'Imported royalty-free photo';

        if (!imagePath || !imageUrl) {
            throw new Error('The imported image path is missing.');
        }

        if (activeMode === 'main') {
            const hidden = document.getElementById('libraryMainImage');
            const selectedBox = document.getElementById('mediaSelected');
            const selectedPreview = document.getElementById('mediaSelectedPreview');
            const selectedName = selectedBox?.querySelector('span');

            if (hidden) {
                hidden.value = imagePath;
            }

            if (selectedPreview) {
                selectedPreview.src = imageUrl;
            }

            if (selectedName) {
                selectedName.textContent = imageName;
            }

            if (selectedBox) {
                selectedBox.hidden = false;
                addAttributionChip(selectedBox, saved);
            }

            const mainPanel = document.getElementById('mainChosenPreview');
            const mainItems = document.getElementById('mainChosenPreviewItems');

            if (mainItems) {
                mainItems.innerHTML = `
                    <div class="chosen-image-thumb">
                        <img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(imageName)}">
                        <span>${escapeHtml(imageName)}</span>
                    </div>
                `;
            }

            if (mainPanel) {
                mainPanel.hidden = false;
                addAttributionChip(mainPanel, saved);
            }

            return;
        }

        if (activeMode === 'gallery') {
            const form = document.querySelector(
                'form input[name="action"][value="create"], form input[name="action"][value="update"]'
            )?.closest('form') || document.querySelector('form[enctype="multipart/form-data"]');

            if (!form) {
                throw new Error('Package form was not found.');
            }

            const existing = Array.from(
                form.querySelectorAll('input[name="library_gallery_images[]"]')
            ).some(function (input) {
                return input.value === imagePath;
            });

            if (!existing) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'library_gallery_images[]';
                hidden.value = imagePath;
                form.appendChild(hidden);
            }

            const galleryPanel = document.getElementById('galleryChosenPreview');
            const galleryItems = document.getElementById('galleryChosenPreviewItems');

            if (galleryItems) {
                const item = document.createElement('div');
                item.className = 'chosen-image-thumb';
                item.innerHTML = `
                    <img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(imageName)}">
                    <span>${escapeHtml(imageName)}</span>
                `;
                galleryItems.appendChild(item);
            }

            if (galleryPanel) {
                galleryPanel.hidden = false;
                addAttributionChip(galleryPanel, saved);
            }

            return;
        }

        if (activeMode === 'itinerary') {
            if (!activeItineraryDay) {
                throw new Error('The itinerary day could not be identified.');
            }

            const days = Array.from(
                document.querySelectorAll('#itineraryDays .itinerary-day')
            );

            const index = Math.max(0, days.indexOf(activeItineraryDay));

            let hidden = activeItineraryDay.querySelector(
                '.itinerary-library-image'
            );

            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.className = 'itinerary-library-image';
                activeItineraryDay.appendChild(hidden);
            }

            hidden.name = 'itinerary_library_image[' + index + ']';
            hidden.value = imagePath;

            const preview = activeItineraryDay.querySelector('.day-preview');

            if (preview) {
                preview.innerHTML = `
                    <img
                        src="${escapeHtml(imageUrl)}"
                        alt="${escapeHtml(imageName)}"
                    >
                `;
                addAttributionChip(
                    activeItineraryDay.querySelector('.day-image-box'),
                    saved
                );
            }
        }
    }

    async function importPhoto(button) {
        let photo;

        try {
            photo = JSON.parse(
                decodeURIComponent(button.dataset.photo || '')
            );
        } catch (error) {
            showStatus('Invalid selected photo information.', true);
            return;
        }

        const originalHtml = button.innerHTML;

        button.disabled = true;
        button.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin"></i> Importing...';

        showStatus(
            ((photo.provider || '').toLowerCase() === 'unsplash' ? 'Selecting Unsplash photo and recording attribution...' : 'Downloading, optimizing and saving the selected Pexels photo...'),
            false
        );

        try {
            const payload = await postPexels({
                action: 'pexels_import',
                provider: photo.provider || 'pexels',
                photo_id: photo.id,
                image_url: photo.download,
                photo_url: photo.photo_url,
                photographer: photo.photographer,
                photographer_url: photo.photographer_url,
                download_location: photo.download_location || '',
                alt: photo.alt
            });

            applyImportedImage(payload.data || {});

            button.innerHTML =
                '<i class="fa-solid fa-circle-check"></i> Selected';

            button.closest('.pexels-photo-card')?.classList.add('selected');

            showStatus(
                payload.message
                + ' Attribution: '
                + (payload.data?.attribution || 'Photo source saved.'),
                false
            );

            /*
            |--------------------------------------------------------------------------
            | MULTIPLE PEXELS SELECTION FOR ADDITIONAL GALLERY IMAGES
            |--------------------------------------------------------------------------
            |
            | Main image and itinerary image keep the existing one-photo behavior
            | and close the Pexels popup after selection.
            |
            | Additional Gallery Images now keeps the popup open so the admin can
            | select multiple Pexels photos in the same search session. Each selected
            | photo is imported and appended to library_gallery_images[] immediately.
            | Use the existing Done button to close the popup after all photos are chosen.
            |
            */

            if (activeMode !== 'gallery') {
                setTimeout(function () {
                    closeModal();
                }, 650);
            }

        } catch (error) {
            button.disabled = false;
            button.innerHTML = originalHtml;
            showStatus(error.message, true);
        }
    }

    document.addEventListener('click', function (event) {
        const launcher = event.target.closest('.open-pexels-picker');

        if (launcher) {
            event.preventDefault();
            openModal(launcher);
            return;
        }

        const importButton = event.target.closest('.pexels-import-button');

        if (importButton) {
            event.preventDefault();
            event.stopPropagation();
            importPhoto(importButton);
            return;
        }

        const photoCard = event.target.closest('.pexels-photo-card');

        if (
            photoCard
            && !event.target.closest('a')
        ) {
            event.preventDefault();

            const cardButton = photoCard.querySelector('.pexels-import-button');

            if (cardButton && !cardButton.disabled) {
                importPhoto(cardButton);
            }
        }
    });

    results?.addEventListener('keydown', function (event) {
        if (
            event.key !== 'Enter'
            && event.key !== ' '
        ) {
            return;
        }

        const photoCard = event.target.closest('.pexels-photo-card');

        if (!photoCard) {
            return;
        }

        event.preventDefault();

        const cardButton = photoCard.querySelector('.pexels-import-button');

        if (cardButton && !cardButton.disabled) {
            importPhoto(cardButton);
        }
    });

    modal.querySelectorAll('[data-close-pexels]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    searchButton?.addEventListener('click', function () {
        searchPhotos(1);
    });

    searchInput?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchPhotos(1);
        }
    });

    previousButton?.addEventListener('click', function () {
        if (hasPreviousPage) {
            searchPhotos(currentPage - 1);
        }
    });

    nextButton?.addEventListener('click', function () {
        if (hasNextPage) {
            searchPhotos(currentPage + 1);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape'
            && modal.classList.contains('open')
        ) {
            closeModal();
        }
    });
});
</script>



<script>
document.addEventListener('DOMContentLoaded', function () {
    const rowsContainer = document.getElementById('packagePriceRows');
    const addButton = document.getElementById('addPackagePriceRow');

    if (!rowsContainer || !addButton) {
        return;
    }

    function todayValue() {
        const date = new Date();
        const offset = date.getTimezoneOffset();
        const localDate = new Date(date.getTime() - offset * 60000);
        return localDate.toISOString().slice(0, 10);
    }

    function addDays(dateValue, days) {
        const date = dateValue
            ? new Date(dateValue + 'T00:00:00')
            : new Date();

        date.setDate(date.getDate() + days);

        const offset = date.getTimezoneOffset();
        const localDate = new Date(date.getTime() - offset * 60000);

        return localDate.toISOString().slice(0, 10);
    }

    function lastRowEndDate() {
        const rows = Array.from(
            rowsContainer.querySelectorAll('.package-price-row')
        );

        const lastRow = rows[rows.length - 1] || null;
        const value = lastRow
            ? lastRow.querySelector('.price-date-to')?.value
            : '';

        return value || todayValue();
    }

    function createRow() {
        const previousEnd = lastRowEndDate();
        const start = addDays(previousEnd, 1);
        const end = addDays(start, 30);

        const row = document.createElement('div');
        row.className = 'package-price-row';

        row.innerHTML = `
            <input type="date" name="price_valid_from[]" class="form-control price-date-from" value="${start}" required>
            <input type="date" name="price_valid_to[]" class="form-control price-date-to" value="${end}" required>
            <input type="number" name="price_3_star[]" class="form-control" min="0" step="0.01" placeholder="3 star price">
            <input type="number" name="price_4_star[]" class="form-control" min="0" step="0.01" placeholder="4 star price">
            <input type="number" name="price_5_star[]" class="form-control" min="0" step="0.01" placeholder="5 star price">
            <button type="button" class="remove-price-row-btn" title="Remove this price row">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;

        rowsContainer.appendChild(row);
        row.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    }

    addButton.addEventListener('click', createRow);

    rowsContainer.addEventListener('click', function (event) {
        const removeButton = event.target.closest('.remove-price-row-btn');

        if (!removeButton) {
            return;
        }

        const row = removeButton.closest('.package-price-row');

        if (!row) {
            return;
        }

        if (
            rowsContainer.querySelectorAll('.package-price-row').length === 1
        ) {
            row.querySelectorAll('input').forEach(function (input) {
                if (input.type === 'date') {
                    return;
                }

                input.value = '';
            });

            return;
        }

        row.remove();
    });

    rowsContainer.addEventListener('change', function (event) {
        if (!event.target.classList.contains('price-date-from')) {
            return;
        }

        const row = event.target.closest('.package-price-row');
        const toInput = row?.querySelector('.price-date-to');

        if (
            toInput
            && event.target.value
            && (
                !toInput.value
                || toInput.value < event.target.value
            )
        ) {
            toInput.value = event.target.value;
        }
    });
});
</script>



<script>
(function(){
    const section=document.getElementById('package-form');
    if(!section) return;

    if(section.dataset.packageEditorInitialized==='1') return;
    section.dataset.packageEditorInitialized='1';

    const form=section.querySelector('form');
    const grid=form ? form.querySelector('.form-grid') : null;
    if(!form || !grid) return;

    /*
    |--------------------------------------------------------------------------
    | ONE-SCREEN ADD / EDIT MODE
    |--------------------------------------------------------------------------
    | Add New Package uses #package-form.
    | Edit Package uses ?edit=ID#package-form.
    | In either case, turn the existing form into a tabbed workspace without
    | changing any field names, values, validation, uploads or submission.
    */
    const url=new URL(window.location.href);
    const editorRequested=
        window.location.hash==='#package-form'
        || url.searchParams.has('edit')
        || url.searchParams.has('new');

    if(!editorRequested) return;

    document.body.classList.add('package-editor-mode');

    const groups=[...grid.querySelectorAll(':scope > .form-group')];

    const sectionForGroup=(group)=>{
        const names=[...group.querySelectorAll('[name]')]
            .map(el=>(el.getAttribute('name')||'').toLowerCase());
        const label=(group.querySelector('label')?.textContent||'').toLowerCase();
        const all=(names.join(' ')+' '+label+' '+group.textContent.toLowerCase());

        if(
            all.includes('itinerary_')
            || all.includes('day-wise itinerary')
            || group.querySelector('.itinerary-builder')
        ) return 'itinerary';

        if(
            all.includes('main_image')
            || all.includes('gallery_images')
            || all.includes('package image')
            || all.includes('additional package images')
        ) return 'media';

        if(
            all.includes('hotel_3_star_id')
            || all.includes('hotel_4_star_id')
            || all.includes('hotel_5_star_id')
            || all.includes('automatic hotels')
            || group.querySelector('.automatic-hotel-builder')
        ) return 'hotels';

        if(
            all.includes('price_valid_')
            || all.includes('price_3_star')
            || all.includes('price_4_star')
            || all.includes('price_5_star')
            || all.includes('child_price')
            || all.includes('child_age_')
            || all.includes('currency')
            || all.includes('pricing')
            || all.includes('date-wise')
        ) return 'pricing';

        if(
            all.includes('inclusions')
            || all.includes('exclusions')
            || all.includes('tour_highlights')
            || all.includes('terms')
            || all.includes('highlights')
            || all.includes('conditions')
        ) return 'content';

        if(
            all.includes('featured')
            || all.includes('bestseller')
            || names.includes('status')
            || all.includes('package promotion')
            || all.includes('package status')
        ) return 'publish';

        return 'basic';
    };

    groups.forEach(group=>{
        group.dataset.packageSection=sectionForGroup(group);
    });

    const tabs=[
        ['basic','fa-solid fa-pen-to-square','Basic Info'],
        ['hotels','fa-solid fa-hotel','Hotels'],
        ['pricing','fa-solid fa-indian-rupee-sign','Pricing'],
        ['media','fa-regular fa-images','Images'],
        ['itinerary','fa-solid fa-route','Itinerary'],
        ['content','fa-solid fa-list-check','Content'],
        ['publish','fa-solid fa-paper-plane','Publish']
    ];

    const nav=document.createElement('nav');
    nav.className='package-editor-tabs';
    nav.setAttribute('aria-label','Package management sections');

    tabs.forEach(([key,icon,label])=>{
        const btn=document.createElement('button');
        btn.type='button';
        btn.className='package-editor-tab';
        btn.dataset.packageTab=key;
        btn.innerHTML='<i class="'+icon+'"></i><span>'+label+'</span>';
        nav.appendChild(btn);
    });

    const status=document.createElement('span');
    status.className='package-tab-status';
    nav.appendChild(status);

    grid.parentNode.insertBefore(nav,grid);

    const tabButtons=[...nav.querySelectorAll('.package-editor-tab')];

    const activate=(name, updateHash=true)=>{
        tabButtons.forEach(btn=>{
            btn.classList.toggle('active',btn.dataset.packageTab===name);
        });

        groups.forEach(group=>{
            group.classList.toggle(
                'package-section-active',
                group.dataset.packageSection===name
            );
        });

        const index=tabs.findIndex(item=>item[0]===name);
        status.textContent='Section '+(index+1)+' of '+tabs.length;

        grid.scrollTop=0;

        if(updateHash){
            history.replaceState(
                null,
                '',
                location.pathname+location.search+'#package-form'
            );
        }
    };

    tabButtons.forEach(btn=>{
        btn.addEventListener('click',()=>activate(btn.dataset.packageTab));
    });

    /*
    | If browser validation finds an invalid field in another hidden tab,
    | automatically open that section so the admin can correct it.
    */
    form.addEventListener('invalid',function(event){
        const group=event.target.closest('.form-group');
        if(group && group.dataset.packageSection){
            activate(group.dataset.packageSection,false);
        }
    },true);

    /*
    | Before submission, show all sections for native browser validation.
    | Hidden groups are restored immediately if submission is prevented.
    */
    form.addEventListener('submit',function(){
        groups.forEach(group=>group.style.display='block');
        setTimeout(()=>{
            groups.forEach(group=>group.style.display='');
        },0);
    });

    activate('basic',false);
})();
</script>







<script>
(function(){
    const sidebar=document.getElementById('sidebar');
    const toggle=document.getElementById('tsMobileSidebarToggle');
    const overlay=document.getElementById('tsSidebarOverlay');
    if(!sidebar || !toggle || !overlay) return;

    function setOpen(open){
        sidebar.classList.toggle('ts-sidebar-open',open);
        toggle.setAttribute('aria-expanded',open?'true':'false');
        overlay.classList.toggle('ts-sidebar-open',open);
        overlay.setAttribute('aria-hidden',open?'false':'true');
        document.body.classList.toggle('ts-sidebar-lock',open);
    }

    toggle.addEventListener('click',()=>setOpen(!sidebar.classList.contains('ts-sidebar-open')));
    overlay.addEventListener('click',()=>setOpen(false));

    document.addEventListener('keydown',e=>{
        if(e.key==='Escape') setOpen(false);
    });

    sidebar.querySelectorAll('a').forEach(a=>{
        a.addEventListener('click',()=>{
            if(window.innerWidth<=850) setOpen(false);
        });
    });

    window.addEventListener('resize',()=>{
        if(window.innerWidth>850) setOpen(false);
    });
})();
</script>


<script id="travscope-package-editor-one-click-fix">
(function(){
    function ensurePackageEditorOnHash(){
        if(window.location.hash !== '#package-form') return;

        const url = new URL(window.location.href);

        if(url.searchParams.has('edit') || url.searchParams.has('new')) return;

        url.searchParams.set('new','1');
        window.location.replace(url.pathname + '?' + url.searchParams.toString() + '#package-form');
    }

    window.addEventListener('hashchange', ensurePackageEditorOnHash);
})();
</script>



<script id="travscope-independent-child-price-rows">
(function(){
    const rows = document.getElementById('childPriceRows');
    const add = document.getElementById('addChildPriceRow');
    if (!rows || !add) return;

    function rowHtml(){
        return `
            <div class="child-price-row">
                <input type="number" name="child_row_age_from[]" class="form-control"
                       min="0" max="18" step="1" placeholder="Age from">
                <input type="number" name="child_row_age_to[]" class="form-control"
                       min="0" max="18" step="1" placeholder="Age to">
                <input type="number" name="child_row_price[]" class="form-control"
                       min="0" step="0.01" placeholder="Child price">
                <button type="button" class="remove-price-row-btn remove-child-price-row"
                        title="Remove child price row">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>`;
    }

    add.addEventListener('click', function(){
        rows.insertAdjacentHTML('beforeend', rowHtml());
    });

    rows.addEventListener('click', function(event){
        const button = event.target.closest('.remove-child-price-row');
        if (!button) return;

        const row = button.closest('.child-price-row');
        if (!row) return;

        const allRows = rows.querySelectorAll('.child-price-row');
        if (allRows.length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input').forEach(input => input.value = '');
        }
    });
})();
</script>


<script src="assets/travscope-pro-admin.js?v=20260916-v2" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
</body>
</html>