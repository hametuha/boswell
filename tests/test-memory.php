<?php
/**
 * Tests for Boswell_Memory
 *
 * @package Boswell
 */

class Test_Boswell_Memory extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Boswell_Memory::OPTION_KEY );
		delete_option( Boswell_Memory::UPDATED_AT_KEY );
	}

	public function test_get_returns_default_when_empty(): void {
		$memory = Boswell_Memory::get();
		$this->assertStringContainsString( '## Recent Activities', $memory );
		$this->assertStringContainsString( '## Ongoing Topics', $memory );
		$this->assertStringContainsString( '## Commentary Log', $memory );
		$this->assertStringContainsString( '## Notes', $memory );
	}

	public function test_get_persists_default_to_option(): void {
		Boswell_Memory::get();
		$stored = get_option( Boswell_Memory::OPTION_KEY );
		$this->assertNotEmpty( $stored );
	}

	public function test_update_replaces_memory(): void {
		$custom = "## Recent Activities\n\n- Custom entry\n";
		Boswell_Memory::update( $custom );
		$this->assertSame( $custom, Boswell_Memory::get() );
	}

	public function test_get_updated_at_returns_iso8601(): void {
		Boswell_Memory::get();
		$updated_at = Boswell_Memory::get_updated_at();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $updated_at );
	}

	public function test_get_section_returns_content(): void {
		Boswell_Memory::append_entry( 'recent_activities', 'Test activity' );
		$section = Boswell_Memory::get_section( 'recent_activities' );
		$this->assertStringContainsString( 'Test activity', $section );
	}

	public function test_get_section_returns_empty_for_invalid_key(): void {
		$this->assertSame( '', Boswell_Memory::get_section( 'nonexistent' ) );
	}

	public function test_append_entry_adds_with_date_prefix(): void {
		Boswell_Memory::append_entry( 'recent_activities', 'Did something' );
		$memory = Boswell_Memory::get();
		$today  = gmdate( 'Y-m-d' );
		$this->assertStringContainsString( "- [{$today}] Did something", $memory );
	}

	public function test_append_entry_rejects_invalid_section(): void {
		$this->assertFalse( Boswell_Memory::append_entry( 'invalid', 'entry' ) );
	}

	public function test_append_entry_trims_old_entries(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			Boswell_Memory::append_entry( 'commentary_log', "Entry #{$i}#" );
		}
		$section = Boswell_Memory::get_section( 'commentary_log' );
		$entries = explode( "\n", trim( $section ) );
		// Should keep only the latest 20 entries (6-25).
		$this->assertCount( Boswell_Memory::MAX_ENTRIES, $entries );
		$this->assertStringNotContainsString( 'Entry #1#', $section );
		$this->assertStringNotContainsString( 'Entry #5#', $section );
		$this->assertStringContainsString( 'Entry #6#', $section );
		$this->assertStringContainsString( 'Entry #25#', $section );
	}

	public function test_uninstall_removes_options(): void {
		Boswell_Memory::get();
		Boswell_Memory::uninstall();
		$this->assertFalse( get_option( Boswell_Memory::OPTION_KEY ) );
		$this->assertFalse( get_option( Boswell_Memory::UPDATED_AT_KEY ) );
	}

	public function test_get_default_contains_all_sections(): void {
		$default = Boswell_Memory::get_default();
		foreach ( Boswell_Memory::SECTIONS as $heading ) {
			$this->assertStringContainsString( "## {$heading}", $default );
		}
	}
}
