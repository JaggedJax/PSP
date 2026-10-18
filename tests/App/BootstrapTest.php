<?php

namespace PSP\Tests\App;

use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use PSP\App\Bootstrap;
use PSP\App\Globals;
use PSP\App\Server;
use PSP\Db\DBTable;
use PSP\Log\Log;
use PSP\Log\LogProcessor;
use PSP\Log\Telemetry;

class BootstrapTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $server_backup = [];

	protected function setUp(): void
	{
		$this->server_backup = $_SERVER;
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server_backup;
		DBTable::$_object_namespace = '';
		Telemetry::$attribute_prefix = 'app';
		Server::trust_ip_headers();
		Globals::instance()->is_cli = true;
	}

	public function test_apply_settings_sets_the_shared_helpers(): void
	{
		Bootstrap::apply_settings([
			'object_namespace' => 'MyApp\\Objects\\',
			'trace_prefix' => 'myapp',
			'log_fields' => ['boot_customer_id'],
			'trusted_ip_headers' => ['HTTP_CF_CONNECTING_IP'],
		]);

		$this->assertSame('MyApp\\Objects\\', DBTable::$_object_namespace);
		$this->assertSame('myapp', Telemetry::$attribute_prefix);
		$this->assertContains('boot_customer_id', LogProcessor::fields());
		$this->assertContains('boot_customer_id', Telemetry::taggable_ids());

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->assertSame('5.6.7.8', Server::get_ip());
	}

	public function test_log_add_fields_can_skip_the_trace(): void
	{
		Log::add_fields(['boot_log_only_id'], trace: false);

		$this->assertContains('boot_log_only_id', LogProcessor::fields());
		$this->assertNotContains('boot_log_only_id', Telemetry::taggable_ids());

		$record = new LogRecord(new \DateTimeImmutable(), 'Default', Level::Info, 'Hi', ['boot_log_only_id' => 9]);
		$this->assertSame(9, (new LogProcessor())($record)->extra['boot_log_only_id']);
	}

	public function test_web_headers_fill_in_the_nonce(): void
	{
		$headers = Bootstrap::web_headers([
			'csp' => [
				'default-src' => ["'self'"],
				'script-src' => ["'strict-dynamic'", "'nonce-{nonce}'"],
			],
			'hsts_max_age' => 31536000,
		], 'abc123', 'prod');

		$this->assertSame([
			"Content-Security-Policy: default-src 'self'; script-src 'strict-dynamic' 'nonce-abc123';",
			'Referrer-Policy: strict-origin-when-cross-origin',
			'Strict-Transport-Security: max-age=31536000',
		], $headers);
	}

	public function test_web_headers_skip_hsts_in_dev(): void
	{
		$headers = Bootstrap::web_headers(['hsts_max_age' => 31536000, 'referrer_policy' => 'no-referrer'], 'abc123', 'dev');

		$this->assertSame(['Referrer-Policy: no-referrer'], $headers);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function namespaces(): array
	{
		return [
			'no trailing backslash' => ['MyApp\\Objects'],
			'leading and trailing backslash' => ['\\MyApp\\Objects\\'],
		];
	}

	#[DataProvider('namespaces')]
	public function test_object_namespace_gets_one_trailing_backslash(string $namespace): void
	{
		Bootstrap::apply_settings(['object_namespace' => $namespace]);

		$this->assertSame('MyApp\\Objects\\', DBTable::$_object_namespace);
	}

	public function test_is_valid_nonce_accepts_generated_nonces_only(): void
	{
		$this->assertTrue(Bootstrap::is_valid_nonce('4821-aB3dE9fG'));
		$this->assertTrue(Bootstrap::is_valid_nonce('dGVzdC1ub25jZQ=='));

		$this->assertFalse(Bootstrap::is_valid_nonce("abc12345'; script-src *"));
		$this->assertFalse(Bootstrap::is_valid_nonce('" onfocus="alert(1)'));
		$this->assertFalse(Bootstrap::is_valid_nonce('short'));
		$this->assertFalse(Bootstrap::is_valid_nonce(str_repeat('a', 129)));
		$this->assertFalse(Bootstrap::is_valid_nonce(null));
	}

	public function test_end_root_span_records_a_server_error(): void
	{
		$span = $this->createMock(SpanInterface::class);
		$span->expects($this->once())->method('setAttribute')->with('http.response.status_code', 503);
		$span->expects($this->once())->method('setStatus')->with(StatusCode::STATUS_ERROR);
		$span->expects($this->once())->method('end');

		(new \ReflectionProperty(Bootstrap::class, 'root_span'))->setValue(null, $span);
		Globals::instance()->is_cli = false;
		http_response_code(503);

		Bootstrap::end_root_span();

		$this->assertNull((new \ReflectionProperty(Bootstrap::class, 'root_span'))->getValue());
	}

	#[RequiresFunction('posix_kill')]
	#[RequiresFunction('pcntl_signal')]
	public function test_sigterm_ends_the_script(): void
	{
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/signal_exit.php') . ' 2>&1', $output, $exit_code);

		$this->assertSame(128 + SIGTERM, $exit_code, implode("\n", $output));
		$this->assertStringNotContainsString('still running', implode("\n", $output));
	}
}
