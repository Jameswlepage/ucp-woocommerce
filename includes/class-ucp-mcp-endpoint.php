<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_MCP_Endpoint {
	public static function register_routes(): void {
		register_rest_route('ucp/v1', '/mcp', [
			[
				'methods' => 'POST',
				'callback' => [__CLASS__, 'handle'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	public static function handle(WP_REST_Request $request): WP_REST_Response {
		$payload = $request->get_json_params();
		if (!is_array($payload)) {
			return new WP_REST_Response(self::jsonrpc_error(null, -32700, 'Parse error'), 400);
		}

		$jsonrpc = $payload['jsonrpc'] ?? null;
		$method = $payload['method'] ?? null;
		$id = $payload['id'] ?? null;
		$params = $payload['params'] ?? null;

		if ($jsonrpc !== '2.0' || !is_string($method)) {
			return new WP_REST_Response(self::jsonrpc_error($id, -32600, 'Invalid Request'), 400);
		}
		if (!is_array($params)) {
			$params = [];
		}

		$profile_url = null;
		$meta = $params['_meta'] ?? null;
		if (is_array($meta) && isset($meta['ucp']) && is_array($meta['ucp'])) {
			$profile_url = $meta['ucp']['profile'] ?? null;
		}

		try {
			switch ($method) {
				case 'create_checkout_session':
				case 'create_checkout':
					return self::call_rest('POST', '/ucp/v1/checkout-sessions', $id, $params, $profile_url);
				case 'update_checkout_session':
				case 'update_checkout':
					$session_id = $params['id'] ?? null;
					if (!is_string($session_id) || $session_id === '') {
						return new WP_REST_Response(self::jsonrpc_error($id, -32602, 'Missing params.id (session id).'), 400);
					}
					return self::call_rest('PATCH', '/ucp/v1/checkout-sessions/' . rawurlencode($session_id), $id, $params, $profile_url);
				case 'complete_checkout_session':
				case 'complete_checkout':
					$session_id = $params['id'] ?? null;
					if (!is_string($session_id) || $session_id === '') {
						return new WP_REST_Response(self::jsonrpc_error($id, -32602, 'Missing params.id (session id).'), 400);
					}
					return self::call_rest('POST', '/ucp/v1/checkout-sessions/' . rawurlencode($session_id) . '/complete', $id, $params, $profile_url);
				default:
					return new WP_REST_Response(self::jsonrpc_error($id, -32601, 'Method not found'), 404);
			}
		} catch (Throwable $e) {
			return new WP_REST_Response(self::jsonrpc_error($id, -32000, 'Internal error'), 500);
		}
	}

	private static function call_rest(string $http_method, string $route, $rpc_id, array $params, ?string $profile_url): WP_REST_Response {
		$r = new WP_REST_Request($http_method, $route);
		if ($profile_url) {
			$r->set_header('ucp-agent', 'profile="' . $profile_url . '"');
		}
		// Remove JSON-RPC plumbing from params for REST handlers.
		unset($params['_meta']);
		// For update/complete via MCP, params include id; REST uses path id.
		unset($params['id']);
		$r->set_body(wp_json_encode($params));

		// Route dispatch shortcut by calling controller methods directly.
		if ($http_method === 'POST' && $route === '/ucp/v1/checkout-sessions') {
			$resp = UCP_WC_REST_Controller::create_session($r);
		} elseif ($http_method === 'PATCH' && str_starts_with($route, '/ucp/v1/checkout-sessions/')) {
			// Extract id from route for controller.
			$session_id = basename($route);
			$r->set_param('id', $session_id);
			$resp = UCP_WC_REST_Controller::update_session($r);
		} elseif ($http_method === 'POST' && preg_match('#^/ucp/v1/checkout-sessions/([^/]+)/complete$#', $route, $m)) {
			$r->set_param('id', $m[1]);
			$resp = UCP_WC_REST_Controller::complete_session($r);
		} else {
			return new WP_REST_Response(self::jsonrpc_error($rpc_id, -32601, 'Unsupported internal route'), 500);
		}

		$body = $resp->get_data();
		$http_status = $resp->get_status();

		$rpc = [
			'jsonrpc' => '2.0',
			'id' => $rpc_id,
			'result' => $body,
		];
		return new WP_REST_Response($rpc, $http_status);
	}

	private static function jsonrpc_error($id, int $code, string $message): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}
}
