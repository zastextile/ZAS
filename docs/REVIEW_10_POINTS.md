# The ten-point review — what was confirmed, what was fixed, what is left

Checked against the code on 13 Nov 2026. Work is on branch
`claude/app-rebuild-cloudway-16ie6s`; nothing was deployed and no live data
was touched. Tests: `bash tests/run_all.sh` (see the last section).

| # | Point | Real? | Status |
|---|---|---|---|
| 1 | Permissions: Create / Edit / Delete; Gate edit may allow delete | **Yes** | **Fixed** on Gate, Opening Stock, Store, Consumption |
| 2 | Stock: duplicate posting from simultaneous requests | **Yes** | **Fixed** |
| 3 | Security: public `diagnose.php`, open cache-clear tool | **Yes** | **Fixed** |
| 4 | Profit: incomplete draft costing may inflate margins | **Partly** | **Fixed** (empty costing) + completeness shown |
| 5 | Currency: missing rate defaults to 1 | **Yes** | **Flagged** in red; historical rates → accounting module |
| 6 | Database: page-load schema changes; conflicting Customer role | **Yes** | Role conflict **fixed**; full move to migrations **not done** (see below) |
| 7 | Mobile: sidebar, clipped dashboard | **Yes** | **Fixed** |
| 8 | Login: sessions after a password reset | **Partly** | **Fixed** |
| 9 | Customers: matched by company name | **Yes** | **Not changed** — belongs with the customer master (accounting phase 1) |
| 10 | Testing: reproducible; permissions, concurrency, backup restore | **Yes** | **Done** |

## 1. Permissions — confirmed, fixed

User Access has separate ticks for Create, Edit and Delete, but the stock
screens asked one old question, `inv_perm('gate')`, which is true for a Create
tick **or** an Edit tick. So a person given only *Edit* could also create and
**delete** gate passes, and the *Delete* tick decided nothing.

Fix: `inv_may($module, $action)` in `includes/inventory.php`; `inv_gate.php`,
`inv_opening.php`, `inv_store.php`, `inv_consume.php` now ask for `c` on a new
document, `u` on an existing one, `d` to delete.

**After uploading:** anybody who needs to edit or delete drafts must have that
tick in *User Access* (Store & Inventory rows). Admins are unaffected.
Post and Reverse were already separate ticks and are unchanged. Contracts, Job
Work and the Item/Party masters still use one "may change" flag — not touched.

## 2. Stock — confirmed, fixed

Posting read the document's status, then wrote the stock rows — with nothing
stopping a second request doing the same in between. The test reproduces it:
8 simultaneous posts of one document wrote several stock rows.

Fix: every post, reverse and delete (`inv_gate_post`, `inv_gate_reverse`,
`inv_gate_delete`, `inv_opening_post`, `inv_opening_reverse`, `inv_store_post`,
`inv_consumption_post`, `inv_doc_reverse`) first takes a database lock named
after the document (`InvDocLock`). The second request waits, then finds the
document already posted and is refused. With the lock, the same 8 simultaneous
posts write exactly one row.

## 3. Security — confirmed, fixed

- `diagnose.php` answered anybody with the config path and database error
  text. Now: admins only in a browser (everyone else gets 404); still runs from
  SSH with `php diagnose.php`.
- `oc_clear_tmp.php` cleared the server's code cache for anybody, no login. It
  now only forwards an **admin** to `opcache_clear.php`. It can be deleted from
  the server.
- `health.php` only prints "PHP is working" — harmless, left alone.

## 4. Profit — partly real, fixed

The dashboard already treats a sale with **no** costing as break-even, so it
cannot inflate profit. The gap was a Final Costing that exists but is empty
(`total_cost = 0`): it counted as "costed" and showed a 100% margin. Now an
empty costing counts as not costed. Draft (not locked) costings that do carry
a cost are still counted, and the Total Cost tile says how many there are and
how much of the sales rests on them.

## 5. Currency — confirmed, flagged

A currency with no rate was converted at 1 with no warning (`dashboard.php`,
`costing_print.php`). Both now name the currency in a red notice. The sum still
has to use something, so the figure is still wrong until a rate is entered —
but it can no longer pass as right.

**Not changed:** the dashboard converts every period at *today's* rate, so last
year's USD sales are valued at this month's rate. Storing the rate of the day
on each invoice is the proper fix and is part of the accounting proposal.

## 6. Database — confirmed; one real hazard fixed

Two modules each replaced the list of user roles with their own on page load.
Production's list had no `customer` in it; on a server not in strict mode that
blanks the role of every customer account. `users_role_ensure()` now only adds
what is missing and never removes.

**Not done:** there are 16 `*_ensure_schema()` functions with about 177
`ALTER TABLE` statements that run from pages. It works, and rewriting all of it
at once on a live system is a bigger risk than leaving it. New work (Attendance,
People Finance) already uses proper migrations with rollback; the old modules
should move over one at a time, each with its own test.

## 7. Mobile — confirmed, fixed

On a phone the whole menu was laid out above every page. Below 900px it is now
a slim bar with a **Menu** button. The page column could not shrink below its
widest table, which cut content off at the right; it now fits the screen and
wide tables scroll inside their own box. Desktop is unchanged.

## 8. Login — partly real, fixed

There is no "remember me" in the app. The real gap: changing a password did not
end sessions already signed in as that user. Each session now carries a
fingerprint of the password it signed in with and ends when the password
changes. No database change; uploading the file signs nobody out.

## 9. Customers — confirmed, not changed

The customer portal shows invoices where the shipment's *buyer name* equals the
login's *company name* (ignoring case). A renamed or mistyped buyer hides
invoices; two logins with the same company name share them. Fixing it means a
customer master with permanent IDs on shipments and logins — a database change
plus a one-time matching of existing data, which should be reviewed by a person.
That customer master is the first thing the accounting module needs anyway.

## 10. Testing — done

- `bash tests/run_all.sh` runs every suite the same way each time; the
  database suites use a scratch MySQL schema and a temporary config.
- `tests/zfix_test.php` — permissions per action, 8-way simultaneous posting,
  security pages, role list, profit/currency, password change, phone layout.
- Backup and restore are tested in `tests/zatt_m8_test.php` (attendance and
  People Finance tables). The older ERP tables have no backup tool in the app.
