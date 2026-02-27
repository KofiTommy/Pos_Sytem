(function () {
    const TENANT_STORAGE_KEY = 'tenant_code';
    const DEFAULT_INFO = {
        business_name: 'Mother Care',
        business_email: 'info@mothercare.com',
        contact_number: '+233 000 000 000',
        logo_filename: ''
    };

    function sanitizeTenantCode(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9-]/g, '')
            .substring(0, 64);
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
            // Ignore parsing/storage errors.
        }

        try {
            return sanitizeTenantCode(localStorage.getItem(TENANT_STORAGE_KEY) || '');
        } catch (error) {
            return '';
        }
    }

    function withTenant(url) {
        const tenantCode = detectTenantCode();
        if (!tenantCode) return url;
        if (/[?&](tenant|business_code)=/i.test(url)) return url;
        return `${url}${url.includes('?') ? '&' : '?'}tenant=${encodeURIComponent(tenantCode)}`;
    }

    function resolveApiUrl() {
        const path = window.location.pathname.toLowerCase();
        if (path.indexOf('/pages/admin/') !== -1) {
            return '../../php/business-settings.php';
        }
        if (path.indexOf('/pages/') !== -1) {
            return '../php/business-settings.php';
        }
        return 'php/business-settings.php';
    }

    function resolveLogoBase() {
        const path = window.location.pathname.toLowerCase();
        if (path.indexOf('/pages/admin/') !== -1) {
            return '../../assets/images/';
        }
        if (path.indexOf('/pages/') !== -1) {
            return '../assets/images/';
        }
        return 'assets/images/';
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
        const filename = String(value || '').trim();
        return filename.replace(/[^a-zA-Z0-9._-]/g, '');
    }

    function applyBusinessInfo(info) {
        const safeInfo = Object.assign({}, DEFAULT_INFO, info || {});

        document.querySelectorAll('[data-business-name]').forEach((el) => {
            el.textContent = safeInfo.business_name;
        });

        document.querySelectorAll('[data-business-email]').forEach((el) => {
            const email = safeInfo.business_email;
            if (el.tagName === 'A') {
                el.setAttribute('href', 'mailto:' + email);
            }
            el.textContent = email;
        });

        document.querySelectorAll('[data-business-phone]').forEach((el) => {
            el.textContent = safeInfo.contact_number;
        });

        const sanitizedLogo = sanitizeFilename(safeInfo.logo_filename);
        const logoUrl = sanitizedLogo ? (resolveLogoBase() + sanitizedLogo) : '';
        document.querySelectorAll('[data-business-logo]').forEach((el) => {
            if (!logoUrl) {
                el.classList.add('d-none');
                return;
            }

            if (el.tagName === 'IMG') {
                el.src = logoUrl;
                el.alt = safeInfo.business_name + ' logo';
            } else {
                el.innerHTML = '<img src="' + escapeHtml(logoUrl) + '" alt="' + escapeHtml(safeInfo.business_name) + ' logo">';
            }
            el.classList.remove('d-none');
        });

        window.businessInfo = safeInfo;
        window.dispatchEvent(new CustomEvent('business-info:loaded', { detail: safeInfo }));
    }

    async function loadBusinessInfo() {
        try {
            const response = await fetch(withTenant(resolveApiUrl()), { cache: 'no-store' });
            const data = await response.json();
            if (data && data.success && data.settings) {
                applyBusinessInfo(data.settings);
                return;
            }
        } catch (error) {
            console.warn('Business info fetch failed:', error);
        }
        applyBusinessInfo(DEFAULT_INFO);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadBusinessInfo);
    } else {
        loadBusinessInfo();
    }
})();
