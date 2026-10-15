<?php
declare(strict_types=1);

/**
 * User Page Design - the content registry for the public marketing pages.
 *
 * One rule shapes everything here: the admin edits *words*, never *markup*.
 *
 * The public pages lean on inline structure inside their headings - a `<br>`
 * that sets the line break, an `<em>` that carries the champagne accent, a
 * `·` that separates clauses. If a heading were exposed as one free-text field,
 * the first person to type a comma into it would silently delete a design
 * decision. So every heading that carries inline markup is exposed as its
 * *parts*: the lead, the accented word, the tail. The template keeps the tags
 * and substitutes the words. That is what "content, not redesign" has to mean
 * in practice.
 *
 * The same reasoning puts link destinations in a select rather than a text box.
 * There is no way to express `javascript:` if the field can only ever hold one
 * of the routes listed in LINKS.
 *
 * Every default below is the copy that page ships with today, read straight off
 * the current templates. A missing row therefore cannot blank a page: the page
 * renders exactly what it rendered before this feature existed.
 */
final class PageContent
{
    /** The eight pages this manager owns, keyed by a stable slug. */
    public const PAGES = [
        'home' => ['label' => 'Homepage', 'path' => 'index.php'],
        'services' => ['label' => 'Service Overview', 'path' => 'services/index.php'],
        'point_to_point' => ['label' => 'Point-to-Point', 'path' => 'services/point-to-point.php'],
        'airport' => ['label' => 'Airport Transportation', 'path' => 'services/airport.php'],
        'hourly' => ['label' => 'Hourly Charter', 'path' => 'services/hourly.php'],
        'group_event' => ['label' => 'Group & Event', 'path' => 'services/group-event.php'],
        'direct_contract' => ['label' => 'Direct Contract', 'path' => 'services/direct-contract.php'],
        'contact' => ['label' => 'Contact', 'path' => 'legal/contact.php'],
        'faq' => ['label' => 'FAQ', 'path' => 'legal/faq.php'],
    ];

    /**
     * The icons a question may carry.
     *
     * A closed list, because the FAQ page inlines these as raw SVG path data.
     * Storing an icon name that the page cannot draw would render an empty box,
     * and storing the path itself would let an admin write markup. The names
     * match the map already in legal/faq.php, one for one.
     */
    public const FAQ_ICONS = [
        'pin' => 'Pin', 'clock' => 'Clock', 'edit' => 'Edit', 'plane' => 'Plane',
        'tag' => 'Tag', 'card' => 'Card', 'doc' => 'Document', 'coin' => 'Coin',
        'time' => 'Time', 'seat' => 'Seat', 'car' => 'Car', 'user' => 'Person',
        'x' => 'Cross', 'chat' => 'Chat',
    ];

    /**
     * The only destinations a button in these templates may point at.
     *
     * Deliberately a closed list. A select built from this cannot emit a
     * `javascript:`, `data:` or protocol-relative URL, and cannot be edited
     * into a link that leaves the site.
     */
    public const LINKS = [
        'Book a ride' => 'services/booking.php',
        'Book point-to-point' => 'services/booking.php?service=point_to_point',
        'Book airport transfer' => 'services/booking.php?service=airport',
        'Book hourly charter' => 'services/booking.php?service=hourly',
        'Group & event inquiry' => 'services/group-event.php',
        'Direct contract inquiry' => 'services/direct-contract.php',
        'Services overview' => 'services/index.php',
        'Point-to-point service' => 'services/point-to-point.php',
        'Airport service' => 'services/airport.php',
        'Hourly service' => 'services/hourly.php',
        'The fleet (homepage section)' => 'index.php#fleet',
        'The fleet page' => 'services/fleet.php',
        'Contact us' => 'legal/contact.php',
        'FAQ' => 'legal/faq.php',
        'My account' => 'account/index.php',
    ];

    /** Upload constraints shared by every image field on every page. */
    public const IMAGE_RULES = [
        'exts' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_bytes' => 4194304,          // 4 MB
        'min_px' => [320, 200],
        'max_px' => [6000, 4000],
    ];

    /**
     * Sections and fields, per page.
     *
     * Shape: section key => [label, note, [field key => spec]]
     * Field spec: label, type (text|textarea|link|image), default, hint
     *
     * Image fields are paired: `<key>` holds the picture, `<key>_alt` holds its
     * alt text. Keeping them apart means the alt text is editable on its own and
     * translatable without touching the file.
     */
    private static function schema(): array
    {
        static $schema = null;
        if ($schema !== null) return $schema;

        $img = static fn(string $label, string $def, string $hint, string $altDef): array => [
            'label' => $label, 'type' => 'image', 'default' => $def, 'hint' => $hint,
            'alt_default' => $altDef,
        ];
        $t = static fn(string $label, string $def = '', string $hint = ''): array
            => ['label' => $label, 'type' => 'text', 'default' => $def, 'hint' => $hint];
        $ta = static fn(string $label, string $def = '', string $hint = ''): array
            => ['label' => $label, 'type' => 'textarea', 'default' => $def, 'hint' => $hint];
        $lk = static fn(string $label, string $def, string $hint = ''): array
            => ['label' => $label, 'type' => 'link', 'default' => $def, 'hint' => $hint];

        /*
          `soft` marks a field that has a real target length rather than just a
          hard cap. A meta description past ~160 characters is truncated in a
          search result, so nearing that is worth saying out loud. The same rule
          does not apply to a 2000-character paragraph, where "90% full" is not
          information - which is why the editor warns against `soft` and never
          against the hard maximum.
        */
        $seo = static fn(string $title, string $desc): array => [
            'seo' => ['Page SEO', 'What a search result shows. Leave the description blank to use the site default.', [
                'seo_title' => $t('Page title', $title, 'Shown in the browser tab and as the search result headline.') + ['soft' => 60],
                'seo_description' => $ta('Meta description', $desc, 'About 160 characters before a search result cuts it off.') + ['soft' => 160],
                'seo_keywords' => $t('Meta keywords', '', 'Optional. Comma separated. Most search engines ignore these.'),
            ]],
        ];

        /*
          The three single-vehicle service pages share a shape, so they are built
          from one template. Their real copy differs; only the strings change.
        */
        $servicePage = static function (string $heroLead, string $heroAccent, string $heroTail, string $cta,
            string $gtk, string $gtkb, string $gtl, string $gtlt, string $aboutNo, string $aboutLead, string $aboutAccent,
            array $imgs, array $facts, string $ghost, string $seoTitle, string $seoDesc,
            string $heroCtaDest, string $noteCtaDest, string $heroImg
        ) use ($t, $ta, $lk, $img, $seo): array {
            $f = [];
            foreach ($seo($seoTitle, $seoDesc) as $k => $v) $f[$k] = $v;

            $f['hero'] = ['Hero panel', 'The large panel at the top of the page. The line break and the champagne accent are part of the design and stay put - only the words change.', [
                'hero_title_lead' => $t('Heading - first line', $heroLead),
                'hero_title_accent' => $t('Heading - accented words', $heroAccent, 'Rendered in the champagne italic. Keep it short.'),
                'hero_title_tail' => $t('Heading - closing words', $heroTail, 'Leave blank to end the heading on the accent.'),
                'hero_cta' => $t('Button label', $cta),
                'hero_cta_url' => $lk('Button goes to', $heroCtaDest),
                'hero_image' => $img('Background image', $heroImg, 'Decorative, sits behind the heading at low opacity. Landscape, 1600px wide or wider.', ''),
            ]];

            $f['note'] = ['Good to know card', 'The small panel sitting in the bottom corner of the hero.', [
                'note_eyebrow' => $t('Eyebrow', 'GOOD TO KNOW'),
                'note_title' => $t('Title', $gtk),
                'note_body' => $ta('Body', $gtkb),
                'note_cta' => $t('Link label', $gtl),
                'note_cta_url' => $lk('Link goes to', $noteCtaDest),
            ]];

            $f['about'] = ['About this service', 'The numbered editorial block below the hero.', [
                'about_eyebrow' => $t('Eyebrow', $aboutNo),
                'about_lead' => $ta('Opening line', $aboutLead),
                'about_accent' => $ta('Accented clause', $aboutAccent, 'Rendered in the muted display italic that follows the opening line.'),
                'about_image_1' => $img('Photo 1', $imgs[0]['src'], 'Landscape. Shown at about half the content width.', $imgs[0]['alt']),
                'about_image_2' => $img('Photo 2', $imgs[1]['src'], 'Landscape. Shown at about half the content width.', $imgs[1]['alt']),
                'about_image_1_alt' => $ta('Photo 1 - alt text', $imgs[0]['alt'], 'Describe the photo for anyone who cannot see it.'),
                'about_image_2_alt' => $ta('Photo 2 - alt text', $imgs[1]['alt'], 'Describe the photo for anyone who cannot see it.'),
                'ghost_word' => $t('Large background word', $ghost, 'The faint oversized word behind this section.'),
            ]];

            $f['facts'] = ['Highlights', 'Three short cards under the editorial block.', [
                'fact_1_title' => $t('Card 1 title', $facts[0][0]), 'fact_1_body' => $t('Card 1 body', $facts[0][1]),
                'fact_2_title' => $t('Card 2 title', $facts[1][0]), 'fact_2_body' => $t('Card 2 body', $facts[1][1]),
                'fact_3_title' => $t('Card 3 title', $facts[2][0]), 'fact_3_body' => $t('Card 3 body', $facts[2][1]),
                'bottom_cta' => $t('Bottom button label', $cta),
                'bottom_cta_url' => $lk('Bottom button goes to', $heroCtaDest),
            ]];
            return $f;
        };

        $schema = [];

        /* ------------------------------------------------------------ home */
        $h = [];
        foreach ($seo('Exotic Lane Limo | Luxury Chauffeur Service', 'Exotic Lane Limo - luxury chauffeur, airport and hourly service. Search, book and pay online.') as $k => $v) $h[$k] = $v;
        $h['hero'] = ['Hero', 'The heading above the booking widget on the homepage.', [
            'hero_title' => $t('Heading - first part', 'Premium car service.'),
            'hero_title_accent' => $t('Heading - accented part', 'Booked in minutes.', 'Rendered in the champagne display italic.'),
            'hero_sub' => $t('Subheading', 'Fixed pricing. Professional chauffeurs.'),
        ]];
        $h['journeys'] = ['Signature journeys', 'Four routes a rider can pick from. The images and buttons belong to each route.', [
            'journeys_eyebrow' => $t('Eyebrow', 'Start with a route'),
            'journeys_title' => $t('Heading', 'Signature journeys'),
            'journey_1_label' => $t('Route 1 name', 'Point-to-Point'),
            'journey_1_desc' => $t('Route 1 description', 'Pickup → stops → destination'),
            'journey_2_label' => $t('Route 2 name', 'Airport transfer'),
            'journey_2_desc' => $t('Route 2 description', 'Terminal to door, door to terminal'),
            'journey_3_label' => $t('Route 3 name', 'Evening on standby'),
            'journey_3_desc' => $t('Route 3 description', 'Hourly charter, 2-hour minimum'),
            'journey_4_label' => $t('Route 4 name', 'Groups & events'),
            'journey_4_desc' => $t('Route 4 description', 'Multi-vehicle, quoted by our team'),
            'journey_1_title' => $t('Route 1 panel heading', 'Across town, your way'),
            'journey_1_body' => $t('Route 1 panel body', 'Up to 6 stops · one-way or round-trip'),
            'journey_1_cta' => $t('Route 1 button', 'Book point-to-point'),
            'journey_1_cta_url' => $lk('Route 1 button goes to', 'Book point-to-point'),
            'journey_1_image' => $img('Route 1 photo', 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1200&q=60', 'Fills the panel. Landscape.', 'Luxury car on the road'),
            'journey_1_image_alt' => $ta('Route 1 photo - alt text', 'Luxury car on the road'),
            'journey_2_title' => $t('Route 2 panel heading', 'Never wait for a ride again'),
            'journey_2_body' => $t('Route 2 panel body', '60 min waiting included · meet & greet available'),
            'journey_2_cta' => $t('Route 2 button', 'Book airport transfer'),
            'journey_2_cta_url' => $lk('Route 2 button goes to', 'Book airport transfer'),
            'journey_2_image' => $img('Route 2 photo', 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=60', 'Fills the panel. Landscape.', 'Airplane wing at sunset'),
            'journey_2_image_alt' => $ta('Route 2 photo - alt text', 'Airplane wing at sunset'),
            'journey_3_title' => $t('Route 3 panel heading', 'The night is yours'),
            'journey_3_body' => $t('Route 3 panel body', '2-hour minimum · chauffeur on call'),
            'journey_3_cta' => $t('Route 3 button', 'Book hourly charter'),
            'journey_3_cta_url' => $lk('Route 3 button goes to', 'Book hourly charter'),
            'journey_3_image' => $img('Route 3 photo', 'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=1200&q=60', 'Fills the panel. Landscape.', 'City streets at night'),
            'journey_3_image_alt' => $ta('Route 3 photo - alt text', 'City streets at night'),
            'journey_4_title' => $t('Route 4 panel heading', 'Bring everyone'),
            'journey_4_body' => $t('Route 4 panel body', 'Weddings · corporate · occasions · free quote'),
            'journey_4_cta' => $t('Route 4 button', 'Request a group quote'),
            'journey_4_cta_url' => $lk('Route 4 button goes to', 'Group & event inquiry'),
            'journey_4_image' => $img('Route 4 photo', 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1200&q=60', 'Fills the panel. Landscape.', 'Chauffeur driving at night'),
            'journey_4_image_alt' => $ta('Route 4 photo - alt text', 'Chauffeur driving at night'),
        ]];
        $h['steps'] = ['How booking works', 'The four numbered steps.', [
            'steps_eyebrow' => $t('Eyebrow', 'How booking works'),
            'steps_title' => $t('Heading', 'Four steps, no surprises'),
            'steps_sub' => $t('Subheading', 'No confusion or delays. Just fixed pricing and reliable chauffeurs.'),
            'steps_image' => $img('Photo', 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1000&q=60', 'Portrait-ish crop shown beside the steps.', 'Black luxury sedan at night'),
            'steps_image_alt' => $ta('Photo - alt text', 'Black luxury sedan at night'),
            'step_1_title' => $t('Step 1 title', 'Tell us the route'),
            'step_1_body' => $t('Step 1 body', 'Pickup, stops, date and vehicle. It takes about a minute.'),
            'step_2_title' => $t('Step 2 title', 'Lock the price'),
            'step_2_body' => $t('Step 2 body', 'We calculate on our servers and freeze the total before you pay.'),
            'step_3_title' => $t('Step 3 title', 'Pay securely'),
            'step_3_body' => $t('Step 3 body', 'Card through Stripe or a secure link. You always get an invoice.'),
            'step_4_title' => $t('Step 4 title', 'Meet your chauffeur'),
            'step_4_body' => $t('Step 4 body', 'Dispatched and confirmed by email. We wait — that is the job.'),
            'steps_cta' => $t('Button label', 'Start step 01'),
            'steps_cta_url' => $lk('Button goes to', 'Book a ride'),
        ]];
        $h['fleet'] = ['Fleet', 'The fleet is listed from your live vehicle records. Only the heading and link text live here.', [
            'fleet_eyebrow' => $t('Eyebrow', 'The roster'),
            'fleet_title' => $t('Heading', 'The fleet'),
            'fleet_cta' => $t('Link label', 'Book your ride'),
            'fleet_cta_url' => $lk('Link goes to', 'Book a ride'),
        ]];
        $h['trust'] = ['Why riders choose us', 'Four reasons, and the questions box beside them.', [
            'trust_eyebrow' => $t('Eyebrow', 'Good to know'),
            'trust_title' => $t('Heading', 'Why riders choose Exotic Lane'),
            'trust_1_title' => $t('Reason 1 title', 'Professional chauffeurs'),
            'trust_1_body' => $t('Reason 1 body', 'Flight-friendly airport pickups, every time.'),
            'trust_2_title' => $t('Reason 2 title', 'Locked pricing'),
            'trust_2_body' => $t('Reason 2 body', 'Server-side totals, frozen before you pay.'),
            'trust_3_title' => $t('Reason 3 title', 'Secure payment'),
            'trust_3_body' => $t('Reason 3 body', 'Stripe checkout with invoice on every ride.'),
            'trust_4_title' => $t('Reason 4 title', 'Waiting included'),
            'trust_4_body' => $t('Reason 4 body', 'Free allowances for airports and terminals.'),
            'faq_title' => $t('Questions box heading', 'Questions, answered'),
            'faq_more' => $t('Questions box link', 'Read all FAQs'),
            'faq_contact' => $t('Questions box second link', 'Contact us'),
        ]];
        $schema['home'] = $h;

        /* ------------------------------------------- service overview */
        $s = [];
        foreach ($seo('Chauffeured service for every occasion | Exotic Lane Limo', 'Point-to-point, airport, hourly, group and contract chauffeured service with fixed pricing locked before you pay.') as $k => $v) $s[$k] = $v;
        $s['hero'] = ['Hero panel', null, [
            'hero_title_lead' => $t('Heading - first line', 'Chauffeured service'),
            'hero_title_accent' => $t('Heading - accented word', 'occasion'),
            'hero_intro' => $ta('Intro paragraph', 'From point-to-point to multi-vehicle events, one team drives every Exotic Lane service — with the price locked before you pay.'),
            'hero_cta' => $t('Primary button label', 'Book now'),
            'hero_cta_url' => $lk('Primary button goes to', 'Book a ride'),
            'hero_cta_2' => $t('Secondary button label', 'Learn more'),
            'hero_cta_2_url' => $lk('Secondary button goes to', 'Services overview'),
            'hero_badge_1' => $t('Badge 1', 'FIXED PRICING'),
            'hero_badge_2' => $t('Badge 2', '60-MIN AIRPORT WAIT'),
            'hero_badge_3' => $t('Badge 3', '2-HR HOURLY MINIMUM'),
        ]];
        $s['about'] = ['About Exotic Lane', null, [
            'about_eyebrow' => $t('Eyebrow', 'Why Exotic Lane'),
            'about_title_lead' => $t('Heading - first line', 'Executive travel,'),
            'about_title_accent' => $t('Heading - accented word', 'handled.'),
            'about_body' => $ta('Body', 'Professional chauffeurs, immaculate vehicles and server-side pricing. You tell us the route — airport, hourly or multi-stop — and we handle dispatch, waiting and confirmation by email.'),
            'about_cta' => $t('Button label', 'Book now'),
            'about_cta_url' => $lk('Button goes to', 'Book a ride'),
            'about_image' => $img('Photo', 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=1000&q=60', 'Portrait crop.', 'Black luxury sedan at night'),
            'about_image_alt' => $ta('Photo - alt text', 'Black luxury sedan at night'),
            'about_card_eyebrow' => $t('Overlay card eyebrow', 'EVERY RIDE INCLUDES'),
            'about_card_body' => $t('Overlay card text', 'Invoice · confirmation email · free waiting'),
        ]];
        $s['list'] = ['Service list', 'The heading above the grid of services. The cards themselves come from your live service records.', [
            'list_eyebrow' => $t('Eyebrow', 'Services'),
            'list_title' => $t('Heading', 'Built around your route'),
            'list_cta' => $t('Button label', 'Book now'),
            'list_cta_url' => $lk('Button goes to', 'Book a ride'),
            'list_image' => $img('Why-us photo', 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1000&q=60', 'Portrait crop beside the accordion.', 'Chauffeur driving at night'),
            'list_image_alt' => $ta('Why-us photo - alt text', 'Chauffeur driving at night'),
            'list_why_eyebrow' => $t('Accordion eyebrow', 'Why choose us'),
            'list_why_lead' => $t('Accordion heading - first line', 'Built on trust,'),
            'list_why_accent' => $t('Accordion heading - second line', 'driven by results'),
        ]];
        $schema['services'] = $s;

        /* ------------------------------------------ the three services */
        $p2p = $servicePage(
            'Your route,', 'driven', 'your way', 'Book point-to-point',
            'Stops on your terms', 'Add up to 6 stops · each priced transparently', 'View fleet', 'View fleet →',
            '01 · ABOUT THIS SERVICE',
            'Direct chauffeured transfer from pickup to destination —',
            'with up to 6 optional stops along the way.',
            [
                ['src' => 'https://images.unsplash.com/photo-1555215695-3004980ad54e?auto=format&fit=crop&w=800&q=60', 'alt' => 'Black luxury sedan'],
                ['src' => 'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?auto=format&fit=crop&w=800&q=60', 'alt' => 'Luxury car front'],
            ],
            [['15 min waiting', 'Free on every point-to-point pickup.'], ['Locked total', 'Calculated server-side before you pay.'], ['Invoice included', 'View and download from your account.']],
            'POINT-TO-POINT',
            'Point-to-Point | Exotic Lane Limo',
            'Direct chauffeured point-to-point transfers with up to 6 stops, one-way or round-trip.',
            'Book point-to-point',
            'The fleet (homepage section)',
            'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1600&q=60'
        );
        $schema['point_to_point'] = $p2p;

        $air = $servicePage(
            'Airport runs,', 'without', 'the wait', 'Book airport transfer',
            '60 minutes on us', 'Free waiting on every airport pickup · meet & greet available', 'Book now', 'Book now →',
            '02 · ABOUT THIS SERVICE',
            'Airport ↔ customer address, in both directions —',
            'no flight tracking, no required flight number.',
            [
                ['src' => 'https://images.unsplash.com/photo-1477959858617-67f85cf4f1df?auto=format&fit=crop&w=800&q=60', 'alt' => 'City skyline at night'],
                ['src' => 'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=800&q=60', 'alt' => 'City streets at night'],
            ],
            [['Both directions', 'To-airport and from-airport, same care.'], ['60 min waiting', 'Free on every airport pickup.'], ['Locked total', 'Approved by you before payment.']],
            'AIRPORT',
            'Airport Transportation | Exotic Lane Limo',
            'Airport to address and address to airport chauffeured service with 60 minutes free waiting.',
            'Book airport transfer',
            'Book airport transfer',
            'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1600&q=60'
        );
        $schema['airport'] = $air;

        $hr = $servicePage(
            'Time on', 'your', 'side', 'Book hourly charter',
            '2 hours, then flexible', 'Additional hours priced per vehicle — business, evenings, weddings', 'Book now', 'Book now →',
            '03 · ABOUT THIS SERVICE',
            'Your chauffeur on standby —',
            'business, evenings out, weddings.',
            [
                ['src' => 'https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=800&q=60', 'alt' => 'Luxury car at night'],
                ['src' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=800&q=60', 'alt' => 'Chauffeur driving at night'],
            ],
            [['2-hour minimum', 'Add more in half-hour steps.'], ['Your vehicle', 'Sedan to Sprinter, priced per vehicle.'], ['Locked total', 'Approved by you before payment.']],
            'HOURLY',
            'Hourly Charter | Exotic Lane Limo',
            'Hourly chauffeur charter with 2-hour minimum and per-vehicle additional-hour pricing.',
            'Book hourly charter',
            'Book hourly charter',
            'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=1600&q=60'
        );
        $schema['hourly'] = $hr;

        /* ------------------------------------------- inquiry pages */
        $inquiry = static function (string $slug, string $label, string $h1, string $intro, string $btn,
            string $eyebrow, array $steps, array $imgs, string $seoTitle, string $seoDesc
        ) use ($t, $ta, $lk, $img, $seo): array {
            $f = [];
            foreach ($seo($seoTitle, $seoDesc) as $k => $v) $f[$k] = $v;
            $f['intro'] = [$label . ' intro', 'The heading beside the form. The photograph and the numbered steps sit opposite it.', [
                'intro_title' => $t('Heading', $h1),
                'intro_body' => $ta('Body', $intro),
                'form_cta' => $t('Form button label', $btn),
            ]];
            $f['panel'] = ['Photo panel', 'The photograph with the numbered list over it.', [
                'panel_eyebrow' => $t('Eyebrow', $eyebrow),
                'panel_step_1' => $t('Step 1', $steps[0]),
                'panel_step_2' => $t('Step 2', $steps[1]),
                'panel_step_3' => $t('Step 3', $steps[2]),
                'panel_image' => $img('Photo', $imgs['src'], 'Fills the panel. Landscape or square.', $imgs['alt']),
                'panel_image_alt' => $ta('Photo - alt text', $imgs['alt']),
            ]];
            return $f;
        };
        $schema['group_event'] = $inquiry(
            'group_event', 'Group & event', 'Group & event, handled.',
            'No matter the headcount or schedule, our team builds the right multi-vehicle plan and quotes it.',
            'Request group quote', 'HOW GROUP BOOKINGS WORK',
            ['Tell us the occasion, headcount and schedule.', 'We respond with vehicles and a quote.', 'Confirm — chauffeurs dispatched for the day.'],
            ['src' => 'https://images.unsplash.com/photo-1464219789935-c2d9d9aba644?auto=format&fit=crop&w=1000&q=60', 'alt' => 'Vehicle on the road'],
            'Group & Event | Exotic Lane Limo',
            'Group and event transportation inquiries with prompt admin quotes for multi-vehicle needs.'
        );
        $schema['direct_contract'] = $inquiry(
            'direct_contract', 'Direct contract', 'Direct contract.',
            'Corporate accounts and recurring service — one agreement, invoiced together.',
            'Request contract quote', 'HOW CONTRACTS WORK',
            ['Describe the recurring need and routes.', 'We propose vehicles, terms and direct billing.', 'Ride on account, invoiced together.'],
            ['src' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1000&q=60', 'alt' => 'Chauffeur driving at night'],
            'Direct Contract | Exotic Lane Limo',
            'Corporate accounts and recurring chauffeured service inquiries with direct billing.'
        );

        /* ---------------------------------------------------------- contact */
        $c = [];
        foreach ($seo('Contact | Exotic Lane Limo', 'Contact Exotic Lane Limo for reservations, quotes and support.') as $k => $v) $c[$k] = $v;
        $c['intro'] = ['Intro', 'Your phone, email and address are not here: they are company settings shared across the whole site, edited in Settings.', [
            'contact_eyebrow' => $t('Eyebrow', 'Contact'),
            'contact_title_lead' => $t('Heading - first line', 'Talk to'),
            'contact_title_tail' => $t('Heading - second line', 'our team.'),
            'contact_body' => $ta('Body', 'Questions about a booking, a quote or an upcoming ride — write to us and we will reply shortly.'),
            'contact_cta' => $t('Button label', 'Book a ride'),
            'contact_cta_url' => $lk('Button goes to', 'Book a ride'),
            'contact_form_cta' => $t('Form button label', 'Contact us'),
        ]];
        $c['strip'] = ['Reassurance strip', 'Two lines under the main panel.', [
            'strip_1_title' => $t('First line - bold part', 'Booking instead?'),
            'strip_1_body' => $t('First line - rest', 'Start online — your price is locked before you pay.'),
            'strip_1_link' => $t('First line - link', 'Book a ride'),
            'strip_1_link_url' => $lk('First link goes to', 'Book a ride'),
            'strip_2_title' => $t('Second line - bold part', 'Ride-day change?'),
            'strip_2_body' => $t('Second line - rest', 'Move your pickup until 2 hours before, from your account.'),
            'strip_2_link' => $t('Second line - link', 'My account'),
            'strip_2_link_url' => $lk('Second link goes to', 'My account'),
        ]];
        $c['facts'] = ['Four facts', null, [
            'contact_fact_1_title' => $t('Fact 1 title', 'Fixed pricing'),
            'contact_fact_1_body' => $t('Fact 1 body', 'Totals calculated on our servers, frozen before payment.'),
            'contact_fact_2_title' => $t('Fact 2 title', 'Waiting included'),
            'contact_fact_2_body' => $t('Fact 2 body', '60 min at airports, 30 at terminals, 15 point-to-point.'),
            'contact_fact_3_title' => $t('Fact 3 title', 'Secure payment'),
            'contact_fact_3_body' => $t('Fact 3 body', 'Stripe checkout and an invoice on every booking.'),
            'contact_fact_4_title' => $t('Fact 4 title', 'Real chauffeurs'),
            'contact_fact_4_body' => $t('Fact 4 body', 'Vetted drivers, dispatched and confirmed by email.'),
        ]];
        $schema['contact'] = $c;

        /* -------------------------------------------------------------- faq
           The questions themselves are rows in `faqs`, not fields here - they are
           added and removed, which a fixed field list cannot express. What this
           page owns is the copy wrapped around them.

           Deliberately no SEO section: legal/faq.php already takes its title and
           description from content_seo('faq'), which admin/cms.php manages.
           Adding a second copy here would give the same tag two owners.
        */
        $schema['faq'] = [
            'intro' => ['Page text', 'The heading and the two lines above the questions.', [
                'faq_heading' => $t('Heading', 'Frequently asked questions'),
                'faq_intro' => $ta('Intro line', 'Everything about booking, pricing, rides and your account.'),
                'faq_intro_link_lead' => $t('Second line - before the link', "Can't find what you're looking for?"),
                'faq_intro_link' => $t('Second line - link text', 'Contact our team'),
                'faq_intro_link_url' => $lk('Second line - link goes to', 'Contact us', 'One of this site\'s own pages.'),
            ]],
        ];

        return $schema;
    }

    /** Full schema: page slug => section key => [label, note, fields]. */
    public static function all(): array
    {
        return self::schema();
    }

    public static function is_page(string $slug): bool
    {
        return isset(self::PAGES[$slug]);
    }

    /** Flat field map for one page: field key => spec. */
    public static function fields(string $slug): array
    {
        $out = [];
        foreach (self::schema()[$slug] ?? [] as $section) {
            foreach ($section[2] as $key => $spec) $out[$key] = $spec;
        }
        return $out;
    }

    /** Sections for one page, with ordering preserved. */
    public static function sections(string $slug): array
    {
        return self::schema()[$slug] ?? [];
    }

    /** Turn a stored link value into a safe internal path, or the field default. */
    public static function safe_link(?string $stored, string $default = ''): string
    {
        $v = trim((string)$stored);
        if ($v === '') return $default;
        // Accept either the label or the raw path, but only if the path is one
        // of ours. Anything else - javascript:, data:, //evil.test - is refused.
        if (isset(self::LINKS[$v])) return self::LINKS[$v];
        $allowed = array_values(self::LINKS);
        if (in_array($v, $allowed, true)) return $v;
        return $default;
    }
}

/* ---------------------------------------------------------------------------
   Accessors used by the public pages.
--------------------------------------------------------------------------- */

/**
 * Saved values for one page, merged over the hardcoded defaults.
 *
 * A static cache keeps this to one query per page per request; every field on a
 * page is read in the same pass.
 */
function page_content(PDO $pdo, string $page): array
{
    static $cache = [];
    if (isset($cache[$page])) return $cache[$page];

    $fields = PageContent::fields($page);
    $out = [];
    foreach ($fields as $key => $spec) $out[$key] = (string)($spec['default'] ?? '');

    try {
        $st = $pdo->prepare('SELECT field_key, value FROM page_blocks WHERE page_slug = ?');
        $st->execute([$page]);
        foreach ($st->fetchAll() as $row) {
            $k = (string)$row['field_key'];
            // Only keys the registry declares are honoured, so a stray or
            // hand-inserted row can never inject an unvalidated field.
            if (!isset($fields[$k])) continue;
            $val = (string)$row['value'];
            $out[$k] = $fields[$k]['type'] === 'link' ? PageContent::safe_link($val, (string)($fields[$k]['default'] ?? '')) : $val;
        }
    } catch (Throwable) {
        // Table missing or unreachable: keep the defaults. A public page must
        // never fail because content storage is having a bad day.
    }

    $cache[$page] = $out;
    return $out;
}

/** One field, with the page's default already applied. */
function pc_text(PDO $pdo, string $page, string $field): string
{
    $all = page_content($pdo, $page);
    return (string)($all[$field] ?? '');
}

/** SEO overrides for a page, shaped for views/layouts/public.php. */
function page_seo(PDO $pdo, string $page): array
{
    $all = page_content($pdo, $page);
    $pick = static fn(string $v): ?string => trim($v) !== '' ? trim($v) : null;
    return array_filter([
        'title' => $pick((string)($all['seo_title'] ?? '')),
        'description' => $pick((string)($all['seo_description'] ?? '')),
        'keywords' => $pick((string)($all['seo_keywords'] ?? '')),
    ], static fn($v) => $v !== null);
}

/* ---------------------------------------------------------------------------
   FAQ rows. Kept here beside the field registry because the FAQ page and its
   editor must agree on what a question is, and both read this.
--------------------------------------------------------------------------- */

/**
 * Categories for the FAQ page, newest schema first, falling back to the four the
 * page has always shipped with. The fallback matters: an admin who deletes every
 * category must not be able to blank the filter pills on a public page.
 */
function faq_groups(PDO $pdo): array
{
    $fallback = [
        ['slug' => 'booking', 'label' => 'Booking', 'n' => 0],
        ['slug' => 'pricing', 'label' => 'Pricing & Payment', 'n' => 0],
        ['slug' => 'rides', 'label' => 'Rides', 'n' => 0],
        ['slug' => 'account', 'label' => 'Account', 'n' => 0],
    ];
    try {
        $rows = $pdo->query('SELECT g.id, g.slug, g.label, g.sort_order,
                (SELECT COUNT(*) FROM faqs f WHERE f.group_id = g.id AND f.is_active = 1) AS n
            FROM faq_groups g WHERE g.is_active = 1 ORDER BY g.sort_order, g.id')->fetchAll();
    } catch (Throwable) {
        return $fallback;
    }
    if ($rows === []) return $fallback;
    return array_map(static fn(array $r): array => [
        'id' => (int)$r['id'],
        'slug' => (string)$r['slug'],
        'label' => (string)$r['label'],
        'n' => (int)$r['n'],
    ], $rows);
}

/**
 * Active questions, in the order the desk put them.
 *
 * Returns [] when the table is missing or empty, which tells the page to use its
 * own built-in list. The public page must never render an empty FAQ because
 * nobody has saved anything yet - the questions were already there in the
 * template and deleting them from a table is not the same as never having had
 * them.
 */
function faq_questions(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT f.id, f.question, f.answer, f.icon, f.sort_order, f.is_active,
                g.slug AS gslug, g.label AS glabel
            FROM faqs f JOIN faq_groups g ON g.id = f.group_id
            WHERE f.is_active = 1 AND g.is_active = 1
            ORDER BY g.sort_order, g.id, f.sort_order, f.id')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    return array_map(static fn(array $r): array => [
        'id' => (int)$r['id'],
        'g' => (string)$r['gslug'],
        'glabel' => (string)$r['glabel'],
        'icon' => (string)$r['icon'],
        'q' => (string)$r['question'],
        'a' => (string)$r['answer'],
    ], $rows);
}

/** Every question including hidden ones - the editor needs to see them all. */
function faq_questions_admin(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT f.id, f.group_id, f.question, f.answer, f.icon, f.sort_order, f.is_active,
                g.slug AS gslug, g.label AS glabel
            FROM faqs f JOIN faq_groups g ON g.id = f.group_id
            ORDER BY g.sort_order, g.id, f.sort_order, f.id')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    return array_map(static fn(array $r): array => [
        'id' => (int)$r['id'],
        'group_id' => (int)$r['group_id'],
        'gslug' => (string)$r['gslug'],
        'glabel' => (string)$r['glabel'],
        'icon' => (string)$r['icon'],
        'q' => (string)$r['question'],
        'a' => (string)$r['answer'],
        'on' => (int)$r['is_active'] === 1,
    ], $rows);
}