<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$employeeId = (int)$user['id'];

// Handle edit, delete, restore actions
handle_attendance_action('history.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));

$tab = $_GET['tab'] ?? 'active';
if (!in_array($tab, ['active', 'deleted'], true)) {
    $tab = 'active';
}

$search = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

// Counts for tabs
$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NULL');
$stmt->execute([$employeeId]);
$countActive = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND deleted_at IS NOT NULL');
$stmt->execute([$employeeId]);
$countDeleted = (int)$stmt->fetchColumn();

// Build query
$sql = 'SELECT * FROM attendance WHERE employee_id = ?';
$params = [$employeeId];

if ($tab === 'deleted') {
    $sql .= ' AND deleted_at IS NOT NULL';
} else {
    $sql .= ' AND deleted_at IS NULL';
}

if ($from !== '') {
    $sql .= ' AND attendance_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND attendance_date <= ?';
    $params[] = $to;
}

if ($search !== '') {
    $sql .= ' AND (attendance_date LIKE ? OR modification_reason LIKE ? OR deletion_reason LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY attendance_date DESC, time_in DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$notice = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance History | TimeLog</title>
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
            <a class="nav-link active" href="history.php">History</a>
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
                <p class="eyebrow">ATTENDANCE RECORDS</p>
                <h1>Attendance History</h1>
                <p class="muted">Search, filter, edit records, or review deleted attendance entries.</p>
            </div>
            <div class="date-chip"><?= e(date('l, F j, Y')) ?></div>
        </div>

        <?php if ($notice): ?>
            <div class="alert <?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <!-- Search & Filter Card -->
        <section class="panel filter-card">
            <form method="get" class="filter-form">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">

                <div class="filter-item search-wrap">
                    <label for="q">Search keyword</label>
                    <input type="text" id="q" name="q" placeholder="Search by date or reason..." value="<?= e($search) ?>">
                </div>

                <div class="filter-item">
                    <label for="from">From date</label>
                    <input type="date" id="from" name="from" value="<?= e($from) ?>">
                </div>

                <div class="filter-item">
                    <label for="to">To date</label>
                    <input type="date" id="to" name="to" value="<?= e($to) ?>">
                </div>

                <div class="filter-buttons">
                    <button class="button primary" type="submit">Filter</button>
                    <?php if ($search !== '' || $from !== '' || $to !== ''): ?>
                        <a class="button outline" href="history.php?tab=<?= e($tab) ?>">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Tabs Navigation -->
        <div class="tabs-nav">
            <a class="tab-item <?= $tab === 'active' ? 'active' : '' ?>" href="history.php?tab=active<?= $from ? '&from='.urlencode($from) : '' ?><?= $to ? '&to='.urlencode($to) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>">
                Active Records
                <span class="tab-badge"><?= $countActive ?></span>
            </a>
            <a class="tab-item <?= $tab === 'deleted' ? 'active' : '' ?>" href="history.php?tab=deleted<?= $from ? '&from='.urlencode($from) : '' ?><?= $to ? '&to='.urlencode($to) : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>">
                Deleted Records (Soft Delete)
                <span class="tab-badge"><?= $countDeleted ?></span>
            </a>
        </div>

        <!-- History Records Table -->
        <section class="panel history-panel" style="margin-top:0">
            <div class="panel-title">
                <div>
                    <p class="eyebrow"><?= $tab === 'deleted' ? 'DELETED RECORDS' : 'ACTIVE RECORDS' ?></p>
                    <h2><?= $tab === 'deleted' ? 'Soft-deleted attendance records' : 'Recorded attendance entries' ?> (<?= count($records) ?>)</h2>
                </div>
                <a class="button outline" href="export.php">Export CSV</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Modifications</th>
                            <?php if ($tab === 'deleted'): ?>
                                <th>Deletion Reason</th>
                            <?php endif; ?>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$records): ?>
                            <tr>
                                <td colspan="<?= $tab === 'deleted' ? 7 : 6 ?>" class="empty">
                                    No <?= $tab === 'deleted' ? 'deleted' : 'attendance' ?> records found matching the criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= e(date('M d, Y', strtotime($row['attendance_date']))) ?></strong>
                                        <div style="font-size:11px; color:var(--muted)"><?= e(date('l', strtotime($row['attendance_date']))) ?></div>
                                    </td>
                                    <td><?= e(date('h:i A', strtotime($row['time_in']))) ?></td>
                                    <td><?= $row['time_out'] ? e(date('h:i A', strtotime($row['time_out']))) : '—' ?></td>
                                    <td>
                                        <?php if ($row['deleted_at']): ?>
                                            <span class="status pending">Deleted</span>
                                        <?php elseif ($row['time_out']): ?>
                                            <span class="status complete">Completed</span>
                                        <?php else: ?>
                                            <span class="status active">In progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['modification_reason'])): ?>
                                            <span class="badge-modified" title="<?= e($row['modification_reason']) ?>">Modified</span>
                                            <div class="muted-note" title="<?= e($row['modification_reason']) ?>">
                                                <?= e(mb_strimwidth($row['modification_reason'], 0, 30, '...')) ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--muted)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($tab === 'deleted'): ?>
                                        <td>
                                            <span class="muted-note" title="<?= e($row['deletion_reason']) ?>">
                                                <?= e($row['deletion_reason'] ?: 'No reason given') ?>
                                            </span>
                                            <div style="font-size:10px; color:var(--muted)">
                                                <?= e(date('M d, Y h:i A', strtotime($row['deleted_at']))) ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align:right">
                                        <div class="action-buttons-cell">
                                            <?php if (!$row['deleted_at']): ?>
                                                <!-- Edit Button -->
                                                <button type="button" class="btn-action edit" title="Edit attendance record"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-date="<?= e($row['attendance_date']) ?>"
                                                    data-time-in="<?= e(date('H:i:s', strtotime($row['time_in']))) ?>"
                                                    data-time-out="<?= $row['time_out'] ? e(date('H:i:s', strtotime($row['time_out']))) : '' ?>"
                                                    data-reason="<?= e($row['modification_reason'] ?? '') ?>"
                                                    onclick="openEditModal(this)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                                    Edit
                                                </button>

                                                <!-- Delete Button -->
                                                <button type="button" class="btn-action delete" title="Request / soft delete record"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-summary="Record date: <?= e(date('M d, Y', strtotime($row['attendance_date']))) ?> (Time In: <?= e(date('h:i A', strtotime($row['time_in']))) ?>, Time Out: <?= $row['time_out'] ? e(date('h:i A', strtotime($row['time_out']))) : '—' ?>)"
                                                    onclick="openDeleteModal(this)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    Delete
                                                </button>
                                            <?php else: ?>
                                                <!-- Restore Button -->
                                                <button type="button" class="btn-action restore" title="Restore soft-deleted record"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-summary="Restore record for <?= e(date('M d, Y', strtotime($row['attendance_date']))) ?> back to active attendance?"
                                                    onclick="openRestoreModal(this)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                    Restore
                                                </button>

                                                <!-- Permanent Delete Button -->
                                                <button type="button" class="btn-action delete" title="Permanently delete from database"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-summary="Record date: <?= e(date('M d, Y', strtotime($row['attendance_date']))) ?> (Time In: <?= e(date('h:i A', strtotime($row['time_in']))) ?>, Time Out: <?= $row['time_out'] ? e(date('h:i A', strtotime($row['time_out']))) : '—' ?>)"
                                                    onclick="openPermanentDeleteModal(this)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                    Delete
                                                </button>
                                            <?php endif; ?>

                                            <!-- Audit Log Button -->
                                            <button type="button" class="btn-action log" title="View Audit Trail"
                                                onclick="openAuditModal(<?= (int)$row['id'] ?>)">
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
                        <textarea id="edit_reason" name="reason" rows="3" required placeholder="Explain why this record is being modified (mandatory)..."></textarea>
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
                        <strong>Soft Delete Notice:</strong> This attendance entry will be removed from your active records but retained in the database for auditing and review.
                    </div>

                    <p id="delete_summary" style="font-size:13px; color:var(--ink); margin-bottom:14px"></p>

                    <label for="delete_reason">
                        Reason for Deletion <span style="color:var(--red)">*</span>
                        <textarea id="delete_reason" name="deletion_reason" rows="3" required placeholder="State reason for deleting this entry (e.g. duplicate entry, incorrect date)..."></textarea>
                    </label>
                </div>

                <div class="modal-footer">
                    <button type="button" class="button outline" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="button danger">Confirm Deletion</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Restore Attendance -->
    <div id="restoreModal" class="modal-backdrop" style="display:none">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Restore Attendance Record</h3>
                <button type="button" class="modal-close" onclick="closeRestoreModal()">&times;</button>
            </div>
            <form method="post" id="restoreForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="restore_attendance">
                <input type="hidden" name="attendance_id" id="restore_attendance_id">

                <div class="modal-body">
                    <p id="restore_summary" style="font-size:13px; color:var(--ink); margin-bottom:14px"></p>

                    <label for="restore_reason">
                        Restore Reason (Optional)
                        <input type="text" id="restore_reason" name="restore_reason" value="Restored by employee">
                    </label>
                </div>

                <div class="modal-footer">
                    <button type="button" class="button outline" onclick="closeRestoreModal()">Cancel</button>
                    <button type="submit" class="button primary">Restore Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Permanent Delete Confirmation -->
    <div id="permanentDeleteModal" class="modal-backdrop" style="display:none">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Permanently Delete Record</h3>
                <button type="button" class="modal-close" onclick="closePermanentDeleteModal()">&times;</button>
            </div>
            <form method="post" id="permanentDeleteForm" onsubmit="return confirm('Are you sure you want to permanently delete this attendance record? This action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="permanent_delete_attendance">
                <input type="hidden" name="attendance_id" id="permanent_delete_attendance_id">

                <div class="modal-body">
                    <div class="alert error" style="margin-bottom:14px">
                        <strong>Permanent Deletion:</strong> This attendance entry and its audit logs will be permanently deleted from the database. This action is irreversible.
                    </div>

                    <p id="permanent_delete_summary" style="font-size:13px; color:var(--ink); margin-bottom:14px"></p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="button outline" onclick="closePermanentDeleteModal()">Cancel</button>
                    <button type="submit" class="button danger">Permanently Delete</button>
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
        return confirm('Are you sure you want to save these modifications to your attendance record?');
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

    function openRestoreModal(btn) {
        if (!btn || !btn.dataset) return;
        document.getElementById('restore_attendance_id').value = btn.dataset.id;
        document.getElementById('restore_summary').textContent = btn.dataset.summary;
        document.getElementById('restoreModal').style.display = 'flex';
    }

    function closeRestoreModal() {
        document.getElementById('restoreModal').style.display = 'none';
    }

    function openPermanentDeleteModal(btn) {
        if (!btn || !btn.dataset) return;
        document.getElementById('permanent_delete_attendance_id').value = btn.dataset.id;
        document.getElementById('permanent_delete_summary').textContent = btn.dataset.summary;
        document.getElementById('permanentDeleteModal').style.display = 'flex';
    }

    function closePermanentDeleteModal() {
        document.getElementById('permanentDeleteModal').style.display = 'none';
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
                if (data.record.deleted_at) {
                    html += '<div style="color:var(--red); font-size:12px; margin-top:4px"><strong>Deleted on:</strong> ' + data.record.deleted_at + ' — Reason: ' + (data.record.deletion_reason || 'None') + '</div>';
                }
                html += '</div>';

                if (!data.logs || data.logs.length === 0) {
                    html += '<p class="empty">No audit history found for this record.</p>';
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

    // Close modals on Escape key or clicking outside
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditModal();
            closeDeleteModal();
            closeRestoreModal();
            closePermanentDeleteModal();
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

