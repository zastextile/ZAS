<?php
/*
  Navigation — declared as data, rendered as a folder tree.

  WHY THIS FILE EXISTS
  The sidebar had grown to ~20 flat links with the role rules written
  inline in the markup, which made it hard to read and risky to change.
  Here the whole menu is one array: every group, sub-group and page with
  the exact condition that shows it. The renderer below is the only place
  that writes markup.

  THE ROLE RULES ARE UNCHANGED. Every 'show' condition below is a direct
  copy of the condition that already governed that link. Nobody sees a
  page they could not see before, and nobody loses one.

  STRUCTURE
    group  ->  items                     (two levels)
    group  ->  sub-group  ->  items      (three levels, used by Store)
*/

function zas_menu(): array {
    $admin      = is_admin();
    $colleague  = is_colleague();
    $staff      = is_staff();
    $prod       = is_production_staff();

    // office = everyone except the shop-floor-only and production-only roles
    $office     = !$staff && !$prod;
    $notProd    = !$prod;

    $inv = function (string $what) { return function_exists('inv_perm') ? inv_perm($what) : false; };
    $invAny = function_exists('inv_can_see') ? inv_can_see() : false;

    return [
        [
            'label' => 'Dashboard', 'icon' => 'home', 'href' => 'dashboard.php',
            'show'  => $notProd,
        ],
        [
            'label' => 'Sales &amp; Export', 'icon' => 'ship',
            'show'  => $notProd,
            'items' => [
                ['label' => 'New Shipment',      'href' => 'shipment_form.php', 'show' => $office],
                ['label' => 'Shipments',         'href' => 'shipments.php',     'show' => $notProd],
                ['label' => 'Packing List',      'href' => 'packing_list.php',  'show' => $notProd],
                ['label' => 'Proforma Invoices', 'href' => 'proforma.php',      'show' => $office],
                ['label' => 'CSV Import',        'href' => 'import_excel.php',  'show' => $office],
            ],
        ],
        [
            'label' => 'Costing &amp; Products', 'icon' => 'tag',
            'show'  => $office || $admin || $colleague,
            'items' => [
                ['label' => 'Product Costing', 'href' => 'product_costing.php', 'show' => $office],
                ['label' => 'Product Master',  'href' => 'product_master.php',  'show' => $admin || $colleague],
                ['label' => 'Part Library',    'href' => 'part_library.php',    'show' => $admin || $colleague],
                /* The product list itself, out and back. The safety net before
                   a reset: whatever else is cleared, the products can return. */
                ['label' => 'Products CSV',       'href' => 'products_csv.php',  'show' => $admin || $colleague],
                /* Named for what it now does. It used to carry an operations
                   CSV as well, removed because that file had no Size column:
                   a product priced per size exported as identical-looking rows
                   and re-imported as "All sizes" whatever you meant. Operations
                   come from the Part Library or the Product Master grid. */
                ['label' => 'Set Quantities CSV', 'href' => 'set_quantities_csv.php', 'show' => $admin || $colleague],
                ['label' => 'Cost Audit',      'href' => 'cost_audit.php',      'show' => $admin],
                ['label' => 'Link Costing Items', 'href' => 'costing_items_link.php', 'show' => $admin],
            ],
        ],
        [
            'label' => 'Production', 'icon' => 'gear',
            'show'  => $prod || $admin,
            'items' => [
                ['label' => 'My Production Work',    'href' => 'production_my_work.php',   'show' => $prod || $admin],
                ['label' => 'Production Dashboard',  'href' => 'production_dashboard.php', 'show' => $prod || $admin],
                ['label' => 'Daily Production Entry','href' => 'production_entry.php',     'show' => $prod || $admin],
                ['label' => 'Production Reports',    'href' => 'production_reports.php',   'show' => $prod || $admin],
                ['label' => 'Production Workers',    'href' => 'production_workers.php',   'show' => $admin],
                ['label' => 'Worker Pay',            'href' => 'production_pay.php',       'show' => $admin],
                /* One order pays more for one operation — a tighter hem, a
                   quality parameter in the contract. Amended there, not on the
                   product, so no other order moves. A colleague may read it;
                   only an admin may change a rate, which the page enforces. */
                ['label' => 'Order Rate Amendments', 'href' => 'production_order_rates.php', 'show' => $admin || $colleague],
                ['label' => 'Correct an Entry',      'href' => 'production_amend.php',     'show' => $admin],
                /* For one moment only: sweeping away the rough dummy data
                   before the real data-entry team starts. Admin, and it makes
                   you type the word. */
                /* THE OLD RESET TOOL IS OFF THE MENU.
                   It clears the OLD production_* tables. The rebuilt module
                   keeps its data in zp_* tables, so that tool would now report
                   success while clearing nothing anyone can see — the most
                   confusing possible outcome. The file is left on disk rather
                   than deleted, because it is still the only way to clear the
                   old tables if that is ever wanted. */
            ],
        ],
        [
            'label' => 'Store &amp; Inventory', 'icon' => 'box',
            'show'  => $invAny,
            'groups' => [
                [
                    'label' => 'Masters',
                    'items' => [
                        ['label' => 'Item Master',        'href' => 'inv_items.php',    'show' => $inv('master') || $inv('view')],
                        ['label' => 'Parties',            'href' => 'inv_parties.php',  'show' => $inv('master') || $inv('view')],
                    ],
                ],
                [
                    'label' => 'Transactions',
                    'items' => [
                        ['label' => 'Gate Inward',      'href' => 'inv_gate.php?dir=in',      'show' => $inv('gate') || $inv('view')],
                        ['label' => 'Gate Outward',     'href' => 'inv_gate.php?dir=out',     'show' => $inv('gate') || $inv('view')],
                        ['label' => 'Store Issue',      'href' => 'inv_store.php?type=issue', 'show' => $inv('store') || $inv('view')],
                        ['label' => 'Store Return',     'href' => 'inv_store.php?type=return','show' => $inv('store') || $inv('view')],
                        ['label' => 'Consumption',      'href' => 'inv_consume.php',          'show' => $inv('consume') || $inv('view')],
                    ],
                ],
                [
                    'label' => 'Stock',
                    'items' => [
                        /* Opening stock sits under Stock, not Transactions.
                           It is not something that happens during the week —
                           it is how a balance starts, and it belongs beside
                           the screens that show balances. */
                        ['label' => 'Opening Stock',  'href' => 'inv_opening.php',          'show' => $inv('gate') || $inv('master') || $inv('view')],
                        ['label' => 'Current Stock',  'href' => 'inv_stock.php',            'show' => $invAny],
                        ['label' => 'Stock Ledger',   'href' => 'inv_ledger.php',           'show' => $invAny],
                        ['label' => 'Floor Balance',  'href' => 'inv_store.php?tab=bal',    'show' => $invAny],
                        ['label' => 'Stock Health',   'href' => 'inv_verify.php',           'show' => $invAny],
                    ],
                ],
                [
                    'label' => 'Contracts',
                    'items' => [
                        ['label' => 'All Contracts',      'href' => 'inv_contracts.php',                    'show' => $invAny],
                        ['label' => 'Purchase',           'href' => 'inv_contracts.php?type=purchase',      'show' => $invAny],
                        ['label' => 'Sales',              'href' => 'inv_contracts.php?type=sales',         'show' => $invAny],
                        ['label' => 'Job Work — we send', 'href' => 'inv_contracts.php?type=jobwork_out',   'show' => $invAny],
                        ['label' => 'Job Work — we do',   'href' => 'inv_contracts.php?type=jobwork_in',    'show' => $invAny],
                    ],
                ],
                [
                    'label' => 'Costing &amp; Charges',
                    'items' => [
                        ['label' => 'Order Costing Control', 'href' => 'inv_ordercost.php',              'show' => $invAny],
                        ['label' => 'Job Work — we charge',  'href' => 'inv_jobwork.php?dir=receivable', 'show' => $invAny],
                        ['label' => 'Job Work — we are charged', 'href' => 'inv_jobwork.php?dir=payable','show' => $invAny],
                        ['label' => 'Production Exceptions',  'href' => 'inv_exceptions.php',            'show' => $admin || $inv('view')],
                    ],
                ],
            ],
        ],
        [
            'label' => 'Tools', 'icon' => 'search',
            'show'  => $office,
            'items' => [
                ['label' => 'AI Search', 'href' => 'search.php', 'show' => $office],
            ],
        ],
        [
            'label' => 'Administration', 'icon' => 'shield',
            'show'  => $admin,
            'items' => [
                ['label' => 'Users',            'href' => 'users.php',     'show' => $admin],
                /* Directly under Users, because it is the other half of
                   setting a person up: the role goes on Users, everything
                   they may do goes here. */
                ['label' => 'User Access',      'href' => 'user_access.php','show' => $admin],
                ['label' => 'Settings',         'href' => 'settings.php',  'show' => $admin],
                ['label' => 'Inventory Setup',  'href' => 'inv_setup.php', 'show' => $admin],
                ['label' => 'Start Again (clear stock)', 'href' => 'inv_reset.php', 'show' => $admin],
            ],
        ],
    ];
}

/* Tiny inline icons — no icon library, no web font, nothing to load. */
function zas_menu_icon(string $name): string {
    $p = [
        'home'   => '<path d="M3 10.5 10 4l7 6.5V17a1 1 0 0 1-1 1h-4v-5H8v5H4a1 1 0 0 1-1-1z"/>',
        'ship'   => '<path d="M3 13l1.5 4h11L17 13M5 13V7l5-3 5 3v6M8 9h4"/>',
        'tag'    => '<path d="M3 3h6l8 8-6 6-8-8V3z"/><circle cx="6.5" cy="6.5" r="1.2"/>',
        'gear'   => '<circle cx="10" cy="10" r="3"/><path d="M10 2v2m0 12v2M2 10h2m12 0h2M4.6 4.6l1.4 1.4m8 8 1.4 1.4m0-10.8-1.4 1.4m-8 8-1.4 1.4"/>',
        'box'    => '<path d="M3 6.5 10 3l7 3.5v7L10 17l-7-3.5z"/><path d="M3 6.5 10 10l7-3.5M10 10v7"/>',
        'search' => '<circle cx="8.5" cy="8.5" r="5"/><path d="m12.5 12.5 4 4"/>',
        'shield' => '<path d="M10 2.5 16 5v5c0 4-2.6 6.6-6 7.5-3.4-.9-6-3.5-6-7.5V5z"/>',
    ];
    $d = $p[$name] ?? $p['box'];
    return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

/* Is this href the page we are on? Compares file names only, so a link
   carrying a query string still highlights its own page. */
function zas_menu_active(string $href): bool {
    static $self = null;
    if ($self === null) $self = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return basename(explode('?', $href)[0]) === $self;
}

function zas_render_menu(): void {
    $menu = zas_menu();
    $self = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    ?>
    <div class="zm-find">
      <input type="text" id="zmFind" placeholder="Find a page…  press /" autocomplete="off" spellcheck="false">
    </div>
    <nav class="zm" id="zmNav">
    <?php foreach ($menu as $gi => $g):
        if (empty($g['show'])) continue;

        /* A group with a direct href is a single link, not a folder. */
        if (!empty($g['href'])) {
            $on = zas_menu_active($g['href']); ?>
            <a class="zm-solo<?= $on ? ' on' : '' ?>" href="<?= e($g['href']) ?>">
              <i class="zm-ico"><?= zas_menu_icon($g['icon'] ?? 'box') ?></i><span><?= $g['label'] ?></span>
            </a>
        <?php continue; }

        /* Collect visible children, and work out whether this group holds
           the current page (so it opens itself). */
        $rows = []; $groupActive = false;
        if (!empty($g['groups'])) {
            foreach ($g['groups'] as $sg) {
                $sub = [];
                foreach ($sg['items'] as $it) {
                    if (empty($it['show'])) continue;
                    $on = zas_menu_active($it['href']);
                    if ($on) $groupActive = true;
                    $sub[] = $it + ['on' => $on];
                }
                if ($sub) $rows[] = ['label' => $sg['label'], 'items' => $sub];
            }
        } else {
            $sub = [];
            foreach ($g['items'] ?? [] as $it) {
                if (empty($it['show'])) continue;
                $on = zas_menu_active($it['href']);
                if ($on) $groupActive = true;
                $sub[] = $it + ['on' => $on];
            }
            if ($sub) $rows[] = ['label' => '', 'items' => $sub];
        }
        if (!$rows) continue;
        $gid = 'zmg' . $gi;
    ?>
      <div class="zm-grp<?= $groupActive ? ' open' : '' ?>" data-g="<?= e($gid) ?>">
        <button class="zm-head" type="button" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>">
          <i class="zm-ico"><?= zas_menu_icon($g['icon'] ?? 'box') ?></i>
          <span><?= $g['label'] ?></span>
          <i class="zm-caret"></i>
        </button>
        <div class="zm-body">
          <?php foreach ($rows as $r): ?>
            <?php if ($r['label'] !== ''): ?><div class="zm-sub"><?= e($r['label']) ?></div><?php endif; ?>
            <?php foreach ($r['items'] as $it): ?>
              <a class="zm-item<?= $it['on'] ? ' on' : '' ?>" href="<?= e($it['href']) ?>"><?= $it['label'] ?></a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
      <p class="zm-empty" id="zmEmpty">No page matches that.</p>
    </nav>

    <style>
    .zm-find{padding:0 4px 10px}
    .zm-find input{width:100%;padding:8px 11px;border-radius:9px;border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.07);color:#eaf1ff;font-size:12px;font-family:inherit}
    .zm-find input::placeholder{color:#7f9ab9}
    .zm-find input:focus{outline:none;border-color:#0ea8c9;background:rgba(255,255,255,.12)}
    .zm{display:flex;flex-direction:column;gap:2px}
    /* Every rule below is written with two classes on purpose. The theme
       already styles `.nav a`, which is more specific than a single class
       and would otherwise override this menu — including a decorative dot
       via `.nav a:before` that has no place in a folder tree. */
    .zm .zm-ico{flex:0 0 auto;width:17px;height:17px;display:inline-flex;opacity:.85}
    .zm .zm-ico svg{width:17px;height:17px}
    .zm .zm-solo::before,.zm .zm-item::before,.zm .zm-head::before{content:none;display:none}
    .zm .zm-solo,.zm .zm-head{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border-radius:9px;
      border:1px solid transparent;background:transparent;color:#cfe0f5;font-size:12.5px;font-weight:600;
      font-family:inherit;text-align:left;cursor:pointer;text-decoration:none}
    .zm .zm-solo:hover,.zm .zm-head:hover{background:rgba(255,255,255,.07);color:#fff}
    .zm .zm-solo.on{background:rgba(14,168,201,.20);color:#fff;border-color:rgba(14,168,201,.45)}
    .zm .zm-head span{flex:1}
    .zm .zm-caret{width:0;height:0;border-left:4px solid currentColor;border-top:3.5px solid transparent;
      border-bottom:3.5px solid transparent;opacity:.6;transition:transform .18s ease}
    .zm .zm-grp.open .zm-caret{transform:rotate(90deg)}
    .zm .zm-body{display:none;padding:2px 0 6px 27px;margin-left:5px;border-left:1px solid rgba(255,255,255,.12)}
    .zm .zm-grp.open .zm-body{display:block}
    .zm .zm-sub{font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:#7793b5;font-weight:800;padding:9px 8px 4px}
    .zm .zm-item{display:block;padding:7px 9px;border-radius:7px;color:#a9c1dc;font-size:12px;font-weight:600;
      text-decoration:none;gap:0}
    .zm .zm-item:hover{background:rgba(255,255,255,.07);color:#fff}
    .zm .zm-item.on{background:rgba(14,168,201,.22);color:#fff}
    .zm-empty{display:none;color:#7f9ab9;font-size:11.5px;padding:10px;margin:0}
    .zm.filtering .zm-body{display:block}
    .zm.filtering .zm-caret{opacity:.25}
    .zm-hide{display:none !important}
    @media (prefers-reduced-motion:reduce){.zm-caret{transition:none}}
    </style>

    <script>
    (function(){
      var nav=document.getElementById('zmNav'), find=document.getElementById('zmFind');
      if(!nav) return;
      var KEY='zasMenuOpen';

      /* Remember which folders the user left open. The folder holding the
         current page always opens regardless of what was saved. */
      var saved={};
      try{ saved=JSON.parse(localStorage.getItem(KEY)||'{}'); }catch(e){ saved={}; }
      nav.querySelectorAll('.zm-grp').forEach(function(g){
        var id=g.dataset.g;
        if(g.classList.contains('open')) return;           // holds current page
        if(saved[id]) g.classList.add('open');
      });

      nav.addEventListener('click',function(ev){
        var head=ev.target.closest('.zm-head');
        if(!head||!nav.contains(head)) return;
        var g=head.parentNode, open=g.classList.toggle('open');
        head.setAttribute('aria-expanded',open?'true':'false');
        saved[g.dataset.g]=open;
        try{ localStorage.setItem(KEY,JSON.stringify(saved)); }catch(e){}
      });

      /* Type to filter. Shows every matching page across all folders at
         once, so a page three levels down is one keystroke away. */
      function filter(){
        var q=(find.value||'').trim().toLowerCase();
        var any=false;
        nav.classList.toggle('filtering',q.length>0);
        nav.querySelectorAll('.zm-grp').forEach(function(g){
          var hits=0;
          g.querySelectorAll('.zm-item').forEach(function(a){
            var m=!q||a.textContent.toLowerCase().indexOf(q)>-1;
            a.classList.toggle('zm-hide',!m);
            if(m) hits++;
          });
          g.querySelectorAll('.zm-sub').forEach(function(s){
            var n=s.nextElementSibling,shown=false;
            while(n&&!n.classList.contains('zm-sub')){
              if(n.classList.contains('zm-item')&&!n.classList.contains('zm-hide')) shown=true;
              n=n.nextElementSibling;
            }
            s.classList.toggle('zm-hide',!shown);
          });
          var headMatch=!q||g.querySelector('.zm-head span').textContent.toLowerCase().indexOf(q)>-1;
          if(headMatch&&q){ g.querySelectorAll('.zm-item').forEach(function(a){a.classList.remove('zm-hide');}); hits=1; }
          g.classList.toggle('zm-hide',q.length>0&&hits===0);
          if(hits) any=true;
        });
        nav.querySelectorAll('.zm-solo').forEach(function(a){
          var m=!q||a.textContent.toLowerCase().indexOf(q)>-1;
          a.classList.toggle('zm-hide',!m);
          if(m) any=true;
        });
        document.getElementById('zmEmpty').style.display=(q&&!any)?'block':'none';
      }
      find.addEventListener('input',filter);
      find.addEventListener('keydown',function(e){
        if(e.key==='Escape'){ find.value=''; filter(); find.blur(); }
        if(e.key==='Enter'){
          var first=nav.querySelector('.zm-item:not(.zm-hide), .zm-solo:not(.zm-hide)');
          if(first) window.location.href=first.getAttribute('href');
        }
      });

      /* "/" from anywhere jumps to the finder — the fastest way to reach
         any screen without lifting a hand off the keyboard. */
      document.addEventListener('keydown',function(e){
        if(e.key==='/'&&!/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName||''))&&!e.target.isContentEditable){
          e.preventDefault(); find.focus(); find.select();
        }
      });
    })();
    </script>
    <?php
}
