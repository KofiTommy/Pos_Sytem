// Initialize cart from localStorage (guard against invalid/stale data)
let cart = [];
try {
    const storedCart = localStorage.getItem('cart');
    if (storedCart) {
        const parsedCart = JSON.parse(storedCart);
        cart = Array.isArray(parsedCart) ? parsedCart : [];
    }
} catch (error) {
    cart = [];
    try {
        localStorage.removeItem('cart');
    } catch (storageError) {
        // Ignore storage cleanup failures.
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function sanitizeImageFilename(value) {
    return String(value ?? '').replace(/[^a-zA-Z0-9._-]/g, '');
}

function addToCartFromElement(element) {
    if (!element) return;
    const productId = Number(element.dataset.productId || 0);
    const productName = String(element.dataset.productName || '');
    const productPrice = Number(element.dataset.productPrice || 0);
    const productImage = String(element.dataset.productImage || '');
    addToCart(productId, productName, productPrice, productImage);
}

function persistCart() {
    try {
        localStorage.setItem('cart', JSON.stringify(cart));
        return true;
    } catch (error) {
        showError('Unable to save cart on this browser session.');
        return false;
    }
}

// Update cart count
function updateCartCount() {
    const cartCount = document.getElementById('cartCount');
    if (cartCount) {
        cartCount.textContent = cart.length;
    }
}

// Update admin portal link based on session state
async function updateAdminPortalLink() {
    const adminLinks = document.querySelectorAll('[data-admin-link]');
    if (!adminLinks.length) return;

    const statusPaths = ['php/session-status.php', '../php/session-status.php'];
    let auth = false;
    let role = '';

    for (const path of statusPaths) {
        try {
            const response = await fetch(path);
            if (!response.ok) continue;
            const data = await response.json();
            auth = !!data.authenticated;
            role = String(data.role || '');
            break;
        } catch (error) {
            // Try the next path candidate.
        }
    }

    adminLinks.forEach((link) => {
        if (auth) {
            const ownerHome = link.getAttribute('href').includes('pages/')
                ? 'pages/admin/dashboard.php'
                : 'admin/dashboard.php';
            const salesHome = link.getAttribute('href').includes('pages/')
                ? 'pages/admin/pos.php'
                : 'admin/pos.php';
            link.href = role === 'owner' ? ownerHome : salesHome;
            link.innerHTML = role === 'owner'
                ? '<i class="fas fa-gauge-high"></i> Owner Dashboard'
                : '<i class="fas fa-cash-register"></i> Sales POS';
        } else {
            link.href = link.getAttribute('href').includes('pages/')
                ? 'pages/login.html'
                : 'login.html';
            link.innerHTML = '<i class="fas fa-sign-in-alt"></i> Staff Login';
        }
    });
}

// Add item to cart
function addToCart(productId, productName, productPrice, productImage) {
    const normalizedId = String(productId);
    const existingItem = cart.find((item) => String(item.id) === normalizedId);

    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.push({
            id: normalizedId,
            name: String(productName || ''),
            price: Number(productPrice || 0),
            image: sanitizeImageFilename(productImage || ''),
            quantity: 1
        });
    }

    persistCart();
    updateCartCount();
    showNotification('Product added to cart successfully!');
}

// Remove item from cart
function removeFromCart(productId) {
    const normalizedId = String(productId);
    cart = cart.filter((item) => String(item.id) !== normalizedId);
    persistCart();
    updateCartCount();
}

// Update item quantity
function updateQuantity(productId, quantity) {
    const normalizedId = String(productId);
    const item = cart.find((cartItem) => String(cartItem.id) === normalizedId);
    if (item) {
        if (quantity <= 0) {
            removeFromCart(productId);
        } else {
            item.quantity = Number(quantity);
            persistCart();
        }
        updateCartCount();
    }
}

// Get cart total
function getCartTotal() {
    return cart.reduce((total, item) => total + (item.price * item.quantity), 0);
}

// Show notification
function showNotification(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.innerHTML = `
        ${escapeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alertDiv);

    setTimeout(() => {
        alertDiv.remove();
    }, 3000);
}

// Show error notification
function showError(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.innerHTML = `
        ${escapeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alertDiv);

    setTimeout(() => {
        alertDiv.remove();
    }, 3000);
}

// Load featured products on landing page
function loadFeaturedProducts() {
    const featuredProductsDiv = document.getElementById('featuredProducts');
    if (!featuredProductsDiv) return;

    fetch('php/get-products.php?limit=3&featured=1')
        .then((response) => response.json())
        .then((data) => {
            featuredProductsDiv.innerHTML = '';

            if (data.success && data.products.length > 0) {
                const cards = data.products.map((product) => {
                    const productId = Number(product.id || 0);
                    const productNameRaw = String(product.name || '');
                    const productName = escapeHtml(productNameRaw);
                    const productPrice = Number(product.price || 0);
                    const productImage = sanitizeImageFilename(product.image || '');
                    const productDescription = escapeHtml(String(product.description || '').substring(0, 60));
                    const productStock = Number(product.stock || 0);
                    const productNameAttr = escapeHtml(productNameRaw);
                    const productImageAttr = escapeHtml(productImage);

                    return `
                        <div class="col-md-4 mb-4">
                            <div class="card product-card product-card-clickable" role="button" tabindex="0" aria-label="Add ${productName} to cart" data-product-id="${productId}" data-product-name="${productNameAttr}" data-product-price="${productPrice}" data-product-image="${productImageAttr}" onclick="addToCartFromElement(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();addToCartFromElement(this);}">
                                <img src="assets/images/${productImage}" class="card-img-top" alt="${productName}" loading="lazy" decoding="async">
                                <div class="card-body">
                                    <h5 class="card-title">${productName}</h5>
                                    <p class="card-text text-muted">${productDescription}...</p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="product-price">GHS ${productPrice.toFixed(2)}</span>
                                        <span class="badge bg-success">${productStock} in stock</span>
                                    </div>
                                    <button type="button" class="btn btn-primary btn-sm w-100 mt-3" data-product-id="${productId}" data-product-name="${productNameAttr}" data-product-price="${productPrice}" data-product-image="${productImageAttr}" onclick="event.stopPropagation(); addToCartFromElement(this)">
                                        <i class="fas fa-shopping-cart"></i> Add to Cart
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                });
                featuredProductsDiv.innerHTML = cards.join('');
            } else {
                featuredProductsDiv.innerHTML = '<div class="col-12"><p class="text-center text-muted">No featured products available.</p></div>';
            }
        })
        .catch((error) => {
            console.error('Error loading products:', error);
            featuredProductsDiv.innerHTML = '<div class="col-12"><p class="text-center text-danger">Error loading products.</p></div>';
        });
}

// Initialize cart count on page load
document.addEventListener('DOMContentLoaded', function () {
    updateCartCount();
    loadFeaturedProducts();
    updateAdminPortalLink();
});

// Validate email
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Validate phone
function isValidPhone(phone) {
    const phoneRegex = /^[0-9\-\+\(\)\s]{10,}$/;
    return phoneRegex.test(phone);
}

// Format currency
function formatCurrency(amount) {
    return 'GHS ' + parseFloat(amount).toFixed(2);
}




