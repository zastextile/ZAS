<?php
/* THE APP IS LIGHT, ON EVERY MACHINE.
 *
 * The skin shipped with @media (prefers-color-scheme:dark). That reads
 * the operating system's setting, so every production screen went black
 * on any machine with Windows dark mode switched on — which is a lot of
 * them — and there was no switch in the app to turn it off. The people
 * this hit hardest were the data entry team, who are in these screens all
 * day.
 *
 * This test opens the real pages in Chromium with the browser TELLING the
 * page that dark mode is preferred, and measures the colours that come
 * out. Reading the stylesheet for the string "prefers-color-scheme" would
 * not prove anything about what the team actually sees.
 */

$B   = __DIR__ . '/app_src/public_html/';
$css = file_get_contents($B . 'assets/css/zskin.css');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.ztheme';
@mkdir($work, 0777, true);

echo "1. Nothing in the app reads the machine's theme\n";
/* One grep, and it is the point of the whole change: if this string comes
   back, somebody's laptop is deciding what the company's screens look
   like again. */
/* Comments are stripped first — for the second time today a test of mine
   read its own explanation and called it a fault. The stylesheet says WHY
   the media query was removed, and saying so is not doing it. */
$files = array_merge(glob($B . 'assets/css/*.css'), glob($B . '*.php'), glob($B . 'includes/*.php'));
$readers = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);          // css and php block comments
    if (preg_match('/prefers-color-scheme\s*:\s*dark/', $src)) $readers[] = basename($f);
}
ok($readers === [], 'no file switches theme on the OS setting, got ' . json_encode($readers));
/* The other media query in that file is prefers-reduced-motion, which is
   an accessibility request from the person using the machine, not a look.
   It stays, and this says so in case a later cleanup lumps them together. */
ok(str_contains($css, 'prefers-reduced-motion'),
   'reduced motion is still honoured — that one IS the operator\'s to decide');

echo "2. The dark palette is kept, so a switch is still one attribute away\n";
ok(str_contains($css, ':root[data-theme="dark"] .zskin{'),
   'the dark tokens are still there, behind an explicit opt-in');
ok(str_contains($css, 'A theme is a decision the business makes'),
   'and the reason it is opt-in is written down where the next person will read it');
ok(!str_contains($css, '[data-theme="light"]'),
   'the light guard is gone with the media query it existed for');

echo "3. Opened with the machine set to DARK, the pages are light\n";

/* The real thing: every page that opts into the skin, rendered with its
   own stylesheet and the skin, under colorScheme:'dark'. */
$pages = [];
foreach (glob($B . '*.php') as $f)
    if (str_contains(file_get_contents($f), '<div class="zskin">')) $pages[] = basename($f);
ok(count($pages) >= 15, count($pages) . ' pages opt into the skin');

/* A page cannot be run without a database, so each one's OWN <style> is
   lifted and put over a scrap of its own markup. That is what decides the
   colour — the tokens resolve the same whatever the markup is. */
$cases = [];
foreach ($pages as $pg) {
    $src = file_get_contents($B . $pg);
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $m)) continue;
    $cases[$pg] = $m[1];
}

$html = '<!doctype html><html><head><meta charset="utf-8">'
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
      . '<style>' . $css . '</style></head><body>';
foreach ($cases as $pg => $pageCss) {
    $id = preg_replace('/[^a-z0-9]/', '', strtolower($pg));
    $html .= '<style>' . $pageCss . '</style>'
          . '<div class="zskin" id="p_' . $id . '">'
          . '<div class="zcard"><table class="ztable"><thead><tr><th>Item</th></tr></thead>'
          . '<tbody><tr><td>row</td></tr></tbody></table></div></div>';
}
$html .= '</body></html>';
file_put_contents($work . '/all.html', $html);

/* And one with the opt-in actually set, to prove the dark palette still
   works when it is asked for — a kept feature that no longer functions is
   just dead code with a comment on it. */
file_put_contents($work . '/optin.html', str_replace('<html>', '<html data-theme="dark">', $html));

$probe = <<<'JS'
const { chromium } = require('playwright');
const lum = c => {
  const m = String(c).match(/(\d+),\s*(\d+),\s*(\d+)/);
  if (!m) return null;
  return (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) / 255;
};
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const [name, file] of [['osDark', 'all.html'], ['optIn', 'optin.html']]) {
    // the browser tells the page the machine prefers dark
    const ctx = await br.newContext({ colorScheme: 'dark', viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto('file://' + process.argv[2] + '/' + file);
    await pg.waitForTimeout(150);
    out[name] = await pg.evaluate(() => {
      const res = {};
      document.querySelectorAll('.zskin').forEach(el => {
        const card = el.querySelector('.zcard');
        const cs = getComputedStyle(card || el);
        res[el.id] = { bg: cs.backgroundColor, fg: cs.color,
                       panel: getComputedStyle(el).getPropertyValue('--panel').trim() };
      });
      res.__body = getComputedStyle(document.body).backgroundColor;
      res.__mediaSaysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      return res;
    });
    await ctx.close();
  }
  await br.close();
  const grade = o => {
    const r = {};
    for (const k of Object.keys(o)) {
      if (k.startsWith('__')) { r[k] = o[k]; continue; }
      r[k] = { panel: o[k].panel, bgLum: lum(o[k].bg), fgLum: lum(o[k].fg) };
    }
    return r;
  };
  console.log(JSON.stringify({ osDark: grade(out.osDark), optIn: grade(out.optIn) }));
})();
JS;
file_put_contents($work . '/probe.js', $probe);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$M = json_decode((string)$raw, true);

if (!is_array($M)) { echo "  FAIL: the browser probe did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    $d = $M['osDark'];
    ok($d['__mediaSaysDark'] === true,
       'the browser really is reporting a dark machine — otherwise this test proves nothing');

    $dark = [];
    foreach ($d as $k => $v) {
        if (str_starts_with($k, '__')) continue;
        /* A panel is light if its background is bright and its text is
           dark. Both, not either — a white-on-white page would pass a
           check on background alone. */
        if ($v['bgLum'] === null || $v['bgLum'] < 0.8 || $v['fgLum'] > 0.4)
            $dark[] = $k . ' (bg ' . round((float)$v['bgLum'], 2) . ', fg ' . round((float)$v['fgLum'], 2) . ')';
    }
    ok($dark === [], 'every skinned page stays light on a dark machine: ' . json_encode($dark));
    ok(lum_ok($d['__body']), 'and the page background behind them is light too');

    echo "4. The dark palette still works when it is ASKED for\n";
    $o = $M['optIn'];
    $lightOnes = [];
    foreach ($o as $k => $v) {
        if (str_starts_with($k, '__')) continue;
        if ($v['bgLum'] !== null && $v['bgLum'] > 0.5) $lightOnes[] = $k;
    }
    ok($lightOnes === [],
       'data-theme="dark" on <html> darkens every skinned page: ' . json_encode($lightOnes));
}

function lum_ok($v) { return is_numeric($v) ? $v > 0.8 : true; }

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
