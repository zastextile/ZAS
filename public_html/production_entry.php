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
/* A WORKER'S SMALL PHOTO FOR THE PREVIEW PANE (owner, 27 Nov 2026).
   Asked for only when the pane has rested on a person, and only for people
   who have a photo. It is the small face People Finance already keeps
   (att_photo_thumbs, made once from the photo on file), and the browser keeps
   it for a day — so each face costs the server one small read per device per
   day. Every face sent is written to the audit log, like any employee photo. */
if (isset($_GET['face'])) {
    $wid = (int)$_GET['face'];
    $eid = 0;
    try {
        $st = db()->prepare("SELECT att_employee_id FROM zp_workers WHERE id = ? AND is_active = 1");
        $st->execute([$wid]); $eid = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    $bytes = ''; $mime = '';
    if ($eid > 0) {
        foreach (['Db', 'Time', 'Policy', 'Audit', 'Storage', 'Images', 'PhotoService'] as $c) require_once __DIR__ . '/includes/att/' . $c . '.php';
        require_once __DIR__ . '/includes/storage.php';
        try {
            \Att\Db::useUtc();
            $url = \Att\PhotoService::thumbs([$eid], (int)(current_user()['id'] ?? 0), 'production entry')[$eid] ?? '';
            if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $url, $m)) { $mime = $m[1]; $bytes = (string)base64_decode($m[2]); }
        } catch (Throwable $e) { error_log('[production-entry] face ' . $wid . ': ' . $e->getMessage()); }
    }
    if ($bytes === '') { http_response_code(404); header('Cache-Control: private, max-age=3600'); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}
require_once __DIR__ . '/includes/zprod.php';
/* the booked-work helpers shared with Correct an Entry: merge, group, edit, cancel */
require_once __DIR__ . '/includes/zp_correct.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
/* A PRODUCTION STAFF LOGIN MAY BE LIMITED TO ITS FLOORS (Users page, 28 Nov
   2026). [] = every floor. The list below offers only those workers, and the
   save refuses anybody else — the list is a convenience, the refusal is the rule. */
require_once __DIR__ . '/includes/zp_staff.php';
zps_ensure();
$FLOORS = zps_depts($me);
/* Same separation as My Work: booking a quantity and seeing the wage are two
   different permissions, and the app already answers the second one. */
$showMoney = can_see_rates();
$date   = trim((string)($_GET['d'] ?? $_POST['entry_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$msg = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($FLOORS) {
        $who = $a === 'book' ? (array)($_POST['r_worker'] ?? [])
             : array_merge(zps_entry_workers(ze_post_ids()), [(int)($_POST['new_worker'] ?? 0)]);
        $no = zps_refuse_workers($who, $FLOORS);
        if ($no !== '') {
            if ($a === 'book') { $errors[] = $no; $a = ''; }
            else { $_SESSION['zp_err'] = $no; redirect('production_entry.php?d=' . urlencode($date)); }
        }
    }

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
            /* how many bookings were TYPED — one booking shared over three sizes
               is saved as three lines; the count is only for this message */
            $nb = max(1, min((int)$r['saved'], (int)($_POST['n_book'] ?? $r['saved'])));
            $_SESSION['zp_msg'] = $nb . ' booking' . ($nb === 1 ? '' : 's') . ' saved'
                . ($nb < (int)$r['saved'] ? ' (' . (int)$r['saved'] . ' lines)' : '')
                . ($showMoney ? ' — PKR ' . number_format($r['amount'], 2) . ' in wages.' : '.');
            redirect('production_entry.php?d=' . urlencode($date));
        }
        $errors = $r['errors'];

    } elseif ($a === 'cancel_entry') {
        /* a summarised row carries every line it stands for: "12,13,14".
           ALL OR NOTHING: if one line refuses (cutting that stitching stands
           on), the ones already cancelled are put back, so a row is never
           left half cancelled. */
        $ids = ze_post_ids();
        $r = ze_cancel_all($ids, (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = 'Entry cancelled' . (count($ids) > 1 ? ' (' . count($ids) . ' lines)' : '') . '. It stays on the list, marked, so the correction is visible.';
        else          $_SESSION['zp_err'] = $r['error'];
        redirect('production_entry.php?d=' . urlencode($date));

    } elseif ($a === 'edit_entry') {
        /* EDIT A BOOKED ROW (owner, 21 Nov 2026: "make it easy to edit").
           Nothing is overwritten: the old lines are cancelled with the reason
           "Edited: was 500, now 450 — <why>", and the new quantity / worker is
           booked through zp_book(), which checks and prices it like any other
           line. If the new booking is refused, the old lines are put back
           exactly as they were. */
        $r = ze_edit_row(ze_post_ids(), $date, (float)($_POST['new_qty'] ?? 0), (int)($_POST['new_worker'] ?? 0), (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = $r['msg'];
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
/* taken off Production Workers as not production (28 Nov 2026): never offered here */
$HIDW = zp_hidden_workers();
if ($HIDW) $workers = array_values(array_filter($workers, fn($w) => !isset($HIDW[(int)$w['id']])));
/* only this login's floors */
if ($FLOORS) $workers = array_values(array_filter($workers, fn($w) => zps_dept_ok($FLOORS, (string)($w['department'] ?? ''))));

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
$WORK = ze_merge_work(zp_work_index());
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
/* ONLY PEOPLE WITH A STAGE ARE PRODUCTION (owner, 19 Nov 2026). Head office,
   admin and the rest are in People Finance but never book a piece, so the
   pickers leave out anybody allotted no stage at all. Somebody on ANOTHER
   stage is still found by typing — standing in for a day keeps working.
   Safety net: until at least one person has a stage, everybody is offered,
   so a new setup is never left with an empty list. */
$PICK = $workers;
foreach ($workers as $w) {
    if (!empty($wsMap[(int)$w['id']])) {
        $PICK = array_values(array_filter($workers, fn($x) => !empty($wsMap[(int)$x['id']])));
        break;
    }
}
/* who has a photo on file — one small query, so the page never asks for a face that is not there */
$HASFACE = [];
try {
    foreach (db()->query("SELECT w.id FROM zp_workers w JOIN att_employees e ON e.id = w.att_employee_id
                          WHERE w.is_active = 1 AND e.reference_photo_key IS NOT NULL")->fetchAll() as $r) $HASFACE[(int)$r['id']] = 1;
} catch (Throwable $e) {}
$stNm  = [];
foreach (zp_stage_all(false) as $s) $stNm[(int)$s['id']] = (string)$s['name'];

/* an unplannable line is reported, not hidden — collected above */
foreach ($lines as $l) {
    $bad = zp_size_problem($l);
    if ($bad !== '') $SIZE_PROBLEMS[] = $bad;
}

$today   = zp_entries(['date' => $date], 400);
if ($FLOORS) { $WDM = zps_worker_dept_map(); $today = array_values(array_filter($today, fn($t) => zps_dept_ok($FLOORS, $WDM[(int)$t['worker_id']] ?? ''))); }
$today   = ze_entries_more($today);   // customer, size, description, who booked it — see includes/zp_correct.php
$PAYT = ze_pay_types();   // fixed salary / daily wages / contractor, from People Finance

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
/* the Booked section: heading with links, a search + stage bar, the edit dialog */
.bk-hd{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.bk-links{display:flex;gap:6px}
.bk-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0 10px}
.bk-bar #bkQ{width:260px;max-width:100%;height:30px}
.bk-seg{display:inline-flex;flex-wrap:wrap;border:1px solid #d9dee4;border-radius:4px;overflow:hidden}
.bk-seg button{height:28px;padding:0 10px;border:0;border-right:1px solid #d9dee4;background:#fff;color:#556b82;font:600 12px inherit;cursor:pointer}
.bk-seg button:last-child{border-right:0}.bk-seg button.on{outline:2px solid #0a6ed1;outline-offset:-2px;color:#0a4f96}
.bk-seg button b{color:#1d2d3e;margin-left:3px}
.bk-cnt{margin-left:auto;color:#556b82;font-size:12px}
.bk-why{font-size:10.5px;color:#9a3412;max-width:150px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.bk-desc{font-size:10.5px;color:#6a7b8c;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bk-dlg{border:1px solid #d9dee4;border-radius:8px;padding:16px 18px;width:min(440px,94vw);box-shadow:0 18px 40px rgba(16,32,56,.25)}
.bk-dlg::backdrop{background:rgba(16,32,56,.35)}
.bk-dlg h3{margin:0 0 6px;font-size:15px}
.bk-dlg .bk-what{font-size:12px;color:#38495f;margin-bottom:10px}
.bk-dlg label{display:block;font-size:12px;color:#556b82;font-weight:600;margin-bottom:8px}
.bk-dlg label .zin{display:block;width:100%;margin-top:3px;height:32px}
.bk-dlg .bk-note{font-size:11.5px;color:#6a7b8c;margin:4px 0 12px}
.bk-dlg .bk-btns{display:flex;justify-content:flex-end;gap:8px}
#bkT tbody tr[hidden]{display:none}
/* stage tint on the list rows (owner, 21 Nov 2026); the chosen row keeps its bar and gets a blue outline */
.zskin .ze-row.stg{background:var(--tb) !important;box-shadow:inset 4px 0 0 var(--tl) !important}
.zskin .ze-row.stg:hover{filter:brightness(.975)}
.zskin .ze-row.stg[data-cur="1"]{box-shadow:inset 4px 0 0 var(--tl),inset 0 0 0 2px #0a6ed1 !important}
.ze-t{font-size:13px;font-weight:800;letter-spacing:-.01em;margin-bottom:1px}
.ze-s{font-size:11px;color:#6b7a90;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ze-s i{font-style:normal;color:#b6c0cf}
.ze-left{font-family:ui-monospace,monospace;font-size:12.5px;font-weight:800;color:#0d9488;text-align:right;white-space:nowrap}
.ze-left small{display:block;font-family:inherit;font-size:9.5px;color:#9aa8ba;font-weight:700;
               text-transform:uppercase;letter-spacing:.06em}
.ze-res mark{background:rgba(29,111,242,.16);color:#1558ad;border-radius:3px;padding:0 1px;font-weight:800}
.ze-none{padding:22px;text-align:center;color:#8a97ab;font-size:12.5px}
/* a hand-added job that is waiting: greyed, not pickable (27 Nov 2026) */
.ze-wh{padding:6px 13px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a97ab;background:#f4f6f9;border-top:1px dashed #d9e0ea}
.ze-wait{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:11px;align-items:center;padding:8px 13px;border-bottom:1px solid #eef2f7;
         background:#f7f8fa;opacity:.62;cursor:not-allowed;box-shadow:inset 4px 0 0 #c3ccd8}
.zskin .ze-wait .ze-left,.ze-wait .ze-left{color:#9a5710 !important;font-size:11.5px !important;font-family:inherit !important;font-weight:700;white-space:normal;max-width:240px}
/* ---- THE QUICK FILTER: PI digits + one stage (27 Nov 2026) ---- */
.ze-top{display:flex;gap:10px 14px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.zskin .ze-top .ze-tabs{margin-bottom:0 !important}
.ze-flt{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ze-fpi{display:inline-flex;align-items:center;height:30px;border:1.5px solid #d9e0ea;border-radius:16px;background:#fff;padding:0 4px 0 10px;gap:6px}
.ze-fpi span{font-size:10.5px;font-weight:800;letter-spacing:.06em;color:#6b7a90}
.ze-fpi input{border:0;outline:0;width:110px;height:26px;font:600 13px/1 ui-monospace,monospace;color:#152033;background:transparent}
.ze-fpi input::placeholder{color:#b9c3d0;font-weight:400}
.ze-fpi:focus-within{border-color:#1d6ff2;box-shadow:0 0 0 3px rgba(29,111,242,.12)}
.ze-fpi.on{border-color:#1d6ff2;background:#eef5ff}
.ze-fst{display:inline-flex;gap:5px;flex-wrap:wrap}
.ze-fc{height:28px;padding:0 10px;border-radius:14px;border:1.5px solid;font-weight:700;font-size:12px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:transform .08s}
.ze-fc:hover{transform:translateY(-1px)}
.ze-fc b{font:700 10.5px/1 ui-monospace,monospace;opacity:.75}
.ze-fc kbd{font:600 9px/1 ui-monospace,monospace;opacity:.55}
.ze-fc.on{box-shadow:0 2px 8px -3px rgba(0,0,0,.35)}
.ze-fc:focus-visible{outline:2px solid #1d6ff2;outline-offset:2px}
.ze-fx{height:26px;padding:0 9px;border-radius:13px;border:1px solid #f2c4c4;background:#fff5f5;color:#b42318;font-weight:700;font-size:11.5px;font-family:inherit;cursor:pointer}
.ze-fnote{font-size:11px;color:#1558ad;background:#eef5ff;border:1px solid #cfe0f7;border-radius:6px;padding:3px 8px;margin-top:6px;display:inline-block}
/* ---- THE PREVIEW PANE: the highlighted row of either list, in full (27 Nov 2026) ---- */
.ze-lv{display:flex;gap:12px;align-items:flex-start}
.ze-lv>.ze-res{flex:1 1 auto;min-width:0}
.ze-pv{flex:0 0 clamp(300px,30vw,460px);width:clamp(300px,30vw,460px);margin-top:10px;position:sticky;top:10px;max-height:72vh;overflow:auto;
       border:1px solid #dfe6ef;border-radius:11px;background:#fff;box-shadow:0 6px 22px -12px rgba(16,32,56,.35);font-size:12.5px;color:#1d2d3e}
.ze-pv[hidden]{display:none}
@media (max-width:980px){ .ze-lv{flex-direction:column;align-items:stretch} .ze-pv{width:auto;flex:none;position:static;max-height:none} }
.zesugpv{position:fixed;z-index:1001;width:340px;max-height:70vh}
.pv-hd{padding:10px 13px 9px;border-bottom:1px solid;border-radius:10px 10px 0 0;position:relative}
.pv-hd.has-face{padding-right:76px}
.pv-face{position:absolute;right:12px;top:10px;width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid #fff;box-shadow:0 2px 8px -2px rgba(16,32,56,.35);background:#fff}
.pv-ini{position:absolute;right:12px;top:10px;width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;line-height:1;color:#fff;border:2px solid #fff;box-shadow:0 2px 8px -2px rgba(16,32,56,.35)}
.pv-k{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;opacity:.85}
.pv-t{font-size:18.5px;font-weight:800;letter-spacing:-.015em;margin:3px 0 6px;line-height:1.22}
.pv-g>b.pv-prod{font-size:14px;font-weight:800}
.pv-b{padding:10px 13px 12px}
.pv-g{display:grid;grid-template-columns:86px minmax(0,1fr);gap:4px 10px;margin-bottom:9px}
.pv-g>span{color:#6b7a90;font-size:11.5px}
.pv-g>b,.pv-g>div{min-width:0;overflow-wrap:anywhere}
.pv-d{font-style:italic;color:#4a5a6a}
.pv-kp{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:4px 0 6px}
.pv-kp div{border:1px solid #e6ecf3;border-radius:8px;padding:5px 7px;background:#fafcfe}
.pv-kp small{display:block;font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a97ab}
.pv-kp b{font-family:ui-monospace,monospace;font-size:14px}
.pv-bar{height:7px;border-radius:4px;background:#e6ecf3;overflow:hidden;margin:2px 0 10px}
.pv-bar i{display:block;height:100%;background:linear-gradient(90deg,#0d9488,#22b8a6)}
.ze-pv table.pv-t2{width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:11.5px;margin-top:2px}
.pv-t2 th{font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;color:#8a97ab;text-align:left;padding:3px 4px;border-bottom:1px solid #e6ecf3}
.pv-t2 td{padding:4px;border-bottom:1px solid #f0f3f7;font-variant-numeric:tabular-nums}
.pv-t2 td.n,.pv-t2 th.n{text-align:right}
.pv-t2 .mb{height:4px;border-radius:2px;background:#e6ecf3;overflow:hidden;margin-top:2px}.pv-t2 .mb i{display:block;height:100%;background:#0d9488}
.pv-sz{display:inline-block;min-width:26px;padding:0 6px;border-radius:10px;background:#eef2f8;color:#33465f;font-weight:700;font-size:11px;text-align:center}
.pv-note{font-size:11px;color:#9a5710;background:#fff7ea;border:1px solid #f4d9a8;border-radius:6px;padding:5px 7px;margin-top:8px}
.pv-ft{font-size:10.5px;color:#8a97ab;margin-top:9px}
.pv-ft kbd{font-family:ui-monospace,monospace;font-size:10px;background:#f4f7fb;border:1px solid #e2e9f1;border-radius:4px;padding:0 4px}
.pv-ch{display:inline-block;font-size:10.5px;font-weight:700;border:1px solid #d9e0ea;border-radius:10px;padding:0 7px;margin:0 4px 4px 0;background:#f7f9fc;color:#33465f}
.ze-usual{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
          background:rgba(13,148,136,.13);color:#0b7f74;padding:1px 7px;border-radius:20px}

.ze-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;margin-top:11px;
         background:rgba(29,111,242,.08);border:1px solid #1d6ff2;border-radius:11px;padding:11px 13px}
.ze-head h3{margin:0 0 2px;font-size:14.5px;font-weight:800;letter-spacing:-.015em;color:#152033}
.ze-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.ze-pt{display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:5px;border:1px solid;white-space:nowrap;
       font-family:inherit;letter-spacing:0;vertical-align:1px}
.ze-pt.pt-salary{color:#1d4ed8;background:#eef4ff;border-color:#bcd0fb}
.ze-pt.pt-daily{color:#15803d;background:#effaf2;border-color:#b5e2c3}
.ze-pt.pt-contract{color:#b45309;background:#fff7ec;border-color:#f3d2a6}
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
      <?php if ($FLOORS): ?><p style="margin:4px 0 0;font-size:12px;color:#107e3e;font-weight:600">Your login works on: <?= e(implode(', ', $FLOORS)) ?> — only these workers are offered.</p><?php endif; ?>
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
      <div class="ze-top">
      <div class="ze-tabs" role="tablist">
        <button type="button" class="ze-tab" role="tab" aria-selected="true" id="tabA" onclick="zeTab('A')">
          <b>By operation</b><span>one job &rarr; many workers</span></button>
        <button type="button" class="ze-tab" role="tab" aria-selected="false" id="tabB" onclick="zeTab('B')">
          <b>By worker</b><span>one person &rarr; everything they did</span></button>
      </div>
      <?php /* THE QUICK FILTER (owner, 27 Nov 2026): the orders being worked on
               today, by the last digits of the PI ("977, 689"), and one stage.
               It narrows every list on the page and stays set after Save until
               the tab is closed. */ ?>
      <div class="ze-flt" id="zeFlt">
        <label class="ze-fpi" title="Last digits of the PI number — several with commas, e.g. 977, 689"><span>PI</span>
          <input id="zeFPi" autocomplete="off" spellcheck="false" placeholder="977, 689"></label>
        <span class="ze-fst" id="zeFSt"></span>
        <button type="button" class="ze-fx" id="zeFClr" hidden title="Show everything again">&times; Clear</button>
      </div>
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
        <?php /* THE LIST AND, BESIDE IT, THE ROW YOU ARE ON — in full (owner, 27 Nov 2026) */ ?>
        <div class="ze-lv"><div class="ze-res" id="zeRes"></div><aside class="ze-pv" id="zePrev" hidden></aside></div>
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
    <?php $BK = $today ? ze_group_today($today) : []; ?>
    <div class="bk-hd">
      <h2>Booked on <?= e(date('D j M Y', strtotime($date))) ?></h2>
      <div class="bk-links">
        <a class="zp-b sm" href="production_reports.php?r=entries&amp;from=<?= e($date) ?>&amp;to=<?= e($date) ?>">Full report</a>
        <?php if (is_admin() || is_colleague()): ?><a class="zp-b sm" href="production_amend.php?from=<?= e($date) ?>&amp;to=<?= e($date) ?>">Corrections</a><?php endif; ?>
      </div>
    </div>
    <?php if (!$today): ?>
      <div class="empty">Nothing booked for this date yet.</div>
    <?php else:
      $stCnt = []; $nCanc = 0;
      foreach ($BK as $t) { if ($t['status'] !== 'active') { $nCanc++; continue; } $stCnt[(string)$t['stage']] = ($stCnt[(string)$t['stage']] ?? 0) + 1; } ?>
      <?php /* FIND AND EXPORT IN THE BROWSER: the rows are already on the page, so
               search, stage filter and CSV cost the server nothing. */ ?>
      <div class="bk-bar">
        <input class="zin" id="bkQ" placeholder="Search worker, order, product, operation…" autocomplete="off" oninput="bkFilter()">
        <span class="bk-seg" id="bkSeg">
          <button type="button" class="on" data-s="">All <b><?= count($BK) ?></b></button>
          <?php foreach ($stCnt as $sn => $c): ?>
            <button type="button" data-s="<?= e($sn) ?>" style="<?= e(ze_stage_tint($sn)) ?>"><?= e($sn) ?> <b><?= $c ?></b></button>
          <?php endforeach; ?>
          <?php if ($nCanc): ?><button type="button" data-s="__canc">Cancelled <b><?= $nCanc ?></b></button><?php endif; ?>
        </span>
        <span id="bkCnt" class="bk-cnt"></span>
        <button type="button" class="zp-b sm" onclick="bkCsv()">Download CSV</button>
      </div>
      <div style="overflow-x:auto">
      <table class="zp-t" id="bkT">
        <thead><tr>
          <th style="width:36px">#</th><th>Worker</th><th>Order &amp; product</th><th>Part &amp; operation</th>
          <th style="width:110px">Stage</th><th class="num" style="width:80px">Qty</th>
          <?php /* BOOKING A QUANTITY AND SEEING THE WAGE ARE TWO DIFFERENT PERMISSIONS.
                   This table printed both columns to anybody who could open the page,
                   so an operator not allowed to see rates saw every rate on the floor.
                   The entry grid above already respected can_see_rates(); this did not. */ ?>
          <?php if ($showMoney): ?>
            <th class="num" style="width:80px">Rate</th><th class="num" style="width:100px">Amount</th>
          <?php endif; ?>
          <th style="width:130px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($BK as $i => $t): $canc = $t['status'] !== 'active';
              $szs = array_values(array_filter($t['items'], fn($x) => $x !== '')); ?>
          <tr style="<?= e(ze_stage_tint((string)$t['stage'])) ?><?= $canc ? ';opacity:.55' : '' ?>"
              data-i="<?= $i ?>" data-s="<?= $canc ? '__canc' : e((string)$t['stage']) ?>">
            <td class="code"><?= $i + 1 ?><div style="font-size:9.5px;color:#8a97ab" title="Booked by <?= e($t['uname'] ?? '') ?>"><?= e(date('H:i', strtotime((string)$t['created_at']))) ?></div></td>
            <td><?= e($t['worker_name'] ?? '(removed worker)') ?>
                <div style="font-size:10px"><span class="code" style="font-size:10px"><?= e($t['worker_code'] ?? '') ?></span><?= ze_pay_pill($PAYT[(int)($t['worker_id'] ?? 0)] ?? null) ?></div></td>
            <td><?= e($t['product_name'] ?? '(removed product)') ?>
                <div class="code" style="font-size:10px"><?= e(trim(($t['pi_no'] ?? '') . ($t['customer'] !== '' ? ' · ' . $t['customer'] : ''), ' ·')) ?></div>
                <?php if (($t['descr'] ?? '') !== ''): ?><div class="bk-desc" title="<?= e($t['descr']) ?>"><?= e($t['descr']) ?></div><?php endif; ?></td>
            <td><?= e($t['part_name'] ?? '—') ?>
                <div class="code" style="font-size:10px"><?= e(implode(', ', $t['ops'])) ?></div></td>
            <td><?php
              /* the colour follows POSITION, not a word: the stage that makes the
                 pieces is marked apart from the ones limited by it, whatever you
                 have chosen to call them */
              echo ze_stage_pill((string)$t['stage']);
              if ($canc) echo ' <span class="pill canc">cancelled</span>';
            ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$t['qty'], 2), '0'), '.') ?><?php if ($szs): ?><div class="code" style="font-size:10px;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis;margin-left:auto" title="<?= e(implode(', ', $szs)) ?>"><?= count($szs) > 1 ? count($szs) . ' sizes' : e($szs[0]) ?></div><?php endif; ?></td>
            <?php if ($showMoney): ?>
              <td class="num"><?= number_format((float)$t['rate_applied'], 2) ?></td>
              <td class="num"><?= number_format((float)$t['amount'], 2) ?></td>
            <?php endif; ?>
            <td>
              <?php if (!$canc): ?>
                <button type="button" class="zp-b sm" onclick="bkEdit(<?= $i ?>)">Edit</button>
                <button type="button" class="zp-b sm red" onclick="zeCancel('<?= e(implode(',', $t['ids'])) ?>')">Cancel</button>
              <?php elseif (trim((string)$t['cancel_reason']) !== ''): ?>
                <div class="bk-why" title="<?= e($t['cancel_reason']) ?>"><?= e($t['cancel_reason']) ?></div>
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

  <?php if ($today): ?>
  <dialog id="bkDlg" class="bk-dlg">
    <form method="post" id="editForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="edit_entry">
      <input type="hidden" name="entry_date" value="<?= e($date) ?>">
      <input type="hidden" name="entry_id" value="">
      <h3>Edit booked work</h3>
      <div id="bkWhat" class="bk-what"></div>
      <label>Quantity<input class="zin num" name="new_qty" inputmode="decimal" required></label>
      <label>Worker<select class="zin" name="new_worker" id="bkWorker"></select></label>
      <label>Why is it changed?<input class="zin" name="reason" value="Wrong quantity" required maxlength="150"></label>
      <div class="bk-note">The old line is not deleted: it stays on the list, marked, with this reason. The new quantity is checked against what is left, like any booking.</div>
      <div class="bk-btns"><button type="button" class="zp-b" onclick="document.getElementById('bkDlg').close()">Close</button>
        <button class="zp-b ok">Save the change</button></div>
    </form>
  </dialog>
  <script id="bkJs">
  /* the booked rows as data, for Edit and for the CSV — the same rows as the table */
  var BOOKED = <?= json_encode(array_map(fn($t) => [
      'ids' => implode(',', $t['ids']), 'wid' => (int)($t['worker_id'] ?? 0), 'w' => (string)($t['worker_name'] ?? ''), 'code' => (string)($t['worker_code'] ?? ''),
      'pt' => ['salary' => 'Fixed salary', 'daily' => 'Daily wages', 'contract' => 'Contractor'][$PAYT[(int)($t['worker_id'] ?? 0)] ?? ''] ?? '',
      'pi' => (string)($t['pi_no'] ?? ''), 'cust' => (string)($t['customer'] ?? ''), 'prod' => (string)($t['product_name'] ?? ''),
      'desc' => (string)($t['descr'] ?? ''), 'part' => (string)($t['part_name'] ?? ''), 'ops' => implode(', ', $t['ops']),
      'sizes' => implode(', ', array_values(array_filter($t['items'], fn($x) => $x !== ''))), 'st' => (string)$t['stage'],
      'status' => $t['status'] === 'active' ? 'Active' : 'Cancelled', 'qty' => round((float)$t['qty'], 2),
      'rate' => $showMoney ? (float)$t['rate_applied'] : null, 'amt' => $showMoney ? round((float)$t['amount'], 2) : null,
      'at' => date('H:i', strtotime((string)$t['created_at'])), 'by' => (string)($t['uname'] ?? ''), 'why' => (string)($t['cancel_reason'] ?? ''),
  ], $BK), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var BKDATE = <?= json_encode($date) ?>, BKMONEY = <?= $showMoney ? 'true' : 'false' ?>;
  var BKWORKERS = <?= json_encode(array_map(fn($w) => [(int)$w['id'], $w['worker_code'] . ' · ' . $w['worker_name']], $PICK), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  (function(){
    var seg = '';
    function low(r){ return [r.w, r.code, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.by].join(' ').toLowerCase(); }
    window.bkFilter = function(){
      var t = document.getElementById('bkQ').value.trim().toLowerCase().split(/\s+/).filter(Boolean), n = 0;
      [].forEach.call(document.querySelectorAll('#bkT tbody tr'), function(tr){
        var r = BOOKED[+tr.dataset.i], h = low(r);
        var ok = (!seg || tr.dataset.s === seg) && t.every(function(w){ return h.indexOf(w) >= 0; });
        tr.hidden = !ok; if (ok) n++;
      });
      document.getElementById('bkCnt').textContent = n + ' of ' + BOOKED.length;
    };
    document.getElementById('bkSeg').addEventListener('click', function(e){
      var b = e.target.closest('button'); if (!b) return;
      seg = b.dataset.s;
      [].forEach.call(this.querySelectorAll('button'), function(x){ x.classList.toggle('on', x === b); });
      bkFilter();
    });
    /* CSV OF WHAT IS ON SCREEN — built here, nothing asked of the server */
    window.bkCsv = function(){
      var head = ['Date','Booked at','By','Worker','Code','Pay type','Order','Customer','Product','Description','Part','Operations','Sizes','Stage','Status','Qty'];
      if (BKMONEY) head.push('Rate','Amount');
      head.push('Cancel reason');
      var lines = [head];
      [].forEach.call(document.querySelectorAll('#bkT tbody tr'), function(tr){
        if (tr.hidden) return;
        var r = BOOKED[+tr.dataset.i];
        var row = [BKDATE, r.at, r.by, r.w, r.code, r.pt, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.status, r.qty];
        if (BKMONEY) row.push(r.rate, r.amt);
        row.push(r.why);
        lines.push(row);
      });
      /* a cell that starts with = + - @ is written as text, so a spreadsheet never runs it */
      var csv = lines.map(function(l){ return l.map(function(v){
        v = v == null ? '' : String(v);
        if (/^[=+\-@]/.test(v) && isNaN(+v)) v = "'" + v;
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], {type:'text/csv;charset=utf-8'}));
      a.download = 'production_' + BKDATE + '.csv'; document.body.appendChild(a); a.click(); a.remove();
    };
    window.bkEdit = function(i){
      var r = BOOKED[i], f = document.getElementById('editForm'), sel = document.getElementById('bkWorker');
      f.entry_id.value = r.ids; f.new_qty.value = r.qty; f.reason.value = 'Wrong quantity';
      document.getElementById('bkWhat').textContent = r.w + ' · ' + r.pi + ' · ' + r.prod + ' · ' + r.part + ' → ' + r.ops + ' · ' + r.st;
      var opts = BKWORKERS.slice(); if (!opts.some(function(o){ return o[0] === r.wid; })) opts.unshift([r.wid, r.code + ' · ' + r.w]);
      sel.innerHTML = opts.map(function(o){ return '<option value="' + o[0] + '"' + (o[0] === r.wid ? ' selected' : '') + '></option>'; }).join('');
      [].forEach.call(sel.options, function(op, k){ op.textContent = opts[k][1]; });
      document.getElementById('bkDlg').showModal(); f.new_qty.select();
    };
    bkFilter();
  })();
  </script>
  <?php endif; ?>

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
  var WORKERS = <?= json_encode(array_map(function ($w) use ($wsMap, $stNm, $PAYT, $HASFACE) {
      $sg = $wsMap[(int)$w['id']] ?? [];
      $sn = [];
      foreach ($sg as $sid) if (isset($stNm[$sid])) $sn[] = $stNm[$sid];
      return [
        'id' => (int)$w['id'], 'code' => (string)$w['worker_code'], 'name' => (string)$w['worker_name'],
        'pt' => $PAYT[(int)$w['id']] ?? '',
        'dept' => (string)($w['department'] ?? ''),
        'ph'  => isset($HASFACE[(int)$w['id']]) ? 1 : 0,
        'sg'  => array_values(array_map('intval', $sg)),
        'hay' => mb_strtolower(trim($w['worker_code'] . ' ' . $w['worker_name'] . ' '
                 . ($w['department'] ?? '') . ' ' . implode(' ', $sn) . ' '
                 . (['salary' => 'fixed salary', 'daily' => 'daily wages', 'contract' => 'contractor'][$PAYT[(int)$w['id']] ?? ''] ?? ''))),
      ];
  }, $PICK)) ?>;
  var PAYW = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractor' };
  function payPill(t){ return PAYW[t] ? '<span class="ze-pt pt-' + t + '">' + PAYW[t] + '</span>' : ''; }
  var HABIT   = <?= json_encode($HABIT) ?>;
  var STN     = <?= json_encode($stNm, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var STL     = <?= json_encode(array_map(fn($s) => [(int)$s['id'], (string)$s['name']], zp_stage_all(true)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
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
  /* STAGE COLOURS — the same rule as ze_stage_style() on the server, so a
     stage reads the same in the list, on the sheet and in the booked table. */
  function stStyle(st){
    var STPAL = [['#f1ecfb','#5a3fa8','#d6cbf2'],['#e3f5f7','#0e6170','#b3e0e6'],['#fbe9f1','#9d174d','#f2c4d8'],['#eef2f6','#33465f','#cfd8e3'],['#fdeee8','#9a3412','#f6cdb9']];
    var l = String(st||'').toLowerCase();
    if (l.indexOf('cut') >= 0) return 'background:#fff4e2;color:#9a5710;border-color:#f1cf97';
    if (/stitch|sew|singer|overlock/.test(l)) return 'background:#e8f1fc;color:#0a4f96;border-color:#b9d3f1';
    if (l.indexOf('pack') >= 0) return 'background:#e8f6ec;color:#1f6b35;border-color:#bfe3ca';
    var h = 0; for (var i=0;i<l.length;i++) h = (h*31 + l.charCodeAt(i)) >>> 0;
    var c = STPAL[h % STPAL.length];
    return 'background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2];
  }
  /* the page skin sets chip colours with !important, so the stage colour says it too */
  /* the row tint: a very light shade of the stage colour, a bar on the left */
  function stTint(st){
    var m = /background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})/.exec(stStyle(st));
    return m ? '--tb:' + m[1] + ';--tl:' + m[2] : '';
  }
  function stChip(st){ return st ? '<span class="ze-chip" style="' + stStyle(st).split(';').join(' !important;') + ' !important;font-weight:600">' + esc(st) + '</span>' : ''; }
  /* one size, or "3 sizes" with the list on hover */
  function szText(w){ return w.nsz > 1 ? w.nsz + ' sizes' : (w.size || ''); }

  function zeWorkOpt(w, click, ev){
    /* THE ORDER NUMBER AND THE PRODUCT, and nothing else on that line.
       The customer name used to sit here too. On a floor list it earns
       nothing — the person booking work knows the style, not the buyer —
       and it pushed the product, which is what they DO recognise, off the
       end of a narrow row. Asked for directly. */
    return { v:w.k, click:click, ev:ev,
             title: w.pn + ' → ' + w.on, strong: w.pi,
             bits: [w.prod, w.desc].filter(Boolean),
             chips:[szText(w), w.nop > 1 ? w.nop + ' operations' : ''].filter(Boolean), szt: w.nsz > 1 ? w.sizes : '', st: w.st,
             rate: w.rate, left: w.left, open: !!w.open };
  }
  /* DOES THIS PERSON WORK THIS STAGE?
     Stated once on the browser side, exactly as zp_worker_does_stage()
     states it on the server — and the two must never drift, or the list
     offers somebody the save then refuses.

     NOTHING ALLOTTED NO LONGER MEANS EVERY STAGE. It used to, and that was
     right while the table was empty. Then two hundred people arrived from
     the HR app — head office, the kitchen, fashion, the garment units — and
     every one of them passed every stage, so allotting forty people to
     Cutting narrowed the Cutting list from 200 to 200.

     Somebody allotted nothing is not "available for everything". They are
     not production, which is the truth about the man in the kitchen.

     THE SAFETY NET IS IN THE CALLER, not here: a stage NOBODY is on yet
     still offers the whole floor, so one stage can be set up this morning
     without the others going dark. */
  function wFits(w, sid){
    if (!sid) return true;
    return !!(w.sg && w.sg.length && w.sg.indexOf(sid) >= 0);
  }

  /* a person, described once */
  function zeWorkerOpt(w, opId, click, ev){
    return { v:w.id, click:click, ev:ev,
             title: w.name, strong: w.code,
             bits: [w.dept].filter(Boolean), chips: [], pay: w.pt,
             usual: opId ? score(w.id, opId) > 0 : usualOps(w.id) > 0 };
  }

  function zeRowHtml(o, t, isCur){
    return '<button type="button" class="ze-row' + (o.st ? ' stg" style="' + stTint(o.st) : '') + '" data-cur="' + (isCur ? 1 : 0) + '"'
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
      +   o.chips.map(function(c){ return '<span class="ze-chip"' + (o.szt ? ' title="' + esc(o.szt) + '"' : '') + '>' + hl(c, t) + '</span>'; }).join('')
      +   (o.st ? stChip(o.st) : '')
      +   (o.pay ? payPill(o.pay) : '')
      +   (o.rate && MONEY ? '<span style="font-family:ui-monospace,monospace">' + n2(o.rate) + '/pc</span>' : '')
      +   (o.usual ? '<span class="ze-usual">usually</span>' : '')
      + '</div></div>'
      + (o.left != null ? '<div class="ze-left"' + (o.open && o.left <= 0 ? ' style="color:#9a5710"' : '') + '>' + qn(o.left) + '<small>' + (o.open ? (o.left > 0 ? 'to cut · open' : 'plan met · open') : 'left') + '</small></div>'
                        : '<div class="ze-left" style="color:#b6c0cf">&rarr;</div>')
      + '</button>';
  }

  /* ============================================================
     THE QUICK FILTER (owner, 27 Nov 2026)
     ------------------------------------------------------------
     "977, 689" keeps only the orders whose PI number ENDS in those digits;
     one stage chip keeps only that stage. Every list on the page honours
     both, and they are kept for this tab (sessionStorage) so they survive
     the reload after Save. All in the browser: the server is not asked.
     ============================================================ */
  var FLT = { pi: '', sid: 0 };
  try { var fsv = JSON.parse(sessionStorage.getItem('zeFlt') || 'null'); if (fsv) FLT = { pi: String(fsv.pi || '').slice(0, 80), sid: +fsv.sid || 0 }; } catch (e) {}
  function fltSave(){ try { sessionStorage.setItem('zeFlt', JSON.stringify(FLT)); } catch (e) {} }
  function piToks(){ return FLT.pi.toLowerCase().split(/[,;\s]+/).filter(Boolean); }
  function piOk(w){
    var tk = piToks(); if (!tk.length) return true;
    var pf = String(w.pifull || w.pi || '').toLowerCase();
    return tk.some(function(x){ return pf.slice(-x.length) === x || (x.length >= 4 && pf.indexOf(x) >= 0); });
  }
  function workOk(w){ return piOk(w) && (!FLT.sid || +w.sid === FLT.sid); }
  /* a stage chip on the people tab: those ticked for it (nobody ticked for it yet: everybody) */
  function workerOk(w){
    if (!FLT.sid) return true;
    var any = WORKERS.some(function(x){ return x.sg && x.sg.indexOf(FLT.sid) >= 0; });
    return !any || wFits(w, FLT.sid);
  }
  function paintFlt(){
    var box = $('zeFSt'); if (!box) return;
    var cnt = {}, all = 0;
    WORK.forEach(function(w){ if (w.done || w.wait || !piOk(w)) return; all++; cnt[w.sid] = (cnt[w.sid] || 0) + 1; });
    box.innerHTML = '<button type="button" class="ze-fc' + (FLT.sid ? '' : ' on') + '" data-sid="0" style="' + (FLT.sid ? 'background:#fff;color:#33465f;border-color:#cfd8e3' : 'background:#33465f;color:#fff;border-color:#33465f') + '" title="Alt+0">All <b>' + all + '</b></button>'
      + (STL || []).map(function(s, i){
          var m = /background:(#[0-9a-f]{6});color:(#[0-9a-f]{6});border-color:(#[0-9a-f]{6})/.exec(stStyle(s[1])) || [0, '#eef2f6', '#33465f', '#cfd8e3'], on = FLT.sid === s[0];
          return '<button type="button" class="ze-fc' + (on ? ' on' : '') + '" data-sid="' + s[0] + '" title="' + esc(s[1]) + (i < 9 ? ' — Alt+' + (i + 1) : '') + '" style="'
            + (on ? 'background:' + m[2] + ';color:#fff;border-color:' + m[2] : 'background:' + m[1] + ';color:' + m[2] + ';border-color:' + m[3]) + '">'
            + esc(s[1]) + ' <b>' + (cnt[s[0]] || 0) + '</b></button>';
        }).join('');
    var fp = $('zeFPi').closest('.ze-fpi'); if (fp) fp.classList.toggle('on', !!piToks().length);
    $('zeFClr').hidden = !FLT.pi && !FLT.sid;
  }
  function fltChanged(){ fltSave(); paintFlt(); cur = 0; if (!picked) draw(); }

  /* ============================================================
     THE PREVIEW PANE (owner, 27 Nov 2026)
     ------------------------------------------------------------
     Whatever row is highlighted — by the arrow keys or under the mouse —
     is shown in full beside the list: order, customer, product, the
     proforma description, each size with its own made / left, the rate and
     what is left to earn. Built from the data already on the page, so it
     costs the server nothing.
     ============================================================ */
  var PAYC = { salary: ['#e8f1fc','#0a4f96','#b9d3f1'], daily: ['#fff4e2','#9a5710','#f1cf97'], contract: ['#f1ecfb','#5a3fa8','#d6cbf2'] };
  var OPN = {}; WORK.forEach(function(w){ for (var o in (w.mop || {})) OPN[o] = (w.pn ? w.pn + ' → ' : '') + w.mop[o]; });
  function workByKey(k){ for (var i = 0; i < WORK.length; i++) if (WORK[i].k === k) return WORK[i]; return null; }
  function workerById(id){ for (var i = 0; i < WORKERS.length; i++) if (WORKERS[i].id === +id) return WORKERS[i]; return null; }
  function pvWork(w, forWorker){
    var m = /background:(#[0-9a-f]{6});color:(#[0-9a-f]{6});border-color:(#[0-9a-f]{6})/.exec(stStyle(w.st)) || [0, '#eef2f6', '#33465f', '#cfd8e3'];
    var need = 0, left = 0; (w.mem || []).forEach(function(x){ need += +x[3] || 0; left += Math.max(0, +x[2] || 0); });
    if (!need) need = left;
    var made = Math.max(0, need - left), pct = need > 0 ? Math.min(100, made / need * 100) : 0, set = w.kind === 'set' || w.kind === 'line';
    var h = '<div class="pv-hd" style="background:' + m[1] + ';color:' + m[2] + ';border-color:' + m[3] + '">'
      + '<div class="pv-k">' + (set ? 'Set / pack work · per set' : 'Part work') + (w.st ? ' · ' + esc(w.st) : '') + '</div>'
      + '<div class="pv-t">' + esc(w.pn) + ' → ' + esc(w.on) + '</div>'
      + (w.nop > 1 ? '<span class="pv-ch">' + w.nop + ' operations</span>' : '') + (w.nsz > 1 ? '<span class="pv-ch">' + w.nsz + ' sizes</span>' : (w.size ? '<span class="pv-ch">Size ' + esc(w.size) + '</span>' : ''))
      + (MONEY ? '<span class="pv-ch">' + n2(w.rate) + ' / ' + (set ? 'set' : 'pc') + '</span>' : '') + '</div><div class="pv-b">'
      + '<div class="pv-g"><span>Order</span><b>' + esc(w.pifull || w.pi) + '</b>'
      + (w.cust ? '<span>Customer</span><div>' + esc(w.cust) + '</div>' : '')
      + '<span>Product</span><b class="pv-prod">' + esc(w.prod) + '</b>'
      + (w.desc ? '<span>Description</span><div class="pv-d">' + esc(w.desc) + '</div>' : '')
      + (w.sizes ? '<span>Sizes</span><div>' + esc(w.sizes) + '</div>' : '') + '</div>'
      + '<div class="pv-kp"><div><small>' + (set ? 'Sets' : 'Pieces') + '</small><b>' + qn(need) + '</b></div><div><small>Made</small><b style="color:#0a4f96">' + qn(made) + '</b></div>'
      + '<div><small>Left</small><b style="color:' + (left > 0 ? '#0d9488' : '#9a5710') + '">' + qn(left) + '</b></div></div>'
      + '<div class="pv-bar" title="' + Math.round(pct) + '% made"><i style="width:' + pct + '%"></i></div>';
    if ((w.mem || []).length > 1 || w.nop > 1) {
      h += '<table class="pv-t2"><tr><th>Size</th>' + (w.nop > 1 ? '<th>Operation</th>' : '') + '<th class="n">Need</th><th class="n">Left</th><th style="width:60px">Made</th></tr>'
        + w.mem.map(function(x){ var nd = +x[3] || 0, lf = Math.max(0, +x[2] || 0), p = nd > 0 ? Math.min(100, (nd - lf) / nd * 100) : 0;
            return '<tr><td><span class="pv-sz">' + esc((w.msz || {})[x[0]] || '—') + '</span></td>' + (w.nop > 1 ? '<td>' + esc((w.mop || {})[x[1]] || '') + '</td>' : '')
              + '<td class="n">' + qn(nd) + '</td><td class="n"><b>' + qn(lf) + '</b></td><td><div class="mb"><i style="width:' + p + '%"></i></div></td></tr>'; }).join('')
        + '</table>';
      if (w.nsz > 1) h += '<div class="pv-ft">A quantity typed here is shared across the sizes by their ordered %.</div>';
    }
    if (MONEY && left > 0) h += '<div class="pv-g" style="margin-top:8px"><span>Left to earn</span><b>PKR ' + n2(left * w.rate) + '</b></div>';
    if (w.open) h += '<div class="pv-note">Cutting is open: you may cut more than the order needs — the extra is shown as information.</div>';
    if (forWorker) { var sc = score(forWorker.id, w.op); if (sc) h += '<div class="pv-ft">' + esc(forWorker.name) + ' has done this ' + sc + ' time' + (sc === 1 ? '' : 's') + ' before.</div>'; }
    return h + '<div class="pv-ft"><kbd>Enter</kbd> choose · <kbd>↑</kbd><kbd>↓</kbd> move</div></div>';
  }
  /* THE FACE: initials at once; the photo only once the pane has rested on
     the person for a moment, and never twice — the browser keeps it a day */
  var FACE = {}, faceT = null;
  function ini(n){ return String(n || '').trim().split(/\s+/).slice(0, 2).map(function(x){ return x.charAt(0); }).join('').toUpperCase(); }
  function faceHtml(w, col){
    if (w.ph && FACE[w.id] && FACE[w.id] !== 'none' && FACE[w.id] !== 'wait') return '<img class="pv-face" alt="" src="' + esc(FACE[w.id]) + '">';
    return '<span class="pv-ini" data-face="' + w.id + '" style="background:' + col + '">' + esc(ini(w.name)) + '</span>';
  }
  function faceLoad(w){
    if (!w.ph || FACE[w.id]) return;
    clearTimeout(faceT);
    faceT = setTimeout(function(){
      if (FACE[w.id]) return;
      FACE[w.id] = 'wait';
      var url = 'production_entry.php?face=' + w.id, im = new Image();
      im.onload = function(){ FACE[w.id] = url; [].forEach.call(document.querySelectorAll('.pv-ini[data-face="' + w.id + '"]'), function(s){
        var i = document.createElement('img'); i.className = 'pv-face'; i.alt = ''; i.src = url; s.parentNode.replaceChild(i, s); }); };
      im.onerror = function(){ FACE[w.id] = 'none'; };
      im.src = url;
    }, 250);
  }
  function pvWorker(w, forWork){
    var c = PAYC[w.pt] || ['#eef2f6', '#33465f', '#cfd8e3'];
    faceLoad(w);
    var hab = (HABIT.byWorker || {})[w.id] || {}, top = Object.keys(hab).sort(function(a, b){ return hab[b] - hab[a]; }).slice(0, 5);
    var onSheet = 0, nSheet = 0; sheet.forEach(function(x){ if (+x.worker === w.id) { onSheet += +x.qty || 0; nSheet++; } });
    var booked = 0; if (typeof BOOKED !== 'undefined') BOOKED.forEach(function(b){ if (b.wid === w.id && b.status === 'Active') booked += +b.qty || 0; });
    var h = '<div class="pv-hd has-face" style="background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2] + '">' + faceHtml(w, c[1])
      + '<div class="pv-k">Worker' + (PAYW[w.pt] ? ' · ' + PAYW[w.pt] : '') + '</div><div class="pv-t">' + esc(w.name) + '</div>'
      + '<span class="pv-ch">' + esc(w.code) + '</span>' + (w.dept ? '<span class="pv-ch">' + esc(w.dept) + '</span>' : '') + '</div><div class="pv-b">'
      + '<div class="pv-g"><span>Stages</span><div>' + ((w.sg || []).length ? w.sg.map(function(s){ return STN[s] ? stChip(STN[s]) + ' ' : ''; }).join('') : '<span style="color:#8a97ab">not allotted</span>') + '</div>'
      + '<span>Booked today</span><b>' + qn(booked) + ' pcs</b>'
      + (nSheet ? '<span>On this sheet</span><b>' + qn(onSheet) + ' pcs · ' + nSheet + ' line' + (nSheet === 1 ? '' : 's') + '</b>' : '') + '</div>';
    if (forWork) { var sc = score(w.id, forWork.op); h += '<div class="pv-g"><span>This job</span><b>' + (sc ? 'done ' + sc + ' time' + (sc === 1 ? '' : 's') + ' before' : 'not done before') + '</b></div>'; }
    if (top.length) h += '<table class="pv-t2"><tr><th>Usually does</th><th class="n">Times</th></tr>'
      + top.map(function(o){ return '<tr><td>' + esc(OPN[o] || ('operation #' + o)) + '</td><td class="n">' + hab[o] + '</td></tr>'; }).join('') + '</table>';
    return h + '<div class="pv-ft"><kbd>Enter</kbd> choose · <kbd>↑</kbd><kbd>↓</kbd> move</div></div>';
  }
  /* the top list: tab A lists work, tab B lists people */
  function pvTop(v){
    var p = $('zePrev'); if (!p) return;
    if (picked || v == null) { p.hidden = true; return; }
    var o = tab === 'A' ? workByKey(v) : workerById(v);
    if (!o) { p.hidden = true; return; }
    p.innerHTML = tab === 'A' ? pvWork(o) : pvWorker(o); p.hidden = false;
  }
  function pvTopCur(){ var c = document.querySelector('#zeRes .ze-row[data-cur="1"]') || document.querySelector('#zeRes .ze-row'); pvTop(c ? c.getAttribute('data-pick') : null); }
  /* the in-row list: tab A lists people for the chosen work, tab B lists work for the chosen person */
  function pvSug(v){
    var box = $('zeSugBox'), p = $('zeSugPrev');
    if (!p) { p = document.createElement('aside'); p.id = 'zeSugPrev'; p.className = 'ze-pv zesugpv'; p.hidden = true; (document.querySelector('.zskin') || document.body).appendChild(p); }
    if (!box || box.hidden || v == null) { p.hidden = true; return; }
    var o = tab === 'A' ? workerById(v) : workByKey(v);
    if (!o) { p.hidden = true; return; }
    p.innerHTML = tab === 'A' ? pvWorker(o, picked) : pvWork(o, picked);
    var r = box.getBoundingClientRect(), w = 340;
    if (r.right + 10 + w <= window.innerWidth) p.style.left = (r.right + 8) + 'px';
    else if (r.left - 10 - w >= 0) p.style.left = (r.left - w - 8) + 'px';
    else { p.hidden = true; return; }                       // a phone: no room beside it, the list alone is clearer
    p.style.top = Math.max(8, Math.min(r.top, window.innerHeight - 260)) + 'px'; p.hidden = false;
  }
  function pvSugCur(){ var b = $('zeSugBox'); var c = b && (b.querySelector('.ze-row[data-cur="1"]') || b.querySelector('.ze-row')); pvSug(c ? c.getAttribute('data-pick') : null); }

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

  /* JOBS ADDED BY HAND THAT ARE WAITING (27 Nov 2026): shown greyed under the
     list, never pickable — plain divs, so the arrow keys and Enter pass them */
  function waitList(t){ return tab === 'A' ? WORK.filter(function(w){ return w.wait && workOk(w) && hit(w.hay, t); }) : []; }
  function waitHtml(ws){
    if (!ws.length) return '';
    return '<div class="ze-wh">Added for this order — waiting for the stage before</div>' + ws.slice(0, 20).map(function(w){
      return '<div class="ze-wait" title="Nothing can be booked on it yet">'
        + '<div><div class="ze-t">' + esc(w.pn) + ' → ' + esc(w.on) + '</div><div class="ze-s"><b>' + esc(w.pi) + '</b><i>&middot;</i>' + esc(w.prod)
        + (w.desc ? '<i>&middot;</i>' + esc(w.desc) : '') + (szText(w) ? '<span class="ze-chip">' + esc(szText(w)) + '</span>' : '') + stChip(w.st) + '</div></div>'
        + '<div class="ze-left">' + esc(w.wnote || 'waiting') + '</div></div>';
    }).join('');
  }
  function tabList(){
    var t = terms();
    /* only OPEN work is offered; finished rows are kept for the message below */
    if (tab === 'A') return WORK.filter(function(w){ return !w.done && !w.wait && workOk(w) && hit(w.hay, t); });
    var list = WORKERS.filter(function(w){ return workerOk(w) && hit(w.hay, t); });
    /* the people who actually book work come first — an empty search on a
       floor of 200 should not open with whoever happened to be added first */
    return list.slice().sort(function(a,b){ return usualOps(b.id) - usualOps(a.id); });
  }

  function draw(){
    var box = $('zeRes'), t = terms();
    if (picked) { box.innerHTML=''; $('zeCnt').textContent=''; pvTop(null); return; }
    var list = tabList(), waits = waitList(t);
    $('zeCnt').textContent = list.length + (list.length === 1 ? ' match' : ' matches') + (waits.length ? ' · ' + waits.length + ' waiting' : '');
    if (!list.length && waits.length) { box.innerHTML = '<div class="ze-none">Nothing can be booked here yet.</div>' + waitHtml(waits); pvTop(null); return; }
    if (!list.length) {
      pvTop(null);
      /* "NOTHING MATCHES" AND "THAT IS ALL DONE" ARE DIFFERENT ANSWERS.
         One is a typo to correct; the other is good news. Saying the first when
         the second is true sends somebody hunting for a job that is finished. */
      var fin = (tab === 'A') ? WORK.filter(function(w){ return w.done && workOk(w) && hit(w.hay, t); }).length : 0;
      box.innerHTML = fin
        ? '<div class="ze-none"><b>' + fin + ' matching job' + (fin===1?' is':'s are')
          + ' already finished.</b><br>Nothing is left to book on '
          + (fin===1?'it':'them') + '.</div>'
        : '<div class="ze-none">Nothing matches &ldquo;' + esc($('zeQ').value) + '&rdquo;'
          + (FLT.pi || FLT.sid ? ' with the filter ' + esc([FLT.pi ? 'PI ' + FLT.pi : '', FLT.sid ? STN[FLT.sid] || '' : ''].filter(Boolean).join(' · ')) + ' &mdash; <a href="#" onclick="zeFltClear();return false">clear it</a>' : '') + '.</div>';
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
    if (waits.length) box.insertAdjacentHTML('beforeend', waitHtml(waits));
    pvTopCur();
  }
  /* the mouse shows a row too, without moving the keyboard's place */
  $('zeRes').addEventListener('mouseover', function(e){ var r = e.target.closest('.ze-row'); if (r) pvTop(r.getAttribute('data-pick')); });
  $('zeRes').addEventListener('mouseleave', pvTopCur);

  window.zePick = function(i){
    var w = tabList()[i]; if (!w) return;
    picked = w;
    $('zeHead').innerHTML = (tab === 'A')
      ? '<div class="ze-head"><div><h3>' + esc(w.pn) + ' → ' + esc(w.on) + '</h3>'
        + '<div style="font-size:12px;color:#38495f"><b>' + esc(w.pi) + '</b> &middot; ' + esc(w.cust)
        + ' &middot; ' + esc(w.prod) + (w.desc ? ' &middot; <span style="color:#556b82">' + esc(w.desc) + '</span>' : '') + '</div><div class="ze-chips">'
        + (w.nsz > 1 ? '<span class="ze-chip" title="Shared across the sizes by ordered %">' + w.nsz + ' sizes: ' + esc(w.sizes) + '</span>'
                     : (w.size ? '<span class="ze-chip">Size ' + esc(w.size) + '</span>' : ''))
        + (w.nop > 1 ? '<span class="ze-chip" title="Shared over the operations, first operation first">' + w.nop + ' operations</span>' : '')
        + stChip(w.st)
        + (MONEY ? '<span class="ze-chip">' + n2(w.rate) + ' / pc</span>' : '')
        + (w.open ? '<span class="ze-chip" style="color:#9a5710;border-color:#e9a23b" title="Cutting is open: you may cut more than the order needs; the extra is shown as information">' + qn(w.left) + ' to cut · open</span>'
                  : '<span class="ze-chip" style="color:#0d9488;border-color:#0d9488">' + qn(w.left) + ' left</span>')
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>'
      : '<div class="ze-head"><div><h3>' + esc(w.name) + '</h3><div class="ze-chips">'
        + '<span class="ze-chip">' + esc(w.code) + '</span>'
        + (w.dept ? '<span class="ze-chip">' + esc(w.dept) + '</span>' : '')
        + payPill(w.pt)
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>';
    $('zeRes').innerHTML = ''; $('zeCnt').textContent = ''; pvTop(null);
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
      /* THE TICKS DECIDE, TYPING INCLUDED (owner, 19 Nov 2026): "if we making
         cutting stage so list only who ticked as cutting". A Cutting job offers
         only the people ticked for Cutting on Production Workers — a typed name
         searches inside that list, not the whole floor. To let somebody stand
         in, tick the stage for them there. The safety net stays: a stage that
         NOBODY is ticked for yet still offers everybody. */
      var all = WORKERS.filter(function(w){ return hit(w.hay, t); });
      var sid = picked ? (picked.sid || 0) : 0;
      var use = all, narrowed = 0;
      if (sid) {
        /* NARROW ONLY WHEN SOMEBODY IS REALLY ON THIS STAGE.
           Testing "is the short list non-empty" was not enough and the test
           caught it: workers allotted NOTHING pass wFits for every stage, so a
           stage nobody has been put on yet still produced a list — of just
           those few — and three hundred people vanished behind a footnote.
           So the count is of people who NAME this stage. None of them means
           the stage means nothing yet, and the whole floor is offered. */
        var onStage = WORKERS.some(function(w){ return w.sg && w.sg.indexOf(sid) >= 0; });
        var fit = all.filter(function(w){ return wFits(w, sid); });
        if (onStage) { narrowed = all.length - fit.length; use = fit; }
      }
      var outw = use.slice().sort(function(a,b){ return score(b.id, opId) - score(a.id, opId); })
        .map(function(w){ return zeWorkerOpt(w, opId, click(), ''); });
      /* The cut is SAID, like every other cut on this screen. A list that
         silently stops short reads as "that person does not exist". */
      /* THE CUT IS SAID, and now it says which way round it is. With two
         hundred people synced from HR and a handful allotted, "191 more"
         is not a footnote about a filter — it is the screen telling him
         the allotment is doing its job. Nobody is unreachable: typing a
         name searches the whole floor. */
      outw.foot = narrowed
        ? 'Only people ticked for ' + esc(picked.st) + ' are listed — tick more on Production Workers.'
        : '';
      return outw;
    }
    var wid = picked ? picked.id : 0;
    /* the other way round: a person picked first is offered only the work of
       the stages they are ticked for (nobody ticked anywhere: everything) */
    var mine = picked && picked.sg && picked.sg.length ? picked.sg : null;
    return WORK.filter(function(w){ return !w.done && !w.wait && workOk(w) && hit(w.hay, t) && (!mine || !w.sid || mine.indexOf(w.sid) >= 0); })
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
      b.addEventListener('mouseover', function(e){ var r = e.target.closest ? e.target.closest('.ze-row') : null; if (r) pvSug(r.getAttribute('data-pick')); });
      b.addEventListener('mouseleave', pvSugCur);
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
    if (el) { zeSugPlace(el); pvSugCur(); } else zeSugHide();
  }
  window.addEventListener('scroll', zeSugTrack, true);
  window.addEventListener('resize', zeSugTrack);
  function zeSugHide(){ var b = $('zeSugBox'); if (b) b.hidden = true; sugRow = -1; pvSug(null); }

  window.zeSug = function(i, el){
    rows[i].label = el.value;
    var pop = zeSugBox(), list = sugList(i, el.value);
    sugRow = i;
    if (!list.length) { pop.hidden = true; pvSug(null); return; }
    /* 12, not 8, and the cut is stated. Eight was a silent truncation on a
       floor with 200 workers: the name you wanted was simply not there, with
       nothing on screen to say more existed. */
    zePaint(pop, list, (el.value||'').trim().toLowerCase().split(/\s+/).filter(Boolean), 0, 12);
    pop.hidden = false;
    zeSugPlace(el); pvSugCur();
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
      o[k].dataset.cur = '1'; o[k].scrollIntoView({block:'nearest'}); pvSugCur();
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
    sheet.forEach(function(x){ if (x.g === key) n += x.qty; });
    return n;
  }
  /* what is already staged on ONE size line of one operation */
  function stagedItem(item, op, kind){
    var n = 0;
    sheet.forEach(function(x){ if (x.item === item && x.op === op && x.kind === kind) n += x.qty; });
    return n;
  }
  var grpSeq = 0;
  function totals(){
    var q = 0, amt = 0, n = 0, over = false;
    rows.forEach(function(r){ r.over = false; });
    if (tab === 'A') {
      rows.forEach(function(r){ var v = parseFloat(r.q)||0; if (r.v && v>0) { n++; q += v; amt += v * rowRate(r); } });
      if (picked && !picked.open && q + stagedOn(picked.k) > picked.left + 0.0001) {
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
        if (r.v && !(other(r.v) || {}).open && per[r.v] + stagedOn(r.v) > rowLeft(r) + 0.0001) { r.over = true; over = true; }
      });
    }
    var sa = 0;
    sheet.forEach(function(x){ sa += x.qty * x.rate; });
    var ng = {}; sheet.forEach(function(x){ ng[x.grp] = 1; });
    $('rowCount').textContent = Object.keys(ng).length + n;
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
        cap.textContent = picked.open
          ? qn(used) + ' booked · plan ' + qn(picked.left) + (used > picked.left + 0.0001 ? ' · extra ' + qn(used - picked.left) + ' (cutting is open)' : '')
          : qn(used) + ' of ' + qn(picked.left) + ' left' + (over ? ' — OVER' : '');
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
  /* the browser copy of ze_alloc() in includes/zp_correct.php — keep the two the same */
  function zeAlloc(mem, qty, open, taken){
    var unit = Math.abs(qty - Math.round(qty)) < 0.00001 ? 1 : 0.01, rem = Math.round(qty / unit), out = {}, groups = [], gk = {};
    mem.forEach(function(m){ if (gk[m[1]] == null) { gk[m[1]] = groups.length; groups.push([]); } groups[gk[m[1]]].push(m); });
    groups.forEach(function(ms, gi){
      if (rem <= 0) return;
      var caps = {}, w = {}, plan = 0, room = 0;
      ms.forEach(function(m){
        var k = m[0] + ':' + m[1], free = Math.max(0, m[2] - (taken[k] || 0));
        caps[k] = open ? Infinity : Math.floor(free / unit + 0.00001);
        w[k] = Math.max(0, +m[3] || 0);
        plan += Math.floor(free / unit + 0.00001);
        if (!open) room += caps[k];
      });
      if (open) room = gi === groups.length - 1 ? rem : plan;
      var give = Math.min(rem, room), left = give, act = Object.keys(caps).filter(function(k){ return caps[k] > 0; });
      while (left > 0 && act.length) {
        var W = 0; act.forEach(function(k){ W += w[k]; });
        var share = {}, base = {}, sum = 0;
        act.forEach(function(k){ share[k] = W > 0 ? left * w[k] / W : left / act.length; base[k] = Math.floor(share[k] + 0.000001); sum += base[k]; });
        var order = act.slice().sort(function(a, b){ return (share[b] - base[b]) - (share[a] - base[a]); });
        for (var i = 0; sum < left && i < order.length; i++) { base[order[i]]++; sum++; }
        var next = [];
        act.forEach(function(k){
          var n = Math.min(base[k], caps[k]);
          if (n > 0) { out[k] = (out[k] || 0) + n; caps[k] -= n; left -= n; }
          if (caps[k] > 0) next.push(k);
        });
        if (next.length === act.length && sum === 0) break;
        act = next;
      }
      rem -= (give - left);
    });
    Object.keys(out).forEach(function(k){ out[k] = Math.round(out[k] * unit * 100) / 100; });
    return [out, Math.round(rem * unit * 100) / 100];
  }

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
      /* ONE TYPED QUANTITY, ONE LINE ON SCREEN (grp). It goes to the server as
         one line per size, so zp_book checks and prices every one. Split by
         ordered % since 22 Nov 2026 (it was first-size-first). */
      var kind = work.kind || 'part', grp = ++grpSeq;
      var mem = work.mem && work.mem.length ? work.mem : [[work.item, work.op, work.left, work.left]];
      var base = { kind:kind, g:work.k, grp:grp, worker:worker.id, rate:work.rate,
                   what:work.pn + ' → ' + work.on, who:worker.name, pt:worker.pt, pi:work.pi, st:work.st,
                   prod:work.prod, sz:szText(work) };
      /* BY ORDERED % (owner, 22 Nov 2026): the quantity is shared across the
         sizes by each size's share of the order — the same rules as ze_alloc()
         on the server. Cutting is open, so nothing is held back there. */
      var taken = {};
      mem.forEach(function(m){ taken[m[0] + ':' + m[1]] = stagedItem(m[0], m[1], kind); });
      var res = zeAlloc(mem, v, !!work.open, taken);
      Object.keys(res[0]).forEach(function(k){
        var p = k.split(':');
        if (res[0][k] > 0) sheet.push(Object.assign({}, base, { item:+p[0], op:+p[1], qty:res[0][k] }));
      });
      /* cannot happen — totals() refuses a line past what is left — but a
         remainder is never dropped silently: it goes on the last line and the
         server refuses it with its own message */
      if (res[1] > 0.0001) sheet.push(Object.assign({}, base, { item:mem[mem.length-1][0], op:mem[mem.length-1][1], qty:res[1] }));
    });
    zeReset();
    paintSheet();
  };

  function paintSheet(){
    var el = $('zeSheet');
    if (!sheet.length) { el.innerHTML = ''; totals(); return; }
    /* one line per booking as typed, however many sizes it was shared over */
    var gs = [], at = {};
    sheet.forEach(function(x){
      if (at[x.grp] == null) { at[x.grp] = gs.length; gs.push({ x:x, qty:0, n:0 }); }
      var g = gs[at[x.grp]]; g.qty += x.qty; g.n++;
    });
    var h = '<div class="ze-stash"><h4>Waiting to be saved — ' + gs.length + ' line'
          + (gs.length === 1 ? '' : 's') + '</h4>';
    gs.forEach(function(g){
      var x = g.x;
      h += '<div class="ze-sl"><div><b>' + esc(x.who) + '</b> ' + payPill(x.pt) + ' &middot; ' + esc(x.what)
         + ' ' + stChip(x.st)
         + ' <span style="color:#8a97ab">(' + esc(x.pi) + (x.prod ? ' &middot; ' + esc(x.prod) : '') + ')</span>'
         + (g.n > 1 ? ' <span style="color:#8a97ab;font-size:11px">shared over ' + g.n + ' lines</span>' : '') + '</div>'
         + '<div style="display:flex;gap:11px;align-items:center">'
         + '<span style="font-family:ui-monospace,monospace"><b>' + qn(g.qty) + '</b> pcs'
         + (MONEY ? ' &middot; ' + n2(g.qty * x.rate) : '') + '</span>'
         + '<button type="button" class="x" onclick="zeUnstash(' + x.grp + ')">&times;</button></div></div>';
    });
    el.innerHTML = h + '</div>';
    totals();
  }
  window.zeUnstash = function(grp){ sheet = sheet.filter(function(x){ return x.grp !== grp; }); paintSheet(); };

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
    var nb = {}; sheet.forEach(function(x){ nb[x.grp] = 1; });
    var hb = document.createElement('input'); hb.type = 'hidden'; hb.className = 'zePost'; hb.name = 'n_book';
    hb.value = Object.keys(nb).length; f.appendChild(hb);
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

  /* ---- the quick filter's controls ---- */
  $('zeFPi').value = FLT.pi;
  $('zeFPi').addEventListener('input', function(){ FLT.pi = this.value.slice(0, 80); fltChanged(); });
  $('zeFPi').addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); $('zeQ').focus(); } });
  $('zeFSt').addEventListener('click', function(e){
    var b = e.target.closest('.ze-fc'); if (!b) return;
    var sid = +b.dataset.sid; FLT.sid = FLT.sid === sid ? 0 : sid; fltChanged();
    var again = $('zeFSt').querySelector('[data-sid="' + b.dataset.sid + '"]'); if (again && e.detail === 0) again.focus(); else $('zeQ').focus();
  });
  window.zeFltClear = function(){ FLT = { pi: '', sid: 0 }; $('zeFPi').value = ''; fltChanged(); $('zeQ').focus(); };
  $('zeFClr').addEventListener('click', zeFltClear);
  /* Alt+1..9 picks a stage, Alt+0 shows all — no mouse needed */
  document.addEventListener('keydown', function(e){
    if (!e.altKey || e.ctrlKey || e.metaKey) return;
    var n = parseInt(e.key, 10); if (isNaN(n)) return;
    if (n === 0) FLT.sid = 0; else if (STL && STL[n - 1]) FLT.sid = FLT.sid === STL[n - 1][0] ? 0 : STL[n - 1][0]; else return;
    e.preventDefault(); fltChanged();
  });
  paintFlt();

  zeTab('A');
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
