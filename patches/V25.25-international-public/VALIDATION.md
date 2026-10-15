# V25.25 public presentation verification

- Exact seven product source hashes match final verification and independent semantic review.
- Four PHP files restore byte for byte to uploaded V25.24 after removing only the two stylesheet links and body classes.
- Every other one of the 357 recovered baseline runtime files retains its uploaded/recovered hash.
- Browser/HTTP: 27 passed, 0 failed; ten desktop/mobile views; forms and links unchanged; no horizontal overflow.
- Runtime uses SELECT-only access to public catalogue data; zero private customer rows copied.
- FULL: 360 entries; 235 unchanged outer payloads, metadata and compressed records; original embedded archive bytes retained.
- All 118 flattened dependency files match the supplied assets archive and pinned runtime baseline.
- PATCH: seven products plus three documentation/patch entries; modes 0644; no SQL/config/uploads/private fixture replacements.
- Git text/binary patch reconstructs all seven products exactly; all four reconstructed PHP files pass syntax checks.

## Product SHA-256

- assets/travscope-world-catalog-v2525.css: `7b2299ba5a38e9e180ae931a91b9aedc6f66f35c9e21ff1fa1432e44eb78074c`
- assets/travscope-world-landscape-pexels-38441491.jpg: `9efc0fce6701e529b5834aa5829d186b723537aad462b8ef60d424461953eac3`
- assets/travscope-world-v2525.css: `c8612ef0219b9ec777f2fa74391d2e3b2b8c585eb40f8c0dac82328022e41afd`
- destinations.php: `579fba1809b78f28c9877c853f35c0e6d2211a92723e52ec9962830e2eb8c4f2`
- index.php: `812e38a67f74f3d265675261105bf4e2c0267ad7f7a830b862ae45727a4d7505`
- package-details.php: `f6808601dd616af3bb9279089d6dcf994cf18f6a90802831ac42cda7d012a071`
- packages.php: `59bc81bb191950a39809127f252ce82a11761c03c29031d0f1883d341ec245ac`

## verification

68 passed, 0 failed; private report SHA-256 `b1aa25c658367db73763ed88c6680c0033cbc96778e0771f4b6277444bee7ee1`.

- browser_http.http.home: passed
- browser_http.http.destinations: passed
- browser_http.http.destination-packages: passed
- browser_http.http.packages: passed
- browser_http.http.package-details: passed
- browser_http.http.destination_card_link: passed
- browser_http.runtime.blocks_mutating_posts: passed
- browser_http.runtime.no_private_customer_rows: passed
- browser_http.browser.render_home_1440: passed
- browser_http.browser.render_destinations_1440: passed
- browser_http.browser.render_destination-packages_1440: passed
- browser_http.browser.render_packages_1440: passed
- browser_http.browser.render_package-details_1440: passed
- browser_http.browser.home_destination_search_1440: passed
- browser_http.browser.destination_search_1440: passed
- browser_http.browser.package_destination_filter_1440: passed
- browser_http.browser.booking_form_preserved_1440: passed
- browser_http.browser.render_home_390: passed
- browser_http.browser.render_destinations_390: passed
- browser_http.browser.render_destination-packages_390: passed
- browser_http.browser.render_packages_390: passed
- browser_http.browser.render_package-details_390: passed
- browser_http.browser.home_destination_search_390: passed
- browser_http.browser.destination_search_390: passed
- browser_http.browser.package_destination_filter_390: passed
- browser_http.browser.booking_form_preserved_390: passed
- browser_http.regression.public_forms_and_links_match_baseline: passed
- extended.responsive.no_horizontal_overflow_home_320: passed
- extended.interaction.hamburger_home_320: passed
- extended.interaction.search_unobstructed_home_320: passed
- extended.responsive.no_horizontal_overflow_destinations_320: passed
- extended.interaction.hamburger_destinations_320: passed
- extended.interaction.search_unobstructed_destinations_320: passed
- extended.responsive.no_horizontal_overflow_destination-packages_320: passed
- extended.interaction.hamburger_destination-packages_320: passed
- extended.interaction.search_unobstructed_destination-packages_320: passed
- extended.responsive.no_horizontal_overflow_packages_320: passed
- extended.interaction.hamburger_packages_320: passed
- extended.interaction.search_unobstructed_packages_320: passed
- extended.responsive.no_horizontal_overflow_package-details_320: passed
- extended.interaction.existing_detail_navigation_320: passed
- extended.interaction.detail_gallery_open_close_320: passed
- extended.interaction.detail_share_menu_320: passed
- extended.interaction.mobile_booking_cta_320: passed
- extended.responsive.no_horizontal_overflow_home_768: passed
- extended.interaction.hamburger_home_768: passed
- extended.interaction.search_unobstructed_home_768: passed
- extended.responsive.no_horizontal_overflow_destinations_768: passed
- extended.interaction.hamburger_destinations_768: passed
- extended.interaction.search_unobstructed_destinations_768: passed
- extended.responsive.no_horizontal_overflow_destination-packages_768: passed
- extended.interaction.hamburger_destination-packages_768: passed
- extended.interaction.search_unobstructed_destination-packages_768: passed
- extended.responsive.no_horizontal_overflow_packages_768: passed
- extended.interaction.hamburger_packages_768: passed
- extended.interaction.search_unobstructed_packages_768: passed
- extended.responsive.no_horizontal_overflow_package-details_768: passed
- extended.interaction.existing_detail_navigation_768: passed
- extended.interaction.detail_gallery_open_close_768: passed
- extended.interaction.detail_share_menu_768: passed
- extended.interaction.detail_booking_calculation_768: passed
- extended.interaction.detail_booking_calculation_1440: passed
- extended.print.new_theme_leaves_computed_styles_unchanged_home: passed
- extended.print.new_theme_leaves_computed_styles_unchanged_destinations: passed
- extended.print.new_theme_leaves_computed_styles_unchanged_packages: passed
- extended.print.new_theme_leaves_computed_styles_unchanged_package-details: passed
- extended.integrity.seven_product_files_unchanged_during_qa: passed
- extended.integrity.catalog_database_unchanged: passed

## semantic_review

25 passed, 0 failed; private report SHA-256 `fd9c12e72a7e60ebf3f79d49224e34fd6f1c54b1c817fe1ffd072a7e1796939c`.

- uploaded_v2524_archive_identity: passed
- uploaded_entries_match_baseline: passed
- bounded_product_scope: passed
- inverse_exact.index.php: passed
- php_lint.index.php: passed
- inverse_exact.destinations.php: passed
- php_lint.destinations.php: passed
- inverse_exact.packages.php: passed
- php_lint.packages.php: passed
- inverse_exact.package-details.php: passed
- php_lint.package-details.php: passed
- unchanged.admin: passed
- unchanged.business_auth_booking_payment: passed
- unchanged.all_original_assets: passed
- unchanged.javascript: passed
- unchanged.sql: passed
- provided_photo_reuse: passed
- no_action_hiding_or_interaction_overrides: passed
- credentials_logo_geometry_preserved: passed
- managed_hero_photography_preserved: passed
- chromium_css_parse.assets/travscope-world-v2525.css: passed
- screen_and_public_scope.assets/travscope-world-v2525.css: passed
- chromium_css_parse.assets/travscope-world-catalog-v2525.css: passed
- screen_and_public_scope.assets/travscope-world-catalog-v2525.css: passed
- chromium_print_media_isolation: passed

## browser_http

27 passed, 0 failed; private report SHA-256 `7ee692dd192b9bfa2cff05b1d656f3e7860fd8e55f57463ad491d51a31cf6e8a`.

- http.home: passed
- http.destinations: passed
- http.destination-packages: passed
- http.packages: passed
- http.package-details: passed
- http.destination_card_link: passed
- runtime.blocks_mutating_posts: passed
- runtime.no_private_customer_rows: passed
- browser.render_home_1440: passed
- browser.render_destinations_1440: passed
- browser.render_destination-packages_1440: passed
- browser.render_packages_1440: passed
- browser.render_package-details_1440: passed
- browser.home_destination_search_1440: passed
- browser.destination_search_1440: passed
- browser.package_destination_filter_1440: passed
- browser.booking_form_preserved_1440: passed
- browser.render_home_390: passed
- browser.render_destinations_390: passed
- browser.render_destination-packages_390: passed
- browser.render_packages_390: passed
- browser.render_package-details_390: passed
- browser.home_destination_search_390: passed
- browser.destination_search_390: passed
- browser.package_destination_filter_390: passed
- browser.booking_form_preserved_390: passed
- regression.public_forms_and_links_match_baseline: passed
