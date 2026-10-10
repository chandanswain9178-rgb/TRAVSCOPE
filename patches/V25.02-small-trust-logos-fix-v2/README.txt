TRAVSCOPE V25.02 — SMALL TRUST LOGOS FIX V2

This revision replaces the first shared-only fix. It updates BOTH the official quotation preview/download template in admin-crm.php and the website/customer-quotation renderer in credential-display.php.

APPLY THIS PATCH
1. Keep backup copies of your existing admin-crm.php and credential-display.php.
2. Upload BOTH replacement PHP files into the website root (the same directory as config.php), overwriting those two files. Uploading the ZIP alone does not install its contents.
3. Reopen the quotation preview and generate a NEW quotation PDF. Previously saved PDF files retain their old layout.
No SQL migration is required. This ZIP contains no database backup or development runtime/configuration.

WHY THE FIRST FIX MISSED THIS
The official quotation used its own flex layout and larger image sizing. The shared renderer file was not used for that path. Reproduction in Dompdf 3.1.6 showed wide logos about42mm and30marks over3pages. V2 uses PDF-compatible inline-block cards, constrains images by both width and height, and wraps120-character labels including unbroken strings. On-screen images fit45px x24px; printed marks fit approximately12mm x7mm (browser image paint can round by oneCSSpixel).

VERIFICATION
92 actualCRMtemplate layout assertions passed in Chromium and Dompdf3.1.6 using5/30 synthetic logos; short,120-character and120-character unbroken labels; mobile width; one-page credential blocks; image/text bounds; all labels retained. 24 shared-layout checks passed with external CSS requests blocked. 33 credential safety/eligibility checks passed with permanent settings unchanged. 8 functional public-page checks passed and both edited PHP files passed lint. mPDF, wkhtmltopdf and live travscope.com deployment were not verified. The development task cannot access the live domain through its current network policy.

ARCHIVES AND PATCH BASE
The full website ZIP preserves every original V25.02 entry and nested assets ZIP; only admin-crm.php and credential-display.php changed. This small ZIP replaces those files on either original V25.02 or a V25.02 installation with the first patch. The text .patch is based on the ORIGINAL V25.02 files; gitapply--check verifies that base. Original archives and private database backups are preserved.

DOWNLOADS
The revision is published on GitHub branch codex/v25-02-logo-print-download. Deployment to travscope.com requires uploading the replacement files as described above.
