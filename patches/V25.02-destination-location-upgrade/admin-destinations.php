<?php

/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM
| ADMIN DESTINATION MANAGEMENT
| ADMIN-PACKAGES IMAGE PICKER + EVERY PEXELS CARD SELECTABLE + CLEAR SIDEBAR VERSION: 2026-08-11-R9
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
$pexelsHttpFunctionsFile = __DIR__ . '/pexels-http-functions.php';
if (is_file($pexelsHttpFunctionsFile)) require_once $pexelsHttpFunctionsFile;
$unsplashPhotoFunctionsFile = __DIR__ . '/unsplash-photo-functions.php';
if (is_file($unsplashPhotoFunctionsFile)) require_once $unsplashPhotoFunctionsFile;
if (is_file(__DIR__ . '/location-master-functions.php')) {
    require_once __DIR__ . '/location-master-functions.php';
}

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['admin_id'])) {
    redirect(BASE_URL . 'admin-login.php');
}

$adminId = (int)$_SESSION['admin_id'];

$stmt = $pdo->prepare("
    SELECT id, name, email, role, status
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$adminId]);

$admin = $stmt->fetch();

if (!$admin || $admin['status'] !== 'active') {
    unset($_SESSION['admin_id']);
    redirect(BASE_URL . 'admin-login.php');
}


/*
|--------------------------------------------------------------------------
| TRAVSCOPE GEO LOCATION SELECTOR
| Read-only AJAX helper for Country -> State -> City/District dropdowns.
| Existing destination save/update logic remains unchanged.
|--------------------------------------------------------------------------
*/

function destinationGeoApiRequest(string $url, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for automatic location selection.');
    }

    $curl = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_USERAGENT => 'TRAVSCOPE Geo Location Selector'
    ];

    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            $curlError !== ''
                ? 'Location service error: ' . $curlError
                : 'Unable to load location data.'
        );
    }

    $decoded = json_decode((string)$response, true);

    if (!is_array($decoded) || !empty($decoded['error'])) {
        throw new RuntimeException(
            is_array($decoded) && !empty($decoded['msg'])
                ? (string)$decoded['msg']
                : 'Invalid location service response.'
        );
    }

    return $decoded;
}

function destinationGeoCountryStateData(): array
{
    $cacheFile = sys_get_temp_dir() . '/travscope_geo_country_states_v1.json';

    if (
        is_file($cacheFile)
        && (time() - (int)@filemtime($cacheFile)) < 86400
    ) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);

        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }

    $response = destinationGeoApiRequest(
        'https://countriesnow.space/api/v0.1/countries/states'
    );

    $data = is_array($response['data'] ?? null)
        ? $response['data']
        : [];

    if (!$data) {
        throw new RuntimeException('No country/state information was returned.');
    }

    @file_put_contents(
        $cacheFile,
        json_encode($data, JSON_UNESCAPED_UNICODE)
    );

    return $data;
}

if (isset($_GET['geo_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $geoAction = trim((string)$_GET['geo_ajax']);

        if ($geoAction === 'countries') {
            $rows = destinationGeoCountryStateData();
            $countries = [];

            foreach ($rows as $row) {
                $name = trim((string)($row['name'] ?? ''));

                if ($name !== '') {
                    $countries[] = $name;
                }
            }

            $countries = array_values(array_unique($countries));
            natcasesort($countries);

            echo json_encode([
                'success' => true,
                'data' => array_values($countries)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($geoAction === 'states') {
            $country = trim((string)($_GET['country'] ?? ''));

            if ($country === '') {
                throw new RuntimeException('Country is required.');
            }

            $states = [];

            foreach (destinationGeoCountryStateData() as $row) {
                if (strcasecmp(trim((string)($row['name'] ?? '')), $country) !== 0) {
                    continue;
                }

                foreach (($row['states'] ?? []) as $state) {
                    $name = trim((string)($state['name'] ?? ''));

                    if ($name !== '') {
                        $states[] = $name;
                    }
                }

                break;
            }

            $states = array_values(array_unique($states));
            natcasesort($states);

            echo json_encode([
                'success' => true,
                'data' => array_values($states)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($geoAction === 'cities') {
            $country = trim((string)($_GET['country'] ?? ''));
            $state = trim((string)($_GET['state'] ?? ''));

            if ($country === '' || $state === '') {
                throw new RuntimeException('Country and state are required.');
            }

            /*
             * V20.4:
             * Never make the admin UI depend on one external city API.
             * Try the live service first, then merge TRAVSCOPE's offline
             * travel-city safety list and any cities already known locally
             * from Hotel/Location Master.
             */
            $cities = [];
            $source = [];
            $remoteMessage = '';

            /*
             * Indian travel states have an offline safety list. Use it first so
             * cPanel does not wait on a blocked external POST request. For other
             * states/countries (or when no offline list exists), use the live API.
             */
            $fallback = function_exists('tsLocationFallbackCities')
                ? tsLocationFallbackCities($country, $state)
                : [];

            if ($fallback) {
                $cities = array_merge($cities, $fallback);
                $source[] = 'offline';
            } else {
                try {
                    $response = destinationGeoApiRequest(
                        'https://countriesnow.space/api/v0.1/countries/state/cities',
                        [
                            'country' => $country,
                            'state' => $state
                        ]
                    );

                    if (is_array($response['data'] ?? null)) {
                        $cities = array_merge($cities, $response['data']);
                        $source[] = 'live';
                    }
                } catch (Throwable $remoteError) {
                    $remoteMessage = $remoteError->getMessage();
                }
            }

            // Reuse child cities already saved under a state-level destination.
            try {
                if (function_exists('tsLocationTableExists')
                    && tsLocationTableExists($pdo, 'destinations')
                    && tsLocationTableExists($pdo, 'destination_locations')) {
                    $local = $pdo->prepare("
                        SELECT DISTINCT dl.location_name
                        FROM destinations d
                        JOIN destination_locations dl ON dl.destination_id=d.id
                        WHERE d.status='active'
                          AND dl.status='active'
                          AND LOWER(TRIM(COALESCE(d.country,'')))=LOWER(TRIM(?))
                          AND LOWER(TRIM(d.name))=LOWER(TRIM(?))
                        ORDER BY dl.location_name
                    ");
                    $local->execute([$country, $state]);
                    $localCities = $local->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    if ($localCities) {
                        $cities = array_merge($cities, $localCities);
                        $source[] = 'location_master';
                    }
                }
            } catch (Throwable $ignored) {}

            // Reuse Hotel Master geo cities. This makes the selector self-healing
            // after admins have already added hotels for a state.
            try {
                if (function_exists('tsLocationTableExists')
                    && tsLocationTableExists($pdo, 'hotels')
                    && function_exists('tsLocationColumnExists')
                    && tsLocationColumnExists($pdo, 'hotels', 'geo_state')
                    && tsLocationColumnExists($pdo, 'hotels', 'geo_city')
                    && tsLocationColumnExists($pdo, 'hotels', 'geo_country')) {
                    $hotelCities = $pdo->prepare("
                        SELECT DISTINCT COALESCE(NULLIF(TRIM(geo_city),''),NULLIF(TRIM(city),''))
                        FROM hotels
                        WHERE status='active'
                          AND LOWER(TRIM(COALESCE(geo_state,'')))=LOWER(TRIM(?))
                          AND (geo_country IS NULL OR geo_country='' OR LOWER(TRIM(geo_country))=LOWER(TRIM(?)))
                          AND COALESCE(NULLIF(TRIM(geo_city),''),NULLIF(TRIM(city),'')) IS NOT NULL
                    ");
                    $hotelCities->execute([$state, $country]);
                    $knownHotelCities = $hotelCities->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    if ($knownHotelCities) {
                        $cities = array_merge($cities, $knownHotelCities);
                        $source[] = 'hotel_master';
                    }
                }
            } catch (Throwable $ignored) {}

            $normalised = [];
            foreach ($cities as $cityName) {
                $cityName = trim((string)$cityName);
                if ($cityName === '') {
                    continue;
                }
                $normalised[strtolower($cityName)] = $cityName;
            }
            $cities = array_values($normalised);
            natcasesort($cities);
            $cities = array_values($cities);

            /*
             * Even when the live provider is down, return success with the
             * local/offline list. This prevents "City list unavailable" from
             * blocking Destination Master, Hotel Master and Package hotels.
             */
            echo json_encode([
                'success' => true,
                'data' => $cities,
                'source' => array_values(array_unique($source)),
                'message' => $cities
                    ? 'City list loaded.'
                    : ($remoteMessage !== '' ? 'Live city service unavailable; no local city list is saved yet.' : 'No city list found.')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('Invalid location request.');

    } catch (Throwable $e) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['destination_csrf'])) {
    $_SESSION['destination_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['destination_csrf'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function destinationImageUrl($image)
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

function destinationFilterChoices(array $choices, $previous, int $maxLength = 150): array
{
    // $previous comes only from this admin's previously validated session
    // context, keeping an empty filtered view useful after edit or deletion.
    if (is_string($previous) && $previous !== '' && mb_strlen($previous) <= $maxLength
        && !in_array($previous, $choices, true)) {
        $choices[] = $previous;
        natcasesort($choices);
        $choices = array_values($choices);
    }
    return $choices;
}

function destinationListFilters(array $input, array $countries, array $continents): array
{
    $filters = [];
    foreach (['q', 'status', 'continent', 'country', 'featured'] as $key) {
        $value = $input[$key] ?? '';
        $filters[$key] = is_scalar($value) ? trim((string)$value) : '';
    }
    $filters['q'] = mb_substr($filters['q'], 0, 200);
    $filters['status'] = strtolower($filters['status']);
    if (!in_array($filters['status'], ['active', 'inactive'], true)) $filters['status'] = '';
    if (!in_array($filters['country'], $countries, true)) $filters['country'] = '';
    if (!in_array($filters['continent'], $continents, true)) $filters['continent'] = '';
    if (!in_array($filters['featured'], ['1', '0'], true)) $filters['featured'] = '';
    return $filters;
}

function destinationListUrl(array $filters, array $editor = [], string $fragment = ''): string
{
    $params = [];
    foreach (['q', 'status', 'continent', 'country', 'featured'] as $key) {
        if (isset($filters[$key]) && is_scalar($filters[$key]) && (string)$filters[$key] !== '') {
            $params[$key] = (string)$filters[$key];
        }
    }
    if (isset($editor['edit']) && is_scalar($editor['edit']) && (int)$editor['edit'] > 0) {
        $params['edit'] = (int)$editor['edit'];
    } elseif (isset($editor['new']) && is_scalar($editor['new']) && (int)$editor['new'] === 1) {
        $params['new'] = 1;
    }
    $url = 'admin-destinations.php';
    if ($params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if ($fragment === 'destination-form') $url .= '#destination-form';
    return $url;
}

function destinationListWhere(array $filters): array
{
    $where = [];
    $params = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(d.name LIKE ? OR d.country LIKE ? OR d.continent LIKE ? OR d.slug LIKE ?)';
        $term = '%' . $filters['q'] . '%';
        array_push($params, $term, $term, $term, $term);
    }
    if (in_array($filters['status'] ?? '', ['active', 'inactive'], true)) {
        $where[] = 'd.status = ?';
        $params[] = $filters['status'];
    }
    foreach (['continent', 'country'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $where[] = 'TRIM(d.' . $key . ') = ?';
            $params[] = $filters[$key];
        }
    }
    if (in_array($filters['featured'] ?? '', ['1', '0'], true)) {
        $where[] = 'd.featured = ?';
        $params[] = (int)$filters['featured'];
    }
    return ['where' => $where, 'params' => $params];
}

function destinationFormValues(array $current, array $posted): array
{
    foreach (['name', 'country', 'continent', 'short_description', 'description'] as $key) {
        if (isset($posted[$key]) && is_scalar($posted[$key])) $current[$key] = (string)$posted[$key];
    }
    $current['featured'] = !empty($posted['featured']) ? 1 : 0;
    if (isset($posted['status']) && is_scalar($posted['status'])) {
        $status = strtolower(trim((string)$posted['status']));
        if (in_array($status, ['active', 'inactive'], true)) $current['status'] = $status;
    }
    if (isset($posted['sort_order']) && is_scalar($posted['sort_order'])) {
        $current['sort_order'] = max(0, (int)$posted['sort_order']);
    }
    foreach (['image_library_path', 'banner_library_path'] as $key) {
        $current[$key] = isset($posted[$key]) && is_scalar($posted[$key]) ? trim((string)$posted[$key]) : '';
    }
    foreach (['remove_image', 'remove_banner_image'] as $key) {
        $current[$key] = !empty($posted[$key]) ? 1 : 0;
    }
    return $current;
}

function makeDestinationSlug($text)
{
    $text = trim((string)$text);

    if ($text === '') {
        return 'destination';
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
        : 'destination';
}

function uniqueDestinationSlug(
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
                FROM destinations
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
                FROM destinations
                WHERE slug = ?
                LIMIT 1
            ");

            $stmt->execute([$slug]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;

        $counter++;
    }
}

function uploadDestinationImage(
    array $file,
    string $prefix = 'destination'
) {
    if (
        !isset($file['error']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Image upload failed.'
        );
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException(
            'Each image must be smaller than 5 MB.'
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(
            'Invalid image upload.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mime = $finfo->file($file['tmp_name']);

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

    if (@getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException(
            'Uploaded file is not a valid image.'
        );
    }

    $directory =
        __DIR__ . '/uploads/destinations/';

    if (!is_dir($directory)) {

        if (!mkdir($directory, 0755, true)) {
            throw new RuntimeException(
                'Unable to create destination upload directory.'
            );
        }
    }

    $extension = $allowed[$mime];

    $fileName =
        $prefix . '_' .
        bin2hex(random_bytes(12)) .
        '.' .
        $extension;

    $destination =
        $directory . $fileName;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {
        throw new RuntimeException(
            'Unable to save destination image.'
        );
    }

    return
        'uploads/destinations/' .
        $fileName;
}

function deleteDestinationImage($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return;
    }

    if (preg_match('#^https?://#i', $image)) {
        return;
    }

    /*
    | Security:
    | Only files from destination upload folder
    | may be deleted.
    */

    if (
        strpos(
            $image,
            'uploads/destinations/'
        ) !== 0
    ) {
        return;
    }

    $path =
        __DIR__ . '/' .
        ltrim($image, '/');

    if (is_file($path)) {
        @unlink($path);
    }
}


/*
|--------------------------------------------------------------------------
| DESTINATION WEB GALLERY + PEXELS HELPERS
|--------------------------------------------------------------------------
*/

function destinationMediaLibrary(): array
{
    $items = [];

    $sources = [
        [
            'dir' => __DIR__ . '/uploads/destinations/',
            'prefix' => 'uploads/destinations/'
        ],
        [
            'dir' => __DIR__ . '/uploads/packages/',
            'prefix' => 'uploads/packages/'
        ],
        [
            'dir' => __DIR__ . '/uploads/itinerary/',
            'prefix' => 'uploads/itinerary/'
        ]
    ];

    foreach ($sources as $source) {
        if (!is_dir($source['dir'])) {
            continue;
        }

        foreach (scandir($source['dir']) ?: [] as $name) {
            $path = $source['dir'] . $name;
            $extension = strtolower(
                pathinfo($name, PATHINFO_EXTENSION)
            );

            if (
                is_file($path)
                && in_array(
                    $extension,
                    ['jpg', 'jpeg', 'png', 'webp'],
                    true
                )
            ) {
                $items[] = [
                    'name' => $name,
                    'image' => $source['prefix'] . $name,
                    'url' => destinationImageUrl(
                        $source['prefix'] . $name
                    ),
                    'mtime' => (int)@filemtime($path)
                ];
            }
        }
    }

    usort(
        $items,
        static fn(array $a, array $b): int =>
            $b['mtime'] <=> $a['mtime']
    );

    return $items;
}

function validDestinationMediaPath(string $path): ?string
{
    $path = trim($path);

    if (function_exists('tsOfficialUnsplashImageUrl') && tsOfficialUnsplashImageUrl($path)) {
        return $path;
    }

    $roots = [
        'uploads/destinations/' => __DIR__ . '/uploads/destinations/',
        'uploads/packages/' => __DIR__ . '/uploads/packages/',
        'uploads/itinerary/' => __DIR__ . '/uploads/itinerary/'
    ];

    foreach ($roots as $prefix => $root) {
        if (strpos($path, $prefix) !== 0) {
            continue;
        }

        $base = realpath($root);
        $file = realpath(__DIR__ . '/' . $path);

        if (
            $base
            && $file
            && is_file($file)
            && strpos(
                $file,
                $base . DIRECTORY_SEPARATOR
            ) === 0
        ) {
            return $path;
        }
    }

    return null;
}

function ensureDestinationAttributionTable(PDO $pdo): void
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

function destinationPexelsApiKey(): string
{
    return defined('PEXELS_API_KEY')
        ? trim((string)PEXELS_API_KEY)
        : '';
}

function destinationPexelsSearch(
    string $query,
    int $page = 1
): array {
    $apiKey = destinationPexelsApiKey();

    if (
        $apiKey === ''
        || $apiKey === 'YOUR_PEXELS_API_KEY_HERE'
    ) {
        throw new RuntimeException(
            'Pexels API key is not configured in config.php.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL is required for Pexels search.'
        );
    }

    $query = trim($query);

    if ($query === '') {
        throw new RuntimeException(
            'Enter a destination photo keyword.'
        );
    }

    $page = max(1, $page);

    $baseUrl = defined('PEXELS_API_BASE_URL')
        ? rtrim((string)PEXELS_API_BASE_URL, '/')
        : 'https://api.pexels.com/v1';

    $perPage = defined('PEXELS_API_PER_PAGE')
        ? max(6, min(40, (int)PEXELS_API_PER_PAGE))
        : 24;

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

    if ($statusCode !== 200 || !is_array($data)) {
        throw new RuntimeException(
            is_array($data) && !empty($data['error'])
                ? (string)$data['error']
                : 'Unable to load Pexels photos.'
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

        $id = trim((string)($photo['id'] ?? ''));
        $photoUrl = trim((string)($photo['url'] ?? ''));
        $photographer = trim((string)(
            $photo['photographer'] ?? ''
        ));
        $photographerUrl = trim((string)(
            $photo['photographer_url'] ?? ''
        ));

        if (
            $id === ''
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
            'id' => $id,
            'preview' => $preview,
            'download' => $download,
            'photo_url' => $photoUrl,
            'photographer' => $photographer,
            'photographer_url' => $photographerUrl,
            'alt' => trim((string)(
                $photo['alt'] ?? $query
            ))
        ];
    }

    return [
        'photos' => $photos,
        'page' => (int)($data['page'] ?? $page),
        'next_page' => !empty($data['next_page']),
        'prev_page' => !empty($data['prev_page'])
    ];
}


function destinationCombinedPhotoSearch(string $query, int $page = 1): array
{
    $pexelsPhotos = [];
    $unsplashPhotos = [];
    $status = [];
    $pexelsMeta = ['next_page' => false, 'prev_page' => false];
    $unsplashMeta = ['next_page' => false, 'prev_page' => false];

    try {
        $pexels = destinationPexelsSearch($query, $page);
        $pexelsPhotos = array_values((array)($pexels['photos'] ?? []));
        $pexelsMeta = $pexels;
        $status['pexels'] = ['ok'=>true,'count'=>count($pexelsPhotos),'message'=>count($pexelsPhotos)?'Pexels results loaded.':'Pexels returned no matching photos.'];
    } catch (Throwable $e) {
        $status['pexels'] = ['ok'=>false,'count'=>0,'message'=>'Pexels unavailable: '.$e->getMessage()];
    }

    try {
        $unsplash = tsUnsplashSearchPhotos($query, $page);
        $unsplashPhotos = array_values((array)($unsplash['photos'] ?? []));
        $unsplashMeta = $unsplash;
        $status['unsplash'] = ['ok'=>true,'count'=>count($unsplashPhotos),'message'=>count($unsplashPhotos)?'Unsplash results loaded.':'Unsplash returned no matching photos.'];
    } catch (Throwable $e) {
        $status['unsplash'] = ['ok'=>false,'count'=>0,'message'=>'Unsplash unavailable: '.$e->getMessage()];
    }

    $photos = tsInterleavePhotoProviders($pexelsPhotos, $unsplashPhotos);
    if (!$photos && empty($status['pexels']['ok']) && empty($status['unsplash']['ok'])) {
        throw new RuntimeException('Both photo providers are unavailable. '.$status['pexels']['message'].' '.$status['unsplash']['message']);
    }

    return [
        'photos'=>$photos,
        'page'=>max(1,$page),
        'next_page'=>!empty($pexelsMeta['next_page']) || !empty($unsplashMeta['next_page']),
        'prev_page'=>$page>1,
        'provider_status'=>$status,
        'total_results'=>count($photos),
        'counts'=>['all'=>count($photos),'pexels'=>count($pexelsPhotos),'unsplash'=>count($unsplashPhotos)],
    ];
}

function importDestinationUnsplashPhoto(PDO $pdo, array $photo): array
{
    $photoId = trim((string)($photo['id'] ?? ''));
    $imageUrl = trim((string)($photo['download'] ?? ''));
    $photoUrl = trim((string)($photo['photo_url'] ?? ''));
    $photographer = trim((string)($photo['photographer'] ?? 'Unsplash contributor'));
    $photographerUrl = trim((string)($photo['photographer_url'] ?? ''));
    $downloadLocation = trim((string)($photo['download_location'] ?? ''));
    $alt = trim((string)($photo['alt'] ?? 'Unsplash destination photo'));

    if ($photoId === '' || !tsOfficialUnsplashImageUrl($imageUrl)) {
        throw new RuntimeException('Invalid selected Unsplash photo.');
    }

    tsTrackUnsplashDownload($downloadLocation);
    ensureDestinationAttributionTable($pdo);
    $attribution = 'Photo by '.$photographer.' on Unsplash';
    $stmt = $pdo->prepare("INSERT INTO image_attributions
        (image_path,provider,provider_photo_id,photographer_name,photographer_url,photo_page_url,original_image_url,attribution_text,license_name)
        VALUES (?,'Unsplash',?,?,?,?,?,?, 'Unsplash API')
        ON DUPLICATE KEY UPDATE provider='Unsplash',provider_photo_id=VALUES(provider_photo_id),photographer_name=VALUES(photographer_name),photographer_url=VALUES(photographer_url),photo_page_url=VALUES(photo_page_url),original_image_url=VALUES(original_image_url),attribution_text=VALUES(attribution_text),license_name='Unsplash API'");
    $stmt->execute([$imageUrl,$photoId,$photographer,$photographerUrl?:null,$photoUrl?:null,$imageUrl,$attribution]);

    return ['image'=>$imageUrl,'url'=>$imageUrl,'name'=>$alt !== '' ? $alt : ('Unsplash '.$photoId),'attribution'=>$attribution,'provider'=>'Unsplash','photographer'=>$photographer,'photographer_url'=>$photographerUrl,'photo_url'=>$photoUrl];
}

function destinationApprovedPexelsUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    return strtolower((string)parse_url(
        $url,
        PHP_URL_SCHEME
    )) === 'https'
        && strtolower((string)parse_url(
            $url,
            PHP_URL_HOST
        )) === 'images.pexels.com';
}

function destinationSafeImageName(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'Pexels Destination Photo';
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

    $value = preg_replace(
        '/[^A-Za-z0-9 _-]+/',
        '',
        $value
    );

    $value = preg_replace(
        '/\s+/',
        ' ',
        (string)$value
    );

    $value = trim((string)$value, " .-_");

    return $value !== ''
        ? substr($value, 0, 90)
        : 'Pexels Destination Photo';
}


function optimizeDestinationPexelsImage(
    string $sourceFile,
    string $destinationFile
): void {
    $targetBytes = 100 * 1024;

    $imageInfo =
        @getimagesize($sourceFile);

    if (!$imageInfo) {
        throw new RuntimeException(
            'The downloaded Pexels file is not a valid image.'
        );
    }

    $mime =
        strtolower(
            (string)(
                $imageInfo['mime']
                ?? ''
            )
        );

    if ($mime === 'image/jpeg') {
        $sourceImage =
            @imagecreatefromjpeg($sourceFile);

    } elseif ($mime === 'image/png') {
        $sourceImage =
            @imagecreatefrompng($sourceFile);

    } elseif (
        $mime === 'image/webp'
        && function_exists('imagecreatefromwebp')
    ) {
        $sourceImage =
            @imagecreatefromwebp($sourceFile);

    } else {
        $sourceImage = false;
    }

    if (
        !$sourceImage
        || !function_exists('imagejpeg')
    ) {
        throw new RuntimeException(
            'PHP GD is required to resize imported photos below 100 KB.'
        );
    }

    $originalWidth =
        imagesx($sourceImage);

    $originalHeight =
        imagesy($sourceImage);

    /*
    | Start with a web-friendly maximum width.
    */
    $workingWidth =
        min(1600, $originalWidth);

    $workingHeight =
        max(
            1,
            (int)round(
                $originalHeight
                * ($workingWidth / $originalWidth)
            )
        );

    if (
        $workingWidth !== $originalWidth
        || $workingHeight !== $originalHeight
    ) {
        $resized =
            imagecreatetruecolor(
                $workingWidth,
                $workingHeight
            );

        imagecopyresampled(
            $resized,
            $sourceImage,
            0,
            0,
            0,
            0,
            $workingWidth,
            $workingHeight,
            $originalWidth,
            $originalHeight
        );

        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    $qualityLevels = [
        82, 76, 70, 64, 58,
        52, 46, 40, 34, 28,
        24, 20, 16
    ];

    $saved = false;

    /*
    | Try several quality levels at the current dimensions.
    */
    foreach ($qualityLevels as $quality) {
        imagejpeg(
            $sourceImage,
            $destinationFile,
            $quality
        );

        clearstatcache(
            true,
            $destinationFile
        );

        if (
            is_file($destinationFile)
            && filesize($destinationFile) <= $targetBytes
        ) {
            $saved = true;
            break;
        }
    }

    /*
    | If quality compression is insufficient, progressively
    | reduce dimensions and retry until the file is <= 100 KB.
    */
    $attempt = 0;

    while (
        !$saved
        && $attempt < 12
    ) {
        $attempt++;

        $currentWidth =
            imagesx($sourceImage);

        $currentHeight =
            imagesy($sourceImage);

        if (
            $currentWidth <= 360
            || $currentHeight <= 220
        ) {
            break;
        }

        $newWidth =
            max(
                360,
                (int)floor(
                    $currentWidth * 0.84
                )
            );

        $newHeight =
            max(
                220,
                (int)floor(
                    $currentHeight * 0.84
                )
            );

        $smaller =
            imagecreatetruecolor(
                $newWidth,
                $newHeight
            );

        imagecopyresampled(
            $smaller,
            $sourceImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $currentWidth,
            $currentHeight
        );

        imagedestroy($sourceImage);
        $sourceImage = $smaller;

        foreach ([48, 40, 32, 26, 20, 16] as $quality) {
            imagejpeg(
                $sourceImage,
                $destinationFile,
                $quality
            );

            clearstatcache(
                true,
                $destinationFile
            );

            if (
                is_file($destinationFile)
                && filesize($destinationFile) <= $targetBytes
            ) {
                $saved = true;
                break;
            }
        }
    }

    imagedestroy($sourceImage);

    clearstatcache(
        true,
        $destinationFile
    );

    if (
        !$saved
        || !is_file($destinationFile)
        || filesize($destinationFile) < 500
        || filesize($destinationFile) > $targetBytes
    ) {
        @unlink($destinationFile);

        throw new RuntimeException(
            'Unable to resize the selected Pexels image to 100 KB or less.'
        );
    }
}

function importDestinationPexelsPhoto(
    PDO $pdo,
    array $photo
): array {
    $photoId = trim((string)($photo['id'] ?? ''));
    $imageUrl = trim((string)($photo['download'] ?? ''));
    $photoUrl = trim((string)($photo['photo_url'] ?? ''));
    $photographer = trim((string)(
        $photo['photographer'] ?? ''
    ));
    $photographerUrl = trim((string)(
        $photo['photographer_url'] ?? ''
    ));
    $alt = trim((string)($photo['alt'] ?? ''));

    if (
        $photoId === ''
        || $imageUrl === ''
        || $photoUrl === ''
        || $photographer === ''
    ) {
        throw new RuntimeException(
            'Incomplete Pexels image information.'
        );
    }

    if (!destinationApprovedPexelsUrl($imageUrl)) {
        throw new RuntimeException(
            'Invalid Pexels image URL.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL is required to import Pexels photos.'
        );
    }

    $directory =
        __DIR__ . '/uploads/destinations/';

    if (
        !is_dir($directory)
        && !mkdir($directory, 0755, true)
    ) {
        throw new RuntimeException(
            'Unable to create destination image directory.'
        );
    }

    $fileName =
        destinationSafeImageName(
            $alt !== ''
                ? $alt
                : 'Pexels Destination ' . $photoId
        )
        . ' - Pexels '
        . $photoId
        . '.jpg';

    $destination = $directory . $fileName;
    $relativePath =
        'uploads/destinations/' . $fileName;

    clearstatcache(
        true,
        $destination
    );

    $mustImport =
        !is_file($destination)
        || filesize($destination) > (100 * 1024);

    if ($mustImport) {
        if (is_file($destination)) {
            @unlink($destination);
        }

        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'travscope_destination_'
        );

        if ($temporaryFile === false) {
            throw new RuntimeException(
                'Unable to create temporary image file.'
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
            optimizeDestinationPexelsImage(
                $temporaryFile,
                $destination
            );
        } finally {
            @unlink(
                $temporaryFile
            );
        }
    }

    ensureDestinationAttributionTable($pdo);

    $attribution =
        'Photo by '
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
        $photographerUrl !== ''
            ? $photographerUrl
            : null,
        $photoUrl,
        $imageUrl,
        $attribution
    ]);

    return [
        'image' => $relativePath,
        'url' => destinationImageUrl($relativePath),
        'name' => $fileName,
        'attribution' => $attribution,
        'size_kb' =>
            is_file($destination)
                ? round(
                    filesize($destination) / 1024,
                    1
                )
                : null
    ];
}


/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/

$success = '';
$error = '';

if (!empty($_SESSION['destination_success'])) {

    $success =
        (string)$_SESSION['destination_success'];

    unset($_SESSION['destination_success']);
}

if (!empty($_SESSION['destination_error'])) {

    $error =
        (string)$_SESSION['destination_error'];

    unset($_SESSION['destination_error']);
}

// Read valid filter choices before actions so redirects and editor links retain
// the same local directory context. No caller-provided return URL is accepted.
$countries = [];
$continents = [];
try {
    $countries = $pdo->query("SELECT DISTINCT TRIM(country) FROM destinations WHERE country IS NOT NULL AND TRIM(country) != '' ORDER BY TRIM(country)")->fetchAll(PDO::FETCH_COLUMN);
    $continents = $pdo->query("SELECT DISTINCT TRIM(continent) FROM destinations WHERE continent IS NOT NULL AND TRIM(continent) != '' ORDER BY TRIM(continent)")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Destination filter choices error: ' . $e->getMessage());
}
$previousFilters = is_array($_SESSION['destination_filter_context'] ?? null) ? $_SESSION['destination_filter_context'] : [];
$countries = destinationFilterChoices($countries, $previousFilters['country'] ?? '');
$continents = destinationFilterChoices($continents, $previousFilters['continent'] ?? '', 100);
$filterInput = $_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['list_filters'] ?? null)
    ? $_POST['list_filters'] : $_GET;
$destinationListFilters = destinationListFilters($filterInput, $countries, $continents);
$destinationListHref = destinationListUrl($destinationListFilters);
$_SESSION['destination_filter_context'] = ['country' => $destinationListFilters['country'], 'continent' => $destinationListFilters['continent']];

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $postedToken === '' ||
        !hash_equals(
            $_SESSION['destination_csrf'] ?? '',
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

        /*
        |--------------------------------------------------------------------------
        | AJAX: PEXELS SEARCH / IMPORT
        |--------------------------------------------------------------------------
        */

        if (
            $action === 'destination_pexels_search'
            || $action === 'destination_pexels_import'
        ) {
            header('Content-Type: application/json; charset=utf-8');

            try {
                if ($action === 'destination_pexels_search') {
                    $query = trim((string)(
                        $_POST['query'] ?? ''
                    ));

                    $page = max(
                        1,
                        (int)($_POST['page'] ?? 1)
                    );

                    echo json_encode([
                        'success' => true,
                        'data' => destinationCombinedPhotoSearch(
                            $query,
                            $page
                        )
                    ]);
                } else {
                    $photoJson = (string)(
                        $_POST['photo'] ?? ''
                    );

                    $photo = json_decode(
                        $photoJson,
                        true
                    );

                    if (!is_array($photo)) {
                        throw new RuntimeException(
                            'Invalid selected photo.'
                        );
                    }

                    $provider = strtolower(trim((string)($photo['provider'] ?? 'pexels')));
                    $saved = $provider === 'unsplash'
                        ? importDestinationUnsplashPhoto($pdo, $photo)
                        : importDestinationPexelsPhoto($pdo, $photo);

                    echo json_encode([
                        'success' => true,
                        'data' => $saved
                    ]);
                }

            } catch (Throwable $e) {
                http_response_code(400);

                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | CREATE / UPDATE
        |--------------------------------------------------------------------------
        */

        if (
            $action === 'create' ||
            $action === 'update'
        ) {

            $destinationId =
                (int)(
                    $_POST['destination_id']
                    ?? 0
                );

            if ($action === 'create') {
                $destinationId = 0;
            }

            $name =
                trim(
                    (string)(
                        $_POST['name']
                        ?? ''
                    )
                );

            $country =
                trim(
                    (string)(
                        $_POST['country']
                        ?? ''
                    )
                );

            $continent =
                trim(
                    (string)(
                        $_POST['continent']
                        ?? ''
                    )
                );

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

            $featured =
                !empty($_POST['featured'])
                    ? 1
                    : 0;

            $status =
                strtolower(
                    trim(
                        (string)(
                            $_POST['status']
                            ?? 'active'
                        )
                    )
                );

            $sortOrder =
                max(
                    0,
                    (int)(
                        $_POST['sort_order']
                        ?? 0
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            if ($name === '') {

                $error =
                    'Destination name is required.';

            } elseif (mb_strlen($name) > 150) {

                $error =
                    'Destination name cannot exceed 150 characters.';

            } elseif ($country === '') {

                $error =
                    'Country is required.';

            } elseif (mb_strlen($country) > 150) {

                $error =
                    'Country cannot exceed 150 characters.';

            } elseif (
                $continent !== '' &&
                mb_strlen($continent) > 100
            ) {

                $error =
                    'Continent cannot exceed 100 characters.';

            } elseif (
                $shortDescription !== '' &&
                mb_strlen($shortDescription) > 500
            ) {

                $error =
                    'Short description cannot exceed 500 characters.';

            } else {

                if (
                    !in_array(
                        $status,
                        [
                            'active',
                            'inactive'
                        ],
                        true
                    )
                ) {
                    $status = 'active';
                }

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | CREATE
                    |--------------------------------------------------------------------------
                    */

                    if ($action === 'create') {

                        $slug =
                            uniqueDestinationSlug(
                                $pdo,
                                makeDestinationSlug($name)
                            );

                        $image =
                            validDestinationMediaPath(
                                (string)(
                                    $_POST['image_library_path']
                                    ?? ''
                                )
                            );

                        $bannerImage =
                            validDestinationMediaPath(
                                (string)(
                                    $_POST['banner_library_path']
                                    ?? ''
                                )
                            );

                        try {

                            if (
                                isset($_FILES['image'])
                                && (
                                    $_FILES['image']['error']
                                    ?? UPLOAD_ERR_NO_FILE
                                ) !== UPLOAD_ERR_NO_FILE
                            ) {
                                $uploadedCardImage =
                                    uploadDestinationImage(
                                        $_FILES['image'],
                                        'destination'
                                    );

                                if ($uploadedCardImage !== null) {
                                    $image =
                                        $uploadedCardImage;
                                }
                            }

                            if (
                                isset($_FILES['banner_image'])
                                && (
                                    $_FILES['banner_image']['error']
                                    ?? UPLOAD_ERR_NO_FILE
                                ) !== UPLOAD_ERR_NO_FILE
                            ) {
                                $uploadedBannerImage =
                                    uploadDestinationImage(
                                        $_FILES['banner_image'],
                                        'banner'
                                    );

                                if ($uploadedBannerImage !== null) {
                                    $bannerImage =
                                        $uploadedBannerImage;
                                }
                            }

                            $stmt = $pdo->prepare("
                                INSERT INTO destinations
                                (
                                    name,
                                    slug,
                                    country,
                                    continent,
                                    short_description,
                                    description,
                                    image,
                                    banner_image,
                                    featured,
                                    status,
                                    sort_order
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
                                    ?
                                )
                            ");

                            $stmt->execute([
                                $name,
                                $slug,
                                $country,
                                $continent !== ''
                                    ? $continent
                                    : null,
                                $shortDescription !== ''
                                    ? $shortDescription
                                    : null,
                                $description !== ''
                                    ? $description
                                    : null,
                                $image,
                                $bannerImage,
                                $featured,
                                $status,
                                $sortOrder
                            ]);

                        } catch (Throwable $e) {

                            if ($image) {
                                deleteDestinationImage(
                                    $image
                                );
                            }

                            if ($bannerImage) {
                                deleteDestinationImage(
                                    $bannerImage
                                );
                            }

                            throw $e;
                        }

                        $_SESSION['destination_success'] =
                            'Destination created successfully.';

                        redirect(
                            BASE_URL . $destinationListHref
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE
                    |--------------------------------------------------------------------------
                    */

                    if ($action === 'update') {

                        if ($destinationId <= 0) {
                            throw new RuntimeException(
                                'Invalid destination.'
                            );
                        }

                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                image,
                                banner_image
                            FROM destinations
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $destinationId
                        ]);

                        $existing =
                            $stmt->fetch();

                        if (!$existing) {
                            throw new RuntimeException(
                                'Destination not found.'
                            );
                        }

                        $slug =
                            uniqueDestinationSlug(
                                $pdo,
                                makeDestinationSlug($name),
                                $destinationId
                            );

                        $image =
                            $existing['image'];

                        $bannerImage =
                            $existing['banner_image'];

                        /*
                        |--------------------------------------------------------------------------
                        | REMOVE CURRENT IMAGE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !empty(
                                $_POST['remove_image']
                            )
                        ) {

                            deleteDestinationImage(
                                $image
                            );

                            $image = null;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | REMOVE BANNER
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !empty(
                                $_POST['remove_banner_image']
                            )
                        ) {

                            deleteDestinationImage(
                                $bannerImage
                            );

                            $bannerImage = null;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | SELECT FROM WEB GALLERY
                        |--------------------------------------------------------------------------
                        */

                        $selectedCardImage =
                            validDestinationMediaPath(
                                (string)(
                                    $_POST['image_library_path']
                                    ?? ''
                                )
                            );

                        if ($selectedCardImage !== null) {
                            if (
                                $image
                                && $image !== $selectedCardImage
                            ) {
                                deleteDestinationImage($image);
                            }

                            $image = $selectedCardImage;
                        }

                        $selectedBannerImage =
                            validDestinationMediaPath(
                                (string)(
                                    $_POST['banner_library_path']
                                    ?? ''
                                )
                            );

                        if ($selectedBannerImage !== null) {
                            if (
                                $bannerImage
                                && $bannerImage !== $selectedBannerImage
                            ) {
                                deleteDestinationImage(
                                    $bannerImage
                                );
                            }

                            $bannerImage =
                                $selectedBannerImage;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | REPLACE NORMAL IMAGE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            isset($_FILES['image']) &&
                            $_FILES['image']['error']
                                !== UPLOAD_ERR_NO_FILE
                        ) {

                            $newImage =
                                uploadDestinationImage(
                                    $_FILES['image'],
                                    'destination'
                                );

                            if ($image) {
                                deleteDestinationImage(
                                    $image
                                );
                            }

                            $image = $newImage;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | REPLACE BANNER
                        |--------------------------------------------------------------------------
                        */

                        if (
                            isset($_FILES['banner_image']) &&
                            $_FILES['banner_image']['error']
                                !== UPLOAD_ERR_NO_FILE
                        ) {

                            $newBanner =
                                uploadDestinationImage(
                                    $_FILES['banner_image'],
                                    'banner'
                                );

                            if ($bannerImage) {
                                deleteDestinationImage(
                                    $bannerImage
                                );
                            }

                            $bannerImage = $newBanner;
                        }

                        $stmt = $pdo->prepare("
                            UPDATE destinations

                            SET
                                name = ?,
                                slug = ?,
                                country = ?,
                                continent = ?,
                                short_description = ?,
                                description = ?,
                                image = ?,
                                banner_image = ?,
                                featured = ?,
                                status = ?,
                                sort_order = ?

                            WHERE id = ?

                            LIMIT 1
                        ");

                        $stmt->execute([
                            $name,
                            $slug,
                            $country,
                            $continent !== ''
                                ? $continent
                                : null,
                            $shortDescription !== ''
                                ? $shortDescription
                                : null,
                            $description !== ''
                                ? $description
                                : null,
                            $image,
                            $bannerImage,
                            $featured,
                            $status,
                            $sortOrder,
                            $destinationId
                        ]);

                        $_SESSION['destination_success'] =
                            'Destination updated successfully.';

                        redirect(
                            BASE_URL . destinationListUrl($destinationListFilters, ['edit' => $destinationId], 'destination-form')
                        );
                    }

                } catch (RuntimeException $e) {

                    $error =
                        $e->getMessage();

                } catch (Throwable $e) {

                    error_log(
                        'Destination save error: ' .
                        $e->getMessage()
                    );

                    $error =
                        'Unable to save destination. Please check your data and try again.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TOGGLE FEATURED
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'toggle_featured') {

            $destinationId =
                (int)(
                    $_POST['destination_id']
                    ?? 0
                );

            if ($destinationId > 0) {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE destinations

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
                        $destinationId
                    ]);

                    $_SESSION['destination_success'] =
                        'Featured setting updated.';

                    redirect(
                        BASE_URL . $destinationListHref
                    );

                } catch (Throwable $e) {

                    $error =
                        'Unable to update featured setting.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CHANGE STATUS
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'change_status') {

            $destinationId =
                (int)(
                    $_POST['destination_id']
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
                $destinationId > 0 &&
                in_array(
                    $newStatus,
                    [
                        'active',
                        'inactive'
                    ],
                    true
                )
            ) {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE destinations

                        SET status = ?

                        WHERE id = ?

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $newStatus,
                        $destinationId
                    ]);

                    $_SESSION['destination_success'] =
                        'Destination status updated.';

                    redirect(
                        BASE_URL . $destinationListHref
                    );

                } catch (Throwable $e) {

                    $error =
                        'Unable to update destination status.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $destinationId =
                (int)(
                    $_POST['destination_id']
                    ?? 0
                );

            if ($destinationId <= 0) {

                $error =
                    'Invalid destination.';

            } else {

                try {

                    /*
                    | IMPORTANT:
                    | Do not delete a destination that has packages.
                    */

                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM packages
                        WHERE destination_id = ?
                    ");

                    $stmt->execute([
                        $destinationId
                    ]);

                    $packageCount =
                        (int)$stmt->fetchColumn();

                    if ($packageCount > 0) {

                        throw new RuntimeException(
                            'This destination has ' .
                            $packageCount .
                            ' package(s). Move or remove those packages first, or set this destination to inactive.'
                        );
                    }

                    // Keep linked child IDs intact. All statuses count, so an
                    // inactive location or hotel cannot be orphaned by deletion.
                    foreach (['destination_locations' => 'saved location(s)', 'hotels' => 'hotel(s)'] as $dependencyTable => $dependencyLabel) {
                        if (function_exists('tsLocationTableExists') && tsLocationTableExists($pdo, $dependencyTable)
                            && function_exists('tsLocationColumnExists') && tsLocationColumnExists($pdo, $dependencyTable, 'destination_id')) {
                            $linked = $pdo->prepare('SELECT COUNT(*) FROM ' . $dependencyTable . ' WHERE destination_id = ?');
                            $linked->execute([$destinationId]);
                            $dependencyCount = (int)$linked->fetchColumn();
                            if ($dependencyCount > 0) {
                                throw new RuntimeException('This destination has ' . $dependencyCount . ' ' . $dependencyLabel . '. Set this destination to inactive to preserve linked records.');
                            }
                        }
                    }

                    $stmt = $pdo->prepare("
                        SELECT
                            image,
                            banner_image
                        FROM destinations
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $destinationId
                    ]);

                    $destination =
                        $stmt->fetch();

                    if (!$destination) {
                        throw new RuntimeException(
                            'Destination not found.'
                        );
                    }

                    $stmt = $pdo->prepare("
                        DELETE FROM destinations
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $destinationId
                    ]);

                    deleteDestinationImage(
                        $destination['image']
                        ?? ''
                    );

                    deleteDestinationImage(
                        $destination['banner_image']
                        ?? ''
                    );

                    $_SESSION['destination_success'] =
                        'Destination deleted successfully.';

                    redirect(
                        BASE_URL . $destinationListHref
                    );

                } catch (RuntimeException $e) {

                    $error =
                        $e->getMessage();

                } catch (Throwable $e) {

                    error_log(
                        'Destination delete error: ' .
                        $e->getMessage()
                    );

                    $error =
                        'Unable to delete destination.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| EDIT DESTINATION
|--------------------------------------------------------------------------
*/

$editDestination = null;

$editId =
    (int)(
        $_GET['edit']
        ?? 0
    );

if ($editId <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $editId = is_scalar($_POST['destination_id'] ?? null) ? (int)$_POST['destination_id'] : 0;
}

if ($editId > 0) {

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM destinations
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $editId
        ]);

        $editDestination =
            $stmt->fetch();

        if (!$editDestination) {
            $error =
                'Destination not found.';
        }

    } catch (Throwable $e) {

        $error =
            'Unable to load destination.';
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH / FILTER
|--------------------------------------------------------------------------
*/

$search = $destinationListFilters['q'];
$statusFilter = $destinationListFilters['status'];
$continentFilter = $destinationListFilters['continent'];
$countryFilter = $destinationListFilters['country'];
$featuredFilter = $destinationListFilters['featured'];

$destinationLocationsAvailable = function_exists('tsLocationTableExists')
    && tsLocationTableExists($pdo, 'destination_locations');
$locationCountSql = $destinationLocationsAvailable
    ? '(SELECT COUNT(*) FROM destination_locations dl WHERE dl.destination_id = d.id)'
    : 'NULL';
$destinationHotelsAvailable = function_exists('tsLocationTableExists') && tsLocationTableExists($pdo, 'hotels')
    && function_exists('tsLocationColumnExists') && tsLocationColumnExists($pdo, 'hotels', 'destination_id');
$hotelCountSql = $destinationHotelsAvailable
    ? '(SELECT COUNT(*) FROM hotels h WHERE h.destination_id = d.id)' : '0';

/*
|--------------------------------------------------------------------------
| FETCH DESTINATIONS
|--------------------------------------------------------------------------
*/

$destinations = [];

try {

    $queryFilters = destinationListWhere($destinationListFilters);
    $where = $queryFilters['where'];
    $params = $queryFilters['params'];

    $sql = "
        SELECT

            d.*,

            (
                SELECT COUNT(*)
                FROM packages p
                WHERE p.destination_id = d.id
            ) AS package_count,

            (
                SELECT COUNT(*)
                FROM packages p
                WHERE
                    p.destination_id = d.id
                    AND p.status = 'active'
            ) AS active_package_count,

            $locationCountSql AS location_count,
            $hotelCountSql AS hotel_count

        FROM destinations d
    ";

    if ($where) {

        $sql .=
            ' WHERE ' .
            implode(
                ' AND ',
                $where
            );
    }

    $sql .= "
        ORDER BY
            d.sort_order ASC,
            d.featured DESC,
            d.id DESC
    ";

    $stmt =
        $pdo->prepare($sql);

    $stmt->execute($params);

    $destinations =
        $stmt->fetchAll();

} catch (Throwable $e) {

    error_log(
        'Destination list error: ' .
        $e->getMessage()
    );

    $error =
        'Unable to load destinations.';
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/

$stats = [
    'total'    => 0,
    'active'   => 0,
    'inactive' => 0,
    'featured' => 0
];

try {

    $stmt = $pdo->query("
        SELECT

            COUNT(*) AS total,

            SUM(
                status = 'active'
            ) AS active,

            SUM(
                status = 'inactive'
            ) AS inactive,

            SUM(
                featured = 1
            ) AS featured

        FROM destinations
    ");

    $row =
        $stmt->fetch();

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
        'Destination stats error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| FORM
|--------------------------------------------------------------------------
*/

$form =
    $editDestination
    ?: [
        'id'                => 0,
        'name'              => '',
        'country'           => '',
        'continent'         => '',
        'short_description' => '',
        'description'       => '',
        'image'             => '',
        'banner_image'      => '',
        'featured'          => 0,
        'status'            => 'active',
        'sort_order'        => 0
    ];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== ''
    && in_array((string)($_POST['action'] ?? ''), ['create', 'update'], true)) {
    $form = destinationFormValues($form, $_POST);
    foreach (['image_library_path', 'banner_library_path'] as $key) {
        $form[$key] = validDestinationMediaPath($form[$key]) ?? '';
    }
}

$destinationMediaLibrary =
    destinationMediaLibrary();

/*
|--------------------------------------------------------------------------
| DESTINATION EDITOR MODE
|--------------------------------------------------------------------------
| Keep Destination Library as the home view.
| Show the Add/Edit destination form only when explicitly requested.
*/
$destinationEditorMode =
    isset($_GET['new'])
    || !empty($editDestination)
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
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
Destinations | TRAVSCOPE Admin
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
    --red:#d94355;
    --orange:#df8617;
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
    background:white;
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
    background:linear-gradient(
        135deg,
        var(--blue),
        var(--cyan)
    );
}

.logo span{
    color:var(--blue);
}

.admin-user{
    display:flex;
    align-items:center;
    gap:10px;
}

.avatar{
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
    font-size:14px;
    line-height:1.35;
}

.admin-user small{
    display:block;
    margin-top:2px;
    color:#7f8c9c;
    font-size:12px;
    line-height:1.35;
}

/* LAYOUT */

.layout{
    min-height:calc(100vh - 70px);
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
}

/* SIDEBAR */

.sidebar{
    padding:20px 13px;
    background:var(--navy);
    color:white;
}

.menu-title{
    padding:0 12px;
    margin:16px 0 8px;
    color:#8fa4b9;
    font-size:11px;
    line-height:1.3;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.8px;
}

.sidebar a{
    min-height:50px;
    margin-bottom:6px;
    padding:0 14px;
    border-radius:10px;
    display:flex;
    align-items:center;
    gap:11px;
    color:#c7d4e1;
    font-size:14px;
    line-height:1.35;
    font-weight:700;
    white-space:normal;
    transition:background .2s ease,color .2s ease,transform .2s ease;
}

.sidebar a:hover,
.sidebar a.active{
    color:white;
    background:rgba(255,255,255,.12);
}

.sidebar a:hover{
    transform:translateX(2px);
}

.sidebar a.active{
    background:linear-gradient(
        135deg,
        var(--blue),
        #0866dd
    );
}

.sidebar i{
    width:22px;
    flex:0 0 22px;
    text-align:center;
    font-size:15px;
}

/* MAIN */

.main{
    min-width:0;
    padding:27px;
}

.page-head{
    margin-bottom:22px;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
}

.page-head h1{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:25px;
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
    background:linear-gradient(
        135deg,
        var(--blue),
        var(--blue2)
    );
    color:white;
    font-size:8px;
    font-weight:800;
}

/* ALERT */

.alert{
    margin-bottom:17px;
    padding:13px 15px;
    border-radius:10px;
    display:flex;
    align-items:center;
    gap:8px;
    font-size:9px;
}

.alert-success{
    background:#eaf9f3;
    border:1px solid #bce7d5;
    color:#08734f;
}

.alert-error{
    background:#fff0f2;
    border:1px solid #f0c5cc;
    color:#b43748;
}

/* STATS */

.stats{
    margin-bottom:22px;
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:13px;
}

.stat{
    padding:17px;
    border:1px solid var(--border);
    border-radius:13px;
    background:white;
    box-shadow:var(--shadow);
}

.stat-icon{
    width:36px;
    height:36px;
    border-radius:10px;
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
    font-size:23px;
}

.stat span{
    display:block;
    margin-top:2px;
    color:#8d98a7;
    font-size:8px;
}

/* CARD */

.card{
    margin-bottom:20px;
    border:1px solid var(--border);
    border-radius:15px;
    overflow:hidden;
    background:white;
    box-shadow:var(--shadow);
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
    margin-top:3px;
    color:#929dab;
    font-size:8px;
}

.card-body{
    padding:19px;
}

/* FILTER */

.filters{
    display:grid;
    grid-template-columns:2fr 1fr 1fr auto;
    gap:9px;
}

.control,
.form-control{
    width:100%;
    height:42px;
    padding:0 11px;
    border:1px solid #dfe6ed;
    border-radius:8px;
    outline:none;
    background:white;
    color:#38465a;
    font-size:9px;
}

.control:focus,
.form-control:focus{
    border-color:#8abaff;
    box-shadow:
        0 0 0 3px rgba(11,116,255,.06);
}

.filter-btn{
    height:42px;
    padding:0 14px;
    border:0;
    border-radius:8px;
    cursor:pointer;
    background:var(--navy);
    color:white;
    font-size:8px;
    font-weight:800;
}

/* DESTINATION MANAGEMENT TABLE */

.table-wrap{
    width:100%;
    overflow-x:auto;
}

.destination-table{
    width:100%;
    min-width:1080px;
    border-collapse:collapse;
}

.destination-table thead{
    background:#f8fafc;
}

.destination-table th{
    padding:11px 12px;
    border-bottom:1px solid var(--border);
    color:#8793a3;
    font-size:7px;
    font-weight:800;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:.45px;
    white-space:nowrap;
}

.destination-table td{
    padding:12px;
    border-bottom:1px solid #edf1f5;
    color:#465469;
    font-size:8px;
    vertical-align:middle;
}

.destination-table tbody tr:hover{
    background:#fbfdff;
}

.destination-cell{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:210px;
}

.destination-thumb{
    width:58px;
    height:42px;
    flex:none;
    border-radius:8px;
    overflow:hidden;
    display:grid;
    place-items:center;
    background:#edf5ff;
    color:var(--blue);
    font-size:15px;
}

.destination-thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.destination-name{
    max-width:170px;
    overflow:hidden;
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:9px;
    font-weight:800;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.subtext{
    margin-top:3px;
    color:#929dab;
    font-size:7px;
}

.badge{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:5px 8px;
    border-radius:20px;
    font-size:7px;
    font-weight:800;
    white-space:nowrap;
}

.badge-active{
    background:#e9f9f2;
    color:var(--green);
}

.badge-inactive{
    background:#fff0f2;
    color:var(--red);
}

.badge-featured{
    background:#fff6e7;
    color:#c27615;
}

.badge-standard{
    background:#f1f3f5;
    color:#758292;
}

.package-count{
    color:var(--navy);
    font-size:9px;
    font-weight:800;
}

.table-actions{
    display:flex;
    align-items:center;
    gap:5px;
    flex-wrap:wrap;
    min-width:225px;
}

.table-actions form{
    margin:0;
}

.action-btn{
    min-height:31px;
    padding:0 8px;
    border:1px solid var(--border);
    border-radius:6px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    cursor:pointer;
    background:white;
    color:#657387;
    font-size:7px;
    font-weight:800;
    white-space:nowrap;
}

.action-btn:hover{
    color:var(--blue);
    border-color:#b9d6fb;
    background:#f7fbff;
}

.action-btn.feature{
    color:#c27615;
    background:#fffaf1;
}

.action-btn.status{
    color:var(--blue);
    background:#f3f8ff;
}

.action-btn.danger{
    color:var(--red);
    background:#fff7f8;
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

textarea.form-control{
    height:110px;
    padding:11px 12px;
    resize:vertical;
    line-height:1.6;
}

textarea.large{
    height:160px;
}

.help{
    margin-top:5px;
    color:#99a4b2;
    font-size:7px;
}

.image-preview{
    width:220px;
    height:140px;
    margin-top:12px;
    border:1px solid var(--border);
    border-radius:11px;
    overflow:hidden;
    display:grid;
    place-items:center;
    background:#f1f5f9;
}

.image-preview.banner{
    width:100%;
    max-width:520px;
    height:210px;
}

.image-preview img{
    width:100%;
    height:100%;
    display:block;
    object-fit:cover;
}

.remove-check{
    margin-top:8px;
    display:flex !important;
    align-items:center;
    gap:6px;
    cursor:pointer;
}

.remove-check input{
    accent-color:var(--red);
}

.option-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.option-card{
    padding:11px 13px;
    border:1px solid var(--border);
    border-radius:9px;
    display:flex !important;
    align-items:center;
    gap:7px;
    cursor:pointer;
}

.option-card input{
    accent-color:var(--blue);
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
    background:linear-gradient(
        135deg,
        var(--blue),
        var(--blue2)
    );
    color:white;
}

.btn-light{
    border:1px solid var(--border);
    background:white;
    color:#627084;
}

.empty{
    padding:50px 20px;
    text-align:center;
}

.empty i{
    margin-bottom:12px;
    color:#aeb9c6;
    font-size:30px;
}

.empty strong{
    display:block;
    color:var(--navy);
    font-size:13px;
}

.empty p{
    margin-top:5px;
    color:#8b97a6;
    font-size:8px;
}

/* RESPONSIVE */

@media(max-width:1100px){

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

    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .filters{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:600px){

    .header{
        padding:0 13px;
    }

    .admin-user > div:last-child{
        display:none;
    }

    .main{
        padding:13px;
    }

    .page-head{
        align-items:flex-start;
        flex-direction:column;
    }


    .filters{
        grid-template-columns:1fr;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }
}


/* DESTINATION IMAGE PICKER */

.destination-image-actions{
    margin-top:9px;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.destination-image-action{
    min-height:39px;
    padding:0 12px;
    border:1px solid #cfe0f4;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    background:#f5f9ff;
    color:var(--blue);
    cursor:pointer;
    font-size:8px;
    font-weight:800;
}

.destination-image-action.pexels{
    border-color:#bfe7da;
    background:#f1fbf7;
    color:#087952;
}

.destination-selected-panel{
    margin-top:10px;
    padding:11px;
    border:1px solid #cfe0f4;
    border-radius:10px;
    background:#f7fbff;
}

.destination-selected-panel[hidden]{
    display:none;
}

.destination-selected-title{
    margin-bottom:8px;
    color:var(--navy);
    font-size:8px;
    font-weight:800;
}

.destination-selected-item{
    display:flex;
    align-items:center;
    gap:10px;
}

.destination-selected-item img{
    width:92px;
    height:62px;
    flex:none;
    border-radius:8px;
    object-fit:cover;
}

.destination-selected-item span{
    min-width:0;
    color:#657387;
    font-size:8px;
    overflow-wrap:anywhere;
}

.media-modal{
    position:fixed;
    inset:0;
    z-index:3000;
    display:none;
    padding:25px;
    background:rgba(4,17,31,.82);
    overflow-y:auto;
}

.media-modal.open{
    display:block;
}

.media-dialog{
    width:min(1180px,100%);
    margin:0 auto;
    border-radius:16px;
    overflow:hidden;
    background:white;
    box-shadow:0 25px 80px rgba(0,0,0,.25);
}

.media-head{
    min-height:67px;
    padding:0 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.media-head h3{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:15px;
}

.media-close{
    width:38px;
    height:38px;
    border:0;
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#f0f4f8;
    color:var(--navy);
    cursor:pointer;
}

.media-tabs{
    padding:13px 18px 0;
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.media-tab{
    min-height:37px;
    padding:0 12px;
    border:1px solid var(--border);
    border-radius:8px;
    background:white;
    color:#68778a;
    cursor:pointer;
    font-size:8px;
    font-weight:800;
}

.media-tab.active{
    border-color:var(--blue);
    background:#edf5ff;
    color:var(--blue);
}

.media-body{
    padding:18px;
}

.media-pane{
    display:none;
}

.media-pane.active{
    display:block;
}

.media-searchbar{
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin-bottom:15px;
}

.media-searchbar input{
    height:43px;
    padding:0 12px;
    border:1px solid var(--border);
    border-radius:8px;
    outline:none;
}

.media-searchbar button{
    min-height:43px;
    padding:0 15px;
    border:0;
    border-radius:8px;
    background:var(--blue);
    color:white;
    cursor:pointer;
    font-size:8px;
    font-weight:800;
}

.media-grid{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:12px;
}

.media-card{
    min-width:0;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
}

.media-card-image{
    width:100%;
    aspect-ratio:4/3;
    object-fit:cover;
    background:#edf2f7;
}

.media-card-body{
    padding:8px;
}

.media-name{
    min-height:30px;
    color:#536176;
    font-size:7px;
    line-height:1.4;
    overflow-wrap:anywhere;
}

.media-credit{
    margin-top:5px;
    color:#7f8b99;
    font-size:7px;
    line-height:1.4;
}

.media-select{
    width:100%;
    min-height:34px;
    margin-top:7px;
    border:0;
    border-radius:7px;
    background:#edf5ff;
    color:var(--blue);
    cursor:pointer;
    font-size:7px;
    font-weight:800;
}

.media-status{
    padding:25px;
    text-align:center;
    color:#7c8999;
    font-size:9px;
}

@media(max-width:1000px){
    .media-grid{
        grid-template-columns:repeat(4,minmax(0,1fr));
    }
}

@media(max-width:700px){
    .media-modal{
        padding:10px;
    }

    .media-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .media-searchbar{
        grid-template-columns:1fr;
    }
}



/* =========================================================
   PROFESSIONAL READABLE ADMIN TYPOGRAPHY
========================================================= */

body{
    font-size:15px;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
}

.page-head h1{
    font-size:28px;
}

.page-head p,
.card-head p{
    font-size:13px;
    line-height:1.55;
}

.card-head h2{
    font-size:17px;
}

.form-group label{
    margin-bottom:8px;
    font-size:13px;
    line-height:1.4;
}

.control,
.form-control{
    height:46px;
    font-size:14px;
}

textarea.form-control{
    height:125px;
    font-size:14px;
    line-height:1.65;
}

textarea.large{
    height:180px;
}

.help{
    margin-top:7px;
    font-size:12px;
    line-height:1.55;
}

.destination-image-action{
    min-height:43px;
    padding:0 14px;
    font-size:13px;
}

.destination-selected-title{
    font-size:13px;
}

.destination-selected-item span{
    font-size:13px;
    line-height:1.5;
}

.remove-check{
    font-size:13px !important;
}

.option-card{
    font-size:13px !important;
}

.btn,
.add-btn,
.filter-btn,
.action-btn{
    font-size:12px;
}

.destination-table th{
    font-size:11px;
}

.destination-table td{
    font-size:13px;
}

.destination-name{
    font-size:14px;
}

.subtext,
.badge,
.stat span{
    font-size:11px;
}

.package-count{
    font-size:13px;
}

.stat strong{
    font-size:25px;
}

.media-head h3{
    font-size:18px;
}

.media-tab{
    min-height:41px;
    font-size:13px;
}

.media-searchbar input{
    font-size:14px;
}

.media-searchbar button{
    font-size:13px;
}

.media-name{
    min-height:40px;
    font-size:12px;
    line-height:1.5;
}

.media-credit{
    font-size:11px;
    line-height:1.5;
}

.media-select{
    min-height:38px;
    font-size:12px;
}

.media-status{
    font-size:14px;
}

@media(max-width:700px){
    .main{
        padding:16px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .page-head h1{
        font-size:24px;
    }

    .image-preview,
    .image-preview.banner{
        width:100%;
        max-width:100%;
        height:190px;
    }
}


/* =========================================================
   DESTINATION IMAGE UPLOAD
   SAME FORMAT AS ADMIN-PACKAGES.PHP
========================================================= */

.native-destination-image-upload{
    width:100%;
    min-height:43px;
    margin-bottom:9px;
    padding:8px 10px;
    border:1px solid #dfe6ed;
    border-radius:8px;
    background:#fff;
    color:#465469;
    font-size:13px;
}

.main-image-gallery-launch,
.pexels-launch-button{
    min-height:42px;
    margin:0 7px 8px 0;
    padding:0 14px;
    border:0;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    cursor:pointer;
    font-size:12px;
    font-weight:800;
}

.main-image-gallery-launch{
    background:linear-gradient(135deg,var(--blue),var(--blue2));
    color:#fff;
}

.pexels-launch-button{
    border:1px solid #bdd8fa;
    background:#fff;
    color:var(--blue);
}

.chosen-image-panel{
    margin-top:10px;
    padding:11px 12px;
    border:1px solid #cfe0f7;
    border-radius:10px;
    background:#f7fbff;
}

.chosen-image-panel[hidden]{
    display:none;
}

.chosen-image-title{
    margin-bottom:9px;
    display:flex;
    align-items:center;
    gap:6px;
    color:#087952;
    font-size:12px;
    font-weight:800;
}

.chosen-image-items{
    display:flex;
    flex-wrap:wrap;
    gap:9px;
}

.chosen-image-thumb{
    width:150px;
    padding:7px;
    border:1px solid #dce5ef;
    border-radius:9px;
    background:#fff;
}

.chosen-image-thumb img{
    width:100%;
    height:90px;
    display:block;
    border-radius:7px;
    object-fit:cover;
}

.chosen-image-thumb span{
    display:block;
    margin-top:6px;
    overflow:hidden;
    color:#657387;
    font-size:11px;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.current-image{
    width:220px;
    height:140px;
    margin-top:11px;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:10px;
    background:#edf2f7;
}

.current-image.destination-banner-current{
    width:100%;
    max-width:460px;
    height:190px;
}

.current-image img{
    width:100%;
    height:100%;
    display:block;
    object-fit:cover;
}

.destination-remove-image{
    margin-top:8px !important;
    display:flex !important;
    align-items:center;
    gap:7px;
    font-size:13px !important;
    cursor:pointer;
}

/* WEB GALLERY MODAL */

.media-modal{
    position:fixed;
    inset:0;
    z-index:5000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:22px;
}

.media-modal.open{
    display:flex;
}

.media-modal-backdrop{
    position:absolute;
    inset:0;
    background:rgba(7,25,45,.68);
    backdrop-filter:blur(3px);
}

.media-modal-dialog{
    position:relative;
    width:min(1050px,96vw);
    max-height:90vh;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border-radius:16px;
    background:#fff;
    box-shadow:0 30px 90px rgba(0,0,0,.25);
}

.media-modal-head{
    padding:17px 19px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}

.media-modal-head h3{
    color:var(--navy);
    font-family:'Manrope',sans-serif;
    font-size:18px;
}

.media-modal-head p{
    margin-top:3px;
    color:var(--muted);
    font-size:12px;
}

.media-modal-close{
    width:37px;
    height:37px;
    border:1px solid var(--border);
    border-radius:9px;
    background:#fff;
    color:#68788b;
    cursor:pointer;
    font-size:15px;
}

.media-modal-search{
    margin:14px 16px 0;
    height:44px;
    padding:0 12px;
    border:1px solid #dbe4ed;
    border-radius:10px;
    display:flex;
    align-items:center;
    gap:9px;
    background:#f9fbfd;
}

.media-modal-search i{
    color:#8c99a9;
}

.media-modal-search input{
    flex:1;
    min-width:0;
    border:0;
    outline:0;
    background:transparent;
    font-size:13px;
}

.media-modal-grid{
    padding:14px 16px 18px;
    overflow:auto;
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(155px,1fr));
    gap:11px;
}

.media-popup-item{
    padding:7px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
}

.media-popup-item.hide{
    display:none;
}

.media-popup-item img{
    width:100%;
    height:115px;
    display:block;
    border-radius:7px;
    object-fit:cover;
    background:#eef3f8;
}

.media-popup-name{
    margin:7px 1px;
    overflow:hidden;
    color:#5d6c7f;
    font-size:10px;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.media-popup-select{
    width:100%;
    min-height:33px;
    border:1px solid #cfe0f7;
    border-radius:7px;
    background:#f4f8ff;
    color:var(--blue);
    cursor:pointer;
    font-size:10px;
    font-weight:800;
}

.media-empty{
    padding:35px;
    color:var(--muted);
    font-size:12px;
    text-align:center;
}

/* PEXELS MODAL */

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
}

.pexels-search-input input{
    width:100%;
    border:0;
    outline:0;
    font-size:14px;
}

.pexels-search-button{
    min-height:46px;
    padding:0 17px;
    border:0;
    border-radius:10px;
    background:var(--navy);
    color:#fff;
    cursor:pointer;
    font-size:12px;
    font-weight:800;
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
    overflow:auto;
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    align-items:start;
    gap:12px;
}

.pexels-photo-card{
    position:relative;
    z-index:1;
    min-width:0;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    box-shadow:0 7px 20px rgba(7,25,45,.07);
    cursor:pointer;
    pointer-events:auto;
    transition:.18s ease;
}

.pexels-photo-card:hover,
.pexels-photo-card:focus{
    border-color:#90bfff;
    box-shadow:0 10px 26px rgba(11,116,255,.16);
    outline:none;
    transform:translateY(-1px);
}

.pexels-photo-card.is-importing{
    pointer-events:none;
    opacity:.72;
}

.free-photo-provider-badge{position:absolute;top:9px;left:9px;z-index:3;padding:5px 8px;border-radius:999px;background:rgba(15,23,42,.84);color:#fff;font-size:10px;font-weight:900;letter-spacing:.04em;box-shadow:0 3px 10px rgba(15,23,42,.18)}
.pexels-photo-image{position:relative}
.pexels-photo-image img{
    width:100%;
    aspect-ratio:4/3;
    display:block;
    object-fit:cover;
}

.pexels-photo-credit{
    padding:7px 8px 0;
    color:#6d7989;
    font-size:9px;
    line-height:1.4;
}

.pexels-photo-body{
    padding:8px;
}

.pexels-photo-name{
    min-height:30px;
    overflow:hidden;
    color:#536176;
    font-size:10px;
    line-height:1.4;
}

.pexels-import-button{
    position:relative;
    z-index:5;
    width:100%;
    min-height:34px;
    margin-top:7px;
    border:0;
    border-radius:7px;
    background:var(--blue);
    color:#fff;
    cursor:pointer;
    font-size:10px;
    font-weight:800;
}

.pexels-empty{
    grid-column:1/-1;
    padding:55px 20px;
    color:var(--muted);
    text-align:center;
}

body.media-modal-open,
body.pexels-modal-open{
    overflow:hidden;
}

@media(max-width:850px){
    .pexels-results{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:600px){
    .pexels-results{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .pexels-search-row{
        grid-template-columns:1fr;
    }

    .current-image,
    .current-image.destination-banner-current{
        width:100%;
        max-width:100%;
        height:190px;
    }
}



/* =========================================================
   CLEAR SIDEBAR / ALL-DEVICE READABILITY OVERRIDES
========================================================= */

@media(max-width:1100px){
    .layout{
        grid-template-columns:220px minmax(0,1fr);
    }

    .sidebar{
        padding:16px 10px;
    }

    .sidebar a{
        min-height:48px;
        padding:0 12px;
        font-size:13px;
    }

    .sidebar i{
        font-size:14px;
    }

    .menu-title{
        font-size:10px;
    }
}

@media(max-width:820px){
    .layout{
        display:block;
    }

    .sidebar{
        width:100%;
        position:relative;
        padding:12px;
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
    }

    .menu-title{
        grid-column:1/-1;
        margin:8px 0 2px;
        padding:0 4px;
        font-size:11px;
    }

    .sidebar a{
        min-height:48px;
        margin:0;
        padding:0 12px;
        font-size:13px;
        border:1px solid rgba(255,255,255,.06);
    }

    .sidebar a:hover{
        transform:none;
    }

    .main{
        padding:18px;
    }
}

@media(max-width:520px){
    .header{
        height:auto;
        min-height:64px;
        padding:10px 12px;
        gap:10px;
    }

    .logo{
        font-size:16px;
    }

    .admin-user strong{
        font-size:12px;
    }

    .admin-user small{
        font-size:10px;
    }

    .sidebar{
        grid-template-columns:1fr;
        gap:7px;
    }

    .menu-title{
        font-size:10px;
    }

    .sidebar a{
        min-height:50px;
        font-size:14px;
        padding:0 14px;
    }

    .sidebar i{
        width:24px;
        flex-basis:24px;
        font-size:15px;
    }

    .main{
        padding:14px;
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

</style>


<style>
.geo-destination-panel{
    border:1px solid #dbe5f2;
    border-radius:18px;
    padding:17px;
    background:
        radial-gradient(circle at top right,rgba(90,115,255,.10),transparent 34%),
        linear-gradient(135deg,#f8fbff 0%,#f7f7ff 48%,#fffaf5 100%);
    box-shadow:0 12px 32px rgba(28,51,84,.07);
}
.geo-panel-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:14px;
}
.geo-panel-heading strong{
    display:block;
    font-size:14px;
    color:#17233b;
}
.geo-panel-help{
    margin-top:4px;
    color:#718096;
    font-size:11px;
    line-height:1.5;
}
.geo-live-pill{
    white-space:nowrap;
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 10px;
    border-radius:999px;
    background:#e9fff2;
    color:#167047;
    font-size:10px;
    font-weight:800;
}
.geo-selector-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:11px;
}
.geo-selector-box{
    position:relative;
    border-radius:15px;
    padding:14px;
    border:1px solid transparent;
}
.geo-selector-box label{
    display:block;
    margin:0 0 7px;
    font-size:11px;
    font-weight:800;
}
.geo-selector-box small{
    display:block;
    margin-top:7px;
    color:#68778a;
    font-size:9px;
    line-height:1.4;
}
.geo-country-box{
    background:linear-gradient(145deg,#edf6ff,#f8fbff);
    border-color:#cfe4ff;
}
.geo-state-box{
    background:linear-gradient(145deg,#f3efff,#fbf9ff);
    border-color:#dfd4ff;
}
.geo-city-box{
    background:linear-gradient(145deg,#fff4e8,#fffaf5);
    border-color:#ffe0bb;
}
.geo-step{
    position:absolute;
    top:9px;
    right:10px;
    width:23px;
    height:23px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:rgba(255,255,255,.9);
    box-shadow:0 4px 12px rgba(20,35,60,.08);
    font-size:10px;
    font-weight:900;
}
.geo-destination-result{
    margin-top:12px;
    border:1px dashed #cad7e7;
    border-radius:14px;
    padding:12px;
    background:#fff;
}
.geo-result-label{
    margin-bottom:7px;
    font-size:11px;
    font-weight:800;
}
.geo-result-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:8px;
}
.geo-use-state-btn{
    white-space:nowrap;
}
.geo-selected-path{
    margin-top:8px;
    color:#607087;
    font-size:10px;
}
@media(max-width:850px){
    .geo-selector-grid{grid-template-columns:1fr;}
    .geo-result-row{grid-template-columns:1fr;}
    .geo-panel-heading{align-items:flex-start;flex-direction:column;}
}
</style>


<style>
/* ==========================================================
   TRAVSCOPE LOCATION COLOR FORMULA — VISUAL ONLY
   Keeps all destination PHP/database/business logic unchanged.
   ========================================================== */

/* COUNTRY — BLUE */
.geo-country-box{
    background:linear-gradient(145deg,#dff0ff 0%,#eef7ff 48%,#f8fcff 100%)!important;
    border-color:#9fd0ff!important;
    box-shadow:0 8px 20px rgba(37,99,235,.08)!important;
}
.geo-country-box .form-control,
.geo-country-box select{
    background:#eef7ff!important;
    border-color:#9fcaff!important;
    color:#14345c!important;
}

/* STATE / REGION — PURPLE */
.geo-state-box{
    background:linear-gradient(145deg,#eee6ff 0%,#f6f1ff 50%,#fcfaff 100%)!important;
    border-color:#c9b7ff!important;
    box-shadow:0 8px 20px rgba(124,58,237,.07)!important;
}
.geo-state-box .form-control,
.geo-state-box select{
    background:#f5efff!important;
    border-color:#cbb9ff!important;
    color:#3d2864!important;
}

/* DISTRICT / CITY — ORANGE */
.geo-city-box{
    background:linear-gradient(145deg,#ffe8cf 0%,#fff2e4 50%,#fffaf4 100%)!important;
    border-color:#ffc98c!important;
    box-shadow:0 8px 20px rgba(234,88,12,.07)!important;
}
.geo-city-box .form-control,
.geo-city-box select{
    background:#fff2e3!important;
    border-color:#ffc68a!important;
    color:#633515!important;
}

/* Final destination result — GREEN / TEAL */
.geo-destination-result{
    background:linear-gradient(135deg,#e9fff5 0%,#f3fff9 55%,#f8fffc 100%)!important;
    border:1px solid #a9e7ca!important;
    box-shadow:0 7px 18px rgba(5,150,105,.06)!important;
}
.geo-destination-result .form-control,
.geo-destination-result input{
    background:#f1fff8!important;
    border-color:#a8dfc5!important;
    color:#164a36!important;
}
.geo-selected-path{
    background:#f5fbff!important;
    border:1px dashed #b9d9f4!important;
    color:#36536f!important;
}

/* Step circles follow the color formula */
.geo-country-box .geo-step{color:#1769c2!important;background:#fff!important}
.geo-state-box .geo-step{color:#7047c8!important;background:#fff!important}
.geo-city-box .geo-step{color:#c66516!important;background:#fff!important}

/* Disabled select must remain visibly connected to its color box */
.geo-selector-box select:disabled{
    opacity:.72!important;
    cursor:not-allowed!important;
    -webkit-text-fill-color:#6b7280!important;
}

/* Clean consistent label typography */
.geo-selector-box label,
.geo-result-label{
    font-family:'DM Sans',sans-serif!important;
    letter-spacing:.1px!important;
}
</style>


<style id="travscope-destination-box-colours-only">
/* ==========================================================
   TRAVSCOPE DESTINATION — BOX COLOUR UPDATE ONLY
   No PHP logic, sizing, sidebar, text, responsive or DB change.
   ========================================================== */

/* 1. TOP STAT BOXES */
.stats .stat:nth-child(1){
    background:linear-gradient(135deg,#eaf5ff 0%,#f7fbff 100%)!important;
    border-color:#c7e2ff!important;
    box-shadow:0 8px 22px rgba(37,99,235,.07)!important;
}
.stats .stat:nth-child(1) .stat-icon{
    background:#dceeff!important;
    color:#1769c2!important;
}

.stats .stat:nth-child(2){
    background:linear-gradient(135deg,#eafaf2 0%,#f7fdf9 100%)!important;
    border-color:#c7ead7!important;
    box-shadow:0 8px 22px rgba(22,163,74,.06)!important;
}
.stats .stat:nth-child(2) .stat-icon{
    background:#dff6e9!important;
    color:#16824f!important;
}

.stats .stat:nth-child(3){
    background:linear-gradient(135deg,#fff5e8 0%,#fffaf4 100%)!important;
    border-color:#f7dfbd!important;
    box-shadow:0 8px 22px rgba(234,88,12,.06)!important;
}
.stats .stat:nth-child(3) .stat-icon{
    background:#ffead0!important;
    color:#bf6519!important;
}

.stats .stat:nth-child(4){
    background:linear-gradient(135deg,#f3edff 0%,#fbf9ff 100%)!important;
    border-color:#ded0fb!important;
    box-shadow:0 8px 22px rgba(124,58,237,.06)!important;
}
.stats .stat:nth-child(4) .stat-icon{
    background:#ebe0ff!important;
    color:#7445c6!important;
}

/* 2. MAIN CONTENT CARDS */
main .card:nth-of-type(1){
    background:linear-gradient(135deg,#f5faff 0%,#ffffff 100%)!important;
    border-color:#d8e9fb!important;
}
main .card:nth-of-type(2){
    background:linear-gradient(135deg,#f8fbff 0%,#ffffff 100%)!important;
    border-color:#dfe8f2!important;
}
#destination-form{
    background:linear-gradient(135deg,#fbfcff 0%,#ffffff 100%)!important;
    border-color:#dfe5f4!important;
}

/* Card heading strips */
main .card:nth-of-type(1) .card-head{
    background:linear-gradient(90deg,#eaf5ff,#f7fbff)!important;
    border-bottom-color:#d7e9fb!important;
}
main .card:nth-of-type(2) .card-head{
    background:linear-gradient(90deg,#eef9f4,#f8fdfb)!important;
    border-bottom-color:#d9eee3!important;
}
#destination-form .card-head{
    background:linear-gradient(90deg,#f3edff,#faf8ff)!important;
    border-bottom-color:#e2d7f8!important;
}

/* 3. SEARCH / FILTER BOXES */
.filters .control:nth-of-type(1){
    background:#eef7ff!important;
    border-color:#bfddfa!important;
}
.filters .control:nth-of-type(2){
    background:#eefaf4!important;
    border-color:#c9e9d8!important;
}
.filters .control:nth-of-type(3){
    background:#fff5e9!important;
    border-color:#f4d9b6!important;
}

/* 4. SMART LOCATION BOXES — keep professional formula */
.geo-country-box{
    background:linear-gradient(145deg,#dff0ff,#f5fbff)!important;
    border-color:#9fd0ff!important;
}
.geo-country-box .form-control,
.geo-country-box select{
    background:#edf7ff!important;
    border-color:#9fcaff!important;
}

.geo-state-box{
    background:linear-gradient(145deg,#eee6ff,#fbf9ff)!important;
    border-color:#c9b7ff!important;
}
.geo-state-box .form-control,
.geo-state-box select{
    background:#f5efff!important;
    border-color:#cbb9ff!important;
}

.geo-city-box{
    background:linear-gradient(145deg,#ffe8cf,#fffaf5)!important;
    border-color:#ffc98c!important;
}
.geo-city-box .form-control,
.geo-city-box select{
    background:#fff2e3!important;
    border-color:#ffc68a!important;
}

.geo-destination-result{
    background:linear-gradient(135deg,#e9fff5,#f8fffc)!important;
    border-color:#a9e7ca!important;
}
.geo-destination-result .form-control{
    background:#f1fff8!important;
    border-color:#a8dfc5!important;
}

/* 5. ALL DESTINATION FORM BOXES — alternating soft colours */
#destination-form .form-grid > .form-group:not(.geo-destination-panel):nth-child(4n+1){
    background:linear-gradient(135deg,#eef7ff,#f9fcff)!important;
    border:1px solid #cfe5fa!important;
    border-radius:13px!important;
    padding:12px!important;
}
#destination-form .form-grid > .form-group:not(.geo-destination-panel):nth-child(4n+2){
    background:linear-gradient(135deg,#eefaf4,#f9fdfb)!important;
    border:1px solid #d0eadc!important;
    border-radius:13px!important;
    padding:12px!important;
}
#destination-form .form-grid > .form-group:not(.geo-destination-panel):nth-child(4n+3){
    background:linear-gradient(135deg,#f5efff,#fcfaff)!important;
    border:1px solid #e2d5fa!important;
    border-radius:13px!important;
    padding:12px!important;
}
#destination-form .form-grid > .form-group:not(.geo-destination-panel):nth-child(4n+4){
    background:linear-gradient(135deg,#fff5e8,#fffbf6)!important;
    border:1px solid #f3ddbd!important;
    border-radius:13px!important;
    padding:12px!important;
}

/* Keep controls lighter but visually connected to their coloured box */
#destination-form .form-group .form-control{
    background:rgba(255,255,255,.78)!important;
}

/* 6. IMAGE BOXES — visually separate card vs banner */
#destination-form .form-group:has(#destinationCardImage){
    background:linear-gradient(135deg,#eaf7ff,#f8fcff)!important;
    border-color:#bee2fb!important;
}
#destination-form .form-group:has(#destinationBannerImage){
    background:linear-gradient(135deg,#fff0f5,#fff9fb)!important;
    border-color:#f3cbd9!important;
}

/* 7. DISPLAY OPTIONS */
#destination-form .option-card{
    background:linear-gradient(135deg,#fff5d9,#fffaf0)!important;
    border-color:#efd79b!important;
}

/* 8. STATUS BOX */
#destination-form .form-group:has(select[name="status"]){
    background:linear-gradient(135deg,#eafaf2,#f8fdfb)!important;
    border-color:#c7ead7!important;
}

/* 9. TABLE AREA */
.destination-table thead th{
    background:#eef5fc!important;
}
.destination-table tbody tr:nth-child(even){
    background:#fbfdff!important;
}
.destination-table tbody tr:hover{
    background:#f2f8ff!important;
}

/* 10. IMAGE PICKER / SELECTED IMAGE PANELS */
.chosen-image-panel{
    background:linear-gradient(135deg,#eafff4,#f8fffb)!important;
    border-color:#b9e7cf!important;
}
.media-modal-search,
.pexels-search-row,
.pexels-policy-note{
    background:#f5f9ff!important;
    border-color:#d9e7f5!important;
}
</style>


<style id="travscope-destination-one-page-editor-fix">
/* Requested cleanup: remove the duplicate TRAVSCOPE ADMIN top header. */
body > header.header,
body > .header{
    display:none !important;
}

/* With the duplicate top header removed, keep content aligned cleanly. */
.main{
    padding-top:18px !important;
}

/* Destination Library stays as the normal home view. */
body.destination-home-mode #destination-form{
    display:none !important;
}

/* Add/Edit becomes a dedicated one-page editor like Package Management. */
body.destination-editor-mode .page-head,
body.destination-editor-mode .stats,
body.destination-editor-mode main.main > section.card:not(#destination-form){
    display:none !important;
}

body.destination-editor-mode .main{
    height:100vh;
    min-height:100vh;
    padding-top:12px !important;
    padding-bottom:12px !important;
    overflow:hidden;
}

body.destination-editor-mode #destination-form{
    height:calc(100vh - 24px);
    margin:0 !important;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border-radius:15px !important;
}

body.destination-editor-mode #destination-form .card-head{
    flex:0 0 auto;
}

body.destination-editor-mode #destination-form .card-body{
    flex:1 1 auto;
    min-height:0;
    padding-top:14px !important;
    overflow:hidden;
}

body.destination-editor-mode #destination-form form{
    height:100%;
    display:flex;
    flex-direction:column;
    min-height:0;
}

.destination-editor-tabs{
    flex:0 0 auto;
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-bottom:12px;
    padding:7px;
    border:1px solid #e2e8f0;
    border-radius:11px;
    background:#f8fafc;
}

.destination-editor-tab{
    min-height:37px;
    padding:0 13px;
    border:1px solid transparent;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    background:transparent;
    color:#64748b;
    cursor:pointer;
    font-size:11px;
    font-weight:800;
}

.destination-editor-tab:hover{
    background:#fff;
    color:#2563eb;
}

.destination-editor-tab.active{
    border-color:#bfdbfe;
    background:#fff;
    color:#2563eb;
    box-shadow:0 4px 12px rgba(15,23,42,.06);
}

/* Requested visual-only upgrade: colourful Destination editor tabs. */
.destination-editor-tab[data-destination-tab="basic"]{
    background:#eff6ff;
    border-color:#bfdbfe;
    color:#2563eb;
}

.destination-editor-tab[data-destination-tab="content"]{
    background:#f5f3ff;
    border-color:#ddd6fe;
    color:#7c3aed;
}

.destination-editor-tab[data-destination-tab="images"]{
    background:#ecfdf5;
    border-color:#a7f3d0;
    color:#059669;
}

.destination-editor-tab[data-destination-tab="settings"]{
    background:#fff7ed;
    border-color:#fed7aa;
    color:#ea580c;
}

.destination-editor-tab[data-destination-tab="basic"].active{
    background:#2563eb;
    border-color:#2563eb;
    color:#fff;
}

.destination-editor-tab[data-destination-tab="content"].active{
    background:#7c3aed;
    border-color:#7c3aed;
    color:#fff;
}

.destination-editor-tab[data-destination-tab="images"].active{
    background:#059669;
    border-color:#059669;
    color:#fff;
}

.destination-editor-tab[data-destination-tab="settings"].active{
    background:#ea580c;
    border-color:#ea580c;
    color:#fff;
}

.destination-editor-tab:hover{
    transform:translateY(-1px);
    box-shadow:0 5px 12px rgba(15,23,42,.10);
}

body.destination-editor-mode #destination-form .form-grid{
    flex:1 1 auto;
    min-height:0;
    overflow:auto;
    align-content:start;
    padding:1px 4px 12px 1px;
}

body.destination-editor-mode #destination-form .form-grid > .form-group{
    display:none;
}

body.destination-editor-mode #destination-form .form-grid > .form-group.destination-tab-active{
    display:block;
}

body.destination-editor-mode #destination-form .form-grid > .form-group.geo-destination-panel.destination-tab-active{
    display:block;
}

body.destination-editor-mode #destination-form .form-footer{
    flex:0 0 auto;
    margin-top:8px !important;
    padding-top:10px !important;
    background:#fff;
}

/* Keep modal tools above the editor. */
.media-modal,
.pexels-modal{
    z-index:3000 !important;
}

@media(max-width:900px){
    body.destination-editor-mode .main{
        height:auto;
        min-height:100vh;
        overflow:visible;
    }

    body.destination-editor-mode #destination-form{
        height:auto;
        min-height:calc(100vh - 24px);
        overflow:visible;
    }

    body.destination-editor-mode #destination-form .card-body,
    body.destination-editor-mode #destination-form form,
    body.destination-editor-mode #destination-form .form-grid{
        height:auto;
        overflow:visible;
    }
}
</style>


<style id="travscope-destination-compact-management-view">
/*
| Requested visual-only sizing adjustment:
| keep Destination Management balanced on one screen — not too large,
| not too small — while preserving all existing functionality.
*/

body.destination-home-mode .main{
    padding:16px 18px 22px !important;
}

body.destination-home-mode .page-head{
    margin-bottom:14px !important;
    align-items:center !important;
    gap:14px !important;
}

body.destination-home-mode .page-head h1{
    font-size:23px !important;
    line-height:1.15 !important;
}

body.destination-home-mode .page-head p{
    margin-top:4px !important;
    font-size:10px !important;
}

body.destination-home-mode .add-btn{
    min-height:38px !important;
    padding:0 13px !important;
    font-size:10px !important;
}

body.destination-home-mode .stats{
    margin-bottom:14px !important;
    gap:10px !important;
}

body.destination-home-mode .stat{
    min-height:104px !important;
    padding:12px 14px !important;
    border-radius:11px !important;
}

body.destination-home-mode .stat-icon{
    width:32px !important;
    height:32px !important;
    border-radius:9px !important;
    font-size:12px !important;
}

body.destination-home-mode .stat strong{
    margin-top:8px !important;
    font-size:19px !important;
    line-height:1.05 !important;
}

body.destination-home-mode .stat span{
    margin-top:3px !important;
    font-size:9px !important;
}

body.destination-home-mode .card{
    margin-bottom:14px !important;
    border-radius:12px !important;
}

body.destination-home-mode .card-head{
    min-height:52px !important;
    padding:0 16px !important;
}

body.destination-home-mode .card-head h2{
    font-size:13px !important;
}

body.destination-home-mode .card-head p{
    margin-top:2px !important;
    font-size:9px !important;
}

body.destination-home-mode .card-body{
    padding:14px 16px !important;
}

body.destination-home-mode .filters{
    gap:8px !important;
}

body.destination-home-mode .control,
body.destination-home-mode .form-control,
body.destination-home-mode .filter-btn{
    height:38px !important;
}

body.destination-home-mode .destination-table th{
    padding:9px 10px !important;
}

body.destination-home-mode .destination-table td{
    padding:10px !important;
}

/* Balanced tablet view. */
@media(max-width:1100px){
    body.destination-home-mode .main{
        padding:14px !important;
    }

    body.destination-home-mode .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    body.destination-home-mode .stat{
        min-height:96px !important;
    }
}

/* Phone view: comfortable single-column management layout. */
@media(max-width:700px){
    body.destination-home-mode .main{
        padding:12px !important;
    }

    body.destination-home-mode .page-head{
        align-items:flex-start !important;
        flex-direction:column !important;
        margin-bottom:12px !important;
    }

    body.destination-home-mode .page-head h1{
        font-size:21px !important;
    }

    body.destination-home-mode .add-btn{
        width:100% !important;
        justify-content:center !important;
    }

    body.destination-home-mode .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
        gap:8px !important;
        margin-bottom:12px !important;
    }

    body.destination-home-mode .stat{
        min-height:88px !important;
        padding:10px 12px !important;
    }

    body.destination-home-mode .filters{
        grid-template-columns:1fr !important;
    }

    body.destination-home-mode .filter-btn{
        width:100% !important;
    }
}

@media(max-width:420px){
    body.destination-home-mode .stats{
        grid-template-columns:1fr !important;
    }

    body.destination-home-mode .stat{
        min-height:82px !important;
    }
}
</style>


<!-- TRAVSCOPE PRO UNIFORM UI -->
<script>document.documentElement.classList.add('ts-pro-preload');</script>
<link rel="stylesheet" href="assets/travscope-pro-admin.css?v=20260916-v2">
<style id="travscope-v2451-merged-destinations-directory">
/* V24.51 - Listing view only: merge metrics into heading and filters into table toolbar.
   Do not affect the dedicated destination Add/Edit screen, POST actions or data logic. */
body.destination-home-mode .main{padding:12px 15px 20px !important}
body.destination-home-mode .page-head.destination-master-head{
    display:flex !important;align-items:center !important;justify-content:space-between !important;
    flex-direction:row !important;gap:12px !important;padding:12px 15px !important;
    background:linear-gradient(115deg,#fff,#f5fbff) !important;
    border:1px solid #dce7f3 !important;border-left:4px solid #0b74ff !important;
    border-radius:12px !important;box-shadow:0 5px 16px rgba(15,38,77,.04) !important;
    margin:0 0 9px !important;
}
body.destination-home-mode .destination-master-summary{
    display:flex;align-items:center;flex:1 1 auto;flex-wrap:wrap;gap:7px 15px;min-width:0
}
body.destination-home-mode .destination-master-titleline{
    display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;min-width:0
}
body.destination-home-mode .destination-master-head h1{
    font-size:20px !important;line-height:1.3 !important;margin:0 !important;white-space:nowrap
}
body.destination-home-mode .destination-master-total{
    display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border:1px solid #cddff8;
    border-radius:7px;background:#eaf3ff;color:#124b9d;white-space:nowrap;
    font-size:10px;font-weight:700;line-height:1.1
}
body.destination-home-mode .destination-master-total strong,
body.destination-home-mode .destination-master-pill strong{
    font-size:12px;font-weight:850;font-variant-numeric:tabular-nums;color:#162a44
}
body.destination-home-mode .destination-master-total strong{font-size:14px;color:#124b9d}
body.destination-home-mode .destination-master-metrics{
    display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-width:0
}
body.destination-home-mode .destination-master-pill{
    display:inline-flex;align-items:center;gap:5px;padding:5px 7px;
    border:1px solid #e0e7ef;border-radius:7px;background:#f8fafc;color:#41536c;
    white-space:nowrap;font-size:10px;font-weight:650;line-height:1.1
}
body.destination-home-mode .destination-master-pill.is-active{
    background:#eafbf2;border-color:#caefdc;color:#087650
}
body.destination-home-mode .destination-master-pill.is-inactive{
    background:#fff6e9;border-color:#f5dbb5;color:#9a5c0d
}
body.destination-home-mode .destination-master-pill.is-featured{
    background:#f3efff;border-color:#e1d6ff;color:#6742b5
}
body.destination-home-mode .destination-master-head .add-btn{
    display:inline-flex !important;flex:0 0 auto;align-items:center;justify-content:center;
    height:34px !important;min-height:34px !important;padding:0 11px !important;
    border-radius:7px !important;font-size:10px !important;white-space:nowrap
}
body.destination-home-mode .destination-directory{
    margin:0 0 9px !important;border:1px solid #dfe7ef !important;
    border-radius:11px !important;overflow:hidden;max-width:100%
}
body.destination-home-mode .destination-directory-toolbar{
    display:grid;grid-template-columns:minmax(135px,170px) minmax(0,1fr);
    gap:10px;align-items:center;padding:10px 11px;
    background:linear-gradient(180deg,#fff,#fbfdff);border-bottom:1px solid #e5ebf2
}
body.destination-home-mode .destination-directory-caption{
    display:flex;flex-wrap:wrap;align-items:center;gap:5px 8px;min-width:0
}
body.destination-home-mode .destination-directory-caption h2{
    color:#0d2c50;font-size:13px !important;line-height:1.2 !important;margin:0 !important
}
body.destination-home-mode .destination-directory-count{
    font-size:10px;font-weight:700;color:#667992;white-space:nowrap
}
body.destination-home-mode .destination-directory .destination-directory-filters{
    display:grid !important;grid-template-columns:minmax(180px,2fr) repeat(2,minmax(110px,1fr)) auto !important;
    align-items:center;gap:6px !important;margin:0;min-width:0
}
body.destination-home-mode .destination-directory .destination-directory-filters .control{
    min-width:0 !important;height:34px !important;min-height:34px !important;
    font-size:11px !important;padding:0 8px !important;border:1px solid #d7e2ed !important;
    border-radius:7px !important;background-color:#fff !important
}
body.destination-home-mode .destination-directory .destination-directory-filters .filter-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:5px;
    height:34px !important;min-height:34px !important;min-width:75px;
    padding:0 11px !important;border-radius:7px !important;
    font-size:10px !important;white-space:nowrap;background:#0b74ff !important;color:#fff !important
}
body.destination-home-mode .destination-directory-body{padding:0 !important}
body.destination-home-mode .destination-directory .table-wrap{
    border:0 !important;margin:0 !important;max-width:100%;overflow-x:auto
}
body.destination-home-mode .destination-directory .destination-table{
    width:100%;min-width:1120px;margin:0;border-collapse:collapse
}
body.destination-home-mode .destination-directory .destination-table th{
    padding:8px 9px !important;background:#f2f6fb !important;
    font-size:9px !important;letter-spacing:.25px !important
}
body.destination-home-mode .destination-directory .destination-table td{
    padding:8px 9px !important;font-size:11px !important;vertical-align:middle
}
body.destination-home-mode .destination-directory .destination-table tbody tr:hover{background:#f8fbff !important}
body.destination-home-mode .destination-directory .destination-cell{gap:8px !important;min-width:180px}
body.destination-home-mode .destination-directory .destination-thumb{
    width:49px !important;height:38px !important;flex:0 0 49px !important;border-radius:6px !important
}
body.destination-home-mode .destination-directory .destination-name{font-size:11px !important;max-width:205px}
body.destination-home-mode .destination-directory .subtext{font-size:9px !important;margin-top:2px !important}
body.destination-home-mode .destination-directory .package-count{font-size:12px !important}
body.destination-home-mode .destination-directory .badge{
    padding:5px 7px !important;font-size:9px !important
}
body.destination-home-mode .destination-directory .table-actions{
    display:flex !important;align-items:center;flex-wrap:nowrap !important;
    gap:4px !important;min-width:278px !important
}
body.destination-home-mode .destination-directory .table-actions form{margin:0 !important;flex:0 0 auto}
body.destination-home-mode .destination-directory .action-btn{
    min-height:28px !important;height:28px !important;padding:0 7px !important;
    font-size:9px !important;border-radius:6px !important;white-space:nowrap
}
body.destination-home-mode .destination-directory .empty{padding:28px 14px !important}
/* Keep all information accessible without introducing a page-wide horizontal scrollbar. */
@media(max-width:1170px){
    body.destination-home-mode .destination-directory-toolbar{grid-template-columns:1fr;gap:8px}
    body.destination-home-mode .destination-directory-caption{justify-content:space-between}
}
@media(max-width:900px){
    body.destination-home-mode .page-head.destination-master-head{
        flex-direction:column !important;align-items:flex-start !important;gap:10px !important
    }
    body.destination-home-mode .destination-directory .destination-directory-filters{
        grid-template-columns:minmax(150px,2fr) repeat(2,minmax(95px,1fr)) auto !important
    }
}
@media(max-width:700px){
    body.destination-home-mode .main{padding:11px !important}
    body.destination-home-mode .page-head.destination-master-head{padding:11px !important}
    body.destination-home-mode .destination-master-head h1{font-size:19px !important}
    body.destination-home-mode .destination-master-summary{gap:8px}
    body.destination-home-mode .destination-master-metrics{gap:4px}
    body.destination-home-mode .destination-master-pill{padding:5px 6px}
    body.destination-home-mode .destination-master-head .add-btn{width:100% !important}
    body.destination-home-mode .destination-directory-toolbar{padding:9px}
    body.destination-home-mode .destination-directory .destination-directory-filters{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important
    }
    body.destination-home-mode .destination-directory .destination-directory-filters input[name="q"]{grid-column:1/-1}
    body.destination-home-mode .destination-directory .destination-directory-filters .filter-btn{grid-column:1/-1}
    body.destination-home-mode .destination-directory .destination-directory-filters .control,
    body.destination-home-mode .destination-directory .destination-directory-filters .filter-btn{
        height:38px !important;min-height:38px !important
    }
}
@media(max-width:420px){
    body.destination-home-mode .destination-master-head h1{white-space:normal}
    body.destination-home-mode .destination-directory .destination-directory-filters{grid-template-columns:1fr !important}
    body.destination-home-mode .destination-directory .destination-directory-filters > *{grid-column:1/-1 !important}
}
</style>

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-admin-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="admin"></script>
<style>
/* Destination directory: scoped, self-contained styles for the admin listing. */
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory{overflow:visible;max-width:100%;border-radius:12px!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory-toolbar{display:grid;grid-template-columns:1fr;gap:12px;padding:16px;background:#f8fbff;border-bottom:1px solid #dce6f2;border-radius:12px 12px 0 0}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory-caption{justify-content:space-between}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory-caption h2{font-size:16px!important;color:#133454}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory-count{font-size:12px;color:#526780}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters{grid-template-columns:minmax(180px,1.8fr) repeat(4,minmax(105px,1fr)) auto auto!important;gap:8px!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .control,
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .filter-btn{height:38px!important;min-height:38px!important;font-size:12px!important;border-radius:8px!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .filter-btn{background:#1262ce!important;font-weight:700}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-clear-filters{font-size:12px;color:#245994;white-space:nowrap;text-decoration:underline;text-underline-offset:3px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .table-wrap{overflow-x:auto;max-width:100%;border-radius:0 0 12px 12px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table{width:100%;min-width:920px;table-layout:auto;margin:0;border-collapse:collapse}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table th{padding:12px!important;background:#edf3fa!important;color:#425b77;font-size:11px!important;letter-spacing:.35px!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table td{padding:14px 12px!important;font-size:13px!important;vertical-align:middle;color:#19334f;border-bottom:1px solid #e6edf6}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-cell{gap:12px!important;min-width:220px;align-items:flex-start}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-identity-copy{min-width:0;max-width:255px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-thumb{position:relative;width:62px!important;height:54px!important;flex:0 0 62px!important;border-radius:9px!important;background:#eaf1f9;color:#6280a2;overflow:hidden}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-image-placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:23px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-thumb img{position:relative;width:100%;height:100%;object-fit:cover;background:#eaf1f9}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-thumb[data-image-state="failed"] img{display:none!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-name{display:block;font-size:14px!important;font-weight:750;line-height:1.35;color:#123c6d;max-width:none;text-decoration:none;overflow-wrap:anywhere;white-space:normal}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-name:hover{text-decoration:underline}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .subtext{font-size:11px!important;line-height:1.45;color:#63758c;margin-top:4px!important;overflow-wrap:anywhere}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-record-meta{font-size:10px!important;color:#6a7b91}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-attention-list{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-attention{display:inline-flex;align-items:center;gap:4px;background:#fff7e9;border:1px solid #f0ddb9;border-radius:5px;padding:3px 5px;font-size:10px;line-height:1.35;color:#805b19}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-image-error[hidden]{display:none!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .package-count,
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-locations-link{font-size:17px!important;font-weight:750;font-variant-numeric:tabular-nums;color:#194f8c}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .package-count span{font-size:11px;font-weight:500;color:#62758d}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-locations-link{text-decoration:none;white-space:nowrap}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-locations-link i{font-size:10px;margin-left:4px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-visibility{min-width:105px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .badge{display:inline-flex;padding:6px 8px!important;font-size:11px!important;line-height:1.2;gap:5px}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-visibility .badge-featured{margin-top:6px;background:#edf4ff;border-color:#d1e1fa;color:#225896}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-visibility .destination-standard{display:block}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .table-actions{display:flex!important;align-items:center;flex-wrap:nowrap!important;gap:6px!important;min-width:236px!important}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .action-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:34px!important;min-height:34px!important;padding:0 9px!important;font-size:11px!important;line-height:1.2;border:1px solid #d8e4f3;border-radius:7px!important;background:#f3f7fd;color:#24558b;text-decoration:none;cursor:pointer}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-edit-link{background:#1262ce;color:white;border-color:#1262ce}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-menu{margin:0;flex:0 0 auto}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-menu summary{list-style:none}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-menu summary::-webkit-details-marker{display:none}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-menu[open] summary{background:#e5effc;border-color:#98b9e8}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel{position:fixed;z-index:1200;width:252px;max-width:calc(100vw - 24px);padding:8px;background:white;border:1px solid #d8e4f3;border-radius:10px;box-shadow:0 10px 28px rgba(19,43,79,.18);overflow:auto;max-height:70vh}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel form{margin:0!important;padding:2px 0}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel .action-btn{width:100%;justify-content:flex-start;min-height:38px!important;height:auto!important;padding:10px!important;font-size:12px!important;background:white;border:0}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel .action-btn:hover{background:#edf4ff}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel .danger{color:#b33b3b}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-more-panel button:disabled{cursor:not-allowed;opacity:.55}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-delete-reason{font-size:11px;line-height:1.45;color:#64758a;margin:3px 10px 6px;white-space:normal}
body:is(.destination-home-mode,.destination-editor-mode) .destination-directory :is(a,button,summary,select,input):focus-visible{outline:3px solid #79aafa;outline-offset:3px}
@media(max-width:1250px){
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters{grid-template-columns:repeat(4,minmax(0,1fr))!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters input[name="q"]{grid-column:span 2}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .filter-btn{grid-column:auto}
}
@media(max-width:800px){
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory-toolbar{padding:12px}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters{grid-template-columns:repeat(2,minmax(0,1fr))!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .control,
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters .filter-btn{height:42px!important;min-height:42px!important;font-size:13px!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters > *{grid-column:auto!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-directory-filters input[name="q"]{grid-column:1/-1!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-clear-filters{text-align:center;font-size:13px}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .table-wrap{overflow:visible}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table{display:block;min-width:0}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table thead{display:none}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table tbody{display:grid;gap:10px;padding:10px;background:#f2f6fc}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;background:white;border:1px solid #dce6f2;border-radius:10px;overflow:visible}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table td{display:block;padding:11px 12px!important;border:0;font-size:13px!important;min-width:0}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table td::before{content:attr(data-label);display:block;font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#63758c;margin-bottom:5px}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-table .destination-identity{grid-column:1/-1;border-bottom:1px solid #e6edf6}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-identity::before{display:none}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-cell{min-width:0}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-identity-copy{max-width:none}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-actions-cell{grid-column:1/-1;border-top:1px solid #e6edf6;padding-top:12px!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .destination-actions-cell::before{display:none}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .table-actions{min-width:0!important;flex-wrap:wrap!important;gap:8px!important}
 body:is(.destination-home-mode,.destination-editor-mode) .destination-directory .action-btn{height:40px!important;min-height:40px!important;font-size:12px!important;padding:0 12px!important}
}
</style>
</head>

<body class="<?= $destinationEditorMode ? 'destination-editor-mode' : 'destination-home-mode'; ?>">

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






<header class="header">

<a
    href="<?= e(BASE_URL . 'admin-dashboard.php'); ?>"
    class="logo"
>

<div class="logo-box">
    <i class="fa-solid fa-plane"></i>
</div>

TRAV<span>SCOPE</span> ADMIN

</a>

<div class="admin-user">

<div class="avatar">

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

<!-- =========================================================
     CONSTANT TRAVSCOPE ADMIN SIDEBAR
========================================================= -->




<main class="main">

<div class="page-head destination-master-head">

<div class="destination-master-summary">
    <div class="destination-master-titleline">
        <h1>Destinations</h1>
        <span class="destination-master-total" title="Total destinations in your library">
            <i class="fa-solid fa-earth-asia" aria-hidden="true"></i>
            <strong><?= number_format($stats['total']); ?></strong> Total Destinations
        </span>
    </div>
    <div class="destination-master-metrics" aria-label="Live destination counts">
        <span class="destination-master-pill is-active"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Active <strong><?= number_format($stats['active']); ?></strong></span>
        <span class="destination-master-pill is-inactive"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i> Inactive <strong><?= number_format($stats['inactive']); ?></strong></span>
        <span class="destination-master-pill is-featured"><i class="fa-solid fa-star" aria-hidden="true"></i> Featured <strong><?= number_format($stats['featured']); ?></strong></span>
    </div>
</div>

<a
    href="<?= e(
        BASE_URL . destinationListUrl($destinationListFilters, ['new' => 1], 'destination-form')
    ); ?>"
    class="add-btn"
>
    <i class="fa-solid fa-plus"></i>
    Add Destination
</a>

</div>


<?php if ($success !== ''): ?>

<div class="alert alert-success">

<i class="fa-solid fa-circle-check"></i>

<?= e($success); ?>

</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="alert alert-error">

<i class="fa-solid fa-circle-exclamation"></i>

<?= e($error); ?>

</div>

<?php endif; ?>


<!-- V24.51: unified Destination Library filter and table; existing search/actions unchanged. -->
<section class="card destination-directory" aria-label="Destination library">
    <div class="destination-directory-toolbar">
        <div class="destination-directory-caption">
            <h2>Destination Library</h2>
            <span class="destination-directory-count"><?= number_format(count($destinations)); ?> found</span>
        </div>
        <form method="get" class="filters destination-directory-filters" aria-label="Filter destinations">
            <input type="search" name="q" class="control" aria-label="Search destinations" placeholder="Search destination, country…" value="<?= e($search); ?>">
            <select name="country" class="control" aria-label="Country">
                <option value="">All countries</option>
                <?php foreach ($countries as $country): ?>
                <option value="<?= e($country); ?>" <?= $countryFilter === $country ? 'selected' : ''; ?>><?= e($country); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="continent" class="control" aria-label="Continent">
                <option value="">All continents</option>
                <?php foreach ($continents as $continent): ?>
                <option value="<?= e($continent); ?>" <?= $continentFilter === $continent ? 'selected' : ''; ?>><?= e($continent); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="control" aria-label="Status">
                <option value="">All statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <select name="featured" class="control" aria-label="Featured">
                <option value="">All visibility</option>
                <option value="1" <?= $featuredFilter === '1' ? 'selected' : ''; ?>>Featured</option>
                <option value="0" <?= $featuredFilter === '0' ? 'selected' : ''; ?>>Standard</option>
            </select>
            <button type="submit" class="filter-btn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Apply</button>
            <a class="destination-clear-filters" href="<?= e(BASE_URL . 'admin-destinations.php'); ?>">Clear filters</a>
        </form>
    </div>
    <div class="destination-directory-body">
        <?php if ($destinations): ?>
        <div class="table-wrap">
            <table class="destination-table">
                <thead><tr>
                    <th scope="col">Destination</th>
                    <th scope="col">Geography</th>
                    <th scope="col">Packages</th>
                    <th scope="col">Locations</th>
                    <th scope="col">Visibility</th>
                    <th scope="col">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($destinations as $destination): ?>
                <?php
                    $image = destinationImageUrl($destination['image'] ?? '');
                    $destinationEditHref = BASE_URL . destinationListUrl($destinationListFilters, ['edit' => (int)$destination['id']], 'destination-form');
                    $destinationCitiesHref = BASE_URL . 'admin-destination-locations.php?destination_id=' . (int)$destination['id'];
                    $geographyIssues = [];
                    if (trim((string)($destination['country'] ?? '')) === '') $geographyIssues[] = 'Country missing';
                    if (trim((string)($destination['continent'] ?? '')) === '') $geographyIssues[] = 'Continent missing';
                    $mediaIssues = [];
                    if ($image === '') $mediaIssues[] = 'Card image missing';
                    if (trim((string)($destination['banner_image'] ?? '')) === '') $mediaIssues[] = 'Banner image missing';
                    $linkedLocations = (int)($destination['location_count'] ?? 0);
                    $linkedHotels = (int)($destination['hotel_count'] ?? 0);
                    $deleteBlocked = (int)$destination['package_count'] > 0 || $linkedLocations > 0 || $linkedHotels > 0;
                    $updated = $destination['updated_at'] ?: ($destination['created_at'] ?? '');
                ?>
                <tr class="destination-row" data-destination-id="<?= (int)$destination['id']; ?>">
                    <td class="destination-identity" data-label="Destination">
                        <div class="destination-cell">
                            <div class="destination-thumb" data-image-state="<?= $image !== '' ? 'ready' : 'missing'; ?>">
                                <span class="destination-image-placeholder" aria-hidden="true"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i></span>
                                <?php if ($image !== ''): ?>
                                <img src="<?= e($image); ?>" alt="<?= e($destination['name']); ?>" loading="lazy">
                                <?php endif; ?>
                            </div>
                            <div class="destination-identity-copy">
                                <a href="<?= e($destinationEditHref); ?>" class="destination-name destination-name-link"><?= e($destination['name']); ?></a>
                                <div class="subtext destination-slug"><?= e($destination['slug']); ?></div>
                                <div class="subtext destination-record-meta" <?= $updated ? 'title="' . e((string)$updated) . '"' : ''; ?>>
                                    <?= $updated ? 'Updated ' . e(date('d M Y', strtotime($updated))) . ' · ' : ''; ?>Sort <?= (int)$destination['sort_order']; ?>
                                </div>
                                <div class="destination-attention-list">
                                    <?php if ($geographyIssues): ?><span class="destination-attention" title="<?= e(implode(' · ', $geographyIssues)); ?>"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Geography missing</span><?php endif; ?>
                                    <?php if ($mediaIssues): ?><span class="destination-attention" title="<?= e(implode(' · ', $mediaIssues)); ?>"><i class="fa-regular fa-image" aria-hidden="true"></i> <?= e(implode(' · ', $mediaIssues)); ?></span><?php endif; ?>
                                    <span class="destination-attention destination-image-error" hidden><i class="fa-regular fa-image" aria-hidden="true"></i> Card image unavailable</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Geography" class="destination-geography">
                        <strong><?= trim((string)($destination['country'] ?? '')) !== '' ? e($destination['country']) : 'Country missing'; ?></strong>
                        <div class="subtext"><?= trim((string)($destination['continent'] ?? '')) !== '' ? e($destination['continent']) : 'Continent missing'; ?></div>
                    </td>
                    <td data-label="Packages" class="destination-packages">
                        <strong class="package-count"><?= number_format((int)$destination['package_count']); ?> <span>total</span></strong>
                        <div class="subtext destination-active-packages"><?= number_format((int)$destination['active_package_count']); ?> active</div>
                    </td>
                    <td data-label="Locations" class="destination-locations">
                        <a href="<?= e($destinationCitiesHref); ?>" class="destination-locations-link" aria-label="Manage locations for <?= e($destination['name']); ?>">
                            <?= $destination['location_count'] !== null ? number_format($linkedLocations) : '—'; ?> <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        </a>
                        <div class="subtext"><?= $destination['location_count'] !== null ? 'Saved locations' : 'Count unavailable'; ?></div>
                    </td>
                    <td data-label="Visibility" class="destination-visibility">
                        <span class="badge badge-<?= e($destination['status']); ?>"><i class="fa-solid <?= $destination['status'] === 'active' ? 'fa-circle-check' : 'fa-circle-pause'; ?>" aria-hidden="true"></i> <?= e(ucfirst($destination['status'])); ?></span>
                        <?php if ((int)$destination['featured'] === 1): ?><span class="badge badge-featured"><i class="fa-solid fa-star" aria-hidden="true"></i> Featured</span><?php else: ?><span class="subtext destination-standard">Standard</span><?php endif; ?>
                    </td>
                    <td data-label="Actions" class="destination-actions-cell">
                        <div class="table-actions">
                            <a href="<?= e($destinationEditHref); ?>" class="action-btn destination-edit-link"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a>
                            <a href="<?= e($destinationCitiesHref); ?>" class="action-btn destination-cities-link"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> Manage Cities</a>
                            <details class="destination-more-menu">
                                <summary class="action-btn" aria-label="More actions for <?= e($destination['name']); ?>">More <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
                                <div class="destination-more-panel">
                                    <form method="post" action="<?= e(BASE_URL . $destinationListHref); ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                        <input type="hidden" name="action" value="toggle_featured">
                                        <input type="hidden" name="destination_id" value="<?= (int)$destination['id']; ?>">
                                        <?php foreach ($destinationListFilters as $key => $value): ?><input type="hidden" name="list_filters[<?= e($key); ?>]" value="<?= e($value); ?>"><?php endforeach; ?>
                                        <button type="submit" class="action-btn feature"><i class="<?= (int)$destination['featured'] === 1 ? 'fa-solid' : 'fa-regular'; ?> fa-star" aria-hidden="true"></i> <?= (int)$destination['featured'] === 1 ? 'Unfeature' : 'Feature'; ?></button>
                                    </form>
                                    <form method="post" action="<?= e(BASE_URL . $destinationListHref); ?>" onsubmit="return confirm('<?= $destination['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> this destination?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="destination_id" value="<?= (int)$destination['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?= $destination['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                        <?php foreach ($destinationListFilters as $key => $value): ?><input type="hidden" name="list_filters[<?= e($key); ?>]" value="<?= e($value); ?>"><?php endforeach; ?>
                                        <button type="submit" class="action-btn status"><i class="fa-solid <?= $destination['status'] === 'active' ? 'fa-pause' : 'fa-play'; ?>" aria-hidden="true"></i> <?= $destination['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                    <form method="post" action="<?= e(BASE_URL . $destinationListHref); ?>" class="destination-delete-form" data-destination-name="<?= e($destination['name']); ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="destination_id" value="<?= (int)$destination['id']; ?>">
                                        <?php foreach ($destinationListFilters as $key => $value): ?><input type="hidden" name="list_filters[<?= e($key); ?>]" value="<?= e($value); ?>"><?php endforeach; ?>
                                        <button type="submit" class="action-btn danger" <?= $deleteBlocked ? 'disabled aria-describedby="delete-reason-' . (int)$destination['id'] . '"' : ''; ?>><i class="fa-regular fa-trash-can" aria-hidden="true"></i> Delete</button>
                                        <?php if ($deleteBlocked): ?><p class="destination-delete-reason" id="delete-reason-<?= (int)$destination['id']; ?>">Linked packages, locations or hotels prevent deletion. Set inactive to keep linked records.</p><?php endif; ?>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty">
            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
            <strong>No destinations found</strong>
            <p>Create your first destination or change your search filters.</p>
            <a href="<?= e(BASE_URL . 'admin-destinations.php'); ?>" class="destination-clear-filters">Clear filters</a>
        </div>
        <?php endif; ?>
    </div>
</section>


<!-- ADD / EDIT -->

<?php if ($destinationEditorMode): ?>

<section
    class="card"
    id="destination-form"
>

<div class="card-head">

<div>

<h2>

<?= $editDestination
    ? 'Edit Destination'
    : 'Add New Destination'; ?>

</h2>

<p>
    Add destination information and attractive travel images.
</p>

</div>


<?php if ($editDestination): ?>

<a
    href="<?= e(
        BASE_URL . destinationListUrl($destinationListFilters, ['new' => 1], 'destination-form')
    ); ?>"
    class="btn btn-light"
>
    <i class="fa-solid fa-plus"></i>
    New Destination
</a>

<?php endif; ?>

</div>


<div class="card-body">

<form
    method="post"
    enctype="multipart/form-data"
>

<?php foreach ($destinationListFilters as $key => $value): ?>
<input type="hidden" name="list_filters[<?= e($key); ?>]" value="<?= e($value); ?>">
<?php endforeach; ?>

<input
    type="hidden"
    name="csrf_token"
    value="<?= e($csrfToken); ?>"
>

<input
    type="hidden"
    name="action"
    value="<?=
        $editDestination
        ? 'update'
        : 'create';
    ?>"
>


<?php if ($editDestination): ?>

<input
    type="hidden"
    name="destination_id"
    value="<?= (int)$editDestination['id']; ?>"
>

<?php endif; ?>


<div class="destination-editor-tabs" id="destinationEditorTabs">
    <button type="button" class="destination-editor-tab active" data-destination-tab="basic">
        <i class="fa-solid fa-location-dot"></i>
        Basic Info
    </button>

    <button type="button" class="destination-editor-tab" data-destination-tab="content">
        <i class="fa-regular fa-file-lines"></i>
        Content
    </button>

    <button type="button" class="destination-editor-tab" data-destination-tab="images">
        <i class="fa-regular fa-images"></i>
        Images
    </button>

    <button type="button" class="destination-editor-tab" data-destination-tab="settings">
        <i class="fa-solid fa-sliders"></i>
        Settings
    </button>
</div>

<div class="form-grid">



<div class="form-group geo-destination-panel" style="grid-column:1/-1">
    <div class="geo-panel-heading">
        <div>
            <strong><i class="fa-solid fa-location-dot"></i> Smart Destination Location</strong>
            <div class="geo-panel-help">
                Choose Country → State/Region → District/City. Travscope fills the destination name automatically.
            </div>
        </div>
        <span class="geo-live-pill"><i class="fa-solid fa-bolt"></i> Auto Select</span>
    </div>

    <div class="geo-selector-grid">

        <div class="geo-selector-box geo-country-box">
            <div class="geo-step">1</div>
            <label>Country *</label>
            <select
                id="destinationGeoCountry"
                name="country"
                class="form-control"
                required
                data-current="<?= e((string)$form['country']); ?>"
            >
                <option value="">Loading countries...</option>
            </select>
            <small>Select from the international country list.</small>
        </div>

        <div class="geo-selector-box geo-state-box">
            <div class="geo-step">2</div>
            <label>State / Region *</label>
            <select
                id="destinationGeoState"
                class="form-control"
                disabled
            >
                <option value="">Select country first</option>
            </select>
            <small>Only states/regions under the selected country appear.</small>
        </div>

        <div class="geo-selector-box geo-city-box">
            <div class="geo-step">3</div>
            <label>District / City</label>
            <select
                id="destinationGeoCity"
                class="form-control"
                disabled
            >
                <option value="">Select state first</option>
            </select>
            <small>Select a district/city, or use the state itself as destination.</small>
        </div>

    </div>

    <div class="geo-destination-result">
        <div class="geo-result-label">Destination Name *</div>

        <div class="geo-result-row">
            <input
                type="text"
                id="destinationGeoName"
                name="name"
                class="form-control"
                maxlength="150"
                required
                readonly
                value="<?= e((string)$form['name']); ?>"
                placeholder="Select location above"
            >

            <button
                type="button"
                class="btn-secondary geo-use-state-btn"
                id="destinationUseState"
                disabled
            >
                <i class="fa-solid fa-map"></i>
                Use State / Region
            </button>
        </div>

        <div class="geo-selected-path" id="destinationGeoPath">
            <i class="fa-solid fa-route"></i>
            Select a country to begin.
        </div>
    </div>
</div>



<div class="form-group">

<label>
    Continent
</label>

<input
    type="text"
    name="continent"
    class="form-control"
    maxlength="100"
    placeholder="Example: Asia"
    value="<?= e($form['continent']); ?>"
>

</div>


<div class="form-group">

<label>
    Sort Order
</label>

<input
    type="number"
    name="sort_order"
    class="form-control"
    min="0"
    value="<?= (int)$form['sort_order']; ?>"
>

<div class="help">
    Lower numbers appear first.
</div>

</div>


<div class="form-group full">

<label>
    Short Description
</label>

<textarea
    name="short_description"
    class="form-control"
    maxlength="500"
    placeholder="Short attractive description..."
><?= e($form['short_description']); ?></textarea>

</div>


<div class="form-group full">

<label>
    Full Destination Description
</label>

<textarea
    name="description"
    class="form-control large"
    placeholder="Describe attractions, culture, experiences, best places to visit..."
><?= e($form['description']); ?></textarea>

</div>


<!-- DESTINATION CARD IMAGE -->

<div class="form-group">

<label>
    Destination Card Image
</label>

<input
    type="file"
    name="image"
    id="destinationCardImage"
    class="native-destination-image-upload"
    accept="image/jpeg,image/png,image/webp"
>

<input
    type="hidden"
    name="image_library_path"
    id="destinationCardLibraryPath"
    value="<?= e($form['image_library_path'] ?? ''); ?>"
    data-preview-url="<?= e(destinationImageUrl($form['image_library_path'] ?? '')); ?>"
>

<button
    type="button"
    class="main-image-gallery-launch open-media-picker"
    data-mode="card"
>
    <i class="fa-regular fa-images"></i>
    Choose Photo
</button>

<button
    type="button"
    class="open-pexels-picker pexels-launch-button"
    data-pexels-mode="card"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search Pexels + Unsplash
</button>

<div
    class="chosen-image-panel"
    id="destinationCardChosenPreview"
    hidden
>
    <div class="chosen-image-title">
        <i class="fa-solid fa-circle-check"></i>
        Selected Destination Card Image
    </div>

    <div
        class="chosen-image-items"
        id="destinationCardChosenPreviewItems"
    ></div>
</div>

<div class="help">
    Upload from Computer / PC, choose from Web Gallery,
    or search Pexels + Unsplash photos. JPG, PNG or WEBP.
    Maximum PC upload size: 5 MB.
</div>

<?php if (!empty($form['image'])): ?>

<div class="current-image">

<img
    src="<?= e(
        destinationImageUrl(
            $form['image']
        )
    ); ?>"
    alt="Current destination card image"
>

</div>

<label class="destination-remove-image">

<input
    type="checkbox"
    name="remove_image"
    value="1"
    <?= !empty($form['remove_image']) ? 'checked' : ''; ?>
>

Remove current card image

</label>

<?php endif; ?>

</div>


<!-- DESTINATION BANNER IMAGE -->

<div class="form-group">

<label>
    Destination Banner Image
</label>

<input
    type="file"
    name="banner_image"
    id="destinationBannerImage"
    class="native-destination-image-upload"
    accept="image/jpeg,image/png,image/webp"
>

<input
    type="hidden"
    name="banner_library_path"
    id="destinationBannerLibraryPath"
    value="<?= e($form['banner_library_path'] ?? ''); ?>"
    data-preview-url="<?= e(destinationImageUrl($form['banner_library_path'] ?? '')); ?>"
>

<button
    type="button"
    class="main-image-gallery-launch open-media-picker"
    data-mode="banner"
>
    <i class="fa-regular fa-images"></i>
    Choose Photo
</button>

<button
    type="button"
    class="open-pexels-picker pexels-launch-button"
    data-pexels-mode="banner"
>
    <i class="fa-solid fa-magnifying-glass"></i>
    Search Pexels + Unsplash
</button>

<div
    class="chosen-image-panel"
    id="destinationBannerChosenPreview"
    hidden
>
    <div class="chosen-image-title">
        <i class="fa-solid fa-circle-check"></i>
        Selected Destination Banner Image
    </div>

    <div
        class="chosen-image-items"
        id="destinationBannerChosenPreviewItems"
    ></div>
</div>

<div class="help">
    Upload a wide banner from Computer / PC, choose from Web Gallery,
    or search Pexels + Unsplash photos.
</div>

<?php if (!empty($form['banner_image'])): ?>

<div class="current-image destination-banner-current">

<img
    src="<?= e(
        destinationImageUrl(
            $form['banner_image']
        )
    ); ?>"
    alt="Current destination banner image"
>

</div>

<label class="destination-remove-image">

<input
    type="checkbox"
    name="remove_banner_image"
    value="1"
    <?= !empty($form['remove_banner_image']) ? 'checked' : ''; ?>
>

Remove current banner image

</label>

<?php endif; ?>

</div>


<div class="form-group full">

<label>
    Display Options
</label>


<div class="option-row">


<label class="option-card">

<input
    type="checkbox"
    name="featured"
    value="1"
    <?= (int)$form['featured'] === 1
        ? 'checked'
        : ''; ?>
>

<i class="fa-solid fa-star"></i>

Featured Destination

</label>


</div>

</div>


<div class="form-group full">

<label>
    Status
</label>

<select
    name="status"
    class="form-control"
>

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


<div class="form-footer">


<?php if ($editDestination): ?>

<a
    href="<?= e(
        BASE_URL . $destinationListHref
    ); ?>"
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

<?= $editDestination
    ? 'Update Destination'
    : 'Create Destination'; ?>

</button>


</div>

</form>

</div>

</section>

<?php endif; ?>



</main>

</div>




<!-- WEB GALLERY MODAL -->

<div
    class="media-modal"
    id="mediaModal"
    aria-hidden="true"
>
    <div
        class="media-modal-backdrop"
        data-close-media
    ></div>

    <div class="media-modal-dialog">

        <div class="media-modal-head">

            <div>
                <h3 id="mediaModalTitle">
                    Choose Destination Image
                </h3>

                <p id="mediaModalHelp">
                    Search by image name and select one photo.
                </p>
            </div>

            <button
                type="button"
                class="media-modal-close"
                data-close-media
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <div class="media-modal-search">

            <i class="fa-solid fa-magnifying-glass"></i>

            <input
                type="search"
                id="mediaSearchInput"
                placeholder="Search Web Gallery by image name"
            >

        </div>

        <div class="media-modal-grid" id="mediaModalGrid">

            <?php if ($destinationMediaLibrary): ?>

                <?php foreach ($destinationMediaLibrary as $media): ?>

                    <article
                        class="media-popup-item"
                        data-image="<?= e($media['image']); ?>"
                        data-name="<?= e(strtolower($media['name'])); ?>"
                    >

                        <img
                            src="<?= e($media['url']); ?>"
                            alt="<?= e($media['name']); ?>"
                            loading="lazy"
                        >

                        <div class="media-popup-name">
                            <?= e($media['name']); ?>
                        </div>

                        <button
                            type="button"
                            class="media-popup-select"
                            data-image="<?= e($media['image']); ?>"
                            data-url="<?= e($media['url']); ?>"
                            data-name="<?= e($media['name']); ?>"
                        >
                            Choose Photo
                        </button>

                    </article>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="media-empty">
                    No photos are available in Web Gallery.
                </div>

            <?php endif; ?>

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

<!-- PEXELS MODAL -->

<div
    class="pexels-modal"
    id="pexelsModal"
    aria-hidden="true"
>
    <div
        class="pexels-modal-backdrop"
        data-close-pexels
    ></div>

    <div class="pexels-modal-dialog">

        <div class="pexels-modal-head">

            <div>
                <h3>
                    Search Pexels + Unsplash
                </h3>

                <p>
                    Search results from Pexels and Unsplash are combined automatically. Photographer and source attribution are retained.
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
                    placeholder="Example: Goa beach, Kashmir mountain, Dubai skyline"
                >

            </div>

            <button
                type="button"
                class="pexels-search-button"
                id="pexelsSearchButton"
            >
                Search Photos
            </button>

        </div>

        <div class="pexels-policy-note">

            <i class="fa-solid fa-shield-halved"></i>

            <span>
                Photos are combined from official Pexels and Unsplash API results.
                Pexels selections are saved to the destination Web Gallery; Unsplash selections use the official hotlinked image source.
            </span>

        </div>

        <div
            class="pexels-status"
            id="pexelsStatus"
            hidden
        ></div>

        <div
            class="pexels-results"
            id="pexelsResults"
        >
            <div class="pexels-empty">
                <i class="fa-regular fa-images"></i>
                Enter a destination or travel keyword to search Pexels + Unsplash.
            </div>
        </div>

        <div class="ts-photo-pagination" id="destinationPhotoPagination" hidden>
            <button type="button" id="destinationPhotoPrevious"><i class="fa-solid fa-chevron-left"></i> Previous</button>
            <span id="destinationPhotoPageText">Page 1</span>
            <button type="button" id="destinationPhotoNext">Next <i class="fa-solid fa-chevron-right"></i></button>
        </div>

    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const csrfToken = <?= json_encode($csrfToken); ?>;

    const modes = {
        card: {
            hidden: document.getElementById('destinationCardLibraryPath'),
            file: document.getElementById('destinationCardImage'),
            panel: document.getElementById('destinationCardChosenPreview'),
            items: document.getElementById('destinationCardChosenPreviewItems')
        },
        banner: {
            hidden: document.getElementById('destinationBannerLibraryPath'),
            file: document.getElementById('destinationBannerImage'),
            panel: document.getElementById('destinationBannerChosenPreview'),
            items: document.getElementById('destinationBannerChosenPreviewItems')
        }
    };

    let activeMode = 'card';

    function addSelectedPreview(mode, src, name) {
        const config = modes[mode];

        if (!config || !src) {
            return;
        }

        config.items.innerHTML = '';

        const item = document.createElement('div');
        item.className = 'chosen-image-thumb';

        const image = document.createElement('img');
        image.src = src;
        image.alt = name || 'Selected image';

        const label = document.createElement('span');
        label.textContent = name || 'Selected image';

        item.appendChild(image);
        item.appendChild(label);

        config.items.appendChild(item);
        config.panel.hidden = false;
    }

    function selectLibraryImage(path, url, name) {
        const config = modes[activeMode];

        if (!config) {
            return;
        }

        config.hidden.value = path;

        /* Same priority as admin-packages.php:
           Web Gallery selection clears Computer / PC selection. */
        config.file.value = '';

        addSelectedPreview(
            activeMode,
            url,
            name
        );
    }

    /* PC upload preview. PC upload has final priority. */
    Object.entries(modes).forEach(function ([mode, config]) {
        if (config.hidden?.value && config.hidden.dataset.previewUrl) {
            addSelectedPreview(mode, config.hidden.dataset.previewUrl, 'Selected image');
        }
        config.file?.addEventListener('change', function () {
            const file = this.files?.[0];

            if (!file) {
                return;
            }

            if (![
                'image/jpeg',
                'image/png',
                'image/webp'
            ].includes(file.type)) {
                alert('Only JPG, PNG and WEBP images are allowed.');
                this.value = '';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert('Image must be smaller than 5 MB.');
                this.value = '';
                return;
            }

            config.hidden.value = '';

            const reader = new FileReader();

            reader.onload = function (event) {
                addSelectedPreview(
                    mode,
                    String(event.target?.result || ''),
                    'New upload: ' + file.name
                );
            };

            reader.readAsDataURL(file);
        });
    });


    /* =====================================================
       WEB GALLERY
    ===================================================== */

    const mediaModal =
        document.getElementById('mediaModal');

    const mediaTitle =
        document.getElementById('mediaModalTitle');

    const mediaHelp =
        document.getElementById('mediaModalHelp');

    const mediaSearch =
        document.getElementById('mediaSearchInput');

    const mediaItems =
        Array.from(
            document.querySelectorAll('.media-popup-item')
        );

    function openMediaModal(mode) {
        activeMode =
            mode === 'banner'
                ? 'banner'
                : 'card';

        mediaTitle.textContent =
            activeMode === 'banner'
                ? 'Choose Destination Banner Image'
                : 'Choose Destination Card Image';

        mediaHelp.textContent =
            'Search by image name and click one photo to use it.';

        mediaSearch.value = '';

        mediaItems.forEach(function (item) {
            item.classList.remove('hide');
        });

        mediaModal.classList.add('open');
        mediaModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('media-modal-open');

        setTimeout(function () {
            mediaSearch?.focus();
        }, 50);
    }

    function closeMediaModal() {
        mediaModal.classList.remove('open');
        mediaModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('media-modal-open');
    }

    document.querySelectorAll('.open-media-picker')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                openMediaModal(
                    this.dataset.mode || 'card'
                );
            });
        });

    document.querySelectorAll('[data-close-media]')
        .forEach(function (button) {
            button.addEventListener(
                'click',
                closeMediaModal
            );
        });

    mediaSearch?.addEventListener('input', function () {
        const query =
            this.value.trim().toLowerCase();

        mediaItems.forEach(function (item) {
            item.classList.toggle(
                'hide',
                Boolean(query)
                && !String(item.dataset.name || '')
                    .includes(query)
            );
        });
    });

    document.querySelectorAll('.media-popup-select')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                selectLibraryImage(
                    String(this.dataset.image || ''),
                    String(this.dataset.url || ''),
                    String(this.dataset.name || '')
                );

                closeMediaModal();
            });
        });


    /* =====================================================
       PEXELS SEARCH
    ===================================================== */

    const pexelsModal =
        document.getElementById('pexelsModal');

    const pexelsSearchInput =
        document.getElementById('pexelsSearchInput');

    const pexelsSearchButton =
        document.getElementById('pexelsSearchButton');

    const pexelsStatus =
        document.getElementById('pexelsStatus');

    const pexelsResults =
        document.getElementById('pexelsResults');
    const destinationPhotoPagination = document.getElementById('destinationPhotoPagination');
    const destinationPhotoPrevious = document.getElementById('destinationPhotoPrevious');
    const destinationPhotoNext = document.getElementById('destinationPhotoNext');
    const destinationPhotoPageText = document.getElementById('destinationPhotoPageText');

    let currentPexelsPhotos = [];
    let currentPexelsPage = 1;
    let currentPexelsQuery = '';
    let hasNextPexelsPage = false;

    function openPexelsModal(mode) {
        activeMode =
            mode === 'banner'
                ? 'banner'
                : 'card';

        pexelsModal.classList.add('open');
        pexelsModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pexels-modal-open');

        pexelsStatus.hidden = true;

        setTimeout(function () {
            pexelsSearchInput?.focus();
        }, 50);
    }

    function closePexelsModal() {
        pexelsModal.classList.remove('open');
        pexelsModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pexels-modal-open');
    }

    document.querySelectorAll('.open-pexels-picker')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                openPexelsModal(
                    this.dataset.pexelsMode || 'card'
                );
            });
        });

    document.querySelectorAll('[data-close-pexels]')
        .forEach(function (button) {
            button.addEventListener(
                'click',
                closePexelsModal
            );
        });

    function showPexelsStatus(message, error) {
        pexelsStatus.textContent = message;
        pexelsStatus.hidden = !message;
        pexelsStatus.classList.toggle(
            'error',
            Boolean(error)
        );
    }

    async function postDestinationImage(data) {
        const body =
            new URLSearchParams();

        body.set('csrf_token', csrfToken);

        Object.entries(data).forEach(function ([key, value]) {
            body.set(key, String(value ?? ''));
        });

        const response =
            await fetch(
                window.location.href,
                {
                    method:'POST',
                    credentials:'same-origin',
                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With':
                            'XMLHttpRequest'
                    },
                    body:body.toString()
                }
            );

        let result;

        try {
            result = await response.json();
        } catch (error) {
            throw new Error(
                'The server returned an invalid response.'
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(
                result.message
                || 'The image request failed.'
            );
        }

        return result.data;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderPexelsPhotos(photos) {
        currentPexelsPhotos =
            Array.isArray(photos)
                ? photos
                : [];

        if (!currentPexelsPhotos.length) {
            pexelsResults.innerHTML =
                '<div class="pexels-empty">'
                + '<i class="fa-regular fa-face-frown"></i>'
                + 'No royalty-free photos were found.'
                + '</div>';

            return;
        }

        pexelsResults.innerHTML =
            currentPexelsPhotos
                .map(function (photo, index) {

                    const provider = String(photo.provider || 'pexels').toLowerCase();
                    const title =
                        photo.alt
                        || (provider === 'unsplash' ? 'Unsplash Destination Photo' : 'Pexels Destination Photo');

                    return `
                        <article
                            class="pexels-photo-card"
                            data-provider="${provider}"
                            data-photo-index="${index}"
                            role="button"
                            tabindex="0"
                            aria-label="Select ${escapeHtml(title)}"
                        >

                            <div class="pexels-photo-image">
                                <span class="free-photo-provider-badge ${provider}">${escapeHtml(photo.provider_label || (provider === 'unsplash' ? 'Unsplash' : 'Pexels'))}</span>
                                <img
                                    src="${escapeHtml(photo.preview)}"
                                    alt="${escapeHtml(title)}"
                                    loading="lazy"
                                >
                            </div>

                            <div class="pexels-photo-credit">
                                Photo by ${escapeHtml(photo.photographer)} / ${escapeHtml(photo.provider_label || ((photo.provider || '').toLowerCase() === 'unsplash' ? 'Unsplash' : 'Pexels'))}
                            </div>

                            <div class="pexels-photo-body">

                                <div class="pexels-photo-name">
                                    ${escapeHtml(title)}
                                </div>

                                <button
                                    type="button"
                                    class="pexels-import-button"
                                    data-photo-index="${index}"
                                >
                                    <i class="fa-solid fa-cloud-arrow-down"></i>
                                    Select &amp; Use Photo
                                </button>

                            </div>

                        </article>
                    `;
                })
                .join('');
    }

    async function searchPexels(page = 1) {
        const query = pexelsSearchInput.value.trim();

        if (!query) {
            showPexelsStatus('Please enter a photo search keyword.', true);
            pexelsSearchInput.focus();
            return;
        }

        currentPexelsQuery = query;
        currentPexelsPage = Math.max(1, Number(page || 1));
        if (destinationPhotoPagination) destinationPhotoPagination.hidden = true;
        showPexelsStatus('Searching Pexels + Unsplash photos...', false);

        try {
            const data = await postDestinationImage({
                action:'destination_pexels_search',
                query:currentPexelsQuery,
                page:currentPexelsPage
            });

            showPexelsStatus('', false);
            currentPexelsPage = Number(data.page || currentPexelsPage);
            hasNextPexelsPage = Boolean(data.next_page);
            renderPexelsPhotos(data.photos || []);

            if (destinationPhotoPrevious) destinationPhotoPrevious.disabled = currentPexelsPage <= 1;
            if (destinationPhotoNext) destinationPhotoNext.disabled = !hasNextPexelsPage;
            if (destinationPhotoPageText) destinationPhotoPageText.textContent =
                'Page ' + currentPexelsPage + ' • ' + Number(data.total_results || (data.photos || []).length) + ' result(s)';
            if (destinationPhotoPagination) destinationPhotoPagination.hidden = false;
        } catch (error) {
            showPexelsStatus(error.message, true);
        }
    }

    pexelsSearchButton?.addEventListener('click', function(){ searchPexels(1); });

    pexelsSearchInput?.addEventListener(
        'keydown',
        function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchPexels(1);
            }
        }
    );

    destinationPhotoPrevious?.addEventListener('click', function(){
        if (currentPexelsPage > 1) searchPexels(currentPexelsPage - 1);
    });
    destinationPhotoNext?.addEventListener('click', function(){
        if (hasNextPexelsPage) searchPexels(currentPexelsPage + 1);
    });

    async function importSelectedPexelsPhoto(
        sourceElement
    ) {
        if (!sourceElement) {
            return;
        }

        const card =
            sourceElement.closest(
                '.pexels-photo-card'
            )
            || (
                sourceElement.classList
                && sourceElement.classList.contains(
                    'pexels-photo-card'
                )
                    ? sourceElement
                    : null
            );

        if (
            !card
            || card.classList.contains(
                'is-importing'
            )
        ) {
            return;
        }

        const button =
            card.querySelector(
                '.pexels-import-button'
            );

        const photoIndex =
            Number(
                card.dataset.photoIndex
            );

        const photo =
            Number.isInteger(photoIndex)
            && photoIndex >= 0
                ? currentPexelsPhotos[photoIndex]
                : null;

        if (!photo) {
            alert(
                'Invalid selected Pexels photo.'
            );
            return;
        }

        card.classList.add(
            'is-importing'
        );

        if (button) {
            button.disabled = true;
            button.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i>'
                + (((photo.provider || '').toLowerCase() === 'unsplash') ? ' Selecting Unsplash...' : ' Importing & resizing...');
        }

        try {
            const imported =
                await postDestinationImage({
                    action:
                        'destination_pexels_import',
                    photo:
                        JSON.stringify(photo)
                });

            const fileName =
                imported.name
                + (
                    imported.size_kb
                        ? ' (' + imported.size_kb + ' KB)'
                        : ''
                );

            selectLibraryImage(
                imported.image,
                imported.url,
                fileName
            );

            closePexelsModal();

        } catch (error) {
            alert(error.message);

            card.classList.remove(
                'is-importing'
            );

            if (button) {
                button.disabled = false;
                button.innerHTML =
                    '<i class="fa-solid fa-cloud-arrow-down"></i>'
                    + ' Select & Use Photo';
            }
        }
    }


    /*
    | Click anywhere on any Pexels card.
    | This works for every row, including rows whose action button
    | is below the currently visible part of the modal.
    */
    pexelsResults?.addEventListener(
        'click',
        function (event) {
            const card =
                event.target.closest(
                    '.pexels-photo-card'
                );

            if (
                !card
                || !pexelsResults.contains(card)
            ) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            importSelectedPexelsPhoto(
                card
            );
        }
    );


    pexelsResults?.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key !== 'Enter'
                && event.key !== ' '
            ) {
                return;
            }

            const card =
                event.target.closest(
                    '.pexels-photo-card'
                );

            if (!card) {
                return;
            }

            event.preventDefault();

            importSelectedPexelsPhoto(
                card
            );
        }
    );


    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (mediaModal.classList.contains('open')) {
                closeMediaModal();
            }

            if (pexelsModal.classList.contains('open')) {
                closePexelsModal();
            }
        }
    );

});
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


<script>
(function(){
    const country = document.getElementById('destinationGeoCountry');
    const state = document.getElementById('destinationGeoState');
    const city = document.getElementById('destinationGeoCity');
    const name = document.getElementById('destinationGeoName');
    const useState = document.getElementById('destinationUseState');
    const path = document.getElementById('destinationGeoPath');

    if(!country || !state || !city || !name) return;

    const endpoint = 'admin-destinations.php';
    const currentCountry = (country.dataset.current || '').trim();
    const existingName = (name.value || '').trim();

    function escapeText(value){
        return String(value ?? '');
    }

    function setOptions(select, values, placeholder){
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);

        values.forEach(value=>{
            const option=document.createElement('option');
            option.value=value;
            option.textContent=value;
            select.appendChild(option);
        });
    }

    async function geoGet(action, params={}){
        const url=new URL(endpoint, window.location.href);
        url.searchParams.set('geo_ajax',action);

        Object.entries(params).forEach(([key,value])=>{
            url.searchParams.set(key,value);
        });

        const response=await fetch(url.toString(),{
            headers:{'Accept':'application/json'}
        });

        const data=await response.json().catch(()=>null);

        if(!response.ok || !data || data.success!==true){
            throw new Error(data?.message || 'Unable to load location information.');
        }

        return Array.isArray(data.data) ? data.data : [];
    }

    function updatePath(){
        const parts=[
            country.value,
            state.value,
            city.value
        ].filter(Boolean);

        path.innerHTML='<i class="fa-solid fa-route"></i> '
            +(parts.length ? parts.map(escapeText).join(' → ') : 'Select a country to begin.');
    }

    async function loadCountries(){
        country.disabled=true;
        setOptions(country,[],'Loading countries...');

        try{
            const countries=await geoGet('countries');
            setOptions(country,countries,'Select Country');
            country.disabled=false;

            if(currentCountry && countries.includes(currentCountry)){
                country.value=currentCountry;
                await loadStates();

                // Existing destination records may not have state stored.
                // Keep the current destination name intact until user selects a new state/city.
                if(existingName){
                    name.value=existingName;
                }
            }
        }catch(error){
            setOptions(country,[],'Unable to load countries');
            path.textContent=error.message;
        }
    }

    async function loadStates(){
        setOptions(state,[],'Loading states...');
        setOptions(city,[],'Select state first');
        state.disabled=true;
        city.disabled=true;
        useState.disabled=true;

        if(!country.value){
            setOptions(state,[],'Select country first');
            updatePath();
            return;
        }

        try{
            const states=await geoGet('states',{country:country.value});
            setOptions(
                state,
                states,
                states.length ? 'Select State / Region' : 'No state/region returned'
            );
            state.disabled=states.length===0;

            // When editing a state-level destination such as Odisha/Meghalaya,
            // restore the state automatically and load its city list.
            if (
                existingName &&
                country.value === currentCountry &&
                states.includes(existingName)
            ) {
                state.value=existingName;
                await loadCities();
                name.value=existingName;
            }

            updatePath();
        }catch(error){
            setOptions(state,[],'Unable to load states');
            path.textContent=error.message;
        }
    }

    async function loadCities(){
        setOptions(city,[],'Loading district/city...');
        city.disabled=true;

        if(!state.value){
            setOptions(city,[],'Select state first');
            useState.disabled=true;
            updatePath();
            return;
        }

        useState.disabled=false;

        try{
            const cities=await geoGet('cities',{
                country:country.value,
                state:state.value
            });

            setOptions(
                city,
                cities,
                cities.length ? 'Select District / City' : 'No city saved — use State / Region'
            );

            city.disabled=cities.length===0;
            updatePath();
        }catch(error){
            setOptions(city,[],'Unable to load city list — use State / Region');
            useState.disabled=false;
            updatePath();
        }
    }

    country.addEventListener('change',()=>{
        name.value='';
        loadStates();
    });

    state.addEventListener('change',()=>{
        name.value='';
        loadCities();
    });

    city.addEventListener('change',()=>{
        if(city.value){
            name.value=city.value;
        }
        updatePath();
    });

    useState.addEventListener('click',()=>{
        if(state.value){
            name.value=state.value;
            city.value='';
            updatePath();
        }
    });

    loadCountries();
})();
</script>


<script id="travscope-destination-one-page-editor">
document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('destination-editor-mode')) {
        return;
    }

    const form = document.querySelector('#destination-form form');
    const grid = document.querySelector('#destination-form .form-grid');
    const tabs = Array.from(
        document.querySelectorAll('.destination-editor-tab')
    );

    if (!form || !grid || !tabs.length) {
        return;
    }

    const groups = Array.from(
        grid.querySelectorAll(':scope > .form-group')
    );

    function groupText(group) {
        return (group.textContent || '').toLowerCase();
    }

    groups.forEach(function (group) {
        const text = groupText(group);
        let section = 'basic';

        if (
            text.includes('short description')
            || text.includes('full destination description')
        ) {
            section = 'content';
        } else if (
            text.includes('destination card image')
            || text.includes('destination banner image')
        ) {
            section = 'images';
        } else if (
            text.includes('sort order')
            || text.includes('display options')
            || (
                text.includes('status')
                && !text.includes('smart destination location')
            )
        ) {
            section = 'settings';
        }

        group.dataset.destinationSection = section;
    });

    function showSection(section) {
        tabs.forEach(function (tab) {
            tab.classList.toggle(
                'active',
                tab.dataset.destinationTab === section
            );
        });

        groups.forEach(function (group) {
            group.classList.toggle(
                'destination-tab-active',
                group.dataset.destinationSection === section
            );
        });

        const activeGroup =
            groups.find(
                group =>
                    group.dataset.destinationSection === section
            );

        if (activeGroup) {
            const scrollBox =
                document.querySelector(
                    '#destination-form .form-grid'
                );

            if (scrollBox) {
                scrollBox.scrollTop = 0;
            }
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            showSection(
                tab.dataset.destinationTab || 'basic'
            );
        });
    });

    showSection('basic');
});
</script>


<script src="assets/travscope-pro-admin.js?v=20260916-v2" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
<script>
(function () {
    const directory = document.querySelector('.destination-directory');
    if (!directory) return;
    directory.querySelectorAll('.destination-thumb img').forEach(function (img) {
        function imageFailed() {
            img.closest('.destination-thumb').dataset.imageState = 'failed';
            const row = img.closest('.destination-row');
            const warning = row && row.querySelector('.destination-image-error');
            if (warning) warning.hidden = false;
        }
        img.addEventListener('error', imageFailed);
        if (img.complete && img.naturalWidth === 0) imageFailed();
    });
    const menus = Array.from(directory.querySelectorAll('.destination-more-menu'));
    function closeMenus(except) {
        menus.forEach(function (menu) { if (menu !== except) menu.open = false; });
    }
    function positionMenu(menu) {
        if (!menu.open) return;
        const summary = menu.querySelector('summary');
        const panel = menu.querySelector('.destination-more-panel');
        const box = summary.getBoundingClientRect();
        if (box.bottom <= 0 || box.top >= window.innerHeight || box.right <= 0 || box.left >= window.innerWidth) {
            menu.open = false;
            return;
        }
        const left = Math.max(12, Math.min(box.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - 12));
        const below = box.bottom + 6;
        const top = below + panel.offsetHeight <= window.innerHeight - 12
            ? below : Math.max(12, box.top - panel.offsetHeight - 6);
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }
    menus.forEach(function (menu) {
        menu.addEventListener('toggle', function () {
            if (!menu.open) return;
            closeMenus(menu);
            positionMenu(menu);
        });
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.destination-more-menu')) closeMenus();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const openMenu = menus.find(function (menu) { return menu.open; });
        if (openMenu) {
            openMenu.open = false;
            openMenu.querySelector('summary').focus();
            event.preventDefault();
        }
    });
    window.addEventListener('resize', function () { closeMenus(); });
    window.addEventListener('scroll', function (event) {
        if (!event.target.closest || !event.target.closest('.destination-more-panel')) menus.forEach(positionMenu);
    }, true);
    directory.querySelectorAll('.destination-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const name = form.dataset.destinationName || 'this destination';
            if (!window.confirm('Delete “' + name + '”? This cannot be undone.')) event.preventDefault();
        });
    });
})();
</script>
</body>
</html>
