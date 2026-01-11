<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Profile {
	public static function ensure_signing_keypair(): void {
		$existing_pub = get_option(UCP_WC_OPTION_KEYS);
		$existing_priv = get_option(UCP_WC_OPTION_PRIVATE_KEY_PEM);
		$existing_kid = get_option(UCP_WC_OPTION_KEY_ID);

		if (!empty($existing_pub) && !empty($existing_priv) && !empty($existing_kid)) {
			return;
		}

		$config = [
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		];
		$res = openssl_pkey_new($config);
		if (!$res) {
			return;
		}

		$private_pem = '';
		openssl_pkey_export($res, $private_pem);
		$details = openssl_pkey_get_details($res);
		if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
			return;
		}

		$kid = 'business_' . gmdate('Y') . '_' . wp_generate_password(8, false, false);
		$x = UCP_WC_Utils::base64url_encode($details['ec']['x']);
		$y = UCP_WC_Utils::base64url_encode($details['ec']['y']);

		$jwks = [[
			'kid' => $kid,
			'kty' => 'EC',
			'crv' => 'P-256',
			'x' => $x,
			'y' => $y,
			'use' => 'sig',
			'alg' => 'ES256',
		]];

		update_option(UCP_WC_OPTION_PRIVATE_KEY_PEM, $private_pem, true);
		update_option(UCP_WC_OPTION_KEYS, $jwks, true);
		update_option(UCP_WC_OPTION_KEY_ID, $kid, true);
	}

	public static function ensure_default_payment_handlers(): void {
		$existing = get_option(UCP_WC_OPTION_PAYMENT_HANDLERS);
		if (!empty($existing)) {
			return;
		}

		$defaults = [
			[
				'id' => 'gpay',
				'name' => 'com.google.pay',
				'version' => '2024-12-03',
				'spec' => 'https://developers.google.com/merchant/ucp/guides/gpay-payment-handler',
				'config_schema' => 'https://pay.google.com/gp/p/ucp/2026-01-11/schemas/gpay_config.json',
				'instrument_schemas' => [
					'https://pay.google.com/gp/p/ucp/2026-01-11/schemas/gpay_card_payment_instrument.json',
				],
			],
			[
				'id' => 'business_tokenizer',
				'name' => 'dev.ucp.business_tokenizer',
				'version' => UCP_PROTOCOL_VERSION,
				'spec' => home_url('/ucp/specs/payments/business_tokenizer'),
				'config_schema' => 'https://ucp.dev/schemas/payments/delegate-payment.json',
				'instrument_schemas' => [
					'https://ucp.dev/schemas/shopping/types/card_payment_instrument.json',
				],
			],
		];

		update_option(UCP_WC_OPTION_PAYMENT_HANDLERS, $defaults, true);
	}

	public static function get_business_profile_array(): array {
		self::ensure_signing_keypair();
		self::ensure_default_payment_handlers();

		$rest_endpoint = rtrim(rest_url('ucp/v1'), '/');
		$mcp_endpoint = rtrim(rest_url('ucp/v1/mcp'), '/');
		$agent_card_url = home_url('/.well-known/agent-card.json');

		$capabilities = [
			[
				'name' => 'dev.ucp.shopping.checkout',
				'version' => UCP_PROTOCOL_VERSION,
				'spec' => 'https://ucp.dev/specification/checkout',
				'schema' => 'https://ucp.dev/schemas/shopping/checkout.json',
			],
			[
				'name' => 'dev.ucp.shopping.fulfillment',
				'version' => UCP_PROTOCOL_VERSION,
				'spec' => 'https://ucp.dev/specification/fulfillment',
				'schema' => 'https://ucp.dev/schemas/shopping/fulfillment.json',
				'extends' => 'dev.ucp.shopping.checkout',
			],
			[
				'name' => 'dev.ucp.shopping.discount',
				'version' => UCP_PROTOCOL_VERSION,
				'spec' => 'https://ucp.dev/specification/discount',
				'schema' => 'https://ucp.dev/schemas/shopping/discount.json',
				'extends' => 'dev.ucp.shopping.checkout',
			],
			[
				'name' => 'dev.ucp.shopping.order',
				'version' => UCP_PROTOCOL_VERSION,
				'spec' => 'https://ucp.dev/specification/order',
				'schema' => 'https://ucp.dev/schemas/shopping/order.json',
			],
		];

		$profile = [
			'ucp' => [
				'version' => UCP_PROTOCOL_VERSION,
				'services' => [
					'dev.ucp.shopping' => [
						'version' => UCP_PROTOCOL_VERSION,
						'spec' => 'https://ucp.dev/specification/overview',
						'rest' => [
							'schema' => 'https://ucp.dev/services/shopping/rest.openapi.json',
							'endpoint' => $rest_endpoint,
						],
						'mcp' => [
							'schema' => 'https://ucp.dev/services/shopping/mcp.openrpc.json',
							'endpoint' => $mcp_endpoint,
						],
						'a2a' => [
							'endpoint' => $agent_card_url,
						],
						'embedded' => [
							'schema' => 'https://ucp.dev/services/shopping/embedded.openrpc.json',
						],
					],
				],
				'capabilities' => $capabilities,
			],
			'payment' => [
				'handlers' => get_option(UCP_WC_OPTION_PAYMENT_HANDLERS, []),
			],
			'signing_keys' => get_option(UCP_WC_OPTION_KEYS, []),
		];

		return $profile;
	}

	public static function output_business_profile(): void {
		$profile = self::get_business_profile_array();
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=3600');
		echo wp_json_encode($profile, JSON_UNESCAPED_SLASHES);
	}

	public static function output_agent_card(): void {
		$card = [
			'version' => '2025-10-01',
			'name' => get_bloginfo('name') ?: 'UCP Business Agent',
			'description' => 'A2A agent card stub for UCP. This reference plugin primarily supports REST transport.',
			'ucp' => [
				'version' => UCP_PROTOCOL_VERSION,
				'profile' => home_url('/.well-known/ucp'),
			],
		];
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=3600');
		echo wp_json_encode($card, JSON_UNESCAPED_SLASHES);
	}
}
