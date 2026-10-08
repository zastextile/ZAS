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

function mob_header(string $title, string $back = '', string $sub = ''): void
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
<link rel="manifest" href="manifest_gate.json">
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
