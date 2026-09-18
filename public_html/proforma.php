<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/proforma_embed.php';
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Staff cannot access proforma invoices.'); }
require_costing('proforma');
costing_ensure_schema();
require_once __DIR__ . '/includes/production.php';
production_ensure_schema();

function pf_num($v): float { return is_numeric($v)?(float)$v:0.0; }
function pf_txt($v,$m=255): string { return mb_substr(trim((string)$v),0,$m); }

/* WHICH SIZE IS THIS LINE? Kept as the size's own id, beside the typed text.
 *
 * The text is never touched — it still prints exactly as typed, here and on
 * every document made from this proforma. This is a link stored next to it.
 *
 * It matters because pieces-per-set depends on the size: a Double taking two
 * pillow cases needs TWO per set, so 500 sets is 1,000 overlocks. Production
 * used to work the size out by matching this typed text against a different
 * table — and when that failed it assumed ONE per set and planned half the
 * work, with nothing on any screen saying so.
 *
 * An unresolved size returns NULL, exactly like every line written before this
 * column existed; those keep behaving as they always did. */
function pf_size_id(int $productId, $postedId, string $label): ?int {
    $postedId = (int)$postedId;
    if ($postedId > 0) return $postedId;
    $label = mb_strtolower(trim($label));
    if ($productId <= 0 || $label === '') return null;
    static $cache = [];
    if (!isset($cache[$productId])) {
        $cache[$productId] = [];
        try {
            $st = db()->prepare("SELECT id, size_label FROM product_sizes WHERE product_id=?");
            $st->execute([$productId]);
            foreach ($st->fetchAll() as $r)
                $cache[$productId][mb_strtolower(trim($r['size_label']))] = (int)$r['id'];
        } catch (Throwable $e) {}
    }
    return $cache[$productId][$label] ?? null;
}

/* The sizes a product actually has. Shares pf_size_id()'s question but returns
   the labels, because a refusal that does not say what the choices ARE just
   sends somebody hunting through Product Master. */
function pf_sizes_of(int $productId): array {
    static $cache = [];
    if ($productId <= 0) return [];
    if (isset($cache[$productId])) return $cache[$productId];
    $out = [];
    try {
        $st = db()->prepare("SELECT size_label FROM product_sizes WHERE product_id=? ORDER BY sort_order, id");
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $r) $out[] = (string)$r['size_label'];
    } catch (Throwable $e) {}
    return $cache[$productId] = $out;
}

/* ============================================================
   THE SIZE IS COMPULSORY — but only where there is one to pick
   ============================================================

   A line whose size does not resolve is not a cosmetic problem. Production
   works pieces-per-set out from the size, so zp_pieces_needed() returns
   ZP_NO_SIZE and THE LINE CANNOT BE BOOKED AT ALL — the day sheet names it in
   a red banner and the work simply cannot be entered against it.

   THE RULE, AND WHY IT IS NOT "EVERY LINE".
     product linked AND that product has sizes  ->  one of them is required
     product linked but it has NO sizes          ->  nothing to pick, so no
     no product link at all (a typed one-off)    ->  no list exists, so no
   Demanding a size everywhere would make it impossible to save a proforma for
   a product that genuinely has none, which several do.

   This runs BEFORE a single row is written, so a refusal leaves the saved
   proforma exactly as it was rather than half-updated. */
function pf_missing_sizes(array $names, array $pids, array $szids, array $szs): array {
    $bad = [];
    for ($i = 0; $i < count($names); $i++) {
        $nm = trim((string)($names[$i] ?? ''));
        /* the same skip the writer uses, or the check would report a blank
           row the save was never going to store */
        if ($nm === '' && trim((string)($szs[$i] ?? '')) === '') continue;
        $pid = (int)($pids[$i] ?? 0);
        if ($pid <= 0) continue;
        $have = pf_sizes_of($pid);
        if (!$have) continue;
        if (pf_size_id($pid, $szids[$i] ?? 0, (string)($szs[$i] ?? ''))) continue;
        $bad[] = ['row' => $i + 1, 'name' => $nm, 'typed' => trim((string)($szs[$i] ?? '')), 'sizes' => $have];
    }
    return $bad;
}

/* The refusal, in words somebody can act on without leaving the page: which
   line, what it says now, and what it is allowed to say. A message that only
   announced "size required" would send them hunting through Product Master. */
function pf_size_error(array $missing): string {
    $lines = [];
    foreach (array_slice($missing, 0, 6) as $m) {
        $lines[] = 'Line ' . $m['row'] . ' (' . ($m['name'] !== '' ? $m['name'] : 'unnamed') . ')'
                 . ($m['typed'] !== ''
                    ? ' says "' . $m['typed'] . '", which is not one of its sizes'
                    : ' has no size')
                 . ' — use ' . implode(', ', array_slice($m['sizes'], 0, 8))
                 . (count($m['sizes']) > 8 ? ' …' : '') . '.';
    }
    if (count($missing) > 6) $lines[] = 'And ' . (count($missing) - 6) . ' more line(s).';
    return 'Nothing was saved. A line for a product that has sizes must say WHICH size — '
         . 'production works pieces-per-set out from it, so without one the order cannot be '
         . 'booked at all. ' . implode(' ', $lines);
}

/* ---------- create from one OR MULTIPLE costing versions ----------
   from_version=ID (single, original link) or from_versions=ID,ID,ID (bulk
   select on product_costing.php) — either way, everything selected lands
   in ONE new proforma invoice, one item row per applicable size per
   version, so a multi-size / multi-product order doesn't need converting
   one at a time and re-merging by hand. */
if (isset($_GET['from_version']) || isset($_GET['from_versions'])) {
    $vids = isset($_GET['from_versions'])
        ? array_values(array_unique(array_filter(array_map('intval', explode(',', $_GET['from_versions'])))))
        : [(int)$_GET['from_version']];
    if (!$vids) { http_response_code(400); exit('No costing version selected.'); }

    $st = db()->prepare("SELECT cv.*, p.name pname, p.product_code pcode, p.default_unit FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
    $rowsToConvert = [];
    foreach ($vids as $vid) { $st->execute([$vid]); $v = $st->fetch(); if ($v) $rowsToConvert[] = $v; }
    if (!$rowsToConvert) { http_response_code(404); exit('Costing version(s) not found.'); }

    $first = $rowsToConvert[0];
    $pi_no = next_doc_no('PI');
    $bd = company_bank_defaults();
    db()->prepare("INSERT INTO proforma_invoices (pi_no,pi_date,costing_version_id,costing_revision,product_id,product_code,currency,status,created_by,bank1_name,bank1_branch,bank1_title,bank1_swift,bank1_iban,bank2_name,bank2_branch,bank2_title,bank2_swift,bank2_iban) VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pi_no, date('Y-m-d'), (int)$first['id'], (int)($first['revision_no'] ?? 1), (int)$first['product_id'], $first['pcode'], $first['currency'] ?: 'USD', current_user()['id'],
            $bd['bank1_name'],$bd['bank1_branch'],$bd['bank1_title'],$bd['bank1_swift'],$bd['bank1_iban'],$bd['bank2_name'],$bd['bank2_branch'],$bd['bank2_title'],$bd['bank2_swift'],$bd['bank2_iban']]);
    $pfid = (int)db()->lastInsertId();

    /* THE SIZE ID IS ALREADY RIGHT HERE, and it used to be thrown away — only
       the label was kept, and production then had to match that text back
       against another table. Both are stored now: the label still prints, and
       the id is the link that makes pieces-per-set reliable. */
    $sz = db()->prepare("SELECT ps.id, ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?");
    $ins = db()->prepare("INSERT INTO proforma_items (proforma_id,product_name,description,size,product_size_id,qty,unit,unit_price,amount,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $i = 0; $names = [];
    foreach ($rowsToConvert as $v) {
        $sz->execute([$v['id']]);
        $sizes = $sz->fetchAll(); if (!$sizes) $sizes = [['id' => null, 'size_label' => '']];
        foreach ($sizes as $s) { $i++; $ins->execute([$pfid, $v['pname'], '', $s['size_label'], $s['id'] ?: null,
                                                      0, $v['default_unit'] ?: 'Pc', (float)$v['suggested_price'], 0, $i]); }
        db()->prepare("UPDATE costing_versions SET status='converted' WHERE id=?")->execute([$v['id']]);
        $names[] = $v['pname'] . ' (' . $v['costing_no'] . ')';
    }
    try { audit_log(0, 'Proforma', 'create', implode(', ', $names), $pi_no . ' from ' . count($rowsToConvert) . ' costing version(s)', 'Costing converted to proforma'); } catch (Throwable $e) {}
    redirect('proforma.php?id=' . $pfid);
}

/* ---------- create blank (no Product Costing needed) ----------
   The only other way in used to be "convert from a saved Costing Version",
   which meant building out a full costing first even for a quick one-off
   quote. costing_version_id/product_id/product_code are already nullable
   on this table (see costing_ensure_schema() in includes/costing.php) —
   this just inserts the same row with those columns blank, and everything
   after that (customer, products, terms) is filled in on the normal edit
   screen exactly the same way. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_blank') {
    verify_csrf();
    if (!costing_perm('proforma')) { http_response_code(403); exit('No permission to create proforma invoices.'); }
    $pi_no = next_doc_no('PI');
    $bd = company_bank_defaults();
    db()->prepare("INSERT INTO proforma_invoices (pi_no,pi_date,currency,status,created_by,bank1_name,bank1_branch,bank1_title,bank1_swift,bank1_iban,bank2_name,bank2_branch,bank2_title,bank2_swift,bank2_iban) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$pi_no, date('Y-m-d'), 'USD', 'draft', current_user()['id'],
            $bd['bank1_name'],$bd['bank1_branch'],$bd['bank1_title'],$bd['bank1_swift'],$bd['bank1_iban'],$bd['bank2_name'],$bd['bank2_branch'],$bd['bank2_title'],$bd['bank2_swift'],$bd['bank2_iban']]);
    $pfid = (int)db()->lastInsertId();
    try { audit_log(0, 'Proforma', 'create', '', $pi_no . ' (created directly, no costing link)', 'Proforma created without a Product Costing version'); } catch (Throwable $e) {}
    redirect('proforma.php?id=' . $pfid);
}

/* ---------- save edits ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save') {
    verify_csrf();
    $pfid=(int)$_POST['id'];
    $prodEnabled = isset($_POST['production_enabled']) ? 1 : 0;
    $showPfooter = isset($_POST['show_pfooter']) ? 1 : 0;
    // bank1_details/bank2_details (the old free-text box) are deliberately
    // left out of this UPDATE — the edit form no longer has those fields,
    // and leaving them out of the SET clause keeps whatever's already
    // saved there untouched instead of overwriting it with an empty
    // string. The 10 structured fields below replace it for anyone
    // editing from now on; proforma_print.php falls back to the old text
    // only when all of a bank's new fields are empty.
    $bankPosted = [
        'bank1_name'=>pf_txt($_POST['bank1_name'] ?? '',160),'bank1_branch'=>pf_txt($_POST['bank1_branch'] ?? '',160),'bank1_title'=>pf_txt($_POST['bank1_title'] ?? '',160),'bank1_swift'=>pf_txt($_POST['bank1_swift'] ?? '',40),'bank1_iban'=>pf_txt($_POST['bank1_iban'] ?? '',80),
        'bank2_name'=>pf_txt($_POST['bank2_name'] ?? '',160),'bank2_branch'=>pf_txt($_POST['bank2_branch'] ?? '',160),'bank2_title'=>pf_txt($_POST['bank2_title'] ?? '',160),'bank2_swift'=>pf_txt($_POST['bank2_swift'] ?? '',40),'bank2_iban'=>pf_txt($_POST['bank2_iban'] ?? '',80),
    ];
    db()->prepare("UPDATE proforma_invoices SET pi_no=?,pi_date=?,delivery_date=?,customer_name=?,customer_address=?,currency=?,payment_terms=?,delivery_terms=?,shipment_terms=?,packing_details=?,validity=?,remarks=?,status=?,bank_choice=?,bank1_name=?,bank1_branch=?,bank1_title=?,bank1_swift=?,bank1_iban=?,bank2_name=?,bank2_branch=?,bank2_title=?,bank2_swift=?,bank2_iban=?,production_enabled=?,show_pfooter=?,updated_at=NOW() WHERE id=?")
      ->execute([pf_txt($_POST['pi_no'] ?? '',40),$_POST['pi_date'] ?: null,pf_txt($_POST['delivery_date'] ?? '',120),pf_txt($_POST['customer_name'] ?? '',200),pf_txt($_POST['customer_address'] ?? '',1000),pf_txt($_POST['currency'] ?? 'USD',8),pf_txt($_POST['payment_terms'] ?? '',200),pf_txt($_POST['delivery_terms'] ?? '',200),pf_txt($_POST['shipment_terms'] ?? '',200),pf_txt($_POST['packing_details'] ?? '',1000),pf_txt($_POST['validity'] ?? '',120),pf_txt($_POST['remarks'] ?? '',1000),pf_txt($_POST['status'] ?? 'draft',30),($_POST['bank_choice'] ?? '1')==='2'?'2':'1',
          $bankPosted['bank1_name'],$bankPosted['bank1_branch'],$bankPosted['bank1_title'],$bankPosted['bank1_swift'],$bankPosted['bank1_iban'],
          $bankPosted['bank2_name'],$bankPosted['bank2_branch'],$bankPosted['bank2_title'],$bankPosted['bank2_swift'],$bankPosted['bank2_iban'],
          $prodEnabled,$showPfooter,$pfid]);
    // Feeds forward into every NEW proforma created after this — see
    // company_bank_defaults_merge() in includes/costing.php. A field left
    // blank here doesn't erase the shared default, so filling in only
    // Bank 1 on this save never clears an already-saved Bank 2 default.
    company_bank_defaults_merge($bankPosted);
    production_set_assignments($pfid, post_array('assigned_staff'), current_user()['id']);
    /* Row IDs are now kept stable across a normal Save (update-in-place /
       insert-new / delete-removed) instead of the old delete-all-then-
       recreate, which reassigned a fresh auto-increment ID to every row on
       every save. That old behaviour is harmless on its own, but it's what
       would have made the CSV export/re-import round trip below unreliable:
       an "Item ID (do not change)" exported from this page would have gone
       stale the moment anyone clicked Save Proforma in between. */
    $ids=$_POST['i_id'] ?? []; $names=$_POST['i_name'] ?? []; $descs=$_POST['i_desc'] ?? []; $szs=$_POST['i_size'] ?? []; $qtys=$_POST['i_qty'] ?? []; $units=$_POST['i_unit'] ?? []; $prices=$_POST['i_price'] ?? []; $pids=$_POST['i_pid'] ?? [];
    $szids=$_POST['i_size_id'] ?? [];
    /* WHICH SIZE THIS LINE IS, kept as the size's own id.
     *
     * The typed text is NOT touched — it still prints exactly as typed, on this
     * proforma and on every document made from it. This is a link stored beside
     * it, nothing more.
     *
     * Why it matters: pieces-per-set depends on the size. A Double taking two
     * pillow cases needs TWO per set, so 500 sets is 1,000 overlocks. Production
     * used to work that size out by matching the typed text against a different
     * table, which is fragile in the obvious way — and when it failed it assumed
     * ONE per set and planned half the work, with nothing on screen saying so.
     *
     * The id is taken from the picker when it resolved, and otherwise worked out
     * here from the text against THAT product's own sizes. Unresolved stays
     * NULL, which every line written before this column existed already is, and
     * those keep behaving exactly as they did. */

    /* THE SIZE CHECK RUNS BEFORE A SINGLE ROW IS WRITTEN, so a refusal leaves
       the stored proforma exactly as it was rather than half-updated. The
       browser blocks this long before it gets here — see pfCheckSizes() — so
       what reaches this point is an old tab, a CSV import or a crafted post. */
    $missing = pf_missing_sizes($names, $pids, $szids, $szs);
    if ($missing) { $_SESSION['error'] = pf_size_error($missing); redirect('proforma.php?id='.$pfid); }

    $existingIds = array_map('intval', array_column(db()->query("SELECT id FROM proforma_items WHERE proforma_id=".$pfid)->fetchAll(), 'id'));
    /* product_id is the line's link to the Product Master, so production
       never has to work out which product a typed name meant. NULL when
       nothing was picked — which is how every line saved before this
       existed behaves, and they keep working exactly as they did. */
    $upd=db()->prepare("UPDATE proforma_items SET product_name=?,product_id=?,description=?,size=?,product_size_id=?,qty=?,unit=?,unit_price=?,amount=?,sort_order=? WHERE id=? AND proforma_id=?");
    $ins=db()->prepare("INSERT INTO proforma_items (proforma_id,product_name,product_id,description,size,product_size_id,qty,unit,unit_price,amount,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $keptIds=[];
    for($i=0;$i<count($names);$i++){
        $nm=pf_txt($names[$i] ?? '',200); if($nm===''&&trim((string)($szs[$i]??''))==='')continue;
        $q=pf_num($qtys[$i]??0); $pr=pf_num($prices[$i]??0);
        $rowId=(int)($ids[$i] ?? 0);
        if ($rowId>0 && in_array($rowId,$existingIds,true)) {
            $upd->execute([$nm,((int)($pids[$i]??0))?:null,pf_txt($descs[$i]??'',500),pf_txt($szs[$i]??'',80),
                           pf_size_id((int)($pids[$i]??0),$szids[$i]??0,(string)($szs[$i]??'')),
                           $q,pf_txt($units[$i]??'',40),$pr,round($q*$pr,2),$i+1,$rowId,$pfid]);
            $keptIds[]=$rowId;
        } else {
            $ins->execute([$pfid,$nm,((int)($pids[$i]??0))?:null,pf_txt($descs[$i]??'',500),pf_txt($szs[$i]??'',80),
                           pf_size_id((int)($pids[$i]??0),$szids[$i]??0,(string)($szs[$i]??'')),
                           $q,pf_txt($units[$i]??'',40),$pr,round($q*$pr,2),$i+1]);
            $keptIds[]=(int)db()->lastInsertId();
        }
    }
    $removeIds=array_diff($existingIds,$keptIds);
    if ($removeIds) { db()->exec("DELETE FROM proforma_items WHERE proforma_id=".$pfid." AND id IN (".implode(',',array_map('intval',$removeIds)).")"); }
    // Auto-embed on every save (same policy as Product Costing) so AI Search's
    // Proforma mode is always current — no manual "Generate Embedding" step.
    try { proforma_embed_one($pfid); } catch (Throwable $e) {}
    try { audit_log(0,'Proforma','edit',$_POST['pi_no'] ?? '','updated','Proforma edited'); } catch(Throwable $e){}
    $_SESSION['flash']='Proforma invoice saved.'; redirect('proforma.php?id='.$pfid);
}

/* ---------- convert selected proforma invoices into ONE shipment ----------
   Same "select multiple, one convert button" pattern as costing -> proforma
   above. All items from every selected proforma land in ONE new shipment's
   invoice items, so multi-size / multi-order proformas don't need shifting
   across one at a time. Nothing here touches invoice/packing/product master
   logic — it only does the same INSERT shipment_form.php + its item-add
   form would already do, in one step. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_to_shipment') {
    verify_csrf();
    if (is_staff()) { http_response_code(403); exit('Staff cannot create commercial invoices.'); }
    if (!can_see_rates()) { $_SESSION['error'] = 'Your user cannot create a commercial invoice because rate visibility is disabled.'; redirect('proforma.php'); }

    $pfIds = array_values(array_unique(array_filter(array_map('intval', $_POST['pf_ids'] ?? []))));
    if (!$pfIds) { $_SESSION['error'] = 'Select at least one proforma invoice first.'; redirect('proforma.php'); }

    $pfSt = db()->prepare("SELECT * FROM proforma_invoices WHERE id=?");
    $itSt = db()->prepare("SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order,id");
    $pfRows = []; $itemsAll = [];
    foreach ($pfIds as $pid) {
        $pfSt->execute([$pid]); $pf = $pfSt->fetch();
        if (!$pf) continue;
        $pfRows[] = $pf;
        $itSt->execute([$pid]);
        foreach ($itSt->fetchAll() as $it) { $itemsAll[] = $it; }
    }
    if (!$pfRows) { $_SESSION['error'] = 'Selected proforma invoice(s) not found.'; redirect('proforma.php'); }

    $first = $pfRows[0];
    $invoiceNo = next_doc_no('INV');
    db()->prepare("INSERT INTO shipments (invoice_no,invoice_date,buyer_name,buyer_address,currency,created_by,updated_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([$invoiceNo, date('Y-m-d'), $first['customer_name'] ?: '(set buyer name)', $first['customer_address'] ?: '', $first['currency'] ?: 'USD', current_user()['id'], current_user()['id']]);
    $shipmentId = (int)db()->lastInsertId();
    db()->prepare("INSERT IGNORE INTO shipment_assignments (shipment_id,user_id,assigned_by) VALUES (?,?,?)")->execute([$shipmentId, current_user()['id'], current_user()['id']]);

    $ins = db()->prepare("INSERT INTO shipment_items (shipment_id,line_no,product_name,des_col,qty,unit,rate,amount) VALUES (?,?,?,?,?,?,?,?)");
    $i = 0;
    foreach ($itemsAll as $it) {
        $i++;
        $qty = (float)$it['qty']; $rate = (float)$it['unit_price'];
        $desc = trim(($it['description'] ?: '') . ($it['size'] ? ' — ' . $it['size'] : ''));
        $ins->execute([$shipmentId, $i, $it['product_name'], $desc, $qty, $it['unit'], $rate, round($qty * $rate, 2)]);
    }
    $pfNos = [];
    foreach ($pfRows as $pf) {
        db()->prepare("UPDATE proforma_invoices SET status='converted', converted_shipment_id=? WHERE id=?")->execute([$shipmentId, $pf['id']]);
        $pfNos[] = $pf['pi_no'];
    }
    try { audit_log($shipmentId, 'Shipment', 'created', '', $invoiceNo . ' from proforma(s): ' . implode(', ', $pfNos), 'Created from Proforma Invoice conversion'); } catch (Throwable $e) {}
    $multiBuyer = count(array_unique(array_column($pfRows, 'customer_name'))) > 1;
    $_SESSION['flash'] = 'Shipment ' . $invoiceNo . ' created from ' . count($pfRows) . ' proforma invoice(s) with ' . count($itemsAll) . ' item(s).'
        . ($multiBuyer ? ' Selected proformas had different customer names — buyer details were pre-filled from the first one, please check.' : '');
    redirect('shipment_view.php?id=' . $shipmentId);
}

/* ---------- delete (requires re-entering your own login password) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete') {
    verify_csrf();
    if (!costing_perm('delete')) { http_response_code(403); exit('No delete permission.'); }
    $pfid=(int)$_POST['id'];
    if (!password_verify((string)($_POST['delete_password'] ?? ''), current_user()['password_hash'])) {
        $_SESSION['error'] = 'Incorrect password — proforma invoice was not deleted.';
        redirect('proforma.php?id=' . $pfid);
    }
    db()->prepare("DELETE FROM proforma_items WHERE proforma_id=?")->execute([$pfid]);
    db()->prepare("DELETE FROM proforma_invoices WHERE id=?")->execute([$pfid]);
    try { audit_log(0,'Proforma','delete',$pfid,'','Proforma deleted'); } catch(Throwable $e){}
    $_SESSION['flash']='Proforma deleted.'; redirect('proforma.php');
}

/* ---------- shared: exact Product Master names, for the reference column
   below — avoids retyping a product name slightly differently than it's
   spelled in Product Master, which would otherwise silently fail to match
   on Final Costing / production lookups later. ---------- */
function pf_master_names(): array {
    return db()->query("SELECT name FROM products WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

/* ---------- CSV template (blank, for new rows) ----------
   Columns 0-6 are what gets read back in on import. The blank spacer column
   plus "Product Master — copy exact name from here" are a same-file
   reference list only — never read on import — so the exact current
   Product Master spelling is one copy/paste away in Excel instead of
   having to retype it and risk a mismatch. */
if (isset($_GET['csv_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proforma_products_template.csv"');
    $names = pf_master_names();
    $out=fopen('php://output','w');
    fputcsv($out,['Item ID (do not change)','Product Name','Description','Size','Qty','Unit','Unit Price','','Product Master — copy exact name from here']);
    fputcsv($out,['','Quilted Comforter','White microfiber shell','180x260','100','Pc','8.50','', $names[0] ?? '']);
    for ($i=1;$i<count($names);$i++) { fputcsv($out,['','','','','','','','',$names[$i]]); }
    fclose($out); exit;
}

/* ---------- export CURRENT product rows (same columns as the template,
   plus each row's real Item ID, plus the same Product Master name
   reference column) — fill this in and re-import it to update the rows
   you already had instead of only being able to add new ones. ---------- */
if (isset($_GET['export_items'])) {
    $pfid=(int)$_GET['export_items'];
    $st=db()->prepare("SELECT * FROM proforma_invoices WHERE id=?"); $st->execute([$pfid]); $pf=$st->fetch();
    if(!$pf){ http_response_code(404); exit('Proforma not found.'); }
    $it=db()->prepare("SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order,id"); $it->execute([$pfid]);
    $items = $it->fetchAll();
    $names = pf_master_names();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]/','_',$pf['pi_no']).'_items.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Item ID (do not change)','Product Name','Description','Size','Qty','Unit','Unit Price','','Product Master — copy exact name from here']);
    $rows = max(count($items), count($names));
    for ($i=0; $i<$rows; $i++) {
        $r = $items[$i] ?? null;
        fputcsv($out,[
            $r ? (int)$r['id'] : '',
            $r ? $r['product_name'] : '',
            $r ? $r['description'] : '',
            $r ? $r['size'] : '',
            $r ? $r['qty'] : '',
            $r ? $r['unit'] : '',
            $r ? $r['unit_price'] : '',
            '',
            $names[$i] ?? '',
        ]);
    }
    fclose($out); exit;
}

/* ---------- import products CSV — update existing rows by Item ID, add
   unrecognised/blank-ID rows as new (never touches the proforma header). ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='import_csv') {
    verify_csrf();
    $pfid=(int)$_POST['id'];
    $chk=db()->prepare("SELECT id FROM proforma_invoices WHERE id=?"); $chk->execute([$pfid]);
    if(!$chk->fetch()){ http_response_code(404); exit('Proforma not found.'); }
    if(empty($_FILES['csv']['tmp_name'])){ $_SESSION['flash']='No CSV file selected.'; redirect('proforma.php?id='.$pfid); }
    $fh=fopen($_FILES['csv']['tmp_name'],'r');
    if(!$fh){ $_SESSION['flash']='Could not read the CSV file.'; redirect('proforma.php?id='.$pfid); }
    /* id AND the link it already has. The UPDATE below uses COALESCE, so a CSV
       name that does not resolve KEEPS the row's existing product — which means
       the size check has to look at that kept link, not at the CSV's failure to
       resolve one, or a reworded line would walk straight past the rule. */
    $existingLink = [];
    foreach (db()->query("SELECT id, product_id FROM proforma_items WHERE proforma_id=".$pfid)->fetchAll() as $er)
        $existingLink[(int)$er['id']] = (int)($er['product_id'] ?? 0);
    $existingIds = array_map('intval', array_keys($existingLink));
    $so=(int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM proforma_items WHERE proforma_id=".$pfid)->fetchColumn();
    /* A CSV row whose name IS a Product Master name is linked on the way
       in, so an import arrives already joined up instead of relying on
       the name matching that later has to guess. */
    $csvMap = [];
    try {
        foreach (db()->query("SELECT id,name FROM products WHERE is_active=1")->fetchAll() as $pm) {
            $csvMap[strtolower(trim($pm['name']))] = (int)$pm['id'];
        }
    } catch (Throwable $e) {}
    /* COALESCE, not a plain assignment, and this matters.

       The edit screen deliberately lets you pick a product and then reword
       the printed name — that is the whole point of storing the code
       separately, and the chip on screen says the link holds. But a
       reworded name is NOT an exact Product Master name, so $csvMap misses
       it. Assigning the miss would write NULL over a link you made on
       purpose, and that line would silently drop out of production again
       on the next export/re-import round trip.

       So: a CSV name that RESOLVES sets the link (including moving a line
       to a different product). A CSV name that does not resolve leaves
       whatever link the row already has alone. The INSERT below has
       nothing to preserve, so NULL is correct there. */
    /* THE SIZE LINK IS RE-WORKED OUT FROM THE IMPORTED TEXT, never carried over.
       A CSV that changes a line from Double to King would otherwise leave it
       still pointing at Double's id, and production would plan Double's
       pieces-per-set for a King line — wrong, and completely silent. */
    $upd=db()->prepare("UPDATE proforma_items SET product_name=?,product_id=COALESCE(?,product_id),description=?,size=?,product_size_id=?,qty=?,unit=?,unit_price=?,amount=? WHERE id=? AND proforma_id=?");
    $ins=db()->prepare("INSERT INTO proforma_items (proforma_id,product_name,product_id,description,size,product_size_id,qty,unit,unit_price,amount,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    /* THE WHOLE FILE IS READ BEFORE ANYTHING IS WRITTEN.
       This used to write each row as it read it, which is fine until a row is
       refused: half the file would already be in, and re-importing the fixed
       file would double the rows it had accepted. Collect, check, then write —
       so an import either lands completely or not at all. */
    $seenHeader=false; $csvRows=[];
    while(($row=fgetcsv($fh))!==false){
        if(!$seenHeader){ $seenHeader=true; if(stripos(implode(',',$row),'product')!==false) continue; }
        if(count(array_filter($row,fn($c)=>trim((string)$c)!==''))===0) continue;
        $nm=pf_txt($row[1] ?? '',200); $size=pf_txt($row[3] ?? '',80);
        if($nm==='' && $size==='') continue;
        $csvRows[]=['id'=>(int)($row[0] ?? 0),'name'=>$nm,'desc'=>pf_txt($row[2] ?? '',500),
                    'size'=>$size,'qty'=>pf_num($row[4] ?? 0),'unit'=>pf_txt($row[5] ?? '',40),
                    'price'=>pf_num($row[6] ?? 0),
                    'pid'=>(int)($csvMap[strtolower($nm)] ?? 0)];
    }
    fclose($fh);

    /* the same rule the screen enforces, so a CSV cannot walk round it —
       checked against the link the row will ACTUALLY end up with */
    $effPid = [];
    foreach ($csvRows as $r)
        $effPid[] = $r['pid'] ?: (($r['id'] > 0 && isset($existingLink[$r['id']])) ? $existingLink[$r['id']] : 0);
    $missing = pf_missing_sizes(array_column($csvRows,'name'), $effPid,
                                array_fill(0, count($csvRows), 0), array_column($csvRows,'size'));
    if ($missing) {
        $_SESSION['error'] = 'Nothing was imported. ' . pf_size_error($missing)
            . ' (Line numbers are rows of the CSV, not counting its header.)';
        redirect('proforma.php?id='.$pfid);
    }

    $updated=0; $added=0;
    foreach ($csvRows as $r) {
        $amt = round($r['qty']*$r['price'],2);
        if ($r['id']>0 && in_array($r['id'],$existingIds,true)) {
            $upd->execute([$r['name'],$r['pid']?:null,$r['desc'],$r['size'],
                           pf_size_id($r['pid'],0,$r['size']),
                           $r['qty'],$r['unit'],$r['price'],$amt,$r['id'],$pfid]);
            $updated++;
        } else {
            $so++;
            $ins->execute([$pfid,$r['name'],$r['pid']?:null,$r['desc'],$r['size'],
                           pf_size_id($r['pid'],0,$r['size']),
                           $r['qty'],$r['unit'],$r['price'],$amt,$so]); $added++;
        }
    }
    try { audit_log(0,'Proforma','import',$pfid,$updated.' updated, '.$added.' added','Products CSV imported'); } catch(Throwable $e){}
    $_SESSION['flash']=$updated.' row(s) updated, '.$added.' row(s) added.'; redirect('proforma.php?id='.$pfid);
}

$id=(int)($_GET['id'] ?? 0);
/* ---------------------------------------------------------------
   THE SKIN. These five strings are how this page has always styled
   itself, so they are also the whole of the restyle: each now carries
   a zskin class as well as its old inline value.

   THE OLD VALUES ARE KEPT ON PURPOSE. If zskin.css fails to load —
   a stale cache, a bad upload — the page still renders as it did
   yesterday instead of arriving as unstyled boxes. That exact failure
   has already cost this project a round.
   --------------------------------------------------------------- */
$cardC='zcard'; $inpC='zin'; $labC='zlab'; $btnC='zbt'; $btnsC='zbt sec';
$card='padding:22px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;margin-bottom:18px';
/* DENSITY. This grid is typed in all day, so it is sized like a spreadsheet
   rather than like a sign-up form: 6px of padding instead of 10, a 7px radius
   instead of 10, and 13px text. The row gets shorter, more lines fit on one
   screen, and the eye travels less between the name and the amount. */
$inp='width:100%;padding:6px 8px;border-radius:7px;border:1px solid #d3dce8;background:#fff;color:#152033;font-size:13px;line-height:1.3;font-family:inherit';
$lab='font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#68737d;display:block;margin-bottom:3px';
$btn='padding:7px 13px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:12.5px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block';
$btns='background:#f6f8fc;color:#152033;border:1px solid #cbd5e3';

if ($id) {
    $st=db()->prepare("SELECT * FROM proforma_invoices WHERE id=?"); $st->execute([$id]); $pf=$st->fetch();
    if(!$pf){ http_response_code(404); exit('Proforma not found.'); }
    $it=db()->prepare("SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order,id"); $it->execute([$id]); $items=$it->fetchAll();
    /* The Product Master the line LOV offers. Loaded once and filtered in
       the browser, so picking an item costs no round trip. */
    $pfMaster = [];
    try { $pfMaster = db()->query("SELECT id, name, default_unit FROM products WHERE is_active=1 ORDER BY name LIMIT 800")->fetchAll(); }
    catch (Throwable $e) {}
    $productionStaffList = db()->query("SELECT id, name FROM users WHERE role='production_staff' AND is_active=1 ORDER BY name")->fetchAll();
    $assignedStaffIds = array_column(production_assigned_staff($id), 'id');
    page_header('Proforma Invoice'); flash();
    /* THE PAGE OPTS IN TO THE SKIN HERE, and this one class is the whole of it.
       zskin.css is scoped under .zskin, so removing this attribute puts the
       page back exactly as it was — which is what makes doing the other
       screens one at a time safe. */
    ?>
    <div class="zskin">
    <div class="topbar"><div><h1>Proforma Invoice — <?= e($pf['pi_no']) ?></h1><p class="lead">Customer-facing only. <?= $pf['product_id'] ? 'Linked to product #'.(int)$pf['product_id'].' · costing #'.(int)$pf['costing_version_id'].' (rev '.(int)$pf['costing_revision'].').' : 'Created directly — not linked to a Product Costing version.' ?></p></div>
      <div style="display:flex;gap:8px"><a class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>" href="proforma.php">← All Proformas</a><a class="<?= $btnC ?>" style="<?= $btn ?>" target="_blank" href="proforma_print.php?id=<?= (int)$id ?>">Print / PDF</a></div></div>
    <form method="post" enctype="multipart/form-data" id="pfImport"><?= csrf_field() ?><input type="hidden" name="action" value="import_csv"><input type="hidden" name="id" value="<?= (int)$id ?>"></form>
    <?php /* the size guard runs on submit — see pfSizeGuard(). It only ever
             blocks; it never changes what is posted. */ ?>
    <form method="post" id="pfSaveForm" onsubmit="return pfSizeGuard(event)">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="<?= $cardC ?>" style="<?= $card ?>">
        <h2 style="font-size:13.5px;font-weight:600;margin:0 0 9px">Invoice &amp; Customer</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:8px">
          <label class="<?= $labC ?>" style="<?= $lab ?>">PI No.<input class="<?= $inpC ?>" style="<?= $inp ?>" name="pi_no" value="<?= e($pf['pi_no']) ?>"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">PI Date<input class="<?= $inpC ?>" style="<?= $inp ?>" type="date" name="pi_date" value="<?= e($pf['pi_date']) ?>"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Delivery Date<input class="<?= $inpC ?>" style="<?= $inp ?>" name="delivery_date" value="<?= e($pf['delivery_date'] ?? '') ?>" placeholder="e.g. 45 days after advance received"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Currency<select class="<?= $inpC ?>" style="<?= $inp ?>" name="currency"><?php foreach(['USD','EUR','GBP','PKR'] as $c): ?><option <?= $pf['currency']===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?></select></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Status<select class="<?= $inpC ?>" style="<?= $inp ?>" name="status"><?php foreach(['draft'=>'Draft','sent'=>'Sent','confirmed'=>'Confirmed','archived'=>'Archived'] as $k=>$t): ?><option value="<?= $k ?>" <?= ($pf['status']??'draft')===$k?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></label>
          <label style="<?= $lab ?>;grid-column:span 2">Customer Name<input class="<?= $inpC ?>" style="<?= $inp ?>" name="customer_name" value="<?= e($pf['customer_name']) ?>"></label>
          <label style="<?= $lab ?>;grid-column:span 2">Customer Address<textarea class="<?= $inpC ?>" style="<?= $inp ?>;min-height:56px" name="customer_address"><?= e($pf['customer_address']) ?></textarea></label>
          <label style="display:flex;align-items:center;gap:9px;margin-top:22px;color:#33415c;font-size:13px;cursor:pointer;grid-column:span 2"><input type="checkbox" name="production_enabled" <?= !empty($pf['production_enabled'])?'checked':'' ?> style="width:16px;height:16px;accent-color:#16a34a"> Enable for Production Tracking <span style="color:#8a97ab;font-weight:400">— lets Production Staff find this order's items in Daily Production Entry</span></label>
          <label style="display:flex;align-items:center;gap:9px;margin-top:10px;color:#33415c;font-size:13px;cursor:pointer;grid-column:span 2"><input type="checkbox" name="show_pfooter" <?= ($pf['show_pfooter'] ?? 1)?'checked':'' ?> style="width:16px;height:16px;accent-color:#16a34a"> Show standard Terms footer on print <span style="color:#8a97ab;font-weight:400">— Order Acceptance / Production / Quality Claim clauses at the bottom of the printed PDF; untick to leave it off for this proforma</span></label>
        </div>
        <?php if(!empty($pf['production_enabled'])): ?>
        <div style="margin-top:12px;padding:10px 14px;border-radius:10px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);font-size:12px;color:#16a34a;font-weight:600">Production status: <?= e(ucwords(str_replace('_',' ', $pf['production_status'] ?? 'not_started'))) ?></div>
        <div style="margin-top:12px;padding:14px 16px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2">
          <div class="zsubh" style="font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#68737d;margin-bottom:6px">Assigned Production Staff <span style="font-weight:400;text-transform:none">— only these logins can log entries for this order</span></div>
          <?php if ($productionStaffList): ?>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            <?php foreach ($productionStaffList as $ps): $on = in_array((int)$ps['id'], $assignedStaffIds, true); ?>
            <label style="display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:20px;border:1.5px solid <?= $on?'#0ea8c9':'#cbd5e3' ?>;background:<?= $on?'rgba(14,168,201,.08)':'#ffffff' ?>;color:<?= $on?'#0ea8c9':'#33415c' ?>;font-size:12.5px;font-weight:600;cursor:pointer">
              <input type="checkbox" name="assigned_staff[]" value="<?= (int)$ps['id'] ?>" <?= $on?'checked':'' ?> style="width:14px;height:14px;accent-color:#0ea8c9"> <?= e($ps['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p style="color:#8a97ab;font-size:12px;margin:0">No Production Staff accounts yet — create one in <a href="users.php" style="color:#0ea8c9;font-weight:600">Users</a> first.</p>
          <?php endif; ?>
          <p style="font-size:11px;color:#8a97ab;margin:10px 0 0">Unassigned orders don't appear in any Production Staff login's search at all.</p>
        </div>
        <?php endif; ?>
      </div>
      <div style="margin-bottom:10px">
        <h2 style="font-size:13.5px;font-weight:600;margin:0 0 8px">Products (customer-facing)</h2>
        <?php /* ONE PANEL. The toolbar, the rows and the totals share a single
                 frame: a card around a bordered grid is one border too many, and
                 the controls belong to the rows they act on. */ ?>
        <div class="zgpanel">
        <div class="ztools">
          <div class="zfind">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input id="pfQ" placeholder="Filter lines…" autocomplete="off">
          </div>
          <span class="zsep"></span>
          <?php if ($items): ?>
          <a class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>;padding:7px 12px;font-size:12px" href="proforma.php?export_items=<?= (int)$id ?>">Export Current Items</a>
          <?php endif; ?>
          <a class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>;padding:7px 12px;font-size:12px" href="proforma.php?csv_template=1">Download Blank Template</a>
          <input form="pfImport" type="file" name="csv" accept=".csv" required class="<?= $inpC ?>" style="<?= $inp ?>;max-width:170px;padding:4px;font-size:11.5px">
          <button form="pfImport" type="submit" class="<?= $btnC ?>" style="<?= $btn ?>;padding:7px 14px;font-size:12px">Import</button>
          <span class="zsp"></span>
          <span class="zcnt" id="pfCnt"></span>
        </div>
        <p style="font-size:11.5px;color:#68737d;margin:0;padding:6px 9px;border-bottom:1px solid #dde2eb">
          Export the rows, edit in Excel, re-import — matching rows update in place, new rows get added.
          Header &amp; terms are never touched. Or just paste a block straight into the grid.</p>
        <div id="pasteWarn" style="display:none;font-size:12px;margin:0 0 10px;padding:9px 12px;border-radius:10px;background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.3);color:#9a5710"></div>
        <div class="zgwrap"><table style="width:100%;min-width:900px" id="pfItems">
          <thead><tr>
            <th class="zpin-l" style="width:230px">Product name</th>
            <th style="width:200px">Description</th>
            <th style="width:150px">Size</th>
            <th style="width:92px;text-align:right">Qty</th>
            <th style="width:82px">Unit</th>
            <th style="width:100px;text-align:right">Unit price</th>
            <th style="width:112px;text-align:right">Amount</th>
            <th class="zpin-r" style="width:40px"></th>
          </tr></thead>
          <tbody><?php foreach($items as $r): ?>
            <tr>
              <?php /* The typed name is what prints; the hidden product_id
                       beside it is what production follows. Pick once from
                       the list and reword freely — the code holds. */
              $lname = '';
              $pidRow = (int)($r['product_id'] ?? 0);
              if ($pidRow > 0) {
                  foreach ($pfMaster as $pm) if ((int)$pm['id'] === $pidRow) $lname = $pm['name'];
              }
              /* Three states, not two. A line can carry the code of a
                 product that has since been removed from the master —
                 $pfMaster holds only the active ones, so an id with no
                 name here is a DEAD link. Saying "linked #3" there would
                 be a lie; production would fall back to guessing at the
                 name, and nobody would know to look. */
              $pfState = $pidRow <= 0 ? 'none' : ($lname !== '' ? 'ok' : 'dead');
              /* Linked, but printing your own wording rather than the
                 master name. Stated plainly so nobody wonders whether
                 the rewording quietly broke the link — it does not. */
              $pfReworded = $pfState === 'ok'
                  && lov_norm((string)$r["product_name"]) !== lov_norm($lname); ?>
              <td class="zpin-l"><input type="hidden" name="i_id[]" value="<?= (int)$r['id'] ?>"><input class="lovf" data-lov="prod" data-c="name" name="i_name[]" autocomplete="off" value="<?= e($r['product_name']) ?>">
                  <input type="hidden" class="pid" name="i_pid[]" value="<?= (int)($r['product_id'] ?? 0) ?>">
                  <div class="linkchip"><?=
                        $pfState === 'ok'   ? '<span class="ok">&#10003; ' . e($lname) . '</span>'
                                              . ($pfReworded ? ' <span class="rw" title="Linked. What prints is your wording, not the master name.">&#9998;</span>' : '')
                      : ($pfState === 'dead' ? '<span class="un">&#9888; product #' . $pidRow . ' no longer in master &mdash; pick again</span>'
                                             : '<span class="un">not linked</span>') ?></div></td>
              <td class="zdim"><input data-c="desc" name="i_desc[]" value="<?= e($r['description']) ?>"></td>
              <?php /* SIZE, WITH ITS LINK. The box is still free text and still
                       prints exactly what is typed — the list underneath simply
                       offers that product's real sizes so the link lands. The
                       chip says whether it did, because a size that only LOOKS
                       right is what made production plan half the pieces. */ ?>
              <td>
                <input data-c="size" name="i_size[]"
                       list="szl<?= (int)$r['id'] ?>" autocomplete="off" value="<?= e($r['size']) ?>"
                       oninput="pfSize(this)" onchange="pfSize(this)">
                <input type="hidden" class="sid" name="i_size_id[]" value="<?= (int)($r['product_size_id'] ?? 0) ?>">
                <datalist id="szl<?= (int)$r['id'] ?>"></datalist>
                <div class="linkchip szchip"></div></td>
              <td><input class="num" data-c="qty" type="number" step="0.001" name="i_qty[]" value="<?= e($r['qty']) ?>" oninput="pfCalc()"></td>
              <td><input data-c="unit" name="i_unit[]" value="<?= e($r['unit']) ?>"></td>
              <td><input class="num" data-c="price" type="number" step="0.01" name="i_price[]" value="<?= e($r['unit_price']) ?>" oninput="pfCalc()"></td>
              <td class="zro amt"><?= e(number_format((float)$r['amount'],2)) ?></td>
              <td class="zpin-r" style="text-align:center"><button type="button" class="rowx" title="Remove this line" onclick="this.closest('tr').remove();pfCalc()">&times;</button></td>
            </tr>
          <?php endforeach; ?></tbody>
          <tfoot><tr>
            <td colspan="6" style="text-align:right;font-weight:600">Total</td>
            <td class="zro" style="font-weight:700" id="pfTotal">0.00</td><td></td>
          </tr></tfoot>
        </table></div>
        <?php /* THE STATUS BAR. Add, state and the keys people forget, on the
                 frame rather than floating under it. */ ?>
        <div class="ztools" style="border-bottom:0;border-top:1px solid #dde2eb">
          <button type="button" class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>" onclick="pfAddRow()">+ Add row</button>
          <span id="pfBadge" class="zcnt">saved</span>
          <span class="zsp"></span>
          <span class="zcnt" id="pasteHint">Paste order: Product · Description · Size · Qty · Unit · Price</span>
        </div>
        </div><?php /* closes .zgpanel */ ?>
        <div class="zhint">
          <span><kbd>Tab</kbd> across</span><span><kbd>Enter</kbd> down</span>
          <span><kbd>&larr;</kbd><kbd>&rarr;</kbd> next cell at the edge of the text</span>
          <span><kbd>Del</kbd> clear</span><span><kbd>Home</kbd>/<kbd>End</kbd> row ends</span>
          <span><kbd>Ctrl</kbd>+<kbd>D</kbd> fill down</span><span><kbd>Ctrl</kbd>+<kbd>V</kbd> paste a block</span>
          <span><kbd>Ctrl</kbd>+<kbd>S</kbd> save</span>
        </div>
      </div>
      <div class="<?= $cardC ?>" style="<?= $card ?>">
        <h2 style="font-size:13.5px;font-weight:600;margin:0 0 9px">Terms</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:8px">
          <label class="<?= $labC ?>" style="<?= $lab ?>">Payment Terms<input class="<?= $inpC ?>" style="<?= $inp ?>" name="payment_terms" value="<?= e($pf['payment_terms']) ?>"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Delivery Terms<input class="<?= $inpC ?>" style="<?= $inp ?>" name="delivery_terms" value="<?= e($pf['delivery_terms']) ?>"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Shipment Terms<input class="<?= $inpC ?>" style="<?= $inp ?>" name="shipment_terms" value="<?= e($pf['shipment_terms']) ?>"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Validity<input class="<?= $inpC ?>" style="<?= $inp ?>" name="validity" value="<?= e($pf['validity']) ?>" placeholder="e.g. 30 days"></label>
          <label class="<?= $labC ?>" style="<?= $lab ?>">Bank on Print<select class="<?= $inpC ?>" style="<?= $inp ?>" name="bank_choice"><option value="1" <?= ($pf['bank_choice'] ?? '1')!=='2'?'selected':'' ?>>Bank 1</option><option value="2" <?= ($pf['bank_choice'] ?? '1')==='2'?'selected':'' ?>>Bank 2</option></select></label>
        </div>
        <?php if (trim((string)($pf['bank1_details'] ?? '')) !== '' && trim((string)($pf['bank1_name'] ?? '')) === '' && trim((string)($pf['bank2_name'] ?? '')) === ''): ?>
        <div style="margin-top:12px;padding:10px 14px;border-radius:10px;background:rgba(184,137,31,.08);border:1px solid rgba(184,137,31,.25);font-size:12px;color:#8a6410">This proforma still has bank details saved the old way (one free-text box) — it keeps printing exactly as before until you fill in the fields below, at which point those take over.</div>
        <?php endif; ?>
        <div class="zsub" style="margin-top:9px;padding:9px 11px;border-radius:6px;background:#fbfcfc;border:1px solid #dde2eb">
          <div class="zsubh" style="font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#68737d;margin-bottom:6px">Bank 1</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">
            <label class="<?= $labC ?>" style="<?= $lab ?>">Bank Name<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank1_name" value="<?= e($pf['bank1_name'] ?? '') ?>" placeholder="Meezan Bank Limited"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">Branch<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank1_branch" value="<?= e($pf['bank1_branch'] ?? '') ?>" placeholder="Faisalabad, Pakistan"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">Account Title<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank1_title" value="<?= e($pf['bank1_title'] ?? '') ?>" placeholder="ZAS Textile"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">SWIFT Code<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank1_swift" value="<?= e($pf['bank1_swift'] ?? '') ?>" placeholder="MEZNPKKA"></label>
            <label style="<?= $lab ?>;grid-column:span 2">Account / IBAN<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank1_iban" value="<?= e($pf['bank1_iban'] ?? '') ?>" placeholder="PK00 MEZN 0000 0000 0000 0000"></label>
          </div>
        </div>
        <div style="margin-top:12px;padding:14px 16px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2">
          <div class="zsubh" style="font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#68737d;margin-bottom:6px">Bank 2</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">
            <label class="<?= $labC ?>" style="<?= $lab ?>">Bank Name<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank2_name" value="<?= e($pf['bank2_name'] ?? '') ?>" placeholder="Habib Bank Limited (HBL)"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">Branch<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank2_branch" value="<?= e($pf['bank2_branch'] ?? '') ?>" placeholder="Faisalabad, Pakistan"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">Account Title<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank2_title" value="<?= e($pf['bank2_title'] ?? '') ?>" placeholder="ZAS Textile"></label>
            <label class="<?= $labC ?>" style="<?= $lab ?>">SWIFT Code<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank2_swift" value="<?= e($pf['bank2_swift'] ?? '') ?>" placeholder="HABBPKKA"></label>
            <label style="<?= $lab ?>;grid-column:span 2">Account / IBAN<input class="<?= $inpC ?>" style="<?= $inp ?>" name="bank2_iban" value="<?= e($pf['bank2_iban'] ?? '') ?>" placeholder="PK00 HABB 0000 0000 0000 0000"></label>
          </div>
        </div>
        <div style="margin-top:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:8px">
          <label style="<?= $lab ?>;grid-column:span 2">Packing Details<input class="<?= $inpC ?>" style="<?= $inp ?>" name="packing_details" value="<?= e($pf['packing_details']) ?>"></label>
          <label style="<?= $lab ?>;grid-column:span 2">Terms &amp; Conditions<textarea class="<?= $inpC ?>" style="<?= $inp ?>;min-height:56px" name="remarks"><?= e($pf['remarks']) ?></textarea></label>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
        <button class="<?= $btnC ?>" style="<?= $btn ?>">Save Proforma</button>
        <?php if(costing_perm('delete')): ?>
        <input type="hidden" id="pfDeletePassword" name="delete_password" value="">
        <button formaction="proforma.php" name="action" value="delete" onclick="return pfConfirmDelete()" class="<?= $btnC ?> del" style="<?= $btn ?>;background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3)">Delete</button>
        <?php endif; ?>
      </div>
    </form>
    <link rel="stylesheet" href="assets/css/lov.css?v=3">
    <style>
/* ---- a line that still needs a size ----
   The mark is on the ROW, not just the cell, because on a wide grid a red
   border on one narrow box scrolled off to the right is easy to miss. */
#pfItems tr.needsz > td{background:var(--bad-soft,#fdeef1)}
#pfItems tr.needsz [data-c="size"]{
  border-color:var(--bad,#c92d4b) !important;
  box-shadow:inset 0 0 0 1px var(--bad,#c92d4b) !important;
  background:var(--panel,#fff) !important}
.pfszwarn{border:1px solid var(--bad,#c92d4b);background:var(--bad-soft,#fdeef1);
  color:var(--bad,#c92d4b);border-radius:6px;padding:9px 12px;font-size:12.5px;
  line-height:1.5;margin:0 0 9px}
.pfszwarn b{font-weight:600}

    .linkchip{margin-top:2px;font-size:10px;font-family:monospace;line-height:1.35;
      display:flex;align-items:center;gap:4px;flex-wrap:wrap}
    .linkchip .ok{color:#127a3f;font-weight:700;background:rgba(22,163,74,.1);
      border:1px solid rgba(22,163,74,.25);border-radius:4px;padding:0 4px}
    .linkchip .un{color:#9a5710;font-weight:700}
    /* linked, but the printed text is your wording, not the master's */
    .linkchip .rw{color:#5b6b85}
    /* THE UNLINK BUTTON. Before this, the only way to drop a link was to clear
       the box — which threw away the wording you had typed for printing. Two
       different intentions needed two different controls. */
    .linkchip .cut{border:1px solid rgba(224,67,93,.35);background:#fff;color:#c9384f;
      border-radius:4px;padding:0 4px;cursor:pointer;font-family:inherit;font-size:10px;
      font-weight:800;line-height:1.35}
    .linkchip .cut:hover{background:rgba(224,67,93,.1);border-color:#e0435d}
    table td{vertical-align:top}
    </style>
    <script src="assets/js/lov.js?v=3"></script>
    <script src="assets/js/grid.js?v=2"></script>
    <script>
    function pfConfirmDelete(){
      var pw=prompt('This will permanently delete this proforma invoice.\nEnter your login password to confirm:');
      if(pw===null||pw===''){return false}
      document.getElementById('pfDeletePassword').value=pw;
      return true;
    }
    function n(v){return parseFloat(v)||0}
    function pfCalc(){var t=0;document.querySelectorAll('#pfItems tbody tr').forEach(function(tr){var q=n(tr.querySelector('[name="i_qty[]"]').value),p=n(tr.querySelector('[name="i_price[]"]').value);var a=q*p;t+=a;tr.querySelector('.amt').textContent=a.toFixed(2)});document.getElementById('pfTotal').textContent=t.toFixed(2)}
    var pfSzN = 0;
    function pfAddRow(){pfSzN++;var tb=document.querySelector('#pfItems tbody');var tr=document.createElement('tr');tr.innerHTML=`<td class="zpin-l"><input type="hidden" name="i_id[]" value="0"><input class="lovf" data-lov="prod" data-c="name" name="i_name[]" autocomplete="off"><input type="hidden" class="pid" name="i_pid[]" value="0"><div class="linkchip"><span class="un">not linked</span></div></td><td class="zdim"><input data-c="desc" name="i_desc[]"></td><td><input data-c="size" name="i_size[]" list="szlnew${pfSzN}" autocomplete="off" oninput="pfSize(this)" onchange="pfSize(this)"><input type="hidden" class="sid" name="i_size_id[]" value="0"><datalist id="szlnew${pfSzN}"></datalist><div class="linkchip szchip"></div></td><td><input class="num" data-c="qty" type="number" step="0.001" name="i_qty[]" value="0" oninput="pfCalc()"></td><td><input data-c="unit" name="i_unit[]"></td><td><input class="num" data-c="price" type="number" step="0.01" name="i_price[]" value="0" oninput="pfCalc()"></td><td class="zro amt">0.00</td><td class="zpin-r" style="text-align:center"><button type="button" class="rowx" title="Remove this line" onclick="this.closest('tr').remove();pfCalc()">&times;</button></td>`;tb.appendChild(tr);if(window.pfSizeRow)window.pfSizeRow(tr)}
    pfCalc();

    /* ---- the line grid ------------------------------------------------
       assets/js/lov.js is the same List of Values the stock screens use;
       assets/js/grid.js is the spreadsheet keyboard. Written here is only
       what this screen knows: which products may be named, and what
       happens when one is taken. */
    (function(){
      var tb = document.querySelector('#pfItems tbody');
      if(!tb || !window.LOV || !window.GRID) return;

      var MASTER = <?= json_encode(array_map(function($p){
          /* the sizes ride along so the size box can offer the real list and
             resolve the link without a round trip */
          $sz = [];
          try {
              $st = db()->prepare("SELECT id, size_label FROM product_sizes WHERE product_id=? ORDER BY COALESCE(sort_order,0), id");
              $st->execute([(int)$p['id']]);
              foreach ($st->fetchAll() as $r9) $sz[] = ['id'=>(int)$r9['id'], 'label'=>(string)$r9['size_label']];
          } catch (Throwable $e) {}
          return ['id'=>(int)$p['id'], 'name'=>$p['name'], 'unit'=>$p['default_unit'] ?: 'Pcs', 'sizes'=>$sz];
      }, $pfMaster ?? [])) ?>;

      /* ---- THE SIZE LINK -------------------------------------------------
         The box stays free text: what is typed is what prints. Underneath it
         the product's real sizes are offered, and the chip says plainly
         whether the typed text matched one of them.

         WHY THE CHIP EARNS ITS SPACE. Pieces-per-set depends on the size — a
         Double taking two pillow cases is TWO per set, so 500 sets is 1,000
         overlocks. A size that only LOOKS right is exactly what used to make
         production plan half the work with nothing on screen to show for it.
         "not linked" here is the warning that used to be missing. */
      window.pfSizeRow = function(tr){
        var box = tr.querySelector('[data-c="size"]'); if(!box) return;
        var pid  = +(tr.querySelector('.pid')||{}).value || 0;
        var p    = byId(pid);
        var dl   = tr.querySelector('datalist');
        if (dl) dl.innerHTML = (p && p.sizes ? p.sizes : [])
                  .map(function(s){ return '<option value="'+String(s.label).replace(/"/g,'&quot;')+'">'; }).join('');
        pfSize(box);
      };
      /* THE SIZE LINK IS STICKY, AND THIS IS THE FIX YOU ASKED FOR.
       *
       * It used to re-resolve on every keystroke and set the link to 0 the
       * moment the text stopped matching — so typing "90" or the buyer's own
       * wording silently dropped the link, which is the one thing production
       * needs to work out pieces-per-set. The product cell never behaved that
       * way; the two cells disagreed, and the size one was wrong.
       *
       * Now they are identical:
       *   typing a name that IS a size of this product  -> links (or re-links)
       *   rewording a line that is already linked       -> KEEPS the link
       *   emptying the box                              -> drops it
       *   the red cross                                 -> drops it deliberately,
       *                                                    keeping your wording
       */
      /* ============================================================
         THE SIZE IS COMPULSORY — CAUGHT HERE, BEFORE THE ROUND TRIP
         ============================================================

         The server refuses too (pf_missing_sizes), but a server refusal on
         this page costs you everything typed: the save redirects, and the
         form is rebuilt from the database. So the browser is where this has
         to be caught — nothing is lost, and the offending cell is put in
         front of you rather than described in a banner.

         THE RULE IS NOT "EVERY LINE". A line only needs a size when its
         linked product HAS sizes. A one-off typed line, or a product with
         none, has no list to pick from and is left alone. */
      window.pfCheckSizes = function(){
        var bad = [], rows = document.querySelectorAll('#pfItems tbody tr');
        for (var i = 0; i < rows.length; i++) {
          var tr = rows[i];
          var nameEl = tr.querySelector('[data-c="name"]');
          var szEl   = tr.querySelector('[data-c="size"]');
          var sidEl  = tr.querySelector('.sid');
          if (!nameEl || !szEl || !sidEl) continue;
          /* the same skip the save uses, so a blank row is never reported */
          if (!nameEl.value.trim() && !szEl.value.trim()) { tr.classList.remove('needsz'); continue; }
          var p = byId(+(tr.querySelector('.pid') || {}).value || 0);
          var need = !!(p && p.sizes && p.sizes.length);
          var ok   = !need || (+sidEl.value || 0) > 0;
          tr.classList.toggle('needsz', !ok);
          if (!ok) bad.push({ n: i + 1, el: szEl,
                              name: nameEl.value.trim() || 'this line',
                              sizes: p.sizes.map(function(s){ return s.label; }) });
        }
        return bad;
      };
      window.pfSizeGuard = function(e){
        var bad = pfCheckSizes();
        if (!bad.length) return true;
        e.preventDefault();
        var b = document.getElementById('pfSzWarn');
        if (!b) {
          b = document.createElement('div');
          b.id = 'pfSzWarn'; b.className = 'pfszwarn';
          var t = document.getElementById('pfItems');
          if (t && t.parentNode) t.parentNode.insertBefore(b, t);
        }
        var first = bad[0];
        b.innerHTML = '<b>' + bad.length + ' line' + (bad.length === 1 ? '' : 's')
          + ' still need' + (bad.length === 1 ? 's' : '') + ' a size.</b> '
          + 'Production works out how many pieces to make from the size, so without one '
          + 'the order cannot be booked at all.<br>Line ' + first.n + ' &mdash; '
          + LOV.esc(first.name) + ' &mdash; use ' + LOV.esc(first.sizes.slice(0, 8).join(', '))
          + (first.sizes.length > 8 ? ' …' : '') + '.';
        first.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        first.el.focus();
        return false;
      };

      window.pfSize = function(box){
        if (!box) return;
        var tr   = box.closest('tr');
        var sid  = tr.querySelector('.sid');
        var chip = tr.querySelector('.szchip');
        if(!sid||!chip) return;
        var pid  = +(tr.querySelector('.pid')||{}).value || 0;
        var p    = byId(pid);
        var txt  = (box.value||'').trim();
        var low  = txt.toLowerCase();
        var hit  = null;
        if (p && p.sizes) for (var i=0;i<p.sizes.length;i++)
          if (String(p.sizes[i].label).trim().toLowerCase() === low) { hit = p.sizes[i]; break; }

        if (!txt) sid.value = 0;                 // no size typed = no link
        else if (hit) sid.value = hit.id;        // matched = link, or move the link
        /* else: leave sid alone — a reworded line keeps the link it had */

        var cur = +sid.value || 0;
        var lab = '';
        if (cur && p && p.sizes)
          for (var j=0;j<p.sizes.length;j++) if (p.sizes[j].id === cur) lab = p.sizes[j].label;
        /* a link to a size that no longer belongs to this product is dead,
           and saying "linked" about it would be a lie */
        if (cur && !lab) { sid.value = 0; cur = 0; }

        /* the row's own mark is refreshed as you type, so a fixed line stops
           being red without waiting for another failed save */
        var trOwn = tr;
        if (trOwn) {
          var pOwn = p, needOwn = !!(pOwn && pOwn.sizes && pOwn.sizes.length);
          var nEl = trOwn.querySelector('[data-c="name"]');
          var blank = nEl && !nEl.value.trim() && !txt;
          trOwn.classList.toggle('needsz', !blank && needOwn && !(+sid.value || 0));
        }

        if (!txt)        chip.innerHTML = '<span class="un">no size</span>';
        else if (!pid)   chip.innerHTML = '<span class="un">link the product first</span>';
        else if (cur)    chip.innerHTML = (low === String(lab).trim().toLowerCase())
                           ? '<span class="ok">&#10003; ' + LOV.esc(lab) + '</span>' + cutBtn('pfUnlinkSize')
                           : '<span class="ok">&#10003; ' + LOV.esc(lab) + '</span>'
                             + ' <span class="rw" title="Linked. What prints is your wording, not the master size.">&#9998;</span>' + cutBtn('pfUnlinkSize');
        else             chip.innerHTML = '<span class="un">&#9888; not a size of this product</span>';
      };

      function byName(v){
        var n = LOV.norm(v);
        for(var i=0;i<MASTER.length;i++) if(LOV.norm(MASTER[i].name)===n) return MASTER[i];
        return null;
      }
      function byId(id){
        id=+id; if(!id) return null;
        for(var i=0;i<MASTER.length;i++) if(MASTER[i].id===id) return MASTER[i];
        return null;
      }
      /* Four honest states, because "linked" and "not linked" cannot say
         the two things that actually matter: that a reworded line is
         still linked, and that a stored code has gone dead. */
      function paintLink(tr){
        /* :not(.szchip) — the size cell now carries a chip of its own, and
           without this the product's state would be painted into it. */
        var pidEl=tr.querySelector('.pid'), chip=tr.querySelector('.linkchip:not(.szchip)');
        if(!pidEl||!chip) return;
        var pid=+pidEl.value, p=byId(pid);
        var f=tr.querySelector('[data-c="name"]');
        var typed=f?f.value.trim():'';
        if(!pid){ chip.innerHTML='<span class="un">not linked</span>'; return; }
        if(!p){ chip.innerHTML='<span class="un">&#9888; product #'+pid+' no longer in master &mdash; pick again</span>'
                + cutBtn('pfUnlink'); return; }
        chip.innerHTML = (typed && LOV.norm(typed)!==LOV.norm(p.name))
          ? '<span class="ok">&#10003; '+LOV.esc(p.name)+'</span>'
            + ' <span class="rw" title="Linked. What prints is your wording, not the master name.">&#9998;</span>'
            + cutBtn('pfUnlink')
          : '<span class="ok">&#10003; '+LOV.esc(p.name)+'</span>' + cutBtn('pfUnlink');
      }
      /* ONE BUTTON, TWO CELLS. The product and the size behave identically, so
         they share it: a small red cross that drops the LINK and leaves the
         TEXT exactly as typed. */
      function cutBtn(fn){
        return ' <button type="button" class="cut" title="Drop the link — your typed wording is kept"'
             + ' onclick="' + fn + '(this)">&times;</button>';
      }
      /* DROPPING A LINK IS A DELIBERATE ACT, and it must not touch the wording.
         Clearing the box used to be the only way, and that threw away the text
         somebody had typed for printing. */
      window.pfUnlink = function(btn){
        var tr = btn.closest('tr'), pidEl = tr.querySelector('.pid');
        if (pidEl) pidEl.value = 0;
        paintLink(tr); pfSizeRow(tr);
      };
      window.pfUnlinkSize = function(btn){
        var tr = btn.closest('tr'), sid = tr.querySelector('.sid');
        if (sid) sid.value = 0;
        pfSize(tr.querySelector('[data-c="size"]'));
      };

      /* What typing in the name cell does to the link.

         Rewording a linked line KEEPS the link — that is the whole point
         of storing the code: the buyer's wording prints, the code stays.
         Only two things move it: clearing the cell (unlink), or typing a
         name that IS another master product (relink to that one). */
      function relink(tr,val){
        var pidEl=tr.querySelector('.pid');
        if(!pidEl) return;
        if(!String(val).trim()) pidEl.value=0;
        else { var hit=byName(val); if(hit) pidEl.value=hit.id; }
        paintLink(tr);
        pfSizeRow(tr);
      }
      function paintAll(){ [].slice.call(tb.children).forEach(function(tr){ paintLink(tr); pfSizeRow(tr); }); }

      LOV.register('prod', {
        cols:[
          {label:'Product', w:'minmax(160px,1fr)', cls:'nm', get:function(r,q){ return LOV.hl(r.p.name,q); }},
          {label:'Unit',    w:'70px',              cls:'gg', get:function(r){ return LOV.esc(r.p.unit); }}
        ],
        title:function(){ return 'Select product — from Product Master'; },
        empty:function(f,q){
          return q ? 'No product matches that. Type the name anyway — the line is kept, just not linked.'
                   : 'Product Master is empty.';
        },
        rows:function(f,q,showAll,cb){
          var out=[];
          MASTER.forEach(function(p){
            var sc=LOV.score(q,'',p.name,'');
            if(sc>0) out.push({p:p,sc:sc});
          });
          out.sort(function(a,b){ return a.sc!==b.sc ? b.sc-a.sc : a.p.name.localeCompare(b.p.name); });
          cb(out,0);
        },
        pick:function(f,r){
          var tr=f.closest('tr');
          tr.querySelector('.pid').value=r.p.id;
          /* Only replace the printed name when it was empty or still held
             a master name we had put there. Your wording is never lost. */
          if(!f.value.trim() || byName(f.value)) f.value=r.p.name;
          var u=tr.querySelector('[data-c="unit"]');
          if(u && !u.value.trim()) u.value=r.p.unit;
          paintLink(tr);
          pfSizeRow(tr);
          if(SAVE) SAVE.touch();
          var q=tr.querySelector('[data-c="qty"]'); if(q){ q.focus(); q.select(); }
        }
      });
      LOV.attach(tb);

      var SAVE=null;
      function newRow(){ pfAddRow(); var tr=tb.lastElementChild; if(tr){ paintLink(tr); pfSizeRow(tr); } return tr; }

      /* A number cell that could not be read is named, not left blank.
         "1,200" is read as 1200; "1,5" or "approx" is not guessed at. */
      function pasteWarn(bad){
        var box=document.getElementById('pasteWarn');
        if(!box) return;
        var LBL={qty:'Qty',price:'Unit Price'};
        var list=bad.slice(0,6).map(function(b){
          return 'line '+b.row+' '+(LBL[b.col]||b.col)+' ("'+LOV.esc(b.text)+'")';
        }).join(', ');
        box.innerHTML='<b>Check these before saving:</b> '+list
          +(bad.length>6?' and '+(bad.length-6)+' more':'')
          +'. These cells were left exactly as pasted because the number could not be read — type the value in.';
        box.style.display='block';
      }

      var G = GRID.attach(tb, {
        cols:['name','desc','size','qty','unit','price'],
        addRow:newRow,
        onEdit:function(tr,col){
          if(col==='name'){
            var f=tr.querySelector('[data-c="name"]');
            relink(tr, f ? f.value : '');
          }
        },
        /* A pasted block links any name that IS a master product, so a
           column out of Excel arrives already joined up. A pasted name
           that matches nothing REPLACES the line, so unlike typing it
           does clear a stale link. */
        afterRowPaste:function(tr){
          var f=tr.querySelector('[data-c="name"]'), hit=f?byName(f.value):null;
          tr.querySelector('.pid').value = hit ? hit.id : 0;
          if(hit){ var u=tr.querySelector('[data-c="unit"]'); if(u&&!u.value.trim()) u.value=hit.unit; }
          paintLink(tr);
          pfSizeRow(tr);
        },
        afterChange:function(){ pfCalc(); if(SAVE) SAVE.touch(); },
        onPasteProblem:pasteWarn
      });

      SAVE = GRID.keys(document.querySelector('#pfItems').closest('form'), {
        badge:'#pfBadge',
        addRow:newRow,
        focusFirst:function(tr){ G.focusFirst(tr); }
      });

      paintAll();

      /* ---- THE FILTER -------------------------------------------------
         It HIDES rows, it never removes them. A filtered-out line is still
         in the form and still posts on save — filtering is a way of looking
         at the sheet, not a way of editing it. Hiding rather than detaching
         is what makes that true: a detached row would silently stop being
         saved, and the line would vanish from the order with nothing on
         screen to explain it. */
      var q = document.getElementById('pfQ');
      var cnt = document.getElementById('pfCnt');
      function pfFilter(){
        var t = (q ? q.value : '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        var rows = tb.children, shown = 0;
        for (var i = 0; i < rows.length; i++) {
          var tr = rows[i], hay = '';
          ['name','desc','size','unit'].forEach(function(c){
            var el = tr.querySelector('[data-c="' + c + '"]');
            if (el) hay += ' ' + el.value.toLowerCase();
          });
          var on = t.every(function(w){ return hay.indexOf(w) >= 0; });
          tr.hidden = !on;
          if (on) shown++;
        }
        if (cnt) cnt.textContent = t.length
          ? shown + ' of ' + rows.length + ' rows'
          : rows.length + (rows.length === 1 ? ' row' : ' rows');
      }
      if (q) q.addEventListener('input', pfFilter);
      window.pfFilter = pfFilter;
      pfFilter();
    })();
    </script>
    </div><?php /* closes .zskin */ ?>
    <?php
    page_footer(); exit;
}

/* ---------- list ---------- */
$rows=db()->query("SELECT pf.*, p.name pname FROM proforma_invoices pf LEFT JOIN products p ON p.id=pf.product_id ORDER BY pf.id DESC LIMIT 300")->fetchAll();
$canConvertShipment = !is_staff() && can_see_rates();
page_header('Proforma Invoices'); flash();
?>
<div class="zskin">
<div class="topbar"><div><h1>Proforma Invoices</h1><p class="lead">Start blank for a quick quote, or convert one from Product Costing — customer-facing only.</p></div>
  <?php if (costing_perm('proforma')): ?>
  <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="create_blank"><button class="<?= $btnC ?>" style="<?= $btn ?>">+ New Proforma</button></form>
  <?php endif; ?>
</div>
<div class="<?= $cardC ?>" style="<?= $card ?>">
  <?php if ($canConvertShipment): ?>
  <div id="pfBulkBar" style="display:none;margin-bottom:12px;padding:12px 14px;border-radius:12px;background:#eef6ff;border:1px solid #cfe4fb;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <span style="font-size:12.5px;color:#0f4c81;font-weight:700"><span id="pfBulkCount">0</span> proforma(s) selected</span>
    <div style="display:flex;gap:8px">
      <button type="button" class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>;padding:8px 12px;font-size:12px" onclick="pfClearSelection()">Clear</button>
      <button type="submit" form="pfConvertForm" class="<?= $btnC ?>" style="<?= $btn ?>;padding:8px 14px;font-size:12px" onclick="return confirm('Create one new shipment from the selected proforma invoice(s)?')">Convert Selected to Shipment</button>
    </div>
  </div>
  <form method="post" id="pfConvertForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="convert_to_shipment">
  <?php endif; ?>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;min-width:680px">
    <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase"><?php if($canConvertShipment): ?><th style="padding:9px 10px"></th><?php endif; ?><th style="padding:9px 10px">PI No.</th><th style="padding:9px 10px">Date</th><th style="padding:9px 10px">Customer</th><th style="padding:9px 10px">Product</th><th style="padding:9px 10px">Currency</th><th style="padding:9px 10px">Status</th><th></th></tr></thead>
    <tbody>
    <?php if($rows): foreach($rows as $r): ?>
      <tr style="border-top:1px solid #f6f8fc">
        <?php if($canConvertShipment): ?><td style="padding:11px 10px"><input type="checkbox" name="pf_ids[]" value="<?= (int)$r['id'] ?>" class="pfSelChk" onchange="pfUpdateBar()" style="width:16px;height:16px"></td><?php endif; ?>
        <td style="padding:11px 10px;color:#0ea8c9;font-weight:600"><?= e($r['pi_no']) ?></td>
        <td style="padding:11px 10px;color:#5a6b82"><?= e($r['pi_date']) ?></td>
        <td style="padding:11px 10px"><?= e($r['customer_name'] ?: '—') ?></td>
        <td style="padding:11px 10px;color:#5a6b82"><?= e($r['pname'] ?: '—') ?></td>
        <td style="padding:11px 10px"><?= e($r['currency']) ?></td>
        <td style="padding:11px 10px"><?= e(ucfirst($r['status'] ?? 'draft')) ?><?php if(!empty($r['converted_shipment_id'])): ?><br><a style="font-size:11px;color:#16a34a;font-weight:700;text-decoration:none" href="shipment_view.php?id=<?= (int)$r['converted_shipment_id'] ?>">→ Shipment</a><?php endif; ?><?php if(!empty($r['production_enabled'])): ?><br><span style="font-size:10.5px;color:#16a34a;font-weight:700">● Production: <?= e(ucwords(str_replace('_',' ',$r['production_status'] ?? 'not_started'))) ?></span><?php endif; ?></td>
        <td style="padding:11px 10px"><a class="<?= $btnsC ?>" style="<?= $btn.';'.$btns ?>;padding:6px 12px;font-size:12px" href="proforma.php?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; else: ?><tr><td colspan="<?= $canConvertShipment?8:7 ?>" style="padding:26px;text-align:center;color:#8a97ab">No proforma invoices yet. Convert one from Product Costing.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($canConvertShipment): ?></form><?php endif; ?>
  <?php if ($canConvertShipment): ?>
  <script>
  function pfUpdateBar(){var n=document.querySelectorAll('.pfSelChk:checked').length;var bar=document.getElementById('pfBulkBar');document.getElementById('pfBulkCount').textContent=n;bar.style.display=n>0?'flex':'none'}
  function pfClearSelection(){document.querySelectorAll('.pfSelChk').forEach(function(c){c.checked=false});pfUpdateBar()}
  </script>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
