TRAVSCOPE V25.02 credential/trust logo print fix

The shared website and customer quotation trust logo block depended on a separate CSS request. If that stylesheet was unavailable, the homepage full-width image rule enlarged marks across multiple printed pages.

The replacement credential-display.php includes scoped layout styles with its markup. Print logos fit within 12mm x 7mm and labels wrap in compact rows. Upload this single PHP file to the website root, replacing the existing credential-display.php after keeping a backup. No database migration is needed.

The full ZIP is the original V25.02 package with only credential-display.php changed. The nested assets ZIP and all other source files are unchanged. No uploaded SQL dump or cloud runtime configuration was added. The separate admin-crm.php official quotation PDF renderer is unchanged.

Verified: 24 Chromium layout checks with external CSS requests blocked, 5 and 30 synthetic logos (including 120-character labels), mobile overflow checks, one-page A4 credential blocks in website and document layouts; 33 credential eligibility/escaping checks with permanent settings unchanged; 8 functional public-page checks; PHP syntax. Browser-generated sample PDFs use synthetic shapes, not customer data.

For Git application, run git apply --check TRAVSCOPE_V25_02_CREDENTIAL_LOGO_PRINT_FIX.patch and git apply TRAVSCOPE_V25_02_CREDENTIAL_LOGO_PRINT_FIX.patch from a directory containing the original V25.02 credential-display.php.

This patch is prepared locally. It has not been pushed to GitHub or deployed to travscope.com.
