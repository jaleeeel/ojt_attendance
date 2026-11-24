<?php
require_once __DIR__ . '/config.php';
if (is_logged_in()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT id, employee_no, full_name, email, password_hash, profile_image FROM employees WHERE email = ? OR employee_no = ? LIMIT 1');
    $stmt->execute([$identity, $identity]);
    $employee = $stmt->fetch();
    if ($employee && password_verify($password, $employee['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$employee['id'];
        $_SESSION['employee_no'] = $employee['employee_no'];
        $_SESSION['full_name'] = $employee['full_name'];
        $_SESSION['profile_image'] = $employee['profile_image'];
        header('Location: index.php'); exit;
    }
    $error = 'Incorrect employee number/email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | TimeLog</title>
    <link rel="icon" type="image/png" href="assets/images/dostlogo.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand" href="login.php">
            <img src="assets/images/dostlogo.png" alt="DOST Logo" class="brand-logo">
            <span class="brand-name">TIME<span>LOG</span></span>
        </a>
        <h1>Welcome back</h1>
        <p class="muted">Sign in to record your attendance.</p>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label for="identity">
                Employee number or email
                <input type="text" id="identity" name="identity" required autofocus autocomplete="username" value="<?= e($_POST['identity'] ?? '') ?>">
            </label>

            <label for="password">
                Password
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </label>

            <button class="button primary full" type="submit">Sign in</button>
        </form>

        <p class="auth-foot">
            New employee? <a href="create_employee.php">Create an account</a>
        </p>
    </main>
</body>
</html>
