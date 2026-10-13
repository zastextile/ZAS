<?php
/* DELETING A SHIPMENT DOCUMENT — only with an admin's password.
 *
 * afnan: "shipment_documents.php can you allow delete documents but with
 * admin password".
 *
 *   1  The password is checked against every ACTIVE ADMIN, and nobody else:
 *      a colleague's own password, an inactive admin's, or an empty box
 *      does not delete anything.
 *   2  The delete is behind the CSRF check and the same permission as
 *      Archive, needs a reason, locks after five wrong passwords, is in the
 *      history with the approving admin's name, closes the version chain,
 *      unlinks payment/cost proofs, and keeps a file the old Files list
 *      still uses.
 * (The whole flow — upload, wrong password, delete a middle and a current
 * version, lockout, shared legacy file — was driven in Chromium against
 * the MariaDB copy when this was built.)
 */
$src = (string)file_get_contents(__DIR__ . '/app_src/public_html/shipment_documents.php');
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; } else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

/* the real function, lifted out of the page */
preg_match('~function exp_doc_admin_for_password\(.*?\n}\n~s', $src, $m);
ok(isset($m[0]), 'the page has exp_doc_admin_for_password()');
function db() {
    static $p = null;
    if ($p === null) {
        $p = new PDO('sqlite::memory:');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, is_active INT, password_hash TEXT)");
        $ins = $p->prepare("INSERT INTO users VALUES (?,?,?,?,?)");
        $ins->execute([1, 'Afnan', 'admin', 1, password_hash('admin-one', PASSWORD_DEFAULT)]);
        $ins->execute([2, 'Sara', 'admin', 1, password_hash('admin-two', PASSWORD_DEFAULT)]);
        $ins->execute([3, 'Ali', 'colleague', 1, password_hash('colleague-pw', PASSWORD_DEFAULT)]);
        $ins->execute([4, 'Old', 'admin', 0, password_hash('retired-admin', PASSWORD_DEFAULT)]);
    }
    return $p;
}
if (isset($m[0])) eval('?>' . "<?php\n" . $m[0]);

head('1. Only an active admin\'s password');
ok((exp_doc_admin_for_password('admin-one')['name'] ?? '') === 'Afnan', 'an admin\'s own password is accepted');
ok((exp_doc_admin_for_password('admin-two')['name'] ?? '') === 'Sara', 'another admin\'s too — the person deleting need not be the admin');
ok(exp_doc_admin_for_password('colleague-pw') === null, 'a colleague\'s password is not enough');
ok(exp_doc_admin_for_password('retired-admin') === null, 'nor an inactive admin\'s');
ok(exp_doc_admin_for_password('') === null && exp_doc_admin_for_password('admin-one ') === null, 'nor an empty box, nor a near miss');

head('2. The guards around it');
$del = substr($src, (int)strpos($src, "if (\$action === 'delete')"), 5000);
ok(strpos($src, 'verify_csrf();') !== false && strpos($src, 'verify_csrf();') < strpos($src, "if (\$action === 'delete')"), 'every POST passes the CSRF check before the delete');
ok(str_contains($del, "exp_require('documents', 'd');"), 'it needs the same permission as Archive');
ok(str_contains($del, "if (\$reason === '') throw"), 'a reason is required');
ok(str_contains($del, "\$lock['n'] >= 5") && str_contains($del, 'time() + 900'), 'five wrong passwords lock it for 15 minutes');
ok(strpos($del, 'exp_doc_admin_for_password') < strpos($del, 'DELETE FROM shipment_documents'), 'the password is checked before anything is deleted');
ok(str_contains($del, "'delete refused'"), 'a wrong password is written to the history');
ok(str_contains($del, "approved with the admin password of ' . \$admin['name']"), 'the delete is in the history with the approving admin');
ok(str_contains($del, 'SET superseded_by=? WHERE shipment_id=? AND superseded_by=?'), 'the version chain closes over the gap');
ok(str_contains($del, 'proof_doc_id') && str_contains($del, "'shipment_costs', 'doc_id'"), 'a payment or cost proof link is cleared, not left dangling');
ok(str_contains($del, 'FROM shipment_files WHERE stored_name=?') && str_contains($del, '$shared'), 'a file the old Files list still uses is kept');
ok(str_contains($del, 'exp_r2_delete($key)') && str_contains($del, '@unlink($path)'), 'otherwise the file goes, from R2 or from the disk');
ok(str_contains($src, 'type="password" name="admin_password"') && str_contains($src, 'autocomplete="new-password"'),
   'the box is a password box the browser does not fill in by itself');

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
