<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if (!$user) {
    header('Location: logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_photo') {
        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Please choose an image file to upload.');
        } elseif ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Error uploading image. (Code: ' . (int)$_FILES['profile_image']['error'] . ')');
        } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            flash('error', 'Image file is too large. Maximum file size is 2MB.');
        } else {
            $tmpPath = $_FILES['profile_image']['tmp_name'];
            $imageInfo = @getimagesize($tmpPath);
            if ($imageInfo === false) {
                flash('error', 'The uploaded file is not a valid image.');
            } else {
                $mime = $imageInfo['mime'];
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif'
                ];

                if (!isset($allowed[$mime])) {
                    flash('error', 'Invalid image format. Only JPG, PNG, WEBP, and GIF are allowed.');
                } else {
                    $uploadDir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $ext = $allowed[$mime];
                    $newFilename = 'avatar_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destPath = $uploadDir . $newFilename;

                    if (move_uploaded_file($tmpPath, $destPath)) {
                        // Delete previous profile picture if it exists
                        if (!empty($user['profile_image'])) {
                            $oldFile = $uploadDir . basename($user['profile_image']);
                            if (file_exists($oldFile)) {
                                @unlink($oldFile);
                            }
                        }

                        $stmt = db()->prepare('UPDATE employees SET profile_image = ? WHERE id = ?');
                        $stmt->execute([$newFilename, (int)$user['id']]);
                        $_SESSION['profile_image'] = $newFilename;

                        flash('success', 'Profile picture updated successfully.');
                    } else {
                        flash('error', 'Failed to save uploaded image. Check folder permissions.');
                    }
                }
            }
        }
        header('Location: account.php');
        exit;
    } elseif ($action === 'remove_photo') {
        if (!empty($user['profile_image'])) {
            $oldFile = __DIR__ . '/uploads/avatars/' . basename($user['profile_image']);
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
            $stmt = db()->prepare('UPDATE employees SET profile_image = NULL WHERE id = ?');
            $stmt->execute([(int)$user['id']]);
            $_SESSION['profile_image'] = null;
            flash('success', 'Profile picture removed.');
        }
        header('Location: account.php');
        exit;
    } elseif ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please provide a valid full name and email address.');
        } else {
            $stmt = db()->prepare('SELECT id FROM employees WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$email, (int)$user['id']]);
            if ($stmt->fetch()) {
                flash('error', 'That email address is already in use by another account.');
            } else {
                $stmt = db()->prepare('UPDATE employees SET full_name = ?, email = ? WHERE id = ?');
                $stmt->execute([$fullName, $email, (int)$user['id']]);
                $_SESSION['full_name'] = $fullName;
                flash('success', 'Personal details updated successfully.');
            }
        }
        header('Location: account.php');
        exit;
    } elseif ($action === 'update_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM employees WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters long.');
        } elseif ($newPassword !== $confirmPassword) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            $stmt = db()->prepare('UPDATE employees SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('success', 'Password changed successfully.');
        }
        header('Location: account.php');
        exit;
    }

    header('Location: account.php');
    exit;
}

$notice = get_flash();
$hasAvatar = !empty($user['profile_image']) && file_exists(__DIR__ . '/uploads/avatars/' . $user['profile_image']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Management | TimeLog</title>
    <link rel="icon" type="image/png" href="assets/images/dostlogo.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php">
            <img src="assets/images/dostlogo.png" alt="DOST Logo" class="brand-logo">
            <span class="brand-name">TIME<span>LOG</span></span>
        </a>
        <div class="top-nav">
            <a class="nav-link" href="index.php">Dashboard</a>
            <a class="nav-link" href="history.php">History</a>
            <a class="nav-link" href="export.php">Export</a>
        </div>
        <div class="top-user">
            <a class="user-chip" href="account.php" title="Manage account">
                <?php if (!empty($user['profile_image']) && file_exists(__DIR__ . '/uploads/avatars/' . $user['profile_image'])): ?>
                    <img class="top-avatar" src="uploads/avatars/<?= e($user['profile_image']) ?>" alt="Avatar">
                <?php else: ?>
                    <span class="top-avatar-initial"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
                <?php endif; ?>
                <span><?= e($user['full_name']) ?></span>
            </a>
            <a class="logout" href="logout.php">Sign out</a>
        </div>
    </header>

    <main class="container narrow">
        <div class="page-heading">
            <div>
                <p class="eyebrow">SETTINGS</p>
                <h1>Account Management</h1>
                <p class="muted">Manage your profile picture, personal details, and account security.</p>
            </div>
        </div>

        <?php if ($notice): ?>
            <div class="alert <?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <!-- Profile Picture Panel -->
        <section class="panel account-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">PROFILE PICTURE</p>
                    <h2>Profile photo</h2>
                </div>
            </div>

            <div class="picture-upload-wrapper">
                <div class="avatar-large" id="avatarPreviewContainer">
                    <?php if ($hasAvatar): ?>
                        <img id="avatarPreviewImg" src="uploads/avatars/<?= e($user['profile_image']) ?>" alt="<?= e($user['full_name']) ?>">
                    <?php else: ?>
                        <span id="avatarPreviewInitial"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                </div>

                <div class="picture-actions">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_photo">

                        <label for="profile_image">
                            Choose new photo
                            <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif" required onchange="previewAvatar(this)">
                        </label>

                        <button class="button primary" type="submit">Upload photo</button>
                    </form>

                    <?php if ($hasAvatar): ?>
                        <form method="post" onsubmit="return confirm('Are you sure you want to remove your profile picture?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="remove_photo">
                            <button class="button danger" type="submit">Remove photo</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <p class="helper">Supported formats: JPG, PNG, WEBP, or GIF. Maximum file size: 2MB. Square images are recommended for best fit.</p>
        </section>

        <!-- Personal Information Panel -->
        <section class="panel account-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">PERSONAL DETAILS</p>
                    <h2>Personal information</h2>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profile">

                <label for="employee_no">
                    Employee number
                    <input type="text" id="employee_no" value="<?= e($user['employee_no']) ?>" disabled>
                </label>

                <label for="full_name">
                    Full name
                    <input type="text" id="full_name" name="full_name" required maxlength="120" value="<?= e($user['full_name']) ?>">
                </label>

                <label for="email">
                    Email address
                    <input type="email" id="email" name="email" required maxlength="190" value="<?= e($user['email']) ?>">
                </label>

                <button class="button primary" type="submit">Save personal details</button>
            </form>
        </section>

        <!-- Change Password Panel -->
        <section class="panel account-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">SECURITY</p>
                    <h2>Change password</h2>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_password">

                <label for="current_password">
                    Current password
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </label>

                <label for="new_password">
                    New password
                    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                </label>

                <label for="confirm_password">
                    Confirm new password
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </label>

                <button class="button primary" type="submit">Update password</button>
            </form>
        </section>
    </main>

    <footer class="footer">TimeLog Attendance System</footer>

    <script>
    function previewAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const container = document.getElementById('avatarPreviewContainer');
                container.innerHTML = '<img id="avatarPreviewImg" src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
</body>
</html>
