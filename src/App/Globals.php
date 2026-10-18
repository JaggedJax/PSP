<?php

namespace PSP\App;

use PSP\Util\Crypt;

/**
 * Use this object to define globals rather than the $GLOBALS array
 * This allows for autocomplete, yay!
 */
class Globals
{
	/** @var self $instance */
	private static self $instance;
	
	public bool $is_cli;
	/** @var bool Are we currently running unit tests? Set $GLOBALS['is_unit_test'] before the autoloader to enable this */
	public bool $is_unit_test = false;
	public bool $in_shutdown = false;
	/** @var string Area of the app that handles this request. EG: admin */
	public string $app_area = '';
	/** @var string A unique Process ID generated when this object is first created */
	public string $process_id = '';
	/** @var string A unique Nonce generated when this object is first created. Used for CSP headers */
	public string $nonce = '';
	/** @var string Name of process used to launch/enter this PHP application */
	public string $process_name = '';
	public float $last_db_write_time = 0;
	public string $last_query_db_type = '';
	/** @var int Tracks number of DB calls made during this process */
	public int $num_db_calls = 0;
	/** @var string Values: local, dev, stage, prod */
	public string $environment;
	public ?\Memcached $memcached = null;
	
	private function __construct()
	{
		$this->is_cli = (php_sapi_name() == 'cli');
		$this->is_unit_test = (isset($GLOBALS['is_unit_test']) && $GLOBALS['is_unit_test'] === true);
		$this->process_name = !empty($GLOBALS['argv'][0]) ? basename($GLOBALS['argv'][0], '.php') : 'Unknown';
		$this->environment = Config::get('psp')['environment'] ?? 'prod';
	}
	
	/**
	 * Get Globals instance for a specific log channel
	 * @return self
	 */
	public static function instance(): self
	{
		if (!isset(self::$instance)) {
			self::$instance = new self();
			self::$instance->process_id = getmypid().'-'.Crypt::rand_alphanumeric(8);
			self::$instance->nonce = self::$instance->process_id;
		}
		return self::$instance;
	}
}
