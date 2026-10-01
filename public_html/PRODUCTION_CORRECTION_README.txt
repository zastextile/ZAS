ZAS TEXTILE — CORRECTING A SAVED PRODUCTION ENTRY
=====================================================================

WHAT THIS ADDS
--------------
Until now a production entry could never be taken back once saved. There
was no edit, no delete, no cancel — anywhere in the application. Good for
fraud control, but a genuine mistake (500 typed instead of 50, the wrong
worker credited, the same work claimed twice) had no way out.

New page:  Production  >  Correct an Entry     ( production_amend.php )
Admin only.


NO SCHEMA CHANGE IS NEEDED
--------------------------
Your production_transactions table ALREADY has the columns for this:
    status ENUM('active','cancelled')
    cancelled_by
    cancellation_reason
    cancelled_at
They were in the original design and have simply never been written to.
No SQL patch, no ALTER, nothing to run. Upload the files and it works.


FILES
-----
  NEW       production_amend.php
  REPLACED  includes/production.php   (adds production_cancel_entry())
  REPLACED  includes/menu.php         (adds the one menu line)
  REPLACED  inv_exceptions.php        (adds a link to the new page)

Back up those three replaced files before uploading, as usual.


HOW IT WORKS
------------
An entry is NEVER edited and NEVER deleted. It is marked cancelled, with
  * the reason you type (minimum 5 characters, kept forever)
  * which admin did it
  * the exact date and time
The original date, worker, quantity, rate and amount stay exactly as they
were written. The record reads as: this was claimed, then it was cancelled,
and here is why.

"Amending" an entry means: cancel the wrong one, then log the right one on
Daily Production Entry as normal. Cancelling gives the quantity back to
the stage, so the corrected entry passes the same limits as any other.


WHY YOU CAN TRUST THE FIGURES AFTERWARDS
----------------------------------------
Every query in the application that reads production_transactions already
filters status='active'. That was checked line by line before this was
built — all 17 of them, in:
    production_dashboard.php (5)  production_reports.php (3)
    inv_exceptions.php (5)        inv_ordercost.php (2)
    production_api.php (1)        includes/production.php (1)

Two queries deliberately do NOT filter, and should not: the guards that
stop you deleting a worker or an operation that has ever been used. A
cancelled entry is still history, and that history must stay protected.
So the moment an entry is cancelled it leaves stage progress, worker
wages, the production dashboard, all five reports, Order Costing Control
and the exception checks — in the same second, with nothing to recompute.

The order's production status (not started / in progress / completed) is
recalculated on the spot as part of the same database transaction.


THE TWO REFUSALS
----------------
Stitching stands on Cutting, and Dispatch stands on Stitching. So:

  * A Cutting entry cannot be cancelled while pieces are already stitched
    against it — you would be left with more stitched than cut.
  * A Stitching entry cannot be cancelled while pieces are already
    dispatched against it.

Cancel the later stage first. The page says so, and names the quantity
that is blocking it.

A Dispatch entry can always be cancelled.


WHY ADMIN ONLY
--------------
The production module exists so a worker or a floor incharge cannot
quietly reshape their own claim. Giving the cancel button to the same
people who make the entries would remove the control the module is for.
Production staff see no such button and no such menu item — for them
nothing has changed at all.


THERE IS NO "UN-CANCEL"
-----------------------
Deliberate. If an entry is cancelled by mistake, log it again on Daily
Production Entry. That leaves a clean, readable trail — one cancellation
with its reason, one fresh entry — instead of a row that flips back and
forth and can no longer be trusted by anyone reading it later.


TESTING
-------
The cancel logic was run against a stubbed database, 9 checks, all pass:
  short reason refused · already-cancelled refused · missing entry refused
  Cutting blocked while stitched work depends on it
  Cutting allowed at the exact boundary (cut equals stitched)
  Stitching blocked while dispatched work depends on it
  Dispatch always cancellable
  two admins cancelling the same row at once — only one succeeds
The three changed PHP files were syntax-checked.

What could not be tested here is the page against your real MySQL data,
because there is no MySQL server in the environment this was built in.
Test it on ONE small entry first and confirm the dashboard and the daily
report both drop it before using it on anything that matters.
