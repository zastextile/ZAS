<?php
/* CUSTOMERS IN PARTIES, PICKED ON THE INVOICE AND THE PROFORMA (owner, 19 Nov 2026).
 * Reads the source, like the other page tests; the browser demo checked the clicks. */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$pt = file_get_contents($B . 'includes/parties.php');
$ip = file_get_contents($B . 'inv_parties.php');
$sf = file_get_contents($B . 'shipment_form.php');
$sv = file_get_contents($B . 'shipment_view.php');
$ss = file_get_contents($B . 'shipment_save.php');
$pf = file_get_contents($B . 'proforma.php');

echo "1. The columns are added once, never changed\n";
foreach (['country', 'currency', 'payment_terms', 'incoterm', 'destination_port', 'contact_person', 'email', 'notify_party'] as $c)
    ok(str_contains($pt, "ALTER TABLE inv_parties ADD COLUMN $c "), "  inv_parties gets $c");
ok(str_contains($pt, 'ALTER TABLE shipments ADD COLUMN party_id INT NULL') && str_contains($pt, 'ALTER TABLE proforma_invoices ADD COLUMN party_id INT NULL'), 'the documents remember the customer (nullable)');
ok(str_contains($pt, "zas_schema_fresh('parties', [__FILE__])") && str_contains($pt, "zas_schema_mark('parties', [__FILE__])"), '  through the run-once check — no ALTER on every page');
ok(!preg_match('/ALTER TABLE \w+ (DROP|MODIFY|CHANGE)|DROP TABLE/i', $pt), '  nothing dropped or modified');

echo "2. A picked party is checked on the server\n";
ok(str_contains($pt, "WHERE id = ? AND party_type IN ('customer','both')"), 'pty_valid_id keeps only a real customer');
ok(str_contains($sf, "pty_valid_id(\$_POST['party_id'] ?? 0)"), 'New Shipment saves it checked');
ok(str_contains($ss, "pty_valid_id(\$_POST['party_id'])") && str_contains($ss, "array_key_exists('party_id', \$_POST)"), 'the shipment save sets it only when the form sent it');
ok(str_contains($pf, "UPDATE proforma_invoices SET party_id=? WHERE id=?"), 'the proforma save keeps it');
ok(str_contains($pf, 'UPDATE shipments SET party_id=?, payment_terms=?, incoterm=?, buyer_country=?, destination_port=? WHERE id=?'), 'a proforma made into a shipment brings its customer');

echo "3. The picker fills, it never locks\n";
ok(str_contains($pt, 'if (!box || !pid || box.readOnly || box.disabled) return;'), 'a locked invoice gets no picker');
ok(str_contains($pt, "if (!el || el.readOnly || el.disabled || !v) return;"), '  and an empty customer field never blanks the document');
ok(str_contains($sv, "pty_picker([") && str_contains($sf, "pty_picker([") && str_contains($pf, "pty_picker(["), 'on New Shipment, the shipment page and the proforma');
ok(substr_count($pt, "fetch(") === 0 && substr_count($pt, 'XMLHttpRequest') === 0, '  and it asks the server nothing while typing');

echo "4. Parties: one read, the rest in the browser\n";
ok(str_contains($ip, "\$act === 'import_buyers'") && str_contains($ip, 'Customer made from shipments and proformas'), 'all buyer names become customers in one click, audited');
ok(!preg_match('/UPDATE\s+(shipments|proforma_invoices)/i', $ip), '  and no existing document is changed');
ok(str_contains($ip, '.ipf [hidden]{display:none!important}'), 'the closed side panel never covers the page (the demo caught it)');
ok(str_contains($ip, 'inv_require(\'master\')'), 'only master-data users can save');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
