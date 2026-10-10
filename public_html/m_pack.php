<?php
/*
  PACKING — the phone version.

  The same shipment, the same login, the same permission as the desktop
  packing list. What is different is the order of the questions, because
  the person holding the phone is standing next to the cartons.

  IT STARTS AT THE SERIAL. "Carton 1 to 100" is the first thing typed and
  the package count follows from it. The desktop asks for the count and
  the serials separately, which lets the two disagree; here they cannot.

  IT DOES NOT CHECK THE INVOICE. What is in the carton is counted, not
  derived. The desktop screen keeps its own over-pack guard for its own
  entry; this screen reports what the team found.

  IT DOES NOT ASK FOR NET AND GROSS. One package goes on the scale and the
  team lists what is inside one unit. The weights are produced from that,
  and the approver may overrule them within ten per cent.

  IT NEEDS SIGNAL, like every page here. The service worker never caches a
  .php page, so nothing on screen can be stale and no save is swallowed.
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
               'pack_weight_missing', 'pack_dedupe'], 'includes/packing.php');
} else {
    http_response_code(500);
    exit('Upload includes/mobile.php — the copy on the server is older than this screen.');
}
require_login();

if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot open the packing list.'); }
exp_ensure_schema();
pack_ensure_schema();

$id  = (int)($_GET['id']  ?? $_POST['shipment_id'] ?? 0);
$tab = (string)($_GET['t'] ?? 'serial');
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

/* THE SIZE FIELD, AND THE RULE BEHIND IT.

   The list is this product's sizes and nothing else — what it has been
   costed in, and what it has actually been packed in before. A product
   nobody has packed yet offers nothing, so the field has to let the
   first one be typed, and what is typed becomes the list from then on.

   Two controls, two names. The select carries the sentinel __new when
   someone wants to type instead, and the server reads the text box in
   that case. Juggling disabled attributes on two fields with one name
   would do the same job and be a great deal easier to get wrong. */
function pack_pick_field(array $opts, string $cur, string $selName, string $newName,
                         string $what = 'size'): string
{
    $known = $opts !== [];
    /* A size already saved that is not in the list — a product renamed,
       or a one-off — must still show as chosen rather than silently
       resetting to nothing. It is MARKED, though: the office's list is
       the rule, and a packer should see when a range is outside it. */
    $off = '';
    if ($cur !== '' && !in_array($cur, $opts, true)) { array_unshift($opts, $cur); $known = true; $off = $cur; }

    $h = '<select class="in" name="' . e($selName) . '" data-pick>';
    $h .= '<option value="">' . ($known ? '— pick a ' . e($what) . ' —' : '— none on record yet —') . '</option>';
    foreach ($opts as $o) {
        $h .= '<option value="' . e($o) . '"' . ($o === $cur ? ' selected' : '') . '>' . e($o)
            . ($o === $off ? ' (not on this line’s list)' : '') . '</option>';
    }
    $h .= '<option value="__new"' . (!$known ? ' selected' : '') . '>+ type a ' . e($what) . ' not in the list</option>';
    $h .= '</select>';
    $h .= '<input class="in" name="' . e($newName) . '" data-picknew placeholder="type the ' . e($what) . '"'
        . ($known ? ' hidden' : '') . ' style="margin-top:8px">';
    return $h;
}

/* EVERY RANGE ON ONE LIST, with Edit and Delete.

   Shown on the New range step (where someone is when they wonder what is
   already done) and on Approve ("final approval: allow edit, delete,
   update, overall, easily"). Each row says its invoice line, quantity and
   whether the line has its weight — weight lives on its own tab and was
   otherwise invisible. On the serial tab Edit jumps to the range's step;
   on Approve it opens the serial tab at that range. Approve also gets an
   Edit weight per line. */
function pack_summary_html(array $groups, array $items, bool $canEdit, int $id, string $self,
                           bool $onSerial): string
{
    if (!$groups) return '';
    ob_start();
    $byId = [];
    foreach ($items as $it) $byId[(int)$it['id']] = $it;
    $sumP = 0; $sumQ = 0.0;
    echo '<div class="mcard"><h2 style="margin:0 0 4px">Already packed</h2>'
       . '<div class="note" style="margin-bottom:10px">Every range on this invoice. '
       . 'Tap Edit to change one.</div>';
    foreach ($groups as $gi => $og) {
        $oP = pack_packages($og); $sumP += $oP; $sumQ += (float)$og['total_qty'];
        $oSizes = pack_sizes((int)$og['id']);
        $it = $byId[(int)$og['invoice_item_id']] ?? null;
        /* a clash already in the data — saved before the rule was
           tight, or by the desktop — is shown, not hidden */
        $clashW = '';
        foreach ($groups as $og2) {
            if ((int)$og2['id'] === (int)$og['id']) continue;
            /* the same number is fine on a different kind of package */
            if (mb_strtolower(trim((string)$og2['unit_title'])) !== mb_strtolower(trim((string)$og['unit_title']))) continue;
            if ((int)$og2['serial_from'] <= (int)$og['serial_to']
                && (int)$og2['serial_to'] >= (int)$og['serial_from']) {
                $clashW = $og2['unit_title'] . ' ' . (int)$og2['serial_from'] . '–' . (int)$og2['serial_to'];
                break;
            }
        }
        $gross = (float)$og['pkg_gross'];
        echo '<div class="sumr' . ($clashW !== '' ? ' bad' : '') . '">'
           . '<div class="sumt"><b>' . e((string)$og['unit_title']) . ' '
           . (int)$og['serial_from'] . '–' . (int)$og['serial_to'] . '</b>'
           . '<span class="pill v">' . number_format($oP) . '</span></div>'
           . '<div class="sumi">' . ($it ? pack_item_html($it) : e((string)$og['product_name'])) . '</div>'
           . '<div class="sumq">' . number_format((float)$og['total_qty']) . ' in total'
           . ($oSizes ? ' · ' . e(pack_size_text($og, $oSizes)) : '') . '</div>'
           . '<div class="sumw">' . ($gross > 0
                ? 'Weighed: ' . number_format($gross, 3) . ' kg gross per ' . e(strtolower((string)$og['unit_title']))
                : '<a href="' . e($self) . '&amp;t=weight&amp;g=' . (int)$og['id'] . '">Not weighed yet — Weight tab</a>')
           . '</div>'
           . ($clashW !== '' ? '<div class="clash" style="margin:8px 0 0">Shares package numbers with '
                              . e($clashW) . '. A number can be used once per kind of package — change one of them.</div>' : '');
        if ($canEdit) {
            echo '<div class="suma">'
           . ($onSerial
               ? '<button type="button" class="btn sec sm" data-mstep-go="' . (int)$gi . '">Edit</button>'
               : '<a class="btn sec sm" href="' . e($self) . '&amp;t=serial#s' . (int)$gi . '">Edit</a>'
                 . '<a class="btn sec sm" href="' . e($self) . '&amp;t=weight&amp;g=' . (int)$og['id'] . '">Edit weight</a>')
               . '<form method="post" onsubmit="return confirm(\'Remove ' . e((string)$og['unit_title']) . ' '
               . (int)$og['serial_from'] . '–' . (int)$og['serial_to'] . ' and its weights?\')">'
               . csrf_field()
               . '<input type="hidden" name="action" value="group_delete">'
               . '<input type="hidden" name="shipment_id" value="' . (int)$id . '">'
               . '<input type="hidden" name="group_id" value="' . (int)$og['id'] . '">'
               . '<button class="btn red sm" type="submit">Delete</button></form></div>';
        }
        echo '</div>';
    }
    echo '<div class="sumtot"><span>' . count($groups) . ' range' . (count($groups) === 1 ? '' : 's')
       . '</span><b>' . number_format($sumP) . ' packages · ' . number_format($sumQ) . ' pcs</b></div></div>';
    return (string)ob_get_clean();
}

/* ONE SCROLLING PAGE, NOT FLIPS. "If you feel any problem because of
   the page flip, use scroll." On this screen the flip meant pressing
   Next past every range to reach the new one, every time. */
mob_steps_scroll(true);

$title = 'Packing — ' . (string)$shipment['invoice_no'];
$back  = 'm_pack.php';
$self  = 'm_pack.php?id=' . $id;

/* =============================================================== actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('This packing list can no longer be changed.'); }
    $action = (string)($_POST['action'] ?? '');

    /* ---- one serial range, with its sizes ---- */
    if ($action === 'group') {
        $g = (int)($_POST['group_id'] ?? 0);
        $itemId = (int)($_POST['invoice_item_id'] ?? 0);
        /* A REFUSED NEW RANGE KEEPS WHAT WAS TYPED. A duplicate number or
           a missing line sent the page back empty, and everything had to
           be typed again. The figures ride back in the session and the
           New range card is filled from them, once. */
        $keepDraft = function () use ($g): void {
            if ($g > 0) return;
            $_SESSION['pack_draft'] = [
                'unit'   => (string)($_POST['unit_title'] ?? ''),
                'from'   => (int)($_POST['serial_from'] ?? 0),
                'to'     => (int)($_POST['serial_to'] ?? 0),
                'item'   => (int)($_POST['invoice_item_id'] ?? 0),
                'mode'   => (string)($_POST['qty_mode'] ?? 'per'),
                'size'   => (string)($_POST['single_size'] ?? ''),
                'colour' => (string)($_POST['single_colour'] ?? ''),
                'qty'    => (float)($_POST['single_qty'] ?? 0),
            ];
        };
        $item = null;
        foreach ($items as $it) if ((int)$it['id'] === $itemId) $item = $it;
        if (!$item) {
            $_SESSION['error'] = 'Pick the item this range holds.';
            $keepDraft();
            redirect($self . '&t=serial#newrange');
        }

        /* Two screens say this, in their own words: the range card sends
           size_mode because it is a live toggle, the assorted setup page
           sends assorted because that is all it is for. Either counts.
           Turning assorted off sends neither, which is the point. */
        $assorted = ($_POST['size_mode'] ?? '') === 'mix' || !empty($_POST['assorted']);
        $sizes = [];
        if ($assorted) {
            $lbl = (array)($_POST['size_label'] ?? []);
            $new = (array)($_POST['size_new'] ?? []);
            $col = (array)($_POST['colour_label'] ?? []);
            $qty = (array)($_POST['size_qty'] ?? []);
            foreach ($lbl as $i => $l) {
                $l = (string)$l;
                if ($l === '__new') $l = trim((string)($new[$i] ?? ''));
                $sizes[] = ['size_label'   => $l,
                            'colour_label' => trim((string)($col[$i] ?? '')),
                            'qty'          => (float)($qty[$i] ?? 0)];
            }
        } else {
            /* __new means "the one I typed", not a size called __new. */
            $one = (string)($_POST['single_size'] ?? '');
            if ($one === '__new') $one = trim((string)($_POST['single_size_new'] ?? ''));
            $sizes[] = ['size_label'   => $one,
                        'colour_label' => trim((string)($_POST['single_colour'] ?? '')),
                        'qty'          => (float)($_POST['single_qty'] ?? 0)];
        }

        [$ok, $msg] = pack_group_save($id, [
            /* only the assorted page asks it; null means "leave the line's
               answer as it is" rather than "off" */
            'weight_by_colour' => isset($_POST['weight_by_colour']) ? !empty($_POST['weight_by_colour']) : null,
            'invoice_item_id' => $itemId,
            'product_name'    => (string)$item['product_name'],
            'des_col'         => (string)($item['des_col'] ?? ''),
            'optional_value'  => (string)($item['optional_value'] ?? ''),
            'unit_title'      => (string)($_POST['unit_title'] ?? 'Carton'),
            'serial_from'     => (int)($_POST['serial_from'] ?? 0),
            'serial_to'       => (int)($_POST['serial_to'] ?? 0),
            'qty_mode'        => (string)($_POST['qty_mode'] ?? 'per'),
            'assorted'        => $assorted,
        ], $sizes, $g);

        /* STRAIGHT ON TO THE NEXT RANGE. It used to come back to the top,
           so reaching the New range card meant paging past every range
           already done. Now it lands on it, already starting at the next
           free number for that kind of package. */
        if ($ok) {
            $_SESSION['flash'] = $msg . ($g > 0 ? '' : ' The next range is ready below.');
        } else {
            $_SESSION['error'] = $msg;
            $keepDraft();
        }
        redirect($self . '&t=serial#newrange');
    }

    if ($action === 'group_delete') {
        pack_group_delete($id, (int)($_POST['group_id'] ?? 0));
        $_SESSION['flash'] = 'Range removed.';
        redirect($self);
    }

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

    if ($action === 'approve') {
        [$ok, $msg] = pack_approve($id, (float)($_POST['final_net'] ?? 0),
                                        (float)($_POST['final_gross'] ?? 0));
        if ($ok) $_SESSION['flash'] = $msg; else $_SESSION['error'] = $msg;
        redirect($self . '&t=approve');
    }
}

/* ================================================================ screens */
$groups = pack_groups($id);

/* ---------------------------------------- the separate assorted setup page */
if ($tab === 'mix' && $gid > 0) {
    $g = pack_group($gid);
    if (!$g || (int)$g['shipment_id'] !== $id) { http_response_code(404); exit('Range not found.'); }
    $sizes = pack_sizes($gid);
    $P     = pack_packages($g);
    $per   = $g['qty_mode'] === 'per';

    /* The order's own sizes and colours. If the office has not set the
       order up, fall back to the product's list rather than offering an
       empty screen — the packer is never stuck. */
    $pal  = pack_palette($id, (int)$g['invoice_item_id']);
    $pSz  = $pal['size']   ?: pack_size_options((string)$g['product_name']);
    $pCol = $pal['colour'];

    /* What one package should come to, so the tapping has a target.
       Per package: the figure already on the range. Direct: the whole
       total, because in that mode the mix IS the total. */
    $target = $per ? (float)$g['qty_per_pkg'] : (float)$g['total_qty'];

    /* One word for it, and it is the user's own: "assorted". A screen
       that says "Mixed" here and "Assorted" elsewhere is two things to
       learn for one idea. */
    mob_header('Assorted — ' . $g['unit_title'] . ' '
               . (int)$g['serial_from'] . '–' . (int)$g['serial_to'],
               $self, (string)$g['product_name'], 'manifest_pack.json');
    mob_flash();
    ?>
    <form method="post" id="mixform">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="group_id" value="<?= $gid ?>">
      <input type="hidden" name="invoice_item_id" value="<?= (int)$g['invoice_item_id'] ?>">
      <input type="hidden" name="unit_title" value="<?= e((string)$g['unit_title']) ?>">
      <input type="hidden" name="serial_from" value="<?= (int)$g['serial_from'] ?>">
      <input type="hidden" name="serial_to" value="<?= (int)$g['serial_to'] ?>">
      <input type="hidden" name="qty_mode" value="<?= e((string)$g['qty_mode']) ?>">
      <input type="hidden" name="size_mode" value="mix">
      <input type="hidden" name="weight_by_colour" id="wbc" value="<?= (int)!empty($g['weight_by_colour']) ?>">
      <?php /* The tapping builds these, one hidden row per combination. */ ?>
      <div id="rows"></div>

      <div class="mcard">
        <h2><?= $per ? 'What one ' . e(strtolower((string)$g['unit_title'])) . ' holds'
                     : 'What this range holds in total' ?></h2>
        <div class="note" style="margin-bottom:13px">
          <?= $pCol ? 'Tap a colour, then tap a size once for every piece.'
                    : 'Tap a size once for every piece.' ?>
        </div>

        <?php if ($pCol): ?>
          <span class="flab">Colour</span>
          <div class="pad" id="colPad"></div>
        <?php endif; ?>

        <span class="flab" style="margin-top:13px">Size</span>
        <div class="pad" id="szPad"></div>

        <div class="meter" id="meter">
          <div class="big"><span id="mLeft">—</span><b id="mCount">—</b></div>
          <div class="bar"><i id="mBar" style="width:0%"></i></div>
          <div class="msg" id="mMsg"></div>
        </div>

        <div id="tally"></div>
      </div>

      <?php if ($pCol): ?>
        <div class="mcard">
          <h2>Do these colours weigh differently?</h2>
          <div class="note" style="margin-bottom:12px">Usually not — the same cloth, a different
            dye. Leave it off and one breakdown per size serves every colour of it.</div>
          <div class="seg" id="wbcSeg">
            <button type="button" data-v="0">Same for every colour</button>
            <button type="button" data-v="1">Each colour differs</button>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($canEdit): ?>
        <button class="btn go" type="submit">Save this range</button>
      <?php endif; ?>
    </form>

    <?php if ($canEdit): ?>
    <form method="post" style="margin-top:10px"
          onsubmit="return confirm('Back to one size and colour? The mix is dropped.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="group_id" value="<?= $gid ?>">
      <input type="hidden" name="invoice_item_id" value="<?= (int)$g['invoice_item_id'] ?>">
      <input type="hidden" name="unit_title" value="<?= e((string)$g['unit_title']) ?>">
      <input type="hidden" name="serial_from" value="<?= (int)$g['serial_from'] ?>">
      <input type="hidden" name="serial_to" value="<?= (int)$g['serial_to'] ?>">
      <input type="hidden" name="qty_mode" value="<?= e((string)$g['qty_mode']) ?>">
      <input type="hidden" name="size_mode" value="one">
      <input type="hidden" name="single_size" value="<?= e((string)($sizes[0]['size_label'] ?? '')) ?>">
      <input type="hidden" name="single_colour" value="<?= e((string)($sizes[0]['colour_label'] ?? '')) ?>">
      <input type="hidden" name="single_qty"
             value="<?= e((string)($per ? ($sizes[0]['qty_per_pkg'] ?? 0) : ($sizes[0]['total_qty'] ?? 0))) ?>">
      <button class="btn red" type="submit">One size and colour instead</button>
    </form>
    <?php endif; ?>

    <style>
      .pad{display:flex;gap:8px;flex-wrap:wrap}
      .pad button{border:2px solid var(--line);background:#fff;color:var(--ink);border-radius:14px;
        padding:0 16px;height:58px;font-size:16px;font-weight:800;cursor:pointer;
        display:inline-flex;align-items:center;gap:8px;position:relative;min-width:88px;
        justify-content:center}
      .pad button.on{background:var(--navy);color:#fff;border-color:var(--navy)}
      .pad button:active{transform:scale(.96)}
      .pad .cnt{position:absolute;top:-8px;right:-8px;min-width:24px;height:24px;border-radius:12px;
        background:var(--cyan);color:#fff;font-size:12.5px;font-weight:800;
        display:grid;place-items:center;padding:0 6px;border:2px solid var(--bg)}
      .sw{width:17px;height:17px;border-radius:50%;border:1px solid rgba(0,0,0,.22);flex:0 0 17px}
      .meter{border-radius:14px;padding:14px;margin-top:15px;border:2px solid var(--line);background:#f6f8fb}
      .meter .big{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
      .meter .big span{font-size:13px;font-weight:800;color:var(--muted)}
      .meter .big b{font-size:30px;font-variant-numeric:tabular-nums;letter-spacing:-.8px}
      .bar{height:9px;border-radius:5px;background:#d7dfea;margin-top:10px;overflow:hidden}
      .bar i{display:block;height:100%;background:var(--cyan);transition:width .18s}
      .meter.done{border-color:var(--good);background:rgba(22,163,74,.09)}
      .meter.done .bar i{background:var(--good)}
      .meter.over{border-color:var(--bad);background:rgba(184,40,63,.08)}
      .meter.over .bar i{background:var(--bad)}
      .meter .msg{font-size:14px;font-weight:800;margin-top:9px}
      .c-good{color:var(--good)}.c-warn{color:var(--muted)}.c-bad{color:var(--bad)}
      .tl{display:grid;grid-template-columns:1fr auto auto auto;gap:10px;align-items:center;
        padding:10px 0;border-top:1px solid var(--line)}
      .tl .who{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;min-width:0}
      .tl .n{font-size:18px;font-weight:800;min-width:28px;text-align:center;font-variant-numeric:tabular-nums}
      .tl button{width:44px;height:44px;border-radius:11px;border:2px solid var(--line);background:#fff;
        color:var(--ink);font-size:22px;font-weight:800;cursor:pointer;line-height:1}
      .seg{display:flex;border:2px solid #cbd5e3;border-radius:12px;overflow:hidden}
      .seg button{flex:1;border:0;background:transparent;color:var(--muted);font-size:14px;
        font-weight:800;padding:14px 8px;cursor:pointer;min-height:54px}
      .seg button.on{background:var(--cyan);color:#fff}
    </style>
    <script>
    (function () {
      /* ONE TAP IS ONE PIECE. No keyboard, no cursor, no decimal point —
         the thing being counted is whole pieces going into a carton, and
         a thumb is the right instrument for it. */
      var SIZES   = <?= json_encode(array_values($pSz)) ?>;
      var COLOURS = <?= json_encode(array_values($pCol)) ?>;
      var SWATCH  = <?= json_encode(array_combine(
                          array_values($pCol),
                          array_map('pack_colour_swatch', array_values($pCol))) ?: new stdClass()) ?>;
      var TARGET  = <?= json_encode(round($target, 3)) ?>;
      var UNIT    = <?= json_encode(strtolower((string)$g['unit_title'])) ?>;
      var PER     = <?= $per ? 'true' : 'false' ?>;
      var MIX     = <?= json_encode(array_map(static function (array $r) use ($per) {
                        return ['s' => (string)$r['size_label'],
                                'c' => (string)($r['colour_label'] ?? ''),
                                'q' => (float)($per ? $r['qty_per_pkg'] : $r['total_qty'])];
                      }, $sizes)) ?>;
      var picked = COLOURS.length ? COLOURS[0] : '';

      var $ = function (i) { return document.getElementById(i); };
      function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
      function num(n) { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
      function hex(c) { return SWATCH[c] || '#cbd5e3'; }
      function total() { return MIX.reduce(function (a, m) { return a + (+m.q || 0); }, 0); }
      function at(s, c) { for (var i = 0; i < MIX.length; i++)
                            if (MIX[i].s === s && MIX[i].c === c) return i; return -1; }
      function bump(s, c, by) {
        var i = at(s, c);
        if (i < 0) { if (by < 0) return; MIX.push({ s: s, c: c, q: 0 }); i = MIX.length - 1; }
        MIX[i].q += by;
        if (MIX[i].q <= 0) MIX.splice(i, 1);
        draw();
      }

      function draw() {
        if (COLOURS.length) {
          $('colPad').innerHTML = COLOURS.map(function (c) {
            var n = MIX.filter(function (m) { return m.c === c; })
                       .reduce(function (a, m) { return a + m.q; }, 0);
            return '<button type="button" data-c="' + esc(c) + '" class="' + (c === picked ? 'on' : '') + '">'
              + '<i class="sw" style="background:' + hex(c) + '"></i>' + esc(c)
              + (n ? '<i class="cnt">' + num(n) + '</i>' : '') + '</button>';
          }).join('');
          $('colPad').querySelectorAll('button').forEach(function (b) {
            b.onclick = function () { picked = b.dataset.c; draw(); };
          });
        }

        $('szPad').innerHTML = SIZES.map(function (s) {
          var i = at(s, picked), n = i < 0 ? 0 : MIX[i].q;
          return '<button type="button" data-s="' + esc(s) + '">' + esc(s)
            + (n ? '<i class="cnt">' + num(n) + '</i>' : '') + '</button>';
        }).join('') || '<span class="note">No size set for this order yet.</span>';
        $('szPad').querySelectorAll('button').forEach(function (b) {
          b.onclick = function () { bump(b.dataset.s, picked, 1); };
        });

        /* The carton must come out exact. It says how far off it is at
           every tap rather than letting a short carton be saved. */
        var have = total(), m = $('meter');
        m.className = 'meter' + (TARGET > 0 && have === TARGET ? ' done'
                               : (TARGET > 0 && have > TARGET ? ' over' : ''));
        $('mLeft').textContent  = PER ? 'In this ' + UNIT : 'Across this range';
        $('mCount').textContent = TARGET > 0 ? num(have) + ' / ' + num(TARGET) : num(have);
        $('mBar').style.width = (TARGET > 0 ? Math.min(100, have / TARGET * 100) : 0) + '%';
        $('mMsg').className = 'msg ' + (TARGET <= 0 ? 'c-warn'
                            : (have === TARGET ? 'c-good' : (have > TARGET ? 'c-bad' : 'c-warn')));
        $('mMsg').textContent = TARGET <= 0
          ? 'Set the quantity on the range first, so this has something to come to.'
          : (have === TARGET ? 'Complete.'
            : (have > TARGET ? num(have - TARGET) + ' too many — take some out.'
                             : num(TARGET - have) + ' still to place.'));

        $('tally').innerHTML = MIX.length ? MIX.map(function (mm, i) {
          return '<div class="tl" data-i="' + i + '">'
            + '<span class="who">'
            + (mm.c ? '<i class="sw" style="background:' + hex(mm.c) + '"></i>' + esc(mm.c) + ' ' : '')
            + esc(mm.s) + '</span>'
            + '<button type="button" class="minus">−</button>'
            + '<span class="n">' + num(mm.q) + '</span>'
            + '<button type="button" class="plus">+</button></div>';
        }).join('') : '<p class="note" style="margin-top:12px">Nothing in it yet.</p>';
        $('tally').querySelectorAll('.tl').forEach(function (d) {
          var mm = MIX[+d.dataset.i];
          d.querySelector('.minus').onclick = function () { bump(mm.s, mm.c, -1); };
          d.querySelector('.plus').onclick  = function () { bump(mm.s, mm.c, 1); };
        });

        /* The hidden rows the server reads. Rebuilt from the tally every
           time, so what is posted is always what is on screen. */
        $('rows').innerHTML = MIX.map(function (mm) {
          return '<input type="hidden" name="size_label[]" value="' + esc(mm.s) + '">'
            + '<input type="hidden" name="colour_label[]" value="' + esc(mm.c) + '">'
            + '<input type="hidden" name="size_qty[]" value="' + mm.q + '">';
        }).join('');
      }

      var seg = document.getElementById('wbcSeg');
      if (seg) {
        var paintSeg = function () {
          seg.querySelectorAll('button').forEach(function (b) {
            b.classList.toggle('on', b.dataset.v === $('wbc').value);
          });
        };
        seg.querySelectorAll('button').forEach(function (b) {
          b.onclick = function () { $('wbc').value = b.dataset.v; paintSeg(); };
        });
        paintSeg();
      }

      draw();
    })();
    </script>
    <?php
    mob_footer();
    exit;
}

/* ------------------------------------------------------------ the tab bar */
mob_header($title, $back, (string)$shipment['buyer_name'], 'manifest_pack.json');
mob_flash();
if (!$canEdit) {
    echo '<div class="flash no">This packing list is closed to you — it is completed or the shipment is locked.</div>';
}
?>
<div class="ptabs">
  <a class="ptab<?= $tab === 'serial'  ? ' on' : '' ?>" href="<?= e($self) ?>&t=serial">Serial &amp; qty</a>
  <a class="ptab<?= $tab === 'weight'  ? ' on' : '' ?>" href="<?= e($self) ?>&t=weight">Weight</a>
  <a class="ptab<?= $tab === 'approve' ? ' on' : '' ?>" href="<?= e($self) ?>&t=approve">Approve</a>
</div>
<style>
.ptabs{display:flex;gap:7px;margin-bottom:14px}
.ptab{flex:1;padding:11px 4px;border-radius:11px;border:1px solid #cbd5e3;background:#fff;
  color:var(--muted);font-weight:700;font-size:13px;text-align:center;text-decoration:none}
.ptab.on{background:var(--navy);color:#fff;border-color:var(--navy)}
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

/* ================================================== tab 1 — serial and qty */
if ($tab === 'serial') {

    /* Every invoice line's list, once. 'set' means the office has set it
       up on the desktop; then those lists are the whole choice. A line
       never set up gets the product's own sizes and no colours, flagged
       so the card can say it is a fallback. */
    $palByItem = [];
    foreach ($items as $it) {
        $pp = pack_palette($id, (int)$it['id']);
        $set = $pp['size'] !== [] || $pp['colour'] !== [];
        $palByItem[(int)$it['id']] = [
            'size'   => $set ? $pp['size'] : pack_size_options((string)$it['product_name']),
            'colour' => $set ? $pp['colour'] : [],
            'set'    => $set,
        ];
    }

    /* Draws one range, new or existing. Kept as a function because the
       "add another" card at the bottom is the same form with nothing in it. */
    /* WHAT THE NEW RANGE STARTS FROM, so nothing is asked twice: the
       kind of package, the invoice line and the quantity mode of the
       range saved last (the next range is usually more of the same — the
       short carton after 1–99), and the next free number FOR THAT KIND.
       A refused save's own figures win over all of it. */
    $lastG = null;
    foreach ($groups as $og) if (!$lastG || (int)$og['id'] > (int)$lastG['id']) $lastG = $og;
    $draft = $_SESSION['pack_draft'] ?? null;
    unset($_SESSION['pack_draft']);
    $nd = [
        'unit' => trim((string)($draft['unit'] ?? ($lastG['unit_title'] ?? 'Carton'))) ?: 'Carton',
        'item' => (int)($draft['item'] ?? ($lastG['invoice_item_id'] ?? 0)),
        'mode' => ((string)($draft['mode'] ?? ($lastG['qty_mode'] ?? 'per'))) === 'direct' ? 'direct' : 'per',
        'from' => (int)($draft['from'] ?? 0),
        'to'   => (int)($draft['to'] ?? 0),
        'single' => $draft && ($draft['size'] !== '' || $draft['qty'] > 0)
            ? ['size_label' => $draft['size'], 'colour_label' => $draft['colour'],
               'qty_per_pkg' => $draft['qty'], 'total_qty' => $draft['qty']] : null,
    ];
    if ($nd['from'] <= 0) {
        $top = 0;
        foreach ($groups as $og) {
            if (mb_strtolower(trim((string)$og['unit_title'])) === mb_strtolower($nd['unit'])) {
                $top = max($top, (int)$og['serial_to']);
            }
        }
        $nd['from'] = $top + 1; $nd['to'] = $top + 1;
    }

    $card = function (?array $g) use ($items, $id, $canEdit, $self, $groups, $palByItem, $nd) {
        $new   = $g === null;
        $gidL  = $new ? 0 : (int)$g['id'];
        $unit  = $new ? $nd['unit'] : (string)$g['unit_title'];
        $from  = $new ? $nd['from'] : (int)$g['serial_from'];
        $to    = $new ? $nd['to'] : (int)$g['serial_to'];
        $mode  = $new ? $nd['mode'] : (string)$g['qty_mode'];
        $asrt  = $new ? false : !empty($g['assorted']);
        $P     = $new ? 0 : pack_packages($g);
        $sizes = $new ? [] : pack_sizes($gidL);
        $selItem = $new ? $nd['item'] : (int)$g['invoice_item_id'];
        $item  = null;
        foreach ($items as $it) if ($selItem > 0 && (int)$it['id'] === $selItem) $item = $it;
        if (!$item) $selItem = 0;

        /* THE ORDER'S PALETTE, NOT THE PRODUCT'S WHOLE HISTORY.
           The office set these from the customer's email; this screen
           shows exactly them. Only when the order has not been set up
           does it fall back to the product's own list, so a packer is
           never stuck in front of an empty screen. */
        /* EXACTLY WHAT THE DESKTOP TICKED FOR THIS LINE — nothing else.
           If the line has been set up on Order sizes & colours, that list
           is the whole choice, sizes and colours alike. Only a line that
           has never been set up falls back to the product's own sizes, and
           the card says so. The map covers every line, so the lists follow
           the dropdown live — see palByItem in the script below. */
        $itemId  = $selItem;
        $lp      = $palByItem[$itemId] ?? ['size' => [], 'colour' => [], 'set' => false];
        $fromPal = $lp['set'];
        $opts    = $lp['size'];
        $cols    = $lp['colour'];
        $single = $new ? $nd['single'] : ($sizes[0] ?? null);
        $singleQty = $single ? ($mode === 'per' ? (float)$single['qty_per_pkg'] : (float)$single['total_qty']) : 0;
        $num = function (float $v): string {
            return $v > 0 ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : '';
        };
        /* The saved mix, added up, so the assorted total can be shown
           without opening it: per package, and over the whole range. */
        $mixPer = 0.0; $mixTot = 0.0;
        foreach ($sizes as $srow) { $mixPer += (float)$srow['qty_per_pkg']; $mixTot += (float)$srow['total_qty']; }
        /* Every OTHER range's serials, so a clash is said while typing,
           not after Save. The server still checks — this only says it
           sooner. */
        $taken = [];
        foreach ($groups as $og) {
            if (!$new && (int)$og['id'] === $gidL) continue;
            $taken[] = [(int)$og['serial_from'], (int)$og['serial_to'],
                        (string)$og['unit_title']];
        }
        ?>
        <?php /* autocomplete="off": on a reload the browser puts back the
                 last thing touched in each box — after the page has drawn,
                 and without telling it. The live server showed a range
                 saved as "#2 Thermal Blanket" with "Bath Towel" in its
                 dropdown and "Direct" lit beside a label that still said
                 per carton; pressing Save would have quietly moved the
                 range to the wrong line. Off, the boxes show what is
                 saved and nothing else. */ ?>
        <form method="post" class="mcard" autocomplete="off"
              data-mixper="<?= e((string)round($mixPer, 4)) ?>"
              data-mixtot="<?= e((string)round($mixTot, 4)) ?>"
              data-hasmix="<?= $asrt && $sizes ? 1 : 0 ?>"
              data-taken="<?= e(json_encode($taken)) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="group">
          <input type="hidden" name="shipment_id" value="<?= $id ?>">
          <input type="hidden" name="group_id" value="<?= $gidL ?>">
          <input type="hidden" name="assorted" value="<?= $asrt ? 1 : 0 ?>">

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h2 style="margin:0"><?= $new ? 'Add the next range' : 'Range ' . (int)$g['line_no'] ?></h2>
            <?php if (!$new): ?><span class="pill v"><?= number_format($P) ?> <?= e(strtolower($unit)) ?></span><?php endif; ?>
          </div>

          <?php /* The box above the dropdown says which invoice line this
                   is, and it FOLLOWS the dropdown: change the line and the
                   box changes with it, so the two can never disagree on
                   screen the way they did on the live server. */ ?>
          <div class="ref" data-ref<?= $item ? '' : ' hidden' ?>><?php if ($item): $il = pack_item_label($item); ?>
            <b><?= e($il['name']) ?></b> <i class="ihint"><?= e($il['no']) ?></i><br>
            <?= e((string)($item['des_col'] ?? '')) ?>
            <?= ($item['optional_value'] ?? '') !== '' ? ' · ' . e((string)$item['optional_value']) : '' ?>
          <?php endif; ?></div>

          <label class="f"><span>Item — from the invoice</span>
            <?php /* The line number and what the line says, in every option.
                     Four lines called "Bath Towel" were four identical
                     choices; now they read #1 Bath Towel — Royal blue,
                     #3 Bath Towel — White, and so on. */ ?>
            <select class="in" name="invoice_item_id" required data-itempick>
              <option value="">— pick the item —</option>
              <?php foreach ($items as $it): $il = pack_item_label($it); ?>
                <option value="<?= (int)$it['id'] ?>"
                  data-name="<?= e($il['name']) ?>" data-no="<?= e($il['no']) ?>"
                  data-des="<?= e((string)($it['des_col'] ?? '')) ?>"
                  data-opt="<?= e((string)($it['optional_value'] ?? '')) ?>"
                  <?= ((int)$it['id'] === $selItem) ? ' selected' : '' ?>>
                  <?= e($il['text']) ?></option>
              <?php endforeach; ?>
            </select></label>

          <span class="flab" style="display:block;font-size:11.5px;text-transform:uppercase;
                letter-spacing:.06em;color:var(--faint);font-weight:700;margin-bottom:5px">
            Serial — this is where packing starts</span>
          <div class="row3">
            <input class="in" name="unit_title" value="<?= e($unit) ?>" aria-label="Unit" required>
            <input class="in" type="number" inputmode="numeric" name="serial_from"
                   value="<?= $from ?: '' ?>" placeholder="from" aria-label="From" required>
            <input class="in" type="number" inputmode="numeric" name="serial_to"
                   value="<?= $to ?: '' ?>" placeholder="to" aria-label="To" required>
          </div>
          <div class="derv" data-serial><span>—</span><b>—</b></div>
          <?php /* Said while typing. The server refuses it anyway; this is
                   so nobody fills in the rest of the card first. */ ?>
          <div class="clash" data-clash hidden></div>

          <?php /* BOTH TOGGLES ARE LIVE.
                   The fields and their labels change the moment one is
                   tapped — nothing is decided on the server and nothing
                   waits for a save. The radios carry the choice to the
                   server as they always did; the script below only
                   decides what is on screen. With no script at all every
                   field is still present and still posts, so the form
                   degrades to the plain version rather than to nothing. */ ?>
          <span class="flab">Quantity</span>
          <div class="seg">
            <label><input type="radio" name="qty_mode" value="per"<?= $mode === 'per' ? ' checked' : '' ?>><span>Per package</span></label>
            <label><input type="radio" name="qty_mode" value="direct"<?= $mode === 'direct' ? ' checked' : '' ?>><span>Direct qty</span></label>
          </div>

          <span class="flab">Sizes in one package</span>
          <div class="seg">
            <label><input type="radio" name="size_mode" value="one"<?= $asrt ? '' : ' checked' ?>
                   <?= $new ? '' : '' ?>><span>One size</span></label>
            <label><input type="radio" name="size_mode" value="mix"<?= $asrt ? ' checked' : '' ?>
                   <?= $new ? ' disabled' : '' ?>><span>Assorted</span></label>
          </div>

          <?php /* One input, two meanings — which is exactly why the label
                   has to change with the toggle rather than after it. The
                   figure is converted when the mode flips, so 10 a carton
                   over 100 cartons becomes 1,000 and back again. */ ?>
          <label class="f" data-one>
            <span data-qtylabel>Quantity per package</span>
            <input class="in" type="number" inputmode="decimal" step="any" name="single_qty"
                   value="<?= e($num($singleQty)) ?>" data-qty></label>
          <div class="note" data-palnote style="margin:-2px 0 10px"><?php
            if ($itemId === 0) echo 'Pick the invoice line first — its sizes and colours follow.';
            elseif ($fromPal) echo e(count($opts) . ' size' . (count($opts) === 1 ? '' : 's') . ' and '
                                   . count($cols) . ' colour' . (count($cols) === 1 ? '' : 's')
                                   . ' — exactly what the office set for this line.');
            else echo 'This line is not set up on Order sizes &amp; colours yet, so these are the product&rsquo;s own sizes.';
          ?></div>
          <label class="f" data-one><span>Size</span>
            <?= pack_pick_field($opts, (string)($single['size_label'] ?? ''),
                                'single_size', 'single_size_new', 'size') ?></label>
          <?php /* Always drawn, hidden when the line has no colours, so the
                   script can fill it when the dropdown moves to a line that
                   does. A colour already saved but no longer on the list is
                   kept and marked, rather than silently dropped. */
                $curCol = (string)($single['colour_label'] ?? '');
                $colOpts = $cols;
                if ($curCol !== '' && !in_array($curCol, $colOpts, true)) $colOpts[] = $curCol; ?>
          <label class="f" data-one data-colbox<?= $colOpts ? '' : ' hidden' ?>><span>Colour</span>
            <select class="in" name="single_colour">
              <option value="">— no colour —</option>
              <?php foreach ($colOpts as $c): ?>
                <option value="<?= e($c) ?>"<?= $c === $curCol ? ' selected' : '' ?>><?= e($c)
                  . (in_array($c, $cols, true) ? '' : ' (not on this line’s list)') ?></option>
              <?php endforeach; ?>
            </select></label>

          <div data-mix hidden>
            <div class="derv"><span data-mixhead>Assorted</span>
              <b><?= e($new ? '' : (pack_size_text($g, $sizes) ?: 'not set up yet')) ?></b></div>
            <?php if (!$new): ?>
              <a class="lnk" href="<?= e($self) ?>&t=mix&g=<?= $gidL ?>">
                <span><b>Tap in what one <?= e(strtolower($unit)) ?> holds</b>
                  <small>a colour, then a size, once per piece</small></span>
                <span class="chev">&rsaquo;</span></a>
            <?php endif; ?>
          </div>
          <?php if ($new): ?>
            <div class="note" style="margin-bottom:11px" data-newnote>
              Add the range with one size first. Assorted can be set up the moment it exists.</div>
          <?php endif; ?>

          <div class="derv" data-total><span>—</span><b>—</b></div>

          <?php if ($canEdit): ?>
            <button class="btn go" type="submit"><?= $new ? 'Add this range' : 'Save this range' ?></button>
          <?php endif; ?>
        </form>

        <?php if (!$new && $canEdit): ?>
          <form method="post" style="margin:-4px 0 14px"
                onsubmit="return confirm('Remove <?= e($unit) ?> <?= $from ?>–<?= $to ?> and its weights?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="group_delete">
            <input type="hidden" name="shipment_id" value="<?= $id ?>">
            <input type="hidden" name="group_id" value="<?= $gidL ?>">
            <button class="btn red" type="submit">Remove this range</button>
          </form>
        <?php endif; ?>
        <?php
    };

    if (!$items) {
        echo '<div class="empty">This invoice has no items yet. Add them on the desktop first.</div>';
    } else {
        /* SAY THE SCOPE BEFORE ANYTHING IS TAPPED. The packer should
           know the whole world of this order in one line, rather than
           discovering it a dropdown at a time. */
        /* NOT THE WHOLE ORDER'S SIZES ADDED TOGETHER. That used to read
           "16 sizes and 6 colours" when every line on the desktop had one
           or two — the union of all of them, which no single carton is
           ever packed from. Each card now says its own line's list; up
           here it only says how many lines have been set up. */
        $nSet = 0; $unset = [];
        foreach ($items as $it) {
            if (!empty($palByItem[(int)$it['id']]['set'])) $nSet++;
            else $unset[] = pack_item_label($it)['no'];
        }
        $nAll = count($items);
        echo '<div class="scope">';
        if ($nSet === $nAll) {
            echo '<b>Sizes &amp; colours set on all ' . $nAll . ' lines</b>'
               . '<span>Each range offers only what the office ticked for its own line.</span>';
        } else {
            echo '<b>Sizes &amp; colours set on ' . $nSet . ' of ' . $nAll . ' lines</b>'
               . '<span>Not set yet: ' . e(implode(', ', $unset))
               . '. Those offer the product&rsquo;s own sizes until the office sets them '
               . 'on Order sizes &amp; colours.</span>';
        }
        echo '</div>';
        /* One range to a screen. Each carries its own form and its own
           Save, so a range is finished before the next one is looked at
           — which is also how the cartons are actually packed. */
        /* ONE BROKEN RANGE MUST NOT BLANK THE SCREEN.
           A card that throws halfway leaves an open <form> and an open
           step behind it. The steps are hidden until the script at the
           very end reveals one, so the whole rest of the page — every
           other range, the New range card, Back and Next — vanished
           with it, and the error box printed at shutdown landed inside
           a hidden step where nobody could see it. That is exactly the
           blank screen that came back from the live server.

           So each card is drawn into a buffer. If it throws, the half-
           drawn card is thrown away, a plain card says this range could
           not be drawn (with the reason, for an admin), and everything
           else on the page carries on. */
        $safeCard = function (?array $g) use ($card): void {
            $lvl = ob_get_level();
            ob_start();
            try {
                $card($g);
                ob_end_flush();
            } catch (Throwable $e) {
                while (ob_get_level() > $lvl) ob_end_clean();
                mob_card_error($g === null ? 'The new range form' : 'This range', $e);
            }
        };
        mob_steps_begin('serialsteps');
        foreach ($groups as $g) {
            mob_step((string)$g['unit_title'] . ' ' . (int)$g['serial_from'] . '–' . (int)$g['serial_to']);
            $safeCard($g);
        }
        if ($canEdit) {
            mob_step('New range', 'Starts at the next free number for its kind of package');
            echo '<span id="newrange" class="anchor"></span>';
            $safeCard(null);
            echo '<div class="mcard"><div class="note">Packing starts from the serial. '
               . '1 to 100 means 100 cartons — the count is never typed. When some packages '
               . 'differ, add another range; that is all a different serial is.</div></div>';
        }

        /* EVERY RANGE ON ONE LIST, with Edit and Delete.
           Asked for so a wrong entry can be found and put right without
           paging through one range at a time. It sits on the New range
           step because that is where someone is when they wonder what is
           already done. Edit jumps to that range's own step; Delete asks
           first. Each row also says whether the range has been weighed,
           because weight lives on its own tab and was otherwise invisible
           from here. */
        if ($groups) {
            if (!$canEdit) mob_step('All ranges');
            echo pack_summary_html($groups, $items, $canEdit, $id, $self, true);
        }
        mob_steps_end();
        ?>
        <script>
        /* "+ type a size not in the list" shows the box. One handler on
           the document, so it covers the range cards, the assorted
           setup and any row added later without wiring each one. */
        document.addEventListener('change', function (ev) {
          var sel = ev.target;
          if (!sel.matches || !sel.matches('[data-pick]')) return;
          var box = sel.parentElement.querySelector('[data-picknew]');
          if (!box) return;
          box.hidden = sel.value !== '__new';
          if (!box.hidden) box.focus();
        });
        </script>
        <script>
        /* EVERY RANGE CARD, LIVE.
           Tapping a toggle changes the label and what is on screen at
           once. Typing a serial re-counts the packages at once. Nothing
           here waits for a save, and nothing here decides what is sent:
           the radios and inputs are the same ones the server already
           read, so a phone with the script blocked still posts a
           complete, correct form. */
        (function () {
          /* Every line's list, keyed by invoice line — what the office
             ticked, or the product's own sizes for a line not set up. */
          var PAL = <?= json_encode((object)array_map(static fn($v) => [
                        'size' => array_values($v['size']), 'colour' => array_values($v['colour']),
                        'set' => (bool)$v['set']], $palByItem), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
          document.querySelectorAll('form.mcard').forEach(function (card) {
            var unitI = card.querySelector('[name="unit_title"]');
            var fromI = card.querySelector('[name="serial_from"]');
            var toI   = card.querySelector('[name="serial_to"]');
            var qtyI  = card.querySelector('[data-qty]');
            if (!unitI || !fromI || !toI || !qtyI) return;   /* not a range card */

            var modeR  = card.querySelectorAll('[name="qty_mode"]');
            var sizeR  = card.querySelectorAll('[name="size_mode"]');
            var label  = card.querySelector('[data-qtylabel]');
            var serial = card.querySelector('[data-serial]');
            var total  = card.querySelector('[data-total]');
            var mixBox = card.querySelector('[data-mix]');
            var mixHd  = card.querySelector('[data-mixhead]');
            var note   = card.querySelector('[data-newnote]');
            var ones   = card.querySelectorAll('[data-one]');
            var pill   = card.querySelector('.pill');
            var clash  = card.querySelector('[data-clash]');
            var pick   = card.querySelector('[data-itempick]');
            var ref    = card.querySelector('[data-ref]');
            var save   = card.querySelector('button[type="submit"]');
            var taken  = []; try { taken = JSON.parse(card.dataset.taken || '[]'); } catch (e) {}
            var mixPer = +card.dataset.mixper || 0, mixTot = +card.dataset.mixtot || 0;
            var hasMix = card.dataset.hasmix === '1';
            var sizeSel = card.querySelector('select[name="single_size"]');
            var colSel  = card.querySelector('select[name="single_colour"]');
            var colBox  = card.querySelector('[data-colbox]');
            var palNote = card.querySelector('[data-palnote]');
            var lastItem = pick ? pick.value : '';
            var was    = mode();

            function mode() {
              for (var i = 0; i < modeR.length; i++) if (modeR[i].checked) return modeR[i].value;
              return 'per';
            }
            function assorted() {
              for (var i = 0; i < sizeR.length; i++) if (sizeR[i].checked) return sizeR[i].value === 'mix';
              return false;
            }
            function unit()  { return (unitI.value.trim() || 'Carton'); }
            function pkgs()  { var n = (+toI.value || 0) - (+fromI.value || 0) + 1; return n > 0 ? n : 0; }
            function num(n)  { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }

            function paint() {
              var u = unit(), lu = u.toLowerCase(), P = pkgs(), per = mode() === 'per', mix = assorted();

              if (pill) pill.textContent = num(P) + ' ' + lu;
              serial.firstElementChild.textContent = P
                ? u + ' ' + fromI.value + ' to ' + toI.value
                : 'Serial is not right yet';
              serial.lastElementChild.textContent = P ? num(P) + ' ' + lu : 'to must be ≥ from';

              /* the label IS the difference between the two modes */
              label.textContent = per ? ('Quantity per ' + lu) : 'Total quantity';

              ones.forEach(function (el) { el.hidden = mix; });
              /* the colour field stays hidden on a line with no colours */
              if (colBox && colSel && colSel.options.length < 2) colBox.hidden = true;
              if (mixBox) mixBox.hidden = !mix;
              if (note)   note.hidden = !mix;
              if (mixHd)  mixHd.textContent = per ? ('Assorted, per ' + lu) : 'Assorted, total';

              var q = +qtyI.value || 0;
              if (mix && !hasMix) {
                total.firstElementChild.textContent = 'Set the sizes to see the total';
                total.lastElementChild.textContent  = '—';
              } else if (mix) {
                /* THE SAVED MIX, ADDED UP. It used to say "set the sizes"
                   here even when they were set, because it never looked.
                   Per package: what one holds, times the packages.
                   Direct: the mix IS the total. */
                total.firstElementChild.textContent = per
                  ? num(mixPer) + ' per ' + lu + ' × ' + num(P)
                  : 'Assorted, over ' + num(P) + ' ' + lu;
                total.lastElementChild.textContent = num(per ? mixPer * P : mixTot);
              } else {
                var t = per ? q * P : q;
                total.firstElementChild.textContent = per
                  ? num(q) + ' per ' + lu + ' × ' + num(P)
                  : num(q) + ' over ' + num(P) + ' ' + lu;
                total.lastElementChild.textContent = num(t);
              }

              /* A PACKAGE NUMBER ONCE PER KIND OF PACKAGE. Carton 1–50
                 twice is refused; Carton 1–50 beside Roll 1–50 is fine. */
              var f = +fromI.value || 0, t2 = +toI.value || 0, hit = null;
              var myKind = unit().toLowerCase();
              if (f > 0 && t2 >= f) {
                for (var k = 0; k < taken.length; k++) {
                  if (String(taken[k][2] || 'Carton').trim().toLowerCase() !== myKind) continue;
                  if (taken[k][0] <= t2 && taken[k][1] >= f) { hit = taken[k]; break; }
                }
              }
              if (clash) {
                clash.hidden = !hit;
                clash.textContent = hit ? ('Already used: ' + hit[2] + ' ' + hit[0] + '–' + hit[1]
                  + '. A number can be used once per kind of package — a different kind, like a Roll, may reuse it.') : '';
              }
              if (save) save.disabled = !!hit;

              if (pick && ref) {
                var o = pick.options[pick.selectedIndex];
                if (o && o.value) {
                  ref.hidden = false;
                  ref.innerHTML = '<b>' + esc(o.dataset.name) + '</b> <i class="ihint">'
                    + esc(o.dataset.no) + '</i><br>' + esc(o.dataset.des)
                    + (o.dataset.opt ? ' · ' + esc(o.dataset.opt) : '');
                } else { ref.hidden = true; }
              }
            }
            function esc(t) {
              return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; });
            }

            /* Flipping the mode converts the figure rather than leaving a
               per-carton number sitting in a box that now means a total. */
            function flipped() {
              var now = mode(), P = pkgs(), q = +qtyI.value || 0;
              if (now !== was && q > 0 && P > 0) {
                qtyI.value = now === 'direct'
                  ? String(Math.round(q * P * 1000) / 1000)
                  : String(Math.round(q / P * 1000) / 1000);
              }
              was = now;
              paint();
            }

            modeR.forEach(function (r) { r.addEventListener('change', flipped); });
            sizeR.forEach(function (r) { r.addEventListener('change', paint); });
            [unitI, fromI, toI, qtyI].forEach(function (el) {
              el.addEventListener('input', paint);
            });
            /* MOVE THE LINE, MOVE THE LISTS. The sizes and colours on offer
               are that line's own, so picking a different line refills
               both from it — they used to stay on whatever line the range
               was saved with, and a New range card offered nothing at all. */
            function refill() {
              if (!pick || pick.value === lastItem) return;
              lastItem = pick.value;
              var lp = PAL[pick.value] || { size: [], colour: [], set: false };
              if (sizeSel) {
                var keepS = sizeSel.value;
                var first = sizeSel.options[0], last = sizeSel.options[sizeSel.options.length - 1];
                while (sizeSel.options.length) sizeSel.remove(0);
                first.textContent = lp.size.length ? '— pick a size —' : '— none on record yet —';
                sizeSel.add(first);
                lp.size.forEach(function (v) { sizeSel.add(new Option(v, v)); });
                if (last && last.value === '__new') sizeSel.add(last);
                sizeSel.value = lp.size.indexOf(keepS) >= 0 ? keepS : '';
                var box = sizeSel.parentElement.querySelector('[data-picknew]');
                if (box) box.hidden = sizeSel.value !== '__new';
              }
              if (colSel) {
                var keepC = colSel.value;
                while (colSel.options.length > 1) colSel.remove(1);
                lp.colour.forEach(function (v) { colSel.add(new Option(v, v)); });
                colSel.value = lp.colour.indexOf(keepC) >= 0 ? keepC : '';
                if (colBox) colBox.hidden = !lp.colour.length || assorted();
              }
              if (palNote) {
                palNote.textContent = !pick.value ? 'Pick the invoice line first — its sizes and colours follow.'
                  : lp.set ? (lp.size.length + ' size' + (lp.size.length === 1 ? '' : 's') + ' and '
                              + lp.colour.length + ' colour' + (lp.colour.length === 1 ? '' : 's')
                              + ' — exactly what the office set for this line.')
                  : 'This line is not set up on Order sizes & colours yet, so these are the product’s own sizes.';
              }
            }
            if (pick) pick.addEventListener('change', function () { refill(); paint(); });

            /* THE NEXT FREE NUMBER, PER KIND. On the New range card, change
               Carton to Roll and the serial moves to the next free Roll
               number — unless someone has typed a serial themselves, which
               always wins. */
            var gidI = card.querySelector('[name="group_id"]');
            var isNew = gidI && !(+gidI.value);
            var typedSerial = false;
            [fromI, toI].forEach(function (el) {
              el.addEventListener('input', function () { typedSerial = true; });
            });
            if (isNew) unitI.addEventListener('input', function () {
              if (typedSerial) return;
              var kind = unit().toLowerCase(), top = 0;
              taken.forEach(function (t) {
                if (String(t[2] || 'Carton').trim().toLowerCase() === kind) top = Math.max(top, +t[1] || 0);
              });
              fromI.value = top + 1; toI.value = top + 1;
              paint();
            });
            /* If a browser restores old values anyway (some ignore
               autocomplete="off" on a back-navigation), redraw from what
               the boxes actually hold, so the labels never describe a
               choice that is not on screen. */
            window.addEventListener('pageshow', paint);
            paint();
          });
        })();
        </script>
        <?php
    }
}

/* ========================================================= tab 2 — weight */
if ($tab === 'weight') {
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
}

/* ======================================================== tab 3 — approve */
if ($tab === 'approve') {
    $lvlT = ob_get_level(); ob_start();
    try {
    $t = pack_totals($id);
    /* EDIT, DELETE, UPDATE — FROM THE APPROVAL ITSELF. The same list as
       the New range step, above the approve form rather than inside it:
       each Delete is a form of its own, and a form inside a form is not
       allowed. Folded away with one tap once it has been checked. */
    if ($groups) {
        echo '<details class="allr" open><summary>All ranges (' . count($groups)
           . ') — edit, delete or change a weight</summary>'
           . pack_summary_html($groups, $items, $canEdit, $id, $self, false) . '</details>';
    }
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <?php
    /* The form wraps the steps so the final figures post from whichever
       step they were typed on. */
    mob_steps_begin('approvesteps');
    mob_step('What saves', 'Size by size, against its serial range');
    ?>
    <div class="mcard">
      <div style="overflow-x:auto"><table class="bk">
        <tr><th>Serial</th><th>What is in it</th><th class="n">Per pkg</th><th class="n">Qty</th><th class="n">g / unit</th></tr>
        <?php if (!$groups): ?>
          <tr><td colspan="5" style="color:var(--faint)">Nothing yet</td></tr>
        <?php else: foreach ($groups as $g):
          $sizes = pack_sizes((int)$g['id']);
          $pu    = pack_per_unit((int)$g['id']);
          $first = true;
          foreach (($sizes ?: [['size_label' => '—', 'qty_per_pkg' => 0, 'total_qty' => 0]]) as $srow): ?>
            <tr>
              <td><?= $first ? e(mb_substr((string)$g['unit_title'], 0, 3)) . ' ' . (int)$g['serial_from']
                               . '–' . (int)$g['serial_to'] : '' ?></td>
              <td><?= e(trim(((string)($srow['colour_label'] ?? '')) . ' '
                              . (string)$srow['size_label'])) ?></td>
              <td class="n"><?= rtrim(rtrim(number_format(pack_size_per_pkg($g, $srow), 2, '.', ''), '0'), '.') ?></td>
              <td class="n"><?= number_format(pack_size_per_pkg($g, $srow) * pack_packages($g)) ?></td>
              <?php /* The same key the weight was filed under, which is
                       the size alone or the size and the colour — reading
                       it by size alone showed 0 g for every colour of a
                       range whose colours weigh differently. */ ?>
              <td class="n"><?= number_format((float)($pu[pack_unit_key($g, $srow)] ?? 0)) ?></td>
            </tr>
          <?php $first = false; endforeach; endforeach; endif; ?>
      </table></div>
    </div>

    <?php mob_step('Calculated', 'What the packing team\'s figures produce'); ?>
    <div class="mcard">
      <div class="sumrow"><span>Total packages</span><b><?= number_format($t['packages']) ?></b></div>
      <div class="sumrow"><span>Total quantity</span><b><?= number_format($t['qty'], 2) ?></b></div>
      <div class="sumrow"><span>Net weight</span><b><?= number_format($t['net'], 3) ?> kg</b></div>
      <div class="sumrow"><span>Gross weight</span><b><?= number_format($t['gross'], 3) ?> kg</b></div>
    </div>

      <?php mob_step('Final figures', 'You may change these — up to '
                     . (int)PACK_TOLERANCE_PCT . '% difference is accepted'); ?>
      <div class="mcard">
        <div class="row2">
          <label class="f"><span>Net kg</span>
            <input class="in" type="number" inputmode="decimal" step="0.001" name="final_net" id="fn"
                   value="<?= number_format($t['net'], 3, '.', '') ?>"></label>
          <label class="f"><span>Gross kg</span>
            <input class="in" type="number" inputmode="decimal" step="0.001" name="final_gross" id="fg"
                   value="<?= number_format($t['gross'], 3, '.', '') ?>"></label>
        </div>
        <div id="dev"></div>
      </div>

      <?php mob_step('Before you approve'); ?>
      <div class="mcard">
        <?php if ($t['unfinished']): foreach ($t['unfinished'] as $u): ?>
          <div class="sumrow"><span><?= e($u) ?></span><b><span class="pill w">accepted</span></b></div>
        <?php endforeach; else: ?>
          <div class="sumrow"><span>Every package balances.</span><b><span class="pill p">ok</span></b></div>
        <?php endif; ?>
        <div class="note" style="margin-top:8px">Quantity is what the packing team counted.
          It is not compared with the invoice.</div>
      </div>

      <?php if ($canEdit): ?>
        <button class="btn go" type="submit">Approve and save the packing list</button>
      <?php endif; ?>

    <?php mob_steps_end(); ?>
    </form>

    <script>
    (function () {
      var NET = <?= json_encode(round($t['net'], 3)) ?>, GROSS = <?= json_encode(round($t['gross'], 3)) ?>;
      var LIM = <?= (int)PACK_TOLERANCE_PCT ?>;
      function one(label, calc, got) {
        if (calc <= 0) return '';
        var p = Math.abs(got - calc) / calc * 100;
        return '<div class="sumrow"><span>' + label + '</span><b><span class="pill '
          + (p <= LIM ? 'p' : 'w') + '">'
          + (p < 0.05 ? 'same as calculated'
                      : p.toFixed(1) + '% from calculated' + (p <= LIM ? ' — accepted' : ' — beyond ' + LIM + '%'))
          + '</span></b></div>';
      }
      function paint() {
        document.getElementById('dev').innerHTML =
            one('Net', NET, parseFloat(document.getElementById('fn').value) || 0)
          + one('Gross', GROSS, parseFloat(document.getElementById('fg').value) || 0);
      }
      ['fn', 'fg'].forEach(function (i) { document.getElementById(i).addEventListener('input', paint); });
      paint();
    })();
    </script>
    <?php
    ob_end_flush();
    } catch (Throwable $e) {
        while (ob_get_level() > $lvlT) ob_end_clean();
        mob_card_error('The approval summary', $e);
    }
}

mob_footer();
