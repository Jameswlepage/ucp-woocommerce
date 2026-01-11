<?php

if (!defined('ABSPATH')) { exit; }

final class UCP_WC_Webhook_Signer {
	/**
	 * Create a compact JWS (ES256) for a JSON body.
	 *
	 * Header: {"alg":"ES256","kid":"...","typ":"JWS"}
	 * Payload: raw JSON string
	 */
	public static function sign_json_body(string $json_body): ?string {
		$private_pem = get_option(UCP_WC_OPTION_PRIVATE_KEY_PEM);
		$kid = get_option(UCP_WC_OPTION_KEY_ID);
		if (empty($private_pem) || empty($kid)) {
			return null;
		}

		$header = [
			'alg' => 'ES256',
			'kid' => $kid,
			'typ' => 'JWS',
		];
		$encoded_header = UCP_WC_Utils::base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
		$encoded_payload = UCP_WC_Utils::base64url_encode($json_body);
		$signing_input = $encoded_header . '.' . $encoded_payload;

		$pkey = openssl_pkey_get_private($private_pem);
		if (!$pkey) {
			return null;
		}

		$der_sig = '';
		$ok = openssl_sign($signing_input, $der_sig, $pkey, OPENSSL_ALGO_SHA256);
		openssl_free_key($pkey);
		if (!$ok) {
			return null;
		}

		$raw_sig = self::ecdsa_der_to_raw($der_sig, 32);
		if ($raw_sig === null) {
			return null;
		}

		$encoded_sig = UCP_WC_Utils::base64url_encode($raw_sig);
		return $signing_input . '.' . $encoded_sig;
	}

	/**
	 * Convert ASN.1 DER ECDSA signature into raw JOSE format (r||s).
	 */
	private static function ecdsa_der_to_raw(string $der, int $part_len): ?string {
		$pos = 0;
		$len = strlen($der);
		if ($len < 8) return null;

		$expect = ord($der[$pos++]);
		if ($expect !== 0x30) return null;
		$seq_len = self::read_der_length($der, $pos);
		if ($seq_len === null) return null;

		if ($pos >= $len || ord($der[$pos++]) !== 0x02) return null;
		$r_len = self::read_der_length($der, $pos);
		if ($r_len === null) return null;
		$r = substr($der, $pos, $r_len);
		$pos += $r_len;

		if ($pos >= $len || ord($der[$pos++]) !== 0x02) return null;
		$s_len = self::read_der_length($der, $pos);
		if ($s_len === null) return null;
		$s = substr($der, $pos, $s_len);
		$pos += $s_len;

		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");
		$r = str_pad($r, $part_len, "\x00", STR_PAD_LEFT);
		$s = str_pad($s, $part_len, "\x00", STR_PAD_LEFT);

		if (strlen($r) !== $part_len || strlen($s) !== $part_len) {
			return null;
		}
		return $r . $s;
	}

	private static function read_der_length(string $der, int &$pos): ?int {
		if ($pos >= strlen($der)) return null;
		$byte = ord($der[$pos++]);
		if (($byte & 0x80) === 0) {
			return $byte;
		}
		$num_bytes = $byte & 0x7F;
		if ($num_bytes < 1 || $num_bytes > 4) return null;
		if (($pos + $num_bytes) > strlen($der)) return null;
		$len = 0;
		for ($i = 0; $i < $num_bytes; $i++) {
			$len = ($len << 8) | ord($der[$pos++]);
		}
		return $len;
	}
}
