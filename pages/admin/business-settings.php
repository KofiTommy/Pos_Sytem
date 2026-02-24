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
    <title>Business Info - Mother Care POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-briefcase"></i> Business Info</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="payment-settings.php" class="btn btn-outline-secondary btn-sm">Payment Settings</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="users.php" class="btn btn-outline-warning btn-sm">Manage Staff</a>
                <a href="../products.html" class="btn btn-outline-dark btn-sm">View Storefront</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" data-notif-total>0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">Notifications</li>
                        <li>
                            <a class="dropdown-item d-flex justify-content-between align-items-center" href="sales.php">
                                Pending Orders
                                <span class="badge bg-warning text-dark" data-pending-orders>0</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex justify-content-between align-items-center" href="dashboard.php#clientMessagesSection">
                                New Messages
                                <span class="badge bg-primary" data-new-messages>0</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-building"></i> Business Details</h5>
                    </div>
                    <div class="card-body">
                        <form id="businessForm">
                            <div class="mb-3">
                                <label for="businessName" class="form-label">Business Name</label>
                                <input type="text" id="businessName" class="form-control" maxlength="180" required>
                            </div>
                            <div class="mb-3">
                                <label for="businessEmail" class="form-label">Business Email</label>
                                <input type="email" id="businessEmail" class="form-control" maxlength="160" required>
                            </div>
                            <div class="mb-3">
                                <label for="contactNumber" class="form-label">Contact Number</label>
                                <input type="text" id="contactNumber" class="form-control" maxlength="40" required>
                            </div>
                            <div class="mb-3">
                                <label for="logoFile" class="form-label">Business Logo</label>
                                <input type="file" id="logoFile" class="form-control" accept="image/*">
                                <div class="form-text">Optional. Upload a logo image (max 5MB).</div>
                            </div>
                            <div class="form-check mb-3">
                                <input type="checkbox" id="removeLogo" class="form-check-input">
                                <label class="form-check-label" for="removeLogo">Remove current logo</label>
                            </div>
                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                <i class="fas fa-save"></i> Update Business Info
                            </button>
                            <button type="button" class="btn btn-outline-danger ms-2" id="deleteBtn">
                                <i class="fas fa-trash"></i> Delete Business Info
                            </button>
                        </form>
                        <div id="formAlert" class="mt-3"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-eye"></i> Preview</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center border rounded p-3 bg-light">
                            <img id="logoPreview" class="img-fluid mb-3 d-none" alt="Business logo preview" style="max-height: 90px;">
                            <h4 class="mb-1" id="namePreview">Mother Care</h4>
                            <p class="mb-1 text-muted" id="emailPreview">info@mothercare.com</p>
                            <p class="mb-0 text-muted" id="phonePreview">+233 000 000 000</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/admin-notifications.js"></script>
    <script>
        let currentLogoFilename = '';

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function sanitizeFilename(value) {
            return String(value || '').replace(/[^a-zA-Z0-9._-]/g, '');
        }

        function showAlert(message, type) {
            document.getElementById('formAlert').innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        function renderPreview() {
            const name = document.getElementById('businessName').value.trim() || 'Business name';
            const email = document.getElementById('businessEmail').value.trim() || 'email@example.com';
            const phone = document.getElementById('contactNumber').value.trim() || 'Phone number';
            const removeLogo = document.getElementById('removeLogo').checked;
            const logoFile = document.getElementById('logoFile').files[0];

            document.getElementById('namePreview').textContent = name;
            document.getElementById('emailPreview').textContent = email;
            document.getElementById('phonePreview').textContent = phone;

            const preview = document.getElementById('logoPreview');
            if (removeLogo) {
                preview.classList.add('d-none');
                preview.removeAttribute('src');
                return;
            }

            if (logoFile) {
                const url = URL.createObjectURL(logoFile);
                preview.src = url;
                preview.classList.remove('d-none');
                return;
            }

            const safeLogo = sanitizeFilename(currentLogoFilename);
            if (safeLogo) {
                preview.src = `../../assets/images/${safeLogo}`;
                preview.classList.remove('d-none');
                return;
            }

            preview.classList.add('d-none');
            preview.removeAttribute('src');
        }

        async function loadSettings() {
            const response = await fetch('../../php/business-settings.php', { cache: 'no-store' });
            const data = await response.json();
            if (!data.success || !data.settings) {
                throw new Error(data.message || 'Failed to load business settings');
            }

            document.getElementById('businessName').value = data.settings.business_name || '';
            document.getElementById('businessEmail').value = data.settings.business_email || '';
            document.getElementById('contactNumber').value = data.settings.contact_number || '';
            document.getElementById('removeLogo').checked = false;
            document.getElementById('logoFile').value = '';
            currentLogoFilename = data.settings.logo_filename || '';
            renderPreview();
        }

        async function saveSettings(event) {
            event.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                const payload = new FormData();
                payload.append('_method', 'PUT');
                payload.append('business_name', document.getElementById('businessName').value.trim());
                payload.append('business_email', document.getElementById('businessEmail').value.trim());
                payload.append('contact_number', document.getElementById('contactNumber').value.trim());
                payload.append('remove_logo', document.getElementById('removeLogo').checked ? '1' : '0');

                const logoFile = document.getElementById('logoFile').files[0];
                if (logoFile) {
                    payload.append('logo_file', logoFile);
                }

                const response = await fetch('../../php/business-settings.php', {
                    method: 'POST',
                    body: payload
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update business settings');
                }

                showAlert('Business info saved successfully.', 'success');
                await loadSettings();
            } catch (error) {
                showAlert(error.message, 'danger');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Update Business Info';
            }
        }

        async function deleteSettings() {
            const ok = confirm('Delete current business info and reset to default values?');
            if (!ok) return;

            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';

            try {
                const response = await fetch('../../php/business-settings.php', {
                    method: 'DELETE'
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to delete business info');
                }

                showAlert('Business info deleted and reset to default.', 'warning');
                await loadSettings();
            } catch (error) {
                showAlert(error.message, 'danger');
            } finally {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Business Info';
            }
        }

        document.getElementById('businessForm').addEventListener('submit', saveSettings);
        document.getElementById('businessName').addEventListener('input', renderPreview);
        document.getElementById('businessEmail').addEventListener('input', renderPreview);
        document.getElementById('contactNumber').addEventListener('input', renderPreview);
        document.getElementById('logoFile').addEventListener('change', renderPreview);
        document.getElementById('removeLogo').addEventListener('change', renderPreview);
        document.getElementById('deleteBtn').addEventListener('click', deleteSettings);

        loadSettings().catch((error) => showAlert(error.message, 'danger'));
    </script>
</body>
</html>
