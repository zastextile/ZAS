<?php
/*
  PROFORMA CONTRACTS — what has been contracted, by customer and by product,
  every currency shown in PKR.

  Kept in its own file rather than inside dashboard.php so that the
  arithmetic can be read and tested on its own, and so the dashboard gains
  one block of markup instead of two hundred lines of query.

  ------------------------------------------------------------ the rules

  WHAT COUNTS AS A CONTRACT. Sent, confirmed and converted. A draft is not a
  commitment and an archived one is finished with, so neither is counted. The
  list is here, in one constant, because that is the line most likely to need
  changing later.

  WHICH RATE. Today's rate, from the same fx_rates table the rest of the app
  uses. This means a contract agreed in April is restated every morning as
  the rupee moves. The alternative — the rate on the day the proforma was
  written — is more truthful for a signed contract, but it needs a rate
  stored on each proforma at save time, and no such column exists. Rather
  than invent a rate for records already saved, this uses today's and says so
  on screen.

  WHY IT IS NEVER ADDED TO SALES. A converted proforma also exists as a
  commercial invoice. The dashboard shows both; adding them would count the
  same business twice. The panel says this in words rather than relying on
  nobody doing the sum.

  QUANTITIES ARE NOT ADDED ACROSS UNITS. 4,000 Pc, 900 Set and 1,200 Kg are
  not 6,100 of anything. Quantity is grouped by unit, and there is no grand
  total — a single figure would look right and mean nothing.
*/

const PFC_STATUSES = ['sent', 'confirmed', 'converted'];
const PFC_TOP_CUSTOMERS = 10;
const PFC_TOP_PRODUCTS  = 10;
const PFC_TOP_PER_UNIT  = 5;

/* Product names are typed by hand, so "Bath Towel", "bath towel" and
   "Bath  Towels" are three rows unless they are folded together. Case,
   spacing and a trailing plural are ignored; nothing else is. Two genuinely
   different products with different names stay different.

   The label kept for display is the first spelling seen, so the screen shows
   something that was actually typed rather than a normalised key. */
function pfc_norm_product(string $s): string {
    /* Punctuation becomes a SPACE, not nothing. Deleting it turned
       "Bath-Towel" into "bathtowel" while "Bath Towel" stayed "bath towel",
       so the hyphenated spelling never merged with the plain one — the exact
       case this function exists to catch. */
    $k = strtolower(trim($s));
    $k = (string)preg_replace('/[^a-z0-9]+/', ' ', $k);
    $k = (string)preg_replace('/\s+/', ' ', $k);
    $k = (string)preg_replace('/\b(\w{4,})s\b/', '$1', $k);   /* towels -> towel */
    return trim($k);
}

function pfc_norm_unit(string $u): string {
    $u = trim($u);
    if ($u === '') return 'Pc';
    $map = ['pc' => 'Pc', 'pcs' => 'Pc', 'piece' => 'Pc', 'pieces' => 'Pc',
            'set' => 'Set', 'sets' => 'Set', 'kg' => 'Kg', 'kgs' => 'Kg',
            'mtr' => 'Mtr', 'mtrs' => 'Mtr', 'meter' => 'Mtr', 'metre' => 'Mtr', 'm' => 'Mtr'];
    return $map[strtolower($u)] ?? $u;
}

/* Everything the panel needs, in one pass over two queries.
 *
 * $from / $to are the dashboard's own period, matched on pi_date and falling
 * back to created_at the same way the shipment queries do — a proforma with
 * no date typed is still a real proforma and must not vanish from the total.
 */
function pfc_summary(string $from, string $to): array
{
    $out = [
        'ok' => false, 'total_pkr' => 0.0, 'count' => 0,
        'customers' => [], 'products' => [], 'qty' => [], 'currencies' => [],
        'missing_rate' => [], 'rates' => [],
    ];

    try {
        $in = implode(',', array_fill(0, count(PFC_STATUSES), '?'));
        $params = array_merge(PFC_STATUSES, [$from, $to]);

        /* The header rows. One row per proforma, with its own total taken
           from its items — proforma_invoices has no total column. */
        $st = db()->prepare(
            "SELECT pf.id, pf.customer_name, pf.currency, pf.status,
                    COALESCE(pf.pi_date, DATE(pf.created_at)) AS d,
                    COALESCE((SELECT SUM(pi.amount) FROM proforma_items pi WHERE pi.proforma_id = pf.id), 0) AS own_total
               FROM proforma_invoices pf
              WHERE pf.status IN ($in)
                AND COALESCE(pf.pi_date, DATE(pf.created_at)) BETWEEN ? AND ?"
        );
        $st->execute($params);
        $heads = $st->fetchAll();
    } catch (Throwable $e) {
        return $out;   /* the panel simply does not draw */
    }

    if (!$heads) { $out['ok'] = true; return $out; }

    $rates = [];
    $byCust = [];
    $byCur  = [];
    $ids    = [];

    foreach ($heads as $h) {
        $id  = (int)$h['id'];
        $ids[] = $id;
        $cur = strtoupper(trim((string)($h['currency'] ?? 'PKR')) ?: 'PKR');
        $own = (float)$h['own_total'];

        if (!array_key_exists($cur, $rates)) $rates[$cur] = exp_pkr_rate($cur);
        $rate = (float)$rates[$cur];

        /* A currency with no rate configured converts to zero, which would
           quietly shrink the total. Counted and named instead. */
        if ($rate <= 0) {
            $out['missing_rate'][$cur] = ($out['missing_rate'][$cur] ?? 0) + 1;
            continue;
        }

        $pkrv = $own * $rate;
        $name = trim((string)($h['customer_name'] ?? '')) ?: '(no customer name)';

        $out['total_pkr'] += $pkrv;
        $out['count']++;

        if (!isset($byCust[$name])) $byCust[$name] = ['name' => $name, 'pkr' => 0.0, 'own' => 0.0, 'cur' => $cur, 'n' => 0, 'mixed' => false];
        $byCust[$name]['pkr'] += $pkrv;
        $byCust[$name]['own'] += $own;
        $byCust[$name]['n']++;
        if ($byCust[$name]['cur'] !== $cur) $byCust[$name]['mixed'] = true;

        $byCur[$cur] = ($byCur[$cur] ?? 0) + $pkrv;
    }

    /* The lines. Fetched for exactly the proformas counted above, so a line
       can never slip in from a proforma the status filter excluded. */
    $byProd = [];
    $byQty  = [];
    if ($ids) {
        try {
            $qin = implode(',', array_fill(0, count($ids), '?'));
            $lt = db()->prepare(
                "SELECT pi.proforma_id, pi.product_name, pi.unit, pi.qty, pi.amount
                   FROM proforma_items pi
                  WHERE pi.proforma_id IN ($qin)"
            );
            $lt->execute($ids);
            $lines = $lt->fetchAll();
        } catch (Throwable $e) { $lines = []; }

        $curById = [];
        foreach ($heads as $h) $curById[(int)$h['id']] = strtoupper(trim((string)($h['currency'] ?? 'PKR')) ?: 'PKR');

        foreach ($lines as $l) {
            $cur  = $curById[(int)$l['proforma_id']] ?? 'PKR';
            $rate = (float)($rates[$cur] ?? 0);
            if ($rate <= 0) continue;

            $label = trim((string)($l['product_name'] ?? ''));
            if ($label === '') $label = '(no product name)';
            $key = pfc_norm_product($label);
            if ($key === '') $key = strtolower($label);

            if (!isset($byProd[$key])) $byProd[$key] = ['label' => $label, 'pkr' => 0.0, 'spellings' => []];
            $byProd[$key]['pkr'] += (float)$l['amount'] * $rate;
            $byProd[$key]['spellings'][$label] = true;

            $unit = pfc_norm_unit((string)($l['unit'] ?? ''));
            $qk   = $unit . '|' . $key;
            if (!isset($byQty[$qk])) $byQty[$qk] = ['label' => $label, 'unit' => $unit, 'qty' => 0.0];
            $byQty[$qk]['qty'] += (float)$l['qty'];
        }
    }

    usort($byCust, fn($a, $b) => $b['pkr'] <=> $a['pkr']);
    $out['customers'] = array_slice(array_values($byCust), 0, PFC_TOP_CUSTOMERS);

    $prods = array_values($byProd);
    usort($prods, fn($a, $b) => $b['pkr'] <=> $a['pkr']);
    foreach ($prods as &$p) $p['merged'] = count($p['spellings']) > 1 ? array_keys($p['spellings']) : [];
    unset($p);
    $out['products'] = array_slice($prods, 0, PFC_TOP_PRODUCTS);

    /* Grouped by unit, biggest unit group first, and capped per unit so one
       unit with forty products cannot push the card off the screen. */
    $units = [];
    foreach ($byQty as $row) $units[$row['unit']][] = $row;
    uasort($units, function ($a, $b) {
        return array_sum(array_column($b, 'qty')) <=> array_sum(array_column($a, 'qty'));
    });
    foreach ($units as $u => $rows) {
        usort($rows, fn($a, $b) => $b['qty'] <=> $a['qty']);
        $out['qty'][$u] = array_slice($rows, 0, PFC_TOP_PER_UNIT);
    }

    arsort($byCur);
    $out['currencies'] = $byCur;
    $out['rates']      = $rates;
    $out['ok']         = true;
    return $out;
}
