<?php
declare(strict_types=1);

/**
 * User Page Design Manager
 *
 * One place to edit the words and pictures on the public marketing pages.
 *
 * What this page can and cannot do is the whole design, so it is worth stating
 * plainly here rather than only in the field hints:
 *
 *   - It edits values. Every field is one of the four kinds the registry in
 *     app/PageContent.php declares, and the template supplies the markup.
 *   - It cannot change layout, CSS, components, fonts, colours or templates,
 *     because there is no mechanism here that could.
 *   - Link destinations are a closed select, so `javascript:` is not reachable.
 *   - A heading whose design depends on inline markup is edited as its parts
 *     (lead / accent / tail) precisely so the markup cannot be lost.
 *
 * Everything below the field loop is the ordinary admin rule set: POST only,
 * CSRF, server-side validation, prepared statements, PRG, audit.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_role('admin');
$pdo = Database::pdo();

/*
  Messages ride back on the query string, not in the session.
  flash() looks harmless but decides read-vs-write on func_num_args(), so
  flash('msg', '') *writes* an empty message; the rest of the admin panel avoids
  it entirely and redirects with ?ok= / ?err=, which is what this does too.
*/
$msg = trim((string)($_GET['ok'] ?? ''));
$errMsg = trim((string)($_GET['err'] ?? ''));
$isErr = $errMsg !== '';

$PAGES = PageContent::PAGES;
$slug = trim((string)($_GET['page'] ?? $_POST['page'] ?? 'home'));
if (!PageContent::is_page($slug)) {
    $slug = 'home';
}
$sections = PageContent::sections($slug);
$fields = PageContent::fields($slug);

/** PRG back to a tab, with a message that survives the redirect. */
$back = static function (string $page, string $ok = '', string $err = ''): void {
    $q = 'admin/user-page-design.php?page=' . urlencode($page);
    if ($ok !== '') $q .= '&ok=' . urlencode($ok);
    if ($err !== '') $q .= '&err=' . urlencode($err);
    redirect($q);
};

/* -------------------------------------------------------------------------
   Upload rules. Reused from the CMS so both editors judge a file identically.
------------------------------------------------------------------------- */
const UPD_SUBDIR = 'pages';
const UPD_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
const UPD_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const UPD_MAX_BYTES = 4194304;          // 4 MB
const UPD_MIN_PX = [320, 200];
const UPD_MAX_PX = [6000, 4000];

/**
 * Autosave folds consecutive saves into one revision batch.
 *
 * Without this, typing a 30-character heading with a debounce writes eight
 * revision rows and "Restore previous version" would undo eight seconds of
 * typing rather than the editing session. Three minutes of continuous work is
 * treated as one batch; pause longer and the next save opens a new one.
 */
const UPD_BATCH_SECONDS = 180;

/*
  Autosave posts the same form, so the same CSRF token, the same validation and
  the same prepared statements apply. The only difference is that an XHR gets
  JSON and a normal submit gets the PRG redirect - which means autosave cannot
  quietly take a different path through the save logic than the Save button.
*/
$isAjax = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'upd-autosave'
    || (string)($_POST['ajax'] ?? '') === '1';

/** Answer an autosave request and stop; a plain submit falls through to PRG. */
$respond = static function (array $payload) use ($isAjax): void {
    if (!$isAjax) return;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

/**
 * Judge an uploaded file beyond type and size: real dimensions, and a minimum
 * so a 40x40 icon cannot end up stretched across a hero.
 */
function upd_check_dimensions(string $tmp): string
{
    $info = @getimagesize($tmp);
    if ($info === false) return 'That file could not be read as an image.';
    [$w, $h] = $info;
    if ($w < UPD_MIN_PX[0] || $h < UPD_MIN_PX[1]) {
        return 'That image is ' . $w . 'Ã—' . $h . '. It needs to be at least '
            . UPD_MIN_PX[0] . 'Ã—' . UPD_MIN_PX[1] . ' or it will look blurry stretched across the page.';
    }
    if ($w > UPD_MAX_PX[0] || $h > UPD_MAX_PX[1]) {
        return 'That image is ' . $w . 'Ã—' . $h . '. The largest accepted is '
            . UPD_MAX_PX[0] . 'Ã—' . UPD_MAX_PX[1] . '.';
    }
    return '';
}

/**
 * True when no other row still points at this file.
 *
 * Two pages can legitimately share one picture; deleting a file because one of
 * them was replaced would blank the other. We only unlink when we are the last
 * reference - and when the path is inside our own upload directory, so this
 * can never be steered at an arbitrary file.
 */
function upd_release_image(PDO $pdo, string $path): void
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, 'storage/uploads/' . UPD_SUBDIR . '/')) return;
    $st = $pdo->prepare('SELECT COUNT(*) c FROM page_blocks WHERE value = ?');
    $st->execute([$path]);
    if ((int)$st->fetch()['c'] > 0) return;      // still referenced somewhere
    $full = APP_ROOT . '/' . $path;
    $real = realpath($full);
    if ($real !== false && str_starts_with($real, APP_ROOT . '/storage/uploads/') && is_file($real)) {
        @unlink($real);
    }
}

/* -------------------------------------------------------------------------
   POST handling
------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $op = (string)($_POST['op'] ?? '');

    /*
      ---- FAQ rows -------------------------------------------------------
      Separate operations rather than one "save everything", because adding,
      deleting and reordering change which rows exist; a field-based save
      cannot express that. Each one is still POST + CSRF + validate + PRG, and
      each one writes to the audit log.
    */
    if ($op === 'faq_add') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM faq_groups WHERE id = ? LIMIT 1');
        $st->execute([$groupId]);
        if (!$st->fetch()) {
            $back('faq', '', 'Pick a category for the new question.');
        }
        $question = trim((string)($_POST['question'] ?? ''));
        $answer = trim((string)($_POST['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            $back('faq', '', 'A question needs both a question and an answer.');
        }
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM faqs WHERE group_id = ' . $groupId)->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO faqs (group_id,question,answer,icon,sort_order,is_active,updated_by)
                              VALUES (?,?,?,?,?,1,?)');
        $ins->execute([$groupId, mb_substr($question, 0, 255), $answer, 'pin', $next, (int)$admin['id']]);
        audit($pdo, 'admin', (int)$admin['id'], 'faq.added', 'faq', (int)$pdo->lastInsertId(),
            ['group_id' => $groupId, 'question' => mb_substr($question, 0, 120)]);
        $back('faq', 'Question added.');
    }

    if ($op === 'faq_delete') {
        $id = (int)($_POST['faq_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, question FROM faqs WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'faq.deleted', 'faq', $id,
                ['question' => mb_substr((string)$row['question'], 0, 120)]);
            $back('faq', 'Question removed.');
        }
        $back('faq', '', 'That question no longer exists.');
    }

    if ($op === 'faq_move') {
        $id = (int)($_POST['faq_id'] ?? 0);
        $dir = (string)($_POST['dir'] ?? 'up');
        $st = $pdo->prepare('SELECT id, group_id, sort_order FROM faqs WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            // Moving up wants the nearest row above, which is the *highest* sort_order
            // below ours - so the ORDER BY runs opposite to the comparison.
            $cmp = $dir === 'down' ? '>' : '<';
            $order = $dir === 'down' ? 'ASC' : 'DESC';
            $st = $pdo->prepare("SELECT id FROM faqs WHERE group_id = ? AND sort_order $cmp ? ORDER BY sort_order $order, id $order LIMIT 1");
            $st->execute([(int)$row['group_id'], (int)$row['sort_order']]);
            $swap = $st->fetchColumn();
            if ($swap !== false) {
                // Each row takes the other's value, so both have to be read
                // before either is written.
                $swapId = (int)$swap;
                $myOrder = (int)$row['sort_order'];
                $theirOrder = (int)$pdo->query('SELECT sort_order FROM faqs WHERE id = ' . $swapId)->fetchColumn();
                $upd = $pdo->prepare('UPDATE faqs SET sort_order = CASE id WHEN ? THEN ? WHEN ? THEN ? ELSE sort_order END WHERE id IN (?,?)');
                $upd->execute([$id, $theirOrder, $swapId, $myOrder, $id, $swapId]);
                audit($pdo, 'admin', (int)$admin['id'], 'faq.reordered', 'faq', $id, ['dir' => $dir]);
            }
        }
        $back('faq', '');
    }

    if ($op === 'faq_group_add') {
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') $back('faq', '', 'Give the category a name.');
        if (mb_strlen($label) > 80) $back('faq', '', 'That category name is too long.');
        // slug must be unique; derive and de-duplicate rather than trusting input
        $base = slugify($label);
        $slug = $base !== '' ? $base : 'group';
        $n = 1;
        while (true) {
            $st = $pdo->prepare('SELECT id FROM faq_groups WHERE slug = ? LIMIT 1');
            $st->execute([$slug]);
            if (!$st->fetch()) break;
            $slug = $base . '-' . (++$n);
        }
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM faq_groups')->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO faq_groups (slug,label,sort_order) VALUES (?,?,?)');
        $ins->execute([$slug, $label, $next]);
        audit($pdo, 'admin', (int)$admin['id'], 'faq_group.added', 'faq_group', (int)$pdo->lastInsertId(),
            ['slug' => $slug, 'label' => $label]);
        $back('faq', 'Category added.');
    }

    if ($op === 'faq_group_rename') {
        $id = (int)($_POST['group_id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($id <= 0 || $label === '') $back('faq', '', 'That category needs a name.');
        if (mb_strlen($label) > 80) $back('faq', '', 'That category name is too long.');
        $pdo->prepare('UPDATE faq_groups SET label = ? WHERE id = ?')->execute([$label, $id]);
        audit($pdo, 'admin', (int)$admin['id'], 'faq_group.renamed', 'faq_group', $id, ['label' => $label]);
        $back('faq', 'Category renamed.');
    }

    if ($op === 'faq_group_delete') {
        $id = (int)($_POST['group_id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) c FROM faqs WHERE group_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetch()['c'] > 0) {
            $back('faq', '', 'That category still has questions on it. Move or delete them first.');
        }
        $st = $pdo->prepare('SELECT id, slug FROM faq_groups WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM faq_groups WHERE id = ?')->execute([$id]);
            audit($pdo, 'admin', (int)$admin['id'], 'faq_group.deleted', 'faq_group', $id, ['slug' => $row['slug']]);
            $back('faq', 'Category removed.');
        }
        $back('faq', '', 'That category no longer exists.');
    }

    /* ---- restore the previous version of this page ---- */
    if ($op === 'restore') {
        $st = $pdo->prepare('SELECT MAX(batch_id) b FROM page_block_revisions WHERE page_slug = ?');
        $st->execute([$slug]);
        $batch = (int)($st->fetch()['b'] ?? 0);

        if ($batch <= 0) {
            $back($slug, '', 'There is no earlier version of this page to restore.');
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT field_key, old_value FROM page_block_revisions WHERE page_slug = ? AND batch_id = ?');
            $st->execute([$slug, $batch]);
            $undo = [];
            foreach ($st->fetchAll() as $r) $undo[(string)$r['field_key']] = $r['old_value'];

            $upd = $pdo->prepare('UPDATE page_blocks SET value = ?, updated_by = ? WHERE page_slug = ? AND field_key = ?');
            $del = $pdo->prepare('DELETE FROM page_blocks WHERE page_slug = ? AND field_key = ?');
            $n = 0;
            foreach ($undo as $k => $v) {
                if (!isset($fields[$k])) continue;     // never restore an unknown field
                /*
                  A NULL old value means the field had no stored row - it was
                  showing its built-in default. Updating instead of deleting would
                  write NULL and render an empty heading; the row has to go so the
                  default comes back.
                */
                if ($v === null) $del->execute([$slug, $k]);
                else $upd->execute([(string)$v, (int)$admin['id'], $slug, $k]);
                $n++;
            }
            $pdo->commit();
            audit($pdo, 'admin', (int)$admin['id'], 'page_content.restored', 'page_content', null,
                ['page' => $slug, 'batch' => $batch, 'fields' => $n]);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $back($slug, '', 'Nothing was restored. ' . $ex->getMessage());
        }
        $back($slug, 'Restored the previous version of ' . $PAGES[$slug]['label'] . ' (' . $n . ' field' . ($n === 1 ? '' : 's') . ').');
    }

    /* ---- save ---- */
    if ($op === 'save') {
        $errors = [];
        $values = [];
        $uploads = [];
        $removals = [];
        $faqTouched = 0;

        // Images first: a failed upload must be reported *instead of* the save
        // silently dropping the image field, and it must never clear the
        // picture that is already there.
        foreach ($fields as $key => $spec) {
            if ($spec['type'] !== 'image') continue;
            $input = 'img_' . $key;

            if (!empty($_POST['remove_' . $key])) {
                $removals[] = $key;
                $values[$key] = '';                       // fall back to the default picture
                continue;
            }
            if (isset($_FILES[$input]) && ($_FILES[$input]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $st = $pdo->prepare('SELECT value FROM page_blocks WHERE page_slug = ? AND field_key = ?');
                $st->execute([$slug, $key]);
                $previous = (string)($st->fetch()['value'] ?? '');

                $dimErr = upd_check_dimensions($_FILES[$input]['tmp_name']);
                if ($dimErr !== '') {
                    $errors[] = $spec['label'] . ': ' . $dimErr;
                    continue;                              // previous image survives
                }
                [$stored, $err] = secure_upload($_FILES[$input], UPD_SUBDIR, UPD_EXTS, UPD_MAX_BYTES, UPD_MIMES);
                if ($err !== null) {
                    $errors[] = $spec['label'] . ': ' . $err;
                    continue;                              // previous image survives
                }
                $values[$key] = (string)$stored;
                $uploads[$key] = $previous;              // released only after a successful save
            }
        }

        // Text and link fields.
        foreach ($fields as $key => $spec) {
            if ($spec['type'] === 'image') continue;

            if (!array_key_exists($key, $_POST)) continue;   // absent means "not on this form"
            $raw = is_string($_POST[$key]) ? trim($_POST[$key]) : '';

            if ($spec['type'] === 'link') {
                // Resolve through the field's own default. Choosing "keep as it is"
                // must leave the field exactly as it was - resolving with no
                // default turned that placeholder into a stored empty string,
                // which then quietly blanked the button until the next save.
                $fieldDefault = (string)($spec['default'] ?? '');
                $safe = PageContent::safe_link($raw, $fieldDefault);
                /*
                  safe_link() falls back to the field default rather than
                  reporting a bad destination, so asking it whether the result
                  was empty could never catch a bad link - it only said yes
                  when the default was empty too. An unrecognised target has to
                  be recognised here, or the admin's typo is silently swapped
                  for the default and they never find out.
                */
                $known = isset(PageContent::LINKS[$raw])
                    || in_array($raw, array_values(PageContent::LINKS), true);
                if ($raw !== '' && !$known) {
                    $errors[] = $spec['label'] . ': "' . $raw . '" is not a destination on this site.';
                    continue;
                }
                $values[$key] = $safe;
                continue;
            }

            $max = $spec['type'] === 'textarea' ? 2000 : 190;
            if (mb_strlen($raw) > $max) {
                $errors[] = $spec['label'] . ' is longer than ' . $max . ' characters and was left unchanged.';
                continue;
            }
            if ($key === 'seo_title' && mb_strlen($raw) > 190) {
                $errors[] = 'Page title is too long for a search result.';
                continue;
            }
            /*
              An empty submit means "back to how it started". Storing '' would
              win over the default in page_content() and leave the page blank,
              so the key is left out of $values entirely and the loop further
              down deletes the row - which is what actually restores the
              original wording.
            */
            if ($raw === '') continue;
            $values[$key] = $raw;
        }

        if ($errors !== []) {
            $respond(['ok' => false, 'errors' => $errors]);
            if ($isAjax) exit;
            // Keep what was typed so the admin does not retype a whole page.
            $_SESSION['page_content_back'] = ['page' => $slug, 'values' => $values];
            $back($slug, '', implode(' Â· ', array_slice($errors, 0, 4))
                . (count($errors) > 4 ? ' Â· and ' . (count($errors) - 4) . ' more' : ''));
        }

        // FAQ rows ride the same autosave as the text fields above: one debounce,
        // one POST, one code path. Only adding, deleting and reordering need
        // their own operations, because those change which rows exist.
        if ($slug === 'faq') {
            $ids = array_map('intval', (array)($_POST['faq_id'] ?? []));
            $qs = (array)($_POST['faq_q'] ?? []);
            $as = (array)($_POST['faq_a'] ?? []);
            $ic = (array)($_POST['faq_icon'] ?? []);
            $gn = (array)($_POST['faq_on'] ?? []);
            foreach ($ids as $i => $faqId) {
                if ($faqId <= 0) continue;
                $st = $pdo->prepare('SELECT id FROM faqs WHERE id = ? LIMIT 1');
                $st->execute([$faqId]);
                if (!$st->fetch()) continue;              // never write to an id we do not own
                $q = trim((string)($qs[$i] ?? ''));
                $a = trim((string)($as[$i] ?? ''));
                if ($q === '' || $a === '') {
                    $errors[] = 'A question needs both a question and an answer.';
                    continue;
                }
                $icon = (string)($ic[$i] ?? 'pin');
                if (!isset(PageContent::FAQ_ICONS[$icon])) $icon = 'pin';
                $upd = $pdo->prepare('UPDATE faqs SET question=?, answer=?, icon=?, is_active=?, updated_by=? WHERE id=?');
                $upd->execute([mb_substr($q, 0, 255), $a, $icon, isset($gn[$i]) ? 1 : 0, (int)$admin['id'], $faqId]);
                $faqTouched++;
            }
        }

        // A half-finished FAQ row must not leave the page half-saved: if any row
        // failed validation, none of the block-field changes go in either.
        if ($errors !== []) {
            $respond(['ok' => false, 'errors' => $errors]);
            if ($isAjax) exit;
            $_SESSION['page_content_back'] = ['page' => $slug, 'values' => $values];
            $back($slug, '', implode(' · ', array_slice($errors, 0, 4))
                . (count($errors) > 4 ? ' · and ' . (count($errors) - 4) . ' more' : ''));
        }

        $changed = [];
        $pdo->beginTransaction();
        try {
            // Record what is there *now* before overwriting it, so a restore has
            // something real to replay.
            $cur = $pdo->prepare('SELECT field_key, value FROM page_blocks WHERE page_slug = ?');
            $cur->execute([$slug]);
            $current = [];
            foreach ($cur->fetchAll() as $r) $current[(string)$r['field_key']] = $r['value'];

            // A field being set back to its built-in default has no stored value
            // to hold, so it is deleted rather than written as a duplicate of
            // the default.
            $batch = 0;
            foreach ($values as $key => $val) {
                if (!isset($fields[$key])) continue;
                $default = (string)($fields[$key]['default'] ?? '');
                if (trim($val) === trim($default)) unset($values[$key]);
            }

            // Continue the open revision batch unless this page has been quiet long
            // enough that a new one should start. See UPD_BATCH_SECONDS.
            $st = $pdo->prepare('SELECT MAX(batch_id) b FROM page_block_revisions WHERE page_slug = ?');
            $st->execute([$slug]);
            $batch = (int)($st->fetch()['b'] ?? 0);
            if ($batch > 0) {
                $st = $pdo->prepare('SELECT created_at FROM page_block_revisions WHERE page_slug = ? AND batch_id = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$slug, $batch]);
                $lastAt = strtotime((string)($st->fetch()['created_at'] ?? '')) ?: 0;
                if ($lastAt === 0 || (time() - $lastAt) > UPD_BATCH_SECONDS) $batch++;
            }
            if ($batch < 1) $batch = 1;

            $ins = $pdo->prepare('INSERT INTO page_blocks (page_slug, section_key, field_key, value, value_type, sort_order, updated_by)
                                  VALUES (?,?,?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE value=VALUES(value), value_type=VALUES(value_type),
                                  section_key=VALUES(section_key), updated_by=VALUES(updated_by)');
            $del = $pdo->prepare('DELETE FROM page_blocks WHERE page_slug = ? AND field_key = ?');
            $rev = $pdo->prepare('INSERT INTO page_block_revisions (page_slug, batch_id, field_key, old_value, changed_by) VALUES (?,?,?,?,?)');

            foreach ($values as $key => $val) {
                $old = $current[$key] ?? null;
                if ($old !== null && trim((string)$old) === trim((string)$val)) continue;   // unchanged
                $rev->execute([$slug, $batch, $key, $old, (int)$admin['id']]);
                $changed[] = $key;

                $section = 'general';
                $order = 0;
                foreach ($sections as $sk => $sec) {
                    foreach ($sec[2] as $k2 => $_) {
                        if ($k2 === $key) { $section = $sk; $order = $order * 100 + array_search($k2, array_keys($sec[2]), true); }
                    }
                }
                $ins->execute([$slug, $section, $key, $val, $fields[$key]['type'], (int)$order, (int)$admin['id']]);
            }

            // Fields the admin cleared back to their default.
            foreach ($fields as $key => $spec) {
                if (!array_key_exists($key, $_POST)) continue;
                if (array_key_exists($key, $values)) continue;
                if (!array_key_exists($key, $current)) continue;     // already default
                $raw = is_string($_POST[$key]) ? trim($_POST[$key]) : '';
                if ($raw !== '' ) continue;                          // not cleared to default
                $rev->execute([$slug, $batch, $key, $current[$key], (int)$admin['id']]);
                $del->execute([$slug, $key]);
                $changed[] = $key;
            }

            foreach ($removals as $key) {
                $old = $current[$key] ?? null;
                if ($old === null) continue;
                $rev->execute([$slug, $batch, $key, $old, (int)$admin['id']]);
                $del->execute([$slug, $key]);
                $changed[] = $key;
                $uploads[$key] = (string)$old;            // released after commit
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $back($slug, '', 'Nothing was saved. ' . $ex->getMessage());
        }

        // Only now, with the new values committed, is it safe to drop files
        // that nothing points at any more.
        foreach ($uploads as $oldPath) upd_release_image($pdo, (string)$oldPath);

audit($pdo, 'admin', (int)$admin['id'], 'page_content.updated', 'page_content', null,
    ['page' => $slug, 'fields' => count($changed), 'faq_rows' => $faqTouched]);

$_SESSION['page_content_back'] = null;

// Report the image paths that are live now, so the editor can repoint a
// thumbnail at the new file without reloading and losing the caret.
$images = [];
foreach ($fields as $k => $spec) {
    if ($spec['type'] !== 'image') continue;
    $st = $pdo->prepare('SELECT value FROM page_blocks WHERE page_slug = ? AND field_key = ?');
    $st->execute([$slug, $k]);
    $row = $st->fetch();
    $images[$k] = ['path' => $row ? (string)$row['value'] : '', 'alt' => (string)($current[$k] ?? '')];
}
$totalChanged = count($changed) + $faqTouched;
$respond(['ok' => true, 'saved' => $totalChanged, 'images' => $images]);

$back($slug, $totalChanged === 0
    ? 'No changes to save.'
    : 'Saved ' . $totalChanged . ' change' . ($totalChanged === 1 ? '' : 's') . ' to ' . $PAGES[$slug]['label'] . '.');
}

    $back($slug, '', 'Unknown action.');
}

/* -------------------------------------------------------------------------
   Read the current values: saved first, then the built-in default.
------------------------------------------------------------------------- */
$current = [];
foreach ($fields as $k => $spec) $current[$k] = (string)($spec['default'] ?? '');
try {
    $st = $pdo->prepare('SELECT field_key, value FROM page_blocks WHERE page_slug = ?');
    $st->execute([$slug]);
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['field_key'];
        if (isset($fields[$k])) $current[$k] = (string)$r['value'];
    }
} catch (Throwable) {
    // fall back to defaults
}
$edited = [];
try {
    $st = $pdo->prepare('SELECT field_key FROM page_blocks WHERE page_slug = ?');
    $st->execute([$slug]);
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['field_key'];
        if (isset($fields[$k])) $edited[$k] = true;
    }
} catch (Throwable) {
}

// Values that failed validation on the last submit, so nothing is retyped.
// Named $replay, not $back: $back is the redirect closure above, and quietly
// reassigning it on GET worked only by luck of ordering.
$replay = $_SESSION['page_content_back'] ?? null;
if (is_array($replay) && ($replay['page'] ?? '') === $slug) {
    foreach ((array)($replay['values'] ?? []) as $k => $v) if (isset($fields[$k])) $current[$k] = (string)$v;
    $_SESSION['page_content_back'] = null;
}

/*
  FAQ rows. Read for the editor only, and only on the FAQ tab, so the other
  eight pages never touch these tables.
*/
$faqGroups = [];
$faqRows = [];
if ($slug === 'faq') {
    try {
        $faqGroups = $pdo->query('SELECT id, slug, label, sort_order,
                (SELECT COUNT(*) FROM faqs f WHERE f.group_id = g.id AND f.is_active = 1) AS n
            FROM faq_groups g WHERE g.is_active = 1 ORDER BY g.sort_order, g.id')->fetchAll();
    } catch (Throwable) {
        $faqGroups = [];
    }
    $faqRows = faq_questions_admin($pdo);
}
$faqByGroup = [];
foreach ($faqRows as $r) $faqByGroup[(int)$r['group_id']][] = $r;

// Was there a previous save to restore?
$hasRestore = false;
try {
    $st = $pdo->prepare('SELECT COUNT(*) c FROM page_block_revisions WHERE page_slug = ?');
    $st->execute([$slug]);
    $hasRestore = (int)$st->fetch()['c'] > 0;
} catch (Throwable) {
}

/*
  Capture the view into $content for the admin layout.
  Without this the markup echoes straight to the browser, $content arrives at the
  layout empty, <main class="admin-main"> renders nothing, and the page lays out
  full-bleed underneath the fixed sidebar - while every field still works and
  every overflow assertion still passes. Presence is not containment.
*/
ob_start();

$tabIndex = array_search($slug, array_keys($PAGES), true);
$tabIndex = $tabIndex === false ? 0 : (int)$tabIndex;
$pageLabel = $PAGES[$slug]['label'];
$path = $PAGES[$slug]['path'];
$editedCount = count($edited);
?>
<?php if ($msg): ?><div class="alert alert-ok" role="status"><?= e($msg) ?></div><?php endif; ?>
<?php if ($isErr): ?><div class="alert alert-err" role="alert"><?= e($errMsg) ?></div><?php endif; ?>

<div class="page-head upd-head">
  <div class="upd-head-main">
    <p class="eyebrow">Content</p>
    <h1 class="font-display text-3xl mt-1">User Page Design</h1>
    <p class="upd-lede">Edit the words and pictures on the public pages. The layout, styling and behaviour are not editable here — that is on purpose.</p>
  </div>
  <div class="upd-head-side">
    <!-- Save state. This is the honest answer to "did that stick?", and it sits
         top-right because that is where a status belongs, not at the bottom of a
         form that is 72 fields long. -->
    <p class="upd-status" data-state="<?= $editedCount > 0 ? 'saved' : 'idle' ?>" role="status" aria-live="polite">
      <span class="upd-dot" aria-hidden="true"></span>
      <span class="upd-say"><?= $editedCount > 0 ? 'Saved' : 'Nothing changed yet' ?></span>
    </p>
    <a class="bk-btn bk-btn-line" href="<?= e(url($path)) ?>" target="_blank" rel="noopener">Preview page<span aria-hidden="true"> ↗</span></a>
  </div>
</div>

<div class="upd-err" id="updErr" hidden role="alert"></div>

<nav class="upd-tabs" role="tablist" aria-label="Public pages">
  <?php foreach ($PAGES as $k => $meta): ?>
    <a role="tab" class="upd-tab<?= $k === $slug ? ' is-on' : '' ?>" href="<?= e(url('admin/user-page-design.php?page=' . $k)) ?>"
       aria-selected="<?= $k === $slug ? 'true' : 'false' ?>"><?= e($meta['label']) ?></a>
  <?php endforeach; ?>
</nav>

<form method="post" action="<?= e(url('admin/user-page-design.php?page=' . $slug)) ?>" enctype="multipart/form-data" class="upd-form">
  <?= csrf_field() ?>
  <input type="hidden" name="op" value="save">
  <input type="hidden" name="page" value="<?= e($slug) ?>">
  <input type="hidden" name="ajax" value="0" id="updAjax">

  <div class="upd-body">

    <!-- The rail. A 72-field page has no shape without one; this is the thing
         that makes "where am I, and what is left" answerable at a glance. -->
    <nav class="upd-rail" aria-label="Sections on this page">
      <p class="upd-rail-h">On this page</p>
      <?php foreach ($sections as $sKey => [$sLabel, , $sFields]): ?>
        <a class="upd-rail-link" href="#sec-<?= e($sKey) ?>" data-rail="<?= e($sKey) ?>">
          <span><?= e($sLabel) ?></span><b class="tabular"><?= count($sFields) ?></b>
        </a>
      <?php endforeach; ?>
      <p class="upd-rail-f"><?= count($fields) ?> fields · <?= $editedCount ?> changed</p>
    </nav>

    <div class="upd-col">
      <?php foreach ($sections as $sKey => [$sLabel, $sNote, $sFields]): ?>
        <section class="upd-sec" id="sec-<?= e($sKey) ?>" data-sec="<?= e($sKey) ?>">
          <header class="upd-sec-head">
            <h2 class="font-display text-xl"><?= e($sLabel) ?></h2>
            <?php if ($sNote): ?><p class="upd-sec-note"><?= e($sNote) ?></p><?php endif; ?>
          </header>

          <div class="upd-grid">
            <?php foreach ($sFields as $key => $spec):
              $val = (string)($current[$key] ?? '');
              $id = 'f_' . $key;
              $isEdited = isset($edited[$key]);
              $max = $spec['type'] === 'textarea' ? 2000 : 190;
              $len = $spec['type'] === 'link' ? 0 : mb_strlen($val);
              $near = $len >= (int)($max * 0.85);
            ?>
              <div class="upd-field upd-type-<?= e($spec['type']) ?><?= $isEdited ? ' is-edited' : '' ?>">
                <div class="upd-label-row">
                  <label class="upd-label" for="<?= e($id) ?>"><?= e($spec['label']) ?></label>
                  <?php if ($isEdited): ?><span class="upd-edited">Changed</span><?php endif; ?>
                  <?php if ($spec['type'] !== 'image'): ?>
                    <span class="upd-count<?= $near ? ' is-near' : '' ?>"><?= $len ?>/<?= $max ?></span>
                  <?php endif; ?>
                </div>
                <p class="upd-key"><?= e($key) ?></p>
                <?php if ($spec['type'] === 'textarea'): ?>
                  <textarea id="<?= e($id) ?>" name="<?= e($key) ?>" class="input upd-input" rows="3" maxlength="<?= $max ?>"><?= e($val) ?></textarea>

                <?php elseif ($spec['type'] === 'link'): ?>
                  <select id="<?= e($id) ?>" name="<?= e($key) ?>" class="input upd-input">
                    <?php foreach (PageContent::LINKS as $lLabel => $lUrl): ?>
                      <option value="<?= e($lLabel) ?>"<?= ($val === $lUrl || $val === $lLabel) ? ' selected' : '' ?>><?= e($lLabel) ?></option>
                    <?php endforeach; ?>
                  </select>

                <?php elseif ($spec['type'] === 'image'): ?>
                  <?php $src = $val !== '' ? $val : (string)$spec['default']; ?>
                  <?php $usingDefault = $val === '' || $val === (string)$spec['default']; ?>
                  <div class="upd-img" data-img="<?= e($key) ?>">
                    <div class="upd-thumb<?= $usingDefault ? ' is-default' : '' ?>" data-thumb="<?= e($key) ?>">
                      <?php if ($src !== ''): ?>
                        <img src="<?= e(media_url($src)) ?>" alt="" loading="lazy" onerror="this.parentNode.classList.add('is-broken')">
                      <?php else: ?>
                        <span class="upd-none">none</span>
                      <?php endif; ?>
                    </div>
                    <div class="upd-img-side">
                      <p class="upd-img-state" data-state="<?= $usingDefault ? 'default' : 'live' ?>">
                        <?= $usingDefault ? "Using the site's picture" : 'Uploaded picture is live' ?>
                      </p>
                      <input type="file" id="<?= e($id) ?>" name="img_<?= e($key) ?>" class="upd-file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                      <?php if (!$usingDefault): ?>
                        <label class="upd-remove"><input type="checkbox" name="remove_<?= e($key) ?>" value="1"> Remove and go back to the site picture</label>
                      <?php endif; ?>
                    </div>
                  </div>

                <?php else: ?>
                  <input type="text" id="<?= e($id) ?>" name="<?= e($key) ?>" class="input upd-input" value="<?= e($val) ?>" maxlength="<?= $max ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ($slug === 'faq'):
        /*
          One running index across every group, not one per group: the inputs
          are posted as faq_q[0], faq_q[1]... and the server walks faq_id[] in
          step, so two groups both starting at zero would point every question
          after the first at the wrong row.
        */
        $fq = 0;
      ?>
        <p class="upd-faq-lede">Questions are rows rather than fields, so they can be added, reordered and removed. Edits save by themselves, the same as the text above.</p>
        <?php foreach ($faqGroups as $g):
          $gid = (int)$g['id'];
          $rows = $faqByGroup[$gid] ?? [];
        ?>
          <section class="upd-faq-group" id="faqgrp-<?= $gid ?>">
            <header class="upd-faq-group-head">
              <h2 class="font-display text-xl"><?= e($g['label']) ?></h2>
              <span class="tabular"><?= count($rows) ?> <?= count($rows) === 1 ? 'question' : 'questions' ?></span>
            </header>

            <div class="upd-faq-rows">
              <?php foreach ($rows as $i => $r): $fid = (int)$r['id']; ?>
                <article class="upd-faq-row<?= $r['on'] ? '' : ' is-off' ?>">
                  <input type="hidden" name="faq_id[<?= $fq ?>]" value="<?= $fid ?>">
                  <div class="upd-faq-row-head">
                    <span class="upd-faq-n tabular"><?= $i + 1 ?></span>
                    <label class="upd-sr" for="faqi-<?= $fid ?>">Icon</label>
                    <select id="faqi-<?= $fid ?>" class="input upd-faq-icon" name="faq_icon[<?= $fq ?>]">
                      <?php foreach (PageContent::FAQ_ICONS as $ik => $ilabel): ?>
                        <option value="<?= e($ik) ?>"<?= $r['icon'] === $ik ? ' selected' : '' ?>><?= e($ilabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label class="upd-faq-on"><input type="checkbox" name="faq_on[<?= $fq ?>]"<?= $r['on'] ? ' checked' : '' ?>> Shown</label>
                    <span class="upd-faq-tools">
                      <button type="button" class="upd-move" title="Move up" aria-label="Move &quot;<?= e($r['q']) ?>&quot; up" onclick="updFaqOp(this,<?= $fid ?>,'move','up')">&uarr;</button>
                      <button type="button" class="upd-move" title="Move down" aria-label="Move &quot;<?= e($r['q']) ?>&quot; down" onclick="updFaqOp(this,<?= $fid ?>,'move','down')">&darr;</button>
                      <button type="button" class="upd-move is-danger" title="Delete" aria-label="Delete &quot;<?= e($r['q']) ?>&quot;" onclick="updFaqOp(this,<?= $fid ?>,'delete')">&times;</button>
                    </span>
                  </div>
                  <label class="upd-sr" for="faqq-<?= $fid ?>">Question</label>
                  <input id="faqq-<?= $fid ?>" type="text" name="faq_q[<?= $fq ?>]" class="input upd-faq-q" value="<?= e($r['q']) ?>" maxlength="255" placeholder="The question a rider would ask">
                  <label class="upd-sr" for="faqa-<?= $fid ?>">Answer</label>
                  <textarea id="faqa-<?= $fid ?>" name="faq_a[<?= $fq ?>]" class="input upd-faq-a" rows="2" placeholder="The answer, in one or two sentences."><?= e($r['a']) ?></textarea>
                </article>
                <?php $fq++; ?>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <p class="upd-faq-none">No questions in this category yet.</p>
              <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
      <p class="upd-hint upd-img-note">Pictures: JPG, PNG or WebP, up to 4 MB, at least 320×200 and no more than 6000×4000. A replacement that is refused leaves the current picture in place.</p>
    </div>
  </div>

  <div class="upd-bar">
    <p class="upd-bar-note">Changes save by themselves. Use Save Changes to write straight away.</p>
    <div class="upd-bar-actions">
      <?php if ($hasRestore): ?>
        <button type="submit" form="upd-restore" class="bk-btn bk-btn-line">Restore previous version</button>
      <?php endif; ?>
      <button type="submit" class="btn-gold rounded-full text-sm px-6 py-2.5">Save Changes</button>
    </div>
  </div>
</form>

<?php if ($hasRestore): ?>
  <form method="post" id="upd-restore" class="upd-restore"
        onsubmit="return confirm('Restore the version of this page from before your last save? Anything you have typed since will be replaced.')">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="restore">
    <input type="hidden" name="page" value="<?= e($slug) ?>">
  </form>
<?php endif; ?>

<?php if ($slug === 'faq'): ?>
  <?php /* Row add/delete/move and category edits cannot ride the autosave form:
           they add or remove rows, and HTML forbids nesting one form in
           another. These are their own POSTs through the same CSRF gate. */ ?>
  <form method="post" id="upd-faq-op" class="upd-hidden-form">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="">
    <input type="hidden" name="faq_id" value="">
    <input type="hidden" name="group_id" value="">
    <input type="hidden" name="dir" value="">
    <input type="hidden" name="page" value="faq">
  </form>

  <div class="upd-faq-add">
    <h2 class="font-display text-xl">Add a question</h2>
    <form method="post" class="upd-faq-add-form">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="faq_add">
      <input type="hidden" name="page" value="faq">
      <div class="upd-faq-add-grid">
        <label class="upd-sr" for="faqnew-q">New question</label>
        <input id="faqnew-q" type="text" name="question" class="input" maxlength="255" required placeholder="The question a rider would ask">
        <label class="upd-sr" for="faqnew-g">Category</label>
        <select id="faqnew-g" name="group_id" class="input" required>
          <?php foreach ($faqGroups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= e($g['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="upd-sr" for="faqnew-a">Answer</label>
        <textarea id="faqnew-a" name="answer" class="input" rows="2" required placeholder="The answer, in one or two sentences."></textarea>
        <button type="submit" class="btn-gold rounded-full text-sm px-5 py-2.5">Add question</button>
      </div>
    </form>
  </div>

  <div class="upd-faq-cats">
    <h2 class="font-display text-xl">Categories</h2>
    <p class="upd-hint">Renaming a category changes its heading on the public page. A category can only be removed once its questions have moved or been deleted.</p>
    <form method="post" class="upd-faq-cat-add">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="faq_group_add">
      <input type="hidden" name="page" value="faq">
      <label class="upd-sr" for="faqcat-new">New category name</label>
      <input id="faqcat-new" type="text" name="label" class="input" maxlength="80" required placeholder="Luggage">
      <button type="submit" class="bk-btn bk-btn-line">Add category</button>
    </form>
    <ul class="upd-faq-cat-list">
      <?php foreach ($faqGroups as $g): ?>
        <li class="upd-faq-cat">
          <form method="post" class="upd-faq-cat-rename">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="faq_group_rename">
            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
            <input type="hidden" name="page" value="faq">
            <label class="upd-sr" for="faqcat-<?= (int)$g['id'] ?>">Name of <?= e($g['label']) ?></label>
            <input id="faqcat-<?= (int)$g['id'] ?>" type="text" name="label" class="input" value="<?= e($g['label']) ?>" maxlength="80">
            <button type="submit" class="bk-btn bk-btn-line">Rename</button>
          </form>
          <button type="button" class="upd-move is-danger" title="Delete category" aria-label="Delete category <?= e($g['label']) ?>"
                  onclick="updFaqOp(this,0,'faq_group_delete','',<?= (int)$g['id'] ?>)">&times;</button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<p class="upd-foot">
  Phone, email and address live in <a href="<?= e(url('admin/settings.php')) ?>">Settings</a> — they are the same values used across every page and in search results, so they are only edited in one place.
</p>

<script>
/*
  Row operations (add is a real form; move and delete are not, because HTML
  forbids nesting a form inside the editor form). One helper fills the shared
  op-form and submits it, so every row button posts the same shape.
*/
function updFaqOp(btn, id, op, dir, groupId) {
  if (op === 'delete' && !confirm('Delete this question? It disappears from the public page.')) return;
  if (op === 'faq_group_delete' && !confirm('Delete this category? It can only be deleted while it has no questions.')) return;
  var f = document.getElementById('upd-faq-op');
  if (!f) return;
  f.op.value = op;
  f.faq_id.value = id || '';
  f.group_id.value = groupId || '';
  f.dir.value = dir || '';
  f.submit();
}
</script>

<script>
/*
  Autosave.
  One form, one endpoint, one code path: the same POST the Save button makes,
  with the XHR header added so the server answers with JSON instead of
  redirecting. That is the whole reason this cannot drift out of step with the
  manual save - it is not a second save implementation.

  Three states are kept apart deliberately:
    pending  - typed into, debounce is armed
    inflight - a request is on the wire
    saving   - the server is deciding
  Only the last two mean "there is work that could be lost", and only those
  trigger the leave-page warning, so a clean page never nags.
*/
(function () {
  var BASE = <?= json_encode(rtrim(SITE_URL, '/')) ?>;
  var form = document.querySelector('.upd-form');
  if (!form) return;

  var status = document.querySelector('.upd-status');
  var dot = status ? status.querySelector('.upd-dot') : null;
  var say = status ? status.querySelector('.upd-say') : null;
  var errBox = document.getElementById('updErr');
  var ajaxFlag = document.getElementById('updAjax');
  var timer = null, inflight = false, pending = false, settled = null;

  function setState(state, text) {
    if (status) status.dataset.state = state;
    if (say) say.textContent = text;
  }
  function showErrors(list) {
    if (!errBox) return;
    if (!list || !list.length) { errBox.hidden = true; errBox.textContent = ''; return; }
    errBox.hidden = false;
    var h = document.createElement('strong');
    h.textContent = 'Not saved: ';
    errBox.textContent = '';
    errBox.appendChild(h);
    errBox.appendChild(document.createTextNode(list.join(' · ')));
  }
  function unsetWork() { pending = false; inflight = false; if (timer) { clearTimeout(timer); timer = null; } }
  function hasWork() { return inflight || pending; }

/* ---- character counters ----
     The count is shown for every bounded field because it is cheap and it stops
     you hitting a silent cap. The warning is different: it fires against the
     field's `soft` target, the point past which something real is lost - a
     description that gets cut off in a search result. There is no such point on
     a 2000-character paragraph, so that field gets a number and no alarm. */
  function paintCount(el) {
    var box = document.querySelector('[data-count-for="' + el.id + '"]');
    if (!box) return;
    var max = parseInt(box.dataset.max, 10) || 0;
    var soft = parseInt(box.dataset.soft, 10) || 0;
    var n = el.value.length;
    box.textContent = max ? (n + '/' + max) : '';
    box.classList.toggle('is-near', soft > 0 && n > soft * 0.9);
  }
  form.querySelectorAll('.upd-input').forEach(function (el) {
    if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') paintCount(el);
    el.addEventListener('input', function () { paintCount(el); });
  });

  /* ---- image thumbnails, repointed from the save response ---- */
  function applyImages(images) {
    if (!images) return;
    Object.keys(images).forEach(function (field) {
      var info = images[field];
      var thumb = form.querySelector('[data-thumb="' + field + '"]');
      var state = form.querySelector('[data-img="' + field + '"] .upd-img-state');
      if (!thumb) return;
      if (info.path) {
        var img = thumb.querySelector('img');
        if (!img) {
          thumb.textContent = '';
          img = document.createElement('img');
          thumb.appendChild(img);
        }
        img.setAttribute('src', info.path.indexOf('storage/') === 0 ? BASE + info.path : info.path);
        img.setAttribute('alt', '');
        thumb.classList.remove('is-default');
      } else {
        thumb.textContent = '';
        var s = document.createElement('span');
        s.className = 'upd-none';
        s.textContent = 'site picture';
        thumb.appendChild(s);
        thumb.classList.add('is-default');
      }
      if (state) {
        state.dataset.state = info.path ? 'live' : 'default';
        state.textContent = info.path ? 'Uploaded picture is live' : "Using the site's picture";
      }
    });
  }

  /* ---- the save itself ---- */
  function flush() {
    if (timer) { clearTimeout(timer); timer = null; }
    pending = false;
    if (inflight) return;              // a queued change will re-run on completion
    inflight = true;
    setState('saving', 'Saving…');
    showErrors(null);

    var fd = new FormData(form);
    ajaxFlag.value = '1';

    fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'upd-autosave' }
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function (j) {
      if (j && j.ok) {
        applyImages(j.images);
        /*
          Clear the file inputs once the bytes are stored. Leaving them
          populated would re-upload the same picture on the very next
          keystroke anywhere else on the page.
        */
        form.querySelectorAll('input[type=file]').forEach(function (f) { f.value = ''; });
        var n = j.saved || 0;
        setState('saved', n ? 'Saved' : 'Saved — no changes');
      } else {
        setState('bad', 'Not saved');
        showErrors(j && j.errors);
        inflight = false;
        return;
      }
      inflight = false;
      settled = window.setTimeout(function () { setState('saved', 'Saved'); }, 2600);
    }).catch(function () {
      inflight = false;
      setState('bad', 'Not saved');
      showErrors(['The page could not be reached. Your text is still here — use Save Changes to try again.']);
    }).then(function () {
      if (pending) schedule();
    });
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    setState('dirty', 'Unsaved');
    pending = true;
    // Long enough that a sentence is one save, short enough that nobody
    // finishes a thought and wonders whether it stuck.
    timer = window.setTimeout(flush, 850);
  }

  form.addEventListener('input', function (e) {
    if (e.target.matches('.upd-input')) schedule();
  });
  form.addEventListener('change', function (e) {
    if (e.target.matches('.upd-input, .upd-file, .upd-remove input')) schedule();
  });

  /* Manual save: stop waiting for the debounce and let the form submit. */
  form.addEventListener('submit', function () {
    if (timer) clearTimeout(timer);
    setState('saving', 'Saving…');
  });

  /* Only nag when there is genuinely unsaved work. */
  window.addEventListener('beforeunload', function (e) {
    if (!hasWork()) return;
    e.preventDefault();
    e.returnValue = '';
    return '';
  });

  document.querySelectorAll('.upd-tab, .upd-rail-link').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!hasWork()) return;
      if (!window.confirm('A change is still saving. Leave anyway?')) e.preventDefault();
    });
  });

  /* Highlight the section you are reading. IntersectionObserver rather than a
     scroll handler: it costs nothing until the page actually scrolls. */
  var railLinks = {};
  document.querySelectorAll('.upd-rail-link').forEach(function (a) { railLinks[a.dataset.rail] = a; });
  var secs = document.querySelectorAll('.upd-sec');
  if (secs.length && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var a = railLinks[en.target.dataset.sec];
        if (a && en.isIntersecting) {
          Object.keys(railLinks).forEach(function (k) { railLinks[k].classList.remove('is-on'); });
          a.classList.add('is-on');
        }
      });
    }, { rootMargin: '-88px 0px -65% 0px', threshold: 0 });
    secs.forEach(function (s) { io.observe(s); });
  }
  window.addEventListener('pagehide', function () { if (settled) clearTimeout(settled); });
})();
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'User Page Design | Admin';
$navActive = 'user-page-design.php';
require APP_ROOT . '/views/layouts/admin.php';