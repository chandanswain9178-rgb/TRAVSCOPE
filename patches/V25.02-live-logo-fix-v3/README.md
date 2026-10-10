TRAVSCOPE V25.02 LIVE LOGO FIX V3 — deployment instructions

This package has not been deployed to the live website.

Live public inspection on 10 October 2026 found that the homepage still loads
assets/credentials-v2501.css, which returns HTTP 403 Forbidden. The homepage
has neither the V1 inline credential stylesheet nor the V2 inline image bounds.
Its trust logos therefore inherit the page's full-width image rule.
Several responsive/experience CSS and JavaScript files also return HTTP 403.

The supplied V25.02 archive stores these static files with Unix permissions
0600 (owner-only). Successfully served public assets have broader read access.
This makes file permissions a likely cause; the live server's ownership and
configuration have not been inspected or verified.

V3 retains exactly the V2 application code. It provides Unix 0644 permission
metadata for the two fixed PHP files and affected public CSS/JavaScript files.
The small ZIP includes an assets/ directory entry with permissions 0755.
There are no database, configuration, credentials, or runtime files in the
small patch package. No database import or migration is required.

DEPLOY THE SMALL PATCH TO THE LIVE WEBSITE

1. In your hosting file manager, locate the actual document root serving
   https://travscope.com/ (often public_html). Confirm that index.php,
   admin-crm.php, credential-display.php, and the assets directory are there.
   Uploading into a versioned folder or a GitHub repository does not replace
   the live files served by that domain.
2. Back up the existing files listed below before replacing them. Keep that
   backup outside the public document root.
3. Upload and extract TRAVSCOPE_V25_02_LIVE_LOGO_FIX_V3.zip into that document
   root, preserving paths and replacing the listed files. admin-crm.php and
   credential-display.php must be at the same level as the live index.php.
4. In the hosting file manager, verify public CSS/JavaScript file permissions
   are 0644 and their containing directories are 0755. Set them manually if
   extracting or overwriting keeps the previous permissions. The two updated
   PHP files should also be 0644, subject to your host's PHP requirements.
   Do not apply a recursive permission change to config.php, SQL backups,
   private directories, or the rest of the website.
5. Clear any hosting/CDN page cache and refresh the homepage. Open
   https://travscope.com/assets/credentials-v2501.css?v=2502 directly: it should
   return HTTP 200 with CSS content, not a Forbidden page. Inspect the live
   page source for an inline .ts-credentials stylesheet and trust images with
   width="45", height="24", and inline max-width:45px/max-height:24px.
6. Check the homepage and the quotation preview, then generate a NEW official
   quotation PDF. Previously downloaded or saved PDFs retain the old layout;
   uploading this patch cannot change them. Trust marks in the newly generated
   document should remain small and wrap across rows. If assets still return
   403 after
   permissions are corrected, ask the host to inspect static-file ownership,
   access rules, and the hosting error log; this package does not change server
   configuration or guarantee that permissions are the only cause of HTTP 403.

FILES TO BACK UP AND REPLACE

- admin-crm.php
- credential-display.php
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
- site-brand-sync.js

The included TRAVSCOPE_V25_02_SMALL_TRUST_LOGOS_FIX_V2.patch is the original
V2 text patch against the original V25.02 source. It documents the PHP code
changes only. It does not correct the public asset permissions; use the ZIP
and the permission checks above for live deployment.

FULL WEBSITE ARCHIVE

TRAVSCOPE_CURRENT_V25_02_LIVE_LOGO_FIX_V3_FULL.zip preserves every entry's file
bytes and all other ZIP metadata from the V2 full archive. Only permissions
for outer CSS/JavaScript files and the two replacement PHP files are set to
0644. The nested assets ZIP is unchanged. Existing config.php, other PHP files,
.htaccess, and all other contents are unchanged. Use the targeted small patch
for an existing live installation, preserving the live configuration/uploads.
This README is a companion file and is included in the small patch ZIP; it is
not inserted into the full archive, preserving that archive's entry contents.
