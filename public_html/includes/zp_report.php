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

/* $floors: a Production Staff login limited to some floors sees only them
   (includes/zp_staff.php); [] = everything. */
function zpr_floor_sql(array $floors, array &$args): string {
    if (!$floors) return '';
    foreach ($floors as $f) $args[] = $f;
    return " AND UPPER(COALESCE(NULLIF(TRIM(w.department),''),'(no department)')) IN (" . implode(',', array_fill(0, count($floors), '?')) . ")";
}

function zpr_dataset(string $from, string $to, bool $money, array $floors = [], int $cap = 120000): array {
    zp_ensure_schema();
    $D = ['from' => $from, 'to' => $to, 'money' => $money, 'days' => [],
          'W' => [], 'P' => [], 'O' => [], 'F' => [], 'L' => [], 'S' => [], 'X' => [''], 'U' => [], 'R' => [], 'A' => [],
          'floors' => $floors, 'cut' => false, 'err' => ''];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $D['days'][] = $d;
        if (count($D['days']) > 400) break;           // a range nobody meant
    }
    $dayIx = array_flip($D['days']);
    $sql = "SELECT e.id, e.entry_date d, e.worker_id wid, e.proforma_id fid, e.proforma_item_id iid, e.product_id pid,
                   COALESCE(e.op_kind,'part') k, e.op_id oid, e.part_id ptid, COALESCE(e.stage,'') st,
                   e.qty, e.rate_applied r, e.amount a, e.status, COALESCE(e.cancel_reason,'') why, COALESCE(e.created_by,0) cb,
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
            WHERE e.entry_date BETWEEN ? AND ?";
    $args = [$from, $to];
    $sql .= zpr_floor_sql($floors, $args) . "
            ORDER BY e.entry_date, e.id
            LIMIT " . ($cap + 1);
    try { $st = db()->prepare($sql); $st->execute($args); }
    catch (Throwable $e) { $D['err'] = 'The report could not be read: ' . $e->getMessage(); return $D; }

    $ix = ['W' => [], 'P' => [], 'O' => [], 'F' => [], 'L' => [], 'S' => [], 'X' => ['' => 0], 'U' => []];
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
        $ui = $put('U', (string)(int)$r['cb'], [(string)(int)$r['cb'], (int)$r['cb']]);   // name filled in below
        $D['R'][] = [$day, $wi, $pi, $oi, $fi, $li, $si, round((float)$r['qty'], 2),
                     $money ? round((float)$r['a'], 2) : 0, $money ? round((float)$r['r'], 2) : 0,
                     $canc ? 1 : 0, $xi, (int)$r['id'], $ui];
    }
    /* ENTERED BY — the login that typed each entry, shown as the person it
       belongs to in People Finance when it is linked (includes/zp_staff.php). */
    $uids = array_values(array_filter(array_map(fn($u) => $u[1], $D['U'])));
    $names = [];
    if ($uids) {
        $in = implode(',', array_fill(0, count($uids), '?'));
        try {
            $q = db()->prepare("SELECT u.id, COALESCE(ae.full_name, u.name) n FROM users u LEFT JOIN att_employees ae ON ae.id = u.att_employee_id WHERE u.id IN ($in)");
            $q->execute($uids); $names = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            try { $q = db()->prepare("SELECT id, name FROM users WHERE id IN ($in)"); $q->execute($uids); $names = $q->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e2) {}
        }
    }
    foreach ($D['U'] as $i => $u) $D['U'][$i][0] = $u[1] ? (string)($names[$u[1]] ?? ('Login #' . $u[1])) : '(not recorded)';
    /* SETS MADE IN THIS PERIOD (Make Sets), for the Finished products view:
       [day, order line id, sets] */
    try {
        $q = db()->prepare("SELECT made_date, proforma_item_id, SUM(sets_made) q FROM zp_assembly WHERE status='active' AND proforma_item_id IS NOT NULL AND made_date BETWEEN ? AND ? GROUP BY made_date, proforma_item_id");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $a) if (isset($dayIx[(string)$a['made_date']])) $D['A'][] = [$dayIx[(string)$a['made_date']], (int)$a['proforma_item_id'], round((float)$a['q'], 2)];
    } catch (Throwable $e) {}
    /* the stage order the floor uses, not the order they happened to appear in */
    $order = [];
    foreach (zp_stage_all(false) as $i => $s) $order[(string)$s['name']] = $i;
    $D['stageOrder'] = array_map(fn($s) => $order[$s[0]] ?? 99, $D['S']);
    $D['stageNames'] = array_keys($order);
    return $D;
}

/* The period before this one, the same length — for "vs previous period". */
function zpr_previous(string $from, string $to, array $floors = []): array {
    $len  = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pTo  = date('Y-m-d', strtotime($from . ' -1 day'));
    $pFrom = date('Y-m-d', strtotime($pTo . ' -' . ($len - 1) . ' days'));
    $out = ['from' => $pFrom, 'to' => $pTo, 'qty' => 0.0, 'amt' => 0.0, 'n' => 0, 'workers' => 0];
    try {
        $args = [$pFrom, $pTo];
        $st = db()->prepare("SELECT COALESCE(SUM(e.qty),0) q, COALESCE(SUM(e.amount),0) a, COUNT(*) n, COUNT(DISTINCT e.worker_id) w
                             FROM zp_entries e LEFT JOIN zp_workers w ON w.id = e.worker_id
                             WHERE e.status='active' AND e.entry_date BETWEEN ? AND ?" . zpr_floor_sql($floors, $args));
        $st->execute($args);
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

/* ============================================================
   FINISHED PRODUCTS — whole products, not operations
   ============================================================
   Owner, 28 Nov 2026: "most cards show operations, so the quantity looks
   bigger … I wish to see reports of one product whole". A duvet set of 300
   with five duvet operations and four pillow operations books 2,700 pieces
   of WORK; this counts the 300 SETS.

   For every open order line (the same lines and the same zp_line_flow() as
   Order Status, so the two can never disagree):
     stages   pieces through EVERY operation of each stage / pieces needed
     ready    sets whose every part has passed its last stage — the slowest
              part decides (200 duvets packed, 150 pillows packed = 150)
     sets     sets recorded on Make Sets for this line
     complete what counts as finished: Make Sets for a set, the last stage
              for a one-part product (as Order Status counts it)
   Worked out once per page open; the period does not change it. */
function zpr_finished(): array {
    zp_ensure_schema();
    $prog = zp_progress_map();
    $out = [];
    foreach (zp_open_lines() as $l) {
        $f = zp_line_flow($l, $prog);
        $ord = (float)($l['ordered_qty'] ?? 0);
        $stages = [];
        foreach ($f['stages'] as $sg) $stages[] = [(string)$sg['name'], round((float)$sg['need'], 2), round((float)$sg['done'], 2)];
        /* each part: its stages, and how many whole sets it is good for */
        $parts = []; $ready = null; $pp = $f['pp']; ksort($pp);
        foreach ($pp as $pos => $byPart)
            foreach ($byPart as $pn => [$need, $thr]) {
                $parts[$pn]['st'][] = [(string)($f['stages'][$pos]['name'] ?? ''), round((float)$need, 2), round((float)$thr, 2)];
                $parts[$pn]['last'] = [(float)$need, (float)$thr];      // positions run in order: the last one wins
            }
        $plist = [];
        foreach ($parts as $pn => $p) {
            [$need, $thr] = $p['last'];
            $per = $ord > 0 && $need > 0 ? $need / $ord : 1.0;           // pieces of this part in one set
            $good = $per > 0 ? floor($thr / $per + 0.0001) : 0;
            $ready = $ready === null ? $good : min($ready, $good);
            $plist[] = [(string)$pn, round($per, 4), $p['st'], $good];
        }
        $ready = $f['no_size'] ? 0 : min((float)($ready ?? 0), $ord);
        $sets = (float)$f['sets'];
        $complete = $f['is_set'] ? $sets : $ready;
        $out[] = [
            'f' => (int)$l['proforma_id'], 'pi' => (string)$l['pi_no'], 'cust' => (string)($l['customer_name'] ?? ''),
            'item' => (int)$l['item_id'], 'pid' => (int)$l['product_id'], 'prod' => (string)$l['product_name'],
            'size' => trim((string)($l['size'] ?? '')), 'desc' => trim((string)($l['description'] ?? '')),
            'ord' => $ord, 'set' => (bool)$f['is_set'], 'stages' => $stages, 'parts' => $plist,
            'ready' => $ready, 'sets' => $sets, 'done' => $complete, 'left' => max(0, $ord - $complete),
            'pct' => $f['no_size'] ? 0.0 : (float)$f['pct'], 'behind' => (string)$f['behind'],
            'nosize' => $f['no_size'] ? zp_size_problem($l) : '',
        ];
    }
    /* the proforma line's own description, as the owner asked to see it */
    $ids = array_column($out, 'item');
    if ($ids) {
        try {
            $q = db()->prepare("SELECT id, description FROM proforma_items WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $q->execute($ids); $dsc = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($out as $i => $o) if ($o['desc'] === '') $out[$i]['desc'] = trim((string)($dsc[$o['item']] ?? ''));
        } catch (Throwable $e) {}
        /* a proforma line with no product name typed: the product's own name */
        $pids = array_values(array_unique(array_column(array_filter($out, fn($o) => trim($o['prod']) === ''), 'pid')));
        if ($pids) {
            try {
                $q = db()->prepare("SELECT id, name FROM products WHERE id IN (" . implode(',', array_fill(0, count($pids), '?')) . ")");
                $q->execute($pids); $pn = $q->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($out as $i => $o) if (trim($o['prod']) === '') $out[$i]['prod'] = (string)($pn[$o['pid']] ?? '(no product)');
            } catch (Throwable $e) {}
        }
    }
    usort($out, fn($a, $b) => $a['pct'] <=> $b['pct']);
    return $out;
}
