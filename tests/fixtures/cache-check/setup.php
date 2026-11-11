<?php
/**
 * Prepares a Playground site for npm run cache-check.
 *
 * scripts/cache-check.mjs requires this through runPhp() (scripts/dev.mjs)
 * with WordPress loaded and $voyager_open_cache_check set to the run's
 * options. It returns what it did and anything that went wrong, and the
 * harness stops before measuring anything unless `ok` is true.
 *
 * It copies harness.php and the plugin's extra mu-plugins into mu-plugins,
 * stores the options harness.php reads, and runs <plugin>/setup.php when
 * there is one.
 *
 * Test fixture. Never run it on a real site: it writes mu-plugins and, for
 * WP Super Cache, wp-config.php.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) || ! isset( $voyager_open_cache_check ) || ! is_array( $voyager_open_cache_check ) ) {
	return [
		'ok'       => false,
		'problems' => [ 'setup.php needs WordPress loaded and $voyager_open_cache_check set.' ],
		'facts'    => [],
	];
}

return ( static function ( array $config ): array {
	$problems = [];
	$facts    = [];

	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		wp_mkdir_p( WPMU_PLUGIN_DIR );
	}
	$copies = [ 'harness.php' => 'voyager-open-cache-check.php' ];
	foreach ( (array) ( $config['mu_plugins'] ?? [] ) as $file ) {
		$copies[ (string) $file ] = 'voyager-open-cache-check-' . $file;
	}
	foreach ( $copies as $from => $to ) {
		if ( ! copy( __DIR__ . '/' . $from, WPMU_PLUGIN_DIR . '/' . $to ) ) {
			$problems[] = "Could not copy {$from} into mu-plugins.";
		}
	}
	$facts['mu-plugins'] = array_values( $copies );

	update_option(
		'voyager_open_cache_check',
		[
			'no_purge' => ! empty( $config['no_purge'] ),
			'stand_in' => ! empty( $config['stand_in'] ),
			'hooks'    => (array) ( $config['hooks'] ?? [] ),
		],
		true
	);

	$plugin = preg_replace( '/[^a-z0-9-]/', '', (string) ( $config['plugin'] ?? '' ) );
	$setup  = __DIR__ . '/' . $plugin . '/setup.php';
	if ( '' !== $plugin && is_file( $setup ) ) {
		$result   = require $setup;
		$facts    = array_merge( $facts, (array) ( $result['facts'] ?? [] ) );
		$problems = array_merge( $problems, (array) ( $result['problems'] ?? [] ) );
	}

	return [
		'ok'       => [] === $problems,
		'problems' => $problems,
		'facts'    => $facts,
	];
} )( $voyager_open_cache_check );
