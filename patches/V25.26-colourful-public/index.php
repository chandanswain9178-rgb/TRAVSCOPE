<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/package-action-mode.php';
require_once __DIR__ . '/package-currency-functions.php';
require_once __DIR__ . '/package-type-assignments.php';

/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM - DATABASE DRIVEN HOME PAGE
| PACKAGE CATEGORY SEARCH + ALL ACTIVE DESTINATIONS VERSION: 2026-08-08-R4
|--------------------------------------------------------------------------
| Admin changes to packages, destinations, reviews and website settings
| appear automatically here.
|--------------------------------------------------------------------------
*/

$siteName       = getSetting($pdo, 'site_name', 'TRAVSCOPE.COM');
$siteTagline    = getSetting($pdo, 'site_tagline', 'Explore More. Travel Better.');
$siteLogo       = trim((string)getSetting($pdo, 'site_logo', ''));
$siteLogoUrl    = $siteLogo !== ''
    ? (preg_match('#^https?://#i', $siteLogo)
        ? $siteLogo
        : rtrim((string)BASE_URL, '/') . '/' . ltrim($siteLogo, '/'))
    : '';
$currencySymbol = getSetting($pdo, 'currency_symbol', '₹');
$supportEmail   = getSetting($pdo, 'support_email', 'support@travscope.com');
$supportMobile  = getSetting($pdo, 'support_mobile', '');
$whatsappNumber = preg_replace('/\D+/', '', getSetting($pdo, 'whatsapp_number', ''));
$officeAddress  = getSetting($pdo, 'office_address', 'Odisha, India');
$businessHours  = getSetting($pdo, 'business_hours', 'Travel Assistance Available');
$whatsappUrl    = trim((string)getSetting($pdo, 'whatsapp_url', ''));
$facebookUrl    = trim((string)getSetting($pdo, 'facebook_url', ''));
$instagramUrl   = trim((string)getSetting($pdo, 'instagram_url', ''));
$youtubeUrl     = trim((string)getSetting($pdo, 'youtube_url', ''));
$twitterUrl     = trim((string)getSetting($pdo, 'x_url', ''));


$currentYear    = date('Y');

/*
|--------------------------------------------------------------------------
| PRIVACY PAGE VERSION
|--------------------------------------------------------------------------
| Add the latest Privacy Policy update timestamp to the public link so
| browsers/CDNs do not keep reopening an older cached Privacy page.
*/
$privacyPageVersion = '';

try {
    $stmt = $pdo->prepare("
        SELECT updated_at
        FROM website_pages
        WHERE page_key = 'privacy'
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute();

    $privacyUpdatedAt = (string)($stmt->fetchColumn() ?: '');

    if ($privacyUpdatedAt !== '') {
        $privacyPageVersion = (string)strtotime($privacyUpdatedAt);
    }
} catch (Throwable $e) {
    $privacyPageVersion = '';
}

$privacyPageUrl =
    'pages.php?page=privacy'
    . ($privacyPageVersion !== ''
        ? '&v=' . rawurlencode($privacyPageVersion)
        : '');




$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;

if ($isLoggedIn) {
    try {
        $currentUser = currentUser($pdo);
        if (!$currentUser || strtolower((string)($currentUser['status'] ?? '')) !== 'active') {
            $currentUser = null;
            $isLoggedIn = false;
        }
    } catch (Throwable $e) {
        $currentUser = null;
        $isLoggedIn = false;
    }
}

function homeImage(?string $image, string $fallback = ''): string {
    $image = trim((string)$image);
    if ($image === '') {
        return $fallback ?: 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1200&q=85';
    }
    if (preg_match('#^https?://#i', $image)) return $image;
    return BASE_URL . ltrim($image, '/');
}

function homeMoney($amount, string $symbol): string {
    return $symbol . number_format((float)$amount, 0);
}

function homeType(string $type): string {
    return ucwords(str_replace('_', ' ', $type));
}

function packagePrice(array $p): float {
    $sale = (float)($p['sale_price'] ?? 0);
    $regular = (float)($p['regular_price'] ?? 0);
    return $sale > 0 ? $sale : $regular;
}

function guestAction(string $target): string {
    return 'register.php?redirect=' . urlencode($target);
}


function homeTableHasColumn(
    PDO $pdo,
    string $table,
    string $column
): bool {
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");

        $stmt->execute([
            $table,
            $column
        ]);

        $cache[$key] =
            (int)$stmt->fetchColumn() > 0;

    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function homeFirstExistingColumn(
    PDO $pdo,
    string $table,
    array $columns
): string {
    foreach ($columns as $column) {
        if (homeTableHasColumn($pdo, $table, $column)) {
            return $column;
        }
    }

    return '';
}

function homeReviewAvatar(
    ?string $image,
    string $name
): array {
    $image = trim((string)$image);
    $name = trim($name);

    if ($name === '') {
        $name = 'Traveller';
    }

    $initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1))
        : strtoupper(substr($name, 0, 1));

    if ($image !== '') {
        return [
            'type' => 'image',
            'value' => homeImage($image),
            'initial' => $initial
        ];
    }

    return [
        'type' => 'initial',
        'value' => '',
        'initial' => $initial
    ];
}


/* HOMEPAGE SLIDES */
$homepageSlides = [];
try {
    $stmt = $pdo->query("
        SELECT *
        FROM homepage_slides
        WHERE status='active'
        ORDER BY sort_order ASC, id ASC
    ");
    $homepageSlides = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Home slides: ' . $e->getMessage());
}

/* ALL ACTIVE DESTINATIONS FOR SEARCH */
$searchDestinations = [];

try {
    $stmt = $pdo->query("
        SELECT
            id,
            name,
            slug,
            country,
            continent,
            image,
            banner_image,
            featured,
            sort_order
        FROM destinations
        WHERE status = 'active'
        ORDER BY
            name ASC,
            country ASC,
            id ASC
    ");

    $searchDestinations =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'Home search destinations: '
        . $e->getMessage()
    );

    $searchDestinations = [];
}


/* DESTINATION CARDS SHOWN ON HOME PAGE */
$destinations = [];

try {
    $stmt = $pdo->query("
        SELECT
            id,
            name,
            slug,
            country,
            continent,
            image,
            banner_image,
            featured,
            sort_order
        FROM destinations
        WHERE status = 'active'
        ORDER BY
            featured DESC,
            sort_order ASC,
            id DESC
        LIMIT 8
    ");

    $destinations =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'Home destination cards: '
        . $e->getMessage()
    );

    $destinations = [];
}

/* COMMON PACKAGE SELECT */
$packageSelect = "
    SELECT p.id,p.destination_id,p.title,p.slug,p.package_code,p.short_description,
           p.duration_days,p.duration_nights,p.regular_price,p.sale_price,p.currency,
           p.package_type,p.main_image,p.featured,p.bestseller,p.customer_action_mode,p.views,p.created_at,
           d.name AS destination_name,d.country AS destination_country,d.slug AS destination_slug
    FROM packages p
    LEFT JOIN destinations d ON d.id=p.destination_id
";

/* FEATURED / RECOMMENDED */
$featuredPackages = [];
try {
    $stmt = $pdo->query($packageSelect . "
        WHERE p.status='active'
        ORDER BY p.featured DESC,p.bestseller DESC,p.views DESC,p.id DESC
        LIMIT 6
    ");
    $featuredPackages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Home featured packages: '.$e->getMessage());
}

/* NEW / UPCOMING */
$newPackages = [];
try {
    $stmt = $pdo->query($packageSelect . "
        WHERE p.status='active'
        ORDER BY p.created_at DESC,p.id DESC
        LIMIT 4
    ");
    $newPackages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Home new packages: '.$e->getMessage());
}

/* BESTSELLERS */
$bestsellers = [];
try {
    $stmt = $pdo->query($packageSelect . "
        WHERE p.status='active' AND p.bestseller=1
        ORDER BY p.views DESC,p.id DESC
        LIMIT 4
    ");
    $bestsellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Home bestsellers: '.$e->getMessage());
}

/* TRAVEL TYPES - ADMIN MANAGED */
$packageTypes = [];

try {
    $homeCategoryJoin = tsPackageTypeAssignmentsReady($pdo)
        ? "p.status = 'active' AND (p.package_type = pt.slug OR EXISTS (SELECT 1 FROM package_type_assignments pta WHERE pta.package_id = p.id AND pta.type_slug = pt.slug))"
        : "p.status = 'active' AND p.package_type = pt.slug";
    $stmt = $pdo->query("
        SELECT
            pt.id,
            pt.name,
            pt.slug,
            COUNT(DISTINCT p.id) AS total
        FROM package_types pt
        LEFT JOIN packages p ON " . $homeCategoryJoin . "
        WHERE pt.status = 'active'
        GROUP BY pt.id, pt.name, pt.slug, pt.sort_order
        ORDER BY pt.sort_order ASC, pt.name ASC, pt.id ASC
    ");
    $packageTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Home package types: ' . $e->getMessage());
}

/* APPROVED CUSTOMER REVIEWS */
$reviews = [];

try {
    $reviewUserColumn =
        homeFirstExistingColumn(
            $pdo,
            'reviews',
            [
                'user_id',
                'customer_id'
            ]
        );

    $userPhotoColumn =
        homeFirstExistingColumn(
            $pdo,
            'users',
            [
                'profile_image',
                'profile_photo',
                'avatar',
                'photo',
                'image'
            ]
        );

    $reviewPhotoColumn =
        homeFirstExistingColumn(
            $pdo,
            'reviews',
            [
                'customer_photo',
                'profile_image',
                'profile_photo',
                'avatar',
                'photo'
            ]
        );

    $adminReplyColumn =
        homeFirstExistingColumn(
            $pdo,
            'reviews',
            [
                'admin_reply',
                'admin_response',
                'reply_text',
                'reply',
                'response'
            ]
        );

    $userNameColumn =
        homeFirstExistingColumn(
            $pdo,
            'users',
            [
                'full_name',
                'name',
                'username'
            ]
        );

    $userJoin = '';
    $userNameSelect = "NULL AS user_profile_name";
    $userPhotoSelect = "NULL AS user_profile_photo";

    if ($reviewUserColumn !== '') {
        $userJoin = "
            LEFT JOIN users u
                ON u.id = r.{$reviewUserColumn}
        ";

        if ($userNameColumn !== '') {
            $userNameSelect =
                "u.{$userNameColumn} AS user_profile_name";
        }

        if ($userPhotoColumn !== '') {
            $userPhotoSelect =
                "u.{$userPhotoColumn} AS user_profile_photo";
        }
    }

    $reviewPhotoSelect =
        $reviewPhotoColumn !== ''
            ? "r.{$reviewPhotoColumn} AS review_profile_photo"
            : "NULL AS review_profile_photo";

    $adminReplySelect =
        $adminReplyColumn !== ''
            ? "r.{$adminReplyColumn} AS admin_reply"
            : "NULL AS admin_reply";

    $sql = "
        SELECT
            r.customer_name,
            r.rating,
            COALESCE(
                NULLIF(r.review_text, ''),
                r.review
            ) AS review_text,
            r.created_at,
            p.title AS package_title,
            {$userNameSelect},
            {$userPhotoSelect},
            {$reviewPhotoSelect},
            {$adminReplySelect}

        FROM reviews r

        LEFT JOIN packages p
            ON p.id = r.package_id

        {$userJoin}

        WHERE r.status = 'approved'
          AND COALESCE(
                NULLIF(r.review_text, ''),
                r.review
              ) IS NOT NULL

        ORDER BY
            r.featured DESC,
            r.id DESC

        LIMIT 3
    ";

    $stmt = $pdo->query($sql);

    $reviews =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'Home reviews: '
        . $e->getMessage()
    );

    $reviews = [];
}

/* LATEST TRAVEL STORIES */
$latestStories = [];

try {
    $stmt = $pdo->query("
        SELECT
            b.id,
            b.title,
            b.slug,
            b.excerpt,
            b.content,
            b.main_image,
            b.image_alt,
            b.location_name,
            b.city,
            b.state_name,
            b.country,
            b.published_at,
            b.created_at,
            c.name AS category_name,
            d.name AS destination_name
        FROM blogs b
        LEFT JOIN blog_categories c
            ON c.id = b.category_id
        LEFT JOIN destinations d
            ON d.id = b.destination_id
        WHERE
            b.status = 'published'
            AND (
                b.published_at IS NULL
                OR b.published_at <= NOW()
            )
        ORDER BY
            b.featured DESC,
            COALESCE(b.published_at, b.created_at) DESC,
            b.id DESC
        LIMIT 3
    ");

    $latestStories =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    /*
    | The homepage must continue working before blogs.sql is installed.
    */
    $latestStories = [];
}

/* LIVE STATS */
$activePackageCount = 0;
$activeDestinationCount = 0;
$approvedReviewCount = 0;
try { $activePackageCount = (int)$pdo->query("SELECT COUNT(*) FROM packages WHERE status='active'")->fetchColumn(); } catch(Throwable $e){}
try { $activeDestinationCount = (int)$pdo->query("SELECT COUNT(*) FROM destinations WHERE status='active'")->fetchColumn(); } catch(Throwable $e){}
try { $approvedReviewCount = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status='approved'")->fetchColumn(); } catch(Throwable $e){}

$services = [
    ['fa-plane-departure','Flight Planning','Plan domestic and international journeys with complete travel assistance.'],
    ['fa-hotel','Stays & Resorts','Discover travel packages with carefully selected accommodation options.'],
    ['fa-passport','Travel Assistance','Get help planning documentation and important pre-travel requirements.'],
    ['fa-umbrella-beach','Holiday Packages','Choose holidays for couples, families, groups and solo travellers.'],
    ['fa-car','Transfers & Tours','Plan transfers, local sightseeing and destination experiences.'],
    ['fa-headset','Customer Support','Track bookings, enquiries and travel assistance from your account.']
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
<meta name="theme-color" content="#07192d">
<meta name="description" content="<?=e($siteName)?> - Explore active destinations and holiday packages updated directly by our travel team.">
<title><?=e($siteName)?> | <?=e($siteTagline)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--navy:#07192d;--navy2:#0b2c4d;--blue:#0b74ff;--cyan:#00b8ff;--green:#0aa66a;--orange:#f29b25;--bg:#f5f8fc;--white:#fff;--text:#172033;--muted:#748196;--border:#e4eaf1;--yellow:#ffb703;--shadow:0 16px 45px rgba(7,25,45,.09)}
*{box-sizing:border-box;margin:0;padding:0}html{scroll-behavior:smooth}body{font-family:'DM Sans',sans-serif;color:var(--text);background:#fff;line-height:1.6}a{text-decoration:none;color:inherit}img{display:block;width:100%}button,input,select{font:inherit}.container{width:min(1180px,calc(100% - 36px));margin:auto}.section{padding:82px 0}.soft{background:var(--bg)}h1,h2,h3,h4{font-family:'Manrope',sans-serif}.topbar{background:var(--navy);color:#b9c9d8;font-size:12px}.topbar .container{min-height:38px;display:flex;justify-content:space-between;align-items:center;gap:20px}.topgroup{display:flex;gap:20px}.topgroup i{color:#67b4ff;margin-right:5px}
.nav{position:sticky;top:0;z-index:1000;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);border-bottom:1px solid var(--border)}.navin{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px}.logo{display:flex;align-items:center;gap:10px;font:800 21px 'Manrope';color:var(--navy)}.logoicon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--blue),var(--cyan))}.logo span{color:var(--blue)}.links{display:flex;gap:27px;align-items:center}.links a{font-size:13px;font-weight:700;color:#405067}.links a:hover{color:var(--blue)}.actions{display:flex;align-items:center;gap:9px}.btn{min-height:45px;padding:0 17px;border:0;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;gap:7px;font-size:11px;font-weight:800;cursor:pointer}.btn-blue{background:var(--blue);color:#fff}.btn-light{background:#eef5fd;color:var(--navy)}.btn-outline{border:1px solid rgba(255,255,255,.4);color:#fff;background:rgba(255,255,255,.08)}.menu{display:none;width:43px;height:43px;border:0;border-radius:10px;background:#edf3f9;color:var(--navy)}
.hero{min-height:630px;position:relative;overflow:hidden;background:#07192d}.hero-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .8s ease;display:flex;align-items:center;background-position:center;background-size:cover}.hero-slide:after{content:"";position:absolute;inset:0;background:transparent;pointer-events:none}.hero-slide.active{opacity:1;visibility:visible}.hero-slide .container{position:relative;z-index:2}.hero-content{max-width:790px;padding:75px 0 120px;color:#fff}.badge{display:inline-flex;padding:7px 12px;border:1px solid rgba(255,255,255,.22);border-radius:30px;background:rgba(255,255,255,.08);font-size:11px;font-weight:800}.hero h1{font-size:clamp(42px,6vw,70px);line-height:1.06;letter-spacing:-2px;margin-top:18px}.hero h1 span{color:#6ab7ff}.hero p{max-width:640px;margin:20px 0 27px;color:#d1dce7;font-size:17px}.hero-buttons{display:flex;gap:10px;flex-wrap:wrap}.trust{display:flex;gap:23px;flex-wrap:wrap;margin-top:28px;color:#d8e5f0;font-size:12px}.trust i{color:#5de0ad;margin-right:5px}.hero-dots{position:absolute;z-index:5;left:50%;bottom:82px;transform:translateX(-50%);display:flex;gap:8px}.hero-dot{width:9px;height:9px;border:0;border-radius:50%;background:rgba(255,255,255,.45);padding:0}.hero-dot.active{width:28px;border-radius:10px;background:#fff}
.searchwrap{position:relative;z-index:10;margin-top:-61px}.searchbox{padding:22px;border-radius:18px;background:#fff;box-shadow:0 25px 65px rgba(7,25,45,.17)}.searchgrid{display:grid;grid-template-columns:1.45fr 1fr 1fr 1fr auto;gap:11px;align-items:end}.field label{display:block;margin-bottom:6px;color:#7a8798;font-size:9px;font-weight:800}.control{height:52px;padding:0 13px;border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;gap:9px}.control i{color:var(--blue)}.control input,.control select{width:100%;border:0;outline:0;background:transparent;color:#344257;font-size:11px;font-weight:600}
.heading{max-width:720px;margin:0 auto 38px;text-align:center}.eyebrow{color:var(--blue);font-size:10px;font-weight:800;letter-spacing:1.3px;text-transform:uppercase}.heading h2{margin-top:8px;color:var(--navy);font-size:clamp(27px,4vw,40px)}.heading p{margin-top:9px;color:var(--muted);font-size:13px}
.destgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.dest{height:285px;position:relative;overflow:hidden;border-radius:17px;background:#dbe4ee;box-shadow:var(--shadow)}.dest img{height:100%;object-fit:cover;transition:.4s}.dest:hover img{transform:scale(1.06)}.dest:after{content:"";position:absolute;inset:0;background:transparent;pointer-events:none}.destbody{position:absolute;z-index:2;left:18px;right:18px;bottom:17px;color:#fff}.destbody strong{font:800 18px 'Manrope'}.destbody span{display:block;color:#d4e0eb;font-size:10px;margin-top:3px}.destcount{position:absolute;z-index:2;top:13px;left:13px;padding:5px 8px;border-radius:20px;background:rgba(255,255,255,.94);color:var(--navy);font-size:8px;font-weight:800}
.typebar{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:24px}.typebar a{padding:8px 12px;border-radius:20px;background:#fff;border:1px solid var(--border);color:#536176;font-size:9px;font-weight:800}.typebar a:hover{border-color:var(--blue);color:var(--blue)}
.pkggrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}.pkg{border:1px solid var(--border);border-radius:17px;background:#fff;overflow:hidden;box-shadow:var(--shadow);transition:.25s}.pkg:hover{transform:translateY(-5px)}.pkgimg{height:220px;position:relative;overflow:hidden;background:#e8eef5}.pkgimg img{height:100%;object-fit:cover;transition:.4s}.pkg:hover .pkgimg img{transform:scale(1.04)}.tag{position:absolute;top:13px;left:13px;padding:6px 9px;border-radius:20px;background:#fff;color:var(--navy);font-size:8px;font-weight:800}.tag.best{background:#fff3dc;color:#a76100}.pkgbody{padding:18px}.loc{color:#748397;font-size:9px}.pkg h3{margin-top:6px;color:var(--navy);font-size:16px}.code{display:inline-block;margin-top:7px;padding:4px 6px;border-radius:5px;background:#edf5ff;color:#0b67cf;font-size:7px;font-weight:800}.desc{margin-top:9px;color:#738094;font-size:10px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.meta{display:flex;gap:13px;margin-top:12px;color:#647286;font-size:9px}.pkgfoot{margin-top:15px;padding-top:14px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}.price small{display:block;color:#8c98a8;font-size:7px}.price strong{color:var(--blue);font:800 18px 'Manrope'}.regular{margin-left:4px;color:#9ca6b3;font-size:8px;text-decoration:line-through}.cardactions{display:flex;gap:6px}.mini{min-height:36px;padding:0 10px;border-radius:8px;font-size:8px}
.newgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}.newcard{border:1px solid var(--border);border-radius:14px;overflow:hidden;background:#fff}.newimg{height:145px}.newimg img{height:100%;object-fit:cover}.newbody{padding:13px}.newbody h3{font-size:12px;color:var(--navy)}.newbody p{margin-top:5px;color:var(--muted);font-size:8px}.newprice{display:flex;justify-content:space-between;align-items:center;margin-top:10px}.newprice strong{color:var(--blue);font-size:11px}
.services{display:grid;grid-template-columns:repeat(3,1fr);gap:17px}.service{padding:24px;border:1px solid var(--border);border-radius:15px}.service i{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;background:#eaf4ff;color:var(--blue);font-size:18px}.service h3{margin-top:15px;font-size:14px;color:var(--navy)}.service p{margin-top:7px;color:var(--muted);font-size:10px}
.stats{background:var(--navy);color:#fff}.statgrid{display:grid;grid-template-columns:repeat(4,1fr);text-align:center}.stat{padding:34px 15px;border-right:1px solid rgba(255,255,255,.09)}.stat:last-child{border:0}.stat strong{display:block;color:#68b6ff;font:800 32px 'Manrope'}.stat span{color:#aebfd0;font-size:10px}
.reviewgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:17px}
.review{padding:22px;border:1px solid var(--border);border-radius:15px;background:#fff;box-shadow:0 10px 30px rgba(7,25,45,.05)}
.review-head{display:flex;align-items:center;gap:11px}
.review-avatar{width:48px;height:48px;flex:0 0 48px;border-radius:50%;overflow:hidden;display:grid;place-items:center;background:linear-gradient(135deg,var(--blue),var(--cyan));color:#fff;font:800 18px 'Manrope',sans-serif;box-shadow:0 7px 18px rgba(11,116,255,.18)}
.review-avatar img{width:100%;height:100%;object-fit:cover}
.review-person{min-width:0;flex:1}
.review-person strong{display:block;overflow:hidden;color:var(--navy);font-size:14px;text-overflow:ellipsis;white-space:nowrap}
.review-person small{display:block;margin-top:2px;overflow:hidden;color:#8a96a5;font-size:12px;text-overflow:ellipsis;white-space:nowrap}
.stars{margin-top:10px;color:var(--yellow);font-size:12px}
.review-text{margin:12px 0 0;color:#59677a;font-size:14px;line-height:1.75}
.admin-reply{margin-top:15px;padding:12px 13px;border-left:3px solid var(--blue);border-radius:8px;background:#f1f7ff}
.admin-reply-title{display:flex;align-items:center;gap:6px;color:var(--blue);font-size:12px;font-weight:800}
.admin-reply p{margin:6px 0 0;color:#53657a;font-size:13px;line-height:1.65}

.cta{margin:25px auto 80px;padding:50px;border-radius:24px;color:#fff;background:linear-gradient(110deg,rgba(7,25,45,.97),rgba(11,116,255,.82)),url('https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=1800&q=85') center/cover}.cta h2{font-size:clamp(27px,4vw,42px)}.cta p{max-width:650px;margin:10px 0 20px;color:#d9e6f2;font-size:12px}
.footer{padding-top:55px;background:#051525;color:#9db0c2}.footgrid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1.2fr;gap:35px;padding-bottom:40px}.footer .logo{color:#fff}.footer p,.footer a{font-size:10px}.footer h4{color:#fff;margin-bottom:13px}.flinks{display:grid;gap:8px}.copyright{min-height:60px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;font-size:9px}
.mobilemenu{display:none;position:fixed;inset:76px 0 0;background:#fff;z-index:999;padding:20px}.mobilemenu.active{display:block}.mobilemenu a{display:block;padding:14px 5px;border-bottom:1px solid var(--border);font-weight:700;color:var(--navy)}
.whatsapp{position:fixed;right:20px;bottom:20px;z-index:900;width:54px;height:54px;border-radius:50%;display:grid;place-items:center;background:#25D366;color:#fff;font-size:24px;box-shadow:0 12px 30px rgba(0,0,0,.2)}


/* TRAVEL STORIES */
.storygrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.storycard{overflow:hidden;border:1px solid var(--border);border-radius:17px;background:#fff;box-shadow:var(--shadow);transition:.25s}
.storycard:hover{transform:translateY(-5px)}
.storyimg{height:220px;overflow:hidden;background:#e8eef5}
.storyimg img{height:100%;object-fit:cover;transition:.4s}
.storycard:hover .storyimg img{transform:scale(1.04)}
.storybody{padding:18px}
.storytag{display:inline-flex;padding:6px 9px;border-radius:20px;background:#edf5ff;color:var(--blue);font-size:11px;font-weight:800}
.storybody h3{margin-top:9px;color:var(--navy);font-size:19px;line-height:1.4}
.storymeta{display:flex;gap:12px;flex-wrap:wrap;margin-top:9px;color:#7b8798;font-size:12px}
.storydesc{margin-top:10px;color:#657386;font-size:14px;line-height:1.7;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.storylink{margin-top:14px;display:inline-flex;align-items:center;gap:6px;color:var(--blue);font-size:13px;font-weight:800}
.storysectionbottom{margin-top:28px;text-align:center}
@media(max-width:1050px){.storygrid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.storygrid{grid-template-columns:1fr}.storyimg{height:235px}}

/* PROFESSIONAL AUTOMATIC RESPONSIVE TYPOGRAPHY */
body{font-size:16px}.topbar{font-size:13px}.links a,.mobilemenu a{font-size:14px}.btn{font-size:13px}.badge{font-size:13px}
.hero h1{font-size:clamp(2.6rem,5.5vw,4.4rem)}.hero p{font-size:clamp(1rem,1.5vw,1.125rem)}.trust{font-size:13px}
.field label{font-size:12px}.control input,.control select{font-size:15px}.heading .eyebrow{font-size:12px}
.heading h2{font-size:clamp(1.9rem,3.2vw,2.6rem)}.heading p{font-size:15px}.destbody strong{font-size:18px}
.pkg h3,.newbody h3{font-size:18px}.pkg .loc,.pkg .desc,.pkg .meta,.newbody p{font-size:14px}
.price strong,.newprice strong{font-size:20px}.service h3{font-size:16px}.service p{font-size:14px}
.review p,.review strong,.review small{font-size:14px}.footer h4{font-size:16px}.footer p,.footer a,.footer .flinks span{font-size:14px}.copyright{font-size:13px}
@media(max-width:1180px){.searchgrid{grid-template-columns:repeat(2,minmax(0,1fr))}.searchgrid>.btn{min-height:52px}}
@media(max-width:1050px){.links{display:none}.menu{display:block}.destgrid,.newgrid{grid-template-columns:repeat(2,1fr)}.pkggrid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.topbar{display:none}.navin{height:68px}.mobilemenu{inset:68px 0 0}.actions>.btn{display:none}.hero{min-height:590px}.hero-content{padding:65px 0 110px}.searchgrid,.pkggrid,.services,.reviewgrid{grid-template-columns:1fr}.section{padding:65px 0}.footgrid{grid-template-columns:1fr 1fr}.statgrid{grid-template-columns:1fr 1fr}.stat:nth-child(2){border-right:0}}
@media(max-width:520px){.container{width:calc(100% - 26px)}.hero h1{font-size:39px}.hero-buttons .btn{width:100%}.searchbox{padding:15px}.searchgrid,.destgrid,.newgrid{grid-template-columns:1fr}.dest{height:250px}.footgrid{grid-template-columns:1fr}.copyright{padding:15px 0;flex-direction:column;justify-content:center;gap:4px;text-align:center}.pkgfoot{align-items:flex-start;flex-direction:column}.cardactions{width:100%}.cardactions .btn{flex:1}}

/* =========================================================
   RECOMMENDED EXPERIENCES - FORCE DETAILS BELOW IMAGE
   ========================================================= */
#packages .pkg{
    display:flex !important;
    flex-direction:column !important;
    height:auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    background:#fff !important;
}

#packages .pkgimg{
    display:block !important;
    width:100% !important;
    height:250px !important;
    min-height:250px !important;
    flex:none !important;
    position:relative !important;
    overflow:hidden !important;
}

#packages .pkgimg img{
    display:block !important;
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
}

#packages .pkgbody{
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
    position:relative !important;
    width:100% !important;
    height:auto !important;
    max-height:none !important;
    overflow:visible !important;
    padding:17px 18px 18px !important;
    background:#fff !important;
    transform:none !important;
}

#packages .pkgbody h3{
    display:block !important;
    margin:0 0 7px !important;
    font-size:17px !important;
    line-height:1.35 !important;
}

#packages .pkgbody h3 a{
    color:#07192d !important;
    text-decoration:none !important;
}

#packages .pkgbody .loc,
#packages .pkgbody .meta,
#packages .pkgbody .desc,
#packages .pkgbody .pkgfoot,
#packages .pkgbody .price,
#packages .pkgbody .cardactions{
    visibility:visible !important;
    opacity:1 !important;
}

#packages .pkgbody .pkgfoot{
    display:flex !important;
    align-items:flex-end !important;
    justify-content:space-between !important;
    gap:12px !important;
    margin-top:14px !important;
}

#packages .pkgbody .price strong{
    display:block !important;
    font-size:19px !important;
    color:#07192d !important;
}

@media(max-width:700px){
    #packages .pkgimg{
        height:220px !important;
        min-height:220px !important;
    }
    #packages .pkgbody .pkgfoot{
        align-items:stretch !important;
        flex-direction:column !important;
    }
}


/* SOCIAL MEDIA - ADMIN SETTINGS DRIVEN */
.social-follow{margin-top:18px}
.social-follow-title{margin-bottom:10px;color:#fff;font:700 12px 'Manrope',sans-serif}
.social-buttons{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.social-btn{width:39px;height:39px;border-radius:10px;display:grid!important;place-items:center;font-size:17px!important;color:#fff!important;transition:.22s;box-shadow:0 7px 18px rgba(0,0,0,.14)}
.social-btn:hover{transform:translateY(-3px);filter:brightness(1.08)}
.social-btn.whatsapp{background:#25d366}
.social-btn.facebook{background:#1877f2}
.social-btn.instagram{background:linear-gradient(135deg,#833ab4,#fd1d1d,#fcb045)}
.social-btn.youtube{background:#ff0000}
.social-btn.twitter{background:#111}



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



/* V24.65: General Settings logo in the marked public header brand area only. */
.nav .logo.has-uploaded-logo{
    min-width:145px;
    justify-content:flex-start;
}
.nav .logo .ts-public-brand-logo{
    display:block;
    width:auto;
    height:auto;
    max-width:165px;
    max-height:52px;
    object-fit:contain;
    object-position:left center;
}
@media(max-width:760px){
    .nav .logo .ts-public-brand-logo{max-width:138px;max-height:44px}
}
/* V25.15 public package enquiry / booking actions */
.ts-home-cardactions{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.ts-home-cardactions .mini{flex:1 1 auto;min-width:61px;white-space:nowrap;justify-content:center}
.ts-home-cardactions .ts-home-enquire{background:#fff2e4;border:1px solid #f1cba7;color:#9c521b}
@media(max-width:520px){.ts-home-cardactions{width:100%}.ts-home-cardactions .mini{flex:1}}
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
<body class="ts-world-public ts-world-home">

<div class="topbar"><div class="container">
<div class="topgroup">
<?php if($supportMobile!==''): ?><span><i class="fa-solid fa-phone"></i><?=e($supportMobile)?></span><?php endif; ?>
<?php if($supportEmail!==''): ?><span><i class="fa-solid fa-envelope"></i><?=e($supportEmail)?></span><?php endif; ?>
</div>
<div class="topgroup"><span><i class="fa-solid fa-headset"></i><?=e($businessHours)?></span></div>
</div></div>

<nav class="nav"><div class="container navin">
<a href="<?=e(BASE_URL)?>" class="logo<?= $siteLogoUrl !== '' ? ' has-uploaded-logo' : '' ?>">
<?php if($siteLogoUrl !== ''): ?>
<img src="<?=e($siteLogoUrl)?>" alt="<?=e((string)$siteName)?>" class="ts-public-brand-logo">
<?php else: ?>
<span class="logoicon"><i class="fa-solid fa-plane"></i></span>TRAV<span>SCOPE</span>
<?php endif; ?>
</a>
<div class="links">
<a href="pages.php?page=about">About Us</a><a href="<?= e(BASE_URL) ?>">Home</a><a href="destinations.php">Destinations</a><a href="packages.php">Packages</a><a href="#new">New Trips</a><a href="#services">Services</a><a href="blog.php">Travel Stories</a><a href="contact.php">Contact</a>
</div>
<div class="actions">
<?php if($isLoggedIn): ?>
<a href="dashboard.php" class="btn btn-light"><i class="fa-regular fa-user"></i> My Dashboard</a>
<?php else: ?>
<a href="login.php" class="btn btn-light"><i class="fa-regular fa-user"></i> Login</a>
<a href="register.php" class="btn btn-blue">Register</a>
<?php endif; ?>
<button class="menu" id="menuBtn"><i class="fa-solid fa-bars"></i></button>
</div>
</div></nav>

<div class="mobilemenu" id="mobileMenu">
<a href="pages.php?page=about">About Us</a><a href="<?= e(BASE_URL) ?>">Home</a><a href="destinations.php">Destinations</a><a href="packages.php">Packages</a><a href="#new">New Trips</a><a href="#services">Services</a><a href="blog.php">Travel Stories</a><a href="contact.php">Contact</a>
<?php if($isLoggedIn): ?><a href="dashboard.php">My Dashboard</a><?php else: ?><a href="login.php">Login</a><a href="register.php">Create Account</a><?php endif; ?>
</div>

<section class="hero" id="home">
<?php if ($homepageSlides): ?>
    <?php foreach ($homepageSlides as $slideIndex => $slide):
        $desktop = homeImage($slide['desktop_image']);
        $mobile = homeImage($slide['mobile_image'] ?: $slide['desktop_image']);
        $heading = trim((string)$slide['heading']);
        $highlight = trim((string)($slide['highlight_text'] ?? ''));
    ?>
    <div class="hero-slide <?= $slideIndex===0?'active':'' ?>"
         data-mobile="<?= e($mobile) ?>"
         data-desktop="<?= e($desktop) ?>"
         style="background-image:url('<?= e($desktop) ?>')">
        <div class="container"><div class="hero-content">
            <?php if(!empty($slide['badge_text'])): ?><div class="badge"><i class="fa-solid fa-earth-americas"></i>&nbsp; <?=e($slide['badge_text'])?></div><?php endif; ?>
            <h1><?= e($heading) ?><?php if($highlight!==''): ?> <span><?=e($highlight)?></span><?php endif; ?></h1>
            <?php if(!empty($slide['description'])): ?><p><?=e($slide['description'])?></p><?php endif; ?>
            <div class="hero-buttons">
                <?php if(!empty($slide['primary_button_text'])): ?><a href="<?=e($slide['primary_button_url'] ?: '#packages')?>" class="btn btn-blue"><?=e($slide['primary_button_text'])?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
                <?php if(!empty($slide['secondary_button_text'])): ?><a href="<?=e($slide['secondary_button_url'] ?: 'contact.php')?>" class="btn btn-outline"><?=e($slide['secondary_button_text'])?></a><?php endif; ?>
            </div>
            <div class="trust"><span><i class="fa-solid fa-circle-check"></i> Live package availability</span><span><i class="fa-solid fa-circle-check"></i> Secure customer account</span><span><i class="fa-solid fa-circle-check"></i> Booking & enquiry tracking</span></div>
        </div></div>
    </div>
    <?php endforeach; ?>
    <?php if(count($homepageSlides)>1): ?><div class="hero-dots"><?php foreach($homepageSlides as $i=>$x): ?><button type="button" class="hero-dot <?=$i===0?'active':''?>" data-slide="<?=$i?>" aria-label="Slide <?=$i+1?>"></button><?php endforeach; ?></div><?php endif; ?>
<?php else: ?>
    <div class="hero-slide active" style="background-image:url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=2000&q=90')">
        <div class="container"><div class="hero-content">
            <div class="badge"><i class="fa-solid fa-earth-americas"></i>&nbsp; <?=e($siteTagline)?></div>
            <h1>Find your next <span>unforgettable journey.</span></h1>
            <p>Explore real destinations and live holiday packages managed by the TRAVSCOPE travel team.</p>
            <div class="hero-buttons"><a href="packages.php" class="btn btn-blue">Explore Packages <i class="fa-solid fa-arrow-right"></i></a><a href="contact.php" class="btn btn-outline">Talk to Travel Expert</a></div>
        </div></div>
    </div>
<?php endif; ?>
</section>

<div class="searchwrap"><div class="container"><div class="searchbox">
<form action="packages.php" method="get" id="homePackageSearch"><div class="searchgrid">

<div class="field">
<label>DESTINATION</label>
<div class="control">
<i class="fa-solid fa-location-dot"></i>
<select name="destination">
<option value="">Where do you want to go?</option>
<?php foreach ($searchDestinations as $d): ?>
<option value="<?= (int)$d['id']; ?>">
    <?= e($d['name']); ?>
    <?= !empty($d['country'])
        ? ' — ' . e($d['country'])
        : ''; ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="field">
<label>JOURNEY DATE</label>
<div class="control">
<i class="fa-regular fa-calendar"></i>
<input
    type="date"
    name="journey_date"
    id="homeJourneyDate"
    min="<?=e(date('Y-m-d'))?>"
>
</div>
</div>

<div class="field">
<label>PACKAGE CATEGORY</label>
<div class="control">
<i class="fa-solid fa-hotel"></i>
<select name="hotel_category" id="homeHotelCategory">
<option value="">All Package Categories</option>
<option value="3">3 Star Package</option>
<option value="4">4 Star Package</option>
<option value="5">5 Star Package</option>
</select>
</div>
</div>

<div class="field">
<label>TRAVEL TYPE</label>
<div class="control">
<i class="fa-solid fa-compass"></i>
<select name="type">
<option value="">All Travel Types</option>
<?php foreach($packageTypes as $t): ?>
<option value="<?=e($t['slug'])?>"><?=e($t['name'])?></option>
<?php endforeach; ?>
</select>
</div>
</div>

<button class="btn btn-blue" type="submit">
<i class="fa-solid fa-magnifying-glass"></i> Search Trips
</button>

</div></form>
</div></div></div>

<?php if($destinations): ?>
<section class="section soft" id="destinations"><div class="container">
<div class="heading"><div class="eyebrow">Explore by destination</div><h2>Where do you want to go?</h2><p>Choose a destination first and see only the active packages available for that place.</p></div>
<div class="destgrid">
<?php foreach($destinations as $d): $di=$d['banner_image'] ?: $d['image']; ?>
<a class="dest" href="destinations.php?destination=<?=(int)$d['id']?>">
<img src="<?=e(homeImage($di))?>" alt="<?=e($d['name'])?>" loading="lazy">
<div class="destcount"><?= !empty($d['featured']) ? 'Featured Destination' : 'Explore Trips' ?></div>
<div class="destbody"><strong><?=e($d['name'])?></strong><span><?=e($d['country'])?><?=!empty($d['continent'])?' • '.e($d['continent']):''?> <i class="fa-solid fa-arrow-right"></i></span></div>
</a>
<?php endforeach; ?>
</div>
<?php if($packageTypes): ?><div class="typebar"><?php foreach($packageTypes as $t): ?><a href="packages.php?type=<?=urlencode($t['slug'])?>"><?=e($t['name'])?> (<?=(int)$t['total']?>)</a><?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php endif; ?>

<section class="section" id="packages"><div class="container">
<div class="heading"><div class="eyebrow">Recommended experiences</div><h2>Packages selected for your next trip</h2><p>These packages come directly from your live TRAVSCOPE database. Admin package updates appear here automatically.</p></div>
<?php if($featuredPackages): ?><div class="pkggrid">
<?php foreach($featuredPackages as $p):
$detail='package-details.php?slug='.urlencode($p['slug']);
$book='book-package.php?package='.(int)$p['id'];
$enquire='contact.php?package='.(int)$p['id'];
$bookLink=$isLoggedIn?$book:guestAction($book);
$enquireLink=$enquire; // Enquiries are also available to visitors who have not logged in.
$customerActionMode=tsCustomerActionMode($p);
$price=packagePrice($p);
?>
<article class="pkg">
<a href="<?=e($detail)?>" class="pkgimg"><img src="<?=e(homeImage($p['main_image']))?>" alt="<?=e($p['title'])?>" loading="lazy"><?php if((int)$p['bestseller']===1): ?><span class="tag best"><i class="fa-solid fa-fire"></i> Best Seller</span><?php elseif((int)$p['featured']===1): ?><span class="tag">Featured</span><?php endif; ?></a>
<div class="pkgbody">
<h3><a href="<?=e($detail)?>"><?=e($p['title'])?></a></h3>
<div class="loc" style="margin-top:7px"><i class="fa-solid fa-location-dot"></i> <?=e($p['destination_name'] ?: 'Travel Experience')?><?=!empty($p['destination_country'])?', '.e($p['destination_country']):''?></div>
<div class="meta"><span><i class="fa-regular fa-clock"></i> <?=(int)$p['duration_days']?> Days / <?=(int)$p['duration_nights']?> Nights</span><span><i class="fa-solid fa-compass"></i> <?=e(homeType($p['package_type']))?></span></div>
<?php if(!empty($p['short_description'])): ?><div class="desc"><?=e($p['short_description'])?></div><?php endif; ?>
<div class="pkgfoot">
<div class="price"><small>Starting from</small><strong><?=e(homeMoney($price,tsPackageCurrencySymbol((string)($p['currency'] ?? 'INR'))))?></strong><?php if((float)$p['regular_price']>$price): ?><span class="regular"><?=e(homeMoney($p['regular_price'],tsPackageCurrencySymbol((string)($p['currency'] ?? 'INR'))))?></span><?php endif; ?></div>
<div class="cardactions ts-home-cardactions"><a href="<?=e($detail)?>" class="btn btn-light mini"><i class="fa-regular fa-eye"></i> View</a><?php if($customerActionMode!=='booking'): ?><a href="<?=e($enquireLink)?>" class="btn mini ts-home-enquire"><i class="fa-regular fa-message"></i> Enquire</a><?php endif; ?><?php if($customerActionMode!=='enquiry'): ?><a href="<?=e($bookLink)?>" class="btn btn-blue mini"><i class="fa-solid fa-calendar-check"></i> Book</a><?php endif; ?></div>
</div>
</div></article>
<?php endforeach; ?>
</div>
<div style="text-align:center;margin-top:28px"><a href="packages.php" class="btn btn-blue">View All Packages <i class="fa-solid fa-arrow-right"></i></a></div>
<?php else: ?><div style="text-align:center;color:#748196;padding:35px">No active packages are available right now.</div><?php endif; ?>
</div></section>

<?php if($newPackages): ?>
<section class="section soft" id="new"><div class="container">
<div class="heading"><div class="eyebrow">Fresh from TRAVSCOPE</div><h2>New & upcoming packages</h2><p>The newest active packages added by your admin team.</p></div>
<div class="newgrid">
<?php foreach($newPackages as $p): ?>
<a class="newcard" href="package-details.php?slug=<?=urlencode($p['slug'])?>">
<div class="newimg"><img src="<?=e(homeImage($p['main_image']))?>" alt="<?=e($p['title'])?>" loading="lazy"></div>
<div class="newbody"><h3><?=e($p['title'])?></h3><p><i class="fa-solid fa-location-dot"></i> <?=e($p['destination_name'] ?: 'Destination')?> • <?=(int)$p['duration_days']?> Days</p><div class="newprice"><strong><?=e(homeMoney(packagePrice($p),tsPackageCurrencySymbol((string)($p['currency'] ?? 'INR'))))?></strong><span style="font-size:8px;color:#0b74ff;font-weight:800">View Trip <i class="fa-solid fa-arrow-right"></i></span></div></div>
</a>
<?php endforeach; ?>
</div>
</div></section>
<?php endif; ?>

<?php if($bestsellers): ?>
<section class="section"><div class="container">
<div class="heading"><div class="eyebrow">Popular with travellers</div><h2>Best-selling experiences</h2><p>Packages marked as best sellers by your travel team.</p></div>
<div class="newgrid">
<?php foreach($bestsellers as $p): ?><a class="newcard" href="package-details.php?slug=<?=urlencode($p['slug'])?>"><div class="newimg"><img src="<?=e(homeImage($p['main_image']))?>" alt="<?=e($p['title'])?>" loading="lazy"></div><div class="newbody"><h3><?=e($p['title'])?></h3><p><?=e($p['destination_name'] ?: 'Travel')?> • <?=e(homeType($p['package_type']))?></p><div class="newprice"><strong><?=e(homeMoney(packagePrice($p),tsPackageCurrencySymbol((string)($p['currency'] ?? 'INR'))))?></strong><span style="font-size:8px;color:#f29b25;font-weight:800"><i class="fa-solid fa-fire"></i> Best Seller</span></div></div></a><?php endforeach; ?>
</div>
</div></section>
<?php endif; ?>

<section class="section soft" id="services"><div class="container">
<div class="heading"><div class="eyebrow">Complete travel support</div><h2>One place for your travel journey</h2><p>Discover packages, make enquiries, book trips and track everything from your TRAVSCOPE account.</p></div>
<div class="services"><?php foreach($services as $s): ?><div class="service"><i class="fa-solid <?=e($s[0])?>"></i><h3><?=e($s[1])?></h3><p><?=e($s[2])?></p></div><?php endforeach; ?></div>
</div></section>

<section class="stats"><div class="container"><div class="statgrid">
<div class="stat"><strong><?=number_format($activeDestinationCount)?>+</strong><span>Active Destinations</span></div>
<div class="stat"><strong><?=number_format($activePackageCount)?>+</strong><span>Live Packages</span></div>
<div class="stat"><strong><?=number_format($approvedReviewCount)?>+</strong><span>Approved Reviews</span></div>
<div class="stat"><strong>24/7</strong><span>Online Trip Access</span></div>
</div></div></section>

<?php if($reviews): ?>
<section class="section"><div class="container">
<div class="heading"><div class="eyebrow">Traveller stories</div><h2>What customers are saying</h2><p>Approved reviews from your TRAVSCOPE review system.</p></div>
<div class="reviewgrid">

<?php foreach ($reviews as $r): ?>

    <?php
    $reviewerName = trim(
        (string)(
            $r['user_profile_name']
            ?: $r['customer_name']
            ?: 'TRAVSCOPE Traveller'
        )
    );

    $reviewerPhoto = trim(
        (string)(
            $r['user_profile_photo']
            ?: $r['review_profile_photo']
            ?: ''
        )
    );

    $reviewAvatar =
        homeReviewAvatar(
            $reviewerPhoto,
            $reviewerName
        );

    $adminReply = trim(
        (string)(
            $r['admin_reply']
            ?? ''
        )
    );
    ?>

    <article class="review">

        <div class="review-head">

            <div class="review-avatar">

                <?php if ($reviewAvatar['type'] === 'image'): ?>

                    <img
                        src="<?= e($reviewAvatar['value']); ?>"
                        alt="<?= e($reviewerName); ?>"
                        loading="lazy"
                        onerror="
                            this.style.display='none';
                            this.parentElement.textContent=<?= e(json_encode($reviewAvatar['initial'])); ?>;
                        "
                    >

                <?php else: ?>

                    <?= e($reviewAvatar['initial']); ?>

                <?php endif; ?>

            </div>


            <div class="review-person">

                <strong>
                    <?= e($reviewerName); ?>
                </strong>

                <small>
                    <?= e(
                        $r['package_title']
                        ?: 'Travel Experience'
                    ); ?>
                </small>

            </div>

        </div>


        <div class="stars">

            <?php for ($i = 1; $i <= 5; $i++): ?>

                <i
                    class="<?= $i <= (int)$r['rating']
                        ? 'fa-solid'
                        : 'fa-regular'; ?> fa-star"
                ></i>

            <?php endfor; ?>

        </div>


        <p class="review-text">
            “<?= e($r['review_text']); ?>”
        </p>


        <?php if ($adminReply !== ''): ?>

            <div class="admin-reply">

                <div class="admin-reply-title">

                    <i class="fa-solid fa-reply"></i>

                    Reply from TRAVSCOPE

                </div>

                <p>
                    <?= nl2br(e($adminReply)); ?>
                </p>

            </div>

        <?php endif; ?>

    </article>

<?php endforeach; ?>

</div>
</div></section>
<?php endif; ?>


<?php if ($latestStories): ?>
<section class="section soft" id="stories">
<div class="container">

<div class="heading">
    <div class="eyebrow">TRAVSCOPE JOURNAL</div>
    <h2>Latest Travel Stories</h2>
    <p>Destination inspiration, local experiences and useful stories from places worth exploring.</p>
</div>

<div class="storygrid">

<?php foreach ($latestStories as $story): ?>
<?php
$storyText = trim((string)($story['excerpt'] ?? ''));

if ($storyText === '') {
    $storyText = trim(
        preg_replace(
            '/\s+/',
            ' ',
            strip_tags((string)($story['content'] ?? ''))
        )
    );

    if (mb_strlen($storyText) > 190) {
        $storyText = mb_substr($storyText, 0, 190) . '...';
    }
}

$storyLocation =
    trim((string)($story['location_name'] ?? ''))
    ?: trim((string)($story['destination_name'] ?? ''))
    ?: trim((string)($story['state_name'] ?? ''))
    ?: trim((string)($story['country'] ?? ''));
?>

<article class="storycard">

    <a
        href="blog-details.php?slug=<?= urlencode((string)$story['slug']); ?>"
        class="storyimg"
        style="display:block;"
    >
        <img
            src="<?= e(homeImage((string)($story['main_image'] ?? ''))); ?>"
            alt="<?= e((string)($story['image_alt'] ?: $story['title'])); ?>"
            loading="lazy"
        >
    </a>

    <div class="storybody">

        <span class="storytag">
            <?= e((string)($story['category_name'] ?: 'Travel Story')); ?>
        </span>

        <h3>
            <a href="blog-details.php?slug=<?= urlencode((string)$story['slug']); ?>">
                <?= e((string)$story['title']); ?>
            </a>
        </h3>

        <div class="storymeta">

            <?php if ($storyLocation !== ''): ?>
                <span>
                    <i class="fa-solid fa-location-dot"></i>
                    <?= e($storyLocation); ?>
                </span>
            <?php endif; ?>

            <span>
                <i class="fa-regular fa-calendar"></i>
                <?= e(
                    date(
                        'd M Y',
                        strtotime(
                            (string)(
                                $story['published_at']
                                ?: $story['created_at']
                            )
                        )
                    )
                ); ?>
            </span>

        </div>

        <div class="storydesc">
            <?= e($storyText); ?>
        </div>

        <a
            href="blog-details.php?slug=<?= urlencode((string)$story['slug']); ?>"
            class="storylink"
        >
            Read Story
            <i class="fa-solid fa-arrow-right"></i>
        </a>

    </div>

</article>

<?php endforeach; ?>

</div>

<div class="storysectionbottom">
    <a href="blog.php" class="btn btn-blue">
        Explore All Travel Stories
        <i class="fa-solid fa-arrow-right"></i>
    </a>
</div>

</div>
</section>
<?php endif; ?>


<div class="container"><section class="cta"><h2>Ready to plan your next journey?</h2><p>Browse our live packages or send an enquiry to the TRAVSCOPE team. Registered customers can manage bookings, payments, enquiries and reviews from their dashboard.</p>
<?php if($isLoggedIn): ?><a href="dashboard.php" class="btn" style="background:#fff;color:#07192d">Open My Dashboard <i class="fa-solid fa-arrow-right"></i></a><?php else: ?><a href="register.php" class="btn" style="background:#fff;color:#07192d">Create Free Account <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
</section></div>

<?php require_once __DIR__ . '/credential-display.php'; echo tsCredentialsHtml($pdo, 'website'); ?>
<footer class="footer"><div class="container">
<div class="footgrid">
<div>
<a href="<?=e(BASE_URL)?>" class="logo"><span class="logoicon"><i class="fa-solid fa-plane"></i></span>TRAV<span>SCOPE</span></a>
<p style="margin-top:13px"><?=e($siteTagline)?> Discover destinations and manage your travel journey online.</p>

<?php if($whatsappUrl!=='' || $facebookUrl!=='' || $instagramUrl!=='' || $youtubeUrl!=='' || $twitterUrl!==''): ?>
<div class="social-follow">
<div class="social-follow-title">Follow TRAVSCOPE</div>
<div class="social-buttons">

<?php if($whatsappUrl!==''): ?>
<a class="social-btn whatsapp" href="<?=e($whatsappUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" title="WhatsApp">
<i class="fa-brands fa-whatsapp"></i>
</a>
<?php endif; ?>

<?php if($facebookUrl!==''): ?>
<a class="social-btn facebook" href="<?=e($facebookUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook">
<i class="fa-brands fa-facebook-f"></i>
</a>
<?php endif; ?>

<?php if($instagramUrl!==''): ?>
<a class="social-btn instagram" href="<?=e($instagramUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram">
<i class="fa-brands fa-instagram"></i>
</a>
<?php endif; ?>

<?php if($youtubeUrl!==''): ?>
<a class="social-btn youtube" href="<?=e($youtubeUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube">
<i class="fa-brands fa-youtube"></i>
</a>
<?php endif; ?>

<?php if($twitterUrl!==''): ?>
<a class="social-btn twitter" href="<?=e($twitterUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="X / Twitter" title="X / Twitter">
<i class="fa-brands fa-x-twitter"></i>
</a>
<?php endif; ?>

</div>
</div>
<?php endif; ?>
</div>
<div><h4>Explore</h4><div class="flinks"><a href="destinations.php">Destinations</a><a href="packages.php">All Packages</a><a href="packages.php?sort=newest">New Packages</a><a href="contact.php">Travel Enquiry</a></div></div>
<div><h4>Account</h4><div class="flinks"><?php if($isLoggedIn): ?><a href="dashboard.php">Dashboard</a><a href="my-bookings.php">My Bookings</a><a href="my-enquiries.php">My Enquiries</a><a href="reviews.php">My Reviews</a><?php else: ?><a href="login.php">Login</a><a href="register.php">Register</a><a href="<?= e($privacyPageUrl); ?>">Privacy Policy</a><a href="pages.php?page=terms">Terms &amp; Conditions</a><?php endif; ?></div></div>
<div><h4>Contact</h4><div class="flinks"><?php if($officeAddress!==''): ?><span><i class="fa-solid fa-location-dot"></i> <?=e($officeAddress)?></span><?php endif; ?><?php if($supportMobile!==''): ?><span><i class="fa-solid fa-phone"></i> <?=e($supportMobile)?></span><?php endif; ?><?php if($supportEmail!==''): ?><span><i class="fa-solid fa-envelope"></i> <?=e($supportEmail)?></span><?php endif; ?></div></div>
</div>
<div class="copyright"><span>© <?=$currentYear?> <?=e($siteName)?>. All Rights Reserved.</span><span>Explore • Experience • Discover</span></div>
</div></footer>

<?php if($whatsappNumber!==''): ?><a class="whatsapp" href="https://wa.me/<?=e($whatsappNumber)?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?>

<script>

const heroSlides=[...document.querySelectorAll('.hero-slide')];
const heroDots=[...document.querySelectorAll('.hero-dot')];
let heroCurrent=0,heroTimer=null;

function applyHeroImages(){
    const mobile=window.matchMedia('(max-width:760px)').matches;
    heroSlides.forEach(slide=>{
        const image=mobile?slide.dataset.mobile:slide.dataset.desktop;
        if(image) slide.style.backgroundImage=`url("${image}")`;
    });
}
function showHeroSlide(i){
    if(!heroSlides.length)return;
    heroCurrent=(i+heroSlides.length)%heroSlides.length;
    heroSlides.forEach((s,n)=>s.classList.toggle('active',n===heroCurrent));
    heroDots.forEach((d,n)=>d.classList.toggle('active',n===heroCurrent));
}
function startHero(){
    if(heroSlides.length<2)return;
    clearInterval(heroTimer);
    heroTimer=setInterval(()=>showHeroSlide(heroCurrent+1),5500);
}
heroDots.forEach((dot,i)=>dot.addEventListener('click',()=>{showHeroSlide(i);startHero()}));
applyHeroImages();
window.addEventListener('resize',applyHeroImages);
startHero();

const b=document.getElementById('menuBtn'),m=document.getElementById('mobileMenu');
b?.addEventListener('click',()=>{m.classList.toggle('active');const i=b.querySelector('i');i.classList.toggle('fa-bars');i.classList.toggle('fa-xmark')});
m?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>m.classList.remove('active')));

const homePackageSearch=document.getElementById('homePackageSearch');
const homeJourneyDate=document.getElementById('homeJourneyDate');
const homeHotelCategory=document.getElementById('homeHotelCategory');

homePackageSearch?.addEventListener('submit',function(){
    /*
    | hotel_category is intentionally optional:
    | - Blank means show packages with any available category.
    | - 3, 4 or 5 sends the selected category to packages.php.
    | journey_date is also sent so packages.php can match the correct
    | date-wise price period from package_date_prices.
    */
    if(homeJourneyDate?.value){
        homeJourneyDate.value=homeJourneyDate.value;
    }

    if(homeHotelCategory?.value){
        homeHotelCategory.value=homeHotelCategory.value;
    }
});
</script>

<script src="assets/travscope-pro-public.js?v=20260916-pro" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
</body>
</html>