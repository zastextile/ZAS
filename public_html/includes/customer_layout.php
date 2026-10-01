<?php
/*
  Minimal PWA shell for the Customer Portal — deliberately NOT page_header()/
  page_footer() from includes/layout.php, since those render the full
  internal sidebar (Shipments, Product Costing, Users, ...) that a customer
  must never see. Own manifest, own theme, own tiny bottom tab bar.
*/

function customer_page_header(string $title, string $activeTab = 'home'): void {
    $u = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> - ZAS Textile</title>
<link rel="manifest" href="manifest_customer.json">
<meta name="theme-color" content="#0ea8c9">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="My Orders">
<style>
:root{--bg:#eef1f6;--paper:#ffffff;--line:#e3e9f2;--ink:#152033;--sub:#5a6b82;--muted:#8a97ab;--accent1:#0ea8c9;--accent2:#6d5bd0;--purple-bg:rgba(109,91,208,.07);--purple-line:rgba(109,91,208,.22)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 "Segoe UI",Arial,sans-serif;-webkit-tap-highlight-color:transparent}
.app-shell{max-width:480px;margin:0 auto;min-height:100vh;display:flex;flex-direction:column;background:var(--bg)}
.appbar{padding:18px 18px 14px;display:flex;justify-content:space-between;align-items:flex-start;padding-top:calc(18px + env(safe-area-inset-top))}
.appbar .co{font-size:16px;font-weight:800}
.appbar .greet{font-size:11.5px;color:var(--sub);margin-top:1px}
.appbar .logout{width:36px;height:36px;border-radius:10px;background:var(--paper);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--sub);text-decoration:none;flex-shrink:0}
.content{flex:1;padding:0 14px 14px}
.zin{width:100%;padding:11px 13px;border-radius:11px;border:1px solid #cbd5e3;background:var(--paper);color:var(--ink);font-size:13.5px;outline:none;font-family:inherit}
.zbtn{padding:12px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13.5px;color:#fff;background:linear-gradient(100deg,var(--accent1),var(--accent2));text-decoration:none;display:inline-block;text-align:center}
.zcard{background:var(--paper);border-radius:14px;padding:14px 16px;border:1px solid var(--line);margin-bottom:10px}
.alert{padding:12px 14px;border-radius:12px;margin-bottom:14px;font-size:12.5px}
.alert.error{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#b8283f}
.alert.success{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.28);color:#127a3f}
.tabbar{display:flex;border-top:1px solid var(--line);background:var(--paper);padding-bottom:env(safe-area-inset-bottom)}
.tab{flex:1;padding:10px 0 10px;display:flex;flex-direction:column;align-items:center;gap:3px;font-size:9.5px;font-weight:700;color:var(--muted);text-decoration:none}
.tab.on{color:var(--accent1)}
</style>
</head>
<body>
<div class="app-shell">
  <div class="appbar">
    <div><div class="co"><?= e($u['company_name'] ?? $u['name'] ?? '') ?></div><div class="greet"><?= e($title) ?></div></div>
    <a class="logout" href="customer_logout.php" title="Logout"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg></a>
  </div>
  <div class="content">
<?php
}

function customer_page_footer(string $activeTab = 'home'): void {
    ?>
  </div>
  <div class="tabbar">
    <a class="tab <?= $activeTab==='home'?'on':'' ?>" href="customer_dashboard.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>Home</a>
    <a class="tab <?= $activeTab==='search'?'on':'' ?>" href="customer_search.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>Search</a>
  </div>
</div>
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('sw.js').catch(function(){}); }); }</script>
</body>
</html>
<?php
}
