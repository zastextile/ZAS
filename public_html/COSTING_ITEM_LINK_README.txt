ZAS TEXTILE — COSTING NOW PICKS ITEMS FROM THE ITEM MASTER
=====================================================================

WHAT CHANGED, IN ONE LINE
-------------------------
The Item column in Product Costing is now a pick-list of your Item Master,
and the line remembers WHICH item it is — so the Group is taken from the
item instead of being guessed from the name.


WHY THIS WAS NEEDED
-------------------
Until now product_costing.php decided every line's Group like this:

    name contains "fabric"  ->  Fabric
    everything else         ->  Accessories

That single rule is the whole reason your seeded Item Master showed
fabrics sitting under Accessories, and the reason Product Costing could
never produce a Packing line at all. It was never a fault in the seed —
the seed copied your costings faithfully.


FILES
-----
  NEW       costing_items_link.php          (admin: settle the old names)
  NEW       COSTING_ITEM_LINK_README.txt    (this file)
  REPLACED  product_costing.php             (the pick-list + the new rule)
  REPLACED  includes/inventory.php          (uses the stored link first)
  REPLACED  includes/menu.php               (one menu line)
  REPLACED  fix_costing_groups.php          (now refuses to undo the fix)

Back those four replaced files up before uploading.


NO SQL PATCH TO RUN
-------------------
One additive column is created by the page itself, the same self-healing
way the rest of your app does it:

    ALTER TABLE costing_lines ADD COLUMN material_id INT NULL DEFAULT NULL

Nullable, no default change, no data rewritten. Every existing costing
line keeps every figure it has.


HOW THE ITEM COLUMN BEHAVES NOW
-------------------------------
It is still one box. Type to search the Item Master list, or type a name
that is not there yet. Under it, one small chip always tells you where you
stand:

    green   FB-0001      linked to that Item Master item
    blue    + add to Item Master    typed name with no match — one click
                                    creates it and links it (asks the
                                    Group once, because the name is
                                    exactly what cannot be trusted)
    grey    free text    the Store & Inventory module is not installed,
                         or this is an old line never linked

Picking a known item fills Unit and Rate ONLY where they are still empty.
A rate you negotiated for this quote is never overwritten by the standard
rate. The item's own spelling is used, so the same material stops
appearing under four different spellings.

There is still NO Group picker in the row. The item carries its group —
there is nothing to set twice and nothing to disagree about.


DELIBERATELY NOT A HARD LOCK
----------------------------
A costing can still be saved with a name that is not in the Item Master.
That is on purpose. Costing is your quoting tool — if a quote cannot be
saved until somebody creates a master record, quoting stops, and staff
will type junk into the Item Master just to get past the screen. That is
how a master file gets ruined. Quote fast, standardise after.

The one place the link IS hard is Consumption, and it always was: stock
cannot move on a name that does not exist. That has not changed.


WHAT HAPPENS TO EVERYTHING ALREADY TYPED
----------------------------------------
Nothing breaks and nothing is lost. Old lines keep their typed name, keep
their figures, keep printing exactly as before, and the Consumption screen
still finds them by name as it always did.

But a name match is a guess, re-made on every page load — and the loose
match takes the FIRST item whose name contains the text, so an old line
called just "Label" attaches to whichever of Brand Label / Size Label /
Care Label it reaches first.

    Administration is not where this lives — it is under
    Costing & Products  >  Link Costing Items      (admin only)

That page lists every distinct item name still unlinked, how many costing
lines and how many products use it, and what it would match:

    exact name     confident
    alias -> ...   from the costing_item_aliases dictionary already in
                   your app (poly bag, ctn, thread, stiching, ...)
    partial name   shown in amber — CHECK THESE, this is the guess that
                   picks the wrong Label
    no match       offers to create the item, with the unit and the
                   average rate your own costings already use

You confirm, change, or create. Applying writes the link, so that name is
never guessed again.

WHAT THAT PAGE WRITES — and nothing else:
    costing_lines.material_id     the link
    costing_lines.line_group      only if you leave the tick box on
    inv_materials                 only the rows you ask it to create
No quantity, rate, weight or amount is touched. No costing total moves.
Final Costings are not read or written at all — they are finished
documents and stay exactly as approved.

The one visible change is the Group, and that is the point: correcting it
is what puts fabrics back under Fabric and finally lets Packing exist. Be
aware the Group decides what the Team-Share print view hides and what its
Net Weight counts, so print one costing before and after on your first
run and satisfy yourself.


fix_costing_groups.php
----------------------
Still there, but now skips any line that is linked, so it can no longer
push your fabrics back into Accessories. It also carries a note saying
what it cannot do: that page can only ever return Fabric or Accessories.


TESTING
-------
The matching rules were run against a stubbed database, 14 checks, all
pass:
  the linked item's group beats the old name rule, both directions
  the alias dictionary is used when a line is not linked
  legacy lines still fall back to exactly today's behaviour
  an invalid item id falls through safely instead of failing
  exact / alias / partial / no-match are each reported as what they are
  partial matches are always labelled partial, never presented as certain
All changed PHP was syntax-checked, and the page's JavaScript was parsed.

What could NOT be tested here is any of it against your real MySQL, as
there is no MySQL server in the environment this was built in.

SUGGESTED FIRST RUN
  1. Open one product in Product Costing. Confirm the Item boxes now show
     a list, and that saving changes no total.
  2. Open Link Costing Items. Look at the amber "partial name" rows first
     — set anything doubtful to "leave it alone".
  3. Apply a handful, not all of them. Re-print one costing and compare.
  4. When you are satisfied, work through the rest.
