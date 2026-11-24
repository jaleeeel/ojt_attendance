<?php
require_once __DIR__ . '/config.php';

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $employeeNo = trim($_POST['employee_no'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($employeeNo === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Enter all fields correctly. Password must be at least 8 characters.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO employees (employee_no, full_name, email, password_hash) VALUES (?, ?, ?, ?)');
            $stmt->execute([$employeeNo, $fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $success = 'Employee account created. You can now sign in.';
        } catch (PDOException $ex) {
            $error = $ex->getCode() === '23000' ? 'Employee number or email already exists.' : 'Unable to create account. Check your database settings.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Employee Account | TimeLog</title>
    <link rel="icon" type="image/png" href="assets/images/dostlogo.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand" href="login.php">
            <img src="assets/images/dostlogo.png" alt="DOST Logo" class="brand-logo">
            <span class="brand-name">TIME<span>LOG</span></span>
        </a>
        <h1>Create employee account</h1>
        <p class="muted">Create an account to use the attendance system.</p>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label for="employee_no">
                Employee number
                <input type="text" id="employee_no" name="employee_no" required maxlength="40" autofocus value="<?= e($_POST['employee_no'] ?? '') ?>">
            </label>

            <label for="full_name">
                Full name
                <input type="text" id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($_POST['full_name'] ?? '') ?>">
            </label>

            <label for="email">
                Email address
                <input type="email" id="email" name="email" required maxlength="190" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
            </label>

            <label for="password">
                Password
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            </label>

            <button class="button primary full" type="submit">Create account</button>
        </form>

        <p class="auth-foot">
            <a href="login.php">Back to sign in</a>
        </p>
    </main>
</body>
</html>
