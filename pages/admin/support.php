<?php
include '../../php/admin-auth.php';
require_roles_page(['owner'], '../login.html');
$currentRole = current_user_role();
$currentBusinessCode = current_business_code();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$appBasePath = preg_replace('#/pages/admin/.*$#', '', $scriptName);
$appBasePath = rtrim((string)$appBasePath, '/');
$appBaseUrl = $scheme . '://' . $host . $appBasePath;
$tenantStorefrontUrl = $appBaseUrl . '/index.html'
    . ($currentBusinessCode !== '' ? ('?tenant=' . rawurlencode($currentBusinessCode)) : '');
$ownerName = trim((string)($_SESSION['username'] ?? ''));
$ownerEmail = trim((string)($_SESSION['email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CediTill Support - Owner Portal</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-life-ring"></i> CediTill Support</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-outline-primary btn-sm">POS</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales</a>
                <a href="<?php echo htmlspecialchars($tenantStorefrontUrl); ?>" class="btn btn-outline-dark btn-sm">View Storefront</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-headset"></i> Send Platform Support Request</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Use this form for CediTill platform issues (login, dashboard, POS, payments, deployment).
                        </p>
                        <form id="supportForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="supportName">Name</label>
                                    <input id="supportName" class="form-control" maxlength="120" required value="<?php echo htmlspecialchars($ownerName); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="supportEmail">Email</label>
                                    <input id="supportEmail" type="email" class="form-control" maxlength="160" required value="<?php echo htmlspecialchars($ownerEmail); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="supportPhone">Phone</label>
                                    <input id="supportPhone" class="form-control" maxlength="40" placeholder="Optional">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="supportSubject">Subject</label>
                                    <input id="supportSubject" class="form-control" maxlength="180" required placeholder="Short summary of the issue">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="supportMessage">Message</label>
                                    <textarea id="supportMessage" class="form-control" rows="7" maxlength="6000" required placeholder="Describe what happened, where it happened, and what you expected."></textarea>
                                </div>
                                <div class="col-12 d-flex gap-2">
                                    <button id="sendSupportBtn" type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-1"></i> Send to CediTill HQ
                                    </button>
                                    <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                                </div>
                            </div>
                        </form>
                        <div id="supportAlert" class="mt-3"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/api-client.js"></script>
    <script src="../../js/broadcast-notices.js?v=20260304-1"></script>
    <script>
        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showSupportAlert(message, type) {
            document.getElementById('supportAlert').innerHTML =
                `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        document.getElementById('supportForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            const sendBtn = document.getElementById('sendSupportBtn');
            const original = sendBtn.innerHTML;

            const payload = {
                name: document.getElementById('supportName').value.trim(),
                email: document.getElementById('supportEmail').value.trim(),
                phone: document.getElementById('supportPhone').value.trim(),
                subject: document.getElementById('supportSubject').value.trim(),
                message: document.getElementById('supportMessage').value.trim()
            };

            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

            try {
                const response = await fetch('../../php/support-messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to send support request.');
                }
                document.getElementById('supportSubject').value = '';
                document.getElementById('supportMessage').value = '';
                showSupportAlert(`Support request sent successfully (Ticket #${Number(data.ticket_id || 0)}).`, 'success');
            } catch (error) {
                showSupportAlert(error.message || 'Failed to send support request.', 'danger');
            } finally {
                sendBtn.disabled = false;
                sendBtn.innerHTML = original;
            }
        });
    </script>
</body>
</html>
