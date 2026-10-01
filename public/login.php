<?php
/**
 * public/login.php  —  sign in
 */
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect(is_admin() ? '/admin/index.php' : '/index.php');
}

$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip       = client_ip();

    $wait = $email === '' ? 0 : login_wait_seconds($email, $ip);

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email and your password.';
    } elseif ($wait > 0) {
        /* Too many recent failures for this account (or from this address).
           We refuse before doing any password work, and say roughly how long
           the wait is — a locked-out student should know what to do next
           rather than think the canteen site is broken. */
        $error = 'Too many failed attempts. Please wait '
               . max(1, (int) ceil($wait / 60)) . ' minute(s) and try again, '
               . 'or ask the canteen to reset your password.';
    } else {
        $user = db_one(
            'SELECT id, name, email, role, password_hash FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        /* password_verify() compares against the bcrypt hash — we never store
           or compare plain-text passwords.

           When there is no such account we still verify against a dummy hash,
           so both paths take about the same ~220 ms. Otherwise a missing
           account answers noticeably faster, and that difference can be
           measured to discover which emails are registered here. */
        $storedHash = $user['password_hash'] ?? login_dummy_hash();
        $ok         = password_verify($password, $storedHash) && $user !== null;

        if ($ok) {
            login_clear_failures($email);          // forgiven, immediately

            session_regenerate_id(true);           // stop session fixation
            $_SESSION['user_id'] = (int) $user['id'];

            flash('Welcome back, ' . $user['name'] . '!');

            $intended = $_SESSION['after_login'] ?? null;
            unset($_SESSION['after_login']);

            if ($intended && str_starts_with($intended, '/')) {
                header('Location: ' . $intended);
                exit;
            }

            redirect($user['role'] === 'admin' ? '/admin/index.php' : '/index.php');
        }

        login_record_failure($email, $ip);

        // Deliberately vague: don't reveal which half was wrong, or whether
        // the account exists at all.
        $error = 'Incorrect email or password.';
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/../includes/header.php';
?>

<div class="narrow">
    <div class="card">
        <h1>Sign in</h1>
        <p class="muted">Use your campus email to order from the canteen.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/login.php')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>"
                       autocomplete="email" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>

        <p class="center small" style="margin-top:16px;">
            No account yet? <a href="<?= e(url('/register.php')) ?>">Register here</a>
        </p>

        <hr style="border:0;border-top:1px dashed var(--line);margin:18px 0;">
        <p class="small muted" style="margin:0;">
            <strong>Demo accounts</strong><br>
            Student &mdash; <span class="mono">maria@campus.edu</span> / <span class="mono">campus123</span><br>
            Admin &nbsp;&nbsp;&mdash; <span class="mono">admin@campus.edu</span> / <span class="mono">admin123</span>
        </p>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
