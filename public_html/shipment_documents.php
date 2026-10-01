<?php
/*
  DOCUMENTS TAB — the official paperwork, with versions.

  An official document is never overwritten. Uploading a BL draft again makes
  V2 and marks V1 superseded; V1 stays readable for ever. The reason is
  simple: when a buyer or a bank disputes what was sent, "the file we have
  now" is not an answer.

  Nothing here is hard-deleted by an ordinary user. A wrong upload is
  archived with a reason and drops out of the normal view.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/storage.php';

$shipment = exp_open_shipment('documents');
$id       = (int)$shipment['id'];

/* Extensions accepted. Mostly PDF, with the office formats and images that
   genuinely turn up. Checked on the extension AND on the browser's declared
   type, and the original filename is never used as the stored name. */
const EXP_DOC_EXT = ['pdf','xlsx','xls','csv','doc','docx','jpg','jpeg','png','webp','txt','zip'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'upload') {
            exp_require('documents', 'c');

            $typeId = (int)($_POST['doc_type_id'] ?? 0);
            if ($typeId <= 0) throw new Exception('Choose a document type.');

            $typeRow = null;
            foreach (exp_masters('doc_type', false) as $t) if ((int)$t['id'] === $typeId) $typeRow = $t;
            if (!$typeRow) throw new Exception('Unknown document type.');

            $stage = (string)($_POST['stage'] ?? '');
            if (!exp_master_flag($typeRow, 'supports_draft_final')) $stage = '';
            if (!in_array($stage, ['draft', 'final', ''], true)) $stage = '';

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE
                    ? 'That file is larger than the server allows.'
                    : 'Upload failed — please try again.');
            }

            global $config;
            $maxMb = (int)($config['max_upload_mb'] ?? 20);
            if ($_FILES['file']['size'] > $maxMb * 1024 * 1024) {
                throw new Exception('File is larger than ' . $maxMb . ' MB.');
            }
            if ($_FILES['file']['size'] <= 0) throw new Exception('That file is empty.');

            $orig = basename((string)$_FILES['file']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, EXP_DOC_EXT, true)) {
                throw new Exception('That file type is not accepted. Allowed: ' . implode(', ', EXP_DOC_EXT) . '.');
            }

            $mime = (string)($_FILES['file']['type'] ?? '');

            [$ok, $driver, $key, $err] = exp_store_upload($id, (string)$_FILES['file']['tmp_name'], $orig, $mime);
            if (!$ok) throw new Exception('The document was not stored: ' . $err);

            $version = exp_next_version($id, $typeId, $stage !== '' ? $stage : null);

            db()->beginTransaction();
            db()->prepare("INSERT INTO shipment_documents
                (shipment_id, doc_type_id, doc_no, stage, version, original_name, storage_driver, storage_key, mime_type, file_size, notes, uploaded_by, uploaded_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([
                    $id, $typeId,
                    trim((string)($_POST['doc_no'] ?? '')) ?: null,
                    $stage !== '' ? $stage : null,
                    $version, $orig, $driver, $key, $mime,
                    (int)$_FILES['file']['size'],
                    trim((string)($_POST['notes'] ?? '')) ?: null,
                    current_user()['id'],
                ]);
            $newId = (int)db()->lastInsertId();

            /* Every earlier live version of this same type and stage is marked
               superseded. They are not archived and not deleted — they are
               simply no longer the current one. */
            db()->prepare("UPDATE shipment_documents SET superseded_by=?
                           WHERE shipment_id=? AND doc_type_id=? AND (stage <=> ?)
                             AND id<>? AND superseded_by IS NULL AND is_archived=0")
                ->execute([$newId, $id, $typeId, $stage !== '' ? $stage : null, $newId]);
            db()->commit();

            audit_log($id, 'Document', 'upload', '',
                      $typeRow['label'] . ($stage !== '' ? ' ' . ucfirst($stage) : '') . ' V' . $version . ' — ' . $orig,
                      'Document uploaded to ' . strtoupper($driver));

            $_SESSION['flash'] = $typeRow['label'] . ($stage !== '' ? ' ' . ucfirst($stage) : '')
                               . ' saved as version ' . $version
                               . ($version > 1 ? '. Version ' . ($version - 1) . ' is kept and marked superseded.' : '.');
            redirect('shipment_documents.php?id=' . $id);
        }

        if ($action === 'archive') {
            exp_require('documents', 'd');
            $did    = (int)($_POST['doc_id'] ?? 0);
            $reason = trim((string)($_POST['archive_reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is required to archive a document.');

            $st = db()->prepare("SELECT * FROM shipment_documents WHERE id=? AND shipment_id=?");
            $st->execute([$did, $id]); $row = $st->fetch();
            if (!$row) throw new Exception('Document not found on this shipment.');

            db()->prepare("UPDATE shipment_documents SET is_archived=1, archive_reason=?, archived_by=?, archived_at=NOW() WHERE id=? AND shipment_id=?")
                ->execute([$reason, current_user()['id'], $did, $id]);
            audit_log($id, 'Document', 'archive', (string)$row['original_name'], 'ARCHIVED', $reason);
            $_SESSION['flash'] = 'Document archived. The file is still stored and still downloadable from the history.';
            redirect('shipment_documents.php?id=' . $id);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
        redirect('shipment_documents.php?id=' . $id);
    }
}

$showArchived = !empty($_GET['archived']);
$docs    = exp_documents($id, $showArchived);
$types   = exp_masters('doc_type');
$typeAll = exp_masters('doc_type', false);
$typeMap = exp_master_map('doc_type');
$flagMap = [];
foreach ($typeAll as $t) $flagMap[(int)$t['id']] = exp_master_flag($t, 'supports_draft_final');

/* Grouped by type and stage so the current version is obvious and the older
   ones sit under it rather than scattered through one long list. */
$groups = [];
foreach ($docs as $d) {
    $k = (int)($d['doc_type_id'] ?? 0) . '|' . (string)($d['stage'] ?? '');
    $groups[$k][] = $d;
}

$canUpload  = exp_can('documents', 'c');
$canArchive = exp_can('documents', 'd');

function exp_size_h($bytes): string {
    $b = (float)$bytes;
    if ($b <= 0) return '';
    if ($b < 1024) return (int)$b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

page_header('Documents — ' . $shipment['invoice_no']);
flash();
echo exp_page_css();
exp_tab_strip($shipment, 'docs');
?>
<style>
.dgroup{border:1px solid #e3e9f2;border-radius:12px;margin-bottom:9px;overflow:hidden}
.dhead{padding:9px 13px;background:#f6f8fc;display:flex;justify-content:space-between;align-items:center;gap:9px;flex-wrap:wrap}
.dhead .n{font-size:13px;font-weight:700;color:#152033}
.drow{padding:9px 13px;border-top:1px solid #f6f8fc;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.drow.old{background:#fcfdfe}
.dmeta{font-size:11px;color:#8a97ab;margin-top:2px}
</style>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
    <h2 style="margin:0">Shipment Documents</h2>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:11.5px;color:#8a97ab">
        Stored on <?= exp_r2_configured() ? 'Cloudflare R2' : 'this server' ?>
      </span>
      <a class="xbtn sec sm" href="shipment_documents.php?id=<?= $id ?><?= $showArchived ? '' : '&archived=1' ?>">
        <?= $showArchived ? 'Hide archived' : 'Show archived' ?>
      </a>
    </div>
  </div>
</div>

<?php if (!$groups): ?>
  <div class="xcard">
    <div class="xnote">No documents uploaded against this shipment yet.</div>
  </div>
<?php endif; ?>

<?php foreach ($groups as $k => $list):
    [$tid, $stage] = explode('|', (string)$k);
    $label = $typeMap[(int)$tid] ?? 'Document';
    if ($stage !== '') $label .= ' — ' . ucfirst($stage);
    /* Newest first, so the first row is the current one. */
    usort($list, fn($a, $b) => (int)$b['version'] <=> (int)$a['version']);
    $current = $list[0];
?>
<div class="dgroup">
  <div class="dhead">
    <div>
      <div class="n"><?= e($label) ?></div>
      <div class="dmeta"><?= count($list) ?> version<?= count($list) === 1 ? '' : 's' ?></div>
    </div>
    <div>
      <?php if ((int)$current['is_archived'] === 1): ?>
        <span class="xpill o">Archived</span>
      <?php else: ?>
        <span class="xpill g">Current: V<?= (int)$current['version'] ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($list as $i => $d):
      $isCurrent = ($i === 0 && (int)$d['is_archived'] === 0);
      $superseded = !empty($d['superseded_by']); ?>
    <div class="drow <?= $isCurrent ? '' : 'old' ?>">
      <div style="min-width:0">
        <div style="font-size:12.5px;font-weight:<?= $isCurrent ? '700' : '500' ?>;color:#152033;word-break:break-all">
          V<?= (int)$d['version'] ?> &middot; <?= e($d['original_name']) ?>
          <?php if ($superseded): ?><span class="xpill o" style="margin-left:5px">superseded</span><?php endif; ?>
          <?php if ((int)$d['is_archived'] === 1): ?><span class="xpill r" style="margin-left:5px">archived</span><?php endif; ?>
        </div>
        <div class="dmeta">
          <?= $d['doc_no'] ? 'No. ' . e($d['doc_no']) . ' &middot; ' : '' ?>
          <?= e(exp_size_h($d['file_size'])) ?>
          <?= $d['uploaded_at'] ? ' &middot; ' . e(date('d M Y H:i', strtotime((string)$d['uploaded_at']))) : '' ?>
          &middot; <?= strtoupper(e((string)$d['storage_driver'])) ?>
          <?php if ($d['notes']): ?><br><?= e($d['notes']) ?><?php endif; ?>
          <?php if ((int)$d['is_archived'] === 1 && $d['archive_reason']): ?>
            <br><span style="color:#b8283f">Archived: <?= e($d['archive_reason']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:6px;white-space:nowrap">
        <a class="xbtn sec sm" href="shipment_doc_file.php?doc=<?= (int)$d['id'] ?>">Download</a>
        <?php if ($canArchive && (int)$d['is_archived'] === 0): ?>
          <button type="button" class="xbtn red sm" onclick="archiveDoc(<?= (int)$d['id'] ?>,'<?= e(addslashes((string)$d['original_name'])) ?>')">Archive</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($canUpload): ?>
<div class="xcard" style="margin-top:12px">
  <h2>Upload a Document</h2>
  <?php if (!exp_r2_configured() && is_admin()): ?>
    <div class="xwarn" style="margin-bottom:12px">
      R2 is not configured, so this file goes to the web server's disk. That works, but
      <a href="exp_settings.php?tab=storage" style="color:#9a5a06;font-weight:700">switch on R2</a>
      to keep documents off the hosting disk.
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="upForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="shipment_id" value="<?= $id ?>">

    <div class="xgrid">
      <label class="xlabel">Document Type
        <select class="xin" name="doc_type_id" id="typeIn" required>
          <option value="">—</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" data-stage="<?= exp_master_flag($t, 'supports_draft_final') ? 1 : 0 ?>"><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel" id="stageWrap" style="display:none">Stage
        <select class="xin" name="stage" id="stageIn">
          <option value="draft">Draft</option>
          <option value="final">Final</option>
        </select>
      </label>
      <label class="xlabel">Document No. <span style="color:#8a97ab">(optional)</span>
        <input class="xin" name="doc_no" placeholder="e.g. MAEU240817221">
      </label>
      <label class="xlabel xspan2">File
        <input class="xin" type="file" name="file" required accept=".pdf,.xlsx,.xls,.csv,.doc,.docx,.jpg,.jpeg,.png,.webp,.txt,.zip">
      </label>
      <label class="xlabel xspan2">Notes<input class="xin" name="notes"></label>
    </div>

    <div id="verNote" class="xnote" style="display:none;margin-top:11px"></div>

    <div style="display:flex;gap:9px;margin-top:13px">
      <button class="xbtn">Upload</button>
      <a class="xbtn sec" href="shipment_view.php?id=<?= $id ?>">Back to Invoice</a>
    </div>
  </form>

  <div class="xnote" style="margin-top:12px">
    Uploading the same type again creates the next version. The older one is kept and marked
    superseded — nothing is ever overwritten.
  </div>
</div>
<?php endif; ?>

<?php if ($canArchive): ?>
<div id="arcModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;border-radius:16px;padding:22px;max-width:430px;width:100%;border:1px solid #e3e9f2">
    <h2 style="font-size:15px;margin:0 0 7px;color:#b8283f">Archive <span id="aName" style="word-break:break-all"></span>?</h2>
    <p style="font-size:12.5px;color:#5a6b82;line-height:1.55;margin:0 0 14px">
      The file is <b>not deleted</b>. It drops out of the normal list and stays downloadable under
      "Show archived", with your reason recorded.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="archive">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="doc_id" id="aId">
      <label class="xlabel">Reason <span style="color:#b8283f">*</span>
        <input class="xin" name="archive_reason" id="aReason" required placeholder="e.g. wrong shipment">
      </label>
      <div style="display:flex;gap:9px;margin-top:15px">
        <button type="button" class="xbtn sec" style="flex:1" onclick="document.getElementById('arcModal').style.display='none'">Cancel</button>
        <button class="xbtn red" style="flex:1">Archive</button>
      </div>
    </form>
  </div>
</div>
<script>
function archiveDoc(id, name) {
  document.getElementById('aId').value = id;
  document.getElementById('aName').textContent = name;
  document.getElementById('aReason').value = '';
  document.getElementById('arcModal').style.display = 'flex';
  document.getElementById('aReason').focus();
}
</script>
<?php endif; ?>

<script>
/* The Stage dropdown only appears for a type that has a draft and a final,
   and the next version number is shown before you upload so there is no
   surprise about what you are about to create. */
(function () {
  var typeIn = document.getElementById('typeIn');
  if (!typeIn) return;
  var wrap = document.getElementById('stageWrap');
  var stage = document.getElementById('stageIn');
  var note = document.getElementById('verNote');

  /* type|stage -> highest existing version, from what the page already knows. */
  var have = <?= json_encode((function () use ($docs) {
      $m = [];
      foreach ($docs as $d) {
          $k = (int)($d['doc_type_id'] ?? 0) . '|' . (string)($d['stage'] ?? '');
          $m[$k] = max($m[$k] ?? 0, (int)$d['version']);
      }
      return $m;
  })()) ?>;

  function refresh() {
    var opt = typeIn.options[typeIn.selectedIndex];
    var hasStage = opt && opt.getAttribute('data-stage') === '1';
    wrap.style.display = hasStage ? 'block' : 'none';

    if (!typeIn.value) { note.style.display = 'none'; return; }
    var key = typeIn.value + '|' + (hasStage ? stage.value : '');
    var next = (have[key] || 0) + 1;
    note.style.display = 'block';
    note.innerHTML = next === 1
      ? 'This will be saved as <b>version 1</b>.'
      : 'This will be saved as <b>version ' + next + '</b>. Version ' + (next - 1) +
        ' is kept and marked superseded.';
  }

  typeIn.addEventListener('change', refresh);
  stage.addEventListener('change', refresh);
  refresh();

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var m = document.getElementById('arcModal');
      if (m && m.style.display === 'flex') { m.style.display = 'none'; return; }
      window.location.href = 'shipment_view.php?id=<?= $id ?>';
    }
  });
})();
</script>
<?php page_footer(); ?>
