<?php
function page_header(string $title): void {
    global $config;
    $u = current_user();
    $role = $u['role'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e($config['app_name']) ?></title>
<?php /* THE STYLESHEET MUST BE CACHE-BUSTED. It was linked bare, so a browser
         that had already downloaded app.css kept serving the old one. When a
         page then shipped markup relying on NEW rules — .zform, .zsec, .zrow —
         the browser had the markup but not the styles, and the page rendered
         completely unstyled. That is a broken screen caused purely by a stale
         cache, and it is why this parameter now exists.

         RAISE THIS NUMBER whenever assets/css/app.css changes. Same for the
         ?v= on any assets/js file. */ ?>
<link rel="stylesheet" href="assets/css/app.css?v=29">
<?php /* THE NEW SKIN, AND WHY IT IS SAFE TO LINK ON EVERY PAGE.
         Every rule inside zskin.css is scoped under .zskin, so a page is
         untouched until it puts that class on a wrapper. Linking it here costs
         one small file; restyling all fifty-seven screens at once would not be
         step by step, and a visual change nobody has looked at cannot be told
         apart from a page that has broken. IBM Plex is fetched alongside it and
         has a real fallback stack, so a blocked font changes nothing but the
         letterforms. */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/css/zskin.css?v=8">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0ea8c9">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ZAS Production">
</head>
<body>
<div class="app">
<?php if ($u): ?>
<aside class="sidebar" style="display:flex;flex-direction:column;max-height:100vh">
    <div class="brand">
        <div class="brand-title">ZAS TEXTILE</div>
        <div class="brand-sub">Textile Exports</div>
    </div>
    <?php
    /* Menu structure lives in includes/menu.php, declared as data. The
       Store & Inventory group only appears once the module is installed
       and this user has an inventory permission (admin always). Guarded so
       a missing add-on file can never break the menu for anyone. */
    if (is_file(__DIR__ . '/inventory.php')) require_once __DIR__ . '/inventory.php';
    require_once __DIR__ . '/menu.php';
    ?>
    <div class="nav" style="overflow-y:auto;flex:1;min-height:0">
        <?php zas_render_menu(); ?>
    </div>
    <a href="logout.php" style="display:none">Logout</a>
    <?php $selfLink = is_admin() ? 'users.php?edit='.(int)$u['id'] : 'profile.php'; ?>
    <div class="side-user" style="display:block;position:static;margin-top:14px;flex-shrink:0">
        <a href="<?= e($selfLink) ?>" style="text-decoration:none;color:inherit;display:block">
          <strong><?= e($u['name']) ?></strong>
          <span>
            <?= e(ucwords(str_replace('_',' ',$role))) ?>
            <?= can_see_rates() ? ' · rates visible' : ' · rates hidden' ?>
          </span>
        </a>
        <a href="logout.php" style="display:block;margin-top:10px;padding:9px 12px;border-radius:9px;text-align:center;font-weight:700;font-size:12.5px;background:rgba(255,93,115,.16);color:#ff8a9c;border:1px solid rgba(255,93,115,.32);text-decoration:none">Logout</a>
    </div>
</aside>
<?php endif; ?>
<main class="content <?= $u ? '' : 'content-login' ?>">
<?php
}

function page_footer(): void {
    ?>
</main>
</div>
<script src="assets/js/app.js"></script>
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('sw.js').catch(function(){}); }); }</script>
</body>
</html>
<?php
}

function flash(): void {
    if (!empty($_SESSION['flash'])) {
        echo '<div class="alert success">' . e($_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    if (!empty($_SESSION['error'])) {
        echo '<div class="alert error">' . e($_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
}
