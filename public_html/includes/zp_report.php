<?php
/*
  PRODUCTION REPORTS & DASHBOARD — ONE QUERY, THE REST IN THE BROWSER
  ===================================================================
  Owner, 28 Nov 2026: "not proper, standard and professional … like SAP Fiori
  and Oracle Fusion … modern, dynamic, animated show / hide … cards flip on a
  click, summary on the front, detail on the back … reports with filters that
  work nicely".

  The old pages ran one grouped query per table and reloaded for every change
  of view. Now the period is read ONCE, as compact rows with the names sent
  once each (dictionaries), and every filter, tab, sort, drill-down and card
  is worked out on the page. Changing a filter costs the server nothing; only
  a different period asks again.

  THE SAME RULE AS BEFORE: only active entries count. Cancelled rows are sent
  only so the "Every entry" audit view can show them, marked; every total
  skips them (flag 1).

  MONEY ONLY FOR THOSE ALLOWED (can_see_rates): without it, rate and amount
  are sent as 0 and the pages draw no money at all.
*/

function zpr_dataset(string $from, string $to, bool $money, int $cap = 120000): array {
    zp_ensure_schema();
    $D = ['from' => $from, 'to' => $to, 'money' => $money, 'days' => [],
          'W' => [], 'P' => [], 'O' => [], 'F' => [], 'L' => [], 'S' => [], 'X' => [''], 'R' => [],
          'cut' => false, 'err' => ''];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $D['days'][] = $d;
        if (count($D['days']) > 400) break;           // a range nobody meant
    }
    $dayIx = array_flip($D['days']);
    $sql = "SELECT e.id, e.entry_date d, e.worker_id wid, e.proforma_id fid, e.proforma_item_id iid, e.product_id pid,
                   COALESCE(e.op_kind,'part') k, e.op_id oid, e.part_id ptid, COALESCE(e.stage,'') st,
                   e.qty, e.rate_applied r, e.amount a, e.status, COALESCE(e.cancel_reason,'') why,
                   w.worker_code wc, w.worker_name wn, COALESCE(NULLIF(TRIM(w.department),''),'(no department)') dept,
                   COALESCE(p.name, '(removed product)') pn,
                   COALESCE(pt.part_name, IF(COALESCE(e.op_kind,'part')='part', CONCAT('Part #', e.part_id), 'Set work')) ptn,
                   COALESCE(CASE COALESCE(e.op_kind,'part') WHEN 'part' THEN o.operation_name WHEN 'line' THEN lo.operation_name ELSE so.operation_name END,
                            CONCAT('Operation #', e.op_id)) opn,
                   pf.pi_no, pf.customer_name cust, pi.size sz
            FROM zp_entries e
            LEFT JOIN zp_workers w   ON w.id  = e.worker_id
            LEFT JOIN products p     ON p.id  = e.product_id
            LEFT JOIN zp_parts pt    ON pt.id = e.part_id
            LEFT JOIN zp_part_ops o  ON o.id  = e.op_id AND COALESCE(e.op_kind,'part') = 'part'
            LEFT JOIN zp_set_ops so  ON so.id = e.op_id AND e.op_kind = 'set'
            LEFT JOIN zp_line_set_ops lo ON lo.id = e.op_id AND e.op_kind = 'line'
            LEFT JOIN proforma_invoices pf ON pf.id = e.proforma_id
            LEFT JOIN proforma_items pi    ON pi.id = e.proforma_item_id
            WHERE e.entry_date BETWEEN ? AND ?
            ORDER BY e.entry_date, e.id
            LIMIT " . ($cap + 1);
    try { $st = db()->prepare($sql); $st->execute([$from, $to]); }
    catch (Throwable $e) { $D['err'] = 'The report could not be read: ' . $e->getMessage(); return $D; }

    $ix = ['W' => [], 'P' => [], 'O' => [], 'F' => [], 'L' => [], 'S' => [], 'X' => ['' => 0]];
    $put = function (string $dict, string $key, array $val) use (&$ix, &$D): int {
        if (!isset($ix[$dict][$key])) { $ix[$dict][$key] = count($D[$dict]); $D[$dict][] = $val; }
        return $ix[$dict][$key];
    };
    $n = 0;
    while ($r = $st->fetch()) {
        if (++$n > $cap) { $D['cut'] = true; break; }
        $day = $dayIx[(string)$r['d']] ?? null;
        if ($day === null) continue;
        $wi = $put('W', (string)$r['wid'], [(string)($r['wc'] ?? ''), (string)($r['wn'] ?? '(removed worker)'), (string)$r['dept'], (int)$r['wid']]);
        $pi = $put('P', (string)$r['pid'], [(string)$r['pn'], (int)$r['pid']]);
        $si = $put('S', (string)$r['st'], [$r['st'] !== '' ? (string)$r['st'] : '(no stage)']);
        $oi = $put('O', $r['k'] . ':' . $r['oid'] . ':' . $r['ptid'], [(string)$r['ptn'], (string)$r['opn'], $si]);
        $fi = $put('F', (string)(int)$r['fid'], [(string)($r['pi_no'] ?? '(no order)'), (string)($r['cust'] ?? ''), (int)$r['fid']]);
        $li = $put('L', (int)$r['fid'] . ':' . (int)$r['iid'], [$fi, (string)($r['sz'] ?? ''), $pi]);
        $canc = $r['status'] !== 'active';
        $xi = $canc && $r['why'] !== '' ? $put('X', (string)$r['why'], [(string)$r['why']]) : 0;
        $D['R'][] = [$day, $wi, $pi, $oi, $fi, $li, $si, round((float)$r['qty'], 2),
                     $money ? round((float)$r['a'], 2) : 0, $money ? round((float)$r['r'], 2) : 0,
                     $canc ? 1 : 0, $xi, (int)$r['id']];
    }
    /* the stage order the floor uses, not the order they happened to appear in */
    $order = [];
    foreach (zp_stage_all(false) as $i => $s) $order[(string)$s['name']] = $i;
    $D['stageOrder'] = array_map(fn($s) => $order[$s[0]] ?? 99, $D['S']);
    return $D;
}

/* The period before this one, the same length — for "vs previous period". */
function zpr_previous(string $from, string $to): array {
    $len  = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pTo  = date('Y-m-d', strtotime($from . ' -1 day'));
    $pFrom = date('Y-m-d', strtotime($pTo . ' -' . ($len - 1) . ' days'));
    $out = ['from' => $pFrom, 'to' => $pTo, 'qty' => 0.0, 'amt' => 0.0, 'n' => 0, 'workers' => 0];
    try {
        $st = db()->prepare("SELECT COALESCE(SUM(qty),0) q, COALESCE(SUM(amount),0) a, COUNT(*) n, COUNT(DISTINCT worker_id) w
                             FROM zp_entries WHERE status='active' AND entry_date BETWEEN ? AND ?");
        $st->execute([$pFrom, $pTo]);
        $r = $st->fetch();
        $out['qty'] = (float)$r['q']; $out['amt'] = (float)$r['a']; $out['n'] = (int)$r['n']; $out['workers'] = (int)$r['w'];
    } catch (Throwable $e) {}
    return $out;
}

/* The period presets shown as chips. [key => [label, from, to]] */
function zpr_presets(): array {
    $t = date('Y-m-d');
    $mon = date('Y-m-d', strtotime('monday this week'));
    return [
        'today'  => ['Today',         $t, $t],
        'yday'   => ['Yesterday',     date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        'week'   => ['This week',     $mon, $t],
        '7'      => ['Last 7 days',   date('Y-m-d', strtotime('-6 days')), $t],
        'month'  => ['This month',    date('Y-m-01'), $t],
        'lmonth' => ['Last month',    date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        '30'     => ['Last 30 days',  date('Y-m-d', strtotime('-29 days')), $t],
        '90'     => ['Last 90 days',  date('Y-m-d', strtotime('-89 days')), $t],
    ];
}

/* The top of both pages: title, period chips, the filter bar (filled by zprep.js). */
function zpr_head(string $title, string $page, string $from, string $to, array $links): void {
    $self = $page === 'dash' ? 'production_dashboard.php' : 'production_reports.php';
    $on = '';
    foreach (zpr_presets() as $k => [$l, $f, $t]) if ($f === $from && $t === $to) { $on = $k; break; }
    ?>
  <div class="ph">
    <div><h1><?= e($title) ?></h1><div class="sub" id="zprSub"><div class="skel" style="width:260px"></div></div></div>
    <div class="acts">
      <?php foreach ($links as [$href, $label, $carry]): ?>
        <a class="btn" href="<?= e($href) ?>"<?= $carry ? ' data-carry' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="per" role="group" aria-label="Period">
    <?php foreach (zpr_presets() as $k => [$l, $f, $t]): ?>
      <a href="<?= e($self) ?>?from=<?= e($f) ?>&amp;to=<?= e($t) ?>" data-carry class="<?= $k === $on ? 'on' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <form method="get" action="<?= e($self) ?>" onsubmit="this.action='<?= e($self) ?>'+location.hash">
      <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"><span style="color:#8396ab">–</span>
      <input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="To">
      <button class="btn sm pri" type="submit">Go</button>
    </form>
  </div>
  <div class="fbar" id="zprF">
    <div class="fhead" id="zprFH"><span class="t">Filters</span><span class="badge" id="zprFN" hidden>0</span>
      <span style="color:#8396ab;font-size:12px">— worked out on this page, nothing is sent to the server</span><span class="chev">▾</span></div>
    <div class="fold"><div>
      <div class="fbody" id="zprFB"></div>
      <div class="toks" id="zprFT" hidden></div>
    </div></div>
  </div>
<?php }

/* The data, for the page's script. */
function zpr_json(array $D): string {
    return json_encode($D, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}
