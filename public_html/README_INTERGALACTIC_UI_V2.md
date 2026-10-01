# ZAS Textile — Export Docs AI · Intergalactic UI V2.0

This is your existing PHP/MySQL application with the new **Intergalactic** dark
theme applied. **No business logic, database, forms, permissions, or endpoints
were changed** — only the presentation layer.

## Files changed (everything else is byte-for-byte your original)

1. `assets/css/app.css` — full dark futuristic theme. It reuses your existing
   CSS class names (`.app`, `.sidebar`, `.brand`, `.nav`, `.card`, `.btn`,
   `.form-grid`, `.field`, `.table-wrap`, `table`, `.badge`, `.alert`, `.kpi`,
   `.grid-2`, `.audit-entry`, `.search-line`, …) so **every page is re-themed
   with zero markup edits**.

2. `assets/js/app.js` — your original functions are untouched
   (`recalcInvoice`, `addInvoiceRow`, `recalcPacking`, `addPackingSelectRow`,
   `confirmLockedEdit`, …). Appended below them, in plain vanilla JS:
   - a single lightweight **starfield canvas** (auto-reduces on mobile, pauses
     when the tab is hidden, respects `prefers-reduced-motion`),
   - a **Web Audio sound engine** (muted by default, preference saved to
     `localStorage`, toggled by the speaker button top-right),
   - **Excel-style keyboard navigation** for any table with input cells
     (↑/↓ move rows, Enter / Shift+Enter next/prev row, ←/→ move columns only
     at the caret edge; Tab, mouse and touch keep working; hidden/disabled/
     read-only cells are skipped; typed data is preserved).

3. `includes/layout.php` — injects the starfield canvas, nebula layers and the
   sound-toggle button into `<body>`; brand shown as **ZAS Textile**.

4. `login.php` — reskinned login card with the animated AI orb (PHP/auth logic
   unchanged).

## Install
Drop this folder onto your server exactly where the old app lived (it is the
same structure). Keep your existing `config/config.php` and database. Clear the
browser cache once so the new `app.css` / `app.js` load.

## V2.1 — CSV Import rebuilt to match the approved demo
- `import_excel.php` is now the **CSV Import** page (nav + dashboard links renamed
  from "Standard Excel Import"). The old multi-sheet Excel template
  (`Data_Header / Data_Invoice_Items / Data_Packing_Items`) and its
  `templates/zas_standard_import_template.xlsx` are **removed**.
- Two per-table CSV templates are generated on the fly:
  - **Invoice Items** — product_name, des_col, category, optional_value, qty, unit, rate, amount
  - **Packing List** — product_name, des_col, package_type (CTN/Bale/Roll),
    package_from, package_to, total_packages, qty_per_package, total_qty,
    net_weight, gross_weight (old carton_* headers still accepted)
- Preview + validation + duplicate handling (default **Skip and import only new
  records**, or Update). Rows are **appended** to a chosen unlocked shipment;
  existing lines are never deleted/replaced. Shipment totals recalculate; every
  import is written to the audit log. Staff are blocked (Admin/Colleague only).
- Two safe, idempotent migrations run automatically (same try/catch pattern as
  the app's reopen columns): `shipment_items.category` and
  `packing_items.package_type`.

## V2.2 — full dark-theme parity on staff & packing screens
- `packing_list.php` ships its own inline `<style>` blocks (staff-mobile cards +
  PC compact entry) that were still light-themed. `app.css` now carries
  authoritative dark overrides for all `.staff-*` and `.pc-compact-*` classes,
  so staff mobile packing and the Admin/Colleague packing grid match the demo —
  glass cards, dark inputs, gradient buttons — with no change to that page's PHP
  or JS logic.
- Removed `default.php` (Hostinger placeholder page, not part of the app).
- `invoice_print.php` / `packing_print.php` stay light on purpose (A4 paper),
  which matches the demo's print output.

## V2.3 — AI Search visuals + real category→staff packing assignment
- **AI Search** (`search.php`): animated conic-gradient orb, hint line, shimmer
  "Ask" button, and the GPT answer now **types out** (typewriter) inside a dark
  glass "ZAS Textile AI" card with a blinking caret. Backend unchanged — still
  embeddings + gpt-5-mini over approved/locked records only; respects
  prefers-reduced-motion.
- **Real category → staff packing assignment** (new, server-enforced):
  - New `packing_assignments` table (shipment + category + user, one staff per
    category) created via safe migration; `shipment_items.category` column added.
  - Category is read from the stored `category` column, falling back to a
    product-name rule (Blankets/Towels/Apparel/Made-ups/Bed Linen) via
    `zas_category_of()` so it works on existing data.
  - `shipment_view.php` shows an **Admin-only "Packing Assignments"** card:
    each category → a staff/colleague dropdown (or "Unassigned"). Saved with an
    audit entry; assigning also grants the shipment assignment.
  - Enforced in `auth.php` (`can_pack_category`) and `packing_list.php`: staff
    see and can save **only their assigned categories**; direct-URL access to
    another category is blocked. If no assignments exist, whole-shipment
    behaviour is preserved (backward compatible).

## Notes
- Ownership / locked-shipment / audit rules remain enforced server-side in your
  PHP exactly as before — the UI only reflects them.
- Package-type totals, optional custom column, CSV import previews and the
  staff "My Saved Entries" screen shown in the design prototype are UI concepts
  approved separately; wiring them into these PHP pages is the next step if you
  want them in production.
