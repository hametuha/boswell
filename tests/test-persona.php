<?php
/**
 * Tests for Boswell_Persona
 *
 * @package Boswell
 */

class Test_Boswell_Persona extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Boswell_Persona::OPTION_KEY );
		delete_option( Boswell_Persona::OLD_OPTION_KEY );
	}

	public function test_get_all_returns_empty_initially(): void {
		$this->assertSame( array(), Boswell_Persona::get_all() );
	}

	public function test_save_creates_persona(): void {
		$user_id = self::factory()->user->create();
		$id      = Boswell_Persona::save(
			array(
				'name'     => 'Test Persona',
				'persona'  => 'A test persona definition.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		$this->assertIsString( $id );
		$this->assertSame( 'test-persona', $id );

		$all = Boswell_Persona::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Test Persona', $all[0]['name'] );
		$this->assertSame( $user_id, $all[0]['user_id'] );
	}

	public function test_save_updates_existing(): void {
		$user_id = self::factory()->user->create();
		$id      = Boswell_Persona::save(
			array(
				'name'     => 'Original',
				'persona'  => 'Original text.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		Boswell_Persona::save(
			array(
				'id'       => $id,
				'name'     => 'Updated',
				'persona'  => 'Updated text.',
				'user_id'  => $user_id,
				'provider' => 'openai',
			)
		);

		$all = Boswell_Persona::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Updated', $all[0]['name'] );
		$this->assertSame( 'openai', $all[0]['provider'] );
	}

	public function test_get_returns_single_persona(): void {
		$user_id = self::factory()->user->create();
		$id      = Boswell_Persona::save(
			array(
				'name'     => 'Madame Claude',
				'persona'  => 'Salon culture.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		$persona = Boswell_Persona::get( $id );
		$this->assertNotNull( $persona );
		$this->assertSame( 'Madame Claude', $persona['name'] );
	}

	public function test_get_returns_null_for_missing(): void {
		$this->assertNull( Boswell_Persona::get( 'nonexistent' ) );
	}

	public function test_delete_removes_persona(): void {
		$user_id = self::factory()->user->create();
		$id      = Boswell_Persona::save(
			array(
				'name'     => 'To Delete',
				'persona'  => 'Will be removed.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		$this->assertTrue( Boswell_Persona::delete( $id ) );
		$this->assertNull( Boswell_Persona::get( $id ) );
		$this->assertSame( array(), Boswell_Persona::get_all() );
	}

	public function test_delete_returns_false_for_missing(): void {
		$this->assertFalse( Boswell_Persona::delete( 'nonexistent' ) );
	}

	public function test_validate_rejects_missing_name(): void {
		$user_id = self::factory()->user->create();
		$result  = Boswell_Persona::save(
			array(
				'name'     => '',
				'persona'  => 'Text.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	public function test_validate_rejects_invalid_user_id(): void {
		$result = Boswell_Persona::save(
			array(
				'name'     => 'Bad User',
				'persona'  => 'Text.',
				'user_id'  => 999999,
				'provider' => 'anthropic',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	public function test_validate_rejects_invalid_provider(): void {
		$user_id = self::factory()->user->create();
		$result  = Boswell_Persona::save(
			array(
				'name'     => 'Bad Provider',
				'persona'  => 'Text.',
				'user_id'  => $user_id,
				'provider' => 'invalid',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_provider', $result->get_error_code() );
	}

	public function test_generate_id_creates_slug(): void {
		$id = Boswell_Persona::generate_id( 'Madame Claude' );
		$this->assertSame( 'madame-claude', $id );
	}

	public function test_generate_id_avoids_duplicates(): void {
		$user_id = self::factory()->user->create();
		Boswell_Persona::save(
			array(
				'name'     => 'Test',
				'persona'  => 'First.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		// Second persona with the same name should get a suffixed ID.
		$id = Boswell_Persona::save(
			array(
				'name'     => 'Test',
				'persona'  => 'Second.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		$this->assertSame( 'test-2', $id );
		$this->assertCount( 2, Boswell_Persona::get_all() );
	}

	public function test_migrate_converts_old_format(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option( Boswell_Persona::OLD_OPTION_KEY, '# Old Persona Markdown' );

		Boswell_Persona::migrate();

		$all = Boswell_Persona::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'default', $all[0]['id'] );
		$this->assertSame( '# Old Persona Markdown', $all[0]['persona'] );
	}

	public function test_migrate_skips_when_new_data_exists(): void {
		$user_id = self::factory()->user->create();
		update_option( Boswell_Persona::OLD_OPTION_KEY, 'Old text' );
		Boswell_Persona::save(
			array(
				'name'     => 'Existing',
				'persona'  => 'Already here.',
				'user_id'  => $user_id,
				'provider' => 'anthropic',
			)
		);

		Boswell_Persona::migrate();

		// Should not duplicate — still just the one existing persona.
		$this->assertCount( 1, Boswell_Persona::get_all() );
		$this->assertSame( 'Existing', Boswell_Persona::get_all()[0]['name'] );
	}
}
