<?php
/**
 * Gateway change packages.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;

/**
 * Post type, operations as JSON, one package per task key, states.
 */
class Test_Gateway_Packages extends WP_UnitTestCase {

	/**
	 * Signs in an agent.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * A stored operation.
	 *
	 * @param string $content Content of the new state.
	 * @return array<string, mixed>
	 */
	private static function entry( string $content = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' ): array {
		return array(
			'ability'   => 'leitvia/update-content',
			'area'      => 'content',
			'target'    => array( 'post' => 12 ),
			'base_hash' => 'abcdef0123456789',
			'level'     => 'suggest',
			'kinds'     => array(),
			'state'     => array( 'post_content' => $content ),
			'findings'  => array(),
		);
	}

	/**
	 * Not public, not in REST, edit_theme_options for everything.
	 */
	public function test_post_type(): void {
		$type = get_post_type_object( Packages::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertSame( 'edit_theme_options', $type->cap->edit_post );
		$this->assertSame( 'edit_theme_options', $type->cap->create_posts );
		$id = Packages::add( '', self::entry() );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'author' ) ), 'edit_post', $id ) );
		$this->assertTrue( user_can( self::factory()->user->create( array( 'role' => 'administrator' ) ), 'edit_post', $id ) );
	}

	/**
	 * A package holds its operations as JSON, written with wp_slash: markup, quotes and backslashes survive. The agent
	 * is the author, the state is open, the action fires once.
	 */
	public function test_add(): void {
		$created = array();
		add_action(
			'leitvia_studio_package_created',
			static function ( int $id ) use ( &$created ): void {
				$created[] = $id;
			}
		);
		$content = '<!-- wp:paragraph {"metadata":{"name":"a < b "q" back\\\\slash"}} --><p>5 > 3 "q" back\\slash</p><!-- /wp:paragraph -->';
		$id      = Packages::add( '', self::entry( $content ) );
		$this->assertIsInt( $id );
		$this->assertSame( get_current_user_id(), (int) get_post_field( 'post_author', $id ) );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertSame( 'open', Packages::state( $id ) );
		$operations = Packages::operations( $id );
		$this->assertSame( array( 'id' => 1 ) + self::entry( $content ), $operations[0] );
		$this->assertSame( array( $id ), $created );
	}

	/**
	 * Operations with the same key share the open package of the same user; another key, another user or a closed
	 * package start a new one.
	 */
	public function test_one_package_per_task(): void {
		$first = Packages::add( 'task-1', self::entry() );
		$this->assertSame( $first, Packages::add( 'task-1', self::entry() ) );
		$this->assertSame( array( 1, 2 ), array_column( Packages::operations( $first ), 'id' ) );
		$this->assertNotSame( $first, Packages::add( 'task-2', self::entry() ) );
		$this->assertNotSame( $first, Packages::add( '', self::entry() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertNotSame( $first, Packages::add( 'task-1', self::entry() ) );
	}

	/**
	 * States: only the known ones and only on packages.
	 */
	public function test_states(): void {
		$id = Packages::add( 'task-1', self::entry() );
		$this->assertTrue( Packages::set_state( $id, 'applied' ) );
		$this->assertSame( 'applied', Packages::state( $id ) );
		$this->assertTrue( Packages::set_state( $id, 'applied' ) );
		$this->assertFalse( Packages::set_state( $id, 'done' ) );
		$this->assertFalse( Packages::set_state( self::factory()->post->create(), 'open' ) );
		$this->assertSame( '', Packages::state( self::factory()->post->create() ) );
		$this->assertNotSame( $id, Packages::add( 'task-1', self::entry() ), 'a closed package takes no more operations' );
	}
}
