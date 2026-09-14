<?php
/*
  MY PRODUCTION WORK — the same booking, shaped for the floor.
  ============================================================

  Daily Production Entry is a grid for somebody entering a whole day at a desk.
  This is the other half of the same job: one worker, one card at a time, big
  targets, on a phone, standing next to a machine.

  IT WRITES THE SAME ROWS THROUGH THE SAME zp_book(). Not a second booking path
  with its own idea of the rules — the caps, the rate lookup and the all-or-
  nothing save are identical, because they are literally the same function. Two
  screens that book work in two different ways will disagree eventually, and the
  disagreement will be about money.

  WHAT A CARD SHOWS is what is LEFT, not what was ordered. "180 left to
  overlock" is an instruction; "500 ordered" is trivia you have to do arithmetic
  on while holding fabric.
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
/* BOOKING WORK AND SEEING THE MONEY ARE TWO DIFFERENT PERMISSIONS.
   An operator books quantities; whether they see what it pays is a separate
   question the app already answers. Conflating the two is how a wage rate ends
   up on a screen it should not be on. */
$showMoney = can_see_rates();
$date   = date('Y-m-d');
$wid    = (int)($_GET['w'] ?? $_POST['worker_id'] ?? 0);

$msg = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_one') {
    verify_csrf();
    /* ONE ROW, THROUGH THE SAME FUNCTION THE DAY SHEET USES. */
    $r = zp_book($date, [[
        'item_id'   => (int)($_POST['item_id'] ?? 0),
        'op_id'     => (int)($_POST['op_id'] ?? 0),
        'worker_id' => $wid,
        'qty'       => (string)($_POST['qty'] ?? 0),
    ]], $userId);
    if ($r['ok']) {
        $_SESSION['zp_msg'] = can_see_rates()
            ? 'Booked — PKR ' . number_format($r['amount'], 2) . ' added.'
            : 'Booked.';
        redirect('production_my_work.php?w=' . $wid);
    }
    $errors = $r['errors'];
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

$workers = zp_workers(true);
$worker  = null;
foreach ($workers as $w) if ((int)$w['id'] === $wid) { $worker = $w; break; }

/* ---- build the cards: every real piece of work still outstanding ---- */
$cards = [];
$SIZE_PROBLEMS = [];
if ($worker) {
    $prog = zp_progress_map();
    foreach (zp_open_lines() as $l) {
        $pid = (int)$l['product_id'];
        /* A line whose size does not resolve produces no cards at all — every
           one of them would say "0 left", which on the floor reads as finished.
           The office sees the reason on the Daily Entry screen; the floor is
           not the place to fix a proforma. */
        if (zp_size_problem($l) !== '') { $SIZE_PROBLEMS[] = zp_size_problem($l); continue; }
        foreach (zp_product_parts($pid) as $p) {
            $partId = (int)$p['id'];
            $needed = zp_pieces_needed($l, $partId);
            foreach (zp_part_ops($partId, true) as $o) {
                $opId = (int)$o['id'];
                $left = zp_remaining($l, $partId, $opId, (string)$o['stage'], $prog);
                if ($left <= 0.0001) continue;               // finished — not work
                $done = (float)($prog['op'][(int)$l['item_id']][$partId][$opId] ?? 0);
                $rate = zp_rate_for($opId, (float)$o['rate'], zp_order_rate_map((int)$l['proforma_id']));
                $cards[] = [
                    'item_id' => (int)$l['item_id'], 'op_id' => $opId,
                    'pi' => $l['pi_no'], 'customer' => $l['customer_name'],
                    'product' => $l['product_name'], 'size' => $l['size'],
                    'part' => $p['part_name'], 'op' => $o['operation_name'],
                    'stage' => $o['stage'], 'rate' => $rate,
                    'left' => $left, 'done' => $done, 'needed' => $needed,
                    'pct' => $needed > 0 ? min(100, round($done / $needed * 100)) : 0,
                ];
            }
        }
    }
    /* CUTTING FIRST. Nothing downstream can move until it is done, so it is
       what the floor should pick up first — and sorting by what is furthest
       behind puts the real bottleneck at the top. */
    usort($cards, function ($a, $b) {
        $ca = zp_is_cutting($a['stage']) ? 0 : 1;
        $cb = zp_is_cutting($b['stage']) ? 0 : 1;
        if ($ca !== $cb) return $ca <=> $cb;
        return $a['pct'] <=> $b['pct'];
    });
}

$mine = $worker ? zp_entries(['date' => $date, 'worker_id' => $wid, 'status' => 'active'], 100) : [];
$myQty = 0.0; $myAmt = 0.0;
foreach ($mine as $m) { $myQty += (float)$m['qty']; $myAmt += (float)$m['amount']; }

page_header('My Production Work');
?>
<style>
.mw-wrap{max-width:1200px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}.zp-b.ok:hover{background:#12823b;color:#fff}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zin{width:100%;padding:9px 11px;border:1px solid #d9e0ea;border-radius:9px;font-size:14px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:5px}
.flash{padding:12px 15px;border-radius:10px;font-size:13.5px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.flash.bad ul{margin:7px 0 0;padding-left:19px;font-weight:500}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:13px}
.card{border:1px solid #e6ebf2;border-left-width:5px;border-radius:12px;padding:14px 15px;background:#fff}
.card.Cutting{border-left-color:#d97706}
.card.Manual{border-left-color:#8b5cf6}
.card.Stitching{border-left-color:#0ea8c9}
.card .pi{font-size:10.5px;color:#8a97ab;font-family:ui-monospace,monospace;margin-bottom:3px}
.card .prod{font-size:14px;font-weight:800;color:#152033;line-height:1.3}
.card .part{font-size:12.5px;color:#5a6b82;margin-top:3px}
.card .big{font-size:28px;font-weight:800;color:#152033;font-variant-numeric:tabular-nums;line-height:1.1;margin:10px 0 2px}
.card .big small{font-size:12px;font-weight:600;color:#8a97ab}
.bar{height:7px;border-radius:5px;background:#eef1f6;overflow:hidden;margin:9px 0 4px}
.bar i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,#0ea8c9,#3ec7e0)}
.meta{display:flex;justify-content:space-between;font-size:10.5px;color:#8a97ab;font-variant-numeric:tabular-nums}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.cut{background:rgba(217,119,6,.13);color:#9a5710}
.pill.man{background:rgba(139,92,246,.14);color:#6d3fd4}
.pill.st{background:rgba(14,168,201,.14);color:#0b7f99}
.bookrow{display:flex;gap:8px;margin-top:11px}
.bookrow input{flex:1;text-align:right;font-variant-numeric:tabular-nums;font-weight:700}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.empty{padding:26px;text-align:center;color:#8a97ab;font-size:13px;line-height:1.6}
.tot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;
     padding:11px 14px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px;font-size:13px}
.tot b{font-size:17px;color:#152033;font-variant-numeric:tabular-nums}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="mw-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">My Production Work</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab"><?= e(date('D j M Y')) ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="zp-b" href="production_entry.php">Full day sheet</a>
      <span style="font-size:10px;color:#a7b2c4;font-family:ui-monospace,monospace">build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="flash bad"><b>Not booked.</b>
      <ul><?php foreach ($errors as $e2): ?><li><?= e($e2) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="zp-card">
    <span class="lab">Who is working?</span>
    <select class="zin" style="max-width:340px"
            onchange="location.href='production_my_work.php?w='+encodeURIComponent(this.value)">
      <option value="">Choose your name…</option>
      <?php foreach ($workers as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $wid ? 'selected' : '' ?>>
          <?= e($w['worker_code']) ?> — <?= e($w['worker_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$workers): ?>
      <p style="margin:10px 0 0;font-size:12.5px;color:#8a5a10">
        There are no active workers yet. <a href="production_workers.php">Add them first</a> —
        a wage has to belong to somebody.</p>
    <?php endif; ?>
  </div>

  <?php if (!$worker): ?>
    <div class="zp-card"><div class="empty">
      Choose your name above and today's work appears.
    </div></div>
  <?php else: ?>

    <?php if ($mine): ?>
      <div class="zp-card">
        <h2 style="margin:0 0 10px;font-size:15.5px;color:#152033">What <?= e($worker['worker_name']) ?> has done today</h2>
        <table class="zp-t">
          <thead><tr><th>Product</th><th>Operation</th><th class="num" style="width:80px">Qty</th>
            <?php if ($showMoney): ?><th class="num" style="width:100px">PKR</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($mine as $m): ?>
            <tr>
              <td><?= e($m['product_name'] ?? '—') ?></td>
              <td><?= e(($m['part_name'] ?? '—') . ' · ' . ($m['operation_name'] ?? '—')) ?></td>
              <td class="num"><?= rtrim(rtrim(number_format((float)$m['qty'], 2), '0'), '.') ?></td>
              <?php if ($showMoney): ?><td class="num"><?= number_format((float)$m['amount'], 2) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="tot">
          <span><?= rtrim(rtrim(number_format($myQty, 2), '0'), '.') ?> pieces today</span>
          <?php if ($showMoney): ?><span>PKR <b><?= number_format($myAmt, 2) ?></b></span><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="zp-card">
      <h2 style="margin:0 0 4px;font-size:15.5px;color:#152033">Work still to do</h2>
      <p style="margin:0 0 13px;font-size:12.5px;color:#8a97ab">
        Cutting first — nothing else can move until it is done. Then whatever is furthest behind.</p>

      <?php if (!$cards): ?>
        <div class="empty">
          Nothing outstanding.<br>
          Either every operation on every live order is finished, or no order is switched on for production yet.
        </div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($cards as $c):
            $cls = $c['stage'] === 'Cutting' ? 'Cutting' : ($c['stage'] === 'Manual Cutting' ? 'Manual' : 'Stitching');
            $pill = $c['stage'] === 'Cutting' ? 'cut' : ($c['stage'] === 'Manual Cutting' ? 'man' : 'st');
          ?>
            <div class="card <?= $cls ?>">
              <div class="pi"><?= e($c['pi']) ?> · <?= e($c['customer'] ?: '—') ?></div>
              <div class="prod"><?= e($c['product']) ?><?= trim((string)$c['size']) !== '' ? ' · ' . e($c['size']) : '' ?></div>
              <div class="part"><?= e($c['part']) ?> — <b><?= e($c['op']) ?></b>
                &nbsp;<span class="pill <?= $pill ?>"><?= e($c['stage']) ?></span></div>

              <div class="big"><?= rtrim(rtrim(number_format($c['left'], 2), '0'), '.') ?>
                <small>left to do</small></div>

              <div class="bar"><i style="width:<?= (int)$c['pct'] ?>%"></i></div>
              <div class="meta">
                <span><?= rtrim(rtrim(number_format($c['done'], 2), '0'), '.') ?> done of
                      <?= rtrim(rtrim(number_format($c['needed'], 2), '0'), '.') ?></span>
                <?php if ($showMoney): ?><span>PKR <?= number_format($c['rate'], 2) ?> / pc</span><?php endif; ?>
              </div>

              <form method="post" class="bookrow"
                    onsubmit="var q=parseFloat(this.qty.value)||0;
                              if(q<=0){alert('Type how many you did.');return false;}
                              if(q><?= $c['left'] ?>+0.0001){alert('Only <?= rtrim(rtrim(number_format($c['left'],2),'0'),'.') ?> left on this one.');return false;}
                              return true;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book_one">
                <input type="hidden" name="worker_id" value="<?= $wid ?>">
                <input type="hidden" name="item_id" value="<?= $c['item_id'] ?>">
                <input type="hidden" name="op_id" value="<?= $c['op_id'] ?>">
                <input class="zin" name="qty" inputmode="decimal" autocomplete="off" placeholder="how many?">
                <button class="zp-b ok">Book</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="note" style="margin-top:14px">
        <b>Each card shows what is LEFT, not what was ordered.</b> A card disappears when its operation is
        finished. Stitching cards only offer what has actually been cut — you cannot stitch a piece that does
        not exist yet, so the number refuses rather than letting the mistake through.
      </div>
    </div>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
