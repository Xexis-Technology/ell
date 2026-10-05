# Exotic Lane Limo — Task Tracker (Phase 1)

Status values: Pending | In Progress | Completed | Partial | Blocked | Failed | Cancelled

## 2026-10-05 — Fleet & crew redesign: compliance dockets (frontend-design)
- `admin/vehicles.php` and `admin/drivers.php` rebuilt as **dockets** rather than tables. The old vehicle page was four stacked forms above an 8-column table with an inline rate form per row; the old crew page put a file input, a text input and two forms inside every table cell. Status: Completed.
- **The hole this exposes:** `vehicles` already had `insurance_expiry`, `registration_expiry`, `inspection_expiry` and `diamond_sticker_expiry`, and `drivers` had `license_expiry`, `reference`, `license_number` and `payout_reference` — but **no form ever wrote them**. Every value was NULL and permanently uneditable, so a limo operator could not legally keep a car on the road from this admin. All four vehicle dates, the driver licence fields, reference and payout reference are now captured and editable. Status: Completed.
- Signature element: the **compliance clock**. Bar length is always time remaining against a 12-month horizon, so a longer bar always means more life left; colour carries urgency on a separate channel (red = out of date or due inside 30 days, amber = inside 90, green = fine, hatched = never recorded). A missing date reads `missing`, not a fake value. Status: Completed.
- First attempt at the clock had **inverted semantics** — a lapsed document drew the longest red bar and an imminent one drew a short one. Caught on screenshot and rewritten so length always means life remaining. Status: Completed.
- Header figures are computed, not decorative: cars, ready to book, out of paper, missing dates, in the shop, blocked today (and the crew equivalent). Filters: All / Ready / Due within 30 days / Missing dates / In the shop, and Everyone / Cleared / Awaiting clearance / Licence due / Unavailable. Status: Completed.
- Progressive disclosure per record: Details, Rates, Documents, Block time (vehicles) and Details, Documents (crew). Only one panel per card stays open, driven by a new `data-fl-toggle` handler in `assets/js/admin.js`. Verified by measurement (open panels 2 -> 3 after a click, `aria-expanded=true`) and by screenshot, not assumed. Status: Completed.
- New capabilities that did not exist before: a car can now be **blocked for a window** with a reason and the block is listed and liftable, and a car's documents can be listed, reviewed and uploaded per car instead of only through a bare form. A live block is stated on the docket ("Blocked now until 18:00 · Brake service") and offers "Lift the block". Status: Completed.
- Crew status is no longer four equal-looking buttons per row. Only the transitions that make sense from the current state are offered, with **Clear to drive** as the primary gold action. Status: Completed.
- The crew "Rides" column was actually counting dispatch records and labelling them rides. Now reports completed trips and open trips separately, plus the licence number. Status: Completed.
- Real outcomes replace blanket "updated" messages: "X is now cleared to drive.", "X is already active." as an error, "That chauffeur is not on the file.", "That licence expiry is not a date we can read.", "x@example.com already has an account." New chauffeurs start pending and the message says so. Status: Completed.
- Drivers had **no edit form at all** — only a status select. Added a details panel for name, phone, reference, licence number, licence expiry and payout reference, with the note that payout is a handle and last four only. Email is shown but not editable, because it is the sign-in identity. Status: Completed.
- `view=` and `id=` now survive every redirect, so acting on a record inside a filtered view keeps you in that view (the old crew page read `$_GET['status']` during a POST and always lost the filter). Status: Completed.
- Bug fixed: `foreach ($vehicles as $v)` gives a **copy**, so every derived compliance flag was silently dropped and the filters all returned zero cards with `Undefined array key "_lapsed"` in the output. Now a reference loop with the required `unset()`. Status: Completed.
- Bug fixed: **adding a vehicle was fatal.** The UPDATE column list (`col=?`) was reused as the INSERT column list, producing a syntax error. Split into a bare `$colList` and a generated `$setList`. Status: Completed.
- Bug fixed: **adding a chauffeur was fatal.** The name was in the shared value array *and* prepended again, so 8 values were bound to 7 placeholders. Fixed with `array_slice($vals, 1)`. Status: Completed.
- Bug fixed: block windows were accepted with the end before the start. Now validated with `DateTime::createFromFormat` on both ends and compared. Status: Completed.
- `status_pill()` had no colour for `maintenance`, so "In the shop" rendered as an unstyled pill. Added to the gold set. Status: Completed.
- Responsive verified with every panel force-opened at 320/360/390/430/600/768/900/1024/1280/1440/1700: `scrollWidth === clientWidth`, no page overflow. The only remaining scroll containers are the document tables below 430px, which scroll by design. The docket label column is now `auto` width so "REGISTRATION" stops colliding with the bar on a phone. Status: Completed.
- PHPUnit green 15 tests / 53 assertions; all 13 admin pages HTTP 200 with no PHP notices. Temp preview files deleted and the fleet restored to its exact prior state: 6 vehicles with all four expiry dates NULL, 1 driver, 0 vehicle/driver documents, 0 blocks, 6 rates, 6 categories, 0 orphan dispatches. Status: Completed.

## 2026-10-05 — Fleet & crew: tiles, modals, rich text, filter fix (owner request)
- The dockets were too tall to scan. Both pages now lead with a **tile grid** — a car tile carries only photo, name, per-mile and hourly rate; a crew tile carries only avatar and name — and every tile opens a native `<dialog>` holding the full record, its docket and its forms. Detail is now one click away instead of six scroll lengths down. `Add car`, `Add category` and `Add driver` moved to the top right. Status: Completed.
- Native `<dialog>` + `showModal()` rather than a hand-rolled overlay, so focus trapping, Escape and the top layer come from the platform. Verified: closed dialogs are `display:none`, Escape closes, focus moves into the dialog. Status: Completed.
- Quill 2.0.3 is loaded from jsDelivr on every admin page; descriptions on vehicles, categories and drivers are rich text. Per owner instruction, **no library is vendored locally** - Font Awesome moved to CDN too and `assets/vendor/` is deleted, so every page depends only on CDN assets and the project's own `assets/css` + `assets/js`. Submission posts through a hidden mirror input while the textarea keeps a plain-text fallback for non-JS. Output is sanitised by `clean_html()` in `app/richtext.php` — verified that `<script>` in a posted description is dropped and `<p>/<strong>/<b>/<i>` survive. Status: Completed.
- Fixed: vehicle category descriptions were being flattened by `plain_text()` into `"Cat richx"` — it concatenated the text and also leaked the *contents* of tags it stripped. They now use `clean_html()` like every other rich field. Status: Completed.
- **Filter bug.** The vehicle header read "0 ready to book / 6 missing dates" and only `In the shop 2` appeared in the filter row, while the modal docket for the same car rendered 7mo/3mo/41d correctly. Root cause: the enrichment loop looked the four dates up through the `$WATCH` key=>label map **by label instead of by column**, so every lookup missed and all six cars were flagged as missing dates. Replaced with an explicit `vehicle_docket()` that names each date, and switched both pages from `foreach ($rows as &$r)` to index loops. Verified against seeded states: 6 cars / 3 ready / 3 paperwork / 2 due within 30 days / 2 in the shop / 2 blocked, and each filter returns exactly its own subset. On a fleet where every car sits in one bucket the row hides itself, which is the intended behaviour. Status: Completed.
- Fixed: `SQLSTATE[23000] ... booking_id cannot be null` at `admin/drivers.php:30`. An admin status change was writing to `driver_status_logs`, which is the driver's live trip log — `booking_id` is `NOT NULL` and FK-bound, and a clearance (pending -> active) has no ride to hang it on. The insert is gone; admin-originated changes go to the audit log. Verified `SELECT COUNT(*) FROM driver_status_logs WHERE booking_id IS NULL` = 0. Status: Completed.
- Shared `days_until(mixed $date): ?int` now lives in `app/Helpers/functions.php` rather than being redeclared per page — a duplicate declaration would have been a fatal the moment both pages loaded in one request. Status: Completed.
- Verified by POST, not by inspection: status change, add car with rich description, add category, add driver, bad-date rejection and missing-make rejection all return clean flashes with no SQLSTATE. Test rows removed and vehicle 1 (slug `cadillac-xts`) restored after a POST test overwrote its make/model. PHPUnit green 15 tests / 53 assertions. Fleet returned to its prior state: 6 vehicles, 1 driver, 0 documents, 0 blocks, 6 rates, 6 categories. Status: Completed.

## 2026-10-05 — Fleet & crew: chips, modal centring, libraries on CDN
- Car and driver cards are now **chips**: a fully rounded pill (`border-radius:999px`) with a round 56px photo or avatar on the left and the name plus its figures stacked on the right. The grid reflows 4-up on desktop, 2-up on laptop/tablet and 1-up on phones, where the disc shrinks to 44px rather than stacking. Status: Completed.
- **Modal centring bug.** Every `dialog.modal` was pinned to the top-left of the viewport. Cause: the Tailwind browser build is loaded above `admin.css` and its preflight does `*, ::after, ::before { margin: 0 }`, which strips the `margin: auto` the UA stylesheet gives `<dialog>`. Restored `margin:auto; position:fixed; inset:0` explicitly, and gave `.md-body` the scrolling while the dialog clips so the rounded corners survive. Verified centred on both axes at 1440/1024/768/390/320 on both pages. Status: Completed.
- **Description field appeared blank for descriptions that were on file.** Three bugs stacked. (1) `new Quill(textarea)` consumes and empties the textarea, so the `dangerouslyPasteHTML(ta.value)` on the next line pasted an empty string. (2) The line after it unconditionally overwrote the hidden mirror with `<p><br></p>`, so saving the record **wiped the stored description**. (3) The editor itself had **zero height** — Quill initialised on the textarea makes that textarea the `.ql-container`, so `ta.style.display='none'` hid the typing surface with it: the toolbar drew and there was nothing to type into, exactly as the owner's screenshot showed. Fixed by mounting Quill into its own `.ql-mount` div, leaving the textarea as a hidden fallback, reading the stored HTML before init, and only syncing the mirror when the editor holds text. Verified: editor measures 636x120 and is `contenteditable`, stored descriptions render with formatting, typed text survives save and reload, and a plain save no longer erases anything. Status: Completed.
- **Dialog opened with the close button focused**, so `showModal()` put the caret on the first focusable element — pressing space while typing activated it and closed the dialog mid-edit. `openModal()` now focuses the first real field instead. Status: Completed.
- All libraries load from CDN and `assets/vendor/` is deleted: Quill 2.0.3 and Font Awesome 6.7.2 on jsDelivr, alongside the existing Tailwind and Alpine. Because Quill is no longer local, `admin.js` polls for it (8s cap) instead of assuming it ran by `DOMContentLoaded`. All four CDN URLs verified HTTP 200. Status: Completed.
- Repaired collateral from testing: vehicle 1's `pricing_rates` row was deleted by a cleanup that matched on a make the test had already clobbered, so the XTS read $0.00/mi; restored to 4.50/95.00 from its siblings' pattern and the surviving rate id. Fleet back to 6 vehicles, 1 driver, 7 rates, 6 categories, 0 documents, 0 blocks, descriptions cleared. PHPUnit green 15 tests / 53 assertions; 17 admin pages clean; console and network clean. Status: Completed.

## 2026-10-05 — Client book redesign (frontend-design)
- `admin/customers.php` replaced. The old page was a 6-column table with a `<select>` and a Save button on **every row**, and it could not show a client a single ride. It is now a concierge's **ledger** with a master/detail split: an index of clients on the left, the selected client's full record on the right. Status: Completed.
- Selection via `?c=<id>`; with no selection the highest-spending client opens, so the page is never a dead index. `?q=` is preserved across index links and across the status redirect. Status: Completed.
- Index is sorted by lifetime spend, and each entry carries the two numbers a concierge actually remembers someone by: rides and money, plus `NEXT 14 OCT` in amber when a ride is booked. Status: Completed.
- Client record: monogram avatar, name, mailto/tel links, "on the book since", then billed-to-date / rides / last ride / next ride. Status: Completed.
- **Ride ledger**: every booking as a dated line with a mono date block, pickup → drop, party size, status pills and the fare set in the display serif. Trips still ahead of today carry the amber date numeral, the same mark the dispatch board uses, so the admin reads as one instrument. Status: Completed.
- The per-row `<select>` + Save is replaced by one honest toggle: **Suspend this client** / **Reactivate this client**, with the consequence spelled out ("Stops new bookings. Existing rides stay on the books."). Status: Completed.
- New information surfaced, all from real data: unpaid total across a client's rides, and a warning when you suspend someone who still has upcoming rides — the old page let you suspend a client mid-itinerary with no signal at all. Status: Completed.
- Status action now reports outcomes instead of always claiming success: "X is now suspended.", "X is already inactive." (as an error), "That client is not on the file." All four paths verified over HTTP, including that `?q=` survives the redirect. Status: Completed.
- Bug fixed: the header read **19 clients** when there were 7. `COUNT(*)` over `customers LEFT JOIN bookings` counts joined rows, not customers, so a client with 7 rides read as 7 clients. Now `COUNT(DISTINCT c.id)`. The rides figure (19) is deliberately client-linked rides only; the 11 guest bookings are correctly excluded, and the billed figure matches. Status: Completed.
- Copy fixes: "across 2 rides still have not been paid" → "is still unpaid"; eyebrow "Clients" → "Customers" to match the nav; dangling separator dot on the contact line when it wraps; alert/heading collision. Status: Completed.
- Bug fixed and it needed a container query: the ledger's five fixed columns collapsed the route column to **0px** and spilled out of the card at a 1080px window, because the two-column split leaves the sheet only ~440px there. A viewport media query cannot see that, so `.cu-sheet` is now a `container-type: inline-size` and the row switches to the wide layout at `@container sheet (min-width: 560px)`. Verified at 340/430/600/768/900/1000/1080/1120/1200/1280/1440/1600: `scrollWidth === clientWidth`, no overflowing nodes, and the ledger correctly shows the wide layout at 768–1000 and 1280–1600 but the stacked layout at 1080–1200 where the sheet is narrow. Status: Completed.
- Empty states written as invitations: no search match ("No client matches 'x'. Try part of an email or a phone number."), and a client with no rides ("Nothing to settle, nothing to dispatch. The ledger fills in as soon as a booking is made."). Status: Completed.
- PHPUnit green 15 tests / 53 assertions. Temp preview files deleted; all QA customers, rides, charges, logs and payments removed and the database verified back to its original state with zero orphan bookings. Status: Completed.

## 2026-10-05 — Dispatch pickers + date picker fixes (owner request)
- Bug fixed: the day picker looked like it did nothing. The form carried a hidden `day=<old day>` next to a `name="pick"` date input, so submitting sent the **old** day and the server correctly re-rendered the same sheet. The hidden field is gone, the date input is now `name="day"`, and the form has an explicit `action`. Verified: `?day=2026-12-25` renders "Friday 25 December" with its own empty state and a "Back to today" link. Status: Completed.
- Native `<select>` replaced with a **searchable picker** for both Driver and Car. The trigger shows the current choice (monogram avatar for a driver, thumbnail for a car) and posts through a hidden input, so the form contract is unchanged. Status: Completed.
- Driver popup: search bar, monogram avatar, name, phone, and live "Free / On a trip" for the selected day. Status: Completed.
- Car popup: search bar, real photo (category-mapped via `vehicle_photo_url`), name, plate, passenger capacity, **per-mile rate and hourly rate** joined from `pricing_rates`, and live "Free / On a trip". Status: Completed.
- "Leave open" is always the first item so an assignment can be cleared from the same control. Status: Completed.
- Keyboard: `role="listbox"` + `role="option"` + `aria-selected`, ArrowUp/ArrowDown move the highlight, Enter picks, Escape closes, the search field autofocuses on open and the highlighted option scrolls into view. Status: Completed.
- Empty state inside the popup is a direction, not an apology: `No driver matches "merc".` Status: Completed.
- Data is delivered once per page as `<script type="application/json" id="dp-data">` encoded with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` so a driver or vehicle name can never break out of the tag or into an attribute. Availability is resolved once and shared by the header counts, the crew panel, the car panel and the pickers instead of being re-queried per panel. Status: Completed.
- Bug found by testing the new control: "Leave open" posts `0`, and the handler cast that to `int 0`, which reached `dispatches.driver_id` and tripped the foreign key ("Could not assign dispatch") instead of clearing the slot. Added an `$optId()` normaliser that maps `''` and `'0'` to `null`. All three paths verified over HTTP: real ids assign, `0` clears, `''` clears. Status: Completed.
- Bug fixed: the popup is an absolutely positioned box, so at 340–759px it added 9–34px of page-level horizontal scroll, and at 560px the 320px popup stuck out past the card. Viewport-unit sizing could not fix this because the trigger sits at a variable indent. The popup is now **inline (static) below 760px**, where the form is single-column, and floats above 760px where the form is two-column; the car picker is right-anchored (`.pk--end`) so it stays inside the card. `dp-form-row`'s two-column breakpoint moved 560px → 760px to match. Status: Completed.
- Verified by measurement at 340/375/430/560/600/759/760/900/1120/1280/1440 with the popups forced open: `scrollWidth === clientWidth` and no overflowing elements at every width (the only flagged nodes are the intentional `text-overflow: ellipsis` labels). Status: Completed.
- Car panel fix: plate and name now each take a full row, so "Mercedes-Benz S-Class" no longer wraps mid-name next to the plate. Status: Completed.
- PHPUnit green 15 tests / 53 assertions; dispatch, empty-state and booking-detail pages all HTTP 200 with no PHP notices. Temp preview files deleted and all QA rows removed. Status: Completed.

## 2026-10-05 — Dispatch board redesign (frontend-design)
- `admin/dispatch.php` replaced: the 6-column table with a full assign form per row is now a **run sheet** ordered by departure time, because that is how a chauffeur board actually works. Status: Completed.
- Structure: header (day label + plain-English key to the amber colour) → day stepper + date picker → one-line stat readout → pricing-backlog warning → run sheet (left, 2/3) + crew / cars / recent moves (right, 1/3). Single column below 1120px. Status: Completed.
- Signature element: a **time rail** down the left with real **idle-gap markers** derived from consecutive departure times (e.g. "2 h 45 m clear") — the dispatcher's actual question is how much slack is left, and no other panel in this admin shows empty time. Status: Completed.
- Amber `#E8A33D` added as an *operational* accent alongside brand champagne: amber means "needs a driver or a car", green means on the road, grey means covered. Champagne stays on actions and the public site. Needy rows get a tinted panel + amber diamond on the rail. Status: Completed.
- Progressive disclosure per row: driver/car state and the booking number are always visible; only the *change* controls sit behind a native `<details>` ("Cover this trip" / "Change driver or car"). No tabs — the owner rejected tabbed admin detail pages. Status: Completed.
- Query widened to the full in-progress lifecycle (`booking_received` → `on_board`) and scoped to one day via `?day=YYYY-MM-DD`, so the board works for tomorrow instead of only today. Status: Completed.
- Availability is now computed and shown: crew and car panels report free vs on-a-trip for the selected day via `DispatchService::driverAvailable` / `vehicleAvailable`, and the header counts free crew and free cars. Status: Completed.
- Bug fixed: dispatch history rendered raw `old_driver_id` / `old_vehicle_id` **integers** instead of names. Now joins drivers + vehicles and shows "Marisol Reyes → Devon Hart · S-Class → Escalade". Status: Completed.
- Bug fixed: errors after an assign were re-rendered as `alert-ok` because only `?msg=` survived the redirect. Added `&err=1`; also preserved `?day=` so a failed assign leaves you on the same day. Status: Completed.
- Bug fixed: `?day=2026-13-45` passed the regex but was not a real date and produced a fatal error in `strtotime`. Now validated with `DateTime::createFromFormat` + round-trip on both GET and POST. Status: Completed.
- Bug fixed: Cormorant Garamond was never loaded by the admin/driver layouts, so every `.font-display` silently fell back to Georgia. Added it (plus IBM Plex Mono) to the admin font request. Status: Completed.
- Mojibake: `Â·` / `â€²` were written into `admin/dispatch.php` by an edit round-trip and rendered as garbage. All non-ASCII punctuation in this file is now HTML entities (`&middot;`, `&rarr;`); file verified 0 non-ASCII bytes. Status: Completed.
- Copy fixes: removed a false claim ("every trip that still needs a driver sits at the top of the queue" — the sheet is time-ordered); `Monday 5 october` → `Monday 5 October`; `5 monday 5 october` → `5 trips · 3 still to cover`; "No record? Cover it by hand" → "No record in the system?"; temp-field placeholders say what to type ("Who is covering it", "Car that isn't in your fleet list"); status `booking_received` renders as "Booking received" instead of the raw enum. Status: Completed.
- Empty state written as an invitation, not an apology: "Nothing on the sheet for Friday 25 December" + two links out. Status: Completed.
- Accessibility: `<details>`/`<summary>` used for native keyboard-operable disclosure; `summary:focus-visible` added to the shared focus-ring rule; gap labels no longer `aria-hidden`; amber is never the only signal (text says "Needs a driver" and the diamond is a shape change too); the reveal animation is gated behind `prefers-reduced-motion: no-preference`. Status: Completed.
- Responsive verified by measurement, not eyeball: in-page probe at 340/360/390/430/600/768/900/1024/1120/1280/1440 reports `scrollWidth === clientWidth` and zero overflowing elements at every width. Status: Completed.
- Assign flow verified end-to-end over HTTP: CSRF → `DispatchService::assign` → PRG redirect with day preserved; success and the "Driver is not available for this date." conflict path both return the correct redirect, and the conflict renders as an error. Status: Completed.
- New `dp-*` component set in `assets/css/admin.css`; cache-buster `admin.css?v=20260925d`. PHPUnit green 15 tests / 53 assertions; all admin pages HTTP 200 with no PHP notices. Status: Completed.

## 2026-10-05 — Admin booking detail rebuild (owner feedback: broken layout)
- Owner rejected the Details/Actions/History tabbed detail view ("no layout design, no proper show"). Tabs removed entirely; the page is now one scannable document with no hidden content. Status: Completed.
- New structure in `admin/bookings.php?action=view`: breadcrumb → header (number + status/payment/pricing pills + Edit trip / Dispatch) → KPI strip (pickup, service, party, vehicle, balance) → quick-action bar (Confirm, Mark paid, Send payment link, Cancel) → 2-column workspace (main: route timeline + facts, charges + totals, payments, status history; aside: customer, change status, pricing, offline payment). Status: Completed.
- Route is a real vertical timeline (Pickup → Stop n → Drop) driven by `booking_stops`; no hardcoded stops. Facts grid adds mileage, hours, assigned driver (+phone from `dispatches`/`drivers`), booked-at, finalized-at, add-ons from `addons_json`. Status: Completed.
- Balance tile shows real remaining balance (total − sum of `paid` payments) instead of repeating the total. Status: Completed.
- Bug fixed: `<p class="label">` children sat inside the actions `grid`, so labels became grid cells and the form grid collapsed. All actions are now real block sections. Status: Completed.
- Nested-card nesting removed (cards inside the actions card). Quick-action forms use `.bk-inline{display:inline-flex}` rather than `display:contents` for accessibility/submission safety. Status: Completed.
- Responsive: `.bk-grid` collapses to one column below 1024px; `.bk-stack` pinned to `minmax(0,1fr)` and all grid children get `min-width:0` so wide tables can no longer stretch the page. Verified with an in-page measuring probe at 360/390/430/600/768/900/1024/1280/1440 — `document.scrollWidth === clientWidth` and zero overflowing elements at every width. Status: Completed.
- Tables drop secondary columns by viewport width (Charges: Source <640px; Payments: Paid at <768px; History: From <1280px, Actor <1536px) and history timestamps collapse to `MM-DD` + time below 1280px, so money columns stay visible instead of scrolling off. Status: Completed.
- Data fix: two `booking_stops` rows held the literal string `System.Object[]` from an earlier PowerShell QA seed; replaced with real locations (Times Square / Herald Square). Status: Completed.
- Copy fix: "1 bags" → "1 bag"; charge quantities formatted without trailing zeros. Status: Completed.
- Verification: `php -l` clean on `admin/bookings.php` + `views/layouts/admin.php`; all admin booking/dashboard/dispatch/payments pages HTTP 200 with no PHP notices; status-change POST verified end-to-end (CSRF → handler → PRG redirect); PHPUnit green 15 tests / 53 assertions. Status: Completed.
- New admin CSS component set (`bk-*`) appended to `assets/css/admin.css`; layout cache-buster bumped to `admin.css?v=20260924h`. Status: Completed.

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

## 2026-09-24 — Active-page nav underline (owner request)
- New nav_active() helper + gold .nav-active underline; wired into all desktop navs (both variants, sticky bars) and mobile menus (color); section mapping (Support covers FAQ, Services covers booking flow). Verified per-page counts + tests green. Status: Completed.

## 2026-09-24 — Auth pages index-style header (owner request)
- Public layout gained an index header variant (same links, pill, hamburger, sticky bar); all 4 auth pages use it. Other pages keep the default header. Verified live + tests green. Status: Completed.

## 2026-09-24 — Auth pages redesign (owner request, frontend-design)
- All 4 auth pages rebuilt as split members'-entrance cards (photo panel + form, our colors/copy, mobile stacks); logic untouched; register now preserves typed input on error. Login flow re-verified over HTTP + tests green. Status: Completed.

## 2026-09-24 — Removed views/public_includes (owner request)
- Re-inlined header/footer into public layout (same index-style design incl. mobile menu) and deleted the partials folder; zero references remain; pages 200 + tests green. Status: Completed.

## 2026-09-24 — Shared public header/footer partials (owner request)
- New views/public_includes/header.php + footer.php in index-page style (wordmark, links, auth-aware CTAs, mobile hamburger menu — previously missing on inner pages); public layout now includes them so every public page shares one header/footer. Verified on 4 pages + home + tests green. Status: Completed.

## 2026-09-24 — Full live trip summary (owner request)
- Sidebar now mirrors service context, route + stops, schedule, party, vehicle, add-ons with quantities and coupon in real time; proven via screenshot with prefilled values + tests green. Status: Completed.

## 2026-09-24 — Add-on cards v2: custom checkboxes + steppers (owner request)
- Gold check boxes (highlight card when checked), price pills, −/+ steppers 1–8 replacing typed numbers; live POST verified (3 boosters × $10 = $30); headless clean + tests green. Status: Completed.

## 2026-09-24 — Add-ons redesign with quantities (owner request)
- Cards with descriptions + per-unit prices; child/booster "How many?" (1–8); pricing engine multiplies quantities from admin-configured rates; live POST verified (2 seats × $15 = $30) + tests green. Status: Completed.

## 2026-09-24 — Vehicle rows with photo, rates + Read more (owner request)
- Picker rows rebuilt: left photo, name, both rates, Read more → detail page (navigates without selecting); selected row gold-bordered; keyboard operable. Verified live + tests green. Status: Completed.

## 2026-09-24 — Vehicle popup + refresh persistence (owner request)
- Step 4 vehicle select replaced with photo-card picker popup (shared helper, rates shown); wizard step saved to sessionStorage per URL and restored on refresh (proven in Node incl. URL-mismatch reset); headless-Chrome clean + tests green. Status: Completed.

## 2026-09-24 — Field-aware resume incl. luggage (owner request)
- Index widget gained luggage; booking prefill reads it; resume lands on first stage with missing input (full→4, no-luggage→3, empty→1, airport-no-direction→1). All 4 proven in headless DOM + tests green. Status: Completed.

## 2026-09-24 — Wizard resumes from widget prefill (owner request)
- Booking wizard auto-advances to first incomplete stage on prefilled arrival (route/schedule done → lands on vehicle step); empty loads still start at step 1. Proven in headless DOM both ways + tests green. Status: Completed.

## 2026-09-24 — Full-chain verification: widget → booking → payment (owner request)
- Confirmed all 10 widget params prefill booking; round-trip POST creates booking 55777470 with stop; confirmation + payment pages 200. Flow stands as designed. Status: Completed.

## 2026-09-24 — Real-time duplicate-location block (owner request)
- Selection is now refused instantly with an inline error inside the popup (both pages), not just on submit; logic unit-proven in Node; headless-Chrome clean + tests green. Status: Completed.

## 2026-09-24 — Same-location blocked (owner request)
- Pickup/destination identical rejected on index widget (inline message), booking wizard (jumps to step 2 with message) and server-side (BookingService, hourly exempt). Live POST proven blocked + tests green. Status: Completed.

## 2026-09-24 — Per-service route rules on booking (owner request)
- Step 2 mirrors the index widget: hourly hides destination (auto-filled from pickup on submit), stops only on one-way point-to-point (cleared otherwise); headless-Chrome clean; hourly POST verified + tests green. Status: Completed.

## 2026-09-24 — Step-1 clickable boxes (owner request)
- Service/trip/direction dropdowns replaced with clickable box + pill groups (p2p shows One/Round, airport shows directions, hourly shows stepper); hidden inputs carry values; airport direction validated per-step; headless-Chrome clean; airport POST verified + tests green. Status: Completed.

## 2026-09-24 — Blank-form fix: HTML-escape JSON in x-data (critical)
- Root cause (proven via headless Chrome console): raw json_encode quote chars terminated the x-data attribute early, killing the whole Alpine component so every stage hid. Fixed with e(json_encode()) on pickup/dest; audited all other attribute contexts clean. Verified zero console errors + screenshot of working wizard + tests green. Status: Completed.

## 2026-09-24 — Booking step wizard (owner request)
- One stage at a time with top horizontal tracker (scrollable mobile), per-stage validation + revisit, trip selector only on point-to-point, YOUR TRIP sidebar kept; graceful no-Alpine fallback; POST E2E verified + tests green. Status: Completed.

## 2026-09-24 — Booking page index-style pickers (owner request)
- Pickup/destination/stops open the location popup (search, Road/Airport chips, custom entry); date uses calendar popup, time uses spinner popup; shared bookingPage() factory in assets/js/booking.js; submit blocked until date+time chosen. POST E2E verified (93901889 with stop) + tests green. Status: Completed.

## 2026-09-24 — Booking page staged redesign (frontend-design)
- Form restructured into numbered journey stages (01 service → 05 contact) + sticky live trip summary sidebar; all field names/logic identical, index header; POST E2E verified (booking 66207486) + tests green. Status: Completed.

## 2026-09-24 — Services page upgrade: live prices + comparison table (frontend-design)
- Cards show live from-prices (DB rates) or Custom quote; new "Which ride fits?" comparison table (waiting, stops, minimum, starting price — all real policy/rate data). Verified live + tests green. Status: Completed.

## 2026-09-24 — Services trim + two-button cards (owner request)
- Removed category strip, stats and fleet showcase (plus dead queries); header CTA is now Book now; service cards carry gold Book + outlined Read more buttons. Verified + tests green. Status: Completed.

## 2026-09-24 — Service detail pages reference-layout rebuild (owner request, layout only)
- point-to-point, airport, hourly rebuilt: spec-column hero panel, giant mixed-type headline, gold CTA, floating fact card, editorial + photo pair + outline watermark, fact strip; index header on all three. Verified live + tests green. Status: Completed.

## 2026-09-24 — Services page reference-layout rebuild (owner request, layout only)
- Rebuilt as hero panel + category marquee + about w/ floating card + real DB stats + 5 service cards (icon, Read more, Book) + why-us accordion + fleet showcase. All data real, our theme/copy. Verified live + tests green. Status: Completed.

## 2026-09-24 — Services index redesign + index header (owner request, frontend-design)
- Rebuilt as route-board rows (SVC·01–05 incl. Direct Contract, fact chips, per-service CTAs) + index header variant. Verified live + tests green. Status: Completed.

## 2026-09-24 — Group/direct inquiry pages reference-layout rebuild (owner request, layout only)
- Split photo-process panels + organized forms, index headers, handlers untouched; group submit verified over HTTP + tests green. Status: Completed.

## 2026-09-24 — Confirmation/cancellation/fleet/payment redesign (frontend-design)
- Confirmation: centered hero (check disc, big number, status pills), detail grid, next-steps cards. Cancellation: policy + form split, danger CTA. Fleet: eyebrow + rate badge overlay. Payment: order-summary + card panels. Index header on all four; logic untouched; all 200 + tests green. Status: Completed.

## 2026-09-24 — Contact reference-layout rebuild (owner request, layout only)
- Rounded panel (headline + org info + Book CTA / form with name, phone, email, message), reassurance strip, 4 fact columns; index header; submit flow verified over HTTP + tests green. Status: Completed.

## 2026-09-24 — FAQ reference-layout rebuild (owner request, layout only)
- Centered header + contact line, 4 category pills filtering 14 real Q&As (booking, pricing & payment, rides, account) with icon squares and expandable rows, CMS body preserved below. Verified live + tests green. Status: Completed.

## 2026-09-24 — Font Awesome replaces Lucide (owner request)
- Lucide never rendered (CDN unreachable in browser). Switched to self-hosted Font Awesome 6.7.2 Free (CSS + woff2 vendored); 19 icons mapped; no JS dependency so nothing can silently fail. Font serves 200, headless clean, tests green. Status: Completed.

## 2026-09-24 — Sidebar CSS cache + self-hosted Lucide (owner requests)
- admin.css had no version string (stale cached styles) → versioned. Icons missing because unpkg was unreachable from the browser → Lucide v1.52.0 self-hosted in assets/js/vendor; all 19 icon names verified present in the build. Status: Completed.

## 2026-09-24 — Booking actions moved below details (owner request)
- Rail removed; actions now a full-width grouped section under details (Pricing/Payment/Status + dispatch shortcut); tag balance verified 19/19; tests green. Status: Completed.

## 2026-09-24 — Admin round buttons + row actions (owner request)
- All admin buttons forced pill-shaped via admin.css (cache-busted); bookings list gained Actions column (gold View + outline Dispatch for dispatchable states) and an icon-style New booking CTA. Verified 12 View buttons render + tests green. Status: Completed.

## 2026-09-24 — Admin bookings ops-console rebuild (frontend-design)
- List: toolbar card with search + status pills + live summary (count, filter, paid-in-view) and empty state. View: trip timeline card, charges table, sticky action rail grouped Pricing/Payment/Status/Danger + dispatch shortcut. Create/edit: eyebrow headers, rounded forms. Verified order + renders + tests green. Status: Completed.

## 2026-09-24 — Admin panel page language (frontend-design)
- Unified headers (eyebrow + title + actions), status_pill() helper (green/red/gold/blue, neutral fallback), row hovers, rounded op cards across all 15 content pages; logic untouched. Verified renders + tests green. Status: Completed.

## 2026-09-24 — Fixed sidebar + Lucide + Chart.js (owner request)
- Sidebar fixed with scrollable grouped nav, brand block, Lucide icons, pinned footer; responsive top-row on mobile. Dashboard chart rebuilt on Chart.js 4 with live data + tooltips; removed hand-rolled SVG. CDNs verified reachable; logged-in render 200; tests green. Status: Completed.

## 2026-09-24 — Admin dashboard reference-layout rebuild (owner request, layout only)
- Greeting bar (time-aware, date, alert bell, avatar), 4 stat cards with real period deltas, SVG revenue chart with 7/14/30d ranges from live data, latest-bookings table, needs-attention card; no invented numbers; no header change needed (admin shell kept). Verified logged-in render + tests green. Status: Completed.

## 2026-09-24 — Timezone token bug + admin login redesign (frontend-design)
- New admin login: centered card, photo panel, pill inputs, working forgot/reset links (new admin/forgot-password.php + reset-password.php). No fake social buttons, no dead links.
- Critical find while verifying: 10-hour PHP/MySQL clock split made ALL password resets silently fail (expires_at vs NOW()). Fixed with PHP-side expiry check + session time_zone alignment; locked with testResetTokenLifecycle. Full forgot→reset→login cycle proven over HTTP. Suite 15/53 green. Status: Completed.

## 2026-09-24 — Blog nav links + deep-path active states (owner request)
- Blog link in hero navs, both mobile menus and default header; nav_active() upgraded to suffix matching so 3-level paths (blogs) highlight incl. reader page. Verified + tests green. Status: Completed.

## 2026-09-24 — Blog layout upgrades (owner request)
- Listing Read-full-story is now a gold button; reader is two-column on PC (article + sticky More-blogs sidebar, stacked mobile); all blog card backgrounds removed site-wide. Verified live. Status: Completed.

## 2026-09-24 — Blog system with slug URLs (owner request)
- services/blogs/index.php (all published) + read.php?slug= (SEO meta, related, 404); blog_posts table + 3 seeds (schema + live); admin/blog.php CRUD with auto-slug + nav link; footer/sitemap links. Fixed empty-slug fallback bug found live. E2E verified (list/read/404/admin create+delete) + new test. Suite 14/48 green. Status: Completed.

## 2026-09-24 — Footer redesign + working newsletter (owner request, layout only)
- New footer: centered newsletter block (validated subscribe, PRG, duplicate-safe) + dark rounded panel (brand, org contact/socials from settings, 3 link columns, car photo). New newsletter_subscribers table (schema + live). Admin notifications page manages subscribers (unsubscribe). E2E verified (subscribe, message, dupe) + new test. Suite 13/45 green. Status: Completed.

## 2026-09-24 — Fleet listing + slug URLs (owner request)
- services/fleet.php lists all cars (Book/View each); detail resolves slug (cadillac-escalade) with legacy numeric fallback + 404; slugs auto-generated (unique) in admin + backfilled; homepage/picker link slugs; sitemap entry. Verified listing/slug/legacy/404 + tests green. Status: Completed.

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
