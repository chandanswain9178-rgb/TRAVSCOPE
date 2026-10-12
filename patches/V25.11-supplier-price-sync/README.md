# TRAVSCOPE V25.11 supplier price sync

This cumulative release restores actual selling prices already saved in Supplier Packages / Rates when eligible supplier packages are copied into Package Master drafts. It retains the previous on-page Cancel / Confirm controls for Add Selected to Package Master and Delete Selected.

Saved positive supplier rates for exact PAX counts, mapped to 3-, 4- or 5-star categories, are copied into Draft reference pricing only from the package's own active/review source rates, with matching source and target currency. A supplier Draft / review source stays unchanged and its imported Master stays a Draft with quotation disabled. Missing supplier rates are not invented. Current positive manual selling prices and manual date prices are preserved.

Opening Package Master repairs eligible previously imported drafts whose saved supplier prices are missing. The package directory displays the repaired price and recalculates readiness on that first visit. Readiness still reflects the package's actual data; packages without an eligible positive selling price remain incomplete.

Supplier deletion retains the current authentication, OTP and retained-history protections. The V25.11 CRM pipeline file retains its uploaded bytes. The current Package Master PDF-button removal and selected-delete controls are preserved within the targeted readiness display update.

## Install the three-file patch on the current website

1. Back up admin-supplier-packages.php, package-supplier-functions.php and admin-packages.php, and keep your normal database and uploads backups.
2. Extract the PATCH ZIP locally. Upload only these three PHP files into the live website document root, the folder containing index.php, overwriting their current versions. Set all three replacement files to 0644.
3. Reload Package Master. Check that the five previously imported drafts recover their own saved eligible supplier prices and that an existing manual price remains unchanged. Check both supplier bulk buttons using the on-page Cancel and Confirm controls.

No SQL migration is required for this three-file patch. The PATCH ZIP contains exactly three PHP replacement files, this README, PRODUCT_MANIFEST.json and one FROM_CURRENT_WEBSITE.patch. It contains no configuration, SQL, uploads, customer records or private development fixtures.

The text patch applies only to the uploaded current website baseline identified in PRODUCT_MANIFEST.json. It changes all three PHP files cumulatively, including the prior supplier-button fixes. Apply it with git apply --check first; set file permissions to 0644 separately when using this method. Direct PHP replacement can be used after the previous two-file button patch.

## Full source archive and fresh installation

The FULL ZIP contains 232 entries: all 215 uploaded outer entries, with only the three PHP replacements, plus seventeen verified current assets/template files recovered byte for byte from the embedded V25.11 CRM archive. The remaining 212 outer payloads, ZIP metadata and compressed records, including both existing embedded archives, are retained. The embedded CRM ZIP is the older backup supplied in the upload; the outer root files are authoritative.

For a fresh installation, unpack assets_2026-09-30_17:12:26.zip first, then copy the FULL ZIP's outer files and the seventeen added assets/template files last so current outer files override older assets. Deploy into the document root containing index.php. Review and configure the original bundled configuration for the target host. This source archive does not import a database.

## Verification scope

The release must match final HTTP / Chromium verification and required focused behavior and independent security reports, with positive passing-check counts, zero failures and unchanged hashes for all three sources. Behavior verification uses 152 schema-only fixture tables, zero customer rows copied and complete owned-fixture cleanup; the security report is a read-only source review. Packaging verifies both ZIP CRCs, all unchanged upload payloads and metadata, both embedded archive hashes, the seventeen recovered files, exact text-patch reconstruction and PHP syntax for all three reconstructed files.

Focused concurrency checks on MariaDB 11.8 use innodb_snapshot_isolation=OFF only in their private test sessions to exercise MySQL-compatible REPEATABLE READ current locking reads. MariaDB's default ON setting can raise error 1020 when a locking read encounters a row changed after the session established a snapshot. The HTTP / Chromium checks use the server's unchanged default setting. Global database settings and production application configuration are unchanged.

Verification uses private synthetic fixtures. Publishing downloads does not deploy the live website or verify production hosting, external email delivery or production customer data.
