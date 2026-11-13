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
