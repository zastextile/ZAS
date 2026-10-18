<?php
/*
  IMPORT — CUSTOMS TAB (view B: the GD working invoice). Phase 2.

  One GD per supplier invoice. Its details (number, date, station, clearing
  agent, assessed and released dates) sit on that invoice row. Its lines are
  copied from the invoice lines, and lines with the same HS code can be
  grouped into one GD line. Every invoice line remembers its GD line, so
  grouping never merges products in stock.

  Three values, never written over one another: the supplier value (from
  the invoice lines), the declared value and the assessed value. When
  assessed differs from declared a reason is required and kept on the
  timeline. The assessed value becomes each line's EFS valuation per kg, and
  the assessed kg (Phase 3) the kg EFS counts — the invoice kg until typed.

  Duty and taxes are not split by head here (afnan): they are entered as a
  cost on COSTS and all of it counts in landed cost. This page only shows
  that they are in.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];
const IMP_GD_STATUS = ['' => '— not filed —', 'filed' => 'Filed', 'assessed' => 'Assessed', 'released' => 'Released'];

/* invoice lines per supplier invoice (a line saved with no invoice belongs to the first) */
function cus_lines_by_invoice(array $invoices, array $lines): array
{
    $first = (int)($invoices[0]['id'] ?? 0);
    $out = [];
    foreach ($lines as $l) $out[(int)($l['invoice_id'] ?: $first)][] = $l;
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $ivId = (int)($_POST['invoice_id'] ?? 0);
    try {
        imp_require('imp', 'u');
        $invoices = imp_invoices($id);
        $iv = null; foreach ($invoices as $x) if ((int)$x['id'] === $ivId) $iv = $x;
        if (!$iv) throw new Exception('That supplier invoice is not on this import.');
        $byIv = cus_lines_by_invoice($invoices, imp_lines($id));
        $ivLines = $byIv[$ivId] ?? [];

        if ($act === 'save_gd') {
            $st = (string)($_POST['gd_status'] ?? ''); if (!array_key_exists($st, IMP_GD_STATUS)) $st = '';
            $f = ['gd_no' => mb_substr(trim((string)($_POST['gd_no'] ?? '')), 0, 80) ?: null, 'gd_date' => imp_date($_POST['gd_date'] ?? ''),
                  'gd_station' => mb_substr(trim((string)($_POST['gd_station'] ?? '')), 0, 160) ?: null, 'gd_agent_id' => (int)($_POST['gd_agent_id'] ?? 0) ?: null,
                  'gd_status' => $st ?: null, 'gd_assessed' => imp_date($_POST['gd_assessed'] ?? ''), 'gd_released' => imp_date($_POST['gd_released'] ?? ''),
                  'gd_notes' => mb_substr(trim((string)($_POST['gd_notes'] ?? '')), 0, 300) ?: null];
            if ($f['gd_released'] && $f['gd_date'] && $f['gd_released'] < $f['gd_date']) throw new Exception('Released cannot be before the GD date.');
            if ($f['gd_assessed'] && $f['gd_date'] && $f['gd_assessed'] < $f['gd_date']) throw new Exception('Assessed cannot be before the GD date.');
            if ($f['gd_released'] && $st !== 'released') $f['gd_status'] = 'released';
            db()->beginTransaction();
            db()->prepare("UPDATE imp_invoices SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($f))) . " WHERE id=? AND import_id=?")
                ->execute(array_merge(array_values($f), [$ivId, $id]));
            /* the GD dates also drive LOGISTICS and the EFS clock — filled there only if still empty */
            db()->prepare("INSERT INTO imp_logistics (import_id) SELECT ? WHERE NOT EXISTS (SELECT 1 FROM imp_logistics WHERE import_id=?)")->execute([$id, $id]);
            if ($f['gd_date']) db()->prepare("UPDATE imp_logistics SET gd_filed=? WHERE import_id=? AND gd_filed IS NULL")->execute([$f['gd_date'], $id]);
            if ($f['gd_released']) db()->prepare("UPDATE imp_logistics SET customs_release=? WHERE import_id=? AND customs_release IS NULL")->execute([$f['gd_released'], $id]);
            imp_log($id, 'GD saved', ($f['gd_no'] ?: 'GD') . ' · ' . (IMP_GD_STATUS[(string)$f['gd_status']] ?? 'not filed')
                . ($f['gd_released'] ? ' · released ' . imp_dmy($f['gd_released']) : ''));
            db()->commit();
            $_SESSION['flash'] = 'GD saved.';
        }

        elseif ($act === 'copy') {
            $todo = array_values(array_filter($ivLines, fn($l) => empty($l['gd_line_id'])));
            if (!$todo) throw new Exception('Every invoice line is already on a GD line.');
            $max = 0; foreach (imp_gd_lines($id) as $g) if ((int)$g['invoice_id'] === $ivId) $max = max($max, (int)$g['line_no']);
            db()->beginTransaction();
            $ins = db()->prepare("INSERT INTO imp_gd_lines (import_id, invoice_id, line_no, hs_code, description, declared_qty, declared_unit, declared_value, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
            foreach ($todo as $l) {
                $ins->execute([$id, $ivId, ++$max, $l['hs_code'], mb_substr((string)$l['description'], 0, 300), (float)$l['bill_qty'], $l['bill_unit'], imp_line_amount($l), current_user()['id'] ?? null]);
                db()->prepare("UPDATE imp_lines SET gd_line_id=? WHERE id=? AND import_id=?")->execute([(int)db()->lastInsertId(), (int)$l['id'], $id]);
            }
            imp_log($id, 'GD lines copied', count($todo) . ' line' . (count($todo) === 1 ? '' : 's') . ' copied from invoice ' . ($iv['invoice_no'] ?: ''));
            db()->commit();
            $_SESSION['flash'] = count($todo) . ' GD line' . (count($todo) === 1 ? '' : 's') . ' copied from the invoice.';
        }

        elseif ($act === 'group') {
            /* lines with the same HS go under the first of them; values and,
               where the units match, quantities add up. Products stay
               separate in stock — only the GD line is shared. */
            $gds = array_values(array_filter(imp_gd_lines($id), fn($g) => (int)$g['invoice_id'] === $ivId));
            $byHs = []; foreach ($gds as $g) if (trim((string)$g['hs_code']) !== '') $byHs[trim((string)$g['hs_code'])][] = $g;
            $merged = 0;
            db()->beginTransaction();
            foreach ($byHs as $hs => $grp) {
                if (count($grp) < 2) continue;
                $keep = array_shift($grp);
                $val = (float)$keep['declared_value']; $qty = (float)$keep['declared_qty']; $sameUnit = true; $ass = $keep['assessed_value'];
                foreach ($grp as $g) {
                    $val += (float)$g['declared_value'];
                    if ((string)$g['declared_unit'] === (string)$keep['declared_unit']) $qty += (float)$g['declared_qty']; else $sameUnit = false;
                    $ass = ($ass === null || $g['assessed_value'] === null) ? null : (float)$ass + (float)$g['assessed_value'];
                    db()->prepare("UPDATE imp_lines SET gd_line_id=? WHERE gd_line_id=? AND import_id=?")->execute([(int)$keep['id'], (int)$g['id'], $id]);
                    db()->prepare("DELETE FROM imp_gd_lines WHERE id=? AND import_id=?")->execute([(int)$g['id'], $id]);
                    $merged++;
                }
                db()->prepare("UPDATE imp_gd_lines SET declared_value=?, declared_qty=?, declared_unit=?, assessed_value=?, updated_at=NOW() WHERE id=?")
                    ->execute([round($val, 2), $sameUnit ? $qty : null, $sameUnit ? $keep['declared_unit'] : null, $ass, (int)$keep['id']]);
            }
            if (!$merged) { db()->rollBack(); throw new Exception('No two GD lines share an HS code, so there is nothing to group.'); }
            /* number the remaining GD lines 1, 2, 3 … again, as the GD itself does */
            $n = 0;
            foreach (db()->query("SELECT id FROM imp_gd_lines WHERE import_id=" . (int)$id . " AND invoice_id=" . (int)$ivId . " ORDER BY line_no, id")->fetchAll() as $r)
                db()->prepare("UPDATE imp_gd_lines SET line_no=? WHERE id=?")->execute([++$n, (int)$r['id']]);
            imp_log($id, 'GD lines grouped', $merged . ' line' . ($merged === 1 ? '' : 's') . ' put under a GD line with the same HS code');
            db()->commit();
            $_SESSION['flash'] = 'Grouped by HS code — ' . $merged . ' GD line' . ($merged === 1 ? '' : 's') . ' merged. Each product stays separate in stock.';
        }

        elseif ($act === 'save_lines') {
            $gds = []; foreach (imp_gd_lines($id) as $g) if ((int)$g['invoice_id'] === $ivId) $gds[(int)$g['id']] = $g;
            $ch = [];
            db()->beginTransaction();
            foreach ((array)($_POST['gd'] ?? []) as $gid => $r) {
                $gid = (int)$gid; if (!isset($gds[$gid])) continue;
                $old = $gds[$gid];
                $dec = trim((string)($r['declared_value'] ?? '')) === '' ? null : imp_num($r['declared_value']);
                $ass = trim((string)($r['assessed_value'] ?? '')) === '' ? null : imp_num($r['assessed_value']);
                $dq  = trim((string)($r['declared_qty'] ?? '')) === '' ? null : imp_num($r['declared_qty']);
                $akg = trim((string)($r['assessed_kg'] ?? '')) === '' ? null : imp_num($r['assessed_kg']);
                if ($akg !== null && $akg < 0) throw new Exception('GD line ' . $old['line_no'] . ': kg cannot be negative.');
                if (($dec !== null && $dec < 0) || ($ass !== null && $ass < 0) || ($dq !== null && $dq < 0)) throw new Exception('GD line ' . $old['line_no'] . ': values cannot be negative.');
                $why = mb_substr(trim((string)($r['diff_reason'] ?? '')), 0, 300);
                if ($ass !== null && $dec !== null && abs($ass - $dec) >= 0.01 && $why === '')
                    throw new Exception('GD line ' . $old['line_no'] . ': assessed ' . number_format($ass, 2) . ' differs from declared ' . number_format($dec, 2) . ' — give the reason.');
                $unit = (string)($r['declared_unit'] ?? ''); if (!in_array($unit, IMP_UNITS, true)) $unit = null;
                db()->prepare("UPDATE imp_gd_lines SET hs_code=?, description=?, declared_qty=?, declared_unit=?, declared_value=?, assessed_value=?, assessed_kg=?, exemption_ref=?, diff_reason=?, updated_by=?, updated_at=NOW() WHERE id=? AND import_id=?")
                    ->execute([mb_substr(trim((string)($r['hs_code'] ?? '')), 0, 20) ?: null, mb_substr(trim((string)($r['description'] ?? '')), 0, 300),
                               $dq, $unit, $dec, $ass, $akg, mb_substr(trim((string)($r['exemption_ref'] ?? '')), 0, 120) ?: null, $why ?: null, current_user()['id'] ?? null, $gid, $id]);
                if (($old['assessed_kg'] ?? null) === null ? $akg !== null : ($akg === null || abs((float)$old['assessed_kg'] - $akg) > 0.0005))
                    $ch[] = 'GD line ' . $old['line_no'] . ' assessed kg ' . (($old['assessed_kg'] ?? null) === null ? '—' : number_format((float)$old['assessed_kg'], 3)) . ' → ' . ($akg === null ? '—' : number_format($akg, 3));
                if ((string)$old['assessed_value'] !== (string)($ass === null ? '' : number_format($ass, 2, '.', '')) && !($old['assessed_value'] === null && $ass === null))
                    $ch[] = 'GD line ' . $old['line_no'] . ' assessed ' . ($old['assessed_value'] === null ? '—' : number_format((float)$old['assessed_value'], 2)) . ' → ' . ($ass === null ? '—' : number_format($ass, 2)) . ($why !== '' ? ' (' . $why . ')' : '');
            }
            /* which GD line each invoice line sits under */
            $valid = array_keys($gds);
            foreach ($ivLines as $l) {
                if (!isset($_POST['map'][(int)$l['id']])) continue;
                $to = (int)$_POST['map'][(int)$l['id']];
                if ($to !== 0 && !in_array($to, $valid, true)) continue;
                if ((int)$l['gd_line_id'] !== $to) {
                    db()->prepare("UPDATE imp_lines SET gd_line_id=? WHERE id=? AND import_id=?")->execute([$to ?: null, (int)$l['id'], $id]);
                    $ch[] = '"' . $l['description'] . '" → ' . ($to ? 'GD line ' . $gds[$to]['line_no'] : 'no GD line');
                }
            }
            imp_log($id, 'GD lines saved', $ch ? implode(' · ', $ch) : 'no change to values or links');
            db()->commit();
            $_SESSION['flash'] = 'GD lines saved.';
        }

        elseif ($act === 'delete_line') {
            $gid = (int)($_POST['gd_line_id'] ?? 0);
            $g = db()->prepare("SELECT * FROM imp_gd_lines WHERE id=? AND import_id=? AND invoice_id=?"); $g->execute([$gid, $id, $ivId]); $g = $g->fetch();
            if (!$g) throw new Exception('That GD line is not here.');
            db()->beginTransaction();
            db()->prepare("UPDATE imp_lines SET gd_line_id=NULL WHERE gd_line_id=? AND import_id=?")->execute([$gid, $id]);
            db()->prepare("DELETE FROM imp_gd_lines WHERE id=?")->execute([$gid]);
            imp_log($id, 'GD line removed', 'GD line ' . $g['line_no'] . ' · ' . $g['description'] . ' — its invoice lines are free again');
            db()->commit();
            $_SESSION['flash'] = 'GD line removed. Its invoice lines can be copied or linked again.';
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_customs.php?id=' . $id . ($ivId ? '#gd' . $ivId : ''));
}

$invoices = imp_invoices($id);
$lines = imp_lines($id);
$gdAll = imp_gd_lines($id);
$byIv = cus_lines_by_invoice($invoices, $lines);
$canEdit = imp_can('imp', 'u');
$agents = function_exists('exp_providers') ? exp_providers('clearing_agent') : [];
$cur = (string)$imp['currency'];
$duty = imp_duty_entered($id);
$m2 = static fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, '.', '');
$ro = $canEdit ? '' : ' readonly';

page_header('Customs — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'customs');

if (!$invoices): ?>
  <div class="xcard"><div class="xnote">Add the supplier invoice on <a href="imp_view.php?id=<?= $id ?>">OVERVIEW</a> first — each GD belongs to one supplier invoice.</div></div>
<?php endif;

foreach ($invoices as $n => $iv):
    $ivId = (int)$iv['id'];
    $ivLines = $byIv[$ivId] ?? [];
    $gds = array_values(array_filter($gdAll, fn($g) => (int)$g['invoice_id'] === $ivId));
    $free = array_filter($ivLines, fn($l) => empty($l['gd_line_id']));
    $sup = []; foreach ($ivLines as $l) if (!empty($l['gd_line_id'])) $sup[(int)$l['gd_line_id']] = ($sup[(int)$l['gd_line_id']] ?? 0) + imp_line_amount($l);
    $kgOf = []; foreach ($ivLines as $l) if (!empty($l['gd_line_id'])) $kgOf[(int)$l['gd_line_id']] = ($kgOf[(int)$l['gd_line_id']] ?? 0) + (float)$l['net_kg'];
    $invNo = [];  foreach (array_values($lines) as $i => $l) $invNo[(int)$l['id']] = $i + 1;
    $st = (string)($iv['gd_status'] ?? '');
?>
<div class="xcard" id="gd<?= $ivId ?>">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">GD<?= !empty($iv['gd_no']) ? ' · ' . e($iv['gd_no']) : '' ?> <span class="imp-small imp-muted" style="font-weight:600">for supplier invoice <?= e($iv['invoice_no'] ?: ('#' . ($n + 1))) ?></span></h2>
    <span class="xpill <?= $st === 'released' ? 'g' : ($st === 'assessed' ? 'b' : ($st === 'filed' ? 'o' : 'n')) ?>"><?= e(IMP_GD_STATUS[$st] ?? 'Not filed') ?><?= $st === 'released' && !empty($iv['gd_released']) ? ' ' . e(imp_dmy($iv['gd_released'])) : '' ?></span>
  </div>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="invoice_id" value="<?= $ivId ?>"><input type="hidden" name="action" value="save_gd">
    <div class="xgrid">
      <?= imp_input('GD no.', 'gd_no', $iv['gd_no'], 'text', $ro) ?>
      <?= imp_input('GD date', 'gd_date', $iv['gd_date'], 'date', $ro) ?>
      <?= imp_input('Customs station / collectorate', 'gd_station', $iv['gd_station'] ?? '', 'text', $ro . ' placeholder="e.g. Karachi — Appraisement East"') ?>
      <label class="xlabel">Clearing agent<select class="xin" name="gd_agent_id"<?= $canEdit ? '' : ' disabled' ?>><option value="0">—</option><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)($iv['gd_agent_id'] ?? 0) === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
      <?= imp_select('Status', 'gd_status', IMP_GD_STATUS, $st, $canEdit ? '' : ' disabled', true) ?>
      <?= imp_input('Assessed on', 'gd_assessed', $iv['gd_assessed'] ?? '', 'date', $ro) ?>
      <?= imp_input('Released on', 'gd_released', $iv['gd_released'] ?? '', 'date', $ro) ?>
      <?= imp_input('Notes', 'gd_notes', $iv['gd_notes'] ?? '', 'text', $ro) ?>
    </div>
    <?php if ($canEdit): ?><div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="xbtn sm">Save GD</button><span class="imp-small imp-muted">The GD date and the release date also fill LOGISTICS if still empty — the release date starts the EFS clock.</span></div><?php endif; ?>
  </form>
</div>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">GD lines</h2>
    <?php if ($canEdit): ?><div style="display:flex;gap:6px;flex-wrap:wrap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="invoice_id" value="<?= $ivId ?>"><input type="hidden" name="action" value="copy"><button class="xbtn sec sm"<?= $free ? '' : ' disabled title="every invoice line is already on a GD line"' ?>>Copy from invoice<?= $free ? ' (' . count($free) . ')' : '' ?></button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="invoice_id" value="<?= $ivId ?>"><input type="hidden" name="action" value="group"><button class="xbtn sec sm"<?= count($gds) > 1 ? '' : ' disabled' ?>>Group lines with the same HS</button></form>
    </div><?php endif; ?>
  </div>
  <?php if (!$gds): ?>
    <div class="xnote" style="margin-top:10px">No GD lines yet. <b>Copy from invoice</b> makes one GD line per invoice line; then group lines with the same HS code if the GD shows them as one.</div>
  <?php else: ?>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="invoice_id" value="<?= $ivId ?>"><input type="hidden" name="action" value="save_lines">
    <div class="xwrap"><table class="xtable" style="min-width:1360px">
      <thead><tr><th>GD line</th><th style="width:110px">HS / PCT</th><th style="min-width:200px">Customs description</th><th>Invoice lines</th><th class="num">Invoice net kg</th><th class="num" style="width:110px">Assessed kg</th><th class="num">Declared qty</th><th style="width:76px">Unit</th>
        <th class="num">Supplier value</th><th class="num" style="width:120px">Declared</th><th class="num" style="width:120px">Assessed</th><th class="num">Difference</th><th style="width:140px">EFS / exemption ref</th><th></th></tr></thead>
      <tbody>
      <?php $tS = 0; $tD = 0; $tA = 0; $anyA = false;
      foreach ($gds as $g): $gid = (int)$g['id']; $sv = $sup[$gid] ?? 0; $tS += $sv; $tD += (float)$g['declared_value'];
        if ($g['assessed_value'] !== null) { $tA += (float)$g['assessed_value']; $anyA = true; }
        $diff = $g['assessed_value'] !== null && $g['declared_value'] !== null ? (float)$g['assessed_value'] - (float)$g['declared_value'] : null;
        $mine = array_filter($ivLines, fn($l) => (int)$l['gd_line_id'] === $gid); ?>
        <tr<?= $diff !== null && abs($diff) >= 0.01 ? ' style="background:rgba(217,119,6,.07)"' : '' ?>>
          <td><b><?= (int)$g['line_no'] ?></b></td>
          <td><input class="xin" name="gd[<?= $gid ?>][hs_code]" value="<?= e($g['hs_code']) ?>"<?= $ro ?>></td>
          <td><input class="xin" name="gd[<?= $gid ?>][description]" value="<?= e($g['description']) ?>"<?= $ro ?>></td>
          <td><?= $mine ? implode(' ', array_map(fn($l) => '<span class="xpill n" title="' . e($l['description']) . '">' . ($invNo[(int)$l['id']] ?? '?') . '</span>', $mine)) : '<span class="imp-small imp-muted">none</span>' ?></td>
          <td class="num"><?= e(number_format($kgOf[$gid] ?? 0, 3)) ?></td>
          <td><input class="xin num" name="gd[<?= $gid ?>][assessed_kg]" value="<?= e(($g['assessed_kg'] ?? null) === null ? '' : rtrim(rtrim(number_format((float)$g['assessed_kg'], 3, '.', ''), '0'), '.')) ?>" placeholder="<?= e(rtrim(rtrim(number_format($kgOf[$gid] ?? 0, 3, '.', ''), '0'), '.')) ?>"<?= $ro ?>></td>
          <td><input class="xin num" name="gd[<?= $gid ?>][declared_qty]" value="<?= e($g['declared_qty'] === null ? '' : rtrim(rtrim(number_format((float)$g['declared_qty'], 3, '.', ''), '0'), '.')) ?>"<?= $ro ?>></td>
          <td><select class="xin" name="gd[<?= $gid ?>][declared_unit]"<?= $canEdit ? '' : ' disabled' ?>><option value="">—</option><?php foreach (IMP_UNITS as $u): ?><option<?= $u === $g['declared_unit'] ? ' selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></td>
          <td class="num"><?= e(number_format($sv, 2)) ?></td>
          <td><input class="xin num" name="gd[<?= $gid ?>][declared_value]" value="<?= e($m2($g['declared_value'])) ?>"<?= $ro ?>></td>
          <td><input class="xin num" name="gd[<?= $gid ?>][assessed_value]" value="<?= e($m2($g['assessed_value'])) ?>" placeholder="when assessed"<?= $ro ?>></td>
          <td class="num"><?= $diff === null ? '—' : (abs($diff) < 0.01 ? '0.00' : '<b style="color:#9a5a06">' . ($diff > 0 ? '+' : '') . e(number_format($diff, 2)) . '</b>') ?></td>
          <td><input class="xin" name="gd[<?= $gid ?>][exemption_ref]" value="<?= e($g['exemption_ref'] ?? '') ?>"<?= $ro ?>></td>
          <td><?php if ($canEdit): ?><button type="submit" form="del<?= $gid ?>" class="xbtn red sm" title="Remove this GD line">✕</button><?php endif; ?></td>
        </tr>
        <tr><td></td><td colspan="13" style="border-top:0;padding-top:0"><label class="xlabel imp-small" style="margin:0">Reason if assessed ≠ declared
          <input class="xin" name="gd[<?= $gid ?>][diff_reason]" value="<?= e($g['diff_reason'] ?? '') ?>" placeholder="e.g. assessed at USD 1.18/m under a valuation ruling"<?= $ro ?>></label></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="8"><?= e($cur) ?></td><td class="num"><?= e(number_format($tS, 2)) ?></td><td class="num"><?= e(number_format($tD, 2)) ?></td><td class="num"><?= $anyA ? e(number_format($tA, 2)) : '—' ?></td><td class="num"><?= $anyA ? e(($tA - $tD >= 0 ? '+' : '') . number_format($tA - $tD, 2)) : '—' ?></td><td colspan="2"></td></tr></tfoot>
    </table></div>

    <h3 style="font-size:12.5px;margin:16px 0 8px;color:#5a6b82">Which GD line each invoice line is under</h3>
    <div class="xwrap"><table class="xtable" style="min-width:720px">
      <thead><tr><th>#</th><th>Invoice line</th><th>HS</th><th class="num">Supplier value</th><th class="num">Net kg</th><th style="width:240px">GD line</th></tr></thead>
      <tbody><?php foreach ($ivLines as $l): ?>
        <tr><td><?= $invNo[(int)$l['id']] ?? '' ?></td><td><?= e($l['description']) ?></td><td><?= e($l['hs_code']) ?></td><td class="num"><?= e(number_format(imp_line_amount($l), 2)) ?></td><td class="num"><?= e(number_format((float)$l['net_kg'], 3)) ?></td>
          <td><select class="xin" name="map[<?= (int)$l['id'] ?>]"<?= $canEdit ? '' : ' disabled' ?>><option value="0">— not on a GD line —</option><?php foreach ($gds as $g): ?><option value="<?= (int)$g['id'] ?>"<?= (int)$l['gd_line_id'] === (int)$g['id'] ? ' selected' : '' ?>>GD line <?= (int)$g['line_no'] ?> · <?= e($g['hs_code'] ?: '—') ?> · <?= e(mb_substr((string)$g['description'], 0, 40)) ?></option><?php endforeach; ?></select></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($canEdit): ?><div style="margin-top:10px"><button class="xbtn">Save GD lines</button></div><?php endif; ?>
  </form>
  <?php foreach ($gds as $g): ?><form method="post" id="del<?= (int)$g['id'] ?>" onsubmit="return confirm('Remove GD line <?= (int)$g['line_no'] ?>? Its invoice lines become free to copy or link again.');"><?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="invoice_id" value="<?= $ivId ?>"><input type="hidden" name="action" value="delete_line"><input type="hidden" name="gd_line_id" value="<?= (int)$g['id'] ?>"></form><?php endforeach; ?>
  <div class="xnote" style="margin-top:12px"><b>Grouping keeps every product separate.</b> A GD line can cover several invoice lines with the same HS code; in stock they stay different items, and the assessed value is shared back to them by supplier value, so each line gets its own EFS valuation per kg (<a href="imp_efs.php?id=<?= $id ?>">EFS</a> tab). <b>Assessed kg</b> is the net kg on the GD — EFS counts it; blank uses the invoice net kg (shown greyed).</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="xcard">
  <h2>Duty &amp; taxes</h2>
  <?php if ($duty > 0): ?>
    <div class="xok">PKR <?= e(number_format($duty, 0)) ?> of duty and taxes is entered on <a href="imp_costs.php?id=<?= $id ?>">COSTS</a>. All of it counts in landed cost.</div>
  <?php else: ?>
    <div class="xnote">No duty or tax entered yet. Add what was paid on <a href="imp_costs.php?id=<?= $id ?>">COSTS</a> as "Customs Duty &amp; Taxes" — taxes are not split by head, and all of it counts in landed cost.</div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
