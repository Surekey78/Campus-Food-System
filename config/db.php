<?php
/**
 * =====================================================================
 *  config/db.php  —  the ONLY file that knows how to reach MariaDB
 * =====================================================================
 *  Everything else in the app calls db() and gets a ready-to-use
 *  mysqli connection back.
 *
 *  Connection details can be overridden with environment variables so
 *  the same code runs unchanged on XAMPP, MAMP, Docker or a live
 *  server. The defaults below suit a stock XAMPP install.
 * =====================================================================
 */

// ---- Optional .env file ---------------------------------------------
// A simple KEY=VALUE file next to the project root. Keys found here are
// used as defaults; real environment variables always win, so you can
// override anything from the shell without editing files.
// `termux-start.sh` writes this file with a generated database password.
$__envFile = dirname(__DIR__) . '/.env';

if (is_readable($__envFile)) {
    foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) {
            continue;                       // blanks and comments
        }
        [$__key, $__val] = explode('=', $__line, 2);
        $__key = trim($__key);
        $__val = trim($__val, " \t\"'");
        if ($__key !== '' && getenv($__key) === false) {
            putenv($__key . '=' . $__val);
        }
    }
    unset($__line, $__key, $__val);
}
unset($__envFile);

/** Read a setting: environment first, then the given default. */
function env_or(string $key, string $default): string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

// ---- Database credentials -------------------------------------------
define('DB_HOST', env_or('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env_or('DB_PORT', '3306'));
define('DB_USER', env_or('DB_USER', 'root'));
define('DB_PASS', env_or('DB_PASS', ''));
define('DB_NAME', env_or('DB_NAME', 'campus_food'));

// ---- App-wide settings ----------------------------------------------
define('APP_NAME',    'Campus Food');
define('CURRENCY',    '₱');
define('SERVICE_FEE', 5.00);   // flat canteen service fee per order

// Pickup windows the student can choose at checkout.
$PICKUP_SLOTS = ['ASAP', '11:30 AM', '12:15 PM', '1:00 PM', '3:30 PM'];

/**
 * Return the shared mysqli connection, opening it on first use.
 *
 * The static variable means the whole request reuses ONE connection,
 * no matter how many times db() is called.
 *
 * @return mysqli
 */
function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    // We handle errors ourselves below, so turn mysqli's
    // exception-throwing behaviour off (default changed in PHP 8.1).
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($conn->connect_errno) {
        http_response_code(500);
        exit(
            '<h2>Database connection failed</h2>' .
            '<p><b>Error ' . $conn->connect_errno . ':</b> ' .
            htmlspecialchars($conn->connect_error) . '</p>' .
            '<p>Is MariaDB running, and have you imported ' .
            '<code>database/schema.sql</code>?</p>'
        );
    }

    // utf8mb4 so emoji and accented names survive the round trip.
    $conn->set_charset('utf8mb4');

    return $conn;
}
