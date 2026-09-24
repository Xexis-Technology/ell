# EXOTIC LANE LIMO
# FINAL MASTER IMPLEMENTATION SPECIFICATION

## Purpose
This is the final implementation contract for OpenCode and other AI coding agents. Read this file, inspect the repository, and build the complete Exotic Lane Limo Phase 1 system from foundation through testing and deployment preparation.

This file consolidates the latest approved project requirements, architecture, rules, design system, task plan, URL rules, and AI memory rules. If an older document conflicts with this file, this file is the final implementation baseline.

---

# 1. EXECUTION RULE

You are the implementation agent.

Do not only create a plan. Implement the system.

Work through the project in logical phases. After each phase, run relevant tests, fix failures, and continue. Do not stop for normal confirmation between phases.

Only stop for a genuinely blocking external dependency that cannot safely be represented by configuration. Never invent credentials, API keys, payment secrets, SMTP passwords, database passwords, or production secrets.

Before coding:
1. Read `last.md` completely.
2. Read every existing `.md` project document in `docs/`.
3. Inspect the repository and current implementation.
4. Inspect git status.
5. Preserve working code and do not duplicate existing functionality.

After coding:
1. Test what changed.
2. Fix errors before continuing.
3. Update `docs/tasks.md`.
4. Update `docs/memory.md`.
5. Record files, database changes, configuration changes, bugs, decisions and tests.

---

# 2. FINAL SCOPE DECISIONS

Use the latest Phase 1 scope.

- Three portals: Public/Customer, Admin, Driver.
- Admin uses one secure Admin permission level in Phase 1. Multi-role Admin/RBAC is future scope.
- Customer has guest checkout plus account features.
- Driver registration requires Admin activation before dispatch.
- Google Maps is optional and controlled by Admin.
- Active pricing mode is either Per-Mile or Hourly.
- Maximum 6 stops.
- Hourly minimum is 2 hours.
- Airport transportation is airport to/from customer address. No flight tracking and no required flight number.
- Driver earnings are 20% company / 80% driver.
- Driver withdrawal eligibility is once every 7 days.
- Email is the Phase 1 primary notification channel.
- Live GPS, flight API, native driver app, SMS primary channel, automatic matching, route optimization, marketplace bidding, multi-role RBAC, advanced reports, advanced driver analytics and advanced Group/Event contract management are future scope.
- Use one `database/database.sql` for the application schema. Do not unnecessarily split customer/admin/driver schema into separate SQL files.
- Use simple flat Admin and Driver module files. Do not create unnecessary nested `index.php` folders.
- Budget is not an implementation constraint inside code or design.

---

# 3. STACK

Backend:
- PHP 8.3+
- MySQL 8
- PDO
- InnoDB
- utf8mb4

Frontend:
- HTML5
- CSS3
- Tailwind CSS 4
- Alpine.js
- Modern JavaScript
- Axios where useful

Composer packages:
- `phpmailer/phpmailer`
- `stripe/stripe-php`
- `dompdf/dompdf`

Server target:
- PHP-FPM
- MySQL

Do not introduce Laravel, React, Vue, Angular, Bootstrap or jQuery.

---

# 4. FINAL SIMPLE PROJECT STRUCTURE

```text
exotic-lane-limo/
├── index.php
├── .htaccess
├── .env
├── .env.example
├── composer.json
├── robots.txt
├── sitemap.xml
├── favicon.ico
│
├── services/
│   ├── index.php
│   ├── point-to-point.php
│   ├── airport.php
│   ├── hourly.php
│   ├── booking.php
│   ├── booking-confirmation.php
│   ├── payment.php
│   ├── cancellation.php
│   ├── group-event.php
│   └── direct-contract.php
│
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── forgot-password.php
│   ├── reset-password.php
│   └── logout.php
│
├── account/
│   ├── index.php
│   ├── bookings.php
│   ├── booking-view.php
│   ├── invoice.php
│   ├── profile.php
│   ├── password.php
│   └── notifications.php
│
├── legal/
│   ├── contact.php
│   ├── faq.php
│   ├── terms.php
│   └── privacy.php
│
├── admin/
│   ├── index.php
│   ├── dashboard.php
│   ├── logout.php
│   ├── bookings.php
│   ├── customers.php
│   ├── vehicles.php
│   ├── drivers.php
│   ├── dispatch.php
│   ├── pricing.php
│   ├── payments.php
│   ├── earnings.php
│   ├── payouts.php
│   ├── reports.php
│   ├── group-events.php
│   ├── notifications.php
│   ├── cms.php
│   ├── settings.php
│   └── audit.php
│
├── driver/
│   ├── index.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── rides.php
│   ├── earnings.php
│   ├── payout.php
│   └── profile.php
│
├── app/
│   ├── Controllers/
│   ├── Services/
│   ├── Models/
│   ├── Middleware/
│   └── Helpers/
│
├── views/
│   ├── layouts/
│   │   ├── public.php
│   │   ├── customer.php
│   │   ├── admin.php
│   │   └── driver.php
│   ├── emails/
│   ├── invoices/
│   └── errors/
│
├── config/
│   ├── app.php
│   ├── database.php
│   ├── auth.php
│   ├── mail.php
│   ├── stripe.php
│   └── maps.php
│
├── database/
│   └── database.sql
│
├── assets/
│   ├── css/
│   │   ├── app.css
│   │   ├── admin.css
│   │   └── driver.css
│   ├── js/
│   │   ├── app.js
│   │   ├── booking.js
│   │   ├── account.js
│   │   ├── admin.js
│   │   └── driver.js
│   ├── images/
│   └── icons/
│
├── storage/
│   ├── logs/
│   ├── uploads/
│   │   ├── drivers/
│   │   └── vehicles/
│   ├── invoices/
│   └── cache/
│
└── webhook/
    └── stripe.php
```

Only create directories/files when required. Do not create empty architecture for no reason.

---

# 5. CENTRAL URL CONFIGURATION

This is mandatory:

```php
define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'http://localhost/ell', '/'));
define('APP_ROOT', dirname(__DIR__));

function url(string $path = ''): string {
    return SITE_URL . '/' . ltrim($path, '/');
}
```

Why:
- `SITE_URL` is the browser/public URL.
- `APP_ROOT` is the server filesystem root.
- `url()` generates all browser-facing URLs consistently.
- Local, production-root and production-subdirectory deployments must work without source-code URL changes.

Examples:
```env
SITE_URL=http://localhost/ell
SITE_URL=https://exoticlanelimo.com
SITE_URL=https://example.com/ell
```

All links, assets, form actions, redirects and AJAX/API URLs must use `url()` or a server-provided `SITE_URL` value.

Never hard-code `/car/`, `/ell/`, localhost URLs or production URLs in application links.

Filesystem operations must use `APP_ROOT`, not `SITE_URL`.

---

# 6. ENVIRONMENT AND CONFIG

Create `.env.example` with:

```env
SITE_URL=
APP_ENV=
APP_DEBUG=
APP_TIMEZONE=America/New_York
APP_CURRENCY=USD
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=
STRIPE_PUBLISHABLE_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
MAPS_ENABLED=
GOOGLE_MAPS_API_KEY=
```

Never commit real secrets. Never invent credentials.

`config/app.php` handles URL, root, timezone and currency.
`config/database.php` handles PDO/MySQL.
`config/auth.php` handles secure session/auth configuration.
`config/mail.php` handles SMTP.
`config/stripe.php` handles Stripe configuration.
`config/maps.php` handles optional Maps configuration.

---

# 7. APPLICATION ARCHITECTURE

Use lightweight MVC/service separation:

```text
Page/Request
↓
Controller
↓
Service
↓
Model/PDO
↓
MySQL
```

Use `render()` to wrap views in separate layouts:
- public
- customer
- admin
- driver

Business rules belong in services, not templates.
Database access must be controlled and parameterized.

---

# 8. AUTHENTICATION AND ACCESS

Three isolated roles:

```text
CUSTOMER → /auth + /account
ADMIN    → /admin
DRIVER   → /driver
```

Customer:
- register/login/logout
- forgot/reset password
- profile
- password change
- booking history
- guest checkout

Admin:
- secure login/logout
- session regeneration
- rate limiting
- audit logging
- single permission level in Phase 1

Driver:
- registration
- Admin activation
- login/logout
- forgot/reset password
- profile

Use secure sessions, HttpOnly, Secure under HTTPS, SameSite and session regeneration.
Use `password_hash()` with Argon2id where available, otherwise secure bcrypt.
Password reset tokens must be cryptographically random, hashed in storage, expire after 30 minutes and be single-use.

---

# 9. SECURITY BASELINE

Mandatory:
- HTTPS/HSTS in production
- secure cookies
- CSRF on every state-changing form
- prepared SQL
- output escaping/XSS protection
- server-side validation
- rate limiting
- server-side authorization
- secure file uploads
- `.env` protection
- audit logs
- hashed secure payment tokens
- Stripe secrets server-side
- idempotent payment webhooks
- double-payment prevention

Sensitive SSN/compliance data must be restricted and excluded from logs.

Do not expose stack traces, credentials, secrets or SQL details in production.

---

# 10. BOOKING NUMBER

Every booking receives a unique random numeric 8-digit Booking Number.

Example:
`58310472`

Rules:
- exactly 8 digits
- random
- server-generated
- unique
- never reused
- never sequential
- separate from internal DB ID
- visible to customer/admin/driver
- searchable

Use:
```php
do {
    $bookingNumber = (string) random_int(10000000, 99999999);
    // database uniqueness check
} while ($exists);
```

Database must have a UNIQUE constraint. Catch duplicate race conditions and retry.

---

# 11. BOOKING SERVICES

Implement:

### Point-to-Point
Pickup → optional stops → destination.

### Airport
Airport ↔ customer address. No flight tracking/API. Flight number is not required.

### Hourly
Minimum 2 hours. Additional hours can be selected and priced.

Trip types where applicable:
- One-Way
- Round-Trip

Add-ons:
- Meet & Greet
- Child Seat
- Booster Seat

Maximum additional stops: 6.

---

# 12. BOOKING FLOW

```text
Service
→ Trip Type
→ Pickup/Destination/Stops
→ Date/Time
→ Passengers/Luggage
→ Vehicle
→ Add-ons
→ Coupon
→ Server-side price calculation
→ Payment
→ Booking
→ Invoice
→ Confirmation
→ Admin dispatch
→ Driver status
→ Finish
→ Earnings
```

Use a single booking UI with Alpine.js/modern JavaScript steps. Final validation and pricing are always server-side.

---

# 13. BOOKING STATUS

Use explicit statuses:

```text
pending_payment
payment_failed
awaiting_pricing
pricing_finalized
booking_received
confirmed
assigned
on_the_way
arrived
at_pickup_location
on_board
finish
cancelled
refunded
```

Do not collapse `awaiting_pricing` and `pricing_finalized`.

---

# 14. GOOGLE MAPS MODES

Maps is optional and Admin-controlled.

When enabled:
- Maps is the active location/distance method for affected flows.
- Manual mileage workflow is disabled for that flow.

When disabled:
- Customer does not enter mileage.
- Booking becomes `awaiting_pricing`.
- Admin enters/verifies mileage.
- Admin finalizes price.
- System generates a secure payment link.
- Customer pays through that link.

Never silently use Maps when disabled.

---

# 15. MAPS-DISABLED PAYMENT FLOW

```text
Customer booking
→ awaiting_pricing
→ Admin enters/verifies mileage
→ pricing_finalized
→ generate random payment token
→ store only token hash + expiry
→ email payment link
→ secure payment page
→ Stripe PaymentIntent
→ webhook
→ booking_received
→ invoice + confirmation email
```

Prevent expired-token payment, token reuse, duplicate PaymentIntents and duplicate payment records.

---

# 16. PRICING ENGINE

Admin selects exactly one active primary mode:

```text
Per-Mile
OR
Hourly
```

Each vehicle may have its own per-mile and hourly rate.

Hourly minimum: 2 hours.

Additional charges:
- Toll
- Parking
- Airport Fee
- Meet & Greet
- Child Seat
- Booster Seat
- Waiting Time
- Extra Stops
- Additional Mileage
- Additional Hours
- Custom Fee

Calculation:

```text
Active Pricing Mode
→ Additional Charges
→ Subtotal
→ Coupon/Discount
→ Tax if configured
→ Final Total
```

Store charges as line items. Recalculate on the server immediately before payment. Never trust browser-submitted totals.

Tax is Admin-configurable, default 0%, and stored as a separate invoice line item.

---

# 17. WAITING TIME

Default free waiting:

```text
Airport        60 min
Bus Terminal   30 min
Train Terminal 30 min
Cruise Terminal 30 min
Point-to-Point 15 min
```

Default additional waiting:
`$15 per additional 10 minutes`

Admin can configure these values.

Waiting charges use a pending-invoice/admin-triggered collection flow. Do not silently auto-charge Stripe.

---

# 18. PICKUP-TIME UPDATE

Customer may update pickup time before the configured 2-hour cutoff.

Store:
- original time
- updated time
- timestamp
- relevant audit data

Reject updates inside cutoff and notify relevant parties.

---

# 19. VEHICLES

Vehicle fields must support:
- make
- model
- year
- plate
- insurance
- registration
- inspection
- diamond sticker
- document expiry
- passenger capacity
- luggage capacity
- status
- category
- availability/blocking

Initial categories:
- Sedan
- SUV
- Luxury SUV
- Luxury Sedan
- Van
- Limo

Admin can add/edit/deactivate categories. Pricing remains vehicle-specific.

---

# 20. DRIVERS

Permanent driver profiles support:
- name
- mobile
- email
- documents
- SSN where required
- reference
- status
- expiry/compliance data

Support temporary/subcontracted drivers and vehicles. Preserve assignment snapshots for historical accuracy.

---

# 21. DISPATCH

Admin can:
- assign driver
- assign vehicle
- reassign driver
- reassign vehicle
- view dispatch history

Support permanent and temporary resources.

Audit reassignment.

No live GPS in Phase 1.

---

# 22. DRIVER TRIP STATUS

```text
Assigned
On the Way
Arrived
At Pickup Location
On Board
Finish
```

Store timestamps for every status transition. Prevent invalid transitions and send relevant email notifications.

---

# 23. DRIVER EARNINGS

When a completed ride is finished, create exactly one earning record.

```text
Company = 20%
Driver  = 80%
```

Example:
Gross `$5,000` → Company `$1,000` → Driver `$4,000`.

Prevent duplicate earnings when completion is retried.

Phase 1 basic earnings:
- completed rides
- gross
- driver earnings
- paid/unpaid
- payout history

Advanced analytics are future scope.

---

# 24. DRIVER PAYOUT

Withdrawal eligibility is once every 7 days according to configured policy.

Support:
- payout information
- payout request
- payout history
- Admin approve
- Admin reject
- Admin record paid

Keep audit history.

---

# 25. STRIPE

Use Stripe PHP SDK.

Never store raw card data.

Normal online flow:

```text
Validated pending booking
→ PaymentIntent
→ Customer payment
→ Stripe webhook
→ idempotent payment handling
→ booking state update
→ invoice
→ email
```

Webhook is authoritative for payment state.

Support success, failure, refund, payment records, secure payment links and double-payment protection.

Admin-created bookings may support offline/manual payment. Offline payments remain pending until explicitly marked paid.

---

# 26. INVOICES

Support browser view and PDF download through dompdf.

Customer and Admin can access invoices.

Invoice must include company, booking number, customer, trip, vehicle, charges, discount, tax, total and payment status.

---

# 27. NOTIFICATIONS

Primary channel: email via PHPMailer/SMTP.

Templates:

```text
booking-created
booking-received-awaiting-pricing
payment-received
booking-confirmed
booking-cancelled
refund-processed
driver-assigned
driver-status-update
pickup-time-updated
waiting-charge
password-reset
inquiry-received
payment-link
```

Log notification attempts. Do not expose SMTP credentials.

---

# 28. ADMIN

Implement simple pages:

```text
admin/index.php
admin/dashboard.php
admin/logout.php
admin/bookings.php
admin/customers.php
admin/vehicles.php
admin/drivers.php
admin/dispatch.php
admin/pricing.php
admin/payments.php
admin/earnings.php
admin/payouts.php
admin/reports.php
admin/group-events.php
admin/notifications.php
admin/cms.php
admin/settings.php
admin/audit.php
```

Modules can use `?action=list|view|create|edit|status|cancel|delete`.

Every mutation:
- POST only
- CSRF
- authorization
- validation
- PRG redirect
- audit where appropriate

Admin booking operations include set mileage, finalize price, send payment link, offline payment and mark paid.

---

# 29. CUSTOMER ACCOUNT

Implement:
- dashboard
- booking history
- booking details
- invoice
- profile
- password
- notifications

Customers must only access their own data.

---

# 30. GROUP & EVENT / DIRECT CONTRACT

Phase 1 supports a basic public inquiry flow.

Collect:
- event type
- dates
- vehicle count
- estimated passengers
- locations
- schedule
- special requirements
- contact details

Admin can view, quote basic pricing, change status and add notes.

Advanced multi-vehicle contract scheduling is future scope.

---

# 31. CMS / SEO

Admin settings must support:
- Site Title
- Meta Description
- Keywords
- Site Logo
- Favicon
- Default OG Image
- Google Search Console verification
- Google Analytics ID
- Facebook Pixel
- Robots settings
- Organization information
- Social links

Public pages need unique titles/descriptions, proper H1-H3 structure, sitemap, robots and basic schema/local SEO.

---

# 32. PUBLIC DESIGN

Brand: Exotic Lane Limo.

Visual direction:
- luxury chauffeur
- executive
- premium
- modern
- minimal
- elegant
- editorial

Do not look like a cheap taxi, generic rental site, SaaS template or overdecorated AI template.

Colors:

```text
#0A0A0C  main black/background
#0F0F0E  deep black/nav/footer
#181819  charcoal cards/panels
#212121  inputs/secondary surfaces
#F3D4A6  champagne main accent
#F7E2BF  light champagne
#D9B978  warm gold CTA/active
#C8A96B  muted gold borders/details
#AB8868  warm gold/tan detail
#F9F9F9  ivory / primary CTA background
#FFFFFF  white text
#F5F5F3  soft ivory
#E5E5E3  light border
#A3322F  error/danger
#8E2B29  deep error
```

Primary CTA buttons use ivory (#F9F9F9) with near-black text, hover champagne (#F3D4A6). No orange-red CTA colors are used anywhere on the site.

Use gold as an accent, not the whole interface.

Typography:
- Cormorant Garamond for display/hero/editorial headings.
- Inter for UI/body/forms/navigation.

Avoid:
- neon
- excessive gradients
- excessive rounded cards
- heavy shadows
- excessive animation
- excessive gold
- clutter

---

# 33. RESPONSIVE

Test:
- 375px
- 390px
- 414px
- 768px
- 1024px
- 1280px
- 1440px+

Public is mobile-first.
Admin has responsive sidebar/tables/forms.
Driver is strongly mobile-optimized with touch-friendly controls.
No horizontal overflow.

---

# 34. DATABASE

Use one `database/database.sql` with MySQL 8/InnoDB/utf8mb4.

Core tables/entities must cover:

- customers
- admins
- drivers
- driver documents/auth
- services
- bookings
- booking stops
- booking charges
- booking time changes
- vehicles
- vehicle categories
- vehicle documents
- vehicle blocks
- dispatches
- pricing rates
- coupons
- additional charge types
- waiting sessions
- payments
- refunds
- invoices
- driver earnings
- driver payouts
- notifications log
- Group/Event inquiries
- content
- settings
- audit logs

Use foreign keys, indexes and unique constraints.

Important constraints:
- booking number unique
- payment identifiers appropriately unique
- secure token hashes
- relational integrity

Use transactions for multi-record booking/payment/financial operations.

Preserve important financial and operational records instead of destructive deletion.

---

# 35. VALIDATION AND ERROR HANDLING

Server validates:
- required fields
- email
- phone
- dates/times
- lead time
- service
- trip type
- stops
- capacity
- luggage
- vehicle availability
- pricing mode
- coupon
- mileage
- hourly minimum
- payment state
- driver state
- payout eligibility

Use `views/errors/404.php` and `views/errors/500.php`.

Production errors must be safe and logged without exposing secrets.

---

# 36. COMPANY POLICY SUPPORT

Support applicable content/rules for:
- reservations
- hourly minimum
- waiting
- Meet & Greet
- child/booster seats
- passenger/luggage limits
- cancellation/no-show
- reservation changes
- additional stops
- toll/parking/facility fees
- vehicle substitution
- customer conduct
- smoking/vaping
- food/beverage
- alcohol
- damage/cleaning
- lost & found
- delays
- service termination
- refunds
- personal property
- service partners
- corporate accounts
- special events

Use content/settings where appropriate.

---

# 37. TESTING CONTRACT

Do not claim a feature is complete without testing it.

Test:
- PHP syntax
- Composer
- autoload
- config
- URL helper
- database schema and foreign keys
- authentication
- session security
- authorization boundaries
- booking flows
- booking number uniqueness
- pricing modes
- charges/coupons/tax
- Maps enabled/disabled
- awaiting pricing
- secure payment link
- Stripe success/failure/webhook/idempotency/refund
- waiting charges
- pickup-time cutoff
- dispatch/reassignment
- driver status lifecycle
- 20/80 earnings
- payout eligibility
- invoices/PDF
- notifications
- Admin mutations/CSRF/audit
- Group/Event inquiry
- responsive layouts
- internal links/assets/forms/redirects/AJAX
- no hard-coded environment paths
- no secrets in source/logs
- XSS/SQLi/CSRF/authorization/upload security

Run available automated tests and meaningful manual smoke tests.

---

# 38. DEPLOYMENT

Target Ubuntu 24.04 + Nginx + PHP-FPM + MySQL.

Production:
- HTTPS
- HSTS
- secure cookies
- debug disabled
- correct permissions
- storage/logs writable as needed
- `.env` protected
- database backups
- error logging

Test local, production root and production subdirectory URL behavior.

---

# 39. DEVELOPMENT PHASES

Execute in this order, but continue autonomously through the full project:

```text
01 Foundation
02 Database
03 Core configuration + URL + helpers
04 Authentication + sessions + security foundation
05 Public layout + design system
06 Public website
07 Services + vehicles + categories
08 Booking engine
09 Pricing engine
10 Customer account
11 Admin operations
12 Driver portal
13 Dispatch
14 Stripe + payments + invoices + webhooks
15 Waiting time
16 Earnings + payout
17 Notifications
18 CMS + SEO + Maps
19 Security hardening + audit
20 Full QA
21 Deployment + handover
```

Do not skip required phases merely because later code can be mocked.

If a dependency is needed early, implement the smallest real foundation needed for it.

---

# 40. AI MEMORY AND TASK UPDATES

After meaningful work update:

`docs/tasks.md`
`docs/memory.md`

Record:
- date/time
- agent
- task
- status
- created files
- modified files
- deleted files
- moved files
- database changes
- configuration changes
- features
- bugs/root causes
- tests
- decisions
- problems
- pending work
- next step

Allowed task statuses:

```text
Pending
In Progress
Completed
Partial
Blocked
Failed
Cancelled
```

Never claim testing that did not happen. Never claim deployment that did not happen. Never store secrets in memory.

If code and docs disagree, inspect code/database/memory/rules/architecture, record the conflict, and apply the latest approved decision. Never silently rewrite project history.

---

# 41. FINAL COMPLETION STANDARD

The project is complete only when the core Phase 1 system is implemented and verified:

- Public website
- Customer authentication/account
- Booking
- Booking number
- Pricing
- Vehicles
- Drivers
- Dispatch
- Driver trip statuses
- Stripe payment
- Secure payment links
- Invoice/PDF
- Waiting time
- Driver earnings 20/80
- Payout
- Email notifications
- Admin operations
- Group/Event basic flow
- CMS/SEO/settings
- Optional Maps
- Security
- Audit
- Responsive UI
- QA

Do not leave core Phase 1 features as fake buttons, TODOs or mock-only implementations.

Where external credentials are unavailable, implement the integration correctly using environment configuration and a safe error/configuration state. Never invent credentials.

---

# 42. FINAL REPORT

When the implementation is complete, provide:

```text
PROJECT STATUS

PHASES COMPLETED

DATABASE

AUTHENTICATION

PUBLIC WEBSITE

BOOKING

PRICING

CUSTOMER ACCOUNT

ADMIN

DRIVER

DISPATCH

PAYMENT

INVOICE

WAITING

EARNINGS

PAYOUT

NOTIFICATIONS

CMS / SEO

MAPS

SECURITY

TESTS

DEPLOYMENT

KNOWN ISSUES

PENDING

TASKS UPDATED: YES/NO
MEMORY UPDATED: YES/NO
```

Only report `Completed` after actual verification.

# END OF FINAL MASTER IMPLEMENTATION SPECIFICATION

# 91. COMPLETE DATABASE FIELD CONTRACT

The database implementation must be concrete, not conceptual. Every table must have a primary key, timestamps where appropriate, useful indexes, foreign keys where applicable, and correct money/date types.

## customers

Required concepts:
- id
- name
- email UNIQUE
- phone
- password_hash
- status
- created_at
- updated_at

## admins

Required concepts:
- id
- name
- email UNIQUE
- password_hash
- status
- last_login_at
- created_at
- updated_at

## drivers

Required concepts:
- id
- name
- email UNIQUE
- phone
- password_hash
- status
- reference
- ssn_encrypted_or_protected_where_required
- payout information reference
- created_at
- updated_at

## vehicles

Required concepts:
- id
- category_id
- make
- model
- year
- plate
- passenger_capacity
- luggage_capacity
- status
- temporary flag
- created_at
- updated_at

## bookings

Required concepts:
- id
- booking_number UNIQUE
- customer_id nullable
- service_type
- trip_type
- airport_direction nullable
- pickup_location
- destination_location
- pickup_date
- pickup_time
- original_pickup_time
- passengers
- luggage
- vehicle_id nullable
- mileage nullable
- hours nullable
- status
- pricing_status
- payment_status
- subtotal DECIMAL
- discount DECIMAL
- tax DECIMAL
- total DECIMAL
- currency
- coupon_id nullable
- pricing_finalized_at nullable
- created_at
- updated_at
- cancelled_at nullable
- completed_at nullable

## booking_stops

Required concepts:
- id
- booking_id
- stop_order
- location
- created_at

Unique/index booking + stop order.

## booking_charges

Required concepts:
- id
- booking_id
- charge_type
- description
- quantity
- unit_price DECIMAL
- total DECIMAL
- source
- created_at

Historical line items must not change when current pricing changes.

## booking_time_changes

Required concepts:
- id
- booking_id
- old_pickup_time
- new_pickup_time
- changed_by_type
- changed_by_id
- reason nullable
- created_at

## booking_status_logs

Required concepts:
- id
- booking_id
- old_status
- new_status
- actor_type
- actor_id
- note nullable
- created_at

## pricing_rates

Required concepts:
- id
- vehicle_id
- per_mile_rate
- hourly_rate
- active
- effective_from
- effective_to nullable
- created_at
- updated_at

## additional_charge_types

Required concepts:
- id
- code
- name
- calculation_type
- amount
- active
- created_at
- updated_at

## coupons

Required concepts:
- id
- code UNIQUE
- type
- value
- minimum_subtotal
- usage_limit nullable
- used_count
- customer_limit nullable
- starts_at
- expires_at
- active
- created_at
- updated_at

## payments

Required concepts:
- id
- booking_id
- provider
- provider_payment_id UNIQUE where applicable
- amount DECIMAL
- currency
- status
- method
- failure_code nullable
- paid_at nullable
- created_at
- updated_at

## payment_links

Required concepts:
- id
- booking_id
- token_hash UNIQUE
- expires_at
- used_at nullable
- created_at

## refunds

Required concepts:
- id
- payment_id
- provider_refund_id nullable UNIQUE
- amount DECIMAL
- status
- reason
- created_by
- created_at
- updated_at

## invoices

Required concepts:
- id
- booking_id
- invoice_number UNIQUE
- subtotal
- discount
- tax
- total
- currency
- payment_status
- payment_token_hash nullable if the implementation keeps payment-link metadata here
- payment_token_expires_at nullable
- payment_token_used_at nullable
- issued_at
- created_at
- updated_at

## dispatches

Required concepts:
- id
- booking_id
- driver_id nullable
- temporary_driver_name nullable
- temporary_driver_phone nullable
- vehicle_id nullable
- temporary_vehicle_snapshot nullable
- status
- assigned_at
- reassigned_at nullable
- created_at
- updated_at

## dispatch_history

Record every assignment and reassignment.

## waiting_rules

Required concepts:
- category
- free_minutes
- charge_interval_minutes
- charge_per_interval
- active
- updated_at

## waiting_sessions

Required concepts:
- booking_id
- category
- started_at
- ended_at nullable
- free_minutes
- billable_minutes
- rate
- charge
- status

## driver_status_logs

Record every driver trip status transition.

## driver_earnings

Required concepts:
- booking_id UNIQUE for one earning per completed ride
- driver_id
- gross_amount
- company_amount
- driver_amount
- status
- earned_at
- paid_at nullable
- created_at
- updated_at

## driver_payouts

Required concepts:
- driver_id
- amount
- eligible_at
- status
- destination_reference
- requested_at
- reviewed_at nullable
- paid_at nullable
- reviewed_by nullable
- notes

## notifications

Required concepts:
- recipient type
- recipient id
- booking id nullable
- template
- email address
- status
- provider message/reference where available
- error_message safe version
- sent_at
- created_at

## audit_logs

Required concepts:
- actor_type
- actor_id
- action
- entity_type
- entity_id
- metadata JSON where supported
- ip_address
- user_agent safe length
- created_at

---

# 92. COMPLETE PUBLIC PAGE CONTRACT

Every public page must use the public layout and centralized URL helper.

## Homepage

Must provide:
- brand/navigation
- primary booking CTA
- premium hero
- service overview
- fleet overview
- company/service trust content
- FAQ preview
- contact CTA
- footer

## Services listing

Show the available service categories and link to each service page.

## Point-to-Point

Explain service and provide booking CTA.

## Airport

Explain airport-to-address and address-to-airport transportation.

## Hourly

Explain 2-hour minimum and additional-hour pricing.

## Booking

Implement the real booking flow.

## Confirmation

Show:
- booking number
- service
- date/time
- route
- vehicle
- payment status
- invoice link where available
- next steps

Do not expose sensitive payment information.

## Payment

Support secure payment-link flow.

## Cancellation

Display current policy content and cancellation request/action where implemented.

## Group/Event

Real inquiry form.

## Direct Contract

Real inquiry form.

## Legal

Terms and privacy content must be accessible and linked from relevant forms.

---

# 93. COMPLETE CUSTOMER ACCOUNT CONTRACT

## Dashboard

Show:
- upcoming booking
- recent bookings
- payment/invoice state
- useful account shortcuts

## Bookings

Support:
- search/filter where useful
- list
- status
- booking number
- date/time
- vehicle
- total

## Booking view

Show full customer-safe booking details.

Customer must never see internal secrets, Admin notes that are private, driver SSN, or sensitive operational data.

## Invoice

Show browser invoice and PDF download.

## Profile

Allow safe profile updates.

## Password

Require current password where appropriate and secure new-password validation.

## Notifications

Show customer-safe notification history.

---

# 94. COMPLETE ADMIN PAGE CONTRACT

Every Admin page must use the Admin layout, Admin middleware and consistent navigation.

## dashboard.php

Operational summary and alerts.

## bookings.php

List, search, filter, view, create, edit, status, cancel, pricing, payment and refund operations.

## customers.php

Customer management and booking history.

## vehicles.php

Vehicle/category/document/rate management.

## drivers.php

Driver activation, profile, documents, compliance and status.

## dispatch.php

Upcoming operational dispatch board.

## pricing.php

Primary mode, rates, charges, tax, coupons and waiting rules.

## payments.php

Payment/refund records.

## earnings.php

20/80 earning ledger.

## payouts.php

Driver payout requests and payment records.

## reports.php

Basic operational/financial summaries only.

## group-events.php

Inquiry and basic quote workflow.

## notifications.php

Notification log and safe retry/status information where implemented.

## cms.php

Basic public content management.

## settings.php

General, booking, waiting, Maps, payment, SEO and organization settings.

## audit.php

Search/filter audit logs.

---

# 95. COMPLETE DRIVER PAGE CONTRACT

## register.php

Create pending driver account.

## index.php

Authentication/status router.

## dashboard.php

Show:
- next assigned ride
- active ride
- completed rides
- basic earnings
- payout status

## rides.php

Show only the authenticated driver's assigned/authorized rides.

Driver can view:
- booking number
- pickup
- destination
- stops
- date/time
- passenger/luggage count
- vehicle
- customer-safe information
- current status

## earnings.php

Show completed ride earnings and paid/unpaid ledger.

## payout.php

Show eligibility, requests and payout history.

## profile.php

Show/edit driver profile and allowed payout information.

---

# 96. COMPLETE BOOKING STATE RULES

Allowed customer/payment states must be implemented explicitly.

`pending_payment` means payment is expected.

`payment_failed` means a payment attempt failed and the booking remains recoverable according to business rules.

`awaiting_pricing` means the booking cannot yet be paid because Admin must establish mileage/final price.

`pricing_finalized` means final price exists and payment can be requested.

`booking_received` means the payment/booking intake is successfully completed.

`confirmed` means Admin has confirmed the ride.

`assigned` means driver/vehicle assignment exists.

`on_the_way`, `arrived`, `at_pickup_location`, `on_board`, `finish` are operational trip states.

`cancelled` means the booking has been cancelled.

`refunded` means applicable paid funds were refunded/recorded.

Do not use one generic status for all these meanings.

---

# 97. COMPLETE PAYMENT STATE RULES

Payment states should distinguish at least:

```text
pending
processing
paid
failed
refunded
partially_refunded
cancelled
```

Booking status and payment status are separate concepts.

Example:

```text
Booking = confirmed
Payment = paid
```

or:

```text
Booking = cancelled
Payment = refunded
```

Do not overload one field to represent both operational and financial state.

---

# 98. COMPLETE PRICING SNAPSHOT RULE

When a booking price is finalized:

1. Read current active pricing configuration.
2. Validate all selected services/add-ons.
3. Calculate on the server.
4. Store line items.
5. Store subtotal.
6. Store discount.
7. Store tax.
8. Store final total.
9. Store finalization timestamp.
10. Use this snapshot for payment and invoice.

Future Admin pricing changes must not alter an already finalized booking.

If an Admin intentionally changes a finalized booking price, create a new audited pricing revision rather than silently overwriting financial history.

---

# 99. COMPLETE AVAILABILITY RULE

Vehicle availability must consider overlapping bookings.

Driver availability must consider overlapping assigned rides.

Vehicle/driver conflicts must be checked server-side before assignment.

A vehicle marked inactive/unavailable must not appear as normally selectable.

An inactive driver must not be dispatchable.

---

# 100. COMPLETE CUSTOMER DATA PROTECTION RULE

Customer-facing pages must never expose:

- database passwords
- Admin credentials
- driver SSN
- private driver documents
- Stripe secret keys
- payment token hashes
- internal audit metadata that is not customer-safe
- other customers' records

Use customer ID scoping in every query.

---

# 101. COMPLETE ADMIN SECURITY RULE

Every Admin mutation must pass:

```text
authenticated Admin
↓
allowed action
↓
CSRF
↓
validation
↓
business rule
↓
transaction where needed
↓
audit
↓
redirect
```

Never trust a hidden form field for authorization.

---

# 102. COMPLETE DRIVER SECURITY RULE

Every Driver action must pass:

```text
authenticated Driver
↓
booking belongs to/was assigned to this driver
↓
valid status transition
↓
CSRF
↓
validation
↓
update
↓
status log
↓
notification where required
```

A driver must never update an arbitrary booking ID just because it was supplied in a POST request.

---

# 103. COMPLETE CUSTOMER BOOKING SECURITY RULE

Before creating a booking:

- validate all submitted data
- recalculate price
- validate vehicle availability
- validate coupon
- validate capacity
- validate stops
- validate service
- validate date/time
- validate Maps state
- validate payment state

Never accept:

```text
client_total
client_subtotal
client_discount
client_tax
client_driver_earning
```

as authoritative values.

---

# 104. COMPLETE EMAIL RULE

Email failures must not reveal internal exception details to customers.

Booking/payment records should not be rolled back solely because an informational email failed unless the business transaction explicitly requires it.

Email failure should be logged and retryable where appropriate.

---

# 105. COMPLETE WEBHOOK RULE

Stripe webhook handler must:

1. Read raw request body.
2. Verify Stripe signature.
3. Parse event.
4. Check idempotency.
5. Process relevant event.
6. Update payment state transactionally.
7. Update booking state appropriately.
8. Create invoice/notification only once.
9. Record webhook/event reference.
10. Return correct HTTP response.

Never mark a payment paid merely because a user visited `/payment/success`.

---

# 106. COMPLETE PRG RULE

For form mutations:

```text
POST
↓
validate/process
↓
flash message
↓
Location redirect
↓
GET
```

This prevents browser refresh from repeating important actions.

Especially required for:

- payment state
- booking status
- dispatch
- pricing
- payout
- refunds
- Admin forms

---

# 107. COMPLETE UI STATE RULE

Every asynchronous/action UI must visibly represent:

```text
idle
loading
success
error
```

Financial actions should disable duplicate submission while processing.

Do not rely on button text alone to communicate success.

---

# 108. COMPLETE ACCESSIBILITY RULE

Forms must have explicit labels.

Errors must be associated with fields.

Buttons must be actual buttons/links, not clickable decorative elements.

Keyboard navigation must work.

Tables must remain usable on smaller screens through responsive layouts.

Status indicators must use text/icons in addition to color.

---

# 109. COMPLETE URL AUDIT

Before completion, search the entire repository for:

```text
http://localhost
/car/
/ell/
https://exoticlanelimo.com
```

Any occurrence in runtime application URL generation must be reviewed.

Allowed occurrences:
- documentation examples
- `.env.example` defaults where intended
- tests explicitly testing those environments

Not allowed:
- hard-coded production links in PHP
- hard-coded local paths in application logic
- hard-coded `/car/` asset paths

---

# 110. COMPLETE CODE AUDIT

Before completion search for:

```text
TODO
FIXME
placeholder
mock
fake
return true
```

Review every result.

No required Phase 1 feature may remain a fake implementation.

---

# 111. COMPLETE DATABASE AUDIT

Verify:

- every foreign key points to the correct table
- money uses DECIMAL
- booking_number is unique
- invoice number is unique
- payment provider IDs are safely indexed
- driver earnings cannot duplicate for one completed booking
- payment links cannot reuse a token
- timestamps exist where required
- indexes support search/filter pages
- deletes do not accidentally destroy financial history

---

# 112. COMPLETE PRODUCTION AUDIT

Verify:

```text
APP_DEBUG=false
HTTPS enabled
HSTS enabled
secure cookies enabled
.env protected
uploads protected
logs writable but not public
storage protected
Stripe webhook configured
SMTP configured
DB credentials valid
DB migrations/schema loaded
cron/background jobs if any configured
robots.txt valid
sitemap.xml valid
404 valid
403 valid
500 valid
```

---

# 113. FINAL IMPLEMENTATION PRINCIPLE

The finished application must behave like one connected system, not a collection of pages.

The connection must be:

```text
Customer
↓
Booking
↓
Pricing
↓
Payment
↓
Invoice
↓
Admin
↓
Dispatch
↓
Driver
↓
Trip Completion
↓
Earnings
↓
Payout
↓
Audit / Notification / History
```

Every major entity must connect correctly through the database.

Every financial event must be traceable.

Every protected action must be authorized.

Every important state change must be testable.

Every public URL must work in local and production environments through `SITE_URL`.

This is the final standard for the Exotic Lane Limo Phase 1 implementation.

# END OF FINAL MASTER SYSTEM SPECIFICATION
