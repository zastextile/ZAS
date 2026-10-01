<?php
/*
  WHY AM I BEING SIGNED OUT? — a read-only diagnostic.

  Sessions in this app are stored in Redis when Redis answers, and in files
  when it does not. That fallback is the suspect:

      bootstrap.php:  ini_set('session.gc_maxlifetime', 28800);
                      redis_session_boot();      <- sets the handler to redis
                      session_start();

      redis.php:      function redis_session_boot(): void {
                          if (!redis_conn()) return;   <- SILENTLY does nothing
                          ...
                      }

  If Redis answers when you sign in, your session lives in Redis. If Redis is
  unreachable on a LATER request, redis_session_boot() returns without setting
  the handler, PHP falls back to its default file store, and that store has no
  idea who you are — so you are signed out, mid-edit, with no error anywhere.
  When Redis comes back you are signed in again. Intermittent Redis means
  intermittent sign-outs, and nothing in the app ever says so.

  THIS PAGE CHANGES NOTHING. It reads the live PHP settings, pings Redis,
  reports how long the current session has left, and writes one marker into
  your own session so that reloading tells you whether the session SURVIVED.
  Leave it open, come back in an hour, press reload: if the marker is gone,
  the session died on its own and that is the whole answer.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_admin();

/* WHICH STORE THIS REQUEST ACTUALLY USED, and if it was not Redis, why.
   This is the single most useful line on the page: a handler of "files" on an
   app configured for Redis is the sign-out, caught in the act. */
$fallback = function_exists('session_fallback_reason') ? session_fallback_reason() : '';
$handler  = ini_get('session.save_handler');
$savePath = (string)ini_get('session.save_path');
$gcLife   = (int)ini_get('session.gc_maxlifetime');
$cookieLt = (int)ini_get('session.cookie_lifetime');
$gcProb   = (int)ini_get('session.gc_probability');
$gcDiv    = (int)ini_get('session.gc_divisor');

/* Never print the Redis password, which sits in the save_path as a query
   string. Everything else about the path is useful. */
$safePath = preg_replace('/(auth(\[pass\])?=)[^&]*/i', '$1***', $savePath);

/* Is Redis actually reachable RIGHT NOW? */
$redis = ['ext' => extension_loaded('redis'), 'up' => false, 'err' => ''];
if ($redis['ext']) {
    try {
        $r = function_exists('redis_conn') ? redis_conn() : null;
        if ($r) { $r->ping(); $redis['up'] = true; }
        else $redis['err'] = 'redis_conn() returned nothing — unreachable, bad auth, or not configured.';
    } catch (Throwable $e) { $redis['err'] = $e->getMessage(); }
} else {
    $redis['err'] = 'The phpredis extension is not loaded at all.';
}

/* ---- IS REDIS THROWING SESSIONS AWAY UNDER MEMORY PRESSURE? ----
 *
 * This is the one that survives every check above. Redis stays up, the handler
 * stays 'redis', the TTL stays 8 hours — and keys still vanish, because a
 * maxmemory policy of allkeys-lru (or allkeys-random) evicts whatever it likes
 * when memory runs short. On shared hosting with a small Redis allowance that
 * is routine, and a session is just another key to evict.
 *
 * evicted_keys is the proof. It counts keys Redis has thrown away to stay
 * inside its limit. On a healthy instance it is zero and stays zero.
 */
$mem = ['ok' => false, 'policy' => '', 'max' => 0, 'used' => 0, 'evicted' => null,
        'expired' => null, 'sessions' => null, 'err' => ''];
if ($redis['up']) {
    try {
        $r = redis_conn();
        $info = $r->info();
        $mem['ok']      = true;
        $mem['used']    = (int)($info['used_memory'] ?? 0);
        $mem['max']     = (int)($info['maxmemory'] ?? 0);
        $mem['evicted'] = isset($info['evicted_keys']) ? (int)$info['evicted_keys'] : null;
        $mem['expired'] = isset($info['expired_keys']) ? (int)$info['expired_keys'] : null;
        $mem['policy']  = (string)($info['maxmemory_policy'] ?? '');
        if ($mem['policy'] === '') {
            /* not in INFO on some builds — ask CONFIG, which shared hosts often block */
            try { $c = $r->config('GET', 'maxmemory-policy'); $mem['policy'] = (string)($c['maxmemory-policy'] ?? ''); }
            catch (Throwable $e) { $mem['policy'] = '(not readable on this host)'; }
        }
        /* how many session keys are actually there, without KEYS blocking the
           server — SCAN in batches, and stop counting at a sane number */
        try {
            $it = null; $n = 0; $pat = REDIS_PREFIX . 'PHPREDIS_SESSION:*'; $guard = 0;
            while (($keys = $r->scan($it, $pat, 500)) !== false && $guard++ < 40) {
                $n += count($keys);
                if ($it === 0 || $it === null) break;
            }
            $mem['sessions'] = $n;
        } catch (Throwable $e) {}
    } catch (Throwable $e) { $mem['err'] = $e->getMessage(); }
}
/* THREE OUTCOMES, NOT TWO — AND A RISKY POLICY IS NOT A CAUSE.
 *
 * The first version of this called any allkeys policy "your answer". That was
 * wrong and it was the same mistake as the /csrf/ test on the error handler:
 * a loose match reported as a finding, which sends somebody hunting for a
 * fault that is not there.
 *
 * Eviction has only actually happened if evicted_keys is above zero. A policy
 * that WOULD evict, on an instance sitting at a third of its memory, has not
 * evicted anything and is not what signed anyone out today. It is worth
 * knowing about, and it is worth saying plainly that it is not the cause.
 */
$evictionHappened = ($mem['evicted'] !== null && $mem['evicted'] > 0);
$memTight         = $mem['max'] > 0 && ($mem['used'] / max(1, $mem['max'])) > 0.85;
$evictionRisk     = stripos($mem['policy'], 'allkeys') !== false;

/* THE MARKER NEEDS A WITNESS THAT OUTLIVES THE SESSION.
 *
 * The first version kept the marker only INSIDE the session — so a session
 * that was wiped came back looking exactly like a first visit, and the page
 * cheerfully said "marker written just now" when what had actually happened
 * was the very failure it was built to catch. It could report a session being
 * REPLACED (same data, new id) but not one being DESTROYED, which is the case
 * that matters.
 *
 * So the same two values are also written to an ordinary browser cookie. The
 * cookie survives what the session does not, and the comparison becomes
 * decisive:
 *
 *   cookie says a marker was written, session has none  -> WIPED. The proof.
 *   both present, ids differ                            -> regenerated
 *   both present, ids match                             -> alive, all is well
 *   neither                                             -> genuinely a first visit
 *
 * The cookie holds a timestamp and a session id. Nothing private, nothing
 * that grants access — a session id that no longer exists is worth nothing,
 * and it is only ever compared against, never trusted.
 */
$COOKIE = 'zas_sesscheck';
$cookieRaw = (string)($_COOKIE[$COOKIE] ?? '');
$cookieAt = 0; $cookieSid = '';
if ($cookieRaw !== '' && strpos($cookieRaw, '|') !== false) {
    [$cA, $cS] = explode('|', $cookieRaw, 2);
    $cookieAt  = (int)$cA;
    $cookieSid = preg_replace('/[^A-Za-z0-9,\-]/', '', $cS);
}

$hasSessionMarker = !empty($_SESSION['sesscheck_at']);
$fresh            = !$hasSessionMarker;

/* THE VERDICT — worked out BEFORE the marker is rewritten below, or writing
   it would destroy the very evidence being read. */
$verdict = 'first';                                   // nothing seen before
if ($hasSessionMarker) {
    $verdict = (($_SESSION['sesscheck_sid'] ?? '') === session_id()) ? 'alive' : 'regenerated';
} elseif ($cookieAt > 0) {
    $verdict = 'wiped';                               // the cookie remembers, the session does not
}
$wipedAfter = $cookieAt > 0 ? time() - $cookieAt : 0;

if ($fresh) {
    $_SESSION['sesscheck_at']  = time();
    $_SESSION['sesscheck_sid'] = session_id();
}
$markerAge = time() - (int)($_SESSION['sesscheck_at'] ?? time());
$sidSame   = ($_SESSION['sesscheck_sid'] ?? '') === session_id();

/* refresh the witness to whatever the session now holds */
@setcookie($COOKIE, ((int)$_SESSION['sesscheck_at']) . '|' . session_id(), [
    'expires'  => time() + 86400 * 7,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

function sc_dur(int $s): string {
    if ($s < 60) return $s . ' sec';
    if ($s < 3600) return round($s / 60) . ' min';
    return round($s / 3600, 1) . ' hr';
}

/* The one combination that explains a mid-edit sign-out. */
$mismatch = ($handler === 'files' && $redis['up']) || ($handler === 'redis' && !$redis['up']);

page_header('Session Check');
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#fff;border:1px solid #e3e9f2;margin-bottom:11px;box-shadow:0 1px 2px rgba(15,35,65,.04)}
.zcard h2{font-size:15px;margin:0 0 11px}
.ztable{width:100%;border-collapse:collapse;font-size:13px}
.ztable th{padding:5px 8px;border-bottom:1px solid #dbe3ee;font-weight:700;text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.ztable td{padding:6px 8px;border-bottom:1px solid #eef2f7;vertical-align:top}
.ztable td.k{color:#5a6b82;width:210px}
.mono{font-family:monospace;font-size:12px;word-break:break-all}
.pill{font-family:monospace;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;white-space:nowrap}
.pill.ok{background:rgba(18,129,60,.12);color:#12813c}
.pill.bad{background:rgba(224,67,93,.14);color:#b8283f}
.pill.warn{background:rgba(163,96,10,.14);color:#a3600a}
.warn{padding:12px 15px;border-radius:12px;background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.35);font-size:13px;color:#8a1f31;margin-bottom:12px}
.safe{padding:11px 14px;border-radius:12px;background:rgba(18,129,60,.07);border:1px solid rgba(18,129,60,.3);font-size:12.5px;color:#0f5f30;margin-bottom:12px}
.note{padding:10px 13px;border-radius:11px;background:#f6f8fc;border:1px solid #e3e9f2;font-size:12.5px;color:#33415c}
.zbtn{padding:10px 16px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-flex;gap:7px;font-family:inherit}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
ul.tight{margin:6px 0 0;padding-left:19px}
ul.tight li{margin-bottom:4px}
</style>

<div class="zcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0">Session Check</h2>
    <div style="display:flex;gap:8px">
      <a class="zbtn sec" href="session_check.php">Reload</a>
      <a class="zbtn sec" href="product_master.php">Back</a>
    </div>
  </div>
</div>

<?php if ($mismatch): ?>
<div class="zcard">
  <div class="warn">
    <b>This is almost certainly your answer.</b>
    <?php if ($handler === 'files' && $redis['up']): ?>
      Sessions are being stored in <b>FILES</b> right now, even though Redis is up. That means
      <code>redis_session_boot()</code> found Redis unreachable at the moment this request started.
      Any session created while Redis was working is invisible to this request &mdash; which is
      exactly what being signed out mid-edit looks like.
    <?php else: ?>
      Sessions are set to <b>REDIS</b>, but Redis is <b>not answering right now</b>. Every request
      in this state loses its session. This is the sign-out you are seeing.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="zcard">
  <?php
  /* WHAT IS ACTUALLY ON THIS SERVER.
   *
   * Three separate rounds were lost to not knowing whether a file had reached
   * the server at all. A page can look wrong for two completely different
   * reasons — the upload did not land, or the browser is showing a stored copy
   * — and they need opposite fixes. Guessing between them wastes a day.
   *
   * This reads the modification date off disk, server-side. Compare it with the
   * date on the zip you uploaded: older means it never landed. If the file date
   * is new but the PAGE still looks old, it is the browser, and a hard reload
   * (Ctrl+Shift+R) fixes it. One table, and the question is answered. */
  /* THE REBUILT MODULE'S FILES. includes/part_library.php is deliberately NOT
     here any more: it belonged to the old module and is safe to delete, and a
     watch list that reports a deliberately-deleted file as "missing" trains
     people to ignore the word "missing" — which is the one word on this table
     that has to keep meaning something.

     includes/production.php IS still here, because proforma.php,
     product_costing.php and the inventory screens still require it. It is not
     a leftover. */
  $watch = [
      'product_master.php', 'part_library.php', 'products_csv.php', 'set_quantities_csv.php',
      'production_entry.php', 'production_my_work.php', 'production_workers.php',
      'production_dashboard.php', 'production_reports.php',
      'production_order_rates.php', 'production_amend.php',
      'login.php', 'session_check.php',
      'includes/zprod.php', 'includes/menu.php',
      'includes/production.php',
      'includes/redis.php', 'includes/bootstrap.php', 'includes/auth.php',
  ];
  ?>
  <h2>Which files are on this server</h2>
  <p class="sub">Modification date read from disk. Older than the zip you uploaded means it did not land.
     Newer, but the page still looks old, means the browser is showing a stored copy &mdash; reload with Ctrl+Shift+R.</p>
  <table class="ztable">
    <tr><th>File</th><th>Last changed on the server</th><th>Size</th></tr>
    <?php foreach ($watch as $f):
        $abs = __DIR__ . '/' . $f;
        $mt  = @filemtime($abs);
        $sz  = @filesize($abs);
    ?>
    <tr>
      <td class="mono"><?= e($f) ?></td>
      <td><?= $mt ? '<b>' . e(date('d M Y H:i', $mt)) . '</b>'
                  : '<span class="pill bad">missing</span>' ?></td>
      <td class="mono"><?= $sz ? number_format((int)$sz) . ' bytes' : '&mdash;' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <h2>Where your session is stored</h2>
  <table class="ztable">
    <tr><td class="k">Save handler</td><td>
      <b class="mono"><?= e($handler ?: 'unknown') ?></b>
      <?php if ($handler === 'redis'): ?><span class="pill ok">Redis</span>
      <?php elseif ($handler === 'files'): ?><span class="pill warn">Files &mdash; the fallback</span>
      <?php endif; ?></td></tr>
    <?php /* If the app could not use Redis for THIS request, say so in words.
             A handler of "files" on an app configured for Redis is the sign-out
             caught in the act, and this line names the reason rather than
             leaving it to be inferred from the row above. */ ?>
    <tr><td class="k">Fallback this request</td><td>
      <?php if ($fallback === ''): ?><span class="pill ok">none &mdash; the Redis store was used</span>
      <?php else: ?><span class="pill bad">yes</span> <?= e($fallback) ?><?php endif; ?></td></tr>
    <tr><td class="k">Save path</td><td class="mono"><?= e($safePath ?: '(default)') ?></td></tr>
    <tr><td class="k">Redis extension</td><td>
      <?= $redis['ext'] ? '<span class="pill ok">loaded</span>' : '<span class="pill bad">not loaded</span>' ?></td></tr>
    <tr><td class="k">Redis answering now</td><td>
      <?= $redis['up'] ? '<span class="pill ok">yes</span>'
                       : '<span class="pill bad">no</span> <span class="mono" style="color:#8a97ab">' . e($redis['err']) . '</span>' ?></td></tr>
  </table>
  <?php if ($handler === 'files'): ?>
  <div class="note" style="margin-top:11px">
    <b>Files in a shared temp directory are the risky case.</b> Many hosts clean that directory on
    their own schedule, which wipes sessions long before the 8 hours below. If the handler stays on
    files, sessions should be given a directory of their own that nothing else cleans.
  </div>
  <?php endif; ?>
</div>

<div class="zcard">
  <h2>Is Redis throwing sessions away?</h2>
  <?php if (!$mem['ok']): ?>
    <?php /* This app's Redis user is ACL-restricted to one key prefix (see
             REDIS_PREFIX in includes/redis.php). INFO is a server command, not
             a key, so a tight ACL refuses it — and then this page can say
             nothing either way. Reporting that is the honest outcome; hiding
             the card would look like a clean bill of health. */ ?>
    <div class="note">
      <b>Redis would not answer INFO, so this check could not run.</b>
      <?php if ($mem['err'] !== ''): ?>
        <br><span class="mono" style="color:#8a97ab"><?= e($mem['err']) ?></span>
      <?php endif; ?>
      <br><br>That is expected if this app's Redis user is restricted to its own key prefix
      &mdash; <code>INFO</code> is a server-wide command, not a key. <b>It is not a clean result:</b>
      eviction is still possible and simply cannot be seen from here. Ask your host for two numbers:
      the <b>maxmemory-policy</b> and the <b>evicted_keys</b> counter. If the policy contains
      <code>allkeys</code>, or evicted_keys is above zero, that is what is signing people out.
    </div>
  <?php elseif ($evictionHappened): ?>
    <div class="warn">
      <b>This is your answer.</b>
      Redis has evicted <b><?= number_format($mem['evicted']) ?></b> keys to stay inside its memory
      limit. Eviction ignores the 8-hour lifetime entirely &mdash; it throws away whatever the policy
      picks, and a session is just another key. Redis stays up the whole time, which is why every
      other check on this page looks healthy.
    </div>
  <?php elseif ($evictionRisk && $memTight): ?>
    <div class="warn">
      <b>Nothing has been evicted yet, but it is about to be.</b>
      The policy is <b class="mono"><?= e($mem['policy']) ?></b> and memory is over 85% full. The next
      thing that needs room will start throwing keys away, and sessions are eligible.
    </div>
  <?php elseif ($evictionRisk): ?>
    <div class="safe">
      <b>Not this. Nothing has been evicted.</b>
      The policy is <b class="mono"><?= e($mem['policy']) ?></b>, which <i>would</i> evict sessions if
      memory ran short &mdash; but the counter is zero and memory has room, so no session has been
      thrown away. Worth fixing one day; <b>it is not what signed you out.</b>
    </div>
  <?php else: ?>
    <div class="safe">
      <b>No evictions.</b> Redis has not thrown any keys away, so this is not what is signing you out.
    </div>
  <?php endif; ?>
  <?php if ($mem['ok']): ?>
  <table class="ztable">
    <tr><td class="k">Eviction policy</td><td>
      <b class="mono"><?= e($mem['policy'] ?: 'unknown') ?></b>
      <?php if (stripos($mem['policy'], 'allkeys') !== false): ?>
        <span class="pill bad">evicts sessions</span>
      <?php elseif (stripos($mem['policy'], 'volatile') !== false): ?>
        <span class="pill warn">evicts keys that have a TTL &mdash; sessions have one</span>
      <?php elseif (stripos($mem['policy'], 'noeviction') !== false): ?>
        <span class="pill ok">never evicts</span>
      <?php endif; ?></td></tr>
    <tr><td class="k">Keys evicted so far</td><td>
      <?php if ($mem['evicted'] === null): ?><span class="mono">not reported</span>
      <?php elseif ($mem['evicted'] > 0): ?>
        <b class="mono" style="color:#b8283f"><?= number_format($mem['evicted']) ?></b>
        <span class="pill bad">sessions among them</span>
      <?php else: ?><b class="mono">0</b> <span class="pill ok">none</span><?php endif; ?></td></tr>
    <tr><td class="k">Keys expired normally</td><td class="mono">
      <?= $mem['expired'] === null ? 'not reported' : number_format($mem['expired']) ?>
      <span style="color:#8a97ab">&mdash; these reached their own lifetime, which is healthy</span></td></tr>
    <tr><td class="k">Memory used</td><td class="mono">
      <?= number_format($mem['used'] / 1048576, 1) ?> MB
      <?php if ($mem['max'] > 0): ?>
        of <?= number_format($mem['max'] / 1048576, 1) ?> MB
        <b><?= round($mem['used'] / max(1, $mem['max']) * 100) ?>%</b>
        <?php if ($mem['used'] / max(1, $mem['max']) > 0.85): ?><span class="pill bad">nearly full</span><?php endif; ?>
      <?php else: ?><span style="color:#8a97ab">&mdash; no limit set</span><?php endif; ?></td></tr>
    <tr><td class="k">Sessions stored now</td><td class="mono">
      <?= $mem['sessions'] === null ? 'not countable on this host' : number_format($mem['sessions']) ?></td></tr>
  </table>
  <?php if ($evictionHappened || ($evictionRisk && $memTight)): ?>
  <div class="note" style="margin-top:11px">
    <b>The fix is one of two things, and neither is app code.</b>
    Either give Redis more memory, or take sessions off it &mdash; sessions are small and constant,
    and a file store that nothing evicts is more dependable than a cache that is allowed to forget.
    Caching can stay on Redis either way; losing a cached value costs a query, losing a session costs
    an hour of somebody's work.
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="zcard">
  <h2>How long a session is meant to last</h2>
  <table class="ztable">
    <tr><td class="k">Idle lifetime</td><td>
      <b class="mono"><?= $gcLife ?></b> seconds &mdash; <?= e(sc_dur($gcLife)) ?>
      <?= $gcLife >= 28800 ? '<span class="pill ok">as configured</span>'
                           : '<span class="pill bad">LOWER than the 28800 bootstrap.php asks for</span>' ?></td></tr>
    <tr><td class="k">Cookie lifetime</td><td>
      <b class="mono"><?= $cookieLt ?></b>
      <?= $cookieLt === 0 ? ' &mdash; until the browser closes <span class="pill ok">normal</span>'
                          : ' seconds &mdash; ' . e(sc_dur($cookieLt)) ?></td></tr>
    <tr><td class="k">Cleanup chance</td><td class="mono"><?= $gcProb ?> in <?= $gcDiv ?: 1 ?> requests</td></tr>
  </table>
  <?php if ($gcLife < 28800): ?>
  <div class="warn" style="margin:11px 0 0">
    <b>The lifetime is shorter than the app asks for.</b> bootstrap.php sets 28800 (8 hours) with
    <code>ini_set</code>, but the value in force is <?= $gcLife ?>. Something is overriding it &mdash;
    usually a host-level php.ini or a .user.ini. That alone would explain regular sign-outs.
  </div>
  <?php endif; ?>
</div>

<div class="zcard">
  <h2>Did this session survive?</h2>
  <?php if ($verdict === 'wiped'): ?>
    <div class="warn">
      <b>Caught it. Your session was DESTROYED, not merely idle.</b>
      This browser still remembers writing a marker
      <?= e(sc_dur($wipedAfter)) ?> ago under session
      <span class="mono"><?= e(substr($cookieSid, 0, 12)) ?>&hellip;</span> &mdash; but the session
      itself came back empty, with a new id. Nothing signed you out; the stored session could not be
      read, so PHP started a fresh one.
      <br><br><b>That is what a timed-out session read looks like.</b> The Redis read timeout for
      sessions was <b>200&nbsp;ms</b>: Redis had to answer within a fifth of a second on every single
      request, and a single late reply cost the whole session. It is now 2.5 seconds, with one retry.
    </div>
  <?php elseif ($verdict === 'regenerated'): ?>
    <div class="warn">
      <b>The session was replaced.</b> The marker was written <?= e(sc_dur($markerAge)) ?> ago under a
      different session id, so the original was lost and a new one started &mdash; without you signing
      in again.
    </div>
  <?php elseif ($verdict === 'alive'): ?>
    <div class="safe">
      <b>Same session, still alive after <?= e(sc_dur($markerAge)) ?>.</b>
      It has not been lost in that time.
    </div>
  <?php else: ?>
    <div class="note">
      <b>Marker written just now</b>, in the session and in this browser.
      Leave this tab open and work as normal. Come back after the next sign-out and press
      <b>Reload</b>: because the browser copy outlives the session, this page can now tell a session
      that was <b>destroyed</b> from one that was merely idle &mdash; which the first version of this
      check could not.
    </div>
  <?php endif; ?>
  <table class="ztable" style="margin-top:11px">
    <tr><td class="k">Session id now</td><td class="mono"><?= e(substr(session_id(), 0, 12)) ?>&hellip;</td></tr>
    <tr><td class="k">Marker written</td><td class="mono"><?= e(date('Y-m-d H:i:s', (int)($_SESSION['sesscheck_at'] ?? time()))) ?></td></tr>
    <tr><td class="k">Browser remembers</td><td class="mono"><?= $cookieAt
        ? e(date('Y-m-d H:i:s', $cookieAt)) . ' &middot; ' . e(substr($cookieSid, 0, 12)) . '&hellip;'
        : 'nothing yet' ?></td></tr>
    <tr><td class="k">Signed in as</td><td class="mono"><?= e(current_user()['name'] ?? '?') ?> &middot; <?= e(current_user()['role'] ?? '?') ?></td></tr>
  </table>
</div>

<div class="zcard">
  <h2>What to do about it</h2>
  <div class="note">
    <ul class="tight">
      <li><b>If "Fallback this request" says anything but "none"</b> &mdash; that request did not use
        Redis, and anyone signed in through Redis looked signed out for it. The reason is printed
        beside it. This is the row that used to be missing, and it is the first one to read.</li>
      <li><b>If the handler says FILES and Redis is up</b> &mdash; Redis is flapping. Either make it
        reliable, or stop using it for sessions (cache only). Sessions are tiny; files are dependable.</li>
      <li><b>If the handler says REDIS and Redis is down</b> &mdash; same conclusion, caught from the
        other side.</li>
      <li><b>If the idle lifetime is below 28800</b> &mdash; a host php.ini is overriding the app.
        That is a hosting setting, not a code one.</li>
      <li><b>If "Keys evicted so far" ever leaves zero</b> &mdash; Redis has started throwing keys
        away to stay inside its memory limit, and on an <code>allkeys-</code> policy a session is just
        another key. That is a server setting, not a code one: sessions want a policy that will not
        evict them, or their own Redis database.</li>
      <li><b>If everything here looks right and you are still signed out</b> &mdash; it is the shared
        temp directory being cleaned. Sessions need their own directory.</li>
    </ul>
    <?php
    /* THIS PARAGRAPH USED TO PROMISE SOMETHING THAT IS NO LONGER TRUE.
     *
     * It said Product Master keeps what you type in your browser and offers it
     * back after a reload. That was true of the OLD page, which saved over AJAX
     * and kept a localStorage draft. The rebuilt page is an ordinary form with
     * one Save, and has no draft at all.
     *
     * A diagnostic page that reassures you about a safety net you do not have
     * is worse than one that says nothing: you would find out it was missing at
     * the exact moment you needed it. So it is checked, live, against the file
     * on disk rather than asserted. */
    $pmFile  = __DIR__ . '/product_master.php';
    $pmDraft = is_file($pmFile) && str_contains((string)@file_get_contents($pmFile), 'localStorage');
    ?>
    <br>
    <?php if ($pmDraft): ?>
      Product Master keeps what you type in your own browser and offers it back after a reload &mdash;
      so a sign-out costs a login, not an hour of typing.
    <?php else: ?>
      <b>Note:</b> the rebuilt Product Master does <b>not</b> keep a local draft &mdash; it is a plain
      form with one Save. A sign-out part-way through would cost what is on screen, so on a long product
      press <b>Save</b> once the name and sizes are in, rather than at the very end.
    <?php endif; ?>
  </div>
</div>
<?php page_footer(); ?>
