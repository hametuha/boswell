<?php
/**
 * Tests for Boswell_Cron (per-persona scheduling)
 *
 * @package Boswell
 */

class Test_Boswell_Cron extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Boswell_Persona::OPTION_KEY );
		Boswell_Cron::unschedule();
	}

	public function tear_down(): void {
		Boswell_Cron::unschedule();
		parent::tear_down();
	}

	/**
	 * Create a test persona with cron fields.
	 *
	 * @param array $overrides Override defaults.
	 * @return string Persona ID.
	 */
	private function create_persona( array $overrides = array() ): string {
		$user_id = self::factory()->user->create();
		$data    = array_merge(
			array(
				'name'           => 'Test Persona',
				'persona'        => 'A test persona.',
				'user_id'        => $user_id,
				'provider'       => 'anthropic',
				'cron_enabled'   => false,
				'cron_frequency' => 'daily',
			),
			$overrides
		);
		return Boswell_Persona::save( $data );
	}

	public function test_persona_stores_cron_fields(): void {
		$id      = $this->create_persona(
			array(
				'cron_enabled'   => true,
				'cron_frequency' => 'hourly',
			)
		);
		$persona = Boswell_Persona::get( $id );
		$this->assertTrue( $persona['cron_enabled'] );
		$this->assertSame( 'hourly', $persona['cron_frequency'] );
	}

	public function test_persona_defaults_cron_disabled(): void {
		$id      = $this->create_persona();
		$persona = Boswell_Persona::get( $id );
		$this->assertFalse( $persona['cron_enabled'] );
		$this->assertSame( 'daily', $persona['cron_frequency'] );
	}

	public function test_persona_save_schedules_when_enabled(): void {
		$id = $this->create_persona( array( 'cron_enabled' => true ) );
		$this->assertIsInt( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );
	}

	public function test_persona_save_does_not_schedule_when_disabled(): void {
		$id = $this->create_persona( array( 'cron_enabled' => false ) );
		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );
	}

	public function test_persona_save_reschedules_on_update(): void {
		$id = $this->create_persona( array( 'cron_enabled' => true, 'cron_frequency' => 'daily' ) );
		$ts1 = wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) );

		// Update to disable.
		$persona                 = Boswell_Persona::get( $id );
		$persona['cron_enabled'] = false;
		Boswell_Persona::save( $persona );

		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );
	}

	public function test_persona_delete_unschedules(): void {
		$id = $this->create_persona( array( 'cron_enabled' => true ) );
		$this->assertIsInt( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );

		Boswell_Persona::delete( $id );
		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );
	}

	public function test_unschedule_all(): void {
		$id1 = $this->create_persona( array( 'name' => 'P1', 'cron_enabled' => true ) );
		$id2 = $this->create_persona( array( 'name' => 'P2', 'cron_enabled' => true ) );

		Boswell_Cron::unschedule(); // no arg = all.
		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id1 ) ) );
		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id2 ) ) );
	}

	public function test_uninstall_clears_all_schedules(): void {
		$id = $this->create_persona( array( 'cron_enabled' => true ) );
		Boswell_Cron::uninstall();
		$this->assertFalse( wp_next_scheduled( Boswell_Cron::HOOK_NAME, array( $id ) ) );
	}

	public function test_invalid_frequency_defaults_to_daily(): void {
		$id      = $this->create_persona( array( 'cron_frequency' => 'every_second' ) );
		$persona = Boswell_Persona::get( $id );
		$this->assertSame( 'daily', $persona['cron_frequency'] );
	}
}
