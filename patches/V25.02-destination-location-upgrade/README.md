# TRAVSCOPE V25.02 — Destinations and Cities & Locations upgrade

This release keeps version V25.02 and includes the approved admin directory improvements plus the earlier small credential-logo and public-file permission fixes.

## What changes

- Destinations uses six clear columns with package totals, linked location counts, active/featured visibility, and compact Edit, Manage Cities and More actions.
- Country/continent/featured filters retain valid context. Missing destination images show deliberate placeholders, and records needing geography or media attention are identified for administrators.
- Cities & Locations shows counts for the selected destination, a compact Add Location form, and search/status/source filters. Administrators can view inactive locations and explicitly change their status.
- Location discovery and sync preserve saved inactive choices. Add and sync operations report their actual outcome. Destination deletion checks linked packages, locations and hotels.
- Credential logos retain the preceding screen and PDF size constraints. The patch also includes the preceding public CSS/JS permission corrections.

## Install on an existing website

1. Back up the 17 listed product files before replacing them. Keep a normal database and uploads backup.
2. Extract the PATCH ZIP on your computer. Copy its PHP files, site-brand-sync.js and assets files into the actual website document root: the folder containing the live index.php. Overwrite the matching files and retain the assets folder structure.
3. Set replacement files to 0644 and the assets directory to 0755 if your uploader changes permissions. Do not copy this release into a second nested project folder.
4. Refresh browser/cache assets, log out and sign in again if necessary. Open Destinations and Cities & Locations, test an ordinary add/edit/status change, and regenerate a quotation PDF to use the included logo rendering fixes.

No SQL import or migration is required by this patch. It contains no configuration file, SQL dump, uploads, private runtime, development login route or database credentials. Your existing configuration and data remain in place.

The FULL ZIP is the complete 219-entry source archive with the three upgraded admin files over the previous V3 full release. It preserves the original bundled project configuration and nested assets archive. For a fresh installation, unpack assets_2026-09-30_17:12:26.zip first, then copy the outer archive files last so the current PHP and public assets take precedence. Deploy into the document root containing index.php and use your hosting configuration. Installing only this code archive does not import a database.

## Patch files

- TRAVSCOPE_V25_02_DESTINATION_LOCATION_UPGRADE_FROM_ORIGINAL.patch is a text patch for the five changed PHP files relative to the original V25.02 archive.
- TRAVSCOPE_V25_02_DESTINATION_LOCATION_UPGRADE_FROM_V3.patch is a text patch for the three changed PHP files relative to the preceding V3 full archive.
- PRODUCT_MANIFEST.json records source hashes and the 17 replacement-file hashes and modes.

The replacement-file ZIP is the complete upgrade path from original V25.02: it also carries unchanged V3 CSS/JS files so their public read permissions can be corrected. A text patch changes code only; set public-file permissions separately if using that installation method.

## Product files in the PATCH ZIP

- admin-crm.php
- admin-destination-locations.php
- admin-destinations.php
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
- site-brand-sync.js

## Validation scope

Release packaging checks verify ZIP CRCs, source hashes, 219 full-archive entries, safe targeted patch contents, exact text-patch application and PHP syntax. The three source hashes match the independent code-review snapshot.

The browser/HTTP verification uses an isolated synthetic development database. Real external city-service availability, email delivery and the production server are outside that fixture. Publishing these ZIPs does not deploy the live website.
