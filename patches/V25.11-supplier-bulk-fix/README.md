# TRAVSCOPE V25.11 supplier bulk button fix

This release fixes the two Supplier Packages bulk buttons on the actual uploaded current website. Add Selected to Package Master and Delete Selected use an on-page confirmation with Cancel and Confirm. The actions work when native browser confirmation popups are blocked, and report their result near the supplier package buttons.

Selected eligible supplier packages can create editable Package Master drafts, including Draft / Import sources without PAX rates. The import preserves content and source links, does not fabricate prices or rates, and keeps draft/review sources disabled for quotation. Duplicate selections and existing links are deduplicated per supplier contract. Short descriptions respect the existing UTF-8 255-character database limit while full content remains available.

Supplier deletion retains the current authentication, OTP and retained-history protections. This release does not disable OTP or replace the existing deletion backend. The current Package Master PDF-button removal, Delete Selected behavior and V25.11 CRM pipeline code retain their uploaded bytes.

## Install the two-file patch on the current website

1. Back up admin-supplier-packages.php and package-supplier-functions.php, and keep your normal database and uploads backups.
2. Extract the PATCH ZIP locally. Upload only these two PHP files into the live website document root, the folder containing index.php, overwriting their current versions. Set both replacement files to 0644.
3. Reload Supplier Packages, select ordinary test packages, and check Add Selected to Package Master and Delete Selected using the on-page Cancel and Confirm controls. Results appear beside the supplier package actions.

No SQL migration is required for this two-file patch. The PATCH ZIP contains exactly two PHP replacement files, this README, PRODUCT_MANIFEST.json and one FROM_CURRENT_WEBSITE.patch. It contains no configuration, SQL, uploads, customer records or private development fixtures.

The text patch applies only to the uploaded current website baseline identified in PRODUCT_MANIFEST.json. It changes both supplier PHP files. Apply it with git apply --check first; set file permissions to 0644 separately when using this method.

## Full source archive and fresh installation

The FULL ZIP contains 232 entries: all 215 uploaded outer entries, with only the two supplier PHP replacements, plus seventeen verified current assets/template files recovered byte for byte from the embedded V25.11 CRM archive. All other outer payloads and ZIP metadata, including both existing embedded archives, are retained. The embedded CRM ZIP is the older backup supplied in the upload; the outer root files are authoritative.

For a fresh installation, unpack assets_2026-09-30_17:12:26.zip first, then copy the FULL ZIP's outer files and the seventeen added assets/template files last so current outer files override older assets. Deploy into the document root containing index.php. Review and configure the original bundled configuration for the target host. This source archive does not import a database.

## Verification scope

The release must match the final two-source verification and the required focused behavior and independent security reports, with positive passing-check counts, zero failures and unchanged source hashes. Behavior verification uses 152 schema-only fixture tables, zero customer rows copied and complete owned-fixture cleanup; the security report is a read-only source review. Packaging verifies both ZIP CRCs, all unchanged upload payloads and metadata, both embedded archive hashes, the seventeen recovered files, exact text-patch reconstruction and PHP syntax for the two reconstructed files.

Verification uses private synthetic fixtures. Publishing downloads does not deploy the live website or verify production hosting, external email delivery or production customer data.
