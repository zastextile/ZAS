<?php
declare(strict_types=1);

namespace Att;

/**
 * Where attendance images are kept: the ERP's own private Cloudflare R2
 * bucket, under attendance/.
 *
 * The ERP already talks to R2 (includes/storage.php) for shipment documents,
 * with the credentials in config.php. This class adds nothing to that
 * arrangement and no second set of keys: put, get and delete go through the
 * ERP's exp_r2_* functions, and the one thing those do not offer — listing a
 * folder — is signed here with the same credentials.
 *
 * AND IT KEEPS THAT FILE'S RULE: no public URL is ever produced and no signed
 * URL is ever handed to a browser. A signed URL is a bearer token inside a
 * link — forwardable, logged by proxies, kept in history. Every image is read
 * by the server after the permission check and sent on; a phone never learns
 * where an image lives. (The blueprint asks for "signed short-lived access";
 * this is stricter than that, and is how the rest of this ERP already works.)
 *
 * A driver can be swapped in for tests. Nothing else may call useDriver().
 */
final class Storage
{
    public const PREFIX = 'attendance/';

    /** @var object|null test double with put/get/delete/list */
    private static ?object $driver = null;

    public static function useDriver(?object $driver): void
    {
        self::$driver = $driver;
    }

    public static function configured(): bool
    {
        if (self::$driver !== null) {
            return true;
        }
        return function_exists('exp_r2_configured') && \exp_r2_configured();
    }

    /** @return array{0:bool,1:string} */
    public static function put(string $key, string $bytes, string $mime): array
    {
        if (self::$driver !== null) {
            return self::$driver->put($key, $bytes, $mime);
        }
        if (!self::configured()) {
            return [false, 'Photo storage is not connected.'];
        }
        // The ERP's uploader takes a file, because its callers have one.
        $tmp = tempnam(sys_get_temp_dir(), 'attimg');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return [false, 'The server could not write a temporary file.'];
        }
        try {
            [$ok, $err] = \exp_r2_put($tmp, $key, $mime);
            return [(bool) $ok, (string) $err];
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{0:bool,1:string,2:string} ok, error, bytes */
    public static function get(string $key): array
    {
        if (self::$driver !== null) {
            return self::$driver->get($key);
        }
        if (!self::configured()) {
            return [false, 'Photo storage is not connected.', ''];
        }
        [$ok, $err, $bytes] = \exp_r2_fetch($key);
        return [(bool) $ok, (string) $err, (string) $bytes];
    }

    /** @return array{0:bool,1:string} */
    public static function delete(string $key): array
    {
        if (self::$driver !== null) {
            return self::$driver->delete($key);
        }
        if (!self::configured()) {
            return [false, 'Photo storage is not connected.'];
        }
        [$ok, $err] = \exp_r2_delete($key);
        return [(bool) $ok, (string) $err];
    }

    /**
     * Everything under a folder.
     *
     * @return array{0:bool,1:string,2:list<array{key:string,size:int,etag:string}>}
     */
    public static function list(string $prefix, int $max = 5000): array
    {
        if (self::$driver !== null) {
            return self::$driver->list($prefix, $max);
        }
        if (!self::configured()) {
            return [false, 'Photo storage is not connected.', []];
        }
        $c     = \exp_r2_config();
        $host  = (string) parse_url($c['endpoint'], PHP_URL_HOST);
        $out   = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'max-keys' => '1000', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $amzDate = gmdate('Ymd\THis\Z');
            $path    = '/' . rawurlencode($c['bucket']);
            $empty   = hash('sha256', '');
            $signed  = self::sigV4('GET', $path, $query, [
                'host' => $host, 'x-amz-content-sha256' => $empty, 'x-amz-date' => $amzDate,
            ], $empty, $c['key'], $c['secret'], $c['region'], $amzDate);

            $ch = curl_init($c['endpoint'] . $path . '?' . self::canonicalQuery($query));
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    'Host: ' . $host, 'x-amz-content-sha256: ' . $empty, 'x-amz-date: ' . $amzDate,
                    'Authorization: ' . $signed['authorization'],
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $body = (string) curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err !== '') {
                return [false, 'Connection to the photo storage failed: ' . $err, []];
            }
            if ($code < 200 || $code > 299) {
                $why = function_exists('exp_r2_s3_error') ? \exp_r2_s3_error($body) : '';
                return [false, 'The photo storage refused to list the folder (HTTP ' . $code . '). ' . $why, []];
            }
            foreach (self::parseList($body) as $row) {
                $out[] = $row;
                if (count($out) >= $max) {
                    return [true, '', $out];
                }
            }
            $token = preg_match('~<NextContinuationToken>(.*?)</NextContinuationToken>~s', $body, $m)
                ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1) : null;
            $more  = preg_match('~<IsTruncated>\s*true\s*</IsTruncated>~i', $body) === 1;
        } while ($more && $token !== null);

        return [true, '', $out];
    }

    /**
     * Pull keys, sizes and ETags out of a ListObjectsV2 answer.
     *
     * @return list<array{key:string,size:int,etag:string}>
     */
    public static function parseList(string $xml): array
    {
        $rows = [];
        if (!preg_match_all('~<Contents>(.*?)</Contents>~s', $xml, $all)) {
            return $rows;
        }
        foreach ($all[1] as $c) {
            if (!preg_match('~<Key>(.*?)</Key>~s', $c, $k)) {
                continue;
            }
            preg_match('~<Size>(\d+)</Size>~', $c, $s);
            preg_match('~<ETag>(.*?)</ETag>~s', $c, $e);
            $rows[] = [
                'key'  => html_entity_decode($k[1], ENT_QUOTES | ENT_XML1),
                'size' => (int) ($s[1] ?? 0),
                'etag' => trim(html_entity_decode((string) ($e[1] ?? ''), ENT_QUOTES | ENT_XML1), '"'),
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------ SigV4

    /** @param array<string,string> $query */
    public static function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $parts);
    }

    /**
     * AWS Signature Version 4 for one request. A pure function of its
     * arguments — the date is passed in — so it can be checked against
     * Amazon's own published examples, which is the only real proof a signer
     * is right (tests/zatt_m5_test.php does).
     *
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-case names; must include host
     * @return array{authorization:string,signature:string,canonical_request:string}
     */
    public static function sigV4(
        string $method, string $canonicalPath, array $query, array $headers, string $payloadHash,
        string $accessKey, string $secret, string $region, string $amzDate, string $service = 's3'
    ): array {
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim(preg_replace('/\s+/', ' ', $value) ?? '') . "\n";
        }
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));

        $canonicalRequest = $method . "\n" . $canonicalPath . "\n" . self::canonicalQuery($query) . "\n"
                          . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $date  = substr($amzDate, 0, 8);
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        // Each step feeds RAW bytes forward (the true flag). Feeding the hex
        // digest forward gives a key that looks right and signs nothing.
        $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $stringToSign, $k);

        return [
            'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
                             . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            'signature' => $signature,
            'canonical_request' => $canonicalRequest,
        ];
    }

    /** A key nobody can guess; the folder says only what kind of image it is. */
    public static function newKey(string $kind, string $ext = 'webp'): string
    {
        $folder = $kind === 'flag' ? 'flags/' . gmdate('Y/m') . '/' : 'ref/';
        $ext    = $ext === 'jpg' ? 'jpg' : 'webp';
        return self::PREFIX . $folder . bin2hex(random_bytes(20)) . '.' . $ext;
    }
}
