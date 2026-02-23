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
    <title>Manage Products - Mother Care POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="fas fa-boxes"></i> Manage Products</a>
            <div class="ms-auto d-flex gap-2 admin-actions">
                <a href="dashboard.php" class="btn btn-outline-info btn-sm">Dashboard</a>
                <a href="pos.php" class="btn btn-outline-primary btn-sm">POS</a>
                <a href="business-settings.php" class="btn btn-outline-primary btn-sm">Business Info</a>
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
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Add New Product</h5>
                    </div>
                    <div class="card-body">
                        <form id="addProductForm">
                            <div class="mb-3">
                                <label for="name" class="form-label">Product Name</label>
                                <input type="text" id="name" class="form-control" maxlength="200" required>
                            </div>
                            <div class="mb-3">
                                <label for="price" class="form-label">Price</label>
                                <input type="number" id="price" class="form-control" min="0" step="0.01" required>
                            </div>
                            <div class="mb-3">
                                <label for="stock" class="form-label">Quantity (Stock)</label>
                                <input type="number" id="stock" class="form-control" min="0" step="1" required>
                            </div>
                            <div class="mb-3">
                                <label for="category" class="form-label">Category</label>
                                <input type="text" id="category" class="form-control" maxlength="100" placeholder="Optional">
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea id="description" class="form-control" rows="3" placeholder="Optional"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="image" class="form-label">Image Filename</label>
                                <input type="text" id="image" class="form-control" maxlength="255" placeholder="e.g. pexels-image.jpg">
                            </div>
                            <div class="mb-3">
                                <label for="imageFile" class="form-label">Upload Image</label>
                                <input type="file" id="imageFile" class="form-control" accept="image/*">
                                <div class="form-text">Optional. If selected, this uploaded file will be used.</div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="featured">
                                <label class="form-check-label" for="featured">Featured Product</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="saveBtn">
                                <i class="fas fa-save"></i> Save Product
                            </button>
                        </form>
                        <div id="formAlert" class="mt-3"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Product List</h5>
                        <button id="refreshBtn" class="btn btn-outline-secondary btn-sm">Refresh</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Category</th>
                                        <th>Featured</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="productsTableBody">
                                    <tr>
                                        <td colspan="7" class="text-muted text-center">Loading products...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editProductForm">
                    <div class="modal-body">
                        <input type="hidden" id="editId">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editName" class="form-label">Product Name</label>
                                <input type="text" id="editName" class="form-control" maxlength="200" required>
                            </div>
                            <div class="col-md-3">
                                <label for="editPrice" class="form-label">Price</label>
                                <input type="number" id="editPrice" class="form-control" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-3">
                                <label for="editStock" class="form-label">Quantity (Stock)</label>
                                <input type="number" id="editStock" class="form-control" min="0" step="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editCategory" class="form-label">Category</label>
                                <input type="text" id="editCategory" class="form-control" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label for="editImage" class="form-label">Image Filename</label>
                                <input type="text" id="editImage" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-6">
                                <label for="editImageFile" class="form-label">Upload New Image</label>
                                <input type="file" id="editImageFile" class="form-control" accept="image/*">
                                <div class="form-text">Optional. If selected, this uploaded file will be used.</div>
                            </div>
                            <div class="col-12">
                                <label for="editDescription" class="form-label">Description</label>
                                <textarea id="editDescription" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="editFeatured">
                                    <label class="form-check-label" for="editFeatured">Featured Product</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="updateBtn">
                            <i class="fas fa-save"></i> Update Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/admin-notifications.js"></script>
    <script>
        let products = [];
        let editModal;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showFormAlert(message, type) {
            const alert = document.getElementById('formAlert');
            alert.innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        }

        function asMoney(value) {
            return 'GHS ' + Number(value || 0).toFixed(2);
        }

        async function loadProducts() {
            const body = document.getElementById('productsTableBody');
            body.innerHTML = '<tr><td colspan="7" class="text-muted text-center">Loading products...</td></tr>';

            const response = await fetch('../../php/manage-products.php');
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to load products');
            }

            products = data.products;

            if (!products.length) {
                body.innerHTML = '<tr><td colspan="7" class="text-muted text-center">No products found.</td></tr>';
                return;
            }

            body.innerHTML = products.map((product) => `
                <tr>
                    <td>${product.id}</td>
                    <td>${escapeHtml(product.name)}</td>
                    <td>${asMoney(product.price)}</td>
                    <td>${Number(product.stock)}</td>
                    <td>${escapeHtml(product.category || '-')}</td>
                    <td>${Number(product.featured) === 1 ? 'Yes' : 'No'}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditModal(${product.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(${product.id})">Delete</button>
                    </td>
                </tr>
            `).join('');
        }

        async function createProduct(event) {
            event.preventDefault();

            const saveBtn = document.getElementById('saveBtn');
            const formData = new FormData();
            formData.append('name', document.getElementById('name').value.trim());
            formData.append('price', Number(document.getElementById('price').value));
            formData.append('stock', Number(document.getElementById('stock').value));
            formData.append('category', document.getElementById('category').value.trim());
            formData.append('description', document.getElementById('description').value.trim());
            formData.append('image', document.getElementById('image').value.trim());
            formData.append('featured', document.getElementById('featured').checked ? '1' : '0');

            const imageFile = document.getElementById('imageFile').files[0];
            if (imageFile) {
                formData.append('image_file', imageFile);
            }

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                const response = await fetch('../../php/manage-products.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Failed to create product');
                }

                document.getElementById('addProductForm').reset();
                document.getElementById('imageFile').value = '';
                showFormAlert('Product added successfully.', 'success');
                await loadProducts();
            } catch (error) {
                showFormAlert(error.message, 'danger');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Product';
            }
        }

        function openEditModal(productId) {
            const product = products.find((item) => Number(item.id) === Number(productId));
            if (!product) {
                showFormAlert('Product not found.', 'danger');
                return;
            }

            document.getElementById('editId').value = product.id;
            document.getElementById('editName').value = product.name || '';
            document.getElementById('editPrice').value = product.price;
            document.getElementById('editStock').value = product.stock;
            document.getElementById('editCategory').value = product.category || '';
            document.getElementById('editImage').value = product.image || '';
            document.getElementById('editImageFile').value = '';
            document.getElementById('editDescription').value = product.description || '';
            document.getElementById('editFeatured').checked = Number(product.featured) === 1;
            editModal.show();
        }

        async function updateProduct(event) {
            event.preventDefault();
            const updateBtn = document.getElementById('updateBtn');
            const formData = new FormData();
            formData.append('_method', 'PUT');
            formData.append('id', Number(document.getElementById('editId').value));
            formData.append('name', document.getElementById('editName').value.trim());
            formData.append('price', Number(document.getElementById('editPrice').value));
            formData.append('stock', Number(document.getElementById('editStock').value));
            formData.append('category', document.getElementById('editCategory').value.trim());
            formData.append('description', document.getElementById('editDescription').value.trim());
            formData.append('image', document.getElementById('editImage').value.trim());
            formData.append('featured', document.getElementById('editFeatured').checked ? '1' : '0');

            const imageFile = document.getElementById('editImageFile').files[0];
            if (imageFile) {
                formData.append('image_file', imageFile);
            }

            updateBtn.disabled = true;
            updateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

            try {
                const response = await fetch('../../php/manage-products.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to update product');
                }

                editModal.hide();
                showFormAlert('Product updated successfully.', 'success');
                await loadProducts();
            } catch (error) {
                showFormAlert(error.message, 'danger');
            } finally {
                updateBtn.disabled = false;
                updateBtn.innerHTML = '<i class="fas fa-save"></i> Update Product';
            }
        }

        async function deleteProduct(productId) {
            const product = products.find((item) => Number(item.id) === Number(productId));
            if (!product) {
                showFormAlert('Product not found.', 'danger');
                return;
            }

            const ok = confirm(`Delete "${product.name}"? This cannot be undone.`);
            if (!ok) return;

            try {
                const response = await fetch('../../php/manage-products.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: Number(productId) })
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Failed to delete product');
                }

                showFormAlert('Product deleted successfully.', 'success');
                await loadProducts();
            } catch (error) {
                showFormAlert(error.message, 'danger');
            }
        }

        document.getElementById('addProductForm').addEventListener('submit', createProduct);
        document.getElementById('editProductForm').addEventListener('submit', updateProduct);
        document.getElementById('refreshBtn').addEventListener('click', async () => {
            try {
                await loadProducts();
            } catch (error) {
                showFormAlert(error.message, 'danger');
            }
        });

        editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
        loadProducts().catch((error) => showFormAlert(error.message, 'danger'));
    </script>
</body>
</html>




