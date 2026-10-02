<?php
/*
  EXPORT MASTERS — the lists the shipment screens pick from.

  One screen for every simple list, switched by ?kind=. Ports, payment
  methods, shipping lines, container types, cost types, document types and
  logistics statuses are all the same shape, so they are all the same table
  and the same form. Adding a new kind is a row in EXP_KINDS, not a new page.

  Banks get their own panel because a bank is not a label — it carries a
  branch, a title, an IBAN and a SWIFT code.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/storage.php';
require_admin();
exp_ensure_schema();

const EXP_KINDS = [
    'port_loading'     => ['Loading Ports', 'Pakistan ports and terminals you load from.'],
    'port_destination' => ['Destination Ports', 'Only needed if you want a fixed list — the invoice already takes free text.'],
    'shipping_line'    => ['Shipping Lines', 'Carriers you book with.'],
    'container_type'   => ['Container Types', "20'GP, 40'HC, LCL and so on."],
    'payment_method'   => ['Payment Methods', 'TT, CAD, LC terms, DP, DA.'],
    'cost_type'        => ['Cost Types', 'Expense headings for the shipment cost ledger.'],
    'doc_type'         => ['Document Types', 'The official documents a shipment can carry.'],
    'logistics_status' => ['Logistics Statuses', 'Where a shipment has reached — separate from invoice approval.'],
];

$kind = (string)($_GET['kind'] ?? 'port_loading');
if (!isset(EXP_KINDS[$kind])) $kind = 'port_loading';
$tab  = (string)($_GET['tab'] ?? 'lists');
if (!in_array($tab, ['lists', 'banks', 'numbering', 'storage'], true)) $tab = 'lists';

/* ------------------------------------------------------------------ writes */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_master') {
            $k     = (string)($_POST['kind'] ?? '');
            if (!isset(EXP_KINDS[$k])) throw new Exception('Unknown list.');
            $id    = (int)($_POST['id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label === '') throw new Exception('Name cannot be empty.');

            $flags = [];
            if ($k === 'cost_type' && !empty($_POST['is_commission']))      $flags['is_commission'] = 1;
            if ($k === 'doc_type'  && !empty($_POST['supports_draft_final'])) $flags['supports_draft_final'] = 1;
            $flagsJson = $flags ? json_encode($flags) : null;

            $sort   = (int)($_POST['sort_order'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;

            if ($id > 0) {
                $old = db()->prepare("SELECT * FROM exp_masters WHERE id=?");
                $old->execute([$id]); $before = $old->fetch();
                db()->prepare("UPDATE exp_masters SET label=?, sort_order=?, is_active=?, flags=? WHERE id=?")
                    ->execute([$label, $sort, $active, $flagsJson, $id]);
                audit_log(0, 'Export Master', $k, (string)($before['label'] ?? ''), $label, 'Master list edited');
            } else {
                db()->prepare("INSERT INTO exp_masters (kind,label,sort_order,is_active,flags,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$k, $label, $sort ?: 999, $active, $flagsJson, current_user()['id']]);
                audit_log(0, 'Export Master', $k, '', $label, 'Master list entry added');
            }
            $_SESSION['flash'] = 'Saved.';
            redirect('exp_settings.php?kind=' . urlencode($k));
        }

        /* A master row is switched off, never deleted — a shipment from last
           year still points at it, and a dropdown that cannot render an old
           value turns a saved record into a blank. */
        if ($action === 'toggle_master') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare("SELECT * FROM exp_masters WHERE id=?");
            $st->execute([$id]); $row = $st->fetch();
            if (!$row) throw new Exception('Not found.');
            $new = (int)$row['is_active'] === 1 ? 0 : 1;
            db()->prepare("UPDATE exp_masters SET is_active=? WHERE id=?")->execute([$new, $id]);
            audit_log(0, 'Export Master', (string)$row['kind'],
                      (int)$row['is_active'] ? 'active' : 'inactive',
                      $new ? 'active' : 'inactive', 'Master entry ' . ($new ? 'reactivated' : 'deactivated'));
            $_SESSION['flash'] = $new ? 'Switched on.' : 'Switched off — old records that use it still read correctly.';
            redirect('exp_settings.php?kind=' . urlencode((string)$row['kind']));
        }

        if ($action === 'save_bank') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['bank_name'] ?? ''));
            if ($name === '') throw new Exception('Bank name cannot be empty.');
            $vals = [
                $name,
                trim((string)($_POST['branch'] ?? '')),
                trim((string)($_POST['account_title'] ?? '')),
                trim((string)($_POST['account_no'] ?? '')),
                trim((string)($_POST['iban'] ?? '')),
                trim((string)($_POST['swift'] ?? '')),
                trim((string)($_POST['currency'] ?? '')),
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($id > 0) {
                $vals[] = $id;
                db()->prepare("UPDATE exp_banks SET bank_name=?, branch=?, account_title=?, account_no=?, iban=?, swift=?, currency=?, sort_order=?, is_active=? WHERE id=?")
                    ->execute($vals);
                audit_log(0, 'Export Bank', 'bank', '', $name, 'Bank edited');
            } else {
                $vals[] = current_user()['id'];
                db()->prepare("INSERT INTO exp_banks (bank_name,branch,account_title,account_no,iban,swift,currency,sort_order,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute($vals);
                audit_log(0, 'Export Bank', 'bank', '', $name, 'Bank added');
            }
            $_SESSION['flash'] = 'Bank saved.';
            redirect('exp_settings.php?tab=banks');
        }

        if ($action === 'save_numbering') {
            $k = (string)($_POST['doc_kind'] ?? '');
            if (!isset(EXP_DOC_KINDS[$k])) throw new Exception('Unknown document kind.');

            /* The prefix is yours, so it is kept as typed apart from the
               characters that would break a filename or a URL later. */
            $prefix  = trim((string)($_POST['prefix'] ?? ''));
            $prefix  = (string)preg_replace('~[^A-Za-z0-9 ./_-]~', '', $prefix);
            if (mb_strlen($prefix) > 20) $prefix = mb_substr($prefix, 0, 20);

            $pattern = trim((string)($_POST['pattern'] ?? ''));
            if ($pattern === '') throw new Exception('The format cannot be empty.');
            if (mb_strlen($pattern) > 120) throw new Exception('That format is too long.');
            if (strpos($pattern, '{SEQ') === false) {
                throw new Exception('The format must contain {SEQ} or {SEQ:3}, otherwise every document would get the same number.');
            }

            $cycle = (string)($_POST['reset_cycle'] ?? 'never');
            if (!isset(EXP_RESET_CYCLES[$cycle])) $cycle = 'never';

            $next   = max(1, (int)($_POST['next_no'] ?? 1));
            $active = isset($_POST['is_active']) ? 1 : 0;

            /* A worked example is rejected before it is saved rather than
               discovered on the next document. */
            $sample = exp_render_pattern($pattern, $prefix, $next);
            if ($sample === '') throw new Exception('That format produces an empty number.');
            if (mb_strlen($sample) > 40) {
                throw new Exception('That format produces "' . $sample . '", which is longer than the 40 characters the column holds.');
            }

            db()->prepare(
                "INSERT INTO exp_numbering (doc_kind,prefix,pattern,next_no,reset_cycle,cycle_key,is_active,updated_at)
                 VALUES (?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE prefix=VALUES(prefix), pattern=VALUES(pattern), next_no=VALUES(next_no),
                                         reset_cycle=VALUES(reset_cycle), cycle_key=VALUES(cycle_key),
                                         is_active=VALUES(is_active), updated_at=NOW()"
            )->execute([$k, $prefix, $pattern, $next, $cycle, exp_cycle_key($cycle), $active]);

            audit_log(0, 'Numbering', 'edit', EXP_DOC_KINDS[$k][0], $sample,
                      $active ? 'Own numbering on — next will be ' . $sample : 'Own numbering off');
            $_SESSION['flash'] = $active
                ? EXP_DOC_KINDS[$k][0] . ' numbering saved. The next one will be ' . $sample . '.'
                : EXP_DOC_KINDS[$k][0] . ' numbering saved but left switched off.';
            redirect('exp_settings.php?tab=numbering');
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect('exp_settings.php?tab=' . urlencode($tab) . '&kind=' . urlencode($kind));
    }
}

/* R2 self-test runs on GET so nothing is written to the database by it. */
$r2test = null;
if ($tab === 'storage' && isset($_GET['run_test'])) $r2test = exp_r2_selftest();

$rows      = exp_masters($kind, false);
$banks     = exp_banks(false);
$numbering = exp_numbering_all();
$edit  = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM exp_masters WHERE id=?");
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null;
}
$editBank = null;
if (isset($_GET['edit_bank'])) {
    $st = db()->prepare("SELECT * FROM exp_banks WHERE id=?");
    $st->execute([(int)$_GET['edit_bank']]); $editBank = $st->fetch() ?: null;
}

page_header('Export Masters');
flash();
echo exp_page_css();
?>
<style>
.ktab{padding:6px 12px;border-radius:18px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;font-size:12px;text-decoration:none;white-space:nowrap}
.ktab:hover{border-color:rgba(14,168,201,.4)}
.ktab.on{background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;border-color:transparent}
.off td{opacity:.45}
</style>

<div class="topbar">
  <div><h1>Export Masters</h1><p class="lead">The lists the shipment screens pick from. Nothing here is hard-coded in the application.</p></div>
</div>

<div class="xcard" style="display:flex;gap:8px;flex-wrap:wrap">
  <a class="ktab <?= $tab === 'lists' ? 'on' : '' ?>" href="exp_settings.php?tab=lists&kind=<?= e($kind) ?>">Lists</a>
  <a class="ktab <?= $tab === 'banks' ? 'on' : '' ?>" href="exp_settings.php?tab=banks">Our Banks</a>
  <a class="ktab <?= $tab === 'numbering' ? 'on' : '' ?>" href="exp_settings.php?tab=numbering">Numbering</a>
  <a class="ktab <?= $tab === 'storage' ? 'on' : '' ?>" href="exp_settings.php?tab=storage">Document Storage</a>
</div>

<?php if ($tab === 'lists'): ?>

<div class="xcard" style="display:flex;gap:7px;flex-wrap:wrap">
  <?php foreach (EXP_KINDS as $k => $meta): ?>
    <a class="ktab <?= $k === $kind ? 'on' : '' ?>" href="exp_settings.php?kind=<?= e($k) ?>"><?= e($meta[0]) ?></a>
  <?php endforeach; ?>
</div>

<div class="xcard">
  <h2><?= e(EXP_KINDS[$kind][0]) ?></h2>
  <div class="xnote" style="margin-bottom:12px"><?= e(EXP_KINDS[$kind][1]) ?></div>

  <form method="post" class="xgrid" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_master">
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label class="xlabel xspan2">Name
      <input class="xin" name="label" required value="<?= e($edit['label'] ?? '') ?>" placeholder="e.g. Port Qasim">
    </label>
    <label class="xlabel">Order
      <input class="xin" type="number" name="sort_order" value="<?= e($edit['sort_order'] ?? '') ?>" placeholder="10">
    </label>
    <label class="xlabel" style="display:flex;align-items:center;gap:8px;padding-top:18px">
      <input type="checkbox" name="is_active" <?= ($edit === null || (int)$edit['is_active'] === 1) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9"> Active
    </label>

    <?php if ($kind === 'cost_type'): ?>
    <label class="xlabel xspan2" style="display:flex;align-items:center;gap:8px;padding-top:18px">
      <input type="checkbox" name="is_commission" <?= exp_master_flag($edit, 'is_commission') ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9">
      This is a commission — hide it from users without rate visibility
    </label>
    <?php endif; ?>

    <?php if ($kind === 'doc_type'): ?>
    <label class="xlabel xspan2" style="display:flex;align-items:center;gap:8px;padding-top:18px">
      <input type="checkbox" name="supports_draft_final" <?= exp_master_flag($edit, 'supports_draft_final') ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9">
      Has a Draft and a Final (like a BL)
    </label>
    <?php endif; ?>

    <div style="display:flex;gap:8px">
      <button class="xbtn"><?= $edit ? 'Update' : 'Add' ?></button>
      <?php if ($edit): ?><a class="xbtn sec" href="exp_settings.php?kind=<?= e($kind) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="xcard">
  <div class="xwrap">
    <table class="xtable">
      <thead><tr>
        <th style="width:60px">Order</th><th>Name</th>
        <?php if ($kind === 'cost_type' || $kind === 'doc_type'): ?><th>Flag</th><?php endif; ?>
        <th style="width:90px">Status</th><th style="width:150px"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" style="padding:22px;text-align:center;color:#8a97ab">Nothing in this list yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $on = (int)$r['is_active'] === 1; ?>
        <tr class="<?= $on ? '' : 'off' ?>">
          <td class="num"><?= (int)$r['sort_order'] ?></td>
          <td style="font-weight:600"><?= e($r['label']) ?></td>
          <?php if ($kind === 'cost_type'): ?>
            <td><?= exp_master_flag($r, 'is_commission') ? '<span class="xpill r">Commission</span>' : '' ?></td>
          <?php elseif ($kind === 'doc_type'): ?>
            <td><?= exp_master_flag($r, 'supports_draft_final') ? '<span class="xpill b">Draft / Final</span>' : '' ?></td>
          <?php endif; ?>
          <td><?= $on ? '<span class="xpill g">Active</span>' : '<span class="xpill o">Off</span>' ?></td>
          <td>
            <a class="xbtn sec sm" href="exp_settings.php?kind=<?= e($kind) ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_master">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="xbtn <?= $on ? 'red' : 'sec' ?> sm"><?= $on ? 'Switch off' : 'Switch on' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="xnote" style="margin-top:12px">
    An entry is switched off, never deleted. A shipment saved last year still points at it, and a
    dropdown that cannot render an old value would turn a saved record into a blank.
  </div>
</div>

<?php elseif ($tab === 'banks'): ?>

<div class="xcard">
  <h2><?= $editBank ? 'Edit Bank' : 'Add a Bank' ?></h2>
  <form method="post" class="xgrid" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_bank">
    <input type="hidden" name="id" value="<?= (int)($editBank['id'] ?? 0) ?>">
    <label class="xlabel xspan2">Bank Name<input class="xin" name="bank_name" required value="<?= e($editBank['bank_name'] ?? '') ?>"></label>
    <label class="xlabel">Branch<input class="xin" name="branch" value="<?= e($editBank['branch'] ?? '') ?>"></label>
    <label class="xlabel">Account Title<input class="xin" name="account_title" value="<?= e($editBank['account_title'] ?? '') ?>"></label>
    <label class="xlabel">Account No.<input class="xin" name="account_no" value="<?= e($editBank['account_no'] ?? '') ?>"></label>
    <label class="xlabel">IBAN<input class="xin" name="iban" value="<?= e($editBank['iban'] ?? '') ?>"></label>
    <label class="xlabel">SWIFT<input class="xin" name="swift" value="<?= e($editBank['swift'] ?? '') ?>"></label>
    <label class="xlabel">Currency<input class="xin" name="currency" value="<?= e($editBank['currency'] ?? '') ?>" placeholder="USD"></label>
    <label class="xlabel">Order<input class="xin" type="number" name="sort_order" value="<?= e($editBank['sort_order'] ?? '') ?>"></label>
    <label class="xlabel" style="display:flex;align-items:center;gap:8px;padding-top:18px">
      <input type="checkbox" name="is_active" <?= ($editBank === null || (int)$editBank['is_active'] === 1) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9"> Active
    </label>
    <div style="display:flex;gap:8px">
      <button class="xbtn"><?= $editBank ? 'Update' : 'Add Bank' ?></button>
      <?php if ($editBank): ?><a class="xbtn sec" href="exp_settings.php?tab=banks">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="xcard">
  <div class="xwrap">
    <table class="xtable">
      <thead><tr><th>Bank</th><th>Branch</th><th>Title</th><th>IBAN</th><th>SWIFT</th><th>Cur</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($banks as $b): $on = (int)$b['is_active'] === 1; ?>
        <tr class="<?= $on ? '' : 'off' ?>">
          <td style="font-weight:600"><?= e($b['bank_name']) ?></td>
          <td><?= e($b['branch']) ?></td>
          <td><?= e($b['account_title']) ?></td>
          <td style="font-size:11.5px"><?= e($b['iban']) ?></td>
          <td><?= e($b['swift']) ?></td>
          <td><?= e($b['currency']) ?></td>
          <td><?= $on ? '<span class="xpill g">Active</span>' : '<span class="xpill o">Off</span>' ?></td>
          <td><a class="xbtn sec sm" href="exp_settings.php?tab=banks&edit_bank=<?= (int)$b['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="xnote" style="margin-top:12px">
    These are selectable on a shipment and on a proforma. The two banks already configured for the
    proforma were carried in automatically. An existing proforma with no bank chosen still prints
    exactly as it did before, from the old settings.
  </div>
</div>

<?php elseif ($tab === 'numbering'): ?>

<div class="xcard">
  <h2>Your Own Document Numbers</h2>
  <div class="xnote">
    Each kind of document can run on your own code and your own sequence. Until you switch one on
    it keeps the number it has always produced, so nothing changes by itself and nothing already
    saved is touched.
    <div style="margin-top:9px">
      <b>The pieces you can use in a format:</b>
      <code>{PREFIX}</code> your code ·
      <code>{YY}</code> <?= date('y') ?> ·
      <code>{YYYY}</code> <?= date('Y') ?> ·
      <code>{MM}</code> <?= date('m') ?> ·
      <code>{DD}</code> <?= date('d') ?> ·
      <code>{SEQ}</code> the counter ·
      <code>{SEQ:4}</code> the counter padded to four digits.
      Everything else — slashes, dashes, spaces, your own letters — prints exactly as you type it.
    </div>
  </div>
</div>

<?php foreach (EXP_DOC_KINDS as $dk => $dmeta):
        $cfg  = $numbering[$dk] ?? null;
        $on   = $cfg && (int)$cfg['is_active'] === 1;
        $pat  = $cfg['pattern'] ?? '{PREFIX}-{YY}{MM}{DD}-{SEQ:3}';
        $pfx  = $cfg['prefix']  ?? $dk;
        $nxt  = max(1, (int)($cfg['next_no'] ?? 1));
        $cyc  = $cfg['reset_cycle'] ?? 'never';
        $last = exp_number_last_used($dk);
?>
<div class="xcard">
  <h2 style="display:flex;align-items:center;gap:10px">
    <?= e($dmeta[0]) ?>
    <?= $on ? '<span class="xpill g">Your numbering</span>' : '<span class="xpill o">Built-in numbering</span>' ?>
  </h2>

  <div class="xnote" style="margin-bottom:12px">
    Written to <code><?= e($dmeta[1]) ?>.<?= e($dmeta[2]) ?></code>.
    <?php if ($last !== null): ?>Most recent on file: <b><?= e($last) ?></b>.<?php else: ?>Nothing on file yet.<?php endif; ?>
    <?php if ($on): ?>
      Next one out: <b><?= e((string)exp_numbering_peek($dk)) ?></b>.
    <?php else: ?>
      Switched off, so the next one looks like <b><?= e($dk) ?>-<?= date('ymd') ?>-<?= str_pad((string)random_int(100,999),3,'0',STR_PAD_LEFT) ?></b> — a random tail, not a sequence.
    <?php endif; ?>
  </div>

  <form method="post" class="xgrid" style="align-items:end" data-num="1">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_numbering">
    <input type="hidden" name="doc_kind" value="<?= e($dk) ?>">

    <label class="xlabel">Your code
      <input class="xin" name="prefix" maxlength="20" value="<?= e($pfx) ?>" placeholder="ZAS" data-f="prefix">
    </label>
    <label class="xlabel xspan2">Format
      <input class="xin" name="pattern" required maxlength="120" value="<?= e($pat) ?>" data-f="pattern" style="font-family:ui-monospace,Menlo,Consolas,monospace">
    </label>
    <label class="xlabel">Next number
      <input class="xin" type="number" min="1" name="next_no" value="<?= (int)$nxt ?>" data-f="next">
    </label>
    <label class="xlabel xspan2">Restart the counter
      <select class="xin" name="reset_cycle" data-f="cycle">
        <?php foreach (EXP_RESET_CYCLES as $cv => $cl): ?>
          <option value="<?= e($cv) ?>" <?= $cyc === $cv ? 'selected' : '' ?>><?= e($cl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel" style="display:flex;align-items:center;gap:8px;padding-top:18px">
      <input type="checkbox" name="is_active" <?= $on ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9">
      Use this instead of the built-in number
    </label>

    <div class="xspan2" style="padding:10px 12px;background:#f6f8fc;border:1px solid #e3e9f2;border-radius:8px">
      <div style="font-size:11px;color:#8a97ab;text-transform:uppercase;letter-spacing:.5px">Preview</div>
      <div data-f="out" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:16px;font-weight:700;color:#0b2a4a;margin-top:3px">&nbsp;</div>
      <div data-f="run" style="font-size:11.5px;color:#64748b;margin-top:4px">&nbsp;</div>
    </div>

    <div><button class="xbtn">Save</button></div>
  </form>
</div>
<?php endforeach; ?>

<div class="xcard">
  <div class="xnote">
    <b>Three things worth knowing.</b>
    <div style="margin-top:7px">1. The counter is handed out one document at a time, so two people
    creating a document in the same second cannot be given the same number. The built-in scheme
    could: its last three digits are drawn at random out of 900, which at ten documents in a day
    is about a one-in-twenty chance of a repeat, and nothing checked for it.</div>
    <div style="margin-top:5px">2. A number that is already on a document is never handed out
    again — the counter steps past it. So you can wind the counter back without creating duplicates.</div>
    <div style="margin-top:5px">3. The number stays typeable. Every one of these screens still lets
    you overwrite it by hand, and a number you type yourself leaves the counter where it is.</div>
  </div>
</div>

<script>
/* Preview, worked out in the browser the same way the server works it out.
   No request, no framework — the format is short and the rules are four
   substitutions. The server renders it again on save, which is the copy
   that counts. */
(function () {
  var now = new Date();
  var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
  var D = {
    '{YYYY}': String(now.getFullYear()),
    '{YY}': String(now.getFullYear()).slice(-2),
    '{MM}': p2(now.getMonth() + 1),
    '{DD}': p2(now.getDate())
  };
  function render(pattern, prefix, seq) {
    var s = pattern.split('{PREFIX}').join(prefix);
    for (var k in D) { s = s.split(k).join(D[k]); }
    return s.replace(/\{SEQ(?::([1-9]))?\}/g, function (_, w) {
      var v = String(seq);
      if (!w) return v;
      while (v.length < +w) v = '0' + v;
      return v;
    }).trim();
  }
  document.querySelectorAll('form[data-num]').forEach(function (f) {
    var g = function (n) { return f.querySelector('[data-f="' + n + '"]'); };
    var out = g('out'), run = g('run');
    function draw() {
      var pat = g('pattern').value, pfx = g('prefix').value, n = parseInt(g('next').value, 10) || 1;
      if (pat.indexOf('{SEQ') === -1) {
        out.textContent = '—';
        run.textContent = 'The format needs {SEQ} or {SEQ:3}, or every document gets the same number.';
        return;
      }
      var a = render(pat, pfx, n);
      out.textContent = a;
      run.textContent = 'then ' + render(pat, pfx, n + 1) + ', ' + render(pat, pfx, n + 2) +
                        ' · ' + a.length + ' characters' + (a.length > 40 ? ' — too long, the column holds 40' : '');
    }
    ['pattern', 'prefix', 'next', 'cycle'].forEach(function (n) {
      var el = g(n); if (el) el.addEventListener('input', draw);
    });
    draw();
  });
})();
</script>

<?php else: /* storage */ ?>

<div class="xcard">
  <h2>Document Storage</h2>
  <?php if (exp_r2_configured()): ?>
    <div class="xnote" style="border-color:rgba(22,163,74,.3);background:rgba(22,163,74,.08)">
      <b>Cloudflare R2 is configured.</b> New documents go to object storage. Files already on this
      server keep working from disk — each document row records where its own bytes live.
    </div>
  <?php else: ?>
    <div class="xwarn">
      <b>R2 is not configured yet, so documents go to this server's disk.</b>
      Everything works; it is just using local storage until the four values below are filled in.
    </div>
  <?php endif; ?>

  <div class="xnote" style="margin-top:12px">
    <b>To switch on R2</b>, add these four lines to <code>config.php</code> on the server. They stay
    on the server only — never in the repository, never sent to a browser.
    <pre style="margin:10px 0 0;padding:10px;background:#fff;border:1px solid #e3e9f2;border-radius:8px;font-size:11.5px;overflow-x:auto">'r2_endpoint'   =&gt; 'https://&lt;account-id&gt;.r2.cloudflarestorage.com',
'r2_bucket'     =&gt; 'zas-documents',
'r2_access_key' =&gt; '&lt;access key id&gt;',
'r2_secret'     =&gt; '&lt;secret access key&gt;',</pre>
  </div>

  <div style="margin-top:14px">
    <a class="xbtn" href="exp_settings.php?tab=storage&run_test=1">Test the R2 Connection</a>
    <span style="color:#8a97ab;font-size:12px;margin-left:8px">Writes a tiny test file, reads it back, deletes it.</span>
  </div>

  <?php if ($r2test !== null): ?>
    <div class="xwrap" style="margin-top:14px">
      <table class="xtable">
        <thead><tr><th style="width:120px">Step</th><th style="width:70px">Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($r2test['steps'] as $s): ?>
          <tr>
            <td style="font-weight:600"><?= e($s[0]) ?></td>
            <td><?= $s[1] ? '<span class="xpill g">OK</span>' : '<span class="xpill r">Failed</span>' ?></td>
            <td><?= e($s[2]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="<?= $r2test['ok'] ? 'xnote' : 'xwarn' ?>" style="margin-top:12px">
      <?= $r2test['ok']
            ? '<b>R2 is working.</b> Upload, download and delete all succeeded. Documents will go to object storage.'
            : '<b>R2 is not usable yet.</b> Nothing was changed and document uploads continue to work on local disk. The failing step above says what to fix.' ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php page_footer(); ?>
