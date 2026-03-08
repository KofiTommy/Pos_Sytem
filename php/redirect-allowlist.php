<?php

function redirect_strip_control_chars(string $value): string {
    return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
}

function redirect_normalize_target(string $target): string {
    return trim(redirect_strip_control_chars((string)$target));
}

function redirect_is_allowlisted(string $target, array $allowlist): bool {
    $candidate = redirect_normalize_target($target);
    if ($candidate === '' || strlen($candidate) > 2048) {
        return false;
    }
    if (strpos($candidate, '\\') !== false) {
        return false;
    }

    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return false;
    }
    if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return false;
    }

    $candidatePath = (string)($parts['path'] ?? '');
    if ($candidatePath === '') {
        return false;
    }

    foreach ($allowlist as $allowedRaw) {
        $allowed = redirect_normalize_target((string)$allowedRaw);
        if ($allowed === '') {
            continue;
        }
        $allowedParts = parse_url($allowed);
        if (!is_array($allowedParts)) {
            continue;
        }
        if (isset($allowedParts['scheme']) || isset($allowedParts['host'])) {
            continue;
        }

        $allowedPath = (string)($allowedParts['path'] ?? '');
        if ($allowedPath === '' || !hash_equals($allowedPath, $candidatePath)) {
            continue;
        }

        $allowedQuery = array_key_exists('query', $allowedParts) ? (string)$allowedParts['query'] : null;
        $candidateQuery = array_key_exists('query', $parts) ? (string)$parts['query'] : '';
        if ($allowedQuery !== null && !hash_equals($allowedQuery, $candidateQuery)) {
            continue;
        }

        $allowedFragment = array_key_exists('fragment', $allowedParts) ? (string)$allowedParts['fragment'] : null;
        $candidateFragment = array_key_exists('fragment', $parts) ? (string)$parts['fragment'] : '';
        if ($allowedFragment !== null && !hash_equals($allowedFragment, $candidateFragment)) {
            continue;
        }

        return true;
    }

    return false;
}

function redirect_resolve_allowlisted(string $target, array $allowlist, string $fallback): string {
    $candidate = redirect_normalize_target($target);
    if (redirect_is_allowlisted($candidate, $allowlist)) {
        return $candidate;
    }

    $safeFallback = redirect_normalize_target($fallback);
    if (redirect_is_allowlisted($safeFallback, $allowlist)) {
        return $safeFallback;
    }

    foreach ($allowlist as $allowed) {
        $normalized = redirect_normalize_target((string)$allowed);
        if (redirect_is_allowlisted($normalized, $allowlist)) {
            return $normalized;
        }
    }

    return '/';
}

