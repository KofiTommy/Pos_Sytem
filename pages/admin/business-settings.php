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
    <title>Business Info - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
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
                                <label for="heroTagline" class="form-label">Welcome Tagline</label>
                                <textarea id="heroTagline" class="form-control" rows="2" maxlength="320"></textarea>
                                <div class="form-text">Shown under "Welcome to" on the home page.</div>
                            </div>
                            <div class="mb-3">
                                <label for="footerNote" class="form-label">Footer Note</label>
                                <textarea id="footerNote" class="form-control" rows="3" maxlength="320"></textarea>
                                <div class="form-text">Shown in the main storefront footer text.</div>
                            </div>
                            <div class="mb-3">
                                <label for="themePalette" class="form-label">Color Palette</label>
                                <select id="themePalette" class="form-select">
                                    <option value="default">Default Teal</option>
                                    <option value="ocean">Ocean Blue</option>
                                    <option value="sunset">Sunset Orange</option>
                                    <option value="forest">Forest Green</option>
                                    <option value="mono">Slate Mono</option>
                                </select>
                                <div class="form-text">Choose storefront and POS accent colors for this business.</div>
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
                            <h4 class="mb-1" id="namePreview">CediTill</h4>
                            <p class="mb-1 text-muted" id="emailPreview">info@ceditill.com</p>
                            <p class="mb-0 text-muted" id="phonePreview">+233 000 000 000</p>
                            <p class="mt-2 mb-1 small text-muted" id="heroPreview">Universal POS tools to manage sales, inventory, and customers with confidence.</p>
                            <p class="mb-0 small text-muted" id="footerPreview">CediTill helps businesses run faster checkout, smarter stock control, and clear daily sales insights.</p>
                            <span class="badge bg-secondary mt-2" id="palettePreview">Palette: default</span>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-link"></i> Shareable Links</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Use these links so customers and staff open the correct shop.</p>
                        <div class="mb-3">
                            <label for="storeUrlInput" class="form-label">Customer Store URL (always works)</label>
                            <div class="input-group">
                                <input type="text" id="storeUrlInput" class="form-control" readonly>
                                <button type="button" class="btn btn-outline-primary" id="copyStoreUrlBtn">Copy</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="prettyStoreUrlInput" class="form-label">Customer Store URL (pretty)</label>
                            <div class="input-group">
                                <input type="text" id="prettyStoreUrlInput" class="form-control" readonly>
                                <button type="button" class="btn btn-outline-primary" id="copyPrettyStoreUrlBtn">Copy</button>
                            </div>
                        </div>
                        <div class="mb-0">
                            <label for="staffLoginUrlInput" class="form-label">Staff Login URL</label>
                            <div class="input-group">
                                <input type="text" id="staffLoginUrlInput" class="form-control" readonly>
                                <button type="button" class="btn btn-outline-primary" id="copyStaffLoginUrlBtn">Copy</button>
                            </div>
                        </div>
                        <div id="shareLinksAlert" class="small mt-2 text-muted"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/admin-notifications.js"></script>
    <script>
        let currentLogoFilename = '';
        let currentBusinessCode = '';

        function sanitizeTenantCode(value) {
            return String(value || '')
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9-]/g, '')
                .substring(0, 64);
        }

        function resolveBasePath() {
            const path = String(window.location.pathname || '');
            const marker = '/pages/admin/';
            const lower = path.toLowerCase();
            const idx = lower.indexOf(marker);
            if (idx >= 0) {
                return path.substring(0, idx);
            }
            return path.replace(/\/[^/]*$/, '');
        }

        function buildShareUrls(businessCode) {
            const code = sanitizeTenantCode(businessCode);
            if (!code) {
                return {
                    store: '',
                    pretty: '',
                    login: ''
                };
            }

            const origin = String(window.location.origin || '');
            const basePath = resolveBasePath();
            const encodedCode = encodeURIComponent(code);

            return {
                store: `${origin}${basePath}/index.html?tenant=${encodedCode}`,
                pretty: `${origin}${basePath}/b/${encodedCode}/`,
                login: `${origin}${basePath}/pages/login.html?tenant=${encodedCode}`
            };
        }

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

        function showShareAlert(message, type = 'muted') {
            const el = document.getElementById('shareLinksAlert');
            el.className = `small mt-2 text-${type}`;
            el.textContent = message;
        }

        function renderShareLinks() {
            const urls = buildShareUrls(currentBusinessCode);
            const storeInput = document.getElementById('storeUrlInput');
            const prettyInput = document.getElementById('prettyStoreUrlInput');
            const loginInput = document.getElementById('staffLoginUrlInput');

            storeInput.value = urls.store;
            prettyInput.value = urls.pretty;
            loginInput.value = urls.login;

            const hasUrls = !!urls.store;
            document.getElementById('copyStoreUrlBtn').disabled = !hasUrls;
            document.getElementById('copyPrettyStoreUrlBtn').disabled = !hasUrls;
            document.getElementById('copyStaffLoginUrlBtn').disabled = !hasUrls;

            if (hasUrls) {
                showShareAlert(`Business code: ${sanitizeTenantCode(currentBusinessCode)}`, 'muted');
            } else {
                showShareAlert('Business code not found for link generation.', 'warning');
            }
        }

        async function copyInputValue(inputId, successLabel) {
            const input = document.getElementById(inputId);
            const text = String(input.value || '').trim();
            if (!text) {
                showShareAlert('No link to copy yet.', 'warning');
                return;
            }

            try {
                await navigator.clipboard.writeText(text);
                showShareAlert(successLabel, 'success');
            } catch (error) {
                input.focus();
                input.select();
                showShareAlert('Clipboard was blocked. Press Ctrl+C after selecting the text.', 'warning');
            }
        }

        function renderPreview() {
            const name = document.getElementById('businessName').value.trim() || 'Business name';
            const email = document.getElementById('businessEmail').value.trim() || 'email@example.com';
            const phone = document.getElementById('contactNumber').value.trim() || 'Phone number';
            const heroTagline = document.getElementById('heroTagline').value.trim() || 'Universal POS tools to manage sales, inventory, and customers with confidence.';
            const footerNote = document.getElementById('footerNote').value.trim() || 'CediTill helps businesses run faster checkout, smarter stock control, and clear daily sales insights.';
            const palette = document.getElementById('themePalette').value || 'default';
            const removeLogo = document.getElementById('removeLogo').checked;
            const logoFile = document.getElementById('logoFile').files[0];

            document.getElementById('namePreview').textContent = name;
            document.getElementById('emailPreview').textContent = email;
            document.getElementById('phonePreview').textContent = phone;
            document.getElementById('heroPreview').textContent = heroTagline;
            document.getElementById('footerPreview').textContent = footerNote;
            document.getElementById('palettePreview').textContent = 'Palette: ' + palette;

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
            document.getElementById('heroTagline').value = data.settings.hero_tagline || '';
            document.getElementById('footerNote').value = data.settings.footer_note || '';
            document.getElementById('themePalette').value = data.settings.theme_palette || 'default';
            document.getElementById('removeLogo').checked = false;
            document.getElementById('logoFile').value = '';
            currentLogoFilename = data.settings.logo_filename || '';
            currentBusinessCode = sanitizeTenantCode((data.business && data.business.business_code) || '');
            renderPreview();
            renderShareLinks();
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
                payload.append('hero_tagline', document.getElementById('heroTagline').value.trim());
                payload.append('footer_note', document.getElementById('footerNote').value.trim());
                payload.append('theme_palette', document.getElementById('themePalette').value);
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
        document.getElementById('heroTagline').addEventListener('input', renderPreview);
        document.getElementById('footerNote').addEventListener('input', renderPreview);
        document.getElementById('themePalette').addEventListener('change', renderPreview);
        document.getElementById('logoFile').addEventListener('change', renderPreview);
        document.getElementById('removeLogo').addEventListener('change', renderPreview);
        document.getElementById('deleteBtn').addEventListener('click', deleteSettings);
        document.getElementById('copyStoreUrlBtn').addEventListener('click', () => copyInputValue('storeUrlInput', 'Customer store URL copied.'));
        document.getElementById('copyPrettyStoreUrlBtn').addEventListener('click', () => copyInputValue('prettyStoreUrlInput', 'Pretty storefront URL copied.'));
        document.getElementById('copyStaffLoginUrlBtn').addEventListener('click', () => copyInputValue('staffLoginUrlInput', 'Staff login URL copied.'));

        loadSettings().catch((error) => showAlert(error.message, 'danger'));
    </script>
</body>
</html>
