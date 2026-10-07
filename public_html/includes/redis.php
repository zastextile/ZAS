<?php
/*
  ZAS Export Docs — Redis integration, isolated in this one file.
  Two independent things, both fully optional at runtime:
   1. redis_session_boot() — routes PHP sessions to Redis if it's reachable,
      otherwise leaves PHP's default file sessions untouched. Must be called
      BEFORE session_start().
   2. cache_get()/cache_set()/cache_remember() — a tiny Redis-backed cache.
      Every function fails soft: if the redis extension isn't loaded, the
      server isn't reachable, or the credentials below are wrong/blank,
      callers just always get a cache miss and fall through to the normal
      DB query. Nothing here can break a page.

  Cloudways commonly runs Redis with ACL enabled — each app gets its own
  username/password and is restricted to keys under its own prefix (any
  other key is rejected with NOPERM). If your setup uses a single plain
  password instead (no username), leave redis_user blank and it falls back
  to plain AUTH automatically.

  Reversible: delete this file and remove the two lines in bootstrap.php
  that reference it, and the app is back to exactly how it was.

  ====================================================================
  THE CREDENTIALS USED TO BE WRITTEN HERE, IN THE SOURCE.
  ====================================================================

  That cost a day of downtime on 7 October 2026. A hard-coded credential
  works perfectly until the other side changes it, and then it fails
  completely, with nothing in the application able to see it coming. When
  the Redis password rotated, every session silently stopped being stored:
  pages still rendered, and every single login came back "CSRF token
  mismatch" — a message about something three steps downstream.

  It was also sitting in the git repository, where a password does not
  belong.

  They now come from config.php, which is gitignored, alongside the
  database password and the R2 keys:

      'redis_host'     => '127.0.0.1',
      'redis_port'     => 6379,
      'redis_user'     => '...',      // Cloudways: Server > Access Details > Redis
      'redis_pass'     => '...',
      'redis_prefix'   => 'yourapp:', // the ACL limits this user to this prefix
      'redis_sessions' => false,      // see below before switching this on

  AND SESSIONS ARE NOW OPT-IN. redis_sessions defaults to FALSE, so the
  session store stays on files — which is what carried the app through the
  outage and what it is running on today. Redis takes the sessions only
  when you deliberately switch it on, after the test on
  Export Masters > Document Storage says it works. The cache does not wait
  for that flag: it is safe either way, because a cache miss costs one
  query while a session miss costs somebody's login.
*/

/* Read once, from config.php. Kept as constants because session_check.php
   and the rest of this file already use these names. */
(function () {
    $c = $GLOBALS['config'] ?? [];
    if (!defined('REDIS_HOST'))     define('REDIS_HOST',     (string)($c['redis_host']   ?? '127.0.0.1'));
    if (!defined('REDIS_PORT'))     define('REDIS_PORT',     (int)   ($c['redis_port']   ?? 6379));
    if (!defined('REDIS_USERNAME')) define('REDIS_USERNAME', (string)($c['redis_user']   ?? ''));
    if (!defined('REDIS_PASSWORD')) define('REDIS_PASSWORD', (string)($c['redis_pass']   ?? ''));
    if (!defined('REDIS_PREFIX'))   define('REDIS_PREFIX',   (string)($c['redis_prefix'] ?? ''));
})();

const REDIS_TIMEOUT = 0.2; // seconds — fail fast, never hang a page on a dead Redis

/* Is Redis configured at all? Without a password there is nothing to
   connect with, and probing an unauthenticated localhost Redis would be a
   guess, not a configuration. */
function redis_configured(): bool {
    return extension_loaded('redis') && REDIS_PASSWORD !== '';
}

/* Sessions move to Redis ONLY when config.php says so AND the credentials
   are present. Anything less and they stay on files, which work. */
function redis_sessions_enabled(): bool {
    return redis_configured() && !empty($GLOBALS['config']['redis_sessions']);
}

/* A SESSION IS NOT A CACHE, AND MUST NOT FAIL FAST.
 *
 * This was the cause of the random sign-outs. The 200ms above was being used
 * as the read timeout for the SESSION store as well as the cache. Redis then
 * had to answer within a fifth of a second on EVERY request, and if it ever
 * did not — a busy neighbour on shared hosting, a brief fsync, any spike —
 * the session read failed, PHP started a fresh empty session, $_SESSION was
 * empty, require_login() sent the login page, and somebody lost their work
 * mid-edit. Redis looked perfectly healthy afterwards, because it was: it had
 * simply been late once.
 *
 * The two have opposite costs when they time out:
 *     a cache miss    costs one database query
 *     a session miss  costs a login, and whatever was being typed
 *
 * So they get different numbers. The cache keeps its 200ms and fails fast.
 * The session waits — 2.5 seconds is still far below any page timeout, and a
 * page that is briefly slow is enormously better than one that signs you out.
 */
const REDIS_SESSION_TIMEOUT = 2.5;

function rk(string $key): string { return REDIS_PREFIX . $key; }

/* Single shared connection per request. Connection is attempted at most once
   per request (the $tried latch) — if it fails, every subsequent cache_get/
   cache_set call in the same request is a cheap no-op instead of retrying.
   A bare TCP connect can succeed even when the server demands auth, so this
   also authenticates and pings before trusting the connection — otherwise a
   wrong/missing credential would look "reachable" here but fail later on
   the first real command. */
function redis_conn(): ?Redis {
  static $r = null;
  static $tried = false;
  if ($tried) return $r;
  $tried = true;
  if (!extension_loaded('redis')) return null;
  try {
    $c = new Redis();
    if (!@$c->connect(REDIS_HOST, REDIS_PORT, REDIS_TIMEOUT)) return null;
    if (REDIS_USERNAME !== '') {
      if (!@$c->auth(['user' => REDIS_USERNAME, 'pass' => REDIS_PASSWORD])) return null;
    } elseif (REDIS_PASSWORD !== '') {
      if (!@$c->auth(REDIS_PASSWORD)) return null;
    }
    if (!@$c->ping()) return null;
    $r = $c;
  } catch (Throwable $e) {
    $r = null;
  }
  return $r;
}

/* ---------------- PHP session -> Redis (with automatic fallback) ---------------- */
/* Set when this request could NOT use the Redis session store, so the app can
   say which store it is on instead of guessing. Read by session_check.php and
   by the sign-out message. '' means Redis is in use, as normal. */
$GLOBALS['ZAS_SESSION_FALLBACK'] = '';
function session_fallback_reason(): string { return (string)($GLOBALS['ZAS_SESSION_FALLBACK'] ?? ''); }

function redis_session_boot(): void {
  if (session_status() !== PHP_SESSION_NONE) return; // already started elsewhere — don't interfere

  /* ==========================================================
     THE SIGN-OUT. THIS LINE, NOT THE TIMEOUT I CHANGED BEFORE.
     ==========================================================

     It used to read:

         if (!redis_conn()) return;   // fall back to file sessions

     redis_conn() is the CACHE connection. It probes with REDIS_TIMEOUT — 200
     milliseconds, chosen deliberately so a dead Redis can never hang a page.
     That is right for a cache and catastrophic here, because the answer to
     that 200ms probe was being used to decide WHERE THE SESSION IS STORED.

     One late probe — a busy neighbour, a brief fsync, any spike — and this
     function returned early. PHP then used FILE sessions for that one request,
     with the same session id in the cookie. The data was in Redis; the file
     handler looked on disk, found nothing, and handed back an empty session.
     require_login() saw no user and sent the login page.

     That is exactly the shape of the fault: the page renders fine (probe
     succeeded), and the save a few minutes later signs you out (probe was late
     that once). Redis looks perfectly healthy afterwards, because it is.

     Raising the timeout INSIDE the save_path, which is what I changed last
     time, never touched this line. The store was still being chosen by a 200ms
     cache probe.

     THE FIX IS TO STOP PROBING. If the extension is present and this app is
     configured for Redis, configure the Redis handler and let phpredis do its
     own connecting — with REDIS_SESSION_TIMEOUT and a retry, which is what
     those settings are for. A health check is not needed to decide something
     that must not change from one request to the next.

     THE STORE MUST NEVER SILENTLY CHANGE MID-SESSION. Half the requests on
     Redis and half on disk is not a degraded service, it is random sign-outs.
     Better to wait 2.5 seconds, and better still to fail loudly. */

  if (!extension_loaded('redis')) {
    $GLOBALS['ZAS_SESSION_FALLBACK'] = 'The php-redis extension is not loaded, so sessions are stored in files.';
    return;
  }
  if (REDIS_PASSWORD === '') {
    $GLOBALS['ZAS_SESSION_FALLBACK'] = 'No Redis credentials in config.php, so sessions are stored in files.';
    return;
  }
  /* THE SWITCH THAT WOULD HAVE PREVENTED THE OUTAGE.
   *
   * Sessions only move to Redis when config.php explicitly says so. Until
   * then they stay on files, which is the state the app recovered into and
   * has been running on since. Switching this on is a deliberate act taken
   * after the test on Export Masters > Document Storage passes — not a
   * default that quietly decides where everybody's login lives. */
  if (empty($GLOBALS['config']['redis_sessions'])) {
    $GLOBALS['ZAS_SESSION_FALLBACK'] = 'Redis sessions are switched off in config.php, so sessions are stored in files.';
    return;
  }
  // ini_set only takes effect if set before session_start() — caller must call this first.
  $authQs = '';
  if (REDIS_USERNAME !== '') {
    $authQs = '&auth[user]=' . rawurlencode(REDIS_USERNAME) . '&auth[pass]=' . rawurlencode(REDIS_PASSWORD);
  } elseif (REDIS_PASSWORD !== '') {
    $authQs = '&auth=' . rawurlencode(REDIS_PASSWORD);
  }
  /* REDIS_SESSION_TIMEOUT, not REDIS_TIMEOUT — see the note on the constant.
     retry_interval gives phpredis one quick second attempt rather than losing
     the session to a single unlucky moment. */
  $path = 'tcp://' . REDIS_HOST . ':' . REDIS_PORT
    . '?timeout=' . REDIS_SESSION_TIMEOUT . '&read_timeout=' . REDIS_SESSION_TIMEOUT
    . '&retry_interval=100'
    . $authQs . '&prefix=' . rawurlencode(REDIS_PREFIX . 'PHPREDIS_SESSION:');
  @ini_set('session.save_handler', 'redis');
  @ini_set('session.save_path', $path);
}

/* ---------------- tiny cache helper ---------------- */
function cache_get(string $key) {
  $r = redis_conn();
  if (!$r) return null;
  try {
    $v = $r->get(rk($key));
    return $v === false ? null : json_decode($v, true);
  } catch (Throwable $e) { return null; }
}
function cache_set(string $key, $value, int $ttl = 30): void {
  $r = redis_conn();
  if (!$r) return;
  try { $r->setex(rk($key), $ttl, json_encode($value)); } catch (Throwable $e) {}
}

/* Get-or-compute: returns the cached value if present, otherwise runs $fn(),
   caches its result, and returns it. If Redis is down, $fn() just runs every
   time — same behaviour as before this feature existed. */
function cache_remember(string $key, int $ttl, callable $fn) {
  $v = cache_get($key);
  if ($v !== null) return $v;
  $v = $fn();
  /* A NEGATIVE ANSWER IS NEVER CACHED.
   *
   * This stored whatever the closure returned, false included. current_user()
   * calls it with a 20-second TTL and returns false when the users row does
   * not come back — so ONE bad read cached "there is no such user" for twenty
   * seconds, and every request in that window was redirected to the login
   * page. A sign-out in the middle of working, with nothing anywhere saying
   * why, and gone again before anyone could look.
   *
   * Re-running a cheap primary-key lookup is the right price for never
   * caching a wrong "no". */
  if ($v !== false && $v !== null) cache_set($key, $v, $ttl);
  return $v;
}

/* ---------------- version-tag invalidation ----------------
   Several different write actions all affect the same cached read (e.g.
   creating, editing, or deleting a product all affect the product listing
   used on Product Costing / Product Master / Packing List). Rather than
   have every write site reconstruct and delete exact cache keys (easy to
   miss one), each read embeds the current version number of the tag(s) it
   depends on; any write just bumps the tag, which invalidates every key
   that embeds it — instantly, with one call, regardless of how many
   different key variants exist. */
function cache_version(string $tag): int {
  $r = redis_conn();
  if (!$r) return 0;
  try {
    $v = $r->get(rk("ver:$tag"));
    return $v === false ? 0 : (int)$v;
  } catch (Throwable $e) { return 0; }
}
function cache_bump(string $tag): void {
  $r = redis_conn();
  if (!$r) return;
  try { $r->incr(rk("ver:$tag")); } catch (Throwable $e) {}
}

/* ------------------------------------------------- the connection test

   The same shape as exp_r2_selftest(): press a button, see each step pass
   or fail, and never see a credential. This exists because switching the
   session store over on faith is what produced a day of "CSRF token
   mismatch" with no way to tell why.

   It checks the SESSION key prefix specifically. A Cloudways ACL can allow
   the cache keys and refuse the session keys, and that difference is
   invisible until every login starts failing. */
function redis_selftest(): array {
    $steps = [];
    $add = function (string $n, bool $ok, string $d) use (&$steps) { $steps[] = [$n, $ok, $d]; };

    if (!extension_loaded('redis')) {
        $add('Extension', false, 'php-redis is not installed on this server.');
        return ['ok' => false, 'steps' => $steps];
    }
    $add('Extension', true, 'php-redis is installed.');

    if (REDIS_PASSWORD === '') {
        $add('Settings', false, 'No redis_pass in config.php, so nothing can connect.');
        return ['ok' => false, 'steps' => $steps];
    }
    $add('Settings', true, 'Host, password and prefix are present. The password is never shown here.');

    $c = new Redis();
    $t0 = microtime(true);
    try { $ok = @$c->connect(REDIS_HOST, REDIS_PORT, 2.0); } catch (Throwable $e) { $ok = false; }
    if (!$ok) {
        $add('Connect', false, 'Could not reach Redis at ' . REDIS_HOST . ':' . REDIS_PORT . '. It may be stopped.');
        return ['ok' => false, 'steps' => $steps];
    }
    $add('Connect', true, 'Answered in ' . round((microtime(true) - $t0) * 1000) . ' ms.');

    try {
        $auth = REDIS_USERNAME !== ''
              ? @$c->auth(['user' => REDIS_USERNAME, 'pass' => REDIS_PASSWORD])
              : @$c->auth(REDIS_PASSWORD);
    } catch (Throwable $e) { $auth = false; }
    if (!$auth) {
        $add('Sign in', false, 'Redis refused these credentials. They have probably been rotated — Cloudways: Server > Access Details > Redis.');
        return ['ok' => false, 'steps' => $steps];
    }
    $add('Sign in', true, 'Credentials accepted.');

    $k = rk('selftest');
    try { $w = @$c->setex($k, 30, 'ok'); $r = @$c->get($k); @$c->del($k); }
    catch (Throwable $e) { $w = false; $r = null; }
    $add('Cache key', (bool)$w && $r === 'ok', ($w && $r === 'ok')
        ? 'Wrote a key under ' . REDIS_PREFIX . ' and read it back.'
        : 'Could not store a key under ' . REDIS_PREFIX . '. The ACL may not allow this prefix.');

    /* The one that actually decides whether logins survive. */
    $sk = REDIS_PREFIX . 'PHPREDIS_SESSION:selftest';
    try { $sw = @$c->setex($sk, 30, 'x'); $sr = @$c->get($sk); @$c->del($sk); }
    catch (Throwable $e) { $sw = false; $sr = null; }
    $add('Session key', (bool)$sw && $sr === 'x', ($sw && $sr === 'x')
        ? 'Sessions can be stored here. Safe to switch on.'
        : 'Redis refuses the session keys. Switching sessions on would log everybody out.');

    $ok = true;
    foreach ($steps as $s) if (!$s[1]) $ok = false;
    return ['ok' => $ok, 'steps' => $steps];
}
