<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/customer.php';
require_admin();
costing_ensure_schema(); // self-heal cost_* permission columns
customer_ensure_schema(); // self-heal the 'customer' role + company_name column

/* Delete user */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    verify_csrf();
    $delId = (int)($_POST['id'] ?? 0);
    if ($delId && $delId === (int)current_user()['id']) {
        $_SESSION['error'] = 'You cannot delete your own account.';
    } elseif ($delId) {
        try {
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$delId]);
            $_SESSION['flash'] = 'User deleted.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Cannot delete: user is linked to shipment records. Set the user to Inactive instead.';
        }
    }
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    // trimmed so a password set/typed here can never accidentally carry a
    // leading/trailing space that then fails to match on login (see the
    // matching fix in login.php — this is the pair that makes both sides
    // consistent)
    $password = trim($_POST['password'] ?? '');
    $cp = fn($k) => isset($_POST[$k]) ? 1 : 0;
    $data = [
        trim($_POST['name'] ?? ''),
        strtolower(trim($_POST['email'] ?? '')),
        $_POST['role'] ?? 'colleague',
        trim($_POST['department'] ?? ''),
        trim($_POST['company_name'] ?? ''),
        isset($_POST['can_see_rates']) ? 1 : 0,
        isset($_POST['is_active']) ? 1 : 0,
        $cp('cost_view'),$cp('cost_create'),$cp('cost_edit'),$cp('cost_import'),$cp('cost_print'),$cp('cost_proforma'),$cp('cost_delete'),
    ];
    $costCols = "can_see_rates=?, is_active=?, cost_view=?, cost_create=?, cost_edit=?, cost_import=?, cost_print=?, cost_proforma=?, cost_delete=?";
    $costInsCols = "can_see_rates,is_active,cost_view,cost_create,cost_edit,cost_import,cost_print,cost_proforma,cost_delete";

    if ($data[0] === '' || $data[1] === '') {
        $_SESSION['error'] = 'Name and email are required.';
        redirect('users.php');
    }
    if ($data[2] === 'customer' && $data[4] === '') {
        $_SESSION['error'] = 'Company Name is required for the Customer role — it must match the Buyer Name on their invoices.';
        redirect('users.php');
    }

    try {
        if ($id) {
            if ($password !== '' && strlen($password) < 8) {
                $_SESSION['error'] = 'Password minimum 8 characters.';
                redirect('users.php');
            }
            if ($password !== '') {
                $stmt = db()->prepare("UPDATE users SET name=?, email=?, role=?, department=?, company_name=?, $costCols, password_hash=? WHERE id=?");
                $stmt->execute([...$data, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = db()->prepare("UPDATE users SET name=?, email=?, role=?, department=?, company_name=?, $costCols WHERE id=?");
                $stmt->execute([...$data, $id]);
            }
            $_SESSION['flash'] = 'User updated.';
        } else {
            if (strlen($password) < 8) {
                $_SESSION['error'] = 'Password minimum 8 characters.';
            } else {
                $stmt = db()->prepare("INSERT INTO users (name,email,role,department,company_name,$costInsCols,password_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([...$data, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['flash'] = 'User created.';
            }
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = (stripos($e->getMessage(), 'unique') !== false || stripos($e->getMessage(), 'duplicate') !== false)
            ? 'That email address is already used by another account.'
            : 'Could not save user: ' . $e->getMessage();
    }
    redirect('users.php');
}

$users = db()->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$meId = (int)current_user()['id'];

function usr_role_badge($role) {
    $map = [
        'admin'     => ['Admin','rgba(109,91,208,.16)','#6d5bd0','rgba(109,91,208,.3)'],
        'colleague' => ['Colleague','rgba(47,127,224,.16)','#2f7fe0','rgba(47,127,224,.3)'],
        'staff'     => ['Staff','rgba(217,119,6,.16)','#d97706','rgba(217,119,6,.3)'],
        'production_staff' => ['Production Staff','rgba(22,163,74,.16)','#16a34a','rgba(22,163,74,.3)'],
        'customer'  => ['Customer','rgba(14,168,201,.16)','#0ea8c9','rgba(14,168,201,.3)'],
    ];
    $x = $map[$role] ?? [ucfirst((string)$role),'#e3e9f2','#33415c','#cbd5e3'];
    return '<span style="padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:600;background:'.$x[1].';color:'.$x[2].';border:1px solid '.$x[3].'">'.htmlspecialchars($x[0]).'</span>';
}

page_header('Users');
flash();
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zhead{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.zhead h2{font-size:15px;margin:0}
.zgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.zlabel{font-size:12px;color:#5a6b82;display:block}
.zin{display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit}
.zin:focus{border-color:#0ea8c9}
.zchk{display:flex;align-items:center;gap:9px;font-size:13px;color:#33415c;cursor:pointer;margin-top:24px}
.zchk input{width:16px;height:16px;accent-color:#0ea8c9}
.zbtn{padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3);padding:7px 11px;font-size:12px}
.ztable{width:100%;border-collapse:collapse;font-size:13px;min-width:720px}
.ztable thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.ztable th{padding:5px 8px}.ztable td{padding:3px 8px;border-top:1px solid #f6f8fc;color:#152033}
.ztable tbody tr:hover{background:#f6f8fc}
</style>

<div class="topbar"><div><h1>Users</h1><p class="lead">Admin controls roles, departments &amp; rate visibility.</p></div>
  <a class="zbtn sec" href="password_manage.php">Manage Passwords</a>
</div>

<div class="zcard">
  <div class="zhead"><h2 id="formTitle">Create User</h2><button type="button" class="zbtn sec" onclick="resetUserForm()">Clear / New</button></div>
  <form method="post" id="userForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="uf_id" value="">
    <div class="zgrid">
      <label class="zlabel">Name<input class="zin" name="name" id="uf_name" required></label>
      <label class="zlabel">Email<input class="zin" name="email" id="uf_email" type="email" required></label>
      <label class="zlabel">Role<select class="zin" name="role" id="uf_role" onchange="toggleCompanyField()"><option value="colleague">Colleague</option><option value="staff">Staff</option><option value="production_staff">Production Staff</option><option value="admin">Admin</option><option value="customer">Customer</option></select></label>
      <label class="zlabel">Department<select class="zin" name="department" id="uf_dept"><option value="">— None —</option><?php for($d=1;$d<=5;$d++): ?><option value="Dept <?= $d ?>">Dept <?= $d ?></option><?php endfor; ?></select></label>
      <label class="zlabel" id="uf_company_wrap" style="display:none">Company Name <span style="color:#8a97ab">(Customer only — must match Buyer Name exactly)</span><input class="zin" name="company_name" id="uf_company" placeholder="e.g. Al Faisal Textiles"></label>
      <label class="zlabel">Password <span style="color:#8a97ab" id="uf_pwhint">(min 8 chars)</span><input class="zin" name="password" id="uf_pw" type="password" minlength="8"></label>
      <label class="zchk"><input type="checkbox" name="can_see_rates" id="uf_rates"> Can see rates</label>
      <label class="zchk"><input type="checkbox" name="is_active" id="uf_active" checked> Active</label>
    </div>
    <div id="costPerms" style="margin-top:16px;padding:14px 16px;border:1px solid #e3e9f2;border-radius:12px;background:#ffffff">
      <div style="font-size:12px;font-weight:700;color:#5a6b82;text-transform:uppercase;letter-spacing:.03em;margin-bottom:10px">Costing Permissions <span style="font-weight:400;text-transform:none">(Colleague only — Admin always full, Staff never)</span></div>
      <div style="display:flex;flex-wrap:wrap;gap:14px">
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_view" id="uf_cv"> View</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_create" id="uf_cc"> Create</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_edit" id="uf_ce"> Edit</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_import" id="uf_ci"> Import</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_print" id="uf_cp"> Print</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_proforma" id="uf_cf"> Convert to PI</label>
        <label class="zchk" style="margin:0"><input type="checkbox" name="cost_delete" id="uf_cd"> Delete</label>
      </div>
    </div>
    <button class="zbtn" id="uf_submit" style="margin-top:16px">Create User</button>
  </form>
</div>

<div class="zcard">
  <div class="zhead"><h2>All Users</h2><span style="color:#8a97ab;font-size:12px">Click Edit to load a user into the form</span></div>
  <div style="overflow-x:auto">
    <table class="ztable">
      <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Department / Company</th><th>Rates</th><th>Active</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($users as $u): ?>
        <tr>
          <td style="color:#5a6b82"><?= e($u['id']) ?></td>
          <td style="font-weight:600"><?= e($u['name']) ?></td>
          <td style="color:#5a6b82"><?= e($u['email']) ?></td>
          <td><?= usr_role_badge($u['role']) ?></td>
          <td><?= e($u['role']==='customer' ? ($u['company_name'] ?: '—') : ($u['department'] ?: '—')) ?></td>
          <td><?= $u['can_see_rates']?'<span style="color:#16a34a">Yes</span>':'<span style="color:#8a97ab">No</span>' ?></td>
          <td><?= $u['is_active']?'<span style="color:#16a34a">Yes</span>':'<span style="color:#b8283f">No</span>' ?></td>
          <td style="white-space:nowrap">
            <button type="button" data-uid="<?= (int)$u['id'] ?>" class="zbtn sec" style="padding:7px 11px;font-size:12px" onclick='editUser(<?= json_encode(["id"=>$u["id"],"name"=>$u["name"],"email"=>$u["email"],"role"=>$u["role"],"department"=>$u["department"],"company_name"=>$u["company_name"]??"","can_see_rates"=>(int)$u["can_see_rates"],"is_active"=>(int)$u["is_active"],"cost_view"=>(int)($u["cost_view"]??0),"cost_create"=>(int)($u["cost_create"]??0),"cost_edit"=>(int)($u["cost_edit"]??0),"cost_import"=>(int)($u["cost_import"]??0),"cost_print"=>(int)($u["cost_print"]??0),"cost_proforma"=>(int)($u["cost_proforma"]??0),"cost_delete"=>(int)($u["cost_delete"]??0)], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
            <?php if((int)$u['id'] !== $meId): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete user <?= e(addslashes($u['name'])) ?>? This cannot be undone.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="id" value="<?= e($u['id']) ?>">
              <button type="submit" class="zbtn red">Delete</button>
            </form>
            <?php else: ?><span style="color:#8a97ab;font-size:11px">(you)</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function editUser(u){
  document.getElementById('uf_id').value = u.id;
  document.getElementById('uf_name').value = u.name || '';
  document.getElementById('uf_email').value = u.email || '';
  document.getElementById('uf_role').value = u.role || 'colleague';
  document.getElementById('uf_dept').value = u.department || '';
  document.getElementById('uf_company').value = u.company_name || '';
  toggleCompanyField();
  document.getElementById('uf_rates').checked = !!u.can_see_rates;
  document.getElementById('uf_active').checked = !!u.is_active;
  document.getElementById('uf_cv').checked = !!u.cost_view;
  document.getElementById('uf_cc').checked = !!u.cost_create;
  document.getElementById('uf_ce').checked = !!u.cost_edit;
  document.getElementById('uf_ci').checked = !!u.cost_import;
  document.getElementById('uf_cp').checked = !!u.cost_print;
  document.getElementById('uf_cf').checked = !!u.cost_proforma;
  document.getElementById('uf_cd').checked = !!u.cost_delete;
  document.getElementById('uf_pw').value = '';
  document.getElementById('uf_pwhint').textContent = '(leave blank to keep current)';
  document.getElementById('uf_pw').removeAttribute('minlength');
  document.getElementById('formTitle').textContent = 'Edit User #' + u.id;
  document.getElementById('uf_submit').textContent = 'Save Changes';
  window.scrollTo({top:0, behavior:'smooth'});
}
function resetUserForm(){
  var f = document.getElementById('userForm'); f.reset();
  document.getElementById('uf_id').value = '';
  document.getElementById('uf_pwhint').textContent = '(min 8 chars)';
  document.getElementById('uf_pw').setAttribute('minlength','8');
  document.getElementById('formTitle').textContent = 'Create User';
  document.getElementById('uf_submit').textContent = 'Create User';
  toggleCompanyField();
}
function toggleCompanyField(){
  var isCustomer = document.getElementById('uf_role').value === 'customer';
  document.getElementById('uf_company_wrap').style.display = isCustomer ? 'block' : 'none';
  document.getElementById('costPerms').style.display = isCustomer ? 'none' : 'block';
}
var _editId = <?= (int)($_GET['edit'] ?? 0) ?>;
if (_editId) {
  var btn = document.querySelector('button[onclick^="editUser"][data-uid="'+_editId+'"]');
  if (btn) btn.click();
}
</script>
<?php page_footer(); ?>
