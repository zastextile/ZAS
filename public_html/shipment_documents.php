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
require_once __DIR__ . '/includes/textindex.php';
require_once __DIR__ . '/includes/storage.php';

$shipment = exp_open_shipment('documents');
$id       = (int)$shipment['id'];

/* Extensions accepted. Mostly PDF, with the office formats and images that
   genuinely turn up. Checked on the extension AND on the browser's declared
   type, and the original filename is never used as the stored name. */
const EXP_DOC_EXT = ['pdf','xlsx','xls','csv','doc','docx','jpg','jpeg','png','webp','txt','zip'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    /* KEEPING THE SEARCH INDEX CURRENT.
     *
     * Registered once, here, rather than bolted onto each of this page's
     * redirects — there are several and a new one would quietly skip the
     * index. A shutdown function runs after the response has gone, so this
     * cannot slow the save down, and every txt_* call swallows its own
     * errors, so it cannot break one either. Re-indexing an unchanged
     * record is harmless: the write is an upsert keyed on the record. */
    register_shutdown_function(function () use ($id) { txt_index_shipment_notes((int)$id);
        foreach (exp_documents((int)$id, true) as $d) txt_index_document((int)$d['id']); });

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

            /* If this upload was started from a payment or a cost row, link it
               back to that row now. The target was verified against this
               shipment when the page loaded, so a hand-edited URL cannot
               attach a document to someone else's payment. */
            $attached = '';
            $target = exp_attach_target($id, (string)($_POST['for'] ?? ''));
            if ($target) {
                exp_attach_document($target, $newId, $id);
                $attached = ' It is now attached to the ' . $target['label'] . '.';
                audit_log($id, 'Document', 'attach', '', $orig . ' → ' . $target['label'], 'Document linked to a row');
            }

            audit_log($id, 'Document', 'upload', '',
                      $typeRow['label'] . ($stage !== '' ? ' ' . ucfirst($stage) : '') . ' V' . $version . ' — ' . $orig,
                      'Document uploaded to ' . strtoupper($driver));

            $_SESSION['flash'] = $typeRow['label'] . ($stage !== '' ? ' ' . ucfirst($stage) : '')
                               . ' saved as version ' . $version
                               . ($version > 1 ? '. Version ' . ($version - 1) . ' is kept and marked superseded.' : '.')
                               . $attached;

            /* Back where the upload started from, not to a list they then
               have to navigate out of. */
            if ($target) {
                redirect(($target['kind'] === 'pay' ? 'shipment_payments.php?id=' : 'shipment_costs.php?id=') . $id);
            }
            redirect('shipment_documents.php?id=' . $id);
        }

        if ($action === 'import_legacy') {
            exp_require('documents', 'c');
            [$ok, $msg] = exp_import_legacy_file(
                $id,
                (int)($_POST['file_id'] ?? 0),
                (int)($_POST['doc_type_id'] ?? 0),
                ($_POST['stage'] ?? '') !== '' ? (string)$_POST['stage'] : null,
                !empty($_POST['to_r2'])
            );
            if (!$ok) throw new Exception($msg);
            $_SESSION['flash'] = $msg;
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
$legacy  = exp_legacy_files($id);

/* Set when the page was opened by an Attach button on a payment or cost row.
   Verified against this shipment, so an invented value simply comes back
   null and the page behaves as an ordinary upload. */
$target  = exp_attach_target($id, (string)($_GET['for'] ?? ''));
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

/* What the text extractor made of each of these files, read in one query
   rather than one per row. */
$textStatus = txt_document_status(array_map(fn($d) => (int)$d['id'], $docs));

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

          <?php /* WHETHER THIS FILE IS SEARCHABLE, AND IF NOT, WHY.
                   A scan holds no text. Saying so here is the difference
                   between "the search is broken" and "there was never
                   anything in that file to find". */
                $xs = $textStatus[(int)$d['id']] ?? null;
                if ($xs !== null):
                  $st = (string)$xs['extract_status']; ?>
            <br><?php if ($st === 'ok'): ?>
              <span class="xpill g">Searchable</span>
              <span style="color:#8a97ab"><?= number_format((int)$xs['chars']) ?> characters read</span>
            <?php else: ?>
              <span class="xpill o"><?= e(DOCTEXT_STATUS[$st] ?? $st) ?></span>
              <?php if (trim((string)$xs['extract_note']) !== ''): ?>
                <span style="color:#8a97ab"><?= e($xs['extract_note']) ?></span>
              <?php endif; ?>
            <?php endif;
                endif; ?>

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

<?php
$pending = array_values(array_filter($legacy, fn($f) => empty($f['imported_as'])));
if ($legacy): ?>
<div class="dgroup" style="margin-top:14px">
  <div class="dhead">
    <div>
      <div class="n">Files attached before the document system</div>
      <div class="dmeta">
        <?= count($legacy) ?> file<?= count($legacy) === 1 ? '' : 's' ?>
        <?php if ($pending): ?> &middot; <?= count($pending) ?> not yet given a type<?php endif; ?>
      </div>
    </div>
    <span class="xpill <?= $pending ? 'o' : 'g' ?>"><?= $pending ? 'Needs a type' : 'All brought in' ?></span>
  </div>

  <?php foreach ($legacy as $f): $done = !empty($f['imported_as']); ?>
    <div class="drow <?= $done ? 'old' : '' ?>">
      <div style="min-width:0;flex:1">
        <div style="font-size:12.5px;word-break:break-all">
          <?= e($f['original_name']) ?>
          <?php if ($done): ?><span class="xpill g" style="margin-left:5px">brought in</span><?php endif; ?>
        </div>
        <div class="dmeta">
          <?= e(exp_size_h($f['file_size'])) ?>
          <?= $f['created_at'] ? ' &middot; ' . e(date('d M Y', strtotime((string)$f['created_at']))) : '' ?>
          &middot; on this server
        </div>

        <?php if (!$done && $canUpload): ?>
        <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:7px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import_legacy">
          <input type="hidden" name="shipment_id" value="<?= $id ?>">
          <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
          <select name="doc_type_id" required class="xin" style="width:auto;min-width:150px;margin-top:0;padding:5px 8px;font-size:11.5px">
            <option value="">Give it a type…</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" data-stage="<?= exp_master_flag($t, 'supports_draft_final') ? 1 : 0 ?>"><?= e($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="stage" class="xin" style="width:auto;margin-top:0;padding:5px 8px;font-size:11.5px">
            <option value="">—</option>
            <option value="draft">Draft</option>
            <option value="final">Final</option>
          </select>
          <?php if (exp_r2_configured()): ?>
            <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:#5a6b82">
              <input type="checkbox" name="to_r2" value="1" checked style="width:14px;height:14px;accent-color:#0ea8c9"> copy to R2
            </label>
          <?php endif; ?>
          <button class="xbtn sm">Bring in</button>
        </form>
        <?php endif; ?>
      </div>
      <div style="white-space:nowrap">
        <a class="xbtn sec sm" href="download_file.php?id=<?= (int)$f['id'] ?>">Download</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="xnote" style="margin-bottom:12px">
  These came from the old <b>Files</b> button, before documents had types and versions.
  Give one a type and it joins the list above as a proper document, with a version and its original
  date kept.
  <b>Nothing is deleted or moved</b> — the old record and the file itself stay exactly as they are,
  and this download keeps working either way. Bringing a file in twice is refused.
</div>
<?php endif; ?>

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

  <?php if ($target): ?>
    <div class="xnote" style="margin-bottom:12px;border-color:#0ea8c9;background:rgba(14,168,201,.08)">
      <b>Attaching to the <?= e($target['label']) ?>.</b>
      When this uploads it is linked to that row, and you are taken back to it.
      <a href="shipment_documents.php?id=<?= $id ?>" style="color:#0ea8c9;font-weight:600;margin-left:6px">Upload without attaching</a>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="upForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <?php if ($target): ?><input type="hidden" name="for" value="<?= e($target['kind'] . ':' . $target['id']) ?>"><?php endif; ?>

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
