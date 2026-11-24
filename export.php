<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$employeeId = (int)$user['id'];

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$scope = $_GET['scope'] ?? 'active';
if (!in_array($scope, ['active', 'deleted', 'all'], true)) {
    $scope = 'active';
}

$validFrom = DateTime::createFromFormat('Y-m-d', $from);
$validTo = DateTime::createFromFormat('Y-m-d', $to);
if (!$validFrom || $validFrom->format('Y-m-d') !== $from || !$validTo || $validTo->format('Y-m-d') !== $to || $from > $to) {
    http_response_code(400);
    exit('Invalid date range. Please select valid From and To dates.');
}

if (isset($_GET['download'])) {
    $sql = 'SELECT e.employee_no, e.full_name, a.attendance_date, a.time_in, a.time_out, a.deleted_at, a.deletion_reason, a.modification_reason 
            FROM attendance a 
            INNER JOIN employees e ON e.id = a.employee_id 
            WHERE a.employee_id = ? AND a.attendance_date BETWEEN ? AND ?';

    if ($scope === 'active') {
        $sql .= ' AND a.deleted_at IS NULL';
    } elseif ($scope === 'deleted') {
        $sql .= ' AND a.deleted_at IS NOT NULL';
    }

    $sql .= ' ORDER BY a.attendance_date ASC, a.time_in ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute([$employeeId, $from, $to]);
    $rows = $stmt->fetchAll();

    $filename = 'attendance_' . $scope . '_' . $from . '_to_' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache');
    $out = fopen('php://output', 'w');

    // UTF-8 BOM helps Microsoft Excel display Unicode names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Employee Number',
        'Employee Name',
        'Attendance Date',
        'Time In',
        'Time Out',
        'Attendance Status',
        'Modified',
        'Modification Reason',
        'Deleted',
        'Deletion Date',
        'Deletion Reason'
    ]);

    foreach ($rows as $row) {
        $safe = static function ($value) {
            $value = (string)$value;
            return preg_match('/^[=+@\-]/', $value) ? "'" . $value : $value;
        };

        $isDeleted = !empty($row['deleted_at']);
        $status = $isDeleted ? 'Deleted (Soft)' : ($row['time_out'] ? 'Completed' : 'In progress');

        fputcsv($out, [
            $safe($row['employee_no']),
            $safe($row['full_name']),
            $row['attendance_date'],
            date('Y-m-d h:i:s A', strtotime($row['time_in'])),
            $row['time_out'] ? date('Y-m-d h:i:s A', strtotime($row['time_out'])) : '',
            $status,
            !empty($row['modification_reason']) ? 'Yes' : 'No',
            $safe($row['modification_reason'] ?? ''),
            $isDeleted ? 'Yes' : 'No',
            $row['deleted_at'] ? date('Y-m-d h:i:s A', strtotime($row['deleted_at'])) : '',
            $safe($row['deletion_reason'] ?? '')
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export Attendance | TimeLog</title>
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
            <a class="nav-link active" href="export.php">Export</a>
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
                <p class="eyebrow">REPORTS & EXPORT</p>
                <h1>Export Attendance</h1>
                <p class="muted">Choose a date range and record status filter to download your attendance records as a CSV spreadsheet.</p>
            </div>
        </div>

        <section class="panel export-panel">
            <form method="get">
                <label for="from">
                    From date
                    <input type="date" id="from" name="from" value="<?= e($from) ?>" required>
                </label>

                <label for="to">
                    To date
                    <input type="date" id="to" name="to" value="<?= e($to) ?>" required>
                </label>

                <div style="grid-column: 1 / -1; margin-bottom: 16px">
                    <label for="scope">Records to include</label>
                    <select id="scope" name="scope" style="height:43px; width:100%; border:1px solid #dce2ea; border-radius:8px; padding:0 12px; font:inherit; font-size:13px; color:var(--ink); background:#fff">
                        <option value="active" <?= $scope === 'active' ? 'selected' : '' ?>>Active records only (default, excludes deleted)</option>
                        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All records (active and soft-deleted)</option>
                        <option value="deleted" <?= $scope === 'deleted' ? 'selected' : '' ?>>Deleted records only</option>
                    </select>
                </div>

                <button class="button primary" type="submit" name="download" value="1">Download CSV Spreadsheet</button>
            </form>

            <p class="helper">The export contains only your personal attendance records. The file includes time-in, time-out, completion status, modification reasons, and deletion records where applicable. Compatible with Microsoft Excel, Google Sheets, and LibreOffice Calc.</p>
        </section>
    </main>

    <footer class="footer">TimeLog Attendance System</footer>
</body>
</html>
