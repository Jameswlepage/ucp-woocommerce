# UCP for WooCommerce (Reference Implementation)

[![Test in WordPress Playground](https://img.shields.io/badge/Test%20in-WordPress%20Playground-3858e9?style=for-the-badge&logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Jameswlepage/ucp-woocommerce/main/blueprint.json)

> **DISCLAIMER:** This is an experimental/test implementation only. It is **NOT** an official WooCommerce plugin and will be deprecated. Do not use in production.

Reference implementation of the [Universal Commerce Protocol (UCP)](https://ucp.dev) `2026-01-11` for WooCommerce.

## Features

- **Business profile** at `/.well-known/ucp`
- **A2A agent-card stub** at `/.well-known/agent-card.json`
- **REST endpoints** (native checkout):
  - `POST /wp-json/ucp/v1/checkout-sessions`
  - `PATCH /wp-json/ucp/v1/checkout-sessions/{id}`
  - `POST /wp-json/ucp/v1/checkout-sessions/{id}/complete`
- **MCP (JSON-RPC 2.0) endpoint**:
  - `POST /wp-json/ucp/v1/mcp`

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce

## Security

- All transport must be HTTPS (the plugin checks webhook URLs and platform profile URLs are https)
- Optional bearer auth: set WordPress option `ucp_wc_bearer_token` to require `Authorization: Bearer <token>` on REST endpoints

## Notes

- This implementation creates WooCommerce orders on `complete` and stores payment credentials opaquely in order meta
- It does not attempt to capture funds; integrate with your PSP/gateway to process the received credential

## License

GPLv2 or later
