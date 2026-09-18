<?php
/*
  Link old costing item names to the Item Master — admin only, one-time
  (re-runnable, and it gets shorter every time).

  Product Costing now picks items from the Item Master and stores WHICH
  item each line is (costing_lines.material_id). Every line saved before
  that is only a typed name, so the app has to guess what it meant — and a
  guess made fresh on every page load is a guess that can change.

  This page settles those guesses once. For every distinct item name still
  unlinked it shows what it would match, and you confirm, change, or create
  the item. Confirming writes the link, so nothing is ever guessed for that
  name again.

  WHAT IT WRITES — and nothing else:
      costing_lines.material_id      the link
      costing_lines.line_group       only if you leave the box ticked
      inv_materials                  only rows you ask it to create
  No quantity, rate, weight or amount is touched. No costing total moves.
  Final Costings are not touched at all — they are finished documents.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
if (is_file(__DIR__ . '/includes/inventory.php')) require_once __DIR__ . '/includes/inventory.php';
require_login();
require_admin();

try { db()->exec("ALTER TABLE costing_lines ADD COLUMN material_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE costing_lines ADD INDEX idx_material (material_id)"); } catch (Throwable $e) {}

/* Packing was retired at the owner's instruction — see inv_item_groups().
   A new item can only be created in one of these. */
const CIL_GROUPS = ['Fabric', 'Accessories', 'Other'];

function cil_items(): array {
    try { return db()->query("SELECT id,code,name,item_group,uom,std_rate FROM inv_materials WHERE is_active=1 ORDER BY item_group,name")->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function cil_num($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function cil_norm(string $s): string { return mb_strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $s))); }

/* Same order of trust the costing sheet itself uses: the alias dictionary
   your app already keeps, then an exact name, then a loose contains. A
   loose hit is shown as a suggestion only — it is never applied for you. */
function cil_suggest(string $name, array $byName, array $aliases): array {
    $exact = $byName[mb_strtolower(trim($name))] ?? null;
    if ($exact) return ['item' => $exact, 'how' => 'exact name'];
    $norm = cil_norm($name);
    if (isset($aliases[$norm])) {
        $std = $byName[mb_strtolower(trim($aliases[$norm]['standard_item']))] ?? null;
        if ($std) return ['item' => $std, 'how' => 'alias → ' . $aliases[$norm]['standard_item']];
    }
    $key = mb_strtolower(trim($name));
    if ($key !== '') {
        foreach ($byName as $k => $cand) {
            if ($k !== '' && (strpos($k, $key) !== false || strpos($key, $k) !== false)) return ['item' => $cand, 'how' => 'partial name'];
        }
    }
    return ['item' => null, 'how' => ''];
}
function cil_guess_group(string $name): string {
    $s = mb_strtolower($name);
    if (preg_match('/fabric|greige|grey cloth|cloth|yarn|poplin|percale|sateen|twill/', $s)) return 'Fabric';
    return 'Accessories';   // packing materials are Accessories too now
}

/* ---------------- apply ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $names = $_POST['name'] ?? [];
    $picks = $_POST['pick'] ?? [];
    $setGroup = !empty($_POST['set_group']);
    $linked = 0; $created = 0; $lines = 0; $errors = [];

    $itemsById = [];
    foreach (cil_items() as $it) $itemsById[(int)$it['id']] = $it;

    db()->beginTransaction();
    try {
        foreach ($names as $i => $rawName) {
            $name = trim((string)$rawName);
            $pick = trim((string)($picks[$i] ?? ''));
            if ($name === '' || $pick === '' || $pick === 'skip') continue;

            if (strpos($pick, 'new:') === 0) {
                $group = substr($pick, 4);
                if (!in_array($group, CIL_GROUPS, true)) { $errors[] = "$name: unknown group."; continue; }
                // the unit and rate this name is actually costed at today,
                // so a created item starts from your own figures, not zero
                $u = db()->prepare("SELECT unit, AVG(rate) r FROM costing_lines WHERE item_name=? AND line_group<>'Workmanship' GROUP BY unit ORDER BY COUNT(*) DESC LIMIT 1");
                $u->execute([$name]); $ur = $u->fetch();
                $uom = strtoupper(mb_substr(trim((string)($ur['unit'] ?? 'PCS')), 0, 20)); if ($uom === '') $uom = 'PCS';
                $rate = cil_num($ur['r'] ?? 0);
                $prefixes = ['Fabric' => 'FB', 'Accessories' => 'AC', 'Other' => 'OT'];
                $p = $prefixes[$group] ?? 'OT'; $n = 0;
                foreach (db()->query("SELECT code FROM inv_materials WHERE code LIKE '$p-%'")->fetchAll() as $r) {
                    $tail = (int)substr((string)$r['code'], strlen($p) + 1);
                    if ($tail > $n) $n = $tail;
                }
                $code = $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO inv_materials (code,name,item_group,uom,std_rate,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$code, mb_substr($name, 0, 190), $group, $uom, $rate, (int)(current_user()['id'] ?? 0)]);
                $mid = (int)db()->lastInsertId();
                $itemsById[$mid] = ['id' => $mid, 'code' => $code, 'name' => $name, 'item_group' => $group, 'uom' => $uom, 'std_rate' => $rate];
                $created++;
            } else {
                $mid = (int)$pick;
                if (!isset($itemsById[$mid])) { $errors[] = "$name: that item no longer exists."; continue; }
            }

            $grp = $itemsById[$mid]['item_group'];
            if ($setGroup) {
                $up = db()->prepare("UPDATE costing_lines SET material_id=?, line_group=? WHERE item_name=? AND material_id IS NULL AND line_group<>'Workmanship'");
                $up->execute([$mid, $grp, $name]);
            } else {
                $up = db()->prepare("UPDATE costing_lines SET material_id=? WHERE item_name=? AND material_id IS NULL AND line_group<>'Workmanship'");
                $up->execute([$mid, $name]);
            }
            $lines += $up->rowCount();
            $linked++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        $_SESSION['error'] = 'Nothing was changed — ' . $e->getMessage();
        redirect('costing_items_link.php');
    }

    try { audit_log(0, 'Product Costing', 'link_items', '', "$linked name(s), $lines line(s), $created new item(s)", 'Costing item names linked to Item Master'); } catch (Throwable $e) {}
    $msg = "$linked name(s) linked across $lines costing line(s)";
    if ($created) $msg .= ", $created new item(s) added to the Item Master";
    $_SESSION['flash'] = $msg . '.' . ($setGroup ? ' Groups were taken from the Item Master.' : '');
    if ($errors) $_SESSION['error'] = implode(' ', array_slice($errors, 0, 5));
    redirect('costing_items_link.php');
}

/* ---------------- gather ---------------- */
$items = cil_items();
$byName = [];
foreach ($items as $it) { $k = mb_strtolower(trim($it['name'])); if (!isset($byName[$k])) $byName[$k] = $it; }
$aliases = [];
try { foreach (db()->query("SELECT alias_norm, standard_item FROM costing_item_aliases")->fetchAll() as $a) $aliases[$a['alias_norm']] = $a; }
catch (Throwable $e) {}

$unlinked = [];
try {
    $unlinked = db()->query("SELECT cl.item_name,
            COUNT(*) n,
            COUNT(DISTINCT cv.product_id) prods,
            GROUP_CONCAT(DISTINCT cl.line_group ORDER BY cl.line_group) groups,
            MAX(cl.unit) unit, AVG(cl.rate) avg_rate
        FROM costing_lines cl
        JOIN costing_versions cv ON cv.id = cl.costing_version_id
        WHERE cl.material_id IS NULL AND cl.line_group <> 'Workmanship'
          AND cl.item_name IS NOT NULL AND cl.item_name <> ''
        GROUP BY cl.item_name
        ORDER BY n DESC, cl.item_name")->fetchAll();
} catch (Throwable $e) { $unlinked = []; }

$totalLinked = 0; $totalLines = 0;
try {
    $totalLinked = (int)db()->query("SELECT COUNT(*) FROM costing_lines WHERE material_id IS NOT NULL")->fetchColumn();
    $totalLines  = (int)db()->query("SELECT COUNT(*) FROM costing_lines WHERE line_group <> 'Workmanship' AND item_name IS NOT NULL AND item_name <> ''")->fetchColumn();
} catch (Throwable $e) {}

$rows = [];
foreach ($unlinked as $u) {
    $s = cil_suggest((string)$u['item_name'], $byName, $aliases);
    $rows[] = $u + ['suggest' => $s['item'], 'how' => $s['how'], 'guess_group' => cil_guess_group((string)$u['item_name'])];
}

/* ONE NAME AT A TIME, WHEN THAT IS WHAT YOU CAME FOR.
   The seed card in Inventory Setup links straight here with ?q=<the name>,
   because "if i go manual one by one take time" is answered by landing on
   the row rather than by hunting for it in a list of two hundred.

   Matched the same flattened way everything else here matches, so
   "COTTON FABRIC-60s" from a link finds "cotton fabric 60 s" in the list.
   An empty or unmatched q shows everything, rather than an empty page that
   looks like the work is finished. */
$q = trim((string)($_GET['q'] ?? ''));
$qHits = null;
if ($q !== '') {
    $qn = cil_norm($q);
    $hit = array_values(array_filter($rows, function ($r) use ($qn) {
        $n = cil_norm((string)$r['item_name']);
        return $n === $qn || str_contains($n, $qn) || str_contains($qn, $n);
    }));
    if ($hit) { $qHits = count($rows); $rows = $hit; }
}
$nSure = 0; foreach ($rows as $r) if ($r['suggest'] && $r['how'] !== 'partial name') $nSure++;

page_header('Link Costing Items');
flash();
?>
<?php if ($q !== ''): ?>
  <div class="cl-note" style="border-left-color:#0ea8c9">
    <?php if ($qHits !== null): ?>
      Showing the <b><?= count($rows) ?></b> name(s) matching <b><?= e($q) ?></b>, out of <?= (int)$qHits ?> still unlinked.
      <a href="costing_items_link.php" style="font-weight:800">Show them all</a>
    <?php else: ?>
      Nothing still unlinked matches <b><?= e($q) ?></b> — it may already be settled. Showing everything instead.
    <?php endif; ?>
  </div>
<?php endif; ?>
<style>
.cl-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px}
.cl-note{background:#f6f8fc;border:1px solid #e3e9f2;border-left:4px solid #6d5bd0;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:12.5px;color:#5a6b82;line-height:1.65}
.cl-note b{color:#152033}
.cl-strip{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e3e9f2}
.cl-stat b{display:block;font-size:19px;font-weight:800;color:#152033;font-variant-numeric:tabular-nums}
.cl-stat span{font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;color:#8a97ab;font-weight:700}
.cl-tbl{width:100%;border-collapse:collapse}
.cl-tbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;color:#8a97ab;font-weight:700;text-align:left;padding:0 8px 5px;white-space:nowrap}
.cl-tbl th.num,.cl-tbl td.num{text-align:right}
.cl-tbl td{padding:3px 8px;border-top:1px solid #f6f8fc;font-size:12.5px;vertical-align:middle}
.cl-name{font-weight:700;color:#152033}
.cl-sub{font-size:11px;color:#8a97ab}
.cl-how{display:inline-block;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;background:rgba(22,163,74,.1);color:#15803d;white-space:nowrap}
.cl-how.weak{background:rgba(217,119,6,.12);color:#b45309}
.cl-how.none{background:#f6f8fc;color:#8a97ab}
.cl-sel{padding:7px 8px;border-radius:9px;border:1px solid #cbd5e3;background:#fff;font-size:12px;font-family:inherit;min-width:260px;max-width:340px}
.cl-btn{padding:10px 18px;border-radius:10px;border:none;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);cursor:pointer;font-family:inherit}
.cl-btn.sec{background:transparent;border:1px solid #cbd5e3;color:#5a6b82}
.cl-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #e3e9f2}
.cl-chk{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer}
.cl-scrollx{overflow-x:auto}
.cl-done{padding:36px;text-align:center;color:#15803d;font-weight:700}
</style>

<div class="topbar"><div>
  <h1>Link Costing Items</h1>
  <p class="lead">Settle the old typed item names against the Item Master, once — admin only</p>
</div></div>

<div class="cl-note">
  Product Costing now stores <b>which Item Master item</b> each line is. Lines saved before that
  are only a typed name, so the app matches them by name every time it needs them — and a name
  match is a guess. Confirm each name here and the guess is replaced by a stored link.<br>
  <b>No figure changes.</b> Quantity, rate, weight and amount are not touched, no costing total
  moves, and Final Costings are not read or written at all. The only thing that changes besides
  the link is the line's <b>Group</b>, if you leave that box ticked below — and that is the point:
  your old lines were grouped by the rule <i>"name contains fabric → Fabric, everything else →
  Accessories"</i>, which is why fabrics sat under Accessories and Packing never appeared.
</div>

<div class="cl-card">
  <div class="cl-strip">
    <div class="cl-stat"><b><?= number_format(count($rows)) ?></b><span>Names still unlinked</span></div>
    <div class="cl-stat"><b><?= number_format($nSure) ?></b><span>With a confident match</span></div>
    <div class="cl-stat"><b><?= number_format($totalLinked) ?> / <?= number_format($totalLines) ?></b><span>Costing lines already linked</span></div>
    <div class="cl-stat"><b><?= number_format(count($items)) ?></b><span>Items in Item Master</span></div>
  </div>

<?php if (!$items): ?>
  <div class="cl-done" style="color:#b45309">
    The Item Master is empty. Install the module and create your items first —<br>
    <a href="inv_setup.php" style="color:#0ea8c9">Administration ▸ Inventory Setup</a>
  </div>
<?php elseif (!$rows): ?>
  <div class="cl-done">✓ Every costing item name is linked to the Item Master. Nothing left to do.</div>
<?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="cl-scrollx">
    <table class="cl-tbl">
      <thead><tr>
        <th>Item name in your costings</th><th class="num">Lines</th><th class="num">Products</th>
        <th>Group now</th><th>Match</th><th>Link it to</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): $sug = $r['suggest']; ?>
        <tr>
          <td>
            <div class="cl-name"><?= e($r['item_name']) ?></div>
            <div class="cl-sub"><?= e($r['unit'] ?: '—') ?> · avg rate <?= number_format((float)$r['avg_rate'], 2) ?></div>
            <input type="hidden" name="name[<?= $i ?>]" value="<?= e($r['item_name']) ?>">
          </td>
          <td class="num"><?= number_format((int)$r['n']) ?></td>
          <td class="num"><?= number_format((int)$r['prods']) ?></td>
          <td class="cl-sub"><?= e($r['groups']) ?></td>
          <td>
            <?php if ($sug && $r['how'] !== 'partial name'): ?><span class="cl-how"><?= e($r['how']) ?></span>
            <?php elseif ($sug): ?><span class="cl-how weak"><?= e($r['how']) ?> — check it</span>
            <?php else: ?><span class="cl-how none">no match</span><?php endif; ?>
          </td>
          <td>
            <select class="cl-sel" name="pick[<?= $i ?>]">
              <option value="skip">— leave it alone —</option>
              <optgroup label="Create a new Item Master item">
                <?php foreach (CIL_GROUPS as $g): ?>
                  <option value="new:<?= e($g) ?>" <?= (!$sug && $g === $r['guess_group']) ? 'selected' : '' ?>>+ create as <?= e($g) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Link to an existing item">
                <?php foreach ($items as $it): ?>
                  <option value="<?= (int)$it['id'] ?>" <?= ($sug && (int)$sug['id'] === (int)$it['id']) ? 'selected' : '' ?>><?= e($it['code'] . ' · ' . $it['name'] . ' (' . $it['item_group'] . ')') ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="cl-bar">
      <label class="cl-chk"><input type="checkbox" name="set_group" value="1" checked> Also correct each line's Group from the item it is linked to</label>
      <div style="margin-left:auto;display:flex;gap:8px">
        <button type="button" class="cl-btn sec" onclick="clSkipAll()">Set all to “leave alone”</button>
        <button type="submit" class="cl-btn" onclick="return confirm('Apply these links?\n\nNo quantity, rate or amount is changed. Only the item link, and the Group if you left that box ticked.')">Apply</button>
      </div>
    </div>
  </form>
<?php endif; ?>
</div>

<script>
function clSkipAll(){document.querySelectorAll('select.cl-sel').forEach(function(s){s.value='skip'})}
</script>
<?php page_footer(); ?>
