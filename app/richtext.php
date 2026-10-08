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
    // h2 is in the allowlist because the blog is built from headings; it was
    // missing and would have stripped the heading out of every post.
    $html = strip_tags($html, '<p><br><b><strong><i><em><u><s><a><ul><ol><li><h2><h3><h4><blockquote>');
    // Rebuild every anchor from its href alone. This keeps only http(s), mailto and
// in-page links, and drops onclick and any other attribute by construction -
    // the previous single-pattern version had an unescaped # inside its own
    // delimiter, so it never matched as written and this check did nothing.
    $html = preg_replace_callback('~<a\b([^>]*)>~i', static function (array $m): string {
        $attrs = $m[1];
        $href = '';
        if (preg_match('~\bhref\s*=\s*"([^"]*)"~i', $attrs, $h)) $href = $h[1];
        elseif (preg_match("~\bhref\s*=\s*'([^']*)'~i", $attrs, $h)) $href = $h[1];
        elseif (preg_match('~\bhref\s*=\s*([^\s>]+)~i', $attrs, $h)) $href = $h[1];
        if ($href === '' || preg_match('~^(?:https?://|mailto:|\#)~i', $href) !== 1) return '<a>';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
    }, $html) ?? $html;
    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Clean author HTML for display, and drop the artefacts the editor leaves
 * behind: empty paragraphs it inserts on every blank line, and the inline
 * styles it can add on paste. Both show up on the public page as blank gaps
 * and one-off fonts, which is what makes a post look broken.
 */
function render_rich_text(?string $html): string
{
    $html = clean_html($html);
    if ($html === '') return '';
    // <p><br></p> and friends carry no words, so they are just a blank line the
    // stylesheet would otherwise give space to.
    $html = preg_replace('#<p>(?:\s|<br\s*/?>)*</p>#i', '', $html) ?? $html;
    // Pasted formatting: keep the words, drop the inline presentation.
    $html = preg_replace('#\s+style\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? $html;
    $html = preg_replace('#\s+class\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? $html;
    return $html;
}

/** Flatten to plain text, for table cells, titles and excerpts. */
function plain_text(?string $html, int $max = 200): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}