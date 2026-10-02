<?php
/*
  THE QUERY ROUTER — Phase 3a.
  ============================

  Reads a question typed in plain words and turns it into SQL. No OpenAI, no
  embedding, no cost.

  WHY THIS EXISTS
  ---------------
  Ten example questions were written down when this module was specified:
  shipments to Belgium with payment outstanding, Spain shipments in
  September, which shipment used container SEKU6489931, invoices with HS code
  630231, freight above USD 3,000, partially paid invoices, and so on.

  Every one of them is a database question. Not one needs an embedding. Yet
  the old search sent all of them to OpenAI twice — once to embed the
  question and once to write a paragraph — and returned prose where a list
  was wanted. This file answers them from the tables instead.

  THE SPENDING RULE
  -----------------
  Nothing here calls OpenAI. The paid routes exist, but only behind a button
  the operator presses, and only while the monthly cap has room. Before this,
  search.php spent two calls on every keystroke-driven search with no cap, no
  cache and no counter.

  THE DICTIONARIES ARE READ FROM YOUR DATA
  ----------------------------------------
  Countries, ports, buyers, freight agents and products are not hard-coded.
  They are the distinct values already in your tables, so the router learns a
  new buyer the moment you invoice them.
*/

const QR_MODES = ['shipment', 'proforma', 'costing'];

/* Words that carry no filter. Stripped before deciding whether anything in
   the question went unrecognised, so a correct search does not report
   "not understood: payment" beside a correct answer. */
const QR_FILLER = [
    'show','find','list','all','the','me','my','with','where','is','are','was','were',
    'for','of','in','to','on','at','and','or','a','an','any','which','what','did','do',
    'we','us','using','used','use','still','by','from','please','give','get','see',
    'shipment','shipments','invoice','invoices','proforma','proformas','costing','costings',
    'order','orders','payment','payments','document','documents','doc','docs',
    'container','containers','value','amount','total','no','number','code','against',
    'made','been','have','has','that','this','it','there','about','much','many','last',
];

/* ------------------------------------------------------------ dictionaries

   One query each, only for the mode being searched, and only once per
   request. These are small lists — distinct buyers and ports, not rows. */
function qr_dict(string $what): array {
    static $cache = [];
    if (isset($cache[$what])) return $cache[$what];

    $sql = [
        'country' => "SELECT DISTINCT buyer_country v FROM shipments WHERE buyer_country<>'' ",
        'port'    => "SELECT DISTINCT destination_port v FROM shipments WHERE destination_port<>'' ",
        'buyer'   => "SELECT DISTINCT buyer_name v FROM shipments WHERE buyer_name<>'' ",
        'customer'=> "SELECT DISTINCT customer_name v FROM proforma_invoices WHERE customer_name<>'' ",
        'agent'   => "SELECT DISTINCT name v FROM exp_providers WHERE is_active=1 ",
        'product' => "SELECT DISTINCT name v FROM products WHERE is_active=1 ",
    ][$what] ?? '';

    $out = [];
    if ($sql !== '') {
        try {
            foreach (db()->query($sql . " LIMIT 500")->fetchAll() as $r) {
                /* ?? '' rather than $r['v'] directly: every query here aliases
                   the column, but a dictionary is a convenience and must never
                   be the thing that fills a log with warnings. */
                $v = trim((string)($r['v'] ?? ''));
                if ($v !== '') $out[mb_strtolower($v)] = $v;
            }
        } catch (Throwable $e) {}
    }
    /* Longest first, so "Port Qasim" is matched before "Port". */
    uksort($out, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $cache[$what] = $out;
}

/* The document prefixes the search box should recognise on sight.
 *
 * The two built-in ones are always in the list even after you change the
 * format, because every number already saved under the old format still
 * starts with them and must stay findable. Numbering may not be loaded on
 * every page that searches, hence the guard. */
function qr_doc_prefixes(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = ['PI', 'INV', 'CI'];
    if (function_exists('exp_numbering_prefixes')) {
        foreach (exp_numbering_prefixes() as $p) {
            $p = strtoupper(trim((string)$p));
            if (preg_match('~^[A-Z][A-Z0-9]{0,9}$~', $p)) $out[] = $p;
        }
    }
    $out = array_values(array_unique($out));
    /* Longest first, so ZASPI is not matched as ZAS with a stray PI. */
    usort($out, fn($a, $b) => strlen($b) <=> strlen($a));
    return $cache = $out;
}

/* --------------------------------------------------------------- the parse

   Each pattern that fires removes its own words from the question, so what
   remains at the end is genuinely unrecognised rather than already used. */
function qr_parse(string $mode, string $raw): array
{
    if (!in_array($mode, QR_MODES, true)) $mode = 'shipment';

    $q = ' ' . mb_strtolower(trim(preg_replace('/\s+/', ' ', $raw))) . ' ';
    $f = []; $used = [];

    $eat = function (string $re, callable $fn) use (&$q, &$used): bool {
        if (!preg_match($re, $q, $m)) return false;
        $fn($m);
        $used[] = trim($m[0]);
        $q = str_replace($m[0], ' ', $q);
        return true;
    };

    /* --- unmistakable codes first, so their digits are not read as money --- */

    /* A container is four letters then seven digits. Nothing else looks like that. */
    $eat('~\b([a-z]{4}\s?\d{7})\b~', function ($m) use (&$f) {
        $f['container'] = strtoupper(preg_replace('/\s/', '', $m[1]));
    });

    /* AN HS CODE IS ONLY EVER RECOGNISED WHEN IT SAYS SO.
     *
     * There is no "six loose digits means HS code" rule, and there must not
     * be one. next_doc_no() builds every document number in this system as
     * PREFIX-YYMMDD-NNN, so PI-260908-786, INV-260908-786 and CI-260908-455
     * all contain a six-digit date. A bare-digits fallback would answer a
     * search for a proforma with a search for an HS code — a wrong answer
     * wearing the shape of a right one.
     *
     * So: write "HS 630231" and it is an HS code. Type six digits on their
     * own and the router says it did not understand, which is the truth. */
    $eat('~\b(?:hs|tariff)\s*(?:code|no\.?)?\s*:?\s*(\d{6,10})\b~',
        function ($m) use (&$f) { $f['hs'] = $m[1]; });

    /* YOUR OWN SERIES, read from Export Masters → Numbering.
     *
     * This runs before the general rule below because the general rule needs
     * a separator, and a format of your own need not have one: set the code
     * to ZAS and the format to {PREFIX}{YYYY}{SEQ:4} and the number is
     * ZAS20260001, which no shape rule would pick out of a sentence. Once a
     * prefix is a configured one, the letters are enough. */
    $prefixes = qr_doc_prefixes();
    if ($prefixes) {
        $alt = implode('|', array_map(fn($p) => preg_quote(mb_strtolower($p), '~'), $prefixes));
        $eat('~\b((?:' . $alt . ')\s*[-/]?\s*\d+(?:\s*[-/]\s*\d+)*)\b~', function ($m) use (&$f) {
            $f['ref'] = strtoupper(preg_replace('/\s/', '', $m[1]));
        });
    }

    /* A document reference: a short letter prefix, a separator, then digits —
       with or without the date block in the middle. Covers ZAS/5191 as typed
       by hand, PI-260908-786 as generated, PI-786 as short_ref() prints it,
       and CI/26/0099. */
    $eat('~\b([a-z]{2,5}\s*[-/]\s*\d{1,8}(?:\s*[-/]\s*\d{1,6})?)\b~', function ($m) use (&$f) {
        $f['ref'] = strtoupper(preg_replace('/\s/', '', $m[1]));
    });

    $eat('~\bbl\s*(?:no\.?|number)?\s*:?\s*([a-z]{3,5}\d{5,})\b~', function ($m) use (&$f) {
        $f['bl'] = strtoupper($m[1]);
    });

    /* --- money --- */
    $eat('~\b(?:over|above|more than|greater than|exceeding|>)\s*(?:usd|eur|gbp|pkr|rs\.?|\$)?\s*([\d,]+(?:\.\d+)?)~',
        function ($m) use (&$f) { $f['min_amount'] = (float)str_replace(',', '', $m[1]); });
    $eat('~\b(?:under|below|less than|up to|<)\s*(?:usd|eur|gbp|pkr|rs\.?|\$)?\s*([\d,]+(?:\.\d+)?)~',
        function ($m) use (&$f) { $f['max_amount'] = (float)str_replace(',', '', $m[1]); });

    /* Is the comparison about freight or cost rather than the document total? */
    if (isset($f['min_amount']) || isset($f['max_amount'])) {
        if ($mode === 'shipment' && preg_match('~\bfreight\b~', $q)) {
            $f['amount_on'] = 'freight'; $used[] = 'freight'; $q = preg_replace('~\bfreight\b~', ' ', $q);
        } elseif ($mode === 'costing' && preg_match('~\b(price|selling)\b~', $q)) {
            $f['amount_on'] = 'price'; $used[] = 'price'; $q = preg_replace('~\b(price|selling)\b~', ' ', $q);
        }
    }

    $eat('~\b(usd|eur|gbp|pkr)\b~', function ($m) use (&$f) { $f['currency'] = strtoupper($m[1]); });

    /* --- payment, shipments only --- */
    if ($mode === 'shipment') {
        if ($eat('~\b(partially paid|part paid|partly paid|part payment)\b~', function () use (&$f) { $f['pay'] = 'partial'; })) {}
        elseif ($eat('~\b(outstanding|unpaid|not paid|no payment|owing|receivable)\b~', function () use (&$f) { $f['pay'] = 'unpaid'; })) {}
        elseif ($eat('~\b(fully paid|paid in full|settled)\b~', function () use (&$f) { $f['pay'] = 'paid'; })) {}
        elseif ($eat('~\bpaid\b~', function () use (&$f) { $f['pay'] = 'paid'; })) {}

        $eat('~\b(booking pending|container loaded|in transit|sailing|arrived|delivered|on hold|booked)\b~',
            function ($m) use (&$f) { $f['logistics'] = $m[1]; });

        if ($eat('~\bchamber\b~', function () use (&$f) { $f['doc'] = 'chamber'; })) {}
        elseif ($eat('~\bcustoms\b~', function () use (&$f) { $f['doc'] = 'customs'; })) {}
    }

    /* --- document status --- */
    $eat('~\b(draft|submitted|approved|locked|converted|final|sent)\b~', function ($m) use (&$f) {
        $f['status'] = ($m[1] === 'approved') ? 'locked' : $m[1];
    });

    /* --- dates --- */
    $months = ['january','february','march','april','may','june','july','august',
               'september','october','november','december'];
    foreach ($months as $i => $mn) {
        if (!isset($f['month']) && preg_match('~\b' . $mn . '\b~', $q)) {
            $f['month'] = $i + 1; $f['month_name'] = ucfirst($mn);
            $used[] = $mn; $q = preg_replace('~\b' . $mn . '\b~', ' ', $q);
        }
    }
    $eat('~\b(20\d\d)\b~', function ($m) use (&$f) { $f['year'] = (int)$m[1]; });

    if ($eat('~\bthis month\b~', function () use (&$f) {
            $f['month'] = (int)date('n'); $f['month_name'] = date('F'); $f['year'] = (int)date('Y'); })) {}
    elseif ($eat('~\blast month\b~', function () use (&$f) {
            $t = strtotime('first day of last month');
            $f['month'] = (int)date('n', $t); $f['month_name'] = date('F', $t); $f['year'] = (int)date('Y', $t); })) {}
    $eat('~\bthis year\b~', function () use (&$f) { $f['year'] = (int)date('Y'); });
    $eat('~\blast (\d{1,3}) days?\b~', function ($m) use (&$f) { $f['days'] = max(1, (int)$m[1]); });
    $eat('~\b(this week|last week)\b~', function ($m) use (&$f) { $f['days'] = $m[1] === 'this week' ? 7 : 14; });

    /* --- names, matched against what the database actually holds --- */
    $lists = $mode === 'shipment' ? ['country' => 'country', 'port' => 'port', 'agent' => 'agent', 'buyer' => 'buyer']
           : ($mode === 'proforma' ? ['customer' => 'buyer'] : ['product' => 'product']);

    foreach ($lists as $dict => $key) {
        if (isset($f[$key])) continue;
        foreach (qr_dict($dict) as $low => $real) {
            if (mb_strpos($q, $low) !== false) {
                $f[$key] = $real;
                $used[] = $real;
                $q = str_replace($low, ' ', $q);
                break;
            }
        }
    }

    /* Whatever survives, once the filler is removed, was not understood. */
    $left = $q;
    foreach (QR_FILLER as $w) $left = preg_replace('~\b' . preg_quote($w, '~') . '\b~', ' ', $left);
    $left = trim(preg_replace('~[^a-z0-9 ]~', ' ', preg_replace('~\s+~', ' ', $left)));

    return ['mode' => $mode, 'filters' => $f, 'used' => $used, 'leftover' => $left];
}

/* Did the parse find anything to look up? month_name and amount_on are
   decorations on another filter, never filters in their own right. */
function qr_has_filters(array $parsed): bool {
    $f = $parsed['filters'];
    unset($f['month_name'], $f['amount_on']);
    return $f !== [];
}

/* A question with no filter and leftover words is a DESCRIPTION. Returning
   every row for it would look like an answer and would not be one. */
function qr_is_description(array $parsed): bool {
    return !qr_has_filters($parsed) && $parsed['leftover'] !== '';
}

/* ------------------------------------------------------------- to SQL

   Returns [where, params] against the aliases each mode's query uses:
   shipment -> s, proforma -> pf, costing -> cv / p. */
/* A reference matches the stored number either whole or shortened.
 *
 * short_ref() prints PI-260908-786 as PI-786, and that is what people read
 * off a screen and type back in. So a reference is matched two ways: exactly,
 * and as prefix + anything + tail. The LIKE has no trailing wildcard, so
 * "PI-786" cannot also match PI-260908-1786.
 *
 * Returns [sqlFragment, params] for one column. */
function qr_ref_match(string $col, string $ref): array {
    $like = null;
    if (preg_match('~^([A-Z][A-Z0-9]{0,9})[-/](?:\d{1,8}[-/])?(\d{1,8})$~', $ref, $m)) {
        $like = $m[1] . '%' . $m[2];
    } elseif (preg_match('~^([A-Z]{2,10})(\d{1,12})$~', $ref, $m)) {
        /* A format with no separator — ZAS20260001, or PI786 typed in a
           hurry. Same idea: the letters, then the digits, with whatever the
           saved number carries in between. The two capture groups are
           letters and digits only, so neither can smuggle a LIKE wildcard. */
        $like = $m[1] . '%' . $m[2];
    }
    if ($like === null) return ["$col = ?", [$ref]];
    return ["($col = ? OR $col LIKE ?)", [$ref, $like]];
}

function qr_where(string $mode, array $f): array
{
    $w = []; $p = [];

    $between = function (string $col) use ($f, &$w, &$p) {
        if (isset($f['days'])) {
            $w[] = "$col >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"; $p[] = (int)$f['days'];
        }
        if (isset($f['month'])) { $w[] = "MONTH($col) = ?"; $p[] = (int)$f['month']; }
        if (isset($f['year']))  { $w[] = "YEAR($col) = ?";  $p[] = (int)$f['year']; }
    };

    if ($mode === 'shipment') {
        if (isset($f['ref']))      { [$rw, $rp] = qr_ref_match('s.invoice_no', $f['ref']); $w[] = $rw; $p = array_merge($p, $rp); }
        if (isset($f['country']))  { $w[] = "s.buyer_country = ?"; $p[] = $f['country']; }
        if (isset($f['port']))     { $w[] = "s.destination_port = ?"; $p[] = $f['port']; }
        if (isset($f['buyer']))    { $w[] = "s.buyer_name = ?"; $p[] = $f['buyer']; }
        if (isset($f['currency'])) { $w[] = "s.currency = ?"; $p[] = $f['currency']; }
        if (isset($f['status']))   { $w[] = "s.status = ?"; $p[] = qr_doc_status($f['status']); }
        if (isset($f['logistics'])){ $w[] = "LOWER(s.logistics_status) = ?"; $p[] = mb_strtolower($f['logistics']); }
        $between('s.invoice_date');

        if (isset($f['container'])) {
            $w[] = "(EXISTS (SELECT 1 FROM shipment_containers c WHERE c.shipment_id=s.id AND c.container_no = ?)
                     OR s.bl_container_no LIKE ?)";
            $p[] = $f['container']; $p[] = '%' . $f['container'] . '%';
        }
        if (isset($f['bl'])) {
            $w[] = "(EXISTS (SELECT 1 FROM shipment_logistics l WHERE l.shipment_id=s.id AND l.bl_no = ?)
                     OR s.bl_container_no LIKE ?)";
            $p[] = $f['bl']; $p[] = '%' . $f['bl'] . '%';
        }
        if (isset($f['hs'])) {
            /* The HS code lives on the customs document lines and on the
               product. Either one counts. */
            $w[] = "(EXISTS (SELECT 1 FROM exp_doc_lines d WHERE d.shipment_id=s.id AND d.hs_code = ?)
                     OR EXISTS (SELECT 1 FROM shipment_items si JOIN products pr ON pr.id=si.product_id
                                WHERE si.shipment_id=s.id AND pr.hs_code = ?))";
            $p[] = $f['hs']; $p[] = $f['hs'];
        }
        if (isset($f['agent'])) {
            $w[] = "(EXISTS (SELECT 1 FROM shipment_costs c JOIN exp_providers pv ON pv.id=c.provider_id
                             WHERE c.shipment_id=s.id AND c.is_void=0 AND pv.name = ?)
                     OR EXISTS (SELECT 1 FROM shipment_logistics l JOIN exp_providers pv2 ON pv2.id=l.freight_provider_id
                                WHERE l.shipment_id=s.id AND pv2.name = ?))";
            $p[] = $f['agent']; $p[] = $f['agent'];
        }
        if (isset($f['doc'])) {
            $w[] = "EXISTS (SELECT 1 FROM exp_doc_lines d2 WHERE d2.shipment_id=s.id AND d2.view = ?)";
            $p[] = $f['doc'];
        }

        /* Payment status, computed — never a stored flag. */
        $recv = "COALESCE((SELECT SUM(pm.amount) FROM shipment_payments pm
                           WHERE pm.shipment_id=s.id AND pm.is_void=0), 0)";
        if (($f['pay'] ?? '') === 'unpaid')  $w[] = "$recv <= 0.004 AND s.total_amount > 0";
        if (($f['pay'] ?? '') === 'partial') $w[] = "$recv > 0.004 AND $recv < s.total_amount - 0.004";
        if (($f['pay'] ?? '') === 'paid')    $w[] = "s.total_amount > 0 AND $recv >= s.total_amount - 0.004";

        if (($f['amount_on'] ?? '') === 'freight') {
            $fr = "COALESCE((SELECT SUM(c2.amount) FROM shipment_costs c2
                             JOIN exp_masters m2 ON m2.id=c2.cost_type_id
                             WHERE c2.shipment_id=s.id AND c2.is_void=0 AND m2.label LIKE '%Freight%'), 0)";
            if (isset($f['min_amount'])) { $w[] = "$fr > ?"; $p[] = $f['min_amount']; }
            if (isset($f['max_amount'])) { $w[] = "$fr < ?"; $p[] = $f['max_amount']; }
        } else {
            if (isset($f['min_amount'])) { $w[] = "s.total_amount > ?"; $p[] = $f['min_amount']; }
            if (isset($f['max_amount'])) { $w[] = "s.total_amount < ?"; $p[] = $f['max_amount']; }
        }
    }

    if ($mode === 'proforma') {
        if (isset($f['ref']))      { [$rw, $rp] = qr_ref_match('pf.pi_no', $f['ref']); $w[] = $rw; $p = array_merge($p, $rp); }
        if (isset($f['buyer']))    { $w[] = "pf.customer_name = ?"; $p[] = $f['buyer']; }
        if (isset($f['currency'])) { $w[] = "pf.currency = ?"; $p[] = $f['currency']; }
        if (isset($f['status']))   { $w[] = "pf.status = ?"; $p[] = $f['status'] === 'locked' ? 'converted' : $f['status']; }
        $between('pf.pi_date');
        if (isset($f['min_amount']) || isset($f['max_amount'])) {
            $tot = "COALESCE((SELECT SUM(pi2.amount) FROM proforma_items pi2 WHERE pi2.proforma_id=pf.id),0)";
            if (isset($f['min_amount'])) { $w[] = "$tot > ?"; $p[] = $f['min_amount']; }
            if (isset($f['max_amount'])) { $w[] = "$tot < ?"; $p[] = $f['max_amount']; }
        }
    }

    if ($mode === 'costing') {
        if (isset($f['ref']))      { [$rw, $rp] = qr_ref_match('cv.costing_no', $f['ref']); $w[] = $rw; $p = array_merge($p, $rp); }
        if (isset($f['product']))  { $w[] = "p.name = ?"; $p[] = $f['product']; }
        if (isset($f['currency'])) { $w[] = "cv.currency = ?"; $p[] = $f['currency']; }
        if (isset($f['status']))   { $w[] = "cv.status = ?"; $p[] = $f['status']; }
        $between('cv.created_at');
        $col = (($f['amount_on'] ?? '') === 'price') ? 'cv.suggested_price' : 'cv.total_cost';
        if (isset($f['min_amount'])) { $w[] = "$col > ?"; $p[] = $f['min_amount']; }
        if (isset($f['max_amount'])) { $w[] = "$col < ?"; $p[] = $f['max_amount']; }
    }

    return [$w ? implode(' AND ', $w) : '', $p];
}

function qr_doc_status(string $s): string {
    return ['draft' => 'draft', 'submitted' => 'submitted', 'sent' => 'submitted',
            'locked' => 'approved_locked', 'final' => 'approved_locked'][$s] ?? $s;
}

/* What was understood, for the chips above the results. */
function qr_chips(array $parsed): array {
    $f = $parsed['filters'];
    $label = [
        'ref' => 'Number', 'container' => 'Container', 'bl' => 'BL', 'hs' => 'HS code',
        'country' => 'Country', 'port' => 'Port', 'buyer' => 'Buyer', 'agent' => 'Freight agent',
        'product' => 'Product', 'currency' => 'Currency', 'status' => 'Status',
        'logistics' => 'Shipment status', 'doc' => 'Has document', 'pay' => 'Payment',
        'month' => 'Month', 'year' => 'Year', 'days' => 'Last days',
        'min_amount' => 'More than', 'max_amount' => 'Less than',
    ];
    $out = [];
    foreach ($label as $k => $name) {
        if (!isset($f[$k])) continue;
        $v = $f[$k];
        if ($k === 'month') $v = $f['month_name'] ?? $v;
        if ($k === 'min_amount' || $k === 'max_amount') {
            $v = number_format((float)$v) . (($f['amount_on'] ?? '') !== '' ? ' ' . $f['amount_on'] : '');
        }
        $out[] = [$name, (string)$v];
    }
    return $out;
}

/* ================================================================= budget

   The ceiling that did not exist before. A paid route checks this first and
   refuses with a message rather than letting a bill run. */
function qr_month(): string { return date('Y-m'); }

function qr_budget(): array {
    $row = null;
    try {
        $st = db()->prepare("SELECT * FROM exp_ai_budget WHERE ym=?");
        $st->execute([qr_month()]);
        $row = $st->fetch();
    } catch (Throwable $e) {}

    $cap = 0;
    try {
        $v = db()->query("SELECT v FROM exp_meta WHERE k='ai_cap_calls'")->fetchColumn();
        $cap = (int)$v;
    } catch (Throwable $e) {}
    if ($cap <= 0) $cap = 500;     /* a sane default until Settings says otherwise */

    $used = (int)($row['calls_used'] ?? 0);
    return ['ym' => qr_month(), 'used' => $used, 'cap' => $cap,
            'left' => max(0, $cap - $used), 'ok' => $used < $cap];
}

function qr_spend(int $calls = 1): void {
    try {
        db()->prepare("INSERT INTO exp_ai_budget (ym, calls_used, updated_at) VALUES (?,?,NOW())
                       ON DUPLICATE KEY UPDATE calls_used = calls_used + VALUES(calls_used), updated_at = NOW()")
            ->execute([qr_month(), $calls]);
    } catch (Throwable $e) {}
}

/* ==================================================================== log

   What people actually ask, which route answered it, and what it cost. This
   is how the next set of patterns gets chosen from evidence rather than from
   guesswork. The question itself is stored; it is a search term typed into
   your own system, not personal data. */
function qr_log(string $mode, string $q, array $parsed, int $rows, string $route, int $calls, int $ms): void {
    try {
        db()->prepare("INSERT INTO exp_query_log (user_id, mode, question, filters_found, leftover, rows_found, route, calls_spent, ms, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([
                current_user()['id'] ?? null,
                $mode,
                mb_substr($q, 0, 500),
                count(qr_chips($parsed)),
                mb_substr($parsed['leftover'], 0, 190),
                $rows, $route, $calls, $ms,
            ]);
    } catch (Throwable $e) {}
}
