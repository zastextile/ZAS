<?php
/*
  IMPORT DOCUMENT DOWNLOAD — the bytes come back only through here, after the
  permission check, exactly like shipment_doc_file.php. No public URL exists
  for any import document, on R2 or on this server.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('imp', 'v');
imp_require('documents', 'v');

$s = db()->prepare("SELECT * FROM imp_documents WHERE id=?");
$s->execute([(int)($_GET['doc'] ?? 0)]);
$doc = $s->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }
exp_send_document($doc);
