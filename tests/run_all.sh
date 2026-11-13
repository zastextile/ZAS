#!/usr/bin/env bash
# ZAS — run EVERY test, the same way each time.
#
#   bash tests/run_all.sh
#
# 1. The ERP suites (tests/z*_test.php and batch2_test.php, except the
#    attendance ones). They read the source files and need no database.
# 2. The attendance, People Finance and ten-point-review suites, through
#    tests/run_att.sh, which starts MySQL, writes a TEMPORARY test config and
#    removes it again. Nothing here touches a live database.
#
# Exit code 0 only when everything passed. One line per suite, then a total.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
RC=0; RAN=0; BAD=()
ATT="$(grep -o 'SUITES=(z[^)]*)' run_att.sh | tr -d '()' | sed 's/SUITES=//')"
for t in batch2_test.php z*_test.php; do
  [ -f "$t" ] || continue
  case " $ATT " in *" $t "*) continue;; esac
  RAN=$((RAN+1))
  out="$(php "$t" 2>&1)"; code=$?
  last="$(printf '%s\n' "$out" | grep -E 'passed|PASS|checks' | tail -1 | tr -s ' ')"
  if [ $code -ne 0 ] || printf '%s' "$out" | grep -qE 'Fatal error|[1-9][0-9]* failed'; then RC=1; BAD+=("$t"); echo "FAIL  $t  $last"; else echo "ok    $t  $last"; fi
done
echo
echo "---- attendance, People Finance and the ten-point review (needs MySQL) ----"
ATT_OUT="$(bash run_att.sh 2>&1)"; ATT_RC=$?
printf '%s\n' "$ATT_OUT" | grep -E 'passed|FAIL|SKIP|green|FAILURES' || true
[ "$ATT_RC" = "0" ] || RC=1
echo
if [ "$RC" = "0" ]; then echo "ALL TESTS PASSED ($RAN ERP suites + the attendance suites)"; else echo "SOME TESTS FAILED: ${BAD[*]:-see above}"; fi
exit $RC
