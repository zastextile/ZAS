<?php
/*
  PRODUCTION STAGES — YOUR WORDS, YOUR ORDER.
  ==========================================

  This replaces the fixed list of Cutting / Manual Cutting / Stitching that was
  written into the code. It was wrong to put it there: every time the factory
  worked differently the code had to be edited, and the old costing table only
  ever accepted three words, so a fourth one was silently thrown away.

  Here you type the names. Cutting, Laser Cut, Fabric Prep, Direct Sewing —
  whatever your floor actually calls it.

  THE ONE THING POSITION MEANS.

  The stage at the TOP makes the pieces. Not because of what it is called —
  because it is first. It is limited by the order quantity and nothing else.

  Every stage below it is limited by the stage above it. Stage 3 can never be
  booked beyond Stage 2, and Stage 2 never beyond Stage 1. That is what stops
  500 being stitched when only 300 were ever cut, and it is the only reason the
  order matters.

  So the list is not decoration. Drag the order to match how work really flows.

  THE PART LIBRARY NOW READS THIS LIST. Whatever you type here is what its
  stage box offers, and the first stage here is the one its first line is
  locked to. No stage name is written into any file any more.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/zprod.php';
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ------------------------------------------------------------------
   THE TABLE — one list for the whole factory
   ------------------------------------------------------------------
   One list, not one per product. A list per product is how you end up with
   "Stitching", "stitching" and "Sewing" all meaning the same job in three
   places, and no report can add them up again.

   The table itself and the readers live in includes/zprod.php, because the Part
   Library and every production screen read the same list. Keeping a second copy
   of them here is exactly the kind of split that caused the size mess. */
function zs_schema(): void { zp_ensure_schema(); }

function zs_all(bool $activeOnly = false): array {
    /* read straight from the database, never the cached copy: this page CHANGES
       the list, and a reader that answered from a cache would redraw the screen
       showing the order as it was before the move. */
    zp_ensure_schema();
    $sql = "SELECT * FROM zp_stage" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY seq, id";
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
}

/* A NAME TYPED TWICE IS A MISTAKE, NOT A SECOND STAGE. Two stages called
   "Stitching" cannot be told apart on any report, so the name is unique and
   the answer is a plain message rather than a database error. */
function zs_add(string $name): array {
    zs_schema();
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return ['ok' => false, 'error' => 'Type a name for the stage.'];
    if (mb_strlen($name) > 60) return ['ok' => false, 'error' => 'That name is too long — keep it under 60 letters.'];
    foreach (zs_all() as $s)
        if (mb_strtolower($s['name']) === mb_strtolower($name))
            return ['ok' => false, 'error' => 'You already have a stage called "' . $s['name'] . '".'];
    try {
        $max = (int)db()->query("SELECT COALESCE(MAX(seq),0) FROM zp_stage")->fetchColumn();
        db()->prepare("INSERT INTO zp_stage (seq, name) VALUES (?,?)")->execute([$max + 1, $name]);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Nothing was saved. ' . $e->getMessage()]; }
    return ['ok' => true, 'error' => ''];
}

function zs_rename(int $id, string $name): array {
    zs_schema();
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($id <= 0 || $name === '') return ['ok' => false, 'error' => 'Type a name for the stage.'];
    foreach (zs_all() as $s)
        if ((int)$s['id'] !== $id && mb_strtolower($s['name']) === mb_strtolower($name))
            return ['ok' => false, 'error' => 'You already have a stage called "' . $s['name'] . '".'];
    /* RENAMING IS ALWAYS SAFE. Work is booked against the stage's id, never
       against its spelling, so correcting a name never moves a wage. */
    try { db()->prepare("UPDATE zp_stage SET name=? WHERE id=?")->execute([$name, $id]); }
    catch (Throwable $e) { return ['ok' => false, 'error' => 'Nothing was changed. ' . $e->getMessage()]; }
    return ['ok' => true, 'error' => ''];
}

/* MOVING A STAGE CHANGES WHAT LIMITS WHAT, so the two rows swap places as one
   write — a half-finished reorder would leave two stages claiming the same
   position and no way to say which limits which. */
function zs_move(int $id, int $dir): array {
    zs_schema();
    $all = zs_all();
    $i = null;
    foreach ($all as $k => $s) if ((int)$s['id'] === $id) { $i = $k; break; }
    if ($i === null) return ['ok' => false, 'error' => 'That stage no longer exists.'];
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($all)) return ['ok' => true, 'error' => ''];   // already at the end
    try {
        db()->beginTransaction();
        $u = db()->prepare("UPDATE zp_stage SET seq=? WHERE id=?");
        $u->execute([$j + 1, (int)$all[$i]['id']]);
        $u->execute([$i + 1, (int)$all[$j]['id']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); return ['ok' => false, 'error' => 'Nothing moved. ' . $e->getMessage()]; }
    return ['ok' => true, 'error' => ''];
}

/* IS ANYTHING USING THIS STAGE?
 *
 * TWO THINGS CAN BE, and both must be asked. Checking only booked wages was a
 * real gap: a stage with no entries yet can still be the stage twenty priced
 * operations sit at, and deleting it would leave every one of them belonging to
 * nothing — the part would price correctly on screen and cost nothing on the
 * floor.
 *
 * A CHECK THAT CANNOT RUN IS NOT A "NO". If either table is unreadable the
 * honest answer is "I cannot tell", and the screen then refuses to delete
 * rather than guessing that it is safe. */
function zs_count_where(string $sql, int $id): array {
    try {
        $st = db()->prepare($sql);
        $st->execute([$id]);
        return ['known' => true, 'count' => (int)$st->fetchColumn()];
    } catch (Throwable $e) {
        $m = $e->getMessage();
        /* a table or column that does not exist yet genuinely holds nothing —
           that is knowable, and it is zero. Anything else is not. */
        if (stripos($m, 'stage_id') !== false) return ['known' => true, 'count' => 0];
        if (stripos($m, 'exist') !== false || stripos($m, 'Unknown table') !== false)
            return ['known' => true, 'count' => 0];
        return ['known' => false, 'count' => 0];
    }
}
function zs_usage(int $id): array {
    $work = zs_count_where("SELECT COUNT(*) FROM zp_entries WHERE stage_id=?", $id);
    $ops  = zs_count_where("SELECT COUNT(*) FROM zp_part_ops WHERE stage_id=? AND is_active=1", $id);
    return [
        'known' => $work['known'] && $ops['known'],
        'count' => $work['count'] + $ops['count'],
        'work'  => $work['count'],
        'ops'   => $ops['count'],
    ];
}

function zs_set_active(int $id, bool $on): void {
    zs_schema();
    try { db()->prepare("UPDATE zp_stage SET is_active=? WHERE id=?")->execute([$on ? 1 : 0, $id]); }
    catch (Throwable $e) {}
}

/* DELETE ONLY WHEN NOTHING WAS EVER BOOKED ON IT. Otherwise the stage is
   switched off: it stops being offered on new work, and every wage already
   paid against it still has a name on the report. */
function zs_delete(int $id): array {
    zs_schema();
    $use = zs_usage($id);
    if (!$use['known'])
        return ['ok' => false, 'error' => 'The entries could not be checked, so nothing was deleted. '
                                        . 'A check that cannot run is never treated as "safe".'];
    if ($use['count'] > 0) {
        $bits = [];
        if ($use['ops'])  $bits[] = $use['ops'] . ' priced operation' . ($use['ops'] === 1 ? '' : 's');
        if ($use['work']) $bits[] = $use['work'] . ' booked ' . ($use['work'] === 1 ? 'entry' : 'entries');
        return ['ok' => false, 'error' => 'That stage still has ' . implode(' and ', $bits)
                                        . ', so it was switched off instead of deleted. '
                                        . 'Nothing priced loses its stage and every wage keeps its name.'];
    }
    try { db()->prepare("DELETE FROM zp_stage WHERE id=?")->execute([$id]); }
    catch (Throwable $e) { return ['ok' => false, 'error' => 'Nothing was deleted. ' . $e->getMessage()]; }
    return ['ok' => true, 'error' => ''];
}

/* ------------------------------------------------------------------
   ACTIONS
   ------------------------------------------------------------------ */
$canEdit = is_admin();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) {
        $err = 'Only an admin may change the stage list. You can read this page.';
    } else {
        $a = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($a === 'add') {
            $r = zs_add((string)($_POST['name'] ?? ''));
            if ($r['ok']) { $_SESSION['zs_msg'] = 'Stage added at the bottom. Move it up if work reaches it earlier.'; redirect('production_stages.php'); }
            $err = $r['error'];
        } elseif ($a === 'rename') {
            $r = zs_rename($id, (string)($_POST['name'] ?? ''));
            if ($r['ok']) { $_SESSION['zs_msg'] = 'Renamed. Work already booked is untouched — it was booked against the stage, not its spelling.'; redirect('production_stages.php'); }
            $err = $r['error'];
        } elseif ($a === 'up' || $a === 'down') {
            $r = zs_move($id, $a === 'up' ? -1 : 1);
            if ($r['ok']) { $_SESSION['zs_msg'] = 'Order changed. What limits what has changed with it — check the list below reads the way work really flows.'; redirect('production_stages.php'); }
            $err = $r['error'];
        } elseif ($a === 'toggle') {
            zs_set_active($id, (int)($_POST['on'] ?? 0) === 1);
            $_SESSION['zs_msg'] = 'Saved.';
            redirect('production_stages.php');
        } elseif ($a === 'delete') {
            $r = zs_delete($id);
            if (!$r['ok'] && str_contains($r['error'], 'switched off')) zs_set_active($id, false);
            $_SESSION[$r['ok'] ? 'zs_msg' : 'zs_err'] = $r['ok'] ? 'Stage deleted. Nothing had ever been booked on it.' : $r['error'];
            redirect('production_stages.php');
        }
    }
}
if (!empty($_SESSION['zs_msg'])) { $msg = $_SESSION['zs_msg']; unset($_SESSION['zs_msg']); }
if (!empty($_SESSION['zs_err'])) { $err = $_SESSION['zs_err']; unset($_SESSION['zs_err']); }

$stages = zs_all();
$active = array_values(array_filter($stages, fn($s) => (int)$s['is_active'] === 1));
$firstId = $active ? (int)$active[0]['id'] : 0;

page_header('Production Stages');
?>
<style>
.zs-wrap{max-width:940px}
.zp-b{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:7px 13px;border-radius:9px;
      border:1px solid #d9e0ea;background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;
      text-decoration:none;line-height:1.15;font-family:inherit}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#fff;border-color:#f0c2cb;color:#c9384f}.zp-b.red:hover{background:#fdeef1;border-color:#e0435d}
.zp-b.sm{padding:4px 9px;font-size:11.5px}
.zp-b.ic{padding:4px 8px;font-size:13px;line-height:1;min-width:30px}
.zp-b[disabled]{opacity:.4;cursor:not-allowed}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 13px;font-size:12px;color:#8a97ab;line-height:1.55}
.zin{padding:8px 10px;border:1px solid #d9e0ea;border-radius:8px;font-size:13px;font-family:inherit;
     color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:5px}
table.zp-t{width:100%;border-collapse:collapse;font-size:13px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:9px 8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:9px 8px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tr.off td{background:#fbfcfe;color:#a8b4c4}
.seq{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;
     background:#eef1f6;color:#5a6b82;font-weight:800;font-size:12px;font-variant-numeric:tabular-nums}
.seq.first{background:#1d76e2;color:#fff}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.makes{background:rgba(29,118,226,.13);color:#1558ad}
.pill.limited{background:#eef1f6;color:#8a97ab}
.pill.off{background:#fdeef1;color:#9c2740}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:12px 14px;border-radius:10px;font-size:12.5px;line-height:1.6}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.empty{padding:26px;text-align:center;color:#8a97ab;font-size:13px;line-height:1.7}
.flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:12.5px;margin-top:10px}
.flow b{background:#fff;border:1px solid #d9e0ea;border-radius:8px;padding:4px 10px;font-weight:700;color:#33465f}
.flow span{color:#8a97ab;font-weight:800}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zs-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Production Stages</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        Your names, your order. The top one makes the pieces.</p>
    </div>
    <a class="zp-b" href="product_master.php">Master Products</a>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$canEdit): ?>
    <div class="note warn" style="margin-bottom:16px">
      <b>You can read this page but not change it.</b> Changing how work flows is an admin action.
    </div>
  <?php endif; ?>

  <div class="zp-card">
    <h2>The stages, in the order work reaches them</h2>
    <p class="sub">Name them whatever your floor calls them. Position is the only thing that carries a rule.</p>

    <?php if (!$stages): ?>
      <div class="empty">
        No stages yet.<br>
        Add the first one below — whatever the very first thing done to the fabric is called.
      </div>
    <?php else: ?>
      <table class="zp-t">
        <thead><tr>
          <th style="width:44px">#</th>
          <th>Stage name</th>
          <th style="width:210px">What limits it</th>
          <th style="width:90px">Status</th>
          <th style="width:230px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($stages as $i => $s):
          $id  = (int)$s['id'];
          $on  = (int)$s['is_active'] === 1;
          $isFirst = $id === $firstId;
          /* the stage above THIS one, among the active ones — that is what caps it */
          $prev = null;
          if ($on && !$isFirst) {
              foreach ($active as $k => $a2) if ((int)$a2['id'] === $id && $k > 0) { $prev = $active[$k - 1]['name']; break; }
          }
        ?>
          <tr class="<?= $on ? '' : 'off' ?>">
            <td><span class="seq <?= $isFirst ? 'first' : '' ?>"><?= $i + 1 ?></span></td>
            <td>
              <?php if ($canEdit): ?>
                <form method="post" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?><input type="hidden" name="action" value="rename">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input class="zin" name="name" value="<?= e($s['name']) ?>" maxlength="60"
                         style="width:210px" autocomplete="off">
                  <button class="zp-b sm">Rename</button>
                </form>
              <?php else: ?>
                <b><?= e($s['name']) ?></b>
              <?php endif; ?>
            </td>
            <td style="font-size:12px">
              <?php if (!$on): ?>
                <span class="pill off">switched off</span>
              <?php elseif ($isFirst): ?>
                <span class="pill makes">makes the pieces</span>
                <div style="color:#8a97ab;font-size:11px;margin-top:3px">Limited by the order quantity</div>
              <?php else: ?>
                <span class="pill limited">limited</span>
                <div style="color:#8a97ab;font-size:11px;margin-top:3px">
                  Cannot pass <b style="color:#5a6b82"><?= e($prev ?? '—') ?></b></div>
              <?php endif; ?>
            </td>
            <td><?= $on ? '<span style="color:#1c6b40;font-weight:700;font-size:12px">Active</span>'
                        : '<span style="color:#a8b4c4;font-weight:700;font-size:12px">Off</span>' ?></td>
            <td>
              <?php if ($canEdit): ?>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <form method="post" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="up"><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="zp-b ic" title="Move earlier" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button></form>
                  <form method="post" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="down"><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="zp-b ic" title="Move later" <?= $i === count($stages) - 1 ? 'disabled' : '' ?>>&darr;</button></form>
                  <form method="post" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="on" value="<?= $on ? 0 : 1 ?>">
                    <button class="zp-b sm"><?= $on ? 'Switch off' : 'Switch on' ?></button></form>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('Delete <?= e(addslashes($s['name'])) ?>?\n\nIf any work was ever booked on it, it will be switched off instead — nothing paid is ever lost.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="zp-b sm red">Delete</button></form>
                </div>
              <?php else: ?>
                <span style="color:#c3cbd8;font-size:11.5px">Admin only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (count($active) > 1): ?>
        <div class="flow">
          <?php foreach ($active as $k => $a2): ?>
            <?php if ($k) echo '<span>&rarr;</span>'; ?><b><?= e($a2['name']) ?></b>
          <?php endforeach; ?>
        </div>
        <p style="margin:8px 0 0;font-size:11.5px;color:#8a97ab">
          Read left to right: each one can never be booked past the one before it.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($canEdit): ?>
  <form method="post" class="zp-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2>Add a stage</h2>
    <p class="sub">It goes to the bottom of the list. Move it up with the arrows if work reaches it earlier than that.</p>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div>
        <span class="lab">Stage name</span>
        <input class="zin" name="name" maxlength="60" style="width:280px" autocomplete="off" required
               placeholder="e.g. Cutting, Laser Cut, Direct Sewing">
      </div>
      <button class="zp-b pri">Add stage</button>
    </div>
  </form>
  <?php endif; ?>

  <div class="note info">
    <b>Why the order is the only rule here.</b>
    The stage at the top makes the pieces, so the order quantity is all that limits it. Everything below it can
    only be booked on pieces that already exist — which is what stops 500 being stitched when 300 were cut.
    Names carry no rule at all: rename anything at any time and no wage moves, because work is booked against
    the stage itself and never against its spelling.
  </div>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
