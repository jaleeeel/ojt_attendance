<?php
require_once __DIR__ . '/config.php';
require_login();

$employeeId = (int)$_SESSION['user_id'];
$attendanceId = (int)($_GET['attendance_id'] ?? 0);

if (!$attendanceId) {
    if (isset($_GET['json']) || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'Missing attendance ID.']);
        exit;
    }
    flash('error', 'Attendance record not specified.');
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? LIMIT 1');
$stmt->execute([$attendanceId, $employeeId]);
$record = $stmt->fetch();

if (!$record) {
    if (isset($_GET['json']) || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Attendance record not found or access denied.']);
        exit;
    }
    flash('error', 'Attendance record not found.');
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM attendance_audit_logs WHERE attendance_id = ? AND employee_id = ? ORDER BY created_at DESC');
$stmt->execute([$attendanceId, $employeeId]);
$logs = $stmt->fetchAll();

if (isset($_GET['json']) || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json');
    echo json_encode([
        'record' => [
            'id' => (int)$record['id'],
            'attendance_date' => $record['attendance_date'],
            'time_in' => $record['time_in'],
            'time_out' => $record['time_out'],
            'deleted_at' => $record['deleted_at'],
            'deletion_reason' => $record['deletion_reason'],
            'modification_reason' => $record['modification_reason']
        ],
        'logs' => $logs
    ]);
    exit;
}

$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log | TimeLog</title>
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
                <p class="eyebrow">AUDIT TRAIL</p>
                <h1>Attendance History Log</h1>
                <p class="muted">Detailed modification and activity history for attendance on <strong><?= e(date('F j, Y', strtotime($record['attendance_date']))) ?></strong>.</p>
            </div>
            <div>
                <a class="button outline" href="history.php">← Back to history</a>
            </div>
        </div>

        <section class="panel account-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">RECORD SUMMARY</p>
                    <h2>Current record status</h2>
                </div>
                <span class="status <?= $record['deleted_at'] ? 'pending' : ($record['time_out'] ? 'complete' : 'active') ?>">
                    <?= $record['deleted_at'] ? 'Deleted (Soft)' : ($record['time_out'] ? 'Completed' : 'In progress') ?>
                </span>
            </div>

            <div class="time-grid" style="margin-bottom:0">
                <div class="time-box">
                    <span>Attendance Date</span>
                    <strong style="font-size:18px"><?= e(date('M d, Y', strtotime($record['attendance_date']))) ?></strong>
                </div>
                <div class="time-box">
                    <span>Time In</span>
                    <strong style="font-size:18px"><?= e(date('h:i A', strtotime($record['time_in']))) ?></strong>
                </div>
                <div class="time-box">
                    <span>Time Out</span>
                    <strong style="font-size:18px"><?= $record['time_out'] ? e(date('h:i A', strtotime($record['time_out']))) : '—' ?></strong>
                </div>
                <div class="time-box">
                    <span>Modified Reason</span>
                    <span style="color:var(--ink); font-weight:600; font-size:13px"><?= $record['modification_reason'] ? e($record['modification_reason']) : 'None' ?></span>
                </div>
            </div>

            <?php if ($record['deleted_at']): ?>
                <div class="alert error" style="margin-top:16px; margin-bottom:0">
                    <strong>Record is deleted:</strong> <?= e($record['deletion_reason'] ?: 'No reason given') ?>
                    <span style="display:block; font-size:11px; margin-top:4px">Deleted on <?= e(date('M d, Y h:i A', strtotime($record['deleted_at']))) ?></span>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel history-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">CHRONOLOGICAL TRAIL</p>
                    <h2>Modifications & activity log</h2>
                </div>
            </div>

            <?php if (!$logs): ?>
                <p class="empty">No audit entries recorded for this attendance entry.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($logs as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-header">
                                <span class="badge-action badge-<?= strtolower(e($log['action'])) ?>">
                                    <?= e($log['action']) ?>
                                </span>
                                <span class="timeline-time"><?= e(date('M d, Y h:i:s A', strtotime($log['created_at']))) ?></span>
                            </div>

                            <div class="timeline-body">
                                <p class="timeline-reason"><strong>Reason:</strong> <?= e($log['reason']) ?></p>

                                <?php if ($log['previous_time_in'] || $log['new_time_in'] || $log['previous_time_out'] || $log['new_time_out'] || ($log['previous_attendance_date'] && $log['new_attendance_date'] && $log['previous_attendance_date'] !== $log['new_attendance_date'])): ?>
                                    <div class="diff-box">
                                        <?php if ($log['previous_attendance_date'] && $log['new_attendance_date'] && $log['previous_attendance_date'] !== $log['new_attendance_date']): ?>
                                            <div class="diff-row">
                                                <span>Date:</span>
                                                <del><?= e(date('M d, Y', strtotime($log['previous_attendance_date']))) ?></del>
                                                → <ins><?= e(date('M d, Y', strtotime($log['new_attendance_date']))) ?></ins>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($log['previous_time_in'] || $log['new_time_in']): ?>
                                            <div class="diff-row">
                                                <span>Time In:</span>
                                                <del><?= $log['previous_time_in'] ? e(date('h:i:s A', strtotime($log['previous_time_in']))) : '—' ?></del>
                                                → <ins><?= $log['new_time_in'] ? e(date('h:i:s A', strtotime($log['new_time_in']))) : '—' ?></ins>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($log['previous_time_out'] || $log['new_time_out']): ?>
                                            <div class="diff-row">
                                                <span>Time Out:</span>
                                                <del><?= $log['previous_time_out'] ? e(date('h:i:s A', strtotime($log['previous_time_out']))) : '—' ?></del>
                                                → <ins><?= $log['new_time_out'] ? e(date('h:i:s A', strtotime($log['new_time_out']))) : '—' ?></ins>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="footer">TimeLog Attendance System</footer>
</body>
</html>

