FIXED Print Reports V2.7.1

This fixes HTTP ERROR 500 caused by a PHP parse error in the print report CSS output.

Replace these files:
/invoice_print.php
/packing_print.php

Optional:
/shipment_view.php
Only replace shipment_view.php if your print buttons are missing.
If print buttons already show, you do not need to replace shipment_view.php again.

Rules remain same:
- Admin and Colleague can access print reports.
- Staff cannot access.
- Commercial Invoice respects rate visibility.
- Packing List has no rates/amounts.
- Address is max two lines.
- Seamless ZAS TEXTILE watermark.
- Optional 3rd column shows only when data exists.
- No OpenAI API/token used.

Tested with php -l:
- invoice_print.php OK
- packing_print.php OK
- shipment_view.php OK
