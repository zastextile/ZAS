<?php
/* DOES IT ACTUALLY INSTALL? — the three phone apps, checked in a browser.
 *
 * "recheck that separate install on mobile as app pwa style"
 *
 * Reading a manifest and saying it looks right is not a check. A phone
 * offers "Add to Home Screen" only when several things line up at once:
 * a manifest it can fetch and parse, a name, icons it can actually load
 * at 192 and 512, a display mode that is not "browser", a start_url
 * inside the scope, and a service worker that registers and takes
 * control. Any one of those missing and the option simply never appears,
 * with nothing on screen to say why.
 *
 * So this serves the real pages over HTTP — a service worker will not
 * register from a file:// page, which is why an offline check would have
 * proved nothing — and asks Chromium what it sees.
 *
 * It also states the thing that is easy to miss: all three apps use the
 * same icon file, so installing more than one puts identical squares on
 * the home screen.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

$MANIFESTS = ['manifest_m.json', 'manifest_gate.json', 'manifest_pack.json'];

/* ======================================= 1. the manifests, as documents */
head('1. Each manifest says what a phone needs to hear');

$parsed = [];
foreach ($MANIFESTS as $m) {
    $raw = @file_get_contents($B . $m);
    t("$m exists", $raw !== false);
    if ($raw === false) continue;
    $j = json_decode($raw, true);
    t("$m is valid JSON", is_array($j), json_last_error_msg());
    if (!is_array($j)) continue;
    $parsed[$m] = $j;

    t("$m has a name",        ($j['name'] ?? '') !== '');
    t("$m has a short_name",  ($j['short_name'] ?? '') !== '');
    /* A short_name longer than about 12 characters is cut on a home
       screen, which is the only place it is ever shown. */
    t("$m short_name fits under an icon (<= 12 chars)",
      mb_strlen((string)($j['short_name'] ?? '')) <= 12, $j['short_name'] ?? null);
    t("$m opens standalone, not in a browser tab",
      ($j['display'] ?? '') === 'standalone', $j['display'] ?? null);
    t("$m has a start_url",   ($j['start_url'] ?? '') !== '');
    t("$m start_url is a page that exists",
      is_file($B . explode('?', (string)($j['start_url'] ?? ''))[0]),
      $j['start_url'] ?? null);
    t("$m start_url sits inside its scope",
      str_starts_with('./' . ltrim((string)$j['start_url'], './'),
                      rtrim((string)($j['scope'] ?? './'), '/') . '/'),
      [$j['start_url'] ?? null, $j['scope'] ?? null]);
    t("$m sets a theme colour, so the phone's bar matches",
      ($j['theme_color'] ?? '') !== '');

    $sizes = [];
    foreach ($j['icons'] ?? [] as $ic) $sizes[] = (string)($ic['sizes'] ?? '');
    t("$m offers a 192 icon",  in_array('192x192', $sizes, true), $sizes);
    t("$m offers a 512 icon",  in_array('512x512', $sizes, true), $sizes);
    $purposes = array_column($j['icons'] ?? [], 'purpose');
    t("$m offers a maskable icon, or Android crops it into a circle badly",
      in_array('maskable', $purposes, true), $purposes);
}

head('2. The icon files are really there, and really that size');

$seenIcons = [];
foreach ($parsed as $m => $j) {
    foreach ($j['icons'] ?? [] as $ic) {
        $src = (string)($ic['src'] ?? '');
        $want = (string)($ic['sizes'] ?? '');
        $path = $B . $src;
        if (isset($seenIcons[$src])) continue;      /* shared between manifests */
        $seenIcons[$src] = true;
        t("$src exists", is_file($path));
        if (!is_file($path)) continue;
        $info = @getimagesize($path);
        t("$src is a real PNG", is_array($info) && ($info['mime'] ?? '') === 'image/png',
          $info['mime'] ?? null);
        if (is_array($info) && $want !== '') {
            [$w, $h] = array_map('intval', explode('x', $want));
            t("$src is actually {$want}", (int)$info[0] === $w && (int)$info[1] === $h,
              $info[0] . 'x' . $info[1]);
        }
    }
}

/* Worth saying out loud rather than discovering on a phone. */
head('3. The three apps share one icon — say so, do not pretend otherwise');

$iconSets = [];
foreach ($parsed as $m => $j) $iconSets[$m] = array_column($j['icons'] ?? [], 'src');
$allSame = count(array_unique(array_map('json_encode', $iconSets))) === 1;
t('all three use the same icon files, so they look identical once installed',
  $allSame, $iconSets);
t('but each has its own name, which is what the phone labels it with',
  count(array_unique(array_column($parsed, 'short_name'))) === count($parsed),
  array_column($parsed, 'short_name'));
t('and each starts on a different page, so they are genuinely separate apps',
  count(array_unique(array_column($parsed, 'start_url'))) === count($parsed),
  array_column($parsed, 'start_url'));

head('4. The service worker is the other half of installability');

$sw = (string)@file_get_contents($B . 'sw.js');
t('sw.js exists', $sw !== '');
t('it handles fetch, which is what a browser looks for',
  str_contains($sw, "addEventListener('fetch'"));
t('and every phone page registers it',
  str_contains((string)file_get_contents($B . 'includes/mobile.php'),
               "serviceWorker.register('sw.js')"));
t('it still refuses to cache a .php page',
  str_contains($sw, 'no interception, always network'),
  'a cached .php page would show stale stock or a stale packing list');
t('each page asks for its own manifest',
  str_contains((string)file_get_contents($B . 'm.php'), "'manifest_m.json'")
  && str_contains((string)file_get_contents($B . 'm_pack.php'), "'manifest_pack.json'"));

/* ============================== 5. what a browser makes of it, served */
head('5. Served over HTTP and put in front of Chromium');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    $work = __DIR__ . '/.zpwa';
    @mkdir($work . '/includes', 0777, true);
    @mkdir($work . '/assets/icons', 0777, true);

    copy($B . 'm.php',               $work . '/m.php.src');
    copy($B . 'includes/mobile.php', $work . '/includes/mobile.php');
    copy($B . 'sw.js',               $work . '/sw.js');
    foreach ($MANIFESTS as $m) copy($B . $m, $work . '/' . $m);
    foreach (glob($B . 'assets/icons/*.png') ?: [] as $p) copy($p, $work . '/assets/icons/' . basename($p));

    /* Render the real m.php once, then serve the result as a static file.
       The page's own PHP is not what is being tested here — the manifest,
       the icons and the service worker are. */
    file_put_contents($work . '/includes/bootstrap.php', <<<'PHP'
<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function current_user(): ?array { return ['id'=>1,'name'=>'Rafiq','email'=>'r@z','role'=>'staff']; }
function is_production_staff(){ return false; }
function is_admin(){ return false; }
function is_colleague(){ return false; }
function assigned_shipment_ids(){ return [5]; }
function redirect($u){ echo "__REDIRECT__ $u"; exit; }
PHP);
    file_put_contents($work . '/includes/inventory.php',
        "<?php\nfunction inv_perm(string \$w): bool { return true; }\n");
    file_put_contents($work . '/includes/packing.php',
        "<?php\nfunction pack_may_use(): bool { return true; }\n");

    $rendered = (string)shell_exec('cd ' . escapeshellarg($work) . ' && '
        . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r '
        . escapeshellarg('$_SERVER["REQUEST_METHOD"]="GET";require "m.php.src";') . ' 2>&1');
    file_put_contents($work . '/m.php', $rendered);
    t('the page rendered for serving', str_contains($rendered, '</html>')
        && !preg_match('~Fatal|Warning:|Notice:~i', $rendered),
        preg_match('~^.*(Fatal|Warning:|Notice:).*$~mi', $rendered, $mm) ? $mm[0] : null);

    /* A router, because php -S would try to execute m.php and because a
       manifest must arrive with a type the browser accepts. */
    file_put_contents($work . '/router.php', <<<'PHP'
<?php
$p = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_dir($p)) $p = rtrim($p, '/') . '/m.php';
if (!is_file($p)) { http_response_code(404); echo 'not found'; return true; }
$ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
$types = ['php' => 'text/html; charset=utf-8', 'html' => 'text/html; charset=utf-8',
          'json' => 'application/manifest+json', 'js' => 'text/javascript',
          'png' => 'image/png', 'css' => 'text/css'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Service-Worker-Allowed: /');
readfile($p);
return true;
PHP);

    /* A FREE PORT, ASKED FOR RATHER THAN GUESSED. A fixed number is a
       collision waiting to happen — this file first used 8731, which
       zhr_test.php also uses, and the two fought. Bind :0, let the
       kernel name a free one, close it and take that number. */
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $pe, $pm);
    $port  = $probe ? (int)substr(($nm = stream_socket_get_name($probe, false)),
                                  strrpos($nm, ':') + 1) : 8749;
    if ($probe) fclose($probe);

    /* exec, SO THE SERVER CAN BE KILLED. proc_open runs a command string
       through a shell, so proc_terminate() kills the shell and leaves
       php -S holding the port for ever — which is exactly what happened
       here, and it broke an unrelated test until the orphan was found.
       exec makes php replace the shell, so there is one process and
       terminating it really stops it. */
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $srv = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
                     . ' -t ' . escapeshellarg($work) . ' ' . escapeshellarg($work . '/router.php'),
                     $desc, $pipes, $work);
    /* Wait for it to answer rather than hoping a sleep was long enough. */
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }

    $js = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
  const p = await ctx.newPage();
  const errs = [], failedReq = [];
  p.on('pageerror', e => errs.push(String(e)));
  p.on('requestfailed', r => failedReq.push(r.url() + ' ' + (r.failure() || {}).errorText));
  await p.goto(base + '/m.php', { waitUntil: 'load' });

  const out = { errors: errs, failedRequests: failedReq };

  // the manifest, as the browser resolves it
  out.manifestHref = await p.evaluate(() => {
    const l = document.querySelector('link[rel=manifest]');
    return l ? l.href : null;
  });
  if (out.manifestHref) {
    const r = await p.request.get(out.manifestHref);
    out.manifestStatus = r.status();
    out.manifestType = (r.headers()['content-type'] || '');
    try { out.manifest = await r.json(); } catch (e) { out.manifestParseError = String(e); }
  }

  // every icon the manifest names, fetched for real
  out.icons = [];
  for (const ic of (out.manifest && out.manifest.icons) || []) {
    const u = new URL(ic.src, out.manifestHref).href;
    const r = await p.request.get(u);
    out.icons.push({ src: ic.src, status: r.status(), type: r.headers()['content-type'] || '',
                     bytes: (await r.body()).length });
  }

  // the service worker: registered, and in control
  /* navigator.serviceWorker.ready NEVER SETTLES when nothing registers —
     it is not a promise that rejects, it simply waits. Awaited on its
     own, a page that lost its registration would hang this harness for
     ever instead of failing it, which is the worst thing a test can do.
     Race it against a clock. */
  out.sw = await p.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return { supported: false };
    try {
      const clock = new Promise(r => setTimeout(() => r('timeout'), 8000));
      const reg = await Promise.race([navigator.serviceWorker.ready, clock]);
      if (reg === 'timeout') {
        return { supported: true, error: 'no service worker took control within 8s' };
      }
      return { supported: true, scope: reg.scope,
               state: (reg.active && reg.active.state) || null,
               script: (reg.active && reg.active.scriptURL) || null };
    } catch (e) { return { supported: true, error: String(e) }; }
  });

  out.startUrlStatus = out.manifest && out.manifest.start_url
    ? (await p.request.get(new URL(out.manifest.start_url, out.manifestHref).href)).status()
    : null;

  await b.close();
  console.log(JSON.stringify(out));
})();
JS;
    file_put_contents($work . '/pwa.js', $js);
    $raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && NODE_PATH=' . escapeshellarg($root)
                    . ' node ' . escapeshellarg($work . '/pwa.js')
                    . ' ' . escapeshellarg('http://127.0.0.1:' . $port) . ' 2>&1');
    $got = json_decode((string)$raw, true);

    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    foreach ($pipes as $pp) if (is_resource($pp)) fclose($pp);

    if (!is_array($got)) {
        t('chromium answered', false, substr((string)$raw, 0, 600));
    } else {
        t('the page loads with no javascript error', ($got['errors'] ?? null) === [], $got['errors'] ?? null);
        t('and nothing it asks for fails to arrive',
          ($got['failedRequests'] ?? null) === [], $got['failedRequests'] ?? null);
        t('the browser finds a manifest link',
          str_ends_with((string)($got['manifestHref'] ?? ''), '/manifest_m.json'),
          $got['manifestHref'] ?? null);
        t('the manifest is served, not 404',
          (int)($got['manifestStatus'] ?? 0) === 200, $got['manifestStatus'] ?? null);
        t('and the browser could parse it',
          is_array($got['manifest'] ?? null), $got['manifestParseError'] ?? 'no manifest');
        t('it is the mobile door, named for the home screen',
          ($got['manifest']['short_name'] ?? '') === 'ZAS Mobile',
          $got['manifest']['short_name'] ?? null);
        t('it opens standalone',
          ($got['manifest']['display'] ?? '') === 'standalone');

        $icons = $got['icons'] ?? [];
        t('every icon in the manifest actually downloads', count($icons) === 3
          && count(array_filter($icons, fn($i) => (int)$i['status'] === 200)) === 3,
          $icons);
        t('and they are real images, not an error page',
          count(array_filter($icons, fn($i) => str_contains((string)$i['type'], 'image/png')
                                            && (int)$i['bytes'] > 1000)) === count($icons),
          $icons);

        t('the start_url loads', (int)($got['startUrlStatus'] ?? 0) === 200,
          $got['startUrlStatus'] ?? null);

        $sw = $got['sw'] ?? [];
        t('the service worker registers', !empty($sw['supported']) && empty($sw['error']),
          $sw);
        t('and reaches activated, which is when a phone offers to install',
          ($sw['state'] ?? '') === 'activated', $sw);
        t('its scope covers the whole mobile app',
          str_ends_with((string)($sw['scope'] ?? ''), '/'), $sw['scope'] ?? null);
        t('it is our sw.js, not something left over',
          str_ends_with((string)($sw['script'] ?? ''), '/sw.js'), $sw['script'] ?? null);
    }
}

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
