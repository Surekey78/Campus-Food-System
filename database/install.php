<?php
/**
 * =====================================================================
 *  database/install.php  —  command-line installer / resetter
 * =====================================================================
 *  Creates the campus_food database, then runs schema.sql and (optional)
 *  demo-data.sql through mysqli. Uses mysqli::multi_query, which is why it
 *  is a CLI script and NOT a web page: an installer reachable through
 *  the browser is a security hole.
 *
 *  Usage:
 *      php database/install.php              # create DB + schema + DEMO data
 *      php database/install.php --no-seed    # schema only, empty canteen
 *                                            #   <- use this for a real canteen
 *      php database/install.php --upgrade    # add anything new to an
 *                                            #   EXISTING canteen, keeping
 *                                            #   every order and account
 *
 *  --upgrade never drops anything. Use it after copying new code onto a
 *  canteen that is already running: schema.sql is for fresh installs only,
 *  because it starts with DROP DATABASE.
 *
 *  Connection details come from config/db.php (DB_* env vars or the
 *  defaults), so the same command works on XAMPP, MAMP and Linux.
 * =====================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This installer is command-line only. Run: php database/install.php');
}

require_once __DIR__ . '/../config/db.php';

// This script builds its own connection instead of calling db(), so it has to
// ask for the same error style the app uses. Without this line mysqli throws
// on every error (the default since PHP 8.1), which turns the friendly
// messages below into PHP fatal errors — and hides a wrong DB_PASS behind a
// stack trace, exactly when a new user needs plain guidance.
mysqli_report(MYSQLI_REPORT_OFF);

$withSeed = !in_array('--no-seed', $argv, true);
// DB_NAME ends up inside DDL statements, which cannot take a placeholder,
// so it is checked rather than escaped. Letters, digits and underscores only
// — which is every name a database should have anyway.
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', DB_NAME)) {
    fwrite(STDERR, "✗ DB_NAME must be letters, digits and underscores only (got '" . DB_NAME . "').\n" .
        "  Fix it in .env, or in config/db.php if you set the defaults there.\n");
    exit(1);
}

// The @ only mutes mysqli's own warning; the connect_errno check below
// reports the same problem with more useful advice.
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);

if ($conn->connect_errno) {
    fwrite(STDERR, "✗ Cannot connect to " . DB_HOST . ':' . DB_PORT .
        " as '" . DB_USER . "' — " . $conn->connect_error . "\n" .
        "  Start MariaDB, then check DB_USER / DB_PASS in config/db.php.\n");
    exit(1);
}

echo "✓ Connected to " . $conn->server_info . " at " . DB_HOST . ':' . DB_PORT . "\n";

// ---------------------------------------------------------------------
//  --upgrade: additive only. No DROP DATABASE, no seeding, no touching
//  existing rows. This is the path a working canteen takes.
// ---------------------------------------------------------------------
if (in_array('--upgrade', $argv, true)) {
    echo "→ Upgrading '" . DB_NAME . "' (no data is deleted)…\n";

    if (!$conn->select_db(DB_NAME)) {
        fwrite(STDERR, "✗ Database '" . DB_NAME . "' does not exist yet.\n" .
            "  This is a fresh install — run:  php database/install.php\n");
        exit(1);
    }

    $conn->set_charset('utf8mb4');

    try {
        $before = [];
        foreach (['users', 'menu_items', 'orders'] as $table) {
            $before[$table] = (int) $conn->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n'];
        }

        runSqlFile($conn, __DIR__ . '/upgrade.sql');

        echo "\n✓ Upgraded. Nothing was removed — row counts before and after:\n";
        foreach ($before as $table => $was) {
            $now = (int) $conn->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n'];
            printf("    %-14s %s → %s%s\n",
                $table, number_format($was), number_format($now),
                $now === $was ? '' : '   ← CHANGED, please check');
        }

        $has = $conn->query("SHOW TABLES LIKE 'login_attempts'");
        if ($has && $has->num_rows) {
            echo "\n  ✓ login_attempts present — sign-in throttling is active.\n";
        } else {
            fwrite(STDERR, "\n  ✗ login_attempts still missing — throttling will stay off.\n");
            exit(1);
        }
    } catch (Throwable $ex) {
        fwrite(STDERR, "\n✗ Upgrade failed: " . $ex->getMessage() . "\n" .
            "  The database was not dropped. Fix the error and run it again.\n");
        exit(1);
    }

    exit(0);
}

/**
 * Point schema.sql at whatever database this install is configured to use.
 *
 * schema.sql names the database literally, so that it stays a file you can
 * load by hand with the mariadb client. That is right for the default name
 * and wrong for every other one: with DB_NAME set to, say, canteen_north,
 * the raw file would quietly create a second database called campus_food,
 * and the app would then connect to a database that does not exist. Only
 * these three statements mention the name — the tables never do.
 */
function retargetSql(string $sql, string $dbName): string
{
    if ($dbName === 'campus_food') {
        return $sql;                     // nothing to do for the default
    }

    $safe = '`' . $dbName . '`';         // validated by the caller

    $sql = preg_replace(
        '/^\s*DROP DATABASE IF EXISTS\s+`?campus_food`?\s*;/mi',
        'DROP DATABASE IF EXISTS ' . $safe . ';',
        $sql
    );
    $sql = preg_replace(
        '/^\s*CREATE DATABASE\s+`?campus_food`?/mi',
        'CREATE DATABASE ' . $safe,
        $sql
    );
    $sql = preg_replace(
        '/^\s*USE\s+`?campus_food`?\s*;/mi',
        'USE ' . $safe . ';',
        $sql
    );

    return $sql;
}

/**
 * Run a .sql file statement by statement via multi_query.
 * The schema and demo-data files contain no DELIMITER blocks, so this is safe.
 */
function runSqlFile(mysqli $conn, string $path, ?string $dbName = null): void
{
    $sql = file_get_contents($path);

    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Could not read $path");
    }

    if ($dbName !== null) {
        $sql = retargetSql($sql, $dbName);
    }

    if (!$conn->multi_query($sql)) {
        throw new RuntimeException("Query failed: " . $conn->error);
    }

    // multi_query returns one result set per statement — drain them all,
    // otherwise the next query reports "commands out of sync".
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        throw new RuntimeException("Query failed: " . $conn->error);
    }
}

// A production download has no demo-data.sql at all. Ask for the file
// rather than the flag, so nobody gets a confusing "could not read" error
// when they run the plain command on a build that was never meant to have
// sample data in it.
$demoFile = __DIR__ . '/demo-data.sql';
$seeded   = $withSeed && is_file($demoFile);

try {
    echo "→ Creating database '" . DB_NAME . "'…\n";
    runSqlFile($conn, __DIR__ . '/schema.sql', DB_NAME);

    if ($seeded) {
        echo "→ Loading sample data (users, categories, menu, orders)…\n";

        // Point the connection at the database we just built. The sample
        // data carries its own USE statement — so it stays loadable by hand
        // — and without this it would switch a custom DB_NAME install back
        // to the default name and fill the wrong database.
        if (!$conn->select_db(DB_NAME)) {
            throw new RuntimeException('Could not select the database ' . DB_NAME);
        }

        runSqlFile($conn, $demoFile, DB_NAME);
    } elseif ($withSeed) {
        echo "\n  This is the production build — the sample canteen ships only in\n";
        echo "  the demo download. Creating an empty canteen instead.\n\n";
    }

    // Point the connection at the new database and count what we made.
    $conn->select_db(DB_NAME);
    $conn->set_charset('utf8mb4');

    $tables = ['users', 'categories', 'menu_items', 'orders', 'order_items'];
    echo "\n✓ Done. Contents of " . DB_NAME . ":\n";

    foreach ($tables as $table) {
        $count = $conn->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n'];
        printf("    %-14s %s row(s)\n", $table, number_format((float) $count));
    }

    if ($seeded) {
        echo "\n  DEMO install — these accounts have publicly known passwords.\n";
        echo "  For a real canteen re-run with --no-seed and create your own admin.\n\n";
        echo "  Demo logins:\n";
        echo "    admin@campus.edu  / admin123    (canteen admin)\n";
        echo "    maria@campus.edu  / campus123   (student)\n";
        echo "    juan@campus.edu   / campus123   (student)\n";
    } elseif (!$withSeed) {
        echo "\n  Empty canteen ready. Next:\n";
        echo "    1. open public/register.php and create your own account\n";
        echo "    2. php tools/make-admin.php --promote you@campus.edu\n";
        echo "    3. sign in, then Admin > Menu items and add your dishes\n";
    }

    echo "\n✓ Open public/index.php in your browser to start ordering.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, '✗ Install failed: ' . $ex->getMessage() . "\n");
    exit(1);
}
