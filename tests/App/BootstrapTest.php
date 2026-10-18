<?php

namespace PSP\Tests\App;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use PSP\App\Bootstrap;
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
}
