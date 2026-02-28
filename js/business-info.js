(function () {
    const TENANT_STORAGE_KEY = 'tenant_code';
    const DEFAULT_INFO = {
        business_name: 'CediTill',
        business_email: 'info@ceditill.com',
        contact_number: '+233 245 067 195',
        logo_filename: '',
        theme_palette: 'default',
        hero_tagline: 'Universal POS tools to manage sales, inventory, and customers with confidence.',
        footer_note: 'CediTill helps businesses run faster checkout, smarter stock control, and clear daily sales insights.'
    };

    const PALETTES = {
        default: {
            '--mc-primary': '#0f766e',
            '--mc-primary-strong': '#0b5f58',
            '--mc-accent': '#f59e0b',
            '--mc-focus-ring': 'rgba(245, 158, 11, 0.45)',
            '--mc-hero-start': '#0f766e',
            '--mc-hero-mid': '#0c5f89',
            '--mc-hero-end': '#0b3f5e',
            '--mc-cta-start': '#0f766e',
            '--mc-cta-mid': '#0b5f58',
            '--mc-cta-end': '#166534'
        },
        ocean: {
            '--mc-primary': '#1d4ed8',
            '--mc-primary-strong': '#1e40af',
            '--mc-accent': '#22d3ee',
            '--mc-focus-ring': 'rgba(34, 211, 238, 0.45)',
            '--mc-hero-start': '#1d4ed8',
            '--mc-hero-mid': '#2563eb',
            '--mc-hero-end': '#0f172a',
            '--mc-cta-start': '#1e40af',
            '--mc-cta-mid': '#1d4ed8',
            '--mc-cta-end': '#0f172a'
        },
        sunset: {
            '--mc-primary': '#ea580c',
            '--mc-primary-strong': '#c2410c',
            '--mc-accent': '#facc15',
            '--mc-focus-ring': 'rgba(250, 204, 21, 0.45)',
            '--mc-hero-start': '#ea580c',
            '--mc-hero-mid': '#f97316',
            '--mc-hero-end': '#7c2d12',
            '--mc-cta-start': '#c2410c',
            '--mc-cta-mid': '#ea580c',
            '--mc-cta-end': '#7c2d12'
        },
        forest: {
            '--mc-primary': '#15803d',
            '--mc-primary-strong': '#166534',
            '--mc-accent': '#84cc16',
            '--mc-focus-ring': 'rgba(132, 204, 22, 0.45)',
            '--mc-hero-start': '#15803d',
            '--mc-hero-mid': '#166534',
            '--mc-hero-end': '#14532d',
            '--mc-cta-start': '#166534',
            '--mc-cta-mid': '#15803d',
            '--mc-cta-end': '#14532d'
        },
        mono: {
            '--mc-primary': '#334155',
            '--mc-primary-strong': '#1f2937',
            '--mc-accent': '#94a3b8',
            '--mc-focus-ring': 'rgba(148, 163, 184, 0.45)',
            '--mc-hero-start': '#334155',
            '--mc-hero-mid': '#475569',
            '--mc-hero-end': '#0f172a',
            '--mc-cta-start': '#1f2937',
            '--mc-cta-mid': '#334155',
            '--mc-cta-end': '#0f172a'
        }
    };

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
            // Ignore parsing/storage errors.
        }

        try {
            const fromPath = detectTenantCodeFromPath(window.location.pathname || '');
            if (fromPath) {
                localStorage.setItem(TENANT_STORAGE_KEY, fromPath);
                return fromPath;
            }
        } catch (error) {
            // Ignore pathname/storage errors.
        }

        return '';
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

    function normalizeBusinessName(value) {
        const raw = String(value || '').trim();
        if (!raw) return DEFAULT_INFO.business_name;

        const cleaned = raw
            // Remove private-use glyphs (often seen when icon fonts are persisted as text)
            .replace(/^[\uE000-\uF8FF\s]+/u, '')
            // Remove legacy baby-themed leading emoji prefixes.
            .replace(/^(?:\u{1F476}|\u{1F37C}|\u{1F931}|\u{1F6BC}|\u{1F47C}|\u{1F9F8}|\s)+/u, '')
            .trim();

        return cleaned || DEFAULT_INFO.business_name;
    }

    function applyThemePalette(paletteName) {
        const key = String(paletteName || 'default').toLowerCase();
        const palette = PALETTES[key] || PALETTES.default;
        const root = document.documentElement;

        Object.keys(palette).forEach((cssVar) => {
            root.style.setProperty(cssVar, palette[cssVar]);
        });

        document.body.setAttribute('data-theme-palette', key);
    }

    function applyBusinessInfo(info) {
        const safeInfo = Object.assign({}, DEFAULT_INFO, info || {});
        safeInfo.business_name = normalizeBusinessName(safeInfo.business_name);
        safeInfo.theme_palette = String(safeInfo.theme_palette || 'default').toLowerCase();
        safeInfo.hero_tagline = String(safeInfo.hero_tagline || DEFAULT_INFO.hero_tagline).trim() || DEFAULT_INFO.hero_tagline;
        safeInfo.footer_note = String(safeInfo.footer_note || DEFAULT_INFO.footer_note).trim() || DEFAULT_INFO.footer_note;
        applyThemePalette(safeInfo.theme_palette);

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

        document.querySelectorAll('[data-business-hero-tagline]').forEach((el) => {
            el.textContent = safeInfo.hero_tagline;
        });

        document.querySelectorAll('[data-business-footer-note]').forEach((el) => {
            el.textContent = safeInfo.footer_note;
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
