<?php
// Update these values to match your local MySQL configuration.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_TIMEZONE', 'Asia/Manila');

date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('Invalid request token. Please go back and try again.');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, employee_no, full_name, email, profile_image, created_at FROM employees WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function log_attendance_audit(
    int $attendanceId,
    int $employeeId,
    string $action,
    ?string $prevDate,
    ?string $newDate,
    ?string $prevIn,
    ?string $newIn,
    ?string $prevOut,
    ?string $newOut,
    string $reason
): void {
    $stmt = db()->prepare('INSERT INTO attendance_audit_logs 
        (attendance_id, employee_id, action, previous_attendance_date, new_attendance_date, previous_time_in, new_time_in, previous_time_out, new_time_out, reason, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $attendanceId,
        $employeeId,
        $action,
        $prevDate,
        $newDate,
        $prevIn,
        $newIn,
        $prevOut,
        $newOut,
        $reason
    ]);
}

function parse_date_and_time(string $date, string $time): ?DateTime {
    $time = trim($time);
    if ($time === '') {
        return null;
    }
    // Formats: 'H:i:s', 'H:i', 'h:i:s A', 'h:i A'
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d h:i:s A', 'Y-m-d h:i A'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $date . ' ' . $time);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }
    $ts = strtotime($date . ' ' . $time);
    if ($ts !== false) {
        return (new DateTime())->setTimestamp($ts);
    }
    return null;
}

function handle_attendance_action(string $redirectUrl): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['edit_attendance', 'delete_attendance', 'restore_attendance', 'permanent_delete_attendance'], true)) {
        return;
    }
    verify_csrf();
    $employeeId = (int)$_SESSION['user_id'];

    if ($action === 'edit_attendance') {
        $id = (int)($_POST['attendance_id'] ?? 0);
        $date = trim($_POST['attendance_date'] ?? '');
        $timeIn = trim($_POST['time_in'] ?? '');
        $timeOut = trim($_POST['time_out'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$id || $date === '' || $timeIn === '' || $reason === '') {
            flash('error', 'Please fill in the attendance date, time-in, and provide a modification reason.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            flash('error', 'Invalid attendance date format.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $timeInDt = parse_date_and_time($date, $timeIn);
        $timeOutDt = $timeOut !== '' ? parse_date_and_time($date, $timeOut) : null;

        if (!$timeInDt) {
            flash('error', 'Invalid Time-in format.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ($timeOut !== '' && !$timeOutDt) {
            flash('error', 'Invalid Time-out format.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ($timeOutDt && $timeOutDt < $timeInDt) {
            flash('error', 'Time-out cannot be earlier than Time-in.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id, $employeeId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            flash('error', 'Attendance record not found or cannot be modified.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ($date !== $existing['attendance_date'] && active_attendance_exists($employeeId, $date, $id)) {
            flash('error', 'An active attendance record already exists for ' . $date . '.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $timeInStr = $timeInDt->format('Y-m-d H:i:s');
        $timeOutStr = $timeOutDt ? $timeOutDt->format('Y-m-d H:i:s') : null;

        $stmt = db()->prepare('UPDATE attendance SET attendance_date = ?, time_in = ?, time_out = ?, modification_reason = ? WHERE id = ? AND employee_id = ?');
        $stmt->execute([
            $date,
            $timeInStr,
            $timeOutStr,
            $reason,
            $id,
            $employeeId
        ]);

        log_attendance_audit(
            $id,
            $employeeId,
            'EDIT',
            $existing['attendance_date'],
            $date,
            $existing['time_in'],
            $timeInStr,
            $existing['time_out'],
            $timeOutStr,
            $reason
        );

        flash('success', 'Attendance record updated successfully.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_attendance') {
        $id = (int)($_POST['attendance_id'] ?? 0);
        $reason = trim($_POST['deletion_reason'] ?? '');

        if (!$id || $reason === '') {
            flash('error', 'A deletion reason is mandatory to request/soft delete an attendance record.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id, $employeeId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            flash('error', 'Attendance record not found or already deleted.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('UPDATE attendance SET deleted_at = NOW(), deletion_reason = ? WHERE id = ? AND employee_id = ?');
        $stmt->execute([$reason, $id, $employeeId]);

        log_attendance_audit(
            $id,
            $employeeId,
            'DELETE',
            $existing['attendance_date'],
            null,
            $existing['time_in'],
            null,
            $existing['time_out'],
            null,
            $reason
        );

        flash('success', 'Attendance record marked as deleted.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'restore_attendance') {
        $id = (int)($_POST['attendance_id'] ?? 0);
        $reason = trim($_POST['restore_reason'] ?? 'Restored by employee');

        if (!$id) {
            flash('error', 'Invalid attendance record.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([$id, $employeeId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            flash('error', 'Deleted attendance record not found.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (active_attendance_exists($employeeId, $existing['attendance_date'])) {
            flash('error', 'Cannot restore: an active attendance record already exists for ' . $existing['attendance_date'] . '.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('UPDATE attendance SET deleted_at = NULL, deletion_reason = NULL, modification_reason = ? WHERE id = ? AND employee_id = ?');
        $stmt->execute([$reason, $id, $employeeId]);

        log_attendance_audit(
            $id,
            $employeeId,
            'RESTORE',
            null,
            $existing['attendance_date'],
            null,
            $existing['time_in'],
            null,
            $existing['time_out'],
            $reason
        );

        flash('success', 'Attendance record restored successfully.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'permanent_delete_attendance') {
        $id = (int)($_POST['attendance_id'] ?? 0);

        if (!$id) {
            flash('error', 'Invalid attendance record specified.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([$id, $employeeId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            flash('error', 'Soft-deleted attendance record not found or already removed.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $stmt = db()->prepare('DELETE FROM attendance WHERE id = ? AND employee_id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id, $employeeId]);

        flash('success', 'Attendance record for ' . e(date('M d, Y', strtotime($existing['attendance_date']))) . ' has been permanently deleted.');
        header('Location: ' . $redirectUrl);
        exit;
    }
}
