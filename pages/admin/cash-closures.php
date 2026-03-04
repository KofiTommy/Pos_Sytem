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
    <title>Cash Closures - CediTill POS</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/images/ceditill-favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-cash-register"></i> Cash Closures</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-primary btn-sm">New Sale (POS)</a>
                <a href="sales.php" class="btn btn-outline-secondary btn-sm">Sales History</a>
                <a href="manage-products.php" class="btn btn-outline-success btn-sm">Manage Products</a>
                <a href="audit-trail.php" class="btn btn-outline-dark btn-sm">Audit Trail</a>
                <a href="operations-alerts.php" class="btn btn-outline-danger btn-sm">Ops Alerts</a>
                <span class="badge bg-warning text-dark align-self-center text-uppercase"><?php echo htmlspecialchars($currentRole); ?></span>
                <a href="../../php/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div id="alertHost"></div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Create or Update Cash Closure</div>
            <div class="card-body">
                <form id="closureForm" class="row g-3">
                    <div class="col-md-3">
                        <label for="closureDate" class="form-label">Closure Date</label>
                        <input type="date" id="closureDate" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label for="shiftLabel" class="form-label">Shift Label</label>
                        <input type="text" id="shiftLabel" class="form-control" maxlength="60" value="daily">
                    </div>
                    <div class="col-md-3">
                        <label for="expectedCash" class="form-label">Expected Cash</label>
                        <input type="number" id="expectedCash" class="form-control" step="0.01" min="-99999999" max="99999999" placeholder="Auto if blank">
                    </div>
                    <div class="col-md-3">
                        <label for="countedCash" class="form-label">Counted Cash</label>
                        <input type="number" id="countedCash" class="form-control" step="0.01" min="-99999999" max="99999999" required>
                    </div>
                    <div class="col-12">
                        <label for="closureNotes" class="form-label">Notes</label>
                        <textarea id="closureNotes" class="form-control" rows="2" maxlength="500" placeholder="Optional notes"></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" id="saveClosureBtn" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Closure
                        </button>
                        <button type="button" id="reloadBtn" class="btn btn-outline-secondary">Reload</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Closures</p>
                        <h4 class="mb-0" id="kpiClosures">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Expected Total</p>
                        <h4 class="mb-0" id="kpiExpected">GHS 0.00</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Counted Total</p>
                        <h4 class="mb-0" id="kpiCounted">GHS 0.00</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-1">Variance Total</p>
                        <h4 class="mb-0" id="kpiVariance">GHS 0.00</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="fromDate" class="form-label mb-1">From</label>
                        <input type="date" id="fromDate" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="toDate" class="form-label mb-1">To</label>
                        <input type="date" id="toDate" class="form-control">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button id="applyRangeBtn" class="btn btn-outline-primary w-100">Apply Range</button>
                        <button id="resetRangeBtn" class="btn btn-outline-secondary">Reset</button>
                    </div>
                </div>
            </div>
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
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody id="closuresRows">
                            <tr><td colspan="8" class="text-muted">No records loaded.</td></tr>
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

        function defaultRange() {
            const today = new Date();
            const from = new Date(today);
            from.setDate(today.getDate() - 13);
            document.getElementById('fromDate').value = from.toISOString().split('T')[0];
            document.getElementById('toDate').value = today.toISOString().split('T')[0];
            document.getElementById('closureDate').value = today.toISOString().split('T')[0];
        }

        function renderSummary(summary) {
            document.getElementById('kpiClosures').textContent = Number(summary.closures_count || 0);
            document.getElementById('kpiExpected').textContent = asMoney(summary.expected_total);
            document.getElementById('kpiCounted').textContent = asMoney(summary.counted_total);
            document.getElementById('kpiVariance').textContent = asMoney(summary.variance_total);
        }

        function renderRows(closures) {
            const rows = document.getElementById('closuresRows');
            if (!closures || closures.length === 0) {
                rows.innerHTML = '<tr><td colspan="8" class="text-muted">No closures found for this range.</td></tr>';
                return;
            }

            rows.innerHTML = closures.map((closure) => {
                const variance = Number(closure.variance || 0);
                const varianceClass = variance < 0 ? 'text-danger' : (variance > 0 ? 'text-success' : '');
                return `
                    <tr>
                        <td>${escapeHtml(closure.closure_date)}</td>
                        <td>${escapeHtml(closure.shift_label)}</td>
                        <td>${asMoney(closure.expected_cash)}</td>
                        <td>${asMoney(closure.counted_cash)}</td>
                        <td class="${varianceClass}">${asMoney(closure.variance)}</td>
                        <td>${escapeHtml(closure.closed_by_username || '-')}</td>
                        <td>${escapeHtml(closure.updated_at || closure.created_at || '-')}</td>
                        <td>${escapeHtml(closure.notes || '')}</td>
                    </tr>
                `;
            }).join('');
        }

        async function loadClosures() {
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
                const params = new URLSearchParams({ from, to });
                const response = await fetch(`../../php/cash-closures.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load cash closures.');
                }

                renderSummary(data.summary || {});
                renderRows(data.closures || []);

                const suggested = (data.suggested_today || {}).expected_cash;
                if (document.getElementById('expectedCash').value.trim() === '' && Number.isFinite(Number(suggested))) {
                    document.getElementById('expectedCash').placeholder = `Auto (today: ${asMoney(suggested)})`;
                }
            } catch (error) {
                showAlert(error.message || 'Failed to load cash closures.');
            }
        }

        async function saveClosure(event) {
            event.preventDefault();
            showAlert('');
            const saveBtn = document.getElementById('saveClosureBtn');
            saveBtn.disabled = true;

            try {
                const payload = {
                    closure_date: document.getElementById('closureDate').value,
                    shift_label: document.getElementById('shiftLabel').value.trim(),
                    expected_cash: document.getElementById('expectedCash').value.trim(),
                    counted_cash: document.getElementById('countedCash').value.trim(),
                    notes: document.getElementById('closureNotes').value.trim()
                };

                const response = await fetch('../../php/cash-closures.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to save cash closure.');
                }

                showAlert('Cash closure saved successfully.', 'success');
                await loadClosures();
            } catch (error) {
                showAlert(error.message || 'Failed to save cash closure.');
            } finally {
                saveBtn.disabled = false;
            }
        }

        document.getElementById('closureForm').addEventListener('submit', saveClosure);
        document.getElementById('applyRangeBtn').addEventListener('click', loadClosures);
        document.getElementById('reloadBtn').addEventListener('click', loadClosures);
        document.getElementById('resetRangeBtn').addEventListener('click', () => {
            defaultRange();
            loadClosures();
        });

        defaultRange();
        loadClosures();
    </script>
</body>
</html>
