#!/usr/bin/env bash
# ZAS Attendance test runner.
#
# The attendance suites need a real MySQL 8, because three of the module's
# rules are kept by the database engine rather than by PHP (see the header of
# zatt_m1_test.php). This starts one, prepares a scratch schema, runs the
# suites, and puts everything back.
#
# IMPORTANT — THE TEST CONFIG IS TEMPORARY ON PURPOSE.
# includes/bootstrap.php reads public_html/config/config.php, so the API test
# needs one to exist. public_html is also what gets dragged onto the live
# server over WinSCP, and a stray test config sitting in it would overwrite
# the real database credentials on the next upload. So it is written here,
# removed in a trap when the run ends, and never left behind. If a config
# already exists it is left completely alone.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUB="$ROOT/public_html"
CFG="$PUB/config/config.php"
SOCK=/var/run/mysqld/mysqld.sock

export ATT_TEST_HOST="${ATT_TEST_HOST:-127.0.0.1}"
export ATT_TEST_DB="${ATT_TEST_DB:-zas_att_test}"
export ATT_TEST_USER="${ATT_TEST_USER:-zas}"
export ATT_TEST_PASS="${ATT_TEST_PASS:-zaspw}"
export ATT_TEST_DSN="mysql:host=${ATT_TEST_HOST};port=3306;dbname=${ATT_TEST_DB};charset=utf8mb4"

WROTE_CFG=0
cleanup() {
  if [ "$WROTE_CFG" = "1" ]; then
    rm -f "$CFG"
    rmdir "$PUB/config" 2>/dev/null || true
    echo "removed the temporary test config"
  fi
}
trap cleanup EXIT

# ---------------------------------------------------------------- database
if ! mysqladmin --socket="$SOCK" ping >/dev/null 2>&1; then
  echo "starting mysqld..."
  mkdir -p /var/run/mysqld /var/log/mysql
  chown -R mysql:mysql /var/run/mysqld /var/log/mysql /var/lib/mysql 2>/dev/null || true
  nohup mysqld --user=mysql --datadir=/var/lib/mysql --socket="$SOCK" \
        --port=3306 --bind-address=127.0.0.1 >/tmp/mysqld.log 2>&1 &
  for _ in $(seq 1 40); do
    mysqladmin --socket="$SOCK" ping >/dev/null 2>&1 && break
    sleep 1
  done
fi

if ! mysqladmin --socket="$SOCK" ping >/dev/null 2>&1; then
  echo "SKIP: no MySQL 8 available. Install with: apt-get install -y mysql-server"
  exit 0
fi

mysql --socket="$SOCK" -uroot <<SQL >/dev/null 2>&1
CREATE DATABASE IF NOT EXISTS ${ATT_TEST_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${ATT_TEST_USER}'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY '${ATT_TEST_PASS}';
CREATE USER IF NOT EXISTS '${ATT_TEST_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${ATT_TEST_PASS}';
GRANT ALL PRIVILEGES ON ${ATT_TEST_DB}.* TO '${ATT_TEST_USER}'@'127.0.0.1', '${ATT_TEST_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# ------------------------------------------------------------- test config
if [ -f "$CFG" ]; then
  echo "a config already exists at public_html/config/config.php — leaving it untouched"
else
  mkdir -p "$PUB/config"
  cat > "$CFG" <<PHP
<?php
/* TEMPORARY test configuration written by tests/run_att.sh.
   It is deleted when the run finishes. Never upload this file. */
return [
    'db_host'      => '${ATT_TEST_HOST}',
    'db_name'      => '${ATT_TEST_DB}',
    'db_user'      => '${ATT_TEST_USER}',
    'db_pass'      => '${ATT_TEST_PASS}',
    'db_charset'   => 'utf8mb4',
    'timezone'     => 'Asia/Karachi',
    'session_name' => 'ZAS_ATT_TEST_SESSION',
];
PHP
  WROTE_CFG=1
fi

# -------------------------------------------------------------------- run
cd "$ROOT/tests"
RC=0
SUITES=("${@:-}")
if [ -z "${SUITES[0]:-}" ]; then
  SUITES=(zatt_m1_test.php zatt_m2_test.php zatt_m3_test.php zatt_m4_test.php zatt_api_test.php)
fi

for t in "${SUITES[@]}"; do
  [ -f "$t" ] || continue
  echo
  echo "================ $t ================"
  php "$t" || RC=1
done

echo
if [ "$RC" = "0" ]; then echo "ATTENDANCE SUITES: all green"; else echo "ATTENDANCE SUITES: FAILURES ABOVE"; fi
exit $RC
