<?php
/*
  THE ONLY WAY A DOCUMENT COMES BACK OUT.

  Three checks before a single byte is sent, and they are in this order on
  purpose: logged in, allowed to see this shipment, allowed to see documents.
  There is no public path to a stored file, no predictable URL and no signed
  link — the object key never leaves the server, and the bytes are streamed
  through here after the checks pass.

  This file exists separately from download_file.php because that one serves
  the old shipment_files rows and must keep working exactly as it does.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/storage.php';

require_login();
exp_ensure_schema();

$docId = (int)($_GET['doc'] ?? 0);
if ($docId <= 0) { http_response_code(404); exit('No document asked for.'); }

$st = db()->prepare("SELECT * FROM shipment_documents WHERE id=?");
$st->execute([$docId]);
$doc = $st->fetch();

/* A missing document and one belonging to a shipment this user may not see
   give the same answer. Telling the two apart would confirm the document
   exists to someone who should not know that. */
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$shipmentId = (int)$doc['shipment_id'];
if (!can_view_shipment($shipmentId)) { http_response_code(404); exit('Document not found.'); }
if (!exp_can('documents', 'v')) { http_response_code(403); exit('You do not have permission to open shipment documents.'); }

/* Reading a document is worth recording. Who downloaded the final BL, and
   when, is exactly the question asked after something goes wrong. */
try { audit_log($shipmentId, 'Document', 'download', '', (string)$doc['original_name'], 'Document downloaded'); }
catch (Throwable $e) {}

exp_send_document($doc);
