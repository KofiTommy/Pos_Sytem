<?php
include_once __DIR__ . '/../../php/hq-auth.php';
if (hq_is_authenticated()) {
    header('Location: dashboard.php');
    exit();
}
$hqEnabled = hq_is_enabled();
$hqIpAllowed = hq_ip_is_allowed();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Login - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(120deg, #0b2942, #124d6e 55%, #1d7a82);
        }
        .hq-card {
            width: 100%;
            max-width: 430px;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(8, 21, 36, 0.32);
        }
    </style>
</head>
<body>
    <div class="card hq-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-2">HQ Dashboard Access</h1>
            <p class="text-muted mb-4">Platform-level read-only monitoring for all shops.</p>

            <?php if (!$hqEnabled): ?>
                <div class="alert alert-warning mb-0">
                    HQ access is not configured. Set `HQ_ADMIN_USERNAME` and `HQ_ADMIN_PASSWORD_HASH` in environment variables.
                </div>
            <?php elseif (!$hqIpAllowed): ?>
                <div class="alert alert-danger mb-0">
                    HQ access is blocked for this network. Contact the system administrator.
                </div>
            <?php else: ?>
                <div id="alertHost"></div>
                <form id="hqLoginForm">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input id="username" class="form-control" maxlength="120" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input id="password" type="password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="loginBtn">Sign In</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const form = document.getElementById('hqLoginForm');
        const alertHost = document.getElementById('alertHost');
        const loginBtn = document.getElementById('loginBtn');

        function showAlert(message, type = 'danger') {
            if (!alertHost) return;
            if (!message) {
                alertHost.innerHTML = '';
                return;
            }
            alertHost.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        }

        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                loginBtn.disabled = true;
                showAlert('');

                try {
                    const payload = {
                        username: document.getElementById('username').value.trim(),
                        password: document.getElementById('password').value
                    };

                    const response = await fetch('../../php/hq-login.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.message || 'Unable to log in.');
                    }

                    window.location.href = data.redirect || 'dashboard.php';
                } catch (error) {
                    showAlert(error.message || 'Unable to log in.');
                } finally {
                    loginBtn.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
