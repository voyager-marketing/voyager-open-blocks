<?php
/**
 * The skills' "Tell your agent" prompts, and the prompt ability that serves them.
 *
 * Every SKILL.md under skills/ holds one "Tell your agent" heading followed
 * by one blockquote: the prompt a person gives their agent. The prompt
 * ability reads it from there at run time, so the skills stay the one place
 * it is written, and lists only the skills that have one in its `item` enum.
 * Each planted SKILL.md below breaks that shape in its own way, and must be
 * refused with a reason and left out of the list.
 *
 * scripts/e2e.mjs runs the ability over REST and compares what it returns
 * with the hero skill's blockquote, read there on its own.
 *
 * @package VoyagerOpenBlocks
 */

declare(strict_types=1);

namespace VoyagerOpenBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VoyagerOpenBlocks\Abilities;
use WP_Error;

require_once dirname( __DIR__ ) . '/prompt-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/patterns.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities.php';

final class SkillPromptsTest extends TestCase {

	private const SKILLS = __DIR__ . '/../../skills';

	/** The entry skill, the quick card. Its prompt holds [task]. */
	private const CARD = 'voyager-open-blocks';

	private const HERO = 'voyager-open-hero';

	/**
	 * The prompt as written in a skill's file today: the heading, a blank
	 * line, and one blockquote line. Read here with a pattern of its own, so
	 * the ability's reader is checked against something it did not compute.
	 */
	private const AS_WRITTEN = '/^## Tell your agent\n\n> ([^\n]+)\n/m';

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['voyager_test_abilities'] = [];

		$dir = tempnam( sys_get_temp_dir(), 'voyager-skills-' );
		$this->assertIsString( $dir );
		unlink( $dir );
		mkdir( $dir );
		$this->dir = $dir;
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*/SKILL.md' ) ?: [] as $file ) {
			unlink( $file );
			rmdir( dirname( $file ) );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	/**
	 * Every SKILL.md under skills/, by folder name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function skill_files(): array {
		$out = [];

		foreach ( glob( self::SKILLS . '/*/SKILL.md' ) ?: [] as $file ) {
			$out[ basename( dirname( $file ) ) ] = [ $file ];
		}

		return $out;
	}

	/**
	 * The check this suite exists for: a SKILL.md with no prompt, or two, fails here by name.
	 */
	#[DataProvider( 'skill_files' )]
	public function test_every_skill_has_one_prompt( string $file ): void {
		$read = Abilities\read_prompt( (string) file_get_contents( $file ) );

		$this->assertIsArray(
			$read,
			sprintf( 'skills/%s/SKILL.md needs one "Tell your agent" heading followed by one blockquote: %s.', basename( dirname( $file ) ), is_string( $read ) ? $read : '' )
		);
	}

	public function test_the_prompt_is_the_blockquote_as_written(): void {
		$files = self::skill_files();

		// A glob that found nothing would pass every check above.
		$this->assertGreaterThanOrEqual( 17, count( $files ), 'skills/ holds at least the 17 skills the pack ships.' );

		foreach ( $files as $name => [ $file ] ) {
			$markdown = (string) file_get_contents( $file );
			$this->assertSame( 1, preg_match( self::AS_WRITTEN, $markdown, $match ), $name );
			$this->assertSame( [ 'text' => $match[1] ], Abilities\read_prompt( $markdown ), $name );
		}
	}

	public function test_the_list_holds_every_skill_with_a_prompt(): void {
		$prompts = Abilities\skill_prompts();

		$this->assertSame( array_keys( self::skill_files() ), array_keys( $prompts ) );
		$this->assertArrayHasKey( self::CARD, $prompts );
		$this->assertArrayHasKey( self::HERO, $prompts );
		$this->assertStringContainsString( '[task]', $prompts[ self::CARD ] );
	}

	/**
	 * A SKILL.md that breaks the shape, each in one way, and the reason it is refused.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function planted(): array {
		return [
			'no heading'                       => [ "# Skill\n\nPlace the block.\n", 'it has no "Tell your agent" heading' ],
			'heading only in a code fence'     => [ "# Skill\n\n```markdown\n## Tell your agent\n\n> Add it.\n```\n", 'it has no "Tell your agent" heading' ],
			'two headings'                     => [ "## Tell your agent\n\n> Add it.\n\n## Tell your agent\n\n> Add it again.\n", 'it has 2 "Tell your agent" headings' ],
			'no blockquote'                    => [ "## Tell your agent\n\n## Structure\n\n> Not this one.\n", 'no blockquote follows its "Tell your agent" heading' ],
			'two blockquotes'                  => [ "## Tell your agent\n\n> Add it.\n\n> Or this.\n", 'its "Tell your agent" section has 2 blockquotes' ],
			'a second one after a note'        => [ "## Tell your agent\n\n> Add it.\n\nOr say:\n\n> Add it twice.\n", 'its "Tell your agent" section has 2 blockquotes' ],
			'prose before the blockquote'      => [ "## Tell your agent\n\nSay this:\n\n> Add it.\n", 'text comes between its "Tell your agent" heading and the blockquote' ],
			'a code fence before it'           => [ "## Tell your agent\n\n```\n> Add it.\n```\n", 'text comes between its "Tell your agent" heading and the blockquote' ],
			'an empty blockquote'              => [ "## Tell your agent\n\n>\n", 'its "Tell your agent" blockquote is empty' ],
		];
	}

	public function test_a_note_after_the_blockquote_is_not_part_of_the_prompt(): void {
		$this->assertSame(
			[ 'text' => 'Add the hero, image beside text.' ],
			Abilities\read_prompt( "## Tell your agent\n\n> Add the hero, image beside text.\n\nFor the other layout, say \"text over a background\".\n\n```bash\n> not a quote\n```\n\n## Structure\n\n> Not this one either.\n" )
		);
	}

	#[DataProvider( 'planted' )]
	public function test_a_planted_skill_is_refused_and_left_out( string $markdown, string $reason ): void {
		$this->assertSame( $reason, Abilities\read_prompt( $markdown ) );

		$this->plant( 'voyager-open-good', "# Good\n\n## Tell your agent\n\n> Add the good block.\n\n## Structure\n" );
		$this->plant( 'voyager-open-planted', $markdown );

		$this->assertSame( [ 'voyager-open-good' => 'Add the good block.' ], Abilities\skill_prompts( $this->dir ) );
	}

	public function test_a_blockquote_over_several_lines_keeps_its_lines(): void {
		$this->assertSame(
			[ 'text' => "Add the hero.\nKeep the phone bound.\n\nTell me what is empty." ],
			Abilities\read_prompt( "### Tell Your Agent ###\r\n\r\n> Add the hero.  \r\n> Keep the phone bound.\r\n>\r\n> Tell me what is empty.\r\n\r\n## Structure\r\n" )
		);
	}

	public function test_the_hero_prompt_is_one_user_message(): void {
		$prompts = Abilities\skill_prompts();

		$this->assertSame(
			[
				'messages' => [
					[
						'role'    => 'user',
						'content' => [
							'type' => 'text',
							'text' => $prompts[ self::HERO ],
						],
					],
				],
			],
			Abilities\prompt( [ 'item' => self::HERO ] )
		);
	}

	public function test_task_fills_the_quick_card(): void {
		$card   = Abilities\skill_prompts()[ self::CARD ];
		$result = Abilities\prompt(
			[
				'item' => self::CARD,
				'task' => ' build my home page ',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( str_replace( '[task]', 'build my home page', $card ), $result['messages'][0]['content']['text'] );
		$this->assertStringNotContainsString( '[task]', $result['messages'][0]['content']['text'] );

		// No task leaves [task] for the person to fill.
		$this->assertSame( $card, $this->text( Abilities\prompt( [ 'item' => self::CARD ] ) ) );
		$this->assertSame( $card, $this->text( Abilities\prompt( [ 'item' => self::CARD, 'task' => '  ' ] ) ) );
	}

	public function test_task_is_refused_on_a_prompt_with_no_task(): void {
		$result = Abilities\prompt(
			[
				'item' => self::HERO,
				'task' => 'build my home page',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_task', $result->get_error_code() );
		$this->assertStringContainsString( 'has no [task]', $result->get_error_message() );

		// An empty task is no task.
		$this->assertIsArray( Abilities\prompt( [ 'item' => self::HERO, 'task' => '' ] ) );
	}

	/**
	 * Items the ability refuses, as a call that skipped the input schema could send them.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function bad_items(): array {
		return [
			'no item'           => [ [] ],
			'an unknown skill'  => [ [ 'item' => 'voyager-open-nothing' ] ],
			'a path'            => [ [ 'item' => '../voyager-open-hero' ] ],
			'not a string'      => [ [ 'item' => [ 'voyager-open-hero' ] ] ],
		];
	}

	#[DataProvider( 'bad_items' )]
	public function test_a_bad_item_is_refused( mixed $input ): void {
		$result = Abilities\prompt( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_item', $result->get_error_code() );
	}

	public function test_a_skill_whose_prompt_broke_is_refused_at_run_time(): void {
		$this->plant( 'voyager-open-planted', "# Planted\n\nNo prompt here.\n" );

		$result = Abilities\prompt( [ 'item' => 'voyager-open-planted' ], $this->dir );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voyager_open_blocks_invalid_item', $result->get_error_code() );
		$this->assertStringContainsString( 'no "Tell your agent" heading', $result->get_error_message() );
	}

	public function test_it_registers_as_an_mcp_prompt_with_every_skill_in_its_enum(): void {
		Abilities\register_prompt();

		$args = $GLOBALS['voyager_test_abilities']['voyager-open-blocks/prompt'] ?? null;
		$this->assertIsArray( $args );

		$this->assertSame( [ 'public' => true, 'type' => 'prompt' ], $args['meta']['mcp'] );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$this->assertSame( array_keys( Abilities\skill_prompts() ), $args['input_schema']['properties']['item']['enum'] );
		$this->assertSame( [ 'item' ], $args['input_schema']['required'] );

		// The adapter keeps an argument's description and drops its enum, so
		// the description is where an MCP client reads the skills from.
		foreach ( array_keys( Abilities\skill_prompts() ) as $name ) {
			$this->assertStringContainsString( $name, $args['input_schema']['properties']['item']['description'] );
		}
	}

	/**
	 * Write a SKILL.md into a skill folder of the temporary skills directory.
	 *
	 * @param string $name     Folder name.
	 * @param string $markdown Its SKILL.md.
	 */
	private function plant( string $name, string $markdown ): void {
		if ( ! is_dir( $this->dir . '/' . $name ) ) {
			mkdir( $this->dir . '/' . $name );
		}
		file_put_contents( $this->dir . '/' . $name . '/SKILL.md', $markdown );
	}

	/**
	 * The text of a prompt result's one message.
	 *
	 * @param mixed $result What prompt() returned.
	 */
	private function text( mixed $result ): string {
		$this->assertIsArray( $result );

		return $result['messages'][0]['content']['text'];
	}
}
