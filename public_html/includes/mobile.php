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

/* ========================================================= the mobile door

   THE LIST OF PHONE SCREENS, IN ONE PLACE.

   A phone user should not have to walk through the desktop app to reach
   the one screen they are allowed to use. m.php is their whole
   application: the same login, the same password, the same permissions —
   and nothing on it except the screens that login may open.

   Every phone screen is one row here. Adding the next one is one line,
   and it appears on the home page, in the installed app, and nowhere a
   user without the permission can see it.

   WHY function_exists AROUND inv_perm. The gate permission lives in
   includes/inventory.php. m.php requires that file, so the guard is not
   what makes this work — it is only here so a part-uploaded copy of the
   app shows fewer tiles instead of a blank error page. If the gate tiles
   ever go missing, the missing require is the first thing to look at. */
function mob_screens(): array
{
    $gate    = function_exists('inv_perm') && inv_perm('gate');
    $notProd = !(function_exists('is_production_staff') && is_production_staff());

    $all = [
        ['key' => 'gate_in', 'label' => 'Gate In', 'sub' => 'Record what came in',
         'href' => 'm_gate.php?dir=in',  'show' => $gate, 'tone' => 'in'],
        ['key' => 'gate_out', 'label' => 'Gate Out', 'sub' => 'Record what went out',
         'href' => 'm_gate.php?dir=out', 'show' => $gate, 'tone' => 'out'],
        ['key' => 'packing', 'label' => 'Packing', 'sub' => 'Serial, sizes and weight',
         'href' => 'm_pack.php',         'show' => $notProd, 'tone' => 'pack'],
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
