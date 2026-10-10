# V25.02 destination and location upgrade validation

Validated on 2026-10-10 against the exact three PHP sources recorded in RELEASE_MANIFEST.json. Independent final code review found no remaining Critical, Important or Minor issues.

- Actual admin HTTP and Chromium: **63 passed, 0 failed** (37 browser, 26 HTTP), including desktop/mobile filters and form context, inactive status preservation, authentication/CSRF, deletion dependencies, sync outcomes, mobile menu positioning and cancelled deletion, and mobile manual add.
- Destination helper and real-template checks: **23 + 14 passed**.
- Location helper and real-page rendering: **44 + 13 passed**.
- Actual destination inline JavaScript menu regression: **5 passed**, covering deferred scroll, automatic scrolling on a real click, keyboard/outside dismissal, Escape focus and an out-of-view trigger.
- Development readiness: database SELECT 1 and 152 baseline tables, **8 public-page checks**, **34 supplier-parser checks**, and **33 credential-library checks** passed.
- ZIP CRC, full 219-entry preservation, exact 17-product-file patch scope, readable replacement-file permissions, both text patches' applicability/exact reconstruction and PHP syntax passed.

The browser/request suite used an owned, separate MariaDB instance, synthetic records and deterministic geo responses. It copied schemas only, copied no source rows, and removed its database, PHP process, credential prelude and sessions. Optional deletion OTP was disabled only in that synthetic database to reach the existing dependency guard without sending mail.

The final request/browser report SHA256 is `e9c319cce41d25dff9461e2dc37c8cf7d5164615cf265ba92f04d6513b663dba`. The final Destinations PHP SHA256 is `53fb11771dcf69e81cc327570892de07a4b55302ee58ba739a43152c7a6261a8`.

Earlier verified quotation renderers are preserved byte-for-byte from V3. Real geo service availability, provider uploads, email/OTP delivery, payments and production admin sessions were not exercised. These artifacts have not been deployed to travscope.com.
