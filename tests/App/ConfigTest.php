<?php

namespace PSP\Tests\App;

use PHPUnit\Framework\TestCase;
use PSP\App\Config;

class ConfigTest extends TestCase
{
	/** @var array<string, string|false> */
	private array $env_backup = [];

	protected function setUp(): void
	{
		Config::reset();
		Config::set_base_dir(__DIR__ . '/../');
	}

	protected function tearDown(): void
	{
		foreach ($this->env_backup as $key => $original_value) {
			if ($original_value === false) {
				putenv($key);
				unset($_ENV[$key], $_SERVER[$key]);
			}
			else {
				putenv("$key=$original_value");
				$_ENV[$key] = $original_value;
				$_SERVER[$key] = $original_value;
			}
		}
		$this->env_backup = [];
		Config::reset();
		Config::set_base_dir(__DIR__ . '/../');
	}

	private function setEnv(string $key, ?string $value): void
	{
		if (!array_key_exists($key, $this->env_backup)) {
			$this->env_backup[$key] = getenv($key);
		}

		if ($value === null) {
			putenv($key);
			unset($_ENV[$key], $_SERVER[$key]);
		}
		else {
			putenv("$key=$value");
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}
	}

	public function test_loads_from_json_file_by_default(): void
	{
		$config = Config::get('psp');

		$this->assertSame('PSP', $config['name']);
		$this->assertSame('test', $config['environment']);
		$this->assertSame('example.com', $config['domain']);
	}

	public function test_env_variables_override_json_file_values(): void
	{
		$this->setEnv('DB_HOST', 'db.internal:3307');
		$this->setEnv('DB_PASSWORD', 'super_secret_123');

		$config = Config::get('db');

		$this->assertSame('db.internal:3307', $config['host']);
		$this->assertSame('super_secret_123', $config['password']);
		// Other values from tests/config/db.json remain intact
		$this->assertSame('psp_test', $config['name']);
		$this->assertSame('root', $config['username']);
	}

	public function test_psp_prefixed_env_overrides_standard_alias(): void
	{
		$this->setEnv('DB_HOST', 'generic.host:3306');
		$this->setEnv('PSP_DB_HOST', 'psp.specific.host:3306');

		$config = Config::get('db');

		$this->assertSame('psp.specific.host:3306', $config['host']);
	}

	public function test_psp_app_aliases_and_nested_keys(): void
	{
		$this->setEnv('APP_ENV', 'staging');
		$this->setEnv('PSP_GRAFANA_URL', 'https://grafana.example.com');
		$this->setEnv('MEMCACHED_PORT', '11212');

		$config = Config::get('psp');

		$this->assertSame('staging', $config['environment']);
		$this->assertSame('https://grafana.example.com', $config['grafana']['url']);
		$this->assertSame(11212, $config['memcached']['port']);
	}

	public function test_full_json_payload_env_variable(): void
	{
		$this->setEnv('PSP_CONFIG_CUSTOM', json_encode([
			'api_url' => 'https://api.example.com',
			'timeout' => 30,
			'features' => ['a', 'b'],
		]));

		$config = Config::get('custom');

		$this->assertSame('https://api.example.com', $config['api_url']);
		$this->assertSame(30, $config['timeout']);
		$this->assertSame(['a', 'b'], $config['features']);
	}

	public function test_generic_config_type_from_env_vars(): void
	{
		// No payment.json file exists
		$this->setEnv('PSP_PAYMENT_API_KEY', 'sk_test_12345');
		$this->setEnv('PSP_PAYMENT_WEBHOOK__SECRET', 'whsec_67890');
		$this->setEnv('PSP_PAYMENT_TIMEOUT', '45');
		$this->setEnv('PSP_PAYMENT_DEBUG', 'true');

		$config = Config::get('payment');

		$this->assertSame('sk_test_12345', $config['api_key']);
		$this->assertSame('whsec_67890', $config['webhook']['secret']);
		$this->assertSame(45, $config['timeout']);
		$this->assertTrue($config['debug']);
	}

	public function test_type_casting_rules(): void
	{
		$this->setEnv('PSP_TESTING_FLAG_TRUE', 'true');
		$this->setEnv('PSP_TESTING_FLAG_FALSE', 'false');
		$this->setEnv('PSP_TESTING_FLAG_NULL', 'null');
		$this->setEnv('PSP_TESTING_INT_VAL', '100');
		$this->setEnv('PSP_TESTING_FLOAT_VAL', '99.95');
		$this->setEnv('PSP_TESTING_LEADING_ZERO_STRING', '01234');
		$this->setEnv('PSP_TESTING_PASSWORD', '123456');

		$config = Config::get('testing');

		$this->assertTrue($config['flag_true']);
		$this->assertFalse($config['flag_false']);
		$this->assertNull($config['flag_null']);
		$this->assertSame(100, $config['int_val']);
		$this->assertSame(99.95, $config['float_val']);
		$this->assertSame('01234', $config['leading_zero_string']);
		$this->assertSame('123456', $config['password']); // Not cast to int
	}

	public function test_programmatic_set_overrides_file_and_env(): void
	{
		$this->setEnv('DB_HOST', 'env.host:3306');

		Config::set('db', [
			'host' => 'manual.override:3306',
			'name' => 'in_memory_db',
		]);

		$config = Config::get('db');

		$this->assertSame('manual.override:3306', $config['host']);
		$this->assertSame('in_memory_db', $config['name']);
	}

	public function test_default_base_dir_is_the_composer_root_project(): void
	{
		$method = new \ReflectionMethod(Config::class, 'default_base_dir');

		$this->assertSame(realpath(dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR, $method->invoke(null));
	}
}
