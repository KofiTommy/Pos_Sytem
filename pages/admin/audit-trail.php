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
    <title>Audit Trail - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-clipboard-list"></i> Audit Trail</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-primary btn-sm">New Sale (POS)</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="cash-closures.php" class="btn btn-outline-dark btn-sm">Cash Closures</a>
                <a href="operations-alerts.php" class="btn btn-outline-danger btn-sm">Ops Alerts</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div id="alertHost"></div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Filters</div>
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
                    <div class="col-md-3">
                        <label for="actionFilter" class="form-label">Action Contains</label>
                        <input type="text" id="actionFilter" class="form-control" maxlength="80" placeholder="e.g. order.update">
                    </div>
                    <div class="col-md-2">
                        <label for="entityTypeFilter" class="form-label">Entity Type</label>
                        <input type="text" id="entityTypeFilter" class="form-control" maxlength="60" placeholder="order/product">
                    </div>
                    <div class="col-md-3">
                        <label for="adjustmentTypeFilter" class="form-label">Adjustment Type Contains</label>
                        <input type="text" id="adjustmentTypeFilter" class="form-control" maxlength="60" placeholder="e.g. pos_sale">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button id="applyBtn" class="btn btn-primary">Apply</button>
                        <button id="resetBtn" class="btn btn-outline-secondary">Reset</button>
                        <button id="exportEventsBtn" class="btn btn-outline-dark">Export Events CSV</button>
                        <button id="exportInventoryBtn" class="btn btn-outline-dark">Export Inventory CSV</button>
                        <button id="exportClosuresBtn" class="btn btn-outline-dark">Export Closures CSV</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Events</p>
                        <h4 class="mb-0" id="kpiEvents">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Adjustments</p>
                        <h4 class="mb-0" id="kpiAdjustments">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Stock In Units</p>
                        <h4 class="mb-0" id="kpiStockIn">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Stock Out Units</p>
                        <h4 class="mb-0" id="kpiStockOut">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Closures</p>
                        <h4 class="mb-0" id="kpiClosures">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Variance Total</p>
                        <h4 class="mb-0" id="kpiVariance">GHS 0.00</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Business Audit Events</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Actor</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="eventsRows">
                            <tr><td colspan="5" class="text-muted">No events loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Inventory Adjustments</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Product</th>
                                <th>Order</th>
                                <th>Delta</th>
                                <th>Before</th>
                                <th>After</th>
                                <th>Actor</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryRows">
                            <tr><td colspan="9" class="text-muted">No adjustments loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Recent Cash Closures</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift</th>
                                <th>Expected</th>
                                <th>Counted</th>
                                <th>Variance</th>
                                <th>By</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody id="closuresRows">
                            <tr><td colspan="7" class="text-muted">No closures loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        function asMoney(value) {
            return 'GHS ' + Number(value || 0).toFixed(2);
        }

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

        function setDefaultRange() {
            const today = new Date();
            const from = new Date(today);
            from.setDate(today.getDate() - 13);
            document.getElementById('fromDate').value = from.toISOString().split('T')[0];
            document.getElementById('toDate').value = today.toISOString().split('T')[0];
            document.getElementById('actionFilter').value = '';
            document.getElementById('entityTypeFilter').value = '';
            document.getElementById('adjustmentTypeFilter').value = '';
        }

        function renderSummary(summary) {
            document.getElementById('kpiEvents').textContent = Number(summary.total_events || 0);
            document.getElementById('kpiAdjustments').textContent = Number(summary.total_adjustments || 0);
            document.getElementById('kpiStockIn').textContent = Number(summary.stock_in_units || 0);
            document.getElementById('kpiStockOut').textContent = Number(summary.stock_out_units || 0);
            document.getElementById('kpiClosures').textContent = Number(summary.closures_count || 0);
            document.getElementById('kpiVariance').textContent = asMoney(summary.variance_total || 0);
        }

        function renderEvents(events) {
            const rows = document.getElementById('eventsRows');
            if (!events || events.length === 0) {
                rows.innerHTML = '<tr><td colspan="5" class="text-muted">No events found for this range.</td></tr>';
                return;
            }

            rows.innerHTML = events.map((event) => {
                const entity = `${event.entity_type || '-'} #${Number(event.entity_id || 0)}`;
                return `
                    <tr>
                        <td>${escapeHtml(event.created_at || '-')}</td>
                        <td><span class="badge bg-light text-dark">${escapeHtml(event.action_key || '-')}</span></td>
                        <td>${escapeHtml(entity)}</td>
                        <td>${escapeHtml(event.actor_username || '-')}</td>
                        <td class="small">${escapeHtml(event.details_preview || '')}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderInventory(rowsData) {
            const rows = document.getElementById('inventoryRows');
            if (!rowsData || rowsData.length === 0) {
                rows.innerHTML = '<tr><td colspan="9" class="text-muted">No inventory adjustments found for this range.</td></tr>';
                return;
            }

            rows.innerHTML = rowsData.map((row) => {
                const delta = Number(row.quantity_delta || 0);
                const deltaClass = delta < 0 ? 'text-danger' : (delta > 0 ? 'text-success' : '');
                return `
                    <tr>
                        <td>${escapeHtml(row.created_at || '-')}</td>
                        <td><span class="badge bg-light text-dark">${escapeHtml(row.adjustment_type || '-')}</span></td>
                        <td>${escapeHtml(row.product_name || '-')}</td>
                        <td>${Number(row.order_id || 0) > 0 ? ('#' + Number(row.order_id)) : '-'}</td>
                        <td class="${deltaClass}">${delta > 0 ? '+' : ''}${delta}</td>
                        <td>${Number(row.stock_before || 0)}</td>
                        <td>${Number(row.stock_after || 0)}</td>
                        <td>${escapeHtml(row.actor_username || '-')}</td>
                        <td>${escapeHtml(row.reason || '')}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderClosures(closures, hasTable) {
            const rows = document.getElementById('closuresRows');
            if (!hasTable) {
                rows.innerHTML = '<tr><td colspan="7" class="text-muted">Cash closure table is not available.</td></tr>';
                return;
            }
            if (!closures || closures.length === 0) {
                rows.innerHTML = '<tr><td colspan="7" class="text-muted">No cash closures found for this range.</td></tr>';
                return;
            }

            rows.innerHTML = closures.map((closure) => {
                const variance = Number(closure.variance || 0);
                const varianceClass = variance < 0 ? 'text-danger' : (variance > 0 ? 'text-success' : '');
                return `
                    <tr>
                        <td>${escapeHtml(closure.closure_date || '-')}</td>
                        <td>${escapeHtml(closure.shift_label || '-')}</td>
                        <td>${asMoney(closure.expected_cash)}</td>
                        <td>${asMoney(closure.counted_cash)}</td>
                        <td class="${varianceClass}">${asMoney(closure.variance)}</td>
                        <td>${escapeHtml(closure.closed_by_username || '-')}</td>
                        <td>${escapeHtml(closure.updated_at || '-')}</td>
                    </tr>
                `;
            }).join('');
        }

        async function loadAuditTrail() {
            showAlert('');
            const from = document.getElementById('fromDate').value;
            const to = document.getElementById('toDate').value;
            const action = document.getElementById('actionFilter').value.trim();
            const entityType = document.getElementById('entityTypeFilter').value.trim();
            const adjustmentType = document.getElementById('adjustmentTypeFilter').value.trim();

            if (!from || !to) {
                showAlert('Choose both from and to dates.', 'warning');
                return;
            }
            if (from > to) {
                showAlert('From date cannot be after to date.', 'warning');
                return;
            }

            try {
                const params = new URLSearchParams({
                    from,
                    to,
                    action,
                    entity_type: entityType,
                    adjustment_type: adjustmentType,
                    limit_events: '200',
                    limit_inventory: '200'
                });
                const response = await fetch(`../../php/audit-trail.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load audit trail.');
                }

                renderSummary(data.summary || {});
                renderEvents(data.events || []);
                renderInventory(data.inventory_adjustments || []);
                renderClosures(data.recent_cash_closures || [], Boolean(data.has_cash_closures_table));
            } catch (error) {
                showAlert(error.message || 'Failed to load audit trail.');
            }
        }

        function buildExportUrl(dataset) {
            const from = document.getElementById('fromDate').value;
            const to = document.getElementById('toDate').value;
            const action = document.getElementById('actionFilter').value.trim();
            const entityType = document.getElementById('entityTypeFilter').value.trim();
            const adjustmentType = document.getElementById('adjustmentTypeFilter').value.trim();

            if (!from || !to) {
                showAlert('Choose both from and to dates before export.', 'warning');
                return '';
            }
            if (from > to) {
                showAlert('From date cannot be after to date.', 'warning');
                return '';
            }

            const params = new URLSearchParams({
                dataset: dataset,
                from: from,
                to: to,
                action: action,
                entity_type: entityType,
                adjustment_type: adjustmentType,
                limit: '20000'
            });
            return `../../php/audit-trail-export.php?${params.toString()}`;
        }

        function openExport(dataset) {
            showAlert('');
            const url = buildExportUrl(dataset);
            if (!url) return;
            window.open(url, '_blank');
        }

        document.getElementById('applyBtn').addEventListener('click', loadAuditTrail);
        document.getElementById('resetBtn').addEventListener('click', () => {
            setDefaultRange();
            loadAuditTrail();
        });
        document.getElementById('exportEventsBtn').addEventListener('click', () => openExport('events'));
        document.getElementById('exportInventoryBtn').addEventListener('click', () => openExport('inventory'));
        document.getElementById('exportClosuresBtn').addEventListener('click', () => openExport('closures'));

        setDefaultRange();
        loadAuditTrail();
    </script>
</body>
</html>
