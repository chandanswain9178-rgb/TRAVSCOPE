# V25.26 colourful public presentation verification

- Exact seven product source hashes match final verification and independent semantic review.
- Four PHP files restore byte for byte to uploaded V25.24 after removing only the two stylesheet links and body classes.
- Every other one of the 357 recovered baseline runtime files retains its uploaded/recovered hash.
- Browser/HTTP: 27 passed, 0 failed; ten desktop/mobile views; forms and links unchanged; no horizontal overflow.
- Extended browser: 41 passed, 0 failed; additional responsive controls, gallery, sharing, booking calculations, print and integrity checks.
- Uploaded text layout is restored, including desktop justification and original mobile alignment; final verification records text comparisons.
- Runtime uses SELECT-only access to public catalogue data; zero private customer rows copied.
- FULL: 360 entries; 235 unchanged outer payloads, metadata and compressed records; original embedded archive bytes retained.
- All 118 flattened dependency files match the supplied assets archive and pinned runtime baseline.
- PATCH: seven products plus three documentation/patch entries; modes 0644; no SQL/config/uploads/private fixture replacements.
- Git text/binary patch reconstructs all seven products exactly; all four reconstructed PHP files pass syntax checks.

## Product SHA-256

- assets/travscope-world-catalog-v2526.css: `a801cce0f60ff4b570503e8b779a9e911c8cea7323ac8d701bd666faa640c936`
- assets/travscope-world-landscape-pexels-38441491.jpg: `9efc0fce6701e529b5834aa5829d186b723537aad462b8ef60d424461953eac3`
- assets/travscope-world-v2526.css: `fe9e9a3f17467477259ac381d421bbe6b0e5c7439420f3e26a51083e2bc9c406`
- destinations.php: `15ed4aae196bdd1eecdc0f335af484e57d69b03a62caec8f5e44fbf0850d5d65`
- index.php: `0a28165fb42e5a8bab1f10571994f277141c5027d0cd81db0dc0153e6fb815c2`
- package-details.php: `0f62a2d8c6cd11509763d89df4395fa300946a34ef42b91dd8008708485412c2`
- packages.php: `2771f14dcc8550be75ccad572f80c110610efab0ca38e97dfd58735d96ffaae4`

## verification

89 passed, 0 failed; private report SHA-256 `0d585cb1c1a0e1b6644c00a7fa57fb631630c4104e7e5fcc83dd9de5c6ff601c`.

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
- text_format.text-format.itinerary_styles_match_original_320: passed
- text_format.text-format.itinerary_text_p_br_lists_unchanged_320: passed
- text_format.text-format.overview_styles_match_original_320: passed
- text_format.text-format.overview_text_p_br_lists_unchanged_320: passed
- text_format.text-format.important-information_styles_match_original_320: passed
- text_format.text-format.important-information_text_p_br_lists_unchanged_320: passed
- text_format.text-format.original_itinerary_alignment_320: passed
- text_format.text-format.itinerary_styles_match_original_768: passed
- text_format.text-format.itinerary_text_p_br_lists_unchanged_768: passed
- text_format.text-format.overview_styles_match_original_768: passed
- text_format.text-format.overview_text_p_br_lists_unchanged_768: passed
- text_format.text-format.important-information_styles_match_original_768: passed
- text_format.text-format.important-information_text_p_br_lists_unchanged_768: passed
- text_format.text-format.original_itinerary_alignment_768: passed
- text_format.text-format.itinerary_styles_match_original_1440: passed
- text_format.text-format.itinerary_text_p_br_lists_unchanged_1440: passed
- text_format.text-format.overview_styles_match_original_1440: passed
- text_format.text-format.overview_text_p_br_lists_unchanged_1440: passed
- text_format.text-format.important-information_styles_match_original_1440: passed
- text_format.text-format.important-information_text_p_br_lists_unchanged_1440: passed
- text_format.text-format.original_itinerary_alignment_1440: passed

## semantic_review

26 passed, 0 failed; private report SHA-256 `fb522d7a010d24d75011250f281c11cbf1ca83abfc1e94bf77e99be33e1d013f`.

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
- original_text_formatting_preserved: passed
- no_action_hiding_or_interaction_overrides: passed
- credentials_logo_geometry_preserved: passed
- managed_hero_photography_preserved: passed
- chromium_css_parse.assets/travscope-world-v2526.css: passed
- screen_and_public_scope.assets/travscope-world-v2526.css: passed
- chromium_css_parse.assets/travscope-world-catalog-v2526.css: passed
- screen_and_public_scope.assets/travscope-world-catalog-v2526.css: passed
- chromium_print_media_isolation: passed

## browser_http

27 passed, 0 failed; private report SHA-256 `d0805456d3febaf1fb28e9a66442dd4d742164867a69177949667196dff548ae`.

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

## extended_browser

41 passed, 0 failed; private report SHA-256 `4155fd50085fc6eb15f706bcfb4517a5d9adcd13dbf9f907faf5c802199a85f7`.

- responsive.no_horizontal_overflow_home_320: passed
- interaction.hamburger_home_320: passed
- interaction.search_unobstructed_home_320: passed
- responsive.no_horizontal_overflow_destinations_320: passed
- interaction.hamburger_destinations_320: passed
- interaction.search_unobstructed_destinations_320: passed
- responsive.no_horizontal_overflow_destination-packages_320: passed
- interaction.hamburger_destination-packages_320: passed
- interaction.search_unobstructed_destination-packages_320: passed
- responsive.no_horizontal_overflow_packages_320: passed
- interaction.hamburger_packages_320: passed
- interaction.search_unobstructed_packages_320: passed
- responsive.no_horizontal_overflow_package-details_320: passed
- interaction.existing_detail_navigation_320: passed
- interaction.detail_gallery_open_close_320: passed
- interaction.detail_share_menu_320: passed
- interaction.mobile_booking_cta_320: passed
- responsive.no_horizontal_overflow_home_768: passed
- interaction.hamburger_home_768: passed
- interaction.search_unobstructed_home_768: passed
- responsive.no_horizontal_overflow_destinations_768: passed
- interaction.hamburger_destinations_768: passed
- interaction.search_unobstructed_destinations_768: passed
- responsive.no_horizontal_overflow_destination-packages_768: passed
- interaction.hamburger_destination-packages_768: passed
- interaction.search_unobstructed_destination-packages_768: passed
- responsive.no_horizontal_overflow_packages_768: passed
- interaction.hamburger_packages_768: passed
- interaction.search_unobstructed_packages_768: passed
- responsive.no_horizontal_overflow_package-details_768: passed
- interaction.existing_detail_navigation_768: passed
- interaction.detail_gallery_open_close_768: passed
- interaction.detail_share_menu_768: passed
- interaction.detail_booking_calculation_768: passed
- interaction.detail_booking_calculation_1440: passed
- print.new_theme_leaves_computed_styles_unchanged_home: passed
- print.new_theme_leaves_computed_styles_unchanged_destinations: passed
- print.new_theme_leaves_computed_styles_unchanged_packages: passed
- print.new_theme_leaves_computed_styles_unchanged_package-details: passed
- integrity.seven_product_files_unchanged_during_qa: passed
- integrity.catalog_database_unchanged: passed
