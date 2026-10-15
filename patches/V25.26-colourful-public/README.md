# TRAVSCOPE V25.26 colourful international customer website presentation

This revision adds brighter colours, gradients and card decoration to the customer home page, destinations, package listings and package detail page. It also corrects the previously published presentation theme's itinerary text alignment: the uploaded V25.24 text layout is restored, including desktop justification and the original mobile alignment. Text content, formatting and structure keep their uploaded source; colour and surrounding decoration provide the new appearance. Branding, managed images, package status, prices and availability retain their existing sources. The bundled landscape is an unchanged photograph from the supplied website, used behind existing managed slides as a visual fallback.

Only seven product files change: four public PHP pages, two CSS files and one supplied landscape image. Each PHP file differs from the uploaded V25.24 file only by two screen stylesheet links and body classes. Database queries, JavaScript, form fields/actions, customer booking and enquiry behavior, admin/CRM/supplier pages and print styles retain their uploaded source. No SQL migration or database import is required.

## Install the patch on V25.24 or V25.25

1. Keep a normal website/database backup and back up index.php, destinations.php, packages.php and package-details.php.
2. Extract the PATCH ZIP and upload its four root PHP files and three files in assets/ into the live document root containing index.php. Preserve the assets/ directory and set these seven files to 0644. Do not upload documentation files as application routes.
3. Refresh the browser, then check the home destination search, destinations, package filters, original itinerary text layout and a package detail/booking page on desktop and mobile. The replacement PHP pages point to new V25.26 stylesheet filenames, so older V25.25 CSS files can remain unused.

The PATCH contains exactly seven product files, README.md, PRODUCT_MANIFEST.json and a FROM_V25_24.patch. Uploading these seven complete product files works on V25.24 or the previously published V25.25 presentation release. The text patch is specifically based on uploaded V25.24, includes the JPEG as a Git binary patch, and requires git apply --check to succeed against that baseline before applying. The patch does not replace configuration or uploads and does not contain SQL, customer records, credentials or private runtime/test files.

## Full website source ZIP

The FULL ZIP has 360 files ready to extract: all 239 uploaded outer files (only the four public PHP files changed), three new presentation assets, and 118 runtime files recovered exactly from the user's own bundled assets archive. These supplied dependencies include PHPMailer, includes, error page, templates, existing assets and existing public uploads. Both original embedded backup archives remain byte for byte unchanged; current outer root files are authoritative. All 235 other original payloads, metadata and compressed ZIP records are preserved. Recovered dependencies and new/replacement product files use 0644 permissions.

For an existing live website, install the seven-file PATCH to preserve all live uploads. The FULL ZIP retains the previously bundled source configuration; review configuration for the target host when making a fresh installation. The user's separate SQL backup is never included, changed or imported by this release. Some photos referenced by the newer SQL are absent from the supplied source archive; this presentation update does not replace or invent those live uploads.

## Verification scope

Packaging requires a passing final verification report, independent semantic review, 27 browser/HTTP checks and 41 extended interaction, print and integrity checks, all covering the exact seven-file source snapshot. Final verification also records the text presentation comparisons. The browser fixture uses public catalogue data with SELECT-only database access and zero private customer rows. Before/after forms and link targets match across ten desktop/mobile views. Additional source checks reverse the presentation hooks to recover each uploaded PHP byte exactly and verify every other existing runtime file unchanged. Both ZIP CRCs, original compressed records, dependency provenance, exact binary/text patch reconstruction and PHP syntax are checked before writing artifacts.

Publishing these downloads does not deploy production hosting or change its database. Existing login, booking, payment, OTP and email services remain the user's current implementation.
