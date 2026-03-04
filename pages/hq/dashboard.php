<?php
include_once __DIR__ . '/../../php/hq-auth.php';
hq_require_page('login.php');
$hqUser = hq_current_username();
$hqActionsEnabled = hq_actions_enabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HQ Dashboard - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --hq-bg: #f3f7fb;
            --hq-card: #ffffff;
            --hq-border: #d8e4ef;
            --hq-primary: #0f5a8a;
            --hq-danger: #b02a37;
            --hq-warning: #9a6b00;
            --hq-success: #1b7a40;
        }
        body {
            background: var(--hq-bg);
            color: #1f2d3d;
        }
        .hq-nav {
            background: #fff;
            border-bottom: 1px solid var(--hq-border);
        }
        .hq-card {
            border: 1px solid var(--hq-border);
            border-radius: 12px;
            background: var(--hq-card);
        }
        .hq-kpi-value {
            font-size: 1.45rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .risk-badge-red {
            background: #ffe8ea;
            color: var(--hq-danger);
        }
        .risk-badge-amber {
            background: #fff5df;
            color: var(--hq-warning);
        }
        .risk-badge-green {
            background: #e7f8ef;
            color: var(--hq-success);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg hq-nav sticky-top">
        <div class="container-fluid px-3 px-lg-4">
            <span class="navbar-brand fw-bold mb-0">HQ Dashboard</span>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge <?php echo $hqActionsEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                    Actions <?php echo $hqActionsEnabled ? 'Enabled' : 'Disabled'; ?>
                </span>
                <span class="small text-muted">Signed in: <?php echo htmlspecialchars($hqUser); ?></span>
                <a href="../../php/hq-logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-3 px-lg-4 py-4">
        <div class="hq-card p-3 mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="fromDate" class="form-label">From</label>
                    <input type="date" id="fromDate" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="toDate" class="form-label">To</label>
                    <input type="date" id="toDate" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="lowStockThreshold" class="form-label">Low Stock Threshold</label>
                    <input type="number" id="lowStockThreshold" class="form-control" min="0" max="1000" value="5">
                </div>
                <div class="col-sm-6 col-lg-4 d-flex gap-2">
                    <button id="refreshBtn" class="btn btn-primary w-100">Refresh</button>
                    <button id="resetBtn" class="btn btn-outline-secondary">Reset</button>
                </div>
            </div>
            <div id="rangeMeta" class="small text-muted mt-2"></div>
        </div>

        <div id="alertHost"></div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Shops</div>
                    <div class="hq-kpi-value"><span id="kpiRegistered">0</span> / <span id="kpiActive">0</span></div>
                    <div class="small text-muted">Registered / Active</div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Gross Sales</div>
                    <div class="hq-kpi-value" id="kpiGrossSales">GHS 0.00</div>
                    <div class="small text-muted">Paid: <span id="kpiPaidSales">GHS 0.00</span></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Orders</div>
                    <div class="hq-kpi-value" id="kpiOrders">0</div>
                    <div class="small text-muted">AOV: <span id="kpiAov">GHS 0.00</span></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Shops Selling Today</div>
                    <div class="hq-kpi-value" id="kpiSellingToday">0</div>
                    <div class="small text-muted">No sales (3d): <span id="kpiNoSales3d">0</span></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Pending Orders</div>
                    <div class="hq-kpi-value" id="kpiPendingOrders">0</div>
                    <div class="small text-muted">Value: <span id="kpiPendingValue">GHS 0.00</span></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Inventory Risk</div>
                    <div class="hq-kpi-value"><span id="kpiLowStock">0</span> / <span id="kpiStockout">0</span></div>
                    <div class="small text-muted">Low-stock / Stockout SKUs</div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Customer Backlog</div>
                    <div class="hq-kpi-value" id="kpiMessages">0</div>
                    <div class="small text-muted">New customer messages</div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="hq-card p-3">
                    <div class="text-muted small">Generated</div>
                    <div class="hq-kpi-value small" id="kpiGenerated">-</div>
                    <div class="small text-muted">UTC timestamp</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="hq-card p-3">
                    <h2 class="h6 mb-3">Owner/Shop Scoreboard</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Shop</th>
                                    <th>Owner</th>
                                    <th>Plan</th>
                                    <th>Orders</th>
                                    <th>Gross</th>
                                    <th>Paid %</th>
                                    <th>Issues %</th>
                                    <th>Low/Out</th>
                                    <th>Visitors</th>
                                    <th>Msgs</th>
                                    <th>Risk</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="scoreboardRows">
                                <tr><td colspan="12" class="text-muted">No data loaded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="hq-card p-3">
                    <h2 class="h6 mb-3">Alert Center</h2>
                    <div id="alertsList" class="small text-muted">No alerts loaded.</div>
                </div>
                <div class="hq-card p-3 mt-3">
                    <h2 class="h6 mb-2">Control Center (Phase 2)</h2>
                    <p class="small text-muted mb-2">
                        Controlled actions are available only when `HQ_ACTIONS_ENABLED=true`.
                    </p>
                    <div id="controlResult" class="small text-muted">No control actions yet.</div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const HQ_ACTIONS_ENABLED = <?php echo $hqActionsEnabled ? 'true' : 'false'; ?>;
        let latestScoreboard = [];
        let actionInFlight = false;

        function asMoney(value) {
            return 'GHS ' + Number(value || 0).toFixed(2);
        }

        function asPercent(value) {
            return (Number(value || 0) * 100).toFixed(1) + '%';
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function riskBadgeClass(level) {
            const normalized = String(level || '').toLowerCase();
            if (normalized === 'red') return 'risk-badge-red';
            if (normalized === 'amber') return 'risk-badge-amber';
            return 'risk-badge-green';
        }

        function showAlert(message, type = 'danger') {
            const host = document.getElementById('alertHost');
            if (!message) {
                host.innerHTML = '';
                return;
            }
            host.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
        }

        function showControlResult(message, type = 'info', extra = {}) {
            const host = document.getElementById('controlResult');
            if (!host) return;

            const parts = [`<div class="alert alert-${type} mb-2">${escapeHtml(message)}</div>`];
            if (extra.reset_link) {
                parts.push(
                    `<div class="mb-1"><strong>Reset Link</strong></div>` +
                    `<textarea class="form-control form-control-sm" rows="3" readonly>${escapeHtml(extra.reset_link)}</textarea>`
                );
            }
            if (extra.expires_at) {
                parts.push(`<div class="text-muted mt-1">Expires at: ${escapeHtml(extra.expires_at)}</div>`);
            }
            host.innerHTML = parts.join('');
        }

        function setDefaultRange() {
            const today = new Date();
            const from = new Date(today);
            from.setDate(today.getDate() - 29);
            document.getElementById('fromDate').value = from.toISOString().split('T')[0];
            document.getElementById('toDate').value = today.toISOString().split('T')[0];
            document.getElementById('lowStockThreshold').value = 5;
        }

        function renderOverview(data) {
            const overview = data.overview || {};
            document.getElementById('kpiRegistered').textContent = Number(overview.registered_businesses || 0);
            document.getElementById('kpiActive').textContent = Number(overview.active_businesses || 0);
            document.getElementById('kpiOrders').textContent = Number(overview.orders_count || 0);
            document.getElementById('kpiGrossSales').textContent = asMoney(overview.gross_sales);
            document.getElementById('kpiPaidSales').textContent = asMoney(overview.paid_sales);
            document.getElementById('kpiAov').textContent = asMoney(overview.avg_order_value);
            document.getElementById('kpiSellingToday').textContent = Number(overview.shops_selling_today || 0);
            document.getElementById('kpiNoSales3d').textContent = Number(overview.no_sales_3d || 0);
            document.getElementById('kpiPendingOrders').textContent = Number(overview.pending_orders_count || 0);
            document.getElementById('kpiPendingValue').textContent = asMoney(overview.pending_orders_value);
            document.getElementById('kpiLowStock').textContent = Number(overview.low_stock_products || 0);
            document.getElementById('kpiStockout').textContent = Number(overview.stockout_products || 0);
            document.getElementById('kpiMessages').textContent = Number(overview.new_messages || 0);
            document.getElementById('kpiGenerated').textContent = String((data.meta || {}).generated_at || '-');
            const range = data.range || {};
            document.getElementById('rangeMeta').textContent = `Range: ${range.from || '-'} to ${range.to || '-'} | Low-stock threshold: ${data.low_stock_threshold}`;
        }

        function renderScoreboard(rows) {
            latestScoreboard = Array.isArray(rows) ? rows : [];
            const tbody = document.getElementById('scoreboardRows');
            if (!latestScoreboard || latestScoreboard.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-muted">No data available.</td></tr>';
                return;
            }

            tbody.innerHTML = latestScoreboard.map((row) => {
                const riskLevel = String(row.risk_level || 'green').toUpperCase();
                const businessId = Number(row.business_id || 0);
                const status = String(row.status || '').toLowerCase();
                let actionCell = '<span class="text-muted">Disabled</span>';
                if (HQ_ACTIONS_ENABLED && businessId > 0) {
                    const toggleLabel = status === 'active' ? 'Suspend' : 'Activate';
                    const toggleClass = status === 'active' ? 'btn-outline-warning' : 'btn-outline-success';
                    actionCell = `
                        <div class="d-flex flex-column gap-1">
                            <button class="btn btn-sm ${toggleClass}" data-action="toggle-status" data-business-id="${businessId}">${toggleLabel}</button>
                            <button class="btn btn-sm btn-outline-primary" data-action="owner-reset" data-business-id="${businessId}">Owner Reset Link</button>
                        </div>
                    `;
                }
                return `
                    <tr>
                        <td>
                            <div class="fw-semibold">${escapeHtml(row.business_name)}</div>
                            <div class="text-muted">${escapeHtml(row.business_code)}</div>
                        </td>
                        <td>
                            <div>${escapeHtml(row.owner_username || '-')}</div>
                            <div class="text-muted">${escapeHtml(row.owner_email || '-')}</div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark">${escapeHtml(row.subscription_plan || '-')}</span>
                            <div class="text-muted">${escapeHtml(row.status || '-')}</div>
                        </td>
                        <td>${Number(row.orders_count || 0)}</td>
                        <td>${asMoney(row.gross_sales)}</td>
                        <td>${asPercent(row.paid_sales_ratio)}</td>
                        <td>${asPercent(row.issue_rate)}</td>
                        <td>${Number(row.low_stock_count || 0)} / ${Number(row.stockout_count || 0)}</td>
                        <td>${Number(row.unique_visitors || 0)}</td>
                        <td>${Number(row.new_messages || 0)}</td>
                        <td><span class="badge ${riskBadgeClass(row.risk_level)}">${riskLevel}</span></td>
                        <td>${actionCell}</td>
                    </tr>
                `;
            }).join('');
        }

        async function runControlAction(payload) {
            if (!HQ_ACTIONS_ENABLED) {
                showControlResult('Actions are disabled. Set HQ_ACTIONS_ENABLED=true in environment.', 'warning');
                return null;
            }
            if (actionInFlight) {
                showControlResult('Another action is in progress. Please wait.', 'warning');
                return null;
            }

            actionInFlight = true;
            try {
                const response = await fetch('../../php/hq-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Control action failed.');
                }
                return data;
            } finally {
                actionInFlight = false;
            }
        }

        function findBusinessRow(businessId) {
            return latestScoreboard.find((row) => Number(row.business_id || 0) === Number(businessId)) || null;
        }

        function renderAlerts(alerts) {
            const alertsList = document.getElementById('alertsList');
            if (!alerts || alerts.length === 0) {
                alertsList.innerHTML = '<div class="text-muted">No alerts for current range.</div>';
                return;
            }

            const severityClass = (severity) => {
                const normalized = String(severity || '').toLowerCase();
                if (normalized === 'high') return 'danger';
                if (normalized === 'medium') return 'warning';
                return 'secondary';
            };

            alertsList.innerHTML = alerts.map((alert) => `
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">${escapeHtml(alert.title || 'Alert')}</div>
                            <div>${escapeHtml(alert.business_name || '-')} (${escapeHtml(alert.business_code || '-')})</div>
                            <div class="text-muted">${escapeHtml(alert.detail || '')}</div>
                        </div>
                        <span class="badge text-bg-${severityClass(alert.severity)}">${escapeHtml(alert.severity || 'info')}</span>
                    </div>
                </div>
            `).join('');
        }

        async function loadDashboard() {
            showAlert('');
            const fromDate = document.getElementById('fromDate').value;
            const toDate = document.getElementById('toDate').value;
            const lowStock = document.getElementById('lowStockThreshold').value;

            if (!fromDate || !toDate) {
                showAlert('Both from and to date are required.', 'warning');
                return;
            }
            if (fromDate > toDate) {
                showAlert('From date cannot be after to date.', 'warning');
                return;
            }

            try {
                const params = new URLSearchParams({
                    from: fromDate,
                    to: toDate,
                    low_stock: String(lowStock || 5)
                });
                const response = await fetch(`../../php/hq-dashboard-data.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load HQ dashboard data.');
                }

                renderOverview(data);
                renderScoreboard(data.scoreboard || []);
                renderAlerts(data.alerts || []);
            } catch (error) {
                showAlert(error.message || 'Failed to load dashboard.');
            }
        }

        document.getElementById('scoreboardRows').addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const action = String(button.getAttribute('data-action') || '');
            const businessId = Number(button.getAttribute('data-business-id') || 0);
            if (!Number.isFinite(businessId) || businessId <= 0) return;

            const row = findBusinessRow(businessId);
            if (!row) return;
            const businessName = String(row.business_name || row.business_code || `Business #${businessId}`);

            try {
                if (action === 'toggle-status') {
                    const currentStatus = String(row.status || '').toLowerCase();
                    const targetStatus = currentStatus === 'active' ? 'suspended' : 'active';
                    const proceed = window.confirm(`Change "${businessName}" status to ${targetStatus}?`);
                    if (!proceed) return;

                    button.disabled = true;
                    const data = await runControlAction({
                        action: 'set_business_status',
                        business_id: businessId,
                        status: targetStatus
                    });
                    if (!data) return;
                    showControlResult(`Business status updated to ${targetStatus}: ${businessName}`, 'success');
                    await loadDashboard();
                    return;
                }

                if (action === 'owner-reset') {
                    const proceed = window.confirm(`Issue a fresh owner password reset link for "${businessName}"?`);
                    if (!proceed) return;

                    button.disabled = true;
                    const data = await runControlAction({
                        action: 'issue_owner_reset_link',
                        business_id: businessId
                    });
                    if (!data) return;
                    showControlResult(
                        `Owner reset link issued for ${businessName}.`,
                        'success',
                        {
                            reset_link: data.reset_link || '',
                            expires_at: data.expires_at || ''
                        }
                    );
                    return;
                }
            } catch (error) {
                showControlResult(error.message || 'Control action failed.', 'danger');
            } finally {
                button.disabled = false;
            }
        });

        document.getElementById('refreshBtn').addEventListener('click', loadDashboard);
        document.getElementById('resetBtn').addEventListener('click', () => {
            setDefaultRange();
            loadDashboard();
        });

        setDefaultRange();
        loadDashboard();
    </script>
</body>
</html>
