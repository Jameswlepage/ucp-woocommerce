=== UCP for WooCommerce (Reference Implementation) ===
Contributors: reference
Tags: commerce, ucp, google, checkout
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

**DISCLAIMER: This is an experimental/test implementation only. It is NOT an official WooCommerce plugin and will be deprecated. Do not use in production.**

[Test in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Jameswlepage/ucp-woocommerce/main/blueprint.json)

This plugin is a **reference implementation** of the Universal Commerce Protocol (UCP) **2026-01-11** for WooCommerce.

It provides:
- Business profile at: `/.well-known/ucp`
- A2A agent-card stub at: `/.well-known/agent-card.json`
- REST endpoints (native checkout):
  - POST   `/wp-json/ucp/v1/checkout-sessions`
  - PATCH  `/wp-json/ucp/v1/checkout-sessions/{id}`
  - POST   `/wp-json/ucp/v1/checkout-sessions/{id}/complete`
- MCP (JSON-RPC 2.0) endpoint:
  - POST `/wp-json/ucp/v1/mcp`

== Security ==
- All transport must be HTTPS (the plugin checks webhook URLs and platform profile URLs are https).
- Optional bearer auth:
  - set WordPress option `ucp_wc_bearer_token` to require `Authorization: Bearer <token>` on REST endpoints.

== Notes ==
- This implementation creates WooCommerce orders on `complete` and stores payment credentials opaquely in order meta.
- It does not attempt to capture funds; integrate with your PSP/gateway to process the received credential.

== Example: create checkout session (REST) ==

POST /wp-json/ucp/v1/checkout-sessions
UCP-Agent: profile="https://platform.example.com/profiles/shopping-agent.json"
Content-Type: application/json

{
  "line_items": [{"product_id": 123, "quantity": 1}],
  "shipping_address": {
    "street_address": "123 Main Street",
    "address_locality": "Charleston",
    "address_region": "SC",
    "postal_code": "29401",
    "address_country": "US"
  }
}

== Example: complete checkout session (REST) ==

POST /wp-json/ucp/v1/checkout-sessions/{id}/complete
Content-Type: application/json

{
  "payment_data": {
    "handler_id": "gpay",
    "credential": {"type": "PAYMENT_GATEWAY", "token": "..."}
  },
  "risk_signals": {"session_id": "abc_123"}
}
