<?php
/* Gate Inward & Outward.

   One screen for both directions because every field is identical — only
   the transaction type list and the sign of the movement differ. The
   register is therefore one searchable list rather than two.

   Draft and verified change nothing. Only Post writes stock, through
   inv_gate_post() in includes/inventory.php — the single place in the
   module where the ledger is written. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view gate passes.'); }

$canEdit = inv_perm('gate');
$canPost = inv_perm('post');
$canRev  = inv_perm('adjust');

/* Contract lines with their live balance, for the "pull from contract"
   picker. Read-only. */
if (($_GET['ajax'] ?? '') === 'contract') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['contract_id'] ?? 0);
    $c = null;
    try { $s = db()->prepare("SELECT contract_no,contract_type,currency,gst_applicable,gst_pct FROM inv_contracts WHERE id=?");
          $s->execute([$cid]); $c = $s->fetch() ?: null; } catch (Throwable $e) {}
    echo json_encode([
        'ok' => (bool)$c,
        'contract' => $c,
        'lines' => inv_contract_lines($cid),
        'unassigned' => inv_contract_unassigned($cid),
        'gst_default' => inv_gst_default(),
    ]);
    exit;
}

/* Create a party without leaving the pass. Same function the Parties
   screen uses, so a party made here is not a lesser kind of party. */
if (($_POST['ajax'] ?? '') === 'newparty') {
    header('Content-Type: application/json');
    if (!inv_perm('gate') && !inv_perm('master')) { echo json_encode(['ok' => false, 'error' => 'Not permitted.']); exit; }
    echo json_encode(inv_party_create((string)($_POST['name'] ?? ''), (string)($_POST['party_type'] ?? 'supplier')));
    exit;
}

/* Everything the item picker and the lot picker need for ONE party, in
   one request: what that party is holding, lot by lot. Used by the two
   transaction types where the goods are already sitting with someone
   else — job work return, and handing a customer's goods back. */
if (($_GET['ajax'] ?? '') === 'held') {
    header('Content-Type: application/json');
    $pid  = (int)($_GET['party_id'] ?? 0);
    $kind = ($_GET['kind'] ?? 'jobworker') === 'custody' ? 'custody' : 'jobworker';
    $mid  = (int)($_GET['material_id'] ?? 0);
    $H = inv_holdings($kind);
    $one = $H[$pid] ?? ['items' => [], 'qty' => 0.0];
    echo json_encode([
        'ok' => true,
        'items' => $one['items'],
        'total' => $one['qty'] ?? 0.0,
        'lots' => $mid ? inv_holding_lots($pid, $mid, $kind) : [],
    ]);
    exit;
}

/* Every open contract line belonging to ONE party, with its live
   balance. This is the list behind the Contract box on each item line.

   Party-scoped on purpose, and it is worth saying why rather than
   leaving it as a filter someone later "improves" away: a picker that
   offers every contract in the company against one supplier is a wrong
   booking waiting to happen, and a wrong booking here is not a typo you
   notice — it silently moves quantity off somebody else's contract
   balance. The party is chosen before the items are, so scoping costs
   nothing and removes the whole class of mistake.

   Read-only. */
if (($_GET['ajax'] ?? '') === 'plines') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['party_id'] ?? 0);
    echo json_encode(['ok' => true, 'lines' => inv_party_contract_lines($pid)]);
    exit;
}

/* What is on hand, for the outward form. Read-only. */
if (($_GET['ajax'] ?? '') === 'onhand') {
    header('Content-Type: application/json');
    $mid = (int)($_GET['material_id'] ?? 0);
    $loc = (int)($_GET['location_id'] ?? 0);
    $own = ($_GET['own'] ?? 'own') === 'customer' ? 'customer' : 'own';
    $lots = [];
    foreach (inv_lot_balances($mid, true) as $l) {
        if ($l['ownership'] !== $own) continue;
        if ($loc > 0 && $l['location_id'] !== $loc) continue;
        $lots[] = $l;
    }
    echo json_encode([
        'ok' => true,
        'available' => inv_available_at($mid, '', $loc, $own),
        'lots' => $lots,
        'tolerance_pct' => inv_neg_tolerance_pct(),
        'is_admin' => is_admin(),
        'location' => inv_location_name($loc),
    ]);
    exit;
}

$dir  = ($_GET['dir'] ?? $_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
$TYPES = inv_gate_types($dir);

/* ------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        if (!$canEdit) { http_response_code(403); exit('You do not have permission to create gate passes.'); }
        $id   = (int)($_POST['id'] ?? 0);
        $type = array_key_exists($_POST['txn_type'] ?? '', $TYPES) ? $_POST['txn_type'] : array_key_first($TYPES);
        $date = ($_POST['gate_date'] ?? '') !== '' ? $_POST['gate_date'] : date('Y-m-d');

        // Same shape of guard the production module already uses on dates.
        if ($date > date('Y-m-d')) { $_SESSION['error'] = 'A gate pass cannot be dated in the future.'; redirect('inv_gate.php?dir=' . $dir); }
        $limit = (int)inv_setting('backdate_days', '7');
        if ($limit > 0 && !is_admin() && $date < date('Y-m-d', strtotime("-$limit days"))) {
            $_SESSION['error'] = "This date is more than $limit days back. Ask an admin to enter it.";
            redirect('inv_gate.php?dir=' . $dir);
        }

        $no = strtoupper(trim((string)($_POST['gate_no'] ?? '')));
        if ($no === '' && $id > 0) {
            try { $s = db()->prepare("SELECT gate_no FROM inv_gate WHERE id=?"); $s->execute([$id]); $no = (string)$s->fetchColumn(); } catch (Throwable $e) {}
        }
        if ($no === '') $no = inv_next_no($dir === 'in' ? 'prefix_gate_in' : 'prefix_gate_out', 'inv_gate', 'gate_no');

        $f = [
            'gate_no' => $no, 'direction' => $dir, 'txn_type' => $type,
            'gate_date' => $date,
            'gate_time' => ($_POST['gate_time'] ?? '') !== '' ? $_POST['gate_time'] : date('H:i:s'),
            'party_id'    => (int)($_POST['party_id'] ?? 0) ?: null,
            'party_text'  => trim((string)($_POST['party_text'] ?? '')) ?: null,
            'contract_id' => (int)($_POST['contract_id'] ?? 0) ?: null,
            'proforma_id' => (int)($_POST['proforma_id'] ?? 0) ?: null,
            'location_id' => (int)($_POST['location_id'] ?? 0) ?: null,
            'vehicle_no'  => trim((string)($_POST['vehicle_no'] ?? '')) ?: null,
            'challan_no'  => trim((string)($_POST['challan_no'] ?? '')) ?: null,
            'purpose'     => trim((string)($_POST['purpose'] ?? '')) ?: null,
            'department'  => trim((string)($_POST['department'] ?? '')) ?: null,
            'verified_by' => trim((string)($_POST['verified_by'] ?? '')) ?: null,
            'security_by' => trim((string)($_POST['security_by'] ?? '')) ?: null,
            'remarks'     => trim((string)($_POST['remarks'] ?? '')) ?: null,
            'override_reason' => trim((string)($_POST['override_reason'] ?? '')) ?: null,
            'status'      => in_array($_POST['status'] ?? '', ['draft','verified'], true) ? $_POST['status'] : 'draft',
            'gst_applicable' => !empty($_POST['gst_applicable']) ? 1 : 0,
            'gst_pct'     => !empty($_POST['gst_applicable']) ? inv_num($_POST['gst_pct'] ?? inv_gst_default()) : null,
        ];

        /* A pass marked VERIFIED says a second person has checked it. It
           cannot say that about a document with nothing on it. Draft is
           deliberately left free — a draft is a work in progress and
           saving an empty one is how you start.

           A line only counts if it names an item AND carries a quantity
           above zero, which is the same rule the insert loop below uses,
           so the count on screen matches what is actually stored. */
        if (($f['status'] ?? 'draft') === 'verified') {
            $real = 0;
            foreach ((array)($_POST['line'] ?? []) as $ln) {
                if (inv_num($ln['qty'] ?? 0) > 0
                    && ((int)($ln['material_id'] ?? 0) > 0 || (int)($ln['product_id'] ?? 0) > 0)) $real++;
            }
            if ($real === 0) {
                $_SESSION['error'] = 'Add at least one item before marking this pass Verified. Save it as a Draft instead — a draft may be empty.';
                redirect('inv_gate.php?dir=' . $dir . ($id > 0 ? '&id=' . $id . '&edit=1' : '&new=1'));
            }
        }

        try {
            $chk = null;
            if ($id > 0) {
                $s = db()->prepare("SELECT status FROM inv_gate WHERE id=?"); $s->execute([$id]); $chk = $s->fetchColumn();
                if ($chk === 'posted' || $chk === 'reversed') {
                    $_SESSION['error'] = 'A posted pass cannot be edited. Reverse it and raise a new one.';
                    redirect('inv_gate.php?id=' . $id);
                }
            }
            db()->beginTransaction();
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_gate SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',prepared_by,created_by';
                $ph = implode(',', array_fill(0, count($f) + 2, '?'));
                $uid = (int)(current_user()['id'] ?? 0);
                $vals = array_values($f); $vals[] = $uid; $vals[] = $uid;
                db()->prepare("INSERT INTO inv_gate ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            db()->prepare("DELETE FROM inv_gate_items WHERE gate_id=?")->execute([$id]);
            /* contract_id is the line's OWN contract. It is written only
               when the line names one; a line left blank stores NULL, and
               every balance query in includes/inventory.php then falls
               back to the contract on the gate header. That fallback is
               what keeps every pass ever saved before today reading
               exactly as it did — those rows are all NULL. */
            $ins = db()->prepare("INSERT INTO inv_gate_items
                (gate_id,material_id,product_id,description,article,lot_no,qty,uom,rate,packing,ownership,sort_order,contract_id,contract_item_id,amount)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $own = $TYPES[$type]['own'];
            $n = 0;
            foreach ((array)($_POST['line'] ?? []) as $ln) {
                $qty = inv_num($ln['qty'] ?? 0);
                $mid = (int)($ln['material_id'] ?? 0);
                $pid = (int)($ln['product_id'] ?? 0);
                if ($qty <= 0 || ($mid <= 0 && $pid <= 0)) continue;
                $rate = inv_num($ln['rate'] ?? 0);
                /* A contract line without its contract, or a contract
                   without its line, would be half a link. The pair is
                   kept together or dropped together. */
                $cLine = (int)($ln['contract_item_id'] ?? 0) ?: null;
                $cHead = (int)($ln['contract_id'] ?? 0) ?: null;
                if ($cLine && !$cHead) $cHead = inv_contract_of_line($cLine);
                if (!$cHead) $cLine = null;
                $ins->execute([$id, $mid ?: null, $pid ?: null,
                    trim((string)($ln['description'] ?? '')) ?: null,
                    trim((string)($ln['article'] ?? '')) ?: null,
                    trim((string)($ln['lot_no'] ?? '')) ?: null,
                    $qty, trim((string)($ln['uom'] ?? '')) ?: null,
                    $rate,
                    trim((string)($ln['packing'] ?? '')) ?: null,
                    $own, $n++,
                    $cHead, $cLine,
                    round($qty * $rate, 2)]);
            }
            db()->commit();
            inv_audit('gate_save', $id, ['no' => $no, 'type' => $type, 'lines' => $n], 'Gate pass saved');
            $_SESSION['flash'] = $n > 0
                ? 'Gate pass ' . $no . ' saved with ' . $n . ' line(s). Nothing has moved in stock yet — press "Post to stock" below to update the ledger.'
                : 'Gate pass ' . $no . ' saved, but with NO lines. A line is only kept if it has both an item picked from the list and a quantity above 0. Press Edit and add one — it cannot be posted until then.';
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not save the gate pass.';
        }
        redirect('inv_gate.php?id=' . $id);
    }

    if ($act === 'post') {
        if (!$canPost) { http_response_code(403); exit('You do not have permission to post to stock.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_gate_post($id);
        if ($r['ok']) $_SESSION['flash'] = 'Posted. Stock has been updated.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_gate.php?id=' . $id);
    }

    if ($act === 'reverse') {
        if (!$canRev) { http_response_code(403); exit('You do not have permission to reverse a posted document.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_gate_reverse($id, (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Reversed. An opposite set of stock entries has been written.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_gate.php?id=' . $id);
    }

    /* Delete. The rules live in inv_gate_delete() so the register and the
       document view cannot drift apart — this only checks permission and
       decides where to send you afterwards. */
    if ($act === 'delete') {
        if (!$canEdit) { http_response_code(403); exit('You do not have permission to delete gate passes.'); }
        $id  = (int)($_POST['id'] ?? 0);
        $dir2 = ($_POST['direction'] ?? $dir) === 'out' ? 'out' : 'in';
        $r = inv_gate_delete($id, (string)($_POST['reason'] ?? ''));
        if ($r['ok']) {
            $_SESSION['flash'] = 'Deleted ' . $r['gate_no'] . '.'
                . ($r['ledger_removed'] ? ' Its ' . $r['ledger_removed'] . ' stock row(s) were removed with it.' : '');
            redirect('inv_gate.php?dir=' . $dir2);
        }
        $_SESSION['error'] = $r['error'];
        redirect('inv_gate.php?id=' . $id);
    }
}

/* --------------------------------------------------------- open a doc */
$doc = null; $lines = [];
if (!empty($_GET['id'])) {
    try {
        $s = db()->prepare("SELECT g.*, p.name party_name, c.contract_no, c.contract_type, pf.pi_no
            FROM inv_gate g
            LEFT JOIN inv_parties p ON p.id=g.party_id
            LEFT JOIN inv_contracts c ON c.id=g.contract_id
            LEFT JOIN proforma_invoices pf ON pf.id=g.proforma_id
            WHERE g.id=?");
        $s->execute([(int)$_GET['id']]);
        $doc = $s->fetch() ?: null;
        if ($doc) {
            $dir = $doc['direction']; $TYPES = inv_gate_types($dir);
            /* lcid is the LINE'S OWN contract and nothing else. A line
               pulled in by the older "Pull lines from contract" button
               stored only contract_item_id, and that pointer names its
               contract just as definitely, so it counts — but the gate
               HEADER's contract deliberately does not. A line with no
               contract of its own is following the header, and the form
               must show that as following, not as chosen: if the header
               were copied into the line's hidden field, the next Save
               would freeze a fallback into a stored link and the line
               would stop following a header that is later changed. */
            $s2 = db()->prepare("SELECT gi.*, m.code mcode, m.name mname, pr.name pname,
                    COALESCE(gi.contract_id, cit.contract_id) lcid,
                    cc.contract_no lcno, cc.contract_type lctype, cit.qty cqty
                FROM inv_gate_items gi
                LEFT JOIN inv_materials m ON m.id=gi.material_id
                LEFT JOIN products pr ON pr.id=gi.product_id
                LEFT JOIN inv_contract_items cit ON cit.id = gi.contract_item_id
                LEFT JOIN inv_contracts cc
                       ON cc.id = COALESCE(gi.contract_id, cit.contract_id)
                WHERE gi.gate_id=? ORDER BY gi.sort_order, gi.id");
            $s2->execute([(int)$doc['id']]); $lines = $s2->fetchAll();
        }
    } catch (Throwable $e) {}
}
$isNew  = isset($_GET['new']);
/* Opening a saved pass shows the DOCUMENT, not the editor. That is what
   puts "Post to stock" on screen — the button lives in the document view,
   so a draft that always reopened in the editor could never be posted.
   The editor is one click away on the Edit button. */
$showForm = $canEdit && ($isNew || ($doc && isset($_GET['edit']) && in_array($doc['status'], ['draft','verified'], true)));
$readOnly = $doc && in_array($doc['status'], ['posted','reversed'], true);

$materials = ($showForm || $doc) ? inv_materials(true) : [];

/* Balances for the item picker. Only an outward pass needs them — an
   inward pass can name anything, because receiving is what puts a thing
   into stock in the first place. One query for every item at every
   location, so the picker can re-rank instantly when the operator
   changes the Location dropdown. */
$SM = ($showForm && $dir === 'out') ? inv_stock_maps('own') : ['qty' => [], 'val' => []];
$stockMap = $SM['qty'];
$valueMap = $SM['val'];

/* Who is holding what, so the party list on a return pass can show only
   the mills that actually have something of ours — and so the customer
   list on a hand-back shows only customers whose goods we are keeping.

   Derived by joining the ledger back to the gate pass that wrote it; no
   column on the ledger stores this. Two small maps are all the browser
   needs: party -> total qty, and party -> which items. */
$heldJW = $showForm ? inv_holdings('jobworker') : [];
$heldCU = $showForm ? inv_holdings('custody')   : [];
$holdMap = ['jobworker' => [], 'custody' => []];
foreach (['jobworker' => $heldJW, 'custody' => $heldCU] as $kind => $H) {
    foreach ($H as $pid => $row) {
        if ($pid <= 0) continue;                       // the unattributable bucket is not a party
        $holdMap[$kind][$pid] = ['qty' => $row['qty'], 'items' => array_map(
            fn($i) => $i['qty'], $row['items'])];
    }
}
/* Quantity sitting at a mill or in custody that no gate pass explains.
   Surfaced rather than hidden — it is the one thing this derived
   approach cannot attribute, and pretending otherwise would be worse. */
$unattributed = [
    'jobworker' => isset($heldJW[0]) ? (float)$heldJW[0]['qty'] : 0.0,
    'custody'   => isset($heldCU[0]) ? (float)$heldCU[0]['qty'] : 0.0,
];
$products = [];
if ($showForm || $doc) { try { $products = db()->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name LIMIT 500")->fetchAll(); } catch (Throwable $e) {} }
$locations = inv_locations(true);
$parties   = $showForm ? inv_parties('') : [];
$contracts = [];
$proformas = [];
if ($showForm) {
    /* Contracts you may book this pass against.
       Only ACTIVE ones can be chosen — a draft is a deal not yet agreed,
       and a gate pass must not quietly commit against one. But the old
       query fetched active only, so a draft contract was simply ABSENT
       with nothing on screen to say why: you made a contract for the
       party, came here, and it was not in the list. Drafts are now
       fetched too and shown greyed out with the reason, so the gap
       explains itself and the fix is one click away.
       Closed and cancelled stay out entirely — those deals are finished
       and must not reappear on any pending screen. */
    try {
        $contracts = db()->query("SELECT c.id, c.contract_no, c.contract_type, c.status,
                COALESCE(c.party_id,0) party_id, p.name pname
            FROM inv_contracts c LEFT JOIN inv_parties p ON p.id = c.party_id
            WHERE c.status IN ('draft','active')
            ORDER BY c.id DESC LIMIT 300")->fetchAll();
    } catch (Throwable $e) {}
    try { $proformas = db()->query("SELECT id,pi_no,customer_name FROM proforma_invoices ORDER BY id DESC LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
}
if ($showForm && !$lines) $lines = [[]];

/* contract balance panel */
$cInfo = null;
if ($doc && $doc['contract_id']) {
    $cq = inv_contract_qty((int)$doc['contract_id']);
    $cd = inv_contract_done((int)$doc['contract_id'], $doc['direction']);
    $thisDoc = 0.0; foreach ($lines as $l) $thisDoc += (float)$l['qty'];
    $cInfo = ['qty' => $cq, 'done' => $cd, 'this' => $thisDoc, 'bal' => $cq - $cd];
}

/* --------------------------------------------------------- the register */
$fq = trim((string)($_GET['q'] ?? ''));
$fs = $_GET['status'] ?? '';
$reg = [];
if (!$doc && !$isNew) {
    $w = ['g.direction = ?']; $p = [$dir];
    if ($fq !== '') { $w[] = '(g.gate_no LIKE ? OR g.challan_no LIKE ? OR g.vehicle_no LIKE ? OR pt.name LIKE ? OR g.party_text LIKE ?)';
        array_push($p, "%$fq%", "%$fq%", "%$fq%", "%$fq%", "%$fq%"); }
    if (in_array($fs, ['draft','verified','posted','reversed'], true)) { $w[] = 'g.status = ?'; $p[] = $fs; }
    try {
        $s = db()->prepare("SELECT g.*, pt.name party_name, c.contract_no, pf.pi_no,
                (SELECT COALESCE(SUM(qty),0) FROM inv_gate_items WHERE gate_id=g.id) tqty,
                (SELECT COUNT(*) FROM inv_gate_items WHERE gate_id=g.id) nlines
            FROM inv_gate g
            LEFT JOIN inv_parties pt ON pt.id=g.party_id
            LEFT JOIN inv_contracts c ON c.id=g.contract_id
            LEFT JOIN proforma_invoices pf ON pf.id=g.proforma_id
            WHERE " . implode(' AND ', $w) . " ORDER BY g.id DESC LIMIT 200");
        $s->execute($p); $reg = $s->fetchAll();
    } catch (Throwable $e) {}
}

$dirLabel = $dir === 'in' ? 'Gate Inward' : 'Gate Outward';
page_header($dirLabel);
flash();
?>
<div class="topbar">
  <div><h1><?= e($dirLabel) ?></h1>
    <p class="lead"><?= $dir === 'in' ? 'Everything arriving at your gate.' : 'Everything leaving your gate.' ?>
      Linking a contract or an order is always optional — a direct movement posts exactly the same way.</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="zbtn sec" href="inv_gate.php?dir=<?= $dir === 'in' ? 'out' : 'in' ?>">Switch to <?= $dir === 'in' ? 'Outward' : 'Inward' ?></a>
    <?php if ($canEdit && !$showForm): ?><a class="zbtn sec" href="?dir=<?= e($dir) ?>&new=1">+ New <?= e($dirLabel) ?></a><?php endif; ?>
  </div>
</div>

<style>
.ig-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ig-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.ig-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.ig-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.ig-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.ig-grid{grid-template-columns:1fr}}
.ig-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ig-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.ig-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
/* type-to-find item picker */
.matbox{position:relative}

/* Contract on the line.

   The box is the same height as every other cell input — a contract that
   made its row taller than the rows around it would undo the density this
   grid was rebuilt for. It is monospace because contract numbers are read
   character by character, and it wraps nothing: a long number scrolls
   inside the box rather than growing the row.

   .set is the state that matters. An empty box is not an error, it is a
   line following the pass header, so it is styled as quiet placeholder
   text; a chosen contract is stated in ink. */
.cbox{position:relative}
/* The font is NOT shrunk. Measuring it showed a smaller font makes a
   shorter line box and a box 1px shorter than the one next to it, which
   is the sort of thing nobody can name but everybody can see. It stays at
   the 12.5px every other cell input uses, and the column is sized for
   monospace at that size instead. */
.ig-tbl td .cline{font-family:ui-monospace,Menlo,Consolas,monospace;
  text-overflow:ellipsis;white-space:nowrap;overflow:hidden}
.ig-tbl td .cline::placeholder{font-family:inherit;color:#aab4c4;font-style:italic}
.ig-tbl td .cline.set{font-weight:700;color:#0b5f8a;border-color:#9fd0e4;background:rgba(14,168,201,.05)}
/* Over the contract balance. Marked, never refused — the quantity that
   actually moved through the gate is the truth, and a gate pass that
   refused to record it would just be a gate pass nobody uses. */
.ig-tbl td .cline.over{border-color:#e5b45a;background:rgba(217,119,6,.07);color:#7a4d09}

.ig-inp.num{text-align:right;font-family:monospace;font-variant-numeric:tabular-nums}
.ig-tbl td .lothint{font-size:10px;color:#8a97ab;margin-top:3px;line-height:1.4}
.ig-tbl td.amt{font-weight:800;font-variant-numeric:tabular-nums;font-family:monospace;font-size:12.5px}

/* per-line detail strip */
tr.detail td{padding:0 8px 3px;border-top:none}
tr.detail .dtog{background:none;border:none;color:#0b5f8a;font-size:11px;font-weight:700;cursor:pointer;
  padding:2px 0;font-family:inherit}
tr.detail .dwrap{display:grid;grid-template-columns:2fr 1fr 1.4fr;gap:10px;margin-top:7px;
  padding:10px;background:#f7fafc;border:1px solid #eef1f7;border-radius:9px}
@media(max-width:760px){tr.detail .dwrap{grid-template-columns:1fr}}
.ig-tbl td.r,.ig-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.ig-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.ig-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.ig-btn.go{background:linear-gradient(100deg,#16a34a,#0e8a3d)}
.ig-btn.warn{background:linear-gradient(100deg,#e08a06,#c0293f)}
.ig-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px;white-space:nowrap}
.st-draft{background:#f0f3f9;color:#5a6b82}
.st-verified{background:rgba(14,168,201,.12);color:#0b7f9b}
.st-posted{background:rgba(22,163,74,.12);color:#16a34a}
.st-reversed{background:rgba(224,67,93,.12);color:#c0293f}
.ig-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.ig-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.ig-note.ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.22);color:#1c5334}
.ig-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.ig-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
.ig-kpi{background:#f7f9fc;border-radius:11px;padding:12px 14px}
.ig-kpi .l{font-size:10px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.ig-kpi .v{font-size:19px;font-weight:800;margin-top:5px;font-variant-numeric:tabular-nums}
.ig-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
</style>

<?php if ($showForm): $D = $doc ?: []; $curType = $D['txn_type'] ?? array_key_first($TYPES); ?>
<div class="ig-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $doc ? 'Edit ' . e($doc['gate_no']) : 'New ' . e($dirLabel) . ' pass' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">The number is generated on save. Draft and verified do not touch stock.</p>

  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($D['id'] ?? 0) ?>">
    <input type="hidden" name="direction" value="<?= e($dir) ?>">

    <div class="ig-grid">
      <div><label class="ig-lbl">Pass no.</label><input class="ig-inp" name="gate_no" value="<?= e($D['gate_no'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div><label class="ig-lbl">Date</label><input class="ig-inp" type="date" name="gate_date" value="<?= e($D['gate_date'] ?? date('Y-m-d')) ?>"></div>
      <div><label class="ig-lbl">Time <?= $dir === 'in' ? 'in' : 'out' ?></label><input class="ig-inp" type="time" name="gate_time" value="<?= e(substr((string)($D['gate_time'] ?? date('H:i')), 0, 5)) ?>"></div>
      <div><label class="ig-lbl">Transaction type</label>
        <select class="ig-inp" name="txn_type" id="ttype">
          <?php foreach ($TYPES as $k => $T): ?>
            <option value="<?= e($k) ?>" data-note="<?= e($T['note']) ?>" data-own="<?= e($T['own']) ?>" <?= $curType === $k ? 'selected' : '' ?>><?= e($T['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <p id="tnote" style="font-size:10.5px;color:#8a97ab;margin:5px 0 0"></p></div>

      <div><label class="ig-lbl" id="plabel"><?= $dir === 'in' ? 'Received from' : 'Party / destination' ?></label>
        <select class="ig-inp" name="party_id" id="pSel">
          <option value="0">— not listed —</option>
          <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"
            data-ptype="<?= e($p['party_type']) ?>"
            data-name="<?= e($p['name']) ?>"
            <?= (int)($D['party_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <p id="pHint" style="font-size:10.5px;color:#8a97ab;margin:5px 0 0"></p></div>

      <?php /* Free text stays for the one-off types (sample, transfer,
               other) where a party record would be noise. On the six real
               trade types it is replaced by + Add new party, because a
               typed name links to nothing — no contract, no ledger, no
               balance — and four spellings of one mill become four
               suppliers. The script swaps between the two. */ ?>
      <div id="pTextWrap"><label class="ig-lbl">…or type a name</label>
        <input class="ig-inp" name="party_text" id="pText" value="<?= e($D['party_text'] ?? '') ?>" placeholder="one-off party"></div>
      <div id="pNewWrap" style="display:none">
        <label class="ig-lbl">Not on the list?</label>
        <button type="button" class="ig-btn sec" id="pNewBtn" style="width:100%;cursor:pointer">+ Add new party</button>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Adds it to the master so it carries contracts and a ledger.</p>
      </div>
      <div><label class="ig-lbl">Vehicle no.</label><input class="ig-inp" name="vehicle_no" value="<?= e($D['vehicle_no'] ?? '') ?>" style="font-family:monospace"></div>
      <div><label class="ig-lbl"><?= $dir === 'in' ? 'Supplier challan / bilty' : 'Challan / reference' ?></label><input class="ig-inp" name="challan_no" value="<?= e($D['challan_no'] ?? '') ?>" style="font-family:monospace"></div>

      <div><label class="ig-lbl">Contract — optional</label>
        <select class="ig-inp" name="contract_id" id="cSel">
          <option value="0">— none, direct —</option>
          <?php
          /* Rendered flat and complete so the box works with no script at
             all; the party filter below regroups it once JS is running. */
          $CTLBL = ['purchase' => 'Purchase', 'sales' => 'Sales',
                    'jobwork_out' => 'Job work — we send', 'jobwork_in' => 'Job work — we do'];
          foreach ($contracts as $c): ?>
            <option value="<?= (int)$c['id'] ?>"
              data-party="<?= (int)$c['party_id'] ?>"
              data-type="<?= e($c['contract_type']) ?>"
              data-status="<?= e($c['status']) ?>"
              data-label="<?= e($c['contract_no'] . ' · ' . ($c['pname'] ?: 'no party') . ' · ' . ($CTLBL[$c['contract_type']] ?? $c['contract_type']) . ($c['status'] === 'draft' ? '  — still a draft, activate it first' : '')) ?>"
              <?= $c['status'] === 'draft' ? 'disabled' : '' ?>
              <?= (int)($D['contract_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['contract_no'] . ' · ' . ($c['pname'] ?: 'no party') . ' · ' . ($CTLBL[$c['contract_type']] ?? $c['contract_type']) . ($c['status'] === 'draft' ? '  — still a draft, activate it first' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p id="cHint" style="font-size:10.5px;color:#8a97ab;margin:5px 0 0"></p></div>
      <div><label class="ig-lbl">Sales tax</label>
        <div style="display:flex;gap:8px;align-items:center">
          <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer;white-space:nowrap">
            <input type="checkbox" name="gst_applicable" id="gstOn" value="1" <?= !empty($D['gst_applicable']) ? 'checked' : '' ?>> GST
          </label>
          <input class="ig-inp" name="gst_pct" id="gstPct" style="width:82px;text-align:right"
                 value="<?= e((string)($D['gst_pct'] ?? inv_gst_default())) ?>">
          <span style="font-size:12px;color:#8a97ab">%</span>
        </div>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Off unless ticked. The print shows both excluding and including.</p></div>
      <?php /* Outward only. On an inward pass this field was written to the
               database, copied onto every stock ledger row, and then never
               read by anything — the single consumer is Order Costing's
               "dispatched against this order" figure, which filters to
               direction='out'. So it is gone from Inward, and named for
               what it actually does on Outward. The material side of an
               order belongs on Consumption, which has its own link. */
      if ($dir === 'out'): ?>
      <div><label class="ig-lbl">Dispatch against order — optional</label>
        <select class="ig-inp" name="proforma_id">
          <option value="0">— none —</option>
          <?php foreach ($proformas as $pf): ?><option value="<?= (int)$pf['id'] ?>" <?= (int)($D['proforma_id'] ?? 0) === (int)$pf['id'] ? 'selected' : '' ?>><?= e($pf['pi_no']) ?> · <?= e($pf['customer_name']) ?></option><?php endforeach; ?>
        </select>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Feeds the dispatched figure on Order Costing Control.</p></div>
      <?php else: /* carry whatever an older inward pass already holds, so
                     re-saving it does not quietly change stored data */ ?>
        <input type="hidden" name="proforma_id" value="<?= (int)($D['proforma_id'] ?? 0) ?>">
      <?php endif; ?>
      <div><label class="ig-lbl">Location</label>
        <select class="ig-inp" name="location_id">
          <?php $defLoc = (int)($D['location_id'] ?? inv_setting('default_location', '1'));
          foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $defLoc === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div style="grid-column:span 2"><label class="ig-lbl">Remarks</label><input class="ig-inp" name="remarks" value="<?= e($D['remarks'] ?? '') ?>"></div>
      <div><label class="ig-lbl">Save as</label>
        <select class="ig-inp" name="status">
          <option value="draft" <?= ($D['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="verified" <?= ($D['status'] ?? '') === 'verified' ? 'selected' : '' ?>>Verified</option>
        </select></div>
    </div>

    <?php /* Four fields that are usually left empty, taking a full row on
             a screen used many times a day. Folded — but ONLY when they are
             all empty. A pass that already uses any of them opens the
             section on load, so folding can never hide something that is
             actually filled in. */
    $moreFilled = trim((string)($D['department'] ?? '')) !== ''
               || trim((string)($D['purpose'] ?? '')) !== ''
               || trim((string)($D['verified_by'] ?? '')) !== ''
               || trim((string)($D['security_by'] ?? '')) !== ''; ?>
    <div style="margin-top:14px">
      <button type="button" id="moreBtn" class="ig-btn sec" style="cursor:pointer;font-size:12.5px">
        <span id="moreCar"><?= $moreFilled ? '▴' : '▾' ?></span> More details
        <span style="font-weight:600;color:#8a97ab">— department, purpose, verified by, security check</span>
        <?php if ($moreFilled): ?><span style="color:#0b5f8a;font-weight:800">· in use</span><?php endif; ?>
      </button>
      <div id="moreWrap" class="ig-grid" style="margin-top:12px;<?= $moreFilled ? '' : 'display:none' ?>">
        <div><label class="ig-lbl">Department</label><input class="ig-inp" name="department" value="<?= e($D['department'] ?? '') ?>"></div>
        <div style="grid-column:span 2"><label class="ig-lbl">Purpose</label><input class="ig-inp" name="purpose" value="<?= e($D['purpose'] ?? '') ?>"></div>
        <div><label class="ig-lbl">Quality / qty verified by</label><input class="ig-inp" name="verified_by" value="<?= e($D['verified_by'] ?? '') ?>"></div>
        <div><label class="ig-lbl">Security check</label><input class="ig-inp" name="security_by" value="<?= e($D['security_by'] ?? '') ?>"></div>
      </div>
    </div>

    <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;margin:22px 0 10px">Items</h3>
    <div style="overflow-x:auto"><table class="ig-tbl" id="glines">
      <?php /* Column plan. Material gets the room, the money columns get
               enough for four decimals and a thousands separator, and
               Packing has left the row — it is used on a minority of lines
               and was taking width from every line. It now lives in the
               per-line detail strip underneath, with Description and the
               finished-product picker. */ ?>
      <?php /* Contract is its OWN column, beside the item — not a chip
               stacked underneath it. A chip under the item turns every
               line into two lines of height whether or not it is used,
               which is the opposite of what a compact grid is for. */ ?>
      <colgroup>
        <col style="width:auto"><col style="width:158px"><col style="width:136px">
        <?php if ($dir === 'out'): ?><col style="width:104px"><?php endif; ?>
        <col style="width:70px"><col style="width:118px"><col style="width:120px">
        <col style="width:124px"><col style="width:40px">
      </colgroup>
      <thead><tr>
        <th style="min-width:200px">Material</th>
        <th>Contract</th>
        <th>Lot / roll</th>
        <?php if ($dir === 'out'): ?><th class="r">Available</th><?php endif; ?>
        <th>UOM</th><th class="r">Quantity</th><th class="r">Rate</th>
        <th class="r">Amount</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $i => $L):
        $hasDetail = trim((string)($L['description'] ?? '')) !== ''
                  || trim((string)($L['packing'] ?? '')) !== ''
                  || (int)($L['product_id'] ?? 0) > 0; ?>
        <tr data-item="<?= (int)($L['material_id'] ?? 0) ?>">
          <td><div class="matbox">
            <?php /* The text box is what you type in and what the LOV
                     anchors to. The <select> beneath it is still the field
                     that gets submitted and is still what every other
                     script here reads, so if this JS ever fails the plain
                     dropdown comes back and the screen still works. */ ?>
            <input class="ig-inp matq lovf" type="text" autocomplete="off" spellcheck="false"
                   data-lov="item" placeholder="click here — the list opens" style="display:none">
            <select class="ig-inp matsel" name="line[<?= $i ?>][material_id]">
              <option value="0">—</option>
              <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" data-uom="<?= e($m['uom']) ?>" data-rate="<?= e((string)$m['std_rate']) ?>" data-grp="<?= e($m['item_group'] ?? '') ?>" <?= (int)($L['material_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
            </select>
          </div></td>
          <?php
            /* What the box shows when the pass is reopened. Three states,
               and they must read differently or the operator cannot tell
               a choice from a default:
                 - the line names a contract        -> its number, plain
                 - it does not, but the header does -> the header's number,
                   greyed, and nothing is stored on the line
                 - neither                          -> empty
               Only the first writes anything back. */
            $lcid = (int)($L['lcid'] ?? 0);
            $lcTxt = $lcid > 0 ? (string)($L['lcno'] ?? '') : '';
            $hdrNo = (string)($doc['contract_no'] ?? '');
          ?>
          <td><div class="cbox">
            <?php /* The "chosen" look is written by the SERVER, not waited
                     for from JavaScript. A box that is blue only after a
                     fetch comes back flickers on every page load, and on a
                     slow line it reads as unlinked for a second. */ ?>
            <input class="ig-inp cline lovf<?= $lcid > 0 ? ' set' : '' ?>" type="text" autocomplete="off" spellcheck="false"
                   data-lov="cline" value="<?= e($lcTxt) ?>"
                   placeholder="<?= $hdrNo !== '' ? e($hdrNo) : '— none —' ?>"
                   title="Which contract line this quantity is booked against. Leave it empty and the line follows the contract on the pass header — or nothing, if the header has none.">
            <input type="hidden" class="cid"   name="line[<?= $i ?>][contract_id]"      value="<?= $lcid > 0 ? $lcid : '' ?>">
            <input type="hidden" class="citem" name="line[<?= $i ?>][contract_item_id]" value="<?= e((string)($L['contract_item_id'] ?? '')) ?>">
          </div></td>
          <td><input class="ig-inp lot lovf" data-lov="lot" name="line[<?= $i ?>][lot_no]"
                 value="<?= e($L['lot_no'] ?? '') ?>" style="font-family:monospace" autocomplete="off"
                 placeholder="any lot">
              <div class="lothint"></div></td>
          <?php if ($dir === 'out'): ?><td class="r avail">—</td><?php endif; ?>
          <td><input class="ig-inp uom derived" name="line[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>"
                 tabindex="-1" title="From the item. Type over it if this pass is different."></td>
          <td><input class="ig-inp qty num" name="line[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" placeholder="0.000"></td>
          <td><input class="ig-inp rate num derived" name="line[<?= $i ?>][rate]" value="<?= e((string)($L['rate'] ?? '')) ?>"
                 placeholder="0.0000" title="From the lot, the contract, or the item. Type over it to change this pass."></td>
          <td class="r amt"><?= number_format((float)($L['amount'] ?? 0), 2) ?></td>
          <td><button type="button" class="ig-btn sec del" title="Remove this line">×</button></td>
        </tr>
        <?php /* Everything that is not needed on most lines. Opens on its
                 own when the line already uses any of it, so folding can
                 never hide something that is filled in. */ ?>
        <tr class="detail<?= $hasDetail ? ' open' : '' ?>">
          <td colspan="<?= $dir === 'out' ? 9 : 8 ?>">
            <button type="button" class="dtog"><?= $hasDetail ? '▴' : '▾' ?> Description, packing, finished product<?= $hasDetail ? ' · in use' : '' ?></button>
            <div class="dwrap"<?= $hasDetail ? '' : ' style="display:none"' ?>>
              <div><label class="ig-lbl">Description — as the contract words it</label>
                <input class="ig-inp desc" name="line[<?= $i ?>][description]" value="<?= e($L['description'] ?? '') ?>"
                       placeholder="defaults to the item name"></div>
              <div><label class="ig-lbl">Packing</label>
                <input class="ig-inp" name="line[<?= $i ?>][packing]" value="<?= e($L['packing'] ?? '') ?>"></div>
              <div><label class="ig-lbl">…or finished product</label>
                <select class="ig-inp" name="line[<?= $i ?>][product_id]">
                  <option value="0">—</option>
                  <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= (int)($L['product_id'] ?? 0) === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
                </select></div>
              <?php /* contract_item_id used to be a hidden field down here,
                       set only by the "Pull lines from contract" button. It
                       has moved up into the Contract column, where it is
                       something you can see and change on the line itself. */ ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php /* left of Quantity, and left of Amount */
      $cA = $dir === 'out' ? 5 : 4; $cB = $dir === 'out' ? 7 : 6; ?>
      <?php /* Columns now: Material, Contract, Lot, [Available], UOM,
               Quantity, Rate, Amount, ×  — 9 on an outward pass, 8 on an
               inward one. Every row below must add up to that or the table
               skews. Contract added one to each, so both spans above moved
               by one; they are written out rather than left as literals so
               the next column to arrive has one place to change. */ ?>
      <tfoot>
        <tr><td colspan="<?= $cA ?>" style="text-align:right;font-weight:800">Total quantity</td>
            <td class="r" id="gqty" style="font-weight:800">0</td><td></td>
            <td class="r" id="gexcl" style="font-weight:800">0.00</td><td></td></tr>
        <tr id="gstRow" style="display:none"><td colspan="<?= $cB ?>" style="text-align:right;color:#5a6b82">GST <span id="gstShow">18</span>%</td>
            <td class="r" id="ggst" style="font-weight:700">0.00</td><td></td></tr>
        <tr><td colspan="<?= $cB ?>" style="text-align:right;font-weight:800">Total including tax</td>
            <td class="r" id="gincl" style="font-weight:800;font-size:13.5px">0.00</td><td></td></tr>
      </tfoot>
    </table></div>

    <?php if ($dir === 'out'): ?>
    <div id="stockWarn" class="ig-note warn" style="margin-top:12px;display:none"></div>
    <?php endif; ?>
    <?php /* One strip for the whole table rather than a note under each
             line. A per-line message would push every row down the moment
             it appeared, which is exactly the two-line row this column was
             designed to avoid. It says what is over and by how much; it
             never stops the save. */ ?>
    <div id="cWarn" class="ig-note warn" style="margin-top:12px;display:none"></div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button type="button" class="ig-btn sec" id="addg">+ Add line</button>
      <button type="button" class="ig-btn sec" id="pullC">Pull lines from contract</button>
      <button class="ig-btn" type="submit">Save pass</button>
      <a class="ig-btn sec" href="inv_gate.php?dir=<?= e($dir) ?>">Back to register</a>
      <span style="font-size:11.5px;color:#8a97ab">Saving does not move stock. Post it from the pass afterwards.</span>
    </div>
  </form>
</div>

<!-- pull lines from the chosen contract -->
<div id="cScrim" style="position:fixed;inset:0;background:rgba(8,14,24,.55);display:none;align-items:flex-start;justify-content:center;padding:26px 16px;overflow:auto;z-index:60">
  <div class="ig-card" style="width:100%;max-width:980px;margin:0">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
      <div><h2 id="cTitle" style="font-size:15px;margin:0;font-weight:800">Lines on the contract</h2>
        <p id="cSub" style="font-size:11.5px;color:#8a97ab;margin:3px 0 0"></p></div>
      <button type="button" class="ig-btn sec" id="cClose">Close</button>
    </div>
    <div style="overflow-x:auto;margin-top:14px"><table class="ig-tbl">
      <thead><tr><th style="width:32px"></th><th>Item</th><th>Description on the contract</th>
        <th class="r">Contracted</th><th class="r">Done</th><th class="r">Balance</th>
        <th class="r">Rate</th><th class="r" style="width:120px">Take now</th></tr></thead>
      <tbody id="cBody"></tbody>
    </table></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;padding-top:14px;border-top:1px solid #e3e9f2">
      <button type="button" class="ig-btn sec" id="cCancel">Cancel</button>
      <button type="button" class="ig-btn" id="cAdd">Add the ticked lines</button>
    </div>
  </div>
</div>

<link rel="stylesheet" href="assets/css/lov.css">
<script src="assets/js/lov.js"></script>
<script>
(function(){
  var tb=document.querySelector('#glines tbody');
  function num(v){var n=parseFloat(String(v).replace(/[^0-9.\-]/g,''));return isNaN(n)?0:n;}
  function money(n){return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}

  /* One place computes the money on this screen, so the form, the gate
     pass print and the contract print cannot disagree. */
  function tot(){
    var q=0, ex=0;
    lines().forEach(function(tr){
      var qty=num((tr.querySelector('.qty')||{}).value), rate=num((tr.querySelector('.rate')||{}).value);
      q+=qty; var amt=qty*rate; ex+=amt;
      var c=tr.querySelector('.amt'); if(c) c.textContent=money(amt);
    });
    document.getElementById('gqty').textContent=q.toLocaleString('en-US',{maximumFractionDigits:3});
    document.getElementById('gexcl').textContent=money(ex);
    var on=document.getElementById('gstOn').checked, pct=num(document.getElementById('gstPct').value);
    var gst=on?Math.round(ex*pct)/100:0;
    document.getElementById('gstRow').style.display=on?'':'none';
    document.getElementById('gstShow').textContent=pct;
    document.getElementById('ggst').textContent=money(gst);
    document.getElementById('gincl').textContent=money(ex+gst);
  }
  tb.addEventListener('input',function(e){
    tot();
    /* the over-the-balance strip is driven by quantity, so it has to be
       recomputed on the same keystroke that changes one */
    if(e.target.classList.contains('qty')) clWarn();
    if(IS_OUT){ var tr=e.target.closest('tr'); if(tr) refreshRow(tr); stockCheck(); }
  });
  document.getElementById('gstOn').addEventListener('change',tot);
  document.getElementById('gstPct').addEventListener('input',tot);

  tb.addEventListener('change',function(e){
    var s=e.target; if(s.tagName!=='SELECT'||s.name.indexOf('[material_id]')<0) return;
    var o=s.options[s.selectedIndex]; if(!o) return;
    var tr=s.closest('tr'), det=detailOf(tr);
    var u=tr.querySelector('.uom'), r=tr.querySelector('.rate');
    var d=det?det.querySelector('.desc'):null;
    /* UOM and rate are DERIVED, not asked for. The unit comes from the
       item; the rate from the stock actually leaving, falling back to the
       item's standard rate when there is no stock to read (a receipt).
       Both stay editable — dashed, so you can see they were filled in. */
    if(u&&!u.value&&o.dataset.uom) u.value=o.dataset.uom;
    if(r&&!num(r.value)){
      var it=itemById(o.value);
      var rt=it?rateFor(it):num(o.dataset.rate);
      if(rt>0) r.value=Number(rt).toFixed(4);
    }
    // Description starts as OUR item name; the operator overwrites it with
    // the supplier's or customer's own wording where the contract differs.
    if(d&&!d.value&&o.value&&o.value!=='0') d.value=(o.text||'').replace(/^[^·]*·\s*/,'');
    tot(); loadOnHand(tr); stockCheck();
  });
  /* A line is now TWO table rows — the entry row and the detail strip
     under it. Everything that walks the lines has to walk pairs, or the
     names go out of step and the wrong values are submitted. */
  function lines(){ return [].slice.call(tb.querySelectorAll('tr')).filter(function(tr){
    return !tr.classList.contains('detail'); }); }
  function detailOf(tr){
    var n = tr.nextElementSibling;
    return (n && n.classList.contains('detail')) ? n : null;
  }

  tb.addEventListener('click',function(e){
    if(!e.target.classList.contains('del')) return;
    var tr=e.target.closest('tr'), det=detailOf(tr);
    if(lines().length>1){ if(det) det.remove(); tr.remove(); }
    else {
      tr.querySelectorAll('input').forEach(function(i){i.value='';});
      var s=tr.querySelector('.matsel'); if(s) s.selectedIndex=0;
      tr.dataset.item='0';
      if(det) det.querySelectorAll('input,select').forEach(function(el){
        if(el.tagName==='INPUT') el.value=''; else el.selectedIndex=0; });
      var a=tr.querySelector('.amt'); if(a) a.textContent='0.00';
      var cf=tr.querySelector('.cline'); if(cf) cf.classList.remove('set','over');
    }
    reindex(); tot(); clWarn(); if(LV.open) lovClose();
  });

  function reindex(){
    lines().forEach(function(tr,i){
      var det=detailOf(tr);
      [tr, det].forEach(function(node){
        if(!node) return;
        node.querySelectorAll('[name]').forEach(function(el){
          el.name=el.name.replace(/line\[\d+\]/,'line['+i+']'); });
      });
    });
  }

  function blankRow(){
    var last=lines()[lines().length-1];
    var c=last.cloneNode(true), d=detailOf(last), dc=d?d.cloneNode(true):null;
    [c, dc].forEach(function(node){
      if(!node) return;
      node.querySelectorAll('input,select').forEach(function(el){
        if(el.tagName==='INPUT') el.value=''; else el.selectedIndex=0;
      });
    });
    c.dataset.item='0';
    /* The hidden contract fields are inputs, so the loop above already
       emptied them. The classes are not values and have to be taken off
       by hand, or a new line inherits the last one's blue box. */
    var cf=c.querySelector('.cline'); if(cf) cf.classList.remove('set','over');
    var a=c.querySelector('.amt'); if(a) a.textContent='0.00';
    var av=c.querySelector('.avail'); if(av){ av.textContent='—'; av.style.color='#8a97ab'; }
    var lh=c.querySelector('.lothint'); if(lh) lh.textContent='';
    if(dc){                                    // a fresh line starts folded
      var w=dc.querySelector('.dwrap'); if(w) w.style.display='none';
      var t=dc.querySelector('.dtog'); if(t) t.textContent='▾ Description, packing, finished product';
      dc.classList.remove('open');
    }
    return [c, dc];
  }

  document.getElementById('addg').addEventListener('click',function(){
    var pair=blankRow();
    tb.appendChild(pair[0]); if(pair[1]) tb.appendChild(pair[1]);
    reindex(); syncAll();
    var f=pair[0].querySelector('.matq');       // the LOV field, not the hidden select
    if(!f||f.style.display==='none') f=pair[0].querySelector('select');
    if(f) f.focus();
  });

  /* ---- pull the lines from the contract -------------------------- */
  var cSel=document.getElementById('cSel'), scrim=document.getElementById('cScrim'), CL=[];
  function openPull(){
    var id=cSel?+cSel.value:0;
    if(!id){ alert('Choose the contract first, in the Contract box above.'); return; }
    document.getElementById('cBody').innerHTML='<tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">Loading…</td></tr>';
    scrim.style.display='flex';
    fetch('inv_gate.php?ajax=contract&contract_id='+id).then(function(r){return r.json()}).then(function(d){
      if(!d.ok){ document.getElementById('cBody').innerHTML='<tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">That contract could not be read.</td></tr>'; return; }
      CL=d.lines||[];
      document.getElementById('cTitle').textContent='Lines on '+(d.contract.contract_no||'');
      var un=d.unassigned||{};
      document.getElementById('cSub').innerHTML=
        (CL.length? CL.length+' line(s). Balance counts only POSTED gate passes booked against each line.' : 'This contract has no lines.')
        + (un.lines? ' <b style="color:#b45309">'+un.lines+' posted gate line(s) name this contract but no particular line ('
            + Number(un.qty).toLocaleString()+' qty) — those are not counted in the balances below.</b>' : '');
      if(d.contract.gst_applicable && !document.getElementById('gstOn').checked){
        document.getElementById('gstOn').checked=true;
        if(d.contract.gst_pct) document.getElementById('gstPct').value=d.contract.gst_pct;
        tot();
      }
      renderC();
    }).catch(function(){
      document.getElementById('cBody').innerHTML='<tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">Could not reach the server.</td></tr>';
    });
  }
  function renderC(){
    var tbc=document.getElementById('cBody');
    if(!CL.length){ tbc.innerHTML='<tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">No lines on this contract.</td></tr>'; return; }
    tbc.innerHTML=CL.map(function(l,i){
      var done=l.balance<=0.0005;
      return '<tr'+(done?' style="opacity:.55"':'')+'>'
        + '<td><input type="checkbox" class="cTick" data-i="'+i+'" '+(done?'disabled':'')+'></td>'
        + '<td><b>'+l.item+'</b></td>'
        + '<td style="color:#5a6b82">'+(l.description||'—')+'</td>'
        + '<td class="r">'+Number(l.qty).toLocaleString('en-US',{maximumFractionDigits:3})+'</td>'
        + '<td class="r">'+Number(l.done).toLocaleString('en-US',{maximumFractionDigits:3})+'</td>'
        + '<td class="r" style="font-weight:700'+(l.over?';color:#c0293f':'')+'">'
          + Number(l.balance).toLocaleString('en-US',{maximumFractionDigits:3})
          + (l.over?'<div style="font-size:10px;font-weight:700">over-delivered</div>':'')+'</td>'
        + '<td class="r">'+money(l.rate)+'</td>'
        + '<td class="r"><input class="ig-inp cQty" data-i="'+i+'" style="width:100px;text-align:right" '
          + (done?'disabled':'value="'+l.balance+'"')+'></td></tr>';
    }).join('');
  }
  function addFromContract(){
    var picked=[];
    [].slice.call(document.querySelectorAll('.cTick')).forEach(function(cb){
      if(!cb.checked) return;
      var i=+cb.dataset.i, q=0;
      var qi=document.querySelector('.cQty[data-i="'+i+'"]'); if(qi) q=num(qi.value);
      if(q>0) picked.push({l:CL[i], qty:q});
    });
    if(!picked.length){ alert('Tick at least one line and give it a quantity.'); return; }
    /* Drop a single empty starter line so the pulled lines are not
       stranded below it. Both of its rows go — entry and detail. */
    if(lines().length===1){
      var first=lines()[0], m=first.querySelector('select[name*="[material_id]"]');
      if(m && (!m.value||m.value==='0') && !num((first.querySelector('.qty')||{}).value)){
        var fd=detailOf(first); if(fd) fd.remove();
        first.remove();
      }
    }
    picked.forEach(function(p){
      var pair=blankRow(), tr=pair[0], det=pair[1];
      var sel=tr.querySelector('select[name*="[material_id]"]');
      if(sel && p.l.material_id){ sel.value=String(p.l.material_id); tr.dataset.item=String(p.l.material_id); }
      var psel=det?det.querySelector('select[name*="[product_id]"]'):null;
      if(psel && p.l.product_id) psel.value=String(p.l.product_id);
      var set=function(root,cls,v){ if(!root) return; var el=root.querySelector(cls); if(el) el.value=v; };
      // the contract's own wording and its agreed rate both win here —
      // that is the whole point of pulling instead of typing
      set(det,'.desc', p.l.description || p.l.item_name || '');
      set(tr,'.qty', p.qty);
      set(tr,'.uom', p.l.uom || '');
      set(tr,'.rate', p.l.rate || '');
      /* The hidden link used to live in the detail strip. It is in the
         Contract column now, so the pull button writes it there — and
         writes the contract beside the line, and shows the number, so a
         pulled line and a line picked by hand end up identical. */
      var ci=tr.querySelector('.citem'); if(ci) ci.value=p.l.id;
      var cid=tr.querySelector('.cid'); if(cid) cid.value=(cSel?cSel.value:'')||'';
      var cf=tr.querySelector('.cline');
      if(cf){
        var co=cSel&&cSel.selectedIndex>=0?cSel.options[cSel.selectedIndex]:null;
        cf.value=co?String(co.text).split(' · ')[0]:'';
        cf.classList.add('set');
      }
      if(det && (p.l.description || p.l.product_id)){
        var w=det.querySelector('.dwrap'); if(w) w.style.display='';
        var t=det.querySelector('.dtog'); if(t) t.textContent='▴ Description, packing, finished product · in use';
      }
      tb.appendChild(tr); if(det) tb.appendChild(det);
    });
    reindex(); syncAll(); tot(); clWarn(); refreshAll(); scrim.style.display='none';
  }
  var pb=document.getElementById('pullC'); if(pb) pb.addEventListener('click', openPull);
  document.getElementById('cClose').onclick=function(){ scrim.style.display='none'; };
  document.getElementById('cCancel').onclick=function(){ scrim.style.display='none'; };
  document.getElementById('cAdd').onclick=addFromContract;
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') scrim.style.display='none'; });

  /* ---- what is actually on hand ---------------------------------- */
  var IS_OUT = <?= $dir === 'out' ? 'true' : 'false' ?>;
  var ONHAND = {};          // "materialId|locationId" -> {available, lots, ...}
  var TOL = 10, ISADMIN = false;

  function locId(){ var el = document.querySelector('select[name="location_id"]'); return el ? +el.value : 0; }
  function ownOf(){ var o = ts.options[ts.selectedIndex]; return (o && o.dataset.own === 'customer') ? 'customer' : 'own'; }

  function loadOnHand(tr){
    if(!IS_OUT) return;
    var sel = tr.querySelector('select[name*="[material_id]"]');
    var mid = sel ? +sel.value : 0;
    if(!mid){ paintAvail(tr, null); return; }
    var key = mid + '|' + locId() + '|' + ownOf();
    if(ONHAND[key]){ paintAvail(tr, ONHAND[key]); return; }
    fetch('inv_gate.php?ajax=onhand&material_id=' + mid + '&location_id=' + locId() + '&own=' + ownOf())
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(!d.ok) return;
        ONHAND[key] = d; TOL = d.tolerance_pct || 10; ISADMIN = !!d.is_admin;
        paintAvail(tr, d);
      }).catch(function(){});
  }
  function paintAvail(tr, d){
    if(!IS_OUT) return;
    var cell = tr.querySelector('.avail'), hint = tr.querySelector('.lothint');
    if(!d){ if(cell){ cell.textContent = '—'; cell.style.color = '#8a97ab'; } if(hint) hint.textContent = ''; return; }
    /* The lots themselves are the LOV's business now. This only says how
       many there are, so the operator knows the box is worth opening. */
    if(hint) hint.textContent = (d.lots && d.lots.length)
      ? d.lots.length + ' lot(s) here — leave blank to take from any'
      : 'no lot of this item is at ' + (d.location || 'this location');
    refreshRow(tr);
  }
  function availFor(tr){
    var sel = tr.querySelector('select[name*="[material_id]"]');
    var mid = sel ? +sel.value : 0; if(!mid) return null;
    var d = ONHAND[mid + '|' + locId() + '|' + ownOf()]; if(!d) return null;
    var lot = (tr.querySelector('.lot') || {}).value || '';
    if(lot.trim() === '') return d.available;
    var bal = 0;
    (d.lots || []).forEach(function(l){ if((l.lot_no || '') === lot.trim()) bal += l.bal; });
    return bal;
  }
  function q3(v){ return Number(v).toLocaleString('en-US',{maximumFractionDigits:3}); }
  function refreshRow(tr){
    if(!IS_OUT) return;
    var cell = tr.querySelector('.avail'); if(!cell) return;
    var av = availFor(tr);
    if(av === null){ cell.textContent = '—'; cell.style.color = '#8a97ab'; return; }
    var want = num((tr.querySelector('.qty') || {}).value);
    cell.textContent = q3(av);
    cell.style.color = want > av + 0.0005 ? '#c0293f' : '#16a34a';
    cell.style.fontWeight = want > av + 0.0005 ? '700' : '400';
  }
  function stockCheck(){
    if(!IS_OUT) return;
    var box = document.getElementById('stockWarn'); if(!box) return;
    var msgs = [], hard = 0;
    lines().forEach(function(tr){
      var av = availFor(tr); if(av === null) return;
      var want = num((tr.querySelector('.qty') || {}).value); if(want <= 0) return;
      var over = want - av; if(over <= 0.0005) return;
      var sel = tr.querySelector('select[name*="[material_id]"]');
      var name = sel ? (sel.options[sel.selectedIndex].text || 'item') : 'item';
      var lim = Math.max(0, av) * TOL / 100;
      if(over > lim + 0.0005){ hard++; msgs.push('<b>' + name + '</b> — ' + q3(av) + ' here, taking ' + q3(over) + ' more (beyond ' + TOL + '%)'); }
      else msgs.push('<b>' + name + '</b> — ' + q3(av) + ' here, taking ' + q3(over) + ' more (within ' + TOL + '%)');
    });
    if(!msgs.length){ box.style.display = 'none'; return; }
    box.style.display = '';
    box.innerHTML = '<b>This pass takes more than is here.</b><br>' + msgs.join('<br>')
      + '<br><br>Put the reason in <b>Remarks</b> and save. '
      + (hard && !ISADMIN
          ? 'Because at least one line is beyond ' + TOL + '%, <b>only an admin can post it</b>.'
          : 'It will be checked again against the ledger when you post.');
  }

  var ts=document.getElementById('ttype'), tn=document.getElementById('tnote');
  /* Sending your own fabric out for processing, or handing a customer's
     goods back, is not a sale — nothing changes owner, so there is nothing
     to tax. GST is switched off and locked for those four types; it
     belongs on the job work SERVICE bill instead. */
  var JOBWORK = ['jobwork_issue','jobwork_return','jobwork_received','jobwork_delivered'];
  function taxRule(){
    var isJob = JOBWORK.indexOf(ts.value) >= 0;
    var on = document.getElementById('gstOn'), pc = document.getElementById('gstPct');
    on.disabled = isJob; pc.disabled = isJob;
    if(isJob) on.checked = false;
    var wrap = on.closest('div').parentNode;
    var hint = wrap.querySelector('p');
    if(hint) hint.textContent = isJob
      ? 'Not applicable — a job work movement is not a sale. Tax goes on the job work bill.'
      : 'Off unless ticked. The print shows both excluding and including.';
    tot();
  }
  function note(){var o=ts.options[ts.selectedIndex];tn.textContent=o?o.dataset.note:'';
    tn.style.color=(o&&o.dataset.own==='customer')?'#d97706':'#8a97ab';}
  /* Only the tax/label side here. Refreshing the lists is left to the
     cascade listener further down, which runs after the party list has
     been rebuilt for the new type — doing it in both places would fetch
     twice and, worse, paint once against a party the new type no longer
     allows. */
  ts.addEventListener('change',function(){ note(); taxRule(); ONHAND={}; });
  var locSel = document.querySelector('select[name="location_id"]');
  if(locSel) locSel.addEventListener('change', function(){ ONHAND={}; refreshAll(); });
  function refreshAll(){
    if(!IS_OUT) return;
    lines().forEach(loadOnHand);
    stockCheck();
  }

  /* ---- the item picker ------------------------------------------- */
  /* The dropdown of two hundred items was unusable: you had to know the
     code to find the item, and on an outward pass nothing told you
     whether the thing you picked was even on the shelf.

     So the select becomes a type-to-find box. The <select> itself is
     still there, still the field that gets submitted, and still what
     every other piece of script on this page reads — it is only hidden
     from view. If this script fails for any reason the plain dropdown
     comes back and the screen still works. */
  var STOCK  = <?= json_encode($stockMap ?: new stdClass()) ?>;
  var VALMAP = <?= json_encode($valueMap ?: new stdClass()) ?>;
  var CSRF   = <?= json_encode(csrf_token()) ?>;

  // built once from the select that is already on the page
  var ITEMS = (function(){
    var out = [], s = tb.querySelector('.matsel');
    if(!s) return out;
    [].slice.call(s.options).forEach(function(o){
      if(!o.value || o.value === '0') return;
      var txt = o.text || '', dot = txt.indexOf('·');
      out.push({ id:o.value, text:txt,
                 code: dot > 0 ? txt.slice(0, dot).trim() : txt,
                 name: dot > 0 ? txt.slice(dot + 1).trim() : txt,
                 grp: o.dataset.grp || '', uom: o.dataset.uom || '',
                 rate: o.dataset.rate || 0 });
    });
    return out;
  })();

  /* Which stock the picker is restricted to, for the type on screen.

       all       receiving — anything may be named, because receiving is
                 the act that puts a thing into stock
       here      leaving our floor — only what is at this location
       atparty   already sitting with someone else (job work return, or
                 handing a customer's goods back) — only what THEY hold */
  function pickMode(){
    var c = pclass();
    if(c === 'holder' || c === 'custody') return 'atparty';
    return IS_OUT ? 'here' : 'all';
  }
  function heldItems(){
    var pid = pSel ? +pSel.value : 0; if(!pid) return null;
    var d = HELD[holdKind() + '|' + pid];
    return d ? (d.items || {}) : null;
  }
  function balOf(id){
    if(pickMode() === 'atparty'){
      var hi = heldItems(); if(!hi) return 0;
      return hi[id] ? hi[id].qty : 0;
    }
    var m = STOCK[id]; if(!m) return 0;
    var l = locId();
    var v = l > 0 ? m[l] : m[0];
    return v === undefined ? 0 : v;
  }
  function itemById(id){
    for(var i = 0; i < ITEMS.length; i++) if(String(ITEMS[i].id) === String(id)) return ITEMS[i];
    return null;
  }

  /* The rate this item should carry on THIS pass.

     Order of trust: what the stock is actually valued at where it is
     standing, then what the party is holding it at, then the item's own
     standard rate. A receipt has no stock to read yet, so it takes the
     standard — which is the only case where the master rate is the right
     answer. Asking the operator to type a number the system already knows
     is how wrong rates get into a ledger. */
  function rateFor(it){
    var mode = pickMode();
    if(mode === 'atparty'){
      var hi = heldItems();
      if(hi && hi[it.id] && hi[it.id].qty > 0 && hi[it.id].value)
        return Math.round((hi[it.id].value / hi[it.id].qty) * 10000) / 10000;
    } else if(mode === 'here'){
      var v = VALMAP[it.id], b = balOf(it.id);
      var l = locId();
      var val = v ? (l > 0 ? v[l] : v[0]) : undefined;
      if(val !== undefined && b > 0.0005) return Math.round((val / b) * 10000) / 10000;
    }
    return num(it.rate);
  }

  /* ---- the LOV providers -------------------------------------------
     The panel, the keyboard and the fuzzy matching live in
     assets/js/lov.js, shared with Consumption and Store issues. What
     belongs HERE is only the two things this screen knows: which rows
     are valid, and what to do when one is taken. */

  function itemById(id){
    for(var i = 0; i < ITEMS.length; i++) if(String(ITEMS[i].id) === String(id)) return ITEMS[i];
    return null;
  }

  /* The rate this item should carry on THIS pass.

     Order of trust: what the stock is actually valued at where it is
     standing, then what the party is holding it at, then the item's own
     standard rate. A receipt has no stock to read yet, so it takes the
     standard — the one case where the master rate is the right answer.
     Asking the operator to type a number the system already knows is how
     wrong rates get into a ledger. */
  function rateFor(it){
    var mode = pickMode();
    if(mode === 'atparty'){
      var hi = heldItems();
      if(hi && hi[it.id] && hi[it.id].qty > 0 && hi[it.id].value)
        return Math.round((hi[it.id].value / hi[it.id].qty) * 10000) / 10000;
    } else if(mode === 'here'){
      var v = VALMAP[it.id], b = balOf(it.id), l = locId();
      var val = v ? (l > 0 ? v[l] : v[0]) : undefined;
      if(val !== undefined && b > 0.0005) return Math.round((val / b) * 10000) / 10000;
    }
    return num(it.rate);
  }

  function locName(){
    var s = document.querySelector('select[name="location_id"]');
    return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].text : 'this location';
  }
  function partyName(){
    if(!pSel || !+pSel.value) return '';
    var o = pSel.options[pSel.selectedIndex];
    return o ? String(o.text).split('   —   ')[0] : '';
  }

  /* Which items may be named, for the transaction type on screen.
     Code order, always. Quantity cannot be compared across units —
     14,000 buttons is not "more" than 1,840 metres of fabric, and
     ranking that way puts the cheapest item at the top of every list.
     When you type, the best match rises; ties fall back to code. */
  function itemRowsFor(q, showAll){
    var mode = pickMode(), out = [], hidden = 0;
    ITEMS.forEach(function(it){
      var sc = LOV.score(q, it.code, it.name, it.grp);
      if(sc <= 0) return;
      var b = mode === 'all' ? null : balOf(it.id);
      if(mode !== 'all' && !(b > 0.0005)){ if(!showAll){ hidden++; return; } }
      out.push({ it:it, bal:b, sc:sc, rate:rateFor(it) });
    });
    out.sort(function(a, b){
      if(a.sc !== b.sc) return b.sc - a.sc;
      return a.it.code.localeCompare(b.it.code);
    });
    return [out, hidden];
  }

  LOV.register('item', {
    cols: [
      { label:'Code',        w:'86px',            cls:'cd', get:function(r,q){ return LOV.hl(r.it.code, q); } },
      { label:'Description', w:'minmax(130px,1fr)',cls:'nm', get:function(r,q){ return LOV.hl(r.it.name, q); } },
      { label:'Group',       w:'78px',            cls:'gg', get:function(r){ return LOV.esc(r.it.grp); } },
      { label:'Available',   w:'76px', align:'r', cls:'nu',
        style:function(r){ return 'font-weight:700;color:' + (r.bal === null ? '#8a97ab' : r.bal > 0 ? '#16a34a' : '#c0293f'); },
        get:function(r){ return r.bal === null ? '—' : LOV.q3(r.bal); } },
      { label:'UOM',         w:'44px',            cls:'gg', get:function(r){ return LOV.esc(r.it.uom); } },
      { label:'Rate',        w:'68px', align:'r', cls:'nu', get:function(r){ return LOV.m2(r.rate); } }
    ],
    moreLabel: 'not available here',
    lessLabel: 'only what is available',
    title: function(){
      var m = pickMode();
      if(m === 'all') return 'Select item — receiving, all items';
      if(m === 'atparty') return 'Select item — held by ' + (partyName() || '…');
      return 'Select item — in stock at ' + locName();
    },
    empty: function(f, q){
      var m = pickMode();
      if(m === 'atparty') return partyName()
        ? 'They are holding nothing that matches.'
        : 'Choose the party first — the list is what they are holding.';
      if(m === 'here') return q ? 'Nothing matching is in stock at ' + locName() + '.'
                                : 'Nothing is in stock at ' + locName() + '.';
      return 'No item matches that.';
    },
    rows: function(f, q, showAll, cb){ var r = itemRowsFor(q, showAll); cb(r[0], r[1]); },
    revert: function(f){ var box = f.closest('.matbox'); if(box) sync(box); },
    pick: function(f, r){
      var tr = f.closest('tr'), sel = tr.querySelector('.matsel');
      sel.value = r.it.id;
      f.value = r.it.code + ' · ' + r.it.name;
      tr.dataset.item = r.it.id;
      sel.dispatchEvent(new Event('change', {bubbles:true}));   // fills uom, rate, description
      var lot = tr.querySelector('.lot'); if(lot) lot.value = '';
      var q = tr.querySelector('.qty'); if(q) q.focus();
    }
  });

  /* Lots for the row's item. On a return pass they come from what the
     party is holding; otherwise from our own floor at this location.
     A receipt has no lot list at all — the number comes off the
     supplier's challan and is typed. */
  LOV.register('lot', {
    cols: [
      { label:'Lot / roll', w:'110px',            cls:'cd', get:function(r,q){ return LOV.hl(r.lot_no || '(no lot)', q); } },
      { label:'Balance',    w:'80px', align:'r',  cls:'nu',
        style:function(){ return 'color:#16a34a;font-weight:700'; },
        get:function(r){ return LOV.q3(r.bal); } },
      { label:'In since',   w:'1fr',              cls:'gg',
        get:function(r,q,i){ return LOV.esc(r.first_in || r.since || ''); } },
      { label:'Rate',       w:'72px', align:'r',  cls:'nu', get:function(r){ return LOV.m2(r.rate || 0); } }
    ],
    title: function(){ return 'Select lot — oldest first'; },
    empty: function(){
      var m = pickMode();
      if(m === 'all') return 'The lot number is typed in on a receipt — take it from their challan.';
      if(m === 'atparty' && !partyName()) return 'Choose the party first.';
      return 'No lot of this item is available here. Leave the box empty to take from any lot.';
    },
    rows: function(f, q, showAll, cb){
      lotsFor(f, function(lots){
        var out = lots.filter(function(l){
          return !q.trim() || LOV.norm(l.lot_no).indexOf(LOV.norm(q)) >= 0;
        });
        cb(out, 0);
      });
    },
    pick: function(f, r){
      var tr = f.closest('tr');
      f.value = r.lot_no || '';
      var rt = tr.querySelector('.rate');
      if(rt && r.rate > 0) rt.value = Number(r.rate).toFixed(4);
      tot(); refreshRow(tr); stockCheck();
      var q = tr.querySelector('.qty'); if(q) q.focus();
    }
  });

  function lotsFor(field, done){
    var tr = field.closest('tr'), sel = tr.querySelector('.matsel');
    var mid = sel ? +sel.value : 0;
    if(!mid || pickMode() === 'all'){ done([]); return; }
    if(pickMode() === 'atparty'){
      var pid = pSel ? +pSel.value : 0;
      if(!pid){ done([]); return; }
      fetch('inv_gate.php?ajax=held&party_id=' + pid + '&kind=' + holdKind() + '&material_id=' + mid)
        .then(function(r){ return r.json(); })
        .then(function(d){ done((d && d.ok && d.lots) ? d.lots : []); })
        .catch(function(){ done([]); });
      return;
    }
    var key = mid + '|' + locId() + '|' + ownOf(), d = ONHAND[key];
    if(d){ done(d.lots || []); return; }
    fetch('inv_gate.php?ajax=onhand&material_id=' + mid + '&location_id=' + locId() + '&own=' + ownOf())
      .then(function(r){ return r.json(); })
      .then(function(dd){
        if(dd && dd.ok){ ONHAND[key] = dd; TOL = dd.tolerance_pct || 10; ISADMIN = !!dd.is_admin; }
        done((dd && dd.lots) ? dd.lots : []);
      }).catch(function(){ done([]); });
  }

  /* ---- contract on the line ----------------------------------------
     One gate pass can carry lines from several contracts, so the
     contract cannot live on the header alone. It stays on the header —
     that is still where a whole pass against one contract is said — and
     a line that names nothing follows it. What is new is that a line MAY
     name its own, and then that is what counts.

     The list is every open line the PARTY has, never every contract in
     the company. Balance is what is left after posted gate passes only;
     drafts are not deliveries. */
  var CTLBL = <?= json_encode($CTLBL ?? []) ?>;
  var CLINES = null, CLPARTY = -1;

  function clParty(){ return pSel ? +pSel.value : 0; }
  function clLoad(cb){
    var pid = clParty();
    if(!pid){ CLINES = []; CLPARTY = 0; cb([]); return; }
    if(CLPARTY === pid && CLINES){ cb(CLINES); return; }
    fetch('inv_gate.php?ajax=plines&party_id=' + pid)
      .then(function(r){ return r.json(); })
      .then(function(d){ CLINES = (d && d.ok && d.lines) ? d.lines : []; CLPARTY = pid; cb(CLINES); })
      .catch(function(){ CLINES = []; CLPARTY = pid; cb([]); });
  }
  function clById(id){
    if(!CLINES) return null;
    for(var i = 0; i < CLINES.length; i++) if(+CLINES[i].id === +id) return CLINES[i];
    return null;
  }
  /* The row's chosen line, and what the box should read. Both are driven
     off the hidden fields, never off the text, so a half-typed search can
     never be mistaken for a choice. */
  function clOf(tr){
    var ci = tr.querySelector('.citem');
    return (ci && +ci.value) ? clById(+ci.value) : null;
  }
  function clPaint(tr){
    var f = tr.querySelector('.cline'), cid = tr.querySelector('.cid');
    if(!f) return;
    var has = !!(cid && +cid.value), l = clOf(tr);
    if(l) f.value = l.contract_no;        // the live list is the freshest truth
    else if(!has) f.value = '';           // nothing stored, so nothing to show
    /* Stored, but not in this party's open list — a contract since closed,
       most often. The server rendered its number and that is still the
       truth about this line, so it is left alone. Blanking it here would
       make a linked line look unlinked and invite someone to "fix" it. */
    f.classList.toggle('set', has);
  }
  function clClear(tr){
    var cid = tr.querySelector('.cid'), ci = tr.querySelector('.citem'), f = tr.querySelector('.cline');
    if(cid) cid.value = ''; if(ci) ci.value = '';
    if(f){ f.value = ''; f.classList.remove('set'); f.classList.remove('over'); }
    clWarn();
  }

  /* Over the contract balance is a FACT, not an error. The goods came
     through the gate; refusing to write them down would only mean the
     pass stops matching the gate register. So it is said, once, under the
     table — and the quantity is saved exactly as typed. */
  /* One strip, two things to say: a standing message (the party changed
     and contracts were dropped) and the live over-balance list. They
     share the strip because they are the same kind of news, and they are
     assembled in one place so neither can overwrite the other — which is
     exactly what happened when each wrote to the box directly. */
  var CLMSG = '';
  function clWarn(){
    var box = document.getElementById('cWarn'); if(!box) return;
    var bad = [];
    lines().forEach(function(tr, i){
      var f = tr.querySelector('.cline'); if(f) f.classList.remove('over');
      var l = clOf(tr); if(!l) return;
      var q = num((tr.querySelector('.qty') || {}).value);
      if(q > l.balance + 0.0005){
        if(f) f.classList.add('over');
        bad.push('line ' + (i + 1) + ' — ' + l.contract_no + ' has '
               + Number(l.balance).toLocaleString('en-US',{maximumFractionDigits:3})
               + ' left, this pass takes '
               + q.toLocaleString('en-US',{maximumFractionDigits:3}));
      }
    });
    var parts = [];
    if(CLMSG) parts.push(CLMSG);
    if(bad.length) parts.push('<b>Over the contract balance on ' + bad.length + ' line(s).</b> '
      + 'This is allowed and the pass will save exactly as typed — it is here so it is '
      + 'not a surprise later on the contract.<br>' + bad.join('<br>'));
    if(!parts.length){ box.style.display = 'none'; box.innerHTML = ''; return; }
    box.style.display = '';
    box.innerHTML = parts.join('<hr style="border:none;border-top:1px solid rgba(217,119,6,.25);margin:9px 0">');
  }

  function clRows(f, q, showAll, cb){
    clLoad(function(all){
      var tr = f.closest('tr'), out = [], hidden = 0;
      /* Taking the contract off a line has to be as easy as putting it
         on, and "clear the box and hope" is not a control. So it is a row
         in the list — but the LAST row, never the first.

         First was the obvious place and it is the wrong one. Opening the
         box on a line that already has a contract selects its text, so
         the highlighted row is row 0 and Enter takes it; with the clear
         row first, the reflex of opening and pressing Enter would WIPE
         the contract instead of leaving it alone. At the bottom it is
         still one arrow key away and it cannot be hit by accident. */
      var cid = tr.querySelector('.cid');
      var offerClear = !!(cid && +cid.value);
      all.forEach(function(l){
        var sc = LOV.score(q, l.contract_no, l.item, l.description + ' ' + l.code + ' ' + l.ctype);
        if(sc <= 0) return;
        if(l.complete && !showAll){ hidden++; return; }
        out.push({ l:l, sc:sc });
      });
      out.sort(function(a, b){
        if(a.sc !== b.sc) return b.sc - a.sc;
        if(a.l.contract_no !== b.l.contract_no) return a.l.contract_no.localeCompare(b.l.contract_no);
        return a.l.item.localeCompare(b.l.item);
      });
      if(offerClear) out.push({ clear:true });
      cb(out, hidden);
    });
  }

  LOV.register('cline', {
    cols: [
      { label:'Contract', w:'112px', cls:'cd',
        get:function(r,q){ return r.clear ? '<i>— no contract —</i>' : LOV.hl(r.l.contract_no, q); } },
      { label:'Type', w:'86px', cls:'gg',
        get:function(r){ return r.clear ? '' : LOV.esc(CTLBL[r.l.ctype] || r.l.ctype); } },
      { label:'Item', w:'minmax(150px,1fr)', cls:'nm',
        get:function(r,q){ return r.clear ? '<span style="color:#8a97ab">follow the contract on the pass header</span>'
                                          : LOV.hl(r.l.item || r.l.description, q); } },
      { label:'Balance', w:'84px', align:'r', cls:'nu',
        style:function(r){ return r.clear ? '' : 'font-weight:700;color:' + (r.l.complete ? '#8a97ab' : '#16a34a'); },
        get:function(r){ return r.clear ? '' : LOV.q3(r.l.balance) + (r.l.complete ? ' <span style="font-size:9.5px">done</span>' : ''); } },
      { label:'UOM', w:'46px', cls:'gg', get:function(r){ return r.clear ? '' : LOV.esc(r.l.uom); } },
      { label:'Rate', w:'70px', align:'r', cls:'nu', get:function(r){ return r.clear ? '' : LOV.m2(r.l.rate); } }
    ],
    moreLabel: 'already completed',
    lessLabel: 'hide completed lines',
    title: function(){ return 'Contract line — ' + (partyName() || 'choose the party first'); },
    empty: function(f, q){
      if(!clParty()) return 'Choose the party first — the list is that party’s contracts.';
      if(!CLINES || !CLINES.length) return 'This party has no draft or active contract with lines on it.';
      return q ? 'No contract line of theirs matches that.' : 'Nothing to show.';
    },
    rows: clRows,
    /* Typed something, then tabbed away without choosing: put the box back
       to what is actually stored. */
    revert: function(f){ var tr = f.closest('tr'); if(tr) clPaint(tr); },
    pick: function(f, r){
      var tr = f.closest('tr');
      if(r.clear){ clClear(tr); var qc = tr.querySelector('.qty'); if(qc) qc.focus(); return; }
      var l = r.l, det = detailOf(tr);
      CLMSG = '';                       // they have acted on it; stop repeating it
      var cid = tr.querySelector('.cid'), ci = tr.querySelector('.citem');
      if(cid) cid.value = l.contract_id;
      if(ci)  ci.value  = l.id;
      f.value = l.contract_no;
      f.classList.add('set');

      /* The contract's own item, wording, unit and agreed rate come with
         it — that is the point of naming the contract rather than typing
         the line again. Anything already filled in by hand is left alone;
         the operator's typing outranks a default. */
      var sel = tr.querySelector('.matsel');
      if(sel && l.material_id && (!sel.value || sel.value === '0')){
        sel.value = String(l.material_id);
        tr.dataset.item = String(l.material_id);
        sel.dispatchEvent(new Event('change', {bubbles:true}));
        syncAll();
      }
      var ps = det ? det.querySelector('select[name*="[product_id]"]') : null;
      if(ps && l.product_id && (!ps.value || ps.value === '0')) ps.value = String(l.product_id);
      var u = tr.querySelector('.uom'); if(u && !u.value && l.uom) u.value = l.uom;
      var rt = tr.querySelector('.rate'); if(rt && !num(rt.value) && l.rate > 0) rt.value = Number(l.rate).toFixed(4);
      var d = det ? det.querySelector('.desc') : null;
      if(d && !d.value && l.description) d.value = l.description;

      /* Straight to the quantity — that is the only thing left to say. */
      var qty = tr.querySelector('.qty');
      if(qty){ qty.focus(); if(qty.select) qty.select(); }
      tot(); clWarn();
      if(IS_OUT){ refreshRow(tr); stockCheck(); }
    }
  });

  LOV.attach(tb);

  /* Show whatever the select already holds, so a saved line reads as its
     item and not as an empty box. */
  function sync(box){
    var sel = box.querySelector('.matsel'), q = box.querySelector('.matq');
    if(!sel || !q) return;
    sel.style.display = 'none';
    q.style.display = '';
    q.value = (sel.value && sel.value !== '0' && sel.options[sel.selectedIndex])
            ? sel.options[sel.selectedIndex].text : '';
  }
  function syncAll(){ tb.querySelectorAll('.matbox').forEach(sync); }

  /* per-line detail strip */
  tb.addEventListener('click', function(e){
    var b = e.target.closest('.dtog'); if(!b) return;
    var w = b.parentNode.querySelector('.dwrap');
    var open = w.style.display !== 'none';
    w.style.display = open ? 'none' : '';
    b.textContent = (open ? '▾' : '▴') + b.textContent.slice(1);
  });

  /* ---- contracts: this party, this movement, nothing else ----------
     Two faults were fixed here. The list used to fetch only status
     'active', so a contract saved five minutes ago — which saves as
     DRAFT — was simply absent with nothing to say why. And it GROUPED
     rather than removed, by direction rather than by transaction type,
     so raising a Sale offered you a purchase contract from a supplier.

     Every transaction type already declares the contract type it belongs
     to, in inv_gate_types(). The form now reads it. A Sale can only ever
     be against a sales contract; a Purchase return against a purchase
     contract; a Sample against nothing at all. */
  var pSel  = document.querySelector('select[name="party_id"]');
  var cHint = document.getElementById('cHint');
  var CONTRACTS = (function(){
    if(!cSel) return [];
    return [].slice.call(cSel.options).filter(function(o){ return o.value && o.value !== '0'; })
      .map(function(o){
        return { id:o.value, label:o.dataset.label || o.text.trim(),
                 party:+(o.dataset.party || 0), type:o.dataset.type || '',
                 status:o.dataset.status || '' };
      });
  })();
  var CTYPE = <?= json_encode(array_map(fn($t) => $t['contract'], inv_gate_types($dir))) ?>;
  function ctypeOf(){ return CTYPE[ts.value] || ''; }

  function fillContracts(){
    if(!cSel) return;
    var keep = cSel.value, want = ctypeOf(), party = pSel ? +pSel.value : 0;

    /* Not under contract at all — say so and take the box out of play,
       rather than offering a list that cannot be right. */
    if(!want){
      cSel.innerHTML = '<option value="0">— not under contract —</option>';
      cSel.value = '0'; cSel.disabled = true;
      if(cHint){ cHint.textContent = 'A sample, transfer or write-off is never under a contract.';
                 cHint.style.color = '#8a97ab'; }
      return;
    }
    cSel.disabled = false;

    var mine = CONTRACTS.filter(function(c){
      return c.type === want && (!party || c.party === party);
    });

    var html = '<option value="0">— none, direct —</option>';
    /* A contract already on this pass is never filtered away — that is
       how the party box used to lose its value, and the same trap is
       here. It is listed first, marked, and stays selected. */
    var kept = keep && keep !== '0' ? keep : '';
    if(kept && !mine.some(function(c){ return String(c.id) === String(kept); })){
      var was = CONTRACTS.filter(function(c){ return String(c.id) === String(kept); })[0];
      html += '<optgroup label="On this pass"><option value="' + kept + '">'
            + (was ? was.label : 'Contract #' + kept) + '</option></optgroup>';
    }
    html += mine.map(function(c){
      return '<option value="' + c.id + '"' + (c.status === 'draft' ? ' disabled' : '') + '>'
           + c.label + '</option>';
    }).join('');
    cSel.innerHTML = html;
    cSel.value = keep;

    if(!cHint) return;
    var drafts = mine.filter(function(c){ return c.status === 'draft'; }).length;
    var live   = mine.length - drafts;
    var word   = want.replace('_', ' ');
    if(!party){
      cHint.textContent = 'Choose the party — only their ' + word + ' contracts will be offered.';
      cHint.style.color = '#8a97ab';
    } else if(drafts && !live){
      cHint.innerHTML = drafts + ' ' + word + ' contract(s) exist for this party but are still '
        + '<b>drafts</b>, so they cannot be chosen. Open <a href="inv_contracts.php" target="_blank" '
        + 'style="color:#9a3412;font-weight:800">Contracts</a>, press Activate, and come back.';
      cHint.style.color = '#9a3412';
    } else if(live){
      cHint.textContent = live + ' ' + word + ' contract(s) for this party'
        + (drafts ? ', and ' + drafts + ' still in draft' : '') + '. Nothing else is offered.';
      cHint.style.color = '#0b5f8a';
    } else {
      cHint.textContent = 'No ' + word + ' contract on file for this party — direct is fine.';
      cHint.style.color = '#8a97ab';
    }
  }

  /* Picking the contract fills the party in, when the party box is still
     empty. The two facts belong together and only one of them should have
     to be typed. */
  function partyFromContract(){
    if(!cSel || !pSel || !cSel.value || cSel.value === '0') return;
    if(+pSel.value) return;
    var c = CONTRACTS.filter(function(x){ return x.id === cSel.value; })[0];
    if(c && c.party && [].slice.call(pSel.options).some(function(o){ return +o.value === c.party; })){
      pSel.value = c.party;
      fillContracts();
    }
  }

  if(cSel) cSel.addEventListener('change', partyFromContract);

  /* ---- the cascade: type decides party, party decides the rest ------ */
  /* Each transaction type already declares, in inv_gate_types(), which
     contract type it belongs to and whose goods are moving. Those two
     facts are enough to narrow every list below it — the form simply
     never read them before. */
  var HOLD = <?= json_encode($holdMap ?: ['jobworker'=>new stdClass(),'custody'=>new stdClass()]) ?>;
  var UNATTRIB = <?= json_encode($unattributed ?: ['jobworker'=>0,'custody'=>0]) ?>;
  var CANMAKE = <?= (inv_perm('gate') || inv_perm('master')) ? 'true' : 'false' ?>;

  /* party class per transaction type. 'holder' and 'custody' mean "only
     those who are actually holding something", which is the rule you
     asked for on a job work return. */
  var PTYPE = {
    purchase:'supplier', sales_return:'customer', jobwork_return:'holder',
    jobwork_received:'customer', transfer_in:'any', sample_in:'any', other_in:'any',
    sale:'customer', purchase_return:'supplier', jobwork_issue:'jobworker',
    jobwork_delivered:'custody', transfer_out:'any', sample_out:'any', other_out:'any'
  };
  /* the six real trade types — free text is not honest on these */
  var TRADE = ['purchase','sales_return','jobwork_return','jobwork_received',
               'sale','purchase_return','jobwork_issue','jobwork_delivered'];

  var pText = document.getElementById('pText'), pTextWrap = document.getElementById('pTextWrap'),
      pNewWrap = document.getElementById('pNewWrap'), pHint = document.getElementById('pHint'),
      pLabel = document.getElementById('plabel');
  var ALLP = pSel ? [].slice.call(pSel.options).filter(function(o){ return o.value && o.value !== '0'; })
                     .map(function(o){ return {id:o.value, name:o.dataset.name || o.text, t:o.dataset.ptype || 'supplier'}; })
                  : [];

  function pclass(){ return PTYPE[ts.value] || 'any'; }
  function holdKind(){ return pclass() === 'custody' ? 'custody' : 'jobworker'; }
  function heldBy(id){
    var m = HOLD[holdKind()] || {};
    return m[id] ? m[id].qty : 0;
  }

  function fillParties(){
    if(!pSel) return;
    var cls = pclass(), keep = pSel.value;
    var list = ALLP.filter(function(p){
      if(cls === 'any') return true;
      if(cls === 'holder')  return (p.t === 'jobworker' || p.t === 'both') && heldBy(p.id) > 0;
      if(cls === 'custody') return (p.t === 'customer'  || p.t === 'both') && heldBy(p.id) > 0;
      if(cls === 'supplier')  return p.t === 'supplier'  || p.t === 'both';
      if(cls === 'customer')  return p.t === 'customer'  || p.t === 'both';
      if(cls === 'jobworker') return p.t === 'jobworker' || p.t === 'both';
      return true;
    });
    var holding = (cls === 'holder' || cls === 'custody');

    /* THE VALUE ON THE PASS IS NEVER DROPPED.

       This used to end "pSel.value = keep; if it didn't stick, use 0" —
       so opening an older pass whose party does not match today's filter
       silently reset the box to "not listed", and saving then erased the
       party from the document. Filtering decides what is EASY TO PICK.
       It must never decide what is allowed to stay. */
    var kept = keep && keep !== '0' ? keep : '';
    var inList = !kept || list.some(function(p){ return String(p.id) === String(kept); });
    var head = '<option value="0">— not listed —</option>';
    if(kept && !inList){
      var was = ALLP.filter(function(p){ return String(p.id) === String(kept); })[0];
      head += '<option value="' + kept + '">' + (was ? was.name : 'Party #' + kept)
            + '   —   on this pass</option>';
    }
    pSel.innerHTML = head + list.map(function(p){
      return '<option value="' + p.id + '">' + p.name
           + (holding ? '   —   holding ' + q3(heldBy(p.id)) : '') + '</option>';
    }).join('');
    pSel.value = keep;

    // free text only where a party record would be noise
    var trade = TRADE.indexOf(ts.value) >= 0;
    if(pTextWrap) pTextWrap.style.display = trade ? 'none' : '';
    if(pNewWrap)  pNewWrap.style.display  = (trade && CANMAKE) ? '' : 'none';
    if(trade && pText) pText.value = '';

    if(pLabel) pLabel.textContent = IS_OUT ? 'Party / destination' : 'Received from';
    if(pHint){
      var un = UNATTRIB[holdKind()] || 0;
      if(holding && !list.length){
        pHint.textContent = cls === 'holder'
          ? 'No mill is holding any of our material, so there is nothing to receive back.'
          : 'We are not holding any customer goods.';
        pHint.style.color = '#9a3412';
      } else if(holding){
        pHint.textContent = list.length + ' ' + (cls === 'holder' ? 'mill(s)' : 'customer(s)')
          + ' currently holding goods.' + (un > 0 ? ' ' + q3(un) + ' more is there with no gate pass behind it.' : '');
        pHint.style.color = '#0b5f8a';
      } else if(cls === 'any'){
        pHint.textContent = 'Anyone — and a typed name is fine on this type.';
        pHint.style.color = '#8a97ab';
      } else {
        pHint.textContent = list.length + ' ' + cls + '(s) on file.';
        pHint.style.color = '#8a97ab';
      }
    }
  }

  /* items and lots held by the chosen party, fetched once per party */
  var HELD = {};
  function loadHeld(cb){
    var cls = pclass();
    if(cls !== 'holder' && cls !== 'custody'){ cb(null); return; }
    var pid = pSel ? +pSel.value : 0; if(!pid){ cb(null); return; }
    var key = holdKind() + '|' + pid;
    if(HELD[key]){ cb(HELD[key]); return; }
    fetch('inv_gate.php?ajax=held&party_id=' + pid + '&kind=' + holdKind())
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d.ok){ HELD[key] = d; cb(d); } else cb(null); })
      .catch(function(){ cb(null); });
  }

  /* Changing the party invalidates every contract already on a line —
     those contracts belong to the party you just moved away from, and
     leaving them would book this pass against somebody else's balance.
     They are dropped, and dropping them is SAID: a cleared field that
     explains nothing is how people lose trust in a form. */
  function clRecheck(){
    CLINES = null; CLPARTY = -1;
    var held = [];
    lines().forEach(function(tr, i){
      var cid = tr.querySelector('.cid'), f = tr.querySelector('.cline');
      if(cid && +cid.value){ held.push(i + 1 + (f && f.value ? ' (' + f.value + ')' : '')); clClear(tr); }
    });
    CLMSG = held.length
      ? '<b>Contract cleared on ' + held.length + ' line(s): ' + held.join(', ') + '.</b> '
        + 'Those contracts belong to the party that was on this pass before. '
        + 'Pick the contract again from the new party’s list — the quantities are untouched.'
      : '';
    clLoad(function(){ lines().forEach(clPaint); clWarn(); });
    clWarn();                    // say it now, not when the fetch comes back
  }

  if(pSel) pSel.addEventListener('change', function(){
    fillContracts(); clRecheck();
    loadHeld(function(){ syncAll(); refreshAll(); });
  });
  ts.addEventListener('change', function(){
    fillParties(); fillContracts();
    loadHeld(function(){ syncAll(); refreshAll(); });
  });

  /* ---- + Add new party --------------------------------------------- */
  var pNewBtn = document.getElementById('pNewBtn');
  if(pNewBtn) pNewBtn.addEventListener('click', function(){
    var cls = pclass();
    var guess = cls === 'holder' ? 'jobworker' : (cls === 'any' ? 'supplier' : cls);
    var name = prompt('Name of the new ' + (guess === 'jobworker' ? 'job worker / mill'
                    : guess === 'customer' ? 'customer' : 'supplier') + ':');
    if(!name || !name.trim()) return;
    var fd = new FormData();
    fd.append('ajax','newparty'); fd.append('_csrf', CSRF);
    fd.append('name', name.trim()); fd.append('party_type', guess);
    fetch('inv_gate.php', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(!d.ok){ alert(d.error || 'Could not add the party.'); return; }
        ALLP.push({id:String(d.id), name:d.name, t:d.party_type});
        ALLP.sort(function(a,b){ return a.name.localeCompare(b.name); });
        fillParties();
        pSel.value = String(d.id);
        fillContracts();
        if(pHint){
          pHint.textContent = d.existing ? (d.note || 'Already existed — selected it.')
                                         : d.name + ' added as ' + d.code + '.';
          pHint.style.color = '#1d6b46';
        }
      }).catch(function(){ alert('Could not reach the server.'); });
  });

  /* ---- More details ------------------------------------------------- */
  var moreBtn = document.getElementById('moreBtn'), moreWrap = document.getElementById('moreWrap'),
      moreCar = document.getElementById('moreCar');
  if(moreBtn) moreBtn.addEventListener('click', function(){
    var open = moreWrap.style.display !== 'none';
    moreWrap.style.display = open ? 'none' : '';
    if(moreCar) moreCar.textContent = open ? '▾' : '▴';
  });

  fillParties(); fillContracts();
  note(); taxRule(); tot(); syncAll();
  /* The Contract boxes were rendered by PHP with the right text already,
     so nothing has to wait for this fetch to be readable. It runs because
     the OVER-BALANCE strip needs the balances, and because the box has to
     know which stored line it is showing before the operator opens it.
     clPaint is only allowed to run once the list is actually in hand —
     before that it would look at an empty list, find no line, and wipe a
     field the server filled in correctly. */
  lines().forEach(function(tr){
    var cid = tr.querySelector('.cid'), f = tr.querySelector('.cline');
    if(f && cid && +cid.value) f.classList.add('set');
  });
  clLoad(function(){ lines().forEach(clPaint); clWarn(); });
  loadHeld(function(){ syncAll(); refreshAll(); });
})();
</script>

<?php elseif ($doc): $T = $TYPES[$doc['txn_type']] ?? ['label' => $doc['txn_type'], 'own' => 'own']; ?>
<div class="ig-card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div>
      <h2 style="font-size:17px;margin:0;font-weight:800;font-family:monospace"><?= e($doc['gate_no']) ?></h2>
      <p style="color:#8a97ab;font-size:12.5px;margin:5px 0 0"><?= e($T['label']) ?> · <?= e($doc['gate_date']) ?> <?= e(substr((string)$doc['gate_time'], 0, 5)) ?> · <?= e(inv_location_name((int)$doc['location_id'])) ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="ig-pill st-<?= e($doc['status']) ?>"><?= e(ucfirst($doc['status'])) ?></span>
      <a class="ig-btn sec" href="inv_gate_print.php?id=<?= (int)$doc['id'] ?>" target="_blank">Print pass</a>
      <?php if ($canEdit && !$readOnly): ?><a class="ig-btn sec" href="?id=<?= (int)$doc['id'] ?>&amp;edit=1">Edit</a><?php endif; ?>
      <a class="ig-btn sec" href="inv_gate.php?dir=<?= e($doc['direction']) ?>">Register</a>
    </div>
  </div>

  <?php if ($T['own'] === 'customer'): ?>
    <div class="ig-note warn" style="margin-top:14px"><b>Customer-owned material.</b> These quantities are tracked because you are responsible for them, but they are never valued as your stock and never appear in your inventory value.</div>
  <?php endif; ?>

  <?php if ($cInfo && $cInfo['qty'] > 0):
    $after = $cInfo['bal'] - ($doc['status'] === 'posted' ? 0 : $cInfo['this']);
    $over  = $doc['status'] !== 'posted' && $cInfo['this'] > $cInfo['bal'] + 0.0001; ?>
    <div class="ig-grid" style="margin-top:16px;grid-template-columns:repeat(4,1fr)">
      <div class="ig-kpi"><div class="l">Contract qty</div><div class="v"><?= number_format($cInfo['qty'], 2) ?></div></div>
      <div class="ig-kpi"><div class="l">Already <?= $doc['direction'] === 'in' ? 'received' : 'dispatched' ?></div><div class="v"><?= number_format($cInfo['done'], 2) ?></div></div>
      <div class="ig-kpi" style="background:rgba(14,168,201,.07)"><div class="l">This pass</div><div class="v" style="color:#0b7f9b"><?= number_format($cInfo['this'], 2) ?></div></div>
      <div class="ig-kpi" style="<?= $over ? 'background:rgba(224,67,93,.08)' : '' ?>"><div class="l">Balance after</div><div class="v" style="<?= $over ? 'color:#c0293f' : '' ?>"><?= number_format($after, 2) ?></div></div>
    </div>
    <?php if ($over): ?>
      <div class="ig-note bad" style="margin-top:14px"><b>This pass exceeds the contract balance by <?= number_format($cInfo['this'] - $cInfo['bal'], 2) ?>.</b>
        <?= $canPost && is_admin() ? ' As an admin you may still post it, but record why in the remarks first — it is written to the audit log.' : ' Ask an admin to review before posting.' ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="ig-grid" style="margin-top:16px">
    <div><div class="ig-lbl">Party</div><div style="font-size:12.5px;font-weight:600"><?= e($doc['party_name'] ?: ($doc['party_text'] ?: '—')) ?></div></div>
    <div><div class="ig-lbl">Vehicle</div><div style="font-size:12.5px;font-family:monospace"><?= e($doc['vehicle_no'] ?: '—') ?></div></div>
    <div><div class="ig-lbl">Challan</div><div style="font-size:12.5px;font-family:monospace"><?= e($doc['challan_no'] ?: '—') ?></div></div>
    <div><div class="ig-lbl">Contract</div><div style="font-size:12.5px"><?= e($doc['contract_no'] ?: 'Direct / none') ?></div></div>
    <div><div class="ig-lbl">Order</div><div style="font-size:12.5px"><?= e($doc['pi_no'] ?: '—') ?></div></div>
    <div><div class="ig-lbl">Verified by</div><div style="font-size:12.5px"><?= e($doc['verified_by'] ?: '—') ?></div></div>
    <div><div class="ig-lbl">Security</div><div style="font-size:12.5px"><?= e($doc['security_by'] ?: '—') ?></div></div>
    <div><div class="ig-lbl">Purpose</div><div style="font-size:12.5px"><?= e($doc['purpose'] ?: '—') ?></div></div>
  </div>

  <?php
    /* The Contract column appears only when a line actually carries one of
       its own. On the ordinary pass — one contract, named once on the
       header above — it would be a column of the same number repeated, and
       a column that says nothing still costs width on every screen and
       every print. When it does appear it is because the pass genuinely
       spans more than the header can say, and then it is the most
       important column on the table. */
    $anyLineContract = false;
    foreach ($lines as $L) if ((int)($L['lcid'] ?? 0) > 0) { $anyLineContract = true; break; }
  ?>
  <div style="overflow-x:auto;margin-top:18px"><table class="ig-tbl">
    <thead><tr><th>Item</th><?php if ($anyLineContract): ?><th>Contract</th><?php endif; ?><th>Lot</th><th class="r">Quantity</th><th>UOM</th><th class="r">Rate</th><th>Packing</th><th class="r">Stock now</th></tr></thead>
    <tbody>
    <?php $tq = 0; foreach ($lines as $L): $tq += (float)$L['qty'];
      $bal = inv_balance($L['material_id'] ? (int)$L['material_id'] : null, $L['product_id'] ? (int)$L['product_id'] : null, null, $L['ownership']); ?>
      <tr>
        <td style="font-weight:600"><?= e($L['mcode'] ? $L['mcode'] . ' · ' . $L['mname'] : ($L['pname'] ?: ($L['description'] ?: '—'))) ?></td>
        <?php if ($anyLineContract): ?>
          <?php /* A line with none is not blank — it is counted against the
                   header contract, and the screen says so in grey rather
                   than leaving the reader to guess. */ ?>
          <td style="font-family:monospace;font-size:11.5px"><?= (int)($L['lcid'] ?? 0) > 0
            ? '<b>' . e((string)$L['lcno']) . '</b>'
            : ($doc['contract_no'] ? '<span style="color:#8a97ab">' . e((string)$doc['contract_no']) . ' — from the header</span>' : '<span style="color:#8a97ab">—</span>') ?></td>
        <?php endif; ?>
        <td style="font-family:monospace"><?= e($L['lot_no'] ?: '—') ?></td>
        <td class="r"><b><?= number_format((float)$L['qty'], 3) ?></b></td>
        <td style="font-family:monospace"><?= e($L['uom'] ?: '') ?></td>
        <td class="r"><?= number_format((float)$L['rate'], 2) ?></td>
        <td><?= e($L['packing'] ?: '—') ?></td>
        <td class="r" style="color:#5a6b82"><?= number_format($bal, 3) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="<?= $anyLineContract ? 3 : 2 ?>" style="text-align:right;font-weight:800">Total</td><td class="r" style="font-weight:800"><?= number_format($tq, 3) ?></td><td colspan="4"></td></tr></tfoot>
  </table></div>

  <?php if ($doc['remarks']): ?><p style="font-size:12.5px;color:#5a6b82;margin:14px 0 0"><b>Remarks:</b> <?= e($doc['remarks']) ?></p><?php endif; ?>

  <?php $links = inv_gate_links((int)$doc['id']); ?>
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($doc['status'] === 'draft' || $doc['status'] === 'verified'): ?>
      <?php if ($canPost): ?>
        <form method="post" onsubmit="return confirm('Post this pass? Stock will be updated and the document locked.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <button class="ig-btn go" type="submit">Post to stock</button>
        </form>
      <?php else: ?>
        <span class="ig-note info" style="margin:0">Saved, but not posted. Someone with posting permission must post it before stock changes.</span>
      <?php endif; ?>
      <?php /* Nothing has moved, so there is nothing to undo — a plain
               delete, no reason, no ceremony. */
      if ($canEdit && !$links['jobwork_bills']): ?>
        <form method="post" style="margin-left:auto"
              onsubmit="return confirm('Delete <?= e($doc['gate_no']) ?>? It has not moved any stock, so nothing in the ledger changes.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <input type="hidden" name="direction" value="<?= e($doc['direction']) ?>">
          <button class="ig-btn warn" type="submit">Delete pass</button>
        </form>
      <?php endif; ?>

    <?php elseif ($doc['status'] === 'posted'): ?>
      <div class="ig-note ok" style="margin:0;flex:1">Posted <?= e((string)$doc['posted_at']) ?>. Stock has been updated. A posted pass cannot be edited — use a reversal if it was wrong.</div>
      <?php if ($canRev): ?>
        <form method="post" onsubmit="return this.reason.value.trim()!=='' || (alert('A reason is required.'),false);" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <input class="ig-inp" name="reason" placeholder="Reason for reversal" style="width:260px">
          <button class="ig-btn warn" type="submit">Reverse</button>
        </form>
      <?php endif; ?>

    <?php else: ?>
      <div class="ig-note bad" style="margin:0;flex:1"><b>Reversed <?= e((string)$doc['reversed_at']) ?>.</b> <?= e((string)$doc['reversal_reason']) ?></div>
    <?php endif; ?>
  </div>

  <?php /* Hard delete of a reversed pass. Separated from the row above and
           stated plainly, because unlike every other action on this screen
           it destroys history: the pass and both sets of stock rows go, and
           only the reason below survives, in the audit log. */
  if ($doc['status'] === 'reversed' && $canEdit): ?>
    <div style="margin-top:16px;border:1px solid #f6c3cd;background:#fdf7f8;border-radius:12px;padding:15px 17px">
      <div style="font-weight:800;font-size:13.5px;color:#8a1628;margin-bottom:7px">Delete this pass for good</div>
      <?php if ($links['jobwork_bills']): ?>
        <p style="font-size:12.5px;color:#8a1628;margin:0;line-height:1.65">
          Not possible — job work bill <b><?= e(implode(', ', $links['jobwork_nos'])) ?></b> was raised
          against this pass. Delete or unlink that bill first.</p>
      <?php elseif (!is_admin()): ?>
        <p style="font-size:12.5px;color:#8a1628;margin:0;line-height:1.65">
          Only an admin can delete a reversed pass.</p>
      <?php else: ?>
        <p style="font-size:12.5px;color:#8a1628;margin:0 0 11px;line-height:1.65">
          This removes the pass and its <b><?= (int)$links['ledger'] ?> stock ledger row(s)</b> — the
          original entry and its reversal. Your closing balances do not change, because those rows cancel
          each other. What is lost is the record that this entry was made and corrected: after deleting,
          nothing on the ledger will explain <?= e((string)$doc['gate_date']) ?>. The reason you type is
          kept in the audit log.</p>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
              onsubmit="return this.reason.value.trim().length>=5
                        ? confirm('Delete <?= e($doc['gate_no']) ?> and its <?= (int)$links['ledger'] ?> stock row(s) permanently? This cannot be undone.')
                        : (alert('Give a reason of at least 5 characters.'),false);">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <input type="hidden" name="direction" value="<?= e($doc['direction']) ?>">
          <input class="ig-inp" name="reason" placeholder="Why is this being deleted?" style="width:300px">
          <button class="ig-btn" type="submit" style="background:#c0293f;border-color:#c0293f;color:#fff">Delete permanently</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php else: ?>

<div class="ig-bar">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <div><label class="ig-lbl">Search</label><input class="ig-inp" name="q" value="<?= e($fq) ?>" placeholder="pass no., challan, vehicle or party" style="width:auto;min-width:230px"></div>
    <div><label class="ig-lbl">Status</label>
      <select class="ig-inp" name="status" style="width:auto">
        <option value="">Any</option>
        <?php foreach (['draft','verified','posted','reversed'] as $s): ?><option value="<?= e($s) ?>" <?= $fs === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select></div>
    <button class="ig-btn sec" type="submit" style="cursor:pointer">Search</button>
  </form>
</div>

<div class="ig-card">
  <?php if (!$reg): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No <?= e(strtolower($dirLabel)) ?> passes yet.
      <?php if ($canEdit): ?><br><br><a class="ig-btn sec" href="?dir=<?= e($dir) ?>&new=1">+ New pass</a><?php endif; ?></p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="ig-tbl">
    <thead><tr><th>Pass</th><th>Date</th><th>Type</th><th>Party</th><th>Linked</th><th class="r">Qty</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reg as $r): $rt = $TYPES[$r['txn_type']] ?? ['label' => $r['txn_type']]; ?>
      <tr<?= $r['status'] === 'reversed' ? ' style="opacity:.6"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($r['gate_no']) ?></td>
        <td><?= e($r['gate_date']) ?><br><span style="font-size:11px;color:#8a97ab"><?= e(substr((string)$r['gate_time'], 0, 5)) ?></span></td>
        <td><?= e($rt['label']) ?></td>
        <td><?= e($r['party_name'] ?: ($r['party_text'] ?: '—')) ?></td>
        <td style="font-size:11.5px"><?= $r['contract_no'] ? e($r['contract_no']) : '<span style="color:#8a97ab">Direct</span>' ?><?php if ($r['pi_no']): ?><br><span style="color:#8a97ab"><?= e($r['pi_no']) ?></span><?php endif; ?></td>
        <td class="r"><?= number_format((float)$r['tqty'], 2) ?><br><span style="font-size:11px;color:#8a97ab"><?= (int)$r['nlines'] ?> line(s)</span></td>
        <td><span class="ig-pill st-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td style="text-align:right"><a class="ig-btn sec" style="padding:5px 10px;font-size:11.5px" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="ig-note info" style="margin:16px 0 0">Only <b>posted</b> passes affect stock and contract balances. Drafts stay in this register so nothing is lost, but they are excluded from every quantity in the system.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer(); ?>
