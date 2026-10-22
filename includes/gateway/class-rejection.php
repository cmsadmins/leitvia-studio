<?php
/**
 * Rejections of the gateway.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a WP_Error in the one format agents read: code, message, and data with status, reason, target, next ability
 * and retry rule. retry is "once" (the agent may correct itself once), "never" (escalate) or "after:<seconds>".
 */
final class Rejection {

	/**
	 * A rejection.
	 *
	 * @param string               $code         Error code, leitvia_<name>.
	 * @param string               $message      Translated message with the fix.
	 * @param int                  $status       HTTP status.
	 * @param string               $reason       Machine-readable reason.
	 * @param array<string, mixed> $target       Affected object, for example array( 'post' => 12, 'block' => 'k3f9a2c1' ).
	 * @param string               $next_ability Ability the agent should call next, '' for none.
	 * @param string               $retry        Retry rule.
	 * @param array<string, mixed> $extra        Further data, for example current_hash or findings.
	 */
	public static function make( string $code, string $message, int $status, string $reason, array $target = array(), string $next_ability = '', string $retry = 'once', array $extra = array() ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'       => $status,
				'reason'       => $reason,
				'target'       => $target,
				'next_ability' => $next_ability,
				'retry'        => $retry,
			) + $extra
		);
	}
}
