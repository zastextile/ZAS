<?php
/*
  THE MOBILE DOOR.

  "can open by user simple using login password but can see just the
   mobile page whatever allowed access to him instead using whole app
   with sidebar full menu"

  One link, given to the people who work on a phone. They sign in with
  the account they already have — the same email, the same password, the
  same permissions — and land here instead of on the dashboard. What they
  see is the phone screens that login is allowed to open, and nothing
  else: no sidebar, no desktop menu, no shipment list, no rates.

  THIS IS NOT A SECOND SET OF PERMISSIONS. It is the same ones, read the
  same way. Hiding a tile is a convenience; every screen behind it still
  checks for itself, so a typed URL is refused exactly as it always was.
  Nothing here grants anything.

  HOW THE LINK WORKS WITHOUT A SESSION. require_login() sends an m_*
  page to login.php carrying where it was going, and login.php sends the
  user straight back once they are in. So the bookmark is the whole
  journey: tap it, sign in, arrive. The desktop is never seen.
*/
require_once __DIR__ . '/includes/bootstrap.php';
/* These two are what answer "does this tile lead anywhere" —
   inventory.php defines inv_perm() for the gate, packing.php defines
   pack_may_use(). Without either, mob_screens() quietly drops that tile
   and nobody can tell why. */
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/packing.php';
require_once __DIR__ . '/includes/mobile.php';
require_login();

$u       = current_user() ?: [];
$screens = mob_screens();

/* The shell already prints who is signed in, on the right of the bar.
   Saying it again here, and a third time under the title, is just the
   same name three times on a small screen. */
mob_header('ZAS Mobile', '', '', 'manifest_m.json');
mob_flash();
?>
<div class="hello">
  <b>Your phone screens</b>
  <span>Only the ones your account is allowed to open are shown here.</span>
</div>

<?php if (!$screens): ?>
  <div class="empty">
    There is no phone screen open to your account yet.<br><br>
    Ask an admin for Gate access on Administration &rsaquo; User Access,
    or sign in on a computer for the full app.
  </div>
<?php else: ?>
  <div class="tiles">
    <?php foreach ($screens as $s): ?>
      <a class="tile t-<?= e($s['tone']) ?>" href="<?= e($s['href']) ?>">
        <span class="ic" aria-hidden="true"><?php
          /* Plain inline shapes. No icon font, no sprite file, nothing to
             fetch — the point of this page is that it opens instantly on
             a bad signal. */
          if ($s['tone'] === 'in') {
              echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                 . ' stroke-linecap="round" stroke-linejoin="round">'
                 . '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 20h16"/></svg>';
          } elseif ($s['tone'] === 'out') {
              echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                 . ' stroke-linecap="round" stroke-linejoin="round">'
                 . '<path d="M12 18V6"/><path d="M7 11l5-5 5 5"/><path d="M4 21h16"/></svg>';
          } else {
              echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
                 . ' stroke-linecap="round" stroke-linejoin="round">'
                 . '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>';
          }
        ?></span>
        <span class="tx"><b><?= e($s['label']) ?></b><small><?= e($s['sub']) ?></small></span>
        <span class="go" aria-hidden="true">&rsaquo;</span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="foot">
  <a class="btn sec" href="logout.php">Sign out</a>
  <p class="note">On a computer? <a href="dashboard.php">Open the full app</a>.</p>
  <p class="note">Add this page to your home screen and it opens like an app.</p>
</div>

<style>
.hello{background:#fff;border:1px solid var(--line);border-radius:14px;padding:13px 14px;margin-bottom:14px}
.hello b{display:block;font-size:14px}
.hello span{display:block;font-size:12px;color:var(--muted);margin-top:2px}
.tiles{display:flex;flex-direction:column;gap:11px}
.tile{display:flex;align-items:center;gap:13px;padding:16px 15px;border-radius:16px;
  text-decoration:none;color:#fff;min-height:78px;box-shadow:0 8px 20px rgba(15,39,66,.1)}
.t-in{background:linear-gradient(100deg,#0ea8c9,#0b7fa0)}
.t-out{background:linear-gradient(100deg,#6d5bd0,#4c3fa8)}
.t-pack{background:linear-gradient(100deg,#0f766e,#115e59)}
.tile .ic{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;
  background:rgba(255,255,255,.18);border-radius:11px}
.tile .ic svg{width:21px;height:21px}
.tile .tx{flex:1;min-width:0}
.tile .tx b{display:block;font-size:17px;font-weight:700}
.tile .tx small{display:block;font-size:12px;opacity:.85;margin-top:1px}
.tile .go{font-size:24px;opacity:.75}
.tile:active{transform:scale(.99)}
.foot{margin-top:22px}
.foot .note{text-align:center;margin:12px 0 0}
.foot .note a{color:var(--cyan);font-weight:700;text-decoration:none}
</style>
<?php mob_footer(); ?>
