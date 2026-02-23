<?php
include '../../php/admin-auth.php';
require_roles_page(['owner'], '../login.html');
$currentRole = current_user_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - Mother Care POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-users-cog"></i> Manage Staff</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-outline-primary btn-sm">POS</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="business-settings.php" class="btn btn-outline-primary btn-sm">Business Info</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="../products.html" class="btn btn-outline-dark btn-sm">View Storefront</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-user-plus"></i> Create Sales Account</h5>
                    </div>
                    <div class="card-body">
                        <form id="createUserForm">
                            <div class="mb-3">
                                <label class="form-label" for="username">Username</label>
                                <input class="form-control" id="username" maxlength="60" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email">Email</label>
                                <input class="form-control" id="email" type="email" maxlength="160" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password">Temporary Password</label>
                                <input class="form-control" id="password" type="password" minlength="10" required>
                                <div class="form-text">At least 10 chars with uppercase, lowercase, number, and symbol.</div>
                            </div>
                            <button class="btn btn-primary w-100" id="createBtn" type="submit">
                                <i class="fas fa-save"></i> Create Sales User
                            </button>
                        </form>
                        <div id="formAlert" class="mt-3"></div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-key"></i> Change My Password</h5>
                    </div>
                    <div class="card-body">
                        <form id="changePasswordForm">
                            <div class="mb-3">
                                <label class="form-label" for="currentPassword">Current Password</label>
                                <input class="form-control" id="currentPassword" type="password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="newOwnerPassword">New Password</label>
                                <input class="form-control" id="newOwnerPassword" type="password" minlength="10" required>
                            </div>
                            <button class="btn btn-outline-primary w-100" id="changePasswordBtn" type="submit">
                                <i class="fas fa-shield-alt"></i> Update Password
                            </button>
                        </form>
                        <div id="passwordAlert" class="mt-3"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Existing Users</h5>
                        <button class="btn btn-outline-secondary btn-sm" id="refreshBtn">Refresh</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="usersRows">
                                    <tr><td colspan="6" class="text-muted text-center">Loading users...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Staff Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="resetStaffPasswordForm">
                    <div class="modal-body">
                        <input type="hidden" id="resetStaffUserId">
                        <div class="mb-3">
                            <label class="form-label">Staff Username</label>
                            <input type="text" class="form-control" id="resetStaffUsername" readonly>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="resetStaffNewPassword">New Password</label>
                            <input class="form-control" id="resetStaffNewPassword" type="password" minlength="10" required>
                            <div class="form-text">At least 10 chars with uppercase, lowercase, number, and symbol.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="resetStaffPasswordBtn">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let users = [];
        let resetPasswordModal;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showFormAlert(message, type) {
            document.getElementById('formAlert').innerHTML =
                `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        function showPasswordAlert(message, type) {
            document.getElementById('passwordAlert').innerHTML =
                `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        async function loadUsers() {
            const response = await fetch('../../php/manage-users.php');
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Failed to load users');
            }

            users = data.users || [];
            const rows = document.getElementById('usersRows');
            if (!users.length) {
                rows.innerHTML = '<tr><td colspan="6" class="text-muted text-center">No users found.</td></tr>';
                return;
            }

            rows.innerHTML = users.map((user) => {
                const userId = Number(user.id);
                const username = String(user.username || '');
                const usernameHtml = escapeHtml(username);
                const usernameJs = JSON.stringify(username);
                const emailHtml = escapeHtml(user.email);
                const roleHtml = escapeHtml(user.role);
                const createdHtml = escapeHtml(user.created_at);
                const roleBadge = user.role === 'owner' ? 'bg-warning text-dark' : 'bg-primary';

                return `
                <tr>
                    <td>${userId}</td>
                    <td>${usernameHtml}</td>
                    <td>${emailHtml}</td>
                    <td><span class="badge ${roleBadge}">${roleHtml}</span></td>
                    <td>${createdHtml}</td>
                    <td>
                        ${user.role === 'sales'
                            ? `<div class="d-flex flex-wrap gap-1">
                                <button class="btn btn-sm btn-outline-primary" onclick="openResetPasswordModal(${userId}, ${usernameJs})">Reset Password</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${userId}, ${usernameJs})">Delete</button>
                               </div>`
                            : '<span class="text-muted small">Protected</span>'}
                    </td>
                </tr>
            `;
            }).join('');
        }

        async function createUser(event) {
            event.preventDefault();
            const createBtn = document.getElementById('createBtn');
            const payload = {
                username: document.getElementById('username').value.trim(),
                email: document.getElementById('email').value.trim(),
                password: document.getElementById('password').value,
                role: 'sales'
            };

            createBtn.disabled = true;
            createBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating...';

            try {
                const response = await fetch('../../php/manage-users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to create account');
                }

                document.getElementById('createUserForm').reset();
                showFormAlert('Sales account created successfully.', 'success');
                await loadUsers();
            } catch (error) {
                showFormAlert(error.message, 'danger');
            } finally {
                createBtn.disabled = false;
                createBtn.innerHTML = '<i class="fas fa-save"></i> Create Sales User';
            }
        }

        async function deleteUser(userId, username) {
            const ok = confirm(`Delete sales account "${username}"?`);
            if (!ok) return;

            try {
                const response = await fetch('../../php/manage-users.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: Number(userId) })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to delete user');
                }
                await loadUsers();
            } catch (error) {
                alert(error.message);
            }
        }

        function openResetPasswordModal(userId, username) {
            document.getElementById('resetStaffUserId').value = Number(userId);
            document.getElementById('resetStaffUsername').value = username;
            document.getElementById('resetStaffNewPassword').value = '';
            resetPasswordModal.show();
        }

        async function resetStaffPassword(event) {
            event.preventDefault();
            const resetBtn = document.getElementById('resetStaffPasswordBtn');
            const payload = {
                action: 'reset_staff_password',
                user_id: Number(document.getElementById('resetStaffUserId').value),
                new_password: document.getElementById('resetStaffNewPassword').value
            };

            resetBtn.disabled = true;
            resetBtn.textContent = 'Resetting...';

            try {
                const response = await fetch('../../php/manage-users.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to reset password');
                }

                resetPasswordModal.hide();
                alert('Staff password reset successfully.');
            } catch (error) {
                alert(error.message);
            } finally {
                resetBtn.disabled = false;
                resetBtn.textContent = 'Reset Password';
            }
        }

        async function changeOwnPassword(event) {
            event.preventDefault();
            const btn = document.getElementById('changePasswordBtn');
            const payload = {
                action: 'change_own_password',
                current_password: document.getElementById('currentPassword').value,
                new_password: document.getElementById('newOwnerPassword').value
            };

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

            try {
                const response = await fetch('../../php/manage-users.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update password');
                }

                document.getElementById('changePasswordForm').reset();
                showPasswordAlert('Password changed successfully.', 'success');
            } catch (error) {
                showPasswordAlert(error.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-shield-alt"></i> Update Password';
            }
        }

        document.getElementById('createUserForm').addEventListener('submit', createUser);
        document.getElementById('changePasswordForm').addEventListener('submit', changeOwnPassword);
        document.getElementById('resetStaffPasswordForm').addEventListener('submit', resetStaffPassword);
        document.getElementById('refreshBtn').addEventListener('click', () => {
            loadUsers().catch((error) => showFormAlert(error.message, 'danger'));
        });

        resetPasswordModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
        loadUsers().catch((error) => showFormAlert(error.message, 'danger'));
    </script>
</body>
</html>
