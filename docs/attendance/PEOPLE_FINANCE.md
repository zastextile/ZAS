# People Finance — how to use it

Menu: **Attendance › People Finance (Pay)** — page `att_finance.php`.

Everybody is paid the same way, in three steps:

**Prepare → Approve → Pay**

| Kind of employee | When | What the system works out |
|---|---|---|
| **Fixed salary** | month end | Basic + allowance + approved overtime − unpaid leave − advance/loan recovery |
| **Daily wages** | weekly, Friday to Thursday | Days present (from Attendance) × daily rate. Under 4 hours inside = half a day |
| **Contractor** | any time | What Production has booked and not yet paid |

Pay day is **Thursday**. Paying on another day is allowed; the screen asks for the reason and keeps it.

## Who does what

| | Prepare | Approve | Record payment | Set up pay |
|---|---|---|---|---|
| **Admin** | everything | yes | yes | yes |
| **Audit** | fixed salaries | yes | — | — |
| **Accounts** | everything | — | yes | yes |
| **HR** | fixed salaries | — | — | yes |
| **HOD** | daily wages and contractors of **their own department** | — | — | — |

- Nobody but an admin approves a sheet they prepared themselves.
- A HOD never sees a salary.
- These follow the **jobs** given on *Attendance › Setup › People*. The new job there is **Audit**.
- To give one power to somebody without the whole job, tick it in *User Access* under **People Finance**.

## First-time setup (once)

1. **People tab** → open each person → choose *Fixed salary*, *Daily wages* or *Contractor* and type the amount. People with no pay set up cannot be put on a sheet; Home tells you how many are left.
2. **Opening balance** (People → a person → *Opening balance*): what the company owed them on the day you start. Only if there is something.
3. **Advances & loans** tab → *Give advance or loan* for anything already outstanding, with how much to recover from each pay.
4. **Contractors**: a contractor must exist as an employee with the **same code** as in *Production › Workers*. Once set to *Contractor* here, they leave the old *Worker Pay* screen and are paid here; everything paid there before is carried into their statement. Workers not moved yet stay on the old screen exactly as before.

## Preparing a pay sheet

1. **Prepare pay** → choose the kind → the dates are filled in (last month / the week to Thursday).
2. Everyone who can be paid is listed and ticked. Use the department filter, the search, *Tick all shown* / *Untick all*.
3. **Amend** on a row opens that person's **lines**. Each line has its own box:
   - change the amount of any line (overtime, unpaid leave, advance recovery, loan recovery…) — a **reason is required**, and the worked-out figure stays visible beside it;
   - **+ Bonus / + Other earning / + Deduction** adds a line (name, amount, reason);
   - **Basic salary is locked** — change it in People, with a reason, so the history is kept;
   - for a contractor or an old balance you may pay **part** of what is owed (no reason needed), never more.
4. *Add a line to everyone ticked* puts the same bonus or deduction on all ticked people at once.
5. **Send for approval**.

The server works every figure out again when you send; the page's running total is only a guide.

## Approving

**Pay sheets → Waiting** → open the sheet → *Lines* shows every line, what was worked out, what it was changed to and why.
The approver can **Amend** again (same editor), **Approve**, or **Send back** with a reason (the people can then be prepared again).

On approval the earnings and deductions go into each person's statement and advances are reduced.

## Paying

**Pay sheets → To pay** → open → **Record payment** (date; reason if it is not Thursday).
*Print pay slips* gives one slip per person with a signature line. *Download (Excel)* gives the list with bank/cash for the bank.

## Mistakes

- A sheet still **waiting**: amend it or send it back.
- A sheet **approved** by mistake: *Cancel sheet* (Admin or Audit), with a reason.
- A sheet **paid** by mistake: *Cancel sheet* (Admin only), with a reason.

Nothing is ever deleted. A cancelled sheet's lines stay in every statement, struck through, with the reason; recovered advances are put back; the people can be prepared again.

## Rules the system keeps

- No day is paid twice: a person cannot be on two salary or wage sheets that share a day.
- One open sheet per contractor at a time.
- A recovery never takes more than is still owed, and by default never more than the pay there is.
- Overtime paid here is only overtime that was **approved and checked** on the Overtime screen. Where Accounts typed a rate there, that amount is used; otherwise the person's *overtime rate per hour* from their pay setup. Staying late is never overtime.
- Unpaid leave = salary ÷ days in the month × days a shift was **assigned** and nobody came. If shifts are not assigned for salaried staff, the line is simply not there — add a *Deduction* line by hand when needed.
- Late arrival is shown in Attendance reports only; it is not deducted.
- Custom dates for a salary (someone joining or leaving): salary ÷ days in the month × days. Overtime, leave and recoveries are settled on the sheet that includes the last day of the month.

## Technical notes

- Migration `0017_finance.sql` (tables `att_fin_*`, the `audit` job, policies `fin_pay_weekday` = 4 and `fin_half_day_minutes` = 240). Rollback `rollback/0017_down.sql`.
- Engine `includes/att/FinanceService.php`; routes `/api/v1/finance/*`; screen `att_finance.php`.
- Money is whole paisa (`BIGINT`). The ledger (`att_fin_ledger`) is the balance: opening + earnings − deductions − payments over lines not cancelled.
- Production (`zp_entries`, `zp_pay`) is copied into the ledger idempotently (`source_key` `zpe:<worker>:<date>`, `zpp:<id>`), only when something changed, and matched to employees in PHP (the production tables may have a different collation).
- The attendance backup (`att_backup.php`) covers the `att_fin_*` tables.
- Tests: `tests/zatt_fin_test.php` (the rules) and `tests/zatt_finweb_test.php` (API, permissions, the screen in a real browser).

## Employee Ledger and entries (migration 0018)

The **Employee Ledger** tab shows everybody between two dates, or one person's
statement (balance brought forward, each line with its reference, running
balance, period totals), with Print / PDF and Excel export. Clicking a line
opens the pay sheet or entry it came from.

Four buttons make entries for one person or many:

| Button | What it makes | After approval |
|---|---|---|
| Add Earning | overtime, production incentive, bonus, allowance, other earning | **posted** to the ledger; paid with the next pay sheet ("Balance owed from before") or by Record Payment |
| Add Deduction | unpaid leave, advance recovery, loan recovery, other deduction | **posted**; never more than is owed to the person |
| Record Payment | part or all of what is owed, by cash or bank | **approved**, then Accounts records the payment (PAY- line) |
| Advance / Loan | the existing advance or loan dialog | recovered on the following sheets |

Every entry is an *entry sheet* (`att_fin_sheets.kind = 'entry'`, reference
`ENT-yymmdd-NN`) and waits for a second person's approval, exactly like a pay
sheet. Entries are not amended: they are sent back and entered again. A posted
entry can be cancelled by Audit or Admin; its lines are struck through.

**No money is promised twice.** `FinanceService::owedFree()` is the balance
less what approved sheets will pay and less what waiting sheets carry out of
the balance. A new pay sheet's "Balance owed from before" and a payment entry
can only take from that.

Migration 0018 widens `att_fin_sheets.kind` (adds `entry`) and `status` (adds
`posted`). Its rollback narrows them again only while no entry exists.

## Mark attendance (week grid)

People Finance › **Mark attendance**, for Admin and HR only (`caps.mark`). One
week (Friday to Thursday) of daily-wage workers, one department or all: each
day is F (full), H (half) or A (absent). "All Full for ticked" and "Fill a
whole day…" mark many at once; days, rate and wages are shown live.

Each changed day becomes an ordinary attendance entry (`EntryAdmin::add`,
source `admin`) at the full-day or half-day times on the screen, with the
reason ("Marked in the week grid: …") and the marker's name in
`att_corrections`. Changing a marked day replaces its entry; A removes it.
A half day must be shorter than `fin_half_day_minutes` and a full day at
least that long, so wages count them as marked.

Never changed in the grid: a day the gate scanner recorded (fix it in Setup ›
Entries), a day already on a daily-wages pay sheet (no day is paid twice —
use Add Earning for a missed day), and a day still to come.

## Who needs a second person, and who does not

| Action | Second person? |
|---|---|
| Give an advance or loan, change its plan, opening balance, mark attendance | No — direct, with the reason |
| **Deduction entry** (penalty, unpaid leave, other deduction, recovery taken early) | **No for Admin, Accounts, HR, Audit** (`caps.direct_deduct`): posted at once — an adjustment, never money going out, never more than is owed. A HOD's still waits. Policy `fin_direct_deductions = 0` switches this off. |
| Earning entry, payment entry, pay sheets | Yes — except the **administrator**, who may "approve now" in the same step (a payment is then also recorded as paid). Decided on the server from who is signed in. |

Adjustment entries (Add earning / Add deduction) may be made for anybody —
wage and contract people included — by Admin, Accounts, HR and Audit
(`caps.adjust`).

**Taking one person off a sheet** (`removeFromSheet`): from a waiting sheet,
by the approver, an admin or whoever prepared it; from an approved or posted
one, by Admin or Audit — their ledger lines are struck through with the reason
and recovered advances are put back. Never from a paid sheet. When the last
person goes the sheet is closed.

**A contractor's production** is never retyped on a pay sheet: it is corrected
in Production (the ledger follows), or adjusted on the sheet with
"± Production adjustment" — pieces × rate, added or taken off, its own line
with the reason. Booked production is offered once: a contractor has one open
sheet at a time, and a sheet only carries what is owed and not already on
another live sheet (`owedFree`).

**Mark attendance › Fill days…** sets one mark on one or several days for
every ticked worker.
