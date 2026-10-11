# V25.11 supplier bulk fix validation

Final report: 22 passed, 0 failed; exact two-source hashes unchanged.
Fixture: 152 cloned schema tables, zero source rows copied, owned database/prelude/sessions removed.

- FULL: 232 entries; only two existing root payloads changed; 213 original payloads, metadata and compressed records retained.
- Both supplied embedded archives remain byte for byte identical.
- Seventeen added assets/template files match the verified embedded V25.11 CRM archive and retain 0644 modes.
- PATCH: exactly two PHP files, README.md, PRODUCT_MANIFEST.json and one text patch; all modes 0644.
- Text patch passed git apply --check and reconstructed both files exactly; both reconstructed PHP files passed syntax checks.
- Current admin-packages.php and admin-crm.php retain their uploaded SHA-256 hashes.

## Verified source SHA-256

- admin-supplier-packages.php: `7ace12be0b0b92d640e7a230d510c1a8ec756ef25029e08a85850e775fd34c0f`
- package-supplier-functions.php: `e77f2301fd65f3b4e053064a8ccbb5687b8e34ff23411629954bf23105eb230a`

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
- browser.add_button_1875: passed
- browser.delete_button_1875: passed
- browser.add_works_with_native_popup_suppressed_1875: passed
- browser.delete_works_with_native_popup_suppressed_1875: passed
- browser.cancel_and_escape_preserve_data_and_restore_focus_1875: passed
- browser.confirmation_accessible_and_fits_1875: passed
- browser.add_button_390: passed
- browser.delete_button_390: passed
- browser.add_works_with_native_popup_suppressed_390: passed
- browser.delete_works_with_native_popup_suppressed_390: passed
- browser.cancel_and_escape_preserve_data_and_restore_focus_390: passed
- browser.confirmation_accessible_and_fits_390: passed

## supplier-focused-current.json

13 passed, 0 failed; same two-source snapshot, schema-only fixture and complete cleanup.

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

## supplier-security-current.json

23 passed, 0 failed; same two-source snapshot, read-only security review.

- php_syntax_admin-supplier-packages.php: passed
- php_syntax_package-supplier-functions.php: passed
- actual_otp_classifier_hidden_delete: passed
- actual_otp_classifier_legacy_selected_delete: passed
- actual_otp_classifier_legacy_single_delete: passed
- actual_otp_classifier_draft_add: passed
- actual_otp_classifier_blank_hidden_action: passed
- otp_hash_binds_selected_ids_and_ignores_only_approval_token: passed
- preserved_uploaded_admin-crm.php: passed
- preserved_uploaded_admin-packages.php: passed
- preserved_uploaded_config.php: passed
- preserved_uploaded_admin-delete-otp-functions.php: passed
- preserved_uploaded_admin-delete-otp.php: passed
- active_admin_and_csrf_required: passed
- whitelisted_hidden_action_and_confirmed_hidden_delete: passed
- named_submitters_and_selection_preserved: passed
- draft_import_status_and_no_fabricated_prices: passed
- active_reference_price_source_constraints: passed
- bulk_scope_dedup_and_transaction_ownership: passed
- deletion_history_and_master_preservation_unchanged: passed
- popup_independent_accessible_confirmation: passed
- cancel_does_not_submit_or_retain_confirmation: passed
- progress_and_result_near_buttons: passed

Synthetic private fixtures only; no live hosting, customer records or external email delivery were tested.
