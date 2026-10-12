<?php
/*
  PACKING — the phone version. SMART PACKING.

  Built from afnan's own demo ("Smart Packing final"): "this feature and
  function is matching with my need — copy and apply on your existing
  mobile packing file."

  ONE PAGE, NEVER RELOADED. Packing / Records / Summary are tabs drawn in
  the browser; every save is one small request and the answer carries the
  whole packing state back (see the API below and pack_state()).

  EVERY PACKAGE IS ITS OWN RECORD. A range is stored once, but one package
  of it can be edited, copied or deleted on its own — the server splits
  the range (pack_split_out) so the rest is untouched.

  WHAT IS IN THE PACKAGE IS COUNTED. Pick a size, tap a colour (or style)
  once per piece. One combination is standard packing, more than one is
  assorted — decided by what was counted, not by a switch.

  A NUMBER ONCE PER PREFIX AND KIND OF PACKAGE. Carton 1 and Roll 1 may
  both exist; two Carton 1s may not. Save & Next moves to the next free
  number by itself.

  WEIGHT IS NOT HERE. It is moving to its own module. Until that exists
  the old weight screen is kept and linked from Summary (t=weight).

  IT NEEDS SIGNAL, like every page here. Only the unsaved draft is kept in
  the browser, so a half-counted carton survives a dropped connection.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/packing.php';
require_once __DIR__ . '/includes/mobile.php';

/* Uploaded by hand, so say plainly when a file was missed rather than
   dying halfway down the page. See mob_needs(). */
if (function_exists('mob_needs')) {
    mob_show_fatal();
    mob_needs(['mob_header', 'mob_footer', 'mob_flash',
               'mob_steps_begin', 'mob_step', 'mob_steps_end', 'mob_card_error',
               'mob_steps_scroll'], 'includes/mobile.php');
    mob_needs(['pack_palette', 'pack_unit_key', 'pack_wkey', 'pack_colour_swatch',
               'pack_weight_missing', 'pack_dedupe', 'pack_state', 'pack_package_save',
               'pack_package_delete', 'pack_split_out', 'pack_finish', 'pack_reopen',
               'pack_line_axes'], 'includes/packing.php');
} else {
    http_response_code(500);
    exit('Upload includes/mobile.php — the copy on the server is older than this screen.');
}
require_login();

if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot open the packing list.'); }
exp_ensure_schema();
pack_ensure_schema();

$id  = (int)($_GET['id']  ?? $_POST['shipment_id'] ?? 0);
$tab = (string)($_GET['t'] ?? 'pack');
$gid = (int)($_GET['g']   ?? 0);
$sz  = (string)($_GET['s'] ?? '');

/* ------------------------------------------------------- pick a shipment */
if ($id <= 0) {
    $ids = assigned_shipment_ids();
    $rows = [];
    try {
        if ($ids === ['ALL']) {
            $rows = db()->query("SELECT id, invoice_no, buyer_name, packing_status, status
                                 FROM shipments ORDER BY id DESC LIMIT 60")->fetchAll();
        } elseif ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = db()->prepare("SELECT id, invoice_no, buyer_name, packing_status, status
                                FROM shipments WHERE id IN ($in) ORDER BY id DESC");
            $s->execute($ids);
            $rows = $s->fetchAll();
        }
    } catch (Throwable $e) { $rows = []; }

    mob_header('Packing', 'm.php', 'Pick a shipment', 'manifest_pack.json');
    mob_flash();
    if (!$rows) {
        echo '<div class="empty">No shipment is assigned to you.</div>';
    } else {
        foreach ($rows as $r) {
            $done = ($r['packing_status'] ?? 'open') === 'completed';
            echo '<a class="mcard" style="display:block;text-decoration:none;color:inherit" href="m_pack.php?id=' . (int)$r['id'] . '">'
               . '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center">'
               . '<b>' . e((string)$r['invoice_no']) . '</b>'
               . '<span class="pill ' . ($done ? 'p' : 'v') . '">' . ($done ? 'completed' : 'open') . '</span></div>'
               . '<div class="note" style="margin-top:4px">' . e((string)$r['buyer_name']) . '</div></a>';
        }
    }
    mob_footer();
    exit;
}

/* ------------------------------------------------------------ the shipment */
$s = db()->prepare("SELECT * FROM shipments WHERE id=?");
$s->execute([$id]);
$shipment = $s->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

$ids = assigned_shipment_ids();
if ($ids !== ['ALL'] && !in_array($id, $ids, true)) {
    http_response_code(403); exit('That shipment is not assigned to you.');
}
$canEdit = pack_may_edit($shipment);

$itStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
$itStmt->execute([$id]);
$items = $itStmt->fetchAll();

/* The weight screen still draws its cards as steps on one scrolling page. */
mob_steps_scroll(true);

$title = 'Packing — ' . (string)$shipment['invoice_no'];
$back  = 'm_pack.php';
$self  = 'm_pack.php?id=' . $id;

/* ============================================================ the app's API

   The Smart Packing screen never reloads. Every change is one JSON POST
   here, and the answer carries the whole packing state back, so what is
   on screen is always what is saved.

     op=save    one package or a range (or an edit of one package)
     op=delete  one package; the rest of its range stays
     op=finish  write the packing list rows and totals, mark it complete
     op=reopen  open it again

   The same CSRF token and the same permission as every other write. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $_POST['_csrf'] = (string)($in['_csrf'] ?? '');
    verify_csrf();
    $answer = function (bool $ok, string $msg) use ($id, $items): void {
        $s = db()->prepare("SELECT * FROM shipments WHERE id=?");
        $s->execute([$id]);
        echo json_encode(['ok' => $ok, 'msg' => $msg, 'state' => pack_state($id, $items, $s->fetch() ?: [])]);
        exit;
    };
    if (!$canEdit) { http_response_code(403); $answer(false, 'This packing list can no longer be changed.'); }
    $op   = (string)($in['op'] ?? '');
    $done = ($shipment['packing_status'] ?? 'open') === 'completed';
    if ($done && in_array($op, ['save', 'delete', 'finish'], true)) {
        $answer(false, 'Packing is finished. Reopen it on Summary to change anything.');
    }
    try {
        if ($op === 'save') {
            [$ok, $msg] = pack_package_save($id, $items, $in);
            $answer($ok, $msg);
        }
        if ($op === 'delete') {
            [$ok, $msg] = pack_package_delete($id, (int)($in['group'] ?? 0), (int)($in['num'] ?? 0));
            $answer($ok, $msg);
        }
        if ($op === 'finish') { [$ok, $msg] = pack_finish($id); $answer($ok, $msg); }
        if ($op === 'reopen') { [$ok, $msg] = pack_reopen($id); $answer($ok, $msg); }
    } catch (Throwable $e) {
        error_log('m_pack api ' . $op . ': ' . $e->getMessage());
        $answer(false, 'That did not save — the server hit a problem. Nothing was changed.');
    }
    $answer(false, 'Unknown request.');
}

/* ======================================= the weight screen's one action

   Kept from before, unchanged, until the weight module exists. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('This packing list can no longer be changed.'); }
    $action = (string)($_POST['action'] ?? '');

    /* ---- the weighed package, and every size's breakdown at once ----
       One save for the whole package. It has to be all of them together,
       because "these four sizes weigh the same" is one action on screen
       and would be four round trips otherwise. */
    if ($action === 'weight') {
        /* ONE INVOICE LINE AT A TIME. The range posted is any range of the
           line; the breakdown and the empty-package weights go to every
           range of that line, and gross is worked out for each. */
        $gId = (int)($_POST['group_id'] ?? 0);
        $grp = pack_group($gId);
        if (!$grp || (int)$grp['shipment_id'] !== $id) { http_response_code(404); exit('Range not found.'); }
        $sibs = pack_siblings($grp);

        /* The empty package, once per kind the line uses. */
        foreach ((array)($_POST['tare'] ?? []) as $kind => $kg) {
            pack_tare_save($gId, (string)$kind, (float)$kg);
        }

        /* Only units the line's ranges actually have. The field arrives
           from a browser, so every key in it is checked against the
           database rather than trusted — a name that is not one of this
           line's own is dropped, not created.

           A UNIT IS A SIZE, OR A SIZE AND A COLOUR. Which one is not
           decided here: pack_unit_key() decides it in one place, from
           the range's own switch, so the screen, the save and the
           seeding can never disagree about where a breakdown is filed. */
        $own = [];
        foreach ($sibs as $sg) {
            foreach (pack_sizes((int)$sg['id']) as $srow) {
                [$uSz, $uCol] = pack_wkey($sg, $srow);
                if ($uSz === '') continue;
                $own[pack_unit_key($sg, $srow)] = [$uSz, $uCol];
            }
        }

        $sent = json_decode((string)($_POST['weights_json'] ?? ''), true);
        if (is_array($sent)) {
            foreach ($sent as $unitKey => $rows) {
                $unitKey = (string)$unitKey;
                if (!isset($own[$unitKey]) || !is_array($rows)) continue;
                [$uSz, $uCol] = $own[$unitKey];
                $lines = [];
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $lines[] = ['w_type' => (string)($r['t'] ?? 'Fabric'),
                                'w_name' => (string)($r['n'] ?? ''),
                                'grams'  => (float)($r['g'] ?? 0)];
                }
                pack_weight_save($gId, $uSz, $lines, true, $uCol);
            }
        }
        pack_gross_refresh($gId);
        $_SESSION['flash'] = count($sibs) > 1
            ? 'Weight saved for all ' . count($sibs) . ' ranges of this line.'
            : 'Weight saved.';
        redirect($self . '&t=weight&g=' . $gId);
    }
    redirect($self);
}

/* ================================================ the weight screen

   Reached from Summary only ("Weight" on a product). It is the screen it
   was, minus the old tab bar; Back returns to the packing app. */
$groups = pack_groups($id);
if ($tab === 'weight') {
mob_header('Weight — ' . (string)$shipment['invoice_no'], $self, (string)$shipment['buyer_name'], 'manifest_pack.json');
mob_flash();
if (!$canEdit) {
    echo '<div class="flash no">This packing list is closed to you — it is completed or the shipment is locked.</div>';
}
?>
<a class="lnk" href="<?= e($self) ?>"><span><small>Weight is moving to its own module</small><b>← Back to packing</b></span><span class="chev">›</span></a>
<style>
  color:var(--muted);font-weight:700;font-size:13px;text-align:center;text-decoration:none}
/* the order's scope, stated before any field is touched */
.btn.sm{padding:10px 12px;font-size:14px}
a.btn.sm{display:block;text-align:center;text-decoration:none;flex:1}
.allr{margin-bottom:14px}.allr>summary{cursor:pointer;font-weight:800;font-size:14px;
  padding:12px 14px;border-radius:12px;background:#fff;border:1px solid var(--line);margin-bottom:10px}
.sumr{border-top:1px solid var(--line);padding:11px 0}
.sumr.bad{background:rgba(184,40,63,.04)}
.sumt{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:15px}
.sumi{font-size:14px;margin-top:3px}
.sumq,.sumw{font-size:12.5px;color:var(--muted);margin-top:2px}
.sumw a{color:var(--bad);font-weight:700}
.suma{display:flex;gap:8px;margin-top:9px}
.suma form{margin:0;flex:1}.suma>.btn,.suma form .btn{width:100%;min-height:44px}
.sumtot{display:flex;justify-content:space-between;border-top:2px solid var(--line);
  padding-top:10px;margin-top:2px;font-size:14px}
.anchor{display:block;position:relative;top:-72px;visibility:hidden}
.asec{margin:2px 0 14px}
.arow{display:grid;grid-template-columns:1fr 110px;gap:10px;align-items:center;
  padding:7px 0;border-bottom:1px solid var(--line)}
.arow b{font-size:16px}
.arow .in{text-align:right;font-size:18px;font-weight:800;padding:11px 12px}
.many{display:grid;grid-template-columns:1fr 1fr;column-gap:12px}
.many .arow{grid-template-columns:1fr 76px;gap:6px}
.many .arow b{font-size:15px;overflow-wrap:anywhere}
.many .arow .in{padding:10px 9px}
.asum{margin-top:8px;padding:10px 12px;border-radius:10px;font-size:14px;font-weight:800;
  background:#f6f8fb;border:1px solid var(--line);color:var(--muted)}
.asum.ok{color:var(--good);background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.35)}
.asum.bad{color:var(--bad);background:rgba(184,40,63,.07);border-color:rgba(184,40,63,.3)}
.clash{margin:-4px 0 11px;padding:10px 12px;border-radius:10px;font-size:13.5px;font-weight:700;
  color:var(--bad);background:rgba(184,40,63,.08);border:1px solid rgba(184,40,63,.3)}
.ihint{font-style:italic;font-weight:600;font-size:.82em;color:var(--muted)}
.scope{background:rgba(14,168,201,.09);border:1px solid rgba(14,168,201,.3);border-radius:13px;
  padding:12px 14px;margin-bottom:13px}
.scope b{font-size:15px;display:block}
.scope span{display:block;font-size:12.5px;color:var(--muted);margin-top:3px;font-weight:600}
.derv{background:#f6f8fb;border:1px solid var(--line);border-radius:10px;padding:9px 11px;
  font-size:13px;font-weight:700;display:flex;justify-content:space-between;gap:8px;margin-bottom:11px}
.derv span{color:var(--muted);font-weight:600}
.ref{background:rgba(14,168,201,.08);border:1px solid rgba(14,168,201,.25);border-radius:10px;
  padding:9px 11px;font-size:12px;color:var(--muted);margin-bottom:11px}
.ref b{color:var(--ink)}
.seg{display:flex;border:1px solid #cbd5e3;border-radius:10px;overflow:hidden;margin-bottom:12px}
.seg label{flex:1;margin:0}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg span{display:block;text-align:center;padding:12px 6px;font-size:13px;font-weight:700;
  color:var(--muted);cursor:pointer;min-height:46px}
.seg input:checked + span{background:var(--cyan);color:#fff}
.row3{display:grid;grid-template-columns:1fr 84px 84px;gap:8px;margin-bottom:11px}
.row2{display:flex;gap:10px}.row2>*{flex:1;min-width:0}
.lnk{display:flex;justify-content:space-between;align-items:center;gap:8px;width:100%;
  padding:12px;border:1px solid #cbd5e3;border-radius:11px;background:#fff;color:var(--ink);
  text-decoration:none;margin-bottom:11px;min-height:48px}
.lnk small{display:block;color:var(--faint);font-size:11.5px;font-weight:600}
.lnk b{font-size:13.5px}
.chev{color:var(--cyan);font-weight:700;font-size:18px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.chip{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:16px;
  padding:8px 13px;font-size:12.5px;font-weight:700;text-decoration:none;min-height:40px;display:inline-block}
.chip.on{background:var(--cyan);color:#fff;border-color:transparent}
.sumrow{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:4px 0}
.sumrow b{font-variant-numeric:tabular-nums}
.formula{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:var(--muted);
  background:#f6f8fb;border:1px solid var(--line);border-radius:9px;padding:10px;margin-top:10px;
  white-space:pre-wrap;line-height:1.75;overflow-x:auto}
table.bk{width:100%;border-collapse:collapse;font-size:12.5px}
table.bk th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;
  color:var(--faint);border-bottom:1px solid var(--line);padding:6px 4px}
table.bk td{padding:6px 4px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
table.bk td.n{text-align:right}
</style>
<?php
    /* The same guard as the range cards: a throw halfway through would
       leave an open form and a hidden step, and the tab would go blank.
       Drawn into a buffer instead, and replaced by a plain card if it
       fails. */
    $lvlT = ob_get_level(); ob_start();
    try {
    if (!$groups) {
        echo '<div class="empty">Add a serial range first.</div>';
    } else {
        $g = null;
        foreach ($groups as $cand) if ((int)$cand['id'] === $gid) $g = $cand;
        if (!$g) { $g = $groups[0]; $gid = (int)$g['id']; }

        /* ONE INVOICE LINE, ONE WEIGHT.
           "Weight only one time for each item in the invoice, even if
            they have many serial ranges — the formula does not change."
           The screen is about the LINE the chosen range belongs to: every
           size any of its ranges holds, weighed once, and the empty weight
           of each kind of package the line uses. Each range's net and
           gross are then worked out from its own quantities. */
        $sibs = [];
        foreach ($groups as $cand) {
            if ((int)$g['invoice_item_id'] > 0
                ? (int)$cand['invoice_item_id'] === (int)$g['invoice_item_id']
                : (int)$cand['id'] === $gid) $sibs[] = $cand;
        }
        if (!$sibs) $sibs = [$g];
        $lineItem = null;
        foreach ($items as $it) if ((int)$it['id'] === (int)$g['invoice_item_id']) $lineItem = $it;

        /* ONE ROW PER THING THAT HAS ITS OWN WEIGHT, across the line.
           With the per-colour switch off that is one row per size, and
           four colours of Single are one question asked once. With it on
           it is one row per size AND colour, because then they differ. */
        $units = []; $unitHome = []; $ranges = []; $kinds = [];
        foreach ($sibs as $sg) {
            $sgSizes = pack_sizes((int)$sg['id']);
            $q = [];
            foreach ($sgSizes as $srow) {
                $k = pack_unit_key($sg, $srow);
                [$uSz, $uCol] = pack_wkey($sg, $srow);
                if ($uSz === '') continue;
                if (!isset($units[$k])) {
                    $units[$k] = ['size' => $uSz, 'colour' => $uCol,
                                  'label' => $uCol === '' ? $uSz : $uCol . ' ' . $uSz];
                    $unitHome[$k] = (int)$sg['id'];
                }
                /* summed, not first-row: with the switch off, 4 White
                   Single and 2 Navy Single are 6 Singles in the carton */
                $q[$k] = ($q[$k] ?? 0) + pack_size_per_pkg($sg, $srow);
            }
            $kind = trim((string)$sg['unit_title']) ?: 'Carton';
            $kk = mb_strtolower($kind);
            if (!isset($kinds[$kk])) $kinds[$kk] = ['label' => $kind, 'kg' => (float)$sg['pkg_tare']];
            elseif ($kinds[$kk]['kg'] <= 0) $kinds[$kk]['kg'] = (float)$sg['pkg_tare'];
            $holds = [];
            foreach ($q as $k => $qq) {
                $holds[] = rtrim(rtrim(number_format($qq, 2, '.', ''), '0'), '.') . ' ' . $units[$k]['label'];
            }
            $ranges[] = ['id' => (int)$sg['id'], 'kind' => $kk,
                         'label' => $kind . ' ' . (int)$sg['serial_from'] . '–' . (int)$sg['serial_to'],
                         'P' => pack_packages($sg),
                         /* what one package holds, in the units weighed —
                            "6 White Single + 4 Navy Single", or "10 Single"
                            when the colours weigh the same */
                         'holds' => implode(' + ', $holds),
                         'q' => array_map(static fn($v) => round($v, 4), $q)];
        }
        $labels  = array_keys($units);
        /* OPEN ON WHAT IS STILL MISSING. Everything already known was
           filled in when the range was saved, so landing on a size that
           is done and making someone hunt for the blank one is the same
           "asking again" in a different shape. */
        $missing = [];
        foreach ($units as $k => $u) {
            if (!pack_weight_lines($unitHome[$k], $u['size'], $u['colour'])) $missing[$k] = $u['label'];
        }
        if ($sz === '' || !in_array($sz, $labels, true)) {
            $sz = (string)(array_key_first($missing) ?? ($labels[0] ?? ''));
        }

        /* EVERY SIZE'S FIGURES GO DOWN WITH THE PAGE, switched in the
           browser, and one Save writes them all back. */
        $allLines = [];
        $allStd   = [];
        $unitName = [];
        foreach ($units as $k => $u) {
            $allLines[$k] = array_map(static function (array $r): array {
                return ['t' => (string)$r['w_type'], 'n' => (string)$r['w_name'], 'g' => (float)$r['grams']];
            }, pack_weight_lines($unitHome[$k], $u['size'], $u['colour']));
            $std = pack_std_get((string)$g['product_name'], $u['size'], $u['colour']);
            if ($std) {
                $allStd[$k] = array_map(static function (array $r): array {
                    return ['t' => (string)$r['w_type'], 'n' => (string)$r['w_name'], 'g' => (float)$r['grams']];
                }, $std);
            }
            $unitName[$k] = $u['label'];
        }
        /* A unit in one range only: how many of it in that range's package.
           Used to say "6 of these in each carton" when that is one figure. */
        $perPkg = [];
        foreach ($units as $k => $u) {
            $vals = [];
            foreach ($ranges as $r) if (isset($r['q'][$k])) $vals[] = $r['q'][$k];
            $perPkg[$k] = count(array_unique($vals)) === 1 ? $vals[0] : 0;
        }
        ?>
        <form method="post" id="wform">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="weight">
          <input type="hidden" name="shipment_id" value="<?= $id ?>">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
          <?php /* Every size's lines in one field, filled in on submit.
                   One save for the whole package rather than one per size
                   — which is also the only way "the same weight for these
                   four sizes" can be a single action. */ ?>
          <input type="hidden" name="weights_json" id="wjson" value="">
        <?php
        /* The form opens before the steps and closes after them, so one
           submit carries every field whichever step it was typed on. */
        mob_steps_begin('weightsteps');
        mob_step('Which item', 'Weight is set once per invoice line — every range of it uses it');
        ?>
        <div class="mcard">
          <select class="in" onchange="location.href=this.value">
            <?php /* ONE ENTRY PER INVOICE LINE, not per range — the line is
                     what has a weight. Each says how many ranges share it. */
                  $byIdW = []; $seenLine = [];
                  foreach ($items as $it) $byIdW[(int)$it['id']] = $it;
                  foreach ($groups as $og):
                    $lk = (int)$og['invoice_item_id'] > 0 ? 'i' . (int)$og['invoice_item_id'] : 'g' . (int)$og['id'];
                    if (isset($seenLine[$lk])) continue;
                    $seenLine[$lk] = true;
                    $nR = 0;
                    foreach ($groups as $o2) {
                        $lk2 = (int)$o2['invoice_item_id'] > 0 ? 'i' . (int)$o2['invoice_item_id'] : 'g' . (int)$o2['id'];
                        if ($lk2 === $lk) $nR++;
                    }
                    $wit = $byIdW[(int)$og['invoice_item_id']] ?? null;
                    $isThis = (int)$og['invoice_item_id'] > 0
                        ? (int)$og['invoice_item_id'] === (int)$g['invoice_item_id']
                        : (int)$og['id'] === $gid; ?>
              <option value="<?= e($self) ?>&amp;t=weight&amp;g=<?= (int)$og['id'] ?>"<?= $isThis ? ' selected' : '' ?>>
                <?= e($wit ? pack_item_label($wit)['text'] : (string)$og['product_name']) ?>
                · <?= $nR ?> range<?= $nR === 1 ? '' : 's' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="note" style="margin-top:9px">Shared by
            <?= e(implode(', ', array_column($ranges, 'label'))) ?>.</div>
        </div>
        <?php mob_step('What is left to weigh', 'Asked once for the whole line'); ?>

          <?php /* SAY WHAT IS LEFT, NOT WHAT THERE IS. A weight belongs
                   to the product and the size, so it is asked for once
                   and carried forward. This line is how someone knows
                   there is nothing to do. */ ?>
          <div class="mcard">
            <?php if (!$units): ?>
              <div class="note">Set the sizes on the first tab.</div>
            <?php elseif (!$missing): ?>
              <div class="sumrow"><span><b>Nothing new to weigh here.</b></span>
                <b><span class="pill p">done</span></b></div>
              <div class="note" style="margin-top:6px">
                All <?= count($units) === 1 ? 'of it' : count($units) . ' of them' ?> came from
                what this product was weighed at before. Change anything only if it is wrong.
              </div>
            <?php else: ?>
              <div class="sumrow">
                <span><b><?= count($missing) ?> of <?= count($units) ?></b>
                  still need<?= count($missing) === 1 ? 's' : '' ?> weighing</span>
                <b><span class="pill w"><?= e(implode(', ', $missing)) ?></span></b></div>
              <div class="note" style="margin-top:6px">The rest came from what this product
                was weighed at before, and is already filled in.</div>
            <?php endif; ?>
          </div>

          <?php /* THE EMPTY PACKAGE, ONCE PER KIND. There is no gross to type
                   per range any more: gross is what one package holds, by
                   the breakdown, plus this. An empty carton and an empty
                   roll core are different, so each kind this line uses is
                   asked once. */ ?>
          <div class="mcard">
            <h2 style="margin:0 0 4px">The empty package</h2>
            <div class="note" style="margin-bottom:10px">Weigh one empty, once. Every range of
              this line that uses it is worked out from it.</div>
            <div class="row2" style="flex-wrap:wrap">
              <?php foreach ($kinds as $kk => $kd): ?>
                <label class="f"><span>One empty <?= e(mb_strtolower($kd['label'])) ?>, kg</span>
                  <input class="in" type="number" inputmode="decimal" step="0.001" min="0"
                         name="tare[<?= e($kd['label']) ?>]" data-tare="<?= e($kk) ?>"
                         value="<?= $kd['kg'] > 0 ? e(number_format($kd['kg'], 3, '.', '')) : '' ?>"></label>
              <?php endforeach; ?>
            </div>
          </div>

          <?php if (count($units) > 1):
                  /* UNITS, NOT SIZES. A range of three colours of one
                     size, weighing the same, is ONE unit — and a step
                     asking which of the one to pick is a screen for
                     nothing. */
                  mob_step('Which one', 'Switches at once — nothing is saved until you press Save'); ?>
            <div class="mcard">
              <div class="chips" id="szchips"></div>
              <div class="note" style="margin-top:10px">A tick means that size already has its weights.</div>
            </div>

            <div class="mcard">
              <h2>Same weight for more than one size</h2>
              <div class="note" style="margin-bottom:10px">When sizes weigh the same, fill one in and
                tick the others — the whole breakdown is copied across.</div>
              <div class="chips" id="applychips"></div>
              <button class="btn sec" type="button" id="applybtn" style="margin-top:11px">
                Use this breakdown for the ticked sizes</button>
              <div class="note" id="applymsg" style="margin-top:9px"></div>
            </div>
          <?php endif;
          mob_step('What one unit is made of', 'Add a line for every material. Grams.'); ?>

          <div class="mcard">
            <h2 id="wsizehead">One unit</h2>
            <div class="note" style="margin-bottom:12px" id="wsizesub"></div>
            <div class="row2" style="margin-bottom:12px">
              <?php /* Both of these fill the lines without touching the
                       server: everything they need came down with the
                       page. Nothing is written until Save. */ ?>
              <button class="btn sec sm" type="button" id="stdbtn" hidden>Use last saved</button>
              <button class="btn sec sm" type="button" id="copybtn" hidden>Copy from…</button>
            </div>
            <select class="in" id="copyfrom" hidden style="margin-bottom:12px"></select>

            <div id="wlines"></div>
            <button class="btn sec" type="button" id="addline">+ Add line</button>
            <div class="derv" style="margin-top:12px"><span>This unit comes to</span><b id="perunit">0 g</b></div>
          </div>

          <?php if ($canEdit): ?>
            <button class="btn go" type="submit">Save the weight</button>
          <?php endif; ?>

        <?php mob_step('Every range of this line', 'Worked out from the weight and the quantities'); ?>

        <?php /* Drawn by PHP so the page is right before any script runs,
                 then redrawn by the script as lines are typed. */ ?>
        <div class="mcard">
          <div id="pkgtotals"><?php
            $lineNet = 0.0; $lineGross = 0.0;
            foreach ($ranges as $r) {
                $cG = 0.0;
                foreach ($r['q'] as $k => $qq) {
                    $cG += array_sum(array_column($allLines[$k] ?? [], 'g')) * $qq;
                }
                $c = $cG / 1000;
                $tr = (float)($kinds[$r['kind']]['kg'] ?? 0);
                $lineNet += $c * $r['P']; $lineGross += ($c + $tr) * $r['P'];
                echo '<div class="rtot"><div class="sumrow"><span><b>' . e($r['label']) . '</b> · '
                   . number_format($r['P']) . '</span><b>' . number_format($c + $tr, 3) . ' kg each</b></div>'
                   . '<div class="note">' . e($r['holds']) . '</div>'
                   . '<div class="note">' . number_format($c, 3) . ' contents + ' . number_format($tr, 3)
                   . ' package · net ' . number_format($c * $r['P'], 3) . ' · gross '
                   . number_format(($c + $tr) * $r['P'], 3) . ' kg</div></div>';
            }
            echo '<div class="sumrow" style="border-top:2px solid var(--line);margin-top:6px;padding-top:9px">'
               . '<span><b>This line</b></span><b>net ' . number_format($lineNet, 3) . ' · gross '
               . number_format($lineGross, 3) . ' kg</b></div>';
          ?></div>
        </div>

        <?php mob_steps_end(); ?>
        </form>

        <style>
        /* TIGHT ON PURPOSE. A weight has six or seven lines and the
           screen has one thumb's worth of room. Nothing here is padded
           for the sake of it. */
        .wrow{border:1px solid var(--line);border-radius:11px;padding:8px 9px;margin-bottom:7px;background:#fff}
        .wtop{display:grid;grid-template-columns:1fr 104px 40px 32px;gap:6px;align-items:center}
        .wtop .in{padding:9px 8px}
        .wtop .g{text-align:right;font-variant-numeric:tabular-nums}
        /* the description, as a symbol */
        .wrow .nt{border:1px solid #cbd5e3;background:#fff;color:var(--faint);border-radius:9px;
          height:40px;font-size:15px;font-weight:800;cursor:pointer;padding:0;line-height:1}
        .wrow .nt.has{color:var(--cyan);border-color:rgba(14,168,201,.5)}
        .wrow .nt.open{background:var(--cyan);color:#fff;border-color:var(--cyan)}
        /* and, when closed and filled, as one thin readable line */
        .wrow .nmshow{display:block;width:100%;text-align:left;border:0;background:transparent;
          color:var(--muted);font-size:12.5px;font-weight:600;padding:5px 2px 0;cursor:pointer}
        .wrow .nm{margin-top:7px}
        .wrow .nm .in{padding:8px 10px;font-size:14px}
        .wbal{text-align:right;font-size:11.5px;font-weight:700;margin-top:5px;font-variant-numeric:tabular-nums}
        .wbal.ok{color:var(--good)}.wbal.left{color:var(--muted)}.wbal.over{color:var(--bad)}
        .rtot{padding:8px 0;border-bottom:1px solid var(--line)}.rtot .note{margin-top:2px}
        .wrow .x{border:0;background:transparent;color:var(--bad);font-size:21px;min-height:40px;
          cursor:pointer;padding:0}
        </style>
        <script>
        (function () {
          var TYPES = <?= json_encode(PACK_WTYPES) ?>;
          /* Every size, its lines, its remembered standard and how many of
             it sit in one package. All of it came down with the page, so
             switching size, copying a breakdown or applying one to four
             sizes at once costs nothing and loses nothing. */
          /* The keys the server files a breakdown under, and the names
             a packer reads. They differ the moment colour is involved:
             the key is "Single|Navy", the name is "Navy Single". */
          var SIZES  = <?= json_encode(array_map('strval', array_keys($units))) ?>;
          var NAMES  = <?= json_encode((object)$unitName) ?>;
          var LINES  = <?= json_encode((object)$allLines) ?>;
          var STD    = <?= json_encode((object)$allStd) ?>;
          var PERPKG = <?= json_encode((object)array_map(static fn($v) => round((float)$v, 4), $perPkg)) ?>;
          /* every range of this line: its packages and how many of each
             unit one package holds — the weight is shared, these are not */
          var RANGES = <?= json_encode($ranges) ?>;
          var at     = <?= json_encode($sz !== '' ? $sz : (string)($labels[0] ?? '')) ?>;

          var box   = document.getElementById('wlines');
          var openNote = null;   /* which line has its description open, if any */
          var chips = document.getElementById('szchips');
          var appl  = document.getElementById('applychips');
          var picked = {};

          function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
              return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; });
          }
          function num(n) { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
          /* THE NAME, NEVER THE KEY. The key is "Single|Navy" and a
             packer must never be shown that — he reads "Navy Single". */
          function nm(k) { return NAMES[k] || k; }
          function kg(v)  { return (Math.round(v * 1000) / 1000).toFixed(3); }
          function rows() { return (LINES[at] = LINES[at] || []); }
          function sum(list) {
            return (list || []).reduce(function (a, r) { return a + (+r.g || 0); }, 0);
          }
          /* No scale figure per range any more, so there is no line-by-line
             balance to run down to. Gross is worked out, not checked. */
          function must() { return 0; }
          function tareOf(kind) {
            var el = document.querySelector('[data-tare="' + kind + '"]');
            return el ? (parseFloat(el.value) || 0) : 0;
          }
          /* What the lines say one package holds, across every size. */
          function contentsOf(r) {
            var t = 0;
            Object.keys(r.q).forEach(function (k) { t += sum(LINES[k]) * (+r.q[k] || 0); });
            return t / 1000;
          }

          function drawChips() {
            if (!chips) return;
            chips.innerHTML = SIZES.map(function (l) {
              return '<button type="button" class="chip' + (l === at ? ' on' : '') + '" data-s="'
                + esc(l) + '">' + esc(nm(l)) + (sum(LINES[l]) > 0 ? ' ✓' : '') + '</button>';
            }).join('');
            chips.querySelectorAll('.chip').forEach(function (c) {
              c.onclick = function () { harvest(); at = c.dataset.s; picked = {}; openNote = null; drawAll(); };
            });

            appl.innerHTML = SIZES.filter(function (l) { return l !== at; }).map(function (l) {
              return '<button type="button" class="chip' + (picked[l] ? ' on' : '') + '" data-s="'
                + esc(l) + '">' + (picked[l] ? '✓ ' : '') + esc(nm(l)) + '</button>';
            }).join('') || '<span class="note">Nothing else in this range to copy to.</span>';
            appl.querySelectorAll('.chip').forEach(function (c) {
              c.onclick = function () {
                picked[c.dataset.s] = !picked[c.dataset.s];
                document.getElementById('applymsg').textContent = '';
                drawChips();
              };
            });
          }

          function drawTools() {
            var head = document.getElementById('wsizehead');
            var sub  = document.getElementById('wsizesub');
            head.textContent = 'One unit of ' + (at ? nm(at) : '—');
            var nIn = RANGES.filter(function (r) { return r.q[at]; }).length;
            sub.textContent = (+PERPKG[at] ? (+PERPKG[at]) + ' of these in each package · ' : '')
              + 'used in ' + nIn + ' range' + (nIn === 1 ? '' : 's') + ' of this line';

            var std = document.getElementById('stdbtn');
            std.hidden = !STD[at];
            std.onclick = function () {
              LINES[at] = STD[at].map(function (r) { return { t: r.t, n: r.n, g: r.g }; });
              drawAll();
            };

            var others = SIZES.filter(function (l) { return l !== at && sum(LINES[l]) > 0; });
            var cb = document.getElementById('copybtn');
            var cf = document.getElementById('copyfrom');
            cb.hidden = others.length === 0;
            cf.hidden = true;
            cf.innerHTML = '<option value="">— copy the lines from —</option>'
              /* value is the key the lines are stored under; the text is
                 what a packer reads. One <option> doing both jobs meant
                 the name had to be the key. */
              + others.map(function (l) {
                  return '<option value="' + esc(l) + '">' + esc(nm(l)) + '</option>'; }).join('');
            cb.onclick = function () { cf.hidden = !cf.hidden; };
            cf.onchange = function () {
              if (!this.value) return;
              LINES[at] = (LINES[this.value] || []).map(function (r) { return { t: r.t, n: r.n, g: r.g }; });
              cf.hidden = true;
              drawAll();
            };
          }

          function drawLines() {
            /* The line-by-line fill only means something when the package
               holds one size. Otherwise the remainder belongs to the whole
               package, and it is shown there instead. */
            var single = SIZES.length === 1;
            var q = +PERPKG[at] || 0;
            var mustG = (single && q > 0) ? must() * 1000 / q : 0;
            var run = mustG;

            box.innerHTML = rows().map(function (r, i) {
              var bal = '';
              if (mustG > 0) {
                run -= (+r.g || 0);
                var cls = Math.abs(run) < 0.5 ? 'ok' : (run < 0 ? 'over' : 'left');
                var txt = Math.abs(run) < 0.5 ? '0 g — balanced ✓'
                        : (run < 0 ? num(-run) + ' g over' : num(run) + ' g still to fill');
                bal = '<div class="wbal ' + cls + '">' + txt + '</div>';
              }

              /* THE DESCRIPTION IS A SYMBOL, NOT A BOX.

                 Fabric is three qualities on one job — fleece, micro,
                 7668 — and filling is often two. But most lines need no
                 description at all, and a full-width empty box on every
                 one of them was forty wasted pixels a line and six lines
                 a size. It is a dot now: hollow when there is nothing,
                 filled when there is. Tapping it opens one input.

                 When it is closed and holds something, the text shows as
                 a thin line rather than a field — readable, a third of
                 the height, and still one tap to change. */
              var has  = (r.n || '').trim() !== '';
              var open = openNote === i;
              var foot = open
                ? '<div class="nm"><input class="in nmi" value="' + esc(r.n) + '" '
                  + 'placeholder="quality — fleece, micro, 7668 …" aria-label="Description"></div>'
                : (has ? '<button type="button" class="nmshow">' + esc(r.n) + '</button>' : '');

              return '<div class="wrow" data-i="' + i + '"><div class="wtop">'
                + '<select class="in ty">' + TYPES.map(function (t) {
                    return '<option' + (t === r.t ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('')
                + '</select>'
                /* text with a decimal keypad, not type=number: the number
                   box drew up/down arrows that ate half of a 72px field,
                   so 300 showed as "3". Wider now, and no arrows. */
                + '<input class="in g" type="text" inputmode="decimal" autocomplete="off" '
                + 'enterkeyhint="next" value="' + (r.g || '') + '" placeholder="grams" aria-label="Grams">'
                + '<button type="button" class="nt' + (has ? ' has' : '') + (open ? ' open' : '') + '" '
                + 'aria-label="' + (has ? 'Change the quality' : 'Name the quality') + '" '
                + 'title="' + (has ? 'Change the quality' : 'Name the quality') + '">'
                + (has ? '●' : '+') + '</button>'
                + '<button type="button" class="x" aria-label="Remove">&times;</button></div>'
                + foot + bal + '</div>';
            }).join('') || '<div class="note">No line yet. Press <b>+ Add line</b> — pick Fabric, '
                + 'put its grams, and tap <b>+</b> only if the quality needs naming.</div>';

            box.querySelectorAll('.wrow').forEach(function (d) {
              var i = +d.dataset.i;
              d.querySelector('.ty').onchange  = function () { harvest(); drawAll(); };
              /* TYPING MUST NOT REDRAW THE LIST. This called drawAll(),
                 which rebuilt every row — including the very box being
                 typed in — so after the first digit the box was a new,
                 unfocused one and the rest of the keystrokes went nowhere.
                 300 became 3. Now a keystroke stores the figure and
                 refreshes the sums only; the row stays exactly as it is. */
              var gIn = d.querySelector('.g');
              gIn.oninput = function () {
                rows()[i].g = parseFloat(String(this.value).replace(',', '.')) || 0;
                document.getElementById('perunit').textContent = num(Math.round(sum(rows()))) + ' g';
                drawChips(); drawTotals();
              };
              gIn.onfocus = function () { this.select(); };
              d.querySelector('.x').onclick    = function () {
                harvest(); rows().splice(i, 1);
                if (openNote === i) openNote = null;
                drawAll();
              };
              d.querySelector('.nt').onclick   = function () {
                harvest(); openNote = (openNote === i) ? null : i; drawAll();
                var f = box.querySelector('.wrow[data-i="' + i + '"] .nmi');
                if (f) f.focus();
              };
              var shown = d.querySelector('.nmshow');
              if (shown) shown.onclick = function () { harvest(); openNote = i; drawAll(); };
              var inp = d.querySelector('.nmi');
              if (inp) inp.oninput = function () { rows()[i].n = this.value; };
            });
            document.getElementById('perunit').textContent = num(Math.round(sum(rows()))) + ' g';
          }

          function drawTotals() {
            var el = document.getElementById('pkgtotals');
            if (!el) return;
            var net = 0, gross = 0, html = '';
            RANGES.forEach(function (r) {
              var c = contentsOf(r), tr = tareOf(r.kind);
              net += c * r.P; gross += (c + tr) * r.P;
              html += '<div class="rtot"><div class="sumrow"><span><b>' + esc(r.label) + '</b> · ' + num(r.P)
                + '</span><b>' + kg(c + tr) + ' kg each</b></div><div class="note">' + esc(r.holds)
                + '</div><div class="note">' + kg(c)
                + ' contents + ' + kg(tr) + ' package · net ' + kg(c * r.P) + ' · gross '
                + kg((c + tr) * r.P) + ' kg</div></div>';
            });
            el.innerHTML = html + '<div class="sumrow" style="border-top:2px solid var(--line);margin-top:6px;'
              + 'padding-top:9px"><span><b>This line</b></span><b>net ' + kg(net) + ' · gross ' + kg(gross)
              + ' kg</b></div>';
          }

          /* Read the boxes back before any redraw, or typing in one would
             be thrown away the moment another changes. */
          function harvest() {
            var list = rows();
            box.querySelectorAll('.wrow').forEach(function (d) {
              var i = +d.dataset.i;
              if (!list[i]) return;
              list[i].t = d.querySelector('.ty').value;
              list[i].g = parseFloat(String(d.querySelector('.g').value).replace(',', '.')) || 0;
              /* Only one description is open at a time, and the rest are
                 not in the page at all — reading .nmi unconditionally
                 threw the moment the box became a symbol. */
              var nm = d.querySelector('.nmi');
              if (nm) list[i].n = nm.value;
            });
          }
          function drawAll() { drawChips(); drawTools(); drawLines(); drawTotals(); }

          document.getElementById('addline').onclick = function () {
            harvest();
            rows().push({ t: TYPES[0], n: '', g: 0 });   /* Fabric — change it on the line */
            drawAll();
          };
          document.querySelectorAll('[data-tare]').forEach(function (el) {
            el.addEventListener('input', drawTotals);
          });

          var ab = document.getElementById('applybtn');
          if (ab) ab.onclick = function () {
            harvest();
            var to = Object.keys(picked).filter(function (k) { return picked[k]; });
            var msg = document.getElementById('applymsg');
            if (!to.length) { msg.textContent = 'Tick the ones that weigh the same first.'; return; }
            if (!sum(rows())) { msg.textContent = 'There is nothing to copy yet — fill this one in first.'; return; }
            to.forEach(function (l) {
              LINES[l] = rows().map(function (r) { return { t: r.t, n: r.n, g: r.g }; });
            });
            picked = {};
            drawAll();
            msg.textContent = 'Copied to ' + to.map(nm).join(', ') + '. Nothing is written until you press Save.';
          };

          /* One field carries the lot. Filled at the last moment so it is
             always what is on screen. */
          document.getElementById('wform').addEventListener('submit', function () {
            harvest();
            document.getElementById('wjson').value = JSON.stringify(LINES);
          });

          drawAll();
        })();
        </script>
        <?php
    }
    ob_end_flush();
    } catch (Throwable $e) {
        while (ob_get_level() > $lvlT) ob_end_clean();
        mob_card_error('The weight for this range', $e);
    }
mob_footer();
exit;
}

/* ================================================================ the app

   SMART PACKING — afnan's demo ("Smart Packing final v7"), on real data.

   Three tabs, one page, never reloaded:
     1 Packing  — pick the product, the serial (one package or a range),
                  the pieces per package; pick a size and tap a colour (or
                  style) once per piece; Save & Next keeps everything ready
                  for the next package at the next free serial.
     2 Records  — every package, one by one or as ranges; edit one package,
                  copy it, delete it; the packing list to print or export.
     3 Summary  — totals per product by size, colour and style; Finish
                  packing / Reopen; Weight is linked from here.

   What changed from the demo, and why:
     - The products are this invoice's lines, and each offers exactly the
       sizes, colours and styles the office set on Order sizes & colours.
     - Saving goes to the server (op=save/delete/finish/reopen below) and
       the answer carries the whole state back, so the screen redraws
       without a page load. Only the unsaved draft lives in the browser.
     - A range is stored once; editing or deleting one package of it
       splits it on the server (pack_split_out), exactly as the demo
       treats every package as its own record.
     - No JSON backup, import or reset: the data is on the server.
     - Weight is not on this page — it is moving to its own module, and
       until then the Weight screen is linked from Summary. */
/* Everything the screen needs is read BEFORE anything is printed, so a
   failure here gives one plain card on a whole page instead of a header
   and then nothing — the blank screen that came back from the live server
   once before. */
try {
    $state   = pack_state($id, $items, $shipment);
    $csrfTok = csrf_token();
} catch (Throwable $e) {
    mob_header($title, $back, (string)$shipment['buyer_name'], 'manifest_pack.json');
    mob_card_error('The packing screen', $e);
    mob_footer();
    exit;
}
mob_header($title, $back, (string)$shipment['buyer_name'], 'manifest_pack.json');
?>
<div id="sp" class="sp">
  <nav class="sp-tabs" aria-label="Packing workflow">
    <button type="button" data-a="tab" data-tab="packing"><span class="sp-n">1</span>Packing</button>
    <button type="button" data-a="tab" data-tab="records"><span class="sp-n">2</span>Records <span id="spCount"></span></button>
    <button type="button" data-a="tab" data-tab="summary"><span class="sp-n">3</span>Summary</button>
  </nav>
  <?php if (!$canEdit): ?>
    <div class="sp-notice sp-warn" style="margin:0 0 12px">This packing list is closed to you — it is finished or the shipment is locked. You can look, not change.</div>
  <?php endif; ?>
  <main id="spMain"></main>
  <div id="spDock"></div>
</div>
<div id="spToast" class="sp-toast" role="status" aria-live="polite" hidden></div>
<div id="spModal"></div>
<div id="spPrint"></div>

<style>
/* Scoped under #sp so nothing here leaks into the rest of the phone app. */
.sp{max-width:100%;overflow-x:clip;--ink:#183537;--muted:#64797b;--teal:#087f72;--dark:#123b3a;--mint:#e4f4ef;--line2:#dce7e4;
  --red:#b73b3b;--amber:#9a650e;--shadow:0 8px 28px #143c3610;padding-bottom:150px}
.sp button,.sp input,.sp select{font:inherit;min-height:44px;border-radius:11px}
.sp button{cursor:pointer;-webkit-tap-highlight-color:transparent;touch-action:manipulation;
  border:1px solid var(--line2);background:#fff;color:var(--ink);padding:9px 13px;font-weight:650}
.sp button:disabled{cursor:default;opacity:.45}
.sp input,.sp select{width:100%;padding:10px 12px;background:#fff;border:1px solid #cbdad6;color:var(--ink);font-size:16px}
.sp label{display:block;font-size:12px;font-weight:650;color:var(--muted);margin-bottom:5px}
.sp h2{font-size:19px;letter-spacing:-.4px;margin:0}.sp h3{font-size:15px;margin:0}
.sp .muted{color:var(--muted)}.sp .small{font-size:12px}
.sp .eyebrow{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:800;color:var(--muted)}
.sp .row{display:flex;gap:9px;align-items:center;padding:0;margin:0;border:0}.sp .between{justify-content:space-between}.sp .wrap{flex-wrap:wrap}
.sp .grow{flex:1;min-width:0}.sp .gap{margin-top:14px}
.sp .primary{background:var(--teal);border-color:var(--teal);color:#fff}
.sp .soft{background:var(--mint);border-color:#beded3;color:#076758}
.sp .danger{color:var(--red)}.sp .quiet{background:transparent;border-color:transparent}
.sp .mini{min-height:36px;font-size:12px;padding:6px 10px}.sp .full{width:100%}
.sp .pill{display:inline-flex;border-radius:20px;padding:4px 8px;background:var(--mint);color:var(--teal);
  font-size:11px;font-weight:750;white-space:nowrap}
.sp .pill.warn{color:var(--amber);background:#fff1d9}.sp .pill.gray{background:#edf1f0;color:var(--muted)}
.sp-notice{padding:11px 12px;border-radius:10px;background:#eef5f2;color:#456563;font-size:12.5px}
.sp-warn{background:#fff4df;color:#8b5b0a}
.sp .card{background:#fff;border:1px solid var(--line2);border-radius:16px;padding:14px;box-shadow:var(--shadow)}
.sp-tabs{display:flex;gap:6px;margin:0 0 14px}
.sp-tabs button{flex:1;min-width:0;border:1px solid var(--line2);background:#fff;display:flex;align-items:center;
  justify-content:center;gap:6px;min-height:46px;color:var(--muted);font-size:13px;padding:8px 4px;white-space:nowrap}
#spCount{font-size:11px;font-weight:600}
.sp-tabs button.active{background:var(--dark);color:#fff;border-color:var(--dark)}
.sp-n{font-size:11px;border:1px solid currentColor;width:20px;height:20px;border-radius:50%;
  display:grid;place-items:center;opacity:.75}
.sp .sel{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--line2);
  padding:11px 12px;border-radius:12px;background:#fff}
.sp .sel .name{font-size:17px;font-weight:800;letter-spacing:-.3px}
.sp .ref{font-size:11.5px;color:var(--muted)}
.sp .search{position:relative;margin-top:8px}
.sp .results{position:absolute;z-index:35;top:50px;left:0;right:0;background:#fff;border:1px solid var(--line2);
  box-shadow:0 12px 30px #163d3420;border-radius:12px;overflow:hidden;max-height:60vh;overflow-y:auto}
.sp .results button{display:block;width:100%;text-align:left;border:0;border-radius:0;border-bottom:1px solid var(--line2)}
.sp .results small{display:block;color:var(--muted);margin-top:3px;font-weight:500}
.sp .segment{display:flex;gap:5px;padding:4px;border-radius:12px;background:#eef2f0}
.sp .segment button{flex:1;min-height:40px;border:0;background:transparent;font-size:12px}
.sp .segment button.active{background:#fff;box-shadow:0 2px 7px #173d3510;color:var(--teal)}
.sp .sgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.sp .sgrid.range{grid-template-columns:1fr 1fr 1fr}
.sp .preview{margin-top:7px;font-size:12.5px;color:var(--muted)}
.sp details{border-top:1px solid var(--line2);margin-top:11px;padding-top:9px}
.sp summary{cursor:pointer;min-height:33px;display:flex;align-items:center;justify-content:space-between;
  font-size:12.5px;color:var(--muted);font-weight:650;list-style:none}
.sp summary:after{content:'+';font-size:18px}.sp details[open]>summary:after{content:'−'}
.sp summary::-webkit-details-marker{display:none}
.sp .settings{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:9px}
.sp .alabel{display:flex;justify-content:space-between;align-items:center;margin:12px 0 7px;font-size:11px;
  text-transform:uppercase;letter-spacing:1px;font-weight:750;color:var(--muted)}
.sp .tgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.sp .touch{min-height:60px;padding:8px 5px;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:3px;user-select:none;-webkit-user-select:none}
.sp .touch .t{font-size:13px;font-weight:800;overflow-wrap:anywhere;text-align:center}
.sp .touch .b{font-size:11px;color:var(--muted);font-variant-numeric:tabular-nums}
.sp .touch.active{background:var(--mint);border-color:var(--teal);color:#076758}
.sp .touch.active .b{color:var(--teal)}
.sp .colors .touch{min-height:66px;background:#f9fbfa}.sp .colors .touch.active{background:var(--mint)}
.sp .dot{width:10px;height:10px;border-radius:50%;border:1px solid #123b3a30;display:inline-block;margin-right:5px;vertical-align:middle}
.sp .hint{font-size:11.5px;color:var(--muted);margin-top:7px}
.sp-dock{position:fixed;bottom:0;left:50%;transform:translateX(-50%);z-index:25;width:calc(100% - 24px);max-width:600px;
  background:#fff;box-shadow:0 -7px 24px #153e3418;border:1px solid #dce7e4;border-radius:16px 16px 0 0;
  padding:10px 14px max(10px,env(safe-area-inset-bottom))}
.sp-dock .crow{display:flex;justify-content:space-between;align-items:center;margin-bottom:7px}
.sp-dock .cval{font-size:17px;font-weight:800;letter-spacing:-.5px;color:#183537}
.sp-dock .crem{font-size:12.5px;font-weight:650;color:#183537}
.sp-dock .prog{height:4px;background:#e9f0ed;border-radius:8px;margin-bottom:9px;overflow:hidden}
.sp-dock .prog>i{display:block;height:100%;background:#087f72;transition:width .15s}
.sp .sp-save{position:relative;overflow:hidden;width:100%;min-height:60px;padding:9px 12px;border-radius:12px;color:#fff;
  font:inherit;font-weight:800;cursor:pointer;background:linear-gradient(115deg,#08796b,#159681);border:1px solid #08796b;
  box-shadow:0 3px 12px #08847026}
.sp .sp-save:disabled{opacity:.5;cursor:default}
.sp .sp-save .st{display:block;font-size:14px;letter-spacing:.35px}
.sp .sp-save .sc{display:block;font-size:11.5px;font-weight:550;opacity:.94;margin-top:3px;font-variant-numeric:tabular-nums}
.sp .sp-save.ok{background:linear-gradient(110deg,#087d68,#19a98d,#158a89)}
.sp .sp-save.ok:before{content:"";position:absolute;inset:-40% -20%;background:linear-gradient(110deg,transparent 35%,#ffffff34 50%,transparent 65%);
  transform:translateY(0) translateX(-80%);animation:spshine .6s ease-out forwards;pointer-events:none}
@keyframes spshine{to{transform:translateX(80%)}}
@media(prefers-reduced-motion:reduce){.sp .sp-save.ok:before{animation:none}}
.sp .combos{margin-top:10px;border-top:1px solid var(--line2)}
.sp .combo{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid #edf2ef}
.sp .combo .text{font-size:13px}
.sp .combo button{padding:5px 9px;min-width:38px;min-height:38px;border-radius:8px}
.sp .combo .amt{min-width:40px;text-align:center;font-weight:800;color:var(--teal)}
.sp .toolbar{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.sp .toolbar input{flex:1 1 100%;min-width:0}.sp .toolbar .segment{flex:1}
.sp .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0}
.sp .stat{background:#fff;border:1px solid var(--line2);padding:10px 11px;border-radius:12px}
.sp .stat .v{font-size:21px;font-weight:800;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.sp .stat .c{font-size:11px;color:var(--muted);margin-top:2px}
.sp .rec{display:grid;grid-template-columns:1fr auto;gap:10px;padding:12px;margin-bottom:10px}
.sp .serial{font-size:17px;font-weight:800;letter-spacing:-.3px;font-variant-numeric:tabular-nums}
.sp .rprod{font-weight:700;font-size:13px;margin-top:3px}
.sp .brk{font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.6}
.sp .acts{display:flex;flex-direction:column;gap:4px}.sp .acts button{font-size:12px;padding:6px 10px;min-height:34px}
.sp .rfoot{display:flex;justify-content:space-between;gap:10px;grid-column:1/-1;border-top:1px solid var(--line2);
  padding-top:8px;font-size:11.5px;color:var(--muted)}
.sp .sline{padding:11px 0;border-bottom:1px solid var(--line2)}.sp .sline:last-child{border-bottom:0}
.sp .stotal{font-size:19px;font-weight:800}
.sp .sprod{margin-top:12px}
.sp .empty{text-align:center;padding:30px;color:var(--muted)}
.sp .tablewrap{overflow:auto;border:1px solid var(--line2);border-radius:12px;margin-top:12px}
.sp table,.sp-print table{width:100%;border-collapse:collapse;font-size:12px;text-align:left;background:#fff}
.sp th,.sp-print th{background:#edf4f0;color:#3d615d;font-size:10px;text-transform:uppercase;letter-spacing:.35px;white-space:nowrap}
.sp th,.sp td,.sp-print th,.sp-print td{padding:9px 8px;border-bottom:1px solid #dce7e4;vertical-align:top}
.sp td.num,.sp-print td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.sp-toast{position:fixed;z-index:80;bottom:150px;left:50%;transform:translateX(-50%);background:#123b3a;color:#fff;
  border-radius:12px;padding:12px 17px;box-shadow:0 7px 20px #0002;max-width:90%;font-size:13px;pointer-events:none}
.sp-toast.error{background:#8d3030}
.sp-back{position:fixed;inset:0;z-index:60;background:#123b3a77;display:flex;align-items:center;justify-content:center;padding:16px}
.sp-modal{background:#fff;border-radius:18px;width:100%;max-width:520px;max-height:90dvh;overflow:auto;padding:18px;color:#183537}
.sp-modal h2{font-size:18px;margin:0 0 8px}.sp-modal .foot{display:flex;gap:8px;margin-top:18px}.sp-modal .foot button{flex:1}
.sp-modal .err{color:#b73b3b;font-size:12.5px;margin-top:10px}
.sp-modal input{width:100%;padding:10px 12px;border:1px solid #cbdad6;border-radius:11px;font-size:16px;min-height:44px}
.sp-modal button{min-height:44px;border-radius:11px;border:1px solid #dce7e4;background:#fff;padding:9px 13px;font:inherit;font-weight:650}
.sp-modal button.primary{background:#087f72;border-color:#087f72;color:#fff}
/* the packing list, on screen inside the dialog */
.sp-print{color:#183537;font-size:13px}
.sp-print>div:first-child{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px}
.sp-print h1{font-size:20px;letter-spacing:-.3px;margin:4px 0 6px}
.sp-print h3{font-size:14px}
.sp-print .eyebrow{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:800;color:#64797b}
.sp-print .ref{font-size:12px;color:#64797b}
.sp-print .sp-notice{align-self:flex-start}
.sp-print .tablewrap{overflow-x:auto;border:1px solid #dce7e4;border-radius:12px;margin-top:12px}
#spPrint{display:none}
@media print{body>*:not(#spPrint){display:none!important}#spPrint{display:block}
  .sp-print{font-size:10px;color:#000}.sp-print h1{font-size:20px;margin:0 0 6px}
  .sp-print th,.sp-print td{border:1px solid #ccd5d0;padding:5px 4px;font-size:8.5px}
  thead{display:table-header-group}tr{break-inside:avoid}@page{size:A4 landscape;margin:12mm}}
@media(max-width:370px){.sp-tabs button{font-size:11px;gap:4px}.sp .sgrid.range{grid-template-columns:1fr 1fr}
  .sp .sgrid.range .qtyf{grid-column:1/-1}.sp .touch .t{font-size:11.5px}}
</style>

<script>
(function () {
"use strict";
/* Everything the screen draws comes from STATE; every change goes to the
   server and STATE comes back with it. */
var STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
var CSRF = <?= json_encode($csrfTok) ?>;
var API = <?= json_encode($self . '&api=1') ?>;
var WEIGHT_URL = <?= json_encode($self . '&t=weight') ?>;
var DRAFT_KEY = 'zas-pack-draft-' + STATE.shipment.id;

var tab = 'packing', history = [], recordMode = 'individual', recordQuery = '', recordPage = 0, recordFilter = null;
var toastTimer, searchQuery = '', saveBusy = false, pointerTimer, suppressTap = false, receipt = null, receiptTimer;
var draft;

var $ = function (q, r) { return (r || document).querySelector(q); };
var clone = function (x) { return JSON.parse(JSON.stringify(x)); };
var esc = function (x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var fmt = function (n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 3 }); };
var prod = function (id) { return STATE.products.find(function (p) { return p.id === String(id); }); };
var axes = function (p) { return [p.sizes.length ? p.sizes : [''], p.colors.length ? p.colors : [''], p.styles.length ? p.styles : ['']]; };
var vkey = function (s, c, t) { return JSON.stringify([s || '', c || '', t || '']); };
var vlabel = function (x) { return [x.size, x.color, x.style].filter(Boolean).join(' / ') || 'Finished unit'; };
var sumQty = function (cs) { return cs.reduce(function (s, c) { return s + c.qty; }, 0); };
var serialText = function (prefix, num, pad) { return prefix + String(num).padStart(pad || 0, '0'); };
var kindOf = function (t) { return String(t || 'Carton').trim().toLowerCase(); };
var DEFAULT_SPEC = { Carton: 'Standard carton', Bale: 'Standard bale', Roll: 'Standard roll', Bag: 'Retail bag', Other: 'Other packaging' };
var COLOR_DOT = { black: '#202b2b', green: '#558a6b', white: '#fff', grey: '#abb4b8', gray: '#abb4b8', blue: '#709ab8',
  navy: '#1f3356', red: '#b8283f', maroon: '#73202f', pink: '#e39ab0', royal: '#2f55c0', yellow: '#e6c34a', brown: '#7a5438' };
function dot(c) { var k = String(c).toLowerCase().replace(/[^a-z]/g, ''); return COLOR_DOT[k] || '#b9cec5'; }

/* ---------------- every saved package, one by one (a range is stored once
   on the server; here it is opened out so each package is its own record) */
function packages() {
  var out = [];
  STATE.records.forEach(function (r) {
    for (var n = r.from; n <= r.to; n++) out.push({ gid: r.id, num: n, prefix: r.prefix, pad: r.pad, item: r.item,
      type: r.type, spec: r.spec, target: r.target, edited: r.edited, weighed: r.weighed,
      combos: r.combos.map(function (c) { return { size: c.size, color: c.colour, style: c.style, qty: c.qty }; }) });
  });
  return out.sort(function (a, b) { return a.prefix.localeCompare(b.prefix) || kindOf(a.type).localeCompare(kindOf(b.type)) || a.num - b.num; });
}
function parseSerial(value, fallback, pad) {
  var v = String(value).trim(), m = v.match(/^([A-Za-z_-]*)(\d+)$/);
  if (!m) throw Error('Use a positive serial number, optionally prefixed: 25, C025 or CTN-025.');
  var n = Number(m[2]);
  if (!Number.isSafeInteger(n) || n < 1 || n > 999999999) throw Error('Serial must be a whole number from 1 to 999,999,999.');
  var prefix = (m[1] || fallback || '').toUpperCase();
  if (prefix.length > 16) throw Error('A prefix may be up to 16 letters, hyphens or underscores.');
  return { prefix: prefix, num: n, pad: Math.max(Number(pad) || 0, m[2].charAt(0) === '0' ? m[2].length : 0) };
}
/* The next free number for this prefix AND kind of package — the same
   rule the server keeps: a number once per prefix and kind. */
function nextSerial(prefix, pad, type, after) {
  prefix = (prefix || '').toUpperCase();
  var used = {};
  packages().forEach(function (p) { if (p.prefix.toUpperCase() === prefix && kindOf(p.type) === kindOf(type)) used[p.num] = 1; });
  var n = after ? after + 1 : Object.keys(used).reduce(function (m, v) { return Math.max(m, +v); }, 0) + 1;
  while (used[n]) n++;
  return serialText(prefix, n, pad);
}
function rangeForDraft(d) {
  var s = parseSerial(d.start, d.prefix, d.pad), e = d.entryMode === 'range' ? parseSerial(d.end, s.prefix, s.pad) : s;
  if (e.prefix !== s.prefix) throw Error('Start and end must use the same prefix.');
  if (e.num < s.num) throw Error('The ending serial must be at or after the starting one.');
  var count = e.num - s.num + 1;
  if (count > 5000) throw Error('Save up to 5,000 packages at a time.');
  return { start: s, end: e, count: count, pad: Math.max(s.pad, e.pad) };
}
function checkComposition(p, cs, target) {
  if (!Number.isInteger(target) || target < 1 || target > 1000000) throw Error('Quantity per package must be a whole number from 1 to 1,000,000.');
  var a = axes(p), keys = {};
  cs.forEach(function (c) {
    if (!Number.isInteger(c.qty) || c.qty <= 0) throw Error('Every combination needs a positive whole quantity.');
    if (a[0].indexOf(c.size) < 0 || a[1].indexOf(c.color) < 0 || a[2].indexOf(c.style) < 0) throw Error('This combination is not one this product is packed in.');
    var k = vkey(c.size, c.color, c.style); if (keys[k]) throw Error('The same combination is listed twice.'); keys[k] = 1;
  });
  if (sumQty(cs) !== target) throw Error('Complete the package first: ' + sumQty(cs) + ' of ' + target + ' ' + p.unit + ' entered.');
}

/* ---------------- the draft: what is being packed now. Kept in this
   browser only, so a half-counted carton survives a lost signal. */
function blankDraft(itemId, cfg) {
  var p = prod(itemId), a = axes(p); cfg = cfg || {};
  var type = cfg.packageType || 'Carton';
  return { productId: p.id, packageType: type, spec: cfg.spec || DEFAULT_SPEC[type] || '', target: cfg.target || 10,
    entryMode: 'single', prefix: cfg.prefix != null ? cfg.prefix : '', pad: cfg.pad != null ? cfg.pad : 0, start: '', end: '',
    selectedSize: cfg.selectedSize != null && a[0].indexOf(cfg.selectedSize) >= 0 ? cfg.selectedSize : a[0][0],
    selectedColor: cfg.selectedColor != null && a[1].indexOf(cfg.selectedColor) >= 0 ? cfg.selectedColor : a[1][0],
    selectedStyle: cfg.selectedStyle != null && a[2].indexOf(cfg.selectedStyle) >= 0 ? cfg.selectedStyle : a[2][0],
    combos: clone(cfg.combos || []).filter(function (c) { return a[0].indexOf(c.size) >= 0 && a[1].indexOf(c.color) >= 0 && a[2].indexOf(c.style) >= 0; }),
    edit: null, advancedOpen: false, compositionOpen: false };
}
/* What this product was packed as last time — its last saved range —
   so switching back to it restores the package details and contents. */
function lastConfig(itemId) {
  var last = null;
  STATE.records.forEach(function (r) { if (r.item === String(itemId) && (!last || r.id > last.id)) last = r; });
  if (!last) return null;
  var cs = last.combos.map(function (c) { return { size: c.size, color: c.colour, style: c.style, qty: Math.round(c.qty) }; });
  return { packageType: last.type, spec: last.spec, target: Math.round(last.target) || 10, prefix: last.prefix, pad: last.pad,
    combos: cs, selectedSize: cs[0] && cs[0].size, selectedColor: cs[0] && cs[0].color, selectedStyle: cs[0] && cs[0].style };
}
function saveLocal() { try { localStorage.setItem(DRAFT_KEY, JSON.stringify(draft)); } catch (e) {} }
function loadLocal() { try { var d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); return d && prod(d.productId) ? d : null; } catch (e) { return null; } }
function freshDraft(itemId) {
  draft = blankDraft(itemId, lastConfig(itemId) || {});
  draft.start = nextSerial(draft.prefix, draft.pad, draft.packageType); draft.end = draft.start;
}

function toast(msg, error) {
  var el = $('#spToast'); el.textContent = msg; el.classList.toggle('error', !!error); el.hidden = false;
  clearTimeout(toastTimer); toastTimer = setTimeout(function () { el.hidden = true; }, 3300);
}

/* ---------------- talking to the server */
function api(body) {
  body._csrf = CSRF;
  return fetch(API, { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
    .then(function (r) { return r.json().catch(function () { throw Error('The server did not answer properly (' + r.status + ').'); }); })
    .then(function (j) { if (j.state) { STATE = j.state; recordFilter = null; } if (!j.ok) throw Error(j.msg || 'That did not save.'); return j; });
}

/* ---------------- drawing */
function nav() {
  document.querySelectorAll('#sp [data-a=tab]').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
  var n = packages().length; $('#spCount').textContent = n ? '(' + fmt(n) + ')' : '';
}
function render() { nav(); $('#spDock').innerHTML = ''; if (tab === 'packing') renderPacking(); else if (tab === 'records') renderRecords(); else renderSummary(); }
function serialLabel(d) {
  try { var r = rangeForDraft(d); return serialText(r.start.prefix, r.start.num, r.pad) + (r.count > 1 ? ' – ' + serialText(r.end.prefix, r.end.num, r.pad) : ''); }
  catch (e) { return d.entryMode === 'range' ? d.start + ' – ' + d.end : d.start; }
}
function touchGrid(values, type, d, p) {
  return values.map(function (v) {
    var qty = d.combos.filter(function (c) { return c[type] === v; }).reduce(function (s, c) { return s + c.qty; }, 0);
    var selected = d['selected' + type.charAt(0).toUpperCase() + type.slice(1)] === v;
    var counts = type === 'color' || (type === 'style' && !p.colors.length);
    var detail = fmt(qty) + ' ' + p.unit;
    if (type === 'color') {
      var here = (d.combos.find(function (c) { return c.size === d.selectedSize && c.color === v && c.style === d.selectedStyle; }) || {}).qty || 0;
      detail = fmt(qty) + ' total · ' + here + ' here';
    }
    return '<button type="button" class="touch' + (selected ? ' active' : '') + '" data-a="' + (counts ? 'count' : 'select-attr') + '" data-type="' + type
      + '" data-value="' + esc(v) + '" aria-pressed="' + selected + '" aria-label="' + esc(v) + ', ' + qty + ' ' + esc(p.unit) + (counts ? ', tap adds one' : ', select') + '">'
      + '<span class="t">' + (type === 'color' ? '<i class="dot" style="background:' + dot(v) + '"></i>' : '') + esc(v) + '</span><span class="b">' + detail + '</span></button>';
  }).join('');
}
function renderPacking() {
  var d = draft, p = prod(d.productId), qty = sumQty(d.combos), complete = qty === d.target, rangeLabel = 'Enter a serial to see the total';
  try { var r = rangeForDraft(d); rangeLabel = serialLabel(d) + ' · ' + fmt(r.count) + ' ' + (r.count === 1 ? 'package' : 'packages') + ' · ' + fmt(r.count * d.target) + ' ' + p.unit; } catch (e) {}
  var lineRef = esc(p.line) + (p.hint ? ' · ' + esc(p.hint) : '') + ' · ' + esc(p.unit);
  $('#spMain').innerHTML =
    '<div class="row between" style="margin-bottom:10px"><h2>' + (d.edit ? 'Edit package' : 'Pack the next ' + esc(d.packageType.toLowerCase())) + '</h2>'
    + '<span class="pill ' + (d.edit ? 'warn' : 'gray') + '">' + (d.edit ? 'ONE PACKAGE ONLY' : esc(STATE.shipment.invoice)) + '</span></div>'
    + '<div class="sel"><div><div class="name">' + esc(p.name) + '</div><div class="ref">' + lineRef + '</div></div>'
    + '<span class="pill ' + (p.setUp ? '' : 'warn') + '">' + (p.setUp ? 'Set up' : 'Not set up') + '</span></div>'
    + '<div class="search"><input id="spSearch" type="search" autocomplete="off" placeholder="Search product, line or description…" value="' + esc(searchQuery) + '" aria-label="Search the invoice\'s products">'
    + '<div id="spResults" class="results" hidden></div></div>'
    + (p.setUp ? '' : '<div class="sp-notice sp-warn gap">This line is not set up on Order sizes &amp; colours, so these are the product\'s own sizes and no colours.</div>')
    + '<div class="card gap">'
    + '<div class="segment"><button type="button" data-a="entry-mode" data-mode="single" class="' + (d.entryMode === 'single' ? 'active' : '') + '">SINGLE ' + esc(d.packageType.toUpperCase())
    + '</button><button type="button" data-a="entry-mode" data-mode="range" class="' + (d.entryMode === 'range' ? 'active' : '') + '"' + (d.edit ? ' disabled' : '') + '>SERIAL RANGE</button></div>'
    + '<div class="sgrid' + (d.entryMode === 'range' ? ' range' : '') + '"><div><label for="spStart">' + (d.entryMode === 'range' ? 'Starting serial' : 'Package serial') + '</label>'
    + '<input id="spStart" data-sel enterkeyhint="next" inputmode="text" autocomplete="off" value="' + esc(d.start) + '"></div>'
    + (d.entryMode === 'range' ? '<div><label for="spEnd">Ending serial</label><input id="spEnd" data-sel enterkeyhint="next" inputmode="text" autocomplete="off" value="' + esc(d.end) + '"></div>' : '')
    + '<div class="qtyf"><label for="spTarget">' + esc(p.unit) + ' / ' + esc(d.packageType.toLowerCase()) + '</label>'
    + '<input id="spTarget" type="text" inputmode="numeric" pattern="[0-9]*" data-sel enterkeyhint="next" value="' + d.target + '"></div></div>'
    + '<div class="preview" id="spPreview">' + esc(rangeLabel) + '</div>'
    + '<details id="spPkgDetails"' + (d.advancedOpen ? ' open' : '') + '><summary><span>' + esc(d.packageType) + ' · ' + esc(d.spec) + ' · prefix ' + (esc(d.prefix) || 'none') + '</span></summary>'
    + '<div class="settings"><div><label for="spType">Package type</label><select id="spType">' + ['Carton', 'Bale', 'Roll', 'Bag', 'Other'].map(function (v) { return '<option' + (v === d.packageType ? ' selected' : '') + '>' + v + '</option>'; }).join('') + '</select></div>'
    + '<div><label for="spSpec">Packaging specification</label><input id="spSpec" value="' + esc(d.spec) + '" maxlength="80"></div>'
    + '<div><label for="spPrefix">Serial prefix (optional)</label><input id="spPrefix" value="' + esc(d.prefix) + '" placeholder="C or CTN-" maxlength="16" autocapitalize="characters"></div>'
    + '<div><label for="spPad">Number padding</label><select id="spPad">' + [0, 2, 3, 4, 5, 6].map(function (n) { return '<option value="' + n + '"' + (n === d.pad ? ' selected' : '') + '>' + (n ? n + ' digits' : 'None') + '</option>'; }).join('') + '</select></div></div></details>'
    + '</div>'
    + '<div class="card gap"><div class="row between"><h3>Count the contents</h3><button type="button" class="mini quiet" data-a="undo"' + (history.length ? '' : ' disabled') + '>↶ Undo</button></div>'
    + (p.sizes.length ? '<div class="alabel"><span>1 · Select size</span><span>' + esc(p.unit) + ' by size</span></div><div class="tgrid" id="spSizes" style="grid-template-columns:repeat(' + Math.min(4, p.sizes.length) + ',1fr)">' + touchGrid(p.sizes, 'size', d, p) + '</div>' : '')
    + (p.styles.length ? '<div class="alabel"><span>' + (p.colors.length ? 'Select style' : (p.sizes.length ? '2 · ' : '') + 'Tap style to count') + '</span><span>' + esc(p.unit) + ' by style</span></div><div class="tgrid" id="spStyles">' + touchGrid(p.styles, 'style', d, p) + '</div>' : '')
    + (p.colors.length ? '<div class="alabel"><span>' + (p.sizes.length ? '2 · ' : '') + 'Tap colour to count</span><span>' + esc(d.selectedSize) + '</span></div><div class="tgrid colors" id="spColors">' + touchGrid(p.colors, 'color', d, p) + '</div>' : '')
    + (!p.colors.length && !p.styles.length ? '<button type="button" class="soft full gap" data-a="count" data-type="unit" data-value="">+ Add one ' + (p.sizes.length ? esc(d.selectedSize || '') + ' ' : '') + esc(p.unit) + '</button>' : '')
    + '<div class="hint">Tap = +1 · hold a ' + (p.colors.length ? 'colour' : p.styles.length ? 'style' : 'count') + ' button to type a quantity.</div>'
    + '<div class="row gap"><button type="button" class="soft grow" data-a="fill">Fill remaining ' + fmt(Math.max(0, d.target - qty)) + ' ' + esc(p.unit) + '</button>'
    + '<button type="button" class="mini" data-a="edit-current">Edit qty</button><button type="button" class="mini quiet danger" data-a="clear"' + (qty ? '' : ' disabled') + '>Clear</button></div>'
    + '<details id="spCompDetails"' + (d.edit || d.compositionOpen ? ' open' : '') + '><summary><span>Composition · ' + d.combos.length + ' ' + (d.combos.length === 1 ? 'combination' : 'combinations') + '</span><span>' + fmt(qty) + ' ' + esc(p.unit) + '</span></summary>'
    + '<div class="combos">' + (d.combos.length ? d.combos.map(function (c, i) {
        return '<div class="combo"><span class="text grow">' + esc(vlabel(c)) + '</span><button type="button" data-a="combo-minus" data-i="' + i + '" aria-label="Remove one ' + esc(vlabel(c)) + '">−</button>'
          + '<button type="button" class="amt" data-a="combo-edit" data-i="' + i + '" aria-label="Edit ' + esc(vlabel(c)) + '">' + c.qty + '</button>'
          + '<button type="button" data-a="combo-plus" data-i="' + i + '" aria-label="Add one ' + esc(vlabel(c)) + '">+</button></div>'; }).join('')
      : '<div class="hint">Select a size, then tap a colour or style.</div>') + '</div></details></div>'
    + (d.edit ? '<button type="button" class="quiet full gap" data-a="cancel-edit">Cancel edit · back to new packing</button>'
              : '<div class="hint" style="text-align:center;margin-top:12px">Save &amp; Next keeps this product, quantity and contents ready.</div>');
  if (!CAN_EDIT || STATE.completed) { $('#spDock').innerHTML = ''; if (STATE.completed && CAN_EDIT) $('#spMain').insertAdjacentHTML('afterbegin', '<div class="sp-notice sp-warn" style="margin-bottom:12px">Packing is finished. Reopen it on Summary to change anything.</div>'); return; }
  $('#spDock').innerHTML = '<div class="sp-dock"><div class="crow"><div class="cval">' + fmt(qty) + ' <span style="font-weight:500;color:#64797b">/ ' + fmt(d.target) + ' ' + esc(p.unit) + '</span></div>'
    + '<div class="crem">' + (complete ? '<span class="pill">✓ COMPLETE</span>' : fmt(Math.max(0, d.target - qty)) + ' remaining') + '</div></div>'
    + '<div class="prog" role="progressbar" aria-valuemin="0" aria-valuemax="' + d.target + '" aria-valuenow="' + qty + '"><i style="width:' + Math.min(100, qty / d.target * 100) + '%"></i></div>'
    + '<button type="button" id="spSave" class="sp-save" data-a="save"></button></div>';
  renderSaveButton();
}
function renderSaveButton() {
  var b = $('#spSave'); if (!b || tab !== 'packing') return;
  var d = draft, p = prod(d.productId), count = 1; try { count = rangeForDraft(d).count; } catch (e) {}
  var ok = receipt && receipt.next === d.start && receipt.productId === d.productId ? receipt : null;
  b.classList.toggle('ok', !!ok); b.disabled = saveBusy;
  b.innerHTML = '<span class="st">' + (saveBusy ? 'SAVING…' : ok ? '✓ ' + (ok.updated ? 'Updated' : 'Saved') + ' ' + esc(ok.label) : (d.edit ? 'SAVE CHANGES' : 'SAVE &amp; NEXT')) + '</span>'
    + '<span class="sc">' + (ok ? 'Next · ' : '') + esc(d.packageType + ' ' + serialLabel(d) + ' · ' + fmt(count * d.target) + ' ' + p.unit) + ' →</span>';
}
function updatePreview() {
  var d = draft; try { var r = rangeForDraft(d); $('#spPreview').textContent = serialLabel(d) + ' · ' + fmt(r.count) + ' packages · ' + fmt(r.count * d.target) + ' ' + prod(d.productId).unit; }
  catch (e) { $('#spPreview').textContent = e.message; } renderSaveButton();
}

/* ---------------- counting */
function snapshot() { history.push(clone(draft)); if (history.length > 50) history.shift(); }
function setCombo(size, color, style, qty) {
  var d = draft; if (!Number.isInteger(qty) || qty < 0) throw Error('Enter a whole quantity of zero or more.');
  var k = vkey(size, color, style), i = d.combos.findIndex(function (c) { return vkey(c.size, c.color, c.style) === k; }), old = i < 0 ? 0 : d.combos[i].qty;
  var total = sumQty(d.combos) - old + qty;
  if (total > d.target) throw Error('The package holds ' + d.target + '; only ' + Math.max(0, d.target - (sumQty(d.combos) - old)) + ' can go in this combination.');
  snapshot();
  if (i < 0) { if (qty) d.combos.push({ size: size, color: color, style: style, qty: qty }); } else if (qty) d.combos[i].qty = qty; else d.combos.splice(i, 1);
  d.selectedColor = color; d.selectedStyle = style; saveLocal(); renderPacking();
}
function increment(color, style) {
  var d = draft; if (sumQty(d.combos) >= d.target) { toast('Package complete · use − or Edit qty to correct'); return; }
  var c = d.combos.find(function (c) { return vkey(c.size, c.color, c.style) === vkey(d.selectedSize, color, style); });
  setCombo(d.selectedSize, color, style, (c ? c.qty : 0) + 1);
}

/* ---------------- Save & Next */
function syncEntry() {
  var d = draft;
  if ($('#spStart')) d.start = $('#spStart').value;
  if ($('#spEnd')) d.end = $('#spEnd').value;
  var q = $('#spTarget');
  if (q && Number(q.value) !== d.target) {
    var n = Number(q.value);
    if (!Number.isInteger(n) || n < 1 || n > 1000000 || sumQty(d.combos) > n) throw Error('Use a package quantity at least equal to what is counted.');
    d.target = n;
  }
}
function saveDraft() {
  if (saveBusy) return;
  var d = draft, p = prod(d.productId), range;
  try { syncEntry(); checkComposition(p, d.combos, d.target); range = rangeForDraft(d);
        if (d.edit && range.count !== 1) throw Error('Edit one package at a time.'); }
  catch (e) { toast(e.message, true); return; }
  saveBusy = true; receipt = null; renderSaveButton();
  var wasEdit = !!d.edit;
  api({ op: 'save', item: p.id, type: d.packageType, spec: (d.spec || '').trim() || DEFAULT_SPEC[d.packageType] || '',
        prefix: range.start.prefix, pad: range.pad, from: range.start.num, to: range.end.num, target: d.target,
        combos: d.combos.map(function (c) { return { size: c.size, colour: c.color, style: c.style, qty: c.qty }; }),
        edit_group: d.edit ? d.edit.gid : 0, edit_num: d.edit ? d.edit.num : 0 })
    .then(function () {
      saveBusy = false;
      d.prefix = range.start.prefix; d.pad = range.pad; d.edit = null; history = [];
      d.start = nextSerial(d.prefix, d.pad, d.packageType, wasEdit ? 0 : range.end.num); d.end = d.start;
      saveLocal(); render();
      var label = serialText(range.start.prefix, range.start.num, range.pad) + (range.count > 1 ? ' – ' + serialText(range.end.prefix, range.end.num, range.pad) : '');
      receipt = { label: label, next: d.start, productId: d.productId, updated: wasEdit }; renderSaveButton();
      clearTimeout(receiptTimer); receiptTimer = setTimeout(function () { receipt = null; renderSaveButton(); }, 1600);
      toast((wasEdit ? 'Updated ' : 'Saved ') + fmt(range.count) + ' ' + (range.count === 1 ? d.packageType.toLowerCase() : 'packages') + ' · next ' + d.start + ' ready');
    })
    .catch(function (e) { saveBusy = false; renderSaveButton(); toast(e.message, true); });
}
function editPackage(pkg, copy) {
  var cfg = { packageType: pkg.type, spec: pkg.spec, target: Math.round(pkg.target), prefix: pkg.prefix, pad: pkg.pad,
    combos: pkg.combos.map(function (c) { return { size: c.size, color: c.color, style: c.style, qty: Math.round(c.qty) }; }),
    selectedSize: pkg.combos[0] && pkg.combos[0].size, selectedColor: pkg.combos[0] && pkg.combos[0].color, selectedStyle: pkg.combos[0] && pkg.combos[0].style };
  draft = blankDraft(pkg.item, cfg);
  draft.edit = copy ? null : { gid: pkg.gid, num: pkg.num };
  draft.start = copy ? nextSerial(pkg.prefix, pkg.pad, pkg.type) : serialText(pkg.prefix, pkg.num, pkg.pad); draft.end = draft.start;
  tab = 'packing'; history = []; saveLocal(); render(); window.scrollTo(0, 0);
  toast(copy ? 'Contents copied onto the next free serial' : 'Editing this package only');
}
function cancelEdit() { draft.edit = null; draft.start = nextSerial(draft.prefix, draft.pad, draft.packageType); draft.end = draft.start; history = []; saveLocal(); render(); toast('New package ready; the saved one is kept'); }

/* ---------------- records */
function signature(p) { return JSON.stringify([p.item, kindOf(p.type), p.spec, p.target, p.combos.map(function (c) { return [c.size, c.color, c.style, c.qty]; }).sort()]); }
function grouped(list) {
  var gs = [];
  list.forEach(function (p) {
    var g = gs[gs.length - 1], sig = signature(p);
    if (g && g.prefix === p.prefix && kindOf(g.last.type) === kindOf(p.type) && g.last.num + 1 === p.num && g.sig === sig && !p.edited && !g.last.edited) { g.list.push(p); g.last = p; }
    else gs.push({ first: p, last: p, prefix: p.prefix, sig: sig, list: [p] });
  });
  return gs;
}
var gSerial = function (g) { return serialText(g.first.prefix, g.first.num, g.first.pad) + (g.list.length > 1 ? ' – ' + serialText(g.last.prefix, g.last.num, g.last.pad) : ''); };
function totals() {
  var units = {}, all = packages();
  all.forEach(function (p) { var u = prod(p.item) ? prod(p.item).unit : 'pcs'; units[u] = (units[u] || 0) + p.target; });
  return { packages: all.length, quantity: all.reduce(function (s, p) { return s + p.target; }, 0), units: units,
    products: Object.keys(all.reduce(function (o, p) { o[p.item] = 1; return o; }, {})).length };
}
var unitText = function (s) { return Object.keys(s.units).map(function (u) { return fmt(s.units[u]) + ' ' + u; }).join(' + '); };
function filtered() {
  return packages().filter(function (p) {
    if (recordFilter && recordFilter.indexOf(p.gid + ':' + p.num) < 0) return false;
    if (!recordQuery) return true;
    var pr = prod(p.item) || { name: '', line: '' };
    return (serialText(p.prefix, p.num, p.pad) + ' ' + pr.name + ' ' + pr.line + ' ' + p.type).toLowerCase().indexOf(recordQuery.toLowerCase()) >= 0;
  });
}
function renderRecords() {
  var s = totals();
  $('#spMain').innerHTML = '<div class="row between" style="margin-bottom:6px"><div><div class="eyebrow">Review &amp; correct</div><h2>Packing records</h2></div>'
    + (CAN_EDIT && !STATE.completed ? '<button type="button" class="primary mini" data-a="tab" data-tab="packing">+ Pack</button>' : '') + '</div>'
    + '<div class="stats"><div class="stat"><div class="v">' + fmt(s.packages) + '</div><div class="c">Packages</div></div><div class="stat"><div class="v">' + fmt(s.quantity) + '</div><div class="c">Packed units</div></div><div class="stat"><div class="v">' + s.products + '</div><div class="c">Products</div></div></div>'
    + '<div class="ref" style="margin-bottom:12px">' + esc(STATE.shipment.invoice) + ' · ' + esc(unitText(s)) + '</div>'
    + '<div class="toolbar"><input id="spRecSearch" type="search" value="' + esc(recordQuery) + '" placeholder="Search serial, product or line…" aria-label="Search saved packages">'
    + '<div class="segment"><button type="button" data-a="record-mode" data-mode="individual" class="' + (recordMode === 'individual' ? 'active' : '') + '">Individual</button><button type="button" data-a="record-mode" data-mode="ranges" class="' + (recordMode === 'ranges' ? 'active' : '') + '">Ranges</button></div></div>'
    + (recordFilter ? '<div class="sp-notice row between" style="margin-bottom:12px"><span>Showing one range</span><button type="button" class="mini" data-a="clear-filter">Show all</button></div>' : '')
    + '<div id="spRecList"></div>'
    + '<div class="toolbar gap"><button type="button" data-a="report">Final packing list</button><button type="button" data-a="print">Print / PDF</button><button type="button" data-a="csv">CSV</button><button type="button" class="soft" data-a="tab" data-tab="summary">Quantity summary →</button></div>'
    + '<div class="hint" style="text-align:center">Each package is its own record. Editing one does not change the rest of its range.</div>';
  renderRecordList();
}
function renderRecordList() {
  var rs = filtered(), items = recordMode === 'ranges' ? grouped(rs) : rs.map(function (p) { return { first: p, last: p, list: [p] }; });
  var pages = Math.max(1, Math.ceil(items.length / 25)); recordPage = Math.min(recordPage, pages - 1);
  var live = CAN_EDIT && !STATE.completed;
  $('#spRecList').innerHTML = items.slice(recordPage * 25, recordPage * 25 + 25).map(function (g) {
    var r = g.first, p = prod(r.item) || { name: '(product removed)', unit: 'pcs', line: '' }, n = g.list.length;
    return '<article class="rec card"><div><div class="row wrap"><span class="serial">' + esc(gSerial(g)) + '</span><span class="pill">' + esc(r.type) + '</span>' + (r.edited ? '<span class="pill warn">Edited</span>' : '') + '</div>'
      + '<div class="rprod">' + esc(p.name) + ' <span class="muted" style="font-weight:600">' + esc(p.line) + '</span> · ' + fmt(r.target) + ' ' + esc(p.unit) + ' / ' + esc(r.type.toLowerCase()) + '</div>'
      + '<div class="brk">' + r.combos.map(function (c) { return esc(vlabel(c)) + ': <b>' + fmt(c.qty) + '</b>'; }).join(' · ') + '</div></div>'
      + '<div class="acts">' + (n > 1 ? '<button type="button" data-a="view-range" data-keys="' + esc(g.list.map(function (x) { return x.gid + ':' + x.num; }).join(',')) + '">View ' + n + '</button>'
        : (live ? '<button type="button" data-a="edit" data-g="' + r.gid + '" data-n="' + r.num + '">Edit</button>' : '')
          + (live ? '<button type="button" data-a="copy" data-g="' + r.gid + '" data-n="' + r.num + '">Copy</button>' : '')
          + (live ? '<button type="button" class="danger" data-a="delete" data-g="' + r.gid + '" data-n="' + r.num + '">Delete</button>' : '')) + '</div>'
      + '<div class="rfoot"><span>' + (n > 1 ? fmt(n) + ' packages · ' + fmt(n * r.target) + ' ' + esc(p.unit) : esc(r.type) + (r.spec ? ' · ' + esc(r.spec) : '')) + '</span><span>' + (r.combos.length === 1 ? 'Standard' : 'Assorted') + ' packing</span></div></article>';
  }).join('') || '<div class="card empty">No packages yet. Start packing, or clear the search.</div>';
  if (pages > 1) $('#spRecList').innerHTML += '<div class="row between gap"><button type="button" data-a="page" data-dir="-1"' + (recordPage ? '' : ' disabled') + '>← Previous</button><span class="small muted">' + (recordPage + 1) + ' / ' + pages + '</span><button type="button" data-a="page" data-dir="1"' + (recordPage + 1 < pages ? '' : ' disabled') + '>Next →</button></div>';
}
function findPkg(g, n) { return packages().find(function (p) { return p.gid === +g && p.num === +n; }); }

/* ---------------- summary */
function summaryLines() {
  var map = {}, order = [];
  packages().forEach(function (pk) {
    pk.combos.forEach(function (c) {
      var k = JSON.stringify([pk.item, c.size, c.color, c.style]);
      if (!map[k]) { map[k] = { item: pk.item, size: c.size, color: c.color, style: c.style, qty: 0, pk: {} }; order.push(k); }
      map[k].qty += c.qty; map[k].pk[pk.gid + ':' + pk.num] = 1;
    });
  });
  return order.map(function (k) { var l = map[k]; l.packages = Object.keys(l.pk).length; return l; });
}
function renderSummary() {
  var s = totals(), lines = summaryLines(), ids = [];
  lines.forEach(function (l) { if (ids.indexOf(l.item) < 0) ids.push(l.item); });
  var firstRange = function (item) { var r = STATE.records.find(function (x) { return x.item === item; }); return r ? r.id : 0; };
  $('#spMain').innerHTML = '<div class="row between" style="margin-bottom:10px"><div><div class="eyebrow">Final packing quantities</div><h2>Quantity summary</h2></div>'
    + '<span class="pill ' + (STATE.completed ? '' : 'warn') + '">' + (STATE.completed ? '✓ Packing finished' : 'In progress') + '</span></div>'
    + '<div class="card"><div class="row between"><div><b>' + esc(STATE.shipment.invoice) + '</b><div class="ref">' + esc(STATE.shipment.buyer) + '</div></div><span class="pill gray">' + fmt(s.packages) + ' packages</span></div>'
    + '<div class="stotal gap">' + (esc(unitText(s)) || 'Nothing packed yet') + '</div>'
    + '<div class="hint">Each line adds up the same size, colour and style across every saved package. It is not compared with the invoice.</div>'
    + (CAN_EDIT ? '<div class="row wrap gap">' + (STATE.completed ? '<button type="button" class="mini" data-a="reopen">Reopen packing</button>' : '<button type="button" class="primary" data-a="finish"' + (s.packages ? '' : ' disabled') + '>Finish packing</button>') + '<button type="button" class="mini" data-a="report">Packing list</button></div>' : '')
    + '</div>'
    + ids.map(function (id) {
        var p = prod(id) || { name: '(product removed)', unit: 'pcs', line: '' }, ls = lines.filter(function (l) { return l.item === id; });
        var weighed = STATE.records.filter(function (r) { return r.item === id; }).every(function (r) { return r.weighed; });
        return '<section class="card sprod"><div class="row between"><div><h3>' + esc(p.name) + '</h3><div class="ref">' + esc(p.line) + (p.hint ? ' · ' + esc(p.hint) : '') + '</div></div>'
          + '<div style="text-align:right"><strong>' + fmt(ls.reduce(function (n, l) { return n + l.qty; }, 0)) + ' ' + esc(p.unit) + '</strong><br>'
          + '<a class="small" style="color:#087f72" href="' + esc(WEIGHT_URL + '&g=' + firstRange(id)) + '">' + (weighed ? 'Weight ✓' : 'Weight: not entered') + ' →</a></div></div>'
          + ls.map(function (l) { return '<div class="sline"><b>' + esc(vlabel(l)) + '</b><div class="small muted">' + fmt(l.qty) + ' ' + esc(p.unit) + ' · in ' + fmt(l.packages) + ' ' + (l.packages === 1 ? 'package' : 'packages') + '</div></div>'; }).join('')
          + '</section>';
      }).join('')
    + (ids.length ? '' : '<div class="empty">Start packing to see the quantity summary.</div>')
    + '<div class="sp-notice gap">Weight is moving to its own module. Until then each product\'s weight is entered on the Weight screen linked above.</div>'
    + '<div class="row wrap gap"><button type="button" data-a="print">Print / PDF</button><button type="button" data-a="csv">Packing CSV</button><button type="button" data-a="summary-csv">Summary CSV</button></div>';
}

/* ---------------- packing list, print and CSV */
function reportHTML() {
  var s = totals(), gs = grouped(packages()), lines = summaryLines();
  return '<div class="sp-print"><div style="display:flex;justify-content:space-between;gap:12px"><div><div class="eyebrow">ZAS TEXTILE · FAISALABAD, PAKISTAN</div><h1>EXPORT PACKING LIST</h1>'
    + '<div class="ref">Invoice: <b>' + esc(STATE.shipment.invoice) + '</b> · ' + esc(STATE.shipment.buyer) + '<br>Prepared ' + new Date().toLocaleDateString('en-GB') + ' · ' + (STATE.completed ? 'Packing finished' : 'In progress') + '</div></div>'
    + '<div class="sp-notice">' + fmt(s.packages) + ' packages<br>' + esc(unitText(s)) + '</div></div>'
    + '<div class="tablewrap"><table><thead><tr><th>Serial / range</th><th>Product</th><th>Package</th><th>Size / colour / style per package</th><th>Qty / pkg</th><th>Packages</th><th>Total qty</th></tr></thead><tbody>'
    + gs.map(function (g) { var r = g.first, p = prod(r.item) || { name: '', unit: '', line: '' }, n = g.list.length;
        return '<tr><td><b>' + esc(gSerial(g)) + '</b>' + (r.edited ? '<br><small>Edited on its own</small>' : '') + '</td><td>' + esc(p.name) + '<br><small>' + esc(p.line) + '</small></td><td>' + esc(r.type) + (r.spec ? '<br><small>' + esc(r.spec) + '</small>' : '') + '</td><td>'
          + r.combos.map(function (c) { return esc(vlabel(c)) + ' × ' + fmt(c.qty); }).join('<br>') + '</td><td class="num">' + fmt(r.target) + ' ' + esc(p.unit) + '</td><td class="num">' + n + '</td><td class="num">' + fmt(n * r.target) + ' ' + esc(p.unit) + '</td></tr>'; }).join('')
    + '</tbody></table></div><h3 style="margin-top:14px">Quantity by product, size, colour and style</h3><div class="tablewrap"><table><thead><tr><th>Product</th><th>Size / colour / style</th><th>Total quantity</th><th>Packages</th></tr></thead><tbody>'
    + lines.map(function (l) { var p = prod(l.item) || { name: '', unit: '' }; return '<tr><td>' + esc(p.name) + '</td><td>' + esc(vlabel(l)) + '</td><td class="num">' + fmt(l.qty) + ' ' + esc(p.unit) + '</td><td class="num">' + fmt(l.packages) + '</td></tr>'; }).join('')
    + '</tbody></table></div></div>';
}
function csvRows(rows) {
  var safe = function (v) { var s = String(v == null ? '' : v); if (/^[=+@\-\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = "'" + s; return '"' + s.replace(/"/g, '""') + '"'; };
  return '﻿' + rows.map(function (r) { return r.map(safe).join(','); }).join('\r\n');
}
function download(text, name) {
  var blob = new Blob([text], { type: 'text/csv;charset=utf-8' }), url = URL.createObjectURL(blob), a = document.createElement('a');
  a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 3000); toast(name + ' downloaded');
}
function packingCSV() {
  return csvRows([['Invoice', 'Serial / Range', 'Product', 'Line', 'Package Type', 'Packaging Spec', 'Contents per Package', 'Qty per Package', 'Unit', 'Packages', 'Total Qty', 'Edited on its own']]
    .concat(grouped(packages()).map(function (g) { var r = g.first, p = prod(r.item) || { name: '', unit: '', line: '' }, n = g.list.length;
      return [STATE.shipment.invoice, gSerial(g), p.name, p.line, r.type, r.spec, r.combos.map(function (c) { return vlabel(c) + ': ' + c.qty; }).join('; '), r.target, p.unit, n, r.target * n, r.edited ? 'Yes' : 'No']; })));
}
function summaryCSV() {
  return csvRows([['Invoice', 'Product', 'Line', 'Size', 'Colour', 'Style', 'Total Qty', 'Unit', 'Packages']]
    .concat(summaryLines().map(function (l) { var p = prod(l.item) || { name: '', unit: '', line: '' }; return [STATE.shipment.invoice, p.name, p.line, l.size, l.color, l.style, l.qty, p.unit, l.packages]; })));
}

/* ---------------- dialogs */
function openModal(title, body, foot) {
  $('#spModal').innerHTML = '<div class="sp-back"><section class="sp-modal" role="dialog" aria-modal="true" aria-labelledby="spMT"><div class="row between" style="display:flex;justify-content:space-between;align-items:center"><h2 id="spMT">' + title + '</h2><button type="button" data-a="close" aria-label="Close">×</button></div>'
    + body + '<div id="spMErr" class="err" role="alert"></div>' + (foot ? '<div class="foot">' + foot + '</div>' : '') + '</section></div>';
}
function closeModal() { $('#spModal').innerHTML = ''; }
var qEdit = null;
function quantityModal(c) {
  var d = draft, cur = c || { size: d.selectedSize, color: d.selectedColor, style: d.selectedStyle };
  var ex = d.combos.find(function (x) { return vkey(x.size, x.color, x.style) === vkey(cur.size, cur.color, cur.style); });
  qEdit = cur;
  openModal('Edit quantity', '<p class="small muted">' + esc(vlabel(cur)) + '</p><div style="margin-top:12px"><label for="spQty" style="display:block;font-size:12px;font-weight:650;color:#64797b;margin-bottom:5px">Quantity in this combination</label><input id="spQty" type="text" inputmode="numeric" pattern="[0-9]*" value="' + ((ex && ex.qty) || 0) + '"></div><div style="font-size:12px;color:#64797b;margin-top:7px">Zero removes it. The package holds ' + d.target + '.</div>',
    '<button type="button" data-a="close">Cancel</button><button type="button" class="primary" data-a="qty-apply">Apply</button>');
  setTimeout(function () { var i = $('#spQty'); if (i) { i.focus(); i.select(); } }, 5);
}
function showReport() { openModal('Final packing list', '<div class="row wrap" style="display:flex;gap:7px;margin:6px 0 12px"><button type="button" data-a="print">Print / Save PDF</button><button type="button" data-a="csv">CSV</button></div>' + reportHTML(), ''); $('.sp-modal').style.maxWidth = '1000px'; }
function printReport() { $('#spPrint').innerHTML = reportHTML(); window.print(); }

/* ---------------- one handler for every tap */
function onAction(el) {
  var a = el.dataset.a, d = draft;
  try { switch (a) {
    case 'tab': tab = el.dataset.tab; searchQuery = ''; render(); window.scrollTo(0, 0); break;
    case 'product': {
      var id = el.dataset.id, prev = draft; freshDraft(id);
      if (prev.edit) { draft.edit = prev.edit; draft.start = prev.start; draft.end = prev.start; draft.prefix = prev.prefix; draft.pad = prev.pad; }
      history = []; searchQuery = ''; saveLocal(); render(); toast('Sizes and colours of this line loaded · last packing details restored'); break; }
    case 'entry-mode': d.entryMode = el.dataset.mode; if (d.entryMode === 'range') d.end = d.start; saveLocal(); renderPacking(); break;
    case 'select-attr': snapshot(); d['selected' + el.dataset.type.charAt(0).toUpperCase() + el.dataset.type.slice(1)] = el.dataset.value; saveLocal(); renderPacking(); break;
    case 'count':
      if (suppressTap) { suppressTap = false; break; }
      if (el.dataset.type === 'color') increment(el.dataset.value, d.selectedStyle);
      else if (el.dataset.type === 'style') increment(d.selectedColor, el.dataset.value);
      else increment(d.selectedColor, d.selectedStyle);
      break;
    case 'undo': if (history.length) { draft = history.pop(); saveLocal(); renderPacking(); } break;
    case 'fill': { var cur = d.combos.find(function (c) { return c.size === d.selectedSize && c.color === d.selectedColor && c.style === d.selectedStyle; });
      setCombo(d.selectedSize, d.selectedColor, d.selectedStyle, (cur ? cur.qty : 0) + d.target - sumQty(d.combos)); break; }
    case 'edit-current': quantityModal(); break;
    case 'combo-edit': quantityModal(d.combos[+el.dataset.i]); break;
    case 'combo-plus': case 'combo-minus': { var c = d.combos[+el.dataset.i]; setCombo(c.size, c.color, c.style, c.qty + (a === 'combo-plus' ? 1 : -1)); break; }
    case 'clear': snapshot(); d.combos = []; saveLocal(); renderPacking(); break;
    case 'qty-apply': { var n = Number($('#spQty').value); setCombo(qEdit.size, qEdit.color, qEdit.style, n); closeModal(); break; }
    case 'save': saveDraft(); break;
    case 'cancel-edit': cancelEdit(); break;
    case 'edit': editPackage(findPkg(el.dataset.g, el.dataset.n), false); break;
    case 'copy': editPackage(findPkg(el.dataset.g, el.dataset.n), true); break;
    case 'delete': {
      var pk = findPkg(el.dataset.g, el.dataset.n);
      if (!pk || !window.confirm('Delete ' + pk.type + ' ' + serialText(pk.prefix, pk.num, pk.pad) + ' from this packing list?')) break;
      api({ op: 'delete', group: pk.gid, num: pk.num }).then(function () { render(); toast('Package deleted'); }).catch(function (e) { render(); toast(e.message, true); });
      break; }
    case 'record-mode': recordMode = el.dataset.mode; recordPage = 0; renderRecords(); break;
    case 'view-range': recordFilter = el.dataset.keys.split(','); recordMode = 'individual'; recordPage = 0; renderRecords(); break;
    case 'clear-filter': recordFilter = null; recordPage = 0; renderRecords(); break;
    case 'page': recordPage += +el.dataset.dir; renderRecordList(); break;
    case 'finish':
      if (draft.edit) throw Error('Save or cancel the package being edited first.');
      if (sumQty(draft.combos) > 0 && !window.confirm('The package on the Packing tab has not been saved. Finish with the saved packages only?')) break;
      if (!window.confirm('Finish packing? The packing list rows and the shipment totals are written from the saved packages.')) break;
      api({ op: 'finish' }).then(function (j) { render(); toast(j.msg); }).catch(function (e) { render(); toast(e.message, true); });
      break;
    case 'reopen': api({ op: 'reopen' }).then(function (j) { tab = 'packing'; render(); toast(j.msg); }).catch(function (e) { toast(e.message, true); }); break;
    case 'summary-csv': download(summaryCSV(), 'Packing-Summary-' + STATE.shipment.invoice + '.csv'); break;
    case 'csv': download(packingCSV(), 'Packing-List-' + STATE.shipment.invoice + '.csv'); break;
    case 'close': closeModal(); break;
    case 'report': showReport(); break;
    case 'print': printReport(); break;
  } } catch (e) { if ($('#spMErr')) $('#spMErr').textContent = e.message; else toast(e.message, true); }
}

/* ---------------- product search: typos forgiven */
function fuzzy(q, text) {
  q = q.toLowerCase().replace(/[^a-z0-9]/g, ''); text = String(text).toLowerCase().replace(/[^a-z0-9]/g, '');
  if (!q) return 0; if (text.indexOf(q) >= 0) return 100 + q.length;
  var i = 0; for (var k = 0; k < text.length; k++) if (text[k] === q[i]) i++; if (i === q.length) return 60;
  var words = text.match(/[a-z]+|\d+/g) || [text], best = 99;
  words.forEach(function (w) { if (Math.abs(w.length - q.length) > 2) return; var row = []; for (var x = 0; x <= w.length; x++) row.push(x);
    for (var a = 0; a < q.length; a++) { var nr = [a + 1]; for (var b = 0; b < w.length; b++) nr[b + 1] = Math.min(nr[b] + 1, row[b + 1] + 1, row[b] + (q[a] === w[b] ? 0 : 1)); row = nr; }
    best = Math.min(best, row[w.length]); });
  return best <= Math.max(1, Math.floor(q.length * 0.3)) ? 40 - best : 0;
}
function renderSearch() {
  var box = $('#spResults'); if (!box) return;
  var m = STATE.products.map(function (p) { return { p: p, s: Math.max.apply(null, [p.name, p.line, p.hint].concat(p.name.split(' ')).map(function (t) { return fuzzy(searchQuery, t || ''); })) }; })
    .filter(function (x) { return !searchQuery || x.s > 0; }).sort(function (a, b) { return b.s - a.s; }).map(function (x) { return x.p; });
  box.hidden = false;
  box.innerHTML = m.length ? m.map(function (p) { return '<button type="button" data-a="product" data-id="' + esc(p.id) + '"><b>' + esc(p.name) + '</b><small>' + esc(p.line) + (p.hint ? ' · ' + esc(p.hint) : '') + ' · ' + esc(p.unit) + (p.setUp ? '' : ' · not set up') + '</small></button>'; }).join('') : '<div class="empty">No matching product</div>';
}

/* ---------------- events */
var root = $('#sp');
document.addEventListener('click', function (e) {
  var el = e.target.closest('[data-a]');
  if (el && !el.disabled && (root.contains(el) || $('#spModal').contains(el))) onAction(el);
  else if (!e.target.closest('.search')) { var r = $('#spResults'); if (r) r.hidden = true; }
});
/* hold a counting button to type the quantity instead of tapping */
document.addEventListener('pointerdown', function (e) {
  var el = e.target.closest('#sp [data-a="count"]'); if (!el) return;
  suppressTap = false; clearTimeout(pointerTimer);
  pointerTimer = setTimeout(function () { var d = draft;
    quantityModal({ size: d.selectedSize, color: el.dataset.type === 'color' ? el.dataset.value : d.selectedColor, style: el.dataset.type === 'style' ? el.dataset.value : d.selectedStyle });
    suppressTap = true; }, 550);
});
['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { document.addEventListener(t, function () { clearTimeout(pointerTimer); }); });
document.addEventListener('contextmenu', function (e) { if (e.target.closest('#sp [data-a="count"]')) e.preventDefault(); });
function selectAll(el) { if (!el) return; el.focus(); try { el.select(); } catch (e) {} }
document.addEventListener('focusin', function (e) { if (e.target.id === 'spSearch') renderSearch(); if (e.target.matches && e.target.matches('#sp [data-sel]')) selectAll(e.target); });
document.addEventListener('input', function (e) {
  var el = e.target, d = draft;
  if (el.id === 'spSearch') { searchQuery = el.value; renderSearch(); }
  else if (el.id === 'spRecSearch') { recordQuery = el.value; recordPage = 0; renderRecordList(); }
  else if (el.id === 'spStart' || el.id === 'spEnd') { d[el.id === 'spStart' ? 'start' : 'end'] = el.value; saveLocal(); updatePreview(); }
});
document.addEventListener('change', function (e) {
  var el = e.target, d = draft;
  try { switch (el.id) {
    case 'spTarget': { var n = Number(el.value);
      if (!Number.isInteger(n) || n < 1 || n > 1000000) { el.value = d.target; throw Error('Enter a whole quantity from 1 to 1,000,000.'); }
      if (sumQty(d.combos) > n) { el.value = d.target; throw Error('Take some out of the contents before lowering the quantity.'); }
      snapshot(); d.target = n; saveLocal(); renderPacking(); break; }
    case 'spType': d.packageType = el.value; d.spec = DEFAULT_SPEC[d.packageType] || ''; d.advancedOpen = true;
      if (!d.edit) { d.start = nextSerial(d.prefix, d.pad, d.packageType); d.end = d.start; }
      saveLocal(); renderPacking(); break;
    case 'spSpec': d.spec = el.value.trim() || DEFAULT_SPEC[d.packageType] || ''; saveLocal(); break;
    case 'spPrefix': { var pf = el.value.toUpperCase();
      if (!/^[A-Z_-]{0,16}$/.test(pf)) { el.value = d.prefix; throw Error('Use letters, hyphens or underscores for the prefix.'); }
      d.prefix = pf; if (!d.edit) { d.start = nextSerial(pf, d.pad, d.packageType); d.end = d.start; } saveLocal(); renderPacking(); break; }
    case 'spPad': { var o = parseSerial(d.start, d.prefix, d.pad), en = parseSerial(d.end || d.start, d.prefix, d.pad);
      d.pad = +el.value; d.start = serialText(o.prefix, o.num, d.pad); d.end = serialText(en.prefix, en.num, d.pad); saveLocal(); renderPacking(); break; }
  } } catch (err) { toast(err.message, true); }
});
document.addEventListener('toggle', function (e) {
  if (e.target.id === 'spPkgDetails') { draft.advancedOpen = e.target.open; saveLocal(); }
  if (e.target.id === 'spCompDetails') { draft.compositionOpen = e.target.open; saveLocal(); }
}, true);
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeModal();
  if (e.key === 'Enter' && e.target.id === 'spQty') { onAction({ dataset: { a: 'qty-apply' } }); return; }
  /* Enter walks serial → end → quantity → the first size, as on the demo */
  if (e.key === 'Enter' && e.target.matches && e.target.matches('#sp [data-sel]')) {
    e.preventDefault();
    var next = { spStart: draft.entryMode === 'range' ? 'spEnd' : 'spTarget', spEnd: 'spTarget' }[e.target.id];
    if (next) selectAll($('#' + next));
    else if (e.target.id === 'spTarget') { try { syncEntry(); renderPacking(); } catch (er) { toast(er.message, true); }
      var f = $('#spSizes button') || $('#spColors button') || $('#spStyles button'); if (f) f.focus(); }
  }
});
window.addEventListener('beforeprint', function () { $('#spPrint').innerHTML = reportHTML(); });

/* ---------------- start */
if (!STATE.products.length) { $('#spMain').innerHTML = '<div class="card empty">This invoice has no lines yet. Add them on the desktop first.</div>'; return; }
draft = loadLocal();
if (!draft) freshDraft(STATE.products[0].id);
else if (!draft.edit) { /* a kept draft may be behind what was saved since */
  try { var used = packages().some(function (p) { var r = parseSerial(draft.start, draft.prefix, draft.pad); return p.prefix === r.prefix && p.num === r.num && kindOf(p.type) === kindOf(draft.packageType); });
        if (used) { draft.start = nextSerial(draft.prefix, draft.pad, draft.packageType); draft.end = draft.start; } } catch (e) {}
}
if (STATE.completed) tab = 'summary';
render();
window.ZASPacking = { get state() { return STATE; }, get draft() { return draft; }, packages: packages, nextSerial: nextSerial, render: render };
})();
</script>
<?php
mob_footer();
