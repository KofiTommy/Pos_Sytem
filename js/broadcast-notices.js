(function () {
    const STORAGE_PREFIX = 'hq_notice_dismissed_';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function currentPath() {
        return String(window.location.pathname || '').toLowerCase();
    }

    function resolveAudience() {
        const path = currentPath();
        if (path.indexOf('/pages/admin/') !== -1) {
            return 'owners';
        }
        return 'customers';
    }

    function resolveApiUrl() {
        const path = currentPath();
        if (path.indexOf('/pages/admin/') !== -1) {
            return '../../php/broadcast-notices.php';
        }
        if (path.indexOf('/pages/') !== -1) {
            return '../php/broadcast-notices.php';
        }
        return 'php/broadcast-notices.php';
    }

    function sanitizeTenantCode(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9-]/g, '')
            .substring(0, 64);
    }

    function detectTenantCodeFallback() {
        try {
            const params = new URLSearchParams(window.location.search || '');
            const fromQuery = sanitizeTenantCode(params.get('tenant') || params.get('business_code') || '');
            if (fromQuery) {
                return fromQuery;
            }
        } catch (error) {
            // Ignore URL parsing issues.
        }

        const segments = String(window.location.pathname || '')
            .split('/')
            .map((part) => part.trim())
            .filter(Boolean);
        for (let i = 0; i < segments.length - 1; i += 1) {
            if (segments[i].toLowerCase() !== 'b') continue;
            const fromPath = sanitizeTenantCode(segments[i + 1]);
            if (fromPath) {
                return fromPath;
            }
        }

        try {
            return sanitizeTenantCode(localStorage.getItem('tenant_code') || '');
        } catch (error) {
            return '';
        }
    }

    function withTenantIfNeeded(url, audience) {
        if (audience !== 'customers') {
            return url;
        }
        if (typeof window.withTenantQuery === 'function') {
            return window.withTenantQuery(url);
        }

        const tenantCode = detectTenantCodeFallback();
        if (!tenantCode) {
            return url;
        }
        if (/[?&](tenant|business_code)=/i.test(url)) {
            return url;
        }
        return `${url}${url.indexOf('?') === -1 ? '?' : '&'}tenant=${encodeURIComponent(tenantCode)}`;
    }

    function storageKey(id) {
        return STORAGE_PREFIX + String(Number(id || 0));
    }

    function isDismissed(id) {
        const key = storageKey(id);
        try {
            return localStorage.getItem(key) === '1';
        } catch (error) {
            return false;
        }
    }

    function markDismissed(id) {
        const key = storageKey(id);
        try {
            localStorage.setItem(key, '1');
        } catch (error) {
            // Ignore localStorage failures.
        }
    }

    function humanTimestamp(value) {
        const raw = String(value || '').trim();
        if (!raw) {
            return '';
        }
        const candidate = raw.replace(' ', 'T');
        const parsed = new Date(candidate);
        if (!Number.isNaN(parsed.getTime())) {
            return parsed.toLocaleString();
        }
        return raw;
    }

    function ensureHost() {
        let host = document.getElementById('broadcastNoticeHost');
        if (host) {
            return host;
        }

        host = document.createElement('div');
        host.id = 'broadcastNoticeHost';
        host.className = 'container mt-3';

        const nav = document.querySelector('nav.navbar');
        if (nav && nav.parentNode) {
            nav.insertAdjacentElement('afterend', host);
            return host;
        }

        const main = document.querySelector('main');
        if (main && main.parentNode) {
            main.insertAdjacentElement('beforebegin', host);
            return host;
        }

        document.body.insertBefore(host, document.body.firstChild);
        return host;
    }

    function renderNotices(notices, audience) {
        const rows = Array.isArray(notices) ? notices : [];
        const visibleRows = rows.filter((row) => !isDismissed(row.id));
        if (visibleRows.length === 0) {
            return;
        }

        const host = ensureHost();
        const accent = audience === 'owners' ? 'warning' : 'info';

        host.innerHTML = visibleRows.map((row) => {
            const id = Number(row.id || 0);
            const subject = escapeHtml(row.subject || 'Update');
            const messageText = String(row.message || '').trim();
            const safeMessage = escapeHtml(messageText).replace(/\n/g, '<br>');
            const createdAt = humanTimestamp(row.created_at || '');
            const createdLabel = createdAt ? `<div class="small text-muted mt-1">Posted: ${escapeHtml(createdAt)}</div>` : '';

            return `
                <div class="alert alert-${accent} border shadow-sm d-flex justify-content-between align-items-start gap-3 mb-2" data-notice-id="${id}">
                    <div>
                        <div class="fw-semibold">${subject}</div>
                        <div class="small mb-0">${safeMessage}</div>
                        ${createdLabel}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-dismiss-notice="${id}">Dismiss</button>
                </div>
            `;
        }).join('');

        host.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-dismiss-notice]');
            if (!button) {
                return;
            }
            const id = Number(button.getAttribute('data-dismiss-notice') || 0);
            if (!Number.isFinite(id) || id <= 0) {
                return;
            }
            markDismissed(id);
            const block = button.closest('[data-notice-id]');
            if (block) {
                block.remove();
            }
            if (host.children.length === 0) {
                host.innerHTML = '';
            }
        });
    }

    async function loadNotices() {
        const audience = resolveAudience();
        const params = new URLSearchParams({
            audience: audience,
            limit: '5'
        });
        const requestUrl = withTenantIfNeeded(`${resolveApiUrl()}?${params.toString()}`, audience);

        try {
            const response = await fetch(requestUrl, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data || !data.success) {
                return;
            }
            renderNotices(data.notices || [], audience);
        } catch (error) {
            // Silent fail; notices should not block page usage.
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadNotices);
    } else {
        loadNotices();
    }
})();
