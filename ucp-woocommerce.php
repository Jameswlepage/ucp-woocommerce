<?php
/**
 * Plugin Name: UCP for WooCommerce (Reference Implementation)
 * Description: Reference implementation of the Universal Commerce Protocol (UCP) 2026-01-11 for WooCommerce. Publishes /.well-known/ucp and exposes UCP checkout session endpoints.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Reference
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('UCP_WC_PLUGIN_VERSION', '0.1.0');
define('UCP_PROTOCOL_VERSION', '2026-01-11');

define('UCP_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UCP_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

define('UCP_WC_OPTION_KEYS', 'ucp_wc_signing_keys');
define('UCP_WC_OPTION_PRIVATE_KEY_PEM', 'ucp_wc_private_key_pem');
define('UCP_WC_OPTION_KEY_ID', 'ucp_wc_key_id');
define('UCP_WC_OPTION_PAYMENT_HANDLERS', 'ucp_wc_payment_handlers');
define('UCP_WC_OPTION_BEARER_TOKEN', 'ucp_wc_bearer_token');

define('UCP_WC_QUERYVAR_WELLKNOWN', 'ucp_well_known');
define('UCP_WC_QUERYVAR_AGENT_CARD', 'ucp_agent_card');

define('UCP_WC_CPT_SESSION', 'ucp_checkout_session');

require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-utils.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-webhook-signer.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-profile.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-negotiation.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-session-store.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-rest-controller.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-mcp-endpoint.php';
require_once UCP_WC_PLUGIN_DIR . 'includes/class-ucp-order-sync.php';

final class UCP_WC_Plugin {
	public static function init(): void {
		add_action('init', [__CLASS__, 'register_rewrites']);
		add_filter('query_vars', [__CLASS__, 'register_query_vars']);
		add_action('init', [__CLASS__, 'register_cpt']);
		add_action('template_redirect', [__CLASS__, 'handle_well_known']);

		add_action('rest_api_init', [__CLASS__, 'register_rest']);

		// WooCommerce order status -> platform webhook.
		add_action('woocommerce_order_status_changed', ['UCP_WC_Order_Sync', 'on_order_status_changed'], 10, 4);
	}

	public static function activate(): void {
		self::register_rewrites();
		flush_rewrite_rules();
		UCP_WC_Profile::ensure_signing_keypair();
		UCP_WC_Profile::ensure_default_payment_handlers();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function register_rewrites(): void {
		add_rewrite_rule('^\.well-known/ucp/?$', 'index.php?' . UCP_WC_QUERYVAR_WELLKNOWN . '=1', 'top');
		add_rewrite_rule('^\.well-known/agent-card\.json/?$', 'index.php?' . UCP_WC_QUERYVAR_AGENT_CARD . '=1', 'top');
	}

	public static function register_query_vars(array $vars): array {
		$vars[] = UCP_WC_QUERYVAR_WELLKNOWN;
		$vars[] = UCP_WC_QUERYVAR_AGENT_CARD;
		return $vars;
	}

	public static function register_cpt(): void {
		// Internal storage. Not shown in admin menus.
		register_post_type(UCP_WC_CPT_SESSION, [
			'labels' => [
				'name' => 'UCP Checkout Sessions',
				'singular_name' => 'UCP Checkout Session',
			],
			'public' => false,
			'show_ui' => false,
			'show_in_rest' => false,
			'supports' => ['title'],
			'capability_type' => 'post',
		]);
	}

	public static function handle_well_known(): void {
		if (get_query_var(UCP_WC_QUERYVAR_WELLKNOWN)) {
			UCP_WC_Profile::output_business_profile();
			exit;
		}
		if (get_query_var(UCP_WC_QUERYVAR_AGENT_CARD)) {
			UCP_WC_Profile::output_agent_card();
			exit;
		}
	}

	public static function register_rest(): void {
		UCP_WC_REST_Controller::register_routes();
		UCP_WC_MCP_Endpoint::register_routes();
	}
}

register_activation_hook(__FILE__, ['UCP_WC_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['UCP_WC_Plugin', 'deactivate']);

UCP_WC_Plugin::init();
