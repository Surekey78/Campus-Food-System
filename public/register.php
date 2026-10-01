<?php
/**
 * public/register.php  —  create a student account
 * Every account created here is a 'student'; admin accounts are made by
 * the canteen staff directly in the database.
 */
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$form   = ['name' => '', 'email' => '', 'student_id' => '', 'phone' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    // -------------------------------------------------- validation
    if ($form['name'] === '') {
        $errors[] = 'Your full name is required.';
    } elseif (char_len($form['name']) > 100) {
        $errors[] = 'That name is too long.';
    }

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$form['email']], 0) > 0) {
        $errors[] = 'That email is already registered. Try signing in instead.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Choose a password with at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    // The student ID is optional, but must be unique when given.
    if ($form['student_id'] !== '' && db_val(
        'SELECT COUNT(*) FROM users WHERE student_id = ?', [$form['student_id']], 0
    ) > 0) {
        $errors[] = 'That student ID is already in use.';
    }

    // ------------------------------------------------------ insert
    if ($errors === []) {
        db_exec(
            'INSERT INTO users (name, email, password_hash, student_id, phone, role)
             VALUES (?, ?, ?, ?, ?, "student")',
            [
                $form['name'],
                $form['email'],
                password_hash($password, PASSWORD_DEFAULT),   // bcrypt hash
                $form['student_id'] !== '' ? $form['student_id'] : null,
                $form['phone'] !== '' ? $form['phone'] : null,
            ]
        );

        session_regenerate_id(true);
        $_SESSION['user_id'] = db_insert_id();

        flash('Welcome to ' . APP_NAME . ', ' . $form['name'] . '! Your account is ready.');
        redirect('/index.php');
    }
}

$pageTitle = 'Register';
require __DIR__ . '/../includes/header.php';
?>

<div class="narrow">
    <div class="card">
        <h1>Create your account</h1>
        <p class="muted">One account, faster lunch. Your order history is kept here.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('/register.php')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="name">Full name</label>
                <input type="text" id="name" name="name" value="<?= e($form['name']) ?>"
                       maxlength="100" autocomplete="name" required>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="student_id">Student ID <span class="muted">(optional)</span></label>
                    <input type="text" id="student_id" name="student_id"
                           value="<?= e($form['student_id']) ?>" maxlength="30"
                           placeholder="2026-00123">
                </div>
                <div class="field">
                    <label for="phone">Mobile <span class="muted">(optional)</span></label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= e($form['phone']) ?>" maxlength="20"
                           placeholder="0917-123-4567">
                </div>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($form['email']) ?>"
                       maxlength="150" autocomplete="email" required>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required>
                    <div class="hint">At least 8 characters.</div>
                </div>
                <div class="field">
                    <label for="password_confirm">Confirm password</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           autocomplete="new-password" required>
                </div>
            </div>

            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>

        <p class="center small" style="margin-top:16px;">
            Already registered? <a href="<?= e(url('/login.php')) ?>">Sign in</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
