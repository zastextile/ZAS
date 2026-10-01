<?php
/*
  DOCUMENT STORAGE — Cloudflare R2, with local disk as the fallback.
  =================================================================

  No SDK and no composer. This app already talks to OpenAI with plain curl
  (includes/openai.php), so outbound HTTPS is known to work from the host.
  R2 speaks S3, and an S3 request is a signed HTTP request — curl plus
  hash_hmac, both core PHP. Adding a dependency manager to sign one header
  would be a far bigger change than this file.

  WHAT NEVER HAPPENS HERE
  -----------------------
  No public URL is ever produced, and no signed URL is ever handed to the
  browser. A signed URL is a bearer token inside a link: forwardable, logged
  by proxies, kept in history. Every read is streamed back through PHP after
  the permission check, exactly as download_file.php has always done. The
  object key is random and never leaves the server.

  Credentials live in config.php, which is gitignored and never shipped.
  They are never echoed, never put in a page, never in JavaScript.
*/

/* Four values in config.php:

     'r2_endpoint'   => 'https://<account-id>.r2.cloudflarestorage.com',
     'r2_bucket'     => 'zas-documents',
     'r2_access_key' => '...',
     'r2_secret'     => '...',

   Leave any of them blank and the module stays on local disk. Nothing breaks;
   documents simply go where shipment_files already go. */
function exp_r2_config(): array {
    global $config;
    return [
        'endpoint' => rtrim((string)($config['r2_endpoint'] ?? ''), '/'),
        'bucket'   => trim((string)($config['r2_bucket'] ?? '')),
        'key'      => trim((string)($config['r2_access_key'] ?? '')),
        'secret'   => trim((string)($config['r2_secret'] ?? '')),
        'region'   => trim((string)($config['r2_region'] ?? 'auto')),
    ];
}

function exp_r2_configured(): bool {
    $c = exp_r2_config();
    return $c['endpoint'] !== '' && $c['bucket'] !== '' && $c['key'] !== '' && $c['secret'] !== '';
}

/* Where a NEW document goes. Existing rows carry their own driver, so this
   only ever decides the destination of the next upload. */
function exp_storage_driver(): string {
    return exp_r2_configured() ? 'r2' : 'local';
}

/* A storage key that gives away nothing and cannot be guessed. The shipment id
   is a readable prefix for housekeeping; the random part is what makes the key
   unguessable, and the key never reaches the browser in any case. */
function exp_storage_key(int $shipmentId, string $originalName): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    return 'shipments/' . $shipmentId . '/' . date('Y/m') . '/'
         . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
}

/* ------------------------------------------------------------- SigV4 signing

   Each path segment is encoded, but the slashes between them are not — S3
   signs the path as a path, not as one escaped string. */
function exp_r2_uri_encode_path(string $path): string {
    $parts = explode('/', $path);
    foreach ($parts as &$p) $p = rawurlencode($p);
    return implode('/', $parts);
}

/* The service is a parameter, not a constant, only so this can be checked
   against Amazon's own published derivation example, which uses 'iam'. Every
   caller here passes 's3'. Each step must be RAW binary (the true flag) — the
   classic failure is feeding the hex digest forward, which produces a key that
   looks plausible and signs nothing. */
function exp_r2_signing_key(string $secret, string $datestamp, string $region, string $service = 's3'): string {
    $kDate    = hash_hmac('sha256', $datestamp, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

/* Builds the three headers an S3 request needs. $payloadHash is the SHA-256 of
   the body in hex, or the hash of the empty string for GET and DELETE. */
function exp_r2_headers(string $method, string $objectKey, string $payloadHash): array {
    $c = exp_r2_config();
    $host = parse_url($c['endpoint'], PHP_URL_HOST);
    $amzDate   = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');

    $canonicalUri = '/' . rawurlencode($c['bucket']) . '/' . exp_r2_uri_encode_path($objectKey);

    $canonicalHeaders = "host:{$host}\n"
                      . "x-amz-content-sha256:{$payloadHash}\n"
                      . "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = $method . "\n" . $canonicalUri . "\n" . "" . "\n"
                      . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $scope = $datestamp . '/' . $c['region'] . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n"
                  . hash('sha256', $canonicalRequest);

    $signature = hash_hmac('sha256', $stringToSign,
                           exp_r2_signing_key($c['secret'], $datestamp, $c['region']));

    return [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Authorization: AWS4-HMAC-SHA256 Credential=' . $c['key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
    ];
}

function exp_r2_url(string $objectKey): string {
    $c = exp_r2_config();
    return $c['endpoint'] . '/' . rawurlencode($c['bucket']) . '/' . exp_r2_uri_encode_path($objectKey);
}

/* ------------------------------------------------------------------- put

   Returns [ok, error]. The error is for the log and for an admin screen — it
   never carries the credentials, because the message is built from curl's own
   text and the HTTP status only. */
function exp_r2_put(string $localPath, string $objectKey, string $mime): array {
    if (!exp_r2_configured()) return [false, 'R2 is not configured.'];
    if (!is_file($localPath))  return [false, 'Temporary upload file is missing.'];

    $hash = hash_file('sha256', $localPath);
    if ($hash === false) return [false, 'Could not hash the upload.'];

    $headers = exp_r2_headers('PUT', $objectKey, $hash);
    if ($mime !== '') $headers[] = 'Content-Type: ' . $mime;

    $fh = fopen($localPath, 'rb');
    if (!$fh) return [false, 'Could not open the upload for reading.'];

    $ch = curl_init(exp_r2_url($objectKey));
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($localPath),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FAILONERROR    => false,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($err !== '')          return [false, 'Connection to R2 failed: ' . $err];
    if ($code < 200 || $code > 299) {
        return [false, 'R2 refused the upload (HTTP ' . $code . '). ' . exp_r2_s3_error((string)$body)];
    }
    return [true, ''];
}

/* ------------------------------------------------------------------- get

   Streams the object straight to the browser. Nothing is buffered in memory,
   so a 40 MB PDF costs no more than a 40 KB one. */
function exp_r2_stream(string $objectKey): array {
    if (!exp_r2_configured()) return [false, 'R2 is not configured.'];

    $headers = exp_r2_headers('GET', $objectKey, hash('sha256', ''));

    $ch = curl_init(exp_r2_url($objectKey));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
            echo $chunk;
            return strlen($chunk);
        },
    ]);
    $ok   = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '')          return [false, 'Connection to R2 failed: ' . $err];
    if ($code < 200 || $code > 299) return [false, 'R2 returned HTTP ' . $code];
    return [(bool)$ok, ''];
}

/* Fetches the object into a local temp file instead of to the browser. Used by
   the connection test and by anything that needs the bytes server-side. */
function exp_r2_fetch(string $objectKey): array {
    if (!exp_r2_configured()) return [false, 'R2 is not configured.', ''];
    $headers = exp_r2_headers('GET', $objectKey, hash('sha256', ''));
    $ch = curl_init(exp_r2_url($objectKey));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '')          return [false, 'Connection to R2 failed: ' . $err, ''];
    if ($code < 200 || $code > 299) return [false, 'R2 returned HTTP ' . $code, ''];
    return [true, '', (string)$body];
}

function exp_r2_delete(string $objectKey): array {
    if (!exp_r2_configured()) return [false, 'R2 is not configured.'];
    $headers = exp_r2_headers('DELETE', $objectKey, hash('sha256', ''));
    $ch = curl_init(exp_r2_url($objectKey));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') return [false, 'Connection to R2 failed: ' . $err];
    if ($code < 200 || $code > 299) return [false, 'R2 returned HTTP ' . $code . '. ' . exp_r2_s3_error((string)$body)];
    return [true, ''];
}

/* S3 errors come back as XML. Pull out the message so an admin sees
   "Access Denied" rather than a wall of markup. */
function exp_r2_s3_error(string $xml): string {
    if ($xml === '') return '';
    if (preg_match('~<Message>(.*?)</Message>~s', $xml, $m)) return trim(strip_tags($m[1]));
    if (preg_match('~<Code>(.*?)</Code>~s', $xml, $m))       return trim(strip_tags($m[1]));
    return '';
}

/* ------------------------------------------------- the one call the pages use

   Puts an uploaded file wherever this install stores documents, and reports
   which driver took it so the row can be read back later.
   Returns [ok, driver, key, error]. */
function exp_store_upload(int $shipmentId, string $tmpPath, string $originalName, string $mime): array {
    $key = exp_storage_key($shipmentId, $originalName);

    if (exp_storage_driver() === 'r2') {
        [$ok, $err] = exp_r2_put($tmpPath, $key, $mime);
        if ($ok) return [true, 'r2', $key, ''];
        /* R2 was configured but refused. The upload is NOT silently written to
           disk instead — a document the operator believes is in object storage
           while it sits on the web host is worse than a clear failure. */
        return [false, 'r2', $key, $err];
    }

    global $config;
    $dir = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/');
    $target = $dir . '/' . basename($key);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!@move_uploaded_file($tmpPath, $target) && !@rename($tmpPath, $target)) {
        return [false, 'local', $key, 'Could not write the file to disk.'];
    }
    /* Local keys are flat filenames, because that is where the existing files
       already live and download has to find both kinds. */
    return [true, 'local', basename($key), ''];
}

/* Reads a stored document back out to the browser. The caller has already done
   the permission check — this function does not know who is asking, and must
   never be reachable without one. */
function exp_send_document(array $doc): void {
    $driver = (string)($doc['storage_driver'] ?? 'local');
    $key    = (string)($doc['storage_key'] ?? '');
    $name   = (string)($doc['original_name'] ?? 'document');
    $mime   = (string)($doc['mime_type'] ?? '') ?: 'application/octet-stream';

    /* Content-Disposition carries a filename the user supplied, so quotes and
       control characters come out of it first. */
    $safeName = preg_replace('/[^A-Za-z0-9 ._\-()\[\]]/', '_', $name);
    if ($safeName === '' || $safeName === null) $safeName = 'document';

    if ($driver === 'r2') {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        [$ok, $err] = exp_r2_stream($key);
        if (!$ok) {
            /* Headers are already out, so there is nowhere clean to put an
               error page. Log it and stop rather than emit a half file that
               looks like a corrupt document. */
            error_log('R2 document read failed for doc ' . (int)($doc['id'] ?? 0) . ': ' . $err);
        }
        exit;
    }

    global $config;
    $dir  = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/');
    $path = $dir . '/' . basename($key);
    if (!is_file($path)) { http_response_code(404); exit('Stored file is missing.'); }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ------------------------------------------------------- the connection test

   Admin presses a button, this writes a tiny object, reads it back, compares
   it and deletes it. It reports what happened and never prints a credential.
   This is how R2 gets verified on the live host, which is the only place it
   can be verified. */
function exp_r2_selftest(): array {
    $steps = [];
    if (!exp_r2_configured()) {
        return ['ok' => false, 'steps' => [['Configuration', false, 'One or more of r2_endpoint, r2_bucket, r2_access_key, r2_secret is empty in config.php.']]];
    }
    $steps[] = ['Configuration', true, 'All four values present.'];

    $key    = 'selftest/' . bin2hex(random_bytes(8)) . '.txt';
    $marker = 'zas-r2-selftest-' . bin2hex(random_bytes(6));
    $tmp    = tempnam(sys_get_temp_dir(), 'r2t');
    if ($tmp === false) return ['ok' => false, 'steps' => array_merge($steps, [['Local temp file', false, 'Could not create a temp file.']])];
    file_put_contents($tmp, $marker);

    [$ok, $err] = exp_r2_put($tmp, $key, 'text/plain');
    $steps[] = ['Upload', $ok, $ok ? 'Wrote a 30-byte test object.' : $err];
    if (!$ok) { @unlink($tmp); return ['ok' => false, 'steps' => $steps]; }

    [$ok2, $err2, $body] = exp_r2_fetch($key);
    $match = $ok2 && trim($body) === $marker;
    $steps[] = ['Download', $match, $match ? 'Read it back and the contents match.'
                                           : ($ok2 ? 'Read it back but the contents differ.' : $err2)];

    [$ok3, $err3] = exp_r2_delete($key);
    $steps[] = ['Delete', $ok3, $ok3 ? 'Removed the test object.'
                                     : 'Could not remove it: ' . $err3 . ' (safe to delete by hand: ' . $key . ')'];

    @unlink($tmp);
    return ['ok' => ($ok && $match && $ok3), 'steps' => $steps];
}
