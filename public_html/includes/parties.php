<?php
/* CUSTOMERS FROM PARTIES (owner, 19 Nov 2026).
 *
 * Parties was the supplier list for gate passes and contracts; buyers were
 * typed again on every shipment and proforma, so "UK" and "Uk" drifted apart
 * and an address was only as good as the last person who typed it. Now a
 * customer is kept once in Parties, with what an export invoice needs, and
 * the invoice and the proforma PICK it.
 *
 * WHAT IS PICKED IS COPIED. A document keeps its own buyer name, address,
 * country and terms, exactly as before; it only also remembers which party
 * they came from (party_id). Changing a customer's address later never
 * changes an invoice already sent — the way SAP and Oracle keep a document's
 * address as it was on the day.
 *
 * ZERO LOAD. The columns below are added once, by the same run-once check as
 * every other module (schema_once.php). The customer list goes into the page
 * once; searching it is done in the browser. Nothing is asked of the server
 * while typing.
 */

/* the export fields a customer carries, in the order the form shows them */
const PTY_EXPORT_FIELDS = [
    'country'          => ['Country', 80],
    'currency'         => ['Currency', 8],
    'payment_terms'    => ['Payment terms', 200],
    'incoterm'         => ['Incoterm', 120],
    'destination_port' => ['Destination port', 160],
    'contact_person'   => ['Contact person', 120],
    'email'            => ['Email', 190],
    'notify_party'     => ['Notify party', 1000],
];
const PTY_CURRENCIES = ['USD', 'EUR', 'GBP', 'PKR'];

function pty_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('inv_ensure_schema')) inv_ensure_schema();      // inv_parties itself
    if (function_exists('zas_schema_fresh') && zas_schema_fresh('parties', [__FILE__])) return;
    try { db()->query("SELECT 1 FROM inv_parties LIMIT 0"); } catch (Throwable $e) { return; }   // not made yet: try again next time
    foreach ([
        "ALTER TABLE inv_parties ADD COLUMN country VARCHAR(80) NULL",
        "ALTER TABLE inv_parties ADD COLUMN currency VARCHAR(8) NULL",
        "ALTER TABLE inv_parties ADD COLUMN payment_terms VARCHAR(200) NULL",
        "ALTER TABLE inv_parties ADD COLUMN incoterm VARCHAR(120) NULL",
        "ALTER TABLE inv_parties ADD COLUMN destination_port VARCHAR(160) NULL",
        "ALTER TABLE inv_parties ADD COLUMN contact_person VARCHAR(120) NULL",
        "ALTER TABLE inv_parties ADD COLUMN email VARCHAR(190) NULL",
        "ALTER TABLE inv_parties ADD COLUMN notify_party TEXT NULL",
        /* the documents remember which customer they were made for */
        "ALTER TABLE shipments ADD COLUMN party_id INT NULL",
        "ALTER TABLE proforma_invoices ADD COLUMN party_id INT NULL",
    ] as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* already there */ }
    }
    if (function_exists('zas_schema_mark')) zas_schema_mark('parties', [__FILE__]);
}

/** Every active customer, ready for the picker: one query, small rows. */
function pty_customers(): array
{
    pty_ensure_schema();
    try {
        return db()->query("SELECT id, code, name, address, city, country, currency, payment_terms, incoterm, destination_port,
                                   contact_person, email, phone, notify_party
                              FROM inv_parties
                             WHERE is_active = 1 AND party_type IN ('customer','both')
                             ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** A party_id from a form, kept only if it is a real customer; null otherwise. */
function pty_valid_id($raw): ?int
{
    $id = (int)$raw;
    if ($id <= 0) return null;
    try {
        $st = db()->prepare("SELECT id FROM inv_parties WHERE id = ? AND party_type IN ('customer','both')");
        $st->execute([$id]);
        return $st->fetchColumn() ? $id : null;
    } catch (Throwable $e) { return null; }
}

/**
 * The picker: a type-ahead on a document's buyer box. $fields maps a customer
 * field to the CSS selector of the box it fills; 'party_id' names the hidden
 * input that remembers the pick. Read-only boxes (a locked invoice) are left
 * alone. Everything runs in the browser on the list printed here.
 */
function pty_picker(array $fields, array $customers, bool $canAdd): string
{
    $list = array_map(fn($c) => [
        'id' => (int)$c['id'], 'code' => (string)$c['code'], 'name' => (string)$c['name'],
        'address' => (string)($c['address'] ?? ''), 'city' => (string)($c['city'] ?? ''), 'country' => (string)($c['country'] ?? ''),
        'currency' => (string)($c['currency'] ?? ''), 'payment_terms' => (string)($c['payment_terms'] ?? ''),
        'incoterm' => (string)($c['incoterm'] ?? ''), 'destination_port' => (string)($c['destination_port'] ?? ''),
        'contact' => trim((string)($c['contact_person'] ?? '') . ((string)($c['email'] ?? '') !== '' ? ' · ' . $c['email'] : '')),
    ], $customers);
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start(); ?>
<style>
.pty-pop{position:absolute;z-index:60;background:#fff;border:1px solid #c9d3df;border-radius:6px;box-shadow:0 10px 28px rgba(16,32,56,.16);
  max-height:300px;overflow:auto;font-size:12.5px;min-width:320px}
.pty-pop .r{display:flex;justify-content:space-between;gap:12px;padding:6px 10px;cursor:pointer;border-bottom:1px solid #eef1f5}
.pty-pop .r.cur,.pty-pop .r:hover{background:#eaf2fc}
.pty-pop .r b{color:#1d2d3e;font-weight:600}.pty-pop .r small{color:#6a7b8c;display:block;font-size:11px;margin-top:1px}
.pty-pop .r .k{font-family:ui-monospace,Menlo,Consolas,monospace;color:#6a7b8c;font-size:11px;white-space:nowrap;text-align:right}
.pty-pop .none{padding:8px 10px;color:#6a7b8c}
.pty-pop .add{display:block;padding:7px 10px;color:#0a6ed1;font-weight:600;text-decoration:none;background:#f7f9fb}
.pty-tag{display:inline-flex;align-items:center;gap:6px;margin-top:4px;font-size:11px;font-weight:600;color:#256f3a;background:#eef8f0;text-transform:none;letter-spacing:0;
  border:1px solid #c6e6cf;border-radius:3px;padding:1px 7px}
.pty-tag.new{color:#8a5a00;background:#fff6e5;border-color:#f0d8a8}
.pty-tag a{color:inherit}
</style>
<script>
(function(){
  var CUST = <?= $j($list) ?>, F = <?= $j($fields) ?>, CAN_ADD = <?= $canAdd ? 'true' : 'false' ?>;
  function q(s){ return s ? document.querySelector(s) : null; }
  var box = q(F.name), pid = q(F.party_id);
  if (!box || !pid || box.readOnly || box.disabled) return;
  var pop = document.createElement('div'); pop.className = 'pty-pop'; pop.hidden = true; document.body.appendChild(pop);
  var tag = document.createElement('div'); box.parentNode.appendChild(tag);
  var cur = 0, shown = [];
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function norm(s){ return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  function byId(id){ for (var i = 0; i < CUST.length; i++) if (CUST[i].id === +id) return CUST[i]; return null; }
  function place(){ var r = box.getBoundingClientRect(); pop.style.left = (r.left + window.scrollX) + 'px'; pop.style.top = (r.bottom + window.scrollY + 2) + 'px'; pop.style.width = Math.max(r.width, 320) + 'px'; }
  function addUrl(){
    var u = 'inv_parties.php?new=1&type=customer&name=' + encodeURIComponent(box.value.trim());
    ['address', 'country', 'currency', 'payment_terms', 'incoterm', 'destination_port'].forEach(function(k){ var el = q(F[k]); if (el && el.value) u += '&' + k + '=' + encodeURIComponent(el.value); });
    return u;
  }
  function paintTag(){
    var c = byId(pid.value), t = box.value.trim();
    if (c && norm(c.name) === norm(t)) { tag.className = 'pty-tag'; tag.innerHTML = '&#10003; Customer ' + esc(c.code) + (c.country ? ' · ' + esc(c.country) : ''); return; }
    if (c) pid.value = '';
    var same = CUST.filter(function(x){ return norm(x.name) === norm(t); })[0];
    if (same) { pid.value = same.id; tag.className = 'pty-tag'; tag.innerHTML = '&#10003; Customer ' + esc(same.code); return; }
    if (!t) { tag.className = ''; tag.innerHTML = ''; return; }
    tag.className = 'pty-tag new';
    tag.innerHTML = 'Not in your customers' + (CAN_ADD ? ' · <a href="' + esc(addUrl()) + '" target="_blank" rel="noopener">+ Save as customer</a>' : '');
  }
  function list(){
    var t = norm(box.value).split(' ').filter(Boolean);
    shown = CUST.filter(function(c){ var h = norm(c.name + ' ' + c.code + ' ' + c.country + ' ' + c.city); return t.every(function(w){ return h.indexOf(w) >= 0; }); }).slice(0, 40);
    if (cur >= shown.length) cur = 0;
    pop.innerHTML = (shown.length ? shown.map(function(c, i){
        return '<div class="r' + (i === cur ? ' cur' : '') + '" data-i="' + i + '"><div><b>' + esc(c.name) + '</b><small>' + esc([c.country, c.contact].filter(Boolean).join(' · ') || c.address.split('\n')[0]) + '</small></div>'
          + '<div class="k">' + esc(c.code) + (c.currency ? '<br>' + esc(c.currency) : '') + '</div></div>'; }).join('')
      : '<div class="none">' + (CUST.length ? 'No customer matches.' : 'No customers yet.') + '</div>')
      + (CAN_ADD && box.value.trim() && !shown.some(function(c){ return norm(c.name) === norm(box.value); }) ? '<a class="add" href="' + esc(addUrl()) + '" target="_blank" rel="noopener">+ Save “' + esc(box.value.trim()) + '” as a new customer</a>' : '');
    place(); pop.hidden = false;
  }
  function pick(c){
    box.value = c.name; pid.value = c.id;
    var put = function(k, v){ var el = q(F[k]); if (!el || el.readOnly || el.disabled || !v) return;
      if (el.tagName === 'SELECT') { for (var i = 0; i < el.options.length; i++) if ((el.options[i].value || el.options[i].text) === v) { el.selectedIndex = i; break; } }
      else el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    put('address', c.address); put('country', c.country); put('currency', c.currency); put('payment_terms', c.payment_terms);
    put('incoterm', c.incoterm); put('destination_port', c.destination_port);
    box.dispatchEvent(new Event('input', { bubbles: true })); box.dispatchEvent(new Event('change', { bubbles: true }));
    pop.hidden = true; paintTag();
  }
  box.setAttribute('autocomplete', 'off');
  box.addEventListener('focus', list);
  box.addEventListener('input', function(){ cur = 0; list(); });
  box.addEventListener('keydown', function(e){
    if (pop.hidden) return;
    if (e.key === 'ArrowDown') { cur = Math.min(cur + 1, shown.length - 1); list(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { cur = Math.max(cur - 1, 0); list(); e.preventDefault(); }
    else if (e.key === 'Enter' && shown[cur]) { pick(shown[cur]); e.preventDefault(); }
    else if (e.key === 'Escape') { pop.hidden = true; }
  });
  pop.addEventListener('mousedown', function(e){ var r = e.target.closest('.r'); if (r) { e.preventDefault(); pick(shown[+r.getAttribute('data-i')]); } });
  box.addEventListener('blur', function(){ setTimeout(function(){ pop.hidden = true; paintTag(); }, 150); });
  window.addEventListener('resize', function(){ if (!pop.hidden) place(); });
  paintTag();
})();
</script>
<?php
    return (string)ob_get_clean();
}
