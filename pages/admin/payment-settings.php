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
    <title>Payment Settings - Mother Care POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-shield-alt"></i> Payment Settings</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-outline-primary btn-sm">POS</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="business-settings.php" class="btn btn-outline-secondary btn-sm">Business Info</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="../products.html" class="btn btn-outline-dark btn-sm">View Storefront</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-wallet"></i> Paystack Mobile Money</h5>
                    </div>
                    <div class="card-body">
                        <form id="paymentSettingsForm">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="enabled">
                                <label class="form-check-label" for="enabled">Enable Mobile Money Checkout</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="useSandbox">
                                <label class="form-check-label" for="useSandbox">Use Test Mode (Sandbox)</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="publicKey">Paystack Public Key</label>
                                <input type="text" id="publicKey" class="form-control" maxlength="200" placeholder="pk_test_xxx or pk_live_xxx">
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="secretKey">Paystack Secret Key</label>
                                <input type="password" id="secretKey" class="form-control" maxlength="200" placeholder="Leave blank to keep existing key">
                            </div>
                            <div class="form-text mb-3">Secret key is encrypted before being stored in the database.</div>
                            <div class="d-flex gap-2">
                                <button type="submit" id="saveBtn" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                <button type="button" id="testBtn" class="btn btn-outline-success">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                            </div>
                        </form>
                        <div id="formAlert" class="mt-3"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-circle-info"></i> Status</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Key Source:</strong> <span id="sourceBadge" class="badge bg-secondary">unknown</span></p>
                        <p class="mb-2"><strong>Saved Secret:</strong> <code id="maskedSecret">-</code></p>
                        <p class="mb-2"><strong>Crypto Key:</strong> <span id="cryptoStatus" class="badge bg-secondary">unknown</span></p>
                        <p class="mb-0"><strong>Updated:</strong> <span id="updatedAt">-</span></p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showAlert(message, type) {
            document.getElementById('formAlert').innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        function renderStatus(settings) {
            const source = settings.source || 'database';
            const sourceClass = source === 'environment' ? 'bg-dark' : 'bg-primary';
            const cryptoReady = !!settings.crypto_ready;
            document.getElementById('sourceBadge').className = `badge ${sourceClass}`;
            document.getElementById('sourceBadge').textContent = source;
            document.getElementById('maskedSecret').textContent = settings.secret_key_masked || '(not set)';
            document.getElementById('cryptoStatus').className = `badge ${cryptoReady ? 'bg-success' : 'bg-danger'}`;
            document.getElementById('cryptoStatus').textContent = cryptoReady ? 'ready' : 'missing PAYMENT_SETTINGS_KEY';
            document.getElementById('updatedAt').textContent = settings.updated_at || '-';
        }

        async function loadSettings() {
            const response = await fetch('../../php/payment-settings.php', { cache: 'no-store' });
            const data = await response.json();
            if (!data.success || !data.settings) {
                throw new Error(data.message || 'Failed to load payment settings.');
            }

            const settings = data.settings;
            document.getElementById('enabled').checked = !!settings.enabled;
            document.getElementById('useSandbox').checked = !!settings.use_sandbox;
            document.getElementById('publicKey').value = settings.public_key || '';
            document.getElementById('secretKey').value = '';
            renderStatus(settings);
        }

        async function saveSettings(event) {
            event.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                const payload = {
                    enabled: document.getElementById('enabled').checked ? 1 : 0,
                    use_sandbox: document.getElementById('useSandbox').checked ? 1 : 0,
                    public_key: document.getElementById('publicKey').value.trim(),
                    secret_key: document.getElementById('secretKey').value.trim()
                };

                const response = await fetch('../../php/payment-settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to save payment settings.');
                }

                showAlert('Payment settings saved successfully.', 'success');
                await loadSettings();
            } catch (error) {
                showAlert(error.message, 'danger');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
            }
        }

        async function testConnection() {
            const testBtn = document.getElementById('testBtn');
            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';

            try {
                const payload = {
                    action: 'test',
                    secret_key: document.getElementById('secretKey').value.trim()
                };
                const response = await fetch('../../php/payment-settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Connection test failed.');
                }
                showAlert('Paystack connection test passed.', 'success');
                if (data.settings) {
                    renderStatus(data.settings);
                }
            } catch (error) {
                showAlert(error.message, 'danger');
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="fas fa-plug"></i> Test Connection';
            }
        }

        document.getElementById('paymentSettingsForm').addEventListener('submit', saveSettings);
        document.getElementById('testBtn').addEventListener('click', testConnection);
        loadSettings().catch((error) => showAlert(error.message, 'danger'));
    </script>
</body>
</html>
