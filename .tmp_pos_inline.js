
        const cart = new Map();
        let products = [];
        let lastReceiptData = null;
        let checkoutInFlight = false;
        const defaultBusinessInfo = {
            business_name: 'Mother Care',
            business_email: 'info@mothercare.com',
            contact_number: '+233 000 000 000',
            logo_filename: ''
        };

        function asMoney(value) {
            return 'GHS ' + Number(value || 0).toFixed(2);
        }

        function asPercent(value) {
            return Number(value || 0).toFixed(2);
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

        function getTotals() {
            let subtotal = 0;
            cart.forEach((item) => {
                subtotal += item.price * item.quantity;
            });
            const taxRate = Number(document.getElementById('taxRate').value || 0);
            const discountInput = Number(document.getElementById('discountAmount').value || 0);
            const discount = Math.min(Math.max(discountInput, 0), subtotal);
            const taxableSubtotal = Math.max(subtotal - discount, 0);
            const tax = taxableSubtotal * (Math.min(Math.max(taxRate, 0), 100) / 100);
            const total = taxableSubtotal + tax;
            const cash = Number(document.getElementById('cashReceived').value || 0);
            const change = cash > 0 ? cash - total : 0;
            return { subtotal, discount, taxableSubtotal, tax, total, change, taxRate };
        }

        function renderCart() {
            const cartDiv = document.getElementById('cartItems');
            if (cart.size === 0) {
                cartDiv.innerHTML = '<p class="text-muted mb-0">No items in cart.</p>';
            } else {
                let html = '<div class="list-group">';
                cart.forEach((item) => {
                    html += `
                        <div class="list-group-item py-2">
                            <div class="d-flex justify-content-between">
                                <strong>${escapeHtml(item.name)}</strong>
                                <span>${asMoney(item.price * item.quantity)}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <span class="text-muted">Qty: ${item.quantity}</span>
                                <div class="btn-group btn-group-sm pos-cart-actions">
                                    <button type="button" class="btn btn-outline-secondary" data-cart-action="decrease" data-product-id="${item.id}" aria-label="Decrease quantity">-</button>
                                    <button type="button" class="btn btn-outline-secondary" data-cart-action="increase" data-product-id="${item.id}" aria-label="Increase quantity">+</button>
                                    <button type="button" class="btn btn-outline-danger" data-cart-action="remove" data-product-id="${item.id}" aria-label="Remove item">x</button>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                cartDiv.innerHTML = html;
            }

            const totals = getTotals();
            document.getElementById('subtotal').textContent = asMoney(totals.subtotal);
            document.getElementById('discount').textContent = asMoney(totals.discount);
            document.getElementById('taxLabel').textContent = `Tax (${asPercent(totals.taxRate)}%)`;
            document.getElementById('tax').textContent = asMoney(totals.tax);
            document.getElementById('total').textContent = asMoney(totals.total);
            document.getElementById('changeDue').textContent = asMoney(totals.change > 0 ? totals.change : 0);
        }

        function renderProducts() {
            const grid = document.getElementById('productsGrid');
            if (products.length === 0) {
                grid.innerHTML = '<p class="text-muted">No products found.</p>';
                return;
            }

            grid.innerHTML = products.map((product) => {
                const productId = Number(product.id || 0);
                const safeName = escapeHtml(product.name || '');
                const safeCategory = escapeHtml(product.category || '');
                const safeImage = sanitizeFilename(product.image || '');
                const stock = Number(product.stock || 0);
                const stockClass = stock > 0 ? 'bg-success' : 'bg-danger';
                const clickableClass = stock > 0 ? 'product-card-clickable' : '';
                const clickableAttrs = stock > 0
                    ? `role="button" tabindex="0" aria-label="Add ${safeName} to sale"`
                    : '';

                return `
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 product-card ${clickableClass}" data-product-id="${productId}" ${clickableAttrs}>
                            <img src="../../assets/images/${safeImage}" class="card-img-top" alt="${safeName}">
                            <div class="card-body">
                                <h6 class="card-title mb-1">${safeName}</h6>
                                <p class="small text-muted mb-1">${safeCategory}</p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">${asMoney(product.price)}</span>
                                    <span class="badge ${stockClass}">${stock} in stock</span>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm w-100 mt-3" data-pos-add="${productId}" ${stock <= 0 ? 'disabled' : ''}>
                                    Add to Sale
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function loadProducts() {
            const query = document.getElementById('productSearch').value.trim();
            const response = await fetch(`../../php/pos-products.php?q=${encodeURIComponent(query)}`);
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Failed to load products');
            }
            products = data.products.map((p) => ({
                id: Number(p.id),
                name: p.name,
                price: Number(p.price),
                stock: Number(p.stock),
                category: p.category,
                image: p.image
            }));
            renderProducts();
        }

        function addToCart(productId) {
            const product = products.find((p) => p.id === Number(productId));
            if (!product || product.stock <= 0) return;
            const existing = cart.get(product.id);
            const currentQty = existing ? existing.quantity : 0;
            if (currentQty + 1 > product.stock) return;

            cart.set(product.id, {
                id: product.id,
                name: product.name,
                price: product.price,
                quantity: currentQty + 1
            });
            renderCart();
        }

        function changeQty(productId, delta) {
            const item = cart.get(Number(productId));
            if (!item) return;

            const product = products.find((p) => p.id === Number(productId));
            const nextQty = item.quantity + delta;
            if (nextQty <= 0) {
                cart.delete(Number(productId));
            } else if (product && nextQty <= product.stock) {
                item.quantity = nextQty;
                cart.set(Number(productId), item);
            }
            renderCart();
        }

        function removeItem(productId) {
            cart.delete(Number(productId));
            renderCart();
        }

        document.getElementById('productsGrid').addEventListener('click', (event) => {
            const addBtn = event.target.closest('button[data-pos-add]');
            if (addBtn) {
                event.preventDefault();
                const productId = Number(addBtn.getAttribute('data-pos-add'));
                if (Number.isFinite(productId)) {
                    addToCart(productId);
                }
                return;
            }
            const card = event.target.closest('.product-card[data-product-id]');
            if (!card) return;
            const productId = Number(card.getAttribute('data-product-id'));
            if (!Number.isFinite(productId)) return;
            addToCart(productId);
        });

        document.getElementById('cartItems').addEventListener('click', (event) => {
            const button = event.target.closest('button[data-cart-action]');
            if (!button) return;

            const action = String(button.getAttribute('data-cart-action') || '');
            const productId = Number(button.getAttribute('data-product-id') || 0);
            if (!Number.isFinite(productId) || productId <= 0) return;

            if (action === 'decrease') {
                changeQty(productId, -1);
                return;
            }
            if (action === 'increase') {
                changeQty(productId, 1);
                return;
            }
            if (action === 'remove') {
                removeItem(productId);
            }
        });

        document.getElementById('productsGrid').addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const card = event.target.closest('.product-card[data-product-id]');
            if (!card) return;
            event.preventDefault();
            const productId = Number(card.getAttribute('data-product-id'));
            if (!Number.isFinite(productId)) return;
            addToCart(productId);
        });

        function clearCart(clearResult = true) {
            cart.clear();
            renderCart();
            if (clearResult) {
                document.getElementById('saleResult').innerHTML = '';
            }
        }

        async function checkout() {
            if (checkoutInFlight) {
                return;
            }
            if (cart.size === 0) {
                alert('Cart is empty');
                return;
            }

            const checkoutBtn = document.getElementById('checkoutBtn');
            const clearBtn = document.getElementById('clearBtn');
            const originalCheckoutLabel = checkoutBtn.innerHTML;
            checkoutInFlight = true;
            checkoutBtn.disabled = true;
            clearBtn.disabled = true;
            checkoutBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Processing...';

            try {
                const payload = {
                    customer_name: document.getElementById('customerName').value.trim(),
                    payment_method: document.getElementById('paymentMethod').value,
                    cash_received: Number(document.getElementById('cashReceived').value || 0),
                    tax_rate: Number(document.getElementById('taxRate').value || 0),
                    discount_amount: Number(document.getElementById('discountAmount').value || 0),
                    items: Array.from(cart.values()).map((i) => ({ id: i.id, quantity: i.quantity }))
                };

                const response = await fetch('../../php/pos-checkout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Checkout failed');
                }

                const summary = data.summary;
                lastReceiptData = { orderId: data.order_id, summary: summary };
                document.getElementById('saleResult').innerHTML = `
                    <div class="alert alert-success border-0 shadow-sm">
                        <h5 class="mb-2">Sale Completed (Order #${data.order_id})</h5>
                        <p class="mb-1">Customer: ${escapeHtml(summary.customer_name)}</p>
                        <p class="mb-1">Payment: ${escapeHtml(summary.payment_method)}</p>
                        <p class="mb-1">Subtotal: ${asMoney(summary.subtotal)}</p>
                        <p class="mb-1">Discount: ${asMoney(summary.discount)}</p>
                        <p class="mb-1">Tax (${asPercent(summary.tax_rate)}%): ${asMoney(summary.tax)}</p>
                        <p class="mb-1"><strong>Total: ${asMoney(summary.total)}</strong></p>
                        <p class="mb-3">Change: ${asMoney(summary.change_due)}</p>
                        <button class="btn btn-sm btn-outline-success" onclick="printLatestReceipt()">Print Receipt</button>
                        <a class="btn btn-sm btn-outline-primary ms-2" href="sales.php">View in Sales History</a>
                    </div>
                `;

                clearCart(false);
                await loadProducts();
            } finally {
                checkoutInFlight = false;
                checkoutBtn.disabled = false;
                clearBtn.disabled = false;
                checkoutBtn.innerHTML = originalCheckoutLabel;
            }
        }

        function printLatestReceipt() {
            if (!lastReceiptData) {
                alert('No completed sale found to print.');
                return;
            }
            printReceipt(lastReceiptData.orderId, lastReceiptData.summary);
        }

        function printReceipt(orderId, summary) {
            const now = new Date();
            const businessInfo = getBusinessInfo();
            const safeLogo = sanitizeFilename(businessInfo.logo_filename || '');
            const logoUrl = safeLogo ? new URL(`../../assets/images/${safeLogo}`, window.location.href).href : '';
            const logoBlock = logoUrl
                ? `<p style="margin-bottom:6px;"><img src="${escapeHtml(logoUrl)}" alt="Logo" style="max-height:48px; max-width:180px;"></p>`
                : '';
            const rows = (summary.items || []).map((item) => `
                <tr>
                    <td>${escapeHtml(item.name)}</td>
                    <td style="text-align:right;">${item.quantity}</td>
                    <td style="text-align:right;">${asMoney(item.price)}</td>
                    <td style="text-align:right;">${asMoney(item.line_total)}</td>
                </tr>
            `).join('');

            const receiptHtml = `
                <!doctype html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>Receipt #${orderId}</title>
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
                            <p class="muted">POS Customer Receipt</p>
                            <p class="muted">${escapeHtml(businessInfo.contact_number)} | ${escapeHtml(businessInfo.business_email)}</p>
                            <p class="muted">Order #${orderId}</p>
                            <p class="muted">${now.toLocaleString()}</p>
                        </div>
                        <hr>
                        <p><strong>Customer:</strong> ${escapeHtml(summary.customer_name)}</p>
                        <p><strong>Payment:</strong> ${escapeHtml(summary.payment_method)}</p>
                        <table>
                            <thead>
                                <tr><th>Item</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Amt</th></tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                        <div class="totals">
                            <div><span>Subtotal</span><span>${asMoney(summary.subtotal)}</span></div>
                            <div><span>Discount</span><span>${asMoney(summary.discount)}</span></div>
                            <div><span>Tax (${asPercent(summary.tax_rate)}%)</span><span>${asMoney(summary.tax)}</span></div>
                            <div class="total"><span>Total</span><span>${asMoney(summary.total)}</span></div>
                            <div><span>Cash</span><span>${asMoney(summary.cash_received)}</span></div>
                            <div><span>Change</span><span>${asMoney(summary.change_due)}</span></div>
                        </div>
                        <p class="thanks">Thank you for shopping with us.</p>
                    </div>
                </body>
                </html>
            `;

            let printFrame = document.getElementById('receiptPrintFrame');
            if (!printFrame) {
                printFrame = document.createElement('iframe');
                printFrame.id = 'receiptPrintFrame';
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

            // Fallback for browsers that do not reliably fire iframe onload after document.write.
            setTimeout(() => {
                try {
                    printFrame.contentWindow.focus();
                    printFrame.contentWindow.print();
                } catch (error) {
                    console.error('Receipt print failed:', error);
                }
            }, 120);
        }

        document.getElementById('searchBtn').addEventListener('click', async () => {
            try {
                await loadProducts();
            } catch (error) {
                alert(error.message);
            }
        });

        document.getElementById('refreshBtn').addEventListener('click', async () => {
            document.getElementById('productSearch').value = '';
            try {
                await loadProducts();
            } catch (error) {
                alert(error.message);
            }
        });

        document.getElementById('clearBtn').addEventListener('click', clearCart);
        document.getElementById('checkoutBtn').addEventListener('click', async () => {
            try {
                await checkout();
            } catch (error) {
                alert(error.message);
            }
        });
        document.getElementById('cashReceived').addEventListener('input', renderCart);
        document.getElementById('taxRate').addEventListener('input', renderCart);
        document.getElementById('discountAmount').addEventListener('input', renderCart);
        document.getElementById('productSearch').addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                try {
                    await loadProducts();
                } catch (error) {
                    alert(error.message);
                }
            }
        });

        document.getElementById('copyShopShareUrl').addEventListener('click', async () => {
            const input = document.getElementById('shopShareUrl');
            const text = String(input.value || '').trim();
            if (!text) return;

            try {
                await navigator.clipboard.writeText(text);
            } catch (error) {
                input.focus();
                input.select();
                alert('Copy failed. On mobile, tap and hold the URL field to copy.');
            }
        });

        function setupPosMobileNav() {
            const offcanvasEl = document.getElementById('posMobileNav');
            if (!offcanvasEl || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
                return;
            }
            const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
            offcanvasEl.querySelectorAll('a[href]').forEach((link) => {
                link.addEventListener('click', () => offcanvas.hide());
            });
        }

        setupPosMobileNav();
        loadProducts().then(renderCart).catch((error) => alert(error.message));
    
