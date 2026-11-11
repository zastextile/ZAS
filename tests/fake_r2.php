<?php
/* A stand-in for Cloudflare R2, for the attendance tests only.
 *
 * Run as a router script: php -S 127.0.0.1:PORT tests/fake_r2.php
 * Objects are files under FAKE_R2_DIR. It speaks just enough S3 for the ERP's
 * own client (includes/storage.php) and Att\Storage to be exercised for real:
 * PUT, GET, DELETE of /bucket/key and ListObjectsV2 on /bucket.
 *
 * It does NOT prove a signature is correct — only Amazon's published examples
 * can, and zatt_m5_test.php checks the signer against those. What it does
 * enforce is that nothing gets in or out unsigned: a request without a SigV4
 * Authorization header for the test key is refused, as a private bucket would.
 *
 * Two switches, as files in the store: ".down" makes every request fail with
 * 500 (storage outage), ".log" collects one line per request.
 */
$dir = rtrim((string) getenv('FAKE_R2_DIR'), '/');
if ($dir === '' || !is_dir($dir)) { http_response_code(500); echo 'FAKE_R2_DIR not set'; return true; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$auth   = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
@file_put_contents("$dir/.log", "$method $path " . ($auth !== '' ? 'signed' : 'UNSIGNED') . "\n", FILE_APPEND);

function s3_error(int $status, string $code, string $msg): bool {
    http_response_code($status);
    header('Content-Type: application/xml');
    echo "<?xml version=\"1.0\"?><Error><Code>$code</Code><Message>$msg</Message></Error>";
    return true;
}
if (!preg_match('~^AWS4-HMAC-SHA256 Credential=TESTKEY/\d{8}/auto/s3/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[0-9a-f]{64}$~', $auth)) {
    return s3_error(403, 'AccessDenied', 'Access Denied');
}
if (is_file("$dir/.down")) {
    return s3_error(500, 'InternalError', 'We encountered an internal error. Please try again.');
}

$parts  = explode('/', ltrim($path, '/'), 2);
$bucket = $parts[0] ?? '';
$key    = $parts[1] ?? '';
if ($bucket !== 'zas-test') { return s3_error(404, 'NoSuchBucket', 'The specified bucket does not exist.'); }
if (str_contains($key, '..')) { return s3_error(400, 'InvalidArgument', 'Bad key'); }
$root = "$dir/objects";
@mkdir($root, 0777, true);

if ($key === '' && $method === 'GET') {                       // ListObjectsV2
    $prefix = (string) ($_GET['prefix'] ?? '');
    $max    = max(1, min(1000, (int) ($_GET['max-keys'] ?? 1000)));
    $after  = (string) ($_GET['continuation-token'] ?? '');
    $all = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $k = substr($f->getPathname(), strlen($root) + 1); if (str_starts_with($k, $prefix)) { $all[] = $k; } } }
    sort($all, SORT_STRING);
    if ($after !== '') { $all = array_values(array_filter($all, fn($k) => strcmp($k, $after) > 0)); }
    $page = array_slice($all, 0, $max);
    $more = count($all) > count($page);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>zas-test</Name><Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>';
    foreach ($page as $k) {
        echo '<Contents><Key>' . htmlspecialchars($k, ENT_XML1) . '</Key><Size>' . filesize("$root/$k") . '</Size><ETag>&quot;' . md5_file("$root/$k") . '&quot;</ETag></Contents>';
    }
    echo '<IsTruncated>' . ($more ? 'true' : 'false') . '</IsTruncated>';
    if ($more) { echo '<NextContinuationToken>' . htmlspecialchars((string) end($page), ENT_XML1) . '</NextContinuationToken>'; }
    echo '</ListBucketResult>';
    return true;
}
if ($key === '') { return s3_error(405, 'MethodNotAllowed', 'Not allowed on a bucket'); }

$file = "$root/$key";
switch ($method) {
    case 'PUT':
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, file_get_contents('php://input'));
        header('ETag: "' . md5_file($file) . '"');
        return true;
    case 'GET':
        if (!is_file($file)) { return s3_error(404, 'NoSuchKey', 'The specified key does not exist.'); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        return true;
    case 'DELETE':
        if (is_file($file)) { unlink($file); }
        http_response_code(204);
        return true;
}
return s3_error(405, 'MethodNotAllowed', 'Method not allowed');
