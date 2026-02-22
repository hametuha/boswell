<?php
/**
 * Boswell Memory
 *
 * Manages Boswell's shared memory stored as Markdown in wp_options.
 *
 * @package Boswell
 */

/**
 * Memory storage and section parsing.
 */
class Boswell_Memory {

	const OPTION_KEY = 'boswell_memory';

	const UPDATED_AT_KEY = 'boswell_memory_updated_at';

	const MAX_ENTRIES = 20;

	/**
	 * Section identifiers mapped to Markdown headings.
	 */
	const SECTIONS = array(
		'recent_activities' => 'Recent Activities',
		'ongoing_topics'    => 'Ongoing Topics',
		'commentary_log'    => 'Commentary Log',
		'notes'             => 'Notes',
	);

	/**
	 * Get the full memory as Markdown.
	 *
	 * @return string
	 */
	public static function get(): string {
		$memory = get_option( self::OPTION_KEY, '' );
		if ( empty( $memory ) ) {
			$memory = self::get_default();
			update_option( self::OPTION_KEY, $memory, false );
			update_option( self::UPDATED_AT_KEY, gmdate( 'c' ), false );
		}
		return $memory;
	}

	/**
	 * Get the last updated timestamp.
	 *
	 * @return string ISO 8601 datetime.
	 */
	public static function get_updated_at(): string {
		return get_option( self::UPDATED_AT_KEY, gmdate( 'c' ) );
	}

	/**
	 * Replace the full memory.
	 *
	 * @param string $markdown Full memory content.
	 * @return bool
	 */
	public static function update( string $markdown ): bool {
		update_option( self::UPDATED_AT_KEY, gmdate( 'c' ), false );
		return update_option( self::OPTION_KEY, $markdown, false );
	}

	/**
	 * Get content of a specific section.
	 *
	 * @param string $section Section key (e.g. 'recent_activities').
	 * @return string Section content, or empty string if not found.
	 */
	public static function get_section( string $section ): string {
		if ( ! isset( self::SECTIONS[ $section ] ) ) {
			return '';
		}

		$memory  = self::get();
		$heading = self::SECTIONS[ $section ];
		$parsed  = self::parse_sections( $memory );

		return $parsed[ $heading ] ?? '';
	}

	/**
	 * Append an entry to a section.
	 *
	 * Automatically prepends today's date as [YYYY-MM-DD].
	 * If the section exceeds MAX_ENTRIES, oldest entries are removed.
	 *
	 * @param string $section Section key.
	 * @param string $entry   Entry text (without date prefix or bullet).
	 * @return bool
	 */
	public static function append_entry( string $section, string $entry ): bool {
		if ( ! isset( self::SECTIONS[ $section ] ) ) {
			return false;
		}

		$memory  = self::get();
		$heading = self::SECTIONS[ $section ];
		$parsed  = self::parse_sections( $memory );

		$date_prefix = gmdate( 'Y-m-d' );
		$new_line    = sprintf( '- [%s] %s', $date_prefix, $entry );

		// Get existing entries, filter out placeholder text.
		$content = trim( $parsed[ $heading ] ?? '' );
		$lines   = self::extract_entries( $content );
		$lines[] = $new_line;

		// Enforce max entries by removing oldest (first items).
		if ( count( $lines ) > self::MAX_ENTRIES ) {
			$lines = array_slice( $lines, count( $lines ) - self::MAX_ENTRIES );
		}

		$parsed[ $heading ] = implode( "\n", $lines );

		return self::update( self::build_markdown( $parsed ) );
	}

	/**
	 * Delete all memory data. Used by register_uninstall_hook.
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::UPDATED_AT_KEY );
	}

	/**
	 * Get the default empty memory template.
	 *
	 * @return string
	 */
	public static function get_default(): string {
		$parts = array();
		foreach ( self::SECTIONS as $heading ) {
			$parts[] = sprintf( "## %s\n", $heading );
		}
		return implode( "\n", $parts );
	}

	/**
	 * Parse memory Markdown into sections.
	 *
	 * @param string $markdown Full memory text.
	 * @return array<string, string> Heading => content.
	 */
	private static function parse_sections( string $markdown ): array {
		$sections        = array();
		$current_heading = null;
		$current_lines   = array();

		foreach ( explode( "\n", $markdown ) as $line ) {
			if ( preg_match( '/^## (.+)$/', $line, $m ) ) {
				if ( null !== $current_heading ) {
					$sections[ $current_heading ] = trim( implode( "\n", $current_lines ) );
				}
				$current_heading = trim( $m[1] );
				$current_lines   = array();
			} else {
				$current_lines[] = $line;
			}
		}

		// Save last section.
		if ( null !== $current_heading ) {
			$sections[ $current_heading ] = trim( implode( "\n", $current_lines ) );
		}

		return $sections;
	}

	/**
	 * Extract list entries (lines starting with "- ") from section content.
	 *
	 * @param string $content Section body text.
	 * @return string[] List of entry lines.
	 */
	private static function extract_entries( string $content ): array {
		if ( empty( $content ) ) {
			return array();
		}

		$entries = array();
		foreach ( explode( "\n", $content ) as $line ) {
			if ( str_starts_with( trim( $line ), '- ' ) ) {
				$entries[] = trim( $line );
			}
		}
		return $entries;
	}

	/**
	 * Build Markdown from parsed sections.
	 *
	 * @param array<string, string> $sections Heading => content.
	 * @return string
	 */
	private static function build_markdown( array $sections ): string {
		$parts = array();

		// Preserve defined section order.
		foreach ( self::SECTIONS as $heading ) {
			$content = $sections[ $heading ] ?? '';
			$parts[] = sprintf( "## %s\n\n%s", $heading, $content );
		}

		// Append any extra sections not in SECTIONS.
		foreach ( $sections as $heading => $content ) {
			if ( ! in_array( $heading, self::SECTIONS, true ) ) {
				$parts[] = sprintf( "## %s\n\n%s", $heading, $content );
			}
		}

		return implode( "\n\n", $parts ) . "\n";
	}
}
