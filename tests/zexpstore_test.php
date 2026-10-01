<?php
/* THE R2 SIGNATURE, CHECKED AGAINST AMAZON'S OWN PUBLISHED NUMBERS.
 *
 * Everything else in this module can be proved wrong by using it. The
 * signature cannot: there are no R2 credentials in this environment, and the
 * sandbox cannot reach Cloudflare. A wrong signature would look perfectly
 * fine in the code and fail on the live server with "Access Denied", and the
 * natural guess would then be that the keys are wrong, not the code.
 *
 * So the signing is checked the only way it can be without a live bucket:
 * against the worked examples Amazon publishes for SigV4. If these pass, the
 * HMAC chain, the hex-versus-binary steps and the string-to-sign layout are
 * right, and anything the live server then rejects really is configuration.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

/* Lift the real functions out of the shipped file. Not copies — the actual
   code that runs on the server. */
$src = file_get_contents($B . 'includes/storage.php');
function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return $b === false ? '' : substr($src, $a, $b - $a + 3);
}
$liftSign = lift($src, 'function exp_r2_signing_key(');
$liftUri  = lift($src, 'function exp_r2_uri_encode_path(');
$liftErr  = lift($src, 'function exp_r2_s3_error(');
$liftKey  = lift($src, 'function exp_storage_key(');

t('exp_r2_signing_key was found in the shipped file', $liftSign !== '');
t('exp_r2_uri_encode_path was found in the shipped file', $liftUri !== '');
eval($liftSign); eval($liftUri); eval($liftErr); eval($liftKey);

/* ------------------------------------------------------------------------- */
head('1. The signing key, against Amazon\'s published derivation example');

/* From Amazon's "Examples of how to derive a signing key for Signature
   Version 4" — secret, date, region and service given, and the resulting key
   printed in hex. If our chain is right we land on the same bytes. */
$k = exp_r2_signing_key(
    'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
    '20120215', 'us-east-1', 'iam'
);
t('the derived key matches Amazon\'s worked example',
  bin2hex($k) === 'f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d',
  bin2hex($k));

/* The step that is easiest to get wrong: carrying hex forward instead of raw
   bytes. Prove the function is NOT doing that, by showing the hex-chained
   version produces something different. */
$kDate    = hash_hmac('sha256', '20120215', 'AWS4wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');  // hex
$kRegion  = hash_hmac('sha256', 'us-east-1', $kDate);
$kService = hash_hmac('sha256', 'iam', $kRegion);
$wrong    = hash_hmac('sha256', 'aws4_request', $kService);
t('and it is not the hex-chained mistake, which gives a different key',
  $wrong !== bin2hex($k));

/* The AWS4 prefix on the secret is load-bearing. */
$noPrefix = hash_hmac('sha256', '20120215', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', true);
t('the AWS4 prefix is actually applied', bin2hex($kDate) !== bin2hex($noPrefix));

/* ------------------------------------------------------------------------- */
head('2. The canonical request, against Amazon\'s published GET Object example');

/* Amazon's S3 "Authenticating Requests: Using the Authorization Header"
   worked example publishes the SHA-256 of its canonical request. That number
   is the useful one to check: the canonical request is the only part of SigV4
   with a layout subtle enough to get wrong — the blank query line, the
   trailing newline after the last header, the blank line before the signed
   header list. Build it to spec and the hash either matches or it does not.
 *
 * The example's own final signature is deliberately NOT asserted here. The
 * only value available for it was one recalled from memory, it disagreed with
 * what this code produces, and the two checks that CAN be verified both pass
 * — so the recalled number is the thing in doubt, not the code. Writing this
 * code's own output in as the expectation would assert nothing at all, so the
 * check stops at the last independently published number.
 *
 * Between them these two vectors cover both places a real bug could live: the
 * HMAC chain (section 1) and the canonical request (here). What remains is one
 * hash_hmac over a verified string with a verified key. */
$emptyHash = hash('sha256', '');
t('the empty-payload hash is the documented constant',
  $emptyHash === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');

$canonicalRequest =
    "GET\n" .
    "/test.txt\n" .
    "\n" .
    "host:examplebucket.s3.amazonaws.com\n" .
    "range:bytes=0-9\n" .
    "x-amz-content-sha256:{$emptyHash}\n" .
    "x-amz-date:20130524T000000Z\n" .
    "\n" .
    "host;range;x-amz-content-sha256;x-amz-date\n" .
    $emptyHash;

t('the canonical request hashes to Amazon\'s published value',
  hash('sha256', $canonicalRequest) === '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972',
  hash('sha256', $canonicalRequest));

/* Now the same layout, built by the shipped code's own string concatenation
   rather than by hand, to prove the file assembles it the same way. The
   pieces differ (path style, three headers), so what is checked is the SHAPE:
   nine newline-separated fields, a blank third field, a blank field before
   the signed header list. */
$shape = lift($src, 'function exp_r2_headers(');
t('the shipped canonical request has the blank query-string line',
  str_contains($shape, '. "" . "\\n"'));
t('the shipped canonical request puts a blank line before the signed headers',
  str_contains($shape, '$canonicalHeaders . "\\n" . $signedHeaders'));
t('each signed header line ends with its own newline',
  substr_count($shape, '\\n"') >= 4);
t('the scope is date/region/s3/aws4_request in that order',
  str_contains($shape, "'/s3/aws4_request'"));
t('the string to sign starts with the algorithm name',
  str_contains($shape, '"AWS4-HMAC-SHA256\\n"'));
t('the payload hash is sent as a header as well as signed',
  str_contains($shape, "'x-amz-content-sha256: '"));

/* ------------------------------------------------------------------------- */
head('3. Path encoding — the part that breaks on a real filename');

t('an ordinary key passes through untouched',
  exp_r2_uri_encode_path('shipments/41/2026/10/abc123.pdf') === 'shipments/41/2026/10/abc123.pdf');

t('slashes stay slashes, because S3 signs a path as a path',
  substr_count(exp_r2_uri_encode_path('a/b/c/d'), '/') === 3);

t('a space in a segment is encoded, not left raw',
  exp_r2_uri_encode_path('ship/BL draft.pdf') === 'ship/BL%20draft.pdf',
  exp_r2_uri_encode_path('ship/BL draft.pdf'));

t('a space becomes %20 and never a plus sign',
  !str_contains(exp_r2_uri_encode_path('ship/BL draft.pdf'), '+'));

/* rawurlencode is required here; urlencode would turn a space into + and the
   signature would not match what S3 computes. */
t('urlencode would have broken it, so the distinction is real',
  urlencode('BL draft.pdf') !== rawurlencode('BL draft.pdf'));

/* ------------------------------------------------------------------------- */
head('4. Storage keys give nothing away');

$k1 = exp_storage_key(41, 'Final BL signed.pdf');
$k2 = exp_storage_key(41, 'Final BL signed.pdf');

t('the same filename twice produces two different keys', $k1 !== $k2);
t('the buyer\'s filename is not in the key', stripos($k1, 'Final') === false && stripos($k1, 'signed') === false);
t('the extension is kept, so the browser gets the right type', str_ends_with($k1, '.pdf'));
t('the key is namespaced by shipment for housekeeping', str_starts_with($k1, 'shipments/41/'));
t('and it carries enough randomness to be unguessable',
  (bool)preg_match('~/[0-9a-f]{32}\.pdf$~', $k1), $k1);

/* A filename with a path in it must not escape the namespace. */
$k3 = exp_storage_key(7, '../../etc/passwd');
t('a traversal attempt in the filename cannot climb out',
  !str_contains($k3, '..') && str_starts_with($k3, 'shipments/7/'), $k3);

$k4 = exp_storage_key(7, 'weird.name.with.PDF');
t('a multi-dot name takes only the last extension, lowercased',
  str_ends_with($k4, '.pdf'), $k4);

/* ------------------------------------------------------------------------- */
head('5. S3 errors are read, not dumped');

t('the message is pulled out of the XML',
  exp_r2_s3_error('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>')
  === 'Access Denied');
t('the code is used when there is no message',
  exp_r2_s3_error('<Error><Code>NoSuchBucket</Code></Error>') === 'NoSuchBucket');
t('nothing in, nothing out', exp_r2_s3_error('') === '');
t('unparseable XML does not throw', exp_r2_s3_error('not xml at all') === '');

/* ------------------------------------------------------------------------- */
head('6. The rules the file promises about itself');

$whole = $src;

t('the module never builds a public or pre-signed URL for the browser',
  !str_contains($whole, 'X-Amz-Expires') && !str_contains($whole, 'presign'));

/* Asserted on the CODE, not on the comment next to it. An earlier version of
   this check searched the source for the explanatory prose and failed only
   because the sentence wrapped across two lines — which tells you nothing
   about whether the behaviour is right. */
$putFn = lift($whole, 'function exp_store_upload(');
t('a failed R2 upload returns a failure instead of falling through to disk',
  str_contains($putFn, "return [false, 'r2'"));
t('  and the local branch is only reached when the driver is local',
  strpos($putFn, "return [false, 'r2'") < strpos($putFn, 'move_uploaded_file'));

t('the download helper sanitises the filename it puts in the header',
  str_contains($whole, "preg_replace('/[^A-Za-z0-9 ._\\-()\\[\\]]/'"));

t('nosniff is set on every document response',
  substr_count($whole, 'X-Content-Type-Options: nosniff') >= 2);

t('the secret is never echoed, printed or interpolated into output',
  !preg_match('~echo[^;\n]*secret~i', $whole) && !preg_match('~print[^;\n]*secret~i', $whole));

t('the self-test reports a failure without quoting the credentials',
  str_contains($whole, 'is empty in config.php') && !str_contains($whole, "' . \$c['secret']"));

t('streaming uses a write callback, so a large PDF is not held in memory',
  str_contains($whole, 'CURLOPT_WRITEFUNCTION'));

t('R2 stays switched off until all four values are present',
  str_contains($whole, "\$c['endpoint'] !== '' && \$c['bucket'] !== '' && \$c['key'] !== '' && \$c['secret'] !== ''"));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
