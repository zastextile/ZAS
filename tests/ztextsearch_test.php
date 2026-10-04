<?php
/* TEXT SEARCH — Phase 3b.
 *
 * Phase 3a answers a question when it can be turned into filters. When it
 * could not, it printed "not understood" and stopped. This fills that dead
 * end by looking for the same words in the text itself.
 *
 * Four things are worth testing here and the rest is detail:
 *
 *   THE EXTRACTOR TELLS THE TRUTH. A scan must be reported as holding no
 *   text, not quietly indexed as empty. The difference is whether you
 *   believe the search is broken or know the file never had anything in it.
 *
 *   SHORT WORDS STILL WORK. MySQL's full-text index ignores anything under
 *   three letters and that setting cannot be changed on shared hosting, so
 *   TT, LC, DP and BL — four terms used daily here — would silently never
 *   match. They must go down the other half of the query.
 *
 *   IT CANNOT LEAK. A search box that reaches a table directly is a way to
 *   read records you cannot open. Every result must be scoped.
 *
 *   IT CANNOT INJECT. A document's own contents end up on the results page.
 *   Nothing from a file may arrive there as markup.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $o = strpos($src, '{', $a);
    if ($o === false) return '';
    $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}
/* Comments explain, they do not implement. Source assertions read code. */
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $s);
}

/* ===================================================== building test files
   Real files, built here, rather than fixtures checked in — a fixture drifts
   away from what the code expects and nobody notices. */
$tmp = sys_get_temp_dir() . '/ztxt' . getmypid();
@mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$zip = function (string $path, array $entries) {
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $n => $c) $z->addFromString($n, $c);
    $z->close();
};

$zip($tmp . '/a.docx', ['word/document.xml' =>
    '<w:document><w:body><w:p><w:r><w:t>Chamber of Commerce certificate</w:t></w:r></w:p>'
  . '<w:p><w:r><w:t>Gulf Textiles LLC</w:t></w:r></w:p></w:body></w:document>']);

/* Excel keeps its words in sharedStrings, not in the sheets. A break list
   that only knows about paragraphs runs the cells together. */
$zip($tmp . '/a.xlsx', [
    'xl/sharedStrings.xml' => '<sst><si><t>Hotel Flat Sheet 300TC</t></si><si><t>Antwerp</t></si><si><t>MAEU123456</t></si></sst>',
    'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c></row></sheetData></worksheet>',
]);

/* A PDF the way Word writes one: Flate-compressed, kerned across a TJ array,
   with a hex string as well. */
$mkpdf = function (string $path, string $content, bool $asImage = false) {
    $comp = gzcompress($content);
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>',
        '<< /Length ' . strlen($comp) . ' /Filter /FlateDecode' . ($asImage ? ' /Subtype /Image' : '') . " >>\nstream\n" . $comp . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $out = "%PDF-1.4\n";
    foreach ($objs as $i => $o) $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
    $out .= "trailer\n<< /Root 1 0 R >>\n%%EOF";
    file_put_contents($path, $out);
};
$mkpdf($tmp . '/a.pdf',
    'BT /F1 12 Tf 72 720 Td [(Bill of Lad) -20 (ing MAEU123456)] TJ 0 -20 Td '
  . '(Payment terms: TT 30 days) Tj 0 -20 Td <436861726765732050616964> Tj ET');
/* A scan: one image stream, no text operator anywhere. */
$mkpdf($tmp . '/scan.pdf', str_repeat("\0", 4000), true);

file_put_contents($tmp . '/a.txt', 'Chamber invoice for Gulf Textiles, Antwerp, TT 30 days');
file_put_contents($tmp . '/a.jpg', str_repeat("\0", 900));

/* ========================================================== the extractor */
head('1. What can be read, and what honestly cannot');

if (!function_exists('db')) { function db() { throw new RuntimeException('the extractor must not touch the database'); } }
require_once $B . 'includes/doctext.php';
/* Loaded for its constants — TXT_SOURCES, TXT_STEPS, TXT_LIMIT. Neither file
   touches the database when it is merely included, which the throwing stub
   above enforces. */
require_once $B . 'includes/textindex.php';

$read = fn(string $f) => doctext_read((string)@file_get_contents($tmp . '/' . $f), $f);

$r = $read('a.txt');
t('a text file is read exactly', $r['status'] === 'ok' && str_contains($r['text'], 'Gulf Textiles'), $r);

$r = $read('a.docx');
t('a .docx is read with no library at all',
  $r['status'] === 'ok' && str_contains($r['text'], 'Chamber of Commerce certificate'), $r);
t('and a paragraph break does not glue two words together',
  str_contains($r['text'], 'certificate Gulf'), $r['text']);

$r = $read('a.xlsx');
t('a .xlsx is read from sharedStrings, where Excel actually keeps its words',
  $r['status'] === 'ok' && str_contains($r['text'], 'Hotel Flat Sheet 300TC'), $r);
t('and separate cells do not run into one another',
  str_contains($r['text'], '300TC Antwerp'), $r['text']);

$r = $read('a.pdf');
t('a born-digital PDF is read', $r['status'] === 'ok', $r);
/* The bug this test exists for: a PDF kerns by splitting a word across a TJ
   array. Joining the pieces with spaces makes "Lading" unfindable. */
t('a word split across a TJ array for kerning is put back together',
  str_contains($r['text'], 'Bill of Lading'), $r['text']);
t('a second text operator is still separated from the first',
  str_contains($r['text'], 'Lading MAEU123456 Payment terms'), $r['text']);
t('a hex string is decoded too', str_contains($r['text'], 'Charges Paid'), $r['text']);

$r = $read('scan.pdf');
t('a scanned PDF is reported as a scan, not as an empty success',
  $r['status'] === 'scan', $r);
t('and it says why, in words rather than a code',
  stripos($r['note'], 'scan') !== false && stripos($r['note'], 'OCR') !== false, $r['note']);
t('nothing is stored for it', $r['text'] === '');

$r = $read('a.jpg');
t('an image is reported as a picture', $r['status'] === 'picture', $r);

$r = doctext_read('anything', 'old.doc');
t('the old .doc format is refused rather than guessed at', $r['status'] === 'unsupported', $r);
t('and it says what to do about it', stripos($r['note'], 'docx') !== false, $r['note']);

$r = doctext_read('PK', 'bundle.zip');
t('a zip is not opened — a zip of scans is still scans', $r['status'] === 'unsupported', $r);

$r = doctext_read('', 'empty.txt');
t('an empty file is empty, not an error', $r['status'] === 'empty');

$r = doctext_read(str_repeat('x', DOCTEXT_MAX_BYTES + 1), 'huge.pdf');
t('a file past the size ceiling is refused before it is parsed', $r['status'] === 'toobig', $r);

head('2. Nonsense is thrown away rather than indexed');
/* A PDF embedding a subset font with a private encoding yields bytes that
   look like text to a regular expression. Storing them would fill the index
   with rows that match nothing. */
t('plain words are accepted',  doctext_looks_like_words('Bill of Lading MAEU123456'));
t('byte soup is rejected',    !doctext_looks_like_words("\x81\x9d\xa3\x88\x92\xb1\xc4\xd7\xe2\xf5\x8a\x9b\xac\xbd"));
t('the test needs enough to judge', !doctext_looks_like_words('ab'));

head('3. The cleaner');
t('control characters are removed', !str_contains(doctext_clean("a\x07b"), "\x07"));
t('runs of space collapse',          doctext_clean("a \n\t  b") === 'a b');
t('invalid UTF-8 does not survive to reach MySQL',
  mb_check_encoding(doctext_clean("Gulf \xC3\x28 Textiles"), 'UTF-8'));

head('4. Only types that can hold text are fetched');
/* Downloading a 40 MB scan from R2 to learn it is a scan costs a round trip
   on every rebuild and teaches nothing. */
t('a pdf is worth fetching',    doctext_worth_reading('bl.pdf'));
t('a docx is worth fetching',   doctext_worth_reading('cert.DOCX'));
t('a jpg is not',              !doctext_worth_reading('scan.jpg'));
t('a zip is not',              !doctext_worth_reading('all.zip'));

/* ============================================================== the search */
head('5. Short words — the trap this design exists for');

$src = file_get_contents($B . 'includes/textindex.php');
t('includes/textindex.php was found', $src !== false && $src !== '');

/* txt_terms() is pure, so it is run rather than read. */
$probe = <<<'PHP'
<?php
function db() { throw new RuntimeException('txt_terms must not touch the database'); }
require $argv[1] . 'includes/textindex.php';
$o = [];
foreach (['chamber certificate', 'TT 30 days', 'BL MAEU123456', 'LC at sight',
          'gulf', 'a', '%', '+chamber* "x"', 'ZAS/5191'] as $q) {
    $o[$q] = txt_terms($q);
}
$o['_snip'] = txt_snippet('The chamber certificate was issued for Gulf Textiles in Antwerp', ['chamber', 'gulf']);
$o['_snip_tag'] = txt_snippet('note <script>alert(1)</script> chamber', ['chamber']);
echo json_encode($o);
PHP;
$pf = $tmp . '/probe.php';
file_put_contents($pf, $probe);
$g = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pf) . ' ' . escapeshellarg($B) . ' 2>&1'), true);

t('the term probe ran', is_array($g), $g);
if (is_array($g)) {
    t('ordinary words go to the full-text half',
      ($g['chamber certificate']['long'] ?? []) === ['chamber', 'certificate']
      && ($g['chamber certificate']['short'] ?? []) === []);

    /* The whole point. MySQL cannot index these; they must be routed to the
       direct-match half or they silently never match. */
    t('TT is recognised as too short for the full-text index',
      in_array('tt', $g['TT 30 days']['short'] ?? [], true), $g['TT 30 days']);
    t('BL likewise',  in_array('bl', $g['BL MAEU123456']['short'] ?? [], true));
    t('LC likewise',  in_array('lc', $g['LC at sight']['short'] ?? [], true));
    t('and the long word in the same question still goes to full-text',
      in_array('maeu123456', $g['BL MAEU123456']['long'] ?? [], true));

    /* Boolean-mode operators in a pasted reference are a syntax error, not a
       search. They must be gone before the expression is built. */
    foreach (['+', '*', '"'] as $ch) {
        $all = implode(' ', $g['+chamber* "x"']['all'] ?? []);
        t("a stray $ch never reaches the boolean expression", !str_contains($all, $ch), $all);
    }
    t('a lone % does not become a term', ($g['%']['all'] ?? []) === []);
    t('a reference keeps its shape',     in_array('zas/5191', $g['ZAS/5191']['all'] ?? [], true), $g['ZAS/5191']);

    head('6. The snippet');
    $snip = $g['_snip'] ?? [];
    $hits = array_values(array_filter($snip, fn($p) => $p[1] === true));
    t('the matching words are marked',   count($hits) >= 2, $snip);
    t('both terms are marked, not only the first',
      in_array('chamber', array_map(fn($p) => strtolower($p[0]), $hits), true)
      && in_array('gulf', array_map(fn($p) => strtolower($p[0]), $hits), true), $hits);
    t('the surrounding words come back too',
      count(array_filter($snip, fn($p) => $p[1] === false)) > 0);

    /* A document's own contents reach the results page. If the snippet were
       built as HTML in PHP, a file could put a tag on screen. */
    $raw = implode('', array_map(fn($p) => $p[0], $g['_snip_tag'] ?? []));
    t('a snippet is returned as pieces, never as markup',
      str_contains($raw, '<script>'), $raw);
    t('and the pieces are plain strings for the page to escape',
      is_array($g['_snip_tag'] ?? null) && is_string(($g['_snip_tag'][0] ?? [null])[0]));
}

head('7. The query cannot leak and cannot be injected');

$search = nocomments(lift($src, 'function txt_search('));
t('the full-text expression is bound, not interpolated',
  str_contains($search, 'AGAINST (? IN BOOLEAN MODE)') && !preg_match('~AGAINST \([^?]*\$~', $search));
t('short-word matching is bound too',
  substr_count($search, 'LIKE ?') >= 3 && !preg_match('~LIKE\s*[\'"]%\$~', $search));
t('a typed % or _ is escaped so it is a literal, not a wildcard',
  str_contains($search, "str_replace(['\\\\', '%', '_']") && str_contains($search, "ESCAPE"));
t('results are limited to the sources this user may see',
  str_contains($search, 'txt_allowed_sources()') && str_contains($search, 'source IN ('));
t('a user without rate visibility never sees a money row',
  str_contains($search, 'can_see_rates()') && str_contains($search, 'rate_sensitive = 0'));
t('a row tied to a shipment is scoped to the shipments this user can open',
  str_contains($search, 'assigned_shipment_ids()') && str_contains($search, 'shipment_id IN ('));
t('a user assigned nothing sees only rows tied to no shipment',
  str_contains($search, "\$where[] = 'shipment_id IS NULL'"));
t('the row cap is a constant, not pasted user input',
  str_contains($search, 'LIMIT " . TXT_LIMIT'));

$allowed = nocomments(lift($src, 'function txt_allowed_sources('));
t('costings are only searchable with costing permission',
  str_contains($allowed, "costing_perm('view')"));
t('proformas likewise',  str_contains($allowed, "costing_perm('proforma')"));
t('payment notes need the payments module',  str_contains($allowed, "exp_can('shippay')"));
t('cost notes need the costs module',        str_contains($allowed, "exp_can('shipcost')"));

head('8. Writing the index can never break a save');

$put = nocomments(lift($src, 'function txt_put('));
t('the write is an upsert, so re-indexing replaces rather than duplicates',
  str_contains($put, 'ON DUPLICATE KEY UPDATE'));
t('and it swallows its own errors',  str_contains($put, 'catch (Throwable'));
t('the body is capped before it is stored', str_contains($put, 'TXT_BODY_MAX'));

$exp = file_get_contents($B . 'includes/export.php');
$schema = nocomments(lift($exp, 'function exp_build_schema('));
t('the table is created',            str_contains($schema, 'CREATE TABLE IF NOT EXISTS exp_text_index'));
t('one row per record, enforced',    str_contains($schema, 'UNIQUE KEY uniq_src (source, source_id)'));
t('the full-text index exists',      str_contains($schema, 'FULLTEXT KEY ft_body'));
t('the shipment column is indexed, since every search filters on it',
  str_contains($schema, 'INDEX idx_ship (shipment_id)'));
/* Read out of the source rather than loaded: export.php is not included in
   this process, and including it to read one constant would drag in the
   whole module and a database connection. */
preg_match("~const EXP_SCHEMA_VERSION = '(\d+)'~", $exp, $sv);
t('the schema version was raised so the table is actually installed',
  (int)($sv[1] ?? 0) >= 6, $sv[1] ?? null);

head('9. The save hooks');

foreach (['shipment_payments.php'  => 'txt_index_shipment_notes',
          'shipment_costs.php'     => 'txt_index_shipment_notes',
          'shipment_logistics.php' => 'txt_index_shipment_notes',
          'shipment_documents.php' => 'txt_index_document',
          'shipment_save.php'      => 'txt_index_shipment',
          'proforma.php'           => 'txt_index_proforma'] as $f => $fn) {
    $s = nocomments((string)file_get_contents($B . $f));
    t("$f re-indexes after a save", str_contains($s, $fn), $f);
    /* After the response, so a slow extraction cannot make a save feel slow
       and an index failure cannot stop one. */
    t("$f does it after the response, not during the save",
      str_contains($s, 'register_shutdown_function'), $f);
}

head('10. The rebuild');

$set  = file_get_contents($B . 'exp_settings.php');
$setN = nocomments($set);
t('there is a Text Search tab',       str_contains($setN, "'search'"));
/* Intent, not the literal array — the same pin broke the numbering test the
   moment this tab was added. */
t('the tab is validated against a fixed list, so the URL cannot be forced elsewhere',
  preg_match('~in_array\(\$tab,\s*\[[^\]]*\'search\'[^\]]*\],\s*true\)~', $setN) === 1);
t('the step is checked against the real list, so a typed URL cannot pick anything else',
  str_contains($setN, 'in_array($step, $valid, true)'));
t('the rebuild runs in slices rather than one request that would time out',
  str_contains($setN, 'txt_rebuild_step(') && str_contains($setN, "'after'"));
t('it still works with scripting off',  str_contains($set, '<noscript>'));
t('the screen reports what share of documents gave up text',
  str_contains($setN, 'docs_ok') && str_contains($setN, 'docs_no_text'));

$steps = nocomments(lift($src, 'function txt_rebuild_step('));
t('each slice has a bounded size',  str_contains($steps, 'LIMIT $size'));
t('the slice size is from the constant table, never from the request',
  str_contains($steps, 'foreach (TXT_STEPS as [$k, , $n]) if ($k === $step) $size = $n;')
  && !str_contains($steps, '$_GET'));
t('documents are the smallest slice, since each may mean a fetch from storage',
  (function () { $d = 999; $s = 0; foreach (TXT_STEPS as [$k,, $n]) { if ($k === 'document') $d = $n; else $s = max($s, $n); } return $d < $s; })());

head('11. Reading a stored file server-side');

$st = nocomments(lift(file_get_contents($B . 'includes/storage.php'), 'function exp_document_bytes('));
t('exp_document_bytes() exists — textindex.php calls it', $st !== '');
t('a missing file returns null rather than throwing, so a rebuild continues',
  substr_count($st, 'return null') >= 4, substr_count($st, 'return null'));
t('a stored key cannot escape the upload folder',  str_contains($st, 'basename($key)'));
t('an oversized file is refused',                  str_contains($st, '$maxBytes'));

head('12. The dead end is gone');

$se  = file_get_contents($B . 'search.php');
$seN = nocomments($se);
t('search.php loads the text index',  str_contains($seN, "includes/textindex.php"));
t('text search runs only when the filters found nothing',
  str_contains($seN, '$rowsSoFar === 0'));
t('it never overrides rows the filters did find',
  !preg_match('~\$textHits\s*=\s*txt_search~', $seN) || str_contains($seN, 'if ($rowsSoFar === 0)'));
t('the variables exist on an empty page load too',
  preg_match('~\$textHits = \[\];\s*\n\$textWhy~', $seN) === 1);
t('a text result is logged as a result, not as nothing found',
  str_contains($seN, '$rowCount ?: count($textHits)'));
t('the snippet is escaped on the way out',
  str_contains($se, '<?= e($piece) ?></mark>') && str_contains($se, '<?= e($piece) ?><?php endif'));
/* "Spends nothing" means the fallback block itself makes no paid call — not
   that the words never appear again in the file, which was what the first
   version of this assertion accidentally tested and why it failed. The two
   paid routes still exist below it, behind their buttons. */
/* Bounded by code, not by comment text — $seN has had its comments
   stripped, so a marker inside one is not there to find. */
$fbStart = strpos($seN, '$rowsSoFar = $mode ===');
$fbEnd   = $fbStart === false ? false : strpos($seN, '$rowCount = $mode ===', $fbStart);
$fallback = $fbStart !== false && $fbEnd !== false ? substr($seN, $fbStart, $fbEnd - $fbStart) : '';
t('the text fallback block was located', $fallback !== '');
t('text search spends nothing — no embedding, no completion',
  $fallback !== '' && !str_contains($fallback, 'create_embedding') && !str_contains($fallback, 'gpt_answer'));
t('and it is not gated behind a paid button either',
  $fallback !== '' && !str_contains($fallback, '$wantSemantic') && !str_contains($fallback, '$wantExplain'));
/* The paid routes must still be there, and still behind a press. */
t('the two paid routes are untouched',
  str_contains($seN, 'if (($wantExplain || $wantSemantic) && !$budget[') );

/* The old copy said nothing was looked up. It would now be a lie. */
t('the old "nothing was looked up" wording is gone',
  !str_contains($seN, 'so nothing was looked up'));

head('13. The Documents tab says why a file is not searchable');

$dc = nocomments((string)file_get_contents($B . 'shipment_documents.php'));
t('the tab reads the extraction status',  str_contains($dc, 'txt_document_status('));
t('in one query for the whole page, not one per row',
  substr_count($dc, 'txt_document_status(') === 1);
t('a readable file is marked searchable', str_contains($dc, 'Searchable'));
t('an unreadable one shows the reason',   str_contains($dc, 'DOCTEXT_STATUS['));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
