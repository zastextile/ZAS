ZAS Export Docs AI System - Replacement Files V1.4

IMPORTANT ANSWER:
- Standard Excel Import does NOT use OpenAI API or your token.
- OpenAI is used only when Admin clicks Create / Regenerate Embedding or uses AI Search.
- This version removes messy auto-detection logic. It imports only a clean standard Excel template.

Replace these files:
1) import_excel.php
2) packing_list.php
3) shipment_view.php
4) shipment_save.php
5) includes/layout.php
6) assets/js/app.js
7) assets/css/app.css
8) templates/zas_standard_import_template.xlsx

No database reinstall required.

New workflow:
1. Download template from Standard Excel Import page.
2. Fill Data_Header, Data_Invoice_Items, Data_Packing_Items.
3. In Data_Packing_Items, use Invoice Line No only.
4. Import file.
5. App creates draft and attaches original Excel.
6. Staff opens Packing List menu and only selects invoice items. Staff cannot type new product names.
7. Admin approves and locks final record.
8. After lock, nobody can edit except Admin with amendment reason.
9. Admin can create/regenerate embedding when final.

Packing list concept:
- Commercial invoice contains item summary.
- Packing list contains carton serial details.
- Packing list rows must select invoice item from the invoice list.
- No new packing product name is allowed.
