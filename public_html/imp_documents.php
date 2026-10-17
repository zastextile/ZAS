<?php
/*
  IMPORT — DOCUMENTS TAB.

  The same rules as the export Documents tab: stored on Cloudflare R2 (or
  this server's disk when R2 is not set up); uploading the same type again
  makes the next version and keeps the old one, marked superseded; a wrong
  upload is archived with a reason; deleting for good needs an ADMIN'S
  password (five wrong in a row lock it for 15 minutes). A document can be
  linked to one invoice line — a test report, a specification.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('documents');
$id  = (int)$imp['id'];
const IMP_DOC_EXT = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'zip'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            imp_require('documents', 'c');
            $tid = (int)($_POST['doc_type_id'] ?? 0);
            $tmap = exp_master_map('imp_doc_type');
            if (!isset($tmap[$tid])) throw new Exception('Choose a document type.');
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
                throw new Exception(($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is larger than the server allows.' : 'Upload failed — please try again.');
            global $config;
            $maxMb = (int)($config['max_upload_mb'] ?? 20);
            if ($_FILES['file']['size'] > $maxMb * 1048576) throw new Exception('File is larger than ' . $maxMb . ' MB.');
            if ($_FILES['file']['size'] <= 0) throw new Exception('That file is empty.');
            $orig = basename((string)$_FILES['file']['name']);
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, IMP_DOC_EXT, true)) throw new Exception('That file type is not accepted. Allowed: ' . implode(', ', IMP_DOC_EXT) . '.');
            $lineId = (int)($_POST['line_id'] ?? 0);
            if ($lineId && !in_array($lineId, array_map(fn($l) => (int)$l['id'], imp_lines($id)), true)) $lineId = 0;
            [$ok, $driver, $key, $err] = imp_store_upload($id, (string)$_FILES['file']['tmp_name'], $orig, (string)($_FILES['file']['type'] ?? ''));
            if (!$ok) throw new Exception('The document was not stored: ' . $err);
            $v = db()->prepare("SELECT COALESCE(MAX(version),0)+1 FROM imp_documents WHERE import_id=? AND doc_type_id=?");
            $v->execute([$id, $tid]); $version = (int)$v->fetchColumn();
            db()->beginTransaction();
            db()->prepare("INSERT INTO imp_documents (import_id, doc_type_id, line_id, doc_no, version, original_name, storage_driver, storage_key, mime_type, file_size, notes, uploaded_by, uploaded_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$id, $tid, $lineId ?: null, mb_substr(trim((string)($_POST['doc_no'] ?? '')), 0, 120) ?: null, $version, $orig, $driver, $key,
                           (string)($_FILES['file']['type'] ?? ''), (int)$_FILES['file']['size'], mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300) ?: null, (int)(current_user()['id'] ?? 0)]);
            $new = (int)db()->lastInsertId();
            db()->prepare("UPDATE imp_documents SET superseded_by=? WHERE import_id=? AND doc_type_id=? AND id<>? AND superseded_by IS NULL AND is_archived=0")->execute([$new, $id, $tid, $new]);
            db()->commit();
            imp_log($id, 'Document uploaded', $tmap[$tid] . ' V' . $version . ' — ' . $orig . ' (' . strtoupper($driver) . ')');
            $_SESSION['flash'] = $tmap[$tid] . ' saved as version ' . $version . ($version > 1 ? '. Version ' . ($version - 1) . ' is kept and marked superseded.' : '.');
        }
        if ($action === 'archive') {
            imp_require('documents', 'd');
            $did = (int)($_POST['doc_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is required to archive a document.');
            $r = db()->prepare("SELECT * FROM imp_documents WHERE id=? AND import_id=?"); $r->execute([$did, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('Document not found on this import.');
            db()->prepare("UPDATE imp_documents SET is_archived=1, archive_reason=?, archived_by=?, archived_at=NOW() WHERE id=? AND import_id=?")->execute([mb_substr($reason, 0, 300), (int)(current_user()['id'] ?? 0), $did, $id]);
            imp_log($id, 'Document archived', $row['original_name'], $reason);
            $_SESSION['flash'] = 'Document archived. The file is still stored and downloadable from "Show archived".';
        }
        if ($action === 'delete') {
            imp_require('documents', 'd');
            $did = (int)($_POST['doc_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is required to delete a document.');
            $lock = $_SESSION['imp_doc_del_lock'] ?? ['n' => 0, 'until' => 0];
            if ((int)$lock['until'] > time()) throw new Exception('Too many wrong passwords. Try again in ' . max(1, (int)ceil(((int)$lock['until'] - time()) / 60)) . ' minutes. Nothing was deleted.');
            $admin = imp_admin_for_password((string)($_POST['admin_password'] ?? ''));
            if (!$admin) {
                $lock['n'] = (int)$lock['n'] + 1;
                if ($lock['n'] >= 5) $lock = ['n' => 0, 'until' => time() + 900];
                $_SESSION['imp_doc_del_lock'] = $lock;
                imp_log($id, 'Document delete refused', '#' . $did, 'Wrong admin password');
                throw new Exception('That is not an admin password — the document was NOT deleted.');
            }
            unset($_SESSION['imp_doc_del_lock']);
            $r = db()->prepare("SELECT * FROM imp_documents WHERE id=? AND import_id=?"); $r->execute([$did, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('Document not found on this import.');
            db()->beginTransaction();
            db()->prepare("UPDATE imp_documents SET superseded_by=? WHERE import_id=? AND superseded_by=?")->execute([$row['superseded_by'] ?: null, $id, $did]);
            db()->prepare("DELETE FROM imp_documents WHERE id=? AND import_id=?")->execute([$did, $id]);
            imp_log($id, 'Document deleted', $row['original_name'] . ' V' . $row['version'], $reason . ' — approved with the admin password of ' . $admin['name']);
            db()->commit();
            $note = '';
            if ((string)$row['storage_driver'] === 'r2') {
                [$okDel, $errDel] = exp_r2_delete((string)$row['storage_key']);
                if (!$okDel) $note = ' The file could not be removed from R2 (' . $errDel . ').';
            } else {
                global $config;
                $path = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/storage/uploads')), '/') . '/' . basename((string)$row['storage_key']);
                if (is_file($path) && !@unlink($path)) $note = ' The file could not be removed from the server disk.';
            }
            $_SESSION['flash'] = 'Deleted ' . $row['original_name'] . '.' . $note;
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_documents.php?id=' . $id . (!empty($_POST['archived']) ? '&archived=1' : ''));
}

$showArch = !empty($_GET['archived']);
$docs = array_values(array_filter(imp_rows('imp_documents', $id, 'doc_type_id, version DESC'), fn($d) => $showArch || (int)$d['is_archived'] === 0));
$tmap = exp_master_map('imp_doc_type');
$lineName = []; foreach (imp_lines($id) as $l) $lineName[(int)$l['id']] = $l['description'];
$groups = []; foreach ($docs as $d) $groups[(int)$d['doc_type_id']][] = $d;
$canUp = imp_can('documents', 'c'); $canDel = imp_can('documents', 'd');
$size = static fn($b) => $b < 1024 ? (int)$b . ' B' : ($b < 1048576 ? round($b / 1024) . ' KB' : round($b / 1048576, 1) . ' MB');

page_header('Documents — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'docs');
?>
<div class="xcard" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
  <h2 style="margin:0">Import documents</h2>
  <div style="display:flex;gap:8px;align-items:center"><span class="imp-small imp-muted">Stored on <?= exp_r2_configured() ? 'Cloudflare R2' : 'this server' ?></span>
    <a class="xbtn sec sm" href="imp_documents.php?id=<?= $id ?><?= $showArch ? '' : '&archived=1' ?>"><?= $showArch ? 'Hide archived' : 'Show archived' ?></a></div>
</div>
<?php if (!$groups): ?><div class="xcard"><div class="xnote">No documents on this import yet.</div></div><?php endif; ?>
<?php foreach ($groups as $tid => $list): $cur = $list[0]; ?>
<div class="xcard" style="padding:0;overflow:hidden">
  <div style="padding:10px 14px;background:#f6f8fc;display:flex;justify-content:space-between;align-items:center"><b style="font-size:13px"><?= e($tmap[$tid] ?? 'Document') ?></b>
    <span class="xpill <?= (int)$cur['is_archived'] === 1 ? 'o' : 'g' ?>"><?= (int)$cur['is_archived'] === 1 ? 'Archived' : 'Current: V' . (int)$cur['version'] ?></span></div>
  <?php foreach ($list as $d): ?>
    <div style="padding:9px 14px;border-top:1px solid #f0f3f8;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div style="min-width:0"><div style="font-size:12.5px;font-weight:600;word-break:break-all">V<?= (int)$d['version'] ?> · <?= e($d['original_name']) ?>
          <?= !empty($d['superseded_by']) ? '<span class="xpill o" style="margin-left:5px">superseded</span>' : '' ?><?= (int)$d['is_archived'] === 1 ? '<span class="xpill r" style="margin-left:5px">archived</span>' : '' ?></div>
        <div class="imp-small imp-muted"><?= $d['doc_no'] ? 'No. ' . e($d['doc_no']) . ' · ' : '' ?><?= e($size((int)$d['file_size'])) ?> · <?= e(imp_dmy($d['uploaded_at'] ? substr($d['uploaded_at'], 0, 10) : null)) ?> · <?= e(strtoupper($d['storage_driver'])) ?>
          <?= $d['line_id'] && isset($lineName[(int)$d['line_id']]) ? ' · line: ' . e($lineName[(int)$d['line_id']]) : '' ?><?= $d['notes'] ? '<br>' . e($d['notes']) : '' ?>
          <?= (int)$d['is_archived'] === 1 && $d['archive_reason'] ? '<br><span style="color:#b8283f">Archived: ' . e($d['archive_reason']) . '</span>' : '' ?></div></div>
      <div style="display:flex;gap:6px;white-space:nowrap">
        <a class="xbtn sec sm" href="imp_doc_file.php?doc=<?= (int)$d['id'] ?>">Download</a>
        <?php if ($canDel && (int)$d['is_archived'] === 0): ?><button type="button" class="xbtn red sm" onclick="docAct('archive',<?= (int)$d['id'] ?>,<?= e(json_encode($d['original_name'])) ?>)">Archive</button><?php endif; ?>
        <?php if ($canDel): ?><button type="button" class="xbtn sec sm" style="color:#b8283f" onclick="docAct('delete',<?= (int)$d['id'] ?>,<?= e(json_encode('V' . $d['version'] . ' · ' . $d['original_name'])) ?>)">Delete</button><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($canUp): ?>
<form class="xcard" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="import_id" value="<?= $id ?>">
  <h2>Upload a document</h2>
  <?php if (!exp_r2_configured() && is_admin()): ?><div class="xwarn" style="margin-bottom:12px">R2 is not configured, so this file goes to the web server's disk. <a href="exp_settings.php?tab=storage" style="color:#9a5a06;font-weight:700">Switch on R2</a> to keep documents off the hosting disk.</div><?php endif; ?>
  <div class="xgrid">
    <label class="xlabel">Document type<select class="xin" name="doc_type_id" required><option value="">—</option><?php foreach (exp_masters('imp_doc_type') as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['label']) ?></option><?php endforeach; ?></select></label>
    <label class="xlabel">For invoice line (optional)<select class="xin" name="line_id"><option value="0">— the whole import —</option><?php foreach ($lineName as $lid => $ln): ?><option value="<?= (int)$lid ?>"><?= e($ln) ?></option><?php endforeach; ?></select></label>
    <?= imp_input('Document no. (optional)', 'doc_no', '') ?>
    <label class="xlabel xspan2">File<input class="xin" type="file" name="file" required accept=".<?= implode(',.', IMP_DOC_EXT) ?>"></label>
    <?= imp_input('Notes', 'notes', '') ?>
  </div>
  <div style="margin-top:12px"><button class="xbtn">Upload</button></div>
  <div class="xnote imp-small" style="margin-top:10px">Uploading the same type again makes the next version; the older one is kept and marked superseded — nothing is overwritten.</div>
</form>
<?php endif; ?>

<?php if ($canDel): ?>
<div id="dModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <form method="post" autocomplete="off" style="background:#fff;border-radius:16px;padding:22px;max-width:430px;width:100%;border:1px solid #e3e9f2">
    <?= csrf_field() ?><input type="hidden" name="action" id="dAct"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="doc_id" id="dId">
    <?php if ($showArch): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <h2 style="font-size:15px;margin:0 0 7px;color:#b8283f"><span id="dTitle"></span> <span id="dName" style="word-break:break-all"></span>?</h2>
    <p class="imp-small imp-muted" id="dText" style="line-height:1.55;margin:0 0 12px"></p>
    <label class="xlabel">Reason <span style="color:#b8283f">*</span><input class="xin" name="reason" id="dReason" required></label>
    <label class="xlabel" id="dPassWrap" style="margin-top:10px">Admin password <span style="color:#b8283f">*</span><input class="xin" type="password" name="admin_password" id="dPass" autocomplete="new-password"></label>
    <div style="display:flex;gap:9px;margin-top:15px"><button type="button" class="xbtn sec" style="flex:1" onclick="document.getElementById('dModal').style.display='none'">Cancel</button><button class="xbtn red" style="flex:1" id="dGo"></button></div>
  </form>
</div>
<script>
function docAct(act, id, name) {
  var del = act === 'delete';
  document.getElementById('dAct').value = act; document.getElementById('dId').value = id; document.getElementById('dName').textContent = name;
  document.getElementById('dTitle').textContent = del ? 'Delete' : 'Archive';
  document.getElementById('dText').innerHTML = del ? 'This <b>permanently deletes</b> the document and its stored file. An <b>admin\'s password</b> is needed.' : 'The file is <b>not deleted</b>: it leaves the normal list and stays downloadable under "Show archived".';
  document.getElementById('dPassWrap').style.display = del ? '' : 'none'; document.getElementById('dPass').required = del; document.getElementById('dPass').value = '';
  document.getElementById('dGo').textContent = del ? 'Delete permanently' : 'Archive';
  document.getElementById('dReason').value = ''; document.getElementById('dModal').style.display = 'flex'; document.getElementById('dReason').focus();
}
</script>
<?php endif; ?>
<?php page_footer(); ?>
