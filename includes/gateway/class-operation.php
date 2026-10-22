<?php
/**
 * One write operation of an agent.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * What a write ability wants to change; Pipeline::run() decides whether and how. The change is a callback that gets
 * a copy of the current state and returns the new state, so nothing is written before the pipeline agrees.
 *
 * Targets: array( 'post' => <ID> ) for an existing post, array( 'post' => 0, 'post_type' => <type> ) for a new one,
 * array( 'option' => <name> ) for an option of Pipeline::OPTIONS or Pipeline::MODEL_OPTION, array( 'term' => <ID> ) for a
 * term of a taxonomy in Scope::taxonomies(). 'block' names the affected
 * block for rejections; 'replace' marks content that replaces the whole post content, which may then hold no classic
 * block even where the old content was classic; 'token_set' on the user global styles makes the pipeline write them with Compiler::activate().
 * State of a post: the keys of Pipeline::POST_FIELDS (a product adds 'product', see Abilities\Commerce::props()). State
 * of an option: array( 'value' => <value> ). State of a term: array( 'term' => <fields> ), see Pipeline::term_state().
 */
final class Operation {

	/**
	 * Change callback.
	 *
	 * @var \Closure(array<string, mixed>): (array<string, mixed>|\WP_Error)
	 */
	public readonly \Closure $change;

	/**
	 * An operation.
	 *
	 * @param string               $ability   Ability name.
	 * @param string               $area      One of Gateway::AREAS.
	 * @param array<string, mixed> $target    Target, see the class description.
	 * @param string               $base_hash Hash the agent read last, '' for a new object.
	 * @param callable             $change    Gets a copy of the current state, returns the new state or a WP_Error.
	 * @param string[]             $kinds     Content kinds the ability knows it touches; the pipeline detects the others.
	 * @param string               $package   Package key from the input; operations with the same key share a package.
	 */
	public function __construct(
		public readonly string $ability,
		public readonly string $area,
		public readonly array $target,
		public readonly string $base_hash,
		callable $change,
		public readonly array $kinds = array(),
		public readonly string $package = ''
	) {
		$this->change = \Closure::fromCallable( $change );
	}

	/**
	 * Post ID of the target, 0 for a new post or an option.
	 */
	public function post_id(): int {
		return (int) ( $this->target['post'] ?? 0 );
	}

	/**
	 * Option name of the target, '' for a post.
	 */
	public function option(): string {
		return (string) ( $this->target['option'] ?? '' );
	}

	/**
	 * Term ID of the target, 0 for a post or an option.
	 */
	public function term(): int {
		return (int) ( $this->target['term'] ?? 0 );
	}

	/**
	 * Whether the operation creates a post.
	 */
	public function creates(): bool {
		return '' === $this->option() && 0 === $this->post_id() && ! isset( $this->target['term'] );
	}
}
