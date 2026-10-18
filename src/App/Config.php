<?php

namespace PSP\App;

class Config
{
	private static ?Config $instance = null;
	/** @var array<string, mixed[]> $config */
	protected array $config = [];
	protected string $base_dir = '';
	private static ?string $custom_base_dir = null;

	private function __construct()
	{
		$this->base_dir = self::$custom_base_dir ?? self::default_base_dir();
	}

	/**
	 * Find the project root. Inside vendor/ it is the dir that contains vendor/, otherwise the package root
	 * @return string
	 */
	private static function default_base_dir(): string
	{
		$package_root = dirname(__DIR__, 2);
		$vendor_pos = strrpos($package_root, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
		$root = $vendor_pos !== false ? substr($package_root, 0, $vendor_pos) : $package_root;
		return $root.DIRECTORY_SEPARATOR;
	}

	/**
	 * Set the project root dir that contains the config dir. Call before the first Config::get()
	 * @param string $base_dir Absolute path to the project root
	 */
	public static function set_base_dir(string $base_dir): void
	{
		self::$custom_base_dir = rtrim($base_dir, '/\\').DIRECTORY_SEPARATOR;
		self::$instance = null;
	}

	/**
	 * Get the project root dir that contains the config dir. Always ends with a directory separator
	 * @return string
	 */
	public static function base_dir(): string
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance->base_dir;
	}

	/**
	 * Programmatically set config values for a given config type.
	 * Overrides file and environment values. Useful for tests or bootstrap.
	 * @param string $config_type
	 * @param mixed[] $values
	 */
	public static function set(string $config_type, array $values): void
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		$config_file = str_replace(['..','/','\\'], '', $config_type);
		self::$instance->config[$config_file] = $values;
	}

	/**
	 * Reset in-memory config cache and custom base dir. Useful for tests.
	 */
	public static function reset(): void
	{
		self::$instance = null;
		self::$custom_base_dir = null;
	}

	/**
	 * Get the config array for a file in the config dir.
	 * Values from config/{$config_type}.json are loaded first, then overlaid with any matching environment variables.
	 * @param string $config_type Do not include ".json" file extension
	 * @return mixed[]
	 */
	public static function get(string $config_type): array
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		$config_file = str_replace(['..','/','\\'], '', $config_type);

		if (!isset(self::$instance->config[$config_file])) {
			self::$instance->config[$config_file] = self::$instance->load($config_file);
		}

		return self::$instance->config[$config_file];
	}

	/**
	 * Load config values for a config type by reading disk JSON, then applying environment overrides.
	 * @param string $config_file
	 * @return mixed[]
	 */
	private function load(string $config_file): array
	{
		$config = [];
		$file = 'config' . DIRECTORY_SEPARATOR . $config_file . '.json';
		$path = $this->base_dir . $file;

		if (file_exists($path)) {
			$contents = file_get_contents($path);
			if ($contents !== false) {
				$decoded = json_decode($contents, true);
				if (is_array($decoded)) {
					$config = $decoded;
				}
			}
		}

		return self::apply_env_overrides($config_file, $config);
	}

	/**
	 * Overlay environment variable overrides on top of the config array.
	 *
	 * Order of precedence (highest to lowest):
	 * 1. Specific PSP_{CONFIG}_{KEY} env vars (e.g. PSP_DB_PASSWORD)
	 * 2. Well-known standard env aliases (e.g. DB_HOST, DB_PASSWORD, APP_ENV)
	 * 3. Full JSON payload from PSP_CONFIG_{CONFIG} or PSP_{CONFIG}_JSON
	 * 4. File-based defaults from config/{$config_file}.json
	 *
	 * @param string $config_file
	 * @param mixed[] $config
	 * @return mixed[]
	 */
	public static function apply_env_overrides(string $config_file, array $config): array
	{
		$upper = strtoupper($config_file);

		// 1. Full JSON payload in env var: PSP_CONFIG_{TYPE} or PSP_{TYPE}_JSON
		$env_json = self::read_env("PSP_CONFIG_{$upper}") ?? self::read_env("PSP_{$upper}_JSON");
		if ($env_json !== null && trim($env_json) !== '') {
			$decoded = json_decode($env_json, true);
			if (is_array($decoded)) {
				$config = array_replace_recursive($config, $decoded);
			}
		}

		// 2. Well-known aliases (e.g. DB_HOST, DB_PASSWORD, APP_ENV, etc.)
		$aliases = self::well_known_aliases($config_file);
		foreach ($aliases as $env_var => $target_path) {
			$val = self::read_env($env_var);
			if ($val !== null) {
				self::set_nested_value($config, $target_path, self::parse_env_value($val, $target_path));
			}
		}

		// 3. Generic prefix env vars: PSP_{CONFIG_FILE}_{KEY}
		// e.g. PSP_DB_HOST, PSP_SCHEDULER_NICE, PSP_PSP_DOMAIN
		// Double underscores '__' represent nesting, e.g. PSP_PSP_GRAFANA__URL
		$psp_prefix = "PSP_{$upper}_";
		$psp_prefix_len = strlen($psp_prefix);
		foreach (self::all_env() as $key => $val) {
			if (is_string($key) && str_starts_with($key, $psp_prefix) && $key !== "PSP_{$upper}_JSON") {
				$suffix = substr($key, $psp_prefix_len);
				$path = strtolower(str_replace('__', '.', $suffix));
				self::set_nested_value($config, $path, self::parse_env_value((string)$val, $path));
			}
		}

		return $config;
	}

	/**
	 * Map of well-known standard environment variable aliases per config file
	 * @param string $config_file
	 * @return array<string, string>
	 */
	private static function well_known_aliases(string $config_file): array
	{
		return match ($config_file) {
			'db' => [
				'DB_HOST' => 'host',
				'PSP_DB_HOST' => 'host',
				'DB_REPLICA_HOST' => 'replica_host',
				'PSP_DB_REPLICA_HOST' => 'replica_host',
				'DB_DATABASE' => 'name',
				'DB_NAME' => 'name',
				'PSP_DB_NAME' => 'name',
				'DB_USER' => 'username',
				'DB_USERNAME' => 'username',
				'PSP_DB_USERNAME' => 'username',
				'PSP_DB_USER' => 'username',
				'DB_PASSWORD' => 'password',
				'PSP_DB_PASSWORD' => 'password',
			],
			'psp' => [
				'APP_NAME' => 'name',
				'PSP_NAME' => 'name',
				'ENVIRONMENT' => 'environment',
				'APP_ENV' => 'environment',
				'PSP_ENV' => 'environment',
				'PSP_ENVIRONMENT' => 'environment',
				'APP_DOMAIN' => 'domain',
				'PSP_DOMAIN' => 'domain',
				'LOG_DIR' => 'log_dir',
				'PSP_LOG_DIR' => 'log_dir',
				'MEMCACHED_HOST' => 'memcached.server',
				'MEMCACHED_SERVER' => 'memcached.server',
				'PSP_MEMCACHED_SERVER' => 'memcached.server',
				'MEMCACHED_PORT' => 'memcached.port',
				'PSP_MEMCACHED_PORT' => 'memcached.port',
				'GRAFANA_URL' => 'grafana.url',
				'PSP_GRAFANA_URL' => 'grafana.url',
				'GRAFANA_LOKI_DATASOURCE_UID' => 'grafana.loki_datasource_uid',
				'PSP_GRAFANA_LOKI_DATASOURCE_UID' => 'grafana.loki_datasource_uid',
				'GRAFANA_LOKI_SELECTOR' => 'grafana.loki_selector',
				'PSP_GRAFANA_LOKI_SELECTOR' => 'grafana.loki_selector',
			],
			'scheduler' => [
				'SCHEDULER_CONCURRENT_JOB_LIMIT' => 'concurrent_job_limit',
				'PSP_SCHEDULER_CONCURRENT_JOB_LIMIT' => 'concurrent_job_limit',
				'SCHEDULER_NICE' => 'nice',
				'PSP_SCHEDULER_NICE' => 'nice',
				'SCHEDULER_BRANCH_REPO' => 'branch_repo',
				'PSP_SCHEDULER_BRANCH_REPO' => 'branch_repo',
			],
			'hibp' => [
				'HIBP_ENABLED' => 'enabled',
				'PSP_HIBP_ENABLED' => 'enabled',
			],
			default => [],
		};
	}

	/**
	 * Read an environment variable from $_ENV, $_SERVER, or getenv()
	 * @param string $key
	 * @return string|null Null when not set
	 */
	public static function read_env(string $key): ?string
	{
		if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
			return (string)$_ENV[$key];
		}
		if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null) {
			return (string)$_SERVER[$key];
		}
		$val = getenv($key);
		if ($val !== false) {
			return (string)$val;
		}
		return null;
	}

	/**
	 * Get all active environment variables across getenv(), $_ENV, and $_SERVER
	 * @return array<string, mixed>
	 */
	private static function all_env(): array
	{
		$env = getenv();
		$result = is_array($env) ? $env : [];
		foreach ($_ENV as $k => $v) {
			$result[$k] = $v;
		}
		foreach ($_SERVER as $k => $v) {
			$result[$k] = $v;
		}
		return $result;
	}

	/**
	 * Set a value in a nested array using dot notation (e.g. 'grafana.url')
	 * @param mixed[] $array
	 * @param string $path
	 * @param mixed $value
	 */
	private static function set_nested_value(array &$array, string $path, mixed $value): void
	{
		$keys = explode('.', $path);
		$last_key = array_pop($keys);
		$current = &$array;
		foreach ($keys as $key) {
			if (!isset($current[$key]) || !is_array($current[$key])) {
				$current[$key] = [];
			}
			$current = &$current[$key];
		}
		$current[$last_key] = $value;
	}

	/**
	 * Parse string environment value to appropriate PHP type (boolean, integer, float, string)
	 * @param string $val
	 * @param string $path
	 * @return mixed
	 */
	private static function parse_env_value(string $val, string $path): mixed
	{
		$trimmed = trim($val);
		$lower = strtolower($trimmed);
		if ($lower === 'true') {
			return true;
		}
		if ($lower === 'false') {
			return false;
		}
		if ($lower === 'null') {
			return null;
		}

		$keys = explode('.', $path);
		$last_key = (string)end($keys);
		// Never cast passwords, secrets, keys, or tokens to numbers
		if (preg_match('/(password|secret|key|token|hash)/i', $last_key)) {
			return $val;
		}

		if (is_numeric($trimmed) && (!str_starts_with($trimmed, '0') || $trimmed === '0')) {
			return str_contains($trimmed, '.') ? (float)$trimmed : (int)$trimmed;
		}

		return $val;
	}
}
