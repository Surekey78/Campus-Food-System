<?php
/**
 * includes/security.php  —  the things that keep accounts and money safe
 *
 * Loaded by includes/functions.php before anything else runs. Five jobs:
 *
 *   1. Error handling    — never print a stack trace to a student
 *   2. Session cookies   — HttpOnly, SameSite, Secure when on HTTPS
 *   3. Security headers  — a Content-Security-Policy the app actually obeys
 *   4. Login throttling  — slow down password guessing
 *   5. Small helpers     — client IP, HTTPS detection
 *
 * Everything here works on a plain PHP host, on Termux, and behind Apache or
 * nginx, because it is all done in PHP rather than in a web-server config
 * file. The one thing a web server has to do for you is serve out of
 * public/ — see the README.
 */

// =====================================================================
//  1. Errors
// =====================================================================
//
// By default PHP prints errors into the page. On a live canteen that leaks
// file paths, SQL fragments pointing at the database, and occasionally
// credentials from a connection string. So: errors go to a log file, and
// the visitor gets a plain apology instead.
//
// Turn printing back on for development with APP_DEBUG=1 in .env (or the
// real environment) — termux-start.sh sets it in the demo .env.

function security_debug(): bool
{
    static $debug = null;

    if ($debug === null) {
        $debug = in_array(strtolower(env_or('APP_DEBUG', '0')), ['1', 'true', 'yes', 'on'], true);
    }

    return $debug;
}

/** Where errors are written. Outside public/, so it is never web-readable. */
function security_log_path(): string
{
    $dir = dirname(__DIR__) . '/storage/logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/app.log';
}

function security_log(string $message): void
{
    $line = sprintf("[%s] %s  %s\n", date('Y-m-d H:i:s'), client_ip(), $message);

    // Suppress the warning if the folder isn't writable — logging must never
    // be the reason a page fails.
    @file_put_contents(security_log_path(), $line, FILE_APPEND | LOCK_EX);
}

function security_boot_errors(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', security_debug() ? '1' : '0');
    ini_set('display_startup_errors', security_debug() ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', security_log_path());

    set_exception_handler(static function (Throwable $e): void {
        security_log(sprintf(
            'Uncaught %s: %s in %s:%d',
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
        ));

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n");
            exit(1);
        }

        // Ignore a client that has already hung up: nothing to show it.
        if (connection_aborted()) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        $detail = security_debug()
            ? '<p class="mono small">' . htmlspecialchars(
                get_class($e) . ': ' . $e->getMessage()
                . ' — ' . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES
              ) . '</p>'
            : '<p class="muted small">Details were written to storage/logs/app.log. '
              . 'Set <span class="mono">APP_DEBUG=1</span> to see them in the browser instead.</p>';

        echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong</title>
<style>
 body { margin:0; background:#f6f4f1; color:#1f2430;
        font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
 .box { max-width:560px; margin:12vh auto; padding:24px;
        background:#fff; border:1px solid #e6e8ee; border-radius:14px;
        box-shadow:0 1px 2px rgba(16,24,40,.05), 0 6px 18px rgba(16,24,40,.06); }
 h1 { font-size:1.3rem; margin:0 0 8px; }
 .muted { color:#8a93a3; }
 .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
 .small { font-size:.84rem; }
 a { color:#c2410c; }
</style></head><body>
<div class="box">
  <h1>The canteen system hit a problem</h1>
  <p>Nothing was saved, and no money moved. Please try again.</p>
  <p><a href="/">Back to the menu</a></p>
  {$detail}
</div>
</body></html>
HTML;
    });
}

// =====================================================================
//  2. Sessions
// =====================================================================
//
// Default PHP session cookies are readable by JavaScript and are sent on
// cross-site requests. Both are worth closing:
//
//   HttpOnly   — an XSS bug can no longer read or steal the session id.
//   SameSite   — the browser won't attach the cookie to a form POSTed from
//                another site, which is a second lock alongside the CSRF
//                token (belt and braces; the token is the real protection).
//   Secure     — only over HTTPS, so the cookie can't leak on plain HTTP.
//                Off automatically on the http:// dev server, or it would
//                never be sent at all.

function client_ip(): string
{
    // REMOTE_ADDR only. X-Forwarded-For is trivially forged unless a trusted
    // proxy sets it, and trusting it here would let anyone reset their own
    // login throttle by sending a new value each request.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function security_boot_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;                       // no cookies in the terminal
    }

    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // A named cookie, so this app's session never collides with another
    // PHP app on the same host.
    session_name('campusfood_sid');

    session_set_cookie_params([
        'lifetime' => 0,              // expires when the browser closes
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);

    // An id that was never issued is refused instead of adopted — stops
    // session fixation by someone who invents an id and waits.
    ini_set('session.use_strict_mode', '1');

    // Keep a signed-in canteen going through a long lunch service (8 hours).
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

// =====================================================================
//  3. Security headers
// =====================================================================
//
// The Content-Security-Policy is the interesting one. It says the browser
// may only run JavaScript from this site, so a script injected into a dish
// name or a category would simply not execute. The app has no inline
// <script> blocks or on* handlers for exactly that reason (see
// public/assets/app.js), which is what makes a strict policy possible.
//
// style-src allows 'unsafe-inline' on purpose: the templates use style=""
// attributes in a couple of dozen places. Inline CSS cannot execute, so the
// risk is cosmetic; a strict style-src would be a large refactor for very
// little gain. script-src stays strict, because that is where the risk is.

function security_headers(): array
{
    return [
        'Content-Security-Policy' => implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
        ]),
        // Stop the browser from second-guessing a file's type — matters for
        // the uploads folder, where a JPEG must never be sniffed as HTML.
        'X-Content-Type-Options' => 'nosniff',
        // frame-ancestors above is the modern way; this covers old browsers.
        'X-Frame-Options' => 'DENY',
        // Send the origin to other sites, but not the page path. A canteen
        // URL can contain an order code.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];
}

function security_send_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    foreach (security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
}

// =====================================================================
//  4. Login throttling
// =====================================================================
//
// Without this, anyone on the campus wifi can sit and guess passwords as
// fast as the server answers. Every failure is recorded; too many in a
// short window and that account (and that address) is told to wait.
//
// Two limits, because they catch different attacks:
//   * per email  — 5 failures / 15 min: stops guessing one person's password
//   * per IP     — 25 failures / 15 min: stops spraying one password across
//                  many accounts from one machine
//
// Succeeding clears the email's failures immediately, so a student who
// finally remembers their password isn't left waiting.

const LOGIN_MAX_PER_EMAIL = 5;
const LOGIN_MAX_PER_IP    = 25;
const LOGIN_WINDOW_MIN    = 15;

/**
 * Is the login_attempts table actually there?
 *
 * A canteen installed before throttling existed has no such table, and
 * install.php cannot simply be re-run to add it — that script drops the
 * database. So the honest options are: keep working, or refuse every sign-in.
 * We keep working, and the dashboard says so out loud (see admin/index.php).
 *
 * The important part is that it is not *silent*. mysqli runs in
 * MYSQLI_REPORT_OFF mode, so a query against a missing table just returns
 * false — the throttle would look like it was running while doing nothing at
 * all. Checked once per request, and only on pages that touch sign-in.
 */
function login_throttle_available(): bool
{
    static $available = null;

    if ($available === null) {
        $result = db()->query("SHOW TABLES LIKE 'login_attempts'");
        $available = ($result instanceof mysqli_result) && $result->num_rows > 0;

        if (!$available) {
            security_log('Login throttling is OFF: the login_attempts table is ' .
                         'missing. Run: php database/install.php --upgrade');
        }
    }

    return $available;
}

/** Seconds to wait before another attempt, or 0 if attempts are allowed. */
function login_wait_seconds(string $email, string $ip): int
{
    if (!login_throttle_available()) {
        return 0;
    }

    $email = mb_strtolower(trim($email), 'UTF-8');

    $byEmail = (int) db_val(
        'SELECT COUNT(*) FROM login_attempts
          WHERE email = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)',
        [$email, LOGIN_WINDOW_MIN],
        0
    );

    if ($byEmail >= LOGIN_MAX_PER_EMAIL) {
        return login_retry_seconds('email', $email);
    }

    $byIp = (int) db_val(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)',
        [$ip],
        0
    );

    if ($byIp >= LOGIN_MAX_PER_IP) {
        return login_retry_seconds('ip', $ip);
    }

    return 0;
}

/** How long until the oldest failure in the window drops out. */
function login_retry_seconds(string $column, string $value): int
{
    $oldest = db_val(
        'SELECT MIN(attempted_at) FROM login_attempts
          WHERE ' . ($column === 'ip' ? 'ip' : 'email') . ' = ?
            AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)',
        [$value],
        null
    );

    if ($oldest === null) {
        return 60;
    }

    $expires = strtotime((string) $oldest) + LOGIN_WINDOW_MIN * 60;
    $left    = $expires - time();

    return max(30, $left);
}

function login_record_failure(string $email, string $ip): void
{
    if (!login_throttle_available()) {
        return;
    }

    db_exec(
        'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())',
        [mb_strtolower(trim($email), 'UTF-8'), $ip]
    );

    // Housekeeping while we are here: nothing older than a day is ever
    // consulted, so drop it. Cheap, and keeps the table tiny forever.
    if (random_int(1, 20) === 1) {
        db_exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}

function login_clear_failures(string $email): void
{
    if (!login_throttle_available()) {
        return;
    }

    db_exec('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower(trim($email), 'UTF-8')]);
}

/**
 * A bcrypt hash of a value nobody can supply, used to keep the timing of a
 * failed login the same whether or not the account exists. Without it, a
 * missing account answers faster (no password_verify call) and that gap can
 * be measured to find out which emails are registered.
 */
function login_dummy_hash(): string
{
    // A real bcrypt hash (cost 12, matching register.php) of a passphrase
    // that is not a password anyone has. It only has to be *valid*: the point
    // is that password_verify() does its full ~220 ms of work either way.
    return '$2y$12$Mav3gVale6OJpIHSwot4aOH.nk2JnUY3e5MgVng62qfQA4yNO6HAy';
}

// =====================================================================
//  5. Boot
// =====================================================================

security_boot_errors();
security_boot_session();
security_send_headers();
