const TENANT_STORAGE_KEY = 'tenant_code';

function sanitizeTenantCode(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9-]/g, '')
        .substring(0, 64);
}

function detectTenantCodeFromPath(pathname) {
    const segments = String(pathname || '')
        .split('/')
        .map((part) => part.trim())
        .filter(Boolean);

    for (let i = 0; i < segments.length - 1; i += 1) {
        if (segments[i].toLowerCase() !== 'b') continue;
        const candidate = sanitizeTenantCode(segments[i + 1]);
        if (candidate) return candidate;
    }

    return '';
}

function detectTenantCode() {
    try {
        const params = new URLSearchParams(window.location.search || '');
        const fromUrl = sanitizeTenantCode(params.get('tenant') || params.get('business_code') || '');
        if (fromUrl) {
            localStorage.setItem(TENANT_STORAGE_KEY, fromUrl);
            return fromUrl;
        }
    } catch (error) {
        // Ignore URL parsing/storage issues.
    }

    try {
        const fromPath = detectTenantCodeFromPath(window.location.pathname || '');
        if (fromPath) {
            localStorage.setItem(TENANT_STORAGE_KEY, fromPath);
            return fromPath;
        }
    } catch (error) {
        // Ignore pathname parsing/storage issues.
    }

    return '';
}

const activeTenantCode = detectTenantCode();
const CART_STORAGE_KEY_PREFIX = 'cart';
const LEGACY_CART_STORAGE_KEY = 'cart';

function resolveCartStorageKey(tenantCode = activeTenantCode) {
    const safeTenantCode = sanitizeTenantCode(tenantCode || '');
    if (safeTenantCode) {
        return `${CART_STORAGE_KEY_PREFIX}:${safeTenantCode}`;
    }
    return `${CART_STORAGE_KEY_PREFIX}:default`;
}

const cartStorageKey = resolveCartStorageKey();

// Initialize cart from tenant-scoped localStorage.
let cart = [];
try {
    const storedCart = localStorage.getItem(cartStorageKey);
    if (storedCart) {
        const parsedCart = JSON.parse(storedCart);
        cart = Array.isArray(parsedCart) ? parsedCart : [];
    }
    // Clean up legacy shared cart key so carts can no longer leak across tenants.
    localStorage.removeItem(LEGACY_CART_STORAGE_KEY);
} catch (error) {
    cart = [];
    try {
        localStorage.removeItem(cartStorageKey);
        localStorage.removeItem(LEGACY_CART_STORAGE_KEY);
    } catch (storageError) {
        // Ignore storage cleanup failures.
    }
}

function withTenantQuery(url) {
    if (!activeTenantCode) return url;
    if (/[?&](tenant|business_code)=/i.test(url)) return url;
    const hashIndex = url.indexOf('#');
    const base = hashIndex >= 0 ? url.substring(0, hashIndex) : url;
    const hash = hashIndex >= 0 ? url.substring(hashIndex) : '';
    return `${base}${base.includes('?') ? '&' : '?'}tenant=${encodeURIComponent(activeTenantCode)}${hash}`;
}

function appendTenantToFormData(formData) {
    if (!activeTenantCode || !formData || typeof formData.append !== 'function') return formData;
    if (!formData.has('business_code')) {
        formData.append('business_code', activeTenantCode);
    }
    return formData;
}

function withTenantPayload(payload) {
    const next = (payload && typeof payload === 'object') ? payload : {};
    if (activeTenantCode && !next.business_code) {
        next.business_code = activeTenantCode;
    }
    return next;
}

function propagateTenantToLinks() {
    if (!activeTenantCode) return;
    const links = document.querySelectorAll('a[href]');
    links.forEach((link) => {
        const href = String(link.getAttribute('href') || '');
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
            return;
        }
        if (/^https?:\/\//i.test(href) && !href.includes(window.location.host)) {
            return;
        }
        if (/^\/?b\/[a-z0-9-]+(?:\/|$)/i.test(href)) {
            return;
        }
        if (/[?&](tenant|business_code)=/i.test(href)) {
            return;
        }
        link.setAttribute('href', withTenantQuery(href));
    });
}

window.withTenantQuery = withTenantQuery;
window.appendTenantToFormData = appendTenantToFormData;
window.withTenantPayload = withTenantPayload;
window.activeTenantCode = activeTenantCode;
window.cartStorageKey = cartStorageKey;

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
        localStorage.setItem(cartStorageKey, JSON.stringify(cart));
        return true;
    } catch (error) {
        showError('Unable to save cart on this browser session.');
        return false;
    }
}

function clearCartState() {
    cart = [];
    try {
        localStorage.removeItem(cartStorageKey);
    } catch (error) {
        // Ignore storage cleanup failures.
    }
    updateCartCount();
}

window.clearCartState = clearCartState;

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
            const loginUrl = link.getAttribute('href').includes('pages/')
                ? 'pages/login.html'
                : 'login.html';
            link.href = withTenantQuery(loginUrl);
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
function renderDefaultFeaturedSolutions(container) {
    const contactHref = withTenantQuery('pages/contact.html');
    const productsHref = withTenantQuery('pages/products.html');
    const solutions = [
        {
            icon: 'fa-cash-register',
            title: 'SmartPOS Checkout Suite',
            description: 'Speed up billing with barcode-ready checkout, receipt generation, and role-based cashier access.',
            badge: 'Fast Counter Sales',
            ctaLabel: 'Request Demo',
            ctaHref: contactHref
        },
        {
            icon: 'fa-boxes-stacked',
            title: 'Inventory Control Pro',
            description: 'Monitor stock in real time, reduce stock-outs, and keep all branches aligned on product availability.',
            badge: 'Stock Accuracy',
            ctaLabel: 'View Catalog',
            ctaHref: productsHref
        },
        {
            icon: 'fa-chart-line',
            title: 'Business Insights Hub',
            description: 'Track top-selling products, cashier performance, and daily revenue trends from one dashboard.',
            badge: 'Growth Analytics',
            ctaLabel: 'Talk to Sales',
            ctaHref: contactHref
        }
    ];

    container.innerHTML = solutions.map((solution) => `
        <div class="col-md-4 mb-4">
            <div class="card h-100 product-card">
                <div class="card-body d-flex flex-column">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:56px;height:56px;background:rgba(15,118,110,0.12);color:#0f766e;">
                        <i class="fas ${solution.icon} fa-lg"></i>
                    </div>
                    <h5 class="card-title mb-2">${escapeHtml(solution.title)}</h5>
                    <p class="card-text text-muted mb-3">${escapeHtml(solution.description)}</p>
                    <div class="d-flex justify-content-between align-items-center mt-auto">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">${escapeHtml(solution.badge)}</span>
                        <a class="btn btn-primary btn-sm" href="${escapeHtml(solution.ctaHref)}">${escapeHtml(solution.ctaLabel)}</a>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

function loadFeaturedProducts() {
    const featuredProductsDiv = document.getElementById('featuredProducts');
    if (!featuredProductsDiv) return;

    const showDefaultSolutions = () => renderDefaultFeaturedSolutions(featuredProductsDiv);

    if (!activeTenantCode) {
        showDefaultSolutions();
        return;
    }

    // Render fallback cards immediately while tenant products are loading.
    showDefaultSolutions();

    fetch(withTenantQuery('php/get-products.php?limit=3&featured=1'))
        .then((response) => response.json())
        .then((data) => {
            if (data.success && data.products.length > 0) {
                const cards = data.products.map((product) => {
                    const productId = Number(product.id || 0);
                    const productNameRaw = String(product.name || '');
                    const productName = escapeHtml(productNameRaw);
                    const productPrice = Number(product.price || 0);
                    const productImage = sanitizeImageFilename(product.image || '') || 'pexels-jonathan-nenemann-12114822.jpg';
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
            }
        })
        .catch((error) => {
            console.error('Error loading products:', error);
            showDefaultSolutions();
        });
}

// Initialize cart count on page load
document.addEventListener('DOMContentLoaded', function () {
    propagateTenantToLinks();
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





