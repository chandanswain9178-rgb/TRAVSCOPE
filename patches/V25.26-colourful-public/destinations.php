<?php
/* TRAVSCOPE V25.14: A selected destination has its own URL and navigation
   context. Reuse the existing package renderer and database queries rather
   than copy pricing / hotel / supplier code into a new implementation. */
$tsDestinationRouteValue = isset($_GET['destination']) && is_scalar($_GET['destination'])
    ? (string)$_GET['destination'] : '';
if ($tsDestinationRouteValue !== '' && ctype_digit($tsDestinationRouteValue)
    && (int)$tsDestinationRouteValue > 0) {
    $tsDestinationListing = true;
    require __DIR__ . '/packages.php';
    return;
}


/*
|--------------------------------------------------------------------------
| TRAVSCOPE.COM
| PUBLIC DESTINATIONS DIRECTORY
| STEP-01 FINAL BASELINE PATCH: 2026-09-09-R1
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| OPTIONAL LOGGED-IN USER
|--------------------------------------------------------------------------
*/

$user = null;
$userId = 0;

if (isLoggedIn()) {
    $user = currentUser($pdo);

    if ($user && strtolower((string)($user['status'] ?? '')) === 'active') {
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

$siteName = getSetting($pdo, 'site_name', 'TRAVSCOPE.COM');
$currencySymbol = getSetting($pdo, 'currency_symbol', '₹');

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function publicDestinationImage(?string $image, bool $banner = false): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return $banner
            ? 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1800&q=85'
            : 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=85';
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    return BASE_URL . ltrim($image, '/');
}

function publicDestinationExcerpt(?string $value): string
{
    $value = trim(strip_tags((string)$value));

    if ($value === '') {
        return 'Discover curated experiences, trusted travel support and memorable holidays with TRAVSCOPE.';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) > 155) {
        return rtrim(mb_substr($value, 0, 152)) . '...';
    }

    if (strlen($value) > 155) {
        return rtrim(substr($value, 0, 152)) . '...';
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim((string)($_GET['q'] ?? ''));
$country = trim((string)($_GET['country'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'recommended'));

$allowedSorts = ['recommended', 'name', 'packages'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'recommended';
}

/*
|--------------------------------------------------------------------------
| COUNTRY OPTIONS
|--------------------------------------------------------------------------
*/

$countries = [];

try {
    $countryStmt = $pdo->query("\n        SELECT DISTINCT country\n        FROM destinations\n        WHERE status = 'active'\n          AND country IS NOT NULL\n          AND TRIM(country) <> ''\n        ORDER BY country ASC\n    ");

    $countries = $countryStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    error_log('Public destinations countries: ' . $e->getMessage());
}

if ($country !== '' && !in_array($country, $countries, true)) {
    $country = '';
}

/*
|--------------------------------------------------------------------------
| BUILD DESTINATION QUERY
|--------------------------------------------------------------------------
*/

$where = ["d.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(\n        d.name LIKE ?\n        OR d.country LIKE ?\n        OR COALESCE(d.continent, '') LIKE ?\n        OR COALESCE(d.short_description, '') LIKE ?\n    )";

    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

if ($country !== '') {
    $where[] = 'd.country = ?';
    $params[] = $country;
}

$orderBy = match ($sort) {
    'name' => 'd.name ASC, d.id ASC',
    'packages' => 'package_count DESC, d.featured DESC, d.sort_order ASC, d.name ASC',
    default => 'd.featured DESC, d.sort_order ASC, package_count DESC, d.name ASC'
};

$destinations = [];
$pageError = '';

try {
    $sql = "\n        SELECT\n            d.id,\n            d.name,\n            d.slug,\n            d.country,\n            d.continent,\n            d.short_description,\n            d.description,\n            d.image,\n            d.banner_image,\n            d.featured,\n            d.sort_order,\n            (\n                SELECT COUNT(*)\n                FROM packages p_count\n                WHERE p_count.destination_id = d.id\n                  AND p_count.status = 'active'\n            ) AS package_count,\n            (\n                SELECT MIN(\n                    CASE\n                        WHEN COALESCE(p_price.sale_price, 0) > 0 THEN p_price.sale_price\n                        WHEN COALESCE(p_price.regular_price, 0) > 0 THEN p_price.regular_price\n                        ELSE NULL\n                    END\n                )\n                FROM packages p_price\n                WHERE p_price.destination_id = d.id\n                  AND p_price.status = 'active'\n            ) AS starting_price\n        FROM destinations d\n        WHERE " . implode(' AND ', $where) . "\n        ORDER BY {$orderBy}\n    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $destinations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Public destinations query: ' . $e->getMessage());
    $pageError = 'Destinations are temporarily unavailable. Please try again shortly.';
}

$totalDestinations = count($destinations);
$totalPackages = array_sum(array_map(
    static fn(array $row): int => (int)($row['package_count'] ?? 0),
    $destinations
));

$featuredHero = null;
foreach ($destinations as $destination) {
    if ((int)($destination['featured'] ?? 0) === 1) {
        $featuredHero = $destination;
        break;
    }
}
if (!$featuredHero && $destinations) {
    $featuredHero = $destinations[0];
}

$queryForReset = ($search !== '' || $country !== '' || $sort !== 'recommended');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#07192d">
<meta name="description" content="Explore destinations across India and around the world with curated TRAVSCOPE holiday packages and travel support.">
<title>Destinations | <?= e($siteName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--navy:#07192d;--navy-soft:#0d2743;--primary:#0b74ff;--primary-dark:#075fd2;--cyan:#00b8ff;--background:#f6f8fb;--white:#fff;--text:#172033;--muted:#778294;--border:#e4e9ef;--green:#0aa66a;--shadow:0 12px 35px rgba(7,25,45,.07)}
*{margin:0;padding:0;box-sizing:border-box}html{scroll-behavior:smooth}body{min-height:100vh;background:var(--background);color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;line-height:1.55}a{color:inherit;text-decoration:none}button,input,select{font:inherit}
.header{height:78px;padding:0 5%;position:sticky;top:0;z-index:1000;display:flex;align-items:center;justify-content:space-between;gap:25px;background:rgba(255,255,255,.96);backdrop-filter:blur(15px);border-bottom:1px solid var(--border)}
.logo{display:flex;align-items:center;gap:10px;color:var(--navy);font-family:'Manrope',sans-serif;font-size:21px;font-weight:800;letter-spacing:-.4px}.logo-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--cyan));color:#fff;box-shadow:0 8px 20px rgba(11,116,255,.22);transform:rotate(-5deg)}.logo span{color:var(--primary)}
.nav{display:flex;align-items:center;gap:28px}.nav a{color:#566274;font-size:13px;font-weight:700;border-radius:8px;transition:.2s}.nav a:hover,.nav a.active{color:var(--primary)}.header-actions{display:flex;align-items:center;gap:9px}.header-button{min-height:41px;padding:0 14px;border-radius:9px;display:flex;align-items:center;justify-content:center;gap:7px;font-size:13px;font-weight:800}.header-button.light{border:1px solid var(--border);background:#fff;color:var(--navy)}.header-button.primary{background:var(--primary);color:#fff}.mobile-menu{display:none;width:42px;height:42px;border:1px solid var(--border);border-radius:9px;background:#fff;color:var(--navy);cursor:pointer}.mobile-nav{display:none;position:fixed;top:78px;left:0;right:0;z-index:999;padding:14px;background:#fff;border-bottom:1px solid var(--border);box-shadow:0 12px 28px rgba(7,25,45,.08)}.mobile-nav.open{display:grid;gap:7px}.mobile-nav a{padding:12px;border-radius:9px;color:#4f5d6d;font-size:14px;font-weight:700}.mobile-nav a:hover,.mobile-nav a.active{background:#eff6ff;color:var(--primary)}.mobile-nav i{width:24px}
.hero{position:relative;min-height:430px;display:flex;align-items:center;overflow:hidden;background:linear-gradient(90deg,rgba(3,18,34,.94),rgba(3,18,34,.64),rgba(3,18,34,.22)),url('<?= e(publicDestinationImage((string)($featuredHero['banner_image'] ?? ''), true)); ?>') center/cover no-repeat;color:#fff}.hero::after{content:'';position:absolute;inset:auto -100px -190px auto;width:430px;height:430px;border-radius:50%;background:rgba(11,116,255,.22)}.hero-inner{width:min(1250px,90%);margin:auto;position:relative;z-index:2}.eyebrow{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border:1px solid rgba(255,255,255,.24);border-radius:999px;background:rgba(255,255,255,.10);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px}.hero h1{max-width:760px;margin-top:18px;color:#fff;font-family:'Manrope',sans-serif;font-size:clamp(2.4rem,5vw,4rem);line-height:1.05;letter-spacing:-1.5px}.hero p{max-width:680px;margin-top:17px;color:rgba(255,255,255,.82);font-size:16px;line-height:1.75}.hero-stats{display:flex;flex-wrap:wrap;gap:12px;margin-top:25px}.hero-stat{min-width:145px;padding:13px 16px;border:1px solid rgba(255,255,255,.2);border-radius:12px;background:rgba(7,25,45,.34);backdrop-filter:blur(9px)}.hero-stat strong{display:block;font-family:'Manrope',sans-serif;font-size:21px}.hero-stat span{display:block;margin-top:2px;color:rgba(255,255,255,.68);font-size:12px}
.search-wrap{position:relative;z-index:5;width:min(1180px,92%);margin:-36px auto 0}.search-card{display:grid;grid-template-columns:minmax(240px,1.7fr) minmax(180px,.9fr) minmax(170px,.8fr) auto;gap:12px;align-items:end;padding:18px;background:#fff;border:1px solid var(--border);border-radius:16px;box-shadow:0 18px 50px rgba(7,25,45,.12)}.field label{display:block;margin-bottom:7px;color:#334155;font-size:12px;font-weight:800}.control{width:100%;height:46px;padding:0 13px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:var(--text);font-size:13px;outline:none}.control:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(37,99,235,.14)}.search-btn{height:46px;padding:0 20px;border:0;border-radius:9px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer}.search-btn:hover{background:var(--primary-dark)}
.container{width:min(1250px,92%);margin:auto;padding:62px 0 72px}.section-head{display:flex;align-items:end;justify-content:space-between;gap:22px;margin-bottom:24px}.section-head h2{font-family:'Manrope',sans-serif;color:var(--navy);font-size:clamp(1.8rem,3vw,2.35rem);letter-spacing:-.6px}.section-head p{max-width:640px;margin-top:6px;color:var(--muted);font-size:14px}.result-summary{color:var(--muted);font-size:13px;font-weight:700;white-space:nowrap}.reset-link{color:var(--primary);font-weight:800}
.destination-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:21px}.destination-card{overflow:hidden;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s}.destination-card:hover{transform:translateY(-4px);box-shadow:0 18px 42px rgba(7,25,45,.11)}.destination-image{height:238px;position:relative;overflow:hidden;background:#dfe7ef}.destination-image img{width:100%;height:100%;object-fit:cover;transition:transform .35s}.destination-card:hover .destination-image img{transform:scale(1.035)}.image-overlay{position:absolute;inset:0;background:linear-gradient(180deg,transparent 48%,rgba(3,18,34,.83))}.featured-badge{position:absolute;top:14px;left:14px;padding:6px 9px;border-radius:999px;background:#fff;color:var(--primary);font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.4px}.package-badge{position:absolute;bottom:14px;left:14px;display:flex;align-items:center;gap:6px;color:#fff;font-size:12px;font-weight:800}.destination-body{padding:19px}.location-line{display:flex;align-items:center;gap:7px;color:var(--primary);font-size:12px;font-weight:800}.destination-body h3{margin-top:7px;color:var(--navy);font-family:'Manrope',sans-serif;font-size:20px}.destination-body p{min-height:65px;margin-top:8px;color:var(--muted);font-size:13px;line-height:1.65}.destination-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:17px;padding-top:15px;border-top:1px solid var(--border)}.start-price small{display:block;color:#8b96a5;font-size:10px;font-weight:700}.start-price strong{display:block;margin-top:2px;color:var(--navy);font-family:'Manrope',sans-serif;font-size:16px}.view-btn{min-height:39px;padding:0 12px;display:inline-flex;align-items:center;gap:7px;border-radius:8px;background:#eff6ff;color:var(--primary);font-size:12px;font-weight:900}.view-btn:hover{background:var(--primary);color:#fff}
.empty{padding:52px 20px;text-align:center;background:#fff;border:1px solid var(--border);border-radius:14px}.empty i{font-size:35px;color:#9fb1c5}.empty h3{margin-top:15px;color:var(--navy);font-family:'Manrope',sans-serif;font-size:22px}.empty p{margin:7px auto 0;max-width:520px;color:var(--muted)}.alert{margin-bottom:20px;padding:13px 15px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;color:#b91c1c;font-size:13px;font-weight:700}
.cta{width:min(1250px,92%);margin:0 auto 65px;padding:34px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:24px;border-radius:18px;background:linear-gradient(135deg,#07192d,#0d3154);color:#fff}.cta::after{content:'';width:340px;height:340px;position:absolute;right:-110px;top:-180px;border-radius:50%;background:rgba(11,116,255,.2)}.cta-content{position:relative;z-index:2}.cta h2{color:#fff;font-family:'Manrope',sans-serif;font-size:clamp(1.8rem,3vw,2.3rem)}.cta p{max-width:620px;margin-top:8px;color:rgba(255,255,255,.7);font-size:14px}.cta a{min-height:44px;padding:0 16px;position:relative;z-index:2;border-radius:9px;display:flex;align-items:center;gap:7px;background:#fff;color:var(--navy);font-size:13px;font-weight:900;white-space:nowrap}.footer{padding:28px 5%;background:var(--navy);color:#8ea0b2}.footer-inner{max-width:1250px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:20px}.footer strong{color:#fff;font-family:'Manrope',sans-serif;font-size:17px}.footer p{font-size:13px}
@media(max-width:1000px){.nav{display:none}.mobile-menu{display:grid;place-items:center}.destination-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.search-card{grid-template-columns:1fr 1fr}.search-btn{grid-column:1/-1}}
@media(max-width:650px){.header{height:70px;padding:0 15px}.mobile-nav{top:70px}.header-actions .header-button.light{display:none}.header-button.primary span{display:none}.hero{min-height:480px;background:linear-gradient(180deg,rgba(3,18,34,.25),rgba(3,18,34,.94)),url('<?= e(publicDestinationImage((string)($featuredHero['banner_image'] ?? ''), true)); ?>') center/cover no-repeat}.hero-inner{width:calc(100% - 30px);padding-top:70px}.hero h1{font-size:2.45rem}.hero p{font-size:14px}.hero-stat{min-width:calc(50% - 6px)}.search-wrap{width:calc(100% - 28px)}.search-card{grid-template-columns:1fr}.search-btn{grid-column:auto}.container{width:calc(100% - 28px);padding:48px 0 55px}.section-head{align-items:flex-start;flex-direction:column}.destination-grid{grid-template-columns:1fr}.destination-image{height:235px}.destination-body p{min-height:0}.cta{width:calc(100% - 28px);padding:26px;align-items:flex-start;flex-direction:column}.cta a{width:100%;justify-content:center}.footer-inner{align-items:flex-start;flex-direction:column}}
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
<body class="ts-world-public ts-world-destinations">
<header class="header">
    <a href="<?= e(BASE_URL); ?>" class="logo">
        <div class="logo-icon"><i class="fa-solid fa-plane"></i></div>
        TRAV<span>SCOPE</span>
    </a>

    <nav class="nav">
        <a href="<?= e(BASE_URL); ?>">Home</a>
        <a href="packages.php">Packages</a>
        <a href="destinations.php" class="active">Destinations</a>
        <?php if ($userId): ?><a href="my-bookings.php">My Trips</a><?php endif; ?>
        <a href="contact.php">Contact</a>
    </nav>

    <div class="header-actions">
        <?php if ($userId): ?>
            <a href="wishlist.php" class="header-button light"><i class="fa-regular fa-heart"></i> Wishlist</a>
            <a href="dashboard.php" class="header-button primary"><i class="fa-regular fa-user"></i><span>My Account</span></a>
        <?php else: ?>
            <a href="login.php" class="header-button light">Login</a>
            <a href="register.php" class="header-button primary"><i class="fa-regular fa-user"></i><span>Join TRAVSCOPE</span></a>
        <?php endif; ?>
        <button type="button" class="mobile-menu" id="mobileMenu" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
    </div>
</header>

<nav class="mobile-nav" id="mobileNav">
    <a href="<?= e(BASE_URL); ?>"><i class="fa-solid fa-house"></i> Home</a>
    <a href="packages.php"><i class="fa-solid fa-suitcase-rolling"></i> Packages</a>
    <a href="destinations.php" class="active"><i class="fa-solid fa-earth-americas"></i> Destinations</a>
    <?php if ($userId): ?>
        <a href="dashboard.php"><i class="fa-solid fa-gauge"></i> My Dashboard</a>
        <a href="wishlist.php"><i class="fa-regular fa-heart"></i> Wishlist</a>
    <?php else: ?>
        <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
    <a href="contact.php"><i class="fa-regular fa-envelope"></i> Contact</a>
</nav>

<section class="hero">
    <div class="hero-inner">
        <div class="eyebrow"><i class="fa-solid fa-location-dot"></i> Explore the world with TRAVSCOPE</div>
        <h1>Find your next unforgettable destination.</h1>
        <p>Browse handpicked places across India and around the world, then explore active holiday packages built for real travellers.</p>
        <div class="hero-stats">
            <div class="hero-stat"><strong><?= number_format($totalDestinations); ?></strong><span>Destinations shown</span></div>
            <div class="hero-stat"><strong><?= number_format($totalPackages); ?></strong><span>Active packages</span></div>
            <?php if ($featuredHero): ?><div class="hero-stat"><strong><?= e((string)$featuredHero['name']); ?></strong><span>Featured now</span></div><?php endif; ?>
        </div>
    </div>
</section>

<div class="search-wrap">
    <form method="get" class="search-card" action="destinations.php">
        <div class="field">
            <label for="destinationSearch">Search destination</label>
            <input class="control" id="destinationSearch" type="search" name="q" value="<?= e($search); ?>" placeholder="Dubai, Odisha, Bali...">
        </div>
        <div class="field">
            <label for="country">Country</label>
            <select class="control" id="country" name="country">
                <option value="">All countries</option>
                <?php foreach ($countries as $countryOption): ?>
                    <option value="<?= e((string)$countryOption); ?>" <?= $country === (string)$countryOption ? 'selected' : ''; ?>><?= e((string)$countryOption); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="sort">Sort by</label>
            <select class="control" id="sort" name="sort">
                <option value="recommended" <?= $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                <option value="name" <?= $sort === 'name' ? 'selected' : ''; ?>>Name A–Z</option>
                <option value="packages" <?= $sort === 'packages' ? 'selected' : ''; ?>>Most packages</option>
            </select>
        </div>
        <button class="search-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Explore</button>
    </form>
</div>

<main class="container">
    <div class="section-head">
        <div>
            <h2>Explore destinations</h2>
            <p>Choose a destination to see its currently available TRAVSCOPE travel packages.</p>
        </div>
        <div class="result-summary">
            <?= number_format($totalDestinations); ?> result<?= $totalDestinations === 1 ? '' : 's'; ?>
            <?php if ($queryForReset): ?> · <a class="reset-link" href="destinations.php">Reset filters</a><?php endif; ?>
        </div>
    </div>

    <?php if ($pageError !== ''): ?><div class="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= e($pageError); ?></div><?php endif; ?>

    <?php if ($destinations): ?>
        <div class="destination-grid">
            <?php foreach ($destinations as $destination):
                $packageCount = (int)($destination['package_count'] ?? 0);
                $startingPrice = (float)($destination['starting_price'] ?? 0);
            ?>
                <article class="destination-card">
                    <a href="destinations.php?destination=<?= (int)$destination['id']; ?>" aria-label="Explore <?= e((string)$destination['name']); ?> packages">
                        <div class="destination-image">
                            <img src="<?= e(publicDestinationImage((string)($destination['image'] ?? ''))); ?>" alt="<?= e((string)$destination['name']); ?>" loading="lazy">
                            <div class="image-overlay"></div>
                            <?php if ((int)($destination['featured'] ?? 0) === 1): ?><div class="featured-badge"><i class="fa-solid fa-star"></i> Featured</div><?php endif; ?>
                            <div class="package-badge"><i class="fa-solid fa-suitcase-rolling"></i> <?= number_format($packageCount); ?> package<?= $packageCount === 1 ? '' : 's'; ?></div>
                        </div>
                    </a>
                    <div class="destination-body">
                        <div class="location-line"><i class="fa-solid fa-location-dot"></i> <?= e((string)$destination['country']); ?><?= trim((string)($destination['continent'] ?? '')) !== '' ? ' · ' . e((string)$destination['continent']) : ''; ?></div>
                        <h3><?= e((string)$destination['name']); ?></h3>
                        <p><?= e(publicDestinationExcerpt((string)($destination['short_description'] ?? $destination['description'] ?? ''))); ?></p>
                        <div class="destination-meta">
                            <div class="start-price">
                                <small><?= $startingPrice > 0 ? 'Packages from' : 'Travel options'; ?></small>
                                <strong><?= $startingPrice > 0 ? e($currencySymbol) . number_format($startingPrice, 0) : 'Explore now'; ?></strong>
                            </div>
                            <a class="view-btn" href="destinations.php?destination=<?= (int)$destination['id']; ?>">View Packages <i class="fa-solid fa-arrow-right"></i></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php elseif ($pageError === ''): ?>
        <div class="empty">
            <i class="fa-solid fa-map-location-dot"></i>
            <h3>No destination found</h3>
            <p>Try another destination name or clear the current filters.</p>
            <p style="margin-top:16px"><a class="view-btn" href="destinations.php">Show all destinations</a></p>
        </div>
    <?php endif; ?>
</main>

<section class="cta">
    <div class="cta-content">
        <h2>Can’t find the trip you want?</h2>
        <p>Tell TRAVSCOPE your destination, dates and traveller requirements. Our team can build a custom travel plan for you.</p>
    </div>
    <a href="contact.php"><i class="fa-regular fa-message"></i> Plan a Custom Trip</a>
</section>

<?php require_once __DIR__ . '/credential-display.php'; echo tsCredentialsHtml($pdo, 'website'); ?>
<footer class="footer">
    <div class="footer-inner">
        <div><strong>TRAVSCOPE.COM</strong><p>Explore more. Experience more. Remember more.</p></div>
        <p>&copy; <?= date('Y'); ?> TRAVSCOPE.COM. All rights reserved.</p>
    </div>
</footer>

<script>
const mobileMenu = document.getElementById('mobileMenu');
const mobileNav = document.getElementById('mobileNav');
if (mobileMenu && mobileNav) {
    mobileMenu.addEventListener('click', function () {
        mobileNav.classList.toggle('open');
    });
}
</script>

<script src="assets/travscope-pro-public.js?v=20260916-pro" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
</body>
</html>
