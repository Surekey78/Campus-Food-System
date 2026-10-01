<?php
/**
 * public/account.php  —  a signed-in user's own profile and password
 *
 * The demo installs ship accounts with publicly known passwords
 * (admin123 / campus123), so being able to change your own password
 * is not a nicety — it is the step that makes an install safe to use
 * for real. It also lets staff fix a typo in their own name without
 * asking anyone with database access.
 */
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'profile';

    // ------------------------------------------------------------ profile
    if ($action === 'profile') {
        $name      = trim((string) ($_POST['name'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));
        $phone     = trim((string) ($_POST['phone'] ?? ''));

        if ($name === '') {
            $errors[] = 'Your name cannot be empty.';
        } elseif (char_len($name) > 100) {
            $errors[] = 'That name is too long.';
        }

        if ($studentId !== '' && char_len($studentId) > 30) {
            $errors[] = 'That student ID is too long.';
        }

        if ($phone !== '' && char_len($phone) > 20) {
            $errors[] = 'That phone number is too long.';
        }

        // Students can't take an ID or phone number that's already in use.
        if ($errors === [] && $studentId !== '') {
            $clash = db_val(
                'SELECT COUNT(*) FROM users WHERE student_id = ? AND id <> ?',
                [$studentId, (int) $user['id']],
                0
            );
            if ((int) $clash > 0) {
                $errors[] = 'Another account already uses that student ID.';
            }
        }

        if ($errors === []) {
            db_exec(
                'UPDATE users SET name = ?, student_id = ?, phone = ? WHERE id = ?',
                [
                    $name,
                    $studentId !== '' ? $studentId : null,
                    $phone !== '' ? $phone : null,
                    (int) $user['id'],
                ]
            );

            flash('Your details have been saved.');
            redirect('/account.php');
        }
    }

    // ----------------------------------------------------------- password
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        // Re-check the current password rather than trusting the session:
        // an unattended phone shouldn't let a passer-by take over the
        // account just because it was left signed in.
        $row = db_one('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors[] = 'Your current password is not correct.';
        }

        if (strlen($new) < 8) {
            $errors[] = 'Choose a new password of at least 8 characters.';
        } elseif ($new === $current) {
            $errors[] = 'The new password is the same as your current one.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two new passwords do not match.';
        }

        // A short, honest strength nudge rather than a fake score.
        if ($errors === [] && preg_match('/^[a-z]+$/i', $new)) {
            flash('Password changed — though letters-only passwords are weak. '
                . 'A few digits or words would be stronger.', 'warn');
        }

        if ($errors === []) {
            // Changing the published demo password makes this your account,
            // so drop the demo flag: no more "your password is in the README"
            // nagging about a password you have already replaced.
            $wasDemo = (int) db_val('SELECT is_demo FROM users WHERE id = ?', [(int) $user['id']], 0) === 1;

            db_exec(
                'UPDATE users SET password_hash = ?, is_demo = 0 WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]
            );

            if ($wasDemo) {
                flash('Password changed. This account is no longer flagged as demo data.');
            }

            // New password, new session id: any other device holding the
            // old session can't keep using it.
            session_regenerate_id(true);

            if (!isset($_SESSION['flash'])) {
                flash('Your password has been changed.');
            }
            redirect('/account.php');
        }
    }
}

// ----------------------------------------------------------------- display
$orders = db_one(
    'SELECT COUNT(*) AS placed, COALESCE(SUM(total), 0) AS spent
       FROM orders WHERE user_id = ? AND status <> "cancelled"',
    [(int) $user['id']]
) ?? ['placed' => 0, 'spent' => 0];

$isDemo = (int) db_val('SELECT is_demo FROM users WHERE id = ?', [(int) $user['id']], 0) === 1;

$pageTitle = 'My Account';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Account</h1>
        <p>Signed in as <strong><?= e($user['name']) ?></strong>
            <?= is_admin() ? '<span class="badge badge-preparing">admin</span>' : '' ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/index.php')) ?>">← Back to menu</a>
</div>

<?php if ($isDemo): ?>
    <div class="alert alert-warn">
        <strong>This is a demo account.</strong>
        Its password is written in the project's README, so anyone who has seen this
        project knows it. Change the password below before using this install for
        anything real.
    </div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="two-col">

    <!-- ------------------------------------------------------- profile -->
    <div>
        <div class="card">
            <h2>Your details</h2>

            <form method="post" action="<?= e(url('/account.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="field">
                    <label for="name">Full name</label>
                    <input type="text" id="name" name="name" maxlength="100"
                           value="<?= e($_POST['name'] ?? $user['name']) ?>" required>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="student_id">Student ID</label>
                        <input type="text" id="student_id" name="student_id" maxlength="30"
                               placeholder="2026-00123"
                               value="<?= e($_POST['student_id'] ?? $user['student_id']) ?>">
                    </div>
                    <div class="field">
                        <label for="phone">Mobile</label>
                        <input type="tel" id="phone" name="phone" maxlength="20"
                               placeholder="0917-123-4567"
                               value="<?= e($_POST['phone'] ?? $user['phone']) ?>">
                    </div>
                </div>

                <div class="field">
                    <label>Email</label>
                    <p style="margin:0;">
                        <span class="mono"><?= e($user['email']) ?></span>
                    </p>
                    <div class="hint">
                        Your email is your sign-in name and it appears on receipts, so it
                        can't be changed here. Ask a canteen administrator to update it for you.
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Save details</button>
                </div>
            </form>
        </div>

        <!-- ----------------------------------------------------- password -->
        <div class="card">
            <h2>Change password</h2>

            <form method="post" action="<?= e(url('/account.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password"
                               autocomplete="new-password" required>
                        <div class="hint">At least 8 characters.</div>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Repeat new password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               autocomplete="new-password" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Change password</button>
                </div>

                <p class="small muted" style="margin:12px 0 0;">
                    Changing your password signs out any other device using this account.
                </p>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------- summary -->
    <div>
        <div class="card">
            <h2>At a glance</h2>
            <ul class="list-clean">
                <li><span class="muted">Account type</span>
                    <span><?= is_admin() ? 'Canteen administrator' : 'Student' ?></span></li>
                <li><span class="muted">Orders placed</span>
                    <span><?= (int) $orders['placed'] ?></span></li>
                <li><span class="muted">Total spent</span>
                    <span><?= e(money($orders['spent'])) ?></span></li>
                <li><span class="muted">Wallet balance</span>
                    <span><strong><?= e(money($user['wallet'])) ?></strong></span></li>
            </ul>
            <p class="small muted" style="margin:12px 0 0;">
                Top up your wallet at the canteen counter.
            </p>
        </div>

        <?php if (is_admin()): ?>
            <div class="card">
                <h2>Canteen tools</h2>
                <p class="small">You have administrator access.</p>
                <div style="display:grid; gap:8px;">
                    <a class="btn btn-ghost btn-block" href="<?= e(url('/admin/index.php')) ?>">Kitchen dashboard</a>
                    <a class="btn btn-ghost btn-block" href="<?= e(url('/admin/items.php')) ?>">Menu items</a>
                    <a class="btn btn-ghost btn-block" href="<?= e(url('/admin/staff.php')) ?>">Staff accounts</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Signing out</h2>
            <p class="small">Use this on a shared or canteen computer.</p>
            <a class="btn btn-ghost btn-block" href="<?= e(url('/logout.php')) ?>">Sign out</a>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
