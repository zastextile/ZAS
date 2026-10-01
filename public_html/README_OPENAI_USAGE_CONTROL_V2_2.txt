Replace files in this package:

/embed.php
/search.php
/includes/layout.php
/dashboard.php
/shipment_view.php

OpenAI Usage Control V2.2:

1. Staff:
   - No AI Search menu.
   - No AI Search page access.
   - No OpenAI token use from staff packing.

2. Admin / Colleague:
   - Can use AI Search.
   - AI Search uses:
     a) text-embedding-3-small for the search question
     b) gpt-5-mini only when approved/locked embedded records are found

3. Embedding:
   - Admin only.
   - Blocked until shipment status is approved_locked.
   - Button on shipment view now shows "Embedding After Lock Only" before approval/lock.
   - After approval/lock, Admin can click "Create / Regenerate Embedding".

4. Draft / Unlocked Shipments:
   - Not embedded.
   - Not sent to GPT for AI answers.
   - Exact database matches may show as normal database table, but not used as GPT context.

5. No OpenAI usage for:
   - Manual invoice entry
   - Standard Excel import
   - Packing list entry
   - Staff mobile packing
   - Save packing row
   - Complete packing list
   - Approval/lock action itself

After upload:
1. Replace all files listed above.
2. Open dashboard.php?v=22.
3. Check Staff login: AI Search should not show.
4. Check Admin shipment before approval: embedding button should be disabled text.
5. Approve/lock shipment.
6. Then click Create / Regenerate Embedding.
7. Use AI Search.
