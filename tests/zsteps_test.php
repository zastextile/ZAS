<?php
/* ONE SCREEN AT A TIME — and nothing ever moves sideways.
 *
 * "instead vertical scroll can u make like page wise as one view one page
 *  flip style instead long scroll / But no horizontal never"
 *
 * A phone screen stacked with cards means thumbing past four things to
 * reach the fifth. These turn a page into steps with Back and Next at
 * the bottom. The hard part is not the showing and hiding — it is the
 * three ways a stepped form quietly breaks:
 *
 *   A REQUIRED FIELD ON A STEP YOU CANNOT SEE. The browser refuses to
 *   submit and refuses to say why: "an invalid form control is not
 *   focusable", in the console, where nobody is looking. The form looks
 *   dead. The shell has to open that step itself.
 *
 *   A HIDDEN FIELD THAT STOPS POSTING. Steps are display:none, not
 *   detached, so everything still posts — but only if it stays in the
 *   form. A test that never submits would not notice.
 *
 *   SOMETHING, ANYTHING, WIDER THAN THE SCREEN. He asked for no
 *   horizontal movement at all, so every step is measured.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function nocomments(string $s): string {
    /* THE NAIVE STRIPPER ATE REAL CODE.
       It used one regex for block comments and one for line comments.
       But a phone's file input carries accept="image" followed by a
       slash and a star, and that opens a block comment which never
       closes — so the regex paired it with the next genuine closer
       forty lines later and swallowed everything between. Across this
       app that is twenty-four files, thirty-one thousand bytes in the
       worst of them, and the damage is invisible: a str_contains goes
       false and somebody notices, but a !str_contains goes true and the
       assertion passes for nothing at all.

       PHP's own tokenizer knows a comment from inline HTML, so an
       attribute value cannot fool it. JavaScript comments are stripped
       afterwards, inside script blocks only, because those mislead a
       test exactly the same way. */
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    /* script AND style: a CSS comment misleads a test exactly as a
       JavaScript one does — these very tests were matching the words
       "translateX" and "swipe" inside a comment that says the code
       must never use them. */
    return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) {
        return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $m[0]);
    }, $out);
}

$mob  = (string)file_get_contents($B . 'includes/mobile.php');
$mobN = nocomments($mob);

/* ================================================== 1. nothing goes sideways */
head('1. Nothing moves sideways, by construction');

t('the step that arrives moves on Y only',
  str_contains($mobN, 'translateY(12px)') && !preg_match('~translateX~', $mobN),
  'a translateX in the mobile shell');
t('the step container refuses horizontal overflow',
  preg_match('~\.msteps\{[^}]*overflow-x:hidden~', $mobN) === 1);
/* overflow on html or body would silently kill position:sticky, and the
   header and the step bar both rely on it. */
t('but html and body are left alone, or sticky would die',
  !preg_match('~html\s*,\s*body\{[^}]*overflow~', $mobN),
  'overflow set on html/body breaks the sticky header');
t('there is no swipe handler anywhere',
  !preg_match('~touchstart|touchmove|swipe~i', $mobN),
  'a swipe gesture was added');
t('the animation is switched off for people who ask for that',
  str_contains($mobN, 'prefers-reduced-motion:reduce){.mstep.on{animation:none}'));

head('2. The pieces are there');

foreach (['mob_steps_begin', 'mob_step', 'mob_steps_end'] as $fn) {
    t("$fn() exists", str_contains($mobN, 'function ' . $fn . '('));
}
t('the script finds its container by id, not by currentScript',
  str_contains($mobN, 'document.getElementById(<?= json_encode($id) ?>)'),
  'closest() would find nothing — the script sits after the container closes');
t('the last step shows no Next, because the page owns the save button',
  str_contains($mobN, 'next.hidden = at === steps.length - 1'));
t('only the step in front of the user is validated',
  str_contains($mobN, 'badField(steps[at])'));
t('drawing and history are separate, so going back does not push again',
  str_contains($mobN, 'function render(') && str_contains($mobN, 'function go(')
  && preg_match('~popstate.*?render\(~s', $mobN) === 1,
  'popstate would add a history entry and Back could never leave the page');

/* ===================================== 3. driven in a browser, for real */
head('3. Driven in a browser');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    $work = __DIR__ . '/.zsteps';
    @mkdir($work . '/includes', 0777, true);
    foreach (glob($work . '/*.html') ?: [] as $old) @unlink($old);
    copy($B . 'includes/mobile.php', $work . '/includes/mobile.php');

    /* A page built from the REAL shell. Deliberately includes a required
       field buried on step 3 and a plain one on step 2, so the submit
       path is exercised rather than described. */
    file_put_contents($work . '/page.php', <<<'PHP'
<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function current_user(): ?array { return ['id'=>1,'name'=>'Tester','email'=>'t@z','role'=>'staff']; }
require __DIR__ . '/includes/mobile.php';
mob_header('Steps', '', 'test');
echo '<form method="get" action="done.html" id="f">';
mob_steps_begin('s');
mob_step('First', 'nothing needed here');
echo '<div class="mcard"><p class="note">step one</p></div>';
mob_step('Second');
echo '<div class="mcard"><label class="f"><span>Optional</span>'
   . '<input class="in" name="opt" id="opt" value="kept"></label></div>';
mob_step('Third');
echo '<div class="mcard"><label class="f"><span>Needed</span>'
   . '<input class="in" name="need" id="need" required></label></div>';
mob_step('Last');
echo '<div class="mcard"><p class="note">step four</p></div>'
   . '<button class="btn go" type="submit" id="go">Save</button>';
mob_steps_end();
echo '</form>';
mob_footer();
PHP);
    file_put_contents($work . '/done.html', '<!doctype html><title>done</title><h1>submitted</h1>');
    $html = (string)shell_exec('cd ' . escapeshellarg($work) . ' && '
        . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL page.php 2>&1');
    file_put_contents($work . '/page.html', $html);
    t('the test page renders with no PHP complaint',
      !preg_match('~Fatal|Warning:|Notice:|Deprecated:~i', $html),
      preg_match('~^.*(Fatal|Warning:|Notice:).*$~mi', $html, $m) ? $m[0] : null);

    $js = <<<'JS'
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const dir = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 360, height: 780 } });
  const errs = [];
  p.on('pageerror', e => errs.push(String(e)));
  await p.goto('file://' + path.join(dir, 'page.html'));
  await p.waitForTimeout(250);

  const out = { errors: errs, trace: [] };
  const overflow = async () => p.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  const snap = async (tag) => {
    out.trace.push({ tag,
      visible: await p.locator('.mstep.on').count(),
      total:   await p.locator('.mstep').count(),
      label:   await p.locator('.mprog .cnt').innerText(),
      backOff: await p.locator('[data-mstep="back"]').isDisabled(),
      nextHid: await p.locator('[data-mstep="next"]').isHidden(),
      hoverflow: await overflow(),
      scrollX: await p.evaluate(() => window.scrollX) });
  };

  await snap('load');
  await p.locator('[data-mstep="next"]').click(); await p.waitForTimeout(200);
  await snap('next1');
  // the optional field keeps its value across steps
  await p.locator('#opt').fill('typed on step two');
  await p.locator('[data-mstep="next"]').click(); await p.waitForTimeout(200);
  await snap('next2');
  out.optStillThere = await p.locator('#opt').inputValue();
  await p.locator('[data-mstep="back"]').click(); await p.waitForTimeout(200);
  await snap('back');
  out.optAfterBack = await p.locator('#opt').inputValue();

  // forward to the last step, leaving the required field EMPTY
  await p.locator('[data-mstep="next"]').click(); await p.waitForTimeout(150);
  // Next must refuse to leave step three while the field is empty
  out.labelWhenBlocked = await p.locator('.mprog .cnt').innerText();
  await p.locator('#need').fill('filled');
  await p.locator('[data-mstep="next"]').click(); await p.waitForTimeout(200);
  await snap('last');

  // the phone's own Back button walks the steps instead of leaving
  await p.goBack(); await p.waitForTimeout(250);
  out.afterHardwareBack = await p.locator('.mprog .cnt').innerText();
  out.stillOnPage = p.url().includes('page.html');

  // ...AND KEEPS GOING. If popstate pushes a new entry of its own, each
  // Back pops one and adds one, so the page can never be left — a trap
  // that one press looks perfectly fine from.
  out.backWalk = [out.afterHardwareBack];
  for (let i = 0; i < 6 && p.url().includes('page.html'); i++) {
    await p.goBack(); await p.waitForTimeout(200);
    out.backWalk.push(p.url().includes('page.html')
      ? await p.locator('.mprog .cnt').innerText() : '(left the page)');
  }
  out.escapedEventually = !p.url().includes('page.html');

  // and the form really submits, carrying both fields
  await p.goto('file://' + path.join(dir, 'page.html'));
  await p.waitForTimeout(200);
  for (let i = 0; i < 3; i++) {
    if (i === 1) await p.locator('#opt').fill('two');
    if (i === 2) await p.locator('#need').fill('three');
    await p.locator('[data-mstep="next"]').click(); await p.waitForTimeout(120);
  }
  await p.locator('#go').click(); await p.waitForTimeout(400);
  out.submittedUrl = p.url();

  // a required field left empty on a hidden step must OPEN that step,
  // not fail silently
  await p.goto('file://' + path.join(dir, 'page.html'));
  await p.waitForTimeout(200);
  await p.evaluate(() => {
    const steps = document.querySelectorAll('.mstep');
    steps.forEach((s, i) => s.classList.toggle('on', i === steps.length - 1));
  });
  await p.locator('#go').click(); await p.waitForTimeout(300);
  out.revealedStep = await p.locator('.mprog .cnt').innerText();
  out.requiredVisible = await p.locator('#need').isVisible();
  out.didNotSubmit = p.url().includes('page.html');

  await b.close();
  console.log(JSON.stringify(out));
})();
JS;
    file_put_contents($work . '/drive.js', $js);
    $raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && NODE_PATH=' . escapeshellarg($root)
                    . ' node ' . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg($work) . ' 2>&1');
    $g = json_decode((string)$raw, true);

    if (!is_array($g)) {
        t('chromium answered', false, substr((string)$raw, 0, 500));
    } else {
        t('no javascript error', ($g['errors'] ?? null) === [], $g['errors'] ?? null);

        $byTag = [];
        foreach ($g['trace'] ?? [] as $r) $byTag[$r['tag']] = $r;

        foreach ($byTag as $tag => $r) {
            t("$tag: exactly one step on screen", (int)$r['visible'] === 1, $r);
            t("$tag: nothing wider than the screen", (int)$r['hoverflow'] === 0, $r['hoverflow']);
            t("$tag: the page is not scrolled sideways", (int)$r['scrollX'] === 0, $r['scrollX']);
        }
        t('four steps in all', (int)($byTag['load']['total'] ?? 0) === 4, $byTag['load'] ?? null);
        t('it opens on the first', str_starts_with((string)($byTag['load']['label'] ?? ''), '1 of 4'),
          $byTag['load']['label'] ?? null);
        t('Back is dead on the first step', ($byTag['load']['backOff'] ?? null) === true);
        t('Next moves on',  str_starts_with((string)($byTag['next1']['label'] ?? ''), '2 of 4'),
          $byTag['next1']['label'] ?? null);
        t('and again',      str_starts_with((string)($byTag['next2']['label'] ?? ''), '3 of 4'),
          $byTag['next2']['label'] ?? null);
        t('Back comes back', str_starts_with((string)($byTag['back']['label'] ?? ''), '2 of 4'),
          $byTag['back']['label'] ?? null);
        t('the last step hides Next, leaving the page its own button',
          ($byTag['last']['nextHid'] ?? null) === true, $byTag['last'] ?? null);

        t('what was typed on an earlier step is still there two steps later',
          ($g['optStillThere'] ?? '') === 'typed on step two', $g['optStillThere'] ?? null);
        t('and still there after going back',
          ($g['optAfterBack'] ?? '') === 'typed on step two', $g['optAfterBack'] ?? null);

        t('Next will not leave a step whose required field is empty',
          str_starts_with((string)($g['labelWhenBlocked'] ?? ''), '3 of 4'),
          $g['labelWhenBlocked'] ?? null);

        t("the phone's own Back button walks back a step",
          str_starts_with((string)($g['afterHardwareBack'] ?? ''), '3 of 4'),
          $g['afterHardwareBack'] ?? null);
        t('and does not leave the page on that first press',
          ($g['stillOnPage'] ?? null) === true);
        /* Pressing Back enough times must eventually get out. If popstate
           pushed its own entry, every press would pop one and add one and
           the user would be stuck on the page for ever. */
        t('and pressing Back enough times does get out, rather than trapping you',
          ($g['escapedEventually'] ?? null) === true, $g['backWalk'] ?? null);

        t('the form submits, carrying fields from every step',
          str_contains((string)($g['submittedUrl'] ?? ''), 'done.html')
          && str_contains((string)($g['submittedUrl'] ?? ''), 'opt=two')
          && str_contains((string)($g['submittedUrl'] ?? ''), 'need=three'),
          $g['submittedUrl'] ?? null);

        /* The one that would otherwise be a silent dead form. */
        t('a required field left empty on a hidden step opens that step',
          str_starts_with((string)($g['revealedStep'] ?? ''), '3 of 4'),
          $g['revealedStep'] ?? null);
        t('and the field is actually on screen to be filled in',
          ($g['requiredVisible'] ?? null) === true);
        t('and nothing was submitted behind the scenes',
          ($g['didNotSubmit'] ?? null) === true);
    }
}

/* ============================================ 4. the packing screen uses it */
head('4. The packing screen is stepped, all three tabs');

$mp  = (string)file_get_contents($B . 'm_pack.php');
$mpN = nocomments($mp);
t('the serial tab steps through the ranges',
  str_contains($mpN, "mob_steps_begin('serialsteps')"));
t('the weight tab is stepped',
  str_contains($mpN, "mob_steps_begin('weightsteps')"));
t('the approve tab is stepped',
  str_contains($mpN, "mob_steps_begin('approvesteps')"));
t('every steps_begin is closed',
  substr_count($mpN, 'mob_steps_begin(') === substr_count($mpN, 'mob_steps_end('),
  [substr_count($mpN, 'mob_steps_begin('), substr_count($mpN, 'mob_steps_end(')]);
t('a single-size range gets no "which size" step to choose from one thing',
  preg_match('~count\(\$sizes\) > 1\):.*?mob_step\(\x27Which size\x27~s', $mpN) === 1);
t('the recall button moved inside the one form, since a nested form is invalid',
  str_contains($mpN, 'name="recall" value="1"')
  && !preg_match('~<form[^>]*>(?:(?!</form>).)*<form~s', $mpN),
  'a form is nested inside another form');
t('and the handler matches it on its own name, not on a duplicate action',
  str_contains($mpN, "isset(\$_POST['recall'])")
  && str_contains($mpN, "\$action === 'weight' && !isset(\$_POST['recall'])"));

head('5. The gate form is stepped too, with the picker left out of it');

$mg  = (string)file_get_contents($B . 'm_gate.php');
$mgN = nocomments($mg);
t('the gate form is stepped',  str_contains($mgN, "mob_steps_begin('gatesteps')"));
t('and closed once',
  substr_count($mgN, 'mob_steps_begin(') === 1 && substr_count($mgN, 'mob_steps_end(') === 1,
  [substr_count($mgN, 'mob_steps_begin('), substr_count($mgN, 'mob_steps_end(')]);
/* mob_step( and mob_steps_begin( do not overlap — the bracket comes
   straight after "step" in one and not in the other — so this is a
   plain count, not a subtraction. The subtraction that used to be here
   only ever came out right because the broken comment stripper had
   eaten one of the calls. */
t('five screens: the pass, the contract, the items, the photo, remarks',
  substr_count($mgN, 'mob_step(') === 5, substr_count($mgN, 'mob_step('));

/* The steps must sit INSIDE the form, or fields on a hidden step would
   not post; and the picker must sit OUTSIDE, or the overlay becomes a
   step and the item box opens nothing. */
$fOpen  = strpos($mgN, '<form method="post" id="gf"');
$begin  = strpos($mgN, "mob_steps_begin('gatesteps')");
$end    = strpos($mgN, 'mob_steps_end()', (int)$begin);
$fClose = strpos($mgN, '</form>', (int)$end);
$picker = strpos($mgN, 'id="pick"');
t('the steps open inside the form',
  $fOpen !== false && $begin !== false && $fOpen < $begin, [$fOpen, $begin]);
t('and close before it does, so every step still posts',
  $end !== false && $fClose !== false && $end < $fClose, [$end, $fClose]);
t('the full-screen item picker is left outside the steps',
  $picker !== false && $picker > (int)$end, [$end, $picker]);
t('the picker is still an overlay, not something a step can hide',
  preg_match('~id="pick"[^>]*position:fixed~', $mgN) === 1);

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
