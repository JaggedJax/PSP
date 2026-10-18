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
	 * Get the config array for a file in the config dir.
	 * @param string $config_type Do not include ".json" file extension
	 * @return mixed[]
	 */
	public static function get(string $config_type): array
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		$config_file = str_replace(['..','/','\\'], '', $config_type);

		if (empty(self::$instance->config[$config_file])) {
			$file = 'config'.DIRECTORY_SEPARATOR.$config_file.".json";
			if (file_exists(self::$instance->base_dir.$file)) {
				self::$instance->config[$config_file] = json_decode(file_get_contents(self::$instance->base_dir . $file), true);
			}
		}

		//TODO: PHP Warning and log on attempting to access invalid config file

		return self::$instance->config[$config_file] ?? [];
	}
}
