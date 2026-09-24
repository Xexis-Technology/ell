# Exotic Lane Limo — Memory (agent decisions & context)

## 2026-09-23 20:50 UTC — Phase 1 build (opencode)
- **Baseline:** last.md (2311 lines) is the final contract; older docs absent (repo had only last.md). Greenfield build.
- **Scope locked:** 3 portals; single Admin level; guest checkout; driver activation gate; Maps optional/admin-controlled; per-mile OR hourly; ≤6 stops; 2h hourly min; airport = address-based, no flight no.; 20/80 split; 7-day payout; email primary.
- **Stack:** PHP 8.3.33, MariaDB 10.4 local (MySQL 8 prod target), PDO, Tailwind 4 (browser CDN), Alpine.js, Axios, phpmailer/phpmailer, stripe/stripe-php, dompdf/dompdf. No Laravel/React/Bootstrap/jQuery.
- **Key decisions:**
  - One database/database.sql (36 tables: §91 25 + services, vehicle_categories/docs/blocks, driver_documents, password_resets, group_event_inquiries, content, settings, pricing_revisions, webhook_events). No secrets stored; token hashes only.
  - Money DECIMAL(10,2), dates DATETIME (UTC), currency CHAR(3) default USD.
  - Flat admin/*.php + driver/*.php files (no nested index folders).
  - Webhook is authoritative; /payment/success never marks paid. Idempotency via webhook_events UNIQUE(provider, event_id).
  - Waiting charges → pending-invoice lines only; no silent Stripe charges.
  - Email failures logged, never roll back bookings; SMTP absent → logged failed + booking continues.
  - Stripe absent → safe config state (clear error, offline/awaiting-pricing paths work).
  - First admin via interactive CLI script (no invented credentials).
  - Local XAMPP Apache (.htaccess) + prod Nginx example conf.
- **Verified:** 11 tests/38 assertions green ×2; 70 files php -l clean; 18 pages HTTP 200; guards redirect; E2E register→login→airport booking (44033400, awaiting_pricing).
- **Recheck 2026-09-23:** closed 8 gaps (driver reset flow; admin booking create/edit + cancel email; coupon_redemptions + per-customer limits; bookings.pricing_status; waiting-charge email; views/emails + views/invoices extraction; guest invoice-link fix; page meta descriptions). Full lifecycle E2E: admin booking 93207414 → finalize → offline pay → confirm → dispatch → driver finish → 20/80 earnings + invoice. Suite now 12 tests/43 assertions green ×3. Architecture note: page scripts act as controllers (Page → Service → PDO → MySQL); app/Controllers + app/Models dirs intentionally not created per "no empty architecture" rule (§4); email/invoice views extracted to views/ as required.
- **2026-09-24 homepage redesign (owner):** reference-layout hero + widget + Signature Journeys, our content/colors, responsive both ways; $hideHeader layout flag; booking prefill extended (date/passengers); Unsplash photos w/ fallback.
- **Standing owner rule (never violate):** reference images define LAYOUT ONLY. Always use Exotic Lane Limo's own content, text, and brand colors — never copy image text, wording, or colors. Applies to every future image reference.
- **2026-09-24 design change (owner):** orange-red CTA colors removed site-wide. Primary CTA (.btn-cta) is now ivory #F9F9F9 bg + near-black text, hover champagne #F3D4A6. New .btn-danger-outline (error-red border) for Cancel booking / Reject payout. last.md §32 palette updated. Error reds kept.
- **Next:** owner supplies SMTP/Stripe keys + creates admin; prod deploy per DEPLOYMENT.md.
