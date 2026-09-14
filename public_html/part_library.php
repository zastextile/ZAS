<?php
/*
  PART LIBRARY — rebuilt from scratch, to the layout you sent.
  ============================================================

  Two panels, exactly as drawn:

    LEFT   Parts Library      — the list. Add, Import, Export, Copy, Paste.
    RIGHT  Part Operations &  — the selected part's stages, operations and
           Cost                 rates. No finishing cost. No rate at the top.

  WHY A PART LIBRARY AT ALL. A Bed Sheet is cut and stitched the same way
  whichever product it ends up in. Defining it once and pointing products at it
  means a rate correction happens in ONE place instead of being retyped on
  twenty products, nineteen of which get missed.

  THE FIRST LINE SITS AT YOUR FIRST STAGE, and that is not decoration. The first
  stage is what makes the pieces; every stage after it is capped by what already
  exists. A part whose first line sat at a later stage would have no ceiling at
  all, and work could be booked on pieces that were never made.

  NO STAGE NAME APPEARS ANYWHERE IN THIS FILE. They are yours, typed on the
  Production Stages screen, and this page simply lists them in your order. Hand
  cutting, laser cutting, a fourth stage nobody thought of — add it there and it
  appears here, with no code change and nothing silently thrown away.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Part Library access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

/* This page is live data and holds its own CSS and JavaScript. A stored copy is
   therefore stale LAYOUT and stale BEHAVIOUR, not just stale numbers — which is
   exactly how an uploaded fix comes to look like it never arrived. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
$editId = isset($_GET['part']) ? (int)$_GET['part'] : 0;

/* ------------------------------------------------------------------
   EXPORT — before any output, because it sends its own headers.
   ------------------------------------------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parts_' . date('Y-m-d_Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                         // so Excel opens UTF-8 correctly
    fputcsv($out, ['Part ID', 'Part Name', 'Default UOM', 'Style', 'Stage', 'Operation', 'Rate', 'Active']);
    $any = false;
    foreach (zp_parts() as $p) {
        $ops = zp_part_ops((int)$p['id']);
        if (!$ops) {
            /* A PART WITH NO OPERATIONS STILL EXPORTS. Skipping it would make
               the file a silent partial backup — the worst kind. */
            fputcsv($out, zp_csv_row([zp_part_code((int)$p['id']), $p['part_name'], $p['uom'], $p['style'], '', '', '', $p['is_active'] ? 'Yes' : 'No']));
            $any = true;
            continue;
        }
        foreach ($ops as $o) {
            fputcsv($out, zp_csv_row([zp_part_code((int)$p['id']), $p['part_name'], $p['uom'], $p['style'],
                           $o['stage'], $o['operation_name'], number_format((float)$o['rate'], 2, '.', ''),
                           $p['is_active'] ? 'Yes' : 'No']));
            $any = true;
        }
    }
    /* the sample row uses YOUR first stage, so the file you get back is a
       template that will actually import rather than one naming a stage you
       have never heard of */
    if (!$any) {
        $fs = zp_stage_first();
        fputcsv($out, ['', 'Bed Sheet', 'Pc', '', $fs ? $fs['name'] : '', $fs ? $fs['name'] : '', '4.00', 'Yes']);
    }
    fclose($out);
    exit;
}

$msg = ''; $err = '';

/* ------------------------------------------------------------------
   ACTIONS
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_part') {
        $r = zp_save_part(
            (int)($_POST['part_id'] ?? 0),
            (string)($_POST['part_name'] ?? ''),
            (string)($_POST['uom'] ?? 'Pc'),
            (string)($_POST['style'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0,
            $userId
        );
        if ($r['ok']) { $_SESSION['zp_msg'] = 'Part saved.'; redirect('part_library.php?part=' . $r['id']); }
        $err = $r['error'];
        $editId = (int)($_POST['part_id'] ?? 0);

    } elseif ($action === 'save_ops') {
        $rows = [];
        $ids    = $_POST['op_id']       ?? [];
        $stages = $_POST['op_stage_id']  ?? [];
        $names  = $_POST['op_name']      ?? [];
        $rates  = $_POST['op_rate']      ?? [];
        /* The stage arrives as an ID. A blank one is left at 0 rather than
           guessed at, so zp_save_part_ops() refuses the line by name instead of
           silently filing it under whichever stage happened to be handy. */
        foreach ($names as $i => $n) {
            $rows[] = ['id' => (int)($ids[$i] ?? 0), 'stage_id' => (int)($stages[$i] ?? 0),
                       'name' => (string)$n, 'rate' => (string)($rates[$i] ?? '0')];
        }
        $pid = (int)($_POST['part_id'] ?? 0);
        $r = zp_save_part_ops($pid, $rows, (string)($_POST['rate_reason'] ?? ''), $userId);
        if ($r['ok']) {
            /* A RATE LIVES ON THE PART, AND A PART IS SHARED. Changing Overlock
               here changes the workmanship of every product using this part, so
               all of them are re-fed to Costing now. Feeding only the part
               would leave the Part Library and the costing sheet quietly
               disagreeing about the same wage. */
            $b = zp_bridge_sync_part($pid);
            $_SESSION['zp_msg'] = 'Operations saved.'
                . ($b['products'] ? ' Costing updated on ' . $b['products'] . ' product'
                                    . ($b['products'] == 1 ? '' : 's') . ' using this part.'
                                  : ' No product uses this part yet, so no costing changed.');
            redirect('part_library.php?part=' . $pid);
        }
        $err = $r['error'];
        $editId = $pid;

    } elseif ($action === 'delete_part') {
        $r = zp_delete_part((int)($_POST['part_id'] ?? 0));
        $_SESSION['zp_msg'] = $r['msg'];
        redirect('part_library.php');

    } elseif ($action === 'duplicate_part') {
        $r = zp_duplicate_part((int)($_POST['part_id'] ?? 0), $userId);
        if ($r['ok']) { $_SESSION['zp_msg'] = 'Copied. You are now on the copy — rename it and change what differs.'; redirect('part_library.php?part=' . $r['id']); }
        $err = $r['error'];

    } elseif ($action === 'import_csv' || $action === 'paste_rows') {
        $text = '';
        if ($action === 'import_csv' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $text = (string)file_get_contents($_FILES['csv']['tmp_name']);
        } else {
            $text = (string)($_POST['pasted'] ?? '');
        }
        $r = zp_import_parts_text($text, $userId);
        if ($r['ok']) { $_SESSION['zp_msg'] = $r['msg']; redirect('part_library.php'); }
        $err = $r['msg'];
    }
}

if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

/* ------------------------------------------------------------------
   IMPORT — accepts the file this page exports, and an Excel paste.
   ------------------------------------------------------------------

   COLUMNS ARE MATCHED BY NAME, NOT POSITION, so a file whose columns were
   reordered still works. A pasted block with no header row is read as
   Part Name / Stage / Operation / Rate, which is the shape people actually
   paste out of a costing sheet.

   IT ADDS AND UPDATES. IT NEVER DELETES. A file is a snapshot of what somebody
   exported, not an instruction to make the database match it — a part missing
   from the file is left exactly where it is.
*/
function zp_import_parts_text(string $text, ?int $userId = null): array {
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') return ['ok' => false, 'msg' => 'Nothing to import — the file or box was empty.'];
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);

    $lines = array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
    if (count($lines) > 2000) return ['ok' => false, 'msg' => 'That is more than 2000 lines. Split the file and import it in pieces.'];

    /* THE OTHER HALF OF THE FORMULA-INJECTION FIX: the export prefixes a cell
       starting with = + - @ with a single quote so Excel cannot run it; the
       import strips ONE back off. Without this the name would gain a quote on
       every round trip. */
    $split = function (string $line): array {
        if (str_contains($line, "\t")) return array_map(fn($c) => zp_csv_unquote(trim($c)), explode("\t", $line));
        return array_map(fn($c) => zp_csv_unquote(trim((string)$c)), str_getcsv($line));
    };

    $first = $split($lines[0]);
    $lower = array_map(fn($c) => mb_strtolower(trim((string)$c)), $first);
    $hasHeader = in_array('part name', $lower, true) || in_array('operation', $lower, true);

    $map = [];
    if ($hasHeader) {
        foreach ($lower as $i => $c) {
            if ($c === 'part name')                         $map[$i] = 'name';
            elseif ($c === 'default uom' || $c === 'uom')   $map[$i] = 'uom';
            elseif ($c === 'style')                         $map[$i] = 'style';
            elseif ($c === 'stage')                         $map[$i] = 'stage';
            elseif ($c === 'operation')                     $map[$i] = 'op';
            elseif ($c === 'rate' || $c === 'rate (per pc)') $map[$i] = 'rate';
            elseif ($c === 'active')                        $map[$i] = 'active';
        }
        array_shift($lines);
    } else {
        $map = [0 => 'name', 1 => 'stage', 2 => 'op', 3 => 'rate'];
    }
    if (!in_array('name', $map, true)) return ['ok' => false, 'msg' => 'No "Part Name" column found. Export a file first to see the shape it expects.'];

    /* gather by part, so one part's rows arrive as one grid */
    $byPart = []; $meta = [];
    foreach ($lines as $n => $line) {
        $cells = $split($line);
        $r = [];
        foreach ($map as $i => $k) $r[$k] = $cells[$i] ?? '';
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        if (!isset($byPart[$name])) { $byPart[$name] = []; $meta[$name] = ['uom' => 'Pc', 'style' => '', 'active' => 1]; }
        if (trim((string)($r['uom'] ?? '')) !== '')   $meta[$name]['uom']   = trim((string)$r['uom']);
        if (trim((string)($r['style'] ?? '')) !== '') $meta[$name]['style'] = trim((string)$r['style']);
        if (isset($r['active']) && trim((string)$r['active']) !== '') {
            $meta[$name]['active'] = in_array(mb_strtolower(trim((string)$r['active'])), ['yes', 'y', '1', 'true', 'active'], true) ? 1 : 0;
        }
        $op = trim((string)($r['op'] ?? ''));
        if ($op === '') continue;                       // a part-only row: create the part, no operation
        $byPart[$name][] = ['stage' => zp_stage_clean($r['stage'] ?? ''), 'name' => $op, 'rate' => (string)($r['rate'] ?? '0'), 'id' => 0];
    }
    if (!$byPart) return ['ok' => false, 'msg' => 'No part names were found in that file.'];

    $added = 0; $updated = 0; $opRows = 0; $problems = [];
    foreach ($byPart as $name => $rows) {
        $find = db()->prepare("SELECT id FROM zp_parts WHERE part_name=?");
        $find->execute([$name]);
        $pid = (int)$find->fetchColumn();
        $r = zp_save_part($pid, $name, $meta[$name]['uom'], $meta[$name]['style'], $meta[$name]['active'], $userId);
        if (!$r['ok']) { $problems[] = $name . ': ' . $r['error']; continue; }
        $pid ? $updated++ : $added++;
        if ($rows) {
            /* THE FIRST LINE MUST BE CUTTING. A file that starts a part on
               Stitching is corrected rather than refused — the import would
               otherwise fail on almost every real spreadsheet — and the
               correction is REPORTED, never silent. */
            if (!zp_is_cutting($rows[0]['stage'])) {
                $cut = null;
                foreach ($rows as $i => $rr) if (zp_is_cutting($rr['stage'])) { $cut = $i; break; }
                if ($cut !== null) { $c = $rows[$cut]; unset($rows[$cut]); array_unshift($rows, $c); $rows = array_values($rows);
                    $problems[] = $name . ': its Cutting line was moved to the top (the first line must be Cutting).';
                } else {
                    $problems[] = $name . ': no Cutting line in the file, so its operations were skipped.';
                    continue;
                }
            }
            $o = zp_save_part_ops($r['id'], $rows, 'CSV import', $userId);
            if (!$o['ok']) $problems[] = $name . ': ' . $o['error'];
            /* An imported rate is a rate. It must reach Costing exactly as a
               typed one does, or a bulk import would silently leave every
               costing sheet on the old numbers. */
            else { $opRows += count($rows); zp_bridge_sync_part((int)$r['id']); }
        }
    }

    $msg = "$added part" . ($added === 1 ? '' : 's') . " added, $updated updated, $opRows operation line"
         . ($opRows === 1 ? '' : 's') . " saved.";
    if ($problems) $msg .= ' Please check: ' . implode(' · ', array_slice($problems, 0, 5))
                        . (count($problems) > 5 ? ' and ' . (count($problems) - 5) . ' more.' : '');
    return ['ok' => true, 'msg' => $msg];
}

/* ------------------------------------------------------------------ */
$parts    = zp_parts();
$costMap  = zp_part_cost_map();
$opCount  = zp_part_op_count_map();
$part     = $editId ? zp_part($editId) : null;
if ($editId && !$part) { $editId = 0; }
$ops      = $editId ? zp_part_ops($editId) : [];
$styles   = [];
foreach ($parts as $p) if (trim((string)$p['style']) !== '' && !in_array($p['style'], $styles, true)) $styles[] = $p['style'];
sort($styles);

/* THE STAGE NAMES USED ON THIS PAGE COME FROM YOUR LIST, never from this file.
   $stageFirstName is whatever you called the stage that makes the pieces;
   $stageNextName is the one after it, which is what a new line defaults to. */
$stageRows      = zp_stage_all(true);
$stageFirstName = $stageRows ? (string)$stageRows[0]['name'] : '';
$stageNextName  = isset($stageRows[1]) ? (string)$stageRows[1]['name'] : $stageFirstName;

page_header('Part Library');
?>
<style>
/* ---- self-contained. Nothing here depends on app.css having been reloaded,
        which is what left a page rendering unstyled once before. ---- */
.zp-wrap{max-width:1500px}
.zp-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.zp-top h1{margin:0;font-size:21px;color:#152033;display:flex;align-items:center;gap:10px}
.zp-top p{margin:3px 0 0;color:#8a97ab;font-size:12.5px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}
.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}
.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-b[disabled]{opacity:.45;cursor:not-allowed;border-color:#e3e8ef;color:#98a5b8;background:#f7f9fc}

.zp-split{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;align-items:start}
@media(min-width:1180px){.zp-split{grid-template-columns:minmax(440px,1fr) minmax(520px,1.15fr)}}

.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.zp-card h2 .lite{font-weight:500;font-size:12px;color:#8a97ab}
.zp-bar{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0 13px}

table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:7px 8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:6px 8px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tr:last-child td{border-bottom:none}
table.zp-t tbody tr:hover{background:#fafcff}
table.zp-t tr.on td{background:#eaf8fc}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.ok{background:rgba(22,163,74,.13);color:#15803d}
.pill.off{background:#eef1f6;color:#8a97ab}

.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.zin[readonly]{background:#f6f8fc;color:#7d8ca1}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
.f3{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:11px;margin-bottom:14px}

.note{padding:10px 12px;border-radius:10px;font-size:12.5px;line-height:1.5;margin-top:12px}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.good{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.note.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note ul{margin:6px 0 0;padding-left:18px}
.note li{margin:2px 0}

.zp-flash{padding:10px 13px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:14px}
.zp-flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.zp-flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}

.hintbox{background:#fdeef1;border:1px solid #f6cdd5;border-radius:10px;padding:11px 13px;font-size:12px;color:#9c2740;line-height:1.55}
.hintbox ul{margin:0;padding-left:17px}
.hintbox li{margin:3px 0}
.hintbox b{color:#7d1f33}

#opTable td{padding:5px 6px}
#opTable .stg{width:132px}
#opTable .rt{width:100px}
#opTable .act{width:44px;text-align:center}
#opTable .no{width:34px;color:#8a97ab;font-size:11.5px;text-align:center}
.fixed{background:#eef1f6;border:1px solid #e3e8ef;border-radius:8px;padding:7px 9px;font-size:12.5px;color:#5a6b82}
.dash{color:#c3cbd8}
.totline{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;
         padding:9px 12px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px;font-size:13px}
.totline b{font-size:16px;color:#152033;font-variant-numeric:tabular-nums}
.empty{padding:22px;text-align:center;color:#8a97ab;font-size:12.5px}
details.paste{margin-top:12px}
details.paste summary{cursor:pointer;font-size:12.5px;color:#0b7f99;font-weight:700}
textarea.zin{min-height:110px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zp-wrap">

  <?php if (!$stageRows): /* NO STAGES = NOTHING CAN BE PRICED. Said here, once,
        rather than letting somebody type a whole grid and be refused on save. */ ?>
    <div style="padding:13px 15px;border-radius:11px;background:#fff6e8;border:1px solid #f3ddb8;
                color:#8a5a10;font-size:13px;line-height:1.6;margin-bottom:16px">
      <b>You have no production stages yet, so an operation has nothing to belong to.</b><br>
      <a href="production_stages.php" style="color:#8a5a10;font-weight:800">Set up your stages first</a> —
      name them whatever your floor calls them. Everything on this page then offers your list.
    </div>
  <?php endif; ?>

  <div class="zp-top">
    <div>
      <h1>Part Library</h1>
      <p>Define a part once — its operations and its rates — then use it on as many products as you like.</p>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
      <a class="zp-b" href="production_stages.php">Production Stages</a>
      <a class="zp-b" href="product_master.php">Master Products</a>
      <a class="zp-b pri" href="part_library.php?part=0#partform">+ Add New Part</a>
      <?php /* WHICH COPY OF THIS FILE IS ACTUALLY RUNNING.
               Three rounds were lost to not being able to tell "the upload did
               not land" from "the browser is showing a stored copy" — they need
               opposite fixes. This is read from disk on the server, so a cached
               page shows the OLD date and a fresh one shows the new. Compare it
               with the date on the zip. */ ?>
      <span class="code" style="font-size:10px;color:#a7b2c4;white-space:nowrap;align-self:center"
            title="Modified date of this file on the server. Older than the zip you uploaded means it never landed; newer but the page still looks old means the browser is showing a stored copy — reload with Ctrl+Shift+R.">
        build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="zp-flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="zp-flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="zp-split">

    <!-- ============================ LEFT: the list ============================ -->
    <div class="zp-card">
      <h2>Parts Library</h2>
      <div class="zp-bar">
        <a class="zp-b pri" href="part_library.php?part=0#partform">+ Add New Part</a>
        <a class="zp-b" href="part_library.php?export=csv">Export CSV</a>
        <button type="button" class="zp-b" onclick="document.getElementById('csvFile').click()">Import CSV</button>
        <button type="button" class="zp-b" id="copyBtn">Copy</button>
        <button type="button" class="zp-b" onclick="document.getElementById('pasteBox').open=true;document.getElementById('pasteTa').focus()">Paste</button>
      </div>

      <form method="post" enctype="multipart/form-data" id="csvForm" style="display:none">
        <?= csrf_field() ?><input type="hidden" name="action" value="import_csv">
        <input type="file" name="csv" id="csvFile" accept=".csv,.txt" onchange="document.getElementById('csvForm').submit()">
      </form>

      <?php if (!$parts): ?>
        <div class="empty">
          No parts yet.<br><br>
          A part is a piece you make — a Bed Sheet, a Pillow Cover, a Packing Bag.<br>
          Press <b>+ Add New Part</b> to define your first one.
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t" id="partsTable">
        <thead><tr>
          <th style="width:34px">#</th><th style="width:66px">Part ID</th><th>Part Name</th>
          <th style="width:76px">Default UOM</th><th style="width:54px" class="num">Ops</th>
          <th style="width:84px" class="num">Cost / Pc</th><th style="width:190px">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($parts as $i => $p): $pid = (int)$p['id']; ?>
          <tr class="<?= $pid === $editId ? 'on' : '' ?>">
            <td class="code"><?= $i + 1 ?></td>
            <td class="code"><b><?= e(zp_part_code($pid)) ?></b></td>
            <td>
              <?= e($p['part_name']) ?>
              <?php if (!$p['is_active']): ?> <span class="pill off">inactive</span><?php endif; ?>
              <?php if (trim((string)$p['style']) !== ''): ?>
                <div class="code" style="font-size:10.5px"><?= e($p['style']) ?></div><?php endif; ?>
            </td>
            <td class="code"><?= e($p['uom']) ?></td>
            <td class="num"><?= (int)($opCount[$pid] ?? 0) ?></td>
            <td class="num"><?= number_format((float)($costMap[$pid] ?? 0), 2) ?></td>
            <td style="white-space:nowrap">
              <a class="zp-b sm" href="part_library.php?part=<?= $pid ?>#partform">Edit</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="duplicate_part">
                <input type="hidden" name="part_id" value="<?= $pid ?>">
                <button class="zp-b sm" title="Make a copy of this part with all of its operations">Duplicate</button>
              </form>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Delete <?= e(addslashes($p['part_name'])) ?>?\n\nIf any product uses it, it will be deactivated instead of deleted, so nothing breaks.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_part">
                <input type="hidden" name="part_id" value="<?= $pid ?>">
                <button class="zp-b sm red">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>

      <details class="paste" id="pasteBox">
        <summary>Paste a block from Excel</summary>
        <form method="post" style="margin-top:9px">
          <?= csrf_field() ?><input type="hidden" name="action" value="paste_rows">
          <p style="font-size:12px;color:#8a97ab;margin:0 0 7px">
            Four columns, no header needed: <b>Part Name · Stage · Operation · Rate</b>.
            One line per operation. The first line of each part must sit at
            <b><?= e($stageFirstName) ?></b> — the stage that makes the pieces.</p>
          <textarea class="zin" name="pasted" id="pasteTa"
            placeholder="<?= e("Bed Sheet\t$stageFirstName\t$stageFirstName\t4.00\nBed Sheet\t$stageNextName\tOverlock\t8.00\nPillow Cover\t$stageFirstName\t$stageFirstName\t2.25") ?>"></textarea>
          <button class="zp-b pri" style="margin-top:9px">Import what I pasted</button>
        </form>
      </details>

      <div class="note good">
        <b>Everything about a part is entered here.</b>
        <ul>
          <li>CSV export and import — the file it writes is the file it reads.</li>
          <li>Copy puts the table on your clipboard; Paste takes a block straight from Excel.</li>
          <li>Edit, Duplicate, Delete.</li>
          <li><b>One part can be used by any number of products.</b> Fix a rate here and every product that uses it is fixed.</li>
        </ul>
      </div>
    </div>

    <!-- ================= RIGHT: operations and cost ================= -->
    <div class="zp-card" id="partform">
      <h2>Part Operations &amp; Cost <span class="lite">— no finishing cost, no rate at the top</span></h2>

      <?php if (!$editId && !isset($_GET['part'])): ?>
        <div class="empty">
          Choose a part on the left to see its operations,<br>or press <b>+ Add New Part</b>.
        </div>
      <?php else: ?>

      <!-- the part's own details -->
      <form method="post" id="partDetails">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_part">
        <input type="hidden" name="part_id" value="<?= (int)$editId ?>">
        <div class="f3">
          <div>
            <span class="lab">Part ID</span>
            <input class="zin" value="<?= $editId ? e(zp_part_code($editId)) : 'new' ?>" readonly
                   title="Given automatically when the part is saved. It never changes.">
          </div>
          <div>
            <span class="lab">Part Name</span>
            <input class="zin" name="part_name" required maxlength="120" autocomplete="off"
                   value="<?= e($part['part_name'] ?? '') ?>" placeholder="Bed Sheet">
          </div>
          <div>
            <span class="lab">Default UOM</span>
            <select class="zin" name="uom">
              <?php foreach (['Pc', 'Set', 'Mtr', 'Kg', 'Pair'] as $u): ?>
                <option <?= ($part['uom'] ?? 'Pc') === $u ? 'selected' : '' ?>><?= $u ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <span class="lab">Style (optional)</span>
            <input class="zin" name="style" list="styleList" maxlength="80" autocomplete="off"
                   value="<?= e($part['style'] ?? '') ?>" placeholder="Plain / Printed">
            <datalist id="styleList"><?php foreach ($styles as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
          </div>
        </div>
        <div style="display:flex;gap:11px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
          <button class="zp-b pri"><?= $editId ? 'Save Part Details' : 'Create Part' ?></button>
          <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:#5a6b82;cursor:pointer">
            <input type="checkbox" name="is_active" value="1" <?= ($editId ? (int)$part['is_active'] : 1) ? 'checked' : '' ?>>
            Active</label>
          <?php if (!$editId): ?>
            <span style="font-size:11.5px;color:#8a97ab">Save the name first — then its operations appear below.</span>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($editId): ?>
      <!-- ---------------- the operations grid ---------------- -->
      <form method="post" id="opsForm" style="margin-top:18px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_ops">
        <input type="hidden" name="part_id" value="<?= (int)$editId ?>">

        <div style="font-size:12.5px;font-weight:800;color:#152033;margin-bottom:7px">Operations (stage by stage)</div>

        <div style="overflow-x:auto">
        <table class="zp-t" id="opTable" style="min-width:470px">
          <thead><tr>
            <th class="no">#</th><th class="stg">Stage</th><th>Operation</th>
            <th class="rt num">Rate / Pc</th><th class="act"></th>
          </tr></thead>
          <tbody id="opBody"></tbody>
        </table>
        </div>

        <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:11px">
          <button type="button" class="zp-b" onclick="zpAddRow()">+ Add Operation</button>
          <span style="font-size:11px;color:#8a97ab;font-family:ui-monospace,monospace">
            Enter or Tab &rarr; next box &middot; Enter on the rate &rarr; new line &middot; Ctrl+S saves</span>
        </div>

        <div class="totline">
          <span>Cost of making one <b style="font-size:13px;font-weight:800"><?= e($part['part_name']) ?></b></span>
          <span><b id="opTotal">0.00</b> <span style="font-size:11.5px;color:#8a97ab">per <?= e($part['uom']) ?></span></span>
        </div>

        <div style="margin-top:11px">
          <input class="zin" name="rate_reason" maxlength="255" autocomplete="off"
                 placeholder="Why a rate changed — only needed if you moved one (e.g. revised after the March wage review)">
        </div>

        <div style="margin-top:12px">
          <button class="zp-b pri">Save Operations</button>
        </div>

        <div class="note info">
          <b>A stage can hold as many operations as you like.</b>
          Put Overlock and Singer both at <b><?= e($stageNextName) ?></b> and each is paid its own rate on
          every piece — one never eats the other's allowance. Need a stage that is not in the box?
          <a href="production_stages.php">Add it on the Stages screen</a> and it appears here straight away.
        </div>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="hintbox" style="margin-top:16px">
    <ul>
      <li><b>The first line is always <?= e($stageFirstName) ?></b> and cannot be deleted — it is the stage that makes the pieces, and every stage after it is limited by it.</li>
      <li><b>From the second line on, the stage is <?= e($stageNextName) ?></b> by default and the cursor starts in the Operation box, because that is the part you actually have to type.</li>
      <li><b>Enter or Tab</b> moves to the Rate; Enter on the rate starts a new line. You never need the mouse.</li>
      <li><b>The stage names are yours.</b> Rename or reorder them on <a href="production_stages.php">Production Stages</a> — renaming never moves a wage, because work is booked against the stage, not its spelling.</li>
      <li><b>No finishing cost and no rate at the top.</b> The cost of a part is simply its operations added up — shown above, live, as you type.</li>
    </ul>
  </div>
</div>

<script>
/* ------------------------------------------------------------------
   THE OPERATIONS GRID
   ------------------------------------------------------------------
   Rows are built in JavaScript from one array, rather than written twice —
   once in PHP for the saved rows and once in JS for new ones. Two copies of
   the same markup drift apart, and then a new row behaves differently from a
   saved one for no reason anybody can see.
   ------------------------------------------------------------------ */
(function(){
  /* YOUR stages, in your order, straight from the Production Stages screen.
     There is no list of stage names anywhere in this file any more. */
  var STAGES = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']],
                                         zp_stage_all(true))) ?>;
  var FIRST_STAGE = <?= (int)zp_stage_first_id() ?>;
  var body   = document.getElementById('opBody');
  if (!body) return;

  var SAVED = <?= json_encode(array_map(fn($o) => [
      'id' => (int)$o['id'],
      'stage_id' => (int)($o['stage_id'] ?: zp_stage_id_for((string)$o['stage'])),
      'name' => $o['operation_name'], 'rate' => rtrim(rtrim(number_format((float)$o['rate'], 2, '.', ''), '0'), '.'),
  ], $ops)) ?>;

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  /* THE FIRST ROW SITS AT THE FIRST STAGE.
     Not because of what that stage is called — because the first stage is the
     one that makes the pieces, and every stage after it is limited by what
     already exists. So row 1 offers only that stage and has no delete button.
     The rule is enforced by what the row offers, not by a message after you
     have already got it wrong. */
  function stageName(id){
    for (var k = 0; k < STAGES.length; k++) if (STAGES[k].id === id) return STAGES[k].name;
    return '';
  }
  function opts(list, sel){
    return list.map(function(s){
      return '<option value="'+s.id+'"'+(s.id===sel?' selected':'')+'>'+esc(s.name)+'</option>';
    }).join('');
  }
  function rowHtml(r, i){
    var first = (i === 0);
    var sel = r.stage_id || (first ? FIRST_STAGE : (STAGES.length > 1 ? STAGES[1].id : FIRST_STAGE));
    var stageCell = '<select class="zin" name="op_stage_id[]" data-c="stage">'
      + opts(first ? STAGES.filter(function(s){ return s.id === FIRST_STAGE; }) : STAGES,
             first ? FIRST_STAGE : sel)
      + '</select>';
    return '<td class="no">' + (i+1) + '</td>'
      + '<td class="stg"><input type="hidden" name="op_id[]" value="'+(r.id||0)+'">' + stageCell + '</td>'
      + '<td><input class="zin" name="op_name[]" data-c="name" autocomplete="off" value="'+esc(r.name)+'"'
        + ' placeholder="'+(first ? esc(stageName(FIRST_STAGE)) : 'Type the operation…')+'"></td>'
      + '<td class="rt"><input class="zin num" name="op_rate[]" data-c="rate" inputmode="decimal"'
        + ' autocomplete="off" value="'+esc(r.rate)+'" placeholder="0.00"></td>'
      + '<td class="act">'
        + (first ? '<span class="dash" title="The first line cannot be removed — it is what makes the pieces">&mdash;</span>'
                 : '<button type="button" class="zp-b sm red" tabindex="-1" onclick="zpDropRow(this)">&times;</button>')
      + '</td>';
  }

  function renumber(){
    [].slice.call(body.children).forEach(function(tr, i){
      tr.querySelector('.no').textContent = i + 1;
      var act = tr.querySelector('.act');
      if (i === 0) act.innerHTML = '<span class="dash" title="The first line cannot be removed — it is what makes the pieces">&mdash;</span>';
      else if (!act.querySelector('button'))
        act.innerHTML = '<button type="button" class="zp-b sm red" tabindex="-1" onclick="zpDropRow(this)">&times;</button>';
    });
    total();
  }

  function total(){
    var t = 0;
    [].slice.call(body.querySelectorAll('[data-c="rate"]')).forEach(function(inp){
      var v = parseFloat(String(inp.value).replace(/,/g, ''));
      if (!isNaN(v)) t += v;
    });
    var el = document.getElementById('opTotal');
    if (el) el.textContent = t.toFixed(2);
  }

  window.zpAddRow = function(stageId){
    var i = body.children.length;
    var tr = document.createElement('tr');
    tr.innerHTML = rowHtml({ id:0, stage_id: stageId || 0, name:'', rate:'' }, i);
    body.appendChild(tr);
    renumber();
    /* the cursor lands where you actually have to type */
    var f = tr.querySelector(i === 0 ? '[data-c="rate"]' : '[data-c="name"]');
    if (f) f.focus();
    return tr;
  };

  window.zpDropRow = function(btn){
    var tr = btn.closest('tr');
    if (tr.parentNode.children.length <= 1) return;   // never leave the grid empty
    tr.remove();
    renumber();
  };

  /* ---- draw what is saved, or start a fresh part on its Cutting line ---- */
  if (SAVED.length) {
    SAVED.forEach(function(r, i){
      var tr = document.createElement('tr');
      tr.innerHTML = rowHtml(r, i);
      body.appendChild(tr);
    });
    renumber();
  } else {
    var first = window.zpAddRow(FIRST_STAGE);
    var nm = first.querySelector('[data-c="name"]');
    /* the first stage's own name is the obvious operation name, already filled
       in — whatever you called that stage */
    if (nm) nm.value = stageName(FIRST_STAGE);
    var rt = first.querySelector('[data-c="rate"]');
    if (rt) rt.focus();
  }

  /* ---- keyboard: this grid is typed, not clicked ---- */
  body.addEventListener('keydown', function(ev){
    var el = ev.target, c = el.getAttribute && el.getAttribute('data-c');
    if (!c) return;
    if (ev.key === 'Enter') {
      ev.preventDefault();
      var tr = el.closest('tr');
      if (c === 'name')  { tr.querySelector('[data-c="rate"]').focus(); return; }
      if (c === 'rate')  {
        var next = tr.nextElementSibling;
        if (next) next.querySelector('[data-c="name"]').focus();
        else window.zpAddRow();
        return;
      }
      tr.querySelector('[data-c="name"]').focus();
      return;
    }
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      var tr2 = el.closest('tr');
      var go = ev.key === 'ArrowDown' ? tr2.nextElementSibling : tr2.previousElementSibling;
      if (!go) return;
      ev.preventDefault();
      var same = go.querySelector('[data-c="' + c + '"]');
      if (same) { same.focus(); if (same.select) same.select(); }
    }
  });

  body.addEventListener('input', function(ev){
    if (ev.target.getAttribute && ev.target.getAttribute('data-c') === 'rate') total();
  });

  /* Ctrl+S saves, because this is a data-entry screen and that is the reflex */
  document.addEventListener('keydown', function(ev){
    if ((ev.ctrlKey || ev.metaKey) && String(ev.key).toLowerCase() === 's') {
      var f = document.getElementById('opsForm');
      if (f) { ev.preventDefault(); f.submit(); }
    }
  });
})();

/* ---- Copy the parts table to the clipboard as a block Excel understands ---- */
(function(){
  var btn = document.getElementById('copyBtn'), tbl = document.getElementById('partsTable');
  if (!btn || !tbl) { if (btn) btn.disabled = true; return; }
  btn.onclick = function(){
    var lines = [];
    [].slice.call(tbl.querySelectorAll('thead th')).slice(0, 6).forEach(function(){});
    lines.push(['Part ID','Part Name','Default UOM','Ops','Cost / Pc'].join('\t'));
    [].slice.call(tbl.querySelectorAll('tbody tr')).forEach(function(tr){
      var td = tr.querySelectorAll('td');
      lines.push([td[1].innerText.trim(), td[2].innerText.trim().split('\n')[0],
                  td[3].innerText.trim(), td[4].innerText.trim(), td[5].innerText.trim()].join('\t'));
    });
    var text = lines.join('\n');
    function done(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1200); }
    /* the modern API needs a secure context; the old one always works, so it is
       the fallback rather than the other way round */
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function(){ legacy(text, done); });
    } else legacy(text, done);
  };
  function legacy(text, done){
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { alert('Could not copy — select the table and press Ctrl+C.'); }
    document.body.removeChild(ta);
  }
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
