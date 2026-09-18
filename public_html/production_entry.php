<?php
/*
  DAILY PRODUCTION ENTRY — the day sheet.
  =======================================

  This is the screen your data-entry team lives in, so it is built for typing,
  not clicking: one date at the top, then rows of
  ORDER · PART & OPERATION · WORKER · QTY, and the money worked out as you go.

  WHAT THE OPERATION BOX OFFERS IS THE WHOLE DESIGN.
  Choosing an order line tells the page exactly which parts that product is made
  of and which operations each part has — so the second box only ever lists real
  work, with what is left to do printed on every option. There is no way to pick
  a combination that does not exist, which removes most of the mistakes a free
  grid makes possible.

  THREE RULES, ENFORCED ON THE SERVER, NOT JUST HERE.

  1. STITCHING CANNOT EXCEED CUTTING. You cannot stitch pieces that were never
     cut. The number is real, so it refuses.
  2. EACH OPERATION HAS ITS OWN ALLOWANCE. Overlock and Singer are two jobs done
     to every piece; booking 150 on one must not eat the other's allowance.
  3. NOTHING SAVES UNTIL EVERY ROW PASSES. A half-saved day sheet is worse than
     a refused one — you cannot tell which half went in, and re-entering it
     double-books the half that did.

  THE RATE IS NEVER TAKEN FROM THE BROWSER. It is looked up on the server at the
  moment of saving and frozen onto the row.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
/* WHO MAY OPEN THIS SCREEN.
 *
 * production_staff MUST be here. These are the people the screen exists for —
 * the operators booking their own work. I had first guarded it with admin and
 * colleague only, which locked the floor out of the floor's own page. Caught by
 * a test, not by me.
 *
 * can_see_rates() is a SEPARATE question and is answered separately below: an
 * operator books quantities; whether they see the money is a different
 * permission, and conflating the two is how a rate ends up on a screen it
 * should not be on. */
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
/* Same separation as My Work: booking a quantity and seeing the wage are two
   different permissions, and the app already answers the second one. */
$showMoney = can_see_rates();
$date   = trim((string)($_GET['d'] ?? $_POST['entry_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$msg = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'book') {
        $rows = [];
        $items = $_POST['r_item'] ?? [];
        foreach ($items as $i => $_) {
            $rows[] = [
                'item_id'   => $_POST['r_item'][$i]   ?? 0,
                'op_id'     => $_POST['r_op'][$i]     ?? 0,
                /* WHICH TABLE op_id POINTS AT. A part operation, a product's
                   set job and an order line's own set job each number from 1,
                   so the id alone stopped being unique the moment set work
                   existed. Defaulting to 'part' means an older page left open
                   in a tab still books part work correctly. */
                'op_kind'   => $_POST['r_kind'][$i]   ?? 'part',
                'worker_id' => $_POST['r_worker'][$i] ?? 0,
                'qty'       => $_POST['r_qty'][$i]    ?? 0,
                'note'      => $_POST['r_note'][$i]   ?? '',
            ];
        }
        $r = zp_book($date, $rows, $userId);
        if ($r['ok']) {
            $_SESSION['zp_msg'] = $r['saved'] . ' line' . ($r['saved'] === 1 ? '' : 's') . ' booked'
                . ($showMoney ? ' — PKR ' . number_format($r['amount'], 2) . ' in wages.' : '.');
            redirect('production_entry.php?d=' . urlencode($date));
        }
        $errors = $r['errors'];

    } elseif ($a === 'cancel_entry') {
        $r = zp_cancel_entry((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = 'Entry cancelled. It stays on the list, marked, so the correction is visible.';
        else          $_SESSION['zp_err'] = $r['error'];
        redirect('production_entry.php?d=' . urlencode($date));
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['zp_err'])) { $errors[] = $_SESSION['zp_err']; unset($_SESSION['zp_err']); }

/* ------------------------------------------------------------------
   WHAT CAN BE BOOKED — built once, handed to the browser as data.
   ------------------------------------------------------------------
   The whole picker runs from this, so the options can never disagree with what
   the server will accept: both are computed from the same functions.
------------------------------------------------------------------- */
$lines   = zp_open_lines();
$prog    = zp_progress_map();
$workers = zp_workers(true);

/* AN ORDER LINE WHOSE SIZE DOES NOT RESOLVE IS NAMED, NOT HIDDEN.
   Its operations would all read "0 left", which looks exactly like finished
   work, and the day's wages for it would simply never be bookable with nothing
   on screen to explain it. Collected below and shown as a red banner. */
$SIZE_PROBLEMS = [];
/* ------------------------------------------------------------------
   THE WORK INDEX AND THE LEARNED DEFAULTS
   ------------------------------------------------------------------
   Both are built once here and handed to the browser as data, so the picker
   never makes a round trip. At 200-300 lines a day, a screen that waits for
   the server between keystrokes is unusable, and the team ends up writing the
   day on paper and entering it late.

   The index is built from the SAME functions the save uses, so the list can
   never offer something the server will refuse. */
$WORK = zp_work_index();
$HABIT = zp_worker_habit();

/* EACH WORKER CARRIES THE STAGES THEY ARE ALLOTTED, and the stage NAMES go
   into their search text too — so typing "packing" finds the packing hall even
   on the tab where no operation has been chosen yet. An empty list means every
   stage; that rule is stated once, in wFits() below.

   PREPARED HERE, NOT INSIDE THE <script> BLOCK. Everything in that block is
   JavaScript with <?= holes punched in it; a <?php ... ?> block of setup in
   the middle of it is PHP that the page's own test harness cannot lift out,
   and it broke that harness the first time I wrote it there. */
$wsMap = zp_worker_stage_map();
$stNm  = [];
foreach (zp_stage_all(false) as $s) $stNm[(int)$s['id']] = (string)$s['name'];

/* an unplannable line is reported, not hidden — collected above */
foreach ($lines as $l) {
    $bad = zp_size_problem($l);
    if ($bad !== '') $SIZE_PROBLEMS[] = $bad;
}

$today   = zp_entries(['date' => $date], 400);
$dayQty  = 0.0; $dayAmt = 0.0;
foreach ($today as $t) if ($t['status'] === 'active') { $dayQty += (float)$t['qty']; $dayAmt += (float)$t['amount']; }

/* ---- why the page might be empty, said plainly ---- */
$blockers = [];
if (!$lines) {
    $anyOrder = 0;
    try { $anyOrder = (int)db()->query("SELECT COUNT(*) FROM proforma_invoices WHERE production_enabled=1")->fetchColumn(); } catch (Throwable $e) {}
    if (!$anyOrder) $blockers[] = ['t' => 'No order is switched on for production yet.',
        'd' => 'Open a Proforma Invoice and turn production on for it. Nothing can be booked against an order the system has not been told to make.',
        'l' => 'proforma.php', 'lt' => 'Open Proforma Invoices'];
    else $blockers[] = ['t' => 'Orders are switched on, but none of their lines match a product.',
        'd' => 'A line is matched by its saved product link, or failing that by its name. Open Master Products and check the names line up.',
        'l' => 'product_master.php', 'lt' => 'Open Master Products'];
}
if ($lines && !$workers) $blockers[] = ['t' => 'No active workers.',
    'd' => 'A wage has to belong to somebody. Add the people on the machines first.',
    'l' => 'production_workers.php', 'lt' => 'Add workers'];
if ($lines && $workers) {
    if (!$WORK) $blockers[] = ['t' => 'These products have no parts with operations yet.',
        'd' => 'A product is made of parts, and a part carries its operations and rates. Define a part, then put it on the product.',
        'l' => 'part_library.php', 'lt' => 'Open Part Library'];
}

page_header('Daily Production Entry');
?>
<style>
.ze-wrap{max-width:1500px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}.zp-b.ok:hover{background:#12823b;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:6px 7px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tbody tr:hover{background:#fafcff}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.flash.bad ul{margin:7px 0 0;padding-left:19px;font-weight:500}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.cut{background:rgba(217,119,6,.13);color:#9a5710}
.pill.man{background:rgba(139,92,246,.14);color:#6d3fd4}
.pill.st{background:rgba(14,168,201,.14);color:#0b7f99}
.pill.canc{background:#fdeef1;color:#9c2740}
.left-big{font-variant-numeric:tabular-nums;font-weight:800}
.blocker{background:#fff6e8;border:1px solid #f3ddb8;border-radius:12px;padding:16px 18px;margin-bottom:14px}
.blocker b{display:block;font-size:14px;color:#8a5a10;margin-bottom:5px}
.blocker p{margin:0 0 11px;font-size:12.5px;color:#8a5a10;line-height:1.55}
#erows td{padding:5px 6px}
#erows .w-op{min-width:230px}.w-ord{min-width:230px}.w-wk{min-width:150px}
.tot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;
     padding:11px 14px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px;font-size:13px}
.tot b{font-size:17px;color:#152033;font-variant-numeric:tabular-nums}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.rowleft{font-size:11px;color:#8a97ab;white-space:nowrap}

/* ---- the two-tab picker ---- */
.ze-tabs{display:flex;gap:4px;background:#f4f7fb;border:1px solid #e2e9f1;border-radius:13px;
         padding:4px;margin-bottom:14px;overflow-x:auto}
.ze-tab{flex:1;min-width:168px;border:0;background:transparent;font-family:inherit;cursor:pointer;
        padding:9px 13px;border-radius:9px;text-align:left;color:#6b7a90}
.ze-tab:hover{color:#152033}
.ze-tab[aria-selected="true"]{background:#1d6ff2;color:#fff;box-shadow:0 2px 9px -4px #1d6ff2}
.ze-tab b{display:block;font-size:13px;font-weight:800;letter-spacing:-.01em}
.ze-tab span{display:block;font-size:10.5px;opacity:.8;margin-top:1px}

.ze-pick{margin-bottom:14px}
.ze-srch{position:relative}
.ze-in{width:100%;padding:12px 74px 12px 40px;border:1.5px solid #d9e0ea;border-radius:11px;
       background:#fbfcfe;color:#152033;font-family:inherit;font-size:15px;font-weight:500}
.ze-in:focus{outline:none;border-color:#1d6ff2;background:#fff;box-shadow:0 0 0 4px rgba(29,111,242,.12)}
.ze-srch svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:17px;height:17px;
             color:#9aa8ba;pointer-events:none}
.ze-cnt{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:11px;color:#9aa8ba;
        font-family:ui-monospace,monospace;pointer-events:none}
.ze-hint{margin:8px 2px 0;font-size:11px;color:#9aa8ba;display:flex;gap:13px;flex-wrap:wrap}
.ze-hint kbd{font-family:ui-monospace,monospace;font-size:10px;background:#f4f7fb;border:1px solid #e2e9f1;
             border-bottom-width:2px;border-radius:4px;padding:1px 4px;color:#38495f;margin-right:2px}

.ze-res{margin-top:10px;border:1px solid #e2e9f1;border-radius:11px;overflow:hidden;
        max-height:300px;overflow-y:auto;background:#fbfcfe}
.ze-res:empty{display:none}
.ze-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:11px;align-items:center;width:100%;
        padding:9px 13px;border:0;border-bottom:1px solid #eef2f7;background:transparent;cursor:pointer;
        text-align:left;font-family:inherit;color:#152033}
.ze-row:last-child{border-bottom:0}
.ze-row:hover{background:rgba(29,111,242,.07)}
.ze-row[data-cur="1"]{background:rgba(29,111,242,.11);box-shadow:inset 3px 0 0 #1d6ff2}
.ze-t{font-size:13px;font-weight:800;letter-spacing:-.01em;margin-bottom:1px}
.ze-s{font-size:11px;color:#6b7a90;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ze-s i{font-style:normal;color:#b6c0cf}
.ze-left{font-family:ui-monospace,monospace;font-size:12.5px;font-weight:800;color:#0d9488;text-align:right;white-space:nowrap}
.ze-left small{display:block;font-family:inherit;font-size:9.5px;color:#9aa8ba;font-weight:700;
               text-transform:uppercase;letter-spacing:.06em}
.ze-res mark{background:rgba(29,111,242,.16);color:#1558ad;border-radius:3px;padding:0 1px;font-weight:800}
.ze-none{padding:22px;text-align:center;color:#8a97ab;font-size:12.5px}
.ze-usual{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
          background:rgba(13,148,136,.13);color:#0b7f74;padding:1px 7px;border-radius:20px}

.ze-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;margin-top:11px;
         background:rgba(29,111,242,.08);border:1px solid #1d6ff2;border-radius:11px;padding:11px 13px}
.ze-head h3{margin:0 0 2px;font-size:14.5px;font-weight:800;letter-spacing:-.015em;color:#152033}
.ze-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.ze-chip{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;background:#fff;
         border:1px solid #e2e9f1;color:#38495f}

.ze-stash{border:1px solid #e2e9f1;border-radius:11px;margin-top:12px;overflow:hidden}
.ze-stash h4{margin:0;padding:9px 13px;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;
             color:#6b7a90;background:#f4f7fb;border-bottom:1px solid #e2e9f1;font-weight:800}
.ze-sl{display:flex;justify-content:space-between;align-items:center;gap:11px;padding:7px 13px;
       border-bottom:1px solid #f1f4f9;font-size:12.5px}
.ze-sl:last-child{border-bottom:0}
.ze-sl b{font-weight:700}
.ze-sl .x{border:1px solid #e2e9f1;background:#fff;color:#b6c0cf;width:26px;height:26px;border-radius:7px;
          cursor:pointer;font-size:14px;line-height:1;flex:none}
.ze-sl .x:hover{border-color:#cf3350;color:#cf3350;background:#fdeef1}
.over{border-color:#cf3350 !important;box-shadow:0 0 0 3px rgba(207,51,80,.14) !important}

/* THE IN-ROW LIST IS FIXED TO THE VIEWPORT, NOT PARKED IN THE CELL.
   The grid sits inside <div style="overflow-x:auto">, and any overflow other
   than visible turns that div into a scroll container that clips absolutely
   positioned children — and CSS computes overflow-y as auto whenever
   overflow-x is auto, so it clipped vertically too. The list showed one row
   and half of the next, with a scrollbar of its own. It now lives on <body>
   and is placed against the input by script. */
.zesug{position:fixed;z-index:120;background:#fff;overflow-y:auto;
       box-shadow:0 12px 34px rgba(21,32,51,.18)}
/* the running ceiling, beside the row hints */
.ze-cap{font-size:11px;color:#8a97ab;font-family:ui-monospace,monospace}
.ze-cap.bad{color:#cf3350;font-weight:800}
</style>

<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so this one attribute is the whole of the restyle. The page
         keeps its own class names — .zp-card, .zp-t, .zp-b and the .ze-*
         picker — and the skin maps onto them. */ ?>
<div class="zskin">
<div class="ze-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Daily Production Entry</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        Log what was cut and stitched today, and by whom. The wage follows automatically.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="zp-b" href="production_workers.php">Workers</a>
      <a class="zp-b" href="part_library.php">Part Library</a>
      <span class="code" style="font-size:10px;color:#a7b2c4">build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="flash bad">
      <b>Nothing was saved.</b> Every line has to be right before any of them go in &mdash; a half-saved
      sheet cannot be told apart from a whole one.
      <ul><?php foreach ($errors as $e2): ?><li><?= e($e2) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($SIZE_PROBLEMS): ?>
    <div class="flash bad">
      <b><?= count($SIZE_PROBLEMS) ?> order line<?= count($SIZE_PROBLEMS) === 1 ? '' : 's' ?>
         cannot be produced until the size is linked.</b>
      Pieces-per-set depends on the size &mdash; a Double taking two pillow cases needs two per set, not one.
      Until the size is linked, the pieces needed cannot be worked out, so these lines are left out rather
      than planned at a number that would be too low.
      <ul><?php foreach (array_unique($SIZE_PROBLEMS) as $sp): ?>
        <li><?= e($sp) ?></li><?php endforeach; ?></ul>
      <div style="margin-top:8px;font-weight:500">
        Open the proforma, pick the size on the line, save. This screen picks it up straight away.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($blockers as $b): ?>
    <div class="blocker">
      <b><?= e($b['t']) ?></b>
      <p><?= e($b['d']) ?></p>
      <a class="zp-b pri" href="<?= e($b['l']) ?>"><?= e($b['lt']) ?></a>
    </div>
  <?php endforeach; ?>

  <?php if (!$blockers): ?>
  <form method="post" id="bookForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="book">

    <div class="zp-card">
      <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <div style="max-width:180px">
          <span class="lab">Date of production</span>
          <input class="zin" type="date" name="entry_date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>"
                 onchange="location.href='production_entry.php?d='+encodeURIComponent(this.value)">
        </div>
        <span style="font-size:12px;color:#8a97ab;padding-bottom:8px">
          <?= $date === date('Y-m-d') ? 'Today.' : 'Booking for ' . e(date('D j M Y', strtotime($date))) . '.' ?>
          A future date is refused &mdash; wages cannot be earned before the work happens.</span>
      </div>

      <?php /* TWO WAYS IN, BECAUSE THE DAY ARRIVES TWO WAYS.
               Sometimes it is one operation and the six people who worked on
               it; sometimes it is one person and everything they did. Forcing
               either shape through the other doubles the typing. */ ?>
      <div class="ze-tabs" role="tablist">
        <button type="button" class="ze-tab" role="tab" aria-selected="true" id="tabA" onclick="zeTab('A')">
          <b>By operation</b><span>one job &rarr; many workers</span></button>
        <button type="button" class="ze-tab" role="tab" aria-selected="false" id="tabB" onclick="zeTab('B')">
          <b>By worker</b><span>one person &rarr; everything they did</span></button>
      </div>

      <div class="ze-pick">
        <div class="ze-srch">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
            <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
          <input class="ze-in" id="zeQ" autocomplete="off" spellcheck="false">
          <span class="ze-cnt" id="zeCnt"></span>
        </div>
        <div class="ze-hint">
          <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> move</span><span><kbd>Enter</kbd> choose</span>
          <span><kbd>Esc</kbd> clear</span><span><kbd>Ctrl</kbd>+<kbd>S</kbd> save the whole sheet</span>
        </div>
        <div class="ze-res" id="zeRes"></div>
        <div id="zeHead"></div>
      </div>

      <div id="zeGrid" hidden>
        <div style="overflow-x:auto">
        <table class="zp-t" id="etable" style="min-width:760px">
          <thead><tr id="zeHrow"></tr></thead>
          <tbody id="erows"></tbody>
        </table>
        </div>
        <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:11px">
          <button type="button" class="zp-b" onclick="zeAddRow()">+ Add line</button>
          <button type="button" class="zp-b" onclick="zeStash()">Done &mdash; next operation</button>
          <span style="font-size:11px;color:#8a97ab;font-family:ui-monospace,monospace">
            Enter on Qty starts the next line</span>
          <?php /* THE CEILING, WHILE YOU FILL IT — not only once you have gone
                   past it. The red block below still explains a refusal, but by
                   then the typing is already done; this counts up as you go so
                   the limit is visible before it is hit. */ ?>
          <span class="ze-cap" id="zeCap" style="margin-left:auto"></span>
        </div>
      </div>

      <?php /* THE SHEET. Everything picked so far, from either tab, waiting to
               be written in one go. Held in hidden inputs so the POST is an
               ordinary form post that the existing, unchanged save handler
               reads exactly as it always did. */ ?>
      <div id="zeSheet"></div>

      <div class="tot">
        <span>Today's sheet &mdash; <span id="rowCount">0</span> line(s)</span>
        <span><?= $showMoney ? 'PKR <b id="sheetTotal">0.00</b>' : '<b id="sheetTotal" hidden></b>' ?></span>
      </div>

      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:13px">
        <button class="zp-b ok" id="zeSave" style="padding:9px 20px;font-size:13.5px">Save the day's work</button>
      </div>

      <div class="note info" style="margin-top:13px">
        <b>One list, and a row in it is the whole job.</b> Order, customer, product, size, part, operation and
        rate &mdash; type any part of any of it and the list shortens. Pressing Enter fills all of it in, so the
        only thing left to type is the quantity. <b>Workers are listed by what they usually do</b>, learned from
        what was really booked, but nobody is locked to anything.
      </div>
    </div>
  </form>
  <?php endif; ?>

  <div class="zp-card">
    <h2>Booked on <?= e(date('D j M Y', strtotime($date))) ?></h2>
    <?php if (!$today): ?>
      <div class="empty">Nothing booked for this date yet.</div>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr>
          <th style="width:36px">#</th><th>Worker</th><th>Product</th><th>Part &amp; operation</th>
          <th style="width:110px">Stage</th><th class="num" style="width:80px">Qty</th>
          <?php /* BOOKING A QUANTITY AND SEEING THE WAGE ARE TWO DIFFERENT PERMISSIONS.
                   This table printed both columns to anybody who could open the page,
                   so an operator not allowed to see rates saw every rate on the floor.
                   The entry grid above already respected can_see_rates(); this did not. */ ?>
          <?php if ($showMoney): ?>
            <th class="num" style="width:80px">Rate</th><th class="num" style="width:100px">Amount</th>
          <?php endif; ?>
          <th style="width:120px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($today as $i => $t): $canc = $t['status'] !== 'active'; ?>
          <tr<?= $canc ? ' style="opacity:.55"' : '' ?>>
            <td class="code"><?= $i + 1 ?></td>
            <td><?= e($t['worker_name'] ?? '(removed worker)') ?>
                <div class="code" style="font-size:10px"><?= e($t['worker_code'] ?? '') ?></div></td>
            <td><?= e($t['product_name'] ?? '(removed product)') ?>
                <?php if ($t['pi_no']): ?><div class="code" style="font-size:10px"><?= e($t['pi_no']) ?></div><?php endif; ?></td>
            <td><?= e($t['part_name'] ?? '—') ?>
                <div class="code" style="font-size:10px"><?= e($t['operation_name'] ?? '(removed operation)') ?></div></td>
            <td><?php
              /* the colour follows POSITION, not a word: the stage that makes the
                 pieces is marked apart from the ones limited by it, whatever you
                 have chosen to call them */
              $cls = zp_is_cutting((string)$t['stage']) ? 'cut' : 'st';
              echo '<span class="pill ' . $cls . '">' . e($t['stage']) . '</span>';
              if ($canc) echo ' <span class="pill canc">cancelled</span>';
            ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$t['qty'], 2), '0'), '.') ?></td>
            <?php if ($showMoney): ?>
              <td class="num"><?= number_format((float)$t['rate_applied'], 2) ?></td>
              <td class="num"><?= number_format((float)$t['amount'], 2) ?></td>
            <?php endif; ?>
            <td>
              <?php if (!$canc): ?>
                <button type="button" class="zp-b sm red" onclick="zeCancel(<?= (int)$t['id'] ?>)">Cancel</button>
              <?php elseif (trim((string)$t['cancel_reason']) !== ''): ?>
                <span class="code" style="font-size:10px" title="<?= e($t['cancel_reason']) ?>">why?</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#f7f9fc">
          <td colspan="5" style="font-weight:800;font-size:12px">Day total (cancelled lines excluded)</td>
          <td class="num" style="font-weight:800"><?= rtrim(rtrim(number_format($dayQty, 2), '0'), '.') ?></td>
          <?php if ($showMoney): ?>
            <td></td>
            <td class="num" style="font-weight:800"><?= number_format($dayAmt, 2) ?></td>
          <?php endif; ?>
          <td></td>
        </tr></tfoot>
      </table>
      </div>

      <div class="note warn" style="margin-top:13px">
        <b>A cancelled line stays on the list.</b> It is marked, not removed, with the reason kept against it.
        Deleting it would make the day's total change with nothing on screen to explain why.
      </div>
    <?php endif; ?>
  </div>

  <form method="post" id="cancelForm" style="display:none">
    <?= csrf_field() ?><input type="hidden" name="action" value="cancel_entry">
    <input type="hidden" name="entry_date" value="<?= e($date) ?>">
    <input type="hidden" name="entry_id" value="">
    <input type="hidden" name="reason" value="">
  </form>
</div>

<script>
/* ====================================================================
   THE PICKER
   ====================================================================

   EVERYTHING RUNS IN THE BROWSER, ON DATA BUILT ONCE BY THE SERVER. At
   200-300 lines a day a screen that waits for the server between keystrokes is
   unusable, and the team ends up writing the day on paper and typing it in
   late — which is how the numbers stop being today's numbers.

   WHAT THE BROWSER DECIDES AND WHAT IT DOES NOT. It decides what to SHOW: the
   order of the list, what is left, the running total. It decides nothing about
   what is SAVED. Every line is re-checked by zp_book() on the server, which
   looks the rate up again and freezes it onto the row. A tampered page cannot
   book a wage this screen would not offer, and it cannot set its own rate.
   ==================================================================== */
(function(){
  var WORK    = <?= json_encode($WORK) ?>;
  var WORKERS = <?= json_encode(array_map(function ($w) use ($wsMap, $stNm) {
      $sg = $wsMap[(int)$w['id']] ?? [];
      $sn = [];
      foreach ($sg as $sid) if (isset($stNm[$sid])) $sn[] = $stNm[$sid];
      return [
        'id' => (int)$w['id'], 'code' => (string)$w['worker_code'], 'name' => (string)$w['worker_name'],
        'dept' => (string)($w['department'] ?? ''),
        'sg'  => array_values(array_map('intval', $sg)),
        'hay' => mb_strtolower(trim($w['worker_code'] . ' ' . $w['worker_name'] . ' '
                 . ($w['department'] ?? '') . ' ' . implode(' ', $sn))),
      ];
  }, $workers)) ?>;
  var HABIT   = <?= json_encode($HABIT) ?>;
  var MONEY   = <?= $showMoney ? 'true' : 'false' ?>;

  var tab    = 'A';      // A = pick the work first, B = pick the worker first
  var picked = null;     // the chosen WORK row, or the chosen worker
  var rows   = [];       // the lines being typed right now
  var sheet  = [];       // everything staged, from either tab, waiting for Save
  var cur    = 0;

  var $ = function(id){ return document.getElementById(id); };
  function esc(t){ return String(t==null?'':t).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (Math.round(v*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function qn(v){ var r = Math.round(v*100)/100; return r.toLocaleString('en-US'); }

  /* EVERY TYPED WORD MUST APPEAR SOMEWHERE. "1042 over" finds the Overlock
     lines on that order — which is how people actually remember a job, half
     the PO number and half the operation. Order of the words does not matter. */
  function terms(){ return $('zeQ').value.trim().toLowerCase().split(/\s+/).filter(Boolean); }
  function hit(hay, t){ for (var i=0;i<t.length;i++) if (hay.indexOf(t[i]) < 0) return false; return true; }

  /* The text is ESCAPED FIRST and marked with two characters that cannot occur
     in escaped HTML, so a part somebody named <script> is shown as text and
     never becomes one. */
  var M1 = '\u0001', M2 = '\u0002';
  function hl(text, t){
    var out = esc(text);
    t.forEach(function(w){
      if(!w) return;
      out = out.replace(new RegExp('('+w.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','ig'), M1+'$1'+M2);
    });
    return out.split(M1).join('<mark>').split(M2).join('</mark>');
  }

  /* ---- LEARNED DEFAULTS: how likely is this pairing, from real history ---- */
  function score(workerId, opId){
    var m = (HABIT.byWorker || {})[workerId];
    return m ? (+m[opId] || 0) : 0;
  }
  function usualOps(workerId){
    var m = (HABIT.byWorker || {})[workerId] || {}, best = 0;
    for (var k in m) if (m[k] > best) best = m[k];
    return best;
  }

  /* ============================================================
     ONE ROW, DRAWN IN TWO PLACES
     ------------------------------------------------------------
     This page used to carry TWO search lists written separately: the big one
     at the top, and a cut-down one inside each grid row. They were roughly
     the same code typed twice, and they drifted — the in-row one lost the
     match highlighting, lost the chips, and on the "By worker" tab lost the
     LEFT count, which is the number that stops somebody over-booking. You
     could pick a job in a row without ever seeing how much of it was still
     open.

     Now there is one option shape, one renderer and one painter. The top list
     and the in-row list differ only in WHERE they are mounted, so they cannot
     drift apart again.
     ============================================================ */

  /* a unit of work, described once — everything a person needs to decide */
  function zeWorkOpt(w, click, ev){
    /* THE ORDER NUMBER AND THE PRODUCT, and nothing else on that line.
       The customer name used to sit here too. On a floor list it earns
       nothing — the person booking work knows the style, not the buyer —
       and it pushed the product, which is what they DO recognise, off the
       end of a narrow row. Asked for directly. */
    return { v:w.k, click:click, ev:ev,
             title: w.pn + ' → ' + w.on, strong: w.pi,
             bits: [w.prod].filter(Boolean),
             chips:[w.size, w.st].filter(Boolean),
             rate: w.rate, left: w.left };
  }
  /* DOES THIS PERSON WORK THIS STAGE?
     Stated once, on the browser side, exactly as zp_worker_does_stage() states
     it on the server: NOTHING ALLOTTED MEANS EVERY STAGE. The table ships
     empty, so on day one this returns true for everybody and the screen
     behaves precisely as it did before the allotment existed. */
  function wFits(w, sid){
    if (!sid) return true;
    if (!w.sg || !w.sg.length) return true;
    return w.sg.indexOf(sid) >= 0;
  }

  /* a person, described once */
  function zeWorkerOpt(w, opId, click, ev){
    return { v:w.id, click:click, ev:ev,
             title: w.name, strong: w.code,
             bits: [w.dept].filter(Boolean), chips: [],
             usual: opId ? score(w.id, opId) > 0 : usualOps(w.id) > 0 };
  }

  function zeRowHtml(o, t, isCur){
    return '<button type="button" class="ze-row" data-cur="' + (isCur ? 1 : 0) + '"'
      /* the value is on the element as well as in the handler: the body-level
         panel reads it back on mousedown and on Enter, without having to parse
         an attribute or fake an event */
      + ' data-pick="' + esc(o.v) + '"'
      /* The top list keeps an inline onclick (zePick is index-based). The
         in-row list passes none: its panel sits on the body and handles the
         whole list in one listener, so an inline handler here would fire the
         pick TWICE. */
      + (o.click ? ' ' + (o.ev || 'onclick') + '="' + o.click + '"' : '')
      + '>'
      + '<div><div class="ze-t">' + hl(o.title, t) + '</div><div class="ze-s">'
      +   (o.strong ? '<b style="color:#38495f">' + hl(o.strong, t) + '</b><i>&middot;</i>' : '')
      +   o.bits.map(function(b){ return hl(b, t); }).join('<i>&middot;</i>')
      +   o.chips.map(function(c){ return '<span class="ze-chip">' + hl(c, t) + '</span>'; }).join('')
      +   (o.rate && MONEY ? '<span style="font-family:ui-monospace,monospace">' + n2(o.rate) + '/pc</span>' : '')
      +   (o.usual ? '<span class="ze-usual">usually</span>' : '')
      + '</div></div>'
      + (o.left != null ? '<div class="ze-left">' + qn(o.left) + '<small>left</small></div>'
                        : '<div class="ze-left" style="color:#b6c0cf">&rarr;</div>')
      + '</button>';
  }

  /* A LONG LIST IS DRAWN SHORT. Painting 1,200 rows on every keystroke is what
     makes a search feel heavy. The cut is SAID OUT LOUD, because a list that
     silently stops at eight looks like the other matches do not exist. */
  function zePaint(box, list, t, curIx, cap){
    cap = cap || 60;
    if (!list.length) { box.innerHTML = '<div class="ze-none">Nothing matches.</div>'; return; }
    var show = list.slice(0, cap);
    box.innerHTML = show.map(function(o, i){ return zeRowHtml(o, t, i === curIx); }).join('')
      + (list.length > show.length
         ? '<div class="ze-none">' + (list.length - show.length) + ' more &mdash; keep typing to narrow it.</div>'
         : '')
      /* A note the LIST carries about itself — today, "these are only the
         people on this stage". It is a plain div, not a .ze-row, so the arrow
         keys and Enter step straight past it and cannot pick it. */
      + (list.foot ? '<div class="ze-none">' + list.foot + '</div>' : '');
    var c = box.querySelector('[data-cur="1"]'); if (c) c.scrollIntoView({block:'nearest'});
  }

  function tabList(){
    var t = terms();
    /* only OPEN work is offered; finished rows are kept for the message below */
    if (tab === 'A') return WORK.filter(function(w){ return !w.done && hit(w.hay, t); });
    var list = WORKERS.filter(function(w){ return hit(w.hay, t); });
    /* the people who actually book work come first — an empty search on a
       floor of 200 should not open with whoever happened to be added first */
    return list.slice().sort(function(a,b){ return usualOps(b.id) - usualOps(a.id); });
  }

  function draw(){
    var box = $('zeRes'), t = terms();
    if (picked) { box.innerHTML=''; $('zeCnt').textContent=''; return; }
    var list = tabList();
    $('zeCnt').textContent = list.length + (list.length === 1 ? ' match' : ' matches');
    if (!list.length) {
      /* "NOTHING MATCHES" AND "THAT IS ALL DONE" ARE DIFFERENT ANSWERS.
         One is a typo to correct; the other is good news. Saying the first when
         the second is true sends somebody hunting for a job that is finished. */
      var fin = (tab === 'A') ? WORK.filter(function(w){ return w.done && hit(w.hay, t); }).length : 0;
      box.innerHTML = fin
        ? '<div class="ze-none"><b>' + fin + ' matching job' + (fin===1?' is':'s are')
          + ' already finished.</b><br>Nothing is left to book on '
          + (fin===1?'it':'them') + '.</div>'
        : '<div class="ze-none">Nothing matches &ldquo;' + esc($('zeQ').value) + '&rdquo;.</div>';
      return;
    }
    if (cur >= list.length) cur = list.length - 1;
    if (cur < 0) cur = 0;
    /* the SAME renderer the in-row list uses — see "ONE ROW, DRAWN IN TWO
       PLACES" above. zePick stays index-based, so the click carries the index. */
    zePaint(box, list.map(function(w, i){
      return tab === 'A' ? zeWorkOpt(w, 'zePick(' + i + ')', 'onclick')
                         : zeWorkerOpt(w, 0, 'zePick(' + i + ')', 'onclick');
    }), t, cur, 60);
  }

  window.zePick = function(i){
    var w = tabList()[i]; if (!w) return;
    picked = w;
    $('zeHead').innerHTML = (tab === 'A')
      ? '<div class="ze-head"><div><h3>' + esc(w.pn) + ' → ' + esc(w.on) + '</h3>'
        + '<div style="font-size:12px;color:#38495f"><b>' + esc(w.pi) + '</b> &middot; ' + esc(w.cust)
        + ' &middot; ' + esc(w.prod) + '</div><div class="ze-chips">'
        + (w.size ? '<span class="ze-chip">Size ' + esc(w.size) + '</span>' : '')
        + '<span class="ze-chip">' + esc(w.st) + '</span>'
        + (MONEY ? '<span class="ze-chip">' + n2(w.rate) + ' / pc</span>' : '')
        + '<span class="ze-chip" style="color:#0d9488;border-color:#0d9488">' + qn(w.left) + ' left</span>'
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>'
      : '<div class="ze-head"><div><h3>' + esc(w.name) + '</h3><div class="ze-chips">'
        + '<span class="ze-chip">' + esc(w.code) + '</span>'
        + (w.dept ? '<span class="ze-chip">' + esc(w.dept) + '</span>' : '')
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>';
    $('zeRes').innerHTML = ''; $('zeCnt').textContent = '';
    $('zeGrid').hidden = false;
    rows = [];
    zeAddRow();
  };

  window.zeReset = function(){
    picked = null; rows = []; cur = 0;
    $('zeQ').value = ''; $('zeHead').innerHTML = ''; $('zeGrid').hidden = true;
    grid(); draw(); $('zeQ').focus();
  };

  window.zeTab = function(t){
    tab = t;
    $('tabA').setAttribute('aria-selected', t === 'A');
    $('tabB').setAttribute('aria-selected', t === 'B');
    $('zeQ').placeholder = (t === 'A')
      ? 'Type an order, product, part or operation…'
      : 'Type a worker name, code or department…';
    zeReset();
  };

  /* ---- the grid of lines under whichever thing was picked ---- */
  window.zeAddRow = function(){
    rows.push({ v:'', label:'', q:'' });
    grid();
    var a = document.querySelectorAll('#erows .pk');
    if (a.length) a[a.length-1].focus();
  };
  window.zeDrop = function(i){ if (rows.length <= 1) return; rows.splice(i,1); grid(); };

  function other(v){        // what a row picks: a worker on tab A, a work row on tab B
    if (tab === 'A') { for (var i=0;i<WORKERS.length;i++) if (WORKERS[i].id === +v) return WORKERS[i]; }
    else             { for (var j=0;j<WORK.length;j++)    if (WORK[j].k === v)      return WORK[j]; }
    return null;
  }
  function rowRate(r){
    if (!r.v) return 0;
    return tab === 'A' ? (picked ? picked.rate : 0) : ((other(r.v) || {}).rate || 0);
  }
  function rowLeft(r){
    return tab === 'A' ? (picked ? picked.left : 0) : ((other(r.v) || {}).left || 0);
  }

  function grid(){
    $('zeHrow').innerHTML = '<th style="width:28px">#</th>'
      + '<th>' + (tab === 'A' ? 'Worker' : 'Order &middot; part &rarr; operation') + '</th>'
      + '<th class="num" style="width:96px">Qty</th>'
      + (MONEY ? '<th class="num" style="width:84px">Rate</th><th class="num" style="width:102px">Amount</th>' : '')
      + '<th style="width:34px"></th>';
    var html = '';
    rows.forEach(function(r,i){
      var rate = rowRate(r), amt = (parseFloat(r.q)||0) * rate;
      html += '<tr>'
        + '<td style="color:#b6c0cf;font-family:ui-monospace,monospace">' + (i+1) + '</td>'
        + '<td style="position:relative">'
        +   '<input class="zin pk" autocomplete="off" value="' + esc(r.label) + '"'
        +   ' placeholder="' + (tab === 'A' ? 'Type a name or code…' : 'Type an order, part or operation…') + '"'
        +   ' oninput="zeSug(' + i + ',this)" onfocus="zeSug(' + i + ',this)"'
        +   ' onkeydown="zeSugKey(event,' + i + ')" onblur="zeSugClose(' + i + ')">'
        /* NO POPUP LIVES IN HERE ANY MORE — see zeSugBox() below. The table is
           wrapped in overflow-x:auto, which makes that div a scroll container
           and clips anything absolutely positioned inside it. */
        + '</td>'
        + '<td><input class="zin num q' + (r.over ? ' over' : '') + '" inputmode="numeric" value="' + esc(r.q) + '"'
        +   ' placeholder="0" oninput="zeQty(' + i + ',this.value)" onkeydown="zeQtyKey(event,' + i + ')"></td>'
        + (MONEY ? '<td class="num" style="color:#8a97ab">' + (rate ? n2(rate) : '—') + '</td>'
                 + '<td class="num"><b>' + (amt ? n2(amt) : '—') + '</b></td>' : '')
        + '<td><button type="button" class="zp-b sm red" tabindex="-1" onclick="zeDrop(' + i + ')">&times;</button></td>'
        + '</tr>';
    });
    $('erows').innerHTML = html;
    totals();
  }

  /* ---- the in-row suggestion list, ordered by what this person usually does ----
     It builds the SAME options the top list does, so a work row in here now
     carries its customer, its stage, its rate and its LEFT count — all of
     which it used to drop, leaving you to pick a job on this tab without ever
     seeing how much of it was still open. */
  function sugList(i, text){
    var t = (text||'').trim().toLowerCase().split(/\s+/).filter(Boolean);
    /* No inline handler and so no hand-escaping of the value into a JS string
       either: the body-level panel reads data-pick, which esc() already made
       safe as an attribute. */
    var click = function(){ return ''; };
    if (tab === 'A') {
      var opId = picked ? picked.op : 0;
      /* THE STAGE NARROWS AN EMPTY BOX, AND ONLY AN EMPTY BOX.
         Three hundred names under the cursor is not a list. So with nothing
         typed, only the people allotted to this job's stage are offered — on
         a packing job that is twenty-six names instead of three hundred.
         The moment anything IS typed the search goes back over everybody,
         because typing a name means you have already decided who you want and
         no filter of mine should be able to say "that person is not here".
         Somebody standing in at another stage for a day is therefore always
         reachable, and the entry saves exactly as it always did. */
      var all = WORKERS.filter(function(w){ return hit(w.hay, t); });
      var sid = picked ? (picked.sid || 0) : 0;
      var use = all, narrowed = 0;
      if (sid && !t.length) {
        /* NARROW ONLY WHEN SOMEBODY IS REALLY ON THIS STAGE.
           Testing "is the short list non-empty" was not enough and the test
           caught it: workers allotted NOTHING pass wFits for every stage, so a
           stage nobody has been put on yet still produced a list — of just
           those few — and three hundred people vanished behind a footnote.
           So the count is of people who NAME this stage. None of them means
           the stage means nothing yet, and the whole floor is offered. */
        var onStage = 0;
        var fit = all.filter(function(w){
          if (w.sg && w.sg.length && w.sg.indexOf(sid) >= 0) onStage++;
          return wFits(w, sid);
        });
        if (onStage) { narrowed = all.length - fit.length; use = fit; }
      }
      var outw = use.slice().sort(function(a,b){ return score(b.id, opId) - score(a.id, opId); })
        .map(function(w){ return zeWorkerOpt(w, opId, click(), ''); });
      /* The cut is SAID, like every other cut on this screen. A list that
         silently stops short reads as "that person does not exist". */
      outw.foot = narrowed
        ? narrowed + ' more not on ' + esc(picked.st) + ' — type a name or code to reach them.'
        : '';
      return outw;
    }
    var wid = picked ? picked.id : 0;
    return WORK.filter(function(w){ return !w.done && hit(w.hay, t); })
      .slice().sort(function(a,b){ return score(wid, b.op) - score(wid, a.op); })
      .map(function(w){
        var o = zeWorkOpt(w, click(), '');
        o.usual = score(wid, w.op) > 0;
        return o;
      });
  }
  /* ============================================================
     THE SECOND LIST LIVES ON THE BODY, NOT IN THE ROW
     ------------------------------------------------------------
     It used to be an absolutely positioned div inside the picker cell. The
     grid is wrapped in <div style="overflow-x:auto">, and ANY overflow other
     than visible makes that div a scroll container which clips absolutely
     positioned descendants. Worse, CSS computes overflow-y as auto whenever
     overflow-x is auto — so the list was clipped vertically as well, and got
     its own scrollbar. You saw the top row and half of the second.

     One panel, hung off the page's outermost wrapper and placed against the
     input with getBoundingClientRect(). Nothing above it can clip it. This is
     the same thing lov.js already does on the proforma grid, for the same
     reason.
     ============================================================ */
  var sugRow = -1;
  function zeSugBox(){
    var b = $('zeSugBox');
    if (!b) {
      b = document.createElement('div');
      b.id = 'zeSugBox';
      b.className = 'ze-res zesug';
      b.hidden = true;
      /* mousedown, not click: it must fire before the input's blur closes it */
      b.addEventListener('mousedown', function(e){
        var row = e.target.closest ? e.target.closest('.ze-row') : null;
        if (!row) return;
        e.preventDefault();
        var v = row.getAttribute('data-pick');
        if (v !== null && sugRow >= 0) zeSugSet(sugRow, v);
      });
      /* MOUNTED ON THE .zskin WRAPPER, NOT ON <body>. Either one escapes the
         overflow-x:auto container that was clipping it, but every skin rule
         for these rows is written as `.zskin .ze-row` — hang the panel off
         <body> and it lands OUTSIDE that ancestor and loses all of it. The
         wrapper is the outermost element on the page, so it clips nothing. */
      (document.querySelector('.zskin') || document.body).appendChild(b);
    }
    return b;
  }
  /* Put it where it FITS, not blindly underneath: drop below when there is
     room, flip above when there is not, and never run off either edge. */
  function zeSugPlace(el){
    var b = zeSugBox(), r = el.getBoundingClientRect();
    b.style.maxHeight = '';
    var w = Math.min(Math.max(r.width, 430), window.innerWidth - 16);
    b.style.width = w + 'px';
    var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
    b.style.left = left + 'px';
    var below = window.innerHeight - r.bottom - 10, above = r.top - 10;
    var h = b.offsetHeight;
    if (h <= below || below >= above) {
      b.style.top = (r.bottom + 3) + 'px';
      b.style.maxHeight = Math.max(120, below) + 'px';
    } else {
      b.style.maxHeight = Math.max(120, above) + 'px';
      b.style.top = Math.max(8, r.top - Math.min(b.offsetHeight, above) - 3) + 'px';
    }
  }
  /* the input scrolls with the page; the panel is fixed, so it has to follow */
  function zeSugTrack(){
    if (sugRow < 0) return;
    var el = document.querySelectorAll('#erows .pk')[sugRow];
    if (el) zeSugPlace(el); else zeSugHide();
  }
  window.addEventListener('scroll', zeSugTrack, true);
  window.addEventListener('resize', zeSugTrack);
  function zeSugHide(){ var b = $('zeSugBox'); if (b) b.hidden = true; sugRow = -1; }

  window.zeSug = function(i, el){
    rows[i].label = el.value;
    var pop = zeSugBox(), list = sugList(i, el.value);
    sugRow = i;
    if (!list.length) { pop.hidden = true; return; }
    /* 12, not 8, and the cut is stated. Eight was a silent truncation on a
       floor with 200 workers: the name you wanted was simply not there, with
       nothing on screen to say more existed. */
    zePaint(pop, list, (el.value||'').trim().toLowerCase().split(/\s+/).filter(Boolean), 0, 12);
    pop.hidden = false;
    zeSugPlace(el);
  };
  window.zeSugKey = function(e, i){
    var pop = $('zeSugBox');
    if (!pop || pop.hidden) { if (e.key === 'Enter') e.preventDefault(); return; }
    var o = [].slice.call(pop.querySelectorAll('.ze-row'));
    var k = -1;
    for (var x=0; x<o.length; x++) if (o[x].dataset.cur === '1') { k = x; break; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (o[k]) o[k].dataset.cur = '0';
      k = e.key === 'ArrowDown' ? Math.min(k+1, o.length-1) : Math.max(k-1, 0);
      o[k].dataset.cur = '1'; o[k].scrollIntoView({block:'nearest'});
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var p = o[k < 0 ? 0 : k];
      /* the row's pick is read straight off the element now, rather than
         synthesising a mousedown that a real listener has to interpret */
      if (p) zeSugSet(i, p.getAttribute('data-pick'));
    } else if (e.key === 'Escape') { zeSugHide(); }
  };
  window.zeSugClose = function(i){ setTimeout(function(){ if (sugRow === i) zeSugHide(); }, 130); };
  window.zeSugSet = function(i, v){
    var o = other(v);
    rows[i].v = (tab === 'A') ? +v : v;
    rows[i].label = o ? (tab === 'A' ? o.name : o.pn + ' → ' + o.on) : '';
    zeSugHide();
    grid();
    var q = document.querySelectorAll('#erows input.q');
    if (q[i]) { q[i].focus(); q[i].select(); }
  };

  window.zeQty = function(i, v){ rows[i].q = v.replace(/[^0-9.]/g,''); totals(); };
  window.zeQtyKey = function(e, i){
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (i === rows.length - 1) zeAddRow();
    else { var a = document.querySelectorAll('#erows .pk'); if (a[i+1]) a[i+1].focus(); }
  };

  /* ---- THE CEILING IS SHARED ON TAB A ----
     Every line there is the SAME operation, so three workers booking 200 each
     against 420 remaining is 600 and must be refused. Checking each line on its
     own would pass all three. On tab B each line is a different operation with
     its own ceiling, but the same one may be typed twice, so they are added up
     per operation before being checked.

     The staged sheet counts too: booking 300 now and 300 again after staging
     must not slip past a 420 ceiling. */
  function stagedOn(key){
    var n = 0;
    sheet.forEach(function(x){ if (x.item + ':' + x.op === key) n += x.qty; });
    return n;
  }
  function totals(){
    var q = 0, amt = 0, n = 0, over = false;
    rows.forEach(function(r){ r.over = false; });
    if (tab === 'A') {
      rows.forEach(function(r){ var v = parseFloat(r.q)||0; if (r.v && v>0) { n++; q += v; amt += v * rowRate(r); } });
      if (picked && q + stagedOn(picked.k) > picked.left + 0.0001) {
        over = true;
        rows.forEach(function(r){ if ((parseFloat(r.q)||0) > 0) r.over = true; });
      }
    } else {
      var per = {};
      rows.forEach(function(r){
        var v = parseFloat(r.q)||0;
        if (r.v && v>0) { n++; q += v; amt += v * rowRate(r); per[r.v] = (per[r.v]||0) + v; }
      });
      rows.forEach(function(r){
        if (r.v && per[r.v] + stagedOn(r.v) > rowLeft(r) + 0.0001) { r.over = true; over = true; }
      });
    }
    var sa = 0;
    sheet.forEach(function(x){ sa += x.qty * x.rate; });
    $('rowCount').textContent = sheet.length + n;
    var tot = $('sheetTotal'); if (tot) tot.textContent = n2(sa + amt);

    var cells = document.querySelectorAll('#erows input.q');
    rows.forEach(function(r,i){ if (cells[i]) cells[i].classList.toggle('over', !!r.over); });
    $('zeSave').disabled = over || (sheet.length + n) === 0;

    /* the running ceiling. On tab A every line is the same operation, so one
       number covers the lot; on tab B each line has its own, so it only
       reports when one of them has been passed. */
    var cap = $('zeCap');
    if (cap) {
      if (tab === 'A' && picked) {
        var used = q + stagedOn(picked.k);
        cap.className = 'ze-cap' + (over ? ' bad' : '');
        cap.textContent = qn(used) + ' of ' + qn(picked.left) + ' left' + (over ? ' — OVER' : '');
      } else {
        cap.className = 'ze-cap' + (over ? ' bad' : '');
        cap.textContent = over ? 'a line is booked past what is left' : '';
      }
    }
    warn(over, q);
    return {n:n, q:q, amt:amt, over:over};
  }

  function warn(over, q){
    var w = $('zeWarn');
    if (!w) {
      w = document.createElement('div'); w.id = 'zeWarn';
      $('zeSheet').parentNode.insertBefore(w, $('zeSheet'));
    }
    if (!over) { w.innerHTML = ''; return; }
    w.innerHTML = '<div class="flash bad" style="margin-top:12px">'
      + (tab === 'A'
         ? '<b>These lines add up to ' + qn(q + stagedOn(picked.k)) + ' pieces, but only ' + qn(picked.left)
           + ' are left at this operation.</b> Nothing can be booked on pieces that were not made at the '
           + 'stage before it, so the sheet is held until the numbers fit.'
         : '<b>One of these operations is booked past what is left.</b> The red box shows which.')
      + '</div>';
  }

  /* ---- staging: finish this block and start another, without saving yet ---- */
  window.zeStash = function(){
    var t = totals();
    if (t.over) return;
    if (!t.n) { zeReset(); return; }
    rows.forEach(function(r){
      var v = parseFloat(r.q)||0;
      if (!r.v || v <= 0) return;
      var work   = (tab === 'A') ? picked : other(r.v);
      var worker = (tab === 'A') ? other(r.v) : picked;
      if (!work || !worker) return;
      /* kind rides on the staged row, not looked up again at save time — the
         work list can be rebuilt between staging and saving, and a row that
         re-derived its own kind then could book against the wrong table. */
      sheet.push({ item:work.item, op:work.op, kind:work.kind || 'part',
                   worker:worker.id, qty:v, rate:work.rate,
                   what:work.pn + ' → ' + work.on, who:worker.name, pi:work.pi });
    });
    zeReset();
    paintSheet();
  };

  function paintSheet(){
    var el = $('zeSheet');
    if (!sheet.length) { el.innerHTML = ''; totals(); return; }
    var h = '<div class="ze-stash"><h4>Waiting to be saved — ' + sheet.length + ' line'
          + (sheet.length === 1 ? '' : 's') + '</h4>';
    sheet.forEach(function(x,i){
      h += '<div class="ze-sl"><div><b>' + esc(x.who) + '</b> &middot; ' + esc(x.what)
         + ' <span style="color:#8a97ab">(' + esc(x.pi) + ')</span></div>'
         + '<div style="display:flex;gap:11px;align-items:center">'
         + '<span style="font-family:ui-monospace,monospace"><b>' + qn(x.qty) + '</b> pcs'
         + (MONEY ? ' &middot; ' + n2(x.qty * x.rate) : '') + '</span>'
         + '<button type="button" class="x" onclick="zeUnstash(' + i + ')">&times;</button></div></div>';
    });
    el.innerHTML = h + '</div>';
    totals();
  }
  window.zeUnstash = function(i){ sheet.splice(i,1); paintSheet(); };

  /* ---- SAVE: the staged sheet PLUS whatever is typed but not yet staged ----
     Forgetting the rows still on screen is the obvious way to lose somebody's
     wages, so Save stages them first and then posts the lot. */
  document.getElementById('bookForm').addEventListener('submit', function(ev){
    var t = totals();
    if (t.over) { ev.preventDefault(); return; }
    if (t.n) zeStash();
    if (!sheet.length) {
      ev.preventDefault();
      alert('Nothing to save yet — pick some work and type a quantity.');
      return;
    }
    var f = ev.target;
    [].slice.call(f.querySelectorAll('.zePost')).forEach(function(e){ e.remove(); });
    sheet.forEach(function(x){
      [['r_item',x.item],['r_op',x.op],['r_kind',x.kind||'part'],['r_worker',x.worker],
       ['r_qty',x.qty],['r_note','']]
        .forEach(function(kv){
          var h = document.createElement('input');
          h.type = 'hidden'; h.className = 'zePost'; h.name = kv[0] + '[]'; h.value = kv[1];
          f.appendChild(h);
        });
    });
  });

  /* ---- keyboard on the main search ---- */
  $('zeQ').addEventListener('input', function(){ cur = 0; draw(); });
  $('zeQ').addEventListener('keydown', function(e){
    var list = tabList();
    if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur+1, Math.min(list.length,60)-1); draw(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur-1, 0); draw(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (list[cur]) zePick(cur); }
    else if (e.key === 'Escape') { $('zeQ').value = ''; cur = 0; draw(); }
  });

  document.addEventListener('keydown', function(ev){
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      var f = document.getElementById('bookForm');
      if (f && !$('zeSave').disabled) { f.requestSubmit ? f.requestSubmit() : f.submit(); }
    }
  });

  window.zeCancel = function(id){
    var why = prompt('Why is this entry being cancelled?\n\nThe line stays on the list, marked, with this reason against it.');
    if (why === null) return;
    if (!why.trim()) { alert('A reason is needed — a correction with no reason cannot be checked later.'); return; }
    var f = document.getElementById('cancelForm');
    f.entry_id.value = id; f.reason.value = why.trim(); f.submit();
  };

  zeTab('A');
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
