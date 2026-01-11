<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Session_Store {
	private const META_ID = '_ucp_session_id';
	private const META_DATA = '_ucp_session_data';

	public static function create(array $session_data): array|WP_Error {
		$id = $session_data['id'] ?? null;
		if (!is_string($id) || $id === '') {
			$id = wp_generate_uuid4();
			$session_data['id'] = $id;
		}

		$post_id = wp_insert_post([
			'post_type' => UCP_WC_CPT_SESSION,
			'post_title' => 'UCP Checkout Session ' . $id,
			'post_status' => 'publish',
		], true);
		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, self::META_ID, $id);
		update_post_meta($post_id, self::META_DATA, $session_data);

		return $session_data;
	}

	public static function get(string $id): array|WP_Error {
		$q = new WP_Query([
			'post_type' => UCP_WC_CPT_SESSION,
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [[
				'key' => self::META_ID,
				'value' => $id,
			]],
		]);
		if (empty($q->posts)) {
			return new WP_Error('ucp_session_not_found', 'Checkout session not found.');
		}
		$post_id = (int)$q->posts[0];
		$data = get_post_meta($post_id, self::META_DATA, true);
		if (!is_array($data)) {
			return new WP_Error('ucp_session_corrupt', 'Checkout session data is missing or invalid.');
		}
		return $data;
	}

	public static function update(string $id, array $patch): array|WP_Error {
		$q = new WP_Query([
			'post_type' => UCP_WC_CPT_SESSION,
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [[
				'key' => self::META_ID,
				'value' => $id,
			]],
		]);
		if (empty($q->posts)) {
			return new WP_Error('ucp_session_not_found', 'Checkout session not found.');
		}
		$post_id = (int)$q->posts[0];

		$current = get_post_meta($post_id, self::META_DATA, true);
		if (!is_array($current)) {
			$current = [];
		}

		$merged = array_merge($current, $patch);
		$merged['id'] = $id;
		$merged['updated_at'] = gmdate('c');

		update_post_meta($post_id, self::META_DATA, $merged);
		return $merged;
	}

	public static function attach_order_id(string $session_id, int $order_id): void {
		$q = new WP_Query([
			'post_type' => UCP_WC_CPT_SESSION,
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [[
				'key' => self::META_ID,
				'value' => $session_id,
			]],
		]);
		if (empty($q->posts)) {
			return;
		}
		$post_id = (int)$q->posts[0];
		$current = get_post_meta($post_id, self::META_DATA, true);
		if (!is_array($current)) {
			$current = [];
		}
		$current['order_id'] = $order_id;
		$current['updated_at'] = gmdate('c');
		update_post_meta($post_id, self::META_DATA, $current);
	}
}
