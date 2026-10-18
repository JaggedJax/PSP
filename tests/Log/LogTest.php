<?php

namespace PSP\Tests\Log;

use PSP\App\Server;
use PSP\Log\JsonLogFormatter;
use PSP\Log\Log;
use PSP\Log\LogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
	protected function setUp(): void
	{

	}

	public function testStackTraceReturnsArrayWithoutTabs(): void
	{
		$trace = Server::stack_trace(1, 0);
		$this->assertIsArray($trace);
		$this->assertNotEmpty($trace);

		foreach ($trace as $line) {
			$this->assertIsString($line);
			$this->assertStringStartsNotWith("\t", $line, "Stack trace line should not start with tab");
			$this->assertMatchesRegularExpression('/^\d+\)\s/', $line, "Line should start with index like '1) '");
		}
	}

	public function testStackTraceLimitsLines(): void
	{
		$singleLineTrace = Server::stack_trace(1, 1);
		$this->assertIsArray($singleLineTrace);
		$this->assertCount(1, $singleLineTrace);
		$this->assertStringStartsNotWith("\t", $singleLineTrace[0]);
	}

	public function testStackTraceHandlesLargeIgnoreGracefully(): void
	{
		// Asking to ignore 1000 calls should safely return empty array without errors
		$trace = Server::stack_trace(1000, 5);
		$this->assertSame([], $trace);
	}

	public function testJsonLogFormatterOutputsValidNdjson(): void
	{
		$formatter = new JsonLogFormatter();
		$record = new LogRecord(
			new \DateTimeImmutable('2026-09-24T18:00:00.123456-07:00'),
			'Default',
			Level::Error,
			'Something went wrong',
			['customer_id' => 123],
			[
				'log_id' => 'test-log-1',
				'process_id' => '9999-abc',
				'process_name' => 'send_orders',
				'hostname' => 'test-host',
				'stacktrace' => [
					'1) /app/src/file.php(10): foo()',
					'2) /app/src/file2.php(20): bar()',
				],
			]
		);

		$formatted = $formatter->format($record);
		$this->assertStringEndsWith("\n", $formatted);

		$decoded = json_decode(trim($formatted), true);
		$this->assertIsArray($decoded);

		// Core fields
		$this->assertSame('Default', $decoded['channel']);
		$this->assertSame('ERROR', $decoded['level']);
		$this->assertSame('Something went wrong', $decoded['message']);
		$this->assertSame('2026-09-24T18:00:00.123456-07:00', $decoded['datetime']);

		// Flattened extra fields
		$this->assertSame('test-log-1', $decoded['log_id']);
		$this->assertSame('9999-abc', $decoded['process_id']);
		$this->assertSame('send_orders', $decoded['process_name']);
		$this->assertSame('test-host', $decoded['hostname']);
		$this->assertSame([
			'1) /app/src/file.php(10): foo()',
			'2) /app/src/file2.php(20): bar()',
		], $decoded['stacktrace']);

		// Context
		$this->assertSame(['customer_id' => 123], $decoded['context']);
	}

	public function testJsonLogFormatterOmitsEmptyContext(): void
	{
		$formatter = new JsonLogFormatter();
		$record = new LogRecord(
			new \DateTimeImmutable('2026-09-24T18:00:00+00:00'),
			'Default',
			Level::Info,
			'All good',
			[],
			['log_id' => 'test-log-2']
		);

		$formatted = $formatter->format($record);
		$decoded = json_decode(trim($formatted), true);

		$this->assertArrayNotHasKey('context', $decoded);
		$this->assertSame('test-log-2', $decoded['log_id']);
		$this->assertSame('INFO', $decoded['level']);
	}

	public function testLogProcessorMovesInjectedFields(): void
	{
		LogProcessor::add_fields('customer_id');
		$processor = new LogProcessor();
		$record = new LogRecord(
			new \DateTimeImmutable(),
			'Default',
			Level::Error,
			'Processor test',
			[
				'log_id' => 'log-100',
				'process_name' => 'sync_inventory',
				'customer_id' => 55,
				'custom_data' => 'keep_in_context',
			],
			[]
		);

		$processed = $processor($record);

		$this->assertSame('log-100', $processed->extra['log_id']);
		$this->assertSame('sync_inventory', $processed->extra['process_name']);
		$this->assertSame(55, $processed->extra['customer_id']);
		$this->assertArrayNotHasKey('log_id', $processed->context);
		$this->assertArrayNotHasKey('customer_id', $processed->context);
		$this->assertSame('keep_in_context', $processed->context['custom_data']);
	}

	public function test_processor_moves_pid_from_context_to_extra(): void
	{
		$record = new LogRecord(new \DateTimeImmutable('2026-09-24T18:00:00'), 'Default', Level::Info, 'Hi', ['pid' => 4821, 'other' => 1]);

		$processed = (new \PSP\Log\LogProcessor())($record);

		$this->assertSame(4821, $processed->extra['pid']);
		$this->assertArrayNotHasKey('pid', $processed->context);
	}
}
