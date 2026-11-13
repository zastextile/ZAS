# ZAS Accounts — proposal for one master financial system

**Status: proposal only. Nothing here is built yet.** It is written to be
agreed (or changed) first, then demonstrated on sample data, then built phase
by phase — the same way People Finance was done.

## The idea in one paragraph

One set of books behind everything. The screens you already use — export
invoices, imports, gate and store, production, People Finance — keep working
as they do now; each one **posts automatically** into a single general ledger.
Accounts, Audit and HR work on a desktop. A HOD or the owner can approve and
look on a phone. Every entry is prepared, approved and kept; nothing is deleted,
only reversed with a reason.

## What you get, in the order I recommend building it

| Phase | What it adds | You can use it for |
|---|---|---|
| **1. Foundation** | Chart of accounts (a ready-made one for a textile exporter, editable) · journal vouchers with prepare → approve · opening balances · period lock · audit history · **Parties**: one master list of customers and suppliers with permanent IDs · currencies with the rate of the day | Trial balance and general ledger from day one |
| **2. Cash, bank, receivables, payables** | Cashbook · bank accounts · receipts and payments · advances · matching a payment to invoices · aging (who owes us, whom we owe, how old) · bank reconciliation · bank charges · exchange gain/loss | Running the money side daily |
| **3. Automatic posting** | Export invoice → receivable and sales · import / purchase → stock and payable · People Finance pay sheet → wages, advances, payment · job-work bills | No double typing; the books follow the operations |
| **4. Stock and manufacturing value** | Stock valued from the stock ledger · work in progress · finished goods · landed cost of imports · cost of goods sold when a shipment leaves | A true gross profit per shipment and per month |
| **5. Assets and taxes** | Fixed-asset register · monthly depreciation · withholding tax · sales tax · export rebate / duty drawback | Tax working papers and the asset schedule |
| **6. Statements and closing** | Profit and loss · balance sheet · cash flow · monthly closing checklist · year end | Monthly accounts the owner and the auditor can sign |

Phases 1 and 2 together are already a working accounting system. Each later
phase switches on by itself and does not disturb the earlier ones.

## How it stays compact

Seven screens, not seventy:

1. **Home** — cash and bank today, receivable, payable, what is waiting for approval.
2. **Vouchers** — one form for every kind (receipt, payment, journal, contra), with multi-line entry and paste from Excel.
3. **Parties** — a customer or supplier with their statement, open invoices and aging on one page.
4. **Cash & Bank** — cashbook, bank book, reconciliation.
5. **Ledger & Reports** — any account between any two dates; trial balance; P&L; balance sheet; cash flow; download to Excel.
6. **Closing** — the month-end checklist and the period lock.
7. **Setup** — chart of accounts, opening balances, currencies, who may do what.

Same roles as People Finance: Accounts prepares, Audit or Admin approves,
nobody approves their own voucher (except Admin). Desktop first; the approval
list and Home also work on a phone.

## What it fixes from the ten-point review

- **Customers matched by name (point 9)** — the Parties master gives every
  customer a permanent ID; shipments and customer logins point to it.
- **Old sales valued at today's exchange rate (point 5)** — each document
  keeps the rate of its own date.
- **Profit from incomplete costing (point 4)** — gross profit comes from stock
  actually consumed and landed cost, not from a costing sheet.

## What I need from you before the demo

1. **Start date.** From which date should the books begin? (A financial year
   start — 1 July — is cleanest; opening balances are entered as of that date.)
2. **Currencies.** PKR as the book currency, with USD and EUR invoices — any others?
3. **Tax.** Are you registered for sales tax? Do you deduct withholding tax on
   supplier and salary payments? Do you claim export rebate?
4. **Today's books.** Where are the accounts kept now — an accountant, Excel,
   other software? Do you want to run both side by side for the first month or two?
5. **Chart of accounts.** Shall I propose a standard one for a textile exporter
   for your accountant to adjust, or do you have your own list?

## Honest limits

- This is a large build. Phase 1 + 2 is the size of the whole attendance
  module; all six phases are several times that. It should go in one phase at a
  time, each tested and used before the next.
- I can build correct double-entry bookkeeping and the reports. I am not your
  auditor or tax adviser: the chart of accounts, the tax treatment and the
  opening balances should be reviewed by your accountant before you rely on them.
- People Finance was built as a simple ledger because that was what was asked
  for. It plugs into this without rework: an approved pay sheet becomes a
  voucher automatically (phase 3).

## Next step

Answer the five questions (short answers are fine). I then build a clickable
demo of phases 1 and 2 on sample data — Home, a voucher, a party statement,
cashbook, trial balance — for you to comment on before anything is added to
the live app.
