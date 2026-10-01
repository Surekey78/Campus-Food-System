<?php
/**
 * =====================================================================
 *  tools/make-admin.php  —  create or promote an administrator
 * =====================================================================
 *  THE BOOTSTRAP PROBLEM
 *  If you set a canteen up properly (schema.sql only, no demo data)
 *  then the database has no users at all. You can register as a
 *  student, but nothing in the web app can turn that account into an
 *  administrator — so the admin pages would be permanently out of
 *  reach. This script is the way in.
 *
 *  It is command-line only, on purpose. An endpoint that can create
 *  admins is the single most valuable thing on the server to an
 *  attacker, so it must never be reachable over the web.
 *
 *  Usage
 *      php tools/make-admin.php                       # asks for everything
 *      php tools/make-admin.php --email you@campus.edu
 *      php tools/make-admin.php --email you@campus.edu --name "A. Cruz" \
 *                              --password 'secret123'
 *      php tools/make-admin.php --promote maria@campus.edu
 *                                                     # student -> admin
 *      php tools/make-admin.php --list                # who exists?
 *
 *  If the email already belongs to a student, that account is promoted
 *  rather than duplicated, so their existing order history survives.
 * =====================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool is command-line only. Run: php tools/make-admin.php');
}

require_once __DIR__ . '/../config/db.php';

/** Small helpers so this script doesn't depend on the web app's includes. */

/** Run a SELECT and return every row. */
function q(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('SQL error: ' . db()->error);
    }
    if ($params !== []) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Run an INSERT / UPDATE / DELETE. Returns affected rows. */
function qx(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('SQL error: ' . db()->error);
    }
    if ($params !== []) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();

    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

/**
 * Read a line from the terminal without echoing it.
 * Uses stty when available; falls back to a normal read otherwise.
 */
function ask_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    $sttyAvailable = false;
    if (function_exists('shell_exec') && @shell_exec('command -v stty 2>/dev/null')) {
        @shell_exec('stty -echo 2>/dev/null');
        $sttyAvailable = true;
    }

    $value = fgets(STDIN);
    $value = $value === false ? '' : trim($value);

    if ($sttyAvailable) {
        @shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");

    return $value;
}

// ------------------------------------------------------------ arguments
$options = getopt('', [
    'email:', 'name:', 'password:', 'promote:', 'list', 'help', 'force',
]);

if (isset($options['help'])) {
    echo <<<TXT

Create or promote an administrator for the Campus Food System.

  php tools/make-admin.php                        interactive
  php tools/make-admin.php --email a@b.edu [--name "Full Name"]
                          [--password 'secret123'] [--force]
  php tools/make-admin.php --promote student@campus.edu
  php tools/make-admin.php --list

  --force   change the password of an existing admin

TXT;
    exit(0);
}

// ---------------------------------------------------------------- check
try {
    db();
} catch (Throwable $ex) {
    fwrite(STDERR, "✗ Cannot reach the database: " . $ex->getMessage() . "\n"
                 . "  Check DB_* in .env or config/db.php.\n");
    exit(1);
}

// ------------------------------------------------------------ --list
if (isset($options['list'])) {
    $users = q('SELECT id, name, email, role, is_demo, wallet FROM users ORDER BY role, name');

    if ($users === []) {
        echo "No accounts yet.\n\n";
        echo "Create the first administrator with:\n";
        echo "    php tools/make-admin.php --email you@campus.edu\n";
        exit(0);
    }

    printf("%-4s %-22s %-28s %-8s %s\n", 'ID', 'NAME', 'EMAIL', 'ROLE', 'NOTES');
    foreach ($users as $u) {
        $notes = [];
        if ((int) $u['is_demo'] === 1) {
            $notes[] = 'DEMO — public password';
        }
        printf(
            "%-4d %-22s %-28s %-8s %s\n",
            $u['id'],
            mb_substr($u['name'], 0, 22),
            $u['email'],
            $u['role'],
            implode(', ', $notes)
        );
    }

    $admins = count(array_filter($users, fn ($u) => $u['role'] === 'admin'));
    echo "\n{$admins} administrator(s), " . count($users) . " account(s) total.\n";
    exit(0);
}

// ------------------------------------------------------------ --promote
if (isset($options['promote'])) {
    $email = trim((string) $options['promote']);
    $user  = q('SELECT id, name, email, role FROM users WHERE email = ?', [$email]);

    if ($user === []) {
        fwrite(STDERR, "✗ No account with the email '$email'.\n"
                     . "  The person should register in the app first, then re-run --promote.\n");
        exit(1);
    }

    $user = $user[0];
    if ($user['role'] === 'admin') {
        echo "· {$user['name']} is already an administrator.\n";
        exit(0);
    }

    qx('UPDATE users SET role = "admin" WHERE id = ?', [(string) $user['id']]);
    echo "✓ Promoted {$user['name']} <{$user['email']}> to administrator.\n";
    echo "  Their existing orders and wallet balance are untouched.\n";
    exit(0);
}

// ------------------------------------------------------------- interactive
$email = trim((string) ($options['email'] ?? ''));
$name  = trim((string) ($options['name'] ?? ''));
$pass  = (string) ($options['password'] ?? '');
$force = isset($options['force']);

if ($email === '') {
    fwrite(STDOUT, "Email address for the administrator: ");
    $email = trim((string) fgets(STDIN));
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "✗ That is not a valid email address.\n");
    exit(1);
}

$existing = q('SELECT id, name, email, role, is_demo FROM users WHERE email = ?', [$email]);
$existing = $existing[0] ?? null;

if ($existing !== null && $existing['role'] === 'admin' && !$force) {
    echo "· {$existing['email']} is already an administrator.\n";
    echo "  To set a new password for it, re-run with --force.\n";
    exit(0);
}

// ------------------------------------------------------------- password
if ($pass === '') {
    $pass = ask_hidden('Password (8+ characters, hidden): ');

    if (strlen($pass) < 8) {
        fwrite(STDERR, "✗ Please use at least 8 characters.\n");
        exit(1);
    }

    $again = ask_hidden('Repeat password: ');
    if ($pass !== $again) {
        fwrite(STDERR, "✗ The two passwords did not match. Nothing was changed.\n");
        exit(1);
    }
}

if (strlen($pass) < 8) {
    fwrite(STDERR, "✗ Please use at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

// ----------------------------------------------------------------- write
try {
if ($existing !== null) {
    // Promote and/or set a new password, and clear the demo flag: this is
    // now a real account, no longer sample content.
    qx('UPDATE users SET role = "admin", password_hash = ?, is_demo = 0,
                          name = COALESCE(NULLIF(?, ""), name)
         WHERE id = ?',
       [$hash, $name, (string) $existing['id']]);

    echo "✓ Updated {$email} — now an administrator with a new password.\n";
} else {
    qx('INSERT INTO users (name, email, password_hash, role, wallet, is_demo)
        VALUES (?, ?, ?, "admin", 0.00, 0)',
       [$name !== '' ? $name : 'Canteen Admin', $email, $hash]);

    echo "✓ Created administrator {$email}.\n";
}
} catch (Throwable $ex) {
    fwrite(STDERR, "✗ Could not save the account: " . $ex->getMessage() . "\n");
    exit(1);
}

// --------------------------------------------------------------- a nudge
$adminCount = (int) (q('SELECT COUNT(*) AS n FROM users WHERE role = "admin"')[0]['n'] ?? 0);
$demoAdmins = (int) (q('SELECT COUNT(*) AS n FROM users WHERE role = "admin" AND is_demo = 1')[0]['n'] ?? 0);

echo "\n  Administrators on this install: {$adminCount}\n";
if ($demoAdmins > 0) {
    echo "\n  Heads up: {$demoAdmins} of them are DEMO accounts with publicly known\n";
    echo "  passwords (admin123). Sign in as your own account, then delete the demo\n";
    echo "  one from Admin > Staff accounts. The app refuses to remove the last\n";
    echo "  administrator, so make sure yours works before you try.\n";
}
echo "\n  Sign in at: /login.php\n";
