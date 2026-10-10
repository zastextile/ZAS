<?php
/*
  THE MOBILE SHELL.

  A second, phone-shaped surface onto the same database. Not a different
  application and not a copy: a mobile screen writes the same tables the
  desktop writes, with the same permissions and the same CSRF, and the
  desktop goes on being the place where a document is finished.

  This file exists so the next mobile screen is cheap. Everything here is
  layout: a header, a flash message, a footer, and one stylesheet. No
  queries, no business rules.

  WHY NOT THE NORMAL LAYOUT. layout.php draws the sidebar, the search bar
  and the full menu — three hundred pixels of furniture that a gatekeeper
  holding a phone in one hand does not want and cannot use. This gives the
  whole screen to the job.

  WHY NOT A FRAMEWORK. The same reason as everywhere else in this app:
  nothing here needs one. It is a form.
*/

/* $manifest is the only reason this signature grew. Each phone screen is
   its own installed app on the home screen — Gate and Packing are used by
   different people and want different names and start pages — and a PWA
   gets that from its manifest. It defaults to the gate's so every screen
   written before this one keeps working untouched. */
function mob_header(string $title, string $back = '', string $sub = '',
                    string $manifest = 'manifest_gate.json'): void
{
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<!-- viewport-fit=cover so the page reaches under the notch, and the bars
     below add the safe-area inset back for themselves. -->
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b2a4a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="<?= e($manifest) ?>">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<title><?= e($title) ?></title>
<style>
/* Phone first and phone only. Nothing here is asked to work at 1400px —
   the desktop screens already do that job properly. */
:root{
  --navy:#0b2a4a; --cyan:#0ea8c9; --violet:#6d5bd0;
  --ink:#152033; --muted:#5a6b82; --faint:#8a97ab;
  --line:#e3e9f2; --bg:#eef1f6; --card:#fff;
  --good:#16a34a; --warn:#d97706; --bad:#b8283f;
  --safe-t:env(safe-area-inset-top,0px); --safe-b:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
/* hidden only gives display:none as a browser DEFAULT, which any inline
   display: beats. The full-screen item picker sets display:flex inline, so
   without this it was visible permanently — covering the whole form, with
   nothing on the page reachable. Made !important so the attribute always
   wins, whatever a layout style says. */
[hidden]{display:none!important}
html,body{margin:0;padding:0;background:var(--bg);color:var(--ink);
  font:15px/1.5 "Inter","Segoe UI",system-ui,-apple-system,sans-serif}
/* 16px is the smallest size iOS will not zoom into on focus. Anything
   smaller on an input makes the whole page jump when it is tapped. */
input,select,textarea,button{font:inherit;font-size:16px}

.mh{position:sticky;top:0;z-index:30;background:var(--navy);color:#fff;
  padding:calc(10px + var(--safe-t)) 14px 10px;display:flex;align-items:center;gap:12px}
.mh a.bk{color:#9ec9e8;text-decoration:none;font-size:22px;line-height:1;padding:4px 2px}
.mh h1{font-size:16px;margin:0;font-weight:700;letter-spacing:-.1px;flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mh .sub{font-size:11px;color:#9ec9e8;font-weight:500;display:block;margin-top:1px}
.mh .who{font-size:11px;color:#9ec9e8;white-space:nowrap}

.wrap{padding:14px 14px calc(26px + var(--safe-b));max-width:620px;margin:0 auto}

.mcard{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:12px}
.mcard h2{font-size:14px;margin:0 0 10px}

label.f{display:block;margin-bottom:12px}
label.f > span{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;
  color:var(--faint);font-weight:700;margin-bottom:5px}
label.f > span .req{color:var(--bad)}
.in{width:100%;padding:12px 13px;border:1px solid #cbd5e3;border-radius:11px;background:#fff;color:var(--ink)}
.in:focus{outline:2px solid var(--cyan);outline-offset:-1px;border-color:var(--cyan)}
select.in{appearance:none;background-image:url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath fill='%235a6b82' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 13px center;background-size:12px;padding-right:36px}
textarea.in{min-height:72px;resize:vertical}

/* 48px minimum on anything tappable — a thumb is about that wide. */
.btn{display:block;width:100%;padding:15px;border:0;border-radius:13px;font-weight:700;
  font-size:16px;cursor:pointer;text-align:center;text-decoration:none;min-height:50px}
.btn.go{background:linear-gradient(100deg,var(--cyan),var(--violet));color:#fff}
.btn.sec{background:#fff;color:var(--navy);border:1px solid #cbd5e3}
.btn.red{background:#fff;color:var(--bad);border:1px solid rgba(184,40,63,.4)}
.btn:active{transform:scale(.99)}
.btn[disabled]{opacity:.55}

.pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
.pill.d{background:rgba(90,107,130,.14);color:var(--muted)}
.pill.v{background:rgba(14,168,201,.15);color:#0b7fa0}
.pill.p{background:rgba(22,163,74,.15);color:var(--good)}
.pill.w{background:rgba(217,119,6,.15);color:var(--warn)}

.flash{padding:12px 14px;border-radius:12px;font-size:13.5px;margin-bottom:12px;font-weight:600}
.flash.ok{background:rgba(22,163,74,.12);border:1px solid rgba(22,163,74,.34);color:#13702f}
.flash.no{background:rgba(184,40,63,.1);border:1px solid rgba(184,40,63,.34);color:#8e1f31}

.note{font-size:12px;color:var(--muted);line-height:1.55}
.empty{text-align:center;color:var(--faint);font-size:13.5px;padding:26px 10px}

/* Shown only when the browser says the connection is gone. The page needs
   the network — it writes to the server — so it says so rather than
   letting someone fill a form that cannot be saved. */
#offline{display:none;position:fixed;left:0;right:0;bottom:0;z-index:50;
  background:var(--bad);color:#fff;text-align:center;font-size:13px;font-weight:700;
  padding:11px 14px calc(11px + var(--safe-b))}
body.is-offline #offline{display:block}
body.is-offline .btn.go{opacity:.5;pointer-events:none}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}

/* ===================================================== one screen at a time

   A phone screen full of cards means thumbing past four things to reach
   the fifth and losing your place every time the page reloads. These
   turn a page into steps: one on screen, Back and Next at the bottom
   where the thumb already is.

   NOTHING EVER MOVES SIDEWAYS. The step that arrives rises from below
   and fades in — translateY only, never translateX — and both the step
   container and the page body refuse horizontal overflow. A sideways
   swipe on a form is how a half-typed figure gets lost.

   overflow-x is set here and NOT on html or body: an overflow on an
   ancestor silently kills position:sticky, and the header and the step
   bar both depend on it. */
.msteps{overflow-x:hidden;max-width:100%}
.mstep{display:none}
/* scroll mode: every section shown, one under the other; a link to a
   section stops below the sticky header rather than under it */
.msteps.scroll .mstep{display:block;margin-bottom:22px;scroll-margin-top:72px}
.msteps.scroll .mstep.on{animation:none}
.mstep.on{display:block;animation:mstepin .22s ease-out}
@keyframes mstepin{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.mstep.on{animation:none}}

.mprog{display:flex;align-items:center;gap:9px;margin:0 0 13px}
.mprog .bars{display:flex;gap:4px;flex:1;min-width:0}
.mprog .bars i{flex:1;height:4px;border-radius:3px;background:#d7dfea;transition:background .2s}
.mprog .bars i.done{background:var(--cyan)}
.mprog .cnt{font-size:11.5px;font-weight:700;color:var(--faint);white-space:nowrap}
.mstep > h2.sh{font-size:17px;margin:0 0 3px;letter-spacing:-.2px}
.mstep > p.ss{font-size:12.5px;color:var(--faint);margin:0 0 13px}

.mnav{position:sticky;bottom:0;z-index:20;display:flex;gap:10px;
  margin:16px -14px 0;padding:11px 14px calc(11px + var(--safe-b));
  background:var(--bg);border-top:1px solid var(--line)}
.mnav button{flex:1;min-height:50px;padding:14px;border:0;border-radius:13px;
  font-weight:700;font-size:16px;cursor:pointer}
.mnav .nb{flex:0 0 34%;background:#fff;color:var(--navy);border:1px solid #cbd5e3}
.mnav .nn{background:linear-gradient(100deg,var(--cyan),var(--violet));color:#fff}
.mnav button[disabled]{opacity:.4}
</style>
</head>
<body>
<div class="mh">
  <?php if ($back !== ''): ?><a class="bk" href="<?= e($back) ?>" aria-label="Back">&#8249;</a><?php endif; ?>
  <h1><?= e($title) ?><?php if ($sub !== ''): ?><span class="sub"><?= e($sub) ?></span><?php endif; ?></h1>
  <span class="who"><?= e((string)($u['name'] ?? $u['email'] ?? '')) ?></span>
</div>
<div class="wrap">
<?php
}

function mob_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        echo '<div class="flash ok">' . e((string)$_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    if (!empty($_SESSION['error'])) {
        echo '<div class="flash no">' . e((string)$_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
}

function mob_footer(): void
{
    ?>
</div>
<div id="offline" role="status">No internet — this page cannot save until the signal is back.</div>
<script>
/* The one piece of script on the page. The service worker deliberately
   never caches a .php page, so with no signal a save would simply fail;
   this says so before the form is filled in rather than after. */
(function () {
  function sync() { document.body.classList.toggle('is-offline', !navigator.onLine); }
  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  }
})();
</script>
</body>
</html>
<?php
}

/* ==================================================== one screen at a time

   USE
     mob_steps_begin();
     mob_step('Serial', 'Where this run of cartons starts');
         ... anything ...
     mob_step('Quantity');
         ... anything ...
     mob_steps_end();

   Each step is one screenful. Back and Next sit at the bottom. The last
   step shows no Next — the page puts its own Save or Approve button
   there, so the thing that writes to the database is always a button the
   page wrote, never one the shell guessed at.

   A FORM MAY SPAN STEPS. Hidden steps are display:none, not detached, so
   every field still posts. That creates one trap worth knowing about: a
   required field on a step you cannot see makes the browser refuse to
   submit AND refuse to say why — "an invalid form control is not
   focusable", in the console, where nobody is looking. The script below
   listens for the invalid event, opens whichever step the field is on,
   and lets the browser point at it. */
/* WHEN A FILE WAS NOT UPLOADED, SAY SO.
   These screens are uploaded by hand, a few files at a time, and the
   failure mode is silent: a page calls a helper that this copy of
   mobile.php does not have yet, PHP stops mid-page with display_errors
   off, and what reaches the phone is a header, a tab bar and then
   nothing. It looks like the data vanished.

   So each screen says up front which helpers it needs, and if any is
   missing it gets a plain page naming the file to upload instead of
   half a screen. */
/* AND WHEN IT DIES ANYWAY, SHOW THE BOSS WHY.
   display_errors is off on the live server, and rightly so, but the
   result is that any fatal on these screens reaches the phone as a
   header and then blank space — indistinguishable from "the data is
   gone". This prints the error, to an admin only, at the bottom of
   whatever did render.

   ADMIN ONLY, DELIBERATELY. A PHP error names file paths and sometimes
   the query, which is nobody else's business. */
function mob_show_fatal(): void
{
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        if (!function_exists('is_admin') || !is_admin()) return;
        $esc = static fn($t) => htmlspecialchars((string)$t, ENT_QUOTES);
        /* IT MUST BE SEEN WHEREVER THE PAGE STOPPED. The first version of
           this printed the box in place — and the page had stopped inside
           a hidden step, so the box was hidden with it and the screen was
           exactly as blank as before. Now it is fixed to the viewport and
           a script lifts it out to <body>; scripts run even inside a
           container that is display:none. */
        echo '<div id="mob-fatal" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;'
           . 'padding:16px;border-radius:12px;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.25);'
           . 'border:1px solid #e3e9f2;border-left:4px solid #b8283f;font:13px/1.6 ui-monospace,monospace;'
           . 'color:#152033;max-height:60vh;overflow:auto">'
           . '<b style="font:700 15px/1.4 system-ui,sans-serif">This screen stopped here</b><br>'
           . $esc($e['message']) . '<br><span style="color:#5a6b82">'
           . $esc(basename((string)$e['file'])) . ' line ' . (int)$e['line'] . '</span></div>'
           . '<script>(function(){var b=document.getElementById("mob-fatal");'
           . 'if(b&&document.body)document.body.appendChild(b);})();</script>';
    });
}

/* A card that could not be drawn, said plainly. Everyone sees that it
   failed and that the rest of the page still works; only an admin sees
   why, because the reason can name a file or a query. */
function mob_card_error(string $what, Throwable $e): void
{
    $esc = static fn($t) => htmlspecialchars((string)$t, ENT_QUOTES);
    $admin = function_exists('is_admin') && is_admin();
    echo '<div class="mcard" style="border-left:4px solid #b8283f">'
       . '<b>' . $esc($what) . ' could not be drawn.</b>'
       . '<div class="note" style="margin-top:6px">Everything else on this page still works. '
       . ($admin ? 'The reason is below — send it to whoever looks after the system.'
                 : 'Ask an admin to open this page; they will see why.')
       . '</div>';
    if ($admin) {
        echo '<div style="margin-top:10px;font:12.5px/1.6 ui-monospace,monospace;color:#152033;'
           . 'background:#f6f8fb;border:1px solid #e3e9f2;border-radius:9px;padding:10px;'
           . 'overflow-wrap:anywhere">'
           . $esc(get_class($e)) . ': ' . $esc($e->getMessage()) . '<br>'
           . '<span style="color:#5a6b82">' . $esc(basename($e->getFile())) . ' line '
           . (int)$e->getLine() . '</span></div>';
    }
    echo '</div>';
}

function mob_needs(array $fns, string $file): void
{
    $missing = [];
    foreach ($fns as $f) if (!function_exists($f)) $missing[] = $f;
    if (!$missing) return;

    http_response_code(500);
    $esc = static fn($t) => htmlspecialchars((string)$t, ENT_QUOTES);
    echo '<!doctype html><html><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>One file is out of date</title><style>'
       . 'body{font:16px/1.6 system-ui,sans-serif;margin:0;background:#eef2f7;color:#152033}'
       . '.w{max-width:620px;margin:0 auto;padding:28px 18px}'
       . '.c{background:#fff;border:1px solid #e3e9f2;border-left:4px solid #b8283f;'
       . 'border-radius:14px;padding:20px}'
       . 'h1{font-size:19px;margin:0 0 10px}code{background:#f6f8fb;border:1px solid #e3e9f2;'
       . 'border-radius:6px;padding:2px 6px;font-size:14px}'
       . 'p{margin:10px 0}ul{margin:10px 0;padding-left:20px}</style></head><body><div class="w">'
       . '<div class="c"><h1>One file is out of date</h1>'
       . '<p>This screen needs a newer <code>' . $esc($file) . '</code> than the one on the '
       . 'server. Upload that file and reload — nothing is lost and no data has changed.</p>'
       . '<p>What it was looking for:</p><ul>';
    foreach ($missing as $m) echo '<li><code>' . $esc($m) . '</code></li>';
    echo '</ul></div></div></body></html>';
    exit;
}

/* SCROLL INSTEAD OF FLIP, for a screen that asks for it.

   "If you feel any problem because of the page flip instead of scroll,
    use scroll — I do not want any problem."

   On the packing screen the flip meant pressing Next past every range
   to reach the next one, every time. So a screen can switch the steps
   off: the same sections, one under the other, no Back/Next bar and no
   progress pips. Edit buttons and #sN links scroll to the section
   instead of flipping to it. Every caller of mob_step() stays exactly as
   it is — only how the steps are shown changes. */
function mob_steps_scroll(bool $on = true): void
{
    $GLOBALS['_mob_scroll'] = $on;
}

function mob_steps_begin(string $id = 'msteps'): void
{
    $GLOBALS['_mob_step_id']   = $id;
    $GLOBALS['_mob_step_open'] = false;
    $GLOBALS['_mob_step_n']    = 0;
    $scroll = !empty($GLOBALS['_mob_scroll']);
    echo '<div class="msteps' . ($scroll ? ' scroll' : '') . '" id="' . e($id) . '">' . "\n";
    if (!$scroll) {
        echo '  <div class="mprog" role="status" aria-live="polite">'
           . '<span class="bars"></span><span class="cnt"></span></div>' . "\n";
    }
    echo '  <div class="mstepwrap">' . "\n";
}

function mob_step(string $title = '', string $sub = ''): void
{
    if (!empty($GLOBALS['_mob_step_open'])) echo "  </section>\n";
    $n = (int)($GLOBALS['_mob_step_n'] ?? 0);
    $GLOBALS['_mob_step_n'] = $n + 1;
    echo '  <section class="mstep" id="s' . $n . '"'
       . ($title !== '' ? ' data-label="' . e($title) . '"' : '') . '>' . "\n";
    if ($title !== '') echo '    <h2 class="sh">' . e($title) . '</h2>' . "\n";
    if ($sub   !== '') echo '    <p class="ss">' . e($sub) . '</p>' . "\n";
    $GLOBALS['_mob_step_open'] = true;
}

function mob_steps_end(string $nextLabel = 'Next'): void
{
    if (!empty($GLOBALS['_mob_step_open'])) echo "  </section>\n";
    $GLOBALS['_mob_step_open'] = false;
    $id = (string)($GLOBALS['_mob_step_id'] ?? 'msteps');
    if (!empty($GLOBALS['_mob_scroll'])) {
        /* scroll mode: close up, and let Edit buttons scroll to a section */
        ?>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById(<?= json_encode($id) ?>);
  if (!box) return;
  box.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-mstep-go]') : null;
    if (!b) return;
    var t = document.getElementById('s' + (+b.getAttribute('data-mstep-go') || 0));
    if (t) { ev.preventDefault(); t.scrollIntoView({ block: 'start' }); }
  });
})();
</script>
        <?php
        return;
    }
    ?>
  </div>
  <div class="mnav">
    <button type="button" class="nb" data-mstep="back">Back</button>
    <button type="button" class="nn" data-mstep="next"><?= e($nextLabel) ?></button>
  </div>
</div>
<script>
(function () {
  /* By id, not document.currentScript.closest() — this script tag sits
     after the container closes, so closest() would find nothing and the
     steps would silently never start. */
  var box = document.getElementById(<?= json_encode($id) ?>);
  if (!box) return;
  var steps = [].slice.call(box.querySelectorAll('.mstep'));
  if (!steps.length) return;
  var bars = box.querySelector('.mprog .bars');
  var cnt  = box.querySelector('.mprog .cnt');
  var back = box.querySelector('[data-mstep="back"]');
  var next = box.querySelector('[data-mstep="next"]');
  var at = 0;

  steps.forEach(function () { bars.appendChild(document.createElement('i')); });
  var pips = [].slice.call(bars.children);
  if (steps.length < 2) box.querySelector('.mprog').hidden = true;

  function render(i, keepScroll) {
    at = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, n) { s.classList.toggle('on', n === at); });
    pips.forEach(function (p, n) { p.classList.toggle('done', n <= at); });
    var label = steps[at].getAttribute('data-label') || '';
    cnt.textContent = (at + 1) + ' of ' + steps.length + (label ? ' · ' + label : '');
    back.disabled = at === 0;
    /* The page owns the button that saves, so the shell stands down on
       the last step rather than offering a Next that does nothing. */
    next.hidden = at === steps.length - 1;
    if (!keepScroll) window.scrollTo(0, 0);
  }

  /* Only the step in front of the user is checked. Checking the whole
     form here would stop them on a field they cannot see. */
  function badField(scope) {
    var els = scope.querySelectorAll('input,select,textarea');
    for (var i = 0; i < els.length; i++) {
      if (els[i].willValidate && !els[i].checkValidity()) return els[i];
    }
    return null;
  }

  /* render() draws. go() draws AND records a history entry, so the
     phone's own Back button walks the steps. Keeping them apart matters:
     popstate must redraw without pushing, or going back would add a new
     entry each time and Back would never leave the page. */
  function go(i, keepScroll) {
    var was = at;
    render(i, keepScroll);
    if (at !== was && at > 0) history.pushState({ mstep: at }, '');
  }

  next.addEventListener('click', function () {
    var bad = badField(steps[at]);
    if (bad) { bad.reportValidity(); return; }
    go(at + 1);
  });
  back.addEventListener('click', function () { go(at - 1); });

  /* A button anywhere inside that names a step jumps straight to it —
     the summary's Edit, for one. data-mstep-go="2" is the third step. */
  box.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-mstep-go]') : null;
    if (!b) return;
    ev.preventDefault();
    go(+b.getAttribute('data-mstep-go') || 0);
  });

  /* A required field on a hidden step: the browser blocks the submit and
     says nothing the user can see. Open its step so it can be pointed at. */
  var reported = false;
  box.addEventListener('invalid', function (ev) {
    var st = ev.target.closest ? ev.target.closest('.mstep') : null;
    if (!st) return;
    var i = steps.indexOf(st);
    if (i < 0 || i === at) return;
    if (reported) return;
    reported = true;
    go(i);
    setTimeout(function () { reported = false; ev.target.reportValidity(); }, 0);
  }, true);

  /* The phone's own Back button steps back instead of leaving the page.
     Nothing is pushed for the first step, so Back from there still does
     what it always did and nobody is trapped. */
  window.addEventListener('popstate', function (e) {
    render(e.state && typeof e.state.mstep === 'number' ? e.state.mstep : 0, true);
  });

  /* "#s2" opens the third step — how Edit on the Approve tab lands on
     the right range of the serial tab. */
  var mh = /^#s(\d+)$/.exec(location.hash || '');
  render(mh ? +mh[1] : 0);
})();
</script>
    <?php
}

/* ========================================================= the mobile door

   THE LIST OF PHONE SCREENS, IN ONE PLACE.

   A phone user should not have to walk through the desktop app to reach
   the one screen they are allowed to use. m.php is their whole
   application: the same login, the same password, the same permissions —
   and nothing on it except the screens that login may open.

   Every phone screen is one row here. Adding the next one is one line,
   and it appears on the home page, in the installed app, and nowhere a
   user without the permission can see it.

   A TILE IS ONLY OFFERED WHEN IT LEADS SOMEWHERE. Not merely "is this
   screen allowed" but "will this person find anything behind it". A
   Packing tile shown to someone with no shipment assigned is a button
   that answers "No shipment is assigned to you", which is worse than no
   button at all. Each screen owns that question in its own file —
   inv_perm() for the gate, pack_may_use() for packing — so this list
   stays a list.

   WHY function_exists AROUND THEM. Those two live in inventory.php and
   packing.php. m.php requires both, so the guards are not what make this
   work — they are here so a part-uploaded copy of the app shows fewer
   tiles instead of a blank error page, and a tile whose own file is
   missing stays hidden rather than leading to a crash. If a tile ever
   goes missing for everyone, a missing require is the first thing to
   look at. */
function mob_screens(): array
{
    $gate = function_exists('inv_perm')      && inv_perm('gate');
    $pack = function_exists('pack_may_use')  && pack_may_use();

    $all = [
        ['key' => 'gate_in', 'label' => 'Gate In', 'sub' => 'Record what came in',
         'href' => 'm_gate.php?dir=in',  'show' => $gate, 'tone' => 'in'],
        ['key' => 'gate_out', 'label' => 'Gate Out', 'sub' => 'Record what went out',
         'href' => 'm_gate.php?dir=out', 'show' => $gate, 'tone' => 'out'],
        ['key' => 'packing', 'label' => 'Packing', 'sub' => 'Serial, sizes and weight',
         'href' => 'm_pack.php',         'show' => $pack, 'tone' => 'pack'],
    ];

    $out = [];
    foreach ($all as $s) if ($s['show']) $out[] = $s;
    return $out;
}

/* ====================================================== gate photographs

   Kept with the mobile shell rather than in storage.php because this is
   the only place that takes one, and storage.php is the export module's
   file. It reuses that module's R2 functions when they are loaded and
   falls back to this server's disk when they are not — so the photo works
   whether or not R2 has been switched on.
*/

const MOBPHOTO_MAX = 12582912;   /* 12 MB — a phone photo is 2-5 MB */
const MOBPHOTO_OK  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                      'png' => 'image/png',  'webp' => 'image/webp'];

function mob_photo_dir(): string {
    global $config;
    $d = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/') . '/gate';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/* Takes one uploaded file and stores it against a gate pass.
   Returns [ok, message]. Never throws — a photo that will not store must
   not lose the gate pass it belongs to. */
function mob_photo_store(int $gateId, array $file, string $caption = ''): array
{
    if ($gateId <= 0) return [false, 'No gate pass to attach to.'];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [false, ''];                 /* nothing chosen is not a failure */
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [false, 'That photo is too large.'];
    if ($err !== UPLOAD_ERR_OK) return [false, 'The photo did not upload. Try again.'];

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [false, 'The photo did not arrive.'];
    if ((int)($file['size'] ?? 0) > MOBPHOTO_MAX) return [false, 'That photo is larger than 12 MB.'];

    /* Trust the bytes, not the name. getimagesize() fails on anything that
       is not actually an image, which is the check that matters when the
       file came off a phone someone else was holding. */
    $info = @getimagesize($tmp);
    if ($info === false) return [false, 'That file is not a photo.'];
    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [false, 'Only JPG, PNG and WEBP photos can be attached.'];
    }

    $name = (string)($file['name'] ?? 'photo.jpg');
    $ext  = array_search($mime, MOBPHOTO_OK, true) ?: 'jpg';
    if ($ext === 'jpeg') $ext = 'jpg';
    /* Unguessable, and nothing from the phone's filename reaches the path. */
    $key  = 'gate/' . $gateId . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

    $driver = 'local';
    if (function_exists('exp_r2_configured') && exp_r2_configured()) {
        [$ok, $e2] = exp_r2_put($tmp, $key, $mime);
        if (!$ok) return [false, 'The photo could not be stored. ' . $e2];
        $driver = 'r2';
    } else {
        $dest = mob_photo_dir() . '/' . basename(str_replace('/', '_', $key));
        if (!@move_uploaded_file($tmp, $dest)) return [false, 'The photo could not be saved on the server.'];
        $key = 'gate/' . basename($dest);
    }

    try {
        db()->prepare("INSERT INTO inv_gate_photos
               (gate_id, storage_driver, storage_key, original_name, mime_type, file_size, caption, uploaded_by)
               VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$gateId, $driver, $key, mb_substr($name, 0, 255), $mime,
                       (int)($file['size'] ?? 0), mb_substr(trim($caption), 0, 190) ?: null,
                       (int)(current_user()['id'] ?? 0)]);
    } catch (Throwable $e) { return [false, 'The photo was stored but could not be recorded.']; }

    return [true, 'Photo attached.'];
}

/* PHP hands a multiple file input back inside out: five parallel arrays
   rather than a list of files. This turns it the right way round, and
   caps the count so one tap cannot post forty photographs. */
const MOBPHOTO_MAX_COUNT = 6;

function mob_photo_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !isset($f['name'])) return [];

    if (!is_array($f['name'])) {                       /* a single input */
        return ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ? [] : [$f];
    }

    $out = [];
    foreach (array_keys($f['name']) as $i) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'     => (string)($f['name'][$i] ?? ''),
            'type'     => (string)($f['type'][$i] ?? ''),
            'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
            'error'    => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($f['size'][$i] ?? 0),
        ];
        if (count($out) >= MOBPHOTO_MAX_COUNT) break;
    }
    return $out;
}

function mob_photos(int $gateId): array {
    try {
        $s = db()->prepare("SELECT * FROM inv_gate_photos WHERE gate_id=? ORDER BY id");
        $s->execute([$gateId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Sends the bytes. The caller checks permission first — this only knows
   how to read from the two places a photo can live. */
function mob_photo_send(array $p): void
{
    $mime = (string)($p['mime_type'] ?? 'image/jpeg');
    header('Content-Type: ' . (in_array($mime, ['image/jpeg','image/png','image/webp'], true) ? $mime : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    header('Cache-Control: private, max-age=3600');

    if (($p['storage_driver'] ?? 'local') === 'r2' && function_exists('exp_r2_stream')) {
        [$ok, $e] = exp_r2_stream((string)$p['storage_key']);
        if (!$ok) error_log('Gate photo read failed for ' . (int)$p['id'] . ': ' . $e);
        exit;
    }
    $path = mob_photo_dir() . '/' . basename((string)$p['storage_key']);
    if (!is_file($path)) { http_response_code(404); exit('Photo missing.'); }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
