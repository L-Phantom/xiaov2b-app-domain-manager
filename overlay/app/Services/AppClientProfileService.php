<?php

namespace App\Services;

use Illuminate\Http\Request;

class AppClientProfileService
{
    public function resolve(?Request $request = null): array
    {
        $request = $request ?: request();
        $context = $this->requestContext($request);
        $profiles = (array) config('app_clients.profiles', []);

        foreach ($profiles as $key => $profile) {
            $matchedBy = $this->matchProfile($context, (array) $profile);
            if ($matchedBy !== null) {
                return $this->buildProfile((string) $key, (array) $profile, $context, $matchedBy);
            }
        }

        return $this->buildProfile('default', [], $context, 'default');
    }

    public function requestContext(Request $request): array
    {
        return [
            'client_slug' => trim((string) ($request->header('x-xboard-client-slug') ?: $request->header('x-client-slug', ''))),
            'package_name' => trim((string) ($request->header('x-xboard-package-name') ?: $request->header('x-package-name', ''))),
            'app_name' => trim((string) ($request->header('x-xboard-app-name') ?: $request->header('x-app-name', ''))),
            'user_agent' => trim((string) $request->userAgent()),
            'host' => trim((string) $request->getHost()),
        ];
    }

    public function buildApiUrls(array $profile, string $version = 'v2'): array
    {
        $basePath = $version === 'v1' ? '/api/v1/client/app' : '/api/v2/app';
        $currentUrl = rtrim(url($basePath), '/');
        $urls = [$currentUrl];

        foreach ((array) ($profile['api_hosts'] ?? []) as $host) {
            $host = $this->normalizeEndpoint($host);
            if ($host === '') {
                continue;
            }
            $urls[] = sprintf('%s%s', $host, $basePath);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    public function buildDebugPayload(array $profile, Request $request): array
    {
        $context = $this->requestContext($request);
        return [
            'profile_key' => $profile['profile_key'] ?? 'default',
            'profile_name' => $profile['profile_name'] ?? 'Default',
            'matched_by' => $profile['matched_by'] ?? 'default',
            'client_slug' => $context['client_slug'],
            'package_name' => $context['package_name'],
            'app_name' => $context['app_name'],
            'user_agent' => $context['user_agent'],
            'request_host' => $context['host'],
            'api_hosts' => $profile['api_hosts'] ?? [],
            'api_base_urls' => $this->buildApiUrls($profile),
            'subscribe_host' => $profile['subscribe_host'] ?? '',
            'subscribe_path' => $profile['subscribe_path'] ?? '',
            'replace_host' => $profile['replace_host'] ?? '',
            'subscribe_sign_enable' => (int) ($profile['subscribe_sign_enable'] ?? 0),
            'subscribe_sign_require_timestamp' => (int) ($profile['subscribe_sign_require_timestamp'] ?? 0),
            'subscribe_sign_max_skew_seconds' => (int) ($profile['subscribe_sign_max_skew_seconds'] ?? 300),
        ];
    }

    private function buildProfile(string $key, array $profile, array $context, string $matchedBy): array
    {
        $appName = (string) ($profile['app_name'] ?? config('v2board.app_name', 'XiaoV2Board'));
        $apiHosts = array_values(array_filter(array_map([$this, 'normalizeEndpoint'], (array) ($profile['app_api_domain_hosts'] ?? config('v2board.app_api_domain_hosts', [])))));
        $subscribeHost = $this->normalizeEndpoint((string) ($profile['app_domain_public_host'] ?? config('v2board.app_domain_public_host', '')));
        $replaceHost = $this->normalizeHost((string) ($profile['app_domain_replace_host'] ?? config('v2board.app_domain_replace_host', '')));
        $subscribePath = $this->normalizePath((string) ($profile['app_domain_subscribe_path'] ?? config('v2board.app_domain_subscribe_path', '/api/v1/client/custom_app/subscribe')));

        return [
            'profile_key' => $key,
            'profile_name' => (string) ($profile['name'] ?? $appName),
            'matched_by' => $matchedBy,
            'client_slug' => $context['client_slug'],
            'package_name' => $context['package_name'],
            'app_name' => $appName,
            'app_url' => (string) config('v2board.app_url'),
            'logo' => config('v2board.logo'),
            'tos_url' => config('v2board.tos_url'),
            'api_domain_enable' => (int) ($profile['app_api_domain_enable'] ?? config('v2board.app_api_domain_enable', 0)),
            'api_hosts' => $apiHosts,
            'api_encrypt_enable' => (int) ($profile['app_api_domain_encrypt_enable'] ?? config('v2board.app_api_domain_encrypt_enable', 0)),
            'api_encrypt_key' => trim((string) ($profile['app_api_domain_encrypt_key'] ?? config('v2board.app_api_domain_encrypt_key', ''))),
            'subscribe_host' => $subscribeHost,
            'subscribe_path' => $subscribePath,
            'replace_host' => $replaceHost,
            'subscribe_sign_enable' => (int) ($profile['app_domain_subscribe_sign_enable'] ?? config('v2board.app_domain_subscribe_sign_enable', 0)),
            'subscribe_sign_secret' => trim((string) ($profile['app_domain_subscribe_sign_secret'] ?? config('v2board.app_domain_subscribe_sign_secret', ''))),
            'subscribe_sign_require_timestamp' => (int) ($profile['app_domain_subscribe_sign_require_timestamp'] ?? config('v2board.app_domain_subscribe_sign_require_timestamp', 0)),
            'subscribe_sign_max_skew_seconds' => (int) ($profile['app_domain_subscribe_sign_max_skew_seconds'] ?? config('v2board.app_domain_subscribe_sign_max_skew_seconds', 300)),
            'minimum_versions' => [
                'android' => (string) config('v2board.android_version', ''),
                'windows' => (string) config('v2board.windows_version', ''),
                'macos' => (string) config('v2board.macos_version', ''),
            ],
        ];
    }

    private function matchProfile(array $context, array $profile): ?string
    {
        $match = (array) ($profile['match'] ?? []);
        if (!$match) {
            return null;
        }

        if ($this->matchesExact($context['client_slug'], (array) ($match['client_slugs'] ?? []))) {
            return 'client_slug';
        }
        if ($this->matchesExact($context['package_name'], (array) ($match['package_names'] ?? []))) {
            return 'package_name';
        }
        if ($this->matchesContains($context['user_agent'], (array) ($match['user_agents_contains'] ?? []))) {
            return 'user_agent';
        }

        return null;
    }

    private function matchesExact(string $actual, array $expected): bool
    {
        if ($actual === '') {
            return false;
        }

        foreach ($expected as $item) {
            if (strcasecmp($actual, trim((string) $item)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchesContains(string $actual, array $expected): bool
    {
        if ($actual === '') {
            return false;
        }

        foreach ($expected as $item) {
            $item = trim((string) $item);
            if ($item !== '' && stripos($actual, $item) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHost(?string $host): string
    {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        return rtrim($host, '/');
    }

    private function normalizeEndpoint(?string $endpoint): string
    {
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = $this->defaultSchemeForEndpoint($endpoint) . '://' . $endpoint;
        }

        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    private function defaultSchemeForEndpoint(string $endpoint): string
    {
        $host = preg_replace('#/.*$#', '', trim($endpoint));
        if (preg_match('#^(\d{1,3}\.){3}\d{1,3}(:\d+)?$#', $host)) {
            return 'http';
        }
        if (preg_match('#:(?!443$)\d+$#', $host)) {
            return 'http';
        }

        return 'https';
    }

    private function normalizePath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            $path = '/api/v1/client/custom_app/subscribe';
        }

        return '/' . ltrim($path, '/');
    }
}
