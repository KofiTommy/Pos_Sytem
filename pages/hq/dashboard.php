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
        .hq-history-item {
            border: 1px solid var(--hq-border);
            border-radius: 10px;
            padding: 0.6rem;
            margin-bottom: 0.5rem;
            background: #fdfefe;
        }
        .hq-chart-wrap {
            position: relative;
            height: 260px;
        }
        .hq-alert-workflow-controls {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.4rem;
            flex-wrap: wrap;
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

        <div class="row g-3 mb-3">
            <div class="col-xl-8">
                <div class="hq-card p-3">
                    <h2 class="h6 mb-2">Sales Trend</h2>
                    <div class="hq-chart-wrap">
                        <canvas id="salesTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="hq-card p-3">
                    <h2 class="h6 mb-2">Orders Trend</h2>
                    <div class="hq-chart-wrap">
                        <canvas id="ordersTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="hq-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h6 mb-0">Owner/Shop Scoreboard</h2>
                        <button id="exportScoreboardBtn" class="btn btn-sm btn-outline-secondary">Export CSV</button>
                    </div>
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
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h6 mb-0">Alert Center</h2>
                        <button id="exportAlertsBtn" class="btn btn-sm btn-outline-secondary">Export CSV</button>
                    </div>
                    <div id="alertsList" class="small text-muted">No alerts loaded.</div>
                </div>
                <div class="hq-card p-3 mt-3">
                    <h2 class="h6 mb-2">Control Center (Phase 2)</h2>
                    <p class="small text-muted mb-2">
                        Controlled actions are available only when `HQ_ACTIONS_ENABLED=true`.
                    </p>
                    <div id="controlResult" class="small text-muted">No control actions yet.</div>
                </div>
                <div class="hq-card p-3 mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Action History</h2>
                        <button id="refreshHistoryBtn" class="btn btn-sm btn-outline-secondary">Refresh</button>
                    </div>
                    <div id="historyMeta" class="small text-muted mb-2">Latest HQ control actions.</div>
                    <form id="historyFilterForm" class="mb-2">
                        <div class="row g-2">
                            <div class="col-12">
                                <label for="historyActionFilter" class="form-label form-label-sm mb-1">Action Type</label>
                                <select id="historyActionFilter" class="form-select form-select-sm">
                                    <option value="">All actions</option>
                                    <option value="set_business_status">Business status updates</option>
                                    <option value="issue_owner_reset_link">Owner reset links</option>
                                    <option value="set_alert_status">Alert workflow updates</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="historyUserFilter" class="form-label form-label-sm mb-1">Actor</label>
                                <input id="historyUserFilter" class="form-control form-control-sm" maxlength="120" placeholder="e.g. hqadmin">
                            </div>
                            <div class="col-6">
                                <label for="historyFromDate" class="form-label form-label-sm mb-1">From</label>
                                <input type="date" id="historyFromDate" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label for="historyToDate" class="form-label form-label-sm mb-1">To</label>
                                <input type="date" id="historyToDate" class="form-control form-control-sm">
                            </div>
                            <div class="col-12 d-flex gap-2 mt-1">
                                <button id="historyApplyBtn" type="submit" class="btn btn-sm btn-primary w-100">Apply Filters</button>
                                <button id="historyResetBtn" type="button" class="btn btn-sm btn-outline-secondary">Reset</button>
                            </div>
                        </div>
                    </form>
                    <div id="actionHistoryList" class="small text-muted">No actions recorded yet.</div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        const HQ_ACTIONS_ENABLED = <?php echo $hqActionsEnabled ? 'true' : 'false'; ?>;
        let latestScoreboard = [];
        let latestAlerts = [];
        let latestDashboardData = null;
        let actionInFlight = false;
        let alertWorkflowInFlight = false;
        let salesTrendChart = null;
        let ordersTrendChart = null;

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

        async function fetchJsonWithSession(url, options = {}) {
            const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options));
            let data = null;
            try {
                data = await response.json();
            } catch (error) {
                data = null;
            }

            const unauthorized = response.status === 401 || (data && String(data.message || '').toLowerCase() === 'unauthorized');
            if (unauthorized) {
                showAlert('Session expired. Redirecting to HQ login...', 'warning');
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 350);
                throw new Error('Unauthorized');
            }

            return { response, data };
        }

        function csvEscape(value) {
            const text = String(value ?? '');
            if (/[",\r\n]/.test(text)) {
                return '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        }

        function downloadCsv(filename, headers, rows) {
            const head = headers.map(csvEscape).join(',');
            const body = rows.map((row) => row.map(csvEscape).join(',')).join('\r\n');
            const csv = head + '\r\n' + body;
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function exportScoreboardCsv() {
            if (!Array.isArray(latestScoreboard) || latestScoreboard.length === 0) {
                showAlert('No scoreboard data to export.', 'warning');
                return;
            }

            const headers = [
                'business_code',
                'business_name',
                'owner_username',
                'owner_email',
                'plan',
                'status',
                'orders_count',
                'gross_sales',
                'paid_sales_ratio',
                'issue_rate',
                'low_stock_count',
                'stockout_count',
                'unique_visitors',
                'new_messages',
                'risk_level'
            ];
            const rows = latestScoreboard.map((row) => ([
                row.business_code || '',
                row.business_name || '',
                row.owner_username || '',
                row.owner_email || '',
                row.subscription_plan || '',
                row.status || '',
                Number(row.orders_count || 0),
                Number(row.gross_sales || 0).toFixed(2),
                Number(row.paid_sales_ratio || 0).toFixed(4),
                Number(row.issue_rate || 0).toFixed(4),
                Number(row.low_stock_count || 0),
                Number(row.stockout_count || 0),
                Number(row.unique_visitors || 0),
                Number(row.new_messages || 0),
                row.risk_level || ''
            ]));

            const now = new Date().toISOString().slice(0, 10);
            downloadCsv(`hq-scoreboard-${now}.csv`, headers, rows);
        }

        function exportAlertsCsv() {
            if (!Array.isArray(latestAlerts) || latestAlerts.length === 0) {
                showAlert('No alerts data to export.', 'warning');
                return;
            }

            const headers = [
                'alert_key',
                'severity',
                'workflow_status',
                'business_code',
                'business_name',
                'title',
                'detail',
                'workflow_updated_by',
                'workflow_updated_at'
            ];
            const rows = latestAlerts.map((alert) => ([
                alert.alert_key || '',
                alert.severity || '',
                alert.workflow_status || 'open',
                alert.business_code || '',
                alert.business_name || '',
                alert.title || '',
                alert.detail || '',
                alert.workflow_updated_by || '',
                alert.workflow_updated_at || ''
            ]));

            const now = new Date().toISOString().slice(0, 10);
            downloadCsv(`hq-alerts-${now}.csv`, headers, rows);
        }

        function actionLabel(actionKey) {
            const normalized = String(actionKey || '').toLowerCase();
            if (normalized === 'set_business_status') return 'Business status updated';
            if (normalized === 'issue_owner_reset_link') return 'Owner reset link issued';
            if (normalized === 'set_alert_status') return 'Alert workflow updated';
            if (!normalized) return 'Action';
            return normalized.replace(/_/g, ' ');
        }

        function actionSummary(actionKey, payload) {
            const details = (payload && typeof payload === 'object') ? payload : {};
            const normalized = String(actionKey || '').toLowerCase();

            if (normalized === 'set_business_status') {
                const previousStatus = String(details.previous_status || '-');
                const newStatus = String(details.new_status || '-');
                return `Status: ${previousStatus} -> ${newStatus}`;
            }

            if (normalized === 'issue_owner_reset_link') {
                const ownerUsername = String(details.owner_username || '');
                const expiresAt = String(details.expires_at || '');
                if (ownerUsername && expiresAt) {
                    return `Owner: ${ownerUsername} | Expires: ${expiresAt}`;
                }
                if (ownerUsername) {
                    return `Owner: ${ownerUsername}`;
                }
                if (expiresAt) {
                    return `Expires: ${expiresAt}`;
                }
            }

            if (normalized === 'set_alert_status') {
                const status = String(details.status || '');
                const title = String(details.title || '');
                if (title && status) {
                    return `${title} -> ${status}`;
                }
                if (status) {
                    return `Status: ${status}`;
                }
            }

            return '';
        }

        function renderActionHistory(actions) {
            const host = document.getElementById('actionHistoryList');
            const rows = Array.isArray(actions) ? actions : [];
            if (rows.length === 0) {
                host.innerHTML = '<div class="text-muted">No actions recorded yet.</div>';
                return;
            }

            host.innerHTML = rows.map((row) => {
                const actionKey = String(row.action_key || '');
                const payload = (row.payload && typeof row.payload === 'object') ? row.payload : {};
                const summary = actionSummary(actionKey, payload);
                const businessCode = String(row.business_code || '');
                const businessName = businessCode !== '' ? businessCode : `Business #${Number(row.business_id || 0)}`;
                return `
                    <div class="hq-history-item">
                        <div class="fw-semibold">${escapeHtml(actionLabel(actionKey))}</div>
                        <div>${escapeHtml(businessName)}</div>
                        <div class="text-muted">By ${escapeHtml(row.performed_by || '-')} at ${escapeHtml(row.created_at || '-')}</div>
                        ${summary ? `<div class="small mt-1">${escapeHtml(summary)}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        function setDefaultHistoryFilters() {
            document.getElementById('historyActionFilter').value = '';
            document.getElementById('historyUserFilter').value = '';
            document.getElementById('historyFromDate').value = '';
            document.getElementById('historyToDate').value = '';
        }

        function readHistoryFilters() {
            const action = String(document.getElementById('historyActionFilter').value || '').trim();
            const user = String(document.getElementById('historyUserFilter').value || '').trim();
            let from = String(document.getElementById('historyFromDate').value || '').trim();
            let to = String(document.getElementById('historyToDate').value || '').trim();
            if (from !== '' && to !== '' && from > to) {
                const tmp = from;
                from = to;
                to = tmp;
                document.getElementById('historyFromDate').value = from;
                document.getElementById('historyToDate').value = to;
            }
            return { action, user, from, to };
        }

        function historyFilterSummary(filters) {
            const f = (filters && typeof filters === 'object') ? filters : {};
            const parts = [];
            if (f.action) {
                parts.push(`action=${String(f.action)}`);
            }
            if (f.user) {
                parts.push(`actor=${String(f.user)}`);
            }
            if (f.from || f.to) {
                parts.push(`date=${String(f.from || '...')}..${String(f.to || '...')}`);
            }
            return parts.join(' | ');
        }

        async function loadActionHistory() {
            const host = document.getElementById('actionHistoryList');
            const meta = document.getElementById('historyMeta');
            const filters = readHistoryFilters();
            host.innerHTML = '<div class="text-muted">Loading action history...</div>';
            meta.textContent = 'Latest HQ control actions.';

            try {
                const params = new URLSearchParams({ limit: '20' });
                if (filters.action) {
                    params.set('action', filters.action);
                }
                if (filters.user) {
                    params.set('user', filters.user);
                }
                if (filters.from) {
                    params.set('from', filters.from);
                }
                if (filters.to) {
                    params.set('to', filters.to);
                }

                const { response, data } = await fetchJsonWithSession(`../../php/hq-action-history.php?${params.toString()}`);
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Failed to load action history.');
                }
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load action history.');
                }

                renderActionHistory(data.actions || []);
                const count = Number((data.meta || {}).count || 0);
                const summary = historyFilterSummary((data.meta || {}).filters || filters);
                meta.textContent = `Showing latest ${count} action${count === 1 ? '' : 's'}${summary ? ` | ${summary}` : ''}.`;
            } catch (error) {
                meta.textContent = 'Unable to load action history.';
                host.innerHTML = `<div class="text-danger">${escapeHtml(error.message || 'Failed to load action history.')}</div>`;
            }
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

        function renderTrendCharts(trends) {
            if (typeof Chart === 'undefined') {
                return;
            }

            const labels = Array.isArray((trends || {}).labels) ? trends.labels : [];
            const orders = Array.isArray((trends || {}).orders) ? trends.orders : [];
            const gross = Array.isArray((trends || {}).gross_sales) ? trends.gross_sales : [];
            const paid = Array.isArray((trends || {}).paid_sales) ? trends.paid_sales : [];

            const salesCanvas = document.getElementById('salesTrendChart');
            const ordersCanvas = document.getElementById('ordersTrendChart');
            if (!salesCanvas || !ordersCanvas) {
                return;
            }

            if (salesTrendChart) {
                salesTrendChart.destroy();
            }
            if (ordersTrendChart) {
                ordersTrendChart.destroy();
            }

            salesTrendChart = new Chart(salesCanvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Gross Sales',
                            data: gross,
                            borderColor: '#0f5a8a',
                            backgroundColor: 'rgba(15, 90, 138, 0.12)',
                            fill: true,
                            tension: 0.25,
                            borderWidth: 2
                        },
                        {
                            label: 'Paid Sales',
                            data: paid,
                            borderColor: '#1b7a40',
                            backgroundColor: 'rgba(27, 122, 64, 0.10)',
                            fill: true,
                            tension: 0.25,
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => `GHS ${Number(value || 0).toFixed(0)}`
                            }
                        }
                    },
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });

            ordersTrendChart = new Chart(ordersCanvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Orders',
                            data: orders,
                            borderColor: '#9a6b00',
                            backgroundColor: 'rgba(154, 107, 0, 0.25)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    },
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
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
                const { response, data } = await fetchJsonWithSession('../../php/hq-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Control action failed.');
                }
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

        function alertWorkflowStatus(status) {
            const normalized = String(status || '').toLowerCase();
            if (normalized === 'acknowledged') return 'acknowledged';
            if (normalized === 'resolved') return 'resolved';
            return 'open';
        }

        function alertWorkflowBadgeClass(status) {
            const normalized = alertWorkflowStatus(status);
            if (normalized === 'resolved') return 'text-bg-success';
            if (normalized === 'acknowledged') return 'text-bg-warning';
            return 'text-bg-secondary';
        }

        function alertWorkflowButtons(alert) {
            const status = alertWorkflowStatus(alert.workflow_status);
            if (status === 'open') {
                return `
                    <button class="btn btn-sm btn-outline-warning" data-alert-action="acknowledge" data-alert-key="${escapeHtml(alert.alert_key || '')}">Acknowledge</button>
                    <button class="btn btn-sm btn-outline-success" data-alert-action="resolve" data-alert-key="${escapeHtml(alert.alert_key || '')}">Resolve</button>
                `;
            }
            if (status === 'acknowledged') {
                return `
                    <button class="btn btn-sm btn-outline-success" data-alert-action="resolve" data-alert-key="${escapeHtml(alert.alert_key || '')}">Resolve</button>
                    <button class="btn btn-sm btn-outline-secondary" data-alert-action="reopen" data-alert-key="${escapeHtml(alert.alert_key || '')}">Reopen</button>
                `;
            }
            return `
                <button class="btn btn-sm btn-outline-secondary" data-alert-action="reopen" data-alert-key="${escapeHtml(alert.alert_key || '')}">Reopen</button>
            `;
        }

        function renderAlerts(alerts) {
            latestAlerts = Array.isArray(alerts) ? alerts : [];
            const alertsList = document.getElementById('alertsList');
            if (!latestAlerts || latestAlerts.length === 0) {
                alertsList.innerHTML = '<div class="text-muted">No alerts for current range.</div>';
                return;
            }

            const severityClass = (severity) => {
                const normalized = String(severity || '').toLowerCase();
                if (normalized === 'high') return 'danger';
                if (normalized === 'medium') return 'warning';
                return 'secondary';
            };

            alertsList.innerHTML = latestAlerts.map((alert) => {
                const workflowStatus = alertWorkflowStatus(alert.workflow_status);
                const workflowInfo = (alert.workflow_updated_by || alert.workflow_updated_at)
                    ? `Updated by ${escapeHtml(alert.workflow_updated_by || '-')} at ${escapeHtml(alert.workflow_updated_at || '-')}`
                    : 'No workflow update yet.';
                return `
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">${escapeHtml(alert.title || 'Alert')}</div>
                            <div>${escapeHtml(alert.business_name || '-')} (${escapeHtml(alert.business_code || '-')})</div>
                            <div class="text-muted">${escapeHtml(alert.detail || '')}</div>
                            <div class="small text-muted mt-1">${workflowInfo}</div>
                            <div class="hq-alert-workflow-controls">
                                ${alertWorkflowButtons(alert)}
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge text-bg-${severityClass(alert.severity)}">${escapeHtml(alert.severity || 'info')}</span>
                            <span class="badge ${alertWorkflowBadgeClass(workflowStatus)}">${escapeHtml(workflowStatus)}</span>
                        </div>
                    </div>
                </div>
            `;
            }).join('');
        }

        function findAlertByKey(alertKey) {
            const key = String(alertKey || '');
            return latestAlerts.find((item) => String(item.alert_key || '') === key) || null;
        }

        async function runAlertWorkflow(alert, nextStatus) {
            if (!alert || !alert.alert_key) {
                return;
            }
            if (alertWorkflowInFlight) {
                showAlert('Another alert workflow update is in progress.', 'warning');
                return;
            }

            alertWorkflowInFlight = true;
            try {
                const { response, data } = await fetchJsonWithSession('../../php/hq-alert-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        alert_key: String(alert.alert_key || ''),
                        status: String(nextStatus || 'open'),
                        business_code: String(alert.business_code || ''),
                        title: String(alert.title || ''),
                        detail: String(alert.detail || '')
                    })
                });
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Failed to update alert workflow.');
                }
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update alert workflow.');
                }

                showAlert(`Alert status updated to ${nextStatus}.`, 'success');
                await loadDashboard();
                await loadActionHistory();
            } finally {
                alertWorkflowInFlight = false;
            }
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
                const { response, data } = await fetchJsonWithSession(`../../php/hq-dashboard-data.php?${params.toString()}`);
                if (!response.ok) {
                    throw new Error((data && data.message) || 'Failed to load HQ dashboard data.');
                }
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load HQ dashboard data.');
                }

                latestDashboardData = data;
                renderOverview(data);
                renderTrendCharts(data.trends || {});
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
                    await loadActionHistory();
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
                    await loadActionHistory();
                    return;
                }
            } catch (error) {
                showControlResult(error.message || 'Control action failed.', 'danger');
            } finally {
                button.disabled = false;
            }
        });

        document.getElementById('alertsList').addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-alert-action]');
            if (!button) return;

            const action = String(button.getAttribute('data-alert-action') || '').toLowerCase();
            const alertKey = String(button.getAttribute('data-alert-key') || '');
            const alert = findAlertByKey(alertKey);
            if (!alert) return;

            let nextStatus = 'open';
            if (action === 'acknowledge') nextStatus = 'acknowledged';
            if (action === 'resolve') nextStatus = 'resolved';
            if (action === 'reopen') nextStatus = 'open';

            const businessName = String(alert.business_name || alert.business_code || 'this business');
            const proceed = window.confirm(`Set alert status to ${nextStatus} for ${businessName}?`);
            if (!proceed) return;

            button.disabled = true;
            try {
                await runAlertWorkflow(alert, nextStatus);
            } catch (error) {
                showAlert(error.message || 'Failed to update alert workflow.', 'danger');
            } finally {
                button.disabled = false;
            }
        });

        document.getElementById('refreshBtn').addEventListener('click', loadDashboard);
        document.getElementById('resetBtn').addEventListener('click', () => {
            setDefaultRange();
            loadDashboard();
        });
        document.getElementById('exportScoreboardBtn').addEventListener('click', exportScoreboardCsv);
        document.getElementById('exportAlertsBtn').addEventListener('click', exportAlertsCsv);
        document.getElementById('refreshHistoryBtn').addEventListener('click', loadActionHistory);
        document.getElementById('historyFilterForm').addEventListener('submit', (event) => {
            event.preventDefault();
            loadActionHistory();
        });
        document.getElementById('historyResetBtn').addEventListener('click', () => {
            setDefaultHistoryFilters();
            loadActionHistory();
        });

        setDefaultRange();
        setDefaultHistoryFilters();
        loadDashboard();
        loadActionHistory();
    </script>
</body>
</html>
