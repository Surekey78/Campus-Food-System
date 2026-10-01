<?php
/**
 * public/admin/staff.php  —  who can use this system, and as what
 *
 * Canteen staff change over, so the admin needs a way to add colleagues
 * and retire them without anyone touching SQL.
 *
 * The rule that matters: the app must never let you remove the last
 * administrator. If it did, nobody could ever open the admin pages
 * again and the only way back in would be a CLI script. Every
 * destructive action below checks that first.
 */
require_once __DIR__ . '/../../includes/functions.php';

$admin = require_admin();

/** How many administrators exist right now? */
function admin_count(): int
{
    return (int) db_val('SELECT COUNT(*) FROM users WHERE role = "admin"', [], 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

    $target = $userId > 0
        ? db_one('SELECT id, name, email, role, is_demo FROM users WHERE id = ?', [$userId])
        : null;

    // --------------------------------------------------------- add staff
    if ($action === 'add') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass  = (string) ($_POST['password'] ?? '');
        $role  = ($_POST['role'] ?? 'admin') === 'staff' ? 'admin' : 'admin';

        if ($name === '') {
            flash('A name is required.', 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid email address.', 'error');
        } elseif (db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email], 0) > 0) {
            flash('That email is already registered — use the Promote button on their row instead.', 'error');
        } elseif (strlen($pass) < 8) {
            flash('Use a password of at least 8 characters.', 'error');
        } else {
            db_exec(
                'INSERT INTO users (name, email, password_hash, role, wallet, is_demo)
                 VALUES (?, ?, ?, ?, 0.00, 0)',
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]
            );
            flash($name . ' can now sign in as an administrator.');
        }

        redirect('/admin/staff.php');
    }

    // --------------------------------------------------------- promote
    if ($action === 'promote') {
        if (!$target) {
            flash('That account no longer exists.', 'error');
        } elseif ($target['role'] === 'admin') {
            flash($target['name'] . ' is already an administrator.', 'warn');
        } else {
            db_exec(
                'UPDATE users SET role = "admin", is_demo = 0 WHERE id = ?',
                [(int) $target['id']]
            );
            flash($target['name'] . ' is now an administrator. Their orders and wallet are untouched.');
        }

        redirect('/admin/staff.php');
    }

    // ----------------------------------------------------------- demote
    if ($action === 'demote') {
        if (!$target) {
            flash('That account no longer exists.', 'error');
        } elseif ($target['role'] !== 'admin') {
            flash($target['name'] . ' is not an administrator.', 'warn');
        } elseif ($target['id'] === $admin['id']) {
            // Demoting yourself is fine in principle, but doing it as the
            // only admin is exactly the lockout this page exists to stop.
            flash('You cannot remove your own administrator access. '
                . 'Ask another administrator to do it.', 'error');
        } elseif (admin_count() <= 1) {
            flash('That is the last administrator account — removing it would lock '
                . 'everyone out of the canteen pages. Promote someone else first.', 'error');
        } else {
            db_exec('UPDATE users SET role = "student" WHERE id = ?', [(int) $target['id']]);
            flash($target['name'] . ' is now a student account. They can still order food.');
        }

        redirect('/admin/staff.php');
    }

    // ----------------------------------------------------------- delete
    if ($action === 'delete') {
        if (!$target) {
            flash('That account no longer exists.', 'error');
        } elseif ($target['id'] === $admin['id']) {
            flash('You cannot delete your own account.', 'error');
        } elseif ($target['role'] === 'admin' && admin_count() <= 1) {
            flash('That is the last administrator account and cannot be deleted.', 'error');
        } else {
            // Orders are a record of money and food. Rather than let a
            // delete cascade silently through them, refuse and explain.
            $orderCount = (int) db_val(
                'SELECT COUNT(*) FROM orders WHERE user_id = ?', [(int) $target['id']], 0
            );

            if ($orderCount > 0) {
                flash(
                    $target['name'] . ' has ' . $orderCount . ' order(s) on record, so the '
                    . 'account cannot be deleted without also destroying that history. '
                    . 'Demote them to a student instead, or clear the demo orders first.',
                    'error'
                );
            } else {
                db_exec('DELETE FROM users WHERE id = ?', [(int) $target['id']]);
                flash($target['name'] . "'s account has been deleted.");
            }
        }

        redirect('/admin/staff.php');
    }

    flash('Unrecognised action.', 'error');
    redirect('/admin/staff.php');
}

// ------------------------------------------------------------------- list
$users = db_all(
    'SELECT u.id, u.name, u.email, u.student_id, u.role, u.wallet, u.is_demo, u.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count
       FROM users u
      ORDER BY FIELD(u.role, "admin", "student"), u.name'
);

$totalAdmins  = admin_count();
$totalUsers   = count($users);
$demoAccounts = count(array_filter($users, static fn ($u) => (int) $u['is_demo'] === 1));
$demoAdmins   = count(array_filter($users, static fn ($u) => (int) $u['is_demo'] === 1 && $u['role'] === 'admin'));

$pageTitle   = 'Staff Accounts';
$activeAdmin = 'staff';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Staff Accounts</h1>
        <p><?= $totalUsers ?> account<?= $totalUsers === 1 ? '' : 's' ?> ·
            <?= $totalAdmins ?> administrator<?= $totalAdmins === 1 ? '' : 's' ?></p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_nav.php'; ?>

<?php if ($demoAccounts > 0): ?>
    <div class="alert alert-warn">
        <strong><?= $demoAccounts ?> demo account<?= $demoAccounts === 1 ? '' : 's' ?> on this install.</strong>
        Their passwords are printed in the project's README, so anyone who has seen this
        project can sign in as them<?= $demoAdmins > 0 ? ' — including as an administrator' : '' ?>.
        <br><br>
        The safe order of business:
        <strong>1.</strong> create your own administrator below,
        <strong>2.</strong> sign in as it and confirm it works,
        <strong>3.</strong> come back here and delete the demo accounts.
    </div>
<?php endif; ?>

<?php if ($totalAdmins === 1): ?>
    <div class="alert alert-info">
        There is only one administrator. If you lose access to that account there is no
        way back in through the website — you would have to run
        <span class="mono">php tools/make-admin.php --promote you@example.com</span>
        on the server. Adding a second administrator is cheap insurance.
    </div>
<?php endif; ?>

<div class="two-col">

    <!-- --------------------------------------------------- account list -->
    <div class="card">
        <h2>Everyone with an account</h2>

        <div class="table-wrap">
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Role</th>
                        <th class="num">Orders</th>
                        <th class="num">Wallet</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                        $isSelf     = (int) $row['id'] === (int) $admin['id'];
                        $isAdminRow = $row['role'] === 'admin';
                        $lastAdmin  = $isAdminRow && $totalAdmins <= 1;
                    ?>
                    <tr>
                        <td class="cell-main">
                            <strong><?= e($row['name']) ?></strong>
                            <?php if ($isSelf): ?><span class="badge badge-preparing">you</span><?php endif; ?>
                            <?php if ((int) $row['is_demo'] === 1): ?>
                                <span class="badge badge-off" title="Sample account with a published password">demo</span>
                            <?php endif; ?>
                            <div class="small muted"><?= e($row['email']) ?></div>
                            <?php if ($row['student_id']): ?>
                                <div class="small muted">ID <?= e($row['student_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Role">
                            <?= $isAdminRow
                                ? '<span class="badge badge-preparing">Admin</span>'
                                : '<span class="badge badge-off">Student</span>' ?>
                        </td>
                        <td class="num" data-label="Orders"><?= (int) $row['order_count'] ?></td>
                        <td class="num nowrap" data-label="Wallet"><?= e(money($row['wallet'])) ?></td>
                        <td class="num nowrap cell-actions">
                            <?php if (!$isAdminRow): ?>
                                <form method="post" action="<?= e(url('/admin/staff.php')) ?>" style="display:inline;" data-confirm="Give <?= e($row['name']) ?> administrator access?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="promote">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Make admin</button>
                                </form>
                            <?php elseif (!$isSelf && !$lastAdmin): ?>
                                <form method="post" action="<?= e(url('/admin/staff.php')) ?>" style="display:inline;" data-confirm="Remove administrator access from <?= e($row['name']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="demote">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Demote</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isSelf && !$lastAdmin): ?>
                                <form method="post" action="<?= e(url('/admin/staff.php')) ?>" style="display:inline;" data-confirm="Delete <?= e($row['name']) ?>? <?= (int) $row['order_count'] > 0 ? 'They have orders on record and this will be refused.' : 'This cannot be undone.' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($lastAdmin || $isSelf): ?>
                                <span class="small muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="small muted" style="margin:14px 0 0;">
            The last administrator cannot be demoted or deleted, and you cannot remove
            your own access — both would leave nobody able to open these pages.
        </p>
    </div>

    <!-- ----------------------------------------------------- add a staff -->
    <div>
        <div class="card">
            <h2>Add an administrator</h2>
            <p class="small muted">
                Use this for a colleague or a successor. They can sign in immediately.
            </p>

            <form method="post" action="<?= e(url('/admin/staff.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">

                <div class="field">
                    <label for="name">Full name</label>
                    <input type="text" id="name" name="name" maxlength="100" required
                           placeholder="Aling Nena">
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="150" required
                           placeholder="nena@campus.edu">
                    <div class="hint">This is their sign-in name.</div>
                </div>

                <div class="field">
                    <label for="password">Temporary password</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password">
                    <div class="hint">
                        At least 8 characters. Tell them to change it from
                        <a href="<?= e(url('/account.php')) ?>">My Account</a> after their first sign-in.
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Create administrator</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Adding a new person your own way?</h2>
            <p class="small">
                Anyone can register themselves from the front page as a student. Once
                they have, <strong>Make admin</strong> on their row promotes them without
                disturbing their orders or wallet.
            </p>
            <p class="small muted" style="margin:0;">
                If nobody can sign in as an administrator at all, use the command line:
            </p>
            <p class="mono small" style="margin:6px 0 0; word-break:break-all;">
                php tools/make-admin.php --promote someone@campus.edu
            </p>
        </div>
    </div>

</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
