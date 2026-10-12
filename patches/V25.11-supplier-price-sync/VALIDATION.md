# V25.11 supplier price sync validation

Final HTTP / Chromium report: 26 passed, 0 failed; exact three-source hashes unchanged.
Fixture: 152 cloned schema tables, zero source rows copied, owned database/prelude/sessions removed.
Focused concurrency checks use MariaDB 11.8 private-session innodb_snapshot_isolation=OFF for MySQL-compatible REPEATABLE READ current locking reads. Normal HTTP / Chromium checks use the unchanged default ON setting; no global database or production application configuration is changed.

- FULL: 232 entries; only three existing root payloads changed; 212 original payloads, metadata and compressed records retained.
- Both supplied embedded archives remain byte for byte identical.
- Seventeen added assets/template files match the verified embedded V25.11 CRM archive and retain 0644 modes.
- PATCH: exactly three PHP files, README.md, PRODUCT_MANIFEST.json and one text patch; all modes 0644.
- Text patch passed git apply --check and reconstructed all three files exactly; all three reconstructed PHP files passed syntax checks.
- Current admin-crm.php retains its uploaded SHA-256 hash.
- The previous admin-supplier-packages.php confirmation fix retains its verified SHA-256 hash.

## Verified source SHA-256

- admin-packages.php: `b4919f258bfbc41a95b141e33f0552e76e84929e7a7ecb8b1893f24b3aed2c30`
- admin-supplier-packages.php: `7ace12be0b0b92d640e7a230d510c1a8ec756ef25029e08a85850e775fd34c0f`
- package-supplier-functions.php: `a6259caf4b44a4de5df8264c28bda50e58c23674bc8754a83d25a3b97d90b23e`

## Final behavior checks

- http.actual_uploaded_six_draft_bulk_import: passed
- http.repeated_import_is_idempotent: passed
- http.actual_uploaded_bulk_delete: passed
- http.supplier_delete_preserves_linked_master: passed
- http.hidden_action_fallback_preserves_selected_ids: passed
- http.hidden_delete_requires_confirmation: passed
- http.both_actions_reject_invalid_csrf: passed
- http.anonymous_bulk_actions_rejected: passed
- http.enabled_delete_otp_replays_exact_hidden_selection_once: passed
- http.enabled_delete_otp_rejects_altered_selection: passed
- http.six_saved_review_supplier_prices_import_exactly: passed
- http.existing_five_prices_recover_without_changing_28400: passed
- browser.add_button_1875: passed
- browser.delete_button_1875: passed
- browser.add_works_with_native_popup_suppressed_1875: passed
- browser.delete_works_with_native_popup_suppressed_1875: passed
- browser.cancel_and_escape_preserve_data_and_restore_focus_1875: passed
- browser.confirmation_accessible_and_fits_1875: passed
- browser.first_visit_shows_six_100_percent_correct_prices_1875: passed
- browser.add_button_390: passed
- browser.delete_button_390: passed
- browser.add_works_with_native_popup_suppressed_390: passed
- browser.delete_works_with_native_popup_suppressed_390: passed
- browser.cancel_and_escape_preserve_data_and_restore_focus_390: passed
- browser.confirmation_accessible_and_fits_390: passed
- browser.first_visit_shows_six_100_percent_correct_prices_390: passed

## security-review.json

28 passed, 0 failed; same three-source snapshot, read-only security review.

- reviewed_exact_frozen_source_hashes: passed
- preserved_current_release_tree_scope: passed
- byte_preserved_config.php: passed
- byte_preserved_admin-delete-otp.php: passed
- byte_preserved_admin-delete-otp-functions.php: passed
- byte_preserved_admin-crm.php: passed
- byte_preserved_admin-supplier-packages.php: passed
- php_lint_admin-supplier-packages.php: passed
- php_lint_package-supplier-functions.php: passed
- php_lint_admin-packages.php: passed
- default_public_active_rate_query_unchanged: passed
- existing_price_conversion_periods_unchanged: passed
- draft_review_rate_branch_scoped_to_actual_source: passed
- currency_duration_package_code_boundaries: passed
- positive_exact_pax_saved_rate_only: passed
- manual_positive_and_existing_date_prices_preserved: passed
- placeholder_removal_atomic_and_only_after_real_rates: passed
- contract_then_package_lock_and_current_source_reads: passed
- caller_transaction_ownership_and_exception_propagation: passed
- new_import_changes_only_saved_rate_repair_call: passed
- content_sync_only_adds_contract_first_lock: passed
- package_master_only_four_local_price_readiness_locations_changed: passed
- real_nine_check_readiness_score_preserved: passed
- first_visit_render_refresh_uses_preserved_price_precedence: passed
- supplier_buttons_auth_csrf_otp_compatibility_preserved: passed
- package_bulk_delete_and_pdf_button_removal_preserved: passed
- sources_unchanged_during_readonly_review: passed
- original_uploaded_baseline_retained_before_small_price_diff: passed

## supplier-focused.json

39 passed, 0 failed; same three-source snapshot, schema-only fixture and complete cleanup.

- legacy_three_argument_import_rejects_review_without_writes: passed
- explicit_review_import_preserves_source_disables_quote_and_invents_no_rates: passed
- unicode_short_description_fits_schema_and_full_description_survives: passed
- caller_transaction_remains_owned_and_rolls_back_all_import_children: passed
- failed_child_write_preserves_caller_transaction_and_owned_call_rolls_back: passed
- active_without_rates_imports_draft_without_fabricated_prices: passed
- active_exact_pax_reference_prices_copy_under_strict_sql_mode: passed
- reference_price_rebuild_excludes_draft_contract_review_itinerary_inactive_supplier: passed
- reference_prices_exclude_non_exact_pax_and_zero_rates: passed
- candidate_prefers_active_content_over_higher_priority_review: passed
- bulk_validates_supplier_scope_and_rejects_malformed_selection: passed
- repeated_bulk_import_and_inactive_existing_link_do_not_duplicate: passed
- two_bulk_connections_wait_for_contract_then_create_once: passed
- draft_saved_review_rates_copy_exact_own_prices_without_activation: passed
- already_imported_draft_repairs_rates_saved_later_and_is_idempotent: passed
- repair_preserves_positive_manual_sale_price: passed
- repair_preserves_positive_manual_regular_price: passed
- repair_preserves_manual_date_prices_and_does_not_duplicate: passed
- repair_rejects_unrelated_supplier_contract_for_imported_package: passed
- repair_rejects_manual_unlinked_package: passed
- repair_rejects_target_currency_mismatch: passed
- repair_rejects_rate_group_currency_mismatch: passed
- repair_rejects_inconsistent_group_supplier: passed
- repair_rejects_inconsistent_pax_itinerary: passed
- repair_rejects_inconsistent_pax_version: passed
- draft_repair_excludes_inactive_group: passed
- draft_repair_excludes_inactive_pax: passed
- draft_repair_excludes_rejected_version: passed
- draft_repair_excludes_inactive_supplier: passed
- draft_repair_excludes_zero_rate: passed
- draft_repair_excludes_pax_range: passed
- draft_repair_excludes_unmapped_category: passed
- draft_repair_preserves_caller_transaction_and_rolls_back_prices: passed
- failed_reference_insert_rolls_back_owned_repair_and_preserves_caller: passed
- reference_repair_waits_for_supplier_contracts_lock_and_creates_once: passed
- reference_repair_waits_for_packages_lock_and_creates_once: passed
- review_caller_repeatable_read_snapshot_does_not_duplicate_committed_repair: passed
- review_caller_repeatable_read_rechecks_withdrawn_source_rates: passed
- review_content_sync_locks_source_contract_before_target_package: passed

Synthetic private fixtures only; no live hosting, customer records or external email delivery were tested.
