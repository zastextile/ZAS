<?php
/*
  MASTER PRODUCTS — the Fiori look (owner, 22 Nov 2026: "compact, fit nicely,
  professional, same like SAP Fiori and Oracle Fusion, more easy functioning").
  =============================================================================

  THE LIST      One Fiori list (zflist.js), the same engine as Order Status and
                the Proforma list. Every product is printed once; the search,
                the KPI cards, the drop-downs, sorting and the Excel file all
                run in the browser, so typing in the search box asks nothing of
                the server. The making cost per set is worked out here from
                seven whole-table reads — never one query per product.

  THE EDITOR    An object page. ONE PAGE, NO TABS, ONE FORM, ONE SAVE.
                Tabs on a data-entry screen hide state: you fill in one tab,
                move on, and the work on the first is still unsaved with nothing
                on screen saying so. Here a sticky bar jumps between the sections
                (General · Sizes · Parts & rates · Set work) and a sticky footer
                holds the one Save, with "Unsaved changes" shown the moment
                anything differs from what the server sent.

                Sizes are CHIPS: type, Enter. A saved size taken off is struck
                through, not deleted on the spot — click it again to keep it.
                Nothing is removed until Save.

                Parts & rates is ONE GRID. A part row carries its quantity per
                size; ▸ opens its operations underneath, each with a rate per
                size (blank = the part's standard rate). The making cost of one
                set — part work plus set work — is shown once, in the grid's
                footer, and repeated in the header tiles.

  THE RULE THAT GOVERNS THIS WHOLE FILE: A PRODUCT IS NEVER SILENTLY LOST.
  Your product names are pointed at by costing, proformas, invoices and
  shipments. Delete is only offered when nothing anywhere uses the product, and
  it is DISABLED rather than hidden, with the reason on it. When something does
  use it, switching it off (Inactive) is the answer: every document keeps
  working and the product simply stops appearing in new lists.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

/* All of this page's CSS and JavaScript is inside the .php, so a stored copy is
   stale LAYOUT and stale BEHAVIOUR — which is exactly how an uploaded fix comes
   to look like it never arrived. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$search = trim((string)($_GET['q'] ?? ''));

$msg = ''; $err = '';

/* ------------------------------------------------------------------
   ACTIONS
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['product_id'] ?? 0);

    /* ONE PAGE, ONE FORM, SEVERAL BUTTONS.
     *
     * The tabs are gone. Everything about a product is on one screen and one
     * Save writes all of it. That removes the whole class of "I filled in tab 2
     * and lost it because I never pressed Save on tab 1", which is the most
     * expensive thing tabs do to a data-entry screen.
     *
     * Adding and removing a part still need a round trip to the server, so they
     * are ordinary submit buttons INSIDE the same form rather than forms of
     * their own — HTML does not allow a form inside a form, and nesting them is
     * how you get a button that silently submits the wrong thing. */
    /* THE ONE-TIME CATCH-UP. Changing a rate is an admin action, and this
       changes them in bulk on the Costing side, so it is admin only. */
    if ($action === 'sync_costing') {
        if (!is_admin()) { $_SESSION['zp_err'] = 'Only an admin may update Costing.'; }
        else {
            /* THE ONE-TIME SIZE MOVE runs here too. Quantities typed against the
               old production-only size list are re-pointed at the shared one.
               Nothing is deleted — the old table stays exactly as it is. */
            $mig = zp_size_migrate();
            $r = zp_bridge_sync_all();
            $_SESSION['zp_msg'] = $r['products']
                ? 'Costing updated from ' . $r['products'] . ' product' . ($r['products'] == 1 ? '' : 's')
                  . ' (' . $r['ops'] . ' operation rate' . ($r['ops'] == 1 ? '' : 's')
                  . ' sent over). Open a costing sheet and Workmanship will now show the real wage.'
                : 'No product has parts yet, so there was nothing to send to Costing.';
            if (!empty($mig['ran']) && ($mig['moved'] || $mig['added']))
                $_SESSION['zp_msg'] .= ' Sizes merged into one list: ' . $mig['moved'] . ' quantit'
                    . ($mig['moved'] === 1 ? 'y' : 'ies') . ' re-pointed, ' . $mig['added']
                    . ' size' . ($mig['added'] === 1 ? '' : 's') . ' carried across. The old size table was left untouched.';
            elseif (!empty($mig['note']) && empty($mig['ran']))
                $_SESSION['zp_err'] = 'The size merge did not finish: ' . $mig['note'];
        }
        redirect('product_master.php');
    }

    if ($action === 'save_all') {
        $do     = (string)($_POST['do'] ?? 'save');
        $rmPart = (int)($_POST['remove_part'] ?? 0);

        /* ---- the name and the basics ---- */
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $err = 'Product name is required.';
        } else {
            $dup = db()->prepare("SELECT id FROM products WHERE name=? AND id<>?");
            $dup->execute([$name, $pid]);
            if ($dup->fetchColumn()) {
                $err = 'A product called "' . $name . '" already exists. Open that one instead of making a second.';
            } else {
                $fields = [
                    $name,
                    trim((string)($_POST['description'] ?? '')),
                    trim((string)($_POST['category'] ?? '')),
                    trim((string)($_POST['default_unit'] ?? 'Pcs')),
                    ($_POST['fcl_40hc_qty'] ?? '') === '' ? null : (float)str_replace(',', '', (string)$_POST['fcl_40hc_qty']),
                    isset($_POST['is_active']) ? 1 : 0,
                ];
                if ($pid > 0) {
                    $fields[] = $pid;
                    db()->prepare("UPDATE products SET name=?, description=?, category=?, default_unit=?, fcl_40hc_qty=?, is_active=? WHERE id=?")
                        ->execute($fields);
                } else {
                    db()->prepare("INSERT INTO products (name, description, category, default_unit, fcl_40hc_qty, is_active) VALUES (?,?,?,?,?,?)")
                        ->execute($fields);
                    $pid = (int)db()->lastInsertId();
                }

                /* ---- sizes ---- */
                $labels = $_POST['size_label'] ?? [];
                $sz = zp_save_sizes($pid, is_array($labels) ? $labels : []);
                /* A REMOVED SIZE IS REMOVED — and then said out loud.
                   This used to report sizes it had REFUSED to remove. It no
                   longer refuses, so it reports what the removal took with it
                   instead. The part that matters is the last sentence: an order
                   line whose size just went cannot be booked until a size is
                   linked to it again. Saying nothing here would mean finding
                   that out on the floor, mid-shift. */
                $note = '';
                if (!empty($sz['dropped'])) {
                    $one  = count($sz['dropped']) === 1;
                    $note = ' Removed ' . implode(', ', array_map(fn($k) => '"' . $k . '"', $sz['dropped']))
                          . '. Anything set against ' . ($one ? 'it' : 'them')
                          . ' went too, and any order line on ' . ($one ? 'that size' : 'those sizes')
                          . ' cannot be booked until a size is linked to it again.'
                          . ' Wages already booked are unchanged.';
                }

                /* ---- part added or removed on this same submit ---- */
                $extra = '';
                if ($do === 'add_part') {
                    $r = zp_add_product_part($pid, (int)($_POST['add_part_id'] ?? 0));
                    if ($r['ok']) $extra = ' Part added — set how many go in one set.';
                    else          $_SESSION['zp_err'] = $r['error'];
                } elseif ($rmPart > 0) {
                    zp_remove_product_part($pid, $rmPart);
                    $extra = ' Part removed from this product. The part itself, and any wages already booked, are untouched.';
                }

                /* ---- quantities ----
                   Applied AFTER the sizes, and only for sizes that still exist.
                   A size deleted in this same submit takes its id with it, and
                   writing a quantity against a dead id would leave a row nothing
                   can ever reach or correct. */
                $live = [];
                foreach (zp_sizes($pid) as $s2) $live[(int)$s2['id']] = true;
                $q = [];
                foreach (($_POST['qty'] ?? []) as $partId => $bySize) {
                    foreach ($bySize as $sizeId => $v) {
                        if (isset($live[(int)$sizeId])) $q[(int)$partId][(int)$sizeId] = $v;
                    }
                }
                if ($q) {
                    $qr = zp_save_qty($pid, $q);
                    if (!$qr['ok']) $_SESSION['zp_err'] = $qr['error'];
                }

                /* ---- rates that differ by size ----
                   Same rule as the quantities directly above, and for the same
                   reason: a size removed in THIS submit has taken its id with
                   it, and a rate written against a dead id is a row nothing can
                   ever read or correct.

                   Sent for EVERY cell, blank ones included — a blank is what
                   clears an override, so leaving them out would make a cleared
                   cell indistinguishable from one that was never touched. */
                $rr = [];
                foreach (($_POST['oprate'] ?? []) as $opId => $bySize) {
                    foreach ($bySize as $sizeId => $v) {
                        if (isset($live[(int)$sizeId]))
                            $rr[] = ['op' => (int)$opId, 'size' => (int)$sizeId,
                                     'rate' => is_scalar($v) ? trim((string)$v) : ''];
                    }
                }
                if ($rr) {
                    $rres = zp_op_rate_save($pid, $rr, $userId ?? 0);
                    if (!$rres['ok']) $_SESSION['zp_err'] = $rres['error'];
                    elseif ($rres['set'] || $rres['cleared']) {
                        $bits = [];
                        if ($rres['set'])     $bits[] = $rres['set'] . ' size rate' . ($rres['set'] === 1 ? '' : 's') . ' set';
                        if ($rres['cleared']) $bits[] = $rres['cleared'] . ' back to the standard';
                        $note .= ' ' . ucfirst(implode(', ', $bits)) . '.';
                    }
                }

                /* ---- SET WORK — the jobs done to the whole set ----
                   Folding, bagging, boxing. Saved before the bridge below,
                   because Costing's workmanship figure is part work PLUS set
                   work and the bridge is what feeds it.

                   The rows arrive in screen order, so the order they are sent
                   in IS the order they happen in; zp_save_set_ops numbers them
                   from that rather than trusting a sequence the browser could
                   have got wrong. */
                if (isset($_POST['setop'])) {
                    $so = [];
                    foreach ((array)$_POST['setop'] as $row) {
                        if (!is_array($row)) continue;
                        $so[] = [
                            'id'             => (int)($row['id'] ?? 0),
                            'stage_id'       => (int)($row['stage_id'] ?? 0),
                            'operation_name' => (string)($row['operation_name'] ?? ''),
                            'rate'           => (string)($row['rate'] ?? '0'),
                        ];
                    }
                    $sres = zp_save_set_ops($pid, $so, $userId ?? 0);
                    if (!$sres['ok']) $_SESSION['zp_err'] = $sres['error'];
                    else $note .= ' ' . $sres['saved'] . ' set job' . ($sres['saved'] === 1 ? '' : 's') . '.';

                    /* The per-size rates go in AFTER the operations, because a
                       brand-new job has no id until the line above gave it one
                       — and a rate keyed on id 0 belongs to nothing. So the
                       rows are re-read and matched back by position. */
                    if ($sres['ok'] && isset($_POST['setrate'])) {
                        $live2 = zp_set_ops($pid, true);
                        $byPos = [];
                        foreach ($live2 as $ix => $o) $byPos[$ix] = (int)$o['id'];
                        $sr = [];
                        foreach ((array)$_POST['setrate'] as $pos => $bySize) {
                            $opId = $byPos[(int)$pos] ?? 0;
                            if ($opId <= 0 || !is_array($bySize)) continue;
                            foreach ($bySize as $sizeId => $v) {
                                if (!isset($live[(int)$sizeId])) continue;
                                $sr[] = ['set_op_id' => $opId, 'size_id' => (int)$sizeId,
                                         'rate' => is_scalar($v) ? trim((string)$v) : ''];
                            }
                        }
                        if ($sr) zp_save_set_rates($pid, $sr, $userId ?? 0);
                    }
                }

                /* FEED COSTING. product_costing.php reads the OLD tables —
                   product_sizes for its size list, production_operations for
                   the Workmanship rate — and it is not alone: fifteen files do.
                   So the sizes, operations and per-set quantities saved above
                   are mirrored across now, on the same save. Without this a
                   size added here never reaches Costing and Workmanship costs
                   zero, which under-prices the product by its whole wage bill.
                   Nothing on the old side is deleted; see zp_bridge_* . */
                $bridge = zp_bridge_sync_product($pid);
                $fed = $bridge['ok']
                     ? ' Costing updated: ' . $bridge['sizes'] . ' size' . ($bridge['sizes'] == 1 ? '' : 's')
                       . ', ' . $bridge['ops'] . ' operation' . ($bridge['ops'] == 1 ? '' : 's') . '.'
                     : '';
                if (!$bridge['ok'])
                    $_SESSION['zp_err'] = 'Saved here, but Costing was NOT updated: ' . $bridge['error']
                                        . ' Workmanship on this product will read low until this is fixed.';

                if (empty($_SESSION['zp_err'])) $_SESSION['zp_msg'] = 'Saved.' . $note . $extra . $fed;
                redirect('product_master.php?edit=' . $pid);
            }
        }

    } elseif ($action === 'duplicate_product') {
        $src = zp_product($pid);
        if (!$src) { $_SESSION['zp_err'] = 'That product no longer exists.'; redirect('product_master.php'); }
        $base = $src['name'] . ' (Copy'; $name = $base . ')'; $n = 2;
        while (true) {
            $c = db()->prepare("SELECT COUNT(*) FROM products WHERE name=?"); $c->execute([$name]);
            if (!(int)$c->fetchColumn()) break;
            $name = $base . ' ' . $n . ')'; $n++;
            if ($n > 60) { $_SESSION['zp_err'] = 'Too many copies of that product already.'; redirect('product_master.php'); }
        }
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO products (name, description, category, default_unit, fcl_40hc_qty, is_active)
                           VALUES (?,?,?,?,?,0)")
                ->execute([$name, $src['description'] ?? '', $src['category'] ?? '',
                           $src['default_unit'] ?? 'Pcs', $src['fcl_40hc_qty'] ?? null]);
            $newId = (int)db()->lastInsertId();

            /* THE COPY ARRIVES INACTIVE. A duplicate is a starting point, not a
               finished product — it should not appear in anybody's picker until
               somebody has looked at it and turned it on. */
            $sizeMap = [];
            $ins = db()->prepare("INSERT INTO product_sizes (product_id, size_label, sort_order) VALUES (?,?,?)");
            foreach (zp_sizes($pid) as $s) {
                $ins->execute([$newId, $s['size_label'], (int)$s['sort_order']]);
                $sizeMap[(int)$s['id']] = (int)db()->lastInsertId();
            }
            $insP = db()->prepare("INSERT INTO zp_product_parts (product_id, part_id, sort_order) VALUES (?,?,?)");
            foreach (zp_product_parts($pid) as $p) $insP->execute([$newId, (int)$p['id'], (int)$p['sort_order']]);

            $insQ = db()->prepare("INSERT INTO zp_part_qty (product_id, part_id, size_id, qty) VALUES (?,?,?,?)");
            foreach (zp_qty_map($pid) as $partId => $bySize) {
                foreach ($bySize as $oldSize => $q) {
                    if (isset($sizeMap[$oldSize])) $insQ->execute([$newId, (int)$partId, $sizeMap[$oldSize], $q]);
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            $_SESSION['zp_err'] = 'Nothing was copied. ' . $e->getMessage();
            redirect('product_master.php');
        }
        $_SESSION['zp_msg'] = 'Copied, with its sizes, parts and quantities. The copy is switched OFF until you turn it on.';
        redirect('product_master.php?edit=' . $newId);

    } elseif ($action === 'delete_product') {
        /* CHECKED AGAIN HERE, NOT JUST ON THE BUTTON.
           A disabled button is a courtesy; this is the rule. Between the page
           being drawn and this request arriving, somebody could have used the
           product on an invoice. */
        $usage = zp_usage_map();
        /* zp_is_used() is true when a lookup could not RUN, not only when it
           found something. A table we failed to read is not evidence of safety. */
        if (zp_is_used($usage, $pid)) {
            db()->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$pid]);
            $where = $usage[$pid] ?? [];
            $_SESSION['zp_msg'] = $where
                ? 'That product is used in ' . implode(', ', $where)
                  . ', so it has been deactivated instead of deleted. Every document that refers to it still works.'
                : 'Some of the checks could not be run (' . implode(', ', zp_usage_unknown($usage))
                  . '), so the product was deactivated rather than deleted. A check that cannot run is never treated as "safe".';
        } else {
            db()->beginTransaction();
            try {
                db()->prepare("DELETE FROM zp_part_qty WHERE product_id=?")->execute([$pid]);
                db()->prepare("DELETE FROM zp_product_parts WHERE product_id=?")->execute([$pid]);
                /* THE SIZE LIST IS SHARED NOW. These ids are the ones Costing
                   and Proforma point at, so deleting a product's sizes here
                   would orphan a saved costing version. zp_save_sizes() checks
                   every referrer before removing anything; this path is only
                   reached for a product nothing uses at all, so its sizes go
                   with it — but only after the same check. */
                foreach (zp_sizes($pid) as $s9) {
                    if (zp_size_refs((int)$s9['id'])) continue;
                    db()->prepare("DELETE FROM product_sizes WHERE id=?")->execute([(int)$s9['id']]);
                }
                db()->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
                db()->commit();
                $_SESSION['zp_msg'] = 'Product deleted. Nothing anywhere was using it.';
            } catch (Throwable $e) {
                db()->rollBack();
                $_SESSION['zp_err'] = 'Nothing was deleted. ' . $e->getMessage();
            }
        }
        redirect('product_master.php');
    }
}

if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['zp_err'])) { $err = $_SESSION['zp_err']; unset($_SESSION['zp_err']); }

/* ------------------------------------------------------------------
   DATA
   ------------------------------------------------------------------ */
require_once __DIR__ . '/includes/zp_correct.php';     // the stage colours Daily Entry uses

$product   = $editId ? zp_product($editId) : null;
if ($editId && !$product) { $editId = 0; }
$isNew     = isset($_GET['edit']) && $editId === 0;
$usageMap  = zp_usage_map();

/* a number as typed: 2 not 2.00, 2.5 not 2.50 */
function pm_n($v): string { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }

/* THE LIST, FROM SEVEN WHOLE-TABLE READS.
   The making cost of one set needs sizes, parts, operations, quantities and
   both kinds of size rate. Asking per product would be seven queries times
   three hundred products on every visit; reading each table once and adding up
   in PHP is the same answer for a fraction of the work. A table that cannot be
   read simply contributes nothing — the cost then reads low, never wrong-high,
   and the Ready check reads "needs setup", which is the safe direction. */
function pm_list_rows(array $usageMap): array {
    $q = function (string $sql): array { try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; } };
    $sizes = $parts = $ops = $qty = $orate = $sops = $srate = [];
    foreach ($q("SELECT id, product_id, size_label FROM product_sizes ORDER BY sort_order, id") as $r)
        $sizes[(int)$r['product_id']][] = [(int)$r['id'], (string)$r['size_label']];
    foreach ($q("SELECT pp.product_id, p.id, p.part_name FROM zp_product_parts pp JOIN zp_parts p ON p.id = pp.part_id ORDER BY pp.sort_order, pp.id") as $r)
        $parts[(int)$r['product_id']][] = [(int)$r['id'], (string)$r['part_name']];
    foreach ($q("SELECT id, part_id, stage, rate FROM zp_part_ops WHERE is_active=1") as $r)
        $ops[(int)$r['part_id']][] = [(int)$r['id'], (float)$r['rate'], (string)$r['stage']];
    foreach ($q("SELECT product_id, part_id, size_id, qty FROM zp_part_qty") as $r)
        $qty[(int)$r['product_id']][(int)$r['part_id']][(int)$r['size_id']] = (float)$r['qty'];
    foreach ($q("SELECT product_id, part_op_id, size_id, rate FROM zp_op_rate") as $r)
        $orate[(int)$r['product_id']][(int)$r['part_op_id']][(int)$r['size_id']] = (float)$r['rate'];
    foreach ($q("SELECT id, product_id, rate FROM zp_set_ops WHERE is_active=1") as $r)
        $sops[(int)$r['product_id']][] = [(int)$r['id'], (float)$r['rate']];
    foreach ($q("SELECT product_id, set_op_id, size_id, rate FROM zp_set_op_rate") as $r)
        $srate[(int)$r['product_id']][(int)$r['set_op_id']][(int)$r['size_id']] = (float)$r['rate'];

    $rows = [];
    $unknown = zp_usage_unknown($usageMap);
    foreach ($q("SELECT id, name, category, default_unit, description, is_active FROM products ORDER BY name") as $p) {
        $pid = (int)$p['id'];
        $sz  = $sizes[$pid] ?? [];
        $pt  = $parts[$pid] ?? [];
        /* the same arithmetic as the editor's footer: part work at each size
           (a missing quantity is 1, a blank size rate is the standard) plus
           the set work */
        $costs = [];
        foreach ($sz ?: [[0, '']] as [$sid]) {
            $t = 0.0;
            foreach ($pt as [$cid]) {
                $one = 0.0;
                foreach ($ops[$cid] ?? [] as [$oid, $rate]) $one += $orate[$pid][$oid][$sid] ?? $rate;
                $t += $one * ($qty[$pid][$cid][$sid] ?? 1.0);
            }
            foreach ($sops[$pid] ?? [] as [$soid, $rate]) $t += $srate[$pid][$soid][$sid] ?? $rate;
            $costs[] = round($t, 2);
        }
        $why = [];
        if (!$sz) $why[] = 'no sizes';
        if (!$pt) $why[] = 'no parts';
        foreach ($pt as [$cid, $pn]) {
            $po = $ops[$cid] ?? [];
            if (!$po) { $why[] = $pn . ' has no operations'; continue; }
            $cut = false;
            foreach ($po as $o) if (zp_is_cutting($o[2])) { $cut = true; break; }
            if (!$cut) $why[] = $pn . ' has no cutting line';
        }
        $used = $usageMap[$pid] ?? [];
        $rows[] = [
            'id'   => $pid,
            'name' => (string)$p['name'],
            'cat'  => trim((string)$p['category']),
            'unit' => (string)$p['default_unit'],
            'desc' => (string)$p['description'],
            'act'  => (int)$p['is_active'],
            'nsz'  => count($sz),
            'szl'  => implode(', ', array_column($sz, 1)),
            'npt'  => count($pt),
            'ptl'  => implode(', ', array_column($pt, 1)),
            'cmin' => $pt || !empty($sops[$pid]) ? min($costs) : 0,
            'cmax' => $pt || !empty($sops[$pid]) ? max($costs) : 0,
            'ok'   => $why ? 0 : 1,
            'why'  => implode('; ', $why),
            'used' => $used,
            'del'  => ($used || $unknown) ? 0 : 1,
            'delw' => $used ? 'Used in ' . implode(', ', $used) . ' — deleting it would orphan those documents. Switch it to Inactive instead.'
                    : ($unknown ? 'Some checks could not be run (' . implode(', ', $unknown) . '). A check that cannot run is never treated as safe.' : ''),
        ];
    }
    return $rows;
}

if ($editId || $isNew) {
    $sizes     = $editId ? zp_sizes($editId) : [];
    $prodParts = $editId ? zp_product_parts($editId) : [];
    $qtyMap    = $editId ? zp_qty_map($editId) : [];
    $todo      = $editId ? zp_product_todo($editId) : [];
    $libParts  = zp_parts(true);
    $costMap   = zp_part_cost_map();
    $szRates   = $editId ? zp_op_rate_map($editId) : [];
    $setOps    = $editId ? zp_set_ops($editId, true) : [];
    $setRates  = $editId ? zp_set_rate_map($editId) : [];
    $stageAll  = zp_stage_all(true);
    /* parts not yet on this product — what the Add part box can offer */
    $onProduct = array_map(fn($p) => (int)$p['id'], $prodParts);
    $canAdd    = array_values(array_filter($libParts, fn($p) => !in_array((int)$p['id'], $onProduct, true)));
    $partOps   = [];
    foreach ($prodParts as $p) $partOps[(int)$p['id']] = zp_part_ops((int)$p['id'], true);
    $cats = [];
    try { $cats = db()->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    $used   = $editId ? ($usageMap[$editId] ?? []) : [];
    $canDel = $editId && !zp_is_used($usageMap, $editId);
    /* ONE EMPTY SET-WORK ROW WHEN THERE ARE NONE. "+ Add a job" clones the last
       row so it always carries this product's real size columns. A blank row
       saves as nothing, because a blank operation name is skipped. */
    $setRows = $setOps ?: [['id' => 0, 'stage_id' => 0, 'operation_name' => '', 'rate' => 0]];
    $units = ['Pcs', 'Sets', 'Pair', 'Mtr', 'Kg', 'Other'];
    $curUnit = (string)($product['default_unit'] ?? 'Pcs');
    /* A UNIT THAT IS NOT IN THE LIST IS KEPT, not quietly turned into "Pcs"
       on the next Save. */
    if ($curUnit !== '' && !in_array($curUnit, $units, true)) array_unshift($units, $curUnit);
} else {
    $rows = pm_list_rows($usageMap);
}

page_header($editId ? $product['name'] : ($isNew ? 'New Product' : 'Master Products'));
?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<script src="assets/js/zflist.js?v=3"></script>
<style>
.zf.pm{max-width:1560px;min-width:0}
.zf.pm .zf-t th,.zf.pm .pm-g th{text-transform:none !important;letter-spacing:0 !important;font-size:12px !important}
.zf.pm table{min-width:0}
.zf.pm .pm-msg{padding:8px 12px;border-radius:4px;margin:0 0 10px;font-size:12.5px;border:1px solid}
.zf.pm .pm-msg.ok{background:#eef8f0;border-color:#c6e6cf;color:#256f3a}
.zf.pm .pm-msg.bad{background:#fdf0ef;border-color:#f3c6c2;color:#b42318}
.zf.pm .pm-msg.warn{background:#fff7ec;border-color:#f0d3a8;color:#9a5710}
.zf.pm .pm-msg ul{margin:3px 0 0;padding-left:18px}
.zf.pm .pm-note{color:var(--mut);font-size:12px;margin:6px 2px 0}
/* ---- object header ---- */
.zf.pm .pm-obj{background:#fff;border:1px solid var(--line);border-radius:6px 6px 0 0;padding:9px 14px 11px}
.zf.pm .pm-crumb{font-size:12px;color:var(--mut)}
.zf.pm .pm-crumb a{color:var(--pri);text-decoration:none}
.zf.pm .pm-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-top:2px}
.zf.pm .pm-top h1{font-size:19px;font-weight:600;margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.zf.pm .pm-acts{display:flex;gap:6px;flex-wrap:wrap}
.zf.pm .pm-tiles{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.zf.pm .pm-tile{border:1px solid var(--line);border-left:4px solid var(--pri);border-radius:4px;padding:5px 12px;min-width:128px}
.zf.pm .pm-tile span{display:block;font-size:11.5px;font-weight:600;color:var(--mut)}
.zf.pm .pm-tile b{display:block;font-size:18px;font-weight:600;color:var(--pri);font-variant-numeric:tabular-nums}
.zf.pm .pm-tile small{display:block;font-size:11px;color:var(--mut);white-space:nowrap}
.zf.pm .pm-tile.g{border-left-color:#8396a8}.zf.pm .pm-tile.g b{color:var(--ink)}
/* ---- the jump bar ---- */
.zf.pm .pm-nav{position:sticky;top:0;z-index:30;background:#fff;border:1px solid var(--line);border-top:0;
  border-radius:0 0 6px 6px;display:flex;gap:2px;padding:0 8px;margin-bottom:10px;overflow-x:auto;box-shadow:0 2px 6px rgba(16,32,56,.04)}
.zf.pm .pm-nav a{padding:8px 12px 7px;font-weight:600;font-size:12.5px;color:var(--mut);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
.zf.pm .pm-nav a:hover{color:var(--pri)}
.zf.pm .pm-nav a.on{color:var(--pri);border-bottom-color:var(--pri)}
.zf.pm .pm-nav a b{font-weight:600;color:var(--ink);margin-left:3px}
/* ---- sections ---- */
.zf.pm .pm-sec{background:#fff;border:1px solid var(--line);border-radius:6px;margin-bottom:10px;scroll-margin-top:46px}
.zf.pm .pm-sh{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 12px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.zf.pm .pm-sh h3{margin:0;font-size:14px;font-weight:600}
.zf.pm .pm-sh .s2{color:var(--mut);font-size:12px}
.zf.pm .pm-sb{padding:10px 12px}
.zf.pm .pm-empty{padding:18px 12px;color:var(--mut);text-align:center}
/* general fields */
.zf.pm .pm-f{display:grid;grid-template-columns:minmax(220px,2.2fr) minmax(140px,1fr) 110px 130px auto;gap:9px 12px;align-items:end}
.zf.pm .pm-f .w{grid-column:1/-1}
.zf.pm .pm-f label{display:block;min-width:0}
.zf.pm .pm-f label>span{display:block;font-size:12px;font-weight:600;color:var(--mut);margin-bottom:3px}
.zf.pm .pm-f .zf-in{width:100%;max-width:none;height:30px}
.zf.pm .pm-f .zf-in.n{text-align:right;font-variant-numeric:tabular-nums}
.zf.pm .sw,.zf.pm .pm-f label.sw{display:inline-flex;align-items:center;gap:8px;height:30px;cursor:pointer;font-weight:600;color:var(--ink);user-select:none}
.zf.pm .sw input{position:absolute;opacity:0;width:1px;height:1px}
.zf.pm .sw i{width:34px;height:18px;border-radius:9px;background:#c5ced8;position:relative;transition:.15s;flex:none}
.zf.pm .sw i::after{content:"";position:absolute;left:2px;top:2px;width:14px;height:14px;border-radius:50%;background:#fff;transition:.15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.zf.pm .sw input:checked+i{background:#107e3e}
.zf.pm .sw input:checked+i::after{left:18px}
.zf.pm .sw input:focus-visible+i{box-shadow:0 0 0 2px rgba(10,110,209,.35)}
/* size chips */
.zf.pm .chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid var(--line);border-radius:4px;padding:4px 6px;min-height:34px;background:#fff;cursor:text}
.zf.pm .chips:focus-within{border-color:var(--pri);box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.zf.pm .chip{display:inline-flex;align-items:center;gap:2px;height:24px;padding:0 3px 0 10px;border-radius:12px;background:#eaf2fc;border:1px solid #c3dbf5;color:#0a4f96;font-weight:600;font-size:12px;white-space:nowrap}
.zf.pm .chip.new{background:#eef8f0;border-color:#c6e6cf;color:#256f3a}
.zf.pm .chip.gone{background:#fdf0ef;border-color:#f3c6c2;color:#b42318}
.zf.pm .chip.gone .t{text-decoration:line-through}
.zf.pm .chip button{border:0;background:none;width:19px;height:19px;border-radius:50%;cursor:pointer;color:inherit;font-size:13px;line-height:1;padding:0}
.zf.pm .chip button:hover{background:rgba(0,0,0,.08)}
.zf.pm .chip small{font-weight:500;opacity:.75;margin-left:4px}
.zf.pm .chipin{border:0;outline:0;flex:1;min-width:180px;height:24px;font:inherit;font-size:12.5px;background:transparent;color:var(--ink)}
.zf.pm .chipin.bad{color:#b42318}
/* the parts & rates grid */
.zf.pm .pm-tw{overflow:auto;max-height:calc(100vh - 210px)}
.zf.pm table.pm-g{width:100%;border-collapse:collapse}
.zf.pm .pm-g th{position:sticky;top:0;z-index:2;background:#f7f9fb;font-weight:600;color:var(--mut);padding:6px 8px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
.zf.pm .pm-g th.n,.zf.pm .pm-g td.n{text-align:right}
.zf.pm .pm-g td{padding:2px 8px;border-bottom:1px solid var(--soft);vertical-align:middle;height:30px}
.zf.pm .pm-g td.n{font-variant-numeric:tabular-nums;white-space:nowrap}
.zf.pm .pm-g th.sz,.zf.pm .pm-g td.sz{width:96px;min-width:84px}
.zf.pm .pm-g tr.pr>td{background:#f9fafb;border-top:1px solid var(--line)}
.zf.pm .pm-g tr.pr .pn{font-weight:600;color:var(--ink)}
.zf.pm .pm-g tr.pr .pc{color:var(--pri);font-weight:600;text-decoration:none;font-size:11.5px;margin-right:6px}
.zf.pm .pm-g tr.pr .s2{color:var(--mut);font-size:11.5px;margin-left:6px}
.zf.pm .pm-g tr.op td.on{padding-left:34px;color:var(--ink)}
.zf.pm .pm-g tr.op.nops td{color:#9a5710;background:#fff7ec;padding-left:34px}
.zf.pm .pm-g .mut{color:var(--mut);font-size:11.5px}
.zf.pm .stg{display:inline-block;font-size:11px;font-weight:600;padding:0 7px;border-radius:3px;line-height:18px;white-space:nowrap}
.zf.pm .tgb{border:0;background:none;width:22px;height:22px;cursor:pointer;color:var(--mut);border-radius:3px;font-size:11px;transition:transform .15s;padding:0}
.zf.pm .tgb:hover{background:#eaf2fc;color:var(--pri)}
.zf.pm .tgb[aria-expanded="true"]{transform:rotate(90deg);color:var(--pri)}
.zf.pm .pm-g input.cell{width:100%;height:26px;border:1px solid transparent;border-radius:3px;background:transparent;text-align:right;font:inherit;font-size:12.5px;
  font-variant-numeric:tabular-nums;padding:0 20px 0 6px;color:var(--ink);box-sizing:border-box}
.zf.pm .pm-g input.cell:hover{border-color:var(--line)}
.zf.pm .pm-g input.cell:focus{outline:0;border-color:var(--pri);background:#fff;box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.zf.pm .pm-g td.q input.cell{border-color:var(--line);background:#fff;font-weight:600;padding-right:6px}
.zf.pm .pm-g td.szcell{position:relative}
.zf.pm .pm-g td.szcell input::placeholder{color:#9aa8b6}
.zf.pm .pm-g td.szcell.ovr{background:#eef8f0}
.zf.pm .pm-g td.szcell.ovr input{color:#256f3a;font-weight:600}
.zf.pm .pm-g td.szcell .x{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;border:0;background:transparent;
  color:#9aa8b6;font-size:12px;line-height:1;cursor:pointer;border-radius:3px;display:none;padding:0}
.zf.pm .pm-g td.szcell.ovr .x{display:inline-block}
.zf.pm .pm-g td.szcell .x:hover{background:#fdf0ef;color:#b42318}
.zf.pm .pm-g .rm{border:0;background:none;color:#9aa8b6;width:24px;height:24px;border-radius:3px;cursor:pointer;font-size:15px;line-height:1;padding:0}
.zf.pm .pm-g .rm:hover{background:#fdf0ef;color:#b42318}
.zf.pm .pm-g tr.add td{background:#fff;border-top:1px solid var(--line);padding:6px 8px}
.zf.pm .pm-g tr.add select{width:auto;max-width:360px}
.zf.pm .pm-g tr.add .addw{display:inline-flex;gap:6px;align-items:center;flex-wrap:nowrap;white-space:nowrap}
.zf.pm .zf-in.q{width:260px}
.zf.pm .pm-g tfoot td{background:#f7f9fb;border-top:1px solid var(--line);font-weight:600;height:28px}
.zf.pm .pm-g tfoot tr.tot td{color:var(--pri);font-size:13.5px;border-top:2px solid var(--line)}
.zf.pm .pm-g tfoot a{color:var(--pri);text-decoration:none;font-weight:500;font-size:11.5px;margin-left:4px}
.zf.pm .pm-g select.cell{width:100%;height:26px;border:1px solid var(--line);border-radius:3px;background:#fff;font:inherit;font-size:12.5px;padding:0 4px}
.zf.pm .pm-g input.cell.t{text-align:left;border-color:var(--line);background:#fff;padding-right:6px}
.zf.pm .pm-g input.cell.r{border-color:var(--line);background:#fff;padding-right:6px}
.zf.pm .pm-g tr.gone td{opacity:.45}
.zf.pm .leg{display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:7px 12px;border-top:1px solid var(--line);font-size:11.5px;color:var(--mut)}
.zf.pm .leg kbd{font-family:ui-monospace,monospace;font-size:10px;background:#f4f7fb;border:1px solid #e2e9f1;border-bottom-width:2px;border-radius:3px;padding:0 4px;color:#38495f}
.zf.pm .leg i{display:inline-block;width:11px;height:11px;border-radius:2px;vertical-align:-1px;margin-right:4px;border:1px solid var(--line)}
.zf.pm .leg i.o{background:#eef8f0;border-color:#c6e6cf}
.zf.pm .leg .r{margin-left:auto}
/* the one Save */
.zf.pm .pm-foot{position:sticky;bottom:0;z-index:30;background:#fff;border:1px solid var(--line);border-radius:6px;padding:8px 12px;
  display:flex;gap:8px;align-items:center;flex-wrap:wrap;box-shadow:0 -4px 14px rgba(16,32,56,.07)}
.zf.pm .pm-foot .zf-btn{height:32px}
.zf.pm .pm-dirty{font-size:12px;font-weight:600;color:var(--mut)}
.zf.pm .pm-dirty.on{color:#9a5710}
.zf.pm .pm-foot .r{margin-left:auto;font-size:11.5px;color:var(--mut)}
.zf.pm .pm-dflt{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
/* the draft bar */
.zf.pm #pmDraftBar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.zf.pm #pmDraftBar[hidden]{display:none !important}
/* the list */
.zf.pm .zf-t td .use{color:var(--mut);font-size:12px}
.zf.pm .zf-t td.acts{white-space:nowrap;text-align:right}
@media(max-width:900px){.zf.pm .pm-f{grid-template-columns:1fr 1fr}.zf.pm .pm-f .nm{grid-column:1/-1}}
@media(max-width:760px){.zf.pm table.pm-g{min-width:620px}.zf.pm .pm-tile{flex:1 1 140px}.zf.pm .pm-foot .r{display:none}}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so the app's chrome matches the other production pages; the
         page's own look is the .zf one shared with Order Status. */ ?>
<div class="zskin">
<div class="zf pm">

<?php if ($msg): ?><div class="pm-msg ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="pm-msg bad"><?= e($err) ?></div><?php endif; ?>

<?php if (!$editId && !$isNew): /* ===================== THE LIST ===================== */ ?>

  <div class="zf-hd">
    <div>
      <h1>Master Products</h1>
      <div class="zf-sub">What each product is made of, its sizes, and what one set costs to make.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <a class="zf-btn" href="part_library.php">Part Library</a>
      <a class="zf-btn" href="products_csv.php">Products CSV</a>
      <?php if (is_admin()): /* THE CATCH-UP. Sends every product's sizes and
           wage rates over to Costing in one go. Safe to press twice — it
           rewrites, it never doubles. */ ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Send every product\'s sizes and wage rates over to Costing?\n\nNothing is deleted. Any old operation rate that would double-count is switched off, not removed.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync_costing">
        <button class="zf-btn" title="Fix Workmanship reading zero on old costings">Update Costing</button>
      </form>
      <?php endif; ?>
      <a class="zf-btn pri" href="product_master.php?edit=0">+ New product</a>
      <span style="font-size:10px;color:#a7b2c4;white-space:nowrap;font-family:ui-monospace,monospace"
            title="Modified date of this file on the server. Older than the zip you uploaded means it never landed.">build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <div id="pmList"></div>
  <div class="pm-note">
    <b>Delete</b> is only possible when nothing uses the product. If it is used in production, a proforma, an invoice
    or a shipment, switch it to <b>Inactive</b> instead — it leaves new lists and every existing document keeps working.
  </div>

  <form method="post" id="pmAct" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value=""><input type="hidden" name="product_id" value="0">
  </form>

  <script>
  (function(){
    var ROWS = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var esc = ZF.esc, n2 = ZF.n2;
    function cost(r){ return r.cmax ? (r.cmin === r.cmax ? n2(r.cmin) : n2(r.cmin) + ' – ' + n2(r.cmax)) : '—'; }
    window.pmAct = function(act, id, name){
      if (act === 'delete_product' && !confirm('Delete ' + name + '?\n\nNothing is using it, so this really deletes it. It cannot be undone.')) return;
      var f = document.getElementById('pmAct');
      f.elements.action.value = act; f.elements.product_id.value = id; f.submit();
    };
    <?php if ($search !== ''): ?>try { if (!location.hash) location.hash = 'q=' + encodeURIComponent(<?= json_encode($search) ?>); } catch (e) {}<?php endif; ?>
    ZFList({
      root: 'pmList', rows: ROWS, sort: 'name', dir: 1, csvName: 'master-products',
      searchHint: 'Search product, category, size, part…',
      hay: function(r){ return [r.name, r.cat, r.unit, r.desc, r.szl, r.ptl, (r.used || []).join(' ')].join(' '); },
      href: function(r){ return 'product_master.php?edit=' + r.id; },
      kpis: [
        { id: 'all',  label: 'Products', cls: 'k-blue',  value: function(a){ return [a.length, a.filter(function(r){ return r.act; }).length + ' active']; }, test: function(){ return true; } },
        { id: 'ok',   label: 'Ready to produce', cls: 'k-green', value: function(a){ var n = a.filter(function(r){ return r.act && r.ok; }).length; return [n, 'active, with parts, operations and cutting']; }, test: function(r){ return r.act && r.ok; } },
        { id: 'need', label: 'Needs setup', cls: 'k-amber', value: function(a){ var n = a.filter(function(r){ return r.act && !r.ok; }).length; return [n, 'active, but something is missing']; }, test: function(r){ return r.act && !r.ok; } },
        { id: 'off',  label: 'Inactive', cls: 'k-grey', value: function(a){ var n = a.filter(function(r){ return !r.act; }).length; return [n, 'not offered in new lists']; }, test: function(r){ return !r.act; } },
        { id: 'free', label: 'Not used anywhere', cls: 'k-grey', value: function(a){ var n = a.filter(function(r){ return r.del; }).length; return [n, 'can be deleted']; }, test: function(r){ return r.del; } }
      ],
      selects: [{ k: 'cat', label: 'Category' }, { k: 'unit', label: 'Unit' }],
      cols: [
        { k: 'name', label: 'Product', sort: function(r){ return r.name.toLowerCase(); }, html: function(r){
            return '<a class="lk" href="product_master.php?edit=' + r.id + '">' + esc(r.name) + '</a>'
              + (r.cat || r.desc ? '<div class="s2">' + esc([r.cat, r.desc].filter(Boolean).join(' · ')) + '</div>' : ''); } },
        { k: 'unit', label: 'Unit', w: '64px', sort: function(r){ return r.unit; }, html: function(r){ return esc(r.unit); } },
        { k: 'nsz', label: 'Sizes', sort: function(r){ return r.nsz; }, html: function(r){
            return r.nsz ? '<span class="nw" title="' + esc(r.szl) + '">' + esc(r.szl) + '</span>' : '<span class="s2">—</span>'; } },
        { k: 'npt', label: 'Parts', sort: function(r){ return r.npt; }, html: function(r){
            return r.npt ? '<span class="nw" title="' + esc(r.ptl) + '">' + esc(r.ptl) + '</span>' : '<span class="s2">—</span>'; } },
        { k: 'cmax', label: 'Making cost / set', num: true, sort: function(r){ return r.cmax; }, html: function(r){ return cost(r); } },
        { k: 'ok', label: 'Setup', sort: function(r){ return r.ok; }, html: function(r){
            return r.ok ? '<span class="zf-pill p-ok">Ready</span>'
                        : '<span class="zf-pill p-sub" title="' + esc(r.why) + '">Needs setup</span><div class="s2 nw" title="' + esc(r.why) + '">' + esc(r.why) + '</div>'; } },
        { k: 'act', label: 'Status', sort: function(r){ return r.act; }, html: function(r){
            return r.act ? '<span class="zf-pill p-ok">Active</span>' : '<span class="zf-pill p-grey">Inactive</span>'; } },
        { k: 'used', label: 'Used in', sort: function(r){ return (r.used || []).length; }, html: function(r){
            return (r.used || []).length ? '<span class="use">' + esc(r.used.join(', ')) + '</span>' : '<span class="s2">—</span>'; } },
        { k: 'x', label: '', html: function(r){
            return '<span class="acts"><a class="zf-btn sm" href="product_master.php?edit=' + r.id + '">Edit</a> '
              + '<button type="button" class="zf-btn sm" title="Copy with its sizes, parts and quantities. The copy starts Inactive." onclick="pmAct(\'duplicate_product\',' + r.id + ')">Copy</button> '
              + (r.del ? '<button type="button" class="zf-btn sm danger" onclick="pmAct(\'delete_product\',' + r.id + ',' + esc(JSON.stringify(r.name)) + ')">Delete</button>'
                       : '<button type="button" class="zf-btn sm off" disabled title="' + esc(r.delw) + '">Delete</button>')
              + '</span>'; } }
      ],
      csv: [['Product', function(r){ return r.name; }], ['Category', function(r){ return r.cat; }], ['Unit', function(r){ return r.unit; }],
            ['Sizes', function(r){ return r.szl; }], ['Parts', function(r){ return r.ptl; }],
            ['Making cost / set (min)', function(r){ return r.cmin.toFixed(2); }], ['Making cost / set (max)', function(r){ return r.cmax.toFixed(2); }],
            ['Setup', function(r){ return r.ok ? 'Ready' : 'Needs setup: ' + r.why; }], ['Status', function(r){ return r.act ? 'Active' : 'Inactive'; }],
            ['Used in', function(r){ return (r.used || []).join(', '); }]],
      empty: 'No products yet. Bring your list in from Products CSV, or press + New product.'
    });
  })();
  </script>

<?php else: /* ===================== THE EDITOR — ONE PAGE, ONE FORM ===================== */
  $nS = count($sizes);
  $cols = 5 + $nS;                         // toggle · name · stage · std · sizes… · remove
?>

  <div class="pm-obj">
    <div class="pm-crumb"><a href="product_master.php">Master Products</a> &rsaquo; <?= $editId ? e($product['name']) : 'New product' ?></div>
    <div class="pm-top">
      <div>
        <h1><?= $editId ? e($product['name']) : 'New product' ?>
          <?php if ($editId): ?>
            <?= (int)$product['is_active'] ? '<span class="zf-pill p-ok">Active</span>' : '<span class="zf-pill p-grey">Inactive</span>' ?>
            <?= $todo ? '<span class="zf-pill p-sub">Needs setup</span>' : '<span class="zf-pill p-ok">Ready to produce</span>' ?>
          <?php endif; ?>
        </h1>
        <div class="zf-sub">
          <?php if ($editId): ?>
            <?= e(implode(' · ', array_filter([trim((string)$product['category']), $curUnit]))) ?>
            &middot; <?= $nS ?> size<?= $nS === 1 ? '' : 's' ?> &middot; <?= count($prodParts) ?> part<?= count($prodParts) === 1 ? '' : 's' ?>
            &middot; Used in: <?= $used ? e(implode(', ', $used)) : 'nothing yet' ?>
          <?php else: ?>
            Give it a name and its sizes, press Create, then add its parts.
          <?php endif; ?>
        </div>
      </div>
      <div class="pm-acts">
        <a class="zf-btn" href="part_library.php">Part Library</a>
        <?php if ($editId): ?>
          <button type="submit" class="zf-btn" form="pmDup" title="Copy with its sizes, parts and quantities. The copy starts Inactive.">Duplicate</button>
          <?php if ($canDel): ?>
            <button type="submit" class="zf-btn danger" form="pmDel"
                    onclick="return confirm('Delete this product?\n\nNothing is using it, so this really deletes it. It cannot be undone.')">Delete</button>
          <?php else: /* DISABLED, NOT HIDDEN — with the reason on it */ ?>
            <button type="button" class="zf-btn off" disabled
                    title="<?= $used ? 'Used in ' . e(implode(', ', $used)) . ' — deleting it would orphan those documents. Switch it to Inactive instead.'
                                     : 'Some checks could not be run (' . e(implode(', ', zp_usage_unknown($usageMap))) . '). A check that cannot run is never treated as safe.' ?>">Delete</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($editId && $todo): ?>
      <div class="pm-msg warn" style="margin:9px 0 0"><b>Before this product can be produced:</b>
        <ul><?php foreach ($todo as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if ($editId && $nS && ($prodParts || $setOps)): ?>
      <div class="pm-tiles" title="Making cost of one set = part work at that size + set work. Wages only: no fabric, no mark-up.">
        <?php foreach ($sizes as $s): ?>
          <div class="pm-tile" data-tile="<?= (int)$s['id'] ?>">
            <span><?= e($s['size_label']) ?> &middot; making cost / set</span>
            <b>0.00</b><small>parts 0.00 &middot; set work 0.00</small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <nav class="pm-nav" id="pmNav">
    <a href="#pmGen" class="on">General</a>
    <a href="#pmSizes">Sizes<b><?= $nS ?></b></a>
    <?php if ($editId): ?>
      <a href="#pmParts">Parts &amp; rates<b><?= count($prodParts) ?></b></a>
      <a href="#pmSet">Set work<b><?= count($setOps) ?></b></a>
    <?php endif; ?>
  </nav>

  <?php /* THE DRAFT BAR — hidden until there is genuinely something to offer. */ ?>
  <div id="pmDraftBar" class="pm-msg warn" hidden>
    <span><b>Unsaved work found.</b> <span id="pmDraftWhen"></span>
      It is only in this browser &mdash; nothing was sent to the server.</span>
    <span style="margin-left:auto;display:flex;gap:6px">
      <button type="button" class="zf-btn sm ok" id="pmDraftYes">Put it back</button>
      <button type="button" class="zf-btn sm" id="pmDraftNo">Discard</button>
    </span>
  </div>

  <?php /* ONE FORM AROUND EVERYTHING. Name, sizes, parts, quantities, size rates
           and set work are all written by the single Save. Duplicate and
           Delete are their own small forms further down — HTML does not allow a
           form inside a form. */ ?>
  <form method="post" id="pmForm" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_all">
    <input type="hidden" name="product_id" value="<?= (int)$editId ?>">
    <?php /* ENTER IN A TEXT BOX SAVES, and nothing else. The browser presses the
             FIRST submit button in the form on Enter — without this one that
             was "+ Add part" or a part's remove button. */ ?>
    <button type="submit" name="do" value="save" class="pm-dflt" tabindex="-1" aria-hidden="true">Save</button>

    <!-- ============ General ============ -->
    <section class="pm-sec" id="pmGen">
      <div class="pm-sh"><h3>General</h3></div>
      <div class="pm-sb pm-f">
        <label class="nm"><span>Product name *</span>
          <input class="zf-in" name="name" required maxlength="200" value="<?= e($product['name'] ?? '') ?>" placeholder="e.g. Duvet Set Dyed"></label>
        <label><span>Category</span>
          <input class="zf-in" name="category" maxlength="120" list="pmCats" value="<?= e($product['category'] ?? '') ?>" placeholder="Bedding"></label>
        <datalist id="pmCats"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
        <label><span>Unit</span>
          <select class="zf-in" name="default_unit">
            <?php foreach ($units as $u): ?><option <?= $curUnit === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?>
          </select></label>
        <label title="Pieces that load into one 40ft HC container."><span>40ft HC capacity</span>
          <input class="zf-in n" name="fcl_40hc_qty" inputmode="decimal"
                 value="<?= $product && $product['fcl_40hc_qty'] !== null ? e(pm_n($product['fcl_40hc_qty'])) : '' ?>" placeholder="32000"></label>
        <label class="sw" title="Inactive products stay on every document but are not offered in new lists.">
          <input type="checkbox" name="is_active" value="1" <?= ($editId ? (int)$product['is_active'] : 1) ? 'checked' : '' ?>><i></i> Active</label>
        <label class="w"><span>Description</span>
          <input class="zf-in" name="description" maxlength="255" value="<?= e($product['description'] ?? '') ?>" placeholder="Optional"></label>
      </div>
    </section>

    <!-- ============ Sizes ============ -->
    <section class="pm-sec" id="pmSizes">
      <div class="pm-sh"><h3>Sizes</h3>
        <span class="s2">Type a size and press Enter. Each size is a column in Parts &amp; rates. Paste "Single, Double, King" to add several.</span></div>
      <div class="pm-sb">
        <div class="chips" id="sizeChips" onclick="if(event.target===this)document.getElementById('sizeIn').focus()">
          <?php foreach ($sizes as $s): ?>
            <span class="chip" data-saved="1"><input type="hidden" name="size_label[]" value="<?= e($s['size_label']) ?>"><span class="t"><?= e($s['size_label']) ?></span><button type="button" title="Remove this size">&times;</button></span>
          <?php endforeach; ?>
          <input class="chipin" id="sizeIn" maxlength="60" placeholder="<?= $sizes ? 'Add a size…' : 'e.g. Single — then Enter' ?>">
        </div>
        <div class="pm-note" id="sizeNote" hidden></div>
      </div>
    </section>

    <!-- ============ Parts & rates — ONE GRID ============ -->
    <?php if ($editId): ?>
    <section class="pm-sec" id="pmParts">
      <div class="pm-sh"><h3>Parts &amp; rates</h3>
        <?php if ($nS && $prodParts): ?>
          <span style="display:flex;gap:6px;align-items:center">
            <span class="s2">Part rows: how many go in one set. &#9656; opens its operations and rates by size.</span>
            <button type="button" class="zf-btn sm" id="pmOpenAll">Open all</button>
          </span>
        <?php endif; ?>
      </div>
      <?php if (!$nS): ?>
        <div class="pm-empty">Add at least one size above and press <b>Save</b> &mdash; then the parts grid appears.</div>
      <?php else: ?>
      <div class="pm-tw">
      <table class="pm-g" id="pmGrid">
        <thead><tr>
          <th style="width:28px"></th><th>Part &middot; operation</th><th style="width:96px">Stage</th>
          <th class="n" style="width:84px" title="The part's own rate, from the Part Library">Std rate</th>
          <?php foreach ($sizes as $s): ?><th class="n sz"><?= e($s['size_label']) ?></th><?php endforeach; ?>
          <th style="width:32px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($prodParts as $p): $cid = (int)$p['id']; $pops = $partOps[$cid]; ?>
          <tr class="pr" data-part="<?= $cid ?>">
            <td><button type="button" class="tgb" aria-expanded="false" title="Show the operations">&#9654;</button></td>
            <td><a class="pc" href="part_library.php?part=<?= $cid ?>#partform" title="Edit in Part Library — operations and standard rates"><?= e(zp_part_code($cid)) ?></a><span class="pn"><?= e($p['part_name']) ?></span><span class="s2"><?= count($pops) ?> op<?= count($pops) === 1 ? '' : 's' ?><?= trim((string)$p['style']) !== '' ? ' · ' . e($p['style']) : '' ?></span></td>
            <td class="mut">qty / set</td>
            <td class="n mut" title="Standard cost of one piece"><?= number_format((float)($costMap[$cid] ?? 0), 2) ?> /pc</td>
            <?php foreach ($sizes as $s): $sid = (int)$s['id']; ?>
              <td class="sz q"><input class="cell qz" inputmode="decimal" data-part="<?= $cid ?>" data-size="<?= $sid ?>"
                     name="qty[<?= $cid ?>][<?= $sid ?>]" value="<?= e(pm_n(zp_qty_for($qtyMap, $cid, $sid))) ?>"
                     title="<?= e($p['part_name']) ?> per <?= e($s['size_label']) ?> set"></td>
            <?php endforeach; ?>
            <td><button type="submit" class="rm" name="remove_part" value="<?= $cid ?>" formnovalidate title="Take this part off this product"
                        onclick="return confirm('Take <?= e(addslashes($p['part_name'])) ?> off this product?\n\nThe part stays in the library, and wages already booked are untouched.')">&times;</button></td>
          </tr>
          <?php if (!$pops): ?>
            <tr class="op nops" data-part="<?= $cid ?>" hidden><td colspan="<?= $cols ?>">No operations yet, so this part costs nothing and cannot be produced.
              <a href="part_library.php?part=<?= $cid ?>#partform">Add its Cutting line in the Part Library &rarr;</a></td></tr>
          <?php else: foreach ($pops as $o): $oid = (int)$o['id']; ?>
            <tr class="op" data-part="<?= $cid ?>" hidden>
              <td></td>
              <td class="on"><?= e($o['operation_name']) ?></td>
              <td><span class="stg" style="<?= e(ze_stage_css((string)$o['stage'])) ?>"><?= e($o['stage']) ?></span></td>
              <td class="n"><?= number_format((float)$o['rate'], 2) ?></td>
              <?php foreach ($sizes as $s): $sid = (int)$s['id']; $has = isset($szRates[$oid][$sid]); ?>
                <td class="sz szcell<?= $has ? ' ovr' : '' ?>">
                  <input class="cell ratein" inputmode="decimal" name="oprate[<?= $oid ?>][<?= $sid ?>]"
                         data-std="<?= e(number_format((float)$o['rate'], 2, '.', '')) ?>"
                         value="<?= $has ? e(number_format((float)$szRates[$oid][$sid], 2, '.', '')) : '' ?>"
                         placeholder="<?= e(number_format((float)$o['rate'], 2, '.', '')) ?>"
                         title="<?= $has ? 'Set for ' . e($s['size_label']) . ' only. Blank it to go back to the standard.'
                                         : 'Standard rate. Type to set one for ' . e($s['size_label']) . ' only.' ?>">
                  <button type="button" class="x" tabindex="-1" title="Back to the standard rate">&times;</button>
                </td>
              <?php endforeach; ?>
              <td></td>
            </tr>
          <?php endforeach; endif; ?>
        <?php endforeach; ?>
          <tr class="add"><td></td><td colspan="<?= $cols - 1 ?>">
            <?php if (!$libParts): ?>
              <span class="mut">Your Part Library is empty. <a href="part_library.php">Define a part first</a> &mdash; a piece you make, with its operations and rates.</span>
            <?php elseif ($canAdd): ?>
              <span class="addw">
                <select class="zf-in" name="add_part_id" id="pmAddSel">
                  <option value="">Add a part from the library&hellip;</option>
                  <?php foreach ($canAdd as $p): $cid = (int)$p['id']; ?>
                    <option value="<?= $cid ?>"><?= e(zp_part_code($cid)) ?> &mdash; <?= e($p['part_name']) ?> (<?= number_format((float)($costMap[$cid] ?? 0), 2) ?> / <?= e($p['uom']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <button class="zf-btn sm pri" name="do" value="add_part" id="pmAddBtn" disabled>+ Add part</button>
                <a class="zf-btn sm" href="part_library.php">New part in library</a>
              </span>
            <?php else: ?>
              <span class="mut">Every part in the library is already on this product. <a href="part_library.php">Add a new part to the library</a></span>
            <?php endif; ?>
          </td></tr>
        </tbody>
        <?php if ($prodParts || $setOps): ?>
        <tfoot>
          <tr><td></td><td colspan="3">Part work / set</td>
            <?php foreach ($sizes as $s): ?><td class="n sz" data-pw="<?= (int)$s['id'] ?>">0.00</td><?php endforeach; ?><td></td></tr>
          <tr><td></td><td colspan="3">Set work / set <a href="#pmSet">edit &darr;</a></td>
            <?php foreach ($sizes as $s): ?><td class="n sz" data-sw="<?= (int)$s['id'] ?>">0.00</td><?php endforeach; ?><td></td></tr>
          <tr class="tot"><td></td><td colspan="3">Making cost of one set</td>
            <?php foreach ($sizes as $s): ?><td class="n sz" data-tot="<?= (int)$s['id'] ?>">0.00</td><?php endforeach; ?><td></td></tr>
        </tfoot>
        <?php endif; ?>
      </table>
      </div>
      <div class="leg">
        <span><i></i>blank = the part's standard rate</span>
        <span><i class="o"></i>green = a rate for that size only</span>
        <span><kbd>Enter</kbd> down &middot; <kbd>Esc</kbd> back to standard &middot; <kbd>Ctrl</kbd>+<kbd>D</kbd> copy the cell above &middot; paste a block from Excel</span>
        <span class="r" id="ovrCount"></span>
      </div>
      <?php endif; ?>
    </section>

    <!-- ============ Set work — done to the whole set ============ -->
    <section class="pm-sec" id="pmSet">
      <div class="pm-sh"><h3>Set work</h3>
        <span class="s2">Folding, matching, bagging, cartoning &mdash; done to the whole set, not to one part. This product's own; an order can take its own copy.</span></div>
      <?php if (!$stageAll): ?>
        <div class="pm-empty">No stages are set up yet. Add them on <a href="production_stages.php">Production Stages</a>.</div>
      <?php else: ?>
      <div class="pm-tw">
      <table class="pm-g" id="setTbl">
        <thead><tr>
          <th style="width:28px">#</th><th style="width:150px">Stage</th><th style="min-width:160px">Operation</th>
          <th class="n" style="width:84px">Rate</th>
          <?php foreach ($sizes as $s): ?><th class="n sz"><?= e($s['size_label']) ?></th><?php endforeach; ?>
          <th style="width:32px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($setRows as $ix => $o): $oid = (int)$o['id']; ?>
          <tr>
            <td class="mut"><?= $ix + 1 ?></td>
            <td><input type="hidden" name="setop[<?= $ix ?>][id]" value="<?= $oid ?>">
              <select class="cell" name="setop[<?= $ix ?>][stage_id]">
                <option value="0">&mdash; none &mdash;</option>
                <?php foreach ($stageAll as $st): ?>
                  <option value="<?= (int)$st['id'] ?>" <?= (int)($o['stage_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option>
                <?php endforeach; ?>
              </select></td>
            <td><input class="cell t" name="setop[<?= $ix ?>][operation_name]" maxlength="120" value="<?= e((string)$o['operation_name']) ?>" placeholder="e.g. Fold &amp; poly bag"></td>
            <td class="n"><input class="cell r setr" name="setop[<?= $ix ?>][rate]" inputmode="decimal" value="<?= e(pm_n($o['rate'])) ?>"></td>
            <?php foreach ($sizes as $s): $sid = (int)$s['id']; $ov = $setRates[$oid][$sid] ?? null; ?>
              <?php /* BLANK MEANS "THE RATE ON THE LEFT" — a filled cell is an
                       override, and one that copied the standard would stop
                       following it the next time the standard moved. */ ?>
              <td class="sz"><input class="cell sets" name="setrate[<?= $ix ?>][<?= $sid ?>]" inputmode="decimal"
                         placeholder="&mdash;" data-size="<?= $sid ?>" value="<?= $ov === null ? '' : e(pm_n($ov)) ?>"></td>
            <?php endforeach; ?>
            <td><button type="button" class="rm" tabindex="-1" title="Remove this job on Save" onclick="zpSetDrop(this)">&times;</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td></td><td colspan="2">Set work per set &nbsp;<button type="button" class="zf-btn sm" onclick="zpSetAdd()">+ Add a job</button></td>
          <td class="n" id="setTot">0.00</td>
          <?php foreach ($sizes as $s): ?><td class="n sz setTotS" data-size="<?= (int)$s['id'] ?>">0.00</td><?php endforeach; ?>
          <td></td>
        </tr></tfoot>
      </table>
      </div>
      <div class="leg"><span>A blank size cell means the rate on the left. A removed job is switched off on Save, never deleted, so wages already booked keep their name.</span></div>
      <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="pm-sec"><div class="pm-empty">Press <b>Create product</b> &mdash; then you can add its parts and set work here.</div></section>
    <?php endif; ?>

    <!-- ============ the one Save, for all of it ============ -->
    <div class="pm-foot">
      <button class="zf-btn ok" name="do" value="save"><?= $editId ? 'Save' : 'Create product' ?></button>
      <a class="zf-btn" href="product_master.php">Cancel</a>
      <span class="pm-dirty" id="pmDirty">No changes</span>
      <span class="r"><kbd style="font-size:10px">Ctrl</kbd>+<kbd style="font-size:10px">S</kbd> saves &middot; one Save writes the name, sizes, quantities, size rates and set work</span>
    </div>
  </form>

  <?php if ($editId): ?>
    <form method="post" id="pmDup" hidden><?= csrf_field() ?><input type="hidden" name="action" value="duplicate_product"><input type="hidden" name="product_id" value="<?= (int)$editId ?>"></form>
    <form method="post" id="pmDel" hidden><?= csrf_field() ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="<?= (int)$editId ?>"></form>
  <?php endif; ?>

<script>
/* ==================================================================
   SIZES AS CHIPS
   A saved size taken off is STRUCK THROUGH, not removed: its hidden input is
   disabled so it is not sent, and clicking it again brings it back. Nothing
   leaves the database until Save — and the Save message then says what the
   removal took with it.
   ================================================================== */
(function(){
  var box = document.getElementById('sizeChips'), inp = document.getElementById('sizeIn');
  if (!box || !inp) return;
  var note = document.getElementById('sizeNote');
  function labels(){ return [].map.call(box.querySelectorAll('.chip'), function(c){ return c.querySelector('.t').textContent.trim().toLowerCase(); }); }
  function say(){
    var gone = box.querySelectorAll('.chip.gone').length, nw = box.querySelectorAll('.chip.new').length, t = [];
    if (nw) t.push(nw + ' new size' + (nw === 1 ? '' : 's') + ' — the grid gets ' + (nw === 1 ? 'its column' : 'their columns') + ' after Save.');
    if (gone) t.push(gone + ' size' + (gone === 1 ? '' : 's') + ' will be removed on Save, with ' + (gone === 1 ? 'its' : 'their') + ' quantities and size rates. An order line on a removed size cannot be booked until a size is linked again. Click a struck size to keep it.');
    note.textContent = t.join(' '); note.hidden = !t.length;
    box.dispatchEvent(new Event('input', { bubbles: true }));
  }
  window.zpAddSize = function(v){
    v = String(v == null ? '' : v).trim().slice(0, 60);
    if (!v) return null;
    if (labels().indexOf(v.toLowerCase()) >= 0) { inp.classList.add('bad'); setTimeout(function(){ inp.classList.remove('bad'); }, 900); return null; }
    var c = document.createElement('span');
    c.className = 'chip new';
    c.innerHTML = '<input type="hidden" name="size_label[]"><span class="t"></span><small>new</small><button type="button" title="Remove">&times;</button>';
    c.querySelector('input').value = v; c.querySelector('.t').textContent = v;
    box.insertBefore(c, inp);
    say();
    return c;
  };
  box.addEventListener('click', function(e){
    var b = e.target.closest('.chip button'), c = e.target.closest('.chip');
    if (!c) return;
    if (c.classList.contains('gone')) { c.classList.remove('gone'); c.querySelector('input').disabled = false; say(); return; }
    if (!b) return;
    if (c.dataset.saved) { c.classList.add('gone'); c.querySelector('input').disabled = true; c.title = 'Click to keep this size'; }
    else c.remove();
    say();
  });
  /* ENTER ADDS THE SIZE — it must NOT submit the form, or typing four sizes
     would save and reload after the first. A comma does the same, and a pasted
     list is split. Backspace in an empty box takes off the last NEW chip. */
  inp.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab' && inp.value.trim()) {
      e.preventDefault();
      if (zpAddSize(inp.value)) inp.value = '';
    } else if (e.key === 'Backspace' && !inp.value) {
      var n = box.querySelectorAll('.chip.new'); if (n.length) { n[n.length - 1].remove(); say(); }
    }
  });
  inp.addEventListener('paste', function(e){
    var t = (e.clipboardData || window.clipboardData).getData('text') || '';
    if (!/[,\t\n;]/.test(t)) return;
    e.preventDefault();
    t.split(/[,\t\n;]+/).forEach(function(v){ zpAddSize(v); });
  });
  /* a size typed but not yet Entered is still added on Save */
  var form = document.getElementById('pmForm');
  if (form) form.addEventListener('submit', function(){ if (inp.value.trim()) { zpAddSize(inp.value); inp.value = ''; } });
})();

/* ==================================================================
   PARTS & RATES — one grid, live totals
   Footer and header tiles are recomputed from what is on screen, never from a
   stored total: part work = Σ over parts of qty-per-set × Σ of its operation
   rates at that size (a blank size cell is the standard rate), plus set work.
   The same arithmetic zp_set_cost() and the list page do on the server.
   ================================================================== */
(function(){
  var grid = document.getElementById('pmGrid');
  var setT = document.getElementById('setTbl');
  function num(v){ v = parseFloat(String(v == null ? '' : v).replace(/,/g, '')); return isNaN(v) ? 0 : v; }
  var SIZES = <?= json_encode(array_map(fn($s) => (int)$s['id'], $sizes ?? [])) ?>;

  function setWork(){
    var std = 0, per = {};
    if (!setT) return { std: 0, per: per };
    [].forEach.call(setT.tBodies[0].rows, function(tr){
      var r = num((tr.querySelector('.setr') || {}).value);
      std += r;
      [].forEach.call(tr.querySelectorAll('.sets'), function(c){
        var v = String(c.value || '').trim();
        /* A BLANK CELL COSTS THE STANDARD, not nothing */
        per[c.dataset.size] = (per[c.dataset.size] || 0) + (v === '' ? r : num(v));
      });
    });
    var t = document.getElementById('setTot'); if (t) t.textContent = std.toFixed(2);
    [].forEach.call(document.querySelectorAll('.setTotS'), function(td){ td.textContent = (per[td.dataset.size] || 0).toFixed(2); });
    return { std: std, per: per };
  }

  function partWork(){
    var tot = {};
    SIZES.forEach(function(s){ tot[s] = 0; });
    if (!grid) return tot;
    var qty = {};
    [].forEach.call(grid.querySelectorAll('input.qz'), function(q){ (qty[q.dataset.part] = qty[q.dataset.part] || {})[q.dataset.size] = num(q.value); });
    [].forEach.call(grid.querySelectorAll('tr.op'), function(tr){
      var part = tr.dataset.part;
      [].forEach.call(tr.querySelectorAll('input.ratein'), function(el, i){
        var sid = SIZES[i], v = el.value.trim();
        var rate = v !== '' && !isNaN(parseFloat(v)) ? parseFloat(v) : num(el.dataset.std);
        tot[sid] += rate * ((qty[part] || {})[sid] || 0);
      });
    });
    return tot;
  }

  window.pmTotals = function(){
    var sw = setWork(), pw = partWork();
    SIZES.forEach(function(sid){
      var p = Math.round(pw[sid] * 100) / 100, s = Math.round((sw.per[sid] || 0) * 100) / 100, t = p + s;
      var a = document.querySelector('[data-pw="' + sid + '"]'); if (a) a.textContent = p.toFixed(2);
      var b = document.querySelector('[data-sw="' + sid + '"]'); if (b) b.textContent = s.toFixed(2);
      var c = document.querySelector('[data-tot="' + sid + '"]'); if (c) c.textContent = t.toFixed(2);
      var tile = document.querySelector('[data-tile="' + sid + '"]');
      if (tile) { tile.querySelector('b').textContent = t.toFixed(2); tile.querySelector('small').textContent = 'parts ' + p.toFixed(2) + ' · set work ' + s.toFixed(2); }
    });
    if (grid) {
      var n = grid.querySelectorAll('td.szcell.ovr').length, oc = document.getElementById('ovrCount');
      if (oc) oc.textContent = n + ' size rate' + (n === 1 ? '' : 's') + ' set';
    }
  };

  if (grid) {
    /* ---- ▸ opens a part's operations ---- */
    function openPart(tr, on){
      var b = tr.querySelector('.tgb'); b.setAttribute('aria-expanded', on ? 'true' : 'false');
      var n = tr.nextElementSibling;
      while (n && n.classList.contains('op')) { n.hidden = !on; n = n.nextElementSibling; }
    }
    var KEY = 'zp_pm_open_<?= (int)$editId ?>', opened = {};
    try { opened = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { opened = {}; }
    [].forEach.call(grid.querySelectorAll('tr.pr'), function(tr){
      /* a part with a size rate set always opens, so a number in the footer is never unexplained */
      var hasOvr = false, n = tr.nextElementSibling;
      while (n && n.classList.contains('op')) { if (n.querySelector('td.szcell.ovr')) hasOvr = true; n = n.nextElementSibling; }
      openPart(tr, hasOvr || !!opened[tr.dataset.part]);
    });
    grid.addEventListener('click', function(e){
      var b = e.target.closest('.tgb');
      if (b) {
        var tr = b.closest('tr'), on = b.getAttribute('aria-expanded') !== 'true';
        openPart(tr, on); opened[tr.dataset.part] = on ? 1 : 0;
        try { localStorage.setItem(KEY, JSON.stringify(opened)); } catch (e2) {}
        return;
      }
      var x = e.target.closest('td.szcell .x');
      if (x) { var el = x.closest('td').querySelector('input'); el.value = ''; mark(el); pmTotals(); el.focus(); }
    });
    var all = document.getElementById('pmOpenAll');
    if (all) all.addEventListener('click', function(){
      var rows = grid.querySelectorAll('tr.pr'), any = [].some.call(rows, function(r){ return r.querySelector('.tgb').getAttribute('aria-expanded') !== 'true'; });
      [].forEach.call(rows, function(r){ openPart(r, any); opened[r.dataset.part] = any ? 1 : 0; });
      all.textContent = any ? 'Close all' : 'Open all';
      try { localStorage.setItem(KEY, JSON.stringify(opened)); } catch (e2) {}
    });

    /* ---- the add-part button only lights up once a part is chosen ---- */
    var sel = document.getElementById('pmAddSel'), ab = document.getElementById('pmAddBtn');
    if (sel && ab) sel.addEventListener('change', function(){ ab.disabled = !sel.value; });

    /* ---- a size rate that differs from the standard is marked green ----
       Typing the standard back in is NOT an override — matching the server,
       so the mark never promises something the save will not store. */
    function mark(el){
      var td = el.closest('td'), std = parseFloat(el.dataset.std), v = el.value.trim(), f = parseFloat(v);
      td.classList.toggle('ovr', v !== '' && !isNaN(f) && Math.abs(f - std) >= 0.0001);
    }
    grid.addEventListener('input', function(e){
      var el = e.target;
      if (el.classList.contains('ratein') || el.classList.contains('qz')) {
        var v = el.value.replace(/[^0-9.]/g, ''); if (el.value !== v) el.value = v;
        if (el.classList.contains('ratein')) mark(el);
        pmTotals();
      }
    });

    /* ---- spreadsheet keys ----
       Enter / ↓ go down the same column to the next VISIBLE box of the same
       kind (quantity to quantity, rate to rate); Esc puts a rate back to the
       standard; Ctrl+D copies the cell above and moves on, so holding it fills
       a column. BACKSPACE IS DELIBERATELY NOT BOUND — everywhere it rubs out
       one character, and stealing it would surprise everyone. */
    function column(el){
      var cls = el.classList.contains('qz') ? 'qz' : 'ratein', idx = [].indexOf.call(el.closest('tr').children, el.closest('td'));
      return [].filter.call(grid.querySelectorAll('input.' + cls), function(x){
        return !x.closest('tr').hidden && [].indexOf.call(x.closest('tr').children, x.closest('td')) === idx; });
    }
    grid.addEventListener('keydown', function(e){
      var el = e.target;
      if (!el.classList || !(el.classList.contains('qz') || el.classList.contains('ratein'))) return;
      var col = column(el), i = col.indexOf(el);
      function go(t){ if (t) { e.preventDefault(); t.focus(); t.select(); } }
      if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
        e.preventDefault();
        if (i > 0) { el.value = col[i - 1].value; if (el.classList.contains('ratein')) mark(el); pmTotals(); go(col[i + 1]); }
        return;
      }
      if (e.key === 'Escape' && el.classList.contains('ratein')) { e.preventDefault(); el.value = ''; mark(el); pmTotals(); return; }
      if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); go(col[i + 1]); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); go(col[i - 1]); return; }
      var row = [].slice.call(el.closest('tr').querySelectorAll('input.cell'));
      var j = row.indexOf(el);
      if (e.key === 'ArrowLeft'  && el.selectionStart === 0) go(row[j - 1]);
      if (e.key === 'ArrowRight' && el.selectionStart === el.value.length) go(row[j + 1]);
    });

    /* ---- PASTE A BLOCK STRAIGHT OUT OF EXCEL ----
       Tabs across, newlines down, starting at the cell you are in. Rows are the
       visible boxes of the same kind; anything past the last one is dropped
       rather than landing on another operation. */
    grid.addEventListener('paste', function(e){
      var el = e.target;
      if (!el.classList || !(el.classList.contains('ratein') || el.classList.contains('qz'))) return;
      var txt = (e.clipboardData || window.clipboardData).getData('text') || '';
      if (txt.indexOf('\t') < 0 && txt.indexOf('\n') < 0) return;
      e.preventDefault();
      var cls = el.classList.contains('qz') ? 'qz' : 'ratein';
      var rows = [], seen = [];
      [].forEach.call(grid.querySelectorAll('input.' + cls), function(x){
        var tr = x.closest('tr'); if (tr.hidden) return;
        var k = seen.indexOf(tr); if (k < 0) { seen.push(tr); rows.push([]); k = rows.length - 1; }
        rows[k].push(x);
      });
      var r0 = seen.indexOf(el.closest('tr')), c0 = rows[r0].indexOf(el);
      txt.replace(/\r/g, '').replace(/\n+$/, '').split('\n').forEach(function(line, dr){
        line.split('\t').forEach(function(cell, dc){
          var t = rows[r0 + dr] && rows[r0 + dr][c0 + dc];
          if (!t) return;
          t.value = String(cell).trim().replace(/[^0-9.]/g, '');
          if (cls === 'ratein') mark(t);
        });
      });
      pmTotals();
    });
  }

  /* ---- set work: add a row, drop a row ----
     The row is cloned from the last one, so it carries whatever size columns
     this product actually has. The names carry a row index which is closed up
     after every add or drop — the per-size rates are matched back BY POSITION
     on the server, so a gap would land them on the wrong job. */
  if (setT) {
    var body = setT.tBodies[0];
    function renumber(){
      [].forEach.call(body.rows, function(tr, i){
        var c = tr.cells[0]; if (c) c.textContent = i + 1;
        [].forEach.call(tr.querySelectorAll('[name]'), function(el){
          el.name = el.name.replace(/^setop\[\d+\]/, 'setop[' + i + ']').replace(/^setrate\[\d+\]/, 'setrate[' + i + ']');
        });
      });
    }
    window.zpSetAdd = function(){
      var last = body.rows[body.rows.length - 1]; if (!last) return;
      var tr = last.cloneNode(true);
      /* the id must NOT come with the clone, or saving would overwrite the row it was copied from */
      [].forEach.call(tr.querySelectorAll('input'), function(el){ el.value = ''; el.placeholder = el.classList.contains('sets') ? '—' : el.placeholder; });
      var s = tr.querySelector('select'); if (s) s.selectedIndex = 0;
      tr.classList.remove('gone');
      body.appendChild(tr); renumber(); pmTotals();
      var f = tr.querySelector('input[name*="[operation_name]"]'); if (f) { f.placeholder = 'e.g. Fold & poly bag'; f.focus(); }
      body.dispatchEvent(new Event('input', { bubbles: true }));
    };
    window.zpSetDrop = function(btn){
      var tr = btn.closest('tr'); if (!tr) return;
      /* A SAVED ROW IS EMPTIED, NOT REMOVED — an empty name is what tells the
         save to switch it off, and you can see what you did before Save. */
      var id = tr.querySelector('input[name*="[id]"]');
      if (id && +id.value > 0) {
        [].forEach.call(tr.querySelectorAll('input'), function(el){ if (el.name.indexOf('[id]') < 0) el.value = ''; });
        tr.classList.add('gone');
        tr.querySelector('input[name*="[operation_name]"]').placeholder = 'removed on Save';
      } else if (body.rows.length > 1) tr.remove();
      else [].forEach.call(tr.querySelectorAll('input'), function(el){ if (el.name.indexOf('[id]') < 0) el.value = ''; });
      renumber(); pmTotals();
      body.dispatchEvent(new Event('input', { bubbles: true }));
    };
    setT.addEventListener('input', pmTotals);
  }
  pmTotals();
})();

/* ==================================================================
   UNSAVED CHANGES — the footer says so, leaving warns, Ctrl+S saves.
   And THE DRAFT SAFETY NET: a copy of what is typed is kept in THIS browser
   only, under a key carrying the product id. It NEVER sends anything to the
   server and NEVER restores by itself — it offers, and you press Put it back.
   Every storage call is wrapped: a private window or blocked site data makes
   localStorage throw, and that is no reason for the page to stop working.
   ================================================================== */
(function(){
  var form = document.getElementById('pmForm'), bar = document.getElementById('pmDraftBar');
  if (!form) return;
  var KEY = 'zp_pm_draft_<?= (int)$editId ?>';
  var SAVED = <?= $msg !== '' ? 'true' : 'false' ?>;
  var dirty = document.getElementById('pmDirty'), leaving = false;

  function read(){
    var d = { sizes: [], f: {} };
    [].forEach.call(document.querySelectorAll('#sizeChips .chip'), function(c){
      if (!c.classList.contains('gone')) d.sizes.push(c.querySelector('.t').textContent);
    });
    [].forEach.call(form.elements, function(el){
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.name === 'size_label[]' || el.id === 'sizeIn' || el.name === 'add_part_id') return;
      d.f[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
    });
    return d;
  }
  var SERVER = JSON.stringify(read());
  function store(v){ try { localStorage.setItem(KEY, v); } catch (e) {} }
  function fetchDraft(){ try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function drop(){ try { localStorage.removeItem(KEY); } catch (e) {} }
  if (SAVED) drop();

  function isDirty(){ return JSON.stringify(read()) !== SERVER; }
  var pending = null;
  function changed(){
    var d = isDirty();
    if (dirty) { dirty.textContent = d ? '● Unsaved changes' : 'No changes'; dirty.classList.toggle('on', d); }
    if (pending) return;
    pending = setTimeout(function(){
      pending = null;
      if (isDirty()) store(JSON.stringify({ at: Date.now(), d: read() })); else drop();
    }, 800);
  }
  document.addEventListener('input', function(e){ if (form.contains(e.target)) changed(); });
  document.addEventListener('change', function(e){ if (form.contains(e.target)) changed(); });

  form.addEventListener('submit', function(){ leaving = true; });
  window.addEventListener('beforeunload', function(e){ if (!leaving && isDirty()) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      var b = form.querySelector('.pm-foot button[name="do"][value="save"]');
      if (form.reportValidity()) { leaving = true; if (form.requestSubmit) form.requestSubmit(b); else b.click(); }
    }
  });

  function apply(d){
    /* sizes: a saved size the draft did not have is struck; one it had that is
       not on the page is added as new */
    var want = (d.sizes || []).map(function(s){ return String(s).toLowerCase(); });
    [].forEach.call(document.querySelectorAll('#sizeChips .chip'), function(c){
      var on = want.indexOf(c.querySelector('.t').textContent.toLowerCase()) >= 0;
      if (c.dataset.saved) { c.classList.toggle('gone', !on); c.querySelector('input').disabled = !on; }
      else if (!on) c.remove();
    });
    var have = [].map.call(document.querySelectorAll('#sizeChips .chip'), function(c){ return c.querySelector('.t').textContent.toLowerCase(); });
    (d.sizes || []).forEach(function(s){ if (have.indexOf(String(s).toLowerCase()) < 0 && window.zpAddSize) zpAddSize(s); });
    /* everything else is matched BY NAME, and a box that no longer exists is
       skipped rather than created — a part may have been removed since */
    Object.keys(d.f || {}).forEach(function(n){
      var el = form.elements.namedItem(n);
      if (!el || el.length > 1 && !el.tagName) return;
      if (el.type === 'checkbox') el.checked = !!d.f[n]; else el.value = d.f[n];
      if (el.classList && el.classList.contains('ratein')) el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    if (window.pmTotals) pmTotals();
    changed();
  }

  if (bar) (function(){
    var raw = fetchDraft(); if (!raw) return;
    var saved; try { saved = JSON.parse(raw); } catch (e) { drop(); return; }
    /* only offered when it DIFFERS from what the server sent — a bar that
       cries wolf on every visit is a bar people stop reading */
    if (!saved || !saved.d || !saved.d.f || JSON.stringify(saved.d) === SERVER) { drop(); return; }
    var when = document.getElementById('pmDraftWhen'), mins = Math.max(0, Math.round((Date.now() - (saved.at || 0)) / 60000));
    if (when) when.textContent = mins < 1 ? 'From a moment ago.' : mins < 60 ? 'From ' + mins + ' min ago.' : 'From ' + Math.round(mins / 60) + ' h ago.';
    bar.hidden = false;
    document.getElementById('pmDraftYes').onclick = function(){ apply(saved.d); bar.hidden = true; };
    document.getElementById('pmDraftNo').onclick = function(){ drop(); bar.hidden = true; };
  })();

  /* ---- the jump bar follows the scroll ---- */
  var nav = document.getElementById('pmNav');
  if (nav && 'IntersectionObserver' in window) {
    var links = [].slice.call(nav.querySelectorAll('a'));
    var io = new IntersectionObserver(function(es){
      es.forEach(function(en){
        if (!en.isIntersecting) return;
        links.forEach(function(a){ a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id); });
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    links.forEach(function(a){ var s = document.querySelector(a.getAttribute('href')); if (s) io.observe(s); });
  }
})();
</script>

<?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
