<?php
/*
  The Phase 1 placeholder for the CUSTOMS and GATE IN tabs. Both are real
  pages now (imp_customs.php, imp_gatein.php); this only forwards an old
  link or bookmark to the right one.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
redirect((($_GET['tab'] ?? '') === 'gatein' ? 'imp_gatein.php' : 'imp_customs.php') . '?id=' . (int)$imp['id']);
