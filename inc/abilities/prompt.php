<?php
/**
 * The prompt ability: a skill's "Tell your agent" prompt, as an MCP prompt.
 *
 * Every skill in skills/ holds the prompt a person gives their agent, as the
 * one blockquote under its "Tell your agent" heading. An agent that reaches
 * the site only over MCP never sees the skills, so this serves that text. It
 * is read from the skill's SKILL.md on every call, so the skills stay the one
 * place it is written, and nothing is cached.
 *
 * `meta.mcp.type` is `prompt`: the WordPress MCP Adapter's default server, from
 * 0.3.0, lists an ability of that type under prompts/list rather than as a
 * tool (includes/Servers/DefaultServerFactory.php), and prompts/get returns
 * the `messages` this returns. The adapter turns each input property into a
 * prompt argument with its name, description and whether it is required, and
 * drops the rest of its schema, `enum` included
 * (includes/Domain/Prompts/RegisterAbilityAsMcpPrompt.php), so `item`'s
 * description names every skill as well.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The text a skill's prompt may hold for the task, which `task` fills.
 */
const TASK_SLOT = '[task]';

/**
 * A skill folder's name.
 */
const SKILL_NAME = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

/**
 * Register prompt, with every skill that has a prompt in its `item` enum.
 *
 * Not registered when no skill has one, since an empty enum accepts nothing.
 */
function register_prompt(): void {
	$items = array_keys( skill_prompts() );

	if ( [] === $items ) {
		return;
	}

	wp_register_ability(
		CATEGORY . '/prompt',
		[
			'label'               => __( 'A Voyager Open Blocks prompt', 'voyager-open-blocks' ),
			'description'         => __( 'The request a person gives their agent for one of the pack\'s skills, the prompt under its "Tell your agent" heading, as one user message. Served over MCP as a prompt, not a tool.', 'voyager-open-blocks' ),
			'category'            => CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'item' => [
						'type'        => 'string',
						'enum'        => $items,
						'description' => 'The skill, voyager-open-blocks for the general prompt: ' . implode( ', ', $items ) . '.',
					],
					'task' => [
						'type'        => 'string',
						'description' => 'Fills [task] in a prompt that has one, such as voyager-open-blocks. Refused for a prompt without it.',
					],
				],
				'required'             => [ 'item' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'messages' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'role'    => [
									'type' => 'string',
									'enum' => [ 'user' ],
								],
								'content' => [
									'type'       => 'object',
									'properties' => [
										'type' => [
											'type' => 'string',
											'enum' => [ 'text' ],
										],
										'text' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\prompt',
			'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			'meta'                => array_merge(
				meta( true, true ),
				[
					'mcp' => [
						'public' => true,
						'type'   => 'prompt',
					],
				]
			),
		]
	);
}

/**
 * Execute prompt: the skill's prompt, with [task] filled when a task is given.
 *
 * An empty task is no task, since an MCP client may send an argument it left
 * blank as "". A prompt with no [task] refuses a task rather than drop it.
 *
 * @param array<string, mixed>|null $input Input.
 * @param string|null               $dir   Skills directory. Defaults to this plugin's skills.
 * @return array{messages: list<array{role: string, content: array{type: string, text: string}}>}|WP_Error
 */
function prompt( $input = null, ?string $dir = null ) {
	$input = assoc( $input );
	$item  = is_string( $input['item'] ?? null ) ? $input['item'] : '';
	$task  = is_string( $input['task'] ?? null ) ? trim( $input['task'] ) : '';
	$dir   = $dir ?? VOYAGER_OPEN_BLOCKS_DIR . '/skills';

	if ( 1 !== preg_match( SKILL_NAME, $item ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_item', 'item names no skill. Pass the name of a skill folder, such as voyager-open-hero.', [ 'status' => 400 ] );
	}

	$file     = $dir . '/' . $item . '/SKILL.md';
	$contents = is_readable( $file ) ? file_get_contents( $file ) : false;
	$read     = false === $contents ? 'it has no SKILL.md' : read_prompt( $contents );

	if ( is_string( $read ) ) {
		return new WP_Error( 'voyager_open_blocks_invalid_item', sprintf( 'The %1$s skill has no prompt to give: %2$s.', $item, $read ), [ 'status' => 400 ] );
	}

	$text = $read['text'];

	if ( '' !== $task ) {
		if ( ! str_contains( $text, TASK_SLOT ) ) {
			return new WP_Error(
				'voyager_open_blocks_invalid_task',
				sprintf( 'The %1$s prompt has no %2$s to fill, so it takes no task. Leave task out.', $item, TASK_SLOT ),
				[ 'status' => 400 ]
			);
		}

		$text = str_replace( TASK_SLOT, $task, $text );
	}

	return [
		'messages' => [
			[
				'role'    => 'user',
				'content' => [
					'type' => 'text',
					'text' => $text,
				],
			],
		],
	];
}

/**
 * Every skill with a prompt, by folder name in name order, each with its prompt.
 *
 * Reads each skills/<name>/SKILL.md, one per skill the pack ships. A skill
 * whose file has no prompt in the shape read_prompt() reads is left out.
 *
 * @param string|null $dir Skills directory. Defaults to this plugin's skills.
 * @return array<string, string>
 */
function skill_prompts( ?string $dir = null ): array {
	$dir = $dir ?? VOYAGER_OPEN_BLOCKS_DIR . '/skills';
	$out = [];

	foreach ( glob( $dir . '/*/SKILL.md' ) ?: [] as $file ) {
		$name     = basename( dirname( $file ) );
		$contents = 1 === preg_match( SKILL_NAME, $name ) && is_readable( $file ) ? file_get_contents( $file ) : false;
		$read     = false === $contents ? null : read_prompt( $contents );

		if ( is_array( $read ) ) {
			$out[ $name ] = $read['text'];
		}
	}

	ksort( $out );

	return $out;
}

/**
 * A SKILL.md's prompt: the one blockquote under its one "Tell your agent" heading.
 *
 * The heading is any level, outside a code fence. Its section runs to the
 * next heading. Only blank lines come between the heading and the
 * blockquote, and the section holds no other blockquote; a note after it,
 * such as how to ask for another layout, is not part of the prompt. The
 * prompt is the blockquote's lines less their ">", joined with line breaks,
 * so a prompt over several lines keeps them.
 *
 * @param string $markdown The SKILL.md.
 * @return array{text: string}|string The prompt, or why the file has none.
 */
function read_prompt( string $markdown ) {
	$lines    = preg_split( '/\R/', $markdown ) ?: [];
	$fenced   = [];
	$headings = [];
	$prompts  = [];
	$fence    = false;

	foreach ( $lines as $index => $line ) {
		if ( 1 === preg_match( '/^ {0,3}(```|~~~)/', $line ) ) {
			$fence = ! $fence;
		}

		// A fence's own lines count as inside it, so neither is read as a heading or a quote.
		$fenced[ $index ] = $fence || 1 === preg_match( '/^ {0,3}(```|~~~)/', $line );

		if ( $fenced[ $index ] || 1 !== preg_match( '/^ {0,3}#{1,6}\s/', $line ) ) {
			continue;
		}

		$headings[] = $index;

		if ( 1 === preg_match( '/^ {0,3}#{1,6}\s+Tell your agent\s*#*\s*$/i', $line ) ) {
			$prompts[] = $index;
		}
	}

	if ( [] === $prompts ) {
		return 'it has no "Tell your agent" heading';
	}
	if ( count( $prompts ) > 1 ) {
		return sprintf( 'it has %d "Tell your agent" headings', count( $prompts ) );
	}

	$start = $prompts[0];
	$end   = count( $lines );

	foreach ( $headings as $index ) {
		if ( $index > $start ) {
			$end = $index;
			break;
		}
	}

	$quotes = [];
	$quote  = null;

	for ( $index = $start + 1; $index < $end; $index++ ) {
		if ( ! $fenced[ $index ] && 1 === preg_match( '/^ {0,3}>\s?(.*)$/', $lines[ $index ], $match ) ) {
			$quote = $quote ?? count( $quotes );

			$quotes[ $quote ][] = rtrim( $match[1] );
			continue;
		}

		$quote = null;

		if ( [] === $quotes && '' !== trim( $lines[ $index ] ) ) {
			return 'text comes between its "Tell your agent" heading and the blockquote';
		}
	}

	if ( [] === $quotes ) {
		return 'no blockquote follows its "Tell your agent" heading';
	}
	if ( count( $quotes ) > 1 ) {
		return sprintf( 'its "Tell your agent" section has %d blockquotes', count( $quotes ) );
	}

	$text = trim( implode( "\n", $quotes[0] ) );

	return '' === $text ? 'its "Tell your agent" blockquote is empty' : [ 'text' => $text ];
}
