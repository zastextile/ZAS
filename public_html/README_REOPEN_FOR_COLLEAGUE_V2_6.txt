Replace files:

/includes/auth.php
/shipment_view.php
/shipment_save.php
/reopen_shipment.php
/approve.php

V2.6 Admin Reopen for Colleague Correction:

Main rule:
- Admin opening/viewing shipment does NOT allow colleague editing.
- Colleague can edit only when:
  1) Shipment is Draft, OR
  2) Admin clicks Reopen for Correction and types a reason.
- Submitted for Approval = colleague view-only.
- Approved & Locked = colleague view-only.
- Admin can amend locked shipment only with amendment reason.
- Admin can reopen for colleague only with reopen reason.

Workflow:
1. Colleague creates draft and edits.
2. Colleague clicks Save & Submit for Approval.
3. Colleague becomes view-only.
4. Admin reviews.
5. If correction needed, Admin types reason and clicks Reopen for Correction.
6. Assigned colleague can edit.
7. Colleague clicks Save & Submit for Approval again.
8. Admin approves & locks.
9. Old AI embeddings are marked inactive after reopen/amend.
10. AI API is NOT used by these workflow actions.

After upload:
1. Replace the files above.
2. Open shipment_view.php?id=...&v=26
3. Test colleague:
   - Draft = edit allowed.
   - Submitted = edit not allowed.
4. Test admin:
   - Open/view only = no colleague edit change.
   - Reopen for Correction with reason = colleague edit allowed.
