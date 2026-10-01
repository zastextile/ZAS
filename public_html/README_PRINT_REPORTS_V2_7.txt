Replace / add these files:

/invoice_print.php        NEW
/packing_print.php        NEW
/shipment_view.php        REPLACE

Print Reports V2.7:
- Adds Commercial Invoice print report.
- Adds Packing List print report.
- Adds print buttons in shipment view.
- Buttons available for Admin and Colleague.
- Staff cannot access print reports.
- Commercial Invoice button respects rate permission:
  Admin always allowed.
  Colleague allowed only if rate visibility is enabled for that user.
- Packing List print has no rates/amounts and is allowed for Admin/Colleague.
- Address/header uses max two lines:
  Faisalabad, Pakistan | Manufacturer & Exporter of Home Textile, Hotel Linen, Towels & Apparel
  WhatsApp: +92 300 8664721 | www.zastextiles.com
- Full-page seamless ZAS TEXTILE watermark.
- Optional / hidden 3rd column logic:
  If optional values exist, column shows.
  If no optional values exist, column is hidden and table adjusts automatically.
- No OpenAI API/token used.

After upload:
1. Upload invoice_print.php and packing_print.php to root.
2. Replace shipment_view.php.
3. Open any shipment.
4. Click:
   Print Commercial Invoice
   Print Packing List
