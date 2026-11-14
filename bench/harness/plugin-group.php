<?php
/**
 * Plugin Name: Bench plugin group
 * Description: Unregisters every ability the pack registers, so the benchmark's plugin group has the pack's Site Data, bindings and blocks but none of its abilities. Installed as an mu-plugin by bench/run.mjs in the plugin group only; never part of the pack.
 *
 * The pack registers its abilities on wp_abilities_api_init at the default
 * priority (register() in inc/abilities/shared.php). This runs on the same
 * action at the last priority, after them, and removes each one whose name
 * starts with the pack's namespace. wp_unregister_ability() is in core since
 * WordPress 6.9 (wp-includes/abilities-api.php). The MCP Adapter lists
 * abilities from the registry when it is asked, so the agent's discover call
 * lists none of the pack's, and executing one by name fails as not found.
 *
 * The pack's ability category stays registered: an empty category is not
 * listed over MCP, and removing it first would make every one of the pack's
 * registrations fail with a notice instead.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBench\PluginGroup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PREFIX = 'voyager-open-blocks/';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		foreach ( array_keys( wp_get_abilities() ) as $name ) {
			if ( str_starts_with( (string) $name, PREFIX ) ) {
				wp_unregister_ability( (string) $name );
			}
		}
	},
	PHP_INT_MAX
);
