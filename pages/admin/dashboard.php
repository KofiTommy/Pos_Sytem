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
    <title>Admin Analytics Dashboard - Mother Care POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        :root {
            --dash-bg-start: #f7fbff;
            --dash-bg-end: #edf3f8;
            --dash-primary: #145da0;
            --dash-accent: #2e8bc0;
            --dash-warn: #d9480f;
            --dash-card: #ffffff;
            --dash-text-muted: #5f6f82;
        }

        body {
            background: radial-gradient(circle at top right, var(--dash-bg-start), var(--dash-bg-end));
            color: #1f2a37;
        }

        .dashboard-shell {
            padding: 24px 0 40px;
        }

        .hero-card {
            border: 0;
            border-radius: 16px;
            background: linear-gradient(140deg, var(--dash-primary), var(--dash-accent));
            color: #fff;
            overflow: hidden;
        }

        .hero-card .btn-light {
            border-radius: 999px;
            font-weight: 600;
        }

        .kpi-card {
            border: 0;
            border-radius: 14px;
            background: var(--dash-card);
            box-shadow: 0 10px 25px rgba(10, 46, 79, 0.08);
        }

        .kpi-card .kpi-label {
            color: var(--dash-text-muted);
            font-size: 0.86rem;
            margin-bottom: 4px;
        }

        .kpi-card .kpi-value {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
        }

        .section-card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 10px 25px rgba(10, 46, 79, 0.08);
        }

        .section-card .card-header {
            background: #fff;
            border-bottom: 1px solid #e9eff5;
            font-weight: 600;
        }

        .mini-pill {
            border-radius: 999px;
            font-size: 0.8rem;
            padding: 0.3rem 0.7rem;
        }

        .stock-alert {
            border-left: 4px solid var(--dash-warn);
            background: #fff7f2;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 10px;
        }

        @media (max-width: 992px) {
            .hero-card .btn {
                width: 100%;
                margin-right: 0 !important;
                margin-bottom: 0.5rem;
            }

            .dashboard-shell {
                padding-top: 16px;
            }
        }

        @media (max-width: 576px) {
            .kpi-card .kpi-value {
                font-size: 1.35rem;
            }

            .section-card .card-header {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="fas fa-chart-pie"></i> Admin Analytics
            </a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-info btn-sm text-white">Dashboard</a>
                <a href="pos.php" class="btn btn-primary btn-sm">New Sale (POS)</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="business-settings.php" class="btn btn-outline-primary btn-sm">Business Info</a>
                <a href="payment-settings.php" class="btn btn-outline-secondary btn-sm">Payment Settings</a>
                <a href="users.php" class="btn btn-outline-warning btn-sm">Manage Staff</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
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
                            <a class="dropdown-item d-flex justify-content-between align-items-center" href="#clientMessagesSection">
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

    <main class="container dashboard-shell">
        <div class="card hero-card mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <p class="mb-2 opacity-75">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                        <h1 class="h3 mb-2">Business Pulse Dashboard</h1>
                        <p class="mb-0 opacity-75">Track sales performance, product movement, and inventory risks in one view.</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="pos.php" class="btn btn-light me-2 mb-2 mb-lg-0">
                            <i class="fas fa-cash-register"></i> Open POS
                        </a>
                        <a href="manage-products.php" class="btn btn-outline-light">
                            <i class="fas fa-boxes"></i> Products
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card section-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="fromDate" class="form-label">From</label>
                        <input type="date" class="form-control" id="fromDate">
                    </div>
                    <div class="col-md-3">
                        <label for="toDate" class="form-label">To</label>
                        <input type="date" class="form-control" id="toDate">
                    </div>
                    <div class="col-md-3">
                        <label for="lowStockThreshold" class="form-label">Low Stock Threshold</label>
                        <input type="number" class="form-control" id="lowStockThreshold" min="0" max="1000" value="5">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="applyFilterBtn" class="btn btn-primary w-100">
                            <i class="fas fa-chart-line"></i> Refresh
                        </button>
                        <button id="resetFilterBtn" class="btn btn-outline-secondary">Reset</button>
                    </div>
                </div>
            </div>
        </div>
<div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Gross Sales</p>
                        <h2 class="kpi-value" id="kpiGrossSales">GHS 0.00</h2>
                        <small class="text-muted">Selected range</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Orders</p>
                        <h2 class="kpi-value" id="kpiOrders">0</h2>
                        <small class="text-muted">Selected range</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Average Order Value</p>
                        <h2 class="kpi-value" id="kpiAov">GHS 0.00</h2>
                        <small class="text-muted">Selected range</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Sales Today</p>
                        <h2 class="kpi-value" id="kpiSalesToday">GHS 0.00</h2>
                        <small id="kpiOrdersToday" class="text-muted">0 orders today</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Client Messages</p>
                        <h2 class="kpi-value" id="kpiMessages">0</h2>
                        <small id="kpiUnreadMessages" class="text-muted">0 new</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Products</p>
                        <h2 class="kpi-value" id="kpiProducts">0</h2>
                        <small class="text-muted">In catalog</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Units In Stock</p>
                        <h2 class="kpi-value" id="kpiUnits">0</h2>
                        <small class="text-muted">All products</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Low Stock Products</p>
                        <h2 class="kpi-value text-danger" id="kpiLowStock">0</h2>
                        <small class="text-muted">Needs attention</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body">
                        <p class="kpi-label">Range</p>
                        <h2 class="kpi-value fs-6 mt-1" id="kpiRange">-</h2>
                        <small class="text-muted">Reporting period</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card section-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-wave-square me-2"></i>Sales Trend</span>
                        <span class="badge bg-light text-dark mini-pill">Daily Gross Sales</span>
                    </div>
                    <div class="card-body">
                        <canvas id="salesTrendChart" height="110"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card section-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-chart-pie me-2"></i>Sales by Category</span>
                    </div>
                    <div class="card-body">
                        <canvas id="categoryChart" height="230"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card section-card h-100">
                    <div class="card-header">
                        <i class="fas fa-crown me-2"></i>Top Products
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Units</th>
                                        <th>Sales</th>
                                    </tr>
                                </thead>
                                <tbody id="topProductsRows">
                                    <tr><td colspan="3" class="text-muted">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card section-card h-100">
                    <div class="card-header">
                        <i class="fas fa-triangle-exclamation me-2"></i>Low Stock Watchlist
                    </div>
                    <div class="card-body" id="lowStockList">
                        <p class="text-muted mb-0">Loading...</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card section-card mt-4" id="clientMessagesSection">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-receipt me-2"></i>Recent Sales</span>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">Open Sales History</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody id="recentSalesRows">
                            <tr><td colspan="6" class="text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card section-card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-envelope-open-text me-2"></i>Client Messages</span>
                <button class="btn btn-sm btn-outline-primary" id="refreshMessagesBtn">Refresh Messages</button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="contactMessagesRows">
                            <tr><td colspan="6" class="text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="contactMessageModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Client Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="messageId">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" id="messageName" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="text" id="messageEmail" class="form-control" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject</label>
                            <input type="text" id="messageSubject" class="form-control" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Message</label>
                            <textarea id="messageBody" class="form-control" rows="5" readonly></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select id="messageStatus" class="form-select">
                                <option value="new">New</option>
                                <option value="read">Read</option>
                                <option value="replied">Replied</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reply Notes</label>
                            <textarea id="messageReply" class="form-control" rows="4" placeholder="Write your response notes here..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a id="replyByEmailBtn" href="#" class="btn btn-outline-primary" target="_blank" rel="noopener">
                        <i class="fas fa-reply"></i> Reply by Email
                    </a>
                    <button type="button" class="btn btn-outline-secondary" id="markReadBtn">Mark as Read</button>
                    <button type="button" class="btn btn-outline-danger" id="deleteMessageBtn">Delete</button>
                    <button type="button" class="btn btn-primary" id="saveMessageBtn">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="../../js/admin-notifications.js"></script>
    <script>
        let salesTrendChart;
        let categoryChart;
        let contactMessageModal;

        function formatMoney(value) {
            const amount = Number(value || 0);
            return 'GHS ' + amount.toFixed(2);
        }

        function esc(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatDateLabel(value) {
            const d = new Date(value + 'T00:00:00');
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }

        function defaultDateRange() {
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - 29);
            const toInput = (date) => date.toISOString().slice(0, 10);
            return { from: toInput(start), to: toInput(end) };
        }

        function renderKpis(payload) {
            const k = payload.kpis;
            document.getElementById('kpiGrossSales').textContent = formatMoney(k.gross_sales);
            document.getElementById('kpiOrders').textContent = String(k.orders_count || 0);
            document.getElementById('kpiAov').textContent = formatMoney(k.avg_order_value);
            document.getElementById('kpiSalesToday').textContent = formatMoney(k.sales_today);
            document.getElementById('kpiOrdersToday').textContent = `${k.orders_today || 0} orders today`;
            document.getElementById('kpiProducts').textContent = String(k.products_count || 0);
            document.getElementById('kpiUnits').textContent = String(k.units_in_stock || 0);
            document.getElementById('kpiLowStock').textContent = String(k.low_stock_count || 0);
            document.getElementById('kpiRange').textContent = `${payload.range.from} to ${payload.range.to}`;
            const newMessages = Number((payload.contact_counts || {}).new || 0);
            const totalMessages = ['new', 'read', 'replied', 'closed']
                .reduce((acc, key) => acc + Number((payload.contact_counts || {})[key] || 0), 0);
            document.getElementById('kpiMessages').textContent = String(totalMessages);
            document.getElementById('kpiUnreadMessages').textContent = `${newMessages} new`;
        }

        function renderSalesTrend(dailySales) {
            const labels = dailySales.map((row) => formatDateLabel(row.sales_date));
            const values = dailySales.map((row) => Number(row.gross_sales || 0));

            if (salesTrendChart) {
                salesTrendChart.destroy();
            }

            const ctx = document.getElementById('salesTrendChart').getContext('2d');
            salesTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Gross Sales',
                        data: values,
                        fill: true,
                        tension: 0.35,
                        borderColor: '#145da0',
                        backgroundColor: 'rgba(46, 139, 192, 0.18)',
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: (v) => 'GHS ' + Number(v).toFixed(0)
                            }
                        }
                    }
                }
            });
        }

        function renderCategoryChart(rows) {
            const labels = rows.map((row) => row.category || 'Uncategorized');
            const values = rows.map((row) => Number(row.gross_sales || 0));
            const palette = ['#145da0', '#2e8bc0', '#0c2d48', '#6aa9d6', '#87b8df', '#b5d5ef', '#d0e7f8'];

            if (categoryChart) {
                categoryChart.destroy();
            }

            const ctx = document.getElementById('categoryChart').getContext('2d');
            categoryChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: palette.slice(0, Math.max(values.length, 1))
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        function renderTopProducts(rows) {
            const body = document.getElementById('topProductsRows');
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="text-muted">No product sales in selected range.</td></tr>';
                return;
            }

            body.innerHTML = rows.map((row) => `
                <tr>
                    <td>
                        <strong>${esc(row.product_name || 'Unknown Product')}</strong><br>
                        <small class="text-muted">${esc(row.category || '-')}</small>
                    </td>
                    <td>${Number(row.units_sold || 0)}</td>
                    <td>${formatMoney(row.gross_sales)}</td>
                </tr>
            `).join('');
        }

        function renderLowStock(rows) {
            const container = document.getElementById('lowStockList');
            if (!rows.length) {
                container.innerHTML = '<p class="text-success mb-0">No low stock products for the selected threshold.</p>';
                return;
            }

            container.innerHTML = rows.map((row) => `
                <div class="stock-alert">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${esc(row.name)}</strong><br>
                            <small class="text-muted">${esc(row.category || '-')}</small>
                        </div>
                        <span class="badge bg-danger rounded-pill">${Number(row.stock)} left</span>
                    </div>
                </div>
            `).join('');
        }

        function renderRecentSales(rows) {
            const body = document.getElementById('recentSalesRows');
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-muted">No sales available.</td></tr>';
                return;
            }

            body.innerHTML = rows.map((row) => `
                <tr>
                    <td>#${row.id}</td>
                    <td>${esc(row.customer_name)}</td>
                    <td>${Number(row.item_count || 0)}</td>
                    <td>${formatMoney(row.total)}</td>
                    <td><span class="badge ${row.status === 'paid' ? 'bg-success' : 'bg-secondary'}">${esc(row.status)}</span></td>
                    <td>${esc(row.created_at)}</td>
                </tr>
            `).join('');
        }

        function renderContactMessages(payload) {
            const body = document.getElementById('contactMessagesRows');
            if (!payload.has_contact_messages_table) {
                body.innerHTML = '<tr><td colspan="6" class="text-muted">No client messages yet.</td></tr>';
                return;
            }

            const rows = payload.contact_messages || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-muted">No client messages available.</td></tr>';
                return;
            }

            body.innerHTML = rows.map((row) => `
                <tr>
                    <td>${esc(row.name)}</td>
                    <td>${esc(row.email)}</td>
                    <td>${esc(row.subject)}</td>
                    <td><span class="badge ${row.status === 'new' ? 'bg-warning text-dark' : row.status === 'replied' ? 'bg-success' : 'bg-secondary'}">${esc(row.status)}</span></td>
                    <td>${esc(row.created_at)}</td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button class="btn btn-sm btn-outline-primary" onclick="openMessage(${Number(row.id || 0)})">View</button>
                            ${row.status !== 'read'
                                ? `<button class="btn btn-sm btn-outline-secondary" onclick="markMessageRead(${Number(row.id || 0)})">Read</button>`
                                : ''}
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteMessage(${Number(row.id || 0)})">Delete</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        async function openMessage(messageId) {
            try {
                const response = await fetch(`../../php/contact-messages.php?id=${messageId}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load message');
                }

                const msg = data.message_item;
                document.getElementById('messageId').value = msg.id;
                document.getElementById('messageName').value = msg.name || '';
                document.getElementById('messageEmail').value = msg.email || '';
                document.getElementById('messageSubject').value = msg.subject || '';
                document.getElementById('messageBody').value = msg.message || '';
                document.getElementById('messageStatus').value = msg.status || 'new';
                document.getElementById('messageReply').value = msg.admin_reply || '';
                document.getElementById('replyByEmailBtn').href = `mailto:${encodeURIComponent(msg.email)}?subject=${encodeURIComponent('Re: ' + (msg.subject || 'Your message to Mother Care'))}`;

                contactMessageModal.show();
            } catch (error) {
                alert(error.message);
            }
        }

        async function saveMessage() {
            const id = Number(document.getElementById('messageId').value);
            const status = document.getElementById('messageStatus').value;
            const adminReply = document.getElementById('messageReply').value.trim();
            const saveBtn = document.getElementById('saveMessageBtn');
            const originalLabel = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const response = await fetch('../../php/contact-messages.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: id,
                        status: status,
                        admin_reply: adminReply
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update message');
                }

                contactMessageModal.hide();
                await loadDashboard();
            } catch (error) {
                alert(error.message);
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = originalLabel;
            }
        }

        async function markMessageRead(messageId) {
            try {
                const response = await fetch('../../php/contact-messages.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: Number(messageId),
                        status: 'read'
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to mark message as read');
                }
                await loadDashboard();
            } catch (error) {
                alert(error.message);
            }
        }

        async function deleteMessage(messageId) {
            const ok = confirm('Delete this message? This cannot be undone.');
            if (!ok) return;

            try {
                const response = await fetch('../../php/contact-messages.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: Number(messageId)
                    })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to delete message');
                }

                const currentId = Number(document.getElementById('messageId').value || 0);
                if (currentId === Number(messageId)) {
                    contactMessageModal.hide();
                }
                await loadDashboard();
            } catch (error) {
                alert(error.message);
            }
        }

        async function loadDashboard() {
            const from = document.getElementById('fromDate').value;
            const to = document.getElementById('toDate').value;
            const lowStock = document.getElementById('lowStockThreshold').value;
            const url = `../../php/dashboard-data.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&low_stock=${encodeURIComponent(lowStock)}`;

            const response = await fetch(url);
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Failed to load dashboard data');
            }

            renderKpis(data);
            renderSalesTrend(data.daily_sales || []);
            renderCategoryChart(data.category_sales || []);
            renderTopProducts(data.top_products || []);
            renderLowStock(data.low_stock_products || []);
            renderRecentSales(data.recent_sales || []);
            renderContactMessages(data);
        }

        function setDefaultFilters() {
            const range = defaultDateRange();
            document.getElementById('fromDate').value = range.from;
            document.getElementById('toDate').value = range.to;
            document.getElementById('lowStockThreshold').value = 5;
        }

        document.getElementById('applyFilterBtn').addEventListener('click', () => {
            loadDashboard().catch((error) => alert(error.message));
        });

        document.getElementById('resetFilterBtn').addEventListener('click', () => {
            setDefaultFilters();
            loadDashboard().catch((error) => alert(error.message));
        });
        document.getElementById('refreshMessagesBtn').addEventListener('click', () => {
            loadDashboard().catch((error) => alert(error.message));
        });
        document.getElementById('saveMessageBtn').addEventListener('click', () => {
            saveMessage().catch((error) => alert(error.message));
        });
        document.getElementById('markReadBtn').addEventListener('click', () => {
            const id = Number(document.getElementById('messageId').value || 0);
            if (!id) return;
            markMessageRead(id).catch((error) => alert(error.message));
        });
        document.getElementById('deleteMessageBtn').addEventListener('click', () => {
            const id = Number(document.getElementById('messageId').value || 0);
            if (!id) return;
            deleteMessage(id).catch((error) => alert(error.message));
        });

        contactMessageModal = new bootstrap.Modal(document.getElementById('contactMessageModal'));
        setDefaultFilters();
        loadDashboard().catch((error) => alert(error.message));
    </script>
</body>
</html>








