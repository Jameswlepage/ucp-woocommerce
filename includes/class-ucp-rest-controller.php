<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_REST_Controller {
	public static function register_routes(): void {
		register_rest_route('ucp/v1', '/checkout-sessions', [
			[
				'methods' => 'POST',
				'callback' => [__CLASS__, 'create_session'],
				'permission_callback' => [__CLASS__, 'permission_check'],
			],
		]);

		register_rest_route('ucp/v1', '/checkout-sessions/(?P<id>[a-zA-Z0-9\-]+)', [
			[
				'methods' => 'GET',
				'callback' => [__CLASS__, 'get_session'],
				'permission_callback' => [__CLASS__, 'permission_check'],
			],
			[
				'methods' => 'PATCH',
				'callback' => [__CLASS__, 'update_session'],
				'permission_callback' => [__CLASS__, 'permission_check'],
			],
		]);

		register_rest_route('ucp/v1', '/checkout-sessions/(?P<id>[a-zA-Z0-9\-]+)/complete', [
			[
				'methods' => 'POST',
				'callback' => [__CLASS__, 'complete_session'],
				'permission_callback' => [__CLASS__, 'permission_check'],
			],
		]);
	}

	public static function permission_check(WP_REST_Request $request): bool|
	WP_Error {
		$token = get_option(UCP_WC_OPTION_BEARER_TOKEN);
		if (is_string($token) && $token !== '') {
			$auth = $request->get_header('authorization');
			if (!is_string($auth) || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
				return new WP_Error('ucp_unauthorized', 'Missing Authorization bearer token.', ['status' => 401]);
			}
			if (!hash_equals($token, trim($m[1]))) {
				return new WP_Error('ucp_unauthorized', 'Invalid Authorization bearer token.', ['status' => 401]);
			}
		}
		return true;
	}

	public static function create_session(WP_REST_Request $request): WP_REST_Response {
		$platform_profile_url = UCP_WC_Utils::parse_ucp_agent_profile_url($request->get_header('ucp-agent'));
		if (!$platform_profile_url) {
			return self::respond_error(UCP_WC_Utils::ucp_error('missing_platform_profile', 'UCP-Agent header with profile="..." is required.'), 400);
		}

		$neg = UCP_WC_Negotiation::negotiate($platform_profile_url);
		if (is_wp_error($neg)) {
			return self::respond_negotiation_error($neg);
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = [];
		}

		$session = [
			'id' => wp_generate_uuid4(),
			'status' => 'incomplete',
			'created_at' => gmdate('c'),
			'updated_at' => gmdate('c'),
			'platform_profile_url' => $platform_profile_url,
			'platform_order_webhook_url' => $neg['platform_order_webhook_url'] ?? null,
			'ucp_active_capabilities' => $neg['active_capabilities_response'] ?? [],
			'line_items' => $params['line_items'] ?? [],
			'shipping_address' => $params['shipping_address'] ?? null,
			'payment' => [
				'handlers' => self::advertise_payment_handlers($params),
			],
		];

		$stored = UCP_WC_Session_Store::create($session);
		if (is_wp_error($stored)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('internal_error', $stored->get_error_message(), 'internal'), 500);
		}

		return self::respond_with_ucp($stored, $neg, 201);
	}

	public static function get_session(WP_REST_Request $request): WP_REST_Response {
		$id = (string)$request['id'];
		$session = UCP_WC_Session_Store::get($id);
		if (is_wp_error($session)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('not_found', $session->get_error_message()), 404);
		}
		$ucp = [
			'version' => UCP_PROTOCOL_VERSION,
			'capabilities' => $session['ucp_active_capabilities'] ?? [],
		];
		$session['ucp'] = $ucp;
		return new WP_REST_Response($session, 200);
	}

	public static function update_session(WP_REST_Request $request): WP_REST_Response {
		$id = (string)$request['id'];
		$current = UCP_WC_Session_Store::get($id);
		if (is_wp_error($current)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('not_found', $current->get_error_message()), 404);
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = [];
		}

		$patch = [];
		foreach (['line_items', 'shipping_address'] as $field) {
			if (array_key_exists($field, $params)) {
				$patch[$field] = $params[$field];
			}
		}

		$updated = UCP_WC_Session_Store::update($id, $patch);
		if (is_wp_error($updated)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('internal_error', $updated->get_error_message(), 'internal'), 500);
		}

		$ucp = [
			'version' => UCP_PROTOCOL_VERSION,
			'capabilities' => $updated['ucp_active_capabilities'] ?? [],
		];
		$updated['ucp'] = $ucp;
		return new WP_REST_Response($updated, 200);
	}

	public static function complete_session(WP_REST_Request $request): WP_REST_Response {
		$id = (string)$request['id'];
		$session = UCP_WC_Session_Store::get($id);
		if (is_wp_error($session)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('not_found', $session->get_error_message()), 404);
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = [];
		}

		$payment_data = $params['payment_data'] ?? null;
		if (!is_array($payment_data)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('invalid_payment_data', 'payment_data is required.'), 400);
		}
		$handler_id = $payment_data['handler_id'] ?? null;
		if (!is_string($handler_id) || $handler_id === '') {
			return self::respond_error(UCP_WC_Utils::ucp_error('invalid_payment_data', 'payment_data.handler_id is required.'), 400);
		}

		$advertised = $session['payment']['handlers'] ?? [];
		$allowed_handler_ids = [];
		foreach ($advertised as $h) {
			if (is_array($h) && isset($h['id'])) {
				$allowed_handler_ids[] = $h['id'];
			}
		}
		if (!in_array($handler_id, $allowed_handler_ids, true)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('invalid_handler_id', 'handler_id is not in the advertised handlers set.'), 400);
		}

		if (!function_exists('wc_create_order')) {
			return self::respond_error(UCP_WC_Utils::ucp_error('woocommerce_missing', 'WooCommerce is required to complete checkout.'), 500);
		}

		$order = wc_create_order();
		if (is_wp_error($order)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('order_create_failed', $order->get_error_message(), 'internal'), 500);
		}

		$line_items = $session['line_items'] ?? [];
		if (!is_array($line_items) || empty($line_items)) {
			return self::respond_error(UCP_WC_Utils::ucp_error('invalid_line_items', 'line_items must be a non-empty array.'), 400);
		}

		foreach ($line_items as $item) {
			if (!is_array($item)) continue;
			$product_id = $item['product_id'] ?? null;
			$qty = $item['quantity'] ?? 1;
			$qty = max(1, (int)$qty);
			if (!is_numeric($product_id)) {
				return self::respond_error(UCP_WC_Utils::ucp_error('invalid_line_items', 'Each line_item requires product_id.'), 400);
			}
			$product = wc_get_product((int)$product_id);
			if (!$product) {
				return self::respond_error(UCP_WC_Utils::ucp_error('invalid_line_items', 'Product not found: ' . (int)$product_id), 400);
			}
			$order->add_product($product, $qty);
		}

		// Addresses.
		$shipping = $session['shipping_address'] ?? null;
		if (is_array($shipping)) {
			$order->set_address(self::map_ucp_address_to_wc($shipping), 'shipping');
		}
		$billing = $payment_data['billing_address'] ?? null;
		if (is_array($billing)) {
			$order->set_address(self::map_ucp_address_to_wc($billing), 'billing');
		}

		$order->update_meta_data('_ucp_session_id', $id);
		$order->update_meta_data('_ucp_platform_profile_url', $session['platform_profile_url'] ?? null);
		$order->update_meta_data('_ucp_platform_order_webhook_url', $session['platform_order_webhook_url'] ?? null);
		$order->update_meta_data('_ucp_payment_handler_id', $handler_id);
		$order->update_meta_data('_ucp_risk_signals', $params['risk_signals'] ?? null);
		$order->update_meta_data('_ucp_ap2', $params['ap2'] ?? null);

		// Store credential opaquely. DO NOT log or echo.
		$order->update_meta_data('_ucp_payment_credential', $payment_data['credential'] ?? null);

		$order->calculate_totals();
		// Mark on-hold by default; merchant can capture through their PSP integration.
		$order->update_status('on-hold', 'UCP checkout completed (payment credential received).');
		$order->save();

		UCP_WC_Session_Store::attach_order_id($id, (int)$order->get_id());

		$resp = [
			'status' => 'complete',
			'order_id' => (int)$order->get_id(),
			'order_number' => (string)$order->get_order_number(),
			'order_status' => (string)$order->get_status(),
		];
		$resp['ucp'] = [
			'version' => UCP_PROTOCOL_VERSION,
			'capabilities' => $session['ucp_active_capabilities'] ?? [],
		];

		return new WP_REST_Response($resp, 200);
	}

	private static function respond_with_ucp(array $payload, array $negotiation, int $http_status): WP_REST_Response {
		$payload['ucp'] = [
			'version' => $negotiation['ucp_version'],
			'capabilities' => $negotiation['active_capabilities_response'],
		];
		return new WP_REST_Response($payload, $http_status);
	}

	private static function respond_negotiation_error(WP_Error $err): WP_REST_Response {
		$code = $err->get_error_code();
		$data = $err->get_error_data();
		if ($code === 'ucp_version_unsupported') {
			$platform_version = is_array($data) ? ($data['platform_version'] ?? '') : '';
			$msg = 'Version ' . $platform_version . ' is not supported. This business implements version ' . UCP_PROTOCOL_VERSION . '.';
			return self::respond_error(UCP_WC_Utils::ucp_error('version_unsupported', $msg), 400);
		}
		if ($code === 'ucp_capability_unsupported') {
			return self::respond_error(UCP_WC_Utils::ucp_error('capability_unsupported', $err->get_error_message()), 400);
		}
		return self::respond_error(UCP_WC_Utils::ucp_error('negotiation_failed', $err->get_error_message()), 400);
	}

	private static function respond_error(array $payload, int $http_status): WP_REST_Response {
		return new WP_REST_Response($payload, $http_status);
	}

	private static function advertise_payment_handlers(array $context): array {
		// Dynamic filtering is application-specific; this reference implementation returns the configured list.
		$handlers = get_option(UCP_WC_OPTION_PAYMENT_HANDLERS, []);
		return is_array($handlers) ? $handlers : [];
	}

	private static function map_ucp_address_to_wc(array $addr): array {
		return [
			'first_name' => (string)($addr['first_name'] ?? ''),
			'last_name' => (string)($addr['last_name'] ?? ''),
			'address_1' => (string)($addr['street_address'] ?? ($addr['address_1'] ?? '')),
			'address_2' => (string)($addr['extended_address'] ?? ($addr['address_2'] ?? '')),
			'city' => (string)($addr['address_locality'] ?? ($addr['city'] ?? '')),
			'state' => (string)($addr['address_region'] ?? ($addr['state'] ?? '')),
			'postcode' => (string)($addr['postal_code'] ?? ($addr['postcode'] ?? '')),
			'country' => (string)($addr['address_country'] ?? ($addr['country'] ?? '')),
		];
	}
}
