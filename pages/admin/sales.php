<?php
include '../../php/admin-auth.php';
require_roles_page(['owner', 'sales'], '../login.html');
$currentRole = current_user_role();
$isOwner = $currentRole === 'owner';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - Mother Care POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo $isOwner ? 'dashboard.php' : 'pos.php'; ?>"><i class="fas fa-chart-line"></i> Sales History</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="pos.php" class="btn btn-primary btn-sm">New Sale</a>
                <?php if ($isOwner): ?>
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="business-settings.php" class="btn btn-outline-primary btn-sm">Business Info</a>
                <a href="payment-settings.php" class="btn btn-outline-secondary btn-sm">Payment Settings</a>
                <a href="users.php" class="btn btn-outline-warning btn-sm">Manage Staff</a>
                <?php endif; ?>
                <a href="../products.html" class="btn btn-outline-dark btn-sm">View Storefront</a>
                <span class="badge bg-<?php echo $isOwner ? 'warning text-dark' : 'primary'; ?> align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
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
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label for="dateFilter" class="form-label">Date</label>
                    <input type="date" id="dateFilter" class="form-control">
                </div>
                <button id="loadSalesBtn" class="btn btn-primary">Load Sales</button>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Orders</p>
                        <h3 id="ordersCount" class="mb-0">0</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Gross Sales</p>
                        <h3 id="grossSales" class="mb-0">GHS 0.00</h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Staff</th>
                                <th>Payment</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salesRows">
                            <tr><td colspan="9" class="text-muted">No sales loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="saleDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sale Details</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="saleDetailBody"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editSaleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Sale</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <form id="editSaleForm">
                    <div class="modal-body">
                        <input type="hidden" id="editOrderId">
                        <div class="mb-3">
                            <label for="editCustomerName" class="form-label">Customer Name</label>
                            <input type="text" id="editCustomerName" class="form-control" maxlength="200" required>
                        </div>
                        <div class="mb-3">
                            <label for="editStatus" class="form-label">Status</label>
                            <select id="editStatus" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label for="editNotes" class="form-label">Notes</label>
                            <textarea id="editNotes" class="form-control" rows="3" placeholder="Optional"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveSaleBtn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/business-info.js"></script>
    <script src="../../js/admin-notifications.js"></script>
    <script>
        let editSaleModal;
        const canDeleteSales = <?php echo $isOwner ? 'true' : 'false'; ?>;
        const defaultBusinessInfo = {
            business_name: 'Mother Care',
            business_email: 'info@mothercare.com',
            contact_number: '+233 000 000 000',
            logo_filename: ''
        };

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

        function sanitizeFilename(value) {
            return String(value || '').replace(/[^a-zA-Z0-9._-]/g, '');
        }

        function getBusinessInfo() {
            return Object.assign({}, defaultBusinessInfo, window.businessInfo || {});
        }

        async function loadSales() {
            const date = document.getElementById('dateFilter').value;
            const response = await fetch(`../../php/pos-sales.php?date=${encodeURIComponent(date)}`);
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Failed to load sales');
            }

            document.getElementById('ordersCount').textContent = data.summary.orders_count;
            document.getElementById('grossSales').textContent = asMoney(data.summary.gross_total);

            const rows = document.getElementById('salesRows');
            if (!data.sales.length) {
                rows.innerHTML = '<tr><td colspan="9" class="text-muted">No sales found for this date.</td></tr>';
                return;
            }

            rows.innerHTML = data.sales.map((sale) => `
                <tr>
                    <td>#${sale.id}</td>
                    <td>${sale.customer_name}</td>
                    <td>${sale.item_count}</td>
                    <td>${asMoney(sale.total)}</td>
                    <td><span class="badge ${sale.status === 'paid' ? 'bg-success' : 'bg-secondary'}">${sale.status}</span></td>
                    <td><span class="badge bg-info text-dark">${sale.staff_username || 'Unassigned'}</span></td>
                    <td><span class="badge ${sale.payment_status === 'paid' ? 'bg-success' : 'bg-warning text-dark'}">${(sale.payment_method || 'cod')} / ${(sale.payment_status || 'unpaid')}</span></td>
                    <td>${sale.created_at}</td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <button class="btn btn-sm btn-outline-primary" onclick="openSale(${sale.id})">View</button>
                            ${sale.status === 'pending'
                                ? `<button class="btn btn-sm btn-success" onclick="confirmSale(${sale.id})">Confirm</button>`
                                : ''}
                            <button class="btn btn-sm btn-outline-secondary" onclick="openEditSale(${sale.id})">Edit</button>
                            ${canDeleteSales ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteSale(${sale.id})">Delete</button>` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        async function openSale(orderId) {
            const response = await fetch(`../../php/pos-sales.php?order_id=${orderId}`);
            const data = await response.json();
            if (!data.success) {
                alert(data.message || 'Failed to load sale');
                return;
            }

            const itemsHtml = data.items.map((item) => `
                <tr>
                    <td>${item.product_name || ('#' + item.product_id)}</td>
                    <td>${item.quantity}</td>
                    <td>${asMoney(item.price)}</td>
                    <td>${asMoney(item.price * item.quantity)}</td>
                </tr>
            `).join('');

            document.getElementById('saleDetailBody').innerHTML = `
                <p><strong>Order #:</strong> ${data.order.id}</p>
                <p><strong>Customer:</strong> ${data.order.customer_name}</p>
                <p><strong>Status:</strong> ${data.order.status}</p>
                <p><strong>Staff:</strong> ${data.order.staff_username || 'Unassigned'}${data.order.staff_user_id ? ' (#' + data.order.staff_user_id + ')' : ''}</p>
                <p><strong>Payment:</strong> ${data.order.payment_method || 'cod'} / ${data.order.payment_status || 'unpaid'}</p>
                <p><strong>Reference:</strong> ${data.order.payment_reference || '-'}</p>
                <p><strong>Created:</strong> ${data.order.created_at}</p>
                <table class="table table-sm">
                    <thead>
                        <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                <div class="text-end">
                    <p class="mb-1"><strong>Subtotal:</strong> ${asMoney(data.order.subtotal)}</p>
                    <p class="mb-1"><strong>Tax:</strong> ${asMoney(data.order.tax)}</p>
                    <p class="mb-0"><strong>Total:</strong> ${asMoney(data.order.total)}</p>
                </div>
                <div class="mt-3 text-end">
                    <button class="btn btn-sm btn-outline-success" onclick="printSaleReceipt(${data.order.id})">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('saleDetailModal'));
            modal.show();
        }

        async function printSaleReceipt(orderId) {
            try {
                const response = await fetch(`../../php/pos-sales.php?order_id=${orderId}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load sale');
                }
                const businessInfo = getBusinessInfo();
                const safeLogo = sanitizeFilename(businessInfo.logo_filename || '');
                const logoUrl = safeLogo ? new URL(`../../assets/images/${safeLogo}`, window.location.href).href : '';
                const logoBlock = logoUrl
                    ? `<p style="margin-bottom:6px;"><img src="${escapeHtml(logoUrl)}" alt="Logo" style="max-height:48px; max-width:180px;"></p>`
                    : '';

                const rows = data.items.map((item) => `
                    <tr>
                        <td>${escapeHtml(item.product_name || ('#' + item.product_id))}</td>
                        <td style="text-align:right;">${item.quantity}</td>
                        <td style="text-align:right;">${asMoney(item.price)}</td>
                        <td style="text-align:right;">${asMoney(item.price * item.quantity)}</td>
                    </tr>
                `).join('');

                const receiptHtml = `
                    <!doctype html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <title>Receipt #${data.order.id}</title>
                        <style>
                            body { font-family: 'Courier New', monospace; margin: 0; padding: 16px; color: #111; }
                            .receipt { max-width: 360px; margin: 0 auto; border: 1px dashed #999; padding: 14px; }
                            h2, p { margin: 0; }
                            .center { text-align: center; }
                            .muted { color: #555; font-size: 12px; }
                            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                            th, td { font-size: 12px; padding: 4px 0; border-bottom: 1px dotted #ddd; }
                            .totals div { display: flex; justify-content: space-between; font-size: 13px; margin: 2px 0; }
                            .total { font-weight: 700; font-size: 16px; border-top: 1px solid #111; padding-top: 6px; margin-top: 6px; }
                            .thanks { margin-top: 10px; text-align: center; font-size: 12px; }
                            @media print { body { padding: 0; } .receipt { border: none; width: 100%; max-width: none; } }
                        </style>
                    </head>
                    <body>
                        <div class="receipt">
                            <div class="center">
                                ${logoBlock}
                                <h2>${escapeHtml(businessInfo.business_name)}</h2>
                                <p class="muted">Customer Receipt</p>
                                <p class="muted">${escapeHtml(businessInfo.contact_number)} | ${escapeHtml(businessInfo.business_email)}</p>
                                <p class="muted">Order #${data.order.id}</p>
                                <p class="muted">${escapeHtml(data.order.created_at)}</p>
                            </div>
                            <hr>
                            <p><strong>Customer:</strong> ${escapeHtml(data.order.customer_name)}</p>
                            <p><strong>Status:</strong> ${escapeHtml(data.order.status)}</p>
                            <table>
                                <thead>
                                    <tr><th>Item</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Amt</th></tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                            <div class="totals">
                                <div><span>Subtotal</span><span>${asMoney(data.order.subtotal)}</span></div>
                                <div><span>Tax</span><span>${asMoney(data.order.tax)}</span></div>
                                <div class="total"><span>Total</span><span>${asMoney(data.order.total)}</span></div>
                            </div>
                            <p class="thanks">Thank you for shopping with us.</p>
                        </div>
                    </body>
                    </html>
                `;

                let printFrame = document.getElementById('salesReceiptPrintFrame');
                if (!printFrame) {
                    printFrame = document.createElement('iframe');
                    printFrame.id = 'salesReceiptPrintFrame';
                    printFrame.style.position = 'fixed';
                    printFrame.style.right = '0';
                    printFrame.style.bottom = '0';
                    printFrame.style.width = '0';
                    printFrame.style.height = '0';
                    printFrame.style.border = '0';
                    document.body.appendChild(printFrame);
                }

                printFrame.onload = function () {
                    try {
                        printFrame.contentWindow.focus();
                        printFrame.contentWindow.print();
                    } finally {
                        printFrame.onload = null;
                    }
                };

                const frameDoc = printFrame.contentWindow.document;
                frameDoc.open();
                frameDoc.write(receiptHtml);
                frameDoc.close();
            } catch (error) {
                alert(error.message || 'Failed to print receipt');
            }
        }

        async function openEditSale(orderId) {
            const response = await fetch(`../../php/pos-sales.php?order_id=${orderId}`);
            const data = await response.json();
            if (!data.success) {
                alert(data.message || 'Failed to load sale');
                return;
            }

            document.getElementById('editOrderId').value = data.order.id;
            document.getElementById('editCustomerName').value = data.order.customer_name || '';
            document.getElementById('editStatus').value = data.order.status || 'paid';
            document.getElementById('editNotes').value = data.order.notes || '';
            editSaleModal.show();
        }

        async function saveSaleEdits(event) {
            event.preventDefault();
            const saveBtn = document.getElementById('saveSaleBtn');
            const payload = {
                order_id: Number(document.getElementById('editOrderId').value),
                customer_name: document.getElementById('editCustomerName').value.trim(),
                status: document.getElementById('editStatus').value,
                notes: document.getElementById('editNotes').value.trim()
            };

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const response = await fetch('../../php/pos-sales.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update sale');
                }

                editSaleModal.hide();
                await loadSales();
            } catch (error) {
                alert(error.message);
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Changes';
            }
        }

        async function deleteSale(orderId) {
            if (!canDeleteSales) {
                alert('Only owner can delete sales records.');
                return;
            }
            const ok = confirm(`Delete sale #${orderId}? Stock will be restored automatically.`);
            if (!ok) return;

            try {
                const response = await fetch('../../php/pos-sales.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: Number(orderId) })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to delete sale');
                }
                await loadSales();
            } catch (error) {
                alert(error.message);
            }
        }

        async function confirmSale(orderId) {
            const ok = confirm(`Confirm order #${orderId} as paid?`);
            if (!ok) return;

            try {
                const detailsResponse = await fetch(`../../php/pos-sales.php?order_id=${orderId}`);
                const detailsData = await detailsResponse.json();
                if (!detailsData.success) {
                    throw new Error(detailsData.message || 'Failed to load order details');
                }

                const payload = {
                    order_id: Number(orderId),
                    customer_name: detailsData.order.customer_name || 'Walk-in Customer',
                    status: 'paid',
                    notes: detailsData.order.notes || ''
                };

                const updateResponse = await fetch('../../php/pos-sales.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const updateData = await updateResponse.json();
                if (!updateData.success) {
                    throw new Error(updateData.message || 'Failed to confirm order');
                }

                await loadSales();
            } catch (error) {
                alert(error.message);
            }
        }

        document.getElementById('loadSalesBtn').addEventListener('click', () => {
            loadSales().catch((error) => alert(error.message));
        });
        document.getElementById('editSaleForm').addEventListener('submit', saveSaleEdits);

        editSaleModal = new bootstrap.Modal(document.getElementById('editSaleModal'));
        document.getElementById('dateFilter').value = new Date().toISOString().slice(0, 10);
        loadSales().catch((error) => alert(error.message));
    </script>
</body>
</html>





