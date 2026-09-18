<?php
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/redis.php';

date_default_timezone_set($config['timezone'] ?? 'Asia/Karachi');

if (session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name'] ?? 'ZAS_EXPORT_DOCS_SESSION');
    ini_set('session.gc_maxlifetime', 28800);
    redis_session_boot();
    try {
        session_start();
    } catch (Throwable $e) {
        /* THE SAME TRAP, ONE LEVEL DOWN.
         *
         * This catch silently moved the session store to disk. The cookie still
         * carried a session id whose data lives in Redis, so the file handler
         * found nothing and handed back an EMPTY session — and the user was
         * shown the login page with no explanation.
         *
         * One retry first: a single unlucky moment is the common case, and a
         * second attempt costs one round trip instead of somebody's work. Only
         * if that also fails do we move to files, and then we RECORD why, so
         * the app can say "the session store was unreachable" instead of the
         * untrue "you have been signed out". */
        try {
            session_start();
        } catch (Throwable $e2) {
            @ini_set('session.save_handler', 'files');
            @ini_set('session.save_path', sys_get_temp_dir());
            $GLOBALS['ZAS_SESSION_FALLBACK'] =
                'Redis did not answer, so this request used file sessions. '
                . 'Anyone signed in through Redis will appear signed out until it responds again.';
            session_start();
        }
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
/* Loaded after auth.php because it uses current_user(), and BEFORE
   layout.php because the menu asks it what to show. It defines functions
   only — no query runs until something calls one, so a page that never
   asks about permissions pays nothing for this line. */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/fx.php';

try {
    db()->exec("ALTER TABLE users ADD COLUMN department VARCHAR(60) NULL");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipment_items ADD COLUMN department VARCHAR(60) NULL");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipments ADD COLUMN optional_column_enabled TINYINT(1) DEFAULT 0");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipments MODIFY optional_column_title VARCHAR(120) NULL");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipments ADD COLUMN incoterm VARCHAR(120) NULL");
} catch (Throwable $e) {}

/* The product a line REALLY is, as opposed to what someone typed.

   Until now a proforma or invoice line held only product_name, and every
   production screen had to work out which product that meant by comparing
   letters — production_resolve_product(), which drops the line entirely
   below 55% confidence and, above it, takes its best guess without
   telling anyone. That is why a manually typed line could be invisible to
   production while a CSV-imported one worked.

   Storing the id removes the guess. Nullable on purpose: every line you
   already have keeps a NULL here and keeps taking the old path, so
   nothing that works today stops working. The typed name is untouched and
   is still what prints — the code is what the system follows. */
try {
    db()->exec("ALTER TABLE proforma_items ADD COLUMN product_id INT NULL");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE proforma_items ADD INDEX idx_pi_product (product_id)");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipment_items ADD COLUMN product_id INT NULL");
} catch (Throwable $e) {}
try {
    db()->exec("ALTER TABLE shipment_items ADD INDEX idx_si_product (product_id)");
} catch (Throwable $e) {}
fx_ensure_table();
