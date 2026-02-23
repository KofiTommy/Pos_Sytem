(function () {
    const DEFAULT_INFO = {
        business_name: 'Mother Care',
        business_email: 'info@mothercare.com',
        contact_number: '+233 000 000 000',
        logo_filename: ''
    };

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
            const response = await fetch(resolveApiUrl(), { cache: 'no-store' });
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
