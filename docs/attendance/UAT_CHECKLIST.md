# Attendance — user acceptance test (UAT)

Run on the live server with real staff and two real phones, before relying on
it for payroll. Use two or three test employees (e.g. codes 9001–9003) in one
department, then mark them inactive afterwards.

People needed: one **gatekeeper** (phone A), a second gatekeeper or the same
person on **phone B**, one **HOD** of the test department (phone C or a
laptop), **HR**, **Accounts**, and an **administrator**.

Tick each line and write the time. "Expected" is what must happen; anything
else is a failure — note what you saw.

| # | Do this | Expected | ✓ / note |
|---|---|---|---|
| AT01 | Gate: 9001 IN, OUT, IN, OUT, IN, OUT across one shift (wait a minute or more between). | Reports › Movements shows 3 entries. HOD's Arrivals shows 3 separate items to confirm. | |
| AT02a | Gate: 9002 IN, then IN again at once. | Second scan refused ("already inside"), red buzz on the phone. | |
| AT02b | Phone A and phone B: type 9003 and press IN at the same moment. | One accepted, one refused. Movements shows one IN only. | |
| AT03 | Phone A: aeroplane mode, 9001 IN. HOD (online) confirms 9001 by code. Phone A back online. | Within a minute the gate IN and the HOD confirmation pair up. Nothing left in HR. | |
| AT04 | HOD phone offline, confirm 9002. Gate (online) scans 9002 IN. HOD phone back online. | They pair up. Nothing left in HR. | |
| AT05 | Both phones offline: 9003 IN twice at different gates a few minutes apart; HOD offline confirms 9003 once. All back online. | The unclear case appears in HR › Reconciliation for a person to decide. The system does not guess. | |
| AT06 | HOD: Arrivals › Select all › Confirm selected. Then try a code from another department. | Each entry confirmed once. Other department's code refused. HOD sees only their own people. | |
| AT07 | Normal IN/OUT for 9001. Then gatekeeper flags 9002 with a photo. Sign in as a HOD of another department and as a plain ERP user. | No photo taken for normal scans. The flag photo opens only for HR/admin (and the reference photo for gate/HOD on scan). Others are refused. | |
| AT08 | 9001 leaves 2 hours after shift end, with no overtime request. | No overtime appears anywhere (Reports › Overtime, Payroll). | |
| AT09 | HOD requests overtime for 9002. HR and Accounts both open it and press Approve / Reject at about the same time. | Only the first decision stands; the other is told it was already decided. History shows one decision. | |
| AT10 | Next day, verify 9002's actual hours. Enter more than approved. | Saving more than approved is refused until an amendment is recorded. | |
| AT11 | Accounts: set a manual rate, check the total, Finalize the period. Then try to change the rate. | Total matches minutes × rate. After finalize, changes are refused; a correction is added beside the figure, and every step is in the history. | |
| AT12 | Phone A offline, scan 9003 IN, do **not** reconnect. Look at the Queue tab. | Queue shows 1 unsent scan with a warning. Everyone understands: if this phone is lost now, that scan is lost — it must be sent or recorded by hand. | |

Extra checks (no AT number):

| Do this | Expected | ✓ / note |
|---|---|---|
| 9001 IN, never scanned OUT. Next morning open Reports › Today; type 9001 on the gate phone. | Listed under "Not scanned out", OUT at the shift end. The gate phone shows green IN and a note that the system closed it. HR can put in the real time. | |
| Gatekeeper signs in on the phone app. | Only IN / OUT and Queue. No HOD screens. | |
| HOD signs in on the phone app. | Only Arrivals, Overtime, Queue. | |
| Code typed on the phone. | Number keypad with a Next key; sound and vibration on accept / refuse (switchable in Queue). | |
| Reports › Today, Attendance, Overtime as HOD. | Only their department. CSV downloads open in Excel. | |
| Setup › System as administrator. | **Ready**, three green web probes. | |
| `php att_backup.php verify <newest file>` on the server. | RESTORE TEST PASSED. | |

## Sign-off

| Role | Name | Date | Signature |
|---|---|---|---|
| Gatekeeper | | | |
| HOD | | | |
| HR | | | |
| Accounts | | | |
| Owner / administrator | | | |

Failures found: ______________________________________________

Accepted for live use: ☐ yes ☐ no, re-test after fixes
