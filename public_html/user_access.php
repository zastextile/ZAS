<?php
/* Administration > User Access.
 *
 * One screen for everything a person may do, and when. What replaced
 * what, and why, is written at the top of includes/access.php.
 *
 * Admin only. An admin's own rights are not editable here — there is
 * nothing to express (admins can do everything) and a half-saved admin
 * is a locked-out admin. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admin access required.'); }
zu_ensure_schema();

$MODULES = zu_modules();
$ACTIONS = zu_actions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)($_POST['user_id'] ?? 0);

    if (($_POST['action'] ?? '') === 'save' && $uid > 0) {
        /* The grid arrives as p[module][] = letter, which is what a set of
           checkboxes naturally posts. Absent means unticked — a module
           with nothing ticked simply is not in the array, and comes out
           of the loop below as an empty string, which zu_save_perms()
           then removes. */
        $map = [];
        foreach ($MODULES as $m) {
            $got = (array)($_POST['p'][$m['k']] ?? []);
            $map[$m['k']] = implode('', array_filter(str_split('vcudpr'),
                fn($a) => in_array($a, $got, true)));
        }
        $r = zu_save_perms($uid, $map, (int)(current_user()['id'] ?? 0));
        if (!$r['ok']) { $_SESSION['error'] = $r['error']; redirect('user_access.php?u=' . $uid); }

        $w = zu_save_window($uid, [
            'from'    => (string)($_POST['acc_from'] ?? ''),
            'until'   => (string)($_POST['acc_until'] ?? ''),
            'days'    => implode('', (array)($_POST['acc_days'] ?? [])),
            't1'      => (string)($_POST['acc_t1'] ?? ''),
            't2'      => (string)($_POST['acc_t2'] ?? ''),
            'outside' => (string)($_POST['acc_outside'] ?? 'readonly'),
        ], (int)(current_user()['id'] ?? 0));
        if (!$w['ok']) { $_SESSION['error'] = $w['error']; redirect('user_access.php?u=' . $uid); }

        /* Written to the audit log because a permission change is the one
           edit that should never be anonymous. */
        if (function_exists('inv_audit'))
            inv_audit('user_access', $uid, ['modules' => $r['modules']], 'Access updated');
        $_SESSION['flash'] = 'Access saved — ' . $r['modules'] . ' area(s).';
        redirect('user_access.php?u=' . $uid);
    }
}

/* ------------------------------------------------------------------ read */
$users = [];
try {
    $users = db()->query("SELECT id, name, email, role, is_active,
               acc_from, acc_until, acc_days, acc_t1, acc_t2, acc_outside, acc_seeded
          FROM users WHERE role <> 'customer' ORDER BY is_active DESC, name")->fetchAll();
} catch (Throwable $e) {}

/* Seed anyone who has never been seen by the new system, so the grid
   shows what they can do today rather than an empty page. */
foreach ($users as $u) if ((int)($u['acc_seeded'] ?? 0) !== 1) {
    try { $s = db()->prepare("SELECT * FROM users WHERE id=?"); $s->execute([(int)$u['id']]);
          $full = $s->fetch(); if ($full) zu_seed_user($full); } catch (Throwable $e) {}
}
try {
    $users = db()->query("SELECT id, name, email, role, is_active,
               acc_from, acc_until, acc_days, acc_t1, acc_t2, acc_outside
          FROM users WHERE role <> 'customer' ORDER BY is_active DESC, name")->fetchAll();
} catch (Throwable $e) {}

$sel = (int)($_GET['u'] ?? 0);
$cur = null;
foreach ($users as $u) if ((int)$u['id'] === $sel) { $cur = $u; break; }
if (!$cur && $users) { $cur = $users[0]; $sel = (int)$cur['id']; }
$perm = $cur ? zu_perm_map($sel, true) : [];
$isAdminUser = $cur && $cur['role'] === 'admin';

$ROLE = ['admin'=>'Admin','colleague'=>'Colleague','staff'=>'Staff','production_staff'=>'Production Staff'];
$DAYN = ['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'];

function ua_initials(string $n): string {
    $p = preg_split('/\s+/', trim($n));
    return strtoupper(substr($p[0] ?? '?', 0, 1) . (count($p) > 1 ? substr(end($p), 0, 1) : ''));
}

page_header('User Access');
?>
<style>
.ua-card{background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:16px 18px;margin-bottom:14px}
.ua-h{font-size:13px;font-weight:800;color:#152033;margin:0 0 2px}
.ua-sub{font-size:11.5px;color:#8a97ab;margin:0 0 10px;line-height:1.55}
.ua-find{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e3;border-radius:9px;font-size:12.5px;font-family:inherit;margin-bottom:8px}
.ua-u{display:flex;gap:9px;align-items:center;padding:6px 7px;border-radius:9px;cursor:pointer;border:1px solid transparent;text-decoration:none;color:inherit}
.ua-u:hover{background:#f7f9fc}
.ua-u.on{background:rgba(14,168,201,.08);border-color:rgba(14,168,201,.25)}
.ua-av{width:28px;height:28px;border-radius:8px;background:#f0f3f9;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:800;color:#5a6b82;flex:none}
.ua-u.on .ua-av{background:#0ea8c9;color:#fff}
.ua-un{font-size:12.5px;font-weight:700;color:#152033;line-height:1.25}
.ua-ur{font-size:10px;color:#8a97ab}
.ua-off{opacity:.5}
.ua-dot{width:7px;height:7px;border-radius:99px;flex:none;margin-left:auto}
.d-now{background:#16a34a}.d-ro{background:#d97706}.d-no{background:#c0293f}.d-off{background:#cbd5e3}
.ua-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ua-tbl th{background:#f7f9fc;color:#5a6b82;font-size:10.5px;font-weight:800;text-align:center;padding:5px 6px;border-bottom:1px solid #e3e9f2;white-space:nowrap}
.ua-tbl th.l{text-align:left}
.ua-tbl td{padding:4px 6px;text-align:center;border-bottom:1px solid #eef1f7}
.ua-tbl td.l{text-align:left}
.ua-grp td{background:#f7f9fc;font-size:10px;font-weight:800;letter-spacing:.05em;color:#8a97ab;text-transform:uppercase;text-align:left}
.ua-tbl .na{color:#cbd5e3}
.ua-tbl th.dcol,.ua-tbl td.dcol{background:rgba(224,67,93,.04)}
.ua-tbl th.dcol{color:#c0293f}
.ua-cb{width:15px;height:15px;accent-color:#0ea8c9;cursor:pointer;margin:0}
.ua-cb.dl{accent-color:#c0293f}
.ua-all{font-size:10px;color:#0b5f8a;cursor:pointer;font-weight:700;background:none;border:none;padding:0;font-family:inherit}
.ua-all:hover{text-decoration:underline}
.ua-when{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.ua-f{display:flex;flex-direction:column;gap:4px}
.ua-f>label{font-size:10px;font-weight:800;letter-spacing:.05em;color:#8a97ab}
.ua-in{box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e3;border-radius:9px;font-size:12.5px;font-family:inherit}
.ua-days{display:flex;gap:4px;flex-wrap:wrap}
.ua-day{position:relative}
.ua-day input{position:absolute;opacity:0;pointer-events:none}
.ua-day span{display:inline-flex;align-items:center;justify-content:center;width:42px;height:34px;border:1px solid #cbd5e3;border-radius:9px;font-size:11.5px;font-weight:700;color:#8a97ab;cursor:pointer;background:#fff}
.ua-day input:checked + span{background:#0ea8c9;border-color:#0ea8c9;color:#fff}
.ua-day input:disabled + span{opacity:.5;cursor:not-allowed}
.ua-radio{display:flex;gap:14px;align-items:center;font-size:12.5px;color:#5a6b82;min-height:34px}
.ua-radio label{display:inline-flex;gap:6px;align-items:center;cursor:pointer}
.ua-say{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);border-radius:11px;padding:12px 14px;font-size:12.5px;color:#2c4a63;line-height:1.7;margin-top:12px}
.ua-say b{color:#152033}
.ua-now{display:inline-block;border-radius:20px;padding:2px 9px;font-size:10px;font-weight:800;margin-left:5px}
.n-yes{background:rgba(22,163,74,.12);color:#16a34a}
.n-ro{background:rgba(217,119,6,.12);color:#b45309}
.n-no{background:rgba(224,67,93,.12);color:#c0293f}
.ua-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.ua-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.ua-lock{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09;border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.ua-pre{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}
.ua-pb{padding:6px 12px;border:1px solid #cbd5e3;border-radius:9px;background:#fff;font-size:11.5px;font-weight:700;color:#5a6b82;cursor:pointer;font-family:inherit}
.ua-pb:hover{border-color:#0ea8c9;color:#0b5f8a}
.ua-two{display:grid;grid-template-columns:272px 1fr;gap:14px;align-items:start}
@media(max-width:1080px){.ua-two{grid-template-columns:1fr}}
</style>

<?php /* OPTING IN TO THE SKIN. Everything above is how the page looks on its
         own; zskin.css only reaches inside this wrapper. Deleting this one
         div restores the styles above with nothing else to undo. */ ?>
<div class="zskin">

<h1 style="font-size:19px;font-weight:800;margin:0 0 4px">User access</h1>
<p style="color:#8a97ab;font-size:12.5px;margin:0 0 16px;max-width:980px">
  Everything a person may do, and when they may do it. Roles are still set on
  <a href="users.php" style="color:#0b5f8a;font-weight:700">Users</a>; everything else is here.</p>

<?php flash(); ?>

<div class="ua-two">
  <div class="ua-card">
    <div class="ua-h">People</div>
    <div class="ua-sub">The dot shows what they can do <i>right now</i>.</div>
    <input class="ua-find" id="ufind" placeholder="Search name, email or role…" autocomplete="off">
    <div id="ulist">
      <?php foreach ($users as $u):
        $st = zu_window_state($u);
        $cls = $st === 'yes' ? 'd-now' : ($st === 'ro' ? 'd-ro' : ($st === 'no' ? 'd-no' : 'd-off'));
        $tip = $st === 'yes' ? 'can work now' : ($st === 'ro' ? 'read only now'
             : ($st === 'no' ? 'locked out now' : 'account disabled')); ?>
        <a class="ua-u<?= (int)$u['id'] === $sel ? ' on' : '' ?><?= (int)$u['is_active'] ? '' : ' ua-off' ?>"
           href="?u=<?= (int)$u['id'] ?>"
           data-find="<?= e(strtolower($u['name'] . ' ' . $u['email'] . ' ' . ($ROLE[$u['role']] ?? $u['role']))) ?>">
          <span class="ua-av"><?= e(ua_initials((string)$u['name'])) ?></span>
          <span><span class="ua-un"><?= e($u['name']) ?></span><br>
                <span class="ua-ur"><?= e($ROLE[$u['role']] ?? $u['role']) ?><?= (int)$u['is_active'] ? '' : ' · disabled' ?></span></span>
          <span class="ua-dot <?= $cls ?>" title="<?= e($tip) ?>"></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
  <?php if (!$cur): ?>
    <div class="ua-card"><div class="zempty"><h4>No users yet</h4>Add people on the Users screen first.</div></div>
  <?php else: ?>
    <form method="post" id="uaForm"><?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="user_id" value="<?= (int)$cur['id'] ?>">

      <div class="ua-card" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
        <div>
          <div class="ua-h" style="font-size:15px"><?= e($cur['name']) ?></div>
          <div class="ua-sub" style="margin:0"><?= e($ROLE[$cur['role']] ?? $cur['role']) ?>
            <?= (int)$cur['is_active'] ? '' : ' · account disabled' ?> · <?= e($cur['email']) ?></div>
        </div>
        <?php if (!$isAdminUser): ?>
        <div style="display:flex;gap:8px">
          <a class="ua-btn sec" href="?u=<?= (int)$cur['id'] ?>">Cancel</a>
          <button class="ua-btn" type="submit">Save access</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($isAdminUser): ?>
        <div class="ua-lock"><b>This is an admin account.</b> Admins can do everything, everywhere, at any
          hour, so there is nothing to set here and the screen will not let you take rights off one.
          That is deliberate: a limit that could lock out the last administrator is a locked building with
          the keys inside. Change the role on <a href="users.php" style="color:#7a4d09;font-weight:800">Users</a>
          first if that is what you mean.</div>
      <?php endif; ?>

      <div class="ua-card">
        <div class="ua-h">What they may do</div>
        <div class="ua-sub">Six actions, per area. <b>Post</b> is the one that moves stock;
          <b>Reverse</b> undoes it. A dash means the action does not exist for that area.</div>
        <?php if (!$isAdminUser): ?>
        <div class="ua-pre">
          <button type="button" class="ua-pb" data-p="entry" title="Create and update in the areas they already have. No delete, no post.">Data entry</button>
          <button type="button" class="ua-pb" data-p="super" title="Everything in the areas they already have, including post and reverse.">Supervisor</button>
          <button type="button" class="ua-pb" data-p="read"  title="Can look at the areas they already have. Changes nothing.">Read only</button>
          <button type="button" class="ua-pb" data-p="none"  title="Takes every tick off. They keep their login and see nothing.">Clear all</button>
        </div>
        <?php endif; ?>
        <div style="overflow-x:auto"><table class="ua-tbl" id="matrix">
          <thead><tr>
            <th class="l" style="min-width:210px">Area</th>
            <?php foreach ($ACTIONS as $k => $a): ?>
              <th style="width:80px" class="<?= $k === 'd' ? 'dcol' : '' ?>" title="<?= e($a['t']) ?>">
                <?= e($a['n']) ?><br>
                <?php if (!$isAdminUser): ?><button type="button" class="ua-all" data-col="<?= e($k) ?>">all</button><?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php $grp = ''; foreach ($MODULES as $m):
            if ($m['g'] !== $grp): $grp = $m['g']; ?>
              <tr class="ua-grp"><td colspan="7"><?= e($grp) ?></td></tr>
            <?php endif;
            $have = $isAdminUser ? 'vcudpr' : ($perm[$m['k']] ?? ''); ?>
            <tr>
              <td class="l"><?= e($m['n']) ?>
                <?php if (!$isAdminUser): ?> <button type="button" class="ua-all" data-row="<?= e($m['k']) ?>">all</button><?php endif; ?>
              </td>
              <?php foreach ($ACTIONS as $k => $a):
                $exists = strpos($m['a'], $k) !== false; ?>
                <td class="<?= $k === 'd' ? 'dcol' : '' ?>">
                  <?php if ($exists): ?>
                    <input type="checkbox" class="ua-cb<?= $k === 'd' ? ' dl' : '' ?>"
                           name="p[<?= e($m['k']) ?>][]" value="<?= e($k) ?>"
                           data-m="<?= e($m['k']) ?>" data-a="<?= e($k) ?>"
                           <?= strpos($have, $k) !== false ? 'checked' : '' ?>
                           <?= $isAdminUser ? 'disabled' : '' ?>>
                  <?php else: ?><span class="na">&mdash;</span><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <div class="ua-card">
        <div class="ua-h">When they may do it</div>
        <div class="ua-sub">Leave anything blank for &ldquo;no limit&rdquo;. Times are
          <?= e(date_default_timezone_get()) ?> &mdash; your own clock, not the server's.</div>
        <div class="ua-when">
          <div class="ua-f"><label>ACCESS FROM</label>
            <input class="ua-in" type="date" name="acc_from" value="<?= e($cur['acc_from'] ?? '') ?>" <?= $isAdminUser ? 'disabled' : '' ?>></div>
          <div class="ua-f"><label>ACCESS UNTIL</label>
            <input class="ua-in" type="date" name="acc_until" value="<?= e($cur['acc_until'] ?? '') ?>" <?= $isAdminUser ? 'disabled' : '' ?>></div>
          <div class="ua-f"><label>FROM TIME</label>
            <input class="ua-in" type="time" name="acc_t1" value="<?= e(substr((string)($cur['acc_t1'] ?? ''), 0, 5)) ?>" <?= $isAdminUser ? 'disabled' : '' ?>></div>
          <div class="ua-f"><label>TO TIME</label>
            <input class="ua-in" type="time" name="acc_t2" value="<?= e(substr((string)($cur['acc_t2'] ?? ''), 0, 5)) ?>" <?= $isAdminUser ? 'disabled' : '' ?>></div>
          <div class="ua-f" style="grid-column:span 2"><label>DAYS</label>
            <div class="ua-days">
              <?php $days = (string)($cur['acc_days'] ?? '1234567');
              foreach ($DAYN as $n => $lbl): ?>
                <label class="ua-day"><input type="checkbox" name="acc_days[]" value="<?= $n ?>"
                  <?= strpos($days, $n) !== false ? 'checked' : '' ?> <?= $isAdminUser ? 'disabled' : '' ?>><span><?= $lbl ?></span></label>
              <?php endforeach; ?>
            </div></div>
          <div class="ua-f" style="grid-column:span 2"><label>OUTSIDE THOSE HOURS</label>
            <div class="ua-radio">
              <label><input type="radio" name="acc_outside" value="readonly"
                <?= ($cur['acc_outside'] ?? 'readonly') !== 'block' ? 'checked' : '' ?> <?= $isAdminUser ? 'disabled' : '' ?>> Can look, cannot change</label>
              <label><input type="radio" name="acc_outside" value="block"
                <?= ($cur['acc_outside'] ?? '') === 'block' ? 'checked' : '' ?> <?= $isAdminUser ? 'disabled' : '' ?>> No access at all</label>
            </div></div>
        </div>
        <div class="ua-say" id="say"></div>
      </div>
    </form>
  <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var MODACT = <?= json_encode(array_column($MODULES, 'a', 'k')) ?>;
  var NAME   = <?= json_encode($cur['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
  var ADMIN  = <?= $isAdminUser ? 'true' : 'false' ?>;
  var DAYN   = <?= json_encode($DAYN) ?>;
  var f = document.getElementById('uaForm');

  /* search filters the list in place — no round trip for a 30-person office */
  var find = document.getElementById('ufind');
  if (find) find.addEventListener('input', function(){
    var q = find.value.toLowerCase();
    document.querySelectorAll('#ulist .ua-u').forEach(function(a){
      a.style.display = (!q || a.dataset.find.indexOf(q) >= 0) ? '' : 'none';
    });
  });
  if (!f || ADMIN) { say(); return; }

  function boxes(mod){ return [].slice.call(f.querySelectorAll('.ua-cb[data-m="' + mod + '"]')); }
  function letters(mod){ return boxes(mod).filter(function(b){ return b.checked; })
                                          .map(function(b){ return b.dataset.a; }).join(''); }
  /* Ticking anything implies being able to open the screen — "create but
     cannot view" is a right nobody can use. Enforced here as well as in
     zu_save_perms(), so the screen never shows a state the server would
     silently change on save. */
  function impliedView(mod){
    var bs = boxes(mod), any = bs.some(function(b){ return b.checked && b.dataset.a !== 'v'; });
    var v = bs.filter(function(b){ return b.dataset.a === 'v'; })[0];
    if (v && any) v.checked = true;
  }

  f.addEventListener('change', function(e){
    if (e.target.classList.contains('ua-cb')) {
      var mod = e.target.dataset.m;
      if (e.target.dataset.a === 'v' && !e.target.checked)
        boxes(mod).forEach(function(b){ b.checked = false; });   // no view, no anything
      else impliedView(mod);
    }
    say();
  });

  f.addEventListener('click', function(e){
    var b = e.target.closest('.ua-all'); if (b) { e.preventDefault(); allBtn(b); say(); return; }
    var p = e.target.closest('.ua-pb');  if (p) { e.preventDefault(); preset(p.dataset.p); say(); }
  });

  function allBtn(b){
    if (b.dataset.row) {
      var bs = boxes(b.dataset.row), on = bs.every(function(x){ return x.checked; });
      bs.forEach(function(x){ x.checked = !on; });
    } else {
      var a = b.dataset.col;
      var bs2 = [].slice.call(f.querySelectorAll('.ua-cb[data-a="' + a + '"]'));
      var on2 = bs2.every(function(x){ return x.checked; });
      bs2.forEach(function(x){ x.checked = !on2; });
      if (!on2) Object.keys(MODACT).forEach(impliedView);
    }
  }

  /* A preset shapes the areas a person ALREADY has rather than handing
     them new ones. "Read only" on somebody with no access would otherwise
     quietly give them the run of the building. */
  function preset(kind){
    Object.keys(MODACT).forEach(function(mod){
      var bs = boxes(mod);
      if (kind === 'none') { bs.forEach(function(x){ x.checked = false; }); return; }
      if (!letters(mod)) return;
      var want = kind === 'read' ? 'v' : kind === 'entry' ? 'vcu' : 'vcudpr';
      bs.forEach(function(x){ x.checked = want.indexOf(x.dataset.a) >= 0; });
    });
  }

  function say(){
    var el = document.getElementById('say'); if (!el) return;
    if (ADMIN) {
      el.innerHTML = '<b>' + NAME + '</b> is an admin: everything, everywhere, at any hour.';
      return;
    }
    var areas = 0, del = 0, post = 0;
    Object.keys(MODACT).forEach(function(mod){
      var L = letters(mod);
      if (L) areas++;
      if (L.indexOf('d') >= 0) del++;
      if (L.indexOf('p') >= 0) post++;
    });
    var picked = [].slice.call(f.querySelectorAll('input[name="acc_days[]"]:checked'))
                   .map(function(x){ return x.value; }).sort().join('');
    var when = (picked === '' || picked === '1234567') ? '<b>any day</b>'
             : picked === '12345' ? '<b>Mon–Fri</b>'
             : picked === '123456' ? '<b>Mon–Sat</b>'
             : '<b>' + picked.split('').map(function(n){ return DAYN[n]; }).join(', ') + '</b>';
    var t1 = f.acc_t1.value, t2 = f.acc_t2.value;
    var hrs = (t1 && t2) ? 'between <b>' + t1 + '</b> and <b>' + t2 + '</b>' : 'at <b>any hour</b>';
    var fr = f.acc_from.value, un = f.acc_until.value, range = '';
    if (fr && un) range = ' Access runs <b>' + fr + '</b> to <b>' + un + '</b>.';
    else if (un)  range = ' Access ends <b>' + un + '</b>.';
    else if (fr)  range = ' Access starts <b>' + fr + '</b>.';
    var block = f.querySelector('input[name="acc_outside"]:checked');
    var out = (block && block.value === 'block')
      ? '<b>cannot get in at all</b>' : '<b>can look but not change anything</b>';

    el.innerHTML = '<b>' + NAME + '</b> may work in <b>' + areas + '</b> area(s), ' + when + ', ' + hrs + '. '
      + (del  ? 'They can delete drafts in <b>' + del + '</b> area(s). '
              : 'They <b>cannot delete</b> anything. ')
      + (post ? 'They can <b>post to stock</b> in <b>' + post + '</b> area(s). '
              : 'They <b>cannot post to stock</b> — someone else commits their documents. ')
      + 'Outside those hours they ' + out + '.' + range
      + (areas === 0 ? '<br><span style="color:#b45309;font-weight:700">With nothing ticked they can sign in and see nothing.</span>' : '');
  }
  say();
})();
</script>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
