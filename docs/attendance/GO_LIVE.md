# Attendance — go-live checklist (Cloudways)

Paths below are for this server:
`/home/master/applications/eqjpvvvmyz/public_html/`. Run commands in
Cloudways SSH after `cd` into that folder.

## 1. Before uploading

- [ ] Cloudways › Backups › **Take backup now** (whole server, files and database).
- [ ] Note the current database step: `php att_migrate.php status`.
- [ ] Check the zip has **no** `config/` folder and no `config.php`. The live
      `config/config.php` is never replaced.

## 2. Upload and migrate

- [ ] WinSCP: upload the zip's `public_html/` contents over the server's
      `public_html/`. Same-named files are replaced; other files are kept.
- [ ] `php att_migrate.php up` then `php att_migrate.php status` — every line
      says `applied`.
- [ ] `php att_check.php` — the last line must say **READY**. Any `FAIL` line
      tells you what to do (`→ …`). Exit code is 1 while anything fails.
- [ ] ERP › Attendance › Setup › **System** shows the same list, plus three web
      probes. The two `.php` probes must be green. If the `.sql` probe is
      amber, ask Cloudways support to block `.sql` downloads (it shows table
      layout only — no data).

## 3. Server settings (Cloudways › Application Settings › PHP-FPM settings)

Recommended; the System tab warns until they are on. They apply to the
whole ERP, so try them out of hours and sign in again afterwards.

```
php_admin_flag[session.cookie_httponly] = on
php_admin_flag[session.cookie_secure] = on
php_admin_value[session.cookie_samesite] = Lax
php_admin_flag[display_errors] = off
```

## 4. Attendance backups (in addition to Cloudways backups)

Cloudways backs up the whole server. The attendance backup is a second,
smaller copy you can **test-restore** without touching live data.

1. Optional but recommended — encrypt the backups. In `config/config.php`
   add one line (a long random phrase; keep a copy somewhere safe, offline —
   without it the backups cannot be read):
   ```php
   'att_backup_key' => 'a long random phrase only the owner knows',
   ```
2. Make one now and prove it restores:
   ```
   php att_backup.php create
   php att_backup.php list
   php att_backup.php verify <file from the list>
   ```
   `verify` loads the backup into scratch tables, compares every table row
   for row, removes the scratch tables, and prints **RESTORE TEST PASSED**.
   Live data is not touched.
3. Backups go to `private_html/att-backups/` (outside the web folder). The
   newest 14 are kept.

Real restore (only after damage, and only with the owner's decision):

```
php att_backup.php restore <file> --yes-replace-live-attendance-data
```

It verifies the file first, backs up the current state, then replaces the
attendance tables. Nothing outside the `att_` tables is touched.

## 5. Scheduled jobs (Cloudways › Application › Cron Job Management)

```
15 2 * * *  cd /home/master/applications/eqjpvvvmyz/public_html && php att_backup.php create
30 2 * * *  cd /home/master/applications/eqjpvvvmyz/public_html && php att_cron.php
```

`att_cron.php` deletes flag photos older than the retention setting.
Setup › System shows when each last ran.

## 6. Setup before staff use it

- [ ] Setup › Shifts: a **Default shift** is chosen (otherwise scans park in HR).
- [ ] Setup › Employees: everyone added (or imported); photos where wanted.
- [ ] Setup › People: gatekeepers, HODs (with their departments), HR, Accounts.
- [ ] Each gate phone: sign in once, then Setup › Phones › **Rename** it
      ("Main gate", "Back gate").
- [ ] Run the UAT checklist (`UAT_CHECKLIST.md`) with real staff.

## 7. Rollback

- Code: upload the previous zip again.
- Database: `php att_migrate.php down --to=N` where N is the step noted in §1
  (each step has its own undo file; undoing overtime or photo steps deletes
  that data — take an attendance backup first).
- Whole server: Cloudways › Backups › restore the backup from §1.

## 8. Day to day

- Morning: Reports › Today — "Needs attention" (quiet phones, held scans,
  open entries, clock drift, overtime waiting).
- HR: Reconciliation — held scans, unmatched HOD confirmations, flags.
- Weekly: `php att_check.php`, and `php att_backup.php verify` on the newest file.
- A lost phone: Setup › Phones › **Withdraw**. Scans it never sent are lost
  (the app warns while it holds unsent scans) — record them by hand in HR.
