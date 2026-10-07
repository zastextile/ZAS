<?php
/* REDIS CREDENTIALS AND THE SESSION STORE.
 *
 * On 7 October 2026 the live site could not be logged into. Every attempt
 * answered "CSRF token mismatch". The cause was not CSRF: the Redis password
 * had been rotated on the server, the credentials were written into
 * includes/redis.php, and nothing in the application could notice. Sessions
 * silently stopped being stored, so the token set on the login page was gone
 * by the time the form was posted.
 *
 * Three faults, and this file holds each of them shut:
 *
 *   A CREDENTIAL IN THE SOURCE. It was also in the git repository. It now
 *   comes from config.php, which is gitignored.
 *
 *   A DEFAULT THAT CHOSE WHERE LOGINS LIVE. Redis took the sessions simply
 *   by being installed. It is now opt-in, and the default — file sessions —
 *   is the configuration the app recovered into.
 *
 *   A SILENT FALLBACK. Every path that declines Redis must record a reason
 *   in words, so a screen can say "the session store is unreachable" instead
 *   of letting the failure surface as a CSRF error at the login box.
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
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $s);
}

$src  = (string)file_get_contents($B . 'includes/redis.php');
$code = nocomments($src);

/* ================================================== no secrets in the code */
head('1. No credential is written into the source');

t('includes/redis.php was found', $src !== '');

/* The shape of a credential, not one specific past value: any of these
   constants assigned a non-empty literal is the fault returning. */
/* Written as the two shapes a hard-coded value actually takes, with no
   escape clause. The first version of this assertion ended in
   "|| <the config define exists>", which is always true, so it passed
   however the file was mutated — the same vacuous-assertion fault this
   suite has caught before. */
foreach (['REDIS_PASSWORD', 'REDIS_USERNAME', 'REDIS_PREFIX'] as $k) {
    $asConst  = (bool)preg_match('~\bconst\s+' . $k . '\s*=\s*[\'"][^\'"]+[\'"]~', $code);
    $asDefine = (bool)preg_match('~\bdefine\s*\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"][^\'"]+[\'"]~', $code);
    t("$k is not assigned a literal value", !$asConst && !$asDefine,
      $asConst ? 'const with a literal' : ($asDefine ? 'define with a literal' : null));
}
/* And a sweep for any other secret-shaped constant anyone adds later. */
$secretish = [];
if (preg_match_all('~\bconst\s+([A-Z0-9_]*(?:PASS|PASSWORD|SECRET|TOKEN|APIKEY|API_KEY)[A-Z0-9_]*)\s*=\s*[\'"]([^\'"]{4,})[\'"]~', $code, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) $secretish[] = $hit[1];
}
t('no secret-shaped constant holds a literal anywhere in the file',
  $secretish === [], $secretish);
t('every credential is read out of $config',
  substr_count($code, "\$c['redis_") >= 4, substr_count($code, "\$c['redis_"));
t('nothing reads a credential from a request',
  !preg_match('~\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]*redis~i', $code));

/* config.php must stay out of the repository, or this achieves nothing. */
$ignore = (string)@file_get_contents(dirname($B, 3) . '/.gitignore');
t('config is gitignored, so moving the credential there actually hides it',
  str_contains($ignore, 'public_html/config/') || str_contains($ignore, 'config/'), $ignore);

/* ======================================================= the opt-in switch */
head('2. Redis cannot take the sessions by itself');

$en = nocomments(lift($src, 'function redis_sessions_enabled('));
t('redis_sessions_enabled() exists', $en !== '');
t('it needs BOTH a working configuration AND an explicit flag',
  str_contains($en, 'redis_configured()') && str_contains($en, "redis_sessions"));

$cfgd = nocomments(lift($src, 'function redis_configured('));
t('a blank password counts as not configured',
  str_contains($cfgd, "REDIS_PASSWORD !== ''"));

$boot = nocomments(lift($src, 'function redis_session_boot('));
t('the boot refuses without the extension',   str_contains($boot, "!extension_loaded('redis')"));
t('the boot refuses without a password',      str_contains($boot, "REDIS_PASSWORD === ''"));
t('the boot refuses unless the flag is set',  str_contains($boot, "empty(\$GLOBALS['config']['redis_sessions'])"));

/* The whole point of the outage: a refusal that says nothing looks like a
   CSRF bug three steps later. */
$reasons = substr_count($boot, "ZAS_SESSION_FALLBACK");
t('every refusal records a reason in words', $reasons >= 3, $reasons);
t('and the reason is readable by the rest of the app',
  str_contains($code, 'function session_fallback_reason('));

/* ============================================ run it, do not just read it */
head('3. What actually happens with nothing configured');

$probe = <<<'PHP'
<?php
$GLOBALS['config'] = [];
require $argv[1] . 'includes/redis.php';
$o = ['configured' => redis_configured(), 'sessions' => redis_sessions_enabled()];
$GLOBALS['ZAS_SESSION_FALLBACK'] = '';
redis_session_boot();
$o['reason']  = session_fallback_reason();
$o['handler'] = ini_get('session.save_handler');
$o['prefix_defined'] = defined('REDIS_PREFIX');
$o['prefix'] = REDIS_PREFIX;
$o['host']   = REDIS_HOST;
$o['port']   = REDIS_PORT;
echo json_encode($o);
PHP;
$pf = sys_get_temp_dir() . '/zredis' . getmypid() . '.php';
file_put_contents($pf, $probe);
$g = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pf) . ' ' . escapeshellarg($B) . ' 2>&1'), true);
@unlink($pf);

t('the probe ran', is_array($g), $g);
if (is_array($g)) {
    t('an unconfigured install is not "configured"',  $g['configured'] === false);
    t('and does not put logins in Redis',             $g['sessions'] === false);
    t('sessions stay on files — the state the app recovered into',
      $g['handler'] === 'files', $g['handler']);
    t('and it says why, in a sentence',
      is_string($g['reason']) && str_contains($g['reason'], 'config.php'), $g['reason']);
    /* session_check.php still uses REDIS_PREFIX, so it must exist even when
       Redis is switched off, or that page fatals. */
    t('REDIS_PREFIX is still defined when Redis is off', $g['prefix_defined'] === true);
    t('and defaults to empty rather than to someone else\'s prefix', $g['prefix'] === '');
    t('host and port fall back to sane defaults',
      $g['host'] === '127.0.0.1' && (int)$g['port'] === 6379, [$g['host'], $g['port']]);
}

/* A second probe: credentials present but the flag off — the state his
   server will be in the moment these files land. */
$probe2 = str_replace("\$GLOBALS['config'] = [];",
    "\$GLOBALS['config'] = ['redis_pass'=>'x','redis_user'=>'u','redis_prefix'=>'p:'];", $probe);
$pf2 = sys_get_temp_dir() . '/zredis2' . getmypid() . '.php';
file_put_contents($pf2, $probe2);
$g2 = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pf2) . ' ' . escapeshellarg($B) . ' 2>&1'), true);
@unlink($pf2);

t('the second probe ran', is_array($g2), $g2);
if (is_array($g2)) {
    t('credentials alone do NOT move the sessions',  $g2['sessions'] === false);
    t('logins still on files',                       $g2['handler'] === 'files', $g2['handler']);
    t('and the reason names the switch',
      str_contains((string)$g2['reason'], 'switched off'), $g2['reason']);
    t('but the cache is considered configured, since a cache miss is harmless',
      $g2['configured'] === true);
}

/* ============================================================== the test */
head('4. The connection test');

$st = nocomments(lift($src, 'function redis_selftest('));
t('redis_selftest() exists', $st !== '');
t('it never returns the password',
  !str_contains($st, 'REDIS_PASSWORD .') && !preg_match('~\$add\([^)]*REDIS_PASSWORD~', $st));
t('it reports the step that failed rather than one yes/no',
  str_contains($st, "'ok' =>") && str_contains($st, "'steps' =>"));

/* A Cloudways ACL can allow the cache prefix and refuse the session prefix.
   Testing only the cache would pass and logins would still break. */
t('it tests the SESSION key prefix specifically, not only the cache prefix',
  str_contains($st, "PHPREDIS_SESSION:selftest"));
t('it says plainly what a failed session key means',
  str_contains($st, 'log everybody out'));
t('it cleans up after itself', substr_count($st, '->del(') >= 2);

head('5. The screen');

$set = nocomments((string)file_get_contents($B . 'exp_settings.php'));
t('the test is reachable from Export Masters', str_contains($set, 'run_redis'));
t('it only runs when asked, on a GET, writing nothing',
  str_contains($set, "isset(\$_GET['run_redis'])"));
t('the function is guarded, so a part-uploaded app does not fatal',
  str_contains($set, "function_exists('redis_selftest')"));
t('the screen states where logins are stored right now',
  str_contains($set, "ini_get('session.save_handler')"));
t('and shows the reason when they are not in Redis',
  str_contains($set, 'session_fallback_reason()'));
t('the config block it shows keeps the switch off',
  str_contains($set, "'redis_sessions' =&gt; false"));

head('6. Nothing else broke');

t('session_check.php can still build its key pattern',
  str_contains((string)file_get_contents($B . 'session_check.php'), "REDIS_PREFIX . 'PHPREDIS_SESSION:*'"));
t('bootstrap.php still calls the boot — it is inert unless switched on',
  str_contains(nocomments((string)file_get_contents($B . 'includes/bootstrap.php')), 'redis_session_boot();'));
t('the cache helpers still fail soft',
  str_contains(nocomments(lift($src, 'function cache_get(')), 'if (!$r) return null;'));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
