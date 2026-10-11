# TRAVSCOPE V25.02 Country and Continent Selection — Validation

Validated on 11 October 2026. The packaged destination source matches the SHA256 captured by the final geography test run, and that source remained unchanged during the run.

| Check | Result | Scope |
| --- | --- | --- |
| Current geography selection suite | 19 passed, 0 failed | Country/continent selection, saved-value recovery, pending-response handling and server validation |
| Earlier admin integration suite | 63 passed, 0 failed | Previously validated Destinations and Cities & Locations upgrade, using an isolated synthetic database |
| Current destination helper checks | 23 passed, 0 failed | Filter/context handling and form-value recovery |
| Current actual-template render checks | 14 passed, 0 failed | Directory columns, visibility, actions, escaping and form context |
| Current mobile More-menu checks | 5 passed, 0 failed | Scroll positioning, keyboard opening, Escape and outside dismissal |
| Current PHP syntax | Passed | All three packaged admin/helper files |
| Text-patch reconstruction and syntax | Passed | Both baselines reconstruct the exact five/three expected PHP files; all eight reconstructed files pass syntax checks |

The geography and earlier admin fixture reports confirm their cleanup steps completed. Public release files contain aggregate results only.

## Source identity

| File | SHA256 |
| --- | --- |
| admin-destinations.php | d7fbf45ef1ba4cdc2e4d3529331b1ee57aebbbf3b80ff490c682b79af704e00a |
| admin-destination-locations.php | f590beabf54ad655a2dacee8d9738228859d99970ecaa371a3228ad55736ca12 |
| location-master-functions.php | 4cb6eb8100d1d1b2c5e973662cea7e412a9f7ff10f2a6a83a167026e8fa8faef |

These hashes match the app, review copies, full ZIP, targeted ZIP and manifests.

## Archive checks

- Both ZIP CRC checks passed.
- The full archive retains all 219 entry names and order. Only three PHP payloads differ from the preceding V3 full archive; the remaining 216 entries preserve their bytes and metadata.
- Only admin-destinations.php changes its payload relative to the previously published destination/location upgrade full archive. Both earlier upgrade ZIPs remain unchanged.
- The targeted ZIP contains exactly 17 product files, one assets directory and four documentation/manifest/text-patch entries. Product files are 0644 and the assets directory is 0755.
- The preceding 14 logo-renderer and public-asset replacement files retain their exact V3 bytes. The full archive retains its original bundled configuration and nested archive bytes.
- The targeted ZIP excludes configuration, SQL backups, private runtimes and fixture routes. No SQL import or migration is required by this release.
- Both text patches pass git apply --check and reconstruct exact final file bytes from their documented baselines.

| Download artifact | Bytes | SHA256 |
| --- | --- | --- |
| TRAVSCOPE_CURRENT_V25_02_COUNTRY_CONTINENT_SELECTION_FULL.zip | 7,889,018 | c6b74c11192c7d95863c276eccc4c8f4b5a2e198ef5fb3d7cfb6740f0652c2d3 |
| TRAVSCOPE_V25_02_COUNTRY_CONTINENT_SELECTION_PATCH.zip | 305,605 | 27b8aa0cd2c52830df8b25cf4854ee2e1267d6d51adc67d367063f3440cdb992 |

The tests use synthetic development data. Real external city-service availability, email delivery and production deployment remain outside that fixture. Continent destinations use manual city entry and the existing unavailable-sync hint when no country is saved.
