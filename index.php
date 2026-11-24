<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$employeeId = (int)$user['id'];
$today = date('Y-m-d');

// Process any Edit or Delete action from modal
handle_attendance_action('index.php');

// Handle Time In / Time Out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'time_in') {
            $stmt = db()->prepare("
                SELECT id
                FROM attendance
                WHERE employee_id = ?
                AND attendance_date = ?
                AND time_out IS NULL
                AND deleted_at IS NULL
                LIMIT 1");

            $stmt->execute([$employeeId, $today]);
            $activeRecord = $stmt->fetch();

            if ($activeRecord) {
                flash('error', 'You already have an active time-in record for today.');
            } else {
            try {
                $now = date('Y-m-d H:i:s');
                $stmt = db()->prepare('INSERT INTO attendance (employee_id, attendance_date, time_in) VALUES (?, ?, ?)');
                $stmt->execute([$employeeId, $today, $now]);
                $newId = (int)db()->lastInsertId();

                log_attendance_audit(
                    $newId,
                    $employeeId,
                    'CREATED',
                    null,
                    $today,
                    null,
                    $now,
                    null,
                    null,
                    'Initial time-in recorded'
                );

                flash('success', 'Time-in recorded successfully.');
            } catch (PDOException $ex) {
                flash('error', 'Unable to record time-in. ' . ($ex->getCode() === '23000' ? 'Duplicate record detected.' : 'Please try again.'));
            }
        }
    } elseif ($action === 'time_out') {
        $stmt = db()->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND time_out IS NULL AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$employeeId, $today]);
        $activeRecord = $stmt->fetch();

        if ($activeRecord) {
            $now = date('Y-m-d H:i:s');
            $stmt = db()->prepare('UPDATE attendance SET time_out = ? WHERE id = ?');
            $stmt->execute([$now, (int)$activeRecord['id']]);

            log_attendance_audit(
                (int)$activeRecord['id'],
                $employeeId,
                'TIME_OUT',
                null,
                null,
                null,
                null,
                null,
                $now,
                'Recorded time-out'
            );

            flash('success', 'Time-out recorded successfully.');
        } else {
            flash('error', 'No active time-in found for today, or time-out is already recorded.');
        }
    }
    header('Location: index.php');
    exit;
}

// Today's attendance
$stmt = db()->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$employeeId, $today]);
$todayRecord = $stmt->fetch();

// Dashboard Statistics
$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NULL');
$stmt->execute([$employeeId]);
$statTotalRecords = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NULL AND time_out IS NOT NULL');
$stmt->execute([$employeeId]);
$statCompleted = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NULL AND time_out IS NULL');
$stmt->execute([$employeeId]);
$statPendingTimeOut = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NOT NULL');
$stmt->execute([$employeeId]);
$statDeleted = (int)$stmt->fetchColumn();

// Recent 7 Active Records
$stmt = db()->prepare('SELECT * FROM attendance WHERE employee_id = ? AND deleted_at IS NULL ORDER BY attendance_date DESC, time_in DESC LIMIT 7');
$stmt->execute([$employeeId]);
$recentRecords = $stmt->fetchAll();

$notice = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance Dashboard | TimeLog</title>
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
            <a class="nav-link active" href="index.php">Dashboard</a>
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

    <main class="container">
        <div class="page-heading">
            <div>
                <p class="eyebrow">EMPLOYEE PORTAL</p>
                <h1>Attendance Dashboard</h1>
                <p class="muted">Record your working hours, review activity statistics, and manage your attendance entries.</p>
            </div>
            <div class="date-chip"><?= e(date('l, F j, Y')) ?></div>
        </div>

        <?php if ($notice): ?>
            <div class="alert <?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <!-- Section 5: Attendance Dashboard Stats -->
        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Attendance</div>
                <div class="stat-value"><?= $statTotalRecords ?></div>
                <div class="stat-sub">Active attendance logs</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Completed Days</div>
                <div class="stat-value" style="color:var(--green)"><?= $statCompleted ?></div>
                <div class="stat-sub">Timed in & timed out</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Pending Time-Out</div>
                <div class="stat-value" style="color:<?= $statPendingTimeOut > 0 ? '#b54708' : 'var(--ink)' ?>">
                    <?= $statPendingTimeOut ?>
                </div>
                <div class="stat-sub"><?= $statPendingTimeOut > 0 ? 'Awaiting time-out' : 'All sessions closed' ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Soft-Deleted Records</div>
                <div class="stat-value" style="color:<?= $statDeleted > 0 ? 'var(--red)' : 'var(--muted)' ?>">
                    <?= $statDeleted ?>
                </div>
                <div class="stat-sub">
                    <a href="history.php?tab=deleted" style="color:inherit; text-decoration:underline">Review deleted</a>
                </div>
            </div>
        </section>

        <section class="grid">
            <!-- Today's Attendance Panel -->
            <article class="panel status-panel">
                <div class="panel-title">
                    <div>
                        <p class="eyebrow">TODAY'S ATTENDANCE</p>
                        <h2>Daily time record</h2>
                    </div>
                    <span class="status <?= $todayRecord ? ($todayRecord['time_out'] ? 'complete' : 'active') : 'pending' ?>">
                        <?= $todayRecord ? ($todayRecord['time_out'] ? 'Completed' : 'In progress') : 'Not started' ?>
                    </span>
                </div>

                <div class="time-grid">
                    <div class="time-box">
                        <span>Time in</span>
                        <strong><?= $todayRecord ? e(date('h:i A', strtotime($todayRecord['time_in']))) : '--:--' ?></strong>
                    </div>
                    <div class="time-box">
                        <span>Time out</span>
                        <strong><?= ($todayRecord && $todayRecord['time_out']) ? e(date('h:i A', strtotime($todayRecord['time_out']))) : '--:--' ?></strong>
                    </div>
                </div>

                <div class="action-row">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="time_in">
                        <button class="button primary" type="submit" <?= $todayRecord ? 'disabled' : '' ?>>Time in</button>
                    </form>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="time_out">
                        <button class="button secondary" type="submit" <?= (!$todayRecord || $todayRecord['time_out']) ? 'disabled' : '' ?>>Time out</button>
                    </form>
                </div>

                <p class="helper">Your time is recorded using the server's Asia/Manila timezone. You can record one attendance entry per calendar day.</p>
            </article>

            <!-- Account Summary Panel -->
            <article class="panel profile-panel">
                <p class="eyebrow">ACCOUNT</p>
                <div class="avatar">
                    <?php if (!empty($user['profile_image']) && file_exists(__DIR__ . '/uploads/avatars/' . $user['profile_image'])): ?>
                        <img src="uploads/avatars/<?= e($user['profile_image']) ?>" alt="<?= e($user['full_name']) ?>">
                    <?php else: ?>
                        <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <h2><?= e($user['full_name']) ?></h2>
                <p class="muted">Employee number: <?= e($user['employee_no']) ?></p>

                <div class="profile-line">
                    <span>Account type</span>
                    <strong>Employee</strong>
                </div>
                <div class="profile-line">
                    <span>Email</span>
                    <strong><?= e($user['email']) ?></strong>
                </div>
                <div class="profile-line">
                    <span>Today's date</span>
                    <strong><?= e(date('M d, Y')) ?></strong>
                </div>

                <div class="profile-action">
                    <a class="button outline full" href="account.php">Manage account</a>
                </div>
            </article>
        </section>

        <!-- Section 1 & 5: Recent Attendance Table with Edit & Delete -->
        <section class="panel history-panel">
            <div class="panel-title">
                <div>
                    <p class="eyebrow">YOUR RECORDS</p>
                    <h2>Recent attendance activity</h2>
                </div>
                <div style="display:flex; gap:10px">
                    <a class="button outline" href="history.php">Full History & Filters →</a>
                    <a class="button outline" href="export.php">Export CSV</a>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time in</th>
                            <th>Time out</th>
                            <th>Status</th>
                            <th>Modifications</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentRecords): ?>
                            <tr>
                                <td colspan="6" class="empty">No attendance records yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentRecords as $record): ?>
                                <tr>
                                    <td>
                                        <strong><?= e(date('M d, Y', strtotime($record['attendance_date']))) ?></strong>
                                        <div style="font-size:11px; color:var(--muted)"><?= e(date('l', strtotime($record['attendance_date']))) ?></div>
                                    </td>
                                    <td><?= e(date('h:i A', strtotime($record['time_in']))) ?></td>
                                    <td><?= $record['time_out'] ? e(date('h:i A', strtotime($record['time_out']))) : '—' ?></td>
                                    <td>
                                        <span class="status <?= $record['time_out'] ? 'complete' : 'active' ?>">
                                            <?= $record['time_out'] ? 'Completed' : 'In progress' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['modification_reason'])): ?>
                                            <span class="badge-modified" title="<?= e($record['modification_reason']) ?>">Modified</span>
                                            <div class="muted-note" title="<?= e($record['modification_reason']) ?>">
                                                <?= e(mb_strimwidth($record['modification_reason'], 0, 24, '...')) ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--muted)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <div class="action-buttons-cell">
                                            <!-- Edit Button -->
                                            <button type="button" class="btn-action edit" title="Edit record"
                                                data-id="<?= (int)$record['id'] ?>"
                                                data-date="<?= e($record['attendance_date']) ?>"
                                                data-time-in="<?= e(date('H:i:s', strtotime($record['time_in']))) ?>"
                                                data-time-out="<?= $record['time_out'] ? e(date('H:i:s', strtotime($record['time_out']))) : '' ?>"
                                                data-reason="<?= e($record['modification_reason'] ?? '') ?>"
                                                onclick="openEditModal(this)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                                Edit
                                            </button>

                                            <!-- Delete Button -->
                                            <button type="button" class="btn-action delete" title="Request / soft delete record"
                                                data-id="<?= (int)$record['id'] ?>"
                                                data-summary="Record date: <?= e(date('M d, Y', strtotime($record['attendance_date']))) ?> (Time In: <?= e(date('h:i A', strtotime($record['time_in']))) ?>, Time Out: <?= $record['time_out'] ? e(date('h:i A', strtotime($record['time_out']))) : '—' ?>)"
                                                onclick="openDeleteModal(this)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                Delete
                                            </button>

                                            <!-- Audit Log Button -->
                                            <button type="button" class="btn-action log" title="View Audit Trail"
                                                onclick="openAuditModal(<?= (int)$record['id'] ?>)">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                Log
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Modal: Edit Attendance Record -->
    <div id="editModal" class="modal-backdrop" style="display:none">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Edit Attendance Record</h3>
                <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="post" id="editForm" onsubmit="return confirmEditSubmit();">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="edit_attendance">
                <input type="hidden" name="attendance_id" id="edit_attendance_id">

                <div class="modal-body">
                    <label for="edit_attendance_date">
                        Attendance Date
                        <input type="date" id="edit_attendance_date" name="attendance_date" required>
                    </label>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px">
                        <label for="edit_time_in">
                            Time In
                            <input type="time" step="any" id="edit_time_in" name="time_in" required>
                        </label>

                        <label for="edit_time_out">
                            Time Out (Optional)
                            <input type="time" step="any" id="edit_time_out" name="time_out">
                        </label>
                    </div>

                    <label for="edit_reason">
                        Modification Reason <span style="color:var(--red)">*</span>
                        <textarea id="edit_reason" name="reason" rows="3" required placeholder="State mandatory reason for modifying this record..."></textarea>
                    </label>
                </div>

                <div class="modal-footer">
                    <button type="button" class="button outline" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="button primary">Save Modifications</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Delete (Soft Delete) Confirmation -->
    <div id="deleteModal" class="modal-backdrop" style="display:none">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Request Deletion</h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="post" id="deleteForm" onsubmit="return confirmDeleteSubmit();">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_attendance">
                <input type="hidden" name="attendance_id" id="delete_attendance_id">

                <div class="modal-body">
                    <div class="alert error" style="margin-bottom:14px">
                        <strong>Soft Delete:</strong> This attendance entry will be removed from your active history but kept securely for audit records.
                    </div>

                    <p id="delete_summary" style="font-size:13px; color:var(--ink); margin-bottom:14px"></p>

                    <label for="delete_reason">
                        Reason for Deletion <span style="color:var(--red)">*</span>
                        <textarea id="delete_reason" name="deletion_reason" rows="3" required placeholder="State mandatory reason for requesting deletion..."></textarea>
                    </label>
                </div>

                <div class="modal-footer">
                    <button type="button" class="button outline" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="button danger">Confirm Deletion</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: View Audit Log Trail -->
    <div id="auditModal" class="modal-backdrop" style="display:none">
        <div class="modal-card modal-large">
            <div class="modal-header">
                <h3>Attendance Audit Trail</h3>
                <button type="button" class="modal-close" onclick="closeAuditModal()">&times;</button>
            </div>
            <div class="modal-body" id="auditModalBody">
                <p class="muted">Loading audit log...</p>
            </div>
            <div class="modal-footer">
                <a id="auditFullPageLink" class="button outline" href="#">Open Full Page</a>
                <button type="button" class="button primary" onclick="closeAuditModal()">Close</button>
            </div>
        </div>
    </div>

    <footer class="footer">TimeLog Attendance System</footer>

    <script>
    function openEditModal(btn) {
        if (!btn || !btn.dataset) return;
        document.getElementById('edit_attendance_id').value = btn.dataset.id;
        document.getElementById('edit_attendance_date').value = btn.dataset.date;
        document.getElementById('edit_time_in').value = btn.dataset.timeIn;
        document.getElementById('edit_time_out').value = btn.dataset.timeOut || '';
        document.getElementById('edit_reason').value = '';
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function confirmEditSubmit() {
        const reason = document.getElementById('edit_reason').value.trim();
        if (!reason) {
            alert('A modification reason is required.');
            document.getElementById('edit_reason').focus();
            return false;
        }
        return confirm('Are you sure you want to save these modifications?');
    }

    function openDeleteModal(btn) {
        if (!btn || !btn.dataset) return;
        document.getElementById('delete_attendance_id').value = btn.dataset.id;
        document.getElementById('delete_summary').textContent = btn.dataset.summary;
        document.getElementById('delete_reason').value = '';
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    function confirmDeleteSubmit() {
        const reason = document.getElementById('delete_reason').value.trim();
        if (!reason) {
            alert('A deletion reason is required.');
            document.getElementById('delete_reason').focus();
            return false;
        }
        return confirm('Confirm soft deletion of this attendance record?');
    }

    function openAuditModal(id) {
        document.getElementById('auditFullPageLink').href = 'audit_log.php?attendance_id=' + id;
        const body = document.getElementById('auditModalBody');
        body.innerHTML = '<p class="muted">Loading audit log...</p>';
        document.getElementById('auditModal').style.display = 'flex';

        fetch('audit_log.php?attendance_id=' + id + '&json=1')
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    body.innerHTML = '<div class="alert error">' + data.error + '</div>';
                    return;
                }
                let html = '<div class="audit-modal-summary">';
                html += '<strong>Date:</strong> ' + data.record.attendance_date + ' | <strong>Time In:</strong> ' + (data.record.time_in ? data.record.time_in.substring(11, 19) : '—') + ' | <strong>Time Out:</strong> ' + (data.record.time_out ? data.record.time_out.substring(11, 19) : '—');
                html += '</div>';

                if (!data.logs || data.logs.length === 0) {
                    html += '<p class="empty">No audit history recorded for this entry.</p>';
                } else {
                    html += '<div class="timeline">';
                    data.logs.forEach(log => {
                        html += '<div class="timeline-item">';
                        html += '  <div class="timeline-header">';
                        html += '    <span class="badge-action badge-' + log.action.toLowerCase() + '">' + log.action + '</span>';
                        html += '    <span class="timeline-time">' + log.created_at + '</span>';
                        html += '  </div>';
                        html += '  <div class="timeline-body">';
                        html += '    <p class="timeline-reason"><strong>Reason:</strong> ' + escapeHtml(log.reason) + '</p>';
                        if (log.previous_time_in || log.new_time_in || log.previous_time_out || log.new_time_out || (log.previous_attendance_date && log.new_attendance_date && log.previous_attendance_date !== log.new_attendance_date)) {
                            html += '<div class="diff-box">';
                            if (log.previous_attendance_date && log.new_attendance_date && log.previous_attendance_date !== log.new_attendance_date) {
                                html += '<div class="diff-row"><span>Date:</span> <del>' + log.previous_attendance_date + '</del> → <ins>' + log.new_attendance_date + '</ins></div>';
                            }
                            if (log.previous_time_in || log.new_time_in) {
                                html += '<div class="diff-row"><span>Time In:</span> <del>' + (log.previous_time_in || '—') + '</del> → <ins>' + (log.new_time_in || '—') + '</ins></div>';
                            }
                            if (log.previous_time_out || log.new_time_out) {
                                html += '<div class="diff-row"><span>Time Out:</span> <del>' + (log.previous_time_out || '—') + '</del> → <ins>' + (log.new_time_out || '—') + '</ins></div>';
                            }
                            html += '</div>';
                        }
                        html += '  </div>';
                        html += '</div>';
                    });
                    html += '</div>';
                }
                body.innerHTML = html;
            })
            .catch(err => {
                body.innerHTML = '<div class="alert error">Failed to load audit log. You can view the full page log directly.</div>';
            });
    }

    function closeAuditModal() {
        document.getElementById('auditModal').style.display = 'none';
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditModal();
            closeDeleteModal();
            closeAuditModal();
        }
    });

    document.querySelectorAll('.modal-backdrop').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
    </script>
</body>
</html>
