<?php
require_once __DIR__ . '/includes/bootstrap.php';
/* costing.php for costing_perm(), export.php for exp_pkr_rate().
 *
 * bootstrap.php does NOT load costing.php — every page that needs
 * costing_perm() requires it for itself, which is why proforma.php,
 * search.php and users.php all carry this same line. Leaving it out took
 * the whole dashboard down with a 500, and the boot test did not catch it
 * because that harness stubs costing_perm(). The stub hid the missing
 * require; zpfcontracts_test.php now checks the requires themselves. */
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/pfcontracts.php';
require_login();
if (is_production_staff()) { redirect('production_my_work.php'); }

$u = current_user();
$admin = is_admin();

// Costing normally happens after the shipment here — a shipment only flags
// as "missing cost" on the dashboard once it's this many days old, so the
// normal in-progress lag never shows up as a false alarm.
const DASH_COST_GRACE_DAYS = 14;

/* ---- scope: admin & colleague see all; staff see assigned ---- */
$scopeSql = '';
$scopeParams = [];
if (!$admin) {
    $ids = assigned_shipment_ids();
    if ($ids === ['ALL']) {
        // colleague — full visibility, no filter
    } elseif ($ids) {
        $scopeSql = ' AND s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $scopeParams = $ids;
    } else {
        $scopeSql = ' AND 1=0';
    }
}

function dash_scalar($sql, $params = []) {
    try { $st = db()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$total   = dash_scalar("SELECT COUNT(*) FROM shipments s WHERE 1=1$scopeSql", $scopeParams);
$pending = dash_scalar("SELECT COUNT(*) FROM shipments s WHERE s.status IN ('draft','submitted')$scopeSql", $scopeParams);

/* ---- value per shipment (sum of item amounts), and actual cost per
   shipment (sum of Final Costing unit-costs x each item's qty — counts
   BOTH draft and locked Final Costings, not locked-only: a draft costing
   is still real, entered cost data, not "no cost", so excluding it just
   understated cost and inflated profit/margin. final_costings.total_cost
   is a PER-UNIT figure, same convention as costing_report_print.php, so
   it must be multiplied by qty here too). ---- */
$valSub = "(SELECT COALESCE(SUM(amount),0) FROM shipment_items WHERE shipment_id=s.id)";
$costValSub = "(SELECT COALESCE(SUM(fc.total_cost * si.qty),0) FROM final_costings fc JOIN shipment_items si ON si.id=fc.shipment_item_id WHERE fc.shipment_id=s.id)";
// Sales total for ONLY the items that actually have a Final Costing — the
// same subset $costValSub already sums cost for. The difference
// ($valSub - $costedValSub) is sales with NO cost entered yet (very
// normal — costing routinely follows a shipment by a few days).
//
// House rule: any not-yet-costed sale is treated as its own cost — i.e.
// assumed break-even (0 profit) — rather than left out of the maths or,
// worse, offset by someone else's cost. That's why every profit/margin
// figure below is built from "true cost" = $costValSub (real, entered
// cost) PLUS ($valSub - $costedValSub) (uncosted sales, counted as their
// own cost). This can only ever pull profit/margin DOWN toward zero, so
// a % here can never be a blunder in the inflated direction — it's a
// floor that rises as costing catches up, never a number that overstates.
// $valSub itself stays the honest, unmodified "Total Sales" figure.
$costedValSub = "(SELECT COALESCE(SUM(si.amount),0) FROM shipment_items si WHERE si.shipment_id=s.id AND EXISTS (SELECT 1 FROM final_costings fc WHERE fc.shipment_item_id=si.id))";

$fx = fx_get_rates(); // live-synced weekly from Frankfurter, falls back to config on failure
function to_pkr(float $amount, ?string $currency, array $fx): float {
    $cur = strtoupper(trim((string)$currency)) ?: 'PKR';
    $rate = $fx[$cur] ?? 1;
    if ($rate <= 0) $rate = 1;
    return $amount / $rate; // fx_per_pkr is "units of currency per 1 PKR", so amount/rate = PKR
}

// Sales/cost/profit analytics is Admin-only now — Colleague and Staff get
// an operational (shipment counts + status) view instead, regardless of
// whether a Colleague account has rate visibility enabled elsewhere.
$canSeeAnalytics = $admin;

/* ============================================================
   GLOBAL TIME FILTER — Last 7 Days / Last 90 Days / Last 6 Months /
   Last Year / Custom. Rolling windows measured back from today, same
   idea as the fixed "last 90 days" / "last 6 months" windows every
   widget already used before this redesign — just selectable now
   instead of hardcoded. Deliberately NOT calendar periods (e.g. "This
   Month" = only the last few days of an in-progress month) because a
   rolling window is far less likely to land on an empty stretch. Every
   analytics widget below is scoped to [$curFrom, $curTo]; only the date
   range is a variable now, the tables/joins/formulas are unchanged. */
$period = $_GET['period'] ?? 'days90';
if (!in_array($period, ['days7', 'days90', 'months6', 'year1', 'custom'], true)) $period = 'days90';
$today = date('Y-m-d');
if ($period === 'days7') {
    $curFrom = date('Y-m-d', strtotime($today . ' -6 days')); $curTo = $today; $periodLabel = 'Last 7 Days';
} elseif ($period === 'months6') {
    $curFrom = date('Y-m-d', strtotime($today . ' -6 months')); $curTo = $today; $periodLabel = 'Last 6 Months';
} elseif ($period === 'year1') {
    $curFrom = date('Y-m-d', strtotime($today . ' -12 months')); $curTo = $today; $periodLabel = 'Last Year';
} elseif ($period === 'custom') {
    $curFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-89 days'));
    $curTo = $_GET['to'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $curFrom)) $curFrom = date('Y-m-d', strtotime('-89 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $curTo)) $curTo = $today;
    if ($curFrom > $curTo) { [$curFrom, $curTo] = [$curTo, $curFrom]; }
    if ($curTo > $today) $curTo = $today;
    $periodLabel = 'Custom: ' . date('d M Y', strtotime($curFrom)) . ' – ' . date('d M Y', strtotime($curTo));
} else {
    $period = 'days90';
    $curFrom = date('Y-m-d', strtotime($today . ' -89 days')); $curTo = $today; $periodLabel = 'Last 90 Days';
}
// previous period = immediately preceding window of equal length, so "vs
// prior period" always compares like-for-like regardless of which tab
$periodDays = (int)round((strtotime($curTo) - strtotime($curFrom)) / 86400) + 1;
$prevTo = date('Y-m-d', strtotime($curFrom . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($periodDays - 1) . ' days'));

$trendFrom = $curFrom; $trendTo = $curTo;
if ($period === 'days7') { $trendGran = 'day'; }
elseif ($period === 'days90') { $trendGran = 'week'; }
elseif ($period === 'months6' || $period === 'year1') { $trendGran = 'month'; }
else { $trendGran = $periodDays <= 31 ? 'day' : ($periodDays <= 366 ? 'week' : 'month'); }

/* One row per shipment, each converted to PKR using ITS OWN currency
   before summing in PHP — never SUM() in SQL across mixed currencies.
   (An earlier version of this function summed in SQL with no GROUP BY on
   currency, so a business with shipments in more than one currency would
   have every shipment's raw amount summed together and then the WRONG,
   arbitrarily-picked single currency's FX rate applied to that mixed
   total — producing nonsense Sales/Cost/Profit figures. Every other
   widget on this page already used the safe per-row pattern below; this
   was the one place that didn't.) */
function dash_period_totals(string $scopeSql, array $scopeParams, string $from, string $to, string $valSub, string $costedValSub, string $costValSub, array $fx): array {
    $sales = 0.0; $costedSales = 0.0; $cost = 0.0; $contracts = 0;
    try {
        $st = db()->prepare("SELECT UPPER(COALESCE(s.currency,'PKR')) cur, $valSub v, $costedValSub cv, $costValSub c
            FROM shipments s WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql");
        $st->execute(array_merge([$from, $to], $scopeParams));
        foreach ($st->fetchAll() as $r) {
            $contracts++;
            $sales += to_pkr((float)$r['v'], $r['cur'], $fx);
            $costedSales += to_pkr((float)$r['cv'], $r['cur'], $fx);
            $cost += to_pkr((float)$r['c'], $r['cur'], $fx);
        }
    } catch (Throwable $e) {}
    // break-even top-up: uncosted sales (sales - costed_sales) count as
    // their own cost, so total_cost is always fully defined and profit
    // can never be inflated by a not-yet-costed record.
    $breakeven = $sales - $costedSales;
    return ['sales' => $sales, 'costed_sales' => $costedSales, 'real_cost' => $cost, 'breakeven_cost' => $breakeven, 'cost' => $cost + $breakeven, 'contracts' => $contracts];
}

function dash_active_customers(string $scopeSql, array $scopeParams, string $from, string $to): int {
    return dash_scalar("SELECT COUNT(DISTINCT s.buyer_name) FROM shipments s WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql", array_merge([$from, $to], $scopeParams));
}

/* per-exact-date sales/cost, PKR-normalized — the one query the trend
   chart needs; dash_bucket_series() below re-groups this same result into
   day/week/month buckets in PHP, so only one query runs per page load
   regardless of which granularity the selected period calls for */
function dash_daily_series(string $scopeSql, array $scopeParams, string $from, string $to, string $valSub, string $costedValSub, string $costValSub, array $fx): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT DATE_FORMAT(s.invoice_date,'%Y-%m-%d') d, UPPER(COALESCE(s.currency,'PKR')) cur, SUM($valSub) sales, SUM($costedValSub) costed_sales, SUM($costValSub) cost
            FROM shipments s WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql GROUP BY d, cur");
        $st->execute(array_merge([$from, $to], $scopeParams));
        foreach ($st->fetchAll() as $r) {
            $d = $r['d']; if (!$d) continue;
            if (!isset($out[$d])) $out[$d] = ['sales' => 0.0, 'costed_sales' => 0.0, 'cost' => 0.0];
            $out[$d]['sales'] += to_pkr((float)$r['sales'], $r['cur'], $fx);
            $out[$d]['costed_sales'] += to_pkr((float)$r['costed_sales'], $r['cur'], $fx);
            $out[$d]['cost'] += to_pkr((float)$r['cost'], $r['cur'], $fx);
        }
    } catch (Throwable $e) {}
    return $out;
}

function dash_bucket_series(array $daily, string $from, string $to, string $granularity): array {
    $buckets = [];
    $cursor = new DateTime($from); $end = new DateTime($to);
    $empty = ['sales' => 0.0, 'costed_sales' => 0.0, 'cost' => 0.0];
    if ($granularity === 'day') {
        while ($cursor <= $end) {
            $d = $cursor->format('Y-m-d');
            $row = $daily[$d] ?? $empty;
            $buckets[] = ['label' => $cursor->format('D'), 'sales' => $row['sales'], 'costed_sales' => $row['costed_sales'], 'cost' => $row['cost']];
            $cursor->modify('+1 day');
        }
    } elseif ($granularity === 'week') {
        $wk = 1; $wkSales = 0.0; $wkCostedSales = 0.0; $wkCost = 0.0;
        while ($cursor <= $end) {
            $d = $cursor->format('Y-m-d');
            $row = $daily[$d] ?? $empty;
            $wkSales += $row['sales']; $wkCostedSales += $row['costed_sales']; $wkCost += $row['cost'];
            $next = (clone $cursor)->modify('+1 day');
            if ((int)$cursor->format('N') === 7 || $next > $end) {
                $buckets[] = ['label' => 'W' . $wk, 'sales' => $wkSales, 'costed_sales' => $wkCostedSales, 'cost' => $wkCost];
                $wk++; $wkSales = 0.0; $wkCostedSales = 0.0; $wkCost = 0.0;
            }
            $cursor = $next;
        }
    } else {
        $m = [];
        foreach ($daily as $d => $row) {
            if ($d < $from || $d > $to) continue;
            $ym = substr($d, 0, 7);
            if (!isset($m[$ym])) $m[$ym] = $empty;
            $m[$ym]['sales'] += $row['sales']; $m[$ym]['costed_sales'] += $row['costed_sales']; $m[$ym]['cost'] += $row['cost'];
        }
        ksort($m);
        foreach ($m as $ym => $row) $buckets[] = ['label' => date('M', strtotime($ym . '-01')), 'sales' => $row['sales'], 'costed_sales' => $row['costed_sales'], 'cost' => $row['cost']];
    }
    return $buckets;
}

/* ---- Sales & Total Profit per product — powers three widgets at once
   (Product Sales & Profit bars, Top Margin Leaders, Profitability Matrix)
   from this single query. Any item with no Final Costing yet counts its
   own sale price as its cost (assumed break-even) — same house rule as
   $costedValSub at the top of this file — so profit/pct are ALWAYS
   defined (never null/blank), and can only be pulled toward zero by
   missing cost data, never inflated by it. has_uncosted/breakeven_pct
   drive the small "⚠ not yet costed" note shown next to a product that's
   affected. ---- */
function dash_product_rows(string $scopeSql, array $scopeParams, string $from, string $to, array $fx): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT si.product_name grp, UPPER(COALESCE(s.currency,'PKR')) cur, si.amount amt,
                (SELECT fc.total_cost * si.qty FROM final_costings fc WHERE fc.shipment_item_id = si.id) cost_amt
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql");
        $st->execute(array_merge([$from, $to], $scopeParams));
        foreach ($st->fetchAll() as $r) {
            $raw = trim((string)$r['grp']); if ($raw === '') continue;
            // group by a case-insensitive key so "Thermal Blanket" and
            // "thermal blanket" (same product, typed differently on
            // different shipments) don't silently fragment into two bars —
            // display uses whichever casing was seen first.
            $g = mb_strtolower($raw);
            if (!isset($out[$g])) $out[$g] = ['name' => $raw, 'sales' => 0.0, 'costed_sales' => 0.0, 'real_cost' => 0.0];
            $amtPkr = to_pkr((float)$r['amt'], $r['cur'], $fx);
            $out[$g]['sales'] += $amtPkr;
            // costed_sales/real_cost only accumulate for items that actually
            // have a Final Costing — the gap (sales - costed_sales) is what
            // gets treated as break-even below.
            if ($r['cost_amt'] !== null) {
                $out[$g]['costed_sales'] += $amtPkr;
                $out[$g]['real_cost'] += to_pkr((float)$r['cost_amt'], $r['cur'], $fx);
            }
        }
    } catch (Throwable $e) {}
    $rows = [];
    foreach ($out as $d) {
        $breakevenAmt = max(0.0, $d['sales'] - $d['costed_sales']);
        $totalCost = $d['real_cost'] + $breakevenAmt;
        $profit = $d['sales'] - $totalCost;
        $pct = $totalCost > 0 ? round($profit / $totalCost * 100, 1) : 0.0;
        $breakevenPct = $d['sales'] > 0 ? round($breakevenAmt / $d['sales'] * 100, 1) : 0.0;
        $rows[] = ['name' => $d['name'], 'sales' => $d['sales'], 'cost' => $totalCost, 'profit' => $profit, 'pct' => $pct, 'has_uncosted' => $breakevenAmt > 1, 'breakeven_pct' => $breakevenPct];
    }
    usort($rows, fn($a, $b) => $b['sales'] <=> $a['sales']);
    return array_slice($rows, 0, 8);
}

/* ---- Shipment items with real sales but NO Final Costing at all — the
   direct, no-guessing list of what to go cost next, so the accuracy gap
   in Total Cost / Avg. Profit % above is fixable rather than just a
   silent "understated" caveat.
   GRACE PERIOD: costing routinely happens after the shipment (that's the
   normal workflow here), so a shipment from a few days ago having no
   costing yet isn't a problem — only ones older than DASH_COST_GRACE_DAYS
   are actually "overdue" and worth surfacing. This keeps the list from
   permanently nagging about completely normal in-progress work. ---- */
function dash_missing_cost_items(string $scopeSql, array $scopeParams, string $from, string $to, array $fx): array {
    $out = [];
    $graceCutoff = date('Y-m-d', strtotime('-' . DASH_COST_GRACE_DAYS . ' days'));
    try {
        $st = db()->prepare("SELECT s.id sid, s.invoice_no, s.buyer_name, UPPER(COALESCE(s.currency,'PKR')) cur,
                COALESCE(s.invoice_date, DATE(s.created_at)) dt, si.product_name, si.amount amt
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql
              AND COALESCE(s.invoice_date, DATE(s.created_at)) <= ?
              AND NOT EXISTS (SELECT 1 FROM final_costings fc WHERE fc.shipment_item_id = si.id)
            ORDER BY dt DESC");
        $st->execute(array_merge([$from, $to], $scopeParams, [$graceCutoff]));
        foreach ($st->fetchAll() as $r) {
            $out[] = ['sid'=>(int)$r['sid'],'invoice_no'=>$r['invoice_no'],'buyer_name'=>$r['buyer_name'],'product_name'=>$r['product_name'],'date'=>$r['dt'],'currency'=>$r['cur'],'amount'=>(float)$r['amt'],'amount_pkr'=>to_pkr((float)$r['amt'],$r['cur'],$fx)];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* ---- Top Customers — same join pattern and same break-even house rule as
   dash_product_rows() above, grouped by buyer instead of product, plus a
   shipment count. Any item with no Final Costing yet counts its own sale
   price as its cost, so profit/pct here are always defined and can only be
   pulled toward zero by missing cost data, never inflated by it. ---- */
function dash_top_customers(string $scopeSql, array $scopeParams, string $from, string $to, array $fx): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT s.id sid, s.buyer_name grp, s.status, UPPER(COALESCE(s.currency,'PKR')) cur, si.amount amt,
                (SELECT fc.total_cost * si.qty FROM final_costings fc WHERE fc.shipment_item_id = si.id) cost_amt
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql");
        $st->execute(array_merge([$from, $to], $scopeParams));
        foreach ($st->fetchAll() as $r) {
            $g = trim((string)$r['grp']); if ($g === '') continue;
            if (!isset($out[$g])) $out[$g] = ['sales' => 0.0, 'costed_sales' => 0.0, 'real_cost' => 0.0, 'shipments' => [], 'active' => false];
            $amtPkr = to_pkr((float)$r['amt'], $r['cur'], $fx);
            $out[$g]['sales'] += $amtPkr;
            if ($r['cost_amt'] !== null) {
                $out[$g]['costed_sales'] += $amtPkr;
                $out[$g]['real_cost'] += to_pkr((float)$r['cost_amt'], $r['cur'], $fx);
            }
            $out[$g]['shipments'][$r['sid']] = true;
            if (in_array($r['status'], ['draft', 'submitted'], true)) $out[$g]['active'] = true;
        }
    } catch (Throwable $e) {}
    $rows = [];
    foreach ($out as $name => $d) {
        $breakevenAmt = max(0.0, $d['sales'] - $d['costed_sales']);
        $totalCost = $d['real_cost'] + $breakevenAmt;
        $profit = $d['sales'] - $totalCost;
        $breakevenPct = $d['sales'] > 0 ? round($breakevenAmt / $d['sales'] * 100, 1) : 0.0;
        $rows[] = ['name' => $name, 'shipments' => count($d['shipments']), 'sales' => $d['sales'], 'cost' => $totalCost, 'profit' => $profit, 'has_uncosted' => $breakevenAmt > 1, 'breakeven_pct' => $breakevenPct, 'status' => $d['active'] ? 'active' : 'completed'];
    }
    usort($rows, fn($a, $b) => $b['sales'] <=> $a['sales']);
    return array_slice($rows, 0, 10);
}

function dash_top(string $groupCol, string $scopeSql, array $scopeParams, string $valSub, string $from, string $to, array $fx): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT s.$groupCol grp, UPPER(COALESCE(s.currency,'PKR')) cur, SUM($valSub) v
            FROM shipments s WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?$scopeSql GROUP BY grp, cur");
        $st->execute(array_merge([$from, $to], $scopeParams));
        foreach ($st->fetchAll() as $r) {
            $g = trim((string)$r['grp']); if ($g === '') continue;
            if (!isset($out[$g])) $out[$g] = 0.0;
            $out[$g] += to_pkr((float)$r['v'], $r['cur'], $fx);
        }
        arsort($out);
        $out = array_slice($out, 0, 10, true);
    } catch (Throwable $e) {}
    return $out;
}

/* Shipment counts by status — the operational view Colleague/Staff see
   instead of Sales/Cost/Profit figures. */
function dash_status_breakdown(string $scopeSql, array $scopeParams): array {
    $out = ['draft' => 0, 'submitted' => 0, 'approved_locked' => 0];
    try {
        $st = db()->prepare("SELECT s.status, COUNT(*) n FROM shipments s WHERE 1=1$scopeSql GROUP BY s.status");
        $st->execute($scopeParams);
        foreach ($st->fetchAll() as $r) { if (isset($out[$r['status']])) $out[$r['status']] = (int)$r['n']; }
    } catch (Throwable $e) {}
    return $out;
}

/* Shipments specifically assigned to this person (via shipment_assignments)
   that still need their action — their own drafts not yet submitted, ones
   Admin sent back for correction, or packing still open on ones they're on.
   Personal to the logged-in user, not the shared scope — a Colleague who
   can technically SEE every shipment doesn't need all of them nagging them
   as "pending", only the ones actually assigned to them. */
/* most recent dated shipment in scope, regardless of the selected period —
   used only to build a helpful hint when the selected period has nothing
   in it ("your data starts in March, try Year"), not for any calculation */
function dash_latest_dated(string $scopeSql, array $scopeParams): ?string {
    try {
        $st = db()->prepare("SELECT MAX(COALESCE(s.invoice_date, DATE(s.created_at))) FROM shipments s WHERE 1=1$scopeSql");
        $st->execute($scopeParams);
        $v = $st->fetchColumn();
        return $v ?: null;
    } catch (Throwable $e) { return null; }
}

function dash_my_pending_tasks(int $userId): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT DISTINCT s.id, s.invoice_no, s.buyer_name, s.status, s.packing_status, s.reopen_status
            FROM shipments s JOIN shipment_assignments sa ON sa.shipment_id = s.id AND sa.user_id = ?
            WHERE s.status = 'draft' OR s.reopen_status = 'reopened_for_correction' OR s.packing_status = 'open'
            ORDER BY s.id DESC LIMIT 20");
        $st->execute([$userId]);
        foreach ($st->fetchAll() as $r) {
            if ($r['reopen_status'] === 'reopened_for_correction') { $badge = 'urgent'; $label = 'Needs correction'; }
            elseif ($r['status'] === 'draft') { $badge = 'info'; $label = 'Draft — complete & submit'; }
            else { $badge = 'warn'; $label = 'Packing pending'; }
            $out[] = ['id' => (int)$r['id'], 'invoice_no' => $r['invoice_no'], 'buyer_name' => $r['buyer_name'], 'badge' => $badge, 'label' => $label];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Dashboard KPIs are the most query-heavy page in the app, but a KPI
   dashboard doesn't need to-the-second accuracy — a short TTL cache is a
   much better tradeoff than chasing every write path that could affect
   these numbers. Keyed by scope AND the selected period/range, so
   switching the filter never shows a stale result from a different
   period, and admin/colleague/staff each get their own correctly-scoped
   cached result. */
$dashKey = 'dashboard:' . md5($scopeSql . '|' . json_encode($scopeParams) . '|' . $period . '|' . $curFrom . '|' . $curTo) . ($canSeeAnalytics ? ':full' : ':basic:uid' . (int)$u['id']);
$dash = cache_remember($dashKey, 45, function () use ($scopeSql, $scopeParams, $canSeeAnalytics, $curFrom, $curTo, $prevFrom, $prevTo, $trendFrom, $trendTo, $trendGran, $valSub, $costedValSub, $costValSub, $fx, $u) {
    $curTotals = $canSeeAnalytics ? dash_period_totals($scopeSql, $scopeParams, $curFrom, $curTo, $valSub, $costedValSub, $costValSub, $fx) : ['sales' => 0.0, 'costed_sales' => 0.0, 'real_cost' => 0.0, 'breakeven_cost' => 0.0, 'cost' => 0.0, 'contracts' => 0];
    $prevTotals = $canSeeAnalytics ? dash_period_totals($scopeSql, $scopeParams, $prevFrom, $prevTo, $valSub, $costedValSub, $costValSub, $fx) : ['sales' => 0.0, 'costed_sales' => 0.0, 'real_cost' => 0.0, 'breakeven_cost' => 0.0, 'cost' => 0.0, 'contracts' => 0];
    $activeCustomers = $canSeeAnalytics ? dash_active_customers($scopeSql, $scopeParams, $curFrom, $curTo) : 0;
    $prevActiveCustomers = $canSeeAnalytics ? dash_active_customers($scopeSql, $scopeParams, $prevFrom, $prevTo) : 0;
    $activeShipments = dash_scalar("SELECT COUNT(*) FROM shipments s WHERE s.status IN ('draft','submitted')$scopeSql", $scopeParams);

    $trendBuckets = [];
    if ($canSeeAnalytics) {
        $daily = dash_daily_series($scopeSql, $scopeParams, $trendFrom, $trendTo, $valSub, $costedValSub, $costValSub, $fx);
        $trendBuckets = dash_bucket_series($daily, $trendFrom, $trendTo, $trendGran);
    }

    $productRows = $canSeeAnalytics ? dash_product_rows($scopeSql, $scopeParams, $curFrom, $curTo, $fx) : [];
    $topMarkets = $canSeeAnalytics ? dash_top('buyer_country', $scopeSql, $scopeParams, $valSub, $curFrom, $curTo, $fx) : [];
    $topCustomers = $canSeeAnalytics ? dash_top_customers($scopeSql, $scopeParams, $curFrom, $curTo, $fx) : [];
    $missingCostItems = $canSeeAnalytics ? dash_missing_cost_items($scopeSql, $scopeParams, $curFrom, $curTo, $fx) : [];

    $statusBreakdown = dash_status_breakdown($scopeSql, $scopeParams);
    $totalShipments = dash_scalar("SELECT COUNT(*) FROM shipments s WHERE 1=1$scopeSql", $scopeParams);
    $myPendingTasks = dash_my_pending_tasks((int)$u['id']);
    $latestDated = ($canSeeAnalytics && $curTotals['contracts'] === 0) ? dash_latest_dated($scopeSql, $scopeParams) : null;

    return compact('curTotals', 'prevTotals', 'activeCustomers', 'prevActiveCustomers', 'activeShipments', 'trendBuckets', 'productRows', 'topMarkets', 'topCustomers', 'missingCostItems', 'statusBreakdown', 'totalShipments', 'myPendingTasks', 'latestDated');
});
['curTotals' => $curTotals, 'prevTotals' => $prevTotals, 'activeCustomers' => $activeCustomers,
 'prevActiveCustomers' => $prevActiveCustomers, 'activeShipments' => $activeShipments, 'trendBuckets' => $trendBuckets,
 'productRows' => $productRows, 'topMarkets' => $topMarkets, 'topCustomers' => $topCustomers, 'missingCostItems' => $missingCostItems,
 'statusBreakdown' => $statusBreakdown, 'totalShipments' => $totalShipments, 'myPendingTasks' => $myPendingTasks,
 'latestDated' => $latestDated] = $dash;

$missingCostCount = count($missingCostItems);
// case-insensitive de-dupe, same reasoning as dash_product_rows() above —
// "Thermal Blanket" and "thermal blanket" shouldn't count as 2 products
$missingCostProducts = array_values(array_intersect_key(
    array_column($missingCostItems, 'product_name'),
    array_unique(array_map('mb_strtolower', array_column($missingCostItems, 'product_name')))
));
$missingCostProductCount = count($missingCostProducts);
$missingCostTotalPkr = array_sum(array_column($missingCostItems, 'amount_pkr'));

// $curTotals['cost'] already includes the break-even top-up (see
// dash_period_totals()), so Gross Profit and Avg. Profit % are always
// well-defined — never null, never overstated by a not-yet-costed sale.
$grossProfit = $curTotals['sales'] - $curTotals['cost'];
$avgMarginPct = $curTotals['sales'] > 0 ? round($grossProfit / $curTotals['sales'] * 100, 1) : null;
$prevGrossProfit = $prevTotals['sales'] - $prevTotals['cost'];

function dash_delta_pct(float $cur, float $prev): array {
    if ($prev <= 0.0001) return $cur > 0 ? ['label' => 'new vs prior period', 'dir' => 'up'] : ['label' => 'no change', 'dir' => 'flat'];
    $pct = round((($cur - $prev) / $prev) * 100, 1);
    $dir = $pct > 0.05 ? 'up' : ($pct < -0.05 ? 'down' : 'flat');
    return ['label' => ($pct >= 0 ? '+' : '') . $pct . '% vs prior period', 'dir' => $dir];
}

/* Converts a top-N name=>value array (e.g. $topMarkets) into donut
   segments — percentage is share of THIS top-N sum, not total company
   sales (there's no cheap "everyone else" total to compare against), so
   it's labelled "Top 10" in the UI, same honesty convention used
   throughout this dashboard. */
function dash_donut_segments(array $data): array {
    $sum = array_sum($data);
    if ($sum <= 0) return [];
    $out = [];
    foreach ($data as $name => $v) { $out[] = ['label' => $name, 'value' => $v, 'pct' => round($v / $sum * 100, 1)]; }
    return $out;
}
$donutColors = ['#0ea8c9', '#6d5bd0', '#d97706', '#16a34a', '#c0293f', '#0891b2', '#9333ea', '#ea580c', '#65a30d', '#db2777'];

function dash_donut_html(array $segments, array $colors, string $centerVal, string $centerLbl): string {
    if (!$segments) return '<p style="color:#8a97ab;font-size:13px;padding:20px 0;text-align:center">No dated shipments in this period.</p>';
    $circ = 364.4; $cum = 0.0;
    $circles = ''; $legend = '';
    foreach ($segments as $i => $seg) {
        $color = $colors[$i % count($colors)];
        $rot = round($cum * 3.6, 2);
        $circles .= '<circle class="donut-seg" data-pct="' . e((string)$seg['pct']) . '" cx="75" cy="75" r="58" fill="none" stroke="' . $color . '" stroke-width="20" stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $circ . '" transform="rotate(' . $rot . ' 75 75)"></circle>';
        $legend .= '<div class="donut-legend-row"><i class="donut-dot" style="background:' . $color . '"></i>' . e($seg['label']) . '<span class="pct">' . e((string)$seg['pct']) . '%</span></div>';
        $cum += $seg['pct'];
    }
    return '<div class="donut-wrap"><svg width="150" height="150" viewBox="0 0 150 150"><g class="donut-ring" transform="rotate(-90 75 75)"><circle cx="75" cy="75" r="58" fill="none" stroke="#eef1f6" stroke-width="20"></circle>'
        . $circles . '</g><text x="75" y="72" text-anchor="middle" class="donut-center-val" fill="#152033">' . e($centerVal) . '</text><text x="75" y="88" text-anchor="middle" class="donut-center-lbl" fill="#8a97ab">' . e($centerLbl) . '</text></svg>'
        . '<div class="donut-legend">' . $legend . '</div></div>';
}

/* Top Margin Leaders + the Matrix — same $productRows the bar list above
   already has. pct is always defined now (break-even house rule), so every
   product with any sales in the period is included; has_uncosted still
   flags the ones whose figure includes an assumed break-even portion. */
$marginRows = $productRows;
$marginLeaders = $marginRows;
usort($marginLeaders, fn($a, $b) => $b['pct'] <=> $a['pct']);
$marginLeaders = array_slice($marginLeaders, 0, 3);

/* ------------------------------------------------- proforma contracts

   Only for people who may see a proforma AND may see money. A panel of
   contract values is a rate screen by another name, so it obeys the same
   two gates the Proforma screen itself does rather than inventing a third.
   Nothing below runs at all for anyone else. */
$pfcShow = costing_perm('proforma') && can_see_rates();
$pfc     = $pfcShow ? pfc_summary($curFrom, $curTo) : ['ok' => false];

$cardCss = 'padding:20px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px)';

page_header('Dashboard');
flash();
?>
<style>
@keyframes zrise { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:none} }
.dash-card{transition:transform .25s ease, box-shadow .25s ease}
.dash-card:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(20,30,60,.12)}
.num{font-family:'Space Grotesk',system-ui,sans-serif;font-variant-numeric:tabular-nums}

/* global filter bar */
.gfb{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px}
.periods{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:12px;flex-wrap:wrap}
.ptab{padding:8px 15px;border-radius:9px;border:none;background:transparent;color:#5a6b82;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.ptab.on{background:#ffffff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.custom-form{display:flex;gap:6px;align-items:center}
.custom-form input{padding:7px 9px;border-radius:8px;border:1px solid #cbd5e3;font-size:12px;font-family:inherit}
.custom-form button{padding:7px 12px;border-radius:8px;border:none;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-size:12px;font-weight:700;cursor:pointer}

/* KPI row */
.dash-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px}
@media (max-width:1080px){.dash-kpis{grid-template-columns:repeat(3,1fr)}}
@media (max-width:640px){.dash-kpis{grid-template-columns:repeat(2,1fr)}}
.dash-num{font-size:21px;font-weight:700;margin-top:8px}
.kpi-delta{font-size:11px;font-weight:700;margin-top:6px}
.kpi-delta.up{color:#16a34a} .kpi-delta.down{color:#c0293f} .kpi-delta.flat{color:#8a97ab}

.dash-section-tag{color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.07em;margin:26px 2px 10px;font-weight:700}
.trend-pt{opacity:0;transition:opacity .3s ease}
.row2{display:grid;grid-template-columns:1fr 340px;gap:16px;margin-bottom:20px}
@media (max-width:920px){.row2{grid-template-columns:1fr}}

.donut-wrap{display:flex;align-items:center;gap:24px;flex-wrap:wrap;justify-content:center}
.donut-ring circle{transition:stroke-dashoffset 1.1s cubic-bezier(.16,1,.3,1)}
.donut-center-val{font-family:'Space Grotesk',system-ui,sans-serif;font-size:19px;font-weight:700}
.donut-center-lbl{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em}
.donut-legend{display:flex;flex-direction:column;gap:9px;flex:1;min-width:150px}
.donut-legend-row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#33415c}
.donut-legend-row .pct{margin-left:auto;font-family:'Space Grotesk',system-ui,sans-serif;font-weight:700;color:#5a6b82}
.donut-dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex-shrink:0}

.task-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 4px;border-bottom:1px solid #f6f8fc;text-decoration:none;color:inherit}
.task-row:last-child{border-bottom:none}
.task-row:hover{background:#f6f8fc}
.task-main{display:flex;align-items:center;gap:10px;min-width:0}
.task-inv{font-family:'Space Grotesk',system-ui,sans-serif;font-weight:700;color:#0ea8c9;font-size:13px;white-space:nowrap}
.task-buyer{color:#5a6b82;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.task-badge{font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.task-badge.urgent{background:rgba(224,67,93,.15);color:#c0293f}
.task-badge.warn{background:rgba(217,119,6,.16);color:#d97706}
.task-badge.info{background:rgba(14,168,201,.14);color:#0ea8c9}

/* Product Sales & Profit bar-in-bar */
.sp-row{display:grid;grid-template-columns:150px 1fr 120px;align-items:center;gap:14px;padding:11px 0;border-top:1px solid #f6f8fc}
.sp-row:first-of-type{border-top:none}
.sp-name{font-size:12.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#152033}
.sp-note{font-size:10px;font-weight:600;color:#d97706;margin-top:2px;white-space:nowrap}
.sp-bars{position:relative;height:22px}
.sp-track-sales{position:absolute;left:0;top:0;height:22px;border-radius:7px;background:linear-gradient(90deg,#0ea8c9,#6d5bd0);opacity:.22;width:0;transition:width 1s cubic-bezier(.16,1,.3,1)}
.sp-track-profit{position:absolute;left:0;top:5px;height:12px;border-radius:5px;background:linear-gradient(90deg,#0ea8c9,#6d5bd0);width:0;transition:width 1s cubic-bezier(.16,1,.3,1) .15s}
.sp-vals{text-align:right;font-size:11px}
.sp-vals .s1{color:#5a6b82;font-weight:600;display:block}
.sp-vals .s2{font-weight:700;margin-top:2px;display:block}
@media (max-width:700px){.sp-row{grid-template-columns:1fr;gap:6px}.sp-vals{text-align:left;display:flex;gap:12px}.sp-vals .s1,.sp-vals .s2{display:inline}}

/* Top Margin Leaders compact card */
.ml-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid #f6f8fc}
.ml-row:first-of-type{border-top:none}
.ml-rank{width:22px;height:22px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0ea8c9,#6d5bd0);flex-shrink:0}
.ml-name{flex:1;font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#152033}
.ml-pct{font-family:'Space Grotesk',system-ui,sans-serif;font-weight:800;font-size:14px}

/* Profitability Matrix */
.mx-pt{opacity:0;transition:opacity .4s ease, r .15s ease;cursor:pointer}
.mx-pt:hover{r:8}
.mx-label{font-size:10.5px;font-weight:700;fill:#152033}
.mx-sub{font-size:9px;fill:#8a97ab}
.quad-legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:14px;font-size:11px;color:#5a6b82}
.quad-legend span{display:inline-flex;align-items:center;gap:6px}
.quad-legend i{width:9px;height:9px;border-radius:3px;display:inline-block}

/* Top Customers table */
.ctbl{width:100%;border-collapse:collapse}
.ctbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;text-align:left;padding:0 8px 5px}
.ctbl th.num,.ctbl td.num{text-align:right}
.ctbl td{padding:3px 8px;border-top:1px solid #f6f8fc;font-size:12.5px}
.ctbl tr:first-of-type td{border-top:none}
.status-pill{font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px}
.status-pill.active{background:rgba(22,163,74,.12);color:#16a34a}
.status-pill.completed{background:rgba(138,151,171,.15);color:#8a97ab}
.scrollx{overflow-x:auto}
</style>

<div class="topbar"><div><h1><?= $admin ? 'Admin Dashboard' : 'Dashboard' ?></h1><p class="lead"><?= $canSeeAnalytics ? 'Sales, cost &amp; margin analytics — built from your shipment and Final Costing records.' : 'Shipment counts and status at a glance.' ?></p></div>
  <div style="display:flex;gap:10px">
  <?php if ($admin): ?><a class="zbtn sec" href="cost_audit.php?from=<?= e($curFrom) ?>&to=<?= e($curTo) ?>">Cost Audit →</a><?php endif; ?>
  <?php if ($admin): ?><a class="zbtn sec" href="weavecontract/" target="_blank" rel="noopener">WeaveContract →</a><?php endif; ?>
  <a class="zbtn sec" href="shipments.php">All Shipments →</a>
  </div>
</div>

<?php if (!$canSeeAnalytics): ?>
<!-- OPERATIONAL VIEW — Colleague / Staff: shipment counts + status only, no money figures. Unchanged by this redesign. -->
<div class="dash-kpis" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .5s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Total Shipments</span>
    <div class="dash-num num" data-count-to="<?= (int)$totalShipments ?>">0</div>
    <div style="color:#5a6b82;font-size:12px;margin-top:4px">All time</div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .55s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Active Shipments</span>
    <div class="dash-num num" data-count-to="<?= (int)$activeShipments ?>">0</div>
    <div style="color:#5a6b82;font-size:12px;margin-top:4px">Draft or submitted</div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .6s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Draft</span>
    <div class="dash-num num" data-count-to="<?= (int)$statusBreakdown['draft'] ?>">0</div>
    <div style="color:#5a6b82;font-size:12px;margin-top:4px">Not yet submitted</div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .65s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Submitted</span>
    <div class="dash-num num" data-count-to="<?= (int)$statusBreakdown['submitted'] ?>">0</div>
    <div style="color:#5a6b82;font-size:12px;margin-top:4px">Awaiting approval</div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .7s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Approved &amp; Locked</span>
    <div class="dash-num num" data-count-to="<?= (int)$statusBreakdown['approved_locked'] ?>">0</div>
    <div style="color:#5a6b82;font-size:12px;margin-top:4px">Fully approved</div>
  </div>
</div>

<div class="dash-section-tag">My Pending Tasks — assigned to you, needs your action</div>
<div class="dash-card" style="<?= $cardCss ?>;padding:10px 22px">
  <?php if ($myPendingTasks): foreach ($myPendingTasks as $t): ?>
  <a class="task-row" href="shipment_view.php?id=<?= (int)$t['id'] ?>">
    <div class="task-main">
      <span class="task-inv"><?= e($t['invoice_no']) ?></span>
      <span class="task-buyer"><?= e($t['buyer_name']) ?></span>
    </div>
    <span class="task-badge <?= e($t['badge']) ?>"><?= e($t['label']) ?></span>
  </a>
  <?php endforeach; else: ?>
  <p style="color:#8a97ab;font-size:13px;padding:16px 0;text-align:center;margin:0">Nothing pending — you're all caught up.</p>
  <?php endif; ?>
</div>

<div class="dash-card" style="<?= $cardCss ?>;padding:20px;text-align:center;margin-top:16px">
  <p style="color:#8a97ab;font-size:12px;margin:0">Sales, cost &amp; margin analytics are Admin-only.</p>
</div>
<?php else: ?>

<!-- GLOBAL TIME FILTER -->
<div class="gfb">
  <div style="color:#5a6b82;font-size:12.5px;font-weight:600"><?= e($periodLabel) ?> <span style="color:#8a97ab;font-weight:400">· every card below reflects this period</span></div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div class="periods">
      <a class="ptab <?= $period==='days7'?'on':'' ?>" href="?period=days7">Last 7 Days</a>
      <a class="ptab <?= $period==='days90'?'on':'' ?>" href="?period=days90">Last 90 Days</a>
      <a class="ptab <?= $period==='months6'?'on':'' ?>" href="?period=months6">Last 6 Months</a>
      <a class="ptab <?= $period==='year1'?'on':'' ?>" href="?period=year1">Last Year</a>
      <a class="ptab <?= $period==='custom'?'on':'' ?>" href="?period=custom">Custom</a>
    </div>
    <?php if ($period === 'custom'): ?>
    <form method="get" class="custom-form">
      <input type="hidden" name="period" value="custom">
      <input type="date" name="from" value="<?= e($curFrom) ?>" max="<?= e($today) ?>">
      <span style="color:#8a97ab;font-size:12px">to</span>
      <input type="date" name="to" value="<?= e($curTo) ?>" max="<?= e($today) ?>">
      <button type="submit">Apply</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($curTotals['contracts'] === 0): ?>
<div class="dash-card" style="<?= $cardCss ?>;padding:14px 20px;margin-bottom:16px;border-color:rgba(217,119,6,.3);background:rgba(217,119,6,.06)">
  <p style="margin:0;font-size:12.5px;color:#a15c00;line-height:1.6">
    <b>No dated shipments in <?= e($periodLabel) ?>.</b>
    <?php if ($latestDated):
      $withinLastYear = strtotime($latestDated) >= strtotime($today . ' -12 months');
    ?>
      Your most recent dated shipment is from <b><?= e(date('d M Y', strtotime($latestDated))) ?></b> —
      <?php if ($withinLastYear): ?>
        try <a href="?period=year1" style="color:#0ea8c9;font-weight:700;text-decoration:none">Last Year</a>
      <?php else: ?>
        try a <a href="?period=custom&amp;from=<?= e(date('Y-m-d', strtotime($latestDated . ' -14 days'))) ?>&amp;to=<?= e($latestDated) ?>" style="color:#0ea8c9;font-weight:700;text-decoration:none">Custom range</a> around that date
      <?php endif; ?>
      to see it.
    <?php else: ?>
      No dated shipments found at all yet — figures below will fill in once shipments have an invoice date.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>

<?php if ($canSeeAnalytics && $missingCostCount > 0): ?>
<div class="dash-card" style="<?= $cardCss ?>;padding:14px 20px;margin-bottom:16px;border-color:rgba(217,119,6,.3);background:rgba(217,119,6,.06)">
  <p style="margin:0;font-size:12.5px;color:#a15c00;line-height:1.6">
    <b><?= $missingCostProductCount ?> product<?= $missingCostProductCount===1?'':'s' ?> in <?= e($periodLabel) ?> <?= $missingCostProductCount===1?'is':'are' ?> overdue for costing</b>
    (shipped more than <?= DASH_COST_GRACE_DAYS ?> days ago, still no cost entered) — <?= e(implode(', ', array_slice($missingCostProducts, 0, 5))) ?><?= $missingCostProductCount > 5 ? ' and '.($missingCostProductCount-5).' more' : '' ?>.
    <?= number_format($missingCostTotalPkr,0) ?> PKR-equivalent in sales is counted below, but with no cost we can't work out their profit, so it's left blank instead of guessed. Shipments costed within the first <?= DASH_COST_GRACE_DAYS ?> days aren't flagged — that's normal turnaround, not a problem. See the full list below.
  </p>
</div>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="dash-kpis">
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .5s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Total Sales</span>
    <div class="dash-num num" data-count-to="<?= (int)round($curTotals['sales']) ?>">0</div>
    <?php $d = dash_delta_pct($curTotals['sales'], $prevTotals['sales']); ?>
    <div class="kpi-delta <?= e($d['dir']) ?>"><?= $d['dir']==='up'?'▲':($d['dir']==='down'?'▼':'•') ?> <?= e($d['label']) ?></div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .55s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Total Cost</span>
    <div class="dash-num num" data-count-to="<?= (int)round($curTotals['cost']) ?>">0</div>
    <?php $d = dash_delta_pct($curTotals['cost'], $prevTotals['cost']); ?>
    <div class="kpi-delta <?= e($d['dir']) ?>"><?= $d['dir']==='up'?'▲':($d['dir']==='down'?'▼':'•') ?> <?= e($d['label']) ?></div>
    <?php if ($curTotals['breakeven_cost'] > 1): ?><div class="kpi-delta" style="color:#d97706;margin-top:2px">⚠ incl. <?= number_format($curTotals['breakeven_cost'],0) ?> assumed break-even (not yet costed)</div><?php endif; ?>
    <?php if ($missingCostCount > 0): ?><div class="kpi-delta" style="color:#d97706;margin-top:2px"><?= $missingCostProductCount ?> product<?= $missingCostProductCount===1?'':'s' ?> overdue for costing</div><?php endif; ?>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .6s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Gross Profit</span>
    <?php if ($curTotals['cost'] > 0): ?>
    <div class="dash-num num" style="color:<?= $grossProfit>=0?'#16a34a':'#c0293f' ?>" data-count-to="<?= (int)round($grossProfit) ?>">0</div>
    <?php $d = dash_delta_pct($grossProfit, $prevGrossProfit); ?>
    <div class="kpi-delta <?= e($d['dir']) ?>"><?= $d['dir']==='up'?'▲':($d['dir']==='down'?'▼':'•') ?> <?= e($d['label']) ?></div>
    <?php else: ?>
    <div class="dash-num">—</div>
    <div class="kpi-delta flat">No cost data yet — can't work out profit</div>
    <?php endif; ?>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .65s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Avg. Profit %</span>
    <div class="dash-num"><?= $avgMarginPct !== null ? $avgMarginPct.'%' : '—' ?></div>
    <div class="kpi-delta flat"><?= $curTotals['breakeven_cost'] > 1 ? 'Includes not-yet-costed sales, assumed break-even' : 'All sales in this period are costed' ?></div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .7s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Active Shipments</span>
    <div class="dash-num num" data-count-to="<?= (int)$activeShipments ?>">0</div>
    <div class="kpi-delta flat">Draft or submitted, right now</div>
  </div>
  <div class="dash-card" style="<?= $cardCss ?>;animation:zrise .75s ease both">
    <span style="color:#5a6b82;font-size:12.5px">Active Customers</span>
    <div class="dash-num num" data-count-to="<?= (int)$activeCustomers ?>">0</div>
    <?php $d = dash_delta_pct($activeCustomers, $prevActiveCustomers); ?>
    <div class="kpi-delta <?= e($d['dir']) ?>"><?= $d['dir']==='up'?'▲':($d['dir']==='down'?'▼':'•') ?> <?= e($d['label']) ?></div>
  </div>
</div>

<?php if ($canSeeAnalytics && $missingCostItems): ?>
<div class="dash-section-tag">Overdue Costing (<?= DASH_COST_GRACE_DAYS ?>+ days old) — <?= e($periodLabel) ?></div>
<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:20px">
  <div style="overflow-x:auto">
  <table class="ctbl" style="width:100%;border-collapse:collapse;font-size:12.5px">
    <thead><tr>
      <th style="text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;padding:0 10px 8px">Invoice</th>
      <th style="text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;padding:0 10px 8px">Buyer</th>
      <th style="text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;padding:0 10px 8px">Product</th>
      <th style="text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;padding:0 10px 8px">Date</th>
      <th class="num" style="text-align:right;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;padding:0 10px 8px">Sale Amount</th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach (array_slice($missingCostItems, 0, 25) as $m): ?>
      <tr>
        <td style="padding:10px;border-top:1px solid #f6f8fc;color:#0ea8c9;font-weight:600"><?= e($m['invoice_no']) ?></td>
        <td style="padding:10px;border-top:1px solid #f6f8fc"><?= e($m['buyer_name']) ?></td>
        <td style="padding:10px;border-top:1px solid #f6f8fc"><?= e($m['product_name']) ?></td>
        <td style="padding:10px;border-top:1px solid #f6f8fc;color:#5a6b82"><?= e(date('d M Y', strtotime($m['date']))) ?></td>
        <td class="num" style="padding:10px;border-top:1px solid #f6f8fc;text-align:right"><?= e($m['currency']) ?> <?= number_format($m['amount'],2) ?></td>
        <td style="padding:10px;border-top:1px solid #f6f8fc"><a href="final_costing.php?shipment_id=<?= (int)$m['sid'] ?>" style="color:#0ea8c9;font-weight:700;font-size:12px;text-decoration:none">Add costing →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($missingCostItems) > 25): ?><p style="font-size:11.5px;color:#8a97ab;margin-top:10px">+<?= count($missingCostItems)-25 ?> more not shown.</p><?php endif; ?>
</div>
<?php endif; ?>

<!-- SALES TREND — the green line is costed_sales - cost (real cost, costed
     items only), which is arithmetically identical to the break-even
     convention's profit (an uncosted item's assumed break-even cost always
     nets to zero either way), so no separate top-up is needed here. -->
<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
    <h2 style="font-size:15px;margin:0">Sales Trend</h2>
    <div style="display:flex;gap:16px;font-size:11px;color:#5a6b82">
      <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:12px;height:3px;border-radius:2px;background:#0ea8c9;display:inline-block"></span>Sales</span>
      <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:12px;height:3px;border-radius:2px;background:#16a34a;display:inline-block"></span>Gross Profit</span>
    </div>
  </div>
  <?php if (count($trendBuckets) >= 2):
    $n = count($trendBuckets); $W = 1100; $H = 230; $padB = 30; $padT = 20;
    $maxV = 1; foreach ($trendBuckets as $b) { $maxV = max($maxV, $b['sales'], $b['costed_sales'] - $b['cost']); }
    $pt = function($i, $val) use ($n, $W, $H, $padB, $padT, $maxV) {
        $x = 20 + $i * (($W - 40) / ($n - 1));
        $y = ($H - $padB) - (($val / $maxV) * ($H - $padT - $padB));
        return round($x, 1) . ',' . round($y, 1);
    };
    $salesPts = []; $profitPts = [];
    foreach ($trendBuckets as $i => $b) { $salesPts[] = $pt($i, $b['sales']); $profitPts[] = $pt($i, $b['costed_sales'] - $b['cost']); }
    $lastX = 20 + ($n - 1) * (($W - 40) / ($n - 1));
    $areaPts = implode(' ', $salesPts) . " $lastX," . ($H - $padB) . " 20," . ($H - $padB);
  ?>
  <svg viewBox="0 0 1100 230" style="width:100%;height:230px;overflow:visible">
    <defs><linearGradient id="dashTrendGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#0ea8c9" stop-opacity="0.18"/><stop offset="100%" stop-color="#0ea8c9" stop-opacity="0"/></linearGradient></defs>
    <?php for ($g=0; $g<4; $g++): $gy = $padT + $g*(($H-$padT-$padB)/3); ?><line x1="0" y1="<?= $gy ?>" x2="1100" y2="<?= $gy ?>" stroke="#eef1f6"/><?php endfor; ?>
    <polygon points="<?= e($areaPts) ?>" fill="url(#dashTrendGrad)"/>
    <polyline id="dashSalesLine" points="<?= e(implode(' ', $salesPts)) ?>" fill="none" stroke="#0ea8c9" stroke-width="2.5" stroke-dasharray="2400" stroke-dashoffset="2400"/>
    <polyline id="dashProfitLine" points="<?= e(implode(' ', $profitPts)) ?>" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-dasharray="2400" stroke-dashoffset="2400"/>
    <g id="dashTrendPts">
      <?php foreach ($trendBuckets as $i => $b): [$sx, $sy] = explode(',', $salesPts[$i]); [$px, $py] = explode(',', $profitPts[$i]); ?>
      <circle class="trend-pt" cx="<?= $sx ?>" cy="<?= $sy ?>" r="4" fill="#0ea8c9"><title>Sales · <?= e($b['label']) ?>: Rs <?= number_format($b['sales'],0) ?></title></circle>
      <circle class="trend-pt" cx="<?= $px ?>" cy="<?= $py ?>" r="4" fill="#16a34a"><title>Profit · <?= e($b['label']) ?>: Rs <?= number_format($b['costed_sales']-$b['cost'],0) ?></title></circle>
      <?php endforeach; ?>
    </g>
    <?php foreach ($trendBuckets as $i => $b): [$mx, ] = explode(',', $pt($i, 0)); ?>
    <text x="<?= $mx ?>" y="<?= $H-8 ?>" font-size="10.5" fill="#8a97ab" text-anchor="middle"><?= e($b['label']) ?></text>
    <?php endforeach; ?>
  </svg>
  <?php elseif (count($trendBuckets) === 1): ?>
  <p style="color:#8a97ab;font-size:13px;padding:40px 0;text-align:center">Only one data point in this range — need at least two to draw a trend.</p>
  <?php else: ?>
  <p style="color:#8a97ab;font-size:13px;padding:40px 0;text-align:center">No dated shipments in this period yet.</p>
  <?php endif; ?>
</div>

<!-- PRODUCT SALES & PROFIT + MARKET SHARE / TOP MARGIN LEADERS -->
<div class="row2">
  <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
    <h2 style="font-size:15px;margin:0 0 4px">Product Sales &amp; Total Profit</h2>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">Large bar = Sales · inner bar = Profit · top 8 by sales, PKR-equivalent</p>
    <?php if ($productRows): ?>
    <?php $maxSales = max(array_column($productRows, 'sales')) ?: 1; ?>
    <?php foreach ($productRows as $p): $salesW = max(2, round($p['sales']/$maxSales*100)); $profitW = max(0, round(max(0,$p['profit'])/$maxSales*100)); ?>
    <div class="sp-row">
      <div class="sp-name"><?= e($p['name']) ?><?php if ($p['has_uncosted']): ?><div class="sp-note">⚠ <?= e($p['breakeven_pct']) ?>% not yet costed</div><?php endif; ?></div>
      <div class="sp-bars"><div class="dash-anim-bar sp-track-sales" style="width:<?= $salesW ?>%"></div><div class="dash-anim-bar sp-track-profit" style="width:<?= $profitW ?>%"></div></div>
      <div class="sp-vals"><span class="s1 num">≈<?= number_format($p['sales'],0) ?></span><span class="s2 num" style="color:<?= $p['profit']>=0?'#16a34a':'#c0293f' ?>">≈<?= number_format($p['profit'],0) ?></span></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?><p style="color:#8a97ab;font-size:13px;padding:20px 0;text-align:center">No dated shipment items in this period.</p><?php endif; ?>
  </div>

  <div>
    <div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:16px">
      <h2 style="font-size:15px;margin:0 0 4px">Market Share</h2>
      <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">Sales by country · top 10</p>
      <?= dash_donut_html(dash_donut_segments($topMarkets), $donutColors, number_format(array_sum($topMarkets),0), 'PKR (top 10)') ?>
    </div>
    <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
      <h2 style="font-size:15px;margin:0 0 4px">Top Markup Leaders</h2>
      <p style="color:#8a97ab;font-size:11.5px;margin:0 0 12px">Highest profit % on cost</p>
      <?php if ($marginLeaders): foreach ($marginLeaders as $i => $p): ?>
      <div class="ml-row"><div class="ml-rank"><?= $i+1 ?></div><div class="ml-name"><?= e($p['name']) ?><?php if ($p['has_uncosted']): ?><div class="sp-note">⚠ incl. break-even</div><?php endif; ?></div><div class="ml-pct num" style="color:<?= $p['pct']>=0?'#16a34a':'#c0293f' ?>"><?= e($p['pct']) ?>%</div></div>
      <?php endforeach; else: ?><p style="color:#8a97ab;font-size:13px;padding:10px 0;text-align:center;margin:0">No dated shipment items in this period.</p><?php endif; ?>
    </div>
  </div>
</div>

<!-- PROFITABILITY MATRIX -->
<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:20px">
  <h2 style="font-size:15px;margin:0 0 4px">Profitability Matrix</h2>
  <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">X: Total Profit · Y: Unit Markup % — each point is one product</p>
  <?php if (count($marginRows) >= 2):
    $mW = 1100; $mH = 360; $mPadL = 55; $mPadR = 40; $mPadT = 30; $mPadB = 40;
    $profits = array_column($marginRows, 'profit'); $pcts = array_column($marginRows, 'pct');
    /* range must cover negative values too (a loss-making product) — a
       0-to-max-only scale pins every negative point to the left/bottom
       edge regardless of how negative it actually is, which is wrong */
    $rawMinProfit = min($profits); $rawMaxProfit = max($profits);
    $profitPad = max(($rawMaxProfit - $rawMinProfit) * 0.12, abs($rawMaxProfit) * 0.1, 1);
    $minProfit = $rawMinProfit - $profitPad; $maxProfit = $rawMaxProfit + $profitPad;
    $rawMinPct = min($pcts); $rawMaxPct = max($pcts);
    $pctPad = max(($rawMaxPct - $rawMinPct) * 0.12, abs($rawMaxPct) * 0.1, 1);
    $minPct = $rawMinPct - $pctPad; $maxPct = $rawMaxPct + $pctPad;
    sort($profits); sort($pcts);
    $medProfit = $profits[(int)floor(count($profits)/2)];
    $medPct = $pcts[(int)floor(count($pcts)/2)];
    $mx = fn($v) => $mPadL + (($v - $minProfit)/($maxProfit - $minProfit))*($mW-$mPadL-$mPadR);
    $my = fn($v) => ($mH-$mPadB) - (($v - $minPct)/($maxPct - $minPct))*($mH-$mPadT-$mPadB);
    $midX = $mx($medProfit); $midY = $my($medPct);
    function dash_quad(float $profit, float $pct, float $medProfit, float $medPct): string {
        if ($profit >= $medProfit && $pct >= $medPct) return '#16a34a';
        if ($profit >= $medProfit && $pct < $medPct) return '#0ea8c9';
        if ($profit < $medProfit && $pct >= $medPct) return '#6d5bd0';
        return '#d97706';
    }
  ?>
  <svg viewBox="0 0 1100 360" style="width:100%;height:360px;overflow:visible">
    <rect x="<?= $midX ?>" y="<?= $mPadT ?>" width="<?= $mW-$mPadR-$midX ?>" height="<?= $midY-$mPadT ?>" fill="#16a34a" opacity="0.06"/>
    <rect x="<?= $midX ?>" y="<?= $midY ?>" width="<?= $mW-$mPadR-$midX ?>" height="<?= $mH-$mPadB-$midY ?>" fill="#0ea8c9" opacity="0.06"/>
    <rect x="<?= $mPadL ?>" y="<?= $mPadT ?>" width="<?= $midX-$mPadL ?>" height="<?= $midY-$mPadT ?>" fill="#6d5bd0" opacity="0.06"/>
    <rect x="<?= $mPadL ?>" y="<?= $midY ?>" width="<?= $midX-$mPadL ?>" height="<?= $mH-$mPadB-$midY ?>" fill="#d97706" opacity="0.06"/>
    <line x1="<?= $midX ?>" y1="<?= $mPadT ?>" x2="<?= $midX ?>" y2="<?= $mH-$mPadB ?>" stroke="#cbd5e3" stroke-dasharray="4 4"/>
    <line x1="<?= $mPadL ?>" y1="<?= $midY ?>" x2="<?= $mW-$mPadR ?>" y2="<?= $midY ?>" stroke="#cbd5e3" stroke-dasharray="4 4"/>
    <?php if ($minProfit < 0 && $maxProfit > 0): $zeroX = $mx(0); ?>
    <line x1="<?= $zeroX ?>" y1="<?= $mPadT ?>" x2="<?= $zeroX ?>" y2="<?= $mH-$mPadB ?>" stroke="#c0293f" stroke-width="1.5"/>
    <text x="<?= $zeroX ?>" y="<?= $mPadT-8 ?>" text-anchor="middle" font-size="9.5" fill="#c0293f" font-weight="700">Breakeven</text>
    <?php endif; ?>
    <line x1="<?= $mPadL ?>" y1="<?= $mH-$mPadB ?>" x2="<?= $mW-$mPadR ?>" y2="<?= $mH-$mPadB ?>" stroke="#e3e9f2"/>
    <line x1="<?= $mPadL ?>" y1="<?= $mPadT ?>" x2="<?= $mPadL ?>" y2="<?= $mH-$mPadB ?>" stroke="#e3e9f2"/>
    <text x="<?= $mW-$mPadR ?>" y="<?= $mH-$mPadB+22 ?>" text-anchor="end" font-size="10.5" fill="#8a97ab">Total Profit →</text>
    <text x="<?= $mPadL-10 ?>" y="<?= $mPadT+4 ?>" text-anchor="end" font-size="10.5" fill="#8a97ab">↑ Unit Markup %</text>
    <?php foreach ($marginRows as $i => $p): $cx = $mx($p['profit']); $cy = $my($p['pct']); $color = dash_quad($p['profit'], $p['pct'], $medProfit, $medPct); $note = $p['has_uncosted'] ? ' — ⚠ incl. '.e((string)$p['breakeven_pct']).'% not yet costed' : ''; ?>
    <circle class="mx-pt" cx="<?= $cx ?>" cy="<?= $cy ?>" r="6.5" fill="<?= $color ?>" stroke="<?= $p['has_uncosted'] ? '#d97706' : '#ffffff' ?>" stroke-width="2" stroke-dasharray="<?= $p['has_uncosted'] ? '2 1.5' : 'none' ?>" style="transition-delay:<?= $i*60 ?>ms"><title><?= e($p['name']) ?> — Profit <?= number_format($p['profit'],0) ?> · <?= e($p['pct']) ?>%<?= $note ?></title></circle>
    <text class="mx-label" x="<?= $cx+10 ?>" y="<?= $cy-6 ?>"><?= e($p['name']) ?></text>
    <text class="mx-sub" x="<?= $cx+10 ?>" y="<?= $cy+7 ?>"><?= e($p['pct']) ?>%<?= $p['has_uncosted'] ? ' ⚠' : '' ?></text>
    <?php endforeach; ?>
  </svg>
  <div class="quad-legend">
    <span><i style="background:#16a34a"></i>Excellent — high profit, high margin</span>
    <span><i style="background:#0ea8c9"></i>Volume — high profit, lower margin</span>
    <span><i style="background:#6d5bd0"></i>Premium — lower profit, high margin</span>
    <span><i style="background:#d97706"></i>Review — low profit, low margin</span>
    <span><i style="background:#fff;border:2px dashed #d97706"></i>⚠ Has not-yet-costed sales</span>
  </div>
  <?php else: ?>
  <p style="color:#8a97ab;font-size:13px;padding:30px 0;text-align:center">Need at least 2 products with dated sales in this period to plot the matrix.</p>
  <?php endif; ?>
</div>

<!-- ============================ PROFORMA CONTRACTS ============================ -->
<?php if ($pfcShow && $pfc['ok'] && $pfc['count'] > 0):
  $pfcTotal = (float)$pfc['total_pkr'];
  $pfcCust  = $pfc['customers'];
  $pfcProd  = $pfc['products'];
  $pfcBar   = function (string $name, float $v, float $max, string $label, string $sub = ''): string {
      $w = $max > 0 ? max(1.5, $v / $max * 100) : 0;
      return '<div class="pfc-bar"><div class="pfc-nm" title="' . e($name) . '">' . e($name) . '</div>'
           . '<div class="pfc-tr"><div class="pfc-fl" style="width:' . number_format($w, 1, '.', '') . '%"></div></div>'
           . '<div class="pfc-vl">' . e($label) . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</div></div>';
  };
  $pfcCurCol = ['USD' => '#0ea8c9', 'EUR' => '#6d5bd0', 'GBP' => '#16a34a', 'PKR' => '#d97706'];
?>
<style>
.pfc-bar{display:grid;grid-template-columns:minmax(92px,150px) 1fr auto;gap:10px;align-items:center;font-size:12.5px;margin-bottom:9px}
.pfc-nm{font-weight:600;color:#152033;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.pfc-tr{background:#e3e9f2;border-radius:5px;height:17px;overflow:hidden;min-width:0}
.pfc-fl{height:100%;border-radius:5px;background:linear-gradient(90deg,#0ea8c9,#6d5bd0)}
.pfc-vl{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap;font-size:12px;color:#152033}
.pfc-vl small{display:block;font-weight:500;color:#8a97ab;font-size:10.5px;text-align:right}
.pfc-unit{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:#8a97ab;font-weight:700;margin:10px 0 2px}
.pfc-unit:first-child{margin-top:0}
.pfc-split{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width:820px){.pfc-split{grid-template-columns:1fr}}
.pfc-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:16px}
.pfc-kc{padding:14px 16px;border-radius:14px;background:#fff;border:1px solid #e3e9f2}
.pfc-kc .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:#8a97ab;font-weight:700}
.pfc-kc .v{font-size:22px;font-weight:700;margin-top:4px;font-variant-numeric:tabular-nums;letter-spacing:-.4px;color:#152033}
.pfc-kc .n{font-size:11.5px;color:#5a6b82;margin-top:2px}
.pfc-note{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.26);border-radius:10px;padding:11px 14px;font-size:12.5px;color:#5a6b82;margin-top:14px}
.pfc-note b{color:#152033}
</style>

<div style="margin:26px 0 10px">
  <h2 style="font-size:15px;margin:0 0 2px">Proforma Contracts</h2>
  <p style="color:#8a97ab;font-size:11.5px;margin:0">
    Sent, confirmed and converted proformas in <?= e($periodLabel) ?> · converted at today's rate<?php
      $rbits = [];
      foreach ($pfc['rates'] as $c => $r) { if ($c !== 'PKR' && $r > 0) $rbits[] = $c . ' ' . number_format($r, 2); }
      echo $rbits ? ' — ' . e(implode(' · ', $rbits)) : '';
    ?>
  </p>
</div>

<div class="pfc-k">
  <div class="pfc-kc"><div class="k">Contracted value</div><div class="v">PKR <?= number_format($pfcTotal, 0) ?></div><div class="n"><?= (int)$pfc['count'] ?> proforma<?= $pfc['count'] === 1 ? '' : 's' ?></div></div>
  <div class="pfc-kc"><div class="k">Customers</div><div class="v"><?= count($pfcCust) ?></div><div class="n">with a contract</div></div>
  <div class="pfc-kc"><div class="k">Products</div><div class="v"><?= count($pfcProd) ?></div><div class="n">across all contracts</div></div>
  <div class="pfc-kc"><div class="k">Average contract</div><div class="v">PKR <?= number_format($pfc['count'] ? $pfcTotal / $pfc['count'] : 0, 0) ?></div><div class="n">per proforma</div></div>
</div>

<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:16px">
  <h2 style="font-size:15px;margin:0 0 4px">Contract value by customer</h2>
  <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">PKR — original currency underneath</p>
  <?php $cmax = $pfcCust ? (float)$pfcCust[0]['pkr'] : 0; ?>
  <?php foreach ($pfcCust as $c): ?>
    <?= $pfcBar((string)$c['name'], (float)$c['pkr'], $cmax, 'PKR ' . number_format($c['pkr'], 0),
          ($c['mixed'] ? 'mixed currency' : $c['cur'] . ' ' . number_format($c['own'], 2)) . ' · ' . (int)$c['n'] . ' PI') ?>
  <?php endforeach; ?>
</div>

<div class="pfc-split" style="margin-bottom:16px">
  <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
    <h2 style="font-size:15px;margin:0 0 4px">Contract value by product</h2>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">PKR</p>
    <?php $pmax = $pfcProd ? (float)$pfcProd[0]['pkr'] : 0; ?>
    <?php foreach ($pfcProd as $p): ?>
      <?= $pfcBar((string)$p['label'], (float)$p['pkr'], $pmax, 'PKR ' . number_format($p['pkr'], 0),
            ($pfcTotal > 0 ? number_format($p['pkr'] / $pfcTotal * 100, 1) . '%' : '')) ?>
      <?php if (!empty($p['merged'])): ?>
        <div style="font-size:10.5px;color:#8a97ab;margin:-5px 0 9px 0">counts <?= e(implode(', ', array_slice($p['merged'], 0, 4))) ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
    <h2 style="font-size:15px;margin:0 0 4px">Quantity by product</h2>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">Each unit on its own</p>
    <?php foreach ($pfc['qty'] as $unit => $rows):
      $qmax = $rows ? (float)$rows[0]['qty'] : 0; ?>
      <div class="pfc-unit"><?= e($unit) ?></div>
      <?php foreach ($rows as $r): ?>
        <?= $pfcBar((string)$r['label'], (float)$r['qty'], $qmax, number_format($r['qty'], 0)) ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<div class="pfc-split" style="margin-bottom:16px">
  <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
    <h2 style="font-size:15px;margin:0 0 4px">Currency mix</h2>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">Share of contracted value</p>
    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
      <?php
        $C = 2 * M_PI * 58; $off = 0; $segs = '';
        foreach ($pfc['currencies'] as $cur => $v) {
            $frac = $pfcTotal > 0 ? $v / $pfcTotal : 0;
            $col  = $pfcCurCol[$cur] ?? '#8a97ab';
            $segs .= '<circle cx="75" cy="75" r="58" fill="none" stroke="' . $col . '" stroke-width="20"'
                   . ' stroke-dasharray="' . number_format($frac * $C, 2, '.', '') . ' ' . number_format($C, 2, '.', '') . '"'
                   . ' stroke-dashoffset="' . number_format(-$off * $C, 2, '.', '') . '"></circle>';
            $off += $frac;
        }
      ?>
      <svg width="150" height="150" viewBox="0 0 150 150" role="img" aria-label="Share of contracted value by currency">
        <g transform="rotate(-90 75 75)"><circle cx="75" cy="75" r="58" fill="none" stroke="#eef1f6" stroke-width="20"></circle><?= $segs ?></g>
      </svg>
      <div style="font-size:12.5px;display:flex;flex-direction:column;gap:7px">
        <?php foreach ($pfc['currencies'] as $cur => $v): ?>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="width:11px;height:11px;border-radius:3px;background:<?= e($pfcCurCol[$cur] ?? '#8a97ab') ?>;display:inline-block"></span>
            <b style="min-width:34px"><?= e($cur) ?></b>
            <span style="color:#5a6b82">PKR <?= number_format($v, 0) ?></span>
            <span style="color:#8a97ab"><?= $pfcTotal > 0 ? number_format($v / $pfcTotal * 100, 1) : '0' ?>%</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="dash-card" style="<?= $cardCss ?>;padding:22px">
    <h2 style="font-size:15px;margin:0 0 4px">Customer detail</h2>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">The same figures as a list</p>
    <div class="scrollx">
      <table class="ctbl">
        <thead><tr><th>Customer</th><th>Cur</th><th class="num">PI</th><th class="num">Value (PKR)</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($pfcCust as $c): ?>
          <tr>
            <td style="font-weight:600"><?= e($c['name']) ?></td>
            <td><?= $c['mixed'] ? '<span style="color:#8a97ab">mixed</span>' : e($c['cur']) ?></td>
            <td class="num"><?= (int)$c['n'] ?></td>
            <td class="num" style="font-weight:700"><?= number_format($c['pkr'], 0) ?></td>
            <td class="num"><?= $pfcTotal > 0 ? number_format($c['pkr'] / $pfcTotal * 100, 1) : '0' ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin-bottom:16px">
  <div class="pfc-note" style="margin-top:0">
    <b>Two things worth knowing.</b>
    <div style="margin-top:6px">Quantities are never added across units. Pc, Set, Kg and Mtr each have
    their own list and there is no grand total, because adding them would produce a number that looks
    right and means nothing.</div>
    <div style="margin-top:5px">A converted proforma is also a commercial invoice. This figure must
    not be added to the sales figures above — the same business would be counted twice.</div>
  </div>
  <?php if (!empty($pfc['missing_rate'])): ?>
    <div style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.32);border-radius:10px;padding:11px 14px;font-size:12.5px;color:#9a5a06;margin-top:12px">
      <b>Left out of the total.</b>
      <?php $bits = []; foreach ($pfc['missing_rate'] as $cur => $n) $bits[] = $n . ' in ' . $cur; ?>
      <?= e(implode(', ', $bits)) ?> — no exchange rate is configured for
      <?= count($pfc['missing_rate']) === 1 ? 'that currency' : 'those currencies' ?>, and converting at
      zero would have quietly shrunk the figures above. Set the rate in Settings and they will appear.
    </div>
  <?php endif; ?>
</div>
<?php elseif ($pfcShow && $pfc['ok']): ?>
<div class="dash-card" style="<?= $cardCss ?>;padding:22px;margin:26px 0 16px">
  <h2 style="font-size:15px;margin:0 0 4px">Proforma Contracts</h2>
  <p style="color:#8a97ab;font-size:12.5px;margin:0">
    No sent, confirmed or converted proforma falls in <?= e($periodLabel) ?>. Drafts are not counted —
    a draft is not a commitment.
  </p>
</div>
<?php endif; ?>

<!-- TOP CUSTOMERS -->
<div class="dash-card" style="<?= $cardCss ?>;padding:22px">
  <h2 style="font-size:15px;margin:0 0 4px">Top Customers</h2>
  <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px">By sales value in this period</p>
  <?php if ($topCustomers): ?>
  <div class="scrollx">
  <table class="ctbl">
    <thead><tr><th>Customer</th><th class="num">Shipments</th><th class="num">Sales</th><th class="num">Profit</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($topCustomers as $c): ?>
    <tr>
      <td style="font-weight:600"><?= e($c['name']) ?><?php if ($c['has_uncosted']): ?><div class="sp-note">⚠ incl. <?= e((string)$c['breakeven_pct']) ?>% not yet costed</div><?php endif; ?></td>
      <td class="num num"><?= (int)$c['shipments'] ?></td>
      <td class="num num">≈<?= number_format($c['sales'],0) ?></td>
      <td class="num num" style="color:<?= $c['profit']>=0?'#16a34a':'#c0293f' ?>;font-weight:700">≈<?= number_format($c['profit'],0) ?></td>
      <td><span class="status-pill <?= e($c['status']) ?>"><?= $c['status']==='active'?'Active':'Completed' ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?><p style="color:#8a97ab;font-size:13px;padding:20px 0;text-align:center">No dated shipments in this period.</p><?php endif; ?>
</div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var REDUCE = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('.dash-anim-bar').forEach(function (el) {
    ['height', 'width'].forEach(function (prop) {
      var target = el.style[prop];
      if (!target || target === '0%') return;
      el.style[prop] = '0%';
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { el.style[prop] = target; });
      });
    });
  });
  document.querySelectorAll('[data-count-to]').forEach(function (el) {
    var target = parseInt(el.getAttribute('data-count-to'), 10) || 0;
    if (REDUCE) { el.textContent = target.toLocaleString('en-US'); return; }
    var start = null, dur = 900;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased).toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });

  // ---- sales trend draw-in ----
  var salesLine = document.getElementById('dashSalesLine');
  var profitLine = document.getElementById('dashProfitLine');
  if (salesLine && profitLine) {
    if (REDUCE) {
      salesLine.style.strokeDashoffset = 0;
      profitLine.style.strokeDashoffset = 0;
      document.querySelectorAll('.trend-pt').forEach(function (c) { c.style.opacity = 1; });
    } else {
      setTimeout(function () {
        salesLine.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(.16,1,.3,1)';
        profitLine.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(.16,1,.3,1) .12s';
        salesLine.style.strokeDashoffset = 0;
        profitLine.style.strokeDashoffset = 0;
      }, 150);
      setTimeout(function () {
        document.querySelectorAll('.trend-pt').forEach(function (c, i) {
          setTimeout(function () { c.style.transition = 'opacity .3s ease'; c.style.opacity = 1; }, i * 40);
        });
      }, 1250);
    }
  }

  // ---- donut sweep-in ----
  document.querySelectorAll('.donut-seg').forEach(function (c, i) {
    var circ = 364.4, pct = parseFloat(c.dataset.pct) || 0;
    var target = circ - (circ * pct / 100);
    var setDonut = function () { c.style.strokeDashoffset = target; };
    if (REDUCE) { setDonut(); } else { setTimeout(setDonut, 200 + i * 120); }
  });

  // ---- profitability matrix fade-in ----
  document.querySelectorAll('.mx-pt').forEach(function (c, i) {
    var setPt = function () { c.style.opacity = 1; };
    if (REDUCE) { setPt(); } else { setTimeout(setPt, 200 + i * 70); }
  });
});
</script>
<?php page_footer(); ?>
