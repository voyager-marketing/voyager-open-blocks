<?php
/**
 * Demo content for a throwaway site: Playground blueprints and local setup.
 *
 * Run inside a loaded WordPress (`wp eval-file playground/seed.php`, or a
 * blueprint runPHP step after wp-load.php). Safe to run twice: pages are
 * found by path and updated in place.
 *
 * Every value here is fictional (example.com, the 555-01xx range). Do not
 * point this at a real site: it overwrites the site title and the
 * voyager_site_* options.
 *
 * No strict_types declaration: `wp eval-file` evals this file, and eval'd
 * code cannot declare it.
 *
 * @package VoyagerOpenBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$voyager_open_seed = static function (): void {
	update_option( 'blogname', 'Example Co.' );
	update_option( 'blogdescription', 'Demo site for Voyager Open Blocks' );
	update_option( 'blog_public', '0' );
	update_option( 'permalink_structure', '/%postname%/' );

	$site_data = [
		'voyager_site_phone'            => '(555) 010-0199',
		'voyager_site_email'            => 'hello@example.com',
		'voyager_site_address'          => '42 Example Avenue',
		'voyager_site_address_city'     => 'Springfield',
		'voyager_site_address_state'    => 'ST',
		'voyager_site_address_zip'      => '00042',
		'voyager_site_hours'            => 'Monday to Friday, 8am to 6pm',
		'voyager_site_social_facebook'  => 'https://facebook.com/example',
		'voyager_site_social_instagram' => 'https://instagram.com/example',
		'voyager_site_social_linkedin'  => 'https://www.linkedin.com/company/example',
		'voyager_site_social_x'         => 'https://x.com/example',
	];
	foreach ( $site_data as $option => $value ) {
		update_option( $option, $value );
	}

	// Demo bindable meta lives in an mu-plugin so the pack itself ships none.
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
	if ( ! is_dir( $mu_dir ) ) {
		wp_mkdir_p( $mu_dir );
	}
	copy( __DIR__ . '/demo-fields.php', $mu_dir . '/voyager-open-blocks-demo-fields.php' );

	$upsert = static function ( string $path, string $title, string $content, int $parent = 0 ): int {
		$existing = get_page_by_path( $path );
		$postarr  = [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => basename( $path ),
			'post_content' => $content,
			'post_parent'  => $parent,
		];
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
		}
		return (int) wp_insert_post( wp_slash( $postarr ) );
	};

	$hub = $upsert( 'voyager-open', 'Voyager Open Blocks', '' );

	$demos = [
		'cta-band'        => [ 'CTA band', '<!-- wp:pattern {"slug":"voyager-open/cta-band"} /-->' ],
		'proof-strip'     => [ 'Proof strip', '<!-- wp:pattern {"slug":"voyager-open/proof-strip"} /-->' ],
		'logo-marquee'    => [ 'Logo marquee', '<!-- wp:pattern {"slug":"voyager-open/logo-marquee"} /-->' ],
		'closing-cta'     => [ 'Closing CTA', '<!-- wp:pattern {"slug":"voyager-open/closing-cta"} /-->' ],
		'contact-section' => [ 'Contact section', '<!-- wp:pattern {"slug":"voyager-open/contact-section"} /-->' ],
		'icon'            => [
			'Icon',
			implode(
				"\n\n",
				[
					'<!-- wp:paragraph --><p>Decorative, linked and bound icons. The last one takes its label from voyager/site-data and its link from the X profile URL.</p><!-- /wp:paragraph -->',
					'<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} --><div class="wp-block-group">',
					'<!-- wp:voyager-open/icon {"icon":"rocket-launch","size":48} /-->',
					'<!-- wp:voyager-open/icon {"icon":"check-circle","size":48,"animation":"pulse","animationTrigger":"always"} /-->',
					'<!-- wp:voyager-open/icon {"icon":"heart","size":48,"animation":"bounce","animationTrigger":"hover","label":"Favourite"} /-->',
					'<!-- wp:voyager-open/icon {"icon":"chat-bubble","size":48,"linkUrl":"/contact/","label":"Contact us"} /-->',
					'<!-- wp:voyager-open/icon {"icon":"globe","size":48,"linkUrl":"https://example.com/","label":"Company","metadata":{"bindings":{"label":{"source":"voyager/site-data","args":{"key":"company"}},"linkUrl":{"source":"voyager/site-data","args":{"key":"social_x"}}}}} /-->',
					'</div><!-- /wp:group -->',
				]
			),
		],
		'site-data'       => [
			'Site data',
			implode(
				"\n\n",
				[
					'<!-- wp:paragraph --><p>Each line below is a paragraph bound to one voyager/site-data key. Change the values under Settings, Site Data.</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"company"}}}}} --><p>Company</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"phone"}}}}} --><p>Phone</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"address_locality"}}}}} --><p>City, State Postal code</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/site-data","args":{"key":"copyright"}}}}} --><p>Copyright</p><!-- /wp:paragraph -->',
					'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"text":{"source":"voyager/site-data","args":{"key":"phone"}},"url":{"source":"voyager/site-data","args":{"key":"phone_link"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Call us</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
				]
			),
		],
		'post-meta-text'  => [
			'Post meta text',
			implode(
				"\n\n",
				[
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"demo_eyebrow"}}}}} --><p>Eyebrow</p><!-- /wp:paragraph -->',
					'<!-- wp:heading --><h2 class="wp-block-heading">Bound to this page\'s own fields</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"demo_summary"}}}}} --><p>Summary</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"demo_stats.0.value"}}}}} --><p>Stat value</p><!-- /wp:paragraph -->',
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"voyager/post-meta-text","args":{"key":"demo_stats.0.label"}}}}} --><p>Stat label</p><!-- /wp:paragraph -->',
				]
			),
		],
	];

	$links = [];
	foreach ( $demos as $slug => [ $title, $content ] ) {
		$id = $upsert( 'voyager-open/' . $slug, $title, $content, $hub );
		if ( 'post-meta-text' === $slug ) {
			update_post_meta( $id, 'demo_eyebrow', 'Field demo' );
			update_post_meta( $id, 'demo_summary', 'This paragraph reads the demo_summary field of the page it sits on.' );
			update_post_meta( $id, 'demo_stats', wp_json_encode( [ [ 'value' => '98%', 'label' => 'Bound from a JSON field with a dot path' ] ] ) );
		}
		$links[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( home_url( '/voyager-open/' . $slug . '/' ) ), esc_html( $title ) );
	}

	$upsert(
		'voyager-open',
		'Voyager Open Blocks',
		'<!-- wp:paragraph --><p>One page per block, binding source and pattern in the pack.</p><!-- /wp:paragraph -->' . "\n\n"
			. '<!-- wp:list --><ul class="wp-block-list">' . implode( '', array_map( static fn ( $li ) => str_replace( '<li>', '<!-- wp:list-item --><li>', $li ) . '<!-- /wp:list-item -->', $links ) ) . '</ul><!-- /wp:list -->'
	);

	flush_rewrite_rules( false );
};

$voyager_open_seed();
