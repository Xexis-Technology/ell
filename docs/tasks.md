# Exotic Lane Limo — Task Tracker (Phase 1)

Status values: Pending | In Progress | Completed | Partial | Blocked | Failed | Cancelled

## 2026-09-24 — Homepage fixes (owner feedback)
- Signature Journeys section converted from ivory to dark theme (charcoal cards, champagne text). Status: Completed.
- Sticky header: fixed bar (logo + links + Book Now) fades in after scrolling 160px via Alpine scroll listener; verified markup live. Status: Completed.
- Passengers: capped select replaced with number input 1–20 (no artificial limit; matches booking validation). Status: Completed.
- Widget tabs now drive the fields: Point-to-Point (pickup/destination/date/pax), Airport (+ direction), Hourly (pickup/date/hours/pax, no destination), Groups (inquiry panel). booking.php prefill extended for direction + hours; verified over HTTP. Status: Completed.

## 2026-09-24 — Booking widget upgrade (owner request)
- Location picker popup for pickup/destination: searchable curated list (18 airports, stations, landmarks) + free-type any address; modal with search, Esc/backdrop close, mobile bottom-sheet style. Status: Completed.
- Point-to-point extra stops in widget: dynamic add/remove, max 6 enforced, submitted as stops[]; booking.php prefill extended (sanitized, capped 6). Verified multi-stop carry-through over HTTP. Status: Completed.
- Hourly restriction: hours clamped min 2 client-side (more allowed, never less; server already enforced). Status: Completed.
- Scrollbars removed (tabs row + journey cards) via .no-scrollbar utility. Layout polish on widget. Status: Completed.

## 2026-09-24 — Journeys/How-it-works gap + rail fixes (owner request)
- Journeys section gained bottom breathing room; How-it-works rail replaced fragile pseudo-element with an explicit span pinned to icon centers (top/bottom 26px), and hollow icons given solid backing so the line passes behind them, not through. Verified 200 + tests green. Status: Completed.

## 2026-09-24 — How-it-works reference layout (owner request, layout only)
- Rebuilt as centered header + photo with floating Fixed-pricing pill and sample trip-slip card + vertical icon steps (gold first icon, connecting line). Our 4 steps, copy, dark champagne system. Verified live + tests green. Status: Completed.

## 2026-09-24 — Auth pages index-style header (owner request)
- Public layout gained an index header variant (same links, pill, hamburger, sticky bar); all 4 auth pages use it. Other pages keep the default header. Verified live + tests green. Status: Completed.

## 2026-09-24 — Auth pages redesign (owner request, frontend-design)
- All 4 auth pages rebuilt as split members'-entrance cards (photo panel + form, our colors/copy, mobile stacks); logic untouched; register now preserves typed input on error. Login flow re-verified over HTTP + tests green. Status: Completed.

## 2026-09-24 — Removed views/public_includes (owner request)
- Re-inlined header/footer into public layout (same index-style design incl. mobile menu) and deleted the partials folder; zero references remain; pages 200 + tests green. Status: Completed.

## 2026-09-24 — Shared public header/footer partials (owner request)
- New views/public_includes/header.php + footer.php in index-page style (wordmark, links, auth-aware CTAs, mobile hamburger menu — previously missing on inner pages); public layout now includes them so every public page shares one header/footer. Verified on 4 pages + home + tests green. Status: Completed.

## 2026-09-24 — Services trim + two-button cards (owner request)
- Removed category strip, stats and fleet showcase (plus dead queries); header CTA is now Book now; service cards carry gold Book + outlined Read more buttons. Verified + tests green. Status: Completed.

## 2026-09-24 — Services page reference-layout rebuild (owner request, layout only)
- Rebuilt as hero panel + category marquee + about w/ floating card + real DB stats + 5 service cards (icon, Read more, Book) + why-us accordion + fleet showcase. All data real, our theme/copy. Verified live + tests green. Status: Completed.

## 2026-09-24 — Services index redesign + index header (owner request, frontend-design)
- Rebuilt as route-board rows (SVC·01–05 incl. Direct Contract, fact chips, per-service CTAs) + index header variant. Verified live + tests green. Status: Completed.

## 2026-09-24 — Contact reference-layout rebuild (owner request, layout only)
- Rounded panel (headline + org info + Book CTA / form with name, phone, email, message), reassurance strip, 4 fact columns; index header; submit flow verified over HTTP + tests green. Status: Completed.

## 2026-09-24 — FAQ reference-layout rebuild (owner request, layout only)
- Centered header + contact line, 4 category pills filtering 14 real Q&As (booking, pricing & payment, rides, account) with icon squares and expandable rows, CMS body preserved below. Verified live + tests green. Status: Completed.

## 2026-09-24 — Footer redesign + working newsletter (owner request, layout only)
- New footer: centered newsletter block (validated subscribe, PRG, duplicate-safe) + dark rounded panel (brand, org contact/socials from settings, 3 link columns, car photo). New newsletter_subscribers table (schema + live). Admin notifications page manages subscribers (unsubscribe). E2E verified (subscribe, message, dupe) + new test. Suite 13/45 green. Status: Completed.

## 2026-09-24 — Fleet Book/View buttons + detail page (owner request)
- Cards now carry gold Book (→ booking) + outlined View (→ services/fleet.php?vehicle=N); new detail page with photo, year/seats/luggage/rates, Book + group-inquiry CTAs, sibling vehicles, per-vehicle SEO, 404 handling; shared vehicle_photo() helper. Verified 200/404 + tests green. Status: Completed.

## 2026-09-24 — Fleet + Trust/FAQ redesign (owner request)
- Fleet: photo cards per vehicle (category-mapped car images + fallback), year/seats/bags, live per-mile + hourly rates, Book link; header + Book-your-ride link. Trust: 2×2 mini-cards (gold discs) + native FAQ accordion with 4 real Q&As. Verified all 6 vehicles render + tests green. Status: Completed.

## 2026-09-24 — Journeys interactive showcase (owner: new design)
- Replaced panels with selector + feature panel (4 services, real facts/prices/links, swipeable options on mobile). Verified live + tests green. Status: Completed.

## 2026-09-24 — Journeys back to panel layout (owner request; rule kept: layout only)
- Restored 4 full-bleed photo panels per the reference layout, with our own titles, descriptions, champagne/gold system and real links (Groups → inquiry). Verified live + tests green. Status: Completed.

## 2026-09-24 — Journeys remade as original route-ticket roster (owner rule: layout-only references)
- Replaced copied photo panels with original design: ELL·101–104 ticket rows with pickup-dot → line → destination-square motif, real route facts, live from-prices, hover arrow; our content/colors throughout. Verified live + tests green. Status: Completed.

## 2026-09-24 — Journeys full-bleed panels (owner request, reference image)
- Rebuilt Signature Journeys as 4 edge-to-edge photo panels (01 Point-to-Point, 02 Airport Transportation, 03 Hourly Chauffeur, 04 Corporate → group inquiry) with gold numerals, serif overlay titles, hairline dividers, hover zoom; 2-col tablet, stacked mobile. Verified live + tests green. Status: Completed.

## 2026-09-24 — Spinner time picker (owner request, reference layout)
- Time picker rebuilt like reference: hour + minute spinner columns (chevron buttons, wrap-around, 5-min steps), AM/PM segmented toggle, live preview, gold OK (commits) + ghost Cancel (discards); no seconds. Reopens from current selection. Verified live + tests green. Status: Completed.

## 2026-09-24 — Calendar/time picker upgrades (owner request)
- Today is bold champagne with gold ring in the calendar; time picker rebuilt as AM/PM + hours column (1–12) + minutes column (00/15/30/45) with live preview, reopening restores prior selection. Verified live + tests green. Status: Completed.

## 2026-09-24 — Date/time dropdown popups (owner request)
- Native date/time inputs replaced with popup pickers: month calendar (past days disabled, no pre-current-month nav) + 30-min slot grid with AM/PM labels; friendly display values, hidden fields submit; missing selection blocks submit with inline message. Verified prefill + tests green. Status: Completed.

## 2026-09-24 — Location popup single-search + chips (owner request)
- Merged the two search bars into one (filters list; Enter or Use-button submits typed text as custom address); Road/Airport toggle chips filter the list (toggle off returns to all); list scrollbar hidden via no-scrollbar. Verified live. Status: Completed.

## 2026-09-24 — Homepage blank-page fix (critical)
- Sticky header opened with <header> but closed with </div>, trapping the entire page inside a display:none element. Fixed closing tag; verified div 97/97, header 2/2, all sections render, tests green. Status: Completed.

## 2026-09-24 — Stops + hourly drop-off rules (owner request)
- Extra stops locked to One Way only (round trip shows none; switching clears); hourly drop-off auto-fills from pickup via same-as-pickup checkbox, unchecking reveals the drop field. Verified live + tests green. Status: Completed.

## 2026-09-24 — Hero card tab fixes (owner request)
- Service tabs renamed to Point-to-Point / Airport / Hourly; One Way / Round Trip moved inside as a segmented trip selector under Point-to-Point. Date/time/passengers row: labels no-wrap, passengers full-width on mobile. Verified live. Status: Completed.

## 2026-09-24 — LUXY-type hero rebuild (owner request, frontend-design)
- Replaced skyline hero + trip-slip widget with two-column hero: left headline ("Premium car service. Booked in minutes.") + booking card (One Way/Round Trip/Airport/Hourly underline tabs, Where-to pickup/drop rows with dot/square marks + location popup, dashed + Add stop max 6, airport direction pills, hourly stepper min 2, date/time/passengers row, gold Get-a-Quote CTA, group-quote micro-link); right chauffeur photo with live rate badge. booking.php prefill extended (trip, time). Verified HTTP + prefill + tests 12/43. Status: Completed.

## 2026-09-24 — Widget inline-strip restyle (owner request, frontend-design)
- Tabs → single segmented control; fields one inline row on desktop (hairline dividers, airport wraps 3+3 at lg / 6 inline at xl); full-height Continue button; stops in inset panel. All functionality preserved. Status: Completed.

## 2026-09-24 — Widget interaction fixes (owner request)
- Pickup/destination fields now open the location popup on click (readonly inputs); selection fills the name; popup gained a custom-address box + Use button so any address still works. Status: Completed.
- Extra stops hidden until + Add stop is pressed; counter appears with rows; rows removable back to zero; max 6 kept. Status: Completed.

## 2026-09-24 — Homepage design-lead pass (frontend-design skill)
- Thesis hero ("Your chauffeur is already waiting."), editorial eyebrow/italic system, fleet-roster marquee signature (real DB vehicles, pause-on-hover, reduced-motion off), flight-code card labels (ELL·101–103), justified 01–04 booking sequence, hairline section structure. All functionality preserved (tabs, prefill, groups). Tests green 12/43, page 200, no PHP noise. Status: Completed.

## 2026-09-24 — Booking widget dark theme (owner request)
- Widget card ivory → charcoal dark; labels brown → white; inputs white → dark (.input) with dark date-picker scheme; tabs active black → gold; groups text lightened. Verified: zero brown/white-input traces, page 200. Status: Completed.

## 2026-09-24 — Homepage redesign on reference layout (owner request)
- Rebuilt index.php on the Tripoo-style reference: full-bleed night-city hero with overlaid nav (desktop links + mobile hamburger), "Find Your Next Journey" headline, circular Explore-Fleet scroll button, ivory booking widget card overlapping hero (service tabs incl. Groups, pickup/destination/date/passengers, gold Search → booking.php with prefill), Signature Journeys photo cards (real min rates from DB, links to booking), fleet + trust/FAQ sections, layout footer.
- Public layout: optional $hideHeader so homepage renders its own hero nav; other pages unchanged (verified header present).
- services/booking.php: accepts date + passengers prefill (validated). Verified end-to-end via HTTP.
- Photos: Unsplash hotlinks with gradient + onerror fallback (no broken layout if offline); owner can later drop brand photography into assets/images/.
- Responsive: desktop = horizontal widget + 3-col cards (left reference); mobile = hamburger, stacked widget, snap-scroll cards (right reference). Status: Completed.

## 2026-09-24 — Remove orange-red CTA colors (owner request)
- Removed #BB4626 / #B93F1E from the entire site. Status: Completed.
- assets/css/app.css: --cta → ivory #F9F9F9, --cta-h → champagne #F3D4A6; .btn-cta now dark-on-ivory; added .btn-danger-outline for destructive actions (Cancel booking, Reject payout) using error-red border.
- last.md §32 palette updated to match (orange lines removed, ivory-CTA rule added).
- Verified: repo-wide grep for both hex codes (any case) returns zero; homepage + CSS HTTP 200 with new tokens live.
- Error/danger reds (#A3322F / #8E2B29) intentionally kept for alerts and destructive outlines.

## 2026-09-23 — Initial Phase 1 implementation (agent: opencode)

| Phase | Task | Status | Notes |
|---|---|---|---|
| 01 | Foundation: composer, .env.example, .gitignore, .htaccess, dirs, nginx example, favicon | Completed | composer install OK (phpmailer 6, stripe-php 16, dompdf 3, phpunit 11) |
| 02 | Database: single database/database.sql (36 tables + seeds) | Completed | Imported to ell_db (MariaDB 10.4 local); FKs verified via SHOW TABLES |
| 03 | Core config + URL helper + bootstrap | Completed | SITE_URL/APP_ROOT/url() per §5; secure sessions; security headers |
| 04 | Auth + sessions + security foundation | Completed | 3 isolated roles, Argon2id/bcrypt, 30-min single-use hashed reset tokens, rate limiting, secure uploads |
| 05 | Public layout + design system | Completed | 4 layouts; brand palette/typography; Tailwind 4 + Alpine.js + Axios; app/admin/driver CSS+JS |
| 06 | Public website | Completed | home, services index, point-to-point, airport, hourly, booking, confirmation, payment, cancellation, group-event, direct-contract, contact, faq, terms, privacy — all HTTP 200 |
| 07 | Services + vehicles + categories | Completed | 6 categories seeded; 6 demo vehicles + rates seeded; blocks + documents |
| 08 | Booking engine | Completed | Transactional create; 8-digit number; ≤6 stops; lead-time; guest+account; E2E HTTP verified (44033400 → awaiting_pricing) |
| 09 | Pricing engine | Completed | Per-mile/hourly exclusive; 2h min; add-ons; coupons; tax; immutable snapshot; audited revisions |
| 10 | Customer account | Completed | dashboard, bookings search/filter, booking view + pickup-time cutoff, invoice browser+PDF, profile, password, notifications |
| 11 | Admin operations | Completed | 17 pages; POST+CSRF+PRG+audit; mileage/finalize/link/offline/mark-paid/confirm/cancel/refund |
| 12 | Driver portal | Completed | register(pending), login activation gate, dashboard, rides, earnings, payout, profile |
| 13 | Dispatch | Completed | assign/reassign permanent+temp, conflict checks, history, driver status flow w/ timestamps |
| 14 | Stripe + payments + invoices + webhooks | Completed | PaymentIntent, idempotent webhook, double-pay protection, secure single-use links, offline flow, dompdf invoices |
| 15 | Waiting time | Completed | Rules seeded (60/30/30/30/15 + $15/10min); admin close → pending-invoice line; no auto-charge |
| 16 | Earnings + payout | Completed | 20/80, UNIQUE(booking_id) retry-safe; 7-day eligibility; approve/reject/record-paid |
| 17 | Notifications | Completed | 13 templates; PHPMailer/SMTP; queued/sent/failed log; safe retry; failures never roll back bookings |
| 18 | CMS + SEO + Maps | Completed | content mgmt, SEO/OG/social settings, robots/sitemap/404/403/500, schema.org; Maps admin toggle + awaiting_pricing path |
| 19 | Security hardening + audit | Completed | URL audit clean (only spec fallback + vendor); code audit clean (app hits = input placeholders/legit returns); uploads deny; .env protected |
| 20 | Full QA | Completed | php -l all 70 files; composer validate; 11 PHPUnit tests 38 assertions (2 consecutive green runs); 18 public pages HTTP 200; auth-guard redirects; register→login→booking E2E |
| 21 | Deployment + handover | Completed | deploy-nginx.conf.example; scripts/create_admin.php; docs/DEPLOYMENT.md; APP_DEBUG=false checklist |

## Files created (new, ~95)
- Root: index.php, .htaccess, .env, .env.example, .gitignore, composer.json, phpunit.xml, robots.txt, sitemap.xml, favicon.ico, deploy-nginx.conf.example, storage/uploads/.htaccess
- config/: app, database, auth, mail, stripe, maps
- app/: bootstrap, Helpers/functions, Core/Database, Middleware/Auth+Security, Services ×7 (Pricing, Booking, Payment, Dispatch, Earnings, Waiting, Notification)
- views/layouts ×4, views/errors ×3
- services/ ×10, auth/ ×5, account/ ×7, legal/ ×4
- admin/ ×17, driver/ ×7, webhook/stripe.php
- assets: css ×3, js ×5
- database/database.sql, scripts/create_admin.php, tests/Phase1Test.php + bootstrap
- docs/tasks.md, docs/memory.md, docs/DEPLOYMENT.md

## 2026-09-23 — Full-system recheck (agent: opencode)
- Audited every last.md section (§4–§113) against implementation. All §4 files present.
- Gaps found and closed:
  1. Driver forgot/reset password missing (§8) → driver/forgot-password.php + driver/reset-password.php (role=driver tokens), link on driver login. HTTP 200 verified.
  2. Admin booking create/edit missing (§28) → admin/bookings.php?action=create (customer-or-guest, offline path) + action=edit (route/schedule/vehicle, totals preserved) + admin-cancel customer email. E2E verified (93207414 created → finalized → paid → finished).
  3. Coupon customer_limit unenforced → new coupon_redemptions table (UNIQUE booking, FKs) + validation + redemption recording in create + finalizePricing + admin coupon form fields (per-customer limit, starts/expires). New test testCouponCustomerLimit green.
  4. bookings.pricing_status concept (§91) → column added (awaiting/finalized), synced in create + finalizePricing, live DB migrated, test asserts.
  5. waiting-charge email never sent → admin waiting-close now emails customer (pending-invoice only, no auto-charge). Admin cancel now emails customer.
  6. views/emails + views/invoices were empty (structure §4) → extracted views/emails/layout.php (used by NotificationService) + views/invoices/invoice.php (used by browser + PDF paths). Invoice page re-verified HTTP 200.
  7. Guest invoice link on confirmation led to login dead-end → now shown only for account bookings; guests get create-account guidance.
  8. Page-specific meta descriptions added (services, booking, group-event, contact).
- Full lifecycle E2E over HTTP: admin create (93207414) → mileage finalize ($90) → offline pay → confirm → driver register → admin activate → dispatch → driver on_the_way→arrived→at_pickup_location→on_board→finish → earnings 90/18/72 (20/80) → invoice INV-2026-396738 → 10 booking logs + 5 driver logs.
- Regression: 12 PHPUnit tests / 43 assertions green ×3 runs; php -l clean on all touched files; URL audit clean (spec fallback + vendor only); secrets audit clean (vendor doc examples only).
- Bug fixed: INSERT placeholder count mismatch after pricing_status addition (HY093) → corrected to 29.

## Database changes
- Created ell_db; imported database.sql: 36 tables, UNIQUE booking_number/invoice_number/provider IDs/token hashes/driver_earnings.booking_id, DECIMAL money, DATETIME dates, FKs, seeds (services, categories, waiting rules, charges, settings, CMS)
- Recheck: + coupon_redemptions table; + bookings.pricing_status ENUM(awaiting/finalized); live DB migrated via ALTER + re-source

## Configuration changes
- .env (local, no secrets — Stripe/SMTP empty → safe config state)
- Composer autoload: classmap for app classes + helpers file

## Bugs found & fixed
1. `.htaccess` used `<DirectoryMatch>` (not allowed) → 500 on all pages. Fixed: removed block, added storage/uploads/.htaccess. Verified 200.
2. Tests: settings static cache caused hourly-mode test to read stale mode → added setting() $refresh flag, cache clear in tests.
3. Tests: re-runs collided on fixed booking numbers/payouts → added cleanup DELETEs; suite green on consecutive runs.
4. Composer PSR-4 warnings for global service classes → switched autoload to classmap.

## Pending work
- Real SMTP + Stripe keys (owner provides; system runs in safe config state until then)
- First admin creation via scripts/create_admin.php (interactive, no invented credentials)
- Production deploy (Ubuntu/Nginx) per docs/DEPLOYMENT.md + APP_DEBUG=false + HTTPS/HSTS
- Manual responsive pass on physical devices (viewports/breakpoints implemented; meta viewport on all layouts)
