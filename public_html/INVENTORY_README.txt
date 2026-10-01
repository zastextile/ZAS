ZAS TEXTILE — STORE & INVENTORY ADD-ON
Complete package: ALL STAGES 1-6
=====================================================================

BEFORE YOU UPLOAD
-----------------
Back up ONE file: includes/layout.php
That is the only existing file this package replaces. If anything looks
wrong, restoring that single file returns the menu to exactly how it was.

Everything else in this zip is a new file that did not exist before.


HOW TO INSTALL
--------------
1. Extract this zip into public_html/ , keeping the includes/ and sql/
   folder structure.
2. Log in as admin. The sidebar is now a folder tree.
3. Open  Administration > Inventory Setup
4. Press "Install inventory schema".
5. Check the health panel: all 16 tables should show a row count.
   If any says "missing", stop and tell me before going further.
6. Press "Create N materials" to build the Item Master from the fabric,
   accessory and packing lines already in your costings.
7. Give staff access in the same page under "Who can use the module".
   Nobody has any access until you tick the boxes. Admin always does.


WHAT IS SAFE ABOUT THIS
-----------------------
* Every new table is prefixed  inv_
* The ONLY change to an existing table is seven permission columns added
  to `users`, all defaulting to 0 — no user gains access on install day.
* No row in shipments, proformas, costings, products, packing, or any
  production table is read, written or altered by the installer.
* The production module is not touched at all. No hook, no trigger, no
  new column. Your Cutting / Stitching / Dispatch stages remain purely
  wage and progress tracking, and never move stock.
* To remove the module completely: drop the inv_ tables (the SQL file in
  sql/ lists them) and restore the old includes/layout.php.


THE RULES THIS MODULE FOLLOWS
-----------------------------
1. STOCK MOVES IN ONLY THREE DOCUMENTS
     Gate Inward    -> material in
     Consumption    -> material out, finished product in
     Gate Outward   -> stock out
   Store Issue and Store Return move stock between LOCATIONS only.

2. ONLY POSTED DOCUMENTS AFFECT STOCK
   Draft and Verified change nothing anywhere. A draft appears in the
   register so it is not lost, but it is excluded from every quantity,
   every balance and every contract figure.

3. A POSTED DOCUMENT IS NEVER EDITED OR DELETED
   Corrections are made by reversing it, which writes an opposite set of
   entries with a reason, a user and a timestamp. The original stays put,
   so the history reads as what happened, then the correction.

4. OWNERSHIP IS SEPARATE FROM LOCATION
   Your material at a job worker  -> still yours: counted AND valued.
   Customer material in your store -> theirs: counted, NEVER valued.
   This is what stops your stock value being inflated by goods you do
   not own and cannot sell.

5. BALANCES ARE NEVER STORED
   Every quantity on screen is summed live from the stock ledger, so no
   cached figure can drift out of step with the movements behind it.


WHAT IS IN THIS RELEASE  (everything - nothing is left to come)
---------------------------------------------------------------
Masters       Item Master (fabric / accessories / packing)
              Parties (suppliers, customers, job workers)
              Locations, settings, permissions
Contracts     Purchase, Sales, Job Work we send, Job Work we do
Transactions  Gate Inward / Outward (13 types), Store Issue,
              Store Return, Consumption
Stock         Current Stock, Stock Ledger with running balance,
              Floor Balance, customer-owned held separately
Costing       Order Costing Control - quoted vs committed vs actual
              vs projected, per order, while it is still running
Job work      Charges both ways: what you bill customers, and what
              processors bill you (checked against your gate)
Control       Production Exceptions - the wage-fraud checks
Printing      Gate Inward Pass, Gate Outward Pass


THE THREE-DOCUMENT MODEL, IN PRACTICE
-------------------------------------
  Gate Inward   -> material arrives                    stock +
  Store Issue   -> store hands material to a floor     location change only
  Consumption   -> materials out, finished product in  THE CONVERTER
  Store Return  -> unused material comes back          location change only
  Gate Outward  -> goods leave the gate                stock -

Consumption is the ONLY document that creates finished product stock.
Your production stages create none, whatever you name them - which is
exactly why comparing the two is meaningful.


ORDER COSTING CONTROL - NOTHING IS TYPED
----------------------------------------
Quoted     the costing version the order was priced on
Materials  posted consumption documents
Wages      production_transactions.amount - you have always recorded
           this; it has simply never been read as a cost before
Other      freight / commission you book on the order page
Selling    proforma_items

The only figure the module adds is the comparison, and the projection:
what the order will cost at completion if the pieces still to make
behave like the ones already made.


PRODUCTION EXCEPTIONS - WHAT IT CAN AND CANNOT SEE
--------------------------------------------------
Your existing save routine already refuses: claiming more than the
order, claiming a later stage before an earlier one, splitting an
over-claim across rows, editing the rate, working on an unassigned
order, future dates, and deleting a worker with history.

The gap those rules cannot close is TWO PEOPLE CLAIMING THE SAME
PHYSICAL WORK, because it still fits inside the order total. That is
what this report is for. Its strongest check compares the floor's
claims against what the STORE posted - two records, two people, no
shared control. If stock were created automatically by the production
stages, the two would always agree and the check would be worthless.

The report writes nothing and needs no change to any production table.


THE MENU
--------
The sidebar is now a folder tree. Two things worth knowing:

  Press  /  from anywhere  -> jumps to the "Find a page" box.
                              Type two letters, press Enter, you are there.

  The folder holding your current page opens itself, and whichever
  folders you leave open are remembered in that browser.

Role visibility for your EXISTING pages is unchanged. It was checked by
rendering the menu for all four roles with the inventory module switched
off, and comparing link counts against the old menu: production staff 4,
staff 3, colleague 9 — identical. Admin additionally sees Cost Audit and
Inventory Setup, which are new admin-only pages.

With inventory permissions granted, a user also sees the Store & Inventory
group - 20 pages, all of which were checked to resolve to a real file.


A NOTE ON TESTING
-----------------
The PHP was syntax-checked and the stock posting logic was tested against
a stubbed database. Verified:
  * customer-owned material always posts at ZERO value, both sides
  * all six gate movement shapes, incl. job work netting to zero across
    locations (the material stays yours, only its place changes)
  * a store issue leaves total company stock unchanged
  * consumption takes waste out WITH the material, so it cannot vanish
  * a reversal exactly cancels the entry it reverses

What could NOT be tested here is the schema running against real MySQL,
because there is no MySQL server in the environment this was built in.
That is exactly why the setup page opens with a health panel listing
every table: with the self-healing schema pattern your app uses, a failed
CREATE TABLE fails silently. The panel makes it visible.

Check that panel first. If every table shows a number, the install worked.
