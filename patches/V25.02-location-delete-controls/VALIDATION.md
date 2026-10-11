# TRAVSCOPE V25.02 Location Delete Controls — Validation

The two edited location files match the source hashes captured by the final deletion test run. Those sources remained unchanged during verification.

| Check | Result | Scope |
| --- | --- | --- |
| Current location deletion suite | 50 passed, 0 failed | Real HTTP/browser deletion, confirmation cancellation, access and CSRF checks, destination scope, linked-record protection, all-or-nothing bulk deletion and stale-snapshot regression |
| Current admin integration rerun | 63 passed, 0 failed | Existing Destinations and Cities & Locations behavior on the frozen release source |
| Current focused two-connection probe | 1 passed, 0 failed | Additional stale-snapshot protection with a READ COMMITTED caller session; the committed dependency remains and deletion aborts |
| Current location helper checks | 44 passed, 0 failed | Existing helper and sync contracts |
| Current actual-template render checks | 13 passed, 0 failed | Existing location-page rendering and state controls |
| Current PHP syntax | Passed | Both edited location files |
| Text-patch reconstruction and syntax | Passed | Both baselines reconstruct the exact five/two expected PHP files; all seven reconstructed files pass syntax checks |
| Earlier geography selection suite | 19 passed, 0 failed | The destination source retains the exact earlier country/continent release bytes; this suite was not repeated for the deletion release |

The focused one-case probe strengthens the concurrency regression in the 50-case suite and is recorded separately. The current deletion, focused probe and admin integration fixture reports confirm their cleanup steps completed. Public release files contain aggregate results only.

## Source identity

| File | SHA256 | Role |
| --- | --- | --- |
| admin-destination-locations.php | d83fb40f5c02ba8f8b27ea67676a5fa88d3dbae652fca3976e103abc90ad26ed | Edited and reviewed |
| location-master-functions.php | de2d8863092b809264f83238821c4e699010695263ba099a3e3ba1d8cd0bf956 | Edited and reviewed |
| admin-destinations.php | d7fbf45ef1ba4cdc2e4d3529331b1ee57aebbbf3b80ff490c682b79af704e00a | Preserved country/continent release source |

The edited-file hashes match the app, review copies, full ZIP, targeted ZIP, manifests and final deletion report. The preserved destination hash matches the app, both ZIPs and the preceding country/continent release.

## Archive checks

- Both ZIP CRC checks passed.
- The full archive retains all 219 entry names and order. Only two location PHP payloads differ from the preceding country/continent full archive; the remaining 217 entries preserve their bytes and metadata.
- The targeted ZIP contains exactly 17 product files, one assets directory and four documentation/manifest/text-patch entries. Files are 0644 and the assets directory is 0755.
- The preceding country/continent full and targeted ZIPs, including their repository mirrors, remain unchanged.
- The earlier credential-logo renderers and public assets retain their exact bytes. The full archive retains its original bundled configuration and nested archive bytes.
- The targeted ZIP excludes configuration, SQL backups, private runtimes and fixture routes. No SQL import or migration is required by this release.
- The original-version text patch changes five PHP files; the country/continent-version text patch changes two. Both pass git apply --check and reconstruct exact final file bytes.

| Download artifact | Bytes | SHA256 |
| --- | --- | --- |
| TRAVSCOPE_CURRENT_V25_02_LOCATION_DELETE_CONTROLS_FULL.zip | 7,891,448 | 786f554201a186a1d914e74dc50e0213d47d96b22c8adb2a2f097b20f1e61d7e |
| TRAVSCOPE_V25_02_LOCATION_DELETE_CONTROLS_PATCH.zip | 289,014 | 8f2b359a51ad119bb013df9258c632166924e0bd9cda243bae856350f54685a5 |

The tests use synthetic development data. Real external city-service availability, email delivery and production deployment remain outside that fixture. Deletion checks stored dependencies and refuses linked deletions. Existing writers in other modules may later submit stale location IDs; this release adds no global reference constraints across those writers.
