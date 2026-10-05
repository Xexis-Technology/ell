<?php
declare(strict_types=1);

/**
 * Rich text arrives from the Quill editor, so it is authored HTML, not plain
 * text. Keep a conservative allowlist of the tags Quill's toolbar can produce
 * and drop everything else (scripts, styles, attributes, javascript: urls).
 */
function clean_html(?string $html): string
{
    $html = trim((string)$html);
    if ($html === '') return '';
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><b><strong><i><em><u><s><a><ul><ol><li><h3><h4><blockquote>');
    // Only allow http(s) and mailto links.
    $html = preg_replace('#<a\s+[^>]*href\s*=\s*["\']?\s*(?!https?://|mailto:|#)[^"\'>\s]*#i', '<a', $html) ?? $html;
    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Flatten to plain text, for table cells, titles and excerpts. */
function plain_text(?string $html, int $max = 200): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}