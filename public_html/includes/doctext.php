<?php
/*
  READING THE TEXT OUT OF AN UPLOADED FILE — Phase 3b.

  No library, no composer, no account, no cost. Everything here is PHP's own
  ZipArchive and zlib, which this server already has.

  What it can and cannot do is stated on screen rather than hidden, because
  the difference matters to you:

    .txt .csv          read straight off. Exact.
    .docx .xlsx .pptx  are ZIP files with XML inside. ZipArchive opens them
                       and the words come out exactly. No library needed.
    .pdf born-digital  streams are inflated and the text operators read. Good,
                       not perfect — a PDF built with a subset font that
                       carries its own private encoding comes out as nonsense,
                       which is why the result is checked before it is kept.
    .pdf scanned       HAS NO TEXT. It is a photograph of a page. Nothing short
                       of OCR can read it, and OCR is not free.
    .jpg .png .webp    same — a picture.
    .zip               not opened. A zip of scans is still scans, and walking
                       into nested archives is how an upload turns into a way
                       to exhaust the server.

  A file that yields nothing is recorded as yielding nothing, with the reason.
  A silent miss would have you believing the search is broken when the truth
  is that the document never contained a single searchable character.
*/

/* Stop reading a file after this much. A 60 MB scan has nothing to give and
   pulling it into memory to prove that helps nobody. */
const DOCTEXT_MAX_BYTES = 20971520;      /* 20 MB */
/* Keep at most this much text per document. Two hundred thousand characters
   is roughly eighty pages — far past the point where more text makes a
   document easier to find. */
const DOCTEXT_MAX_TEXT  = 200000;
/* A PDF with fewer than this many readable characters is treated as a scan
   rather than as a success with almost nothing in it. */
const DOCTEXT_PDF_MIN   = 12;

/* Every status this can return, and what the screen should say about it. */
const DOCTEXT_STATUS = [
    'ok'          => 'Text read',
    'scan'        => 'No text — looks like a scan',
    'picture'     => 'No text — this is a picture',
    'unreadable'  => 'Text could not be read reliably',
    'unsupported' => 'This type holds no text to read',
    'toobig'      => 'Too large to read',
    'empty'       => 'The file held no text',
    'error'       => 'Could not be read',
];

function doctext_ext(string $filename): string {
    $e = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return preg_replace('/[^a-z0-9]/', '', $e);
}

/* Collapse whatever came out into something worth storing and worth reading
   back as a snippet: one space between words, no control characters, valid
   UTF-8 so MySQL accepts it and so mb_* does not trip over it later. */
function doctext_clean(string $s): string {
    $s = str_replace(["\r\n", "\r", "\t", "\xC2\xA0"], ' ', $s);
    if (!mb_check_encoding($s, 'UTF-8')) {
        $c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = $c === false ? (string)preg_replace('/[^\x09\x0A\x20-\x7E]/', ' ', $s) : $c;
    }
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/* Is this actually words, or is it the wreckage of a font we cannot decode?
 *
 * A PDF whose fonts carry a private encoding yields byte soup that looks like
 * text to a regular expression. Storing it would fill the index with garbage
 * that matches nothing and bloats every row, so the ratio of plausible
 * characters is measured and poor extractions are thrown away. */
function doctext_looks_like_words(string $s): bool {
    $len = strlen($s);
    if ($len < DOCTEXT_PDF_MIN) return false;
    $good = strlen((string)preg_replace('/[^A-Za-z0-9 .,\/()\-:;@&%+#\'"]/', '', $s));
    return ($good / $len) >= 0.75;
}

/* ------------------------------------------------------------------ office

   .docx, .xlsx and .pptx are ZIP containers. The words live in XML parts
   whose names differ per format, so the parts are chosen by pattern. Shared
   strings matter for .xlsx in particular: a spreadsheet keeps most of its
   text in sharedStrings.xml, not in the sheets. */
function doctext_from_office(string $path): array {
    if (!class_exists('ZipArchive')) {
        return ['text' => '', 'status' => 'error', 'note' => 'ZipArchive is not available on this server.'];
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        return ['text' => '', 'status' => 'error', 'note' => 'The file could not be opened as a document.'];
    }
    $want = '~^(word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml'
          . '|xl/(sharedStrings\.xml|worksheets/sheet\d+\.xml)'
          . '|ppt/(slides/slide\d+\.xml|notesSlides/notesSlide\d+\.xml))$~';
    $parts = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = (string)$z->getNameIndex($i);
        if (preg_match($want, $n)) $parts[] = $n;
    }
    sort($parts);
    $out = '';
    foreach ($parts as $n) {
        $xml = $z->getFromName($n);
        if ($xml === false || $xml === '') continue;
        /* A break must not glue the last word of one run to the first of the
           next, or "Gulf Textiles" becomes "GulfTextiles". The closing tag of
           every text-bearing element counts, not just the paragraph: a
           spreadsheet keeps its words in sharedStrings as <si><t>…</t></si>,
           one cell per pair, with no paragraph anywhere in the file. */
        $xml = (string)preg_replace('~</(w:p|w:tr|w:t|a:p|a:t|si|t|v|row|c)>~', ' $0 ', $xml);
        $xml = (string)preg_replace('~<[^>]*>~', '', $xml);
        $out .= ' ' . html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (strlen($out) > DOCTEXT_MAX_TEXT * 2) break;
    }
    $z->close();
    $out = doctext_clean($out);
    if ($out === '') return ['text' => '', 'status' => 'empty', 'note' => 'The document contained no text.'];
    return ['text' => $out, 'status' => 'ok', 'note' => ''];
}

/* --------------------------------------------------------------------- pdf

   Inflate every stream, then read the text-showing operators out of the page
   content: Tj for a single string, TJ for an array of strings with kerning
   numbers between them. Both forms appear in ordinary PDFs and reading only
   one of them loses most of the words in a document produced by Word. */
function doctext_pdf_unescape(string $s): string {
    $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
            '(' => '(', ')' => ')', '\\' => '\\'];
    $out = ''; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        if ($s[$i] !== '\\') { $out .= $s[$i]; continue; }
        $c = $s[++$i] ?? '';
        if ($c === '') break;
        if (isset($map[$c])) { $out .= $map[$c]; continue; }
        if ($c >= '0' && $c <= '7') {          /* \ddd octal */
            $oct = $c;
            for ($k = 0; $k < 2; $k++) {
                $d = $s[$i + 1] ?? '';
                if ($d >= '0' && $d <= '7') { $oct .= $d; $i++; } else break;
            }
            $out .= chr(octdec($oct) & 0xFF);
            continue;
        }
        if ($c === "\n") continue;              /* a line continuation */
        $out .= $c;
    }
    return $out;
}

function doctext_from_pdf(string $raw): array {
    $text = '';

    /* Each stream is tried as zlib, then as raw deflate, then as-is. A PDF
       may legitimately store its content uncompressed. */
    if (preg_match_all('~stream\r?\n(.*?)endstream~s', $raw, $m)) {
        foreach ($m[1] as $s) {
            $s = rtrim($s, "\r\n");
            $d = @gzuncompress($s);
            if ($d === false) $d = @gzinflate($s);
            if ($d === false) $d = $s;
            if (!is_string($d) || $d === '') continue;
            /* Only page content is wanted. An image stream that happens to
               inflate is not text and must not be scanned for parentheses. */
            if (!preg_match('~(Tj|TJ|Td|TD|Tf|BT)~', $d)) continue;

            /* THE OPERATORS ARE READ IN ORDER, AND TJ IS NOT SPLIT.
             *
             * A PDF kerns by breaking a word across an array:
             *   [(Bill of Lad) -20 (ing MAEU123456)] TJ
             * Sweeping up every bracketed string and joining with spaces
             * turns that into "Bill of Lad ing", and "Lading" then matches
             * nothing. The pieces inside one TJ belong to one run of text and
             * are joined with nothing between them.
             *
             * The numbers between them are kerns, in thousandths of an em.
             * A large negative kern is how a PDF writes a space without
             * writing one, so past a threshold a space is put back. */
            $re = '~\[((?:\\\\.|[^\\\\\[\]])*)\]\s*TJ'      /* 1: a TJ array   */
                . '|\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\')'  /* 2: a Tj string  */
                . '|<([0-9A-Fa-f\s]{4,})>\s*(?:Tj|TJ)~s';    /* 3: a hex string */
            if (preg_match_all($re, $d, $ops, PREG_SET_ORDER)) {
                foreach ($ops as $op) {
                    if (($op[1] ?? '') !== '') {
                        $run = '';
                        if (preg_match_all('~\(((?:\\\\.|[^\\\\()])*)\)|(-?[\d.]+)~s', $op[1], $bits, PREG_SET_ORDER)) {
                            foreach ($bits as $b) {
                                if (isset($b[2]) && $b[2] !== '') {
                                    if ((float)$b[2] <= -120) $run .= ' ';
                                } else {
                                    $run .= doctext_pdf_unescape($b[1]);
                                }
                            }
                        }
                        $text .= $run . ' ';
                    } elseif (($op[2] ?? '') !== '') {
                        $text .= doctext_pdf_unescape($op[2]) . ' ';
                    } elseif (($op[3] ?? '') !== '') {
                        $hex = (string)preg_replace('/\s+/', '', $op[3]);
                        if (strlen($hex) % 2 === 0) $text .= (string)@hex2bin($hex) . ' ';
                    }
                }
            }
            if (strlen($text) > DOCTEXT_MAX_TEXT * 2) break;
        }
    }

    $text = doctext_clean($text);

    if ($text === '') {
        return ['text' => '', 'status' => 'scan',
                'note' => 'This PDF holds no text at all, so it is almost certainly a scan or a photograph. Only OCR could read it.'];
    }
    if (!doctext_looks_like_words($text)) {
        return ['text' => '', 'status' => 'unreadable',
                'note' => 'Text was found but it decoded as nonsense, which happens when a PDF embeds its fonts with a private encoding. It has been discarded rather than filling the index with gibberish.'];
    }
    return ['text' => $text, 'status' => 'ok', 'note' => ''];
}

/* ------------------------------------------------------------------- entry

   Given the bytes of a file and its name, return what can be read out of it.
   The caller never has to know which branch ran. */
function doctext_read(string $bytes, string $filename): array
{
    $ext  = doctext_ext($filename);
    $size = strlen($bytes);

    if ($size === 0)                  return ['text' => '', 'status' => 'empty',   'note' => 'The file is empty.', 'ext' => $ext];
    if ($size > DOCTEXT_MAX_BYTES)    return ['text' => '', 'status' => 'toobig',  'note' => 'The file is larger than ' . round(DOCTEXT_MAX_BYTES / 1048576) . ' MB, so it was not read.', 'ext' => $ext];

    try {
        if (in_array($ext, ['txt', 'csv'], true)) {
            $t = doctext_clean($bytes);
            $r = $t === ''
               ? ['text' => '', 'status' => 'empty', 'note' => 'The file held no text.']
               : ['text' => $t, 'status' => 'ok', 'note' => ''];
        } elseif (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            /* ZipArchive wants a path, so the bytes go to a temporary file
               that is removed whatever happens below. */
            $tmp = tempnam(sys_get_temp_dir(), 'zasdt');
            if ($tmp === false) {
                $r = ['text' => '', 'status' => 'error', 'note' => 'No temporary file could be created.'];
            } else {
                file_put_contents($tmp, $bytes);
                try { $r = doctext_from_office($tmp); }
                finally { @unlink($tmp); }
            }
        } elseif ($ext === 'pdf') {
            $r = doctext_from_pdf($bytes);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'], true)) {
            $r = ['text' => '', 'status' => 'picture',
                  'note' => 'An image holds no text that can be searched. Only OCR could read it.'];
        } elseif (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
            /* The pre-2007 binary formats. Not ZIPs, not XML — reading them
               properly needs a real parser, and guessing at the bytes returns
               fragments that look like a successful read and are not. */
            $r = ['text' => '', 'status' => 'unsupported',
                  'note' => 'The old ' . strtoupper($ext) . ' format cannot be read without a library. Saving it as ' . strtoupper($ext) . 'x makes it searchable.'];
        } else {
            $r = ['text' => '', 'status' => 'unsupported',
                  'note' => 'Nothing in a .' . ($ext ?: 'file') . ' can be searched as text.'];
        }
    } catch (Throwable $e) {
        $r = ['text' => '', 'status' => 'error', 'note' => 'The file could not be read.'];
    }

    if (strlen($r['text']) > DOCTEXT_MAX_TEXT) {
        $r['text'] = mb_substr($r['text'], 0, DOCTEXT_MAX_TEXT, 'UTF-8');
        $r['note'] = 'Only the first ' . number_format(DOCTEXT_MAX_TEXT) . ' characters were kept.';
    }
    $r['ext']   = $ext;
    $r['chars'] = strlen($r['text']);
    return $r;
}

/* Can this type ever yield text? Used to decide whether to fetch the bytes
   from R2 at all — downloading a 40 MB scan to learn it is a scan is a waste
   of a round trip that is paid for on every rebuild. */
function doctext_worth_reading(string $filename): bool {
    return in_array(doctext_ext($filename), ['txt','csv','docx','xlsx','pptx','pdf'], true);
}
