<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Order_Sync {
	public static function on_order_status_changed(int $order_id, string $old_status, string $new_status, $order): void {
		if (!is_a($order, 'WC_Order')) {
			return;
		}
		$webhook_url = $order->get_meta('_ucp_platform_order_webhook_url', true);
		if (!is_string($webhook_url) || $webhook_url === '') {
			return;
		}
		// Basic HTTPS enforcement.
		if (!UCP_WC_Utils::is_https_url($webhook_url)) {
			return;
		}

		$session_id = $order->get_meta('_ucp_session_id', true);
		$session_caps = [];
		if (is_string($session_id) && $session_id !== '') {
			$session = UCP_WC_Session_Store::get($session_id);
			if (!is_wp_error($session)) {
				$session_caps = $session['ucp_active_capabilities'] ?? [];
			}
		}

		// Only send if 'dev.ucp.shopping.order' was negotiated.
		$has_order_cap = false;
		foreach ($session_caps as $cap) {
			if (is_array($cap) && ($cap['name'] ?? null) === 'dev.ucp.shopping.order') {
				$has_order_cap = true;
				break;
			}
		}
		if (!$has_order_cap) {
			return;
		}

		$payload = self::build_order_update_payload($order, $new_status);
		$json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) {
			return;
		}

		$jws = UCP_WC_Webhook_Signer::sign_json_body($json);
		$headers = [
			'Content-Type' => 'application/json',
			'Idempotency-Key' => 'ucp-order-' . $order_id . '-' . $new_status,
		];
		if ($jws) {
			$headers['UCP-Signature'] = $jws;
		}

		wp_remote_post($webhook_url, [
			'timeout' => 8,
			'headers' => $headers,
			'body' => $json,
		]);
	}

	private static function build_order_update_payload(WC_Order $order, string $status): array {
		$items = [];
		foreach ($order->get_items() as $item_id => $item) {
			if (!is_a($item, 'WC_Order_Item_Product')) continue;
			$product_id = $item->get_product_id();
			$items[] = [
				'product_id' => (int)$product_id,
				'quantity' => (int)$item->get_quantity(),
				'name' => (string)$item->get_name(),
			];
		}

		$total = (float)$order->get_total();
		$amount_cents = (int)round($total * 100);

		return [
			'ucp' => [
				'version' => UCP_PROTOCOL_VERSION,
				'capabilities' => [
					['name' => 'dev.ucp.shopping.order', 'version' => UCP_PROTOCOL_VERSION],
				],
			],
			'order' => [
				'id' => (string)$order->get_id(),
				'number' => (string)$order->get_order_number(),
				'status' => $status,
				'currency' => (string)$order->get_currency(),
				'total_amount' => $amount_cents,
				'updated_at' => gmdate('c'),
				'items' => $items,
			],
		];
	}
}
