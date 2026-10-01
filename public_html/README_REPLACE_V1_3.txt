REPLACE FILES V1.3

Upload these files to your app root and replace existing files:

1) import_excel.php
2) shipment_form.php
3) includes/auth.php
4) assets/css/app.css

What changed:
- Excel import now creates an editable draft form before saving.
- Imported Excel is attached automatically to the created shipment.
- User can edit header, invoice items, charges, and packing rows before creating draft.
- Colleague without rate visibility cannot import/create commercial invoice, to prevent hidden rates saving as zero.
- CSS upgraded for modern UI.
- No database reinstall required.

After upload:
- Login as Admin.
- Go to Import Excel.
- Upload XLSX.
- Review/correct editable data.
- Click Create Shipment Draft From Imported Excel.
