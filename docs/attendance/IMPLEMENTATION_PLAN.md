# ZAS Attendance & Overtime — Implementation Plan

Target: **PHP 8.2+ / MySQL 8 / offline-first PWA**, per approved baseline **v1.4**.
Sources of truth: `BLUEPRINT.md`, `API_CONTRACT.md`, `schema.sql`, `CLAUDE.md`.
**No approved business rule is changed by this plan.** Where the spec is silent, the
gap is listed in §10 as a decision, not filled in silently.

Status: **plan only — no code written yet.** M1 starts after decisions D1–D4 are answered.

---

## 0. What is already on the ground (repo reality check)

This is not an empty repository. Before planning anything I inspected it, as
`CLAUDE.md` requires ("first inspect existing repository... do not overwrite
existing code without review").

| Fact | Detail |
|---|---|
| Existing app | ZAS ERP — exports, imports, costing, production, inventory |
| Size | **242 PHP files, ~89,500 lines**, live on Cloudways |
| Style | Flat-file pages in `public_html/`, shared logic in `public_html/includes/` |
| DB access | `db()` → PDO, `ERRMODE_EXCEPTION`, assoc fetch, **`EMULATE_PREPARES=false`** |
| Bootstrap | `includes/bootstrap.php` — config, Redis session, helpers, auth, access, layout |
| Auth | `current_user()`, `require_login()`, `require_admin()` on `users` |
| Permissions | **`zu_perm`** — per-user, per-module, action letters `v c u d p r`; `zu_can()` / `zu_require()`; admin bypasses |
| Access windows | `zu_window_state()` — per-user allowed days/hours, enforced in `require_login()` + `verify_csrf()` |
| CSRF | `csrf_token()` / `csrf_field()` / `verify_csrf()` in `includes/helpers.php` |
| Sessions | Redis-backed with documented file fallback |
| Migrations | **No migration runner.** Idempotent `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` in `try/catch`, run on boot |
| Tests | 67 standalone scripts in `tests/`, pattern `z<topic>_test.php`, `ok($cond,$msg)` counters, sources read from `tests/app_src/`; some drive a real browser via Playwright. **No PHPUnit, no runner script** |
| **Existing PWA** | **`public_html/sw.js` + `manifest.json` already exist**, root scope, deliberately never caches `.php` |
| Mobile screens | `m.php`, `m_gate.php`, `m_gate_photo.php` (material gate pass + camera) |
| Secrets | `public_html/config/` and `config.php` are gitignored; live on server only |

### Toolchain verified in this environment

| Need | Status |
|---|---|
| PHP 8.2+ | **PHP 8.3.6** ✅ |
| `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `sodium`, `intl`, `zip` | all present ✅ |
| MySQL 8 for real integration tests | **`mysql-server 8.0.46` installable via apt** ✅ (exact target version) |
| Node (browser tests) | v22.23.4 ✅ |
| Composer | present, **plugins disabled in this sandbox** ⚠️ (affects AWS SDK install — see §7) |

### Name collisions — the two hard blockers

`schema.sql` defines tables that **already exist in production with different shapes**:

| `schema.sql` wants | Production already has | Conflict |
|---|---|---|
| `users` (`username`, `role ENUM('gatekeeper','hod','hr','accounts','admin')`, `department_id` FK, BIGINT PK) | `users` (`name`, **`email`** login, `role ENUM('admin','colleague','staff')` + `customer`/`production_staff`, `department` **VARCHAR**, INT PK, plus `can_see_rates`, `acc_*` window columns) | **Hard collision.** Creating this drops/【breaks】 live logins |
| `audit_logs` (`actor_id`, `action`, `subject_type`, `subject_id`, `old_data JSON`, `new_data JSON`) | `audit_logs` (`shipment_id`, `section_changed`, `field_changed`, `old_value`, `new_value`, …) | **Hard collision.** Shipment audit trail would break |

Adjacent, overlapping but **not** colliding:

- **`zp_workers`** — production worker master (`worker_code`, `worker_name`, `department`, `is_active`), synced from an external HR app by token. Covers people *on machines*, not office staff.
- **`inv_departments`** — department master (`name`, `is_active`).
- **`inv_gate` / `m_gate.php`** — "gate" already means **material** gate pass here. The new employee gate must not reuse that word bare, or every future reader will confuse the two.

`README.md` in the handoff explicitly says the SQL is *"a baseline to refine in
migrations"* requiring *"integration review"*. Renaming tables to fit this
repository is therefore sanctioned by the spec and changes **no business rule**.

---

## 1. Decisions (D1–D4) — ANSWERED 2026-11-10

| # | Decision | Answer |
|---|---|---|
| **D1** | Where the system lives | **A — integrate into the existing ZAS app.** Shared DB, shared `users` login, `zu_perm` permissions, `att_` prefix on every new table |
| **D2** | Employee master | **New `att_employees`**, with optional `worker_code` link to `zp_workers`. Covers office staff; existing HR-token sync untouched |
| **D3** | Roles | **Via `zu_perm` modules + `att_user_roles`.** Live `users.role` enum is not widened |
| **D4** | Gate IN with no shift assigned | **Admit, and attach a configurable default shift.** Nobody is stopped at the gate; HR corrects later. Default shift is a policy row, not hard-coded |

The rest of this section records the reasoning behind those answers.

### D1 — Where does this system live? ★ primary decision

**Option A — integrate into the existing ZAS app (recommended).**
Same `public_html`, same database, same `db()`, same Redis session, same WinSCP
deploy. New tables all prefixed `att_`. Reuse `users` for login and `zu_perm`
for permissions.

- ✅ One login for HR/Accounts/Admin, who already live in this app daily
- ✅ `zu_perm` **already delivers** blueprint §1's "permissions separately configurable"
- ✅ Departments and a worker master already exist
- ✅ Access windows, CSRF, audit habits, caching already built and tested
- ⚠️ Touches a live production system → every migration must be additive and reversible

**Option B — standalone app** (own folder/subdomain, own DB, own users).

- ✅ Zero risk to the live ERP
- ❌ Second set of user accounts for the same people, maintained by hand
- ❌ Rebuilds auth, permissions, CSRF, sessions, audit from scratch
- ❌ Employee master duplicated and drifting

**Recommendation: A.** The spec's own roles (hr, accounts, admin) are people
already using this app. B means they log in twice to do one job.

### D2 — Employee master

The attendance system needs every employee with a gate code — including office
staff, who are **not** in `zp_workers` (production only).

**Recommendation:** new **`att_employees`** as the attendance master, with an
optional `worker_code` link to `zp_workers` so production and attendance agree on
the same person where both know them. Does not disturb the existing HR-token sync.

### D3 — Roles

Spec roles are `gatekeeper, hod, hr, accounts, admin`; production `users.role` is
`admin, colleague, staff` (+`customer`, `production_staff`).

**Recommendation:** do **not** widen the live `role` enum (it gates 56 existing
write screens). Instead:
- authorisation via **`zu_perm` modules** (§5) — the mechanism already in place,
- plus `att_user_roles` mapping a user to attendance roles + department scope.

Spec requirement "server-side role and department authorization on every
endpoint" is met exactly; it simply rides existing infrastructure.

### D4 — Gate IN when the employee has no shift assigned ★ blocks M2

`entry_cycles.shift_instance_id` is `NOT NULL`, so every Gate IN needs a shift
instance. The spec never says what happens when someone scans in with no shift
assigned for that day — a daily reality in a factory.

Options: (a) auto-create an instance from a default shift; (b) accept the event
but hold the cycle for HR; (c) reject at the gate. **(c) stops people entering
the factory and is almost certainly wrong.** My recommendation is **(a) with a
configurable default shift, falling back to (b)** — but this is a business rule,
so **you decide**, and it will be configurable either way (per blueprint §10).

---

## 2. Repository plan (assuming D1 = A)

Follows existing conventions: flat pages, logic in `includes/`, `att_` namespace
everywhere. `public_html/api/` and `public_html/att/` are both confirmed free.

```
public_html/
  att/                              ← PWA scope: gatekeeper + HOD (own SW scope)
    index.php                       app shell (role-aware)
    manifest.webmanifest            scope /att/, own identity, installable
    sw.js                           offline shell cache  (scope /att/ ONLY)
    app/ db.js sync.js ui.js scan.js
  api/v1/                           ← REST, JSON, session + CSRF
    index.php                       front controller → routes
  includes/att/
    Migrations.php  Repo.php  Policy.php  Audit.php
    AttendanceService.php           gate IN/OUT, cycles, 60s rule, transitions
    HodService.php                  confirmations, bulk, pending match
    SyncService.php                 envelope intake, idempotency, receipts
    OvertimeService.php             request → approve → verify → rate → finalise
    PhotoService.php                flag + R2 signed upload
    Reconciler.php                  pending/ambiguous resolution
  att_hr_reconciliation.php         desktop screens, existing flat convention
  att_ot_requests.php att_ot_approve.php att_ot_verify.php att_ot_payroll.php
  att_employees.php att_shifts.php att_reports.php
  sql/att/0001_*.sql … NNNN_*.sql   ← versioned, forward-only migrations
tests/
  zatt_*_test.php                   ← existing naming convention
docs/attendance/                    ← this plan + runbooks
```

**Migrations:** the repo's boot-time `try/catch ALTER` habit cannot express
ordering, rollback, or "has this run?", which the spec explicitly requires
("database migrations", "rollback plan" per milestone). I will add a small
runner — `att_migrations` table + numbered SQL files, applied by CLI, never on
page load. This is **new infrastructure for the new module only**; existing
tables keep their current mechanism untouched.

---

## 3. Schema & migration plan

Every new table is `att_`-prefixed. **No existing table is dropped or altered**
except two additive, nullable columns (M1-03).

### Mapping from `schema.sql`

| `schema.sql` | Becomes | Change & why |
|---|---|---|
| `departments` | `att_departments` | Prefixed. Seeded from `inv_departments`; kept separate so attendance scoping can't be changed by an inventory edit |
| `employees` | `att_employees` | + `worker_code` (nullable FK-ish link to `zp_workers`), + `qr_payload`, + `notice_ack_at` (privacy notice, §6) |
| `users` | **not created** — reuse live `users` | Collision. Attendance roles → `att_user_roles` |
| `devices` | `att_devices` | + `secret_hash`, `last_seen_at`, `app_version`, `pin_hash`; `revoked_at` kept (remote revoke, §6) |
| `shifts` | `att_shifts` | + `break_minutes`, `crosses_midnight`, `grace_in_minutes`, `grace_out_minutes` (blueprint §10 configurability) |
| `shift_instances` | `att_shift_instances` | **+ `UNIQUE(employee_id, starts_at)`** — original has none, so a double assignment would silently create two cycles streams |
| `attendance_events` | `att_events` | **immutable.** + `device_time`, `timezone`, `clock_skew_ms`, `source ENUM('online','offline_replay')`, `provisional` — blueprint §3 requires skew + provisional distinction; the original schema has nowhere to put them |
| `entry_cycles` | `att_entry_cycles` | Keeps the **stored generated `open_employee_id` + UNIQUE** trick — this is what makes "one open cycle per employee" true at the database level under concurrency. Verified valid on MySQL 8 |
| `hod_arrivals` | `att_hod_arrivals` | Keeps `UNIQUE(entry_cycle_id)` (one confirmation per cycle, NULLs allowed while pending). + `match_method`, `matched_at`, `ambiguity_reason` (AT05 evidence) |
| `sync_receipts` | `att_sync_receipts` | **+ `reason_code`, + `response_json`** — `API_CONTRACT` requires a replay to return the *same* receipt; without the stored response, a retry after a dropped connection can't be answered identically |
| `overtime_requests` | `att_ot_requests` | + `planned_start`, `planned_end` (blueprint §4 "optionally planned start/end" — absent from original schema) |
| `overtime_request_employees` | `att_ot_request_employees` | + `decision_version` (optimistic lock for the approval race, AT09) |
| `overtime_payroll` | `att_ot_payroll` | + `locked_at`; finalise = lock (AT11) |
| `attendance_photos` | `att_photos` | + `mime`, `bytes`, `sha256`, `uploaded_by`, `retention_until` (§6: MIME by inspection, size limits, retention) |
| `audit_logs` | **`att_audit_logs`** | Collision. Same columns as spec (`actor_id`, `action`, `subject_type`, `subject_id`, `old_data JSON`, `new_data JSON`, `reason`) |

### New tables the spec requires but `schema.sql` omits

| Table | Why it is mandatory |
|---|---|
| `att_user_roles` | D3. Attendance role + department scope per user |
| `att_corrections` | Blueprint §5: "corrections append audit entries, not destructive edits" — needs a home |
| `att_ot_amendments` | Blueprint §4: changes after decision, and actual > approved, "require audited amendment" |
| `att_policies` | Blueprint §10: shifts/breaks/OT windows/who-verifies **"configurable rather than hard-code"** |
| `att_migrations` | Migration runner bookkeeping |
| `att_rate_limits` | §6 rate limits on login + sync intake |

### Migration order (M1)

```
0001_departments_employees.sql     att_departments, att_employees
0002_roles_devices.sql             att_user_roles, att_devices
0003_shifts.sql                    att_shifts, att_shift_instances
0004_policies_audit.sql            att_policies, att_audit_logs, att_migrations seed
0005_link_columns.sql              ADDITIVE ONLY: users.att_enabled TINYINT NULL,
                                   zp_workers.att_employee_id BIGINT NULL
0006_rate_limits.sql               att_rate_limits
```
Every file has a paired `rollback/NNNN_down.sql`. 0005 is the only file touching
an existing table, and both columns are nullable with no default behaviour change.

---

## 4. Endpoint map

All under `/api/v1`, JSON, HTTPS, session cookie + CSRF, every route
server-side authorised. **Client-supplied operator/role IDs are never trusted**
(`API_CONTRACT` final line) — actor always comes from the session.

| Method | Route | Role (via `zu_perm`) | Idempotent | Milestone |
|---|---|---|---|---|
| POST | `/auth/login` | public, rate-limited | — | M1 |
| POST | `/auth/logout` | any | — | M1 |
| GET | `/auth/me` | any | yes | M1 |
| GET | `/employees?query=` | gate, hod, hr, admin | yes | M1 |
| GET | `/employees/{code}` | gate, hod, hr, admin | yes | M1 |
| GET | `/employees/sync-snapshot` | gate, hod | yes | M4 |
| GET | `/shifts` | hr, admin | yes | M1 |
| POST | `/shifts/assignments` | hr, admin | by (emp, date) | M1 |
| POST | `/attendance/in` | gatekeeper | **by `event_uuid`** | M2 |
| POST | `/attendance/out` | gatekeeper | **by `event_uuid`** | M2 |
| GET | `/attendance/movements` | gate, hod, hr | yes | M2 |
| GET | `/attendance/entry-cycles` | hod, hr | yes | M2 |
| GET | `/hod/pending` | hod (own dept only) | yes | M3 |
| POST | `/hod/confirm` | hod (own dept) | **by `event_uuid`** | M3 |
| POST | `/hod/confirm-bulk` | hod (own dept) | **one uuid per cycle** | M3 |
| GET | `/hod/movements` | hod (own dept) | yes | M3 |
| POST | `/sync/events` | gate, hod | **by `event_uuid`** | M4 |
| GET | `/sync/status` | gate, hod | yes | M4 |
| GET | `/sync/changes?cursor=` | gate, hod | yes | M4 |
| POST | `/attendance/{cycle}/flag` | gatekeeper | by cycle | M5 |
| POST | `/attendance/{cycle}/photo-upload-url` | gatekeeper | — | M5 |
| POST | `/attendance/{cycle}/photo-confirm` | gatekeeper | by object key | M5 |
| POST | `/overtime/requests` | hod | by `request_uuid` | M6 |
| GET | `/overtime/requests` | hod, hr, accounts, admin | yes | M6 |
| POST | `/overtime/requests/{id}/decisions` | **hr \| accounts \| admin (any ONE)** | first commit wins | M6 |
| GET | `/overtime/verification` | hr, accounts, admin, (gatekeeper if permitted) | yes | M6 |
| POST | `/overtime/requests/{id}/verify-hours` | as above, next day | by request_employee | M6 |
| POST | `/overtime/requests/{id}/amend` | hr, admin (audited) | — | M6 |
| POST | `/overtime/payroll/rate` | accounts, admin | — | M6 |
| POST | `/overtime/payroll/finalize` | accounts, admin | **locks** | M6 |
| GET | `/hr/reconciliation` | hr, admin | yes | M4 |
| GET | `/reports/attendance\|movements\|overtime` | hr, accounts, admin | yes | M7 |

**Event envelope** exactly as contracted:
`{event_uuid, device_id, device_sequence, employee_code, event_type, occurred_at, timezone, payload}`
→ `{event_uuid, receipt_status: ACCEPTED|ALREADY_RECEIVED|PENDING_MATCH|REJECTED|NEEDS_REVIEW, server_event_id, reason_code}`

Idempotency is enforced by **two** database constraints, not by application
checks: `att_events.event_uuid UNIQUE` and `UNIQUE(device_id, device_sequence)`.
A duplicate insert raises `23000`, which is caught and answered
`ALREADY_RECEIVED` **with the originally stored response**.

---

## 5. Permission matrix

New `zu_perm` modules (group "Attendance"), reusing the existing letters:

| key | name | actions |
|---|---|---|
| `attgate` | Attendance — Gate IN/OUT | `vc` |
| `atthod` | Attendance — HOD Confirmation | `vc` |
| `attflag` | Attendance — Flag & Photo | `vc` |
| `attot` | Overtime — Request | `vcu` |
| `attotap` | Overtime — Approve/Reject | `vu` |
| `attotvf` | Overtime — Verify Actual Hours | `vu` |
| `attpay` | Overtime — Rate & Payroll | `vu` + finalise |
| `atthr` | Attendance — Reconciliation & Corrections | `vud` |
| `attrep` | Attendance — Reports | `v` |
| `attadm` | Attendance — Employees, Shifts, Devices | `vcud` |

| Capability | gatekeeper | hod | hr | accounts | admin |
|---|:--:|:--:|:--:|:--:|:--:|
| Gate IN / OUT | ✅ | — | — | — | ✅ |
| Flag + photo | ✅ | — | — | — | ✅ |
| Confirm arrival | — | ✅ own dept | — | — | ✅ |
| Raise OT request | — | ✅ own dept | — | — | ✅ |
| **Approve/reject OT** | — | — | ✅ | ✅ | ✅ |
| Verify next-day hours | ⚙️ if granted | — | ✅ | ✅ | ✅ |
| Amend after decision | — | — | ✅ | — | ✅ |
| Rate + finalise payroll | — | — | — | ✅ | ✅ |
| Reconciliation queue | — | — | ✅ | — | ✅ |

⚙️ = blueprint §4 explicitly allows "explicitly permitted gatekeeper" — a
`zu_perm` tick, off by default. **Department scoping for HOD is enforced in SQL
on every query**, never in the UI alone (AT06).

---

## 6. PWA cache & sync strategy

### Service-worker scope — constrained by what already exists

`public_html/sw.js` is registered at **root scope** and deliberately never caches
`.php`. The attendance PWA needs a genuinely offline shell, which contradicts
that policy. Resolution:

- New SW at **`/att/sw.js`, scope `/att/` only**. A narrower scope wins for
  pages under it, so the root SW keeps governing the rest of the ERP unchanged.
- Separate `/att/manifest.webmanifest` (`scope:/att/`, `start_url:/att/`) so the
  gate device installs **"ZAS Gate"** as its own app, not the production app.
- **No change to the existing root `sw.js` or `manifest.json`.**

### Caching

| Content | Strategy |
|---|---|
| `/att/` shell (HTML/CSS/JS/icons) | Precache on install, cache-first, versioned cache name |
| Employee lookup snapshot | IndexedDB, refreshed via `/employees/sync-snapshot`, **only employees the device is permitted to see** |
| Any `/api/v1/*` | **Never cached.** Network-only; writes go to the outbox |

### Offline write path (blueprint §3, invariant 4)

1. User acts → event written to IndexedDB **inside one transaction**, with
   `event_uuid` (UUIDv4), `device_id`, monotonic `device_sequence`, actor,
   employee, type, device time + timezone, `sync_state='pending'`.
2. **Only after that transaction commits** does the UI say "saved" — and it says
   *"saved on this device"*, not *"recorded"*, until the server acknowledges.
3. Replay on app open, foreground, reconnect, and periodic timer. Background
   Sync is an optimisation only, never the sole trigger.
4. A local event is deleted **only** on a server receipt. `ALREADY_RECEIVED`
   counts as success (that is the whole point of the UUID).
5. Pending count always visible; a queue older than a configurable threshold
   raises a visible warning.
6. `navigator.storage.persist()` requested on first run.

### Reconciliation (AT03–AT05)

- HOD confirmation arriving **before** its gate event → stored
  `status='pending_match'`, `entry_cycle_id NULL`. Never guessed.
- Matching order: (1) explicit gate `event_uuid` if the device knew it;
  (2) otherwise employee + shift + time sequence **within a configurable window**.
- **More than one plausible cycle → `status='ambiguous'` → HR queue.** Never
  auto-matched. This is AT05 and it is a hard rule.
- Server records `received_at`, canonical `occurred_at`, and clock skew; offline
  replays are marked `provisional` until reconciled.

### Honest limits (mandated by `CLAUDE.md`, and true)

A lost phone, cleared browser data, or OS storage eviction **can lose events
that were never synced**. Persistent-storage requests and pending-count warnings
reduce the window; they do not close it. **This plan does not claim zero offline
data loss and the UI will not imply it.** If that risk is unacceptable for the
gate, the mitigation is the on-premise sync gateway in blueprint §3 — a separate
decision (§10).

---

## 7. Security controls

| Control | Implementation |
|---|---|
| AuthZ | `zu_require('attgate','c')` etc. on **every** endpoint, before any work |
| Dept scoping | Enforced in the SQL `WHERE`, every HOD/report query |
| Actor identity | From session only; client role/operator fields ignored |
| SQL | PDO prepared statements, `EMULATE_PREPARES=false` (already the repo default) |
| CSRF | Existing `verify_csrf()`; JSON routes take the token via `X-CSRF-Token` |
| Sessions | Existing Redis sessions; **rotate id on login** |
| Rate limits | `att_rate_limits` on login + `/sync/events` |
| Devices | Registered, `secret_hash`, device PIN, **revocable** (`revoked_at` checked per request) |
| Transport | HTTPS only; `Secure`/`HttpOnly`/`SameSite=Lax` cookies |
| R2 | Private bucket, keys **server-side only**, short-lived signed URLs, MIME verified by `finfo` (not the client's claim), size cap, `sha256`, retention date |
| Camera | **Only** on Capture & Flag. Normal attendance never opens a camera (invariant 6, AT07) |
| Images | Never public; every access audited |
| Biometrics | **None.** No automated identification |
| Secrets | Nothing in JS bundles; config stays in gitignored `config/` |

**Composer note:** plugins are disabled in this sandbox, so the AWS SDK may not
install here. R2 (M5) is S3-compatible, so the fallback is a small hand-written
SigV4 signer (~150 lines, no dependency) — decided at M5, not before.

---

## 8. Test strategy

Tests follow the repo's existing convention — `tests/zatt_*_test.php`, `ok()`
counters — so they run the same way as the other 67. Added on top:

- **A real MySQL 8.0.46** instance (installable here, matching the production
  target) for integration tests. SQLite is *not* adequate: the one-open-cycle
  guarantee depends on a MySQL **stored generated column + UNIQUE**, and the
  approval race depends on **`SELECT … FOR UPDATE`**. Both must be tested on the
  real engine or they are not tested at all.
- A runner, `tests/run_att.sh`, since no suite runner exists today.

### Acceptance tests → automated coverage

| ID | Rule | Test |
|---|---|---|
| AT01 | 3 IN/OUT pairs → 3 cycles, 3 HOD requirements | `zatt_cycles_test.php` |
| AT02 | 60s repeat + cross-device duplicate blocked | `zatt_dup_test.php` |
| AT03 | Gate offline → HOD online reconciles | `zatt_recon_test.php` |
| AT04 | HOD offline → gate online reconciles | `zatt_recon_test.php` |
| AT05 | Ambiguous → HR, **never guessed** | `zatt_ambig_test.php` |
| AT06 | Bulk HOD, once per cycle, own dept only | `zatt_bulk_test.php` |
| AT07 | No photo on normal entry; flagged photo private | `zatt_photo_test.php` |
| AT08 | Late checkout alone ≠ overtime | `zatt_ot_rules_test.php` |
| AT09 | One approval authoritative; **concurrent race safe** | `zatt_ot_race_test.php` (parallel processes, real locks) |
| AT10 | Next-day actual hours; excess needs amendment | `zatt_ot_verify_test.php` |
| AT11 | Manual rate, payroll lock, audit | `zatt_payroll_test.php` |
| AT12 | Unsynced device loss stated as a real risk | `zatt_honesty_test.php` — asserts the UI never claims guaranteed delivery |

Plus: midnight-spanning shifts, timezone/DST, out-of-order replay, clock skew,
invalid transitions (IN while inside / OUT while outside), and payroll lock
enforcement.

---

## 9. Milestones

Each: files touched → acceptance → rollback. **M1 only after D1–D4.**

| M | Scope | Acceptance | Rollback |
|---|---|---|---|
| **M1** | Migration runner, `att_*` masters, auth/roles, employees, shifts | Migrations up+down clean; `/auth/*`, `/employees*`, `/shifts*` pass; zero change to existing screens | Run `0001–0006_down`; drop `att_*`; the 2 added columns are nullable, safe to leave |
| **M2** | Gate IN/OUT, cycles, 60s rule, transitions | AT01, AT02 | Down-migrate M2 tables; M1 intact |
| **M3** | HOD per-cycle + bulk, dept scoping | AT06 | as above |
| **M4** | IndexedDB outbox, `/sync/*`, reconciliation, HR queue | AT03, AT04, AT05, AT12 | PWA is additive; server routes removable |
| **M5** | Flag + camera + R2 | AT07 | Feature-flag off; photos retained per policy |
| **M6** | OT request → approve → verify → rate → finalise | AT08–AT11 | Down-migrate OT tables |
| **M7** | Dashboards & reports | Read-only; no new writes | Delete pages |
| **M8** | Integration, UAT, backup/restore drill, hardening, deploy | Full suite + restore proven | Documented restore |

---

## 10. Open decisions (blueprint §10) — configurable, never hard-coded

Needed from ZAS/IT before the milestone shown:

| # | Question | Needed by |
|---|---|---|
| 1 | Real shift times, break policy, grace periods | M1 |
| 2 | **D4** — Gate IN with no assigned shift | **M2** |
| 3 | Reconciliation time window for implicit HOD matching | M4 |
| 4 | OT approval time windows | M6 |
| 5 | May a gatekeeper enter next-day actual hours? | M6 |
| 6 | Which roles may amend after decision | M6 |
| 7 | Photo retention period; privacy notice wording | M5 |
| 8 | Device inventory, PIN policy, revocation owner | M4 |
| 9 | Backup/restore RPO/RTO; on-premise sync gateway? | M8 |
| 10 | Hosting confirm: Cloudways + WinSCP, MySQL 8 vs MariaDB | M1 |

Defaults ship in `att_policies` and are editable by Admin — nothing above is
baked into code.

---

## 11. Risks

| Risk | Severity | Handling |
|---|---|---|
| `users` / `audit_logs` collisions | **High** | Resolved by D1/D3 + `att_` prefix. Migration 0005 is additive-only |
| Live ERP disruption | **High** | No existing table dropped/altered destructively; module behind `zu_perm`, dark until ticked |
| Unsynced events lost with a device | **Medium** | Mitigated, **not eliminated** — stated plainly (AT12) |
| Two PWAs on one origin | Medium | Narrower `/att/` scope; root SW untouched |
| Clock skew on gate devices | Medium | Server canonical time + recorded skew; device time stored but never trusted |
| MariaDB instead of MySQL 8 on Cloudways | Medium | Decision #10. Generated column + `FOR UPDATE` work on both, but must be verified on the real target before M8 |
| Composer/AWS SDK unavailable | Low | Hand-written SigV4 fallback at M5 |

---

## 12. M1 — delivered 2026-11-10

**99 assertions green** (`tests/run_att.sh`): 63 domain/migration + 36 API over
real HTTP, against **MySQL 8.0.46** — the exact production target.
Existing suite re-run: **67 clean, 0 regressions** (`zgateskin_test.php` fails
2 one-pixel CSS assertions both before and after this work).

| Delivered | Where |
|---|---|
| Migration runner (up / down / status, checksum-guarded, CLI-only) | `includes/att/Migrations.php`, `att_migrate.php` |
| 6 migrations + 6 rollbacks | `sql/att/`, `sql/att/rollback/` |
| Masters, roles, policy, audit, time | `includes/att/` |
| REST API, M1 routes | `api/v1/index.php` |
| zu_perm modules registered | `includes/access.php` (additive) |
| Tests + runner | `tests/zatt_m1_test.php`, `zatt_api_test.php`, `run_att.sh` |

**Proven, not assumed:**
- One open entry cycle per employee — enforced by MySQL (generated column +
  UNIQUE). Re-entry after close allowed; 3 IN/OUT pairs → 3 cycles, 1 open.
  **AT01's foundation is real before M2 is written.**
- `up → down → up` leaves `users` and `zp_workers` **byte-for-byte identical**
  (asserted against `SHOW CREATE TABLE`).
- A HOD gets `EMPLOYEE_NOT_FOUND` — the *same* answer as a non-existent code —
  when probing another department, so the endpoint cannot map the workforce.
- Login gives one message for a wrong password and an unknown email.
- Editing an already-applied migration is refused.
- A deleted `duplicate_window_seconds` policy row falls back to **60**, never 0.

**Two real bugs this caught, both now fixed:**
1. `zu_can($m, $a, $id)` with an explicit id **skips the admin bypass and the
   access-window check** — only `zu_can($m, $a)` applies them. My first draft
   passed the id and silently denied every admin. Documented in `Roles::can()`.
2. `Att\Roles::can()` originally returned `true` when `zu_can()` was absent,
   "for testability" — that would have made a half-uploaded `includes/`
   directory an open door. Now fails closed except on CLI.

**Rollback:** `php att_migrate.php down --to=0`.

## 13. M2 — delivered 2026-11-10

**162 assertions green** across the three suites (M1 64, M2 51, API 47).
Existing ERP suites: 65 clean, 0 regressions.

| Delivered | Where |
|---|---|
| `att_events` (immutable), `att_entry_cycles`, `att_sync_receipts` + rollbacks | `sql/att/0007–0009` |
| Gate IN/OUT, 60-second rule, transitions, receipts, race rescue | `includes/att/AttendanceService.php` |
| `POST /attendance/in\|out`, `GET /attendance/movements\|entry-cycles` | `api/v1/index.php` |
| Tests | `tests/zatt_m2_test.php`, `zatt_api_test.php` §8b |

**Acceptance:**
- **AT01** ✅ three IN/OUT pairs → three cycles, each owing its own HOD
  confirmation, sequences 1-2-3, exactly one open.
- **AT02** ✅ same person on a second gate seconds later is rejected
  `DUPLICATE_WITHIN_WINDOW`; judged on `occurred_at`, so a late-arriving offline
  scan an hour apart is still accepted.
- **Race** ✅ two real PHP processes opening a cycle for one employee at the
  same instant: one `ACCEPTED`, one `REJECTED` (not a 500), exactly one open
  cycle. Enforced by the unique key, not by PHP.

**Behaviour worth knowing:**
- A replay returns the **original** receipt, including a rejection — never a
  fresh evaluation that could now say the opposite.
- A `REJECTED` event is HTTP **200** with a receipt: a settled decision, so the
  phone's outbox stops retrying it.
- The operator is always the signed-in user; a client-sent `operator_id` is
  ignored (tested).
- A revoked handset is stopped on its **next event**, not at session expiry.
- Device clock skew is stored, never corrected; an unreadable device time goes
  to `NEEDS_REVIEW` rather than becoming "now".

**Bugs found and fixed while building it:**
1. D4 auto-shift created a shift instance *before* checking it covered the
   scan, leaving an orphan "scheduled day" for out-of-hours scans. Now checks
   first; an out-of-hours scan goes to review and writes nothing.
2. The migration runner silently let two files share a version number (one
   overwrote the other, the next FK failed on an unrelated table). It now
   refuses duplicate versions outright.

## 14. M3 — delivered 2026-11-10

**235 assertions green** (M1 64, M2 51, M3 62, API 58). Existing ERP suites:
65 clean, 0 regressions.

| Delivered | Where |
|---|---|
| `att_hod_arrivals` + rollback | `sql/att/0010` |
| Confirm, bulk confirm, pending list, history, cycle matching | `includes/att/HodService.php` |
| Shared receipts + device gate (used by gate and HOD) | `includes/att/Receipts.php` |
| `GET /hod/pending\|movements`, `POST /hod/confirm\|confirm-bulk` | `api/v1/index.php` |
| Tests | `tests/zatt_m3_test.php`, `zatt_api_test.php` §8c |

**Acceptance:**
- **AT06** ✅ bulk confirms each cycle once, each with its own receipt; another
  department's cycle inside a bulk request is refused; a replayed bulk request
  writes nothing new. Department scope comes from `att_user_roles` on the
  server — a `department_id` or `hod_user_id` sent by the client is ignored.
- **Race** ✅ two real processes confirming one cycle: one `ACCEPTED`, one
  `REJECTED ALREADY_CONFIRMED`, exactly one row (unique key on `entry_cycle_id`).
- **AT05 groundwork** ✅ when only the employee is known: one candidate cycle
  inside `hod_match_window_minutes` → confirmed (`match_method=sequence`);
  none → `PENDING_MATCH`; two or more → `NEEDS_REVIEW / AMBIGUOUS_MATCH`,
  nothing linked. The HR screen that resolves these is M4.

**Behaviour worth knowing:**
- Cycles that already closed still appear as owing a confirmation.
- A confirmation writes no gate event and never changes presence minutes.
- A HOD outside the cycle's department gets `CYCLE_NOT_FOUND`, the same answer
  as for a cycle that does not exist.
- Device role is enforced both ways: a gate handset cannot post confirmations
  and a HOD handset cannot record gate movements.
- A confirmation naming a gate event the server has not seen yet waits for
  that event; it does not settle for another entry that happens to be there.
- A device is optional on HOD confirmation (an admin may confirm from a
  desktop); when named, it must be registered, live and HOD-scoped.

**Test flaw found and fixed:** M2's clock-skew step used the real clock with a
09:00–17:00 default shift, so it passed or failed by time of day. It now uses a
round-the-clock shift for that step.

## 15. M4 — delivered 2026-11-11

**372 assertions green** (M1 64, M2 51, M3 62, M4 74, API 58, browser 63).
Existing ERP suites: no regressions (`zgateskin_test.php` fails 2 of 80 on a
1px CSS check with or without this work — it predates the module).

| Delivered | Where |
|---|---|
| Review columns, `att_corrections`, device revoke columns, 3 policies + rollback | `sql/att/0011` |
| Upload batches, device registration/revocation, status, changes feed | `includes/att/SyncService.php` |
| Matching waiting HOD confirmations to gate entries | `includes/att/Reconciler.php` |
| HR queue and decisions (each needs a reason, each is audited) | `includes/att/HrService.php` |
| `/devices/*`, `/sync/events\|status\|changes`, `/hr/reconciliation[/resolve]` | `api/v1/index.php` |
| Gate / HOD phone app (installable, works offline) | `public_html/att/` |
| HR Reconciliation screen | `public_html/att_hr_reconciliation.php` |
| "Attendance" menu group | `includes/menu.php` |
| Tests | `tests/zatt_m4_test.php`, `tests/zatt_pwa_test.php` (real Chromium) |

**Acceptance:**
- **AT03** ✅ scans made offline are kept on the phone across a reload, upload
  when the network returns, and each gets exactly one server record; the
  screen says "saved on this phone" until the server's receipt arrives and
  only then "Recorded by the server".
- **AT04** ✅ an upload replayed after a lost response returns the original
  receipts and writes nothing new (`event_uuid` and `(device, sequence)` are
  unique in MySQL, receipts are stored verbatim).
- **AT05** ✅ a HOD confirmation that arrives before its gate entry waits and
  is linked when the entry arrives; with two or more possible entries nothing
  is linked — it goes to HR, who choose one or set it aside, with a reason.
- **AT12** ✅ the app never claims the data is safe before a receipt; the
  footer states what is still only on the phone and that clearing the browser
  or losing the phone before upload loses it.

**Behaviour worth knowing:**
- Matching never runs in the middle of an upload (`more=true`), so a
  half-uploaded phone cannot cause a wrong link.
- Device clock drift is measured from the phone's clock at upload time, stored,
  and shown to HR above `clock_skew_warn_ms`; times are never silently
  corrected.
- Withdrawing a phone stops it on its next event; what it already uploaded
  stays.
- Every database connection the module uses is pinned to UTC
  (`Db::useUtc()`), so column defaults are UTC even if the server's MySQL is
  set to Karachi time.

**Known limit (decision 3 in §10):** two different offline gate phones holding
entries for the same employee, uploading at different times, are only kept
apart by the match window. Keep one gate phone per gate where possible.

**Not built yet:** screens to manage employees, departments, shifts, roles and
policies (M7). Until then they are set through the API or directly in the
database.

## 16. Admin screens — delivered 2026-11-11 (pulled forward from M7)

Pulled forward at the owner's request: without them nobody can be scanned,
so nothing built so far could be tried at the gate.

**541 assertions green** (M1 64, M2 51, M3 62, M4 74, API 58, phone app 63,
setup 169). Existing ERP suites unchanged.

| Delivered | Where |
|---|---|
| Employee code / worker code widened to 40 characters + rollback | `sql/att/0012` |
| All setup rules | `includes/att/AdminService.php` |
| `/admin/overview\|departments\|employees\|workers\|shifts\|users\|jobs\|settings` | `api/v1/index.php` |
| Setup screen: Start here, Employees, Departments, Shifts, People, Settings | `public_html/att_setup.php` |
| Menu: Attendance › Setup | `includes/menu.php` |
| Tests (service, HTTP, Chromium) | `tests/zatt_admin_test.php` |

**What the screen refuses, and why:**
- Changing the code of an employee who already has scans — phones hold scans
  by code while offline.
- Making somebody inactive while they are inside the factory — their exit
  scan would be refused and the entry would stay open.
- A code that is another employee's card text, or the reverse — the gate
  looks a scan up as "code or card text".
- Switching off a department that has active employees, or the default shift.
- Re-pointing a day that already has entries at another shift, by an edit or
  by a bulk assignment. Changing a shift's hours moves only days that have
  not started.
- An import overwriting somebody already on the roll: such lines are skipped
  and reported. The list is always checked first; nothing is written until
  the second button.

**Access:**
- Departments, employees, shifts: the `attadm` permission (view / create /
  update; "delete" = make inactive, nothing is ever deleted).
- People and Settings: ERP admin accounts only. A setup clerk cannot give
  themselves a job or change a setting (tested over HTTP).
- Giving a job (Gatekeeper, HOD of a department, HR, Accounts) ticks the
  matching User Access permission through the ERP's own `zu_save_perms()`;
  taking the last such job away un-ticks it, so removing HR really removes
  the access. If that cannot be done the screen says so.
- The 60-second duplicate rule is shown and cannot be edited.

**Additions outside API_CONTRACT.md:** the `/admin/*` routes. The contract
covers phones and reports and leaves masters to the implementation; nothing a
phone uses depends on them.

**Found and fixed while building:**
- The ERP stylesheet gives every table `min-width:980px`. On a phone that
  pushed the HR Reconciliation screen (M4) and this one sideways. Both now
  undo it and scroll a wide table inside its own box; a browser test checks
  every tab at 390px.

**Still to come in M7:** attendance and movement reports, dashboards, device
list on the setup screen (withdrawing a phone is on the HR screen today).

## 17. M5 — delivered 2026-11-11

**758 assertions green** (M1 64, M2 51, M3 62, M4 74, API 58, phone app 63,
setup 173, M5 213). Existing ERP suites unchanged.

| Delivered | Where |
|---|---|
| `att_flags`, reference-photo bookkeeping columns, 2 policies + rollback | `sql/att/0013` |
| Private storage through the ERP's existing R2 client; folder listing | `includes/att/Storage.php` |
| Inspect by content, then re-make: fixed size, WebP | `includes/att/Images.php` |
| Reference photos, bring-in from a bucket folder, flags, stills, review, retention | `includes/att/PhotoService.php` |
| `/employees/{code}/photo`, `/flags*`, `/attendance/{cycle}/flag\|photo-upload-url\|photo-confirm`, `/hr/flags*`, `/admin/photos/*`, `/admin/employees/photo*` | `api/v1/index.php` |
| Phone: photo on code/scan (gate and HOD), Capture & Flag with offline queue | `public_html/att/` (app 1.1.0, shell v2) |
| Setup: Photo… per employee, Photos tab, retention setting | `att_setup.php` |
| HR: Flagged entries, photo on file beside photo taken at the gate | `att_hr_reconciliation.php` |
| Timed clean-up (CLI only) | `att_cron.php` |
| Tests + stand-in bucket | `tests/zatt_m5_test.php`, `tests/fake_r2.php` |

**Acceptance — AT07 ✅**
- *No photo for a normal entry.* Five scans and a HOD confirmation leave the
  bucket empty; the gate-event, entry and HOD tables have no column a picture
  could go in; a still without a flag is refused; the app has one picture
  input and it is inside `openFlag()`.
- *Flagged photo private.* Random object key that never leaves the server; no
  link is ever produced; HR-only over HTTP; every view audited.

**Owner's requirements (2026-11-11):**
- *The employee's photo shows when a gatekeeper or HOD types or scans a
  code.* Shown beside the name before IN / OUT / Confirm is pressed, and from
  the HOD's waiting list. Tested in Chromium on both phones.
- *Strict uploads: cropped, fixed size, modern format, no large files.* Every
  reference photo is stored at exactly 480 × 600 WebP (about 30 KB) whatever
  was uploaded; a flagged still is fitted inside 1280 and not cropped. No
  original is kept anywhere. Limits: 10 MB in, 40 megapixels, 160 × 200
  minimum. JPEG is written only where the server's GD cannot write WebP.
- *Online-only on the phone.* Photos are fetched each time (`no-store`), never
  in the service-worker cache or IndexedDB; offline the box says so and the
  name still shows.

**Decisions taken while building, each stricter than the blueprint text:**
1. **No signed URL is handed to a phone.** The blueprint says "signed
   short-lived access". The ERP's own storage layer already refuses to give a
   browser a signed link (it is a forwardable bearer token), so pictures are
   read by the server after the permission and department checks and sent on.
2. **`photo-upload-url` answers with an address on this server**, not a
   pre-signed bucket URL. The bytes are inspected and re-made before anything
   is stored, and no bucket CORS setup is needed.
3. **Phones flag by the scan's own id** (`POST /flags`), because offline they
   cannot know the entry number; the three contract routes exist for callers
   that do. A scan the server refused can still be flagged (no entry, known
   employee).
4. **Storage reused, not duplicated:** the four `r2_` lines already in
   `config.php` for documents. Attendance writes only under `attendance/`;
   the hand-over folder is `employee-photos/` and is only ever read.

**Found and fixed while building:**
- A HOD given the job from the People tab held only the HOD permission, and
  the employee list/lookup routes asked for the gate permission — so a HOD's
  phone could not load its list and showed no name for a typed code. Those
  routes now accept either permission; the department scope still applies.
- The test helper that waited for the phone's employee list was satisfied by
  an unresolved promise, which is how the above went unseen in M4.

**Not in M5 (and why):**
- Flagging from the HOD screen — the blueprint puts Capture & Flag at the gate.
- Face matching — the crop is a fixed rule (centre, favouring the top), shown
  back on the Setup screen so a bad one is seen at once.
- Retention for reference photos of leavers — only flagged stills have a
  retention period today. Open decision for the owner.

## 18. M6 — delivered 2026-11-11

**915 assertions green** (M1 64, M2 51, M3 62, M4 74, API 58, phone app 63,
setup 173, M5 213, M6 157). Existing ERP suites unchanged.

| Delivered | Where |
|---|---|
| Requests, lines, decision batches, history, payroll runs, payroll, corrections; 5 policies + rollback | `sql/att/0014` |
| Request → decision → next-day verification → rate → finalize → correction | `includes/att/OvertimeService.php` |
| All ten overtime routes in API_CONTRACT.md, plus `/overtime/employees`, `…/dispute`, `/overtime/lines/{id}/history`, `/overtime/payroll` (view), `/overtime/payroll/correct`, `/overtime/payroll/runs[/{id}/export]` | `api/v1/index.php` |
| Desktop: New request, Requests, Verify hours, Payroll | `att_overtime.php` |
| HOD phone: Overtime tab (online only) | `att/app.js` 1.2.0, shell v3 |
| Jobs tick the overtime permissions; six settings | `includes/att/AdminService.php` |
| Tests, incl. a two-process approval race and a browser run | `tests/zatt_m6_test.php` |

**Acceptance:**
- **AT08** ✅ An employee five hours late with no request, and one with a
  request nobody approved, are on no verification list, owe nothing, and no
  line anywhere has actual minutes. No column in the overtime tables is ever
  filled from a gate record; the gate history is shown beside the empty
  "actual minutes" box, never copied into it (checked in the browser).
- **AT09** ✅ Two real processes decide the same employee at the same moment
  (approve vs reject): exactly one wins, the other gets `ALREADY_DECIDED`, one
  decision in the history. A decision replayed with the same id returns the
  first answer. A later change is an amendment with a reason.
- **AT10** ✅ Minutes cannot be entered on the work date itself; next day,
  more than approved is refused (`EXCESS_REQUIRES_AMENDMENT`) until an officer
  amends the approval. A dispute parks a line for an amendment to settle.
- **AT11** ✅ The rate is typed (hourly or fixed); there is no wage column to
  derive one from. Amounts are counted in whole paisa. Finalizing refuses
  while anything in the period is undecided, unchecked, disputed or unrated,
  and refuses if the total differs from the one on the screen. After it, the
  line is locked (`PAYROLL_LOCKED`) — including 0-minute lines — and a
  correction is a new row beside the finalized figure, which never changes.

**Decisions taken while building (open to the owner):**
1. **One live overtime line per employee per work date**, kept by a unique key.
   Without it two HODs could request the same evening and it could be paid
   twice. More time on the same evening is an amendment.
2. **Who does what, by default:** HOD requests; HR and Accounts approve and
   verify; Accounts rates and finalizes; Admin can do everything. A
   gatekeeper verifies only with the verify permission AND the setting on.
   Amending verified minutes is for HR / Accounts / Admin only.
3. **Windows:** request up to 14 days ahead and 1 day back; decisions without
   a time limit; actual minutes from the next day. All on Settings.
4. **Overtime requests are online only** on the phone, with a request id kept
   until the server answers so a retry is never a second request. They are
   not gate records and do not go through the offline queue.
5. **The payroll export escapes spreadsheet formulas** in text cells.

**Not in M6:** a HOD withdrawing a request before it is decided (an officer
rejects it instead); separate approval limits per officer; overtime reports
across months (M7).

## 19. M7 — delivered 2026-11-11

**1007 assertions green** (M1 64, M2 51, M3 62, M4 74, API 58, phone app 83,
setup 173, M5 216, M6 157, M7 69). Existing ERP suites unchanged.

| Delivered | Where |
|---|---|
| Today, attendance, movements, overtime — read-only | `includes/att/ReportService.php` |
| `/reports/dashboard`, `/reports/attendance\|movements\|overtime` (+ `?format=csv`), `/admin/devices/{id}/label` | `api/v1/index.php` |
| Reports screen: Today, Attendance, Movements, Overtime | `att_reports.php` |
| Setup › Phones: list, rename, withdraw | `att_setup.php` |
| HOD, HR and Accounts jobs include Reports; menu entry | `AdminService.php`, `includes/menu.php` |
| Tests, incl. night shift across midnight, grace boundaries, scope, CSV, browser | `tests/zatt_m7_test.php` |

**Definitions the reports use (and say on screen):**
- *Present minutes* = closed IN–OUT entries only. An open entry is "inside".
  An HOD confirmation is never an IN (blueprint §5) — tested.
- *Late* = first IN after shift start + grace. *Left early* = last OUT before
  shift end − grace with nothing open; labelled **potential** (blueprint §2).
  Exactly on a grace limit is neither.
- *Absent* = a shift day that was assigned and has no entry. A default shift
  is attached only on arrival (D4), so "no entry today" is shown separately
  and includes days off.
- *Overtime* = approved and next-day-checked only (AT08 on the report side).
- A night shift is reported under the day it began.

**Scope:** HR, Accounts and Admin see every department and get the "Needs
attention" panel (blueprint §7 monitoring: quiet phones as the server-side
sign of a sync backlog, held scans, unmatched HOD confirmations, entries left
open, clock drift, failed photo uploads, overtime waiting). A HOD sees only
their departments and no system monitoring. Every CSV download is audited.

**Not here:** backup monitoring — Cloudways takes the backups; the panel says
so. All API_CONTRACT.md routes are now built (no 501s left).

## 20. M8 — delivered 2026-11-11

**All attendance suites green, incl. `tests/zatt_m8_test.php` (61).** Existing
ERP suites unchanged.

| Delivered | Where |
|---|---|
| Readiness check: server, database, files, security, setup, day-to-day; each failure says how to fix it; recognises a test config | `includes/att/SystemCheck.php`, `att_check.php`, Setup › System, `/admin/system-check` |
| Attendance backup: consistent snapshot, per-table fingerprints, optional AES-256-GCM (`att_backup_key`), keeps 14 | `includes/att/AttBackup.php`, `att_backup.php create\|list` |
| Restore test into scratch tables (live untouched); real restore behind a long flag, after a safety backup | `att_backup.php verify\|restore` |
| Hardening: X-Frame-Options, Referrer-Policy on the API; CSP on the phone app; `sql/att/.htaccess`; CLI tools 404 over the web | `Http.php`, `att/index.html`, `sql/att/.htaccess` |
| Go-live and UAT documents | `GO_LIVE.md`, `UAT_CHECKLIST.md` |

**What M8 tests prove:**
- Every route except login/logout checks the user; every POST except login
  checks CSRF *before* permissions — read from the router source, so a new
  route without them fails the build.
- No attendance page or phone script builds HTML from strings; no key or
  password in any browser file; services never read `$_GET/$_POST` directly.
- Backup → verify passes; a backup with rows missing, or cut short, fails;
  encrypted backup needs the right key; restore after damage gives back the
  exact rows and the one-open-entry key.
- Blueprint §7: 240 gate scans, 8 at a time over two gates, p95 under 1 s.
- AT02 across devices: the same person IN at two gates at the same instant,
  20 times — always exactly one accepted.

**AT traceability**

| Test | Automated in | UAT step |
|---|---|---|
| AT01 | `zatt_m2_test.php` | UAT AT01 |
| AT02 | `zatt_m2_test.php`, `zatt_m8_test.php` (two gates, HTTP) | AT02a, AT02b |
| AT03 | `zatt_m4_test.php` | AT03 |
| AT04 | `zatt_m4_test.php` | AT04 |
| AT05 | `zatt_m3_test.php`, `zatt_m4_test.php` | AT05 |
| AT06 | `zatt_m1_test.php`, `zatt_m3_test.php`, `zatt_api_test.php` | AT06 |
| AT07 | `zatt_m5_test.php` | AT07 |
| AT08 | `zatt_m6_test.php`, `zatt_m7_test.php` | AT08 |
| AT09 | `zatt_m6_test.php` (two processes) | AT09 |
| AT10 | `zatt_m6_test.php` | AT10 |
| AT11 | `zatt_m6_test.php` | AT11 |
| AT12 | `zatt_pwa_test.php` | AT12 |

**Left for people, not code:** the UAT with real staff and phones
(`UAT_CHECKLIST.md`), the PHP-FPM cookie settings, choosing an
`att_backup_key`, and the two cron lines (`GO_LIVE.md` §3–5).

## 21. Gate screen and Automatic OUT — delivered 2026-11-12 (owner's requests)

**Gate screen (phone 1.4).** One button only: green IN for someone outside,
red OUT for someone inside — from the central server when online (every
gate agrees), else from the phone's own queue and employee list. Nothing
shown until a code is typed; success messages fade; "Not right?" shows the
other button. The server still decides.

**Automatic OUT (migration 0015).** New rule from the owner: an entry still
open `auto_out_after_minutes` (240) after shift end + *approved* overtime is
closed at shift end + approved overtime, `close_source = 'auto_shift_end'`,
`auto_closed_at` = when. No gate event is written; overtime is never created
(a person who came in after that time is closed at their IN, 0 minutes).
Runs on phone syncs, Reports › Today and HR Reconciliation (at most every 5
minutes), from `att_cron.php`, and on the spot when the person's next IN
arrives. A real OUT scanned before the system closed the entry replaces it;
one scanned after is refused. HR can re-time a system OUT as a correction.
Shown on Reports › Today, attendance/movements reports and CSVs, HR, the
overtime check, and the gate phone. Tests: `tests/zatt_autoout_test.php`.

**Admin entries (migration 0016).** The administrator adds, edits and deletes
entries from Setup › Entries; gate events are never altered and a copy of every
change is kept. Tests: `tests/zatt_entries_test.php`.

## 22. People Finance — delivered 2026-11-13 (owner's request)

Pay for three kinds of employee through one pipeline, **prepare → approve →
pay**, designed with the owner over five demo rounds. The user guide is
`docs/attendance/PEOPLE_FINANCE.md`; the decisions are these.

- **Fixed salary** (month end; custom dates = salary ÷ days in month × days),
  **daily wages** (weekly Friday–Thursday, days present from Attendance,
  under `fin_half_day_minutes` = half a day), **contractor** (the unpaid
  production balance). Pay day `fin_pay_weekday` = Thursday; another day needs
  a reason.
- **Every line is amended by itself** with a required reason; the worked-out
  figure stays beside the changed one. Lines can be added (bonus, other
  earning, deduction). Basic salary is not amendable on a sheet.
- **Roles**: Admin all; Audit (new job) prepares salaries and approves;
  Accounts prepares all and pays; HR prepares salaries; HOD prepares wages and
  contractors of their own department. Nobody but an admin approves their own
  sheet. Jobs open the module by themselves; `finprep/finwage/finapp/finpay`
  in User Access give single powers.
- **One ledger** (`att_fin_ledger`) is the balance. Earnings and deductions
  post on approval, the payment on pay. Nothing is deleted: a sheet approved or
  paid by mistake is cancelled with a reason and its lines stay, struck out.
- **Production**: `zp_entries` and old `zp_pay` payments are copied in
  idempotently. A worker set up as a contractor leaves the old Worker Pay grid
  (`production_pay.php` refuses them); the others stay there unchanged.
- The server works out every figure; the browser sends only which line to
  change, to what, and why.

Migration `0017_finance.sql`. Tests: `tests/zatt_fin_test.php` (111) and
`tests/zatt_finweb_test.php` (72, API + the screen in a browser).
