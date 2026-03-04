(function () {
    const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

    function getCookie(name) {
        const key = String(name || '').trim();
        if (!key) return '';
        const encodedKey = encodeURIComponent(key) + '=';
        const parts = String(document.cookie || '').split(';');
        for (let i = 0; i < parts.length; i += 1) {
            const part = parts[i].trim();
            if (part.indexOf(encodedKey) !== 0) continue;
            return decodeURIComponent(part.substring(encodedKey.length));
        }
        return '';
    }

    function resolveUrl(input) {
        try {
            if (input instanceof Request) {
                return new URL(input.url, window.location.href);
            }
            return new URL(String(input || ''), window.location.href);
        } catch (error) {
            return null;
        }
    }

    function requestMethod(input, init) {
        if (init && typeof init.method === 'string' && init.method.trim() !== '') {
            return init.method.trim().toUpperCase();
        }
        if (input instanceof Request && typeof input.method === 'string' && input.method.trim() !== '') {
            return input.method.trim().toUpperCase();
        }
        return 'GET';
    }

    function requestCredentials(input, init) {
        if (init && typeof init.credentials === 'string' && init.credentials.trim() !== '') {
            return init.credentials;
        }
        if (input instanceof Request && typeof input.credentials === 'string' && input.credentials.trim() !== '') {
            return input.credentials;
        }
        return 'same-origin';
    }

    function mergeHeaders(input, init) {
        if (init && init.headers) {
            return new Headers(init.headers);
        }
        if (input instanceof Request && input.headers) {
            return new Headers(input.headers);
        }
        return new Headers();
    }

    function shouldAttachCsrf(url, method) {
        if (!url || !method || SAFE_METHODS.has(method)) {
            return false;
        }
        return url.origin === window.location.origin;
    }

    function shouldUseMethodOverride(url, method) {
        if (!url) return false;
        if (url.origin !== window.location.origin) return false;
        return method === 'PUT' || method === 'DELETE' || method === 'PATCH';
    }

    if (typeof window.fetch !== 'function') {
        return;
    }

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const url = resolveUrl(input);
        const method = requestMethod(input, init);
        const headers = mergeHeaders(input, init);
        const credentials = requestCredentials(input, init);
        let transportMethod = method;

        if (shouldAttachCsrf(url, method)) {
            const token = getCookie('XSRF-TOKEN');
            if (token && !headers.has('X-CSRF-Token')) {
                headers.set('X-CSRF-Token', token);
            }
        }

        if (shouldUseMethodOverride(url, method)) {
            transportMethod = 'POST';
            if (!headers.has('X-HTTP-Method-Override')) {
                headers.set('X-HTTP-Method-Override', method);
            }
        }

        const nextInit = Object.assign({}, init || {}, {
            method: transportMethod,
            headers: headers,
            credentials: credentials
        });
        return originalFetch(input, nextInit);
    };
})();
