<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Negotiation {
	/**
	 * Resolve and validate a platform profile from a URL.
	 * Caches profile per HTTP Cache-Control max-age (default 3600s).
	 */
	public static function fetch_platform_profile(string $profile_url): array|WP_Error {
		if (!UCP_WC_Utils::is_https_url($profile_url)) {
			return new WP_Error('ucp_invalid_platform_profile_url', 'Platform profile URL must be an https URL.');
		}

		$cache_key = 'ucp_pprof_' . md5($profile_url);
		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['_profile'])) {
			return $cached['_profile'];
		}

		$resp = wp_remote_get($profile_url, [
			'timeout' => 8,
			'headers' => [
				'Accept' => 'application/json',
			],
		]);
		if (is_wp_error($resp)) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code($resp);
		$body = wp_remote_retrieve_body($resp);
		if ($code < 200 || $code >= 300) {
			return new WP_Error('ucp_platform_profile_fetch_failed', 'Failed to fetch platform profile.', ['http_status' => $code]);
		}
		$profile = json_decode($body, true);
		if (!is_array($profile)) {
			return new WP_Error('ucp_platform_profile_invalid_json', 'Platform profile is not valid JSON.');
		}

		$validation = self::validate_platform_profile($profile);
		if (is_wp_error($validation)) {
			return $validation;
		}

		$cache_control = wp_remote_retrieve_header($resp, 'cache-control');
		$max_age = UCP_WC_Utils::cache_control_max_age(is_string($cache_control) ? $cache_control : null);
		$ttl = $max_age ?? 3600;
		$ttl = max(60, min($ttl, 86400));

		set_transient($cache_key, ['_profile' => $profile], $ttl);

		return $profile;
	}

	public static function validate_platform_profile(array $profile): true|WP_Error {
		$ucp = $profile['ucp'] ?? null;
		if (!is_array($ucp)) {
			return new WP_Error('ucp_platform_profile_missing_ucp', 'Platform profile missing ucp object.');
		}
		$version = $ucp['version'] ?? null;
		if (!is_string($version) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $version)) {
			return new WP_Error('ucp_platform_profile_missing_version', 'Platform profile missing valid ucp.version.');
		}
		$caps = $ucp['capabilities'] ?? null;
		if (!is_array($caps)) {
			return new WP_Error('ucp_platform_profile_missing_capabilities', 'Platform profile missing ucp.capabilities array.');
		}

		// Namespace binding validation for capability spec and schema URLs.
		foreach ($caps as $cap) {
			if (!is_array($cap)) {
				return new WP_Error('ucp_platform_profile_invalid_capability', 'Platform capability entry is invalid.');
			}
			$name = $cap['name'] ?? null;
			$spec = $cap['spec'] ?? null;
			$schema = $cap['schema'] ?? null;
			if (!is_string($name) || !is_string($spec) || !is_string($schema)) {
				return new WP_Error('ucp_platform_profile_invalid_capability_fields', 'Platform capability missing required fields name/spec/schema.');
			}

			$expected_origin = self::expected_origin_for_capability($name);
			if ($expected_origin) {
				$spec_origin = UCP_WC_Utils::origin($spec);
				$schema_origin = UCP_WC_Utils::origin($schema);
				if (!$spec_origin || !$schema_origin) {
					return new WP_Error('ucp_platform_profile_invalid_capability_urls', 'Platform capability spec/schema are not valid URLs.');
				}
				// Exact origin match (scheme+host+optional port).
				if (strtolower($spec_origin) !== strtolower($expected_origin) || strtolower($schema_origin) !== strtolower($expected_origin)) {
					return new WP_Error('ucp_platform_profile_namespace_binding_failed', 'Capability spec/schema origin does not match namespace authority.', [
						'capability' => $name,
						'expected_origin' => $expected_origin,
						'spec_origin' => $spec_origin,
						'schema_origin' => $schema_origin,
					]);
				}
			}
		}

		return true;
	}

	/**
	 * Compute active negotiated capabilities for a request.
	 */
	public static function negotiate(string $platform_profile_url): array|WP_Error {
		$platform_profile = self::fetch_platform_profile($platform_profile_url);
		if (is_wp_error($platform_profile)) {
			return $platform_profile;
		}

		$platform_ucp_version = $platform_profile['ucp']['version'] ?? '';
		if (!is_string($platform_ucp_version)) {
			return new WP_Error('ucp_platform_profile_missing_version', 'Platform profile missing ucp.version.');
		}

		// Version negotiation: if platform > business => unsupported.
		if (UCP_WC_Utils::compare_version($platform_ucp_version, UCP_PROTOCOL_VERSION) === 1) {
			return new WP_Error('ucp_version_unsupported', 'Platform version is newer than business implementation.', [
				'platform_version' => $platform_ucp_version,
				'business_version' => UCP_PROTOCOL_VERSION,
			]);
		}

		$business_profile = UCP_WC_Profile::get_business_profile_array();
		$business_caps = $business_profile['ucp']['capabilities'] ?? [];
		$platform_caps = $platform_profile['ucp']['capabilities'] ?? [];

		$platform_by_name = [];
		foreach ($platform_caps as $cap) {
			if (is_array($cap) && isset($cap['name'])) {
				$platform_by_name[$cap['name']] = $cap;
			}
		}

		$intersection = [];
		foreach ($business_caps as $cap) {
			if (!is_array($cap) || empty($cap['name']) || empty($cap['version'])) {
				continue;
			}
			$name = $cap['name'];
			if (!isset($platform_by_name[$name])) {
				continue;
			}
			$platform_cap_version = $platform_by_name[$name]['version'] ?? null;
			if (!is_string($platform_cap_version)) {
				continue;
			}
			// Capability version compatibility: include if platform cap version <= business cap version.
			if (UCP_WC_Utils::compare_version($platform_cap_version, $cap['version']) === 1) {
				continue;
			}
			$intersection[$name] = $cap;
		}

		// Prune orphaned extensions until fixed point.
		$changed = true;
		while ($changed) {
			$changed = false;
			foreach ($intersection as $name => $cap) {
				$parent = $cap['extends'] ?? null;
				if (is_string($parent) && !isset($intersection[$parent])) {
					unset($intersection[$name]);
					$changed = true;
				}
			}
		}

		if (!isset($intersection['dev.ucp.shopping.checkout'])) {
			return new WP_Error('ucp_capability_unsupported', 'No mutually supported checkout capability found.');
		}

		$active_for_response = [];
		foreach ($intersection as $cap) {
			$active_for_response[] = [
				'name' => $cap['name'],
				'version' => $cap['version'],
			];
		}

		return [
			'ucp_version' => UCP_PROTOCOL_VERSION,
			'platform_profile_url' => $platform_profile_url,
			'platform_profile' => $platform_profile,
			'active_capabilities' => array_values($intersection),
			'active_capabilities_response' => $active_for_response,
			'platform_order_webhook_url' => self::extract_platform_order_webhook_url($platform_profile),
		];
	}

	public static function extract_platform_order_webhook_url(array $platform_profile): ?string {
		$caps = $platform_profile['ucp']['capabilities'] ?? [];
		if (!is_array($caps)) return null;
		foreach ($caps as $cap) {
			if (!is_array($cap)) continue;
			if (($cap['name'] ?? null) === 'dev.ucp.shopping.order') {
				$config = $cap['config'] ?? null;
				if (is_array($config) && isset($config['webhook_url']) && is_string($config['webhook_url'])) {
					return $config['webhook_url'];
				}
			}
		}
		return null;
	}

	private static function expected_origin_for_capability(string $capability_name): ?string {
		// Parse {reverse-domain}.{service}.{capability}
		$parts = explode('.', $capability_name);
		if (count($parts) < 3) {
			return null;
		}
		$reverse_domain_parts = array_slice($parts, 0, -2);
		if (empty($reverse_domain_parts)) {
			return null;
		}
		$authority = implode('.', array_reverse($reverse_domain_parts));
		return 'https://' . strtolower($authority);
	}
}
