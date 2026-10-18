<?php

namespace PSP\Tests\Process;

use PSP\Process\CLI;
use PHPUnit\Framework\TestCase;

use function Patchwork\always;
use function Patchwork\redefine;

class CLITest extends TestCase
{
	/**
	 * This method is called before each test.
	 */
	protected function setUp(): void
	{
		redefine('getopt', always([]));

		// Define the options for all tests
		CLI::setOptions(
			[
				's:'  => 'The numeric ID of the account.',
				'v'   => 'Enable verbose output.',
				'o::' => 'Optional output file path.',
			],
			[
				'account:' => '-s The numeric ID of the account. (Required)',
				'verbose'   => '-v Enable verbose output mode.',
				'output::'  => '-o Optional output file path.',
				'force'     => 'Force the operation without a short-option counterpart.',
			]
		);
	}

	/**
	 * This method is called after each test.
	 */
	protected function tearDown(): void
	{
		// Reset the singleton to ensure test isolation
		CLI::reset();
	}

	public function testGetValueProvidedByLongOption(): void
	{
		// Simulate getopt() returning a long option
		CLI::inject(['account' => '12345']);

		// Get by long name
		$this->assertSame('12345', CLI::get('account'));
		// Get by the mapped short name
		$this->assertSame('12345', CLI::get('s'));
	}

	public function testGetValueProvidedByShortOption(): void
	{
		// Simulate getopt() returning a short option
		CLI::inject(['s' => '67890']);

		// Get by short name
		$this->assertSame('67890', CLI::get('s'));
		// Get by the mapped long name
		$this->assertSame('67890', CLI::get('account'));
	}

	public function testGetFlagProvidedByLongOption(): void
	{
		// Simulate getopt() returning a long option flag (value is `false`)
		CLI::inject(['verbose' => false]);

		// Flags should be converted to `true`
		$this->assertTrue(CLI::get('verbose'));
		$this->assertTrue(CLI::get('v'));
	}

	public function testGetFlagProvidedByShortOption(): void
	{
		// Simulate getopt() returning a short option flag
		CLI::inject(['v' => false]);

		$this->assertTrue(CLI::get('v'));
		$this->assertTrue(CLI::get('verbose'));
	}

	public function testGetFlagWithoutShortOptionCounterpart(): void
	{
		CLI::inject(['force' => false]);

		$this->assertTrue(CLI::get('force'));
	}

	public function testGetDefaultValueWhenOptionIsMissing(): void
	{
		// Simulate no options provided
		CLI::inject([]);

		$this->assertNull(CLI::get('account'));
		$this->assertSame('default_value', CLI::get('account', 'default_value'));
	}

	public function testGetOptionalValueWhenProvided(): void
	{
		CLI::inject(['o' => 'report.txt']);
		$this->assertSame('report.txt', CLI::get('output'));
		$this->assertSame('report.txt', CLI::get('o'));
	}

	public function testGetOptionalValueWhenNotProvided(): void
	{
		// An optional value that is not provided should not be "set"
		CLI::inject([]);
		$this->assertNull(CLI::get('output'));
		$this->assertNull(CLI::get('o'));
	}

	public function testHelpMessageGeneration(): void
	{
		$help = CLI::generateHelpMessage();

		// Check for key components in the generated help string
		$this->assertStringContainsString('-s <value>, --account=<value>', $help);
		$this->assertStringContainsString('-v, --verbose', $help);
		$this->assertStringContainsString('-o [<value>], --output[=<value>]', $help);
		$this->assertStringContainsString('--force', $help);
		$this->assertStringContainsString('The numeric ID of the account. (Required)', $help);
	}
}
