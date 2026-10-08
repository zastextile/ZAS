<?php
/*
  Sends one gate photograph.

  A page, not a link to a file. The photos live outside the web root (or in
  R2, which has no public bucket), so the only way to one is through here,
  and here checks permission first. A predictable URL to an object store is
  a password written on the envelope.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/mobile.php';
require_login();

/* Anyone who may look at the gate module may look at its photos. Creating
   a pass is a stronger right than seeing one, so 'view' is enough. */
if (!inv_perm('view') && !inv_perm('gate') && !inv_perm('master')) {
    http_response_code(403); exit('Not permitted.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('No photo asked for.'); }

try {
    $s = db()->prepare("SELECT p.* FROM inv_gate_photos p JOIN inv_gate g ON g.id = p.gate_id WHERE p.id = ?");
    $s->execute([$id]);
    $p = $s->fetch();
} catch (Throwable $e) { $p = null; }

if (!$p) { http_response_code(404); exit('Photo not found.'); }

mob_photo_send($p);
