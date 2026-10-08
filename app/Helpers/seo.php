<?php
declare(strict_types=1);

/**
 * Everything a search engine is told about this business that is not page copy.
 *
 * The point of this file is that the CMS preview and the rendered <head> build
 * their output from the *same* function. A schema preview that is assembled
 * separately from the schema that ships is a preview of nothing.
 *
 * What this can and cannot do, stated once so it is not oversold in the admin:
 * these are the on-page signals a crawler can actually read. They make the
 * site eligible to be surfaced correctly - a rich result, a local pack entry,
 * a larger share preview. Ranking is decided by Google's systems over the
 * whole web, and nothing here promises a position.
 */

/** schema.org business types this site could honestly describe itself as. */
const SEO_BUSINESS_TYPES = [
    'LimousineService' => 'Limo / chauffeur service',
    'TaxiService' => 'Taxi service',
    'AutoRental' => 'Car rental',
    'LocalBusiness' => 'Local business (generic)',
];

/** Day keys, in the order schema.org expects. */
const SEO_DAYS = [
    'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
    'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
];

/**
 * Read a settings value as a trimmed string. Returns '' rather than null so
 * callers never have to guard.
 */
function seo_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $v = setting($pdo, $key, $default);
        return trim((string)$v);
    } catch (Throwable) {
        return $default;
    }
}

/**
 * The business address as one readable line, joined from its parts.
 *
 * The parts are the single source of truth. `org_address` is not stored, so
 * the footer, the structured data and the schema preview cannot drift apart
 * the way a free-text address and a separately typed postal address do.
 */
function seo_address_line(PDO $pdo): string
{
    $parts = array_filter([
        seo_setting($pdo, 'org_street'),
        seo_setting($pdo, 'org_city'),
        trim(seo_setting($pdo, 'org_region') . ' ' . seo_setting($pdo, 'org_postal')),
        seo_setting($pdo, 'org_country'),
    ], static fn($v) => $v !== '');
    return implode(', ', $parts);
}

/**
 * PostalAddress, or null when too little is known to be worth emitting.
 * A schema with a street but no city is worse than no schema: it is a claim
 * the data cannot support.
 */
function seo_postal_address(PDO $pdo): ?array
{
    $street = seo_setting($pdo, 'org_street');
    $city = seo_setting($pdo, 'org_city');
    if ($street === '' && $city === '') return null;
    $a = ['@type' => 'PostalAddress'];
    if ($street !== '') $a['streetAddress'] = $street;
    if ($city !== '') $a['addressLocality'] = $city;
    $region = seo_setting($pdo, 'org_region');
    if ($region !== '') $a['addressRegion'] = $region;
    $postal = seo_setting($pdo, 'org_postal');
    if ($postal !== '') $a['postalCode'] = $postal;
    $country = seo_setting($pdo, 'org_country');
    if ($country !== '') $a['addressCountry'] = $country;
    return $a;
}

/**
 * Geo coordinates. Requires both halves - a latitude with no longitude is
 * a pin in the ocean.
 */
function seo_geo(PDO $pdo): ?array
{
    $lat = seo_setting($pdo, 'org_lat');
    $lng = seo_setting($pdo, 'org_lng');
    if ($lat === '' || $lng === '') return null;
    if (!is_numeric($lat) || !is_numeric($lng)) return null;
    return ['@type' => 'GeoCoordinates', 'latitude' => (float)$lat, 'longitude' => (float)$lng];
}

/**
 * Opening hours. A 24/7 business says so once rather than listing seven
 * identical rows, which is both shorter and what a reader actually wants.
 */
function seo_opening_hours(PDO $pdo): array
{
    if (seo_setting($pdo, 'org_hours_247') === '1') return ['Mo-Su 00:00-23:59'];

    $out = [];
    foreach (SEO_DAYS as $key => $name) {
        $raw = seo_setting($pdo, 'org_hours_' . $key);
        if ($raw === '') continue;
        // Accept "09:00-17:00" and normalise the separator, since a paste from
        // somewhere else may well use an en dash.
        $norm = str_replace(['–', '—', ' '], ['-', '-', ''], $raw);
        if (!preg_match('/^(\d{1,2}):?(\d{2})\s*-\s*(\d{1,2}):?(\d{2})$/', $norm, $m)) continue;
        $open = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        $close = sprintf('%02d:%02d', (int)$m[3], (int)$m[4]);
        $short = ucfirst(substr($name, 0, 2));
        if ($open === '00:00' && $close === '00:00') {
            $out[] = $short . ' 00:00-23:59';
        } else {
            $out[] = $short . ' ' . $open . '-' . $close;
        }
    }
    return $out;
}

/** The social profiles that identify this business, for schema.org sameAs. */
function seo_same_as(PDO $pdo): array
{
    $out = [];
    foreach (['social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_tiktok', 'social_linkedin'] as $k) {
        $v = seo_setting($pdo, $k);
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

/**
 * The business node. This is the claim a local pack or a knowledge panel is
 * built from, so every field here should be a thing the business can back up.
 */
function seo_business_node(PDO $pdo): array
{
    $name = seo_setting($pdo, 'org_name', 'Exotic Lane Limo');
    $type = seo_setting($pdo, 'schema_type', 'LimousineService');
    if (!isset(SEO_BUSINESS_TYPES[$type])) $type = 'LimousineService';

    $node = [
        '@type' => $type,
        'name' => $name,
        'url' => SITE_URL . '/',
    ];

    $desc = seo_setting($pdo, 'meta_description');
    if ($desc !== '') $node['description'] = $desc;

    $logo = seo_setting($pdo, 'site_logo');
    if ($logo !== '') $node['logo'] = media_url($logo);
    $favicon = seo_setting($pdo, 'favicon');
    if ($favicon !== '') $node['image'] = media_url($favicon);

    $phone = seo_setting($pdo, 'org_phone');
    if ($phone !== '') {
        $node['telephone'] = $phone;
        // Same number in both places: this is what lets a search engine match
        // the site to the listing the owner already has.
        $node['contactPoint'] = [
            '@type' => 'ContactPoint',
            'telephone' => $phone,
            'contactType' => 'reservations',
            'areaServed' => 'US',
        ];
    }
    $email = seo_setting($pdo, 'org_email');
    if ($email !== '') $node['email'] = $email;

    $addr = seo_postal_address($pdo);
    if ($addr) $node['address'] = $addr;
    $geo = seo_geo($pdo);
    if ($geo) $node['geo'] = $geo;

    $hours = seo_opening_hours($pdo);
    if ($hours) $node['openingHours'] = $hours;

    $price = seo_setting($pdo, 'price_range');
    if ($price !== '') $node['priceRange'] = $price;
    $currency = seo_setting($pdo, 'currency', 'USD');
    if ($currency !== '') $node['currenciesAccepted'] = $currency;

    $cities = array_values(array_filter(array_map('trim', explode(',', seo_setting($pdo, 'service_area_cities')))));
    $radius = seo_setting($pdo, 'service_area_radius');
    if ($cities !== []) {
        $node['areaServed'] = array_map(static fn($c) => ['@type' => 'City', 'name' => $c], $cities);
    } elseif ($radius !== '' && is_numeric($radius)) {
        $node['areaServed'] = [
            '@type' => 'GeoCircle',
            'geoMidpoint' => $geo ?? ['@type' => 'GeoCoordinates', 'latitude' => 0, 'longitude' => 0],
            'geoRadius' => (float)$radius * 1000,
        ];
    }

    $sameAs = seo_same_as($pdo);
    if ($sameAs !== []) $node['sameAs'] = $sameAs;

    return $node;
}

/**
 * Every schema node for a public page, as a @graph.
 *
 * @param array $ctx url, pageSeo, extra nodes supplied by the page
 */
function seo_schema_graph(PDO $pdo, array $ctx = []): array
{
    $url = (string)($ctx['url'] ?? SITE_URL . '/');
    $business = seo_business_node($pdo);
    $businessId = SITE_URL . '/#business';

    $graph = [];
    $graph[] = $business;
    $business['@id'] = $businessId;

    $website = [
        '@type' => 'WebSite',
        '@id' => SITE_URL . '/#website',
        'url' => SITE_URL . '/',
        'name' => seo_setting($pdo, 'site_title', 'Exotic Lane Limo'),
        'publisher' => ['@id' => $businessId],
    ];
    $graph[] = $website;

    // The page itself, linked to both the site and the business, so the
    // relationships between them are explicit rather than inferred.
    $page = [
        '@type' => 'WebPage',
        '@id' => $url . '#webpage',
        'url' => $url,
        'isPartOf' => ['@id' => SITE_URL . '/#website'],
        'about' => ['@id' => $businessId],
    ];
    $title = trim((string)($ctx['title'] ?? ''));
    if ($title !== '') $page['name'] = $title;
    $desc = trim((string)($ctx['description'] ?? ''));
    if ($desc !== '') $page['description'] = $desc;
    $graph[] = $page;

    foreach (($ctx['extra'] ?? []) as $node) {
        if (is_array($node) && $node !== []) $graph[] = $node;
    }

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

/**
 * JSON-LD for a set of question/answer pairs, which is what earns the
 * expandable result under a question in a search listing.
 */
function seo_faq_node(array $faqs): ?array
{
    $items = [];
    foreach ($faqs as $f) {
        $q = trim((string)($f['q'] ?? ''));
        $a = trim((string)($f['a'] ?? ''));
        if ($q === '' || $a === '') continue;
        $items[] = [
            '@type' => 'Question',
            'name' => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
        ];
    }
    return $items === [] ? null : ['@type' => 'FAQPage', 'mainEntity' => $items];
}

/** JSON-LD for a single blog post. */
function seo_article_node(array $post, string $url): ?array
{
    $title = trim((string)($post['title'] ?? ''));
    if ($title === '') return null;
    $a = [
        '@type' => 'BlogPosting',
        'headline' => $title,
        'url' => $url,
        'mainEntityOfPage' => $url,
        'publisher' => ['@id' => SITE_URL . '/#business'],
    ];
    $desc = trim((string)($post['meta_description'] ?: $post['excerpt'] ?? ''));
    if ($desc !== '') $a['description'] = $desc;
    $cover = trim((string)($post['cover'] ?? ''));
    if ($cover !== '') $a['image'] = cover_url($cover, 1200);
    $pub = trim((string)($post['published_at'] ?? ''));
    if ($pub !== '') {
        $ts = strtotime($pub);
        if ($ts !== false) $a['datePublished'] = date('c', $ts);
    }
    $upd = trim((string)($post['updated_at'] ?? ''));
    if ($upd !== '') {
        $ts = strtotime($upd);
        if ($ts !== false) $a['dateModified'] = date('c', $ts);
    }
    return $a;
}

/**
 * A plain-text diagnosis of what is filled in and what is not.
 *
 * Returns findings the owner can act on, phrased as what is missing rather
 * than as a score, because a score invites the reading that a number can be
 * gamed - and it cannot.
 *
 * @return array<int,array{tone:string,what:string,fix:string}>
 */
function seo_readiness(PDO $pdo): array
{
    $out = [];

    $add = static function (bool $ok, string $what, string $fix) use (&$out): void {
        $out[] = ['tone' => $ok ? 'ok' : 'off', 'what' => $what, 'fix' => $fix];
    };

    $add(seo_setting($pdo, 'site_title') !== '', 'Site title', 'Set a site title in SEO setup.');
    $add(seo_setting($pdo, 'meta_description') !== '', 'Default meta description', 'Set a default description in SEO setup.');
    $add(seo_setting($pdo, 'og_image') !== '' || seo_setting($pdo, 'site_logo') !== '', 'Share image', 'Upload a share image so links posted in messages show a picture.');
    $add(seo_setting($pdo, 'gsc_verification') !== '', 'Google Search Console', 'Add the verification code to claim the site in Search Console.');

    $addr = seo_postal_address($pdo);
    $add($addr !== null && ($addr['addressLocality'] ?? '') !== '', 'Local SEO: city', 'Add a street and city. Local results need a real place.');
    $add(seo_geo($pdo) !== null, 'Local SEO: map pin', 'Add latitude and longitude so the map pin lands on the door.');
    $add(seo_setting($pdo, 'org_phone') !== '', 'Local SEO: phone', 'Add a phone number. It must match the number on your Google listing.');

    $hours = seo_opening_hours($pdo);
    $add($hours !== [], 'Local SEO: opening hours', 'Add opening hours, or mark the business 24/7.');

    $area = seo_setting($pdo, 'service_area_cities') !== '' || seo_setting($pdo, 'service_area_radius') !== '';
    $add($area, 'Local SEO: service area', 'List the cities served or set a service radius.');

    $sameAs = seo_same_as($pdo);
    $add($sameAs !== [], 'Social SEO: profiles', 'Add the social profile URLs so the profiles are tied to the business.');

    // Per-page coverage, which is where most sites quietly fall down.
    try {
        $rows = $pdo->query('SELECT slug, meta_title, meta_description FROM content')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $rows = [];
    }
    $bare = 0;
    foreach ($rows as $r) {
        if (trim((string)$r['meta_description']) === '') $bare++;
    }
    $add($rows !== [] && $bare === 0, 'Page descriptions', $bare === 0
        ? 'Every editable page has its own description.'
        : $bare . ' of ' . count($rows) . ' pages still fall back to the site description.');

    return $out;
}