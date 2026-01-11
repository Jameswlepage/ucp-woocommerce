<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Utils {
	public static function base64url_encode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public static function base64url_decode(string $data): string {
		$remainder = strlen($data) % 4;
		if ($remainder) {
			$data .= str_repeat('=', 4 - $remainder);
		}
		return base64_decode(strtr($data, '-_', '+/')) ?: '';
	}

	/**
	 * RFC 8941 Structured Field Dictionary parsing (minimal).
	 * Expects: UCP-Agent: profile="https://..."
	 */
	public static function parse_ucp_agent_profile_url(?string $header_value): ?string {
		if (!$header_value) {
			return null;
		}
		// Minimal extraction: profile="..."
		if (preg_match('/(?:^|[;,\s])profile\s*=\s*"([^"]+)"/i', $header_value, $m)) {
			return trim($m[1]);
		}
		return null;
	}

	public static function is_https_url(string $url): bool {
		$parts = wp_parse_url($url);
		return is_array($parts) && (($parts['scheme'] ?? '') === 'https') && !empty($parts['host']);
	}

	/**
	 * Returns origin "https://host" (no trailing slash) or null.
	 */
	public static function origin(string $url): ?string {
		$parts = wp_parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}
		$scheme = strtolower($parts['scheme']);
		$host = strtolower($parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : null;
		if ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
			return $scheme . '://' . $host . ':' . $port;
		}
		return $scheme . '://' . $host;
	}

	/**
	 * Extract max-age from Cache-Control header. Returns seconds or null.
	 */
	public static function cache_control_max_age(?string $cache_control): ?int {
		if (!$cache_control) {
			return null;
		}
		if (preg_match('/max-age\s*=\s*(\d+)/i', $cache_control, $m)) {
			return (int)$m[1];
		}
		return null;
	}

	/**
	 * Compare two YYYY-MM-DD strings.
	 * Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
	 */
	public static function compare_version(string $a, string $b): int {
		// Lexicographic comparison works for YYYY-MM-DD.
		if ($a === $b) return 0;
		return ($a < $b) ? -1 : 1;
	}

	/**
	 * Build a standard UCP error response structure.
	 */
	public static function ucp_error(string $code, string $message, string $severity = 'requires_buyer_input'): array {
		return [
			'status' => 'requires_escalation',
			'messages' => [[
				'type' => 'error',
				'code' => $code,
				'message' => $message,
				'severity' => $severity,
			]],
		];
	}
}
