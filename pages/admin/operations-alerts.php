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
    <title>Operations Alerts - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-triangle-exclamation"></i> Operations Alerts</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-primary btn-sm">New Sale (POS)</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="cash-closures.php" class="btn btn-outline-dark btn-sm">Cash Closures</a>
                <a href="audit-trail.php" class="btn btn-outline-dark btn-sm">Audit Trail</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div id="alertHost"></div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Filters and Actions</div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label for="fromDate" class="form-label">From</label>
                        <input type="date" id="fromDate" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label for="toDate" class="form-label">To</label>
                        <input type="date" id="toDate" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select id="statusFilter" class="form-select">
                            <option value="open" selected>Open</option>
                            <option value="acknowledged">Acknowledged</option>
                            <option value="resolved">Resolved</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="severityFilter" class="form-label">Severity</label>
                        <select id="severityFilter" class="form-select">
                            <option value="all" selected>All</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="limitInput" class="form-label">Limit</label>
                        <input type="number" id="limitInput" class="form-control" min="1" max="1000" value="200">
                    </div>
                    <div class="col-md-2">
                        <label for="lowStockThreshold" class="form-label">Low Stock Threshold</label>
                        <input type="number" id="lowStockThreshold" class="form-control" min="0" max="1000" value="5">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button id="applyBtn" class="btn btn-outline-primary">Apply Filters</button>
                        <button id="resetBtn" class="btn btn-outline-secondary">Reset</button>
                        <button id="scanBtn" class="btn btn-primary">
                            <i class="fas fa-arrows-rotate"></i> Run Scan Now
                        </button>
                        <button id="ackAllBtn" class="btn btn-outline-success">
                            <i class="fas fa-check-double"></i> Acknowledge Open
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Total</p>
                        <h4 class="mb-0" id="kpiTotal">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Open</p>
                        <h4 class="mb-0 text-danger" id="kpiOpen">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Acknowledged</p>
                        <h4 class="mb-0 text-info" id="kpiAcknowledged">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Resolved</p>
                        <h4 class="mb-0 text-success" id="kpiResolved">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">High</p>
                        <h4 class="mb-0 text-danger" id="kpiHigh">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Medium / Low</p>
                        <h4 class="mb-0" id="kpiMediumLow">0 / 0</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Detected Alerts</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Detected</th>
                                <th>Severity</th>
                                <th>Alert</th>
                                <th>Metric</th>
                                <th>Status</th>
                                <th>Acknowledged</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="alertsRows">
                            <tr><td colspan="7" class="text-muted">No alerts loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showAlert(message, type = 'danger') {
            const host = document.getElementById('alertHost');
            if (!message) {
                host.innerHTML = '';
                return;
            }
            host.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
        }

        function asMoney(value) {
            return 'GHS ' + Number(value || 0).toFixed(2);
        }

        function defaultRange() {
            const today = new Date();
            const from = new Date(today);
            from.setDate(today.getDate() - 13);
            document.getElementById('fromDate').value = from.toISOString().split('T')[0];
            document.getElementById('toDate').value = today.toISOString().split('T')[0];
            document.getElementById('statusFilter').value = 'open';
            document.getElementById('severityFilter').value = 'all';
            document.getElementById('limitInput').value = '200';
            document.getElementById('lowStockThreshold').value = '5';
        }

        function severityBadgeClass(severity) {
            const s = String(severity || '').toLowerCase();
            if (s === 'high') return 'danger';
            if (s === 'medium') return 'warning text-dark';
            if (s === 'low') return 'secondary';
            return 'light text-dark';
        }

        function statusBadgeClass(status) {
            const s = String(status || '').toLowerCase();
            if (s === 'open') return 'danger';
            if (s === 'acknowledged') return 'info';
            if (s === 'resolved') return 'success';
            return 'secondary';
        }

        function formatMetric(alert) {
            const metric = Number(alert.metric_value || 0);
            const threshold = Number(alert.threshold_value || 0);
            const key = String(alert.alert_key || '').toLowerCase();
            const isRate = key.includes('rate') || (Math.abs(metric) <= 1 && Math.abs(threshold) <= 1 && threshold !== 0);
            const isCash = key.includes('cash') || key.includes('variance');

            if (isRate) {
                return `${(metric * 100).toFixed(1)}% (threshold ${(threshold * 100).toFixed(1)}%)`;
            }
            if (isCash) {
                return `${asMoney(metric)} (threshold ${asMoney(threshold)})`;
            }

            const metricLabel = Number.isInteger(metric) ? String(metric) : metric.toFixed(2);
            const thresholdLabel = Number.isInteger(threshold) ? String(threshold) : threshold.toFixed(2);
            return `${metricLabel} (threshold ${thresholdLabel})`;
        }

        function renderSummary(summary) {
            document.getElementById('kpiTotal').textContent = Number(summary.total_alerts || 0);
            document.getElementById('kpiOpen').textContent = Number(summary.open_alerts || 0);
            document.getElementById('kpiAcknowledged').textContent = Number(summary.acknowledged_alerts || 0);
            document.getElementById('kpiResolved').textContent = Number(summary.resolved_alerts || 0);
            document.getElementById('kpiHigh').textContent = Number(summary.high_alerts || 0);
            document.getElementById('kpiMediumLow').textContent = `${Number(summary.medium_alerts || 0)} / ${Number(summary.low_alerts || 0)}`;
        }

        function renderAlerts(alerts) {
            const rows = document.getElementById('alertsRows');
            if (!alerts || alerts.length === 0) {
                rows.innerHTML = '<tr><td colspan="7" class="text-muted">No alerts found for current filters.</td></tr>';
                return;
            }

            rows.innerHTML = alerts.map((alert) => {
                const status = String(alert.status || '').toLowerCase();
                const canAcknowledge = status === 'open';
                const context = alert.context && typeof alert.context === 'object' ? JSON.stringify(alert.context) : '';
                const acknowledgedText = alert.acknowledged_at
                    ? `${escapeHtml(alert.acknowledged_by_username || '-')}<br><span class="text-muted small">${escapeHtml(alert.acknowledged_at)}</span>`
                    : '<span class="text-muted">-</span>';

                return `
                    <tr>
                        <td>
                            <div>${escapeHtml(alert.last_detected_at || alert.alert_date || '-')}</div>
                            <div class="small text-muted">${escapeHtml(alert.alert_date || '-')}</div>
                        </td>
                        <td><span class="badge bg-${severityBadgeClass(alert.severity)}">${escapeHtml(alert.severity || 'unknown')}</span></td>
                        <td>
                            <div class="fw-semibold">${escapeHtml(alert.title || 'Alert')}</div>
                            <div class="small text-muted">${escapeHtml(alert.details || '')}</div>
                            <div class="small mt-1"><code>${escapeHtml(alert.alert_key || '')}</code></div>
                            ${context ? `<details class="mt-1"><summary class="small text-muted">Context</summary><pre class="small mb-0">${escapeHtml(context)}</pre></details>` : ''}
                        </td>
                        <td>${escapeHtml(formatMetric(alert))}</td>
                        <td><span class="badge bg-${statusBadgeClass(alert.status)}">${escapeHtml(alert.status || 'unknown')}</span></td>
                        <td>${acknowledgedText}</td>
                        <td>
                            ${canAcknowledge ? `<button class="btn btn-sm btn-outline-success" data-ack-id="${Number(alert.id || 0)}">Acknowledge</button>` : '<span class="text-muted small">No action</span>'}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function buildQueryParams() {
            const from = document.getElementById('fromDate').value;
            const to = document.getElementById('toDate').value;
            const status = document.getElementById('statusFilter').value;
            const severity = document.getElementById('severityFilter').value;
            const limit = String(Math.min(1000, Math.max(1, Number(document.getElementById('limitInput').value || 200))));
            return new URLSearchParams({ from, to, status, severity, limit });
        }

        async function loadAlerts() {
            showAlert('');
            const from = document.getElementById('fromDate').value;
            const to = document.getElementById('toDate').value;
            if (!from || !to) {
                showAlert('Choose both from and to dates.', 'warning');
                return;
            }
            if (from > to) {
                showAlert('From date cannot be after to date.', 'warning');
                return;
            }

            try {
                const response = await fetch(`../../php/operations-alerts.php?${buildQueryParams().toString()}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load operations alerts.');
                }
                renderSummary(data.summary || {});
                renderAlerts(data.alerts || []);
            } catch (error) {
                showAlert(error.message || 'Failed to load operations alerts.');
            }
        }

        async function runScanNow() {
            showAlert('');
            const btn = document.getElementById('scanBtn');
            btn.disabled = true;
            try {
                const threshold = Math.min(1000, Math.max(0, Number(document.getElementById('lowStockThreshold').value || 5)));
                const response = await fetch('../../php/operations-alerts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'scan_now',
                        low_stock_threshold: threshold
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Scan failed.');
                }

                const scan = data.scan || {};
                showAlert(
                    `Scan complete. Detected ${Number(scan.detected_count || 0)} alerts, inserted ${Number(scan.inserted || 0)}, updated ${Number(scan.updated || 0)}, resolved ${Number(scan.resolved || 0)}.`,
                    'success'
                );
                await loadAlerts();
            } catch (error) {
                showAlert(error.message || 'Failed to run scan.');
            } finally {
                btn.disabled = false;
            }
        }

        async function acknowledgeAlert(alertId) {
            showAlert('');
            try {
                const response = await fetch('../../php/operations-alerts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'acknowledge',
                        alert_id: Number(alertId || 0)
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to acknowledge alert.');
                }
                showAlert('Alert acknowledged.', 'success');
                await loadAlerts();
            } catch (error) {
                showAlert(error.message || 'Failed to acknowledge alert.');
            }
        }

        async function acknowledgeAllOpen() {
            showAlert('');
            const btn = document.getElementById('ackAllBtn');
            btn.disabled = true;
            try {
                const response = await fetch('../../php/operations-alerts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'acknowledge_all_open' })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to acknowledge open alerts.');
                }
                const affected = Number(data.affected || 0);
                showAlert(`Acknowledged ${affected} open alert(s).`, 'success');
                await loadAlerts();
            } catch (error) {
                showAlert(error.message || 'Failed to acknowledge open alerts.');
            } finally {
                btn.disabled = false;
            }
        }

        document.getElementById('applyBtn').addEventListener('click', loadAlerts);
        document.getElementById('scanBtn').addEventListener('click', runScanNow);
        document.getElementById('ackAllBtn').addEventListener('click', acknowledgeAllOpen);
        document.getElementById('resetBtn').addEventListener('click', () => {
            defaultRange();
            loadAlerts();
        });

        document.getElementById('alertsRows').addEventListener('click', (event) => {
            const btn = event.target.closest('[data-ack-id]');
            if (!btn) {
                return;
            }
            const alertId = Number(btn.getAttribute('data-ack-id') || 0);
            if (alertId <= 0) {
                return;
            }
            acknowledgeAlert(alertId);
        });

        defaultRange();
        loadAlerts();
    </script>
</body>
</html>
