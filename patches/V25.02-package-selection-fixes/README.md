# TRAVSCOPE V25.02 — Package Selection Fixes

This release keeps V25.02 and includes the preceding location deletion, country/continent selection, admin directory, credential-logo and public-file permission improvements.

## What changes

- Package Master adds row checkboxes, a selected count, Select all listed packages and a confirmed Delete selected action. Only selected packages are requested for deletion; the current list/filter context is preserved.
- Selected deletion is atomic. Booking, enquiry, quotation and retained history references prevent the whole selection from being deleted. Invalid, missing or stale selections fail safely. Current active-admin access, OTP authentication and CSRF protections remain enforced, and existing row deletion remains available.
- Package Master removes the Browse Packages PDF button only.
- Eligible selected supplier packages, including drafts and packages without PAX rates, can create editable Package Master drafts with their itinerary/content and supplier source links. Sources must belong to an active supplier and have an active or draft contract with active or review version/itinerary content. Inactive or expired sources are skipped. Missing prices or PAX rates are not invented; draft/review source links remain disabled for quotation and source status is preserved.
- Bulk supplier creation deduplicates selections and uses locking to protect source links against concurrent duplicate creation. Already linked packages are reported rather than duplicated. Supplier short descriptions fit the existing database column limit while full descriptions are preserved.
- Previous destination and location code, five previous PHP replacement files and twelve public CSS/JS replacement files retain their preceding release bytes.

## Install on an existing website

1. Back up the 21 listed product files and keep a normal database/uploads backup. The new package-delete-functions.php helper must be installed with the other PHP files.
2. Extract the PATCH ZIP on your computer. Copy its PHP files, site-brand-sync.js and assets files into the live document root containing index.php. Overwrite matching files and retain the assets folder structure.
3. Set replacement files to 0644 and public directories to 0755 if your uploader changes permissions. Refresh browser/cache assets and sign in again if necessary.
4. Check selection counts and confirmation in Package Master, verify a linked package cannot be deleted, and create/edit a supplier draft in Package Master. Regenerate a quotation PDF to retain the preceding logo rendering improvements.

No SQL import or migration is required. The PATCH ZIP includes no configuration, SQL dump, uploads, private runtime, test source, development login route or database credentials. Existing configuration and data remain in place.

The FULL ZIP is the complete 220-entry source archive: the preceding 219 entries with three upgraded package sources and the new package-delete-functions.php helper. It retains the original bundled project configuration and nested assets ZIP. For a fresh installation, unpack assets_2026-09-30_17:12:26.zip first, then copy the outer archive files last so current PHP and public assets take precedence. Deploy into the document root containing index.php and use the hosting configuration. This code archive does not import a database.

## Text patches and manifests

- TRAVSCOPE_V25_02_PACKAGE_SELECTION_FIXES_FROM_ORIGINAL.patch upgrades nine PHP files from the original V25.02 archive, including the new helper from /dev/null.
- TRAVSCOPE_V25_02_PACKAGE_SELECTION_FIXES_FROM_LOCATION_DELETE.patch upgrades the four package PHP files from the preceding location deletion archive, including the new helper from /dev/null.
- PRODUCT_MANIFEST.json records the four verified source hashes, preserved prior source hashes, all 21 replacement-file hashes and modes, and the text-patch hashes.
- RELEASE_MANIFEST.json records artifact hashes and packaging validation. VALIDATION.md records the final behavior report used for this release.

The targeted ZIP provides the complete upgrade from original V25.02 and carries the twelve preceding CSS/JS files for their public read permissions. A text patch changes code; set public-file permissions separately when installing that way.

## Product files in the PATCH ZIP

- admin-crm.php
- admin-destination-locations.php
- admin-destinations.php
- admin-packages.php
- admin-supplier-packages.php
- assets/credentials-v2501.css
- assets/crm-itinerary-studio-v2497.css
- assets/crm-itinerary-studio-v2497.js
- assets/crm-laptop-workspaces-v2498.css
- assets/crm-laptop-workspaces-v2498.js
- assets/travscope-experience-v2500.css
- assets/travscope-experience-v2500.js
- assets/travscope-responsive-admin-v2499.css
- assets/travscope-responsive-core-v2499.css
- assets/travscope-responsive-public-v2499.css
- assets/travscope-responsive-v2499.js
- credential-display.php
- location-master-functions.php
- package-delete-functions.php
- package-supplier-functions.php
- site-brand-sync.js

## Validation scope

The final behavior report must pass at least one check with zero failures, complete fixture cleanup and retain unchanged source hashes during the run. The release builder verifies those hashes against all four current package files, checks both ZIPs' CRCs, counts 220 full entries and 21 targeted product files, preserves previous payloads/metadata, applies and reconstructs both text patches exactly, and lints every reconstructed PHP file.

Behavior verification uses an isolated synthetic development database. Production data, live hosting, external email delivery and real external service availability are outside that fixture. Publishing these downloads does not deploy the website.
